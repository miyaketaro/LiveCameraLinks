<?php

declare(strict_types=1);

/**
 * LiveCameraLinks
 * JSON Preview Export Admin v1.0
 *
 * Server:
 * /public_html/livecameralinks.com/admin/export-preview.php
 *
 * Exporter:
 * /public_html/livecameralinks.com/scripts/export/export-cameras.php
 *
 * IMPORTANT:
 * - 管理者ログイン必須
 * - 公開 camera/ JSON は変更しない
 * - preview JSON のみ生成
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
// Helper
// =========================================================

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
// Paths
// =========================================================

$siteRoot =
    dirname(__DIR__);

$exportScript =
    $siteRoot
    . DIRECTORY_SEPARATOR
    . 'scripts'
    . DIRECTORY_SEPARATOR
    . 'export'
    . DIRECTORY_SEPARATOR
    . 'export-cameras.php';

$previewRoot =
    $siteRoot
    . DIRECTORY_SEPARATOR
    . 'scripts'
    . DIRECTORY_SEPARATOR
    . 'export'
    . DIRECTORY_SEPARATOR
    . 'output'
    . DIRECTORY_SEPARATOR
    . 'preview';


// =========================================================
// Current production camera count
// =========================================================

$productionCameraCount = 0;

$dbError = '';

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

    $productionCameraCount =
        (int)$pdo
            ->query("
                SELECT COUNT(*)
                FROM cameras
                WHERE status = 'active'
            ")
            ->fetchColumn();

} catch (Throwable $e) {

    $dbError =
        '本番 cameras テーブルの件数取得に失敗しました。';
}


// =========================================================
// Export
// =========================================================

$message = '';

$error = '';

$output = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

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
    // Export script existence
    // -----------------------------------------------------

    if (
        !is_file(
            $exportScript
        )
    ) {

        $error =
            'export-cameras.php が見つかりません。';

    } else {

        // -------------------------------------------------
        // Execute exporter in separate PHP process
        // -------------------------------------------------

        $phpBinary =
            PHP_BINARY;

        $command =
            escapeshellarg(
                $phpBinary
            )
            . ' '
            . escapeshellarg(
                $exportScript
            )
            . ' 2>&1';


        if (
            function_exists(
                'shell_exec'
            )
        ) {

            $result =
                shell_exec(
                    $command
                );

            $output =
                (string)$result;


            if (
                str_contains(
                    $output,
                    'JSON preview export completed.'
                )
            ) {

                $message =
                    'プレビューJSONを生成しました。';

            } else {

                $error =
                    'JSON生成処理でエラーが発生しました。';
            }

        } else {

            $error =
                'このサーバーでは shell_exec が利用できません。';
        }
    }
}


// =========================================================
// Preview file inspection
// =========================================================

$previewFiles = [];

if (
    is_dir(
        $previewRoot
    )
) {

    $iterator =
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $previewRoot,
                FilesystemIterator::SKIP_DOTS
            )
        );

    foreach (
        $iterator
        as $file
    ) {

        if (
            !$file->isFile()
        ) {

            continue;
        }

        if (
            strtolower(
                $file->getExtension()
            )
            !== 'json'
        ) {

            continue;
        }


        $fullPath =
            $file->getPathname();

        $relativePath =
            substr(
                $fullPath,
                strlen(
                    $previewRoot
                ) + 1
            );

        $relativePath =
            str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                $relativePath
            );


        $previewFiles[] = [

            'path' =>
                $relativePath,

            'size' =>
                $file->getSize(),

            'updated' =>
                date(
                    'Y-m-d H:i:s',
                    $file->getMTime()
                ),
        ];
    }


    usort(
        $previewFiles,
        static function (
            array $a,
            array $b
        ): int {

            return strcmp(
                $a['path'],
                $b['path']
            );
        }
    );
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
JSONプレビュー生成 | LiveCameraLinks
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

    color: white;

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

.card {
    background: white;

    border: 1px solid #ddd;

    border-radius: 10px;

    padding: 20px;

    margin-bottom: 18px;
}

.stats {
    display: flex;

    gap: 12px;

    flex-wrap: wrap;
}

.stat {
    min-width: 160px;

    background: #f8f9fa;

    border: 1px solid #ddd;

    border-radius: 8px;

    padding: 14px;
}

.stat-label {
    font-size: 12px;

    color: #666;
}

.stat-value {
    margin-top: 3px;

    font-size: 26px;

    font-weight: 700;
}

.warning {
    padding: 12px 14px;

    background: #fff7df;

    border:
        1px solid
        #efd995;

    border-radius: 8px;

    margin-bottom: 18px;

    line-height: 1.6;

    font-size: 13px;
}

.message {
    padding: 12px 14px;

    background: #e8f7ec;

    color: #145c2b;

    border:
        1px solid
        #b7dfc1;

    border-radius: 8px;

    margin-bottom: 18px;
}

.error {
    padding: 12px 14px;

    background: #ffe8e8;

    color: #8b1d1d;

    border:
        1px solid
        #efbcbc;

    border-radius: 8px;

    margin-bottom: 18px;
}

button {
    border: 0;

    border-radius: 8px;

    padding: 12px 20px;

    background: #18212b;

    color: white;

    font-weight: 700;

    cursor: pointer;
}

button:hover {
    background: #304151;
}

table {
    width: 100%;

    border-collapse: collapse;

    font-size: 13px;
}

th,
td {
    padding: 9px 10px;

    border-bottom:
        1px solid #eee;

    text-align: left;
}

th {
    background: #eef1f4;
}

pre {
    white-space: pre-wrap;

    overflow-wrap: anywhere;

    background: #101820;

    color: #e8edf2;

    padding: 15px;

    border-radius: 8px;

    font-size: 12px;

    line-height: 1.6;
}

.muted {
    color: #777;

    font-size: 12px;
}

code {
    background: rgba(0,0,0,.05);

    padding: 2px 4px;

    border-radius: 4px;
}

@media (
    max-width: 700px
) {

    main {
        padding: 12px;
    }

    header {
        padding: 14px;
    }

    .header-inner {
        align-items: flex-start;
    }
}

</style>

</head>

<body>


<header>

<div class="header-inner">

<div>

<h1>
LiveCameraLinks JSONプレビュー生成
</h1>

<div class="header-sub">
EXPORT PREVIEW v1.0
</div>

</div>


<a
    class="back-link"
    href="./"
>
管理画面へ戻る
</a>

</div>

</header>


<main>


<?php
if (
    $message !== ''
):
?>

<div class="message">
<?= h($message) ?>
</div>

<?php endif; ?>


<?php
if (
    $error !== ''
):
?>

<div class="error">
<?= h($error) ?>
</div>

<?php endif; ?>


<?php
if (
    $dbError !== ''
):
?>

<div class="error">
<?= h($dbError) ?>
</div>

<?php endif; ?>


<div class="warning">

この画面では
<strong>
本番JSONは変更しません。
</strong>

本番
<code>cameras</code>
テーブルから、

<code>
scripts/export/output/preview/
</code>

だけへJSONを書き出します。

</div>


<div class="card">

<div class="stats">

<div class="stat">

<div class="stat-label">
本番 active カメラ
</div>

<div class="stat-value">
<?= $productionCameraCount ?>
</div>

</div>


<div class="stat">

<div class="stat-label">
生成済みJSON
</div>

<div class="stat-value">
<?= count($previewFiles) ?>
</div>

</div>

</div>

</div>


<div class="card">

<h2>
プレビュー生成
</h2>

<p class="muted">
現在の本番 cameras テーブルから
プレビューJSONを再生成します。
</p>


<form method="post">

<input
    type="hidden"
    name="csrf_token"
    value="<?= h($csrfToken) ?>"
>

<button type="submit">
プレビューJSONを生成
</button>

</form>

</div>


<?php if ($output !== ''): ?>

<div class="card">

<h2>
実行結果
</h2>

<pre><?= h($output) ?></pre>

</div>

<?php endif; ?>


<div class="card">

<h2>
生成ファイル
</h2>


<?php if (!$previewFiles): ?>

<p class="muted">
まだプレビューJSONはありません。
</p>

<?php else: ?>


<table>

<thead>

<tr>
<th>ファイル</th>
<th>サイズ</th>
<th>更新日時</th>
</tr>

</thead>

<tbody>

<?php
foreach (
    $previewFiles
    as $file
):
?>

<tr>

<td>
<?= h($file['path']) ?>
</td>

<td>
<?= number_format(
    (int)$file['size']
) ?>
 bytes
</td>

<td>
<?= h($file['updated']) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>


<?php endif; ?>

</div>


</main>

</body>

</html>