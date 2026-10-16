<?php
declare(strict_types=1);

/**
 * LiveCameraLinks
 * Provider Promotion Editor
 *
 * 配信者専用PR編集画面
 * 保存内容は下書きとして扱う
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

function promotionH($value): string
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
// Camera ID validation
// =========================================================

$cameraId = trim(
    (string)($_GET['camera_id'] ?? '')
);

if (
    $cameraId === ''
    || strlen($cameraId) > 191
) {
    http_response_code(400);
    exit('カメラIDが正しくありません。');
}

// =========================================================
// CSRF token
// =========================================================

if (empty($_SESSION['provider_csrf'])) {
    $_SESSION['provider_csrf'] = bin2hex(random_bytes(32));
}

// =========================================================
// Initial values
// =========================================================

$error = '';
$message = '';
$camera = null;
$promotion = null;
$pdo = null;
$account = null;

// =========================================================
// Database processing
// =========================================================

try {
    $pdo = providerDb();

    // 1. 本人確認済みの有効アカウントか確認
    $stmt = $pdo->prepare(
        "SELECT id, provider_id
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
        http_response_code(403);
        exit('配信者アカウントが認証されていません。');
    }

    $providerId = (string)$account['provider_id'];

    // 2. カメラ編集権限を確認
    if (!providerCanEditCamera(
        $pdo,
        $accountId,
        $cameraId
    )) {
        http_response_code(403);
        exit('このカメラの編集権限がありません。');
    }

    // 3. カメラ情報を取得
    $stmt = $pdo->prepare(
        "SELECT camera_id, name AS title
         FROM cameras
         WHERE camera_id = :camera_id
         LIMIT 1"
    );

    $stmt->execute([
        'camera_id' => $cameraId,
    ]);

    $camera = $stmt->fetch();

    if (!$camera) {
        http_response_code(404);
        exit('カメラが見つかりません。');
    }

    // 4. 下書きを優先して取得。なければ既存PRを初期値にする。
    $stmt = $pdo->prepare(
        "SELECT id, promotion_text, promotion_url, status
         FROM provider_promotions
         WHERE camera_id = :camera_id AND provider_id = :provider_id
         ORDER BY (status = 'draft') DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute(['camera_id' => $cameraId, 'provider_id' => $providerId]);
    $promotion = $stmt->fetch() ?: null;

    // POST: Save draft. 公開中の行は絶対に更新しない。
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !hash_equals((string)$_SESSION['provider_csrf'], $token)) {
            http_response_code(403);
            $error = '画面を再読み込みして、もう一度お試しください。';
        } else {
            $rawText = $_POST['promotion_text'] ?? '';
            $rawUrl = $_POST['promotion_url'] ?? '';
            if (!is_string($rawText) || !is_string($rawUrl)) {
                $error = '入力内容が正しくありません。';
            } else {
                $text = trim($rawText);
                $url = trim($rawUrl);
                if (mb_strlen($text, 'UTF-8') > 1000) {
                    $error = 'PR文章は1000文字以内で入力してください。';
                } elseif (strlen($url) > 2048) {
                    $error = 'URLが長すぎます。';
                } elseif ($url !== '' && (
                    !filter_var($url, FILTER_VALIDATE_URL)
                    || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
                )) {
                    $error = 'URLはhttpまたはhttps形式で入力してください。';
                } elseif (!$promotion) {
                    $error = 'PR登録情報がありません。管理者へお問い合わせください。';
                } else {
                    $pdo->beginTransaction();
                    // 同じカメラへの同時保存を直列化する。
                    $lock = $pdo->prepare(
                        'SELECT camera_id FROM cameras WHERE camera_id = :camera_id FOR UPDATE'
                    );
                    $lock->execute(['camera_id' => $cameraId]);
                    if (!$lock->fetchColumn()) {
                        throw new RuntimeException('Camera disappeared during save');
                    }

                    // ロック取得後に権限と本人確認を再検証する。
                    if (!providerCanEditCamera($pdo, $accountId, $cameraId)) {
                        $pdo->rollBack();
                        http_response_code(403);
                        exit('編集権限がありません。');
                    }
                    $checkAccount = $pdo->prepare(
                        "SELECT 1 FROM provider_accounts
                         WHERE id = :account_id AND provider_id = :provider_id
                           AND status = 'active' AND verification_status = 'verified'
                           AND email_verified_at IS NOT NULL LIMIT 1"
                    );
                    $checkAccount->execute([
                        'account_id' => $accountId,
                        'provider_id' => $providerId,
                    ]);
                    if (!$checkAccount->fetchColumn()) {
                        $pdo->rollBack();
                        http_response_code(403);
                        exit('配信者認証が無効です。');
                    }

                    $findDraft = $pdo->prepare(
                        "SELECT id FROM provider_promotions
                         WHERE camera_id = :camera_id AND provider_id = :provider_id
                           AND status = 'draft'
                         ORDER BY id DESC LIMIT 1 FOR UPDATE"
                    );
                    $findDraft->execute([
                        'camera_id' => $cameraId,
                        'provider_id' => $providerId,
                    ]);
                    $draftId = $findDraft->fetchColumn();

                    if ($draftId !== false) {
                        $save = $pdo->prepare(
                            "UPDATE provider_promotions
                             SET promotion_text = :promotion_text,
                                 promotion_url = :promotion_url
                             WHERE id = :id AND camera_id = :camera_id
                               AND provider_id = :provider_id AND status = 'draft'"
                        );
                        $save->execute([
                            'promotion_text' => $text,
                            'promotion_url' => $url !== '' ? $url : null,
                            'id' => $draftId,
                            'camera_id' => $cameraId,
                            'provider_id' => $providerId,
                        ]);
                    } else {
                        // 公開中PRは保持し、新しい下書きを別行として作成する。
                        $save = $pdo->prepare(
                            "INSERT INTO provider_promotions
                             (camera_id, provider_id, promotion_text, promotion_url, status)
                             VALUES (:camera_id, :provider_id, :promotion_text, :promotion_url, 'draft')"
                        );
                        $save->execute([
                            'camera_id' => $cameraId,
                            'provider_id' => $providerId,
                            'promotion_text' => $text,
                            'promotion_url' => $url !== '' ? $url : null,
                        ]);
                        $draftId = $pdo->lastInsertId();
                    }

                    $getSaved = $pdo->prepare(
                        "SELECT id, promotion_text, promotion_url, status
                         FROM provider_promotions
                         WHERE id = :id AND camera_id = :camera_id
                           AND provider_id = :provider_id AND status = 'draft' LIMIT 1"
                    );
                    $getSaved->execute([
                        'id' => $draftId,
                        'camera_id' => $cameraId,
                        'provider_id' => $providerId,
                    ]);
                    $saved = $getSaved->fetch();
                    if (!$saved
                        || (string)($saved['promotion_text'] ?? '') !== $text
                        || (string)($saved['promotion_url'] ?? '') !== $url
                    ) {
                        throw new RuntimeException('Draft save verification failed');
                    }
                    $pdo->commit();
                    $promotion = $saved;
                    $_SESSION['provider_csrf'] = bin2hex(random_bytes(32));
                    $message = 'PR文章を下書き保存しました。まだ公開されていません。';
                }
            }
        }
    }

} catch (Throwable $e) {

    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Provider promotion edit error: '
        . $e->getMessage()
    );

    http_response_code(503);

    $error = '現在保存できません。時間をおいてお試しください。';
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

<title>PR編集 | LiveCameraLinks</title>

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
    max-width: 720px;
    margin: 36px auto;
    padding: 28px;
    background: white;
    border-radius: 14px;
}

h1 {
    font-size: 24px;
}

h2 {
    font-size: 19px;
}

label {
    display: block;
    margin-top: 22px;
    font-weight: bold;
}

textarea,
input {
    box-sizing: border-box;
    width: 100%;
    padding: 12px;
    margin-top: 8px;
    border: 1px solid #bac6d3;
    border-radius: 8px;
    font: inherit;
}

textarea {
    min-height: 180px;
}

button {
    margin-top: 24px;
    padding: 12px 22px;
    background: #1456a0;
    color: white;
    border: 0;
    border-radius: 8px;
    cursor: pointer;
}

p {
    line-height: 1.7;
}

.error {
    color: #a11d1d;
}

.success {
    color: #176534;
}

.note {
    color: #64748b;
}
</style>

</head>

<body>

<main>

<p>
    <a href="dashboard.php">← 管理画面へ戻る</a>
</p>

<h1>カメラPR編集</h1>

<?php if ($camera): ?>

    <h2>
        <?= promotionH($camera['title']) ?>
    </h2>

    <p class="note">
        カメラID：
        <?= promotionH($cameraId) ?>
    </p>

<?php endif; ?>

<?php if ($error !== ''): ?>

    <p class="error" role="alert">
        <?= promotionH($error) ?>
    </p>

<?php endif; ?>

<?php if ($message !== ''): ?>

    <p class="success" role="status">
        <?= promotionH($message) ?>
    </p>

<?php endif; ?>

<?php if ($camera && $promotion && $error === ''): ?>

<form method="post">

    <input
        type="hidden"
        name="csrf_token"
        value="<?= promotionH($_SESSION['provider_csrf']) ?>"
    >

    <label for="promotion_text">
        PR文章（1000文字以内）
    </label>

    <textarea
        id="promotion_text"
        name="promotion_text"
        maxlength="1000"
    ><?= promotionH(
        $promotion['promotion_text'] ?? ''
    ) ?></textarea>

    <label for="promotion_url">
        PRリンク（任意）
    </label>

    <input
        id="promotion_url"
        name="promotion_url"
        type="url"
        maxlength="2048"
        value="<?= promotionH(
            $promotion['promotion_url'] ?? ''
        ) ?>"
    >

    <p class="note">
        保存すると下書きになります。
        支払い・管理者確認・公開条件の確認が完了するまで、
        一般公開されません。
    </p>

    <button type="submit">
        下書きを保存
    </button>

</form>

<?php elseif ($camera && !$promotion && $error === ''): ?>

<p class="note">
    この配信者に紐づくPR登録情報はありません。
    管理者へお問い合わせください。
</p>

<?php endif; ?>

</main>

</body>
</html>
