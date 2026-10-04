<?php
declare(strict_types=1);

function fail(string $message, int $code = 1): never {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ERROR: ' . $message . PHP_EOL;
    exit($code);
}

function httpGetJson(string $url): array {
    $ch = curl_init($url);
    if ($ch === false) fail('Failed to initialize cURL.');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'LiveCameraLinksCollector/0.2',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status >= 400) {
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
    foreach ($rules as $tag => $keywords) {
        if (containsAny($text, $keywords)) $tags[] = $tag;
    }
    return $tags;
}

function candidateAssessment(array $tags, string $title, string $description, string $channel): array {
    $text = $title . ' ' . $description . ' ' . $channel;
    $score = 0;
    $reasons = [];

    $weights = ['道路'=>7,'雪道'=>7,'駅'=>6,'繁華街'=>5,'観光'=>3,'河川'=>2];
    foreach ($tags as $tag) {
        $score += $weights[$tag] ?? 0;
        $reasons[] = $tag . 'タグ';
    }

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

    // High-confidence camera rule: a camera term plus a useful place/road category.
    $highConfidence = containsAny($text, $cameraWords) && !empty(array_intersect($tags, ['道路','雪道','駅','繁華街','観光']));
    $hardReject = containsAny($text, $negative);

    if (!$hardReject && ($highConfidence || $score >= 16)) $status = '採用候補';
    elseif ($hardReject || $score <= 1) $status = '除外候補';
    else $status = '要確認';

    return ['score'=>$score, 'status'=>$status, 'reasons'=>array_values(array_unique($reasons))];
}

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) fail('config.php not found.');
$config = require $configPath;
$apiKey = trim((string)($config['youtube_api_key'] ?? ''));
if ($apiKey === '' || $apiKey === 'PASTE_YOUR_API_KEY_HERE') fail('YouTube API key is not configured.');

$queries = $config['queries'] ?? [];
$maxResults = max(1, min(50, (int)($config['max_results_per_query'] ?? 25)));
$regionCode = (string)($config['region_code'] ?? 'JP');
$relevanceLanguage = (string)($config['relevance_language'] ?? 'ja');

$historyPath = __DIR__ . '/known-videos.json';
$known = [];
if (file_exists($historyPath)) {
    $knownData = json_decode((string)file_get_contents($historyPath), true);
    if (is_array($knownData)) $known = $knownData;
}
$runStartedAt = date(DATE_ATOM);

$all = [];
foreach ($queries as $query) {
    $params = [
        'part'=>'snippet','type'=>'video','eventType'=>'live','q'=>(string)$query,
        'maxResults'=>$maxResults,'regionCode'=>$regionCode,'relevanceLanguage'=>$relevanceLanguage,'key'=>$apiKey,
    ];
    $data = httpGetJson('https://www.googleapis.com/youtube/v3/search?' . http_build_query($params));
    foreach (($data['items'] ?? []) as $item) {
        $videoId = $item['id']['videoId'] ?? null;
        if (!$videoId) continue;
        $s = $item['snippet'] ?? [];
        $title = html_entity_decode((string)($s['title'] ?? ''), ENT_QUOTES|ENT_HTML5, 'UTF-8');
        $description = html_entity_decode((string)($s['description'] ?? ''), ENT_QUOTES|ENT_HTML5, 'UTF-8');
        $channel = (string)($s['channelTitle'] ?? '');
        $tags = classifyCandidate($title . ' ' . $description . ' ' . (string)$query);
        $assessment = candidateAssessment($tags, $title, $description, $channel);
        $all[$videoId] = [
            'video_id'=>$videoId,
            'title'=>$title,
            'url'=>'https://www.youtube.com/watch?v=' . $videoId,
            'channel_id'=>(string)($s['channelId'] ?? ''),
            'channel_title'=>$channel,
            'published_at'=>(string)($s['publishedAt'] ?? ''),
            'thumbnail'=>(string)($s['thumbnails']['high']['url'] ?? $s['thumbnails']['medium']['url'] ?? $s['thumbnails']['default']['url'] ?? ''),
            'matched_query'=>(string)$query,
            'tags'=>$tags,
            'candidate_score'=>$assessment['score'],
            'candidate_status'=>$assessment['status'],
            'assessment_reasons'=>$assessment['reasons'],
            'description'=>$description,
        ];
    }
}

