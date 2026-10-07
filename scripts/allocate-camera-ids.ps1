#requires -Version 5.1

param(
    [string]$PreviewPath = ".\reports\approved-registration-preview.csv",
    [switch]$Write
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$PrefectureCodes = @{
    "北海道" = "01"
    "青森県" = "02"
    "岩手県" = "03"
    "宮城県" = "04"
    "秋田県" = "05"
    "山形県" = "06"
    "福島県" = "07"
    "茨城県" = "08"
    "栃木県" = "09"
    "群馬県" = "10"
    "埼玉県" = "11"
    "千葉県" = "12"
    "東京都" = "13"
    "神奈川県" = "14"
    "新潟県" = "15"
    "富山県" = "16"
    "石川県" = "17"
    "福井県" = "18"
    "山梨県" = "19"
    "長野県" = "20"
    "岐阜県" = "21"
    "静岡県" = "22"
    "愛知県" = "23"
    "三重県" = "24"
    "滋賀県" = "25"
    "京都府" = "26"
    "大阪府" = "27"
    "兵庫県" = "28"
    "奈良県" = "29"
    "和歌山県" = "30"
    "鳥取県" = "31"
    "島根県" = "32"
    "岡山県" = "33"
    "広島県" = "34"
    "山口県" = "35"
    "徳島県" = "36"
    "香川県" = "37"
    "愛媛県" = "38"
    "高知県" = "39"
    "福岡県" = "40"
    "佐賀県" = "41"
    "長崎県" = "42"
    "熊本県" = "43"
    "大分県" = "44"
    "宮崎県" = "45"
    "鹿児島県" = "46"
    "沖縄県" = "47"
}

if (-not (Test-Path $PreviewPath)) {
    throw "Preview CSV not found: $PreviewPath"
}

$rows = @(Import-Csv $PreviewPath)

$existingIds = @()

Get-ChildItem .\camera -Recurse -File -Filter *.json |
Where-Object {
    $_.Name -ne "index.json" -and
    $_.Name -notlike "*.template.json"
} |
ForEach-Object {

    try {
        $json = [System.IO.File]::ReadAllText(
            $_.FullName,
            [System.Text.Encoding]::UTF8
        ) | ConvertFrom-Json

        foreach ($camera in @($json.cameras)) {
            if (
                $null -ne $camera -and
                -not [string]::IsNullOrWhiteSpace(
                    [string]$camera.cameraId
                )
            ) {
                $existingIds += [string]$camera.cameraId
            }
        }
    }
    catch {}
}

$nextNumber = @{}

foreach ($prefectureName in $PrefectureCodes.Keys) {

    $code = $PrefectureCodes[$prefectureName]

    $numbers = @(
        $existingIds |
        Where-Object {
            $_ -match "^JP-$code-(\d{6})$"
        } |
        ForEach-Object {
            [int]$Matches[1]
        }
    )

    if ($numbers.Count -gt 0) {
        $nextNumber[$code] = (
            ($numbers | Measure-Object -Maximum).Maximum + 1
        )
    }
    else {
        $nextNumber[$code] = 1
    }
}

$result = @()

foreach ($row in $rows) {

    $currentId = [string]$row.proposedCameraId

    if (
        -not [string]::IsNullOrWhiteSpace($currentId)
    ) {
        $result += [PSCustomObject]@{
            candidateId         = $row.candidateId
            confirmedPrefecture = $row.confirmedPrefecture
            currentCameraId     = $currentId
            proposedCameraId    = $currentId
            action              = "keep-existing"
        }

        continue
    }

    $prefecture = [string]$row.confirmedPrefecture

    if (
        [string]::IsNullOrWhiteSpace($prefecture) -or
        -not $PrefectureCodes.ContainsKey($prefecture)
    ) {
        $result += [PSCustomObject]@{
            candidateId         = $row.candidateId
            confirmedPrefecture = $prefecture
            currentCameraId     = ""
            proposedCameraId    = ""
            action              = "prefecture-unresolved"
        }

        continue
    }

    $code = $PrefectureCodes[$prefecture]
    $number = [int]$nextNumber[$code]

    $newId = "JP-{0}-{1:D6}" -f $code, $number

    $nextNumber[$code] = $number + 1

    $result += [PSCustomObject]@{
        candidateId         = $row.candidateId
        confirmedPrefecture = $prefecture
        currentCameraId     = ""
        proposedCameraId    = $newId
        action              = "allocate"
    }
}

Write-Host "===== Camera ID Allocation Preview ====="
Write-Host "Rows            :" $rows.Count
Write-Host "Existing IDs    :" $existingIds.Count
Write-Host "Keep existing   :" @(
    $result | Where-Object { $_.action -eq "keep-existing" }
).Count
Write-Host "Allocate        :" @(
    $result | Where-Object { $_.action -eq "allocate" }
).Count
Write-Host "Unresolved      :" @(
    $result | Where-Object { $_.action -eq "prefecture-unresolved" }
).Count

Write-Host ""

$result |
Format-Table -AutoSize

$allocations = @(
    $result |
    Where-Object {
        $_.action -eq "allocate"
    }
)

if (-not $Write) {
    Write-Host ""
    Write-Host "DRY-RUN only. No files were modified."
    exit 0
}

if ($allocations.Count -eq 0) {
    Write-Host ""
    Write-Host "No new camera IDs to allocate."
    exit 0
}

$rowMap = @{}

foreach ($row in $rows) {
    $rowMap[[string]$row.candidateId] = $row
}

foreach ($allocation in $allocations) {

    $candidateId = [string]$allocation.candidateId

    if (-not $rowMap.ContainsKey($candidateId)) {
        throw "Candidate row not found: $candidateId"
    }

    $row = $rowMap[$candidateId]

    if (
        -not [string]::IsNullOrWhiteSpace(
            [string]$row.proposedCameraId
        )
    ) {
        throw "Camera ID already exists for: $candidateId"
    }

    $row.proposedCameraId = [string]$allocation.proposedCameraId
}

$rows |
Export-Csv `
    -Path $PreviewPath `
    -NoTypeInformation `
    -Encoding UTF8

Write-Host ""
Write-Host "Allocated camera IDs written:" $allocations.Count
Write-Host "Written:" $PreviewPath