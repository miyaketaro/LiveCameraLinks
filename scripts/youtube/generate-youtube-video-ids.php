<?php
declare(strict_types=1);

// Read camera JSON files and generate a YouTube video ID CSV.
// This script performs no network or API access.

$root = dirname(__DIR__, 2);
$cameraRoot = $root . DIRECTORY_SEPARATOR . 'camera';
$arguments = array_slice($argv, 1);
if (count($arguments) > 1 || ($arguments !== [] && $arguments[0] !== '--test')) {
    fwrite(STDERR, "ERROR: unknown argument\n");
    exit(1);
}
$testMode = in_array('--test', $arguments, true);
$outputDirectory = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'youtube' . DIRECTORY_SEPARATOR . 'output';
$output = $outputDirectory . DIRECTORY_SEPARATOR . ($testMode ? 'youtube-video-ids-test.csv' : 'youtube-video-ids.csv');

if (!is_dir($cameraRoot)) {
    fwrite(STDERR, "ERROR: camera directory not found\n");
    exit(1);
}

if (file_exists($output)) {
    fwrite(STDERR, "ERROR: output already exists; refusing to overwrite: {$output}\n");
    exit(1);
}

$ids = [];
$urlPattern = '~https?://(?:www\.)?(?:youtube\.com/(?:watch\?v=|live/)|youtu\.be/)([A-Za-z0-9_-]{11})(?=[^A-Za-z0-9_-]|$)~i';

$collectStrings = static function ($value) use (&$collectStrings, &$ids, $urlPattern): void {
    if (is_string($value)) {
        if (preg_match_all($urlPattern, $value, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[$id] = true;
            }
        }
        return;
    }

    if (is_array($value)) {
        foreach ($value as $child) {
            $collectStrings($child);
        }
    }
};

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($cameraRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    if (strtolower($file->getExtension()) !== 'json') {
        continue;
    }
    if (strcasecmp($file->getBasename(), 'index.json') === 0) {
        continue;
    }
    $files[] = $file->getPathname();
}
sort($files, SORT_STRING);

foreach ($files as $file) {
    $json = file_get_contents($file);
    if ($json === false) {
        fwrite(STDERR, "ERROR: cannot read JSON: {$file}\n");
        exit(1);
    }
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, "ERROR: invalid JSON: {$file}\n");
        exit(1);
    }
    $collectStrings($data);
}

$sortedIds = array_keys($ids);
sort($sortedIds, SORT_STRING);

if (!is_dir($outputDirectory)) {
    fwrite(STDERR, "ERROR: output directory not found: {$outputDirectory}\n");
    exit(1);
}

$temporary = tempnam($outputDirectory, $testMode ? 'youtube-video-ids-test-' : 'youtube-video-ids-');
if ($temporary === false) {
    fwrite(STDERR, "ERROR: cannot create temporary output\n");
    exit(1);
}

$handle = fopen($temporary, 'wb');
if ($handle === false) {
    @unlink($temporary);
    fwrite(STDERR, "ERROR: cannot open temporary output\n");
    exit(1);
}

if (fputcsv($handle, ['video_id'], ',', '"', '') === false) {
    fclose($handle);
    @unlink($temporary);
    fwrite(STDERR, "ERROR: cannot write CSV header\n");
    exit(1);
}

foreach ($sortedIds as $id) {
    if (fputcsv($handle, [$id], ',', '"', '') === false) {
        fclose($handle);
        @unlink($temporary);
        fwrite(STDERR, "ERROR: cannot write CSV row\n");
        exit(1);
    }
}

if (!fclose($handle)) {
    @unlink($temporary);
    fwrite(STDERR, "ERROR: cannot close temporary output\n");
    exit(1);
}

if (file_exists($output) || !rename($temporary, $output)) {
    @unlink($temporary);
    fwrite(STDERR, "ERROR: output already exists or cannot be finalized: {$output}\n");
    exit(1);
}

fwrite(STDOUT, "Created {$output} with " . count($sortedIds) . " video IDs\n");
