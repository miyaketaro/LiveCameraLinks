param(
    [string]$SourcePath = ".\youtube-test\output\national-master.json",
    [string]$DestinationPath = ".\data\camera_candidates.json",
    [switch]$Write
)

$ErrorActionPreference = "Stop"

function ConvertTo-SafeBoolean {
    param($Value)

    if ($Value -is [bool]) {
        return $Value
    }

    if ($null -eq $Value) {
        return $false
    }

    $text = ([string]$Value).Trim().ToLowerInvariant()

    switch ($text) {
        "true"  { return $true }
        "1"     { return $true }
        "yes"   { return $true }
        "false" { return $false }
        "0"     { return $false }
        "no"    { return $false }
        default { return $false }
    }
}

function Read-Utf8Json {
    param([string]$Path)

    if (-not (Test-Path $Path)) {
        throw "File not found: $Path"
    }

    $resolved = Resolve-Path $Path

    [System.IO.File]::ReadAllText(
        $resolved,
        [System.Text.Encoding]::UTF8
    ) | ConvertFrom-Json
}

function Get-LocationReview {
    param(
        [string]$Prefecture,
        [string]$DetectedPrefecture
    )

    if (
        [string]::IsNullOrWhiteSpace($Prefecture) -or
        [string]::IsNullOrWhiteSpace($DetectedPrefecture)
    ) {
        return "unknown"
    }

    if ($Prefecture -eq $DetectedPrefecture) {
        return "match"
    }

    return "mismatch"
}

function Get-CategoryInfo {
    param(
        [string]$Title,
        [string]$Description
    )

    $title = [string]$Title
    $desc  = [string]$Description

    $hints = New-Object System.Collections.Generic.List[string]
    $reasons = New-Object System.Collections.Generic.List[string]

    if ($title -match '駅|鉄道|電車|新幹線|JR|線路|train|station') {
        $hints.Add("station")
        $reasons.Add("station keyword in title")
    }

    if ($title -match '河川|水位|増水|洪水|堤防|川ライブ|河川情報') {
        $hints.Add("river")
        $reasons.Add("river keyword in title")
    }

    if ($title -match '駐車場|パーキング') {
        $hints.Add("parking")
        $reasons.Add("parking keyword in title")
    }

    if ($title -match '空港|飛行場|airport') {
        $hints.Add("airport")
        $reasons.Add("airport keyword in title")
    }

    if ($title -match '港|漁港|フェリー|海岸|海水浴場|湾|日本海|harbor|port') {
        $hints.Add("port")
        $reasons.Add("port/coast keyword in title")
    }

    if ($title -match '富士山|山頂|展望|スキー|温泉|夕日|絶景|神社|寺|城|観光|高原|桜島|大内宿|陣屋') {
        $hints.Add("tourism")
        $reasons.Add("tourism keyword in title")
    }

    if ($title -match '国道|県道|道路|高速|自動車道|交差点|峠|バイパス|交通状況|道路状況|JCT|インターチェンジ|ラウンドアバウト|road|street|traffic') {
        $hints.Add("road")
        $reasons.Add("strong road keyword in title")
    }
    elseif ($title -match '橋') {
        $hints.Add("road")
        $reasons.Add("bridge keyword in title")
    }

    if ($title -match '繁華街|商店街|市街地|中心街|広場|街並み') {
        $hints.Add("city")
        $reasons.Add("city keyword in title")
    }

    # タイトルで何も取れない場合だけ description を補助利用
    if ($hints.Count -eq 0) {
        if ($desc -match '国道|県道|道路状況|交差点|高速道路|自動車道') {
            $hints.Add("road")
            $reasons.Add("road keyword in description only")
        }
    }

    $uniqueHints = @($hints | Select-Object -Unique)

    if ($uniqueHints.Count -eq 0) {
        return [PSCustomObject]@{
            Primary    = "other"
            Hints      = @("other")
            Confidence = "low"
            Reason     = "no strong category signal"
        }
    }

    $primary = $uniqueHints[0]

    $confidence = if ($reasons -match 'description only|bridge keyword') {
        "medium"
    } else {
        "high"
    }

    [PSCustomObject]@{
        Primary    = $primary
        Hints      = $uniqueHints
        Confidence = $confidence
        Reason     = ($reasons | Select-Object -Unique) -join "; "
    }
}

