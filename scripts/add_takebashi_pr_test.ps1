$ErrorActionPreference = "Stop"

$path = Join-Path $PSScriptRoot "..\camera\road\tokyo\chiyoda.json"
$path = [System.IO.Path]::GetFullPath($path)

if (-not (Test-Path $path)) {
    throw "chiyoda.json not found: $path"
}

$data = Get-Content $path -Raw -Encoding UTF8 | ConvertFrom-Json

$camera = $data.cameras |
    Where-Object { $_.id -eq "road_tokyo_takebashi_jct_c1" }

if (-not $camera) {
    throw "Takebashi camera not found"
}

$msg = [pscustomobject]@{
    enabled = $true
    type    = "promotion"
    text    = "表示テスト：設置者からのお知らせ・PR／CM領域"
    url     = ""
}

$camera | Add-Member `
    -NotePropertyName providerMessage `
    -NotePropertyValue $msg `
    -Force

$json = $data | ConvertTo-Json -Depth 30

[System.IO.File]::WriteAllText(
    $path,
    $json,
    (New-Object System.Text.UTF8Encoding($false))
)

Write-Host "PR test message added."
Write-Host "Camera: $($camera.name)"
Write-Host "File: $path"
