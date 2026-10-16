<?php
declare(strict_types=1);

// LiveCameraLinks 配信者専用ログアウト処理

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

// POST方式以外は受け付けない
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('この操作はPOST方式で実行してください。');
}

// ログイン状態を確認
if (empty($_SESSION['provider_account_id'])) {
    header('Location: login.php', true, 303);
    exit;
}

// CSRFトークンを確認
$sessionToken = $_SESSION['provider_csrf'] ?? '';
$requestToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($sessionToken)
    || !is_string($requestToken)
    || $sessionToken === ''
    || !hash_equals($sessionToken, $requestToken)
) {
    http_response_code(403);
    exit('不正なリクエストです。画面を再読み込みしてください。');
}

// セッション情報を削除
$_SESSION = [];

// ブラウザのセッションCookieを削除
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
}

// サーバー側のセッションを破棄
session_destroy();

// ログイン画面へ戻る
header('Location: login.php', true, 303);
exit;
