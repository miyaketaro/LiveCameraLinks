$ErrorActionPreference = "Stop"
$path = Join-Path $PSScriptRoot "..\camera\road\tokyo\chiyoda.json"
if (-not (Test-Path $path)) {
    $path = Join-Path $PSScriptRoot "..\..\camera\road\tokyo\chiyoda.json"
}
$data = Get-Content -Raw -Encoding UTF8 $path | ConvertFrom-Json
$camera = $data | Where-Object { $_.id -eq "road_tokyo_takebashi_jct_c1" }
if (-not $camera) { throw "Takebashi camera not found" }
$camera.PSObject.Properties.Remove("providerMessage")
$json = $data | ConvertTo-Json -Depth 20
[System.IO.File]::WriteAllText((Resolve-Path $path), $json, (New-Object System.Text.UTF8Encoding($false)))
Write-Host "PR test message removed."
