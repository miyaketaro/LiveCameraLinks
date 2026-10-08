<?php

declare(strict_types=1);

/**
 * LiveCameraLinks
 * Camera Candidate Editor v2.6
 *
 * AUTH
 * + EDIT / SAVE
 * + CONFIRM
 * + APPROVE
 * + cameras UPSERT
 * + APPROVED_AT PRESERVE
 * + APPROVED DOWNGRADE PROTECTION
 * + PRODUCTION COMPATIBILITY COLUMNS PRESERVE
 * + TRANSACTION
 * + CSRF
 * + RETURN URL SESSION
 */

// =========================================================
// Private configuration
// =========================================================

require_once dirname(__DIR__, 3)
    . '/private/livecameralinks/database.php';

require_once dirname(__DIR__, 3)
    . '/private/livecameralinks/admin-auth.php';


// =========================================================
// Session
// =========================================================

session_set_cookie_params([
    'httponly' => true,
    'secure'   => true,
    'samesite' => 'Strict',
]);

session_start();


// =========================================================
// Authentication
// =========================================================

if (
    empty($_SESSION['admin_authenticated'])
) {
    header('Location: ./');
    exit;
}


// =========================================================
// CSRF
// =========================================================

if (
    empty($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    (string)$_SESSION['csrf_token'];


// =========================================================
// Return URL
// =========================================================

$incomingReturnUrl =
    (string)(
        $_GET['return']
        ??
        $_POST['return']
        ??
        ''
    );

if ($incomingReturnUrl !== '') {

    $isSafeReturnUrl =
        !str_contains(
            $incomingReturnUrl,
            '://'
        )
        &&
        !str_starts_with(
            $incomingReturnUrl,
            '//'
        )
        &&
        str_starts_with(
            $incomingReturnUrl,
            '/admin/'
        );

    if ($isSafeReturnUrl) {

        $_SESSION['admin_return_url'] =
            $incomingReturnUrl;
    }
}

$returnUrl =
    (string)(
        $_SESSION['admin_return_url']
        ??
        './'
    );

if (
    $returnUrl !== './'
    &&
    (
        str_contains(
            $returnUrl,
            '://'
        )
        ||
        str_starts_with(
            $returnUrl,
            '//'
        )
        ||
        !str_starts_with(
            $returnUrl,
            '/admin/'
        )
    )
) {

    $returnUrl = './';

    unset(
        $_SESSION['admin_return_url']
    );
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

    http_response_code(500);

    exit(
        'Database connection failed.'
    );
}


// =========================================================
// Helpers
// =========================================================

function nullable(
    string $value
): ?string {

    return $value === ''
        ? null
        : $value;
}


function h(
    mixed $value
): string {

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


// =========================================================
// Candidate ID
// =========================================================

$id =
    filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );

if (
    !$id
    &&
    isset($_POST['id'])
) {

    $id =
        filter_var(
            $_POST['id'],
            FILTER_VALIDATE_INT
        );
}

if (!$id) {

    http_response_code(400);

    exit(
        'Invalid candidate ID.'
    );
}


// =========================================================
// Load candidate
// =========================================================

$loadStmt =
    $pdo->prepare("
        SELECT *
        FROM camera_candidates
        WHERE id = :id
        LIMIT 1
    ");

$loadStmt->execute([
    ':id' => $id,
]);

$camera =
    $loadStmt->fetch();

if (!$camera) {

    http_response_code(404);

    exit(
        'Camera candidate not found.'
    );
}


// =========================================================
// Save / Confirm / Approve
// =========================================================

$message = '';
$error = '';

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    // -----------------------------------------------------
    // CSRF
    // -----------------------------------------------------

    $postedToken =
        (string)(
            $_POST['csrf_token']
            ??
            ''
        );

    if (
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {

        http_response_code(403);

        exit(
            'Invalid CSRF token.'
        );
    }


    // -----------------------------------------------------
    // Action
    // -----------------------------------------------------

    $action =
        (string)(
            $_POST['action']
            ??
            'save'
        );

    $allowedActions = [
        'save',
        'confirm',
        'approve',
    ];

    if (
        !in_array(
            $action,
            $allowedActions,
            true
        )
    ) {

        $error =
            '操作の種類が正しくありません。';
    }


    // -----------------------------------------------------
    // Input
    // -----------------------------------------------------

    $name =
        trim(
            (string)(
                $_POST['name']
                ??
                ''
            )
        );

    $category =
        trim(
            (string)(
                $_POST['category']
                ??
                ''
            )
        );

    $subcategory =
        trim(
            (string)(
                $_POST['subcategory']
                ??
                ''
            )
        );

    $prefecture =
        trim(
            (string)(
                $_POST['prefecture']
                ??
                ''
            )
        );

    $area =
        trim(
            (string)(
                $_POST['area']
                ??
                ''
            )
        );

    $locationName =
        trim(
            (string)(
                $_POST['location_name']
                ??
                ''
            )
        );

    $viewTarget =
        trim(
            (string)(
                $_POST['view_target']
                ??
                ''
            )
        );

    $providerName =
        trim(
            (string)(
                $_POST['provider_name']
                ??
                ''
            )
        );

    $providerType =
        trim(
            (string)(
                $_POST['provider_type']
                ??
                ''
            )
        );

    $providerUrl =
        trim(
            (string)(
                $_POST['provider_url']
                ??
                ''
            )
        );

    $youtubeChannelUrl =
        trim(
            (string)(
                $_POST['youtube_channel_url']
                ??
                ''
            )
        );

    $videoUrl =
        trim(
            (string)(
                $_POST['video_url']
                ??
                ''
            )
        );

    $mediaType =
        trim(
            (string)(
                $_POST['media_type']
                ??
                ''
            )
        );

    $updateInterval =
        trim(
            (string)(
                $_POST['update_interval']
                ??
                ''
            )
        );

    $status =
        trim(
            (string)(
                $_POST['status']
                ??
                ''
            )
        );

    $reviewStatus =
        trim(
            (string)(
                $_POST['review_status']
                ??
                ''
            )
        );

    $normalizationNotes =
        trim(
            (string)(
                $_POST['normalization_notes']
                ??
                ''
            )
        );

    $notes =
        trim(
            (string)(
                $_POST['notes']
                ??
                ''
            )
        );


    // -----------------------------------------------------
    // Existing state
    // -----------------------------------------------------

    $currentStatus =
        (string)(
            $camera['status']
            ??
            ''
        );

    $currentReviewStatus =
        (string)(
            $camera['review_status']
            ??
            ''
        );

    $currentApprovedAt =
        $camera['approved_at']
        ??
        null;


    // -----------------------------------------------------
    // Protect approved records
    // -----------------------------------------------------

    if (
        $error === ''
        &&
        $currentStatus === 'approved'
        &&
        $action !== 'approve'
    ) {

        // 承認済みは通常保存で降格させない
        $status =
            'approved';

        $reviewStatus =
            'confirmed';
    }


    // -----------------------------------------------------
    // Direct approved selection prohibition
    // -----------------------------------------------------

    if (
        $error === ''
        &&
        $action !== 'approve'
        &&
        $currentStatus !== 'approved'
        &&
        $status === 'approved'
    ) {

        $error =
            'approved は状態欄から直接設定できません。［承認する］ボタンを使用してください。';
    }


    // -----------------------------------------------------
    // Confirm state transition
    // -----------------------------------------------------

    if (
        $error === ''
        &&
        $action === 'confirm'
    ) {

        if (
            $currentStatus === 'approved'
        ) {

            $error =
                'このカメラはすでに承認済みです。';

        } else {

            $reviewStatus =
                'confirmed';
        }
    }


    // -----------------------------------------------------
    // Approve state transition
    // -----------------------------------------------------

    if (
        $error === ''
        &&
        $action === 'approve'
    ) {

        if (
            $currentReviewStatus
            !== 'confirmed'
        ) {

            $error =
                '未確認のカメラは承認できません。';

        } else {

            $reviewStatus =
                'confirmed';

            $status =
                'approved';
        }
    }


    // -----------------------------------------------------
    // approved_at
    // -----------------------------------------------------

    $approvedAt =
        $currentApprovedAt;

    if (
        $error === ''
        &&
        $action === 'approve'
        &&
        empty($approvedAt)
    ) {

        $approvedAt =
            date(
                'Y-m-d H:i:s'
            );
    }


    // -----------------------------------------------------
    // Latitude / longitude
    // -----------------------------------------------------

    $latitudeRaw =
        trim(
            (string)(
                $_POST['latitude']
                ??
                ''
            )
        );

    $longitudeRaw =
        trim(
            (string)(
                $_POST['longitude']
                ??
                ''
            )
        );

    $latitude =
        $latitudeRaw === ''
            ? null
            : filter_var(
                $latitudeRaw,
                FILTER_VALIDATE_FLOAT
            );

    $longitude =
        $longitudeRaw === ''
            ? null
            : filter_var(
                $longitudeRaw,
                FILTER_VALIDATE_FLOAT
            );


    // -----------------------------------------------------
    // Validation
    // -----------------------------------------------------

    if (
        $error === ''
        &&
        $name === ''
    ) {

        $error =
            'カメラ名は必須です。';
    }

    if (
        $error === ''
        &&
        $latitudeRaw !== ''
        &&
        $latitude === false
    ) {

        $error =
            '緯度の形式が正しくありません。';
    }

    if (
        $error === ''
        &&
        $longitudeRaw !== ''
        &&
        $longitude === false
    ) {

        $error =
            '経度の形式が正しくありません。';
    }

    if (
        $error === ''
        &&
        $latitude !== null
        &&
        (
            $latitude < -90
            ||
            $latitude > 90
        )
    ) {

        $error =
            '緯度は -90〜90 の範囲で入力してください。';
    }

    if (
        $error === ''
        &&
        $longitude !== null
        &&
        (
            $longitude < -180
            ||
            $longitude > 180
        )
    ) {

        $error =
            '経度は -180〜180 の範囲で入力してください。';
    }


    // -----------------------------------------------------
    // URL validation
    // -----------------------------------------------------

    if (
        $error === ''
        &&
        $providerUrl !== ''
        &&
        filter_var(
            $providerUrl,
            FILTER_VALIDATE_URL
        ) === false
    ) {

        $error =
            '配信元URLの形式が正しくありません。';
    }

    if (
        $error === ''
        &&
        $youtubeChannelUrl !== ''
        &&
        filter_var(
            $youtubeChannelUrl,
            FILTER_VALIDATE_URL
        ) === false
    ) {

        $error =
            'YouTubeチャンネルURLの形式が正しくありません。';
    }

    if (
        $error === ''
        &&
        $videoUrl !== ''
        &&
        filter_var(
            $videoUrl,
            FILTER_VALIDATE_URL
        ) === false
    ) {

        $error =
            '映像URLの形式が正しくありません。';
    }


    // -----------------------------------------------------
    // Allowed status
    // -----------------------------------------------------

    $allowedStatuses = [
        '',
        'new',
        'checking',
        'needs_fix',
        'approved',
        'rejected',
        'duplicate',
        'inactive',
    ];

    if (
        $error === ''
        &&
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {

        $error =
            '状態の値が正しくありません。';
    }


    // -----------------------------------------------------
    // Transaction
    // -----------------------------------------------------

    if ($error === '') {

        try {

            $pdo->beginTransaction();


            // =============================================
            // 1. Update camera_candidates
            // =============================================

            $updateStmt =
                $pdo->prepare("
                    UPDATE camera_candidates
                    SET
                        name = :name,
                        category = :category,
                        subcategory = :subcategory,
                        prefecture = :prefecture,
                        area = :area,
                        location_name = :location_name,
                        view_target = :view_target,
                        latitude = :latitude,
                        longitude = :longitude,
                        provider_name = :provider_name,
                        provider_type = :provider_type,
                        provider_url = :provider_url,
                        youtube_channel_url = :youtube_channel_url,
                        video_url = :video_url,
                        media_type = :media_type,
                        update_interval = :update_interval,
                        status = :status,
                        review_status = :review_status,
                        approved_at = :approved_at,
                        normalization_notes = :normalization_notes,
                        notes = :notes
                    WHERE id = :id
                    LIMIT 1
                ");

            $updateStmt->execute([

                ':name' =>
                    $name,

                ':category' =>
                    nullable($category),

                ':subcategory' =>
                    nullable($subcategory),

                ':prefecture' =>
                    nullable($prefecture),

                ':area' =>
                    nullable($area),

                ':location_name' =>
                    nullable($locationName),

                ':view_target' =>
                    nullable($viewTarget),

                ':latitude' =>
                    $latitude,

                ':longitude' =>
                    $longitude,

                ':provider_name' =>
                    nullable($providerName),

                ':provider_type' =>
                    nullable($providerType),

                ':provider_url' =>
                    nullable($providerUrl),

                ':youtube_channel_url' =>
                    nullable($youtubeChannelUrl),

                ':video_url' =>
                    nullable($videoUrl),

                ':media_type' =>
                    nullable($mediaType),

                ':update_interval' =>
                    nullable($updateInterval),

                ':status' =>
                    nullable($status),

                ':review_status' =>
                    nullable($reviewStatus),

                ':approved_at' =>
                    $approvedAt,

                ':normalization_notes' =>
                    nullable($normalizationNotes),

                ':notes' =>
                    nullable($notes),

                ':id' =>
                    $id,
            ]);


            // =============================================
            // 2. Reload candidate inside transaction
            // =============================================

            $syncLoadStmt =
                $pdo->prepare("
                    SELECT *
                    FROM camera_candidates
                    WHERE id = :id
                    LIMIT 1
                ");

            $syncLoadStmt->execute([
                ':id' => $id,
            ]);

            $updatedCamera =
                $syncLoadStmt->fetch();

            if (!$updatedCamera) {

                throw new RuntimeException(
                    'Updated candidate could not be reloaded.'
                );
            }


            // =============================================
            // 3. Decide production sync
            // =============================================

            $shouldSyncProduction =
                (
                    $action === 'approve'
                )
                ||
                (
                    $action === 'save'
                    &&
                    (string)$updatedCamera['status']
                    === 'approved'
                );


            // =============================================
            // 4. UPSERT cameras
            //
            // IMPORTANT:
            //
            // public_camera_id
            // region
            // kind
            // coordinate_type
            // source_name
            // publisher
            // link_policy
            // preferred_view
            // themes_json
            // view_targets_json
            // source_tier
            // last_checked
            // audit_status
            // prefecture_code
            // area_code
            //
            // 上記の現行JSON互換列には触れない。
            // 既存値を保持する。
            // =============================================

            if ($shouldSyncProduction) {

                if (
                    (string)$updatedCamera['review_status']
                    !== 'confirmed'
                ) {

                    throw new RuntimeException(
                        'Only confirmed cameras can be synced to production.'
                    );
                }


                $upsertStmt =
                    $pdo->prepare("
                        INSERT INTO cameras (
                            camera_id,
                            slug,
                            name,
                            category,
                            subcategory,
                            prefecture,
                            prefecture_slug,
                            city,
                            city_slug,
                            area,
                            location_name,
                            view_target,
                            latitude,
                            longitude,
                            provider_name,
                            provider_type,
                            provider_url,
                            youtube_channel_url,
                            video_url,
                            media_type,
                            update_interval,
                            status,
                            source_candidate_id,
                            source_candidate_db_id,
                            approved_at
                        )
                        VALUES (
                            :camera_id,
                            :slug,
                            :name,
                            :category,
                            :subcategory,
                            :prefecture,
                            :prefecture_slug,
                            :city,
                            :city_slug,
                            :area,
                            :location_name,
                            :view_target,
                            :latitude,
                            :longitude,
                            :provider_name,
                            :provider_type,
                            :provider_url,
                            :youtube_channel_url,
                            :video_url,
                            :media_type,
                            :update_interval,
                            'active',
                            :source_candidate_id,
                            :source_candidate_db_id,
                            :approved_at
                        )
                        ON DUPLICATE KEY UPDATE

                            slug =
                                VALUES(slug),

                            name =
                                VALUES(name),

                            category =
                                VALUES(category),

                            subcategory =
                                VALUES(subcategory),

                            prefecture =
                                VALUES(prefecture),

                            prefecture_slug =
                                VALUES(prefecture_slug),

                            city =
                                VALUES(city),

                            city_slug =
                                VALUES(city_slug),

                            area =
                                VALUES(area),

                            location_name =
                                VALUES(location_name),

                            view_target =
                                VALUES(view_target),

                            latitude =
                                VALUES(latitude),

                            longitude =
                                VALUES(longitude),

                            provider_name =
                                VALUES(provider_name),

                            provider_type =
                                VALUES(provider_type),

                            provider_url =
                                VALUES(provider_url),

                            youtube_channel_url =
                                VALUES(youtube_channel_url),

                            video_url =
                                VALUES(video_url),

                            media_type =
                                VALUES(media_type),

                            update_interval =
                                VALUES(update_interval),

                            status =
                                'active',

                            source_candidate_id =
                                VALUES(source_candidate_id),

                            source_candidate_db_id =
                                VALUES(source_candidate_db_id),

                            approved_at =
                                COALESCE(
                                    approved_at,
                                    VALUES(approved_at)
                                )
                    ");


                $upsertStmt->execute([

                    ':camera_id' =>
                        $updatedCamera['camera_id'],

                    ':slug' =>
                        $updatedCamera['slug'],

                    ':name' =>
                        $updatedCamera['name'],

                    ':category' =>
                        $updatedCamera['category'],

                    ':subcategory' =>
                        $updatedCamera['subcategory'],

                    ':prefecture' =>
                        $updatedCamera['prefecture'],

                    ':prefecture_slug' =>
                        $updatedCamera['prefecture_slug'],

                    ':city' =>
                        $updatedCamera['city'],

                    ':city_slug' =>
                        $updatedCamera['city_slug'],

                    ':area' =>
                        $updatedCamera['area'],

                    ':location_name' =>
                        $updatedCamera['location_name'],

                    ':view_target' =>
                        $updatedCamera['view_target'],

                    ':latitude' =>
                        $updatedCamera['latitude'],

                    ':longitude' =>
                        $updatedCamera['longitude'],

                    ':provider_name' =>
                        $updatedCamera['provider_name'],

                    ':provider_type' =>
                        $updatedCamera['provider_type'],

                    ':provider_url' =>
                        $updatedCamera['provider_url'],

                    ':youtube_channel_url' =>
                        $updatedCamera['youtube_channel_url'],

                    ':video_url' =>
                        $updatedCamera['video_url'],

                    ':media_type' =>
                        $updatedCamera['media_type'],

                    ':update_interval' =>
                        $updatedCamera['update_interval'],

                    ':source_candidate_id' =>
                        $updatedCamera['candidate_id'],

                    ':source_candidate_db_id' =>
                        $updatedCamera['id'],

                    ':approved_at' =>
                        $updatedCamera['approved_at'],
                ]);
            }


            // =============================================
            // 5. Commit
            // =============================================

            $pdo->commit();


            // =============================================
            // 6. Message
            // =============================================

            if (
                $action === 'confirm'
            ) {

                $message =
                    '確認済みにしました。';

            } elseif (
                $action === 'approve'
            ) {

                $message =
                    '承認済みにし、本番 cameras に同期しました。';

            } elseif (
                (string)$updatedCamera['status']
                === 'approved'
            ) {

                $message =
                    '保存し、本番 cameras に同期しました。';

            } else {

                $message =
                    '保存しました。';
            }


            // =============================================
            // 7. Reload after commit
            // =============================================

            $loadStmt->execute([
                ':id' => $id,
            ]);

            $camera =
                $loadStmt->fetch();


        } catch (Throwable $e) {

            if (
                $pdo->inTransaction()
            ) {

                $pdo->rollBack();
            }

            $error =
                '保存処理に失敗しました。データベースは変更前の状態に戻されました。';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ja">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<meta
    name="robots"
    content="noindex,nofollow"
>

<title>
カメラ編集 | LiveCameraLinks
</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;

    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Yu Gothic",
        "Meiryo",
        sans-serif;

    background: #f5f6f8;

    color: #222;
}

header {
    background: #18212b;

    color: #fff;

    padding: 18px 24px;
}

.header-inner {
    max-width: 1100px;

    margin: 0 auto;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;
}

header h1 {
    margin: 0;

    font-size: 22px;
}

.header-sub {
    margin-top: 4px;

    font-size: 13px;

    opacity: .75;
}

.back-link {
    color: white;

    text-decoration: none;

    border:
        1px solid
        rgba(255,255,255,.35);

    border-radius: 8px;

    padding: 8px 12px;

    font-size: 13px;
}

main {
    max-width: 1100px;

    margin: 0 auto;

    padding: 22px;
}

.info,
form {
    background: #fff;

    border: 1px solid #ddd;

    border-radius: 10px;

    padding: 20px;
}

.info {
    margin-bottom: 16px;
}

.info-grid {
    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 12px;
}

.info-label {
    color: #777;

    font-size: 11px;

    margin-bottom: 3px;
}

.info-value {
    font-size: 13px;

    overflow-wrap: anywhere;
}

.grid {
    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 16px;
}

.field-full {
    grid-column: 1 / -1;
}

label {
    display: block;

    margin-bottom: 6px;

    font-size: 13px;

    font-weight: 700;
}

input,
select,
textarea {
    width: 100%;

    padding: 10px 11px;

    border: 1px solid #cfd4d8;

    border-radius: 7px;

    background: #fff;

    color: #222;

    font: inherit;
}

input[readonly] {
    background: #f1f3f5;

    color: #666;
}

textarea {
    min-height: 100px;

    resize: vertical;
}

.actions {
    display: flex;

    gap: 10px;

    flex-wrap: wrap;

    margin-top: 20px;
}

button {
    border: 0;

    border-radius: 8px;

    padding: 11px 22px;

    background: #18212b;

    color: #fff;

    font-weight: 700;

    cursor: pointer;
}

.confirm-button {
    background: #246b3a;
}

.approve-button {
    background: #1d5f9b;
}

.button-secondary {
    display: inline-flex;

    align-items: center;

    padding: 11px 18px;

    border: 1px solid #bbb;

    border-radius: 8px;

    background: #fff;

    color: #222;

    text-decoration: none;
}

.message {
    margin-bottom: 16px;

    padding: 12px 14px;

    border-radius: 8px;

    background: #e8f7ec;

    color: #145c2b;

    border: 1px solid #b7dfc1;
}

.error {
    margin-bottom: 16px;

    padding: 12px 14px;

    border-radius: 8px;

    background: #ffe8e8;

    color: #8b1d1d;

    border: 1px solid #efbcbc;
}

.warning {
    padding: 10px 12px;

    background: #fff7df;

    border: 1px solid #efd995;

    border-radius: 7px;

    font-size: 12px;

    line-height: 1.6;

    margin-bottom: 18px;
}

.approved-note {
    padding: 10px 12px;

    background: #eef5ff;

    border: 1px solid #bfd6ef;

    border-radius: 7px;

    color: #174b83;

    margin-bottom: 18px;

    font-size: 13px;

    line-height: 1.7;
}

code {
    background: rgba(0,0,0,.05);

    padding: 1px 4px;

    border-radius: 4px;
}

@media (
    max-width: 760px
) {

    .grid,
    .info-grid {
        grid-template-columns: 1fr;
    }

    main {
        padding: 12px;
    }
}

</style>

</head>

<body>


<header>

<div class="header-inner">

<div>

<h1>
LiveCameraLinks カメラ編集
</h1>

<div class="header-sub">
camera_candidates / EDIT v2.6
</div>

</div>

<a
    class="back-link"
    href="<?= h($returnUrl) ?>"
>
一覧へ戻る
</a>

</div>

</header>


<main>


<div class="info">

<div class="info-grid">

<div>

<div class="info-label">
DB ID
</div>

<div class="info-value">
<?= (int)$camera['id'] ?>
</div>

</div>


<div>

<div class="info-label">
candidate_id
</div>

<div class="info-value">
<?= h($camera['candidate_id']) ?>
</div>

</div>


<div>

<div class="info-label">
camera_id
</div>

<div class="info-value">
<?= h($camera['camera_id']) ?>
</div>

</div>


<div>

<div class="info-label">
承認日時
</div>

<div class="info-value">

<?php
if (
    !empty(
        $camera['approved_at']
    )
):
?>

<?= h(
    $camera['approved_at']
) ?>

<?php else: ?>

未承認

<?php endif; ?>

</div>

</div>


</div>

</div>


<?php if ($message !== ''): ?>

<div class="message">
<?= h($message) ?>
</div>

<?php endif; ?>


<?php if ($error !== ''): ?>

<div class="error">
<?= h($error) ?>
</div>

<?php endif; ?>


<?php
if (
    $camera['status']
    === 'approved'
):
?>

<div class="approved-note">

このカメラは
<strong>承認済み</strong>
です。

［保存］すると本番
<code>cameras</code>
にも同期されます。

<strong>
承認状態と初回承認日時は保持されます。
</strong>

現行JSON互換用の追加項目
（cameraId・region・themes等）は、
この画面の通常保存では削除されません。

公開JSONや公開サイトへの自動反映は、
まだ行いません。

</div>

<?php else: ?>

<div class="warning">

この画面で保存・確認しても、

<strong>
公開サイトやJSONはまだ変更されません。
</strong>

［承認する］を押した場合のみ、

<code>camera_candidates</code>

から本番

<code>cameras</code>

へ同期されます。

</div>

<?php endif; ?>


<form method="post">


<input
    type="hidden"
    name="id"
    value="<?= (int)$camera['id'] ?>"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= h($csrfToken) ?>"
>

<input
    type="hidden"
    name="return"
    value="<?= h($returnUrl) ?>"
>


<div class="grid">


<div class="field-full">

<label>
カメラID
</label>

<input
    type="text"
    value="<?= h($camera['camera_id']) ?>"
    readonly
>

</div>


<div class="field-full">

<label for="name">
カメラ名 *
</label>

<input
    id="name"
    name="name"
    type="text"
    value="<?= h($camera['name']) ?>"
    required
>

</div>


<div>

<label for="category">
カテゴリ
</label>

<input
    id="category"
    name="category"
    type="text"
    value="<?= h($camera['category']) ?>"
>

</div>


<div>

<label for="subcategory">
サブカテゴリ
</label>

<input
    id="subcategory"
    name="subcategory"
    type="text"
    value="<?= h($camera['subcategory']) ?>"
>

</div>


<div>

<label for="prefecture">
都道府県
</label>

<input
    id="prefecture"
    name="prefecture"
    type="text"
    value="<?= h($camera['prefecture']) ?>"
>

</div>


<div>

<label for="area">
地域
</label>

<input
    id="area"
    name="area"
    type="text"
    value="<?= h($camera['area']) ?>"
>

</div>


<div class="field-full">

<label for="location_name">
設置場所
</label>

<input
    id="location_name"
    name="location_name"
    type="text"
    value="<?= h($camera['location_name']) ?>"
>

</div>


<div class="field-full">

<label for="view_target">
撮影対象
</label>

<input
    id="view_target"
    name="view_target"
    type="text"
    value="<?= h($camera['view_target']) ?>"
>

</div>


<div>

<label for="latitude">
緯度
</label>

<input
    id="latitude"
    name="latitude"
    type="text"
    inputmode="decimal"
    value="<?= h($camera['latitude']) ?>"
>

</div>


<div>

<label for="longitude">
経度
</label>

<input
    id="longitude"
    name="longitude"
    type="text"
    inputmode="decimal"
    value="<?= h($camera['longitude']) ?>"
>

</div>


<div>

<label for="provider_name">
配信元
</label>

<input
    id="provider_name"
    name="provider_name"
    type="text"
    value="<?= h($camera['provider_name']) ?>"
>

</div>


<div>

<label for="provider_type">
配信元種別
</label>

<input
    id="provider_type"
    name="provider_type"
    type="text"
    value="<?= h($camera['provider_type']) ?>"
>

</div>


<div class="field-full">

<label for="provider_url">
配信元URL
</label>

<input
    id="provider_url"
    name="provider_url"
    type="url"
    value="<?= h($camera['provider_url']) ?>"
>

</div>


<div class="field-full">

<label for="youtube_channel_url">
YouTubeチャンネルURL
</label>

<input
    id="youtube_channel_url"
    name="youtube_channel_url"
    type="url"
    value="<?= h($camera['youtube_channel_url']) ?>"
>

</div>


<div class="field-full">

<label for="video_url">
映像URL
</label>

<input
    id="video_url"
    name="video_url"
    type="url"
    value="<?= h($camera['video_url']) ?>"
>

</div>


<div>

<label for="media_type">
メディア種別
</label>

<input
    id="media_type"
    name="media_type"
    type="text"
    value="<?= h($camera['media_type']) ?>"
>

</div>


<div>

<label for="update_interval">
更新間隔
</label>

<input
    id="update_interval"
    name="update_interval"
    type="text"
    value="<?= h($camera['update_interval']) ?>"
>

</div>


<div>

<label for="status">
状態
</label>


<?php
if (
    $camera['status']
    === 'approved'
):
?>

<input
    type="text"
    value="approved"
    readonly
>

<input
    type="hidden"
    name="status"
    value="approved"
>

<?php else: ?>


<select
    id="status"
    name="status"
>

<?php

$statusOptions = [
    '',
    'new',
    'checking',
    'needs_fix',
    'rejected',
    'duplicate',
    'inactive',
];

foreach (
    $statusOptions
    as $statusOption
):

?>

<option
    value="<?= h($statusOption) ?>"
    <?= (string)$camera['status']
        === $statusOption
        ? 'selected'
        : '' ?>
>
<?= $statusOption === ''
    ? '未設定'
    : h($statusOption) ?>
</option>

<?php endforeach; ?>

</select>


<?php endif; ?>

</div>


<div>

<label for="review_status">
確認状態
</label>

<input
    id="review_status"
    name="review_status"
    type="text"
    value="<?= h($camera['review_status']) ?>"
    readonly
>

</div>


<div class="field-full">

<label for="normalization_notes">
正規化メモ
</label>

<textarea
    id="normalization_notes"
    name="normalization_notes"
><?= h($camera['normalization_notes']) ?></textarea>

</div>


<div class="field-full">

<label for="notes">
管理メモ
</label>

<textarea
    id="notes"
    name="notes"
><?= h($camera['notes']) ?></textarea>

</div>


</div>


<div class="actions">


<button
    type="submit"
    name="action"
    value="save"
>
保存
</button>


<?php
if (
    $camera['review_status']
    !== 'confirmed'
    &&
    $camera['status']
    !== 'approved'
):
?>

<button
    type="submit"
    name="action"
    value="confirm"
    class="confirm-button"
>
確認済みにする
</button>

<?php endif; ?>


<?php
if (
    $camera['review_status']
    === 'confirmed'
    &&
    $camera['status']
    !== 'approved'
):
?>

<button
    type="submit"
    name="action"
    value="approve"
    class="approve-button"
>
承認する
</button>

<?php endif; ?>


<a
    class="button-secondary"
    href="<?= h($returnUrl) ?>"
>
保存せず一覧へ戻る
</a>


</div>


</form>


</main>

</body>

</html>