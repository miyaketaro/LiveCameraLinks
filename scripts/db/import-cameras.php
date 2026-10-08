<?php

declare(strict_types=1);

/**
 * LiveCameraLinks
 * Full SQL Generator v5
 *
 * Existing JSON -> camera_candidates
 *
 * - Reads all existing camera JSON
 * - Removes UTF-8 BOM in memory
 * - Normalizes reference-site IDs
 * - Preserves original IDs
 * - Marks missing provider URL as needs_fix
 * - Generates INSERT IGNORE SQL for ALL cameras
 *
 * IMPORTANT:
 * - NO MySQL connection
 * - NO database writes
 * - NO JSON modification
 *
 * Output:
 * scripts/db/output/full-import-474.sql
 */

$projectRoot = realpath(__DIR__ . '/../../');

if ($projectRoot === false) {
    fwrite(STDERR, "Project root not found.\n");
    exit(1);
}

$cameraRoot = $projectRoot . DIRECTORY_SEPARATOR . 'camera';
$outputDir  = __DIR__ . DIRECTORY_SEPARATOR . 'output';
$outputFile = $outputDir . DIRECTORY_SEPARATOR . 'full-import-474.sql';

if (!is_dir($cameraRoot)) {
    fwrite(STDERR, "camera directory not found.\n");
    exit(1);
}

if (!is_dir($outputDir)) {
    if (!mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
        fwrite(STDERR, "Cannot create output directory.\n");
        exit(1);
    }
}


// =========================================================
// Reference sites
// =========================================================

$referenceSources = [
    'cametan',
    'livecombs',
    'livecam.asia',
    'livecam_asia',
    'live-atlas',
    'live_atlas',
    'fujiyama',
    'hir-net',
    'hir_net',
    'tomarigi',
];


// =========================================================
// Prefecture mapping
// =========================================================

$prefectureMap = [
    'hokkaido'  => '北海道',

    'aomori'    => '青森県',
    'iwate'     => '岩手県',
    'miyagi'    => '宮城県',
    'akita'     => '秋田県',
    'yamagata'  => '山形県',
    'fukushima' => '福島県',

    'ibaraki'   => '茨城県',
    'tochigi'   => '栃木県',
    'gunma'     => '群馬県',
    'saitama'   => '埼玉県',
    'chiba'     => '千葉県',
    'tokyo'     => '東京都',
    'kanagawa'  => '神奈川県',

    'niigata'   => '新潟県',
    'toyama'    => '富山県',
    'ishikawa'  => '石川県',
    'fukui'     => '福井県',
    'yamanashi' => '山梨県',
    'nagano'    => '長野県',
    'gifu'      => '岐阜県',
    'shizuoka'  => '静岡県',
    'aichi'     => '愛知県',

    'mie'       => '三重県',
    'shiga'     => '滋賀県',
    'kyoto'     => '京都府',
    'osaka'     => '大阪府',
    'hyogo'     => '兵庫県',
    'nara'      => '奈良県',
    'wakayama'  => '和歌山県',

    'tottori'   => '鳥取県',
    'shimane'   => '島根県',
    'okayama'   => '岡山県',
    'hiroshima' => '広島県',
    'yamaguchi' => '山口県',

    'tokushima' => '徳島県',
    'kagawa'    => '香川県',
    'ehime'     => '愛媛県',
    'kochi'     => '高知県',

    'fukuoka'   => '福岡県',
    'saga'      => '佐賀県',
    'nagasaki'  => '長崎県',
    'kumamoto'  => '熊本県',
    'oita'      => '大分県',
    'miyazaki'  => '宮崎県',
    'kagoshima' => '鹿児島県',
    'okinawa'   => '沖縄県',
];


// =========================================================
// Statistics
// =========================================================

$stats = [
    'json_files'            => 0,
    'json_errors'           => 0,
    'bom_removed'           => 0,
    'camera_records'        => 0,
    'reference_ids'         => 0,
    'needs_fix'             => 0,
    'missing_video_url'     => 0,
    'missing_coordinates'   => 0,
    'generated_records'     => 0,
];


// =========================================================
// Helpers
// =========================================================

function removeUtf8Bom(string $raw, bool &$removed): string
{
    $removed = false;

    if (
        strlen($raw) >= 3 &&
        ord($raw[0]) === 0xEF &&
        ord($raw[1]) === 0xBB &&
        ord($raw[2]) === 0xBF
    ) {
        $removed = true;
        return substr($raw, 3);
    }

    return $raw;
}


function firstValue(array $row, array $keys): mixed
{
    foreach ($keys as $key) {
        if (
            array_key_exists($key, $row) &&
            $row[$key] !== null &&
            $row[$key] !== ''
        ) {
            return $row[$key];
        }
    }

    return null;
}


