#requires -Version 5.1

param(
    [string]$SourcePath = ".\data\camera_candidates.json",
    [string]$OutputPath = ".\reports\review-batch.csv",
    [int]$BatchSize = 10,
    [string]$ReviewTier = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Read-Utf8Json {
    param(
        [Parameter(Mandatory=$true)]
        [string]$Path
    )

    if (-not (Test-Path $Path)) {
        throw "File not found: $Path"
    }

    return [System.IO.File]::ReadAllText(
        (Resolve-Path $Path),
        [System.Text.Encoding]::UTF8
    ) | ConvertFrom-Json
}

if ($BatchSize -le 0) {
    throw "BatchSize must be greater than 0."
}

Write-Host "===== Review Batch Export ====="
Write-Host "Source     :" $SourcePath
Write-Host "Output     :" $OutputPath
Write-Host "Batch size :" $BatchSize
Write-Host "Review tier:" $(if (
    [string]::IsNullOrWhiteSpace($ReviewTier)
) {
    "(all pending tiers)"
}
else {
    $ReviewTier
})

$data = Read-Utf8Json -Path $SourcePath
$candidates = @($data.candidates)

if ($candidates.Count -eq 0) {
    throw "No candidate records found."
}

$pending = @(
    $candidates |
    Where-Object {
        [string]$_.reviewStatus -eq "pending" -and
        $_.approved -ne $true
    }
)

if (-not [string]::IsNullOrWhiteSpace($ReviewTier)) {
    $pending = @(
        $pending |
        Where-Object {
            [string]$_.reviewTier -eq $ReviewTier
        }
    )
}

$tierOrder = @{
    "priority-review" = 1
    "location-review" = 2
    "normal-review"   = 3
    "needs-review"    = 4
    "low-priority"    = 5
}

$sorted = @(
    $pending |
    Sort-Object `
        @{
            Expression = {
                $tier = [string]$_.reviewTier

                if ($tierOrder.ContainsKey($tier)) {
                    $tierOrder[$tier]
                }
                else {
                    99
                }
            }
            Ascending = $true
        },
        @{
            Expression = {
                try {
                    [int]$_.candidateScore
                }
                catch {
                    0
                }
            }
            Descending = $true
        },
        @{
            Expression = {
                [string]$_.candidateId
            }
            Ascending = $true
        }
)

$batch = @(
    $sorted |
    Select-Object -First $BatchSize
)

$rows = @(
    foreach ($candidate in $batch) {

        [PSCustomObject][ordered]@{
            candidateId         = [string]$candidate.candidateId
            title               = [string]$candidate.title

            prefecture          = [string]$candidate.prefecture
            detectedPrefecture  = [string]$candidate.detectedPrefecture
            locationReview      = [string]$candidate.locationReview
            locationStatus      = [string]$candidate.locationStatus

            primaryCategoryHint = [string]$candidate.primaryCategoryHint
            categoryHints       = [string]$candidate.categoryHints
            categoryConfidence  = [string]$candidate.categoryConfidence

            candidateScore      = [string]$candidate.candidateScore
            candidateStatus     = [string]$candidate.candidateStatus

            channelTitle        = [string]$candidate.channelTitle
            url                 = [string]$candidate.url
            isLiveNow           = [string]$candidate.isLiveNow

            reviewTier          = [string]$candidate.reviewTier
            reviewStatus        = [string]$candidate.reviewStatus

            confirmedPrefecture = [string]$candidate.confirmedPrefecture
            confirmedCategory   = [string]$candidate.confirmedCategory

            providerName        = [string]$candidate.providerName
            providerPageUrl     = [string]$candidate.providerPageUrl

            officialConfirmed   = [string]$candidate.officialConfirmed
            reviewedAt          = [string]$candidate.reviewedAt
            reviewNote          = [string]$candidate.reviewNote
            approved            = [string]$candidate.approved
        }
    }
)

$outputDir = Split-Path $OutputPath -Parent

if (
    -not [string]::IsNullOrWhiteSpace($outputDir) -and
    -not (Test-Path $outputDir)
) {
    New-Item `
        -ItemType Directory `
        -Path $outputDir `
        -Force |
    Out-Null
}

$rows |
Export-Csv `
    -Path $OutputPath `
    -NoTypeInformation `
    -Encoding UTF8

Write-Host ""
Write-Host "Total candidates :" $candidates.Count
Write-Host "Pending matches  :" $pending.Count
Write-Host "Batch rows       :" $rows.Count
Write-Host "Output           :" $OutputPath

Write-Host ""

if ($rows.Count -gt 0) {

    $rows |
    Select-Object `
        candidateId,
        reviewTier,
        candidateScore,
        prefecture,
        primaryCategoryHint,
        title |
    Format-Table -AutoSize
}
else {
    Write-Host "No pending candidates matched the requested filters."
}
