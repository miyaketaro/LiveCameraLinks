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

$approved = @(
    $data.candidates |
    Where-Object {
        $_.approved -eq $true -and
        $_.reviewStatus -eq "approved"
    } |
    Sort-Object confirmedPrefecture, confirmedCategory, title
)

$rows = foreach ($c in $approved) {

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

    [PSCustomObject][ordered]@{
        candidateId         = $c.candidateId
        sourceId            = $c.sourceId
        title               = $c.title

        confirmedPrefecture = $c.confirmedPrefecture
        confirmedCategory   = $c.confirmedCategory

        targetFolder        = $targetFolder
        targetFile          = ""

        providerName        = $c.providerName
        providerPageUrl     = $c.providerPageUrl

        directStreamUrl     = $c.url
        channelTitle        = $c.channelTitle

        existingMatch       = ""
        existingCameraId    = ""
        registrationStatus  = "pending"
        registrationNote    = ""
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

Write-Host "===== Approved Registration Preview ====="
Write-Host "Approved candidates :" $approved.Count
Write-Host "Preview rows        :" $rows.Count
Write-Host "Output              :" $OutputPath