function normalizePath(string $path): string
{
    return str_replace('\\', '/', $path);
}


function containsReferenceSource(
    ?string $value,
    array $referenceSources
): ?string {

    if ($value === null || $value === '') {
        return null;
    }

    $haystack = strtolower($value);

    foreach ($referenceSources as $source) {
        if (str_contains($haystack, strtolower($source))) {
            return $source;
        }
    }

    return null;
}


function normalizeReferenceId(
    string $id,
    array $referenceSources
): string {

    foreach ($referenceSources as $source) {

        $variants = [
            $source . '_',
            str_replace('.', '_', $source) . '_',
            str_replace('-', '_', $source) . '_',
        ];

        foreach ($variants as $prefix) {

            if (
                str_starts_with(
                    strtolower($id),
                    strtolower($prefix)
                )
            ) {
                return substr($id, strlen($prefix));
            }
        }
    }

    return $id;
}


function sqlValue(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    $value = (string)$value;

    $value = str_replace(
        "'",
        "''",
        $value
    );

    $value = str_replace(
        [
            "\0",
            "\x1a",
        ],
        '',
        $value
    );

    return "'" . $value . "'";
}


function getProviderUrl(array $row): ?string
{
    return firstValue(
        $row,
        [
            'providerPageUrl',
            'providerOfficialUrl',
            'officialRoadUrl',
            'url',
        ]
    );
}


function getVideoUrl(array $row): ?string
{
    return firstValue(
        $row,
        [
            'directStreamUrl',
            'videoUrl',
            'youtubeUrl',
            'streamUrl',
            'url',
        ]
    );
}


function getProviderName(array $row): ?string
{
    return firstValue(
        $row,
        [
            'publisher',
            'source',
        ]
    );
}


function getSlug(
    array $row,
    string $normalizedId
): string {

    $slug = firstValue(
        $row,
        [
            'slug',
        ]
    );

    if ($slug !== null) {
        return (string)$slug;
    }

    return str_replace(
        '_',
        '-',
        $normalizedId
    );
}


// =========================================================
// Read all JSON
// =========================================================

$records = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $cameraRoot,
        FilesystemIterator::SKIP_DOTS
    )
);

foreach ($iterator as $fileInfo) {

    if (!$fileInfo->isFile()) {
        continue;
    }

    if (strtolower($fileInfo->getExtension()) !== 'json') {
        continue;
    }

    $stats['json_files']++;

    $fullPath = $fileInfo->getPathname();

    $relativePath = normalizePath(
        str_replace(
            $projectRoot . DIRECTORY_SEPARATOR,
            '',
            $fullPath
        )
    );

    $raw = file_get_contents($fullPath);

    if ($raw === false) {
        $stats['json_errors']++;
        continue;
    }

    $bomRemoved = false;

    $raw = removeUtf8Bom(
        $raw,
        $bomRemoved
    );

    if ($bomRemoved) {
        $stats['bom_removed']++;
    }

    $json = json_decode(
        $raw,
        true
    );

    if (json_last_error() !== JSON_ERROR_NONE) {

        $stats['json_errors']++;

        echo "[JSON ERROR] {$relativePath}\n";
        echo "  " . json_last_error_msg() . "\n";

        continue;
    }

    if (
        !isset($json['cameras']) ||
        !is_array($json['cameras'])
    ) {
        continue;
    }

    foreach ($json['cameras'] as $camera) {

        if (!is_array($camera)) {
            continue;
        }

        $records[] = [
            'file' => $relativePath,

            'file_category' =>
                $json['category'] ?? null,

            'file_prefecture' =>
                $json['prefecture'] ?? null,

            'file_area' =>
                $json['area'] ?? null,

            'data' =>
                $camera,
        ];
    }
}

$stats['camera_records'] = count($records);


// =========================================================
// Convert records
// =========================================================

$candidates = [];

