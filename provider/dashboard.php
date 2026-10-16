<?php
declare(strict_types=1);

/**
 * LiveCameraLinks
 * Provider Dashboard
 *
 * Local:
 *   LiveCameraLinks/private/provider-auth.php
 *
 * Production:
 *   /private/livecameralinks/provider-auth.php
 */

// =========================================================
// Authentication file
// =========================================================

$providerAuthProduction = dirname(__DIR__, 3)
    . '/private/livecameralinks/provider-auth.php';

$providerAuthLocal = dirname(__DIR__)
    . '/private/provider-auth.php';

if (is_file($providerAuthProduction)) {
    require_once $providerAuthProduction;
} elseif (is_file($providerAuthLocal)) {
    require_once $providerAuthLocal;
} else {
    error_log('Provider authentication file not found');
    http_response_code(503);
    exit('現在サービスを利用できません。');
}

// =========================================================
// Session
// =========================================================

session_name('LCL_PROVIDER');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/provider',
    'secure' => !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// =========================================================
// HTML escaping
// =========================================================

function dashboardH($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// =========================================================
// Login check
// =========================================================

if (empty($_SESSION['provider_account_id'])) {
    header('Location: login.php', true, 303);
    exit;
}

$accountId = (int)$_SESSION['provider_account_id'];

// =========================================================
// CSRF token
// =========================================================

if (empty($_SESSION['provider_csrf'])) {
    $_SESSION['provider_csrf'] = bin2hex(random_bytes(32));
}

// =========================================================
// Database
// =========================================================

$account = null;
$cameras = [];
$error = '';

try {
    $pdo = providerDb();

    // 本人確認済みの有効アカウントだけを取得
    $stmt = $pdo->prepare(
        "SELECT
             id,
             provider_id,
             display_name
         FROM provider_accounts
         WHERE id = :id
           AND status = 'active'
           AND verification_status = 'verified'
           AND email_verified_at IS NOT NULL
         LIMIT 1"
    );

    $stmt->execute([
        'id' => $accountId,
    ]);

    $account = $stmt->fetch();

    if (!$account) {
        unset(
            $_SESSION['provider_account_id'],
            $_SESSION['provider_login_at']
        );

        header('Location: login.php', true, 303);
        exit;
    }

    // 編集権限があり、配信者IDが一致するPRを取得
    // 同じカメラに複数のPRがある場合は最新の1件を取得
    $stmt = $pdo->prepare(
        "SELECT
             c.camera_id,
             c.name AS title,
             pca.access_role,
             pp.id AS promotion_id,
             pp.promotion_text,
             pp.status AS promotion_status,
             pp.valid_until

         FROM provider_camera_access AS pca

         JOIN cameras AS c
           ON c.camera_id = pca.camera_id

         JOIN provider_promotions AS pp
           ON pp.id = (
               SELECT MAX(pp2.id)
               FROM provider_promotions AS pp2
               WHERE pp2.camera_id = c.camera_id
                 AND pp2.provider_id = :promotion_provider_id
           )

         WHERE pca.provider_account_id = :account_id
           AND pca.access_role IN ('owner', 'editor')
           AND pca.verification_status = 'verified'
           AND pca.granted_at IS NOT NULL
           AND pca.revoked_at IS NULL

         ORDER BY c.camera_id"
    );

    $stmt->execute([
        'promotion_provider_id' => $account['provider_id'],
        'account_id' => $accountId,
    ]);

    $cameras = $stmt->fetchAll();

} catch (Throwable $e) {

    error_log(
        'Provider dashboard error: ' . $e->getMessage()
    );

    http_response_code(503);

    $error = '現在データを取得できません。時間をおいてお試しください。';
}

?>
<!doctype html>
<html lang="ja">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<meta name="robots" content="noindex,nofollow">

<title>配信者管理画面 | LiveCameraLinks</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f3f6fa;
    color: #243247;
    font-family: system-ui, sans-serif;
}

main {
    max-width: 960px;
    margin: 36px auto;
    padding: 24px;
}

header,
article {
    background: #fff;
    padding: 24px;
    border-radius: 14px;
    margin-bottom: 18px;
    box-shadow: 0 4px 18px #172b4d10;
}

h1 {
    margin-top: 0;
    font-size: 24px;
}

h2 {
    font-size: 19px;
}

p {
    line-height: 1.7;
}

.meta {
    color: #64748b;
    font-size: 14px;
}

.button {
    display: inline-block;
    padding: 10px 16px;
    border-radius: 8px;
    background: #1456a0;
    color: white;
    text-decoration: none;
}

.button:hover {
    background: #104782;
}

.logout-button {
    margin-top: 10px;
    padding: 10px 18px;
    border: 1px solid #bac6d3;
    border-radius: 8px;
    background: #fff;
    color: #243247;
    font: inherit;
    cursor: pointer;
}

.logout-button:hover {
    background: #f3f6fa;
}

.error {
    color: #a11d1d;
}
</style>

</head>

<body>

<main>

<header>

    <h1>LiveCameraLinks 配信者管理画面</h1>

    <?php if ($account): ?>

        <p>
            ようこそ、
            <?= dashboardH($account['display_name']) ?> さん
        </p>

    <?php endif; ?>

    <p class="meta">
        本人確認と編集権限の承認が完了したカメラを表示しています。
    </p>

    <form method="post" action="logout.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= dashboardH($_SESSION['provider_csrf']) ?>"
        >

        <button
            type="submit"
            class="logout-button"
        >
            ログアウト
        </button>

    </form>

</header>

<?php if ($error !== ''): ?>

    <article>

        <p class="error" role="alert">
            <?= dashboardH($error) ?>
        </p>

    </article>

<?php elseif (!$cameras): ?>

    <article>

        <h2>編集可能なカメラはありません</h2>

        <p>
            配信者確認・編集権限の承認・PR登録が完了すると、
            ここにカメラが表示されます。
        </p>

    </article>

<?php else: ?>

    <?php foreach ($cameras as $camera): ?>

        <article>

            <h2>
                <?= dashboardH($camera['title']) ?>
            </h2>

            <p class="meta">
                カメラID：
                <?= dashboardH($camera['camera_id']) ?>
            </p>

            <p class="meta">
                権限：
                <?= dashboardH($camera['access_role']) ?>
            </p>

            <p>
                PR状態：
                <?= dashboardH(
                    $camera['promotion_status'] ?? '未登録'
                ) ?>
            </p>

            <p>
                PR文章：
                <?= dashboardH(
                    $camera['promotion_text'] ?? '未設定'
                ) ?>
            </p>

            <a
                class="button"
                href="promotion-edit.php?camera_id=<?= rawurlencode($camera['camera_id']) ?>"
            >
                PRを編集する
            </a>

        </article>

    <?php endforeach; ?>

<?php endif; ?>

</main>

</body>
</html>
