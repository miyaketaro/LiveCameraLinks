#requires -Version 5.1

param(
    [string]$PreviewPath = ".\reports\approved-registration-preview.csv",
    [string]$RecordsPath = ".\reports\approved-registration-records.json"
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
Write-Host "Mode        : DRY-RUN"
Write-Host "Preview     : $PreviewPath"
Write-Host "Records     : $RecordsPath"
Write-Host ""
Write-Host "Production camera JSON files will NOT be modified."

if (-not (Test-Path $PreviewPath)) {
    throw "Preview CSV not found: $PreviewPath"
}

# --------------------------------------------------
# Step 1
# Check target-file suggestions.
# No -Write flag: preview only.
# --------------------------------------------------

Invoke-PipelineStep `
    -Name "STEP 1 - Target file suggestion" `
    -ScriptPath ".\scripts\suggest-target-files.ps1" `
    -Arguments @(
        "-PreviewPath",
        $PreviewPath
    )

# --------------------------------------------------
# Step 2
# Check camera-ID allocation.
# No -Write flag: preview only.
# --------------------------------------------------

Invoke-PipelineStep `
    -Name "STEP 2 - Camera ID allocation" `
    -ScriptPath ".\scripts\allocate-camera-ids.ps1" `
    -Arguments @(
        "-PreviewPath",
        $PreviewPath
    )

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
        $PreviewPath,
        "-OutputPath",
        $RecordsPath
    )

# --------------------------------------------------
# Step 4
# Production registration DRY-RUN.
# No -Write flag.
# --------------------------------------------------

Invoke-PipelineStep `
    -Name "STEP 4 - Production registration check" `
    -ScriptPath ".\scripts\register-approved-cameras.ps1" `
    -Arguments @(
        "-PreviewPath",
        $RecordsPath
    )

# --------------------------------------------------
# Step 5
# Camera index rebuild DRY-RUN.
# No -Write flag.
# --------------------------------------------------

Invoke-PipelineStep `
    -Name "STEP 5 - Camera index rebuild check" `
    -ScriptPath ".\scripts\rebuild-camera-index.ps1" `
    -Arguments @()

Write-Host ""
Write-Host "============================================================"
Write-Host "PIPELINE RESULT"
Write-Host "============================================================"
Write-Host "All DRY-RUN pipeline steps completed successfully."
Write-Host "Production camera JSON files were not modified."