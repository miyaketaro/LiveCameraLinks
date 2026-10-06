param(
    [string]$SourcePath = ".\data\camera_candidates.json",
    [string]$OutputPath = ".\reports\youtube-priority-review.csv"
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $SourcePath)) {
    throw "Source file not found: $SourcePath"
}

$source = [System.IO.File]::ReadAllText(
    (Resolve-Path $SourcePath),
    [System.Text.Encoding]::UTF8
) | ConvertFrom-Json

$existingReviewMap = @{}

if (Test-Path $OutputPath) {

    $existingRows = Import-Csv $OutputPath

    foreach ($row in $existingRows) {
        if (-not [string]::IsNullOrWhiteSpace($row.candidateId)) {
            $existingReviewMap[$row.candidateId] = $row
        }
    }
}

$items = @(
    $source.candidates |
    Where-Object {
        $_.reviewTier -eq "priority-review"
    } |
    Sort-Object `
        @{Expression={[int]$_.candidateScore};Descending=$true},
        prefecture,
        title
)

$rows = foreach ($c in $items) {

    $providerPageUrl = ""
    $providerName = ""
    $officialConfirmed = "false"
    $reviewedAt = ""

    $confirmedPrefecture = [string]$c.confirmedPrefecture
    $confirmedCategory = [string]$c.confirmedCategory
    $reviewStatus = [string]$c.reviewStatus
    $reviewNote = [string]$c.reviewNote
    $approved = [string]$c.approved

    if ($existingReviewMap.ContainsKey($c.candidateId)) {

        $old = $existingReviewMap[$c.candidateId]

        if ($null -ne $old.providerPageUrl) {
            $providerPageUrl = [string]$old.providerPageUrl
        }

        if ($null -ne $old.providerName) {
            $providerName = [string]$old.providerName
        }

        if ($null -ne $old.officialConfirmed) {
            $officialConfirmed = [string]$old.officialConfirmed
        }

        if ($null -ne $old.reviewedAt) {
            $reviewedAt = [string]$old.reviewedAt
        }

        if ($null -ne $old.confirmedPrefecture) {
            $confirmedPrefecture = [string]$old.confirmedPrefecture
        }

        if ($null -ne $old.confirmedCategory) {
            $confirmedCategory = [string]$old.confirmedCategory
        }

        if ($null -ne $old.reviewStatus) {
            $reviewStatus = [string]$old.reviewStatus
        }

        if ($null -ne $old.reviewNote) {
            $reviewNote = [string]$old.reviewNote
        }

        if ($null -ne $old.approved) {
            $approved = [string]$old.approved
        }
    }

    [PSCustomObject][ordered]@{
        candidateId         = $c.candidateId
        title               = $c.title

        prefecture          = $c.prefecture
        detectedPrefecture  = $c.detectedPrefecture
        locationReview      = $c.locationReview
        locationStatus      = $c.locationStatus

        primaryCategoryHint = $c.primaryCategoryHint
        categoryHints       = (@($c.categoryHints) -join "|")
        categoryConfidence  = $c.categoryConfidence

        candidateScore      = $c.candidateScore
        candidateStatus     = $c.candidateStatus

        channelTitle        = $c.channelTitle
        url                 = $c.url

        confirmedPrefecture = $confirmedPrefecture
        confirmedCategory   = $confirmedCategory

        providerPageUrl     = $providerPageUrl
        providerName        = $providerName
        officialConfirmed   = $officialConfirmed
        reviewedAt          = $reviewedAt

        reviewStatus        = $reviewStatus
        reviewNote          = $reviewNote
        approved            = $approved
    }
}

$outputDir = Split-Path $OutputPath -Parent

if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$rows |
Export-Csv `
    -Path $OutputPath `
    -NoTypeInformation `
    -Encoding UTF8

Write-Host "===== Priority Review Export ====="
Write-Host "Source candidates :" @($source.candidates).Count
Write-Host "Priority review   :" $rows.Count
Write-Host "Existing reviews  :" $existingReviewMap.Count
Write-Host "Output            :" $OutputPath
