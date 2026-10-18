#requires -Version 5.1
[CmdletBinding()]
param(
    [string]$CameraRoot = (Join-Path (Split-Path -Parent $PSScriptRoot) 'camera'),
    [string]$CandidatePath = (Join-Path (Split-Path -Parent $PSScriptRoot) 'data\camera_candidates.json'),
    [string]$CandidateCsvDirectory = (Join-Path (Split-Path -Parent $PSScriptRoot) 'youtube-test\output'),
    [string]$LiveAuditPath = (Join-Path (Split-Path -Parent $PSScriptRoot) 'scripts\youtube\output\youtube-live-results.csv'),
    [string]$OutputPath = (Join-Path (Split-Path -Parent $PSScriptRoot) 'reports\tokyo-station-review.csv')
)
$ErrorActionPreference = 'Stop'

function J([int[]]$Code) { return (-join ($Code | ForEach-Object { [char]$_ })) }
$Marunouchi = J @(0x4e38,0x306e,0x5185)
$Yaesu = J @(0x516b,0x91cd,0x6d32)
$Unconfirmed = J @(0x8981,0x78ba,0x8a8d)
$ExistingMatch = J @(0x65e2,0x5b58,0x4e00,0x81f4)
$NewCandidate = J @(0x65b0,0x898f,0x5019,0x88dc)

