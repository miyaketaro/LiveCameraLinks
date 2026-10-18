<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$reviewInput = __DIR__ . '/output/youtube-not-found-review-test.csv';
$providerInput = __DIR__ . '/output/youtube-provider-channels-test.csv';
$output = __DIR__ . '/output/youtube-not-found-successor-candidates-test.csv';

function successorFail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function csvOpen(string $path, array $required): array
{
    $handle = fopen($path, 'r');
    if ($handle === false) successorFail('unable to open input CSV: ' . $path);
    $header = fgetcsv($handle, 0, ',', '"', '');
    if ($header === false) { fclose($handle); successorFail('CSV header not found: ' . $path); }
    if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $positions = array_flip($header);
    foreach ($required as $column) if (!array_key_exists($column, $positions)) { fclose($handle); successorFail('required column missing: ' . $column); }
    return [$handle, $positions];
}

function normalizeSuccessor(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[\x{3000}\s]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('~[・／/,、:：;；|｜()（）【】「」『』［］\[\]{}<>＜＞]+~u', ' ', $value) ?? $value);
}

function termsSuccessor(string $value): array
{
    $generic = ['live','ライブ','ライブカメラ','camera','tokyo','東京','付近','方面','周辺','カメラ'];
    $terms = [];
    preg_match_all('/[\p{Han}\p{Katakana}A-Za-z0-9_-]{2,}/u', normalizeSuccessor($value), $matches);
    foreach ($matches[0] as $term) if (!in_array($term, $generic, true)) $terms[$term] = true;
    return $terms;
}

function apiGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false || $http !== 200) successorFail('YouTube API request failed: HTTP ' . $http . ' ' . $error);
    $data = json_decode($response, true);
    if (!is_array($data) || isset($data['error'])) successorFail('YouTube API returned invalid response');
    return $data;
}

if (file_exists($output)) successorFail('output already exists; refusing to overwrite');
if (!is_file($reviewInput) || !is_file($providerInput)) successorFail('required input CSV not found');
$config = require $root . '/youtube-test/config.php';
$key = $config['youtube_api_key'] ?? '';
if (!is_string($key) || $key === '') successorFail('YouTube API key is missing');

[$providerHandle, $providerPos] = csvOpen($providerInput, ['cameraId','providerPageUrl','providerChannelId','providerChannelTitle','providerChannelHandle','evidence','approvalStatus']);
$providers = [];
while (($row = fgetcsv($providerHandle, 0, ',', '"', '')) !== false) {
    if ($row === [null]) successorFail('invalid provider CSV row');
    $cameraId = trim((string) ($row[$providerPos['cameraId']] ?? ''));
    if ($cameraId === '') successorFail('provider CSV cameraId is empty');
    $providers[$cameraId] = [
        'channelId' => trim((string) ($row[$providerPos['providerChannelId']] ?? '')),
        'channelTitle' => (string) ($row[$providerPos['providerChannelTitle']] ?? ''),
        'handle' => (string) ($row[$providerPos['providerChannelHandle']] ?? ''),
    ];
}
fclose($providerHandle);

[$reviewHandle, $reviewPos] = csvOpen($reviewInput, ['video_id','cameraId','cameraName','place','aliases','providerPageUrl','sourceFile']);
$reviews = [];
while (($row = fgetcsv($reviewHandle, 0, ',', '"', '')) !== false) {
    if ($row === [null]) successorFail('invalid review CSV row');
    $cameraId = trim((string) ($row[$reviewPos['cameraId']] ?? ''));
    $videoId = trim((string) ($row[$reviewPos['video_id']] ?? ''));
    if ($videoId === '') successorFail('review CSV oldVideoId is empty');
    $reviews[] = [
        'oldVideoId' => $videoId,
        'cameraId' => $cameraId,
        'cameraName' => (string) ($row[$reviewPos['cameraName']] ?? ''),
        'place' => (string) ($row[$reviewPos['place']] ?? ''),
        'aliases' => (string) ($row[$reviewPos['aliases']] ?? ''),
        'providerPageUrl' => (string) ($row[$reviewPos['providerPageUrl']] ?? ''),
        'sourceFile' => (string) ($row[$reviewPos['sourceFile']] ?? ''),
    ];
}
fclose($reviewHandle);

$channelReviews = [];
$enrichedReviews = [];
foreach ($reviews as $review) {
    $provider = $providers[$review['cameraId']] ?? ['channelId' => '', 'channelTitle' => '', 'handle' => ''];
    $review['providerChannelId'] = $provider['channelId'];
    $review['providerChannelTitle'] = $provider['channelTitle'];
    $review['providerChannelHandle'] = $provider['handle'];
    $enrichedReviews[] = $review;
    $channelReviews[$provider['channelId']][] = $review;
}