function Get-ReviewTier {
    param(
        [string]$CandidateStatus,
        [string]$LocationStatus,
        [string]$LocationReview,
        [int]$CandidateScore
    )

    if ($LocationReview -eq "mismatch") {
        return "location-review"
    }

    if (
        $CandidateStatus -eq "採用候補" -and
        $LocationStatus -eq "一致" -and
        $CandidateScore -ge 20
    ) {
        return "priority-review"
    }

    if (
        $CandidateStatus -eq "除外候補" -or
        $LocationStatus -eq "地域外"
    ) {
        return "low-priority"
    }

    if ($CandidateStatus -eq "採用候補") {
        return "normal-review"
    }

    return "needs-review"
}

Write-Host "===== LiveCameraLinks YouTube Candidate Import ====="

$master = @((Read-Utf8Json $SourcePath) | ForEach-Object { $_ })
$existing = Read-Utf8Json $DestinationPath

# 既存レビュー情報を candidateId 単位で保持
$existingMap = @{}

foreach ($old in @($existing.candidates)) {
    if (-not [string]::IsNullOrWhiteSpace([string]$old.candidateId)) {
        $existingMap[$old.candidateId] = $old
    }
}

$converted = foreach ($item in $master) {

    $candidateId = "YT-$($item.video_id)"

    $matchedQueries = @()

    if ($null -ne $item.matched_queries) {
        $matchedQueries = @(
            $item.matched_queries |
            Where-Object {
                $null -ne $_ -and
                -not [string]::IsNullOrWhiteSpace([string]$_)
            }
        )
    }

    $locationReview = Get-LocationReview `
        -Prefecture ([string]$item.prefecture) `
        -DetectedPrefecture ([string]$item.detected_prefecture)

    $category = Get-CategoryInfo `
        -Title ([string]$item.title) `
        -Description ([string]$item.description)

    $reviewTier = Get-ReviewTier `
        -CandidateStatus ([string]$item.candidate_status) `
        -LocationStatus ([string]$item.location_status) `
        -LocationReview $locationReview `
        -CandidateScore ([int]$item.candidate_score)

    $reviewStatus = "pending"
    $reviewNote = ""
    $approved = $false
    $confirmedPrefecture = ""
    $confirmedCategory = ""
    $providerName = ""
    $providerPageUrl = ""
    $officialConfirmed = $false
    $reviewedAt = ""

    if ($existingMap.ContainsKey($candidateId)) {

        $old = $existingMap[$candidateId]

        if (-not [string]::IsNullOrWhiteSpace([string]$old.reviewStatus)) {
            $reviewStatus = [string]$old.reviewStatus
        }

        if ($null -ne $old.reviewNote) {
            $reviewNote = [string]$old.reviewNote
        }

        if ($null -ne $old.approved) {
            $approved = [bool]$old.approved
        }

        if ($null -ne $old.confirmedPrefecture) {
            $confirmedPrefecture = [string]$old.confirmedPrefecture
        }

        if ($null -ne $old.confirmedCategory) {
            $confirmedCategory = [string]$old.confirmedCategory
        }

        if ($null -ne $old.providerName) {
            $providerName = [string]$old.providerName
        }

        if ($null -ne $old.providerPageUrl) {
            $providerPageUrl = [string]$old.providerPageUrl
        }

        if ($null -ne $old.officialConfirmed) {
            $officialConfirmed = ConvertTo-SafeBoolean $old.officialConfirmed
        }

        if ($null -ne $old.reviewedAt) {
            $reviewedAt = [string]$old.reviewedAt
        }
    }

    [PSCustomObject][ordered]@{
        candidateId          = $candidateId
        sourceType           = "youtube"
        sourceId             = $item.video_id

        title                = $item.title
        channelId            = $item.channel_id
        channelTitle         = $item.channel_title
        url                  = $item.url
        thumbnail            = $item.thumbnail
        description          = $item.description
        tags                 = @($item.tags)

        isLiveNow            = $item.is_live_now
        embeddable           = $item.embeddable

        prefecture           = $item.prefecture
        detectedPrefecture   = $item.detected_prefecture
        confirmedPrefecture  = $confirmedPrefecture

        searchBlock          = $item.search_block
        detectedBlock        = $item.detected_block

        locationStatus       = $item.location_status
        locationReview       = $locationReview

        primaryCategoryHint  = $category.Primary
        categoryHints        = @($category.Hints)
        categoryConfidence   = $category.Confidence
        categoryReason       = $category.Reason
        confirmedCategory    = $confirmedCategory

        candidateScore       = [int]$item.candidate_score
        candidateStatus      = $item.candidate_status
        discoveryStatus      = $item.discovery_status

        reviewTier           = $reviewTier
        reviewStatus         = $reviewStatus
        reviewNote           = $reviewNote
        approved             = $approved

        providerName         = $providerName
        providerPageUrl      = $providerPageUrl
        officialConfirmed    = $officialConfirmed
        reviewedAt           = $reviewedAt

        matchedQuery         = $item.matched_query
        matchedQueries       = $matchedQueries

        firstSeenAt          = $item.first_seen_at
        lastSeenAt           = $item.last_seen_at

        source               = "youtube-national-collector"
    }
}

