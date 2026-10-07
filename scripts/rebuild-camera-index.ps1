#requires -Version 5.1

param(
    [switch]$Write
)

# LiveCameraLinks safe camera index rebuild
# Only index files are rebuilt.
# Camera data JSON files are never deleted or modified.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot   = Split-Path -Parent $PSScriptRoot
$CameraRoot = Join-Path $RepoRoot 'camera'

$Categories = @(
    'road',
    'river',
    'station',
    'city',
    'airport',
    'parking',
    'port',
    'tourism'
)

function Read-Utf8Json {
    param(
        [Parameter(Mandatory=$true)]
        [string]$Path
    )

    $text = [System.IO.File]::ReadAllText(
        $Path,
        [System.Text.Encoding]::UTF8
    )

    return ($text | ConvertFrom-Json)
}

function Write-Utf8Json {
    param(
        [Parameter(Mandatory=$true)]
        [string]$Path,

        [Parameter(Mandatory=$true)]
        $Object
    )

    $json = $Object | ConvertTo-Json -Depth 30

    $utf8Bom = New-Object System.Text.UTF8Encoding($true)

    [System.IO.File]::WriteAllText(
        $Path,
        $json + [Environment]::NewLine,
        $utf8Bom
    )
}

function Get-CameraCount {
    param(
        [Parameter(Mandatory=$true)]
        [string]$Path
    )

    $json = Read-Utf8Json -Path $Path

    if ($null -eq $json.PSObject.Properties['cameras']) {
        return 0
    }

    return @(
        @($json.cameras) |
        Where-Object {
            $null -ne $_ -and
            -not [string]::IsNullOrWhiteSpace(
                [string]$_.cameraId
            )
        }
    ).Count
}

Write-Host ''
Write-Host '===== LiveCameraLinks Camera Index Rebuild ====='
Write-Host ('Camera root : ' + $CameraRoot)
Write-Host ('Mode        : ' + $(if ($Write) { 'WRITE' } else { 'DRY-RUN' }))
Write-Host ''

if (-not (Test-Path -LiteralPath $CameraRoot)) {
    throw "Camera root was not found: $CameraRoot"
}

$categoryResults = @()
$masterPrefectureMap = @{}

