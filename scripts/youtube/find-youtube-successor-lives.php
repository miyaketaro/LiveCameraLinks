<?php
declare(strict_types=1);

// Find current LIVE candidates for channels of ended videos.
// This script does not modify camera JSON or approve/register candidates.

$root = dirname(__DIR__, 2);
$config = require $root . '/youtube-test/config.php';
$key = $config['youtube_api_key'] ?? '';

if (!is_string($key) || $key === '') {
    exit("ERROR: YouTube API key is missing\n");
}

$input = __DIR__ . '/output/youtube-live-results-test.csv';
$output = __DIR__ . '/output/youtube-successor-candidates-test.csv';

if (file_exists($output)) {
    exit("ERROR: output already exists; refusing to overwrite\n");
}

$handle = fopen($input, 'r');
if ($handle === false) {
    exit("ERROR: Input CSV not found\n");
}

$header = fgetcsv($handle, 0, ',', '"', '');
if ($header === false) {
    fclose($handle);
    exit("ERROR: Input CSV header not found\n");
}
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$columns = array_flip($header);
foreach (['oldVideoId' => 'video_id', 'status' => 'status', 'channelId' => 'channelId', 'channelTitle' => 'channelTitle'] as $name => $columnName) {
    if (!array_key_exists($columnName, $columns)) {
        fclose($handle);
        exit("ERROR: Required column missing: {$columnName}\n");
    }
}

$endedByChannel = [];
while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    $status = trim((string)($row[$columns['status']] ?? ''));
    $channelId = trim((string)($row[$columns['channelId']] ?? ''));
    if ($status !== 'ENDED' || $channelId === '') {
        continue;
    }
    $endedByChannel[$channelId][] = [
        'oldVideoId' => trim((string)($row[$columns['video_id']] ?? '')),
        'channelId' => $channelId,
        'channelTitle' => trim((string)($row[$columns['channelTitle']] ?? ''))
    ];
}
fclose($handle);

$candidatesByChannel = [];
foreach (array_keys($endedByChannel) as $channelId) {
    $query = http_build_query([
        'part' => 'snippet',
        'type' => 'video',
        'eventType' => 'live',
        'channelId' => $channelId,
        'maxResults' => 5,
        'key' => $key
    ]);
    $url = 'https://www.googleapis.com/youtube/v3/search?' . $query;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        exit("ERROR: API search failed for channel {$channelId} HTTP {$httpCode} {$error}\n");
    }
    $data = json_decode($response, true);
    if (!is_array($data) || isset($data['error']) || !isset($data['items']) || !is_array($data['items'])) {
        exit("ERROR: Invalid API response for channel {$channelId}\n");
    }

    $candidates = [];
    foreach ($data['items'] as $item) {
        if (!is_array($item) || !isset($item['id']['videoId']) || !is_string($item['id']['videoId'])) {
            exit("ERROR: Required candidate fields missing for channel {$channelId}\n");
        }
        $candidateId = $item['id']['videoId'];
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $candidateId)) {
            exit("ERROR: Invalid candidate video ID for channel {$channelId}\n");
        }
        $snippet = $item['snippet'] ?? null;
        if (!is_array($snippet) || ($snippet['liveBroadcastContent'] ?? '') !== 'live') {
            exit("ERROR: Unexpected non-LIVE candidate for channel {$channelId}\n");
        }
        $candidates[$candidateId] = [
            'channelTitle' => (string)($snippet['channelTitle'] ?? ''),
            'candidateVideoId' => $candidateId,
            'candidateTitle' => (string)($snippet['title'] ?? ''),
            'candidateUrl' => 'https://www.youtube.com/watch?v=' . $candidateId,
            'candidateLiveStatus' => 'live'
        ];
    }
    $candidatesByChannel[$channelId] = array_values($candidates);
}

$rows = [];
foreach ($endedByChannel as $channelId => $endedRows) {
    $candidates = $candidatesByChannel[$channelId] ?? [];
    $matchStatus = count($candidates) === 0 ? 'no_live_found' : (count($candidates) === 1 ? 'candidate_found' : 'multiple_candidates');
    foreach ($endedRows as $ended) {
        if ($matchStatus === 'no_live_found') {
            $rows[] = [$ended['oldVideoId'], $channelId, $ended['channelTitle'], '', '', '', '', $matchStatus];
            continue;
        }
        foreach ($candidates as $candidate) {
            $rows[] = [$ended['oldVideoId'], $channelId, $candidate['channelTitle'] ?: $ended['channelTitle'], $candidate['candidateVideoId'], $candidate['candidateTitle'], $candidate['candidateUrl'], $candidate['candidateLiveStatus'], $matchStatus];
        }
    }
}

$temporary = tempnam(dirname($output), 'youtube-successor-candidates-test-');
if ($temporary === false) {
    exit("ERROR: Cannot create temporary output\n");
}
$out = fopen($temporary, 'wb');
if ($out === false) {
    @unlink($temporary);
    exit("ERROR: Cannot open temporary output\n");
}

$header = ['oldVideoId', 'channelId', 'channelTitle', 'candidateVideoId', 'candidateTitle', 'candidateUrl', 'candidateLiveStatus', 'matchStatus'];
if (fputcsv($out, $header, ',', '"', '') === false) {
    fclose($out);
    @unlink($temporary);
    exit("ERROR: Cannot write CSV header\n");
}
foreach ($rows as $row) {
    if (fputcsv($out, $row, ',', '"', '') === false) {
        fclose($out);
        @unlink($temporary);
        exit("ERROR: Cannot write CSV row\n");
    }
}
if (!fclose($out) || file_exists($output) || !rename($temporary, $output)) {
    @unlink($temporary);
    exit("ERROR: Cannot finalize output\n");
}

fwrite(STDOUT, "Created {$output} with " . count($rows) . " rows\n");