$channelCandidates = [];
foreach ($channelReviews as $channelId => $group) {
    if ($channelId === '') continue;
    $search = apiGet('https://www.googleapis.com/youtube/v3/search?' . http_build_query([
        'part' => 'snippet', 'type' => 'video', 'eventType' => 'live',
        'channelId' => $channelId, 'maxResults' => 5, 'key' => $key,
    ]));
    $ids = [];
    foreach ($search['items'] ?? [] as $item) {
        $id = (string) ($item['id']['videoId'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) $ids[$id] = true;
    }
    $channelCandidates[$channelId] = array_keys($ids);
}

$verified = [];
foreach ($channelCandidates as $channelId => $ids) {
    if ($ids === []) { $verified[$channelId] = []; continue; }
    $data = apiGet('https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
        'part' => 'snippet,liveStreamingDetails', 'id' => implode(',', $ids), 'key' => $key,
    ]));
    foreach ($data['items'] ?? [] as $item) {
        $id = (string) ($item['id'] ?? '');
        $live = (string) ($item['snippet']['liveBroadcastContent'] ?? 'none');
        $end = (string) ($item['liveStreamingDetails']['actualEndTime'] ?? '');
        $status = ($live === 'live' && $end === '') ? 'LIVE' : (($end !== '') ? 'ENDED' : 'NOT_LIVE');
        if ($status === 'LIVE') $verified[$channelId][$id] = ['title' => (string) ($item['snippet']['title'] ?? ''), 'status' => $status];
    }
}

$rows = [];
foreach ($enrichedReviews as $review) {
    $channelId = (string) ($review['providerChannelId'] ?? '');
    $providerTitle = (string) ($review['providerChannelTitle'] ?? '');
    $providerHandle = (string) ($review['providerChannelHandle'] ?? '');
    $matches = $verified[$channelId] ?? [];
    $cameraTerms = termsSuccessor($review['cameraName'] . ' ' . $review['place'] . ' ' . $review['aliases']);
    if ($channelId === '') {
        $rows[] = [$review['oldVideoId'],$review['cameraId'],$review['cameraName'],$review['place'],$review['aliases'],$review['providerPageUrl'],'','', $providerHandle,'','','','', 'location_unknown','provider_review_required','公式チャンネル未確認のため人間確認が必要','pending',$review['sourceFile']];
        continue;
    }
    if ($matches === []) {
        $rows[] = [$review['oldVideoId'],$review['cameraId'],$review['cameraName'],$review['place'],$review['aliases'],$review['providerPageUrl'],$channelId,$providerTitle,$providerHandle,'','','','', 'location_unknown','no_candidate','現在LIVE候補なし','pending',$review['sourceFile']];
        continue;
    }
    foreach ($matches as $candidateId => $candidate) {
        $candidateTerms = termsSuccessor($candidate['title']);
        $overlap = array_keys(array_intersect_key($cameraTerms, $candidateTerms));
        $location = count($overlap) >= 2 ? 'exact_match' : (count($overlap) === 1 ? 'specific_match' : 'location_unknown');
        $status = in_array($location, ['exact_match','specific_match'], true) ? 'candidate_found' : 'human_review_required';
        $reason = $overlap === [] ? '現在LIVEだが具体的地点名の一致根拠が不足' : '現在LIVE、地点語一致: ' . implode('、', $overlap);
        $rows[] = [$review['oldVideoId'],$review['cameraId'],$review['cameraName'],$review['place'],$review['aliases'],$review['providerPageUrl'],$channelId,$providerTitle,$providerHandle,$candidateId,$candidate['title'],'https://www.youtube.com/watch?v=' . $candidateId,$candidate['status'],$location,$status,$reason,'pending',$review['sourceFile']];
    }
}

$temp = tempnam(dirname($output), 'not-found-successor-');
if ($temp === false || ($out = fopen($temp, 'wb')) === false) successorFail('unable to create temporary output');
$header = ['oldVideoId','cameraId','cameraName','place','aliases','providerPageUrl','providerChannelId','providerChannelTitle','providerChannelHandle','candidateVideoId','candidateTitle','candidateUrl','candidateLiveStatus','locationMatchStatus','successorSearchStatus','reviewNote','approvalStatus','sourceFile'];
fputcsv($out, $header, ',', '"', '');
foreach ($rows as $row) fputcsv($out, $row, ',', '"', '');
if (!fclose($out) || !rename($temp, $output)) { @unlink($temp); successorFail('unable to finalize output'); }
