param(
    [string]$CameraRoot = ".\camera",
    [string]$ReportPath = ".\reports\source-audit\external-source-traces.csv",
    [string]$PrivateRoot = "C:\Users\a\Documents\LiveCameraLinks-private",
    [switch]$Apply
)

$ErrorActionPreference = "Stop"

# ---------------------------------------------------------
# LiveCameraLinks source separation migration
# - Default = DRY RUN
# - Public Camera JSON is changed ONLY with -Apply
# - Private provenance records are written ONLY with -Apply
# ---------------------------------------------------------

if (-not (Test-Path $CameraRoot)) {
    throw "Camera root not found: $CameraRoot"
}

if (-not (Test-Path $ReportPath)) {
    throw "Audit report not found: $ReportPath"
}

$cameraRootPath = (Resolve-Path $CameraRoot).Path
$auditRows = Import-Csv $ReportPath

# Fields that are internal provenance / audit metadata.
$moveFields = @(
    "referencePageUrl",
    "batchSource",
    "healthReason",
    "auditStatus"
)

# These fields require manual/provider verification and are NOT auto-removed.
$manualReviewFields = @(
    "url",
    "providerPageUrl",
    "directStreamUrl",
    "source",
    "publisher",
    "provider"
)

function Get-CamerasContainer($data) {
    if ($null -ne $data.cameras) { return $data.cameras }
    if ($data -is [System.Array]) { return $data }
    return $null
}

function Get-CameraKey($camera) {
    if ([string]$camera.cameraId) { return [string]$camera.cameraId }
    if ([string]$camera.slug) { return [string]$camera.slug }
    return [string]$camera.id
}

function Normalize-Slug([string]$slug) {
    if (-not $slug) { return $slug }

    $prefixes = @(
        "^cametan_",
        "^liveatlas_",
        "^fujiyama_",
        "^hirnet_",
        "^hir_net_",
        "^tomarigi_",
        "^livecombs_",
        "^livecam_asia_",
        "^livecamasia_"
    )

    $result = $slug
    foreach ($p in $prefixes) {
        $result = $result -replace $p, ""
    }
    return $result
}

# Build quick lookup from audit CSV.
$auditBySlug = @{}
foreach ($row in $auditRows) {
    $slug = [string]$row.slug
    if (-not $slug) { continue }

    if (-not $auditBySlug.ContainsKey($slug)) {
        $auditBySlug[$slug] = @()
    }
    $auditBySlug[$slug] += $row
}

$files = Get-ChildItem $CameraRoot -Recurse -Filter *.json |
    Where-Object {
        $_.Name -ne "index.json" -and
        $_.Name -ne "camera_index.json"
    } |
    Sort-Object FullName

$publicChanges = @()
$privateRecords = @()
$manualReviews = @()
$slugChanges = @()
$fileObjects = @{}

foreach ($file in $files) {
    $data = Get-Content $file.FullName -Raw -Encoding UTF8 | ConvertFrom-Json
    $fileObjects[$file.FullName] = $data

    $cameras = Get-CamerasContainer $data
    if ($null -eq $cameras) { continue }

    foreach ($camera in $cameras) {
        $oldId = [string]$camera.id
        $existingSlug = [string]$camera.slug
        $lookupSlug = if ($existingSlug) { $existingSlug } else { $oldId }

        if (-not $lookupSlug) { continue }

        $rows = @()
        if ($auditBySlug.ContainsKey($lookupSlug)) {
            $rows = $auditBySlug[$lookupSlug]
        }

        # Only process cameras identified by the audit.
        if ($rows.Count -eq 0) { continue }

        # -----------------------------------------------
        # 1. Prepare slug normalization
        # -----------------------------------------------
        $targetSlug = Normalize-Slug $lookupSlug
        if ($targetSlug -ne $lookupSlug) {
            $slugChanges += [pscustomobject]@{
                oldSlug = $lookupSlug
                newSlug = $targetSlug
                name = [string]$camera.name
                file = $file.FullName
            }
        }

        # -----------------------------------------------
        # 2. Build private provenance record
        # -----------------------------------------------
        $sources = $rows |
            Select-Object sourceKey,sourceLabel |
            Sort-Object sourceKey -Unique

        $private = [ordered]@{
            cameraId = [string]$camera.cameraId
            slug = $targetSlug
            name = [string]$camera.name
            discoverySources = @()
            movedFields = [ordered]@{}
            sourceAudit = @()
        }

        foreach ($s in $sources) {
            $private.discoverySources += [ordered]@{
                sourceKey = [string]$s.sourceKey
                sourceLabel = [string]$s.sourceLabel
            }
        }

        foreach ($row in $rows) {
            $private.sourceAudit += [ordered]@{
                severity = [string]$row.severity
                sourceKey = [string]$row.sourceKey
                sourceLabel = [string]$row.sourceLabel
                field = [string]$row.field
                value = [string]$row.value
                originalFile = [string]$row.file
            }
        }

        # -----------------------------------------------
        # 3. Fields safe to move out of public Camera JSON
        # -----------------------------------------------
        foreach ($field in $moveFields) {
            $prop = $camera.PSObject.Properties[$field]
            if ($null -eq $prop) { continue }

            $value = $prop.Value
            if ($null -eq $value) { continue }

            $text = [string]$value
            if (-not $text) { continue }

            $private.movedFields[$field] = $value

            $publicChanges += [pscustomobject]@{
                action = "MOVE_TO_PRIVATE"
                field = $field
                slug = $lookupSlug
                newSlug = $targetSlug
                name = [string]$camera.name
                value = $text
                file = $file.FullName
            }

            if ($Apply) {
                $camera.PSObject.Properties.Remove($field)
            }
        }

        # -----------------------------------------------
        # 4. Slug migration
        # -----------------------------------------------
        if ($Apply) {
            if (-not $camera.PSObject.Properties["slug"]) {
                $camera | Add-Member -NotePropertyName slug -NotePropertyValue $targetSlug -Force
            } else {
                $camera.slug = $targetSlug
            }

            # Keep old id untouched for compatibility in this migration step.
            # It will be retired later after loader/store compatibility is verified.
        }

        # -----------------------------------------------
        # 5. Manual-review fields
        # -----------------------------------------------
        foreach ($row in $rows) {
            if ($manualReviewFields -contains [string]$row.field) {
                $manualReviews += [pscustomobject]@{
                    severity = [string]$row.severity
                    sourceLabel = [string]$row.sourceLabel
                    field = [string]$row.field
                    slug = $lookupSlug
                    newSlug = $targetSlug
                    name = [string]$camera.name
                    value = [string]$row.value
                    file = $file.FullName
                    action = "VERIFY_ORIGINAL_PROVIDER_BEFORE_PUBLICATION"
                }
            }
        }

        $privateRecords += [pscustomobject]$private
    }
}