function Get-Value([object]$Object, [string[]]$Names) {
    if ($null -eq $Object) { return '' }
    foreach ($name in $Names) {
        $p = $Object.PSObject.Properties | Where-Object { $_.Name -ieq $name } | Select-Object -First 1
        if ($null -ne $p -and $null -ne $p.Value) { return [string]$p.Value }
    }
    return ''
}
function Resolve-Visibility([object]$Record, [bool]$Existing, [string]$Source) {
    if (-not $Existing) { return [pscustomobject]@{ Value=''; Raw=''; Valid=$true } }
    $raw = Get-Value $Record @('visibility')
    $normalized = if ([string]::IsNullOrWhiteSpace($raw)) { 'public' } else { $raw.Trim().ToLowerInvariant() }
    if ($normalized -in @('public','hidden','retired')) { return [pscustomobject]@{ Value=$normalized; Raw=$raw; Valid=$true } }
    $id = Get-Value $Record @('cameraId','id','public_camera_id','candidateId','candidate_id')
    Write-Warning ("Unknown visibility value: cameraId/candidateId={0}; visibility={1}; sourceFile={2}" -f $id,$raw,$Source)
    return [pscustomobject]@{ Value=$raw.Trim(); Raw=$raw; Valid=$false }
}
function Read-Records([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return @() }
    $data = Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($data -is [array]) { return @($data) }
    foreach ($n in @('cameras','candidates','items','records','data')) {
        $p = $data.PSObject.Properties | Where-Object { $_.Name -ieq $n } | Select-Object -First 1
        if ($null -ne $p) { return @($p.Value) }
    }
    return @($data)
}
function Normalize-Url([string]$Url) {
    if ([string]::IsNullOrWhiteSpace($Url)) { return '' }
    try { return (([uri]$Url.Trim()).AbsoluteUri.TrimEnd('/')).ToLowerInvariant() }
    catch { return $Url.Trim().ToLowerInvariant().TrimEnd('/') }
}
function Normalize-Text([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value)) { return '' }
    return (($Value.Trim().ToLowerInvariant()) -replace '[\s\u3000]+',' ' -replace '[「」『』"''、,，。．・/／:：()（）]','').Trim()
}
function Get-YouTubeId([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value)) { return '' }
    $value = $Value.Trim()
    if ($value -match '(?:[?&]v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{11})(?:[^A-Za-z0-9_-]|$)') { return $Matches[1] }
    if ($value -match '^[A-Za-z0-9_-]{11}$') { return $value }
    return ''
}
function Get-VideoIdentity([object]$Record) {
    foreach ($name in @('video_id','videoId','youtubeVideoId','sourceId')) {
        $id = Get-YouTubeId (Get-Value $Record @($name))
        if ($id) { return [pscustomobject]@{ Type='youtube_video_id'; Key=$id; Evidence="$name field" } }
    }
    foreach ($name in @('url','directStreamUrl','videoUrl','video_url','watchUrl')) {
        $url = Get-Value $Record @($name)
        $id = Get-YouTubeId $url
        if ($id) { return [pscustomobject]@{ Type='youtube_video_id'; Key=$id; Evidence="$name field; YouTube URL" } }
    }
    foreach ($name in @('directStream','streamUrl','videoUrl','video_url','directStreamUrl')) {
        $url = Get-Value $Record @($name)
        if (-not $url) { continue }
        try { $uri = [uri]$url.Trim() } catch { continue }
        if ($uri.Scheme -notin @('http','https','rtmp','rtmps')) { continue }
        $isDirect = ($uri.AbsolutePath -match '\.(m3u8|mp4|mjpg|mjpeg|ts)$') -or ($uri.AbsoluteUri -match '(?i)(m3u8|stream|playlist|manifest)')
        if ($isDirect) { return [pscustomobject]@{ Type='direct_video_url'; Key=(Normalize-Url $url); Evidence="$name field; direct format" } }
    }
    return [pscustomobject]@{ Type='none'; Key=''; Evidence='no verified video identity' }
}
function Get-AreaResult([string]$NameText, [string]$PlaceText, [string]$LocationText, [string]$ContextText) {
    $separator = '[\s\u30fb/／、：:　-]*'
    $maruPattern = '\u6771\u4eac\u99c5' + $separator + '\u4e38\u306e\u5185(?:\u53e3|\u99c5\u524d\u5e83\u5834)?|\u4e38\u306e\u5185(?:\u5317\u53e3|\u4e2d\u592e\u53e3|\u5357\u53e3|\u99c5\u524d\u5e83\u5834)|\u5927\u624b\u753a.*\u4e38\u306e\u5185'
    $yaesuPattern = '\u6771\u4eac\u99c5' + $separator + '\u516b\u91cd\u6d32(?:\u53e3|\u4e2d\u592e\u5357\u53e3)?|\u516b\u91cd\u6d32(?:\u4e2d\u592e\u53e3|\u5357\u53e3|\u53e3\u524d)|\u30d0\u30b9\u30bf\u30fc\u30df\u30ca\u30eb\u6771\u4eac\u516b\u91cd\u6d32'
    $nameM = [regex]::Match($NameText, $maruPattern); $nameY = [regex]::Match($NameText, $yaesuPattern)
    $placeM = [regex]::Match($PlaceText, $maruPattern); $placeY = [regex]::Match($PlaceText, $yaesuPattern)
    if ($nameM.Success -and -not $nameY.Success) { return [pscustomobject]@{ Area=$Marunouchi; Evidence=$nameM.Value; Strength=400 } }
    if ($nameY.Success -and -not $nameM.Success) { return [pscustomobject]@{ Area=$Yaesu; Evidence=$nameY.Value; Strength=400 } }
    if ($nameM.Success -and $nameY.Success) { return [pscustomobject]@{ Area=$Unconfirmed; Evidence=($nameM.Value + ' / ' + $nameY.Value); Strength=400 } }
    $placeHasMaru = $PlaceText -match '丸の内'
    $placeHasYaesu = $PlaceText -match '八重洲'
    if ($placeHasMaru -and -not $placeHasYaesu) { return [pscustomobject]@{ Area=$Marunouchi; Evidence='place:' + $PlaceText; Strength=300 } }
    if ($placeHasYaesu -and -not $placeHasMaru) { return [pscustomobject]@{ Area=$Yaesu; Evidence='place:' + $PlaceText; Strength=300 } }
    if ($placeHasMaru -and $placeHasYaesu) { return [pscustomobject]@{ Area=$Unconfirmed; Evidence='place:' + $PlaceText; Strength=300 } }
    if ($placeM.Success -and -not $placeY.Success) { return [pscustomobject]@{ Area=$Marunouchi; Evidence='place:' + $PlaceText; Strength=300 } }
    if ($placeY.Success -and -not $placeM.Success) { return [pscustomobject]@{ Area=$Yaesu; Evidence='place:' + $PlaceText; Strength=300 } }
    if ($placeM.Success -and $placeY.Success) { return [pscustomobject]@{ Area=$Unconfirmed; Evidence='place:' + $PlaceText; Strength=300 } }
    if ($ContextText -match '\u6771\u4eac\u99c5|\u4e38\u306e\u5185|\u516b\u91cd\u6d32') { return [pscustomobject]@{ Area=$Unconfirmed; Evidence='東京駅関連語（出口不明）'; Strength=100 } }
    return [pscustomobject]@{ Area=''; Evidence=''; Strength=0 }
}
function Merge-AreaResult($Left, $Right) {
    $results = @($Left,$Right) | Where-Object { $_ -and $_.Strength -gt 0 }
    $maru = @($results | Where-Object { $_.Area -eq $Marunouchi })
    $yaesu = @($results | Where-Object { $_.Area -eq $Yaesu })
    if ($maru.Count -and $yaesu.Count) {
        $maxM = ($maru | Measure-Object Strength -Maximum).Maximum
        $maxY = ($yaesu | Measure-Object Strength -Maximum).Maximum
        return [pscustomobject]@{ Area=$Unconfirmed; Evidence='conflicting location evidence: 丸の内 / 八重洲'; Strength=[Math]::Max($maxM,$maxY) }
    }
    if ($maru.Count) { return $maru | Sort-Object Strength -Descending | Select-Object -First 1 }
    if ($yaesu.Count) { return $yaesu | Sort-Object Strength -Descending | Select-Object -First 1 }
    $fallback = $results | Sort-Object Strength -Descending | Select-Object -First 1
    if ($fallback) { return $fallback }
    return [pscustomobject]@{ Area=$Unconfirmed; Evidence='東京駅関連語（出口不明）'; Strength=100 }
}
function Get-TargetEvidence([string]$LocationText, [string]$RecordId) {
    if ($LocationText -match '\u9759\u5ca1\u770c|\u71b1\u6d77\u5e02') { return '' }
    if ($RecordId -match '(?i)(?:^|[_-])(?:tokyo[_-]station|station[_-]tokyo)(?:[_-]|$)') { return 'explicit-place-id' }
    if ($LocationText -match '\u6771\u4eac\u99c5') { return '東京駅' }
    if ($LocationText -match '\u4e38\u306e\u5185') { return '丸の内' }
    if ($LocationText -match '\u516b\u91cd\u6d32') { return '八重洲' }
    return ''
}
function Category([string]$Path) {
    $parts = $Path -split '[\\/]'; $i = [array]::IndexOf($parts,'camera')
    if ($i -ge 0 -and $i + 1 -lt $parts.Count) { return $parts[$i + 1] }
    return 'candidate'
}
function Add-Item([hashtable]$Map, [object]$Record, [string]$Source, [string]$Kind, [bool]$Existing) {
    $name = Get-Value $Record @('name','cameraName','title')
    $place = Get-Value $Record @('place','location','address')
    $tags = Get-Value $Record @('tags','keywords')
    $desc = Get-Value $Record @('desc','description','summary')
    $provider = Get-Value $Record @('providerPageUrl','provider_url','source','officialUrl')
    $video = Get-Value $Record @('videoUrl','video_url','directStream','directStreamUrl','streamUrl','watchUrl')
    $recordKind = Get-Value $Record @('kind','type')
    $mediaType = Get-Value $Record @('mediaType','media_type')
    $viewpoint = Get-Value $Record @('viewpoint','view','cameraView')
    $recordId = Get-Value $Record @('cameraId','id','public_camera_id','candidateId','candidate_id')
    $visibility = Resolve-Visibility $Record $Existing $Source
    $locationText = "$name $place $(Get-Value $Record @('prefecture','prefectureName','city','ward','region','detected_prefecture','detected_city')) $recordId"
    $contextText = "$locationText $tags $desc $provider $video"
    $targetEvidence = Get-TargetEvidence $locationText $recordId
    if (-not $targetEvidence) { return }
    $areaResult = Get-AreaResult $name $place $locationText $contextText
    if (-not $areaResult.Area) { $areaResult = [pscustomobject]@{ Area=$Unconfirmed; Evidence=$targetEvidence; Strength=0 } }
    $identity = Get-VideoIdentity $Record
    $providerKey = Normalize-Url $provider
    $normalizedName = Normalize-Text $name
    $normalizedPlace = Normalize-Text $place
    $key = if ($identity.Type -ne 'none') { $identity.Type + ':' + $identity.Key.ToLowerInvariant() } elseif ($providerKey) { 'provider:' + $providerKey } else { 'row:' + [guid]::NewGuid().ToString('N') }
    $item = [pscustomobject][ordered]@{
        Existing = $Existing; RecordStatus = if ($Existing) { 'existing' } else { 'candidate' }; ExistingCameraId = if ($Existing) { Get-Value $Record @('cameraId','id','public_camera_id') } else { '' }
        CandidateId = if (-not $Existing) { Get-Value $Record @('candidateId','candidate_id','id') } else { '' }
        CameraName = $name; StationArea = $areaResult.Area; AreaEvidence = $areaResult.Evidence; AreaStrength = $areaResult.Strength; AreaResult = $areaResult; TargetEvidence = $targetEvidence; Category = $Kind
        Visibility = $visibility.Value; VisibilityRaw = $visibility.Raw; VisibilityValid = $visibility.Valid
        Region = if ($locationText -match '\u4e2d\u592e\u533a') { J @(0x6771,0x4eac,0x90fd,0x4e2d,0x592e,0x533a) } else { J @(0x6771,0x4eac,0x90fd,0x5343,0x4ee3,0x7530,0x533a) }
        ProviderUrl = $provider; ProviderKey = $providerKey; NormalizedName = $normalizedName; NormalizedPlace = $normalizedPlace; Kind = $recordKind; MediaType = $mediaType; Viewpoint = $viewpoint; VideoUrl = $video; VideoId = if ($identity.Type -eq 'youtube_video_id') { $identity.Key } else { '' }; DuplicateKeyType = $identity.Type; DuplicateKey = $identity.Key; DuplicateEvidence = $identity.Evidence; DuplicateResult = 'no_duplicate'; DuplicateMatchedCameraId = ''; DuplicateReviewNote = ''; SourceFile = $Source; PlaceText = $place
    }
    if ($Map.ContainsKey($key)) {
        $mergedArea = Merge-AreaResult $Map[$key].AreaResult $item.AreaResult
        $Map[$key].StationArea = $mergedArea.Area
        $Map[$key].AreaEvidence = $mergedArea.Evidence
        $Map[$key].AreaStrength = $mergedArea.Strength
        $Map[$key].AreaResult = $mergedArea
        if ($Existing -and -not $Map[$key].Existing) {
            $Map[$key].Visibility = $visibility.Value
            $Map[$key].VisibilityRaw = $visibility.Raw
            $Map[$key].VisibilityValid = $visibility.Valid
        }
        foreach ($p in @('CandidateId','ExistingCameraId','ProviderUrl','VideoUrl')) { if (-not $Map[$key].$p -and $item.$p) { $Map[$key].$p = $item.$p } }
        if ($identity.Type -eq 'none' -and $Map[$key].ProviderKey) {
            $strongContext = ($normalizedName -and $Map[$key].NormalizedName -eq $normalizedName -and $normalizedPlace -and $Map[$key].NormalizedPlace -eq $normalizedPlace -and $Kind -eq $Map[$key].Category -and (($mediaType -and $mediaType -eq $Map[$key].MediaType) -or ($recordKind -and $recordKind -eq $Map[$key].Kind)))
            if ($strongContext) {
                $Map[$key].DuplicateKeyType = 'provider_context'
                $Map[$key].DuplicateKey = $providerKey + '|' + $normalizedName + '|' + $normalizedPlace
                $Map[$key].DuplicateEvidence = 'providerPageUrl + name + place + category/mediaType'
                $Map[$key].DuplicateResult = 'review_required'
                $Map[$key].RecordStatus = 'review_required'
                if ($Map[$key].ExistingCameraId) { $Map[$key].DuplicateMatchedCameraId = $Map[$key].ExistingCameraId }
                elseif ($item.ExistingCameraId) { $Map[$key].DuplicateMatchedCameraId = $item.ExistingCameraId }
                $Map[$key].DuplicateReviewNote = 'provider page has multiple camera views; human confirmation required'
            } else {
                $Map[$key].DuplicateKeyType = 'provider_page'
                $Map[$key].DuplicateKey = $providerKey
                $Map[$key].DuplicateEvidence = 'providerPageUrl only'
                $Map[$key].DuplicateResult = 'review_required'
                $Map[$key].RecordStatus = 'review_required'
                $Map[$key].DuplicateMatchedCameraId = ''
                $Map[$key].DuplicateReviewNote = 'provider page may contain multiple views or non-camera information'
            }
        }
        if ($Existing -or $Map[$key].Existing) {
            $Map[$key].Existing = $true
            if ($Existing) {
                $Map[$key].Visibility = $visibility.Value
                $Map[$key].VisibilityRaw = $visibility.Raw
                $Map[$key].VisibilityValid = $visibility.Valid
            }
            if ($item.ExistingCameraId) { $Map[$key].ExistingCameraId = $item.ExistingCameraId }
            $Map[$key].DuplicateResult = 'existing_match'
            $Map[$key].DuplicateMatchedCameraId = $Map[$key].ExistingCameraId
            $Map[$key].DuplicateReviewNote = 'matched existing record by YouTube video ID'
            if (-not $Existing -or $Map[$key].RecordStatus -eq 'candidate') { $Map[$key].RecordStatus = 'existing_with_candidate' }
        }
        elseif ($Map[$key].DuplicateResult -eq 'no_duplicate') {
            $Map[$key].DuplicateResult = 'candidate_merged'
            $Map[$key].DuplicateMatchedCameraId = ''
            $Map[$key].DuplicateReviewNote = 'merged with candidate records by video identity'
            $Map[$key].RecordStatus = 'candidate'
        }
        $Map[$key].SourceFile = "$($Map[$key].SourceFile)|$Source"; return
    }
    $Map[$key] = $item
}

