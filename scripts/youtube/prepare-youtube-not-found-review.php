<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$input = __DIR__ . '/output/youtube-live-results-test.csv';
$output = __DIR__ . '/output/youtube-not-found-review-test.csv';

function notFoundFail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function notFoundStrings(mixed $value): array
{
    if (is_string($value)) return [$value];
    $result = [];
    if (is_array($value)) {
        foreach ($value as $child) $result = array_merge($result, notFoundStrings($child));
    }
    return $result;
}

function notFoundVideoIds(string $value): array
{
    $ids = [];
    $patterns = [
        '~(?:https?://)?(?:www\.)?youtube\.com/watch\?[^\s#]*?\bv=([A-Za-z0-9_-]{11})(?:[^A-Za-z0-9_-]|$)~i',
        '~(?:https?://)?(?:www\.)?youtube\.com/live/([A-Za-z0-9_-]{11})(?:[^A-Za-z0-9_-]|$)~i',
        '~(?:https?://)?youtu\.be/([A-Za-z0-9_-]{11})(?:[^A-Za-z0-9_-]|$)~i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $value, $matches)) {
            foreach ($matches[1] as $id) $ids[$id] = true;
        }
    }
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', trim($value))) $ids[trim($value)] = true;
    return array_keys($ids);
}

function notFoundValue(array $record, array $keys, string $fallback = ''): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $record)) {
            continue;
        }

        $value = $record[$key];

        if ($value === null || !is_scalar($value)) {
            continue;
        }

        $text = (string) $value;

        if (trim($text) === '') {
            continue;
        }

        return $text;
    }
    return $fallback;
}

function notFoundAliases(array $record): string
{
    if (!array_key_exists('aliases', $record) || $record['aliases'] === null) return '[]';
    $encoded = json_encode($record['aliases'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? '' : $encoded;
}

function notFoundVisibility(array $record): string
{
    if (!array_key_exists('visibility', $record) || $record['visibility'] === null) return 'public';
    $value = trim((string) $record['visibility']);
    if ($value === '') return 'public';
    $lower = strtolower($value);
    return in_array($lower, ['public', 'hidden', 'retired'], true) ? $lower : $value;
}

if (file_exists($output)) notFoundFail('output already exists; refusing to overwrite');
if (!is_file($input)) notFoundFail('input CSV not found');

$cameraIndex = [];
$cameraRoot = $root . DIRECTORY_SEPARATOR . 'camera';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cameraRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') continue;
    $basename = strtolower($file->getBasename());
    if ($basename === 'index.json' || $basename === 'camera_index.json') continue;
    $path = $file->getPathname();
    $json = file_get_contents($path);
    if ($json === false) notFoundFail('unable to read JSON: ' . $path);
    $doc = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($doc)) notFoundFail('invalid JSON: ' . $path);
    if (!isset($doc['cameras']) || !is_array($doc['cameras'])) continue;
    $relative = ltrim(str_replace($root, '', $path), '\\/');
    foreach ($doc['cameras'] as $record) {
        if (!is_array($record)) notFoundFail('invalid camera record in JSON: ' . $path);
        $ids = [];
        foreach (notFoundStrings($record) as $value) foreach (notFoundVideoIds($value) as $id) $ids[$id] = true;
        if ($ids === []) continue;
        $camera = [
            'cameraId' => notFoundValue($record, ['cameraId', 'id']),
            'cameraName' => notFoundValue($record, ['cameraName', 'name']),
            'category' => notFoundValue($record, ['category'], (string) ($doc['category'] ?? '')),
            'prefectureCode' => notFoundValue($record, ['prefectureCode', 'prefecture'], (string) ($doc['prefecture'] ?? '')),
            'areaCode' => notFoundValue($record, ['areaCode', 'area'], (string) ($doc['area'] ?? '')),
            'place' => notFoundValue($record, ['place']),
            'aliases' => notFoundAliases($record),
            'source' => notFoundValue($record, ['source']),
            'providerPageUrl' => notFoundValue($record, ['providerPageUrl']),
            'directStreamUrl' => notFoundValue($record, ['directStreamUrl']),
            'desc' => notFoundValue($record, ['desc']),
            'sourceFile' => $relative,
            'visibility' => notFoundVisibility($record),
            '_key' => notFoundValue($record, ['cameraId', 'id']) . '|' . $relative,
        ];
        foreach (array_keys($ids) as $id) $cameraIndex[$id][$camera['_key']] = $camera;
    }
}

$columns = ['video_id', 'status'];
$handle = fopen($input, 'r');
if ($handle === false) notFoundFail('unable to open input CSV');
$header = fgetcsv($handle, 0, ',', '"', '');
if ($header === false) { fclose($handle); notFoundFail('input CSV header not found'); }
if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
$positions = array_flip($header);
foreach ($columns as $column) if (!array_key_exists($column, $positions)) { fclose($handle); notFoundFail('required column missing: ' . $column); }

$rows = [];
$counts = ['camera_found' => 0, 'camera_multiple_matches' => 0, 'camera_not_found' => 0];
while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    if ($row === [null] || !isset($row[$positions['video_id']], $row[$positions['status']])) { fclose($handle); notFoundFail('invalid input CSV row'); }
    if (strtoupper(trim((string) $row[$positions['status']])) !== 'NOT_FOUND') continue;
    $videoId = trim((string) $row[$positions['video_id']]);
    if ($videoId === '') continue;
    $matches = array_values($cameraIndex[$videoId] ?? []);
    if ($matches === []) {
        $counts['camera_not_found']++;
        $rows[] = [$videoId, 'NOT_FOUND', '', '', '', '', '', '', '', '', '', '', '', '', '', 'camera_not_found', 'required', 'not_started', 'YouTube動画が見つからず、camera逆引きも確認が必要'];
        continue;
    }
    $matchStatus = count($matches) > 1 ? 'camera_multiple_matches' : 'camera_found';
    $counts[$matchStatus]++;
    foreach ($matches as $camera) {
        $rows[] = [$videoId, 'NOT_FOUND', $camera['cameraId'], $camera['cameraName'], $camera['category'], $camera['prefectureCode'], $camera['areaCode'], $camera['place'], $camera['aliases'], $camera['source'], $camera['providerPageUrl'], $camera['directStreamUrl'], $camera['desc'], $camera['sourceFile'], $camera['visibility'], $matchStatus, 'required', 'not_started', $matchStatus === 'camera_found' ? 'YouTube動画が見つからず、公式配信元の後継確認が必要' : 'YouTube動画が見つからず、複数camera一致の確認が必要'];
    }
}
fclose($handle);

$temp = tempnam(dirname($output), 'not-found-');
if ($temp === false || ($out = fopen($temp, 'wb')) === false) notFoundFail('unable to create temporary output');
$outHeader = ['video_id','auditStatus','cameraId','cameraName','category','prefectureCode','areaCode','place','aliases','source','providerPageUrl','directStreamUrl','desc','sourceFile','visibility','cameraMatchStatus','providerReviewStatus','successorSearchStatus','reviewNote'];
fputcsv($out, $outHeader, ',', '"', '');
foreach ($rows as $row) fputcsv($out, $row, ',', '"', '');
if (!fclose($out) || !rename($temp, $output)) { @unlink($temp); notFoundFail('unable to finalize output'); }

fwrite(STDOUT, sprintf("NOT_FOUND対象: %d件; 出力行: %d; camera_found: %d; camera_multiple_matches: %d; camera_not_found: %d\n", count(array_unique(array_column($rows, 0))), count($rows), $counts['camera_found'], $counts['camera_multiple_matches'], $counts['camera_not_found']));
