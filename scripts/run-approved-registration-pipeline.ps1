#requires -Version 5.1

param(
    [string]$PreviewPath = ".\reports\approved-registration-preview.csv",
    [string]$RecordsPath = ".\reports\approved-registration-records.json",
    [switch]$Write
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Invoke-PipelineStep {
    param(
        [string]$Name,
        [string]$ScriptPath,
        [string[]]$Arguments
    )

    Write-Host ""
    Write-Host "============================================================"
    Write-Host $Name
    Write-Host "============================================================"

    if (-not (Test-Path $ScriptPath)) {
        throw "Script not found: $ScriptPath"
    }

    & powershell.exe `
        -NoProfile `
        -ExecutionPolicy Bypass `
        -File $ScriptPath `
        @Arguments

    if ($LASTEXITCODE -ne 0) {
        throw "$Name failed with exit code $LASTEXITCODE"
    }

    Write-Host ""
    Write-Host "$Name : OK"
}

Write-Host "===== LiveCameraLinks Approved Registration Pipeline ====="
Write-Host "Mode        :" $(if ($Write) { "WRITE" } else { "DRY-RUN" })
Write-Host "Preview     : $PreviewPath"
Write-Host "Records     : $RecordsPath"
Write-Host ""

if ($Write) {
    Write-Host "Production camera JSON files WILL be modified."
}
else {
    Write-Host "Production camera JSON files will NOT be modified."
}

if (-not (Test-Path $PreviewPath)) {
    throw "Preview CSV not found: $PreviewPath"
}

$effectivePreviewPath = $PreviewPath
$effectiveRecordsPath = $RecordsPath
$tempPreviewPath = $null
$tempRecordsPath = $null

if (-not $Write) {

    $previewDir = Split-Path $PreviewPath -Parent

    if ([string]::IsNullOrWhiteSpace($previewDir)) {
        $previewDir = "."
    }

    $token = [Guid]::NewGuid().ToString("N")

    $tempPreviewPath = Join-Path `
        $previewDir `
        ("pipeline-dryrun-" + $token + ".csv")

    $tempRecordsPath = Join-Path `
        $previewDir `
        ("pipeline-dryrun-" + $token + ".json")

    Copy-Item `
        -LiteralPath $PreviewPath `
        -Destination $tempPreviewPath `
        -Force

    $effectivePreviewPath = $tempPreviewPath
    $effectiveRecordsPath = $tempRecordsPath

    Write-Host "DRY-RUN working copy:" $effectivePreviewPath
}

# --------------------------------------------------
# Step 1
# Check target-file suggestions.
# In WRITE mode, applies target-file suggestions.
# --------------------------------------------------

$step1Arguments = @(
    "-PreviewPath",
    $effectivePreviewPath
)

$step1Arguments += "-Write"

Invoke-PipelineStep `
    -Name "STEP 1 - Target file suggestion" `
    -ScriptPath ".\scripts\suggest-target-files.ps1" `
    -Arguments $step1Arguments

# --------------------------------------------------
# Step 2
# Check camera-ID allocation.
# In WRITE mode, applies target-file suggestions.
# --------------------------------------------------

$step2Arguments = @(
    "-PreviewPath",
    $effectivePreviewPath
)

$step2Arguments += "-Write"

Invoke-PipelineStep `
    -Name "STEP 2 - Camera ID allocation" `
    -ScriptPath ".\scripts\allocate-camera-ids.ps1" `
    -Arguments $step2Arguments

# --------------------------------------------------
# Safety gate
# Verify all unregistered rows have targetFile
# and proposedCameraId before continuing.
# --------------------------------------------------

$registrationRows = @(Import-Csv $effectivePreviewPath)

$registrationTargets = @(
    $registrationRows |
    Where-Object {
        [string]$_.registrationStatus -ne "registered"
    }
)

$invalidTargets = @(
    $registrationTargets |
    Where-Object {
        [string]::IsNullOrWhiteSpace(
            [string]$_.targetFile
        ) -or
        [string]::IsNullOrWhiteSpace(
            [string]$_.proposedCameraId
        )
    }
)

Write-Host ""
Write-Host "===== Registration Safety Gate ====="
Write-Host "Unregistered rows :" $registrationTargets.Count
Write-Host "Invalid rows      :" $invalidTargets.Count

if ($invalidTargets.Count -gt 0) {

    $invalidTargets |
    Select-Object `
        candidateId,
        confirmedPrefecture,
        confirmedCategory,
        targetFile,
        proposedCameraId |
    Format-Table -AutoSize

    throw (
        "Registration safety gate failed. " +
        "Resolve targetFile or proposedCameraId before continuing."
    )
}

Write-Host "Safety gate       : OK"

# --------------------------------------------------
# Step 3
# Generate registration-record preview.
#
# This writes only the derived report JSON.
# It does NOT modify camera/*.json.
# --------------------------------------------------

Invoke-PipelineStep `
    -Name "STEP 3 - Registration record generation" `
    -ScriptPath ".\scripts\export-approved-registration-records.ps1" `
    -Arguments @(
        "-SourcePath",
        $effectivePreviewPath,
        "-OutputPath",
        $effectiveRecordsPath
    )

# --------------------------------------------------
# Step 4
# Production registration DRY-RUN.
# No -Write flag.
# --------------------------------------------------

$step4Arguments = @(
    "-PreviewPath",
    $effectiveRecordsPath,
    "-RegistrationCsvPath",
    $effectivePreviewPath
)

if ($Write) {
    $step4Arguments += "-Write"
}

Invoke-PipelineStep `
    -Name $(if ($Write) {
        "STEP 4 - Production registration"
    }
    else {
        "STEP 4 - Production registration check"
    }) `
    -ScriptPath ".\scripts\register-approved-cameras.ps1" `
    -Arguments $step4Arguments

# --------------------------------------------------
# Step 5
# Camera index rebuild DRY-RUN.
# No -Write flag.
# --------------------------------------------------

$step5Arguments = @()

if ($Write) {
    $step5Arguments += "-Write"
}

Invoke-PipelineStep `
    -Name $(if ($Write) {
        "STEP 5 - Camera index rebuild"
    }
    else {
        "STEP 5 - Camera index rebuild check"
    }) `
    -ScriptPath ".\scripts\rebuild-camera-index.ps1" `
    -Arguments $step5Arguments

Write-Host ""
Write-Host "============================================================"
Write-Host "PIPELINE RESULT"
Write-Host "============================================================"

if ($Write) {
    Write-Host "All WRITE pipeline steps completed successfully."
    Write-Host "Production camera JSON files and indexes were updated."
}
else {
    Write-Host "All DRY-RUN pipeline steps completed successfully."
    Write-Host "Production camera JSON files were not modified."

    if (
        $null -ne $tempPreviewPath -and
        (Test-Path $tempPreviewPath)
    ) {
        Remove-Item $tempPreviewPath -Force
    }

    if (
        $null -ne $tempRecordsPath -and
        (Test-Path $tempRecordsPath)
    ) {
        Remove-Item $tempRecordsPath -Force
    }

    Write-Host "Temporary DRY-RUN files were removed."
}
