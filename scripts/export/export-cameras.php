<?php

declare(strict_types=1);

/**
 * LiveCameraLinks
 * Production Camera JSON Preview Exporter v2.0
 *
 * Purpose:
 *   cameras テーブルの active レコードから、
 *   現行 LiveCameraLinks JSON 互換形式の
 *   プレビューJSONを生成する。
 *
 * Output:
 *   scripts/export/output/preview/
 *
 * IMPORTANT:
 *   - DBは変更しない
 *   - 本番 camera/ 配下は変更しない
 *   - preview 配下だけを再生成する
 *   - public_camera_id がないレコードは公開準備未完了としてスキップ
 */

// =========================================================
// Paths
// =========================================================

$projectRoot =
    dirname(__DIR__, 2);

$outputRoot =
    $projectRoot
    . DIRECTORY_SEPARATOR
    . 'scripts'
    . DIRECTORY_SEPARATOR
    . 'export'
    . DIRECTORY_SEPARATOR
    . 'output'
    . DIRECTORY_SEPARATOR
    . 'preview';


// =========================================================
// Database configuration
// =========================================================

$dbConfigCandidates = [

    // -----------------------------------------------------
    // Local
    // -----------------------------------------------------

    $projectRoot
        . DIRECTORY_SEPARATOR
        . 'private'
        . DIRECTORY_SEPARATOR
        . 'database.php',

    // -----------------------------------------------------
    // Server
    //
    // /home/.../public_html/livecameralinks.com/
    // /home/.../private/livecameralinks/database.php
    // -----------------------------------------------------

    dirname(
        $projectRoot,
        2
    )
        . DIRECTORY_SEPARATOR
        . 'private'
        . DIRECTORY_SEPARATOR
        . 'livecameralinks'
        . DIRECTORY_SEPARATOR
        . 'database.php',
];

$dbConfigLoaded = false;

foreach (
    $dbConfigCandidates
    as $dbConfig
) {

    if (is_file($dbConfig)) {

        require_once $dbConfig;

        $dbConfigLoaded = true;

        break;
    }
}

if (!$dbConfigLoaded) {

    fwrite(
        STDERR,
        'Database configuration file not found.'
        . PHP_EOL
    );

    exit(1);
}


// =========================================================
// Required DB constants
// =========================================================

$requiredConstants = [
    'DB_HOST',
    'DB_NAME',
    'DB_USER',
    'DB_PASSWORD',
];

foreach (
    $requiredConstants
    as $constantName
) {

    if (!defined($constantName)) {

        fwrite(
            STDERR,
            "Missing database constant: {$constantName}"
            . PHP_EOL
        );

        exit(1);
    }
}


// =========================================================
// Helpers
// =========================================================

function ensureDirectory(
    string $path
): void {

    if (is_dir($path)) {

        return;
    }

    if (
        !mkdir(
            $path,
            0775,
            true
        )
        &&
        !is_dir($path)
    ) {

        throw new RuntimeException(
            "Failed to create directory: {$path}"
        );
    }
}


