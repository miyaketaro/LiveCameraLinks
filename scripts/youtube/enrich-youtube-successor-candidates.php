<?php
declare(strict_types=1);

// Enrich successor-LIVE review rows with read-only camera JSON matches.

$root = dirname(__DIR__, 2);
$input = __DIR__ . '/output/youtube-successor-candidates-test.csv';
$output = __DIR__ . '/output/youtube-successor-candidates-enriched-test.csv';

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function videoIdsFromValue(string $value): array
{
    $ids = [];
    $patterns = [
        '~(?:https?://)?(?:www\.)?youtube\.com/watch\?[^\s#]*?\bv=([A-Za-z0-9_-]{11})(?:[^A-Za-z0-9_-]|$)~i',
        '~(?:https?://)?(?:www\.)?youtube\.com/live/([A-Za-z0-9_-]{11})(?:[^A-Za-z0-9_-]|$)~i',
        '~(?:https?://)?youtu\.be/([A-Za-z0-9_-]{11})(?:[^A-Za-z0-9_-]|$)~i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $value, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[$id] = true;
            }
        }
    }
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', trim($value))) {
        $ids[trim($value)] = true;
    }
    return array_keys($ids);
}

function collectStrings(mixed $value): array
{
    if (is_string($value)) {
        return [$value];
    }
    $result = [];
    if (is_array($value)) {
        foreach ($value as $child) {
            $result = array_merge($result, collectStrings($child));
        }
    }
    return $result;
}

function firstValue(array $record, array $keys, string $fallback = ''): string
{
    foreach ($keys as $key) {
        if (isset($record[$key]) && is_scalar($record[$key])) {
            return (string) $record[$key];
        }
    }
    return $fallback;
}

function snapshotAliases(array $record): string
{
    if (!array_key_exists('aliases', $record) || $record['aliases'] === null) {
        return '[]';
    }
    $aliases = $record['aliases'];
    if (is_array($aliases)) {
        $encoded = json_encode($aliases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? '' : $encoded;
    }
    // Preserve an anomalous non-array value as JSON rather than coercing it.
    $encoded = json_encode($aliases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? '' : $encoded;
}

if (file_exists($output)) {
    fail('output already exists; refusing to overwrite');
}
if (!is_file($input)) {
    fail('input CSV not found');
}

$cameraIndex = [];
$cameraRoot = $root . DIRECTORY_SEPARATOR . 'camera';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cameraRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'json' || strtolower($file->getBasename()) === 'index.json') {
        continue;
    }
    $path = $file->getPathname();
    $json = file_get_contents($path);
    if ($json === false) {
        fail('unable to read JSON: ' . $path);
    }
    $doc = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($doc)) {
        fail('invalid JSON: ' . $path);
    }
    if (!isset($doc['cameras']) || !is_array($doc['cameras'])) {
        continue;
    }
    $relative = ltrim(str_replace($root, '', $path), '\\/');
    foreach ($doc['cameras'] as $record) {
        if (!is_array($record)) {
            fail('invalid camera record in JSON: ' . $path);
        }
        $ids = [];
        foreach (collectStrings($record) as $value) {
            foreach (videoIdsFromValue($value) as $id) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            continue;
        }
        $camera = [
            'cameraId' => firstValue($record, ['cameraId', 'id']),
            'cameraName' => firstValue($record, ['cameraName', 'name']),
            'category' => firstValue($record, ['category'], (string) ($doc['category'] ?? '')),
            'prefectureCode' => firstValue($record, ['prefectureCode', 'prefecture'], (string) ($doc['prefecture'] ?? '')),
            'areaCode' => firstValue($record, ['areaCode', 'area'], (string) ($doc['area'] ?? '')),
            'sourceFile' => $relative,
            'cameraPlace' => firstValue($record, ['place']),
            'cameraAliases' => snapshotAliases($record),
            'cameraSource' => firstValue($record, ['source']),
            'cameraProviderPageUrl' => firstValue($record, ['providerPageUrl']),
            'cameraDesc' => firstValue($record, ['desc']),
            '_key' => firstValue($record, ['cameraId', 'id']) . '|' . $relative,
        ];
        foreach (array_keys($ids) as $id) {
            $cameraIndex[$id][$camera['_key']] = $camera;
        }
    }
}

$handle = fopen($input, 'r');
if ($handle === false) {
    fail('unable to open input CSV');
}
$header = fgetcsv($handle, 0, ',', '"', '');
if ($header === false) {
    fclose($handle);
    fail('input CSV header not found');
}
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}
$expected = ['oldVideoId', 'channelId', 'channelTitle', 'candidateVideoId', 'candidateTitle', 'candidateUrl', 'candidateLiveStatus', 'matchStatus'];
if ($header !== $expected) {
    fclose($handle);
    fail('input CSV header does not match expected columns');
}

$rows = [];
while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    if ($row === [null] || count($row) !== count($expected)) {
        fclose($handle);
        fail('invalid input CSV row');
    }
    $base = array_combine($expected, $row);
    $oldId = (string) $base['oldVideoId'];
    $matches = array_values($cameraIndex[$oldId] ?? []);
    if ($matches === []) {
        $rows[] = array_merge($row, ['', '', '', '', '', '', 'camera_not_found', '', '', '', '', '']);
        continue;
    }
    $status = count($matches) > 1 ? 'camera_multiple_matches' : 'camera_found';
    foreach ($matches as $camera) {
        $rows[] = array_merge($row, [
            $camera['cameraId'], $camera['cameraName'], $camera['category'],
            $camera['prefectureCode'], $camera['areaCode'], $camera['sourceFile'], $status,
            $camera['cameraPlace'], $camera['cameraAliases'], $camera['cameraSource'],
            $camera['cameraProviderPageUrl'], $camera['cameraDesc'],
        ]);
    }
}
fclose($handle);

$outputHandle = null;
$temp = tempnam(dirname($output), 'enrich-');
if ($temp === false || ($outputHandle = fopen($temp, 'wb')) === false) {
    fail('unable to create temporary output');
}
$outHeader = array_merge($expected, [
    'cameraId', 'cameraName', 'category', 'prefectureCode', 'areaCode', 'sourceFile', 'cameraMatchStatus',
    'cameraPlace', 'cameraAliases', 'cameraSource', 'cameraProviderPageUrl', 'cameraDesc',
]);
fputcsv($outputHandle, $outHeader, ',', '"', '');
foreach ($rows as $row) {
    fputcsv($outputHandle, $row, ',', '"', '');
}
if (!fclose($outputHandle) || !rename($temp, $output)) {
    @unlink($temp);
    fail('unable to finalize output');
}
