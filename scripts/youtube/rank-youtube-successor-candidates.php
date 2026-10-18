<?php
declare(strict_types=1);

// Rank successor candidates for human review only. No API or camera JSON access.
$input = __DIR__ . '/output/youtube-successor-candidates-enriched-test.csv';
$output = __DIR__ . '/output/youtube-successor-candidates-ranked-test.csv';

function failRank(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function normalizeRank(string $value): string
{
    if (class_exists('Normalizer')) {
        $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
    }
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    if (function_exists('mb_convert_kana')) {
        $value = mb_convert_kana($value, 'as', 'UTF-8');
    }
    $value = strtr($value, ['０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9']);
    $value = replaceRank('/[\x{3000}\s]+/u', ' ', $value);
    // Use ~ as delimiter because the punctuation set includes a slash.
    $value = replaceRank('~[・／/,、:：;；|｜()（）【】「」『』［］\[\]{}<>＜＞]+~u', ' ', $value);
    return trim(replaceRank('/\s+/u', ' ', $value));
}

function replaceRank(string $pattern, string $replacement, string $subject): string
{
    $result = preg_replace($pattern, $replacement, $subject);
    if ($result === null) {
        failRank('normalization regular expression failed');
    }
    return $result;
}

function rankLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function rankContains(string $haystack, string $needle): bool
{
    if (function_exists('mb_strpos')) {
        return mb_strpos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return strpos($haystack, $needle) !== false;
}

function rankTerms(string $value): array
{
    $normalized = normalizeRank($value);
    $generic = ['live','ライブ','ライブカメラ','camera','tokyo','東京','付近','方面','周辺','カメラ','の今'];
    $terms = [];
    preg_match_all('/[\p{Han}\p{Katakana}A-Za-z0-9_-]{2,}/u', $normalized, $matches);
    foreach ($matches[0] as $term) {
        if (in_array($term, $generic, true)) {
            continue;
        }
        $terms[$term] = max($terms[$term] ?? 0, rankLength($term));
    }
    return $terms;
}

function aliasesFromCell(string $cell, bool &$valid): array
{
    $valid = true;
    if (trim($cell) === '') {
        return [];
    }
    $decoded = json_decode($cell, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        $valid = false;
        return [];
    }
    $result = [];
    foreach ($decoded as $value) {
        if (is_string($value)) {
            $result[] = $value;
        } else {
            $valid = false;
        }
    }
    return $result;
}

if (file_exists($output)) {
    failRank('output already exists; refusing to overwrite');
}
if (!is_file($input)) {
    failRank('input CSV not found');
}

$baseHeader = [
    'oldVideoId','channelId','channelTitle','candidateVideoId','candidateTitle','candidateUrl',
    'candidateLiveStatus','matchStatus','cameraId','cameraName','category','prefectureCode',
    'areaCode','sourceFile','cameraMatchStatus','cameraPlace','cameraAliases','cameraSource',
    'cameraProviderPageUrl','cameraDesc',
];
$handle = fopen($input, 'r');
if ($handle === false) {
    failRank('unable to open input CSV');
}
$header = fgetcsv($handle, 0, ',', '"', '');
if ($header === false) {
    fclose($handle);
    failRank('input CSV header not found');
}
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}
if ($header !== $baseHeader) {
    fclose($handle);
    failRank('input CSV header does not match expected columns');
}

$rows = [];
while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    if ($row === [null] || count($row) !== count($baseHeader)) {
        fclose($handle);
        failRank('invalid input CSV row');
    }
    $rows[] = array_combine($baseHeader, $row);
}
fclose($handle);

