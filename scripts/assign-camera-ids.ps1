param(
    [string]$CameraRoot = ".\camera",
    [switch]$Apply
)

$ErrorActionPreference = "Stop"

$regionCodes = @{
    "hokkaido"  = "01"; "aomori"    = "02"; "iwate"     = "03"; "miyagi"    = "04"
    "akita"     = "05"; "yamagata"  = "06"; "fukushima" = "07"; "ibaraki"   = "08"
    "tochigi"   = "09"; "gunma"     = "10"; "saitama"   = "11"; "chiba"     = "12"
    "tokyo"     = "13"; "kanagawa"  = "14"; "niigata"   = "15"; "toyama"    = "16"
    "ishikawa"  = "17"; "fukui"     = "18"; "yamanashi" = "19"; "nagano"    = "20"
    "gifu"      = "21"; "shizuoka"  = "22"; "aichi"     = "23"; "mie"       = "24"
    "shiga"     = "25"; "kyoto"     = "26"; "osaka"     = "27"; "hyogo"     = "28"
    "nara"      = "29"; "wakayama"  = "30"; "tottori"   = "31"; "shimane"   = "32"
    "okayama"   = "33"; "hiroshima" = "34"; "yamaguchi" = "35"; "tokushima" = "36"
    "kagawa"    = "37"; "ehime"     = "38"; "kochi"     = "39"; "fukuoka"   = "40"
    "saga"      = "41"; "nagasaki"  = "42"; "kumamoto"  = "43"; "oita"      = "44"
    "miyazaki"  = "45"; "kagoshima" = "46"; "okinawa"   = "47"
}

function Get-CamerasContainer($data) {
    if ($null -ne $data.cameras) { return $data.cameras }
    if ($data -is [System.Array]) { return $data }
    return $null
}

$rootPath = (Resolve-Path $CameraRoot).Path

$files = Get-ChildItem $CameraRoot -Recurse -Filter *.json |
    Where-Object {
        $_.Name -ne "index.json" -and
        $_.Name -ne "camera_index.json"
    } |
    Sort-Object FullName

$records = @()

foreach ($file in $files) {
    $relative = $file.FullName.Replace($rootPath, "").Trim("\")
    $parts = $relative -split "\\"

    if ($parts.Count -lt 3) { continue }

    $category = $parts[0].ToLowerInvariant()
    $prefectureSlug = $parts[1].ToLowerInvariant()

    if (-not $regionCodes.ContainsKey($prefectureSlug)) { continue }

    $regionCode = $regionCodes[$prefectureSlug]
    $areaSlug = [System.IO.Path]::GetFileNameWithoutExtension($file.Name).ToLowerInvariant()

    $data = Get-Content $file.FullName -Raw -Encoding UTF8 | ConvertFrom-Json
    $cameras = Get-CamerasContainer $data
    if ($null -eq $cameras) { continue }

    foreach ($camera in $cameras) {
        $existingSlug = [string]$camera.slug
        $oldId = [string]$camera.id
        $slug = if ($existingSlug) { $existingSlug } else { $oldId }

        $records += [pscustomobject]@{
            File = $file.FullName
            Data = $data
            Camera = $camera
            Category = $category
            RegionCode = $regionCode
            PrefectureSlug = $prefectureSlug
            AreaSlug = $areaSlug
            SortSlug = $slug.ToLowerInvariant()
        }
    }
}

$records = $records | Sort-Object `
    @{Expression="RegionCode"; Ascending=$true}, `
    @{Expression="SortSlug"; Ascending=$true}, `
    @{Expression="Category"; Ascending=$true}, `
    @{Expression="AreaSlug"; Ascending=$true}, `
    @{Expression="File"; Ascending=$true}

$used = @{}
foreach ($r in $records) {
    if (-not $used.ContainsKey($r.RegionCode)) {
        $used[$r.RegionCode] = New-Object System.Collections.Generic.HashSet[int]
    }

    $cid = [string]$r.Camera.cameraId
    if ($cid -match '^JP-(\d{2})-(\d{6})$' -and $Matches[1] -eq $r.RegionCode) {
        [void]$used[$r.RegionCode].Add([int]$Matches[2])
    }
}

$next = @{}
foreach ($region in $used.Keys) {
    if ($used[$region].Count -eq 0) {
        $next[$region] = 1
    } else {
        $next[$region] = (($used[$region] | Measure-Object -Maximum).Maximum + 1)
    }
}

$changes = @()

foreach ($r in $records) {
    $camera = $r.Camera

    if (-not $camera.PSObject.Properties["slug"]) {
        $oldId = [string]$camera.id
        if ($oldId) {
            $camera | Add-Member -NotePropertyName slug -NotePropertyValue $oldId
        }
    }

    if (-not [string]$camera.cameraId) {
        if (-not $next.ContainsKey($r.RegionCode)) {
            $next[$r.RegionCode] = 1
        }

        while ($used[$r.RegionCode].Contains([int]$next[$r.RegionCode])) {
            $next[$r.RegionCode]++
        }

        $number = [int]$next[$r.RegionCode]
        $newId = "JP-{0}-{1:D6}" -f $r.RegionCode, $number

        $camera | Add-Member -NotePropertyName cameraId -NotePropertyValue $newId -Force
        [void]$used[$r.RegionCode].Add($number)
        $next[$r.RegionCode]++

        $changes += [pscustomobject]@{
            CameraId = $newId
            Slug = [string]$camera.slug
            Name = [string]$camera.name
            Region = $r.RegionCode
            Category = $r.Category
            Area = $r.AreaSlug
            File = $r.File
        }
    }
}

$allIds = $records | ForEach-Object { [string]$_.Camera.cameraId } | Where-Object { $_ }
$dupIds = $allIds | Group-Object | Where-Object Count -gt 1

$allSlugs = $records | ForEach-Object { [string]$_.Camera.slug } | Where-Object { $_ }
$dupSlugs = $allSlugs | Group-Object | Where-Object Count -gt 1

Write-Host ""
Write-Host "LiveCameraLinks cameraId migration preview (deterministic order)"
Write-Host "Records: $($records.Count)"
Write-Host "New cameraId: $($changes.Count)"
Write-Host "Duplicate cameraId: $($dupIds.Count)"
Write-Host "Duplicate slug: $($dupSlugs.Count)"
Write-Host ""
Write-Host "Order: RegionCode -> slug -> category -> area -> file"
Write-Host ""

$changes | Select-Object -First 30 | Format-Table CameraId,Slug,Name -AutoSize

if ($dupIds.Count -gt 0) {
    Write-Host "ERROR: duplicate cameraId exists. Nothing will be written." -ForegroundColor Red
    exit 2
}

if ($dupSlugs.Count -gt 0) {
    Write-Host "WARNING: duplicate slug exists. Review before applying." -ForegroundColor Yellow
    $dupSlugs | Select-Object Name,Count | Format-Table
}

if (-not $Apply) {
    Write-Host ""
    Write-Host "DRY RUN only. No files were changed."
    Write-Host "After review, run again with -Apply."
    exit 0
}

$byFile = $records | Group-Object File

foreach ($group in $byFile) {
    $first = $group.Group | Select-Object -First 1
    $json = $first.Data | ConvertTo-Json -Depth 40

    [System.IO.File]::WriteAllText(
        $group.Name,
        $json,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

Write-Host ""
Write-Host "Applied successfully." -ForegroundColor Green
Write-Host "Existing cameraId values were preserved."
Write-Host "Deleted/unused numbers are not reused."
