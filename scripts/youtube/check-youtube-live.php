<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$config = require $root . '/youtube-test/config.php';
$key = $config['youtube_api_key'] ?? '';

if (!is_string($key) || $key === '') {
    exit("ERROR: YouTube API key is missing\n");
}

$arguments = array_slice($argv, 1);
$testMode = in_array('--test', $arguments, true);
$candidateMode = in_array('--candidate-test', $arguments, true);

if ($testMode && $candidateMode) {
    exit("ERROR: --test and --candidate-test cannot be combined\n");
}

if ($candidateMode) {
    $input = __DIR__ . '/output/youtube-video-ids-candidate-test.csv';
    $output = __DIR__ . '/output/youtube-live-results-candidate-test.csv';
} elseif ($testMode) {
    $input = __DIR__ . '/output/youtube-video-ids-test.csv';
    $output = __DIR__ . '/output/youtube-live-results-test.csv';
} else {
    $input = __DIR__ . '/output/youtube-video-ids.csv';
    $output = __DIR__ . '/output/youtube-live-results.csv';
}

if (($testMode || $candidateMode) && file_exists($output)) {
    exit("ERROR: Test output already exists; refusing to overwrite\n");
}

$handle = fopen($input, 'r');

if (!$handle) {
    exit("ERROR: Input CSV not found\n");
}

// Read CSV header (including UTF-8 BOM if present)
$header = fgetcsv($handle, 0, ',', '"', '');

if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$column = array_search('video_id', $header ?: [], true);

if ($column === false) {
    fclose($handle);
    exit("ERROR: video_id column not found\n");
}

$ids = [];

while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    $id = trim($row[$column] ?? '');

    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
        $ids[$id] = true;
    }
}

fclose($handle);

$ids = array_keys($ids);

echo "VIDEO IDS: " . count($ids) . "\n";

if (count($ids) === 0) {
    exit("ERROR: No valid video IDs\n");
}

$results = [];

foreach (array_chunk($ids, 50) as $index => $batch) {

    $url = 'https://www.googleapis.com/youtube/v3/videos?' .
        http_build_query([
            'part' => 'snippet,liveStreamingDetails',
            'id' => implode(',', $batch),
            'key' => $key
        ]);

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
        echo "API ERROR: batch " . ($index + 1) .
            " HTTP $httpCode $error\n";
        exit(1);
    }

    $data = json_decode($response, true);

    if (!is_array($data) || isset($data['error'])) {
        echo "API ERROR: invalid response\n";
        exit(1);
    }

    $found = [];

    foreach ($data['items'] ?? [] as $video) {

        $id = $video['id'];
        $found[$id] = true;

        $live = $video['snippet']['liveBroadcastContent']
            ?? 'none';

        $start = $video['liveStreamingDetails']['actualStartTime']
            ?? '';

        $end = $video['liveStreamingDetails']['actualEndTime']
            ?? '';

        $channelId = $video['snippet']['channelId'] ?? '';
        $channelTitle = $video['snippet']['channelTitle'] ?? '';

        if ($live === 'live' && $end === '') {
            $status = 'LIVE';
        } elseif ($live === 'upcoming') {
            $status = 'UPCOMING';
        } elseif ($end !== '') {
            $status = 'ENDED';
        } else {
            $status = 'NOT_LIVE';
        }

        $results[$id] = [
            $id,
            $status,
            $live,
            $start,
            $end,
            $channelId,
            $channelTitle
        ];
    }

    foreach ($batch as $id) {
        if (!isset($found[$id])) {
            $results[$id] = [
                $id,
                'NOT_FOUND',
                '',
                '',
                '',
                '',
                ''
            ];
        }
    }

    echo "BATCH " . ($index + 1) . ": OK\n";
}

// Write CSV only after all API batches succeed
$out = fopen($output, 'w');

if (!$out) {
    exit("ERROR: Cannot create output CSV\n");
}

    $header = [
    'video_id',
    'status',
    'liveBroadcastContent',
    'actualStartTime',
    'actualEndTime'
    ];
    $extendedOutput = $testMode || $candidateMode;
    if ($extendedOutput) {
        $header[] = 'channelId';
        $header[] = 'channelTitle';
    }
    fputcsv($out, $header, ',', '"', '');

foreach ($ids as $id) {
    $row = $results[$id];
    if (!$extendedOutput) {
        $row = array_slice($row, 0, 5);
    }
    fputcsv($out, $row, ',', '"', '');
}

fclose($out);

// Summary
$counts = [];

foreach ($results as $row) {
    $status = $row[1];
    $counts[$status] = ($counts[$status] ?? 0) + 1;
}

ksort($counts);

foreach ($counts as $status => $count) {
    echo "$status: $count\n";
}

echo "CSV CREATED: $output\n";