$scored = [];
foreach ($rows as $index => $row) {
    $candidateId = trim((string) $row['candidateVideoId']);
    $reason = [];
    $score = 0;
    $termMatches = [];
    $placeMatches = [];
    $nameMatches = [];
    $aliasesValid = true;
    $candidateTerms = rankTerms((string) $row['candidateTitle'] . ' ' . (string) $row['channelTitle']);
    $candidateText = normalizeRank((string) $row['candidateTitle'] . ' ' . (string) $row['channelTitle']);
    $cameraFields = [
        'place' => (string) $row['cameraPlace'],
        'name' => (string) $row['cameraName'],
        'source' => (string) $row['cameraSource'],
        'desc' => (string) $row['cameraDesc'],
    ];
    $aliases = aliasesFromCell((string) $row['cameraAliases'], $aliasesValid);
    if (!$aliasesValid) {
        $reason[] = 'cameraAliasesのJSONが不正';
    }
    foreach (['place','name'] as $field) {
        foreach (array_keys(rankTerms($cameraFields[$field])) as $term) {
            if (rankLength($term) >= 3 && rankContains($candidateText, $term)) {
                $termMatches[$term] = true;
                if ($field === 'place') $placeMatches[$term] = true;
                if ($field === 'name') $nameMatches[$term] = true;
            }
        }
    }
    foreach ($aliases as $alias) {
        foreach (array_keys(rankTerms($alias)) as $term) {
            if (rankLength($term) >= 3 && rankContains($candidateText, $term)) {
                $termMatches[$term] = true;
            }
        }
    }
    if ($placeMatches !== []) {
        $score += 40 + min(20, (count($placeMatches) - 1) * 10);
        $reason[] = 'place一致: ' . implode('、', array_keys($placeMatches));
    }
    if ($nameMatches !== []) {
        $score += 30 + min(15, (count($nameMatches) - 1) * 8);
        $reason[] = 'cameraName一致: ' . implode('、', array_keys($nameMatches));
    }
    $aliasMatches = array_diff(array_keys($termMatches), array_keys($placeMatches), array_keys($nameMatches));
    if ($aliasMatches !== []) {
        $score += min(25, count($aliasMatches) * 15);
        $reason[] = 'aliases一致: ' . implode('、', $aliasMatches);
    }
    foreach (['source' => 10, 'desc' => 5] as $field => $weight) {
        $terms = rankTerms($cameraFields[$field]);
        $hits = array_values(array_intersect(array_keys($terms), array_keys($candidateTerms)));
        if ($hits !== []) {
            $score += $weight;
            $reason[] = $field . '補助一致: ' . implode('、', $hits);
        }
    }
    if ($candidateId === '') {
        $score = 0;
        $reason[] = '候補LIVEなし';
    }
    $hasSpecific = count($termMatches) > 0;
    $cameraKnown = trim((string) $row['cameraId']) !== '' && trim((string) $row['cameraName']) !== '';
    $reviewStatus = 'weak_candidate';
    if ($score >= 35) $reviewStatus = 'possible_candidate';
    if ($score >= 70 && $hasSpecific && $cameraKnown && $aliasesValid && (string) $row['cameraMatchStatus'] === 'camera_found') {
        $reviewStatus = 'strong_candidate';
    }
    if ((string) $row['cameraMatchStatus'] !== 'camera_found') {
        $reason[] = 'camera照合が一意でないためレビュー必要';
    }
    if ($reason === []) $reason[] = '固有地点の一致根拠なし';
    $scored[] = ['row' => $row, 'score' => $score, 'terms' => count($termMatches), 'place' => count($placeMatches), 'name' => count($nameMatches), 'status' => $reviewStatus, 'reason' => implode('；', $reason), 'index' => $index];
}

$groups = [];
foreach ($scored as $item) {
    $key = (string) $item['row']['oldVideoId'] . "\0" . (string) $item['row']['cameraId'];
    $groups[$key][] = $item;
}
foreach ($groups as &$group) {
    usort($group, static function (array $a, array $b): int {
        foreach (['score','terms','place','name'] as $field) {
            if ($a[$field] !== $b[$field]) return $b[$field] <=> $a[$field];
        }
        return strcmp((string) $a['row']['candidateVideoId'], (string) $b['row']['candidateVideoId']);
    });
    $rank = 1;
    foreach ($group as &$item) {
        $item['rank'] = ((string) $item['row']['candidateVideoId'] === '') ? '' : (string) $rank++;
    }
    unset($item);
}
unset($group);

$byIndex = [];
foreach ($groups as $group) foreach ($group as $item) $byIndex[$item['index']] = $item;
ksort($byIndex);
$temp = tempnam(dirname($output), 'rank-');
if ($temp === false || ($out = fopen($temp, 'wb')) === false) {
    failRank('unable to create temporary output');
}
$outHeader = array_merge($baseHeader, ['candidateScore','candidateRank','candidateReviewStatus','candidateMatchReason']);
fputcsv($out, $outHeader, ',', '"', '');
foreach ($byIndex as $item) {
    $row = $item['row'];
    fputcsv($out, array_merge(array_values($row), [(string) $item['score'], (string) $item['rank'], $item['status'], $item['reason']]), ',', '"', '');
}
if (!fclose($out) || !rename($temp, $output)) {
    @unlink($temp);
    failRank('unable to finalize output');
}