$duplicates = @(
    $converted |
    Group-Object candidateId |
    Where-Object { $_.Count -gt 1 }
)

$emptyIds = @(
    $converted |
    Where-Object {
        [string]::IsNullOrWhiteSpace($_.sourceId)
    }
)

$nullMatchedQueries = @(
    $converted |
    Where-Object {
        @($_.matchedQueries | Where-Object { $null -eq $_ }).Count -gt 0
    }
)

Write-Host ""
Write-Host "Source records          :" $master.Count
Write-Host "Converted candidates    :" @($converted).Count
Write-Host "Duplicate candidateId   :" $duplicates.Count
Write-Host "Empty sourceId          :" $emptyIds.Count
Write-Host "Null matchedQueries     :" $nullMatchedQueries.Count
Write-Host "Mode                     :" $(if ($Write) { "WRITE" } else { "DRY-RUN" })

Write-Host ""
Write-Host "===== review tiers ====="

$converted |
Group-Object reviewTier |
Select-Object Name,Count |
Sort-Object Count -Descending |
Format-Table -AutoSize

Write-Host "===== category hints ====="

$converted |
Group-Object primaryCategoryHint |
Select-Object Name,Count |
Sort-Object Count -Descending |
Format-Table -AutoSize

Write-Host "===== location review ====="

$converted |
Group-Object locationReview |
Select-Object Name,Count |
Sort-Object Count -Descending |
Format-Table -AutoSize

if ($duplicates.Count -gt 0) {
    throw "Duplicate candidateId detected."
}

if ($emptyIds.Count -gt 0) {
    throw "Empty sourceId detected."
}

if ($nullMatchedQueries.Count -gt 0) {
    throw "Null value detected in matchedQueries."
}

if (-not $Write) {
    Write-Host ""
    Write-Host "Dry-run completed. No files were changed."
    exit 0
}

$existing.candidates = @($converted)

$existing.statistics.candidateCount = @($converted).Count
$existing.statistics.approvedCount = @(
    $converted | Where-Object { $_.approved -eq $true }
).Count
$existing.statistics.rejectedCount = @(
    $converted | Where-Object { $_.reviewStatus -eq "rejected" }
).Count

$existing.lastUpdated = (Get-Date).ToString("yyyy-MM-dd")

$json = $existing | ConvertTo-Json -Depth 30

$destinationFullPath = [System.IO.Path]::GetFullPath(
    (Join-Path (Get-Location) $DestinationPath)
)

[System.IO.File]::WriteAllText(
    $destinationFullPath,
    $json,
    [System.Text.UTF8Encoding]::new($false)
)

Write-Host ""
Write-Host "Written:" $DestinationPath
