<?php
declare(strict_types=1);

/**
 * LiveCameraLinks
 * Provider Login
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

// Production: outside public_html
$providerAuthProduction = dirname(__DIR__, 3)
    . '/private/livecameralinks/provider-auth.php';

// Local: inside project directory
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
// Already logged in
// =========================================================

if (!empty($_SESSION['provider_account_id'])) {
    header('Location: dashboard.php', true, 303);
    exit;
}

// =========================================================
// CSRF token
// =========================================================

if (empty($_SESSION['provider_csrf'])) {
    $_SESSION['provider_csrf'] = bin2hex(random_bytes(32));
}

// =========================================================
// Helpers
// =========================================================

function loginH($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// =========================================================
// Login processing
// =========================================================

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $requestToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['provider_csrf'] ?? '';

    // CSRF validation
    if (
        !is_string($requestToken)
        || !is_string($sessionToken)
        || $sessionToken === ''
        || !hash_equals($sessionToken, $requestToken)
    ) {
        http_response_code(403);
        $error = '不正なリクエストです。画面を再読み込みしてください。';

    } elseif (
        !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $password === ''
    ) {
        $error = 'メールアドレスとパスワードを確認してください。';

    } else {

        try {
            $pdo = providerDb();

            $account = providerAuthenticate(
                $pdo,
                $email,
                $password
            );

            if ($account) {

                session_regenerate_id(true);

                $_SESSION['provider_account_id']
                    = (int)$account['id'];

                $_SESSION['provider_login_at'] = time();

                $_SESSION['provider_csrf']
                    = bin2hex(random_bytes(32));

                header('Location: dashboard.php', true, 303);
                exit;

            } else {
                $error = 'ログイン情報を確認してください。';
            }

        } catch (Throwable $e) {

            error_log(
                'Provider login error: ' . $e->getMessage()
            );

            http_response_code(503);

            $error = '現在ログイン処理を利用できません。時間をおいてお試しください。';
        }
    }
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

<title>配信者ログイン | LiveCameraLinks</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    background: #f3f6fa;
    color: #243247;
    font-family: system-ui, sans-serif;
}

main {
    max-width: 460px;
    margin: 70px auto;
    padding: 20px;
}

.card {
    background: #fff;
    padding: 30px;
    border-radius: 14px;
    box-shadow: 0 4px 18px #172b4d10;
}

h1 {
    margin-top: 0;
    font-size: 24px;
}

.description {
    color: #64748b;
    line-height: 1.7;
    font-size: 14px;
}

label {
    display: block;
    margin-top: 20px;
    margin-bottom: 8px;
    font-weight: 600;
}

input {
    display: block;
    width: 100%;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font: inherit;
}

input:focus {
    outline: 2px solid #1456a0;
    outline-offset: 1px;
}

button {
    display: block;
    width: 100%;
    margin-top: 26px;
    padding: 13px;
    border: 0;
    border-radius: 8px;
    background: #1456a0;
    color: white;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}

button:hover {
    background: #104782;
}

.error {
    margin-top: 18px;
    padding: 12px;
    border-radius: 8px;
    background: #fff1f1;
    color: #a11d1d;
    line-height: 1.6;
}

.note {
    margin-top: 20px;
    color: #64748b;
    font-size: 13px;
    line-height: 1.7;
}
</style>

</head>

<body>

<main>

<section class="card">

    <h1>LiveCameraLinks</h1>

    <p class="description">
        配信者専用ログイン
    </p>

    <p class="description">
        本人確認と利用承認が完了した配信者のみ、
        管理画面をご利用いただけます。
    </p>

    <?php if ($error !== ''): ?>

        <div class="error" role="alert">
            <?= loginH($error) ?>
        </div>

    <?php endif; ?>

    <form method="post" action="login.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= loginH($_SESSION['provider_csrf']) ?>"
        >

        <label for="email">
            メールアドレス
        </label>

        <input
            type="email"
            id="email"
            name="email"
            value="<?= loginH($email) ?>"
            autocomplete="username"
            maxlength="254"
            required
        >

        <label for="password">
            パスワード
        </label>

        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit">
            ログイン
        </button>

    </form>

    <p class="note">
        配信者アカウントの利用には、
        メール認証・本人確認・管理者による承認が必要です。
    </p>

</section>

</main>

</body>
</html>