foreach ($records as $recordInfo) {

    $row = $recordInfo['data'];

    $originalId = firstValue(
        $row,
        [
            'id',
            'cameraId',
            'camera_id',
        ]
    );

    if ($originalId === null) {
        continue;
    }

    $originalId = (string)$originalId;


    // -----------------------------------------------------
    // Reference-source normalization
    // -----------------------------------------------------

    $referenceSource =
        containsReferenceSource(
            $originalId,
            $referenceSources
        );

    if ($referenceSource !== null) {

        $stats['reference_ids']++;

        $normalizedId =
            normalizeReferenceId(
                $originalId,
                $referenceSources
            );

    } else {

        $normalizedId =
            $originalId;
    }


    // -----------------------------------------------------
    // Slug
    // -----------------------------------------------------

    $originalSlug = firstValue(
        $row,
        [
            'slug',
        ]
    );

    $slug = getSlug(
        $row,
        $normalizedId
    );


    // -----------------------------------------------------
    // Prefecture
    // -----------------------------------------------------

    $prefectureSlug = firstValue(
        $row,
        [
            'prefectureCode',
        ]
    );

    if ($prefectureSlug === null) {
        $prefectureSlug =
            $recordInfo['file_prefecture'];
    }

    $prefectureSlug =
        $prefectureSlug !== null
            ? (string)$prefectureSlug
            : null;

    $prefectureName =
        $prefectureSlug !== null
            ? (
                $prefectureMap[
                    strtolower($prefectureSlug)
                ]
                ?? $prefectureSlug
            )
            : null;


    // -----------------------------------------------------
    // Area
    // -----------------------------------------------------

    $areaSlug = firstValue(
        $row,
        [
            'areaCode',
        ]
    );

    if ($areaSlug === null) {
        $areaSlug =
            $recordInfo['file_area'];
    }


    // -----------------------------------------------------
    // Category
    // -----------------------------------------------------

    $category = firstValue(
        $row,
        [
            'category',
        ]
    );

    if ($category === null) {
        $category =
            $recordInfo['file_category'];
    }


    // -----------------------------------------------------
    // URLs
    // -----------------------------------------------------

    $providerUrl = getProviderUrl(
        $row
    );

    $videoUrl = getVideoUrl(
        $row
    );


    // -----------------------------------------------------
    // Status
    // -----------------------------------------------------

    $status =
        'new';

    $reviewStatus =
        'unchecked';

    $providerUrlStatus =
        'unchecked';

    $videoUrlStatus =
        'unchecked';

    if ($providerUrl === null) {

        $status =
            'needs_fix';

        $providerUrlStatus =
            'missing';

        $stats['needs_fix']++;
    }

    if ($videoUrl === null) {

        $videoUrlStatus =
            'missing';

        $stats['missing_video_url']++;
    }


    // -----------------------------------------------------
    // Coordinates
    // -----------------------------------------------------

    $latitude = firstValue(
        $row,
        [
            'lat',
            'latitude',
        ]
    );

    $longitude = firstValue(
        $row,
        [
            'lng',
            'lon',
            'longitude',
        ]
    );

    if (
        $latitude === null ||
        $longitude === null
    ) {
        $stats['missing_coordinates']++;
    }


    // -----------------------------------------------------
    // Source
    // -----------------------------------------------------

    if ($referenceSource !== null) {

        $sourceType =
            'reference_site';

        $sourceName =
            $referenceSource;

    } else {

        $sourceType =
            'import_json';

        $sourceName =
            firstValue(
                $row,
                [
                    'source',
                    'publisher',
                ]
            );
    }


    // -----------------------------------------------------
    // Notes
    // -----------------------------------------------------

    $notesParts = [
        'Imported from existing LiveCameraLinks JSON',
        'source file: ' . $recordInfo['file'],
    ];

    if ($referenceSource !== null) {

        $notesParts[] =
            'reference source ID normalized from '
            . $originalId;
    }

    if ($providerUrl === null) {
        $notesParts[] =
            'provider URL missing';
    }

    $notes = implode(
        ' / ',
        $notesParts
    );


    // -----------------------------------------------------
    // DB candidate
    // -----------------------------------------------------

    $candidates[] = [

        'candidate_id' =>
            'import_' . $normalizedId,

        'original_id' =>
            $originalId,

        'camera_id' =>
            $normalizedId,

        'original_slug' =>
            $originalSlug,

        'slug' =>
            $slug,

        'name' =>
            firstValue(
                $row,
                [
                    'name',
                    'title',
                ]
            ),

        'category' =>
            $category,

        'subcategory' =>
            firstValue(
                $row,
                [
                    'kind',
                ]
            ),

        'prefecture' =>
            $prefectureName,

        'prefecture_slug' =>
            $prefectureSlug,

        'city' =>
            null,

        'city_slug' =>
            $areaSlug,

        'area' =>
            $areaSlug,

        'location_name' =>
            firstValue(
                $row,
                [
                    'place',
                ]
            ),

        'view_target' =>
            null,

        'latitude' =>
            $latitude,

        'longitude' =>
            $longitude,

        'provider_name' =>
            getProviderName(
                $row
            ),

        'provider_type' =>
            firstValue(
                $row,
                [
                    'operatorType',
                ]
            ),

        'provider_url' =>
            $providerUrl,

        'youtube_channel_url' =>
            firstValue(
                $row,
                [
                    'channelUrl',
                ]
            ),

        'video_url' =>
            $videoUrl,

        'media_type' =>
            firstValue(
                $row,
                [
                    'mediaType',
                ]
            ),

        'update_interval' =>
            null,

        'source_type' =>
            $sourceType,

        'source_name' =>
            $sourceName,

        'source_url' =>
            null,

        'target_file' =>
            $recordInfo['file'],

        'normalized' =>
            $referenceSource !== null
                ? 1
                : 0,

        'normalization_notes' =>
            $referenceSource !== null
                ? (
                    $originalId
                    . ' -> '
                    . $normalizedId
                )
                : null,

        'provider_url_status' =>
            $providerUrlStatus,

        'video_url_status' =>
            $videoUrlStatus,

        'stream_status' =>
            firstValue(
                $row,
                [
                    'liveStatus',
                ]
            )
            ?? 'unknown',

        'duplicate_status' =>
            'unchecked',

        'duplicate_camera_id' =>
            null,

        'status' =>
            $status,

        'review_status' =>
            $reviewStatus,

        'notes' =>
            $notes,
    ];
}


