param(
    [string]$SourcePath = ".\reports\approved-registration-preview.csv",
    [string]$OutputPath = ".\reports\approved-registration-records.json"
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $SourcePath)) {
    throw "Source CSV not found: $SourcePath"
}

$rows = @(Import-Csv $SourcePath)

$nameMap = @{
    "JP-19-000057" = "三郡橋北交差点"
    "JP-19-000058" = "十五所交差点"
    "JP-19-000059" = "白根IC西交差点"
    "JP-19-000060" = "富士川大橋西交差点"

    "JP-18-000001" = "丹南地域② 交通・河川情報"
    "JP-18-000002" = "丹南地域① 交通情報"
    "JP-18-000003" = "大名町交差点・福井駅西口周辺"

    "JP-01-000001" = "せたな町 北檜山交差点"
}

$placeMap = @{
    "JP-19-000057" = "山梨県南アルプス市 三郡橋北交差点"
    "JP-19-000058" = "山梨県南アルプス市 十五所交差点"
    "JP-19-000059" = "山梨県南アルプス市 白根IC西交差点"
    "JP-19-000060" = "山梨県富士川町 富士川大橋西交差点"

    "JP-18-000001" = "福井県 丹南地域（越前市・鯖江市・越前町）"
    "JP-18-000002" = "福井県 丹南地域（越前市・鯖江市・越前町）"
    "JP-18-000003" = "福井県福井市 大名町交差点・福井駅西口周辺"

    "JP-01-000001" = "北海道せたな町北檜山区 北檜山交差点"
}

$roadMap = @{
    "JP-18-000001" = "県道武生美山線・県道福井鯖江線"
    "JP-18-000002" = "国道8号・県道鯖江織田線"
}

$records = foreach ($r in $rows) {

    if ([string]::IsNullOrWhiteSpace($r.proposedCameraId)) {
        throw "Missing proposedCameraId: $($r.candidateId)"
    }

    if ([string]::IsNullOrWhiteSpace($r.targetFile)) {
        throw "Missing targetFile: $($r.candidateId)"
    }

    $kind = switch ($r.confirmedCategory) {
        "road"    { "道路" }
        "river"   { "河川" }
        "station" { "駅・鉄道" }
        "parking" { "駐車場" }
        "airport" { "空港" }
        "tourism" { "観光" }
        "port"    { "港・海" }
        "city"    { "街・繁華街" }
        default   { "その他" }
    }

    $themes = switch ($r.confirmedCategory) {
        "road"    { @("道路","渋滞・流れ","交通") }
        "river"   { @("河川","水位","防災") }
        "station" { @("駅周辺","鉄道","交通") }
        "parking" { @("駐車場","交通") }
        "airport" { @("空港","交通") }
        "tourism" { @("観光・名所") }
        "port"    { @("港","海") }
        "city"    { @("繁華街","街並み") }
        default   { @("ライブカメラ") }
    }

    $region = switch ($r.confirmedPrefecture) {
        "北海道" { "北海道" }
        "福井県" { "中部" }
        "山梨県" { "中部" }
        default  { "" }
    }

    $prefectureCode = switch ($r.confirmedPrefecture) {
        "北海道" { "hokkaido" }
        "福井県" { "fukui" }
        "山梨県" { "yamanashi" }
        default  { "" }
    }

    $areaCode = [System.IO.Path]::GetFileNameWithoutExtension($r.targetFile)

    $slugBase = $r.proposedCameraId.ToLower().Replace("-","_")
    $finalName = if ($nameMap.ContainsKey($r.proposedCameraId)) {
        $nameMap[$r.proposedCameraId]
    }
    else {
        $r.title
    }

    $finalPlace = if ($placeMap.ContainsKey($r.proposedCameraId)) {
        $placeMap[$r.proposedCameraId]
    }
    else {
        "$($r.confirmedPrefecture) $($r.title)"
    }

    $finalRoadName = if ($roadMap.ContainsKey($r.proposedCameraId)) {
        $roadMap[$r.proposedCameraId]
    }
    else {
        ""
    }

    [PSCustomObject][ordered]@{
        candidateId     = $r.candidateId
        targetFile      = $r.targetFile
        registrationStatus = $r.registrationStatus
        registrationNote   = $r.registrationNote

        record = [PSCustomObject][ordered]@{
            id              = $slugBase
            name            = $finalName
            place           = $finalPlace
            region          = $region
            kind            = $kind
            category        = $r.confirmedCategory
            road_name       = $finalRoadName

            lat             = ""
            lng             = ""
            coordinateType  = "unknown"

            source          = $r.providerName
            publisher       = $r.providerName
            providerPageUrl = $r.providerPageUrl
            directStreamUrl = $r.directStreamUrl

            linkPolicy      = "provider"
            preferredView   = "provider"
            mediaType       = "YouTube Live"

            themes          = @($themes)
            viewTargets     = @($r.title)

            status          = "active"
            sourceTier      = "配信元公式"
            lastChecked     = (Get-Date).ToString("yyyy-MM-dd")
            auditStatus     = "YouTube candidate review approved"

            prefectureCode  = $prefectureCode
            areaCode        = $areaCode
            slug            = $slugBase
            cameraId        = $r.proposedCameraId
        }
    }
}

$outputDir = Split-Path $OutputPath -Parent

if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$json = $records | ConvertTo-Json -Depth 20

[System.IO.File]::WriteAllText(
    (Join-Path (Get-Location) $OutputPath),
    $json,
    [System.Text.UTF8Encoding]::new($false)
)

Write-Host "===== Registration Record Preview ====="
Write-Host "Input rows  :" $rows.Count
Write-Host "Output rows :" $records.Count
Write-Host "Output      :" $OutputPath
