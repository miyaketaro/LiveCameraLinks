param(
    [string]$CameraRoot = ".\camera",
    [switch]$Apply
)

$ErrorActionPreference = "Stop"

$TargetSlug = "city_tokyo_ota_kamata_sbps"
$OfficialProvider = "https://sbp-s.co.jp/"
$DirectStream = "https://www.youtube.com/watch?v=x4gyqPBWZW8"
$Publisher = "株式会社SBPS"

function Get-CamerasContainer($data) {
    if ($null -ne $data.cameras) { return $data.cameras }
    if ($data -is [System.Array]) { return $data }
    return $null
}

$files = Get-ChildItem $CameraRoot -Recurse -Filter *.json |
    Where-Object {
        $_.Name -ne "index.json" -and
        $_.Name -ne "camera_index.json"
    }

$found = @()

foreach ($file in $files) {
    $data = Get-Content $file.FullName -Raw -Encoding UTF8 | ConvertFrom-Json
    $cameras = Get-CamerasContainer $data
    if ($null -eq $cameras) { continue }

    foreach ($camera in $cameras) {
        $slug = if ([string]$camera.slug) { [string]$camera.slug } else { [string]$camera.id }
        if ($slug -ne $TargetSlug) { continue }

        $before = [pscustomobject]@{
            file = $file.FullName
            id = [string]$camera.id
            slug = $slug
            name = [string]$camera.name
            publisher = [string]$camera.publisher
            source = [string]$camera.source
            providerPageUrl = [string]$camera.providerPageUrl
            directStreamUrl = [string]$camera.directStreamUrl
            url = [string]$camera.url
        }

        if ($Apply) {
            if ($camera.PSObject.Properties["publisher"]) {
                $camera.publisher = $Publisher
            } else {
                $camera | Add-Member -NotePropertyName publisher -NotePropertyValue $Publisher -Force
            }

            if ($camera.PSObject.Properties["source"]) {
                $camera.source = $Publisher
            } else {
                $camera | Add-Member -NotePropertyName source -NotePropertyValue $Publisher -Force
            }

            if ($camera.PSObject.Properties["providerPageUrl"]) {
                $camera.providerPageUrl = $OfficialProvider
            } else {
                $camera | Add-Member -NotePropertyName providerPageUrl -NotePropertyValue $OfficialProvider -Force
            }

            if ($camera.PSObject.Properties["directStreamUrl"]) {
                $camera.directStreamUrl = $DirectStream
            } else {
                $camera | Add-Member -NotePropertyName directStreamUrl -NotePropertyValue $DirectStream -Force
            }

            if ($camera.PSObject.Properties["url"]) {
                $camera.url = $DirectStream
            } else {
                $camera | Add-Member -NotePropertyName url -NotePropertyValue $DirectStream -Force
            }

            $json = $data | ConvertTo-Json -Depth 40
            [System.IO.File]::WriteAllText(
                $file.FullName,
                $json,
                (New-Object System.Text.UTF8Encoding($false))
            )
        }

        $found += $before
    }
}

Write-Host ""
Write-Host "LiveCameraLinks Kamata provider fix"
Write-Host "Mode: $(if($Apply){'APPLY'}else{'DRY RUN'})"
Write-Host "Matches: $($found.Count)"
Write-Host ""

if ($found.Count -eq 0) {
    Write-Host "ERROR: target camera not found." -ForegroundColor Red
    exit 2
}

if ($found.Count -gt 1) {
    Write-Host "ERROR: duplicate target camera found. Nothing should be applied." -ForegroundColor Red
    $found | Format-Table file,id,slug,name -AutoSize
    exit 3
}

$found | Format-List *

Write-Host ""
Write-Host "Planned values:"
Write-Host "  publisher       = $Publisher"
Write-Host "  source          = $Publisher"
Write-Host "  providerPageUrl = $OfficialProvider"
Write-Host "  directStreamUrl = $DirectStream"
Write-Host "  url             = $DirectStream"
Write-Host ""

if ($Apply) {
    Write-Host "Applied successfully." -ForegroundColor Green
} else {
    Write-Host "DRY RUN only. No files were changed."
    Write-Host "After review, run again with -Apply."
}
