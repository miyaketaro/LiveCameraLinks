#requires -Version 5.1

param(
    [string]$PreviewPath = ".\reports\approved-registration-preview.csv",
    [switch]$Write
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$PrefectureMap = @{
    "北海道"   = "hokkaido"
    "青森県"   = "aomori"
    "岩手県"   = "iwate"
    "宮城県"   = "miyagi"
    "秋田県"   = "akita"
    "山形県"   = "yamagata"
    "福島県"   = "fukushima"
    "茨城県"   = "ibaraki"
    "栃木県"   = "tochigi"
    "群馬県"   = "gunma"
    "埼玉県"   = "saitama"
    "千葉県"   = "chiba"
    "東京都"   = "tokyo"
    "神奈川県" = "kanagawa"
    "新潟県"   = "niigata"
    "富山県"   = "toyama"
    "石川県"   = "ishikawa"
    "福井県"   = "fukui"
    "山梨県"   = "yamanashi"
    "長野県"   = "nagano"
    "岐阜県"   = "gifu"
    "静岡県"   = "shizuoka"
    "愛知県"   = "aichi"
    "三重県"   = "mie"
    "滋賀県"   = "shiga"
    "京都府"   = "kyoto"
    "大阪府"   = "osaka"
    "兵庫県"   = "hyogo"
    "奈良県"   = "nara"
    "和歌山県" = "wakayama"
    "鳥取県"   = "tottori"
    "島根県"   = "shimane"
    "岡山県"   = "okayama"
    "広島県"   = "hiroshima"
    "山口県"   = "yamaguchi"
    "徳島県"   = "tokushima"
    "香川県"   = "kagawa"
    "愛媛県"   = "ehime"
    "高知県"   = "kochi"
    "福岡県"   = "fukuoka"
    "佐賀県"   = "saga"
    "長崎県"   = "nagasaki"
    "熊本県"   = "kumamoto"
    "大分県"   = "oita"
    "宮崎県"   = "miyazaki"
    "鹿児島県" = "kagoshima"
    "沖縄県"   = "okinawa"
}

# --------------------------------------------------
# Known place/area aliases
# Only aliases that are sufficiently reliable belong here.
# --------------------------------------------------

$AreaAliases = @{
    "yamanashi" = @{
        "minamialps" = @(
            "南アルプス市",
            "三郡橋",
            "十五所",
            "白根IC",
            "白根"
        )

        "fujikawa" = @(
            "富士川町",
            "富士川大橋"
        )

        "kofu" = @(
            "甲府市",
            "甲府駅"
        )

        "fujiyoshida" = @(
            "富士吉田市",
            "富士吉田"
        )

        "kawaguchiko" = @(
            "河口湖",
            "富士河口湖町"
        )

        "yamanakako" = @(
            "山中湖",
            "山中湖村"
        )
    }

    "fukui" = @{
        "tannan" = @(
            "丹南",
            "越前市",
            "鯖江市",
            "越前町"
        )

        "fukui" = @(
            "福井市",
            "福井駅",
            "大名町交差点"
        )
    }

    "hokkaido" = @{
        "setana" = @(
            "せたな町",
            "北檜山"
        )
    }
}

function Get-ExistingAreaFiles {
    param(
        [string]$Category,
        [string]$PrefectureCode
    )

    $folder = ".\camera\$Category\$PrefectureCode"

    if (-not (Test-Path $folder)) {
        return @()
    }

    return @(
        Get-ChildItem `
            -LiteralPath $folder `
            -File `
            -Filter "*.json" |
        Where-Object {
            $_.Name -ne "index.json" -and
            $_.Name -notlike "*.template.json"
        } |
        ForEach-Object {
            [System.IO.Path]::GetFileNameWithoutExtension($_.Name)
        } |
        Sort-Object
    )
}

function Get-AreaSuggestion {
    param(
        [string]$Title,
        [string]$PrefectureCode,
        [string[]]$ExistingAreas
    )

    if (
        [string]::IsNullOrWhiteSpace($Title) -or
        [string]::IsNullOrWhiteSpace($PrefectureCode)
    ) {
        return $null
    }

    if (-not $AreaAliases.ContainsKey($PrefectureCode)) {
        return $null
    }

    $matches = @()

    foreach ($areaCode in $AreaAliases[$PrefectureCode].Keys) {

        if ($areaCode -notin $ExistingAreas) {
            continue
        }

        foreach ($alias in $AreaAliases[$PrefectureCode][$areaCode]) {

            if ($Title -like "*$alias*") {
                $matches += $areaCode
                break
            }
        }
    }

    $matches = @(
        $matches |
        Sort-Object -Unique
    )

    if ($matches.Count -eq 1) {
        return $matches[0]
    }

    return $null
}

if (-not (Test-Path $PreviewPath)) {
    throw "Preview CSV not found: $PreviewPath"
}

$rows = @(Import-Csv $PreviewPath)

$result = @()

foreach ($row in $rows) {

    $candidateId = [string]$row.candidateId
    $title = [string]$row.title
    $prefectureName = [string]$row.confirmedPrefecture
    $category = [string]$row.confirmedCategory
    $currentTarget = [string]$row.targetFile

    if (-not [string]::IsNullOrWhiteSpace($currentTarget)) {

        $result += [PSCustomObject]@{
            candidateId       = $candidateId
            title             = $title
            currentTargetFile = $currentTarget
            suggestedTarget   = $currentTarget
            action            = "keep-existing"
        }

        continue
    }

    if (
        [string]::IsNullOrWhiteSpace($prefectureName) -or
        -not $PrefectureMap.ContainsKey($prefectureName)
    ) {

        $result += [PSCustomObject]@{
            candidateId       = $candidateId
            title             = $title
            currentTargetFile = ""
            suggestedTarget   = ""
            action            = "prefecture-unresolved"
        }

        continue
    }

    if ([string]::IsNullOrWhiteSpace($category)) {

        $result += [PSCustomObject]@{
            candidateId       = $candidateId
            title             = $title
            currentTargetFile = ""
            suggestedTarget   = ""
            action            = "category-unresolved"
        }

        continue
    }

    $prefectureCode = $PrefectureMap[$prefectureName]

    $areas = @(
        Get-ExistingAreaFiles `
            -Category $category `
            -PrefectureCode $prefectureCode
    )

    if ($areas.Count -eq 0) {

        $result += [PSCustomObject]@{
            candidateId       = $candidateId
            title             = $title
            currentTargetFile = ""
            suggestedTarget   = ""
            action            = "no-existing-area"
        }

        continue
    }

    $areaCode = Get-AreaSuggestion `
        -Title $title `
        -PrefectureCode $prefectureCode `
        -ExistingAreas $areas

    if ([string]::IsNullOrWhiteSpace($areaCode)) {

        $result += [PSCustomObject]@{
            candidateId       = $candidateId
            title             = $title
            currentTargetFile = ""
            suggestedTarget   = ""
            action            = "review-required"
        }

        continue
    }

    $suggestedTarget = "camera/$category/$prefectureCode/$areaCode.json"

    $result += [PSCustomObject]@{
        candidateId       = $candidateId
        title             = $title
        currentTargetFile = ""
        suggestedTarget   = $suggestedTarget
        action            = "suggest"
    }
}

Write-Host "===== Target File Suggestion Preview ====="
Write-Host "Rows              :" $rows.Count
Write-Host "Keep existing     :" @(
    $result |
    Where-Object { $_.action -eq "keep-existing" }
).Count
Write-Host "Suggest           :" @(
    $result |
    Where-Object { $_.action -eq "suggest" }
).Count
Write-Host "Review required   :" @(
    $result |
    Where-Object { $_.action -eq "review-required" }
).Count
Write-Host "No existing area  :" @(
    $result |
    Where-Object { $_.action -eq "no-existing-area" }
).Count
Write-Host "Unresolved        :" @(
    $result |
    Where-Object {
        $_.action -eq "prefecture-unresolved" -or
        $_.action -eq "category-unresolved"
    }
).Count

Write-Host ""

$result |
Select-Object `
    candidateId,
    currentTargetFile,
    suggestedTarget,
    action |
Format-Table -AutoSize

$suggestions = @(
    $result |
    Where-Object {
        $_.action -eq "suggest"
    }
)

if (-not $Write) {
    Write-Host ""
    Write-Host "DRY-RUN only. No files were modified."
    exit 0
}

if ($suggestions.Count -eq 0) {
    Write-Host ""
    Write-Host "No target files to write."
    exit 0
}

$rowMap = @{}

foreach ($row in $rows) {
    $rowMap[[string]$row.candidateId] = $row
}

foreach ($suggestion in $suggestions) {

    $candidateId = [string]$suggestion.candidateId

    if (-not $rowMap.ContainsKey($candidateId)) {
        throw "Candidate row not found: $candidateId"
    }

    $row = $rowMap[$candidateId]

    if (
        -not [string]::IsNullOrWhiteSpace(
            [string]$row.targetFile
        )
    ) {
        throw "Target file already exists for: $candidateId"
    }

    $row.targetFile = [string]$suggestion.suggestedTarget
}

$rows |
Export-Csv `
    -Path $PreviewPath `
    -NoTypeInformation `
    -Encoding UTF8

Write-Host ""
Write-Host "Target files written:" $suggestions.Count
Write-Host "Written:" $PreviewPath