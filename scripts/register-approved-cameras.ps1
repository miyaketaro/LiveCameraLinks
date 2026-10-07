param(
    [string]$PreviewPath = ".\reports\approved-registration-records.json",
    [switch]$Write
)

$ErrorActionPreference = "Stop"

function Read-Utf8Json {
    param([string]$Path)

    if (-not (Test-Path $Path)) {
        throw "File not found: $Path"
    }

    return [System.IO.File]::ReadAllText(
        (Resolve-Path $Path),
        [System.Text.Encoding]::UTF8
    ) | ConvertFrom-Json
}

function Write-Utf8BomJson {
    param(
        [string]$Path,
        $Object
    )

    $dir = Split-Path $Path -Parent

    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }

    $json = $Object | ConvertTo-Json -Depth 30

    $utf8Bom = New-Object System.Text.UTF8Encoding($true)

    [System.IO.File]::WriteAllText(
        $Path,
        $json,
        $utf8Bom
    )
}

Write-Host "===== Register Approved Cameras ====="

$preview = @((Read-Utf8Json $PreviewPath) | ForEach-Object { $_ })

if ($preview.Count -eq 0) {
    throw "No preview records found."
}

# --------------------------------------------------
# 既存 cameraId 全件取得
# --------------------------------------------------

$existingIds = @{}

Get-ChildItem .\camera -Recurse -Filter *.json |
ForEach-Object {

    try {
        $json = [System.IO.File]::ReadAllText(
            $_.FullName,
            [System.Text.Encoding]::UTF8
        ) | ConvertFrom-Json

        foreach ($camera in @($json.cameras)) {

            if (
                $null -ne $camera -and
                -not [string]::IsNullOrWhiteSpace([string]$camera.cameraId)
            ) {
                $existingIds[[string]$camera.cameraId] = $_.FullName
            }
        }
    }
    catch {
        throw "Failed to parse existing JSON: $($_.FullName)"
    }
}

# --------------------------------------------------
# preview 側の検証
# --------------------------------------------------

$previewIds = @(
    $preview |
    ForEach-Object { $_.record.cameraId }
)

$duplicatePreviewIds = @(
    $previewIds |
    Group-Object |
    Where-Object { $_.Count -gt 1 }
)

if ($duplicatePreviewIds.Count -gt 0) {
    throw "Duplicate cameraId found inside preview."
}

$collisions = @(
    $preview |
    Where-Object {
        $existingIds.ContainsKey([string]$_.record.cameraId)
    }
)

if ($collisions.Count -gt 0) {

    Write-Host ""
    Write-Host "Existing cameraId collisions:"

    $collisions |
    ForEach-Object {
        [PSCustomObject]@{
            cameraId   = $_.record.cameraId
            targetFile = $_.targetFile
            existing   = $existingIds[[string]$_.record.cameraId]
        }
    } |
    Format-Table -AutoSize

    throw "cameraId collision detected."
}

# --------------------------------------------------
# targetFile ごとにグループ化
# --------------------------------------------------

$groups = @(
    $preview |
    Group-Object targetFile
)

Write-Host ""
Write-Host "Preview records :" $preview.Count
Write-Host "Target files    :" $groups.Count
Write-Host "Mode            :" $(if ($Write) { "WRITE" } else { "DRY-RUN" })

Write-Host ""
Write-Host "===== target files ====="

$targetSummary = @(
    foreach ($group in $groups) {

        $targetFile = [string]$group.Name
        $exists = Test-Path $targetFile

        $existingCount = 0

        if ($exists) {

            $existing = Read-Utf8Json $targetFile
            $existingCount = @($existing.cameras).Count
        }

        [PSCustomObject]@{
            targetFile    = $targetFile
            exists        = $exists
            existingCount = $existingCount
            addCount      = $group.Count
            finalCount    = $existingCount + $group.Count
        }
    }
)

$targetSummary |
Format-Table -AutoSize

if (-not $Write) {

    Write-Host ""
    Write-Host "Dry-run completed. No camera files were changed."
    exit 0
}

# --------------------------------------------------
# WRITE
# --------------------------------------------------

foreach ($group in $groups) {

    $targetFile = [string]$group.Name

    if (Test-Path $targetFile) {

        $target = Read-Utf8Json $targetFile

        $existingCameras = @($target.cameras)

        $target.cameras = @(
            $existingCameras +
            @($group.Group | ForEach-Object { $_.record })
        )

        $target.cameraCount = @($target.cameras).Count
    }
    else {

        $first = $group.Group[0]

        $target = [PSCustomObject][ordered]@{
            schemaVersion = "1.0"
            category      = [string]$first.record.category
            prefecture    = [string]$first.record.prefectureCode
            area          = [string]$first.record.areaCode
            cameraCount   = $group.Count
            cameras       = @(
                $group.Group |
                ForEach-Object { $_.record }
            )
        }
    }

    Write-Utf8BomJson `
        -Path $targetFile `
        -Object $target

    Write-Host "Written:" $targetFile
}

Write-Host ""
Write-Host "Registration completed."
