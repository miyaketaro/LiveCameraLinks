param(
    [string]$SourcePath = ".\data\camera_candidates.json",
    [string]$OutputPath = ".\reports\approved-registration-preview.csv"
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $SourcePath)) {
    throw "Source file not found: $SourcePath"
}

$data = [System.IO.File]::ReadAllText(
    (Resolve-Path $SourcePath),
    [System.Text.Encoding]::UTF8
) | ConvertFrom-Json

# --------------------------------------------------
# 既存CSVの手動設定を保持
# --------------------------------------------------

$existingMap = @{}

if (Test-Path $OutputPath) {

    $existingRows = @(Import-Csv $OutputPath)

    foreach ($row in $existingRows) {

        if (
            -not [string]::IsNullOrWhiteSpace(
                [string]$row.candidateId
            )
        ) {
            $existingMap[[string]$row.candidateId] = $row
        }
    }
}

# --------------------------------------------------
# 承認済み候補
# --------------------------------------------------

$approved = @(
    $data.candidates |
    Where-Object {
        $_.approved -eq $true -and
        $_.reviewStatus -eq "approved"
    } |
    Sort-Object confirmedPrefecture, confirmedCategory, title
)

$rows = @(
    foreach ($c in $approved) {

        $targetFolder = switch ($c.confirmedCategory) {
            "road"    { "camera/road" }
            "river"   { "camera/river" }
            "station" { "camera/station" }
            "parking" { "camera/parking" }
            "airport" { "camera/airport" }
            "tourism" { "camera/tourism" }
            "port"    { "camera/port" }
            "city"    { "camera/city" }
            default   { "camera/other" }
        }

        # 新規候補の初期値
        $targetFile = ""
        $proposedCameraId = ""
        $existingMatch = ""
        $existingCameraId = ""
        $registrationStatus = "pending"
        $registrationNote = ""

        # 既存CSVに同一candidateIdがあれば手動情報を復元
        if ($existingMap.ContainsKey([string]$c.candidateId)) {

            $old = $existingMap[[string]$c.candidateId]

            if ($null -ne $old.targetFile) {
                $targetFile = [string]$old.targetFile
            }

            if ($null -ne $old.proposedCameraId) {
                $proposedCameraId = [string]$old.proposedCameraId
            }

            if ($null -ne $old.existingMatch) {
                $existingMatch = [string]$old.existingMatch
            }

            if ($null -ne $old.existingCameraId) {
                $existingCameraId = [string]$old.existingCameraId
            }

            if (
                -not [string]::IsNullOrWhiteSpace(
                    [string]$old.registrationStatus
                )
            ) {
                $registrationStatus =
                    [string]$old.registrationStatus
            }

            if ($null -ne $old.registrationNote) {
                $registrationNote =
                    [string]$old.registrationNote
            }
        }

        [PSCustomObject][ordered]@{
            candidateId         = $c.candidateId
            sourceId            = $c.sourceId
            title               = $c.title

            confirmedPrefecture = $c.confirmedPrefecture
            confirmedCategory   = $c.confirmedCategory

            targetFolder        = $targetFolder
            targetFile          = $targetFile
            proposedCameraId    = $proposedCameraId

            providerName        = $c.providerName
            providerPageUrl     = $c.providerPageUrl

            directStreamUrl     = $c.url
            channelTitle        = $c.channelTitle

            existingMatch       = $existingMatch
            existingCameraId    = $existingCameraId
            registrationStatus  = $registrationStatus
            registrationNote    = $registrationNote
        }
    }
)

$outputDir = Split-Path $OutputPath -Parent

if (-not (Test-Path $outputDir)) {
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

Write-Host "===== Approved Registration Preview ====="
Write-Host "Approved candidates :" $approved.Count
Write-Host "Preview rows        :" $rows.Count
Write-Host "Existing rows       :" $existingMap.Count
Write-Host "Output              :" $OutputPath