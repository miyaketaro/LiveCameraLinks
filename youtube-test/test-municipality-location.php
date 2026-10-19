<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$snapshotPath = __DIR__ . '/output/block-kanto-tourism-latest.json';
$masterPath = $root . '/data/master/municipalities.json';

function readJson(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "File not found: {$path}\n");
        exit(1);
    }
    $value = json_decode((string)file_get_contents($path), true);
    if (!is_array($value)) {
        fwrite(STDERR, "Invalid JSON: {$path}\n");
        exit(1);
    }
    return $value;
}

function prefectureList(array $master): array
{
    $items = [];
    foreach ((array)($master['municipalities'] ?? []) as $municipality) {
        $name = (string)($municipality['prefecture_name'] ?? '');
        if ($name !== '') {
            $items[$name] = true;
        }
    }
    return array_keys($items);
}

function detectPrefecture(string $title, string $description, string $channel, array $prefectures): string
{
    foreach ([$title, $description, $channel] as $text) {
        foreach ($prefectures as $prefecture) {
            if (mb_strpos($text, $prefecture, 0, 'UTF-8') !== false) {
                return $prefecture;
            }
        }
    }
    return '';
}

function inferMunicipality(array $row, array $municipalities): ?array
{
    $searched = array_values(array_filter(array_map('strval', (array)($row['searched_prefectures'] ?? []))));
    if ($searched === []) return null;
    $text = (string)($row['title'] ?? '') . ' ' . (string)($row['description'] ?? '') . ' ' . (string)($row['channel_title'] ?? '');
    $exactHits = []; $shortHits = [];
    foreach ($municipalities as $municipality) {
        if (!is_array($municipality) || !in_array((string)($municipality['prefecture_name'] ?? ''), $searched, true)) continue;
        $exactTokens = [(string)($municipality['official_name'] ?? ''), (string)($municipality['full_name'] ?? '')];
        foreach ((array)($municipality['aliases'] ?? []) as $alias) if (is_scalar($alias)) $exactTokens[] = (string)$alias;
        foreach (array_unique(array_filter($exactTokens, static fn(string $token): bool => $token !== '')) as $token) if (mb_strpos($text,$token,0,'UTF-8')!==false) { $exactHits[(string)$municipality['municipality_code']]=['municipality'=>$municipality,'match_type'=>'exact']; break; }
        if (in_array((string)($municipality['type']??''),['city','town','village'],true)) {
            $short=preg_replace('/(市|町|村)$/u','',(string)($municipality['official_name']??''));
            if (is_string($short) && mb_strlen($short,'UTF-8')>=3 && mb_strpos($text,$short,0,'UTF-8')!==false && preg_match('/'.preg_quote($short,'/').'(?:(?:駅)|(?:市役所)|(?:町役場)|(?:村役場))/u',$text)) $shortHits[(string)$municipality['municipality_code']]=['municipality'=>$municipality,'match_type'=>'short_context'];
        }
    }
    if (count($exactHits)===1) return array_values($exactHits)[0];
    if (count($exactHits)>1) return null;
    return count($shortHits)===1 ? array_values($shortHits)[0] : null;
}

$snapshot = readJson($snapshotPath);
$master = readJson($masterPath);
$municipalities = (array)($master['municipalities'] ?? []);
$prefectures = prefectureList($master);
$counts = ['一致' => 0, '地域外' => 0, '未判定' => 0];
$improved = 0;

foreach ($snapshot as $row) {
    if (!is_array($row)) {
        continue;
    }
    $old = (string)($row['location_status'] ?? '未判定');
    $detected = detectPrefecture((string)($row['title'] ?? ''), (string)($row['description'] ?? ''), (string)($row['channel_title'] ?? ''), $prefectures);
    $municipalityCode = '';
    $municipalityName = '';
    $municipalityMatchType = '';
    if ($detected === '') {
        $municipalityMatch = inferMunicipality($row, $municipalities);
        if ($municipalityMatch !== null) {
            $municipality = $municipalityMatch['municipality'];
            $detected = (string)$municipality['prefecture_name'];
            $municipalityCode = (string)$municipality['municipality_code'];
            $municipalityName = (string)$municipality['official_name'];
            $municipalityMatchType = (string)$municipalityMatch['match_type'];
        }
    }
    $searched = (array)($row['searched_prefectures'] ?? []);
    if ($detected === '') {
        $new = '未判定';
    } elseif (in_array($detected, $searched, true)) {
        $new = '一致';
    } else {
        $new = '地域外';
    }
    $counts[$new]++;
    if ($old === '未判定' && $new === '一致') {
        $improved++;
    }
    echo json_encode([
        'title' => (string)($row['title'] ?? ''),
        'searched_prefectures' => $searched,
        'old_location_status' => $old,
        'new_location_status' => $new,
        'detected_prefecture' => $detected,
        'detected_municipality_code' => $municipalityCode,
        'detected_municipality_name' => $municipalityName,
        'municipality_match_type' => $municipalityMatchType,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}


$synthetic = ['title' => '江戸川区ライブカメラ', 'description' => '', 'channel_title' => '', 'searched_prefectures' => ['東京都']];
$syntheticMatch = inferMunicipality($synthetic, $municipalities);
echo json_encode(['synthetic_case' => '江戸川区明示', 'location_status' => $syntheticMatch !== null ? '一致' : '未判定', 'detected_municipality_code' => $syntheticMatch !== null ? (string)$syntheticMatch['municipality']['municipality_code'] : '', 'detected_municipality_name' => $syntheticMatch !== null ? (string)$syntheticMatch['municipality']['official_name'] : '', 'municipality_match_type' => $syntheticMatch !== null ? (string)$syntheticMatch['match_type'] : ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

echo json_encode([
    'summary' => $counts,
    'improved_count' => $improved,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
