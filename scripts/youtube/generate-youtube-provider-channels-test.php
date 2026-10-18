<?php
declare(strict_types=1);

// Human-reviewed provider channel fixture for NOT_FOUND review tests.
$output = __DIR__ . '/output/youtube-provider-channels-test.csv';

if (file_exists($output)) {
    fwrite(STDERR, "ERROR: output already exists; refusing to overwrite\n");
    exit(1);
}

$rows = [
    [
        'JP-11-000001',
        'https://yumex-jp.com/',
        'UCcl_5IcX-9-Q2qbJNWLUIQg',
        'YUMEX株式会社',
        '@yumex5324',
        '公式サイトおよび公式YouTubeチャンネルを人間確認',
        'pending',
    ],
    [
        'JP-13-000076',
        'https://www.kensetsu.metro.tokyo.lg.jp/river/suibo',
        'UCaydvLwWthLMbfKLEQSY2UQ',
        '東京都水防チャンネル',
        '@TokyoSuibou',
        '東京都建設局公式ページのYouTubeリンクを人間確認',
        'pending',
    ],
];

$temp = tempnam(dirname($output), 'provider-channels-');
if ($temp === false || ($handle = fopen($temp, 'wb')) === false) {
    fwrite(STDERR, "ERROR: unable to create temporary output\n");
    exit(1);
}

$header = [
    'cameraId',
    'providerPageUrl',
    'providerChannelId',
    'providerChannelTitle',
    'providerChannelHandle',
    'evidence',
    'approvalStatus',
];
fputcsv($handle, $header, ',', '"', '');
foreach ($rows as $row) {
    fputcsv($handle, $row, ',', '"', '');
}

if (!fclose($handle) || !rename($temp, $output)) {
    @unlink($temp);
    fwrite(STDERR, "ERROR: unable to finalize output\n");
    exit(1);
}