function jsonWrite(
    string $path,
    mixed $data
): void {

    $json =
        json_encode(
            $data,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

    $json .= PHP_EOL;

    if (
        file_put_contents(
            $path,
            $json
        ) === false
    ) {

        throw new RuntimeException(
            "Failed to write JSON: {$path}"
        );
    }
}


function jsonArray(
    mixed $value
): array {

    if ($value === null) {

        return [];
    }

    if (is_array($value)) {

        return $value;
    }

    $value =
        trim(
            (string)$value
        );

    if ($value === '') {

        return [];
    }

    try {

        $decoded =
            json_decode(
                $value,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

        return is_array($decoded)
            ? array_values($decoded)
            : [];

    } catch (Throwable $e) {

        return [];
    }
}


function normalizeCode(
    ?string $value
): string {

    $value =
        strtolower(
            trim(
                (string)$value
            )
        );

    $value =
        preg_replace(
            '/[^a-z0-9_-]+/',
            '-',
            $value
        );

    return trim(
        (string)$value,
        '-'
    );
}


function removePreviewJsonFiles(
    string $root
): void {

    if (!is_dir($root)) {

        return;
    }

    $iterator =
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

    foreach (
        $iterator
        as $item
    ) {

        if (
            $item->isFile()
            &&
            strtolower(
                $item->getExtension()
            ) === 'json'
        ) {

            unlink(
                $item->getPathname()
            );

            continue;
        }

        if ($item->isDir()) {

            $files =
                scandir(
                    $item->getPathname()
                );

            if (
                is_array($files)
                &&
                count($files) === 2
            ) {

                rmdir(
                    $item->getPathname()
                );
            }
        }
    }
}


// =========================================================
// Database connection
// =========================================================

try {

    $pdo =
        new PDO(
            'mysql:host='
            . DB_HOST
            . ';dbname='
            . DB_NAME
            . ';charset=utf8mb4',

            DB_USER,
            DB_PASSWORD,

            [
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,

                PDO::ATTR_DEFAULT_FETCH_MODE =>
                    PDO::FETCH_ASSOC,

                PDO::ATTR_EMULATE_PREPARES =>
                    false,
            ]
        );

} catch (PDOException $e) {

    fwrite(
        STDERR,
        'Database connection failed.'
        . PHP_EOL
    );

    exit(1);
}


// =========================================================
// Read active production cameras
// =========================================================

$sql = "
    SELECT
        id,
        camera_id,
        public_camera_id,
        slug,

        name,

        category,
        subcategory,

        prefecture,
        prefecture_slug,

        city,
        city_slug,

        area,

        region,
        kind,

        location_name,
        view_target,

        latitude,
        longitude,
        coordinate_type,

        provider_name,
        provider_type,

        source_name,
        publisher,

        provider_url,
        youtube_channel_url,
        video_url,

        link_policy,
        preferred_view,

        themes_json,
        view_targets_json,

        media_type,
        update_interval,

        source_tier,
        last_checked,
        audit_status,

        prefecture_code,
        area_code,

        status,

        approved_at,
        updated_at,
        created_at

    FROM cameras

    WHERE status = 'active'

    ORDER BY
        category,
        prefecture_code,
        area_code,
        camera_id
";

$rows =
    $pdo
        ->query($sql)
        ->fetchAll();


// =========================================================
// Prepare preview output
// =========================================================

ensureDirectory(
    $outputRoot
);

// v1.x等で生成された古いpreview JSONを削除。
// 本番 camera/ は一切触らない。
removePreviewJsonFiles(
    $outputRoot
);


// =========================================================
// Build groups
// =========================================================

$groups = [];

$skipped = [];

$exportedCameraCount = 0;

foreach (
    $rows
    as $row
) {

    // -----------------------------------------------------
    // Required compatibility fields
    // -----------------------------------------------------

    $publicCameraId =
        trim(
            (string)(
                $row['public_camera_id']
                ??
                ''
            )
        );

    $category =
        normalizeCode(
            $row['category']
            ??
            null
        );

    $prefectureCode =
        normalizeCode(
            $row['prefecture_code']
            ??
            $row['prefecture_slug']
            ??
            null
        );

    $areaCode =
        normalizeCode(
            $row['area_code']
            ??
            $row['area']
            ??
            null
        );


    // -----------------------------------------------------
    // Not ready for production-compatible JSON
    // -----------------------------------------------------

    $missing = [];

    if ($publicCameraId === '') {

        $missing[] =
            'public_camera_id';
    }

    if ($category === '') {

        $missing[] =
            'category';
    }

    if ($prefectureCode === '') {

        $missing[] =
            'prefecture_code';
    }

    if ($areaCode === '') {

        $missing[] =
            'area_code';
    }

    if ($missing) {

        $skipped[] = [

            'camera_id' =>
                $row['camera_id'],

            'name' =>
                $row['name'],

            'missing' =>
                $missing,
        ];

        continue;
    }


    // -----------------------------------------------------
    // Compatibility values
    // -----------------------------------------------------

    $sourceName =
        trim(
            (string)(
                $row['source_name']
                ??
                ''
            )
        );

    if ($sourceName === '') {

        $sourceName =
            trim(
                (string)(
                    $row['provider_name']
                    ??
                    ''
                )
            );
    }


    $publisher =
        trim(
            (string)(
                $row['publisher']
                ??
                ''
            )
        );

    if ($publisher === '') {

        $publisher =
            $sourceName;
    }


    $kind =
        trim(
            (string)(
                $row['kind']
                ??
                ''
            )
        );

    if ($kind === '') {

        $kind =
            trim(
                (string)(
                    $row['subcategory']
                    ??
                    ''
                )
            );
    }


    $coordinateType =
        trim(
            (string)(
                $row['coordinate_type']
                ??
                ''
            )
        );

    if ($coordinateType === '') {

        $coordinateType =
            (
                $row['latitude'] !== null
                &&
                $row['longitude'] !== null
            )
                ? 'exact'
                : 'unknown';
    }


    $linkPolicy =
        trim(
            (string)(
                $row['link_policy']
                ??
                ''
            )
        );

    if ($linkPolicy === '') {

        $linkPolicy =
            'provider';
    }


    $preferredView =
        trim(
            (string)(
                $row['preferred_view']
                ??
                ''
            )
        );

    if ($preferredView === '') {

        $preferredView =
            (
                !empty(
                    $row['video_url']
                )
            )
                ? 'direct'
                : 'provider';
    }


    $themes =
        jsonArray(
            $row['themes_json']
            ??
            null
        );


    $viewTargets =
        jsonArray(
            $row['view_targets_json']
            ??
            null
        );

    if (
        !$viewTargets
        &&
        !empty(
            $row['view_target']
        )
    ) {

        $viewTargets = [
            trim(
                (string)$row['view_target']
            ),
        ];
    }


    // -----------------------------------------------------
    // Existing LiveCameraLinks-compatible camera object
    // -----------------------------------------------------

    $camera = [

        'id' =>
            (string)$row['camera_id'],

        'name' =>
            (string)$row['name'],

        'place' =>
            (string)(
                $row['location_name']
                ??
                ''
            ),

        'region' =>
            (string)(
                $row['region']
                ??
                ''
            ),

        'kind' =>
            $kind,

        'category' =>
            $category,

        'lat' =>
            $row['latitude'] !== null
                ? (float)$row['latitude']
                : null,

        'lng' =>
            $row['longitude'] !== null
                ? (float)$row['longitude']
                : null,

        'coordinateType' =>
            $coordinateType,

        'source' =>
            $sourceName,

        'publisher' =>
            $publisher,

        'providerPageUrl' =>
            (string)(
                $row['provider_url']
                ??
                ''
            ),

        'directStreamUrl' =>
            (string)(
                $row['video_url']
                ??
                ''
            ),

        'linkPolicy' =>
            $linkPolicy,

        'preferredView' =>
            $preferredView,

        'mediaType' =>
            (string)(
                $row['media_type']
                ??
                ''
            ),

        'themes' =>
            $themes,

        'viewTargets' =>
            $viewTargets,

        'status' =>
            'active',

        'sourceTier' =>
            (string)(
                $row['source_tier']
                ??
                ''
            ),

        'lastChecked' =>
            (string)(
                $row['last_checked']
                ??
                ''
            ),

        'auditStatus' =>
            (string)(
                $row['audit_status']
                ??
                ''
            ),

        'prefectureCode' =>
            $prefectureCode,

        'areaCode' =>
            $areaCode,

        'slug' =>
            (string)(
                $row['slug']
                ??
                $row['camera_id']
            ),

        'cameraId' =>
            $publicCameraId,
    ];


    // -----------------------------------------------------
    // Group
    // -----------------------------------------------------

    $groupKey =
        $category
        . '/'
        . $prefectureCode
        . '/'
        . $areaCode;

    if (
        !isset(
            $groups[$groupKey]
        )
    ) {

        $groups[$groupKey] = [

            'category' =>
                $category,

            'prefecture' =>
                $prefectureCode,

            'area' =>
                $areaCode,

            'cameras' =>
                [],
        ];
    }

    $groups[$groupKey]['cameras'][] =
        $camera;

    $exportedCameraCount++;
}


// =========================================================
// Write compatible JSON files
// =========================================================

$generatedFiles = [];

foreach (
    $groups
    as $group
) {

    $directory =
        $outputRoot
        . DIRECTORY_SEPARATOR
        . $group['category']
        . DIRECTORY_SEPARATOR
        . $group['prefecture'];

    ensureDirectory(
        $directory
    );


    $filePath =
        $directory
        . DIRECTORY_SEPARATOR
        . $group['area']
        . '.json';


    $payload = [

        'schemaVersion' =>
            '1.0',

        'category' =>
            $group['category'],

        'prefecture' =>
            $group['prefecture'],

        'area' =>
            $group['area'],

        'cameraCount' =>
            count(
                $group['cameras']
            ),

        'cameras' =>
            $group['cameras'],
    ];


    jsonWrite(
        $filePath,
        $payload
    );


    $generatedFiles[] = [

        'path' =>
            $filePath,

        'count' =>
            count(
                $group['cameras']
            ),
    ];
}


// =========================================================
// Preview master index
// =========================================================

$masterFiles = [];

foreach (
    $generatedFiles
    as $generatedFile
) {

    $relativePath =
        substr(
            $generatedFile['path'],
            strlen(
                $outputRoot
            ) + 1
        );

    $relativePath =
        str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            $relativePath
        );

    $masterFiles[] = [

        'file' =>
            $relativePath,

        'cameraCount' =>
            $generatedFile['count'],
    ];
}


jsonWrite(
    $outputRoot
    . DIRECTORY_SEPARATOR
    . 'camera_index.json',

    [
        'schemaVersion' =>
            '2.0-preview',

        'generatedAt' =>
            date(DATE_ATOM),

        'activeProductionCameras' =>
            count($rows),

        'exportedCameras' =>
            $exportedCameraCount,

        'skippedCameras' =>
            count($skipped),

        'files' =>
            $masterFiles,

        'skipped' =>
            $skipped,
    ]
);


// =========================================================
// Console output
// =========================================================

echo PHP_EOL;

echo
    '============================================='
    . PHP_EOL;

echo
    ' LiveCameraLinks JSON PREVIEW EXPORT v2.0'
    . PHP_EOL;

echo
    '============================================='
    . PHP_EOL;

echo PHP_EOL;


echo
    str_pad(
        'Active production cameras:',
        32
    )
    . count($rows)
    . PHP_EOL;


echo
    str_pad(
        'Exported cameras:',
        32
    )
    . $exportedCameraCount
    . PHP_EOL;


echo
    str_pad(
        'Skipped cameras:',
        32
    )
    . count($skipped)
    . PHP_EOL;


echo
    str_pad(
        'JSON groups:',
        32
    )
    . count($groups)
    . PHP_EOL;


echo
    str_pad(
        'Compatible JSON files:',
        32
    )
    . count($generatedFiles)
    . PHP_EOL;


echo PHP_EOL;

echo
    'Output:'
    . PHP_EOL;

echo
    '  '
    . $outputRoot
    . PHP_EOL;

echo PHP_EOL;


foreach (
    $generatedFiles
    as $file
) {

    $relativePath =
        substr(
            $file['path'],
            strlen(
                $outputRoot
            ) + 1
        );

    echo
        '  ['
        . $file['count']
        . '] '
        . str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            $relativePath
        )
        . PHP_EOL;
}


if ($skipped) {

    echo PHP_EOL;

    echo
        'Skipped cameras:'
        . PHP_EOL;

    foreach (
        $skipped
        as $item
    ) {

        echo
            '  - '
            . $item['camera_id']
            . ' / '
            . $item['name']
            . ' / missing: '
            . implode(
                ', ',
                $item['missing']
            )
            . PHP_EOL;
    }
}


echo PHP_EOL;

echo
    'Master index:'
    . PHP_EOL;

echo
    '  camera_index.json'
    . PHP_EOL;


echo PHP_EOL;

echo
    'Database writes: 0'
    . PHP_EOL;

echo
    'Production JSON writes: 0'
    . PHP_EOL;

echo PHP_EOL;

echo
    'JSON preview export completed.'
    . PHP_EOL;