if (Test-Path -LiteralPath $OutputPath -PathType Leaf) { throw "Output already exists; refusing to overwrite: $OutputPath" }
$map = @{}
$files = Get-ChildItem -LiteralPath $CameraRoot -Recurse -File -Filter '*.json' | Where-Object { $_.FullName -match '[\\/]tokyo[\\/](chiyoda|chuo)\.json$' -and $_.FullName -match '[\\/](station|road|parking|tourism|city)[\\/]' }
foreach ($f in $files) { foreach ($r in (Read-Records $f.FullName)) { Add-Item $map $r $f.FullName (Category $f.FullName) $true } }
$candidateFiles = @(); if (Test-Path -LiteralPath $CandidatePath -PathType Leaf) { $candidateFiles += $CandidatePath }
if (Test-Path -LiteralPath $CandidateCsvDirectory -PathType Container) { $candidateFiles += Get-ChildItem -LiteralPath $CandidateCsvDirectory -File -Filter '*.csv' | ForEach-Object FullName }
foreach ($f in ($candidateFiles | Select-Object -Unique)) { if ($f -like '*.csv') { foreach ($r in (Import-Csv -LiteralPath $f)) { Add-Item $map $r $f 'candidate' $false } } else { foreach ($r in (Read-Records $f)) { Add-Item $map $r $f 'candidate' $false } } }
$liveMap = @{}; if (Test-Path -LiteralPath $LiveAuditPath -PathType Leaf) { foreach ($r in (Import-Csv -LiteralPath $LiveAuditPath)) { $identity = Get-VideoIdentity $r; if ($identity.Type -eq 'youtube_video_id') { $liveMap[$identity.Key.ToLowerInvariant()] = Get-Value $r @('status','liveStatus','live_status') } } }
$rows = foreach ($i in $map.Values) {
    $status = if ($i.VideoId -and $liveMap.ContainsKey($i.VideoId.ToLowerInvariant())) { $liveMap[$i.VideoId.ToLowerInvariant()] } else { 'UNVERIFIED' }
    $u = New-Object System.Collections.Generic.List[string]; if ($i.StationArea -eq $Unconfirmed) { [void]$u.Add('station area') }; if ($i.VideoId -and $status -ne 'LIVE') { [void]$u.Add('LIVE status') }; if (-not $i.ProviderUrl) { [void]$u.Add('provider URL') }
    $duplicate = if ($i.Existing) { $ExistingMatch } else { $NewCandidate }
    if (-not $i.VisibilityValid) { [void]$u.Add('visibility') }
    [pscustomobject][ordered]@{ existingCameraId=$i.ExistingCameraId; candidateId=$i.CandidateId; cameraName=$i.CameraName; stationArea=$i.StationArea; areaEvidence=$i.AreaEvidence; category=$i.Category; region=$i.Region; providerUrl=$i.ProviderUrl; videoUrl=$i.VideoUrl; liveStatus=$status; duplicate判定=$duplicate; duplicateKeyType=$i.DuplicateKeyType; duplicateKey=$i.DuplicateKey; duplicateEvidence=$i.DuplicateEvidence; duplicateResult=$i.DuplicateResult; duplicateMatchedCameraId=$i.DuplicateMatchedCameraId; duplicateReviewNote=$i.DuplicateReviewNote; recordStatus=$i.RecordStatus; visibility=$i.Visibility; unconfirmedItems=($u -join '|'); approvalStatus='pending'; lastCheckedAt=(Get-Date).ToUniversalTime().ToString('o'); sourceFile=$i.SourceFile }
}
$dir = Split-Path -Parent $OutputPath; if (-not (Test-Path -LiteralPath $dir -PathType Container)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
$rows | Export-Csv -LiteralPath $OutputPath -NoTypeInformation -Encoding UTF8
Write-Output "Created: $OutputPath"