foreach ($category in $Categories) {

    $categoryPath = Join-Path $CameraRoot $category

    if (-not (Test-Path -LiteralPath $categoryPath)) {
        continue
    }

    $prefectureResults = @()

    $prefectureDirs = @(
        Get-ChildItem `
            -LiteralPath $categoryPath `
            -Directory |
        Sort-Object Name
    )

    foreach ($prefectureDir in $prefectureDirs) {

        $areaResults = @()

        $areaFiles = @(
            Get-ChildItem `
                -LiteralPath $prefectureDir.FullName `
                -File `
                -Filter '*.json' |
            Where-Object {
                $_.Name -ne 'index.json' -and
                $_.Name -notlike '*.template.json'
            } |
            Sort-Object Name
        )

        foreach ($areaFile in $areaFiles) {

            $cameraCount = Get-CameraCount -Path $areaFile.FullName

            $areaResults += [PSCustomObject][ordered]@{
                area        = [System.IO.Path]::GetFileNameWithoutExtension(
                    $areaFile.Name
                )
                file        = $areaFile.Name
                cameraCount = $cameraCount
            }
        }

        $prefectureCameraCount = (
            $areaResults |
            Measure-Object cameraCount -Sum
        ).Sum

        if ($null -eq $prefectureCameraCount) {
            $prefectureCameraCount = 0
        }

        $prefectureResults += [PSCustomObject]@{
            prefecture  = $prefectureDir.Name
            file        = ($prefectureDir.Name + '/index.json')
            areaCount   = $areaResults.Count
            cameraCount = [int]$prefectureCameraCount
            areas       = $areaResults
            fullPath    = $prefectureDir.FullName
        }

        if (-not $masterPrefectureMap.ContainsKey($prefectureDir.Name)) {
            $masterPrefectureMap[$prefectureDir.Name] = [ordered]@{
                prefecture  = $prefectureDir.Name
                areas       = @{}
                cameraCount = 0
            }
        }

        foreach ($area in $areaResults) {
            $masterPrefectureMap[$prefectureDir.Name].areas[
                $area.area
            ] = $true
        }

        $masterPrefectureMap[$prefectureDir.Name].cameraCount +=
            [int]$prefectureCameraCount
    }

    $categoryCameraCount = (
        $prefectureResults |
        Measure-Object cameraCount -Sum
    ).Sum

    if ($null -eq $categoryCameraCount) {
        $categoryCameraCount = 0
    }

    $categoryResults += [PSCustomObject]@{
        category    = $category
        prefectures = $prefectureResults
        cameraCount = [int]$categoryCameraCount
    }
}

$totalCameraCount = (
    $categoryResults |
    Measure-Object cameraCount -Sum
).Sum

if ($null -eq $totalCameraCount) {
    $totalCameraCount = 0
}

$masterPrefectures = @(
    foreach ($key in ($masterPrefectureMap.Keys | Sort-Object)) {

        $item = $masterPrefectureMap[$key]

        [PSCustomObject][ordered]@{
            prefecture  = $item.prefecture
            areaCount   = $item.areas.Count
            cameraCount = [int]$item.cameraCount
        }
    }
)

Write-Host 'Category summary:'
Write-Host ''

$categorySummary = @(
    foreach ($item in $categoryResults) {
        [PSCustomObject]@{
            category        = $item.category
            cameraCount     = $item.cameraCount
            prefectureCount = $item.prefectures.Count
        }
    }
)

$categorySummary | Format-Table -AutoSize

Write-Host ''
Write-Host ('Total cameras     : ' + $totalCameraCount)
Write-Host ('Total prefectures : ' + $masterPrefectures.Count)

if ([int]$totalCameraCount -ne 458) {
    throw (
        'Expected 458 cameras, but calculated ' +
        $totalCameraCount +
        '. No index files were modified.'
    )
}

if (-not $Write) {
    Write-Host ''
    Write-Host 'DRY-RUN completed. No index files were modified.'
    exit 0
}

# --------------------------------------------------
# Backup existing indexes only
# --------------------------------------------------

$Stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$BackupRoot = Join-Path `
    $RepoRoot `
    ('backup\camera-index-' + $Stamp)

New-Item `
    -ItemType Directory `
    -Path $BackupRoot `
    -Force |
Out-Null

$existingIndexes = @(
    Get-ChildItem `
        -LiteralPath $CameraRoot `
        -Recurse `
        -File `
        -Filter 'index.json'
)

foreach ($indexFile in $existingIndexes) {

    $relativePath = $indexFile.FullName.Substring(
        $CameraRoot.Length
    ).TrimStart('\')

    $backupPath = Join-Path $BackupRoot $relativePath
    $backupDir = Split-Path $backupPath -Parent

    if (-not (Test-Path $backupDir)) {
        New-Item `
            -ItemType Directory `
            -Path $backupDir `
            -Force |
        Out-Null
    }

    Copy-Item `
        -LiteralPath $indexFile.FullName `
        -Destination $backupPath `
        -Force
}

$masterPath = Join-Path $CameraRoot 'camera_index.json'

if (Test-Path $masterPath) {
    Copy-Item `
        -LiteralPath $masterPath `
        -Destination (Join-Path $BackupRoot 'camera_index.json') `
        -Force
}

Write-Host ''
Write-Host ('Backup created: ' + $BackupRoot)

# --------------------------------------------------
# Write prefecture indexes
# --------------------------------------------------

foreach ($categoryResult in $categoryResults) {

    foreach ($prefecture in $categoryResult.prefectures) {

        $prefectureIndex = [ordered]@{
            schemaVersion = '1.0'
            category      = $categoryResult.category
            prefecture    = $prefecture.prefecture
            areaCount     = $prefecture.areaCount
            cameraCount   = $prefecture.cameraCount
            areas         = @(
                foreach ($area in $prefecture.areas) {
                    [ordered]@{
                        area        = $area.area
                        file        = $area.file
                        cameraCount = $area.cameraCount
                    }
                }
            )
        }

        $indexPath = Join-Path `
            $prefecture.fullPath `
            'index.json'

        Write-Utf8Json `
            -Path $indexPath `
            -Object $prefectureIndex

        Write-Host ('Written: ' + $indexPath)
    }
}

# --------------------------------------------------
# Write category indexes
# --------------------------------------------------

foreach ($categoryResult in $categoryResults) {

    $categoryIndex = [ordered]@{
        schemaVersion = '1.0'
        category      = $categoryResult.category
        prefectures   = @(
            foreach ($prefecture in $categoryResult.prefectures) {
                [ordered]@{
                    prefecture  = $prefecture.prefecture
                    file        = $prefecture.file
                    areaCount   = $prefecture.areaCount
                    cameraCount = $prefecture.cameraCount
                }
            }
        )
    }

    $categoryIndexPath = Join-Path `
        (Join-Path $CameraRoot $categoryResult.category) `
        'index.json'

    Write-Utf8Json `
        -Path $categoryIndexPath `
        -Object $categoryIndex

    Write-Host ('Written: ' + $categoryIndexPath)
}

# --------------------------------------------------
# Preserve master metadata where possible
# --------------------------------------------------

$oldMaster = $null

if (Test-Path $masterPath) {
    $oldMaster = Read-Utf8Json -Path $masterPath
}

$categoryMap = [ordered]@{}

foreach ($categoryResult in $categoryResults) {
    $categoryMap[$categoryResult.category] = [ordered]@{
        file        = ($categoryResult.category + '/index.json')
        cameraCount = $categoryResult.cameraCount
    }
}

$routeCount = 0

if (
    $null -ne $oldMaster -and
    $null -ne $oldMaster.statistics -and
    $null -ne $oldMaster.statistics.routeCount
) {
    $routeCount = [int]$oldMaster.statistics.routeCount
}

$masterIndex = [ordered]@{
    schemaVersion = if ($null -ne $oldMaster) {
        [string]$oldMaster.schemaVersion
    } else {
        '1.0'
    }

    dataVersion = if ($null -ne $oldMaster) {
        [string]$oldMaster.dataVersion
    } else {
        '1.0'
    }

    project = if (
        $null -ne $oldMaster -and
        $null -ne $oldMaster.project
    ) {
        [string]$oldMaster.project
    } else {
        'LiveCameraLinks'
    }

    description = if (
        $null -ne $oldMaster -and
        $null -ne $oldMaster.description
    ) {
        [string]$oldMaster.description
    } else {
        'LiveCameraLinks camera master index'
    }

    statistics = [ordered]@{
        cameraCount     = [int]$totalCameraCount
        prefectureCount = $masterPrefectures.Count
        areaCount       = (
            $masterPrefectures |
            Measure-Object areaCount -Sum
        ).Sum
        routeCount      = $routeCount
    }

    cameraCategories = $categoryMap

    routes = if (
        $null -ne $oldMaster -and
        $null -ne $oldMaster.routes
    ) {
        $oldMaster.routes
    } else {
        [ordered]@{
            index = 'route/index.json'
        }
    }

    prefectures = $masterPrefectures
}

Write-Utf8Json `
    -Path $masterPath `
    -Object $masterIndex

Write-Host ('Written: ' + $masterPath)

Write-Host ''
Write-Host '===== Rebuild completed ====='
Write-Host ('Camera count     : ' + $totalCameraCount)
Write-Host ('Prefecture count : ' + $masterPrefectures.Count)
Write-Host ''