// =========================================================
// SQL columns
// =========================================================

$columns = [
    'candidate_id',
    'original_id',
    'camera_id',
    'original_slug',
    'slug',
    'name',
    'category',
    'subcategory',
    'prefecture',
    'prefecture_slug',
    'city',
    'city_slug',
    'area',
    'location_name',
    'view_target',
    'latitude',
    'longitude',
    'provider_name',
    'provider_type',
    'provider_url',
    'youtube_channel_url',
    'video_url',
    'media_type',
    'update_interval',
    'source_type',
    'source_name',
    'source_url',
    'target_file',
    'normalized',
    'normalization_notes',
    'provider_url_status',
    'video_url_status',
    'stream_status',
    'duplicate_status',
    'duplicate_camera_id',
    'status',
    'review_status',
    'notes',
];


// =========================================================
// Generate FULL SQL
// =========================================================

$sql = '';

$sql .=
    "-- =====================================================\n";

$sql .=
    "-- LiveCameraLinks camera_candidates\n";

$sql .=
    "-- FULL IMPORT v5\n";

$sql .=
    "-- Camera records: "
    . count($candidates)
    . "\n";

$sql .=
    "-- Existing candidate_id values are skipped by INSERT IGNORE\n";

$sql .=
    "-- =====================================================\n\n";

$sql .=
    "SET NAMES utf8mb4;\n\n";

$sql .=
    "START TRANSACTION;\n\n";


foreach ($candidates as $candidate) {

    $sql .=
        "-- "
        . ($candidate['camera_id'] ?? '')
        . " | "
        . ($candidate['name'] ?? '')
        . "\n";

    $sql .=
        "INSERT IGNORE INTO camera_candidates (\n    ";

    $sql .=
        implode(
            ",\n    ",
            $columns
        );

    $sql .=
        "\n) VALUES (\n    ";

    $values = [];

    foreach ($columns as $column) {

        $values[] =
            sqlValue(
                $candidate[$column]
                ?? null
            );
    }

    $sql .=
        implode(
            ",\n    ",
            $values
        );

    $sql .=
        "\n);\n\n";
}

$sql .=
    "COMMIT;\n";


$result = file_put_contents(
    $outputFile,
    $sql
);

if ($result === false) {

    fwrite(
        STDERR,
        "Cannot write full SQL file.\n"
    );

    exit(1);
}

$stats['generated_records'] =
    count($candidates);


// =========================================================
// Console
// =========================================================

echo "\n";
echo "=============================================\n";
echo " LiveCameraLinks FULL SQL Generator v5\n";
echo "=============================================\n\n";

printf(
    "%-34s %d\n",
    'JSON files:',
    $stats['json_files']
);

printf(
    "%-34s %d\n",
    'JSON errors:',
    $stats['json_errors']
);

printf(
    "%-34s %d\n",
    'BOM removed:',
    $stats['bom_removed']
);

echo "\n";

printf(
    "%-34s %d\n",
    'Camera records:',
    $stats['camera_records']
);

printf(
    "%-34s %d\n",
    'Reference-source IDs:',
    $stats['reference_ids']
);

printf(
    "%-34s %d\n",
    'Needs fix:',
    $stats['needs_fix']
);

printf(
    "%-34s %d\n",
    'Missing video URL:',
    $stats['missing_video_url']
);

printf(
    "%-34s %d\n",
    'Missing coordinates:',
    $stats['missing_coordinates']
);

echo "\n";

printf(
    "%-34s %d\n",
    'SQL records generated:',
    $stats['generated_records']
);

echo "\n";

echo "SQL output:\n";
echo "  scripts/db/output/full-import-474.sql\n\n";

echo "Database writes: 0\n";
echo "FULL SQL generation v5 completed.\n";