foreach (array_chunk(array_keys($all), 50) as $chunk) {
    $params = ['part'=>'snippet,liveStreamingDetails,status','id'=>implode(',', $chunk),'key'=>$apiKey];
    $data = httpGetJson('https://www.googleapis.com/youtube/v3/videos?' . http_build_query($params));
    $returned = [];
    foreach (($data['items'] ?? []) as $video) {
        $id = (string)($video['id'] ?? '');
        if ($id === '' || !isset($all[$id])) continue;
        $returned[$id] = true;
        $live = $video['liveStreamingDetails'] ?? [];
        $all[$id]['actual_start_time'] = (string)($live['actualStartTime'] ?? '');
        $all[$id]['actual_end_time'] = (string)($live['actualEndTime'] ?? '');
        $all[$id]['embeddable'] = (bool)($video['status']['embeddable'] ?? false);
        $all[$id]['privacy_status'] = (string)($video['status']['privacyStatus'] ?? '');
        $all[$id]['is_live_now'] = isset($live['actualStartTime']) && !isset($live['actualEndTime']);
    }
    foreach ($chunk as $id) if (!isset($returned[$id])) unset($all[$id]);
}

$rows = array_values(array_filter($all, fn(array $x): bool => ($x['is_live_now'] ?? false) === true));
foreach ($rows as &$row) {
    $id = (string)$row['video_id'];
    $row['discovery_status'] = isset($known[$id]) ? '既知' : '新規';
    $row['first_seen_at'] = (string)($known[$id]['first_seen_at'] ?? $runStartedAt);
    $known[$id] = [
        'video_id'=>$id,
        'title'=>(string)$row['title'],
        'url'=>(string)$row['url'],
        'channel_title'=>(string)$row['channel_title'],
        'first_seen_at'=>$row['first_seen_at'],
        'last_seen_at'=>$runStartedAt,
    ];
}
unset($row);
file_put_contents($historyPath, json_encode($known, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
usort($rows, fn(array $a,array $b): int => (($a['discovery_status']==='新規'?1:0) <=> ($b['discovery_status']==='新規'?1:0)) * -1 ?: ($b['candidate_score'] <=> $a['candidate_score']) ?: strcmp($a['title'],$b['title']));

$outDir = __DIR__ . '/output';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) fail('Could not create output directory.');
$stamp = date('Ymd-His');
$jsonPath = $outDir . "/candidates-{$stamp}.json";
$csvPath = $outDir . "/candidates-{$stamp}.csv";
$json = json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
if ($json === false || file_put_contents($jsonPath, $json) === false) fail('Could not create JSON output.');

$fp = fopen($csvPath, 'wb');
if ($fp === false) fail('Could not create CSV output.');
fputcsv($fp, ['discovery','status','score','tags','title','url','channel','thumbnail','matched_query','actual_start_time','embeddable','video_id']);
foreach ($rows as $r) {
    fputcsv($fp, [$r['discovery_status'],$r['candidate_status'],$r['candidate_score'],implode('|',$r['tags']),$r['title'],$r['url'],$r['channel_title'],$r['thumbnail'],$r['matched_query'],$r['actual_start_time'],$r['embeddable']?'1':'0',$r['video_id']]);
}
fclose($fp);

header('Content-Type: text/html; charset=UTF-8');
$count = count($rows);
$adopt = count(array_filter($rows, fn($r)=>($r['candidate_status'] ?? '')==='採用候補'));
$review = count(array_filter($rows, fn($r)=>($r['candidate_status'] ?? '')==='要確認'));
$exclude = $count - $adopt - $review;
echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LiveCameraLinks Collector</title>';
echo '<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;max-width:900px;margin:40px auto;padding:0 20px;line-height:1.7;color:#222">';
echo '<h1>YouTube LIVE候補 収集完了</h1>';
echo '<p style="font-size:32px;font-weight:700;margin-bottom:8px">'.$count.'件</p>';
echo '<p>採用候補 '.$adopt.'件 / 要確認 '.$review.'件 / 除外候補 '.$exclude.'件</p>';
echo '<p><a style="display:inline-block;padding:12px 18px;background:#111;color:#fff;text-decoration:none;border-radius:8px" href="viewer.php">候補一覧を開く</a></p>';
echo '<p style="color:#666">収集結果は output フォルダに保存されました。</p>';
echo '</body></html>';