# -----------------------------------------------
# Validate target slug uniqueness
# -----------------------------------------------
$allTargetSlugs = @()

foreach ($file in $files) {
    $data = $fileObjects[$file.FullName]
    $cameras = Get-CamerasContainer $data
    if ($null -eq $cameras) { continue }

    foreach ($camera in $cameras) {
        $candidate = if ([string]$camera.slug) {
            [string]$camera.slug
        } else {
            Normalize-Slug ([string]$camera.id)
        }
        if ($candidate) { $allTargetSlugs += $candidate }
    }
}

$dupSlugs = $allTargetSlugs |
    Group-Object |
    Where-Object Count -gt 1

Write-Host ""
Write-Host "LiveCameraLinks source-separation migration"
Write-Host "Mode: $(if($Apply){'APPLY'}else{'DRY RUN'})"
Write-Host "Audit rows loaded: $($auditRows.Count)"
Write-Host "Affected cameras: $($privateRecords.Count)"
Write-Host "Public fields to move: $($publicChanges.Count)"
Write-Host "Slug changes: $($slugChanges.Count)"
Write-Host "Manual provider/url reviews: $($manualReviews.Count)"
Write-Host "Duplicate target slugs: $($dupSlugs.Count)"
Write-Host ""

if ($slugChanges.Count -gt 0) {
    Write-Host "Slug changes (first 30):"
    $slugChanges |
        Select-Object -First 30 oldSlug,newSlug,name |
        Format-Table -AutoSize
}

if ($manualReviews.Count -gt 0) {
    Write-Host ""
    Write-Host "Manual review required (first 30):"
    $manualReviews |
        Select-Object -First 30 severity,sourceLabel,field,newSlug,name |
        Format-Table -AutoSize
}

if ($dupSlugs.Count -gt 0) {
    Write-Host ""
    Write-Host "ERROR: duplicate target slug(s) found. Nothing will be applied." -ForegroundColor Red
    $dupSlugs | Select-Object Name,Count | Format-Table -AutoSize
    exit 2
}

# -----------------------------------------------
# DRY RUN: write reports only
# -----------------------------------------------
$reportDir = ".\reports\source-separation"
New-Item -ItemType Directory -Force -Path $reportDir | Out-Null

$publicChanges |
    Export-Csv (Join-Path $reportDir "public-fields-to-move.csv") -NoTypeInformation -Encoding UTF8

$slugChanges |
    Export-Csv (Join-Path $reportDir "slug-changes.csv") -NoTypeInformation -Encoding UTF8

$manualReviews |
    Export-Csv (Join-Path $reportDir "manual-provider-review.csv") -NoTypeInformation -Encoding UTF8

$privateRecords |
    ConvertTo-Json -Depth 30 |
    Set-Content (Join-Path $reportDir "private-provenance-preview.json") -Encoding UTF8

if (-not $Apply) {
    Write-Host ""
    Write-Host "DRY RUN only."
    Write-Host "No public Camera JSON files were changed."
    Write-Host "No private provenance files were written."
    Write-Host ""
    Write-Host "Review:"
    Write-Host "  $reportDir\public-fields-to-move.csv"
    Write-Host "  $reportDir\slug-changes.csv"
    Write-Host "  $reportDir\manual-provider-review.csv"
    Write-Host "  $reportDir\private-provenance-preview.json"
    exit 0
}

# -----------------------------------------------
# APPLY
# -----------------------------------------------
# Public camera JSON: write modified objects.
$byFile = @{}
foreach ($file in $files) {
    $byFile[$file.FullName] = $fileObjects[$file.FullName]
}

foreach ($path in $byFile.Keys) {
    $json = $byFile[$path] | ConvertTo-Json -Depth 40
    [System.IO.File]::WriteAllText(
        $path,
        $json,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

# Private provenance lives outside repository.
$privateCollector = Join-Path $PrivateRoot "collector\candidates"
New-Item -ItemType Directory -Force -Path $privateCollector | Out-Null

$privateFile = Join-Path $privateCollector "provenance-migrated.json"
$privateRecords |
    ConvertTo-Json -Depth 30 |
    Set-Content $privateFile -Encoding UTF8

Write-Host ""
Write-Host "Applied successfully." -ForegroundColor Green
Write-Host "Public Camera JSON provenance fields were removed."
Write-Host "Slug prefixes were normalized."
Write-Host "Old id fields were preserved for compatibility."
Write-Host "Private provenance written to:"
Write-Host "  $privateFile"
Write-Host ""
Write-Host "IMPORTANT:"
Write-Host "Manual provider/url review items were NOT auto-corrected."
Write-Host "Review:"
Write-Host "  $reportDir\manual-provider-review.csv"
