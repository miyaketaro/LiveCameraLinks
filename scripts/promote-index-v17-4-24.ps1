param(
    [switch]$Apply
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = 'C:\Users\a\Documents\GitHub\LiveCameraLinks'
$Candidate = Join-Path $RepoRoot 'index-v17-4-23-production-candidate.html'
$Target = Join-Path $RepoRoot 'index.html'
$Stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$BackupDir = Join-Path $RepoRoot ('backup_index_v17_4_23_' + $Stamp)
$BackupFile = Join-Path $BackupDir 'index.html'

Write-Host ''
Write-Host 'LiveCameraLinks index promotion v17.4.24'
Write-Host ('Mode      : ' + ($(if ($Apply) { 'APPLY' } else { 'DRY RUN' })))
Write-Host ('Repository: ' + $RepoRoot)
Write-Host ('Candidate : ' + $Candidate)
Write-Host ('Target    : ' + $Target)
Write-Host ''

if (-not (Test-Path -LiteralPath $RepoRoot)) {
    throw "Repository folder not found: $RepoRoot"
}
if (-not (Test-Path -LiteralPath $Candidate)) {
    throw "Candidate file not found: $Candidate"
}

$candidateText = Get-Content -LiteralPath $Candidate -Raw -Encoding UTF8

# JSON-driven builds intentionally keep an empty runtime array:
#   const CAMERAS=[];
# This is NOT the old embedded camera dataset.
$hasEmptyRuntimeArray = ($candidateText -match 'const\s+CAMERAS\s*=\s*\[\s*\]\s*;')

# Reject only a literal CAMERAS array that already contains data.
$hasNonEmptyEmbeddedArray = ($candidateText -match 'const\s+CAMERAS\s*=\s*\[\s*(?!\])[\s\S]*?\]\s*;')

# Also reject obvious old large inline object payloads assigned directly to CAMERAS.
# This check is intentionally narrow to avoid treating the empty runtime store as legacy data.
$hasLegacyEmbeddedCameras = $hasNonEmptyEmbeddedArray -and (-not $hasEmptyRuntimeArray)

$checks = [ordered]@{
    candidateExists           = $true
    hasCameraIndexReference   = ($candidateText -match 'camera_index\.json')
    hasCameraId               = ($candidateText -match 'cameraId')
    hasFavoriteTop            = ($candidateText -match 'camera-favorite-top-v174')
    hasV17422FavoriteRule     = ($candidateText -match 'v17422-favorite-white')
    hasEmptyRuntimeCameras    = $hasEmptyRuntimeArray
    hasLegacyEmbeddedCameras  = $hasLegacyEmbeddedCameras
}

Write-Host 'Checks:'
$checks.GetEnumerator() | ForEach-Object {
    Write-Host ('  {0,-27}: {1}' -f $_.Key, $_.Value)
}
Write-Host ''

if (-not $checks.hasCameraIndexReference) {
    throw 'camera_index.json reference was not found.'
}
if (-not $checks.hasCameraId) {
    throw 'cameraId support was not found.'
}
if (-not $checks.hasEmptyRuntimeCameras) {
    throw 'Expected empty runtime CAMERAS array was not found.'
}
if ($checks.hasLegacyEmbeddedCameras) {
    throw 'Legacy non-empty embedded CAMERAS dataset still exists. Promotion stopped.'
}

if (-not $Apply) {
    Write-Host 'DRY RUN only. No files were changed.'
    Write-Host 'The empty "const CAMERAS=[];" runtime store is allowed.'
    Write-Host 'After review, run again with -Apply.'
    exit 0
}

if (Test-Path -LiteralPath $Target) {
    New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null
    Copy-Item -LiteralPath $Target -Destination $BackupFile -Force
    Write-Host ('[OK] Backup created: ' + $BackupFile)
}

Copy-Item -LiteralPath $Candidate -Destination $Target -Force
Write-Host ('[OK] Production index updated: ' + $Target)
Write-Host ''
Write-Host 'Next:'
Write-Host '  1. Start local server from repository root.'
Write-Host '  2. Open http://localhost:8000/'
Write-Host '  3. Search for 竹橋 and verify card/actions/favorite.'
Write-Host '  4. Review Git diff before commit/push.'
