param(
    [string]$ReviewPath = ".\reports\youtube-priority-review.csv",
    [string]$DestinationPath = ".\data\camera_candidates.json",
    [switch]$Write
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $ReviewPath)) {
    throw "Review CSV not found: $ReviewPath"
}

if (-not (Test-Path $DestinationPath)) {
    throw "Destination JSON not found: $DestinationPath"
}

$reviews = @(Import-Csv $ReviewPath)

$data = [System.IO.File]::ReadAllText(
    (Resolve-Path $DestinationPath),
    [System.Text.Encoding]::UTF8
) | ConvertFrom-Json

$candidates = @($data.candidates)

$candidateMap = @{}

foreach ($candidate in $candidates) {
    $candidateMap[$candidate.candidateId] = $candidate
}

$updated = 0
$approved = 0
$notFound = @()
$invalidApproved = @()

foreach ($review in $reviews) {

    if (-not $candidateMap.ContainsKey($review.candidateId)) {
        $notFound += $review.candidateId
        continue
    }

    $candidate = $candidateMap[$review.candidateId]

    $isApproved = (
        $review.reviewStatus -eq "approved" -and
        $review.approved -eq "True"
    )

    if ($isApproved) {

        if (
            [string]::IsNullOrWhiteSpace($review.confirmedPrefecture) -or
            [string]::IsNullOrWhiteSpace($review.confirmedCategory) -or
            [string]::IsNullOrWhiteSpace($review.providerName) -or
            [string]::IsNullOrWhiteSpace($review.providerPageUrl) -or
            $review.officialConfirmed -ne "true"
        ) {
            $invalidApproved += $review.candidateId
            continue
        }
    }

    $candidate.confirmedPrefecture = $review.confirmedPrefecture
    $candidate.confirmedCategory = $review.confirmedCategory
    $candidate.reviewStatus = $review.reviewStatus
    $candidate.reviewNote = $review.reviewNote
    $candidate.approved = $isApproved

    $updated++

    if ($isApproved) {
        $approved++
    }
}

Write-Host "===== Priority Review Import ====="
Write-Host ""
Write-Host "Review rows          :" $reviews.Count
Write-Host "Candidate records    :" $candidates.Count
Write-Host "Updated records      :" $updated
Write-Host "Approved records     :" $approved
Write-Host "Candidate not found  :" $notFound.Count
Write-Host "Invalid approvals    :" $invalidApproved.Count
Write-Host "Mode                 :" $(if ($Write) { "WRITE" } else { "DRY-RUN" })

if ($notFound.Count -gt 0) {
    Write-Host ""
    Write-Host "===== Candidate not found ====="
    $notFound | ForEach-Object { Write-Host $_ }
}

if ($invalidApproved.Count -gt 0) {
    Write-Host ""
    Write-Host "===== Invalid approvals ====="
    $invalidApproved | ForEach-Object { Write-Host $_ }

    throw "Approved rows are missing required confirmation fields."
}

if (-not $Write) {
    Write-Host ""
    Write-Host "Dry-run completed. No files were changed."
    exit 0
}

$data.statistics.candidateCount = $candidates.Count
$data.statistics.approvedCount = @(
    $candidates |
    Where-Object { $_.approved -eq $true }
).Count

$data.statistics.rejectedCount = @(
    $candidates |
    Where-Object { $_.reviewStatus -eq "rejected" }
).Count

$data.lastUpdated = (Get-Date).ToString("yyyy-MM-dd")

$json = $data | ConvertTo-Json -Depth 30

[System.IO.File]::WriteAllText(
    (Resolve-Path $DestinationPath),
    $json,
    [System.Text.UTF8Encoding]::new($false)
)

Write-Host ""
Write-Host "Written:" $DestinationPath
