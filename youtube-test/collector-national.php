<?php
declare(strict_types=1);

function fail(string $message, int $code = 1): never {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ERROR: ' . $message . PHP_EOL;
    exit($code);
}
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function quotaDay(): string {
    $tz = new DateTimeZone('America/Los_Angeles');
    return (new DateTime('now', $tz))->format('Y-m-d');
}
function loadJsonFile(string $path, array $default = []): array {
    if (!file_exists($path)) return $default;
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}
function saveJsonFile(string $path, array $data): void {
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
}
function loadQuotaState(string $path, int $limit): array {
    $day = quotaDay();
    $state = loadJsonFile($path, []);
    if (($state['quota_day'] ?? '') !== $day) {
        $state = ['quota_day'=>$day,'used'=>0,'limit'=>$limit,'last_updated_at'=>date(DATE_ATOM),'source'=>'app_counter'];
        saveJsonFile($path, $state);
    }
    $state['limit'] = $limit;
    $state['used'] = max(0, min($limit, (int)($state['used'] ?? 0)));
    return $state;
}
function markQuotaUsed(string $path, array &$state, int $amount = 1): void {
    $state['used'] = min((int)$state['limit'], (int)$state['used'] + max(0,$amount));
    $state['last_updated_at'] = date(DATE_ATOM);
    saveJsonFile($path, $state);
}
function markQuotaExhausted(string $path, int $limit): void {
    $state = loadQuotaState($path, $limit);
    $state['used'] = $limit;
    $state['last_updated_at'] = date(DATE_ATOM);
    $state['source'] = 'google_429';
    saveJsonFile($path, $state);
}
function renderNotice(string $title, string $message, string $viewer='national-viewer.php', int $status=200): never {
    http_response_code($status);
    header('Content-Type:text/html;charset=UTF-8');
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).'</title><body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;max-width:900px;margin:40px auto;padding:0 20px;line-height:1.7;color:#222">';
    echo '<h1>'.h($title).'</h1><p>'.h($message).'</p><p><a href="'.h($viewer).'">一覧へ戻る</a></p></body></html>';
    exit;
}
function httpGetJson(string $url): array {
    $ch = curl_init($url);
    if ($ch === false) fail('Failed to initialize cURL.');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'LiveCameraLinksCollector/0.32',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status >= 400) {
        if ($status === 429 && is_string($body) && (str_contains($body, 'Search Queries') || str_contains($body, 'rateLimitExceeded'))) {
            $limit = (int)($GLOBALS['quotaDailyLimit'] ?? 100);
            $path = (string)($GLOBALS['quotaStatePath'] ?? (__DIR__.'/youtube-quota-usage.json'));
            markQuotaExhausted($path, $limit);
        }
        fail("HTTP error {$status}: {$err}\n" . (is_string($body) ? $body : ''));
    }
    $json = json_decode((string)$body, true);
    if (!is_array($json)) fail('Invalid JSON returned by YouTube API.');
    return $json;
}
function containsAny(string $text, array $keywords): bool {
    $text = mb_strtolower($text, 'UTF-8');
    foreach ($keywords as $kw) {
        if (mb_strpos($text, mb_strtolower((string)$kw, 'UTF-8')) !== false) return true;
    }
    return false;
}
function classifyCandidate(string $text): array {
    $rules = [
        '道路' => ['道路','国道','県道','高速','渋滞','交通','峠','トンネル','road','traffic','highway','expressway'],
        '駅' => ['駅','鉄道','新幹線','電車','railway','station','train'],
        '繁華街' => ['繁華街','商店街','スクランブル','交差点','街並み','downtown','street','city view'],
        '観光' => ['観光','温泉','富士山','海','港','空港','展望','観光地','tourism','airport','beach','harbor'],
        '雪道' => ['雪','積雪','凍結','スキー','snow','winter','ski'],
        '河川' => ['河川','川','水位','ダム','river','dam'],
    ];
    $tags = [];
    foreach ($rules as $tag => $keywords) if (containsAny($text, $keywords)) $tags[] = $tag;
    return $tags;
}
function candidateAssessment(array $tags, string $title, string $description, string $channel): array {
    $text = $title . ' ' . $description . ' ' . $channel;
    $score = 0; $reasons = [];
    $weights = ['道路'=>7,'雪道'=>7,'駅'=>6,'繁華街'=>5,'観光'=>3,'河川'=>2];
    foreach ($tags as $tag) { $score += $weights[$tag] ?? 0; $reasons[] = $tag . 'タグ'; }
    $cameraWords = ['ライブカメラ','live camera','webcam','定点カメラ','定点','ライブ映像','live view'];
    $continuousWords = ['24/7','24時間','24h','常時配信'];
    $roadStrong = ['国道','県道','高速道路','高速','交差点','道路状況','交通状況','峠','トンネル','道の駅','road','route','highway','traffic'];
    $placeStrong = ['駅前','駅周辺','商店街','繁華街','空港','港','温泉','観光地'];
    $negative = ['ゲーム実況','gameplay','vtuber','歌枠','雑談配信','music live','音楽配信','ニュース専門','breaking news','競馬中継','パチンコ','casino','スポーツ中継'];
    $softNegative = ['ニュース','news','ゲーム','game','music','音楽','雑談'];
    if (containsAny($text, $cameraWords)) { $score += 8; $reasons[] = 'カメラ明記'; }
    if (containsAny($title, $cameraWords)) { $score += 5; $reasons[] = 'タイトルにカメラ'; }
    if (containsAny($text, $continuousWords)) { $score += 3; $reasons[] = '常時配信'; }
    if (containsAny($text, $roadStrong)) { $score += 5; $reasons[] = '道路系キーワード'; }
    if (containsAny($text, $placeStrong)) { $score += 3; $reasons[] = '現地確認向け'; }
    if (containsAny($text, $negative)) { $score -= 22; $reasons[] = '強い除外語'; }
    elseif (containsAny($text, $softNegative)) { $score -= 8; $reasons[] = '除外語'; }
    if (empty($tags)) { $score -= 5; $reasons[] = 'カテゴリ不明'; }
    $highConfidence = containsAny($text, $cameraWords) && !empty(array_intersect($tags, ['道路','雪道','駅','繁華街','観光']));
    $hardReject = containsAny($text, $negative);
    if (!$hardReject && ($highConfidence || $score >= 16)) $status = '採用候補';
    elseif ($hardReject || $score <= 1) $status = '除外候補';
    else $status = '要確認';
    return ['score'=>$score,'status'=>$status,'reasons'=>array_values(array_unique($reasons))];
}
function blockDefinitions(): array {
    return [
        'hokkaido' => ['name'=>'北海道','prefs'=>['北海道']],
        'tohoku' => ['name'=>'東北','prefs'=>['青森県','岩手県','宮城県','秋田県','山形県','福島県']],
        'kanto' => ['name'=>'関東','prefs'=>['茨城県','栃木県','群馬県','埼玉県','千葉県','東京都','神奈川県']],
        'chubu' => ['name'=>'中部','prefs'=>['新潟県','富山県','石川県','福井県','山梨県','長野県','岐阜県','静岡県','愛知県']],
        'kinki' => ['name'=>'近畿','prefs'=>['三重県','滋賀県','京都府','大阪府','兵庫県','奈良県','和歌山県']],
        'chugoku' => ['name'=>'中国','prefs'=>['鳥取県','島根県','岡山県','広島県','山口県']],
        'shikoku' => ['name'=>'四国','prefs'=>['徳島県','香川県','愛媛県','高知県']],
        'kyushu' => ['name'=>'九州','prefs'=>['福岡県','佐賀県','長崎県','熊本県','大分県','宮崎県','鹿児島県']],
        'okinawa' => ['name'=>'沖縄','prefs'=>['沖縄県']],
    ];
}
function allPrefectures(array $defs): array {
    $prefs=[]; foreach($defs as $def) foreach($def['prefs'] as $p) $prefs[]=$p; return $prefs;
}
function blockForPrefecture(string $pref, array $defs): string {
    foreach ($defs as $def) if (in_array($pref, $def['prefs'], true)) return $def['name'];
    return '';
}
function detectPrefecture(string $title, string $description, string $channel, array $prefs): string {
    foreach ([$title,$description,$channel] as $text) {
        foreach ($prefs as $pref) if (mb_strpos($text, $pref, 0, 'UTF-8') !== false) return $pref;
    }
    return '';
}
function enrichLocation(array $row, array $defs): array {
    $detected = detectPrefecture((string)($row['title']??''),(string)($row['description']??''),(string)($row['channel_title']??''),allPrefectures($defs));
    $row['detected_prefecture'] = $detected;
    $row['detected_block'] = $detected !== '' ? blockForPrefecture($detected,$defs) : '';
    $searched = (array)($row['searched_prefectures'] ?? []);
    if ($detected === '') $row['location_status'] = '未判定';
    elseif (in_array($detected,$searched,true)) $row['location_status'] = '一致';
    else $row['location_status'] = '地域外';
    return $row;
}
function loadSiteRegistered(string $path): array {
    if (!file_exists($path)) return [];
    $rows=json_decode((string)file_get_contents($path),true); if(!is_array($rows)) return [];
    $ids=[];
    foreach($rows as $r){
        if(is_string($r)){ if(preg_match('/(?:v=|youtu\.be\/)([A-Za-z0-9_-]{6,})/',$r,$m))$ids[$m[1]]=1; else $ids[$r]=1; continue; }
        if(!is_array($r)) continue;
        $id=trim((string)($r['video_id']??'')); if($id!==''){$ids[$id]=1;continue;}
        $url=(string)($r['url']??''); if(preg_match('/(?:v=|youtu\.be\/)([A-Za-z0-9_-]{6,})/',$url,$m))$ids[$m[1]]=1;
    }
    return $ids;
}
function latestSnapshotForBlockFromHistory(string $blockName, string $outDir): ?array {
    $files = glob($outDir . '/candidates-*.json') ?: []; rsort($files,SORT_STRING);
    foreach($files as $file){$rows=json_decode((string)@file_get_contents($file),true);if(!is_array($rows)||!$rows)continue;foreach($rows as $r)if(($r['search_block']??'')===$blockName)return $rows;}
    return null;
}
function legacyRowsForNewBlock(string $slug,array $def,string $outDir): ?array {
    $legacyMap=['hokkaido'=>'hokkaido-tohoku','tohoku'=>'hokkaido-tohoku','chugoku'=>'chugoku-shikoku','shikoku'=>'chugoku-shikoku','kyushu'=>'kyushu-okinawa','okinawa'=>'kyushu-okinawa'];
    if(!isset($legacyMap[$slug])) return null;
    $file=$outDir.'/block-'.$legacyMap[$slug].'-latest.json';
    if(!file_exists($file)) return null;
    $rows=json_decode((string)file_get_contents($file),true); if(!is_array($rows)) return null;
    $filtered=[];
    foreach($rows as $r){$searched=(array)($r['searched_prefectures']??[$r['prefecture']??'']); if(array_intersect($searched,$def['prefs']))$filtered[]=$r;}
    return $filtered ?: null;
}
function rebuildNationalMaster(string $outDir,array $defs,array $siteRegistered): array {
    $master=[];
    foreach($defs as $slug=>$def){
        $snapshot=$outDir.'/block-'.$slug.'-latest.json';
        $rows=null;
        if(file_exists($snapshot)) $rows=json_decode((string)file_get_contents($snapshot),true);
        if(!is_array($rows)) $rows=legacyRowsForNewBlock($slug,$def,$outDir);
        if(!is_array($rows)) {$legacy=latestSnapshotForBlockFromHistory($def['name'],$outDir); if(is_array($legacy))$rows=$legacy;}
        if(!is_array($rows)) continue;
        foreach($rows as $row){
            $id=(string)($row['video_id']??''); if($id==='')continue;
            $row['search_blocks']=array_values(array_unique(array_filter(array_merge((array)($row['search_blocks']??[]),[(string)($row['search_block']??$def['name'])]))));
            $row['searched_prefectures']=array_values(array_unique(array_filter(array_merge((array)($row['searched_prefectures']??[]),[(string)($row['prefecture']??'')]))));
            $row=enrichLocation($row,$defs);
            $row['site_status']=isset($siteRegistered[$id])?'登録済':'未登録';
            if(!isset($master[$id])){$master[$id]=$row;continue;}
            $blocks=array_values(array_unique(array_filter(array_merge((array)$master[$id]['search_blocks'],(array)$row['search_blocks']))));
            $prefs=array_values(array_unique(array_filter(array_merge((array)$master[$id]['searched_prefectures'],(array)$row['searched_prefectures']))));
            if((int)($row['candidate_score']??0)>(int)($master[$id]['candidate_score']??0))$master[$id]=array_merge($master[$id],$row);
            $master[$id]['search_blocks']=$blocks; $master[$id]['searched_prefectures']=$prefs;
            $master[$id]=enrichLocation($master[$id],$defs); $master[$id]['site_status']=isset($siteRegistered[$id])?'登録済':'未登録';
        }
    }
    $rows=array_values($master);
    usort($rows,fn(array $a,array $b):int => (($a['site_status']??'未登録')==='未登録'?-1:1)<=> (($b['site_status']??'未登録')==='未登録'?-1:1) ?: ((int)($b['candidate_score']??0)<=>(int)($a['candidate_score']??0)) ?: strcmp((string)($a['title']??''),(string)($b['title']??'')));
    @file_put_contents($outDir.'/national-master.json',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    $fp=@fopen($outDir.'/registration-candidates.csv','wb');
    if($fp){fputcsv($fp,['site_status','candidate_status','score','detected_prefecture','location_status','tags','title','url','channel','video_id']);foreach($rows as $r){if(($r['site_status']??'未登録')!=='未登録'||($r['candidate_status']??'')!=='採用候補')continue;fputcsv($fp,[$r['site_status'],$r['candidate_status'],$r['candidate_score'],$r['detected_prefecture']??'',$r['location_status']??'',implode('|',$r['tags']??[]),$r['title'],$r['url'],$r['channel_title'],$r['video_id']]);}fclose($fp);}
    return $rows;
}

$configPath=__DIR__.'/config.php'; if(!file_exists($configPath))fail('config.php not found.'); $config=require $configPath;
$apiKey=trim((string)($config['youtube_api_key']??'')); if($apiKey===''||$apiKey==='PASTE_YOUR_API_KEY_HERE')fail('YouTube API key is not configured.');
$quotaDailyLimit=max(1,(int)($config['search_daily_limit']??100));
$quotaStatePath=__DIR__.'/youtube-quota-usage.json';
$GLOBALS['quotaDailyLimit']=$quotaDailyLimit;
$GLOBALS['quotaStatePath']=$quotaStatePath;
$quotaState=loadQuotaState($quotaStatePath,$quotaDailyLimit);
$blockRunsPath=__DIR__.'/block-runs.json';
$blockRuns=loadJsonFile($blockRunsPath,[]);
$defs=blockDefinitions(); $slugs=array_keys($defs); $statePath=__DIR__.'/rotation-state.json'; $state=['next_index'=>0];
if(file_exists($statePath)){$tmp=json_decode((string)file_get_contents($statePath),true);if(is_array($tmp))$state=$tmp;}
$requested=trim((string)($_GET['block']??''));
if($requested!==''&&isset($defs[$requested])){$currentSlug=$requested;$idx=array_search($currentSlug,$slugs,true);$state['next_index']=(($idx===false?0:(int)$idx)+1)%count($slugs);$runMode='手動指定';}
else{$idx=((int)($state['next_index']??0))%count($slugs);$currentSlug=$slugs[$idx];$state['next_index']=($idx+1)%count($slugs);$runMode='自動ローテーション';}
$currentBlock=$defs[$currentSlug]['name']; $state['last_block']=$currentBlock;$state['last_slug']=$currentSlug;$state['last_run_at']=date(DATE_ATOM);@file_put_contents($statePath,json_encode($state,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

$queries=[];foreach($defs[$currentSlug]['prefs'] as $pref)$queries[]=$pref.' 道路 ライブカメラ';
$requiredSearches=count($queries);
$remaining=max(0,$quotaDailyLimit-(int)$quotaState['used']);
$force=((string)($_GET['force']??''))==='1';
$todayKey=quotaDay();
$lastRun=$blockRuns[$currentSlug]??[];
if(!$force && ($lastRun['quota_day']??'')===$todayKey){
    $savedCount=(int)($lastRun['count']??0);
    renderNotice('本日は収集済みです', $currentBlock.'は本日すでに収集済みです（保存済み '.$savedCount.'件）。API節約のため再収集を停止しました。', 'national-viewer.php?block='.rawurlencode($currentBlock));
}
if($remaining<$requiredSearches){
    renderNotice('API検索枠が不足しています', 'この地域の収集には '.$requiredSearches.' 回の検索が必要ですが、本システム記録上の残りは '.$remaining.' 回です。Google側の検索枠が回復してから実行してください。', 'national-viewer.php', 429);
}
$maxResults=max(1,min(50,(int)($config['max_results_per_query']??25)));$regionCode=(string)($config['region_code']??'JP');$relevanceLanguage=(string)($config['relevance_language']??'ja');
$historyPath=__DIR__.'/known-videos.json';$known=[];if(file_exists($historyPath)){$kd=json_decode((string)file_get_contents($historyPath),true);if(is_array($kd))$known=$kd;}
$runStartedAt=date(DATE_ATOM);$all=[];
foreach($queries as $query){
    $prefecture=preg_split('/\s+/u',trim((string)$query))[0]??'';
    $params=['part'=>'snippet','type'=>'video','eventType'=>'live','q'=>$query,'maxResults'=>$maxResults,'regionCode'=>$regionCode,'relevanceLanguage'=>$relevanceLanguage,'key'=>$apiKey];
    $data=httpGetJson('https://www.googleapis.com/youtube/v3/search?'.http_build_query($params));
    markQuotaUsed($quotaStatePath,$quotaState,1);
    foreach(($data['items']??[]) as $item){$videoId=$item['id']['videoId']??null;if(!$videoId)continue;$s=$item['snippet']??[];$title=html_entity_decode((string)($s['title']??''),ENT_QUOTES|ENT_HTML5,'UTF-8');$description=html_entity_decode((string)($s['description']??''),ENT_QUOTES|ENT_HTML5,'UTF-8');$channel=(string)($s['channelTitle']??'');
        if(isset($all[$videoId])){$all[$videoId]['matched_queries'][]=$query;$all[$videoId]['searched_prefectures'][]=$prefecture;$all[$videoId]['matched_queries']=array_values(array_unique($all[$videoId]['matched_queries']));$all[$videoId]['searched_prefectures']=array_values(array_unique($all[$videoId]['searched_prefectures']));continue;}
        $tags=classifyCandidate($title.' '.$description.' '.$channel);$ass=candidateAssessment($tags,$title,$description,$channel);
        $all[$videoId]=['video_id'=>$videoId,'title'=>$title,'url'=>'https://www.youtube.com/watch?v='.$videoId,'channel_id'=>(string)($s['channelId']??''),'channel_title'=>$channel,'published_at'=>(string)($s['publishedAt']??''),'thumbnail'=>(string)($s['thumbnails']['high']['url']??$s['thumbnails']['medium']['url']??$s['thumbnails']['default']['url']??''),'matched_query'=>$query,'matched_queries'=>[$query],'search_block'=>$currentBlock,'search_blocks'=>[$currentBlock],'prefecture'=>$prefecture,'searched_prefectures'=>[$prefecture],'tags'=>$tags,'candidate_score'=>$ass['score'],'candidate_status'=>$ass['status'],'assessment_reasons'=>$ass['reasons'],'description'=>$description];
    }
}
foreach(array_chunk(array_keys($all),50) as $chunk){$params=['part'=>'snippet,liveStreamingDetails,status','id'=>implode(',',$chunk),'key'=>$apiKey];$data=httpGetJson('https://www.googleapis.com/youtube/v3/videos?'.http_build_query($params));$returned=[];foreach(($data['items']??[]) as $video){$id=(string)($video['id']??'');if($id===''||!isset($all[$id]))continue;$returned[$id]=true;$live=$video['liveStreamingDetails']??[];$all[$id]['actual_start_time']=(string)($live['actualStartTime']??'');$all[$id]['actual_end_time']=(string)($live['actualEndTime']??'');$all[$id]['embeddable']=(bool)($video['status']['embeddable']??false);$all[$id]['privacy_status']=(string)($video['status']['privacyStatus']??'');$all[$id]['is_live_now']=isset($live['actualStartTime'])&&!isset($live['actualEndTime']);}foreach($chunk as $id)if(!isset($returned[$id]))unset($all[$id]);}
$rows=array_values(array_filter($all,fn(array $x):bool=>($x['is_live_now']??false)===true));
foreach($rows as &$row){$id=(string)$row['video_id'];$row['discovery_status']=isset($known[$id])?'既知':'新規';$row['first_seen_at']=(string)($known[$id]['first_seen_at']??$runStartedAt);$row['last_seen_at']=$runStartedAt;$row=enrichLocation($row,$defs);$known[$id]=['video_id'=>$id,'title'=>(string)$row['title'],'url'=>(string)$row['url'],'channel_title'=>(string)$row['channel_title'],'first_seen_at'=>$row['first_seen_at'],'last_seen_at'=>$runStartedAt];}unset($row);
@file_put_contents($historyPath,json_encode($known,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
usort($rows,fn(array $a,array $b):int=>(($a['discovery_status']==='新規'?1:0)<=>($b['discovery_status']==='新規'?1:0))*-1 ?: ($b['candidate_score']<=>$a['candidate_score']) ?: strcmp($a['title'],$b['title']));
$outDir=__DIR__.'/output';if(!is_dir($outDir)&&!mkdir($outDir,0775,true)&&!is_dir($outDir))fail('Could not create output directory.');$stamp=date('Ymd-His');$jsonPath=$outDir."/candidates-{$stamp}.json";$json=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);if($json===false||file_put_contents($jsonPath,$json)===false)fail('Could not create JSON output.');@file_put_contents($outDir.'/block-'.$currentSlug.'-latest.json',$json);
$blockRuns[$currentSlug]=[
    'name'=>$currentBlock,
    'quota_day'=>$todayKey,
    'last_run_at'=>date(DATE_ATOM),
    'count'=>count($rows),
    'searches_used'=>$requiredSearches,
    'snapshot'=>'output/block-'.$currentSlug.'-latest.json'
];
saveJsonFile($blockRunsPath,$blockRuns);
$csvPath=$outDir."/candidates-{$stamp}.csv";$fp=fopen($csvPath,'wb');if($fp===false)fail('Could not create CSV output.');fputcsv($fp,['discovery','status','score','detected_prefecture','location_status','tags','title','url','channel','matched_query','search_block','searched_prefectures','actual_start_time','video_id']);foreach($rows as $r)fputcsv($fp,[$r['discovery_status'],$r['candidate_status'],$r['candidate_score'],$r['detected_prefecture']??'',$r['location_status']??'',implode('|',$r['tags']),$r['title'],$r['url'],$r['channel_title'],$r['matched_query'],$r['search_block'],implode('|',$r['searched_prefectures']??[]),$r['actual_start_time'],$r['video_id']]);fclose($fp);
$siteRegistered=loadSiteRegistered(__DIR__.'/site-registered.json');$master=rebuildNationalMaster($outDir,$defs,$siteRegistered);$count=count($rows);$adopt=count(array_filter($rows,fn($r)=>($r['candidate_status']??'')==='採用候補'));$review=count(array_filter($rows,fn($r)=>($r['candidate_status']??'')==='要確認'));$exclude=$count-$adopt-$review;$unregistered=count(array_filter($master,fn($r)=>($r['site_status']??'未登録')==='未登録'));
$remaining=max(0,$quotaDailyLimit-(int)$quotaState['used']);
$saved=[];
foreach($defs as $slug=>$def){
    $snapshot=$outDir.'/block-'.$slug.'-latest.json';
    $savedCount=0;
    if(file_exists($snapshot)){
        $tmp=json_decode((string)@file_get_contents($snapshot),true);
        if(is_array($tmp))$savedCount=count($tmp);
    }
    $saved[$slug]=['count'=>$savedCount,'exists'=>file_exists($snapshot),'today'=>(($blockRuns[$slug]['quota_day']??'')===$todayKey),'last_run_at'=>(string)($blockRuns[$slug]['last_run_at']??'')];
}
$completed=count(array_filter($saved,fn($x)=>$x['exists']));
header('Content-Type:text/html;charset=UTF-8');
echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LiveCameraLinks 全国収集</title><body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;max-width:1050px;margin:36px auto;padding:0 20px;line-height:1.7;color:#222">';
echo '<h1>全国YouTube LIVE候補 収集完了</h1>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0"><div style="padding:10px 14px;border:1px solid #ddd;border-radius:10px;background:#f7f7f7"><strong>本日の検索API使用量</strong> '.(int)$quotaState['used'].' / '.$quotaDailyLimit.'</div><div style="padding:10px 14px;border:1px solid #ddd;border-radius:10px;background:#f7f7f7"><strong>残り</strong> '.$remaining.'</div><div style="padding:10px 14px;border:1px solid #ddd;border-radius:10px;background:#f7f7f7"><strong>保存済み地域</strong> '.$completed.' / '.count($defs).'</div></div>';
echo '<p style="color:#666">※API使用量は第3.6版以降にこのシステムから実行した search.list を記録します。Google Cloud側で別途使用した分は、429を検出するまで反映されない場合があります。</p>';
echo '<p>今回の探索ブロック: <strong>'.h($currentBlock).'</strong> <span style="color:#666">('.h($runMode).')</span></p><p style="font-size:32px;font-weight:700;margin-bottom:8px">'.$count.'件</p><p>採用候補 '.$adopt.'件 / 要確認 '.$review.'件 / 除外候補 '.$exclude.'件</p><p>全国マスター: <strong>'.count($master).'件</strong> / サイト未登録判定 <strong>'.$unregistered.'件</strong></p>';
echo '<p><a style="display:inline-block;padding:12px 18px;background:#111;color:#fff;text-decoration:none;border-radius:8px;margin-right:8px" href="national-viewer.php?block='.rawurlencode($currentBlock).'">'.h($currentBlock).$count.'件の候補一覧を開く</a><a style="display:inline-block;padding:12px 18px;background:#f1f3f5;color:#111;text-decoration:none;border-radius:8px" href="national-viewer.php">全国候補一覧を開く</a></p>';
echo '<h2 style="margin-top:34px">ブロックを直接収集</h2><div style="display:flex;gap:8px;flex-wrap:wrap">';
foreach($defs as $slug=>$def){
    $info=$saved[$slug];
    $label=$def['name'].' '.($info['exists']?'✓ 保存済 '.$info['count'].'件':'未収集');
    if($info['today'])$label='✓ 本日収集済 '.$def['name'].' '.$info['count'].'件';
    $style=$slug===$currentSlug?'background:#111;color:#fff':($info['today']?'background:#e7f6ec;color:#087f23':($info['exists']?'background:#eef3ff;color:#234':'background:#f1f3f5;color:#111'));
    echo '<a href="collector-national.php?block='.h($slug).'" style="padding:9px 12px;border-radius:8px;text-decoration:none;'.$style.'">'.h($label).'</a>';
}
echo '</div><p style="margin-top:18px"><a href="collector-national.php">次のブロックを自動収集</a></p><p style="color:#666">9ブロック順: 北海道 → 東北 → 関東 → 中部 → 近畿 → 中国 → 四国 → 九州 → 沖縄</p></body></html>';
