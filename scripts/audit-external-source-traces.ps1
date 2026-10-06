param(
    [string]$CameraRoot = ".\camera",
    [string]$ReportRoot = ".\reports\source-audit"
)

$ErrorActionPreference = "Stop"

# ---------------------------------------------------------
# LiveCameraLinks external-source trace audit
# Read-only audit: camera JSON files are never modified.
# ---------------------------------------------------------

$sourcePatterns = @(
    [pscustomobject]@{ Key="cametan";      Regex="cametan(?:\.com)?";                     Label="Cametan" },
    [pscustomobject]@{ Key="livecam_asia"; Regex="livecam(?:\.asia|_asia)|livecam\.asia"; Label="livecam.asia" },
    [pscustomobject]@{ Key="liveatlas";    Regex="live[-_ ]?atlas|liveatlas";             Label="LiveAtlas" },
    [pscustomobject]@{ Key="fujiyama";     Regex="fujiyama(?:\.tv)?";                    Label="Fujiyama.TV" },
    [pscustomobject]@{ Key="hirnet";       Regex="hir[-_ ]?net|hir-net|hir_net";          Label="HIR-NET" },
    [pscustomobject]@{ Key="tomarigi";     Regex="tomarigi(?:\.me)?|とまり木";            Label="とまり木" },
    [pscustomobject]@{ Key="livecombs";    Regex="livecombs(?:\.com)?";                   Label="livecombs" },
    [pscustomobject]@{ Key="livecamdb";    Regex="ライブカメラdb|livecamera[-_ ]?db|livecam[-_ ]?db"; Label="ライブカメラDB" }
)

# Fields that are expected to be public camera data and therefore audited.
$publicFields = @(
    "id","slug","name","place","region","kind","category",
    "source","publisher","publisherLabel","provider",
    "url","providerPageUrl","directStreamUrl","referencePageUrl",
    "desc","description","healthReason","auditStatus","batchSource",
    "sourceTier","viewpoint"
)

function Get-CamerasContainer($data) {
    if ($null -ne $data.cameras) { return $data.cameras }
    if ($data -is [System.Array]) { return $data }
    return $null
}

function Get-ScalarText($value) {
    if ($null -eq $value) { return "" }
    if ($value -is [string]) { return $value }
    if ($value -is [System.Array]) { return ($value -join " | ") }
    return [string]$value
}

$rootPath = (Resolve-Path $CameraRoot).Path
$files = Get-ChildItem $CameraRoot -Recurse -Filter *.json |
    Where-Object {
        $_.Name -ne "index.json" -and
        $_.Name -ne "camera_index.json"
    } |
    Sort-Object FullName

$records = @()
$issues = @()

foreach ($file in $files) {
    $data = Get-Content $file.FullName -Raw -Encoding UTF8 | ConvertFrom-Json
    $cameras = Get-CamerasContainer $data
    if ($null -eq $cameras) { continue }

    foreach ($camera in $cameras) {
        $records += $camera

        $cameraId = [string]$camera.cameraId
        $slug = if ([string]$camera.slug) { [string]$camera.slug } else { [string]$camera.id }
        $name = [string]$camera.name

        foreach ($field in $publicFields) {
            $prop = $camera.PSObject.Properties[$field]
            if ($null -eq $prop) { continue }

            $text = Get-ScalarText $prop.Value
            if (-not $text) { continue }

            foreach ($p in $sourcePatterns) {
                if ($text -match $p.Regex) {
                    $severity = "review"
                    if ($field -in @("id","slug")) { $severity = "high" }
                    elseif ($field -in @("providerPageUrl","directStreamUrl","url","source","publisher","provider")) { $severity = "high" }
                    elseif ($field -in @("referencePageUrl","batchSource","healthReason","auditStatus")) { $severity = "medium" }

                    $issues += [pscustomobject]@{
                        severity = $severity
                        sourceKey = $p.Key
                        sourceLabel = $p.Label
                        field = $field
                        cameraId = $cameraId
                        slug = $slug
                        name = $name
                        value = $text
                        file = $file.FullName
                    }
                }
            }
        }
    }
}

# Dedupe identical issue rows.
$issues = $issues |
    Sort-Object severity,sourceKey,field,slug,file |
    Group-Object severity,sourceKey,field,cameraId,slug,name,value,file |
    ForEach-Object { $_.Group[0] }

New-Item -ItemType Directory -Force -Path $ReportRoot | Out-Null

$csvPath = Join-Path $ReportRoot "external-source-traces.csv"
$jsonPath = Join-Path $ReportRoot "external-source-traces.json"
$summaryPath = Join-Path $ReportRoot "summary.txt"

$issues | Export-Csv -Path $csvPath -NoTypeInformation -Encoding UTF8

$issues |
    ConvertTo-Json -Depth 20 |
    Set-Content -Path $jsonPath -Encoding UTF8

$bySource = $issues | Group-Object sourceLabel | Sort-Object Count -Descending
$byField = $issues | Group-Object field | Sort-Object Count -Descending
$bySeverity = $issues | Group-Object severity | Sort-Object Name

$uniqueCameras = $issues |
    ForEach-Object { if ($_.cameraId) { $_.cameraId } else { $_.slug } } |
    Where-Object { $_ } |
    Sort-Object -Unique

$summary = @()
$summary += "LiveCameraLinks external-source trace audit"
$summary += "=========================================="
$summary += "Camera records scanned: $($records.Count)"
$summary += "Issue rows: $($issues.Count)"
$summary += "Affected cameras: $($uniqueCameras.Count)"
$summary += ""
$summary += "By severity:"
foreach ($g in $bySeverity) {
    $summary += "  $($g.Name): $($g.Count)"
}
$summary += ""
$summary += "By source:"
foreach ($g in $bySource) {
    $summary += "  $($g.Name): $($g.Count)"
}
$summary += ""
$summary += "By field:"
foreach ($g in $byField) {
    $summary += "  $($g.Name): $($g.Count)"
}
$summary += ""
$summary += "CSV: $csvPath"
$summary += "JSON: $jsonPath"

$summary | Set-Content -Path $summaryPath -Encoding UTF8

Write-Host ""
Write-Host "LiveCameraLinks external-source trace audit"
Write-Host "Camera records scanned: $($records.Count)"
Write-Host "Issue rows: $($issues.Count)"
Write-Host "Affected cameras: $($uniqueCameras.Count)"
Write-Host ""

if ($issues.Count -gt 0) {
    Write-Host "By source:"
    $bySource | Select-Object Name,Count | Format-Table -AutoSize

    Write-Host "By field:"
    $byField | Select-Object Name,Count | Format-Table -AutoSize

    Write-Host "Top 30 findings:"
    $issues |
        Select-Object -First 30 severity,sourceLabel,field,slug,name |
        Format-Table -AutoSize
} else {
    Write-Host "No configured external-source traces were found."
}

Write-Host ""
Write-Host "No camera JSON files were modified."
Write-Host "Reports:"
Write-Host "  $csvPath"
Write-Host "  $jsonPath"
Write-Host "  $summaryPath"
