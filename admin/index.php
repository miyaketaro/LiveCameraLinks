<?php

declare(strict_types=1);

/**
 * LiveCameraLinks
 * Camera Candidates Admin v2.3
 *
 * AUTH + SEARCH
 * + STATUS FILTER
 * + REVIEW FILTER
 * + APPROVED COUNT
 * + EDIT LINK
 * + RETURN URL
 *
 * Server:
 * /public_html/livecameralinks.com/admin/index.php
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
// Logout
// =========================================================

if (isset($_GET['logout'])) {

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    header('Location: ./');
    exit;
}


// =========================================================
// Login
// =========================================================

$loginError = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['admin_login'])
) {

    $user = trim(
        (string)($_POST['username'] ?? '')
    );

    $password =
        (string)($_POST['password'] ?? '');

    if (
        hash_equals(
            (string)ADMIN_USER,
            $user
        )
        &&
        hash_equals(
            (string)ADMIN_PASSWORD,
            $password
        )
    ) {

        session_regenerate_id(true);

        $_SESSION['admin_authenticated'] = true;

        header('Location: ./');
        exit;

    } else {

        $loginError =
            'ユーザー名またはパスワードが違います。';
    }
}


// =========================================================
// Login screen
// =========================================================

if (
    empty($_SESSION['admin_authenticated'])
) {

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
LiveCameraLinks 管理ログイン
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

.login-wrap {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 20px;
}

.login-card {
    width: min(420px, 100%);
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 12px;
    padding: 28px;
    box-shadow: 0 10px 30px rgba(0,0,0,.08);
}

.login-card h1 {
    margin: 0 0 8px;
    font-size: 22px;
}

.login-card p {
    margin: 0 0 20px;
    color: #666;
    font-size: 14px;
}

.login-card label {
    display: block;
    margin-top: 16px;
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: 700;
}

.login-card input {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #ccc;
    border-radius: 8px;
    font-size: 15px;
}

.login-card button {
    width: 100%;
    margin-top: 20px;
    padding: 12px;
    border: 0;
    border-radius: 8px;
    background: #18212b;
    color: #fff;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
}

.error {
    margin-top: 14px;
    padding: 10px;
    border-radius: 8px;
    background: #ffe8e8;
    color: #8b1d1d;
    font-size: 13px;
}

</style>

</head>

<body>

<div class="login-wrap">

<div class="login-card">

<h1>
LiveCameraLinks 管理ログイン
</h1>

<p>
管理者専用ページです。
</p>

<?php if ($loginError !== ''): ?>

<div class="error">
<?= htmlspecialchars(
    $loginError,
    ENT_QUOTES,
    'UTF-8'
) ?>
</div>

<?php endif; ?>

<form method="post">

<input
    type="hidden"
    name="admin_login"
    value="1"
>

<label for="username">
ユーザー名
</label>

<input
    id="username"
    name="username"
    type="text"
    autocomplete="username"
    required
>

<label for="password">
パスワード
</label>

<input
    id="password"
    name="password"
    type="password"
    autocomplete="current-password"
    required
>

<button type="submit">
ログイン
</button>

</form>

</div>

</div>

</body>

</html>

<?php

    exit;
}


// =========================================================
// Database connection
// =========================================================

try {

    $pdo = new PDO(
        'mysql:host=' . DB_HOST
        . ';dbname=' . DB_NAME
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
// Filters
// =========================================================

$q = trim(
    (string)($_GET['q'] ?? '')
);

$status = trim(
    (string)($_GET['status'] ?? '')
);

$reviewStatus = trim(
    (string)($_GET['review_status'] ?? '')
);

$prefecture = trim(
    (string)($_GET['prefecture'] ?? '')
);

$category = trim(
    (string)($_GET['category'] ?? '')
);


// =========================================================
// WHERE
// =========================================================

$where = [];
$params = [];

if ($q !== '') {

    $where[] = '
        (
            camera_id LIKE :q
            OR candidate_id LIKE :q
            OR original_id LIKE :q
            OR name LIKE :q
            OR provider_name LIKE :q
            OR location_name LIKE :q
        )
    ';

    $params[':q'] =
        '%' . $q . '%';
}

if ($status !== '') {

    $where[] =
        'status = :status';

    $params[':status'] =
        $status;
}

if ($reviewStatus !== '') {

    $where[] =
        'review_status = :review_status';

    $params[':review_status'] =
        $reviewStatus;
}

if ($prefecture !== '') {

    $where[] =
        'prefecture = :prefecture';

    $params[':prefecture'] =
        $prefecture;
}

if ($category !== '') {

    $where[] =
        'category = :category';

    $params[':category'] =
        $category;
}

$whereSql =
    $where
        ? 'WHERE ' . implode(' AND ', $where)
        : '';


// =========================================================
// Counts
// =========================================================

$countSql = "
    SELECT
        COUNT(*) AS total,

        SUM(
            status = 'needs_fix'
        ) AS needs_fix,

        SUM(
            status = 'approved'
        ) AS approved_count,

        SUM(
            normalized = 1
        ) AS normalized_count,

        SUM(
            review_status = 'unchecked'
        ) AS unchecked_count,

        SUM(
            review_status = 'confirmed'
        ) AS confirmed_count

    FROM camera_candidates
";

$countRow =
    $pdo
        ->query($countSql)
        ->fetch();


// =========================================================
// Filter lists
// =========================================================

$prefectures =
    $pdo
        ->query("
            SELECT DISTINCT prefecture
            FROM camera_candidates
            WHERE prefecture IS NOT NULL
              AND prefecture <> ''
            ORDER BY prefecture
        ")
        ->fetchAll(PDO::FETCH_COLUMN);

$categories =
    $pdo
        ->query("
            SELECT DISTINCT category
            FROM camera_candidates
            WHERE category IS NOT NULL
              AND category <> ''
            ORDER BY category
        ")
        ->fetchAll(PDO::FETCH_COLUMN);


// =========================================================
// Camera rows
// =========================================================

$sql = "
    SELECT
        id,
        candidate_id,
        camera_id,
        original_id,
        name,
        category,
        prefecture,
        area,
        provider_name,
        provider_url,
        video_url,
        normalized,
        provider_url_status,
        video_url_status,
        status,
        review_status,
        target_file
    FROM camera_candidates
    {$whereSql}
    ORDER BY
        CASE
            WHEN status = 'needs_fix' THEN 0
            ELSE 1
        END,

        CASE
            WHEN review_status = 'unchecked' THEN 0
            ELSE 1
        END,

        prefecture,
        category,
        name

    LIMIT 1000
";

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$rows = $stmt->fetchAll();


// =========================================================
// Return URL for edit.php
// =========================================================

$currentRequestUri =
    (string)(
        $_SERVER['REQUEST_URI']
        ??
        '/admin/'
    );

if (
    $currentRequestUri === ''
    ||
    str_contains(
        $currentRequestUri,
        '://'
    )
    ||
    str_starts_with(
        $currentRequestUri,
        '//'
    )
) {

    $currentRequestUri =
        '/admin/';
}


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
LiveCameraLinks カメラ管理
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
    display: flex;
    justify-content: space-between;
    align-items: center;
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

.logout {
    color: white;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.35);
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 13px;
    white-space: nowrap;
}

main {
    padding: 20px;
}

.stats {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.stat {
    display: block;
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 12px 18px;
    min-width: 130px;
    text-decoration: none;
    color: #222;
}

.stat:hover {
    border-color: #9aa7b2;
}

.stat-active {
    border-color: #18212b;
    box-shadow:
        0 0 0 2px rgba(24,33,43,.08);
}

.stat-needs {
    background: #fff7f7;
}

.stat-unchecked {
    background: #fff9e8;
}

.stat-confirmed {
    background: #eef9f1;
}

.stat-approved {
    background: #eef5ff;
}

.stat strong {
    display: block;
    margin-top: 3px;
    font-size: 24px;
}

.filters {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 14px;
    margin-bottom: 18px;
}

.filters form {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

.filters input,
.filters select,
.filters button {
    font-size: 14px;
    padding: 8px 10px;
}

.filters input {
    min-width: 260px;
}

.reset {
    display: inline-block;
    padding: 8px 10px;
}

.result-info {
    margin-bottom: 10px;
    color: #666;
    font-size: 13px;
}

.table-wrap {
    overflow: auto;
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
}

table {
    width: 100%;
    border-collapse: collapse;
    white-space: nowrap;
    font-size: 13px;
}

th,
td {
    padding: 8px 10px;
    border-bottom: 1px solid #eee;
    text-align: left;
    vertical-align: top;
}

th {
    position: sticky;
    top: 0;
    background: #eef1f4;
    z-index: 1;
}

.row-needs-fix {
    background: #fffafa;
}

.row-unchecked {
    background: #fffdf6;
}

.row-approved {
    background: #f7faff;
}

.badge {
    display: inline-block;
    border-radius: 12px;
    padding: 2px 8px;
    font-size: 12px;
    background: #e9ecef;
}

.badge-needs {
    background: #ffe8e8;
    color: #8b1d1d;
}

.badge-unchecked {
    background: #fff0bf;
    color: #725500;
}

.badge-confirmed {
    background: #dff4e5;
    color: #215f32;
}

.badge-approved {
    background: #dcecff;
    color: #174b83;
}

.badge-normalized {
    background: #e7f3ff;
}

a {
    color: #075cab;
}

.muted {
    color: #777;
    font-size: 11px;
    margin-top: 3px;
}

.empty {
    padding: 30px;
    text-align: center;
    color: #777;
}

.edit-button {
    display: inline-block;
    background: #18212b;
    color: white;
    text-decoration: none;
    border-radius: 7px;
    padding: 6px 11px;
    font-size: 12px;
    font-weight: 700;
}

@media (max-width: 700px) {

    header {
        padding: 14px;
    }

    main {
        padding: 12px;
    }

    .filters input {
        min-width: 100%;
        width: 100%;
    }
}

</style>

</head>

<body>


<header>

<div class="header-inner">

<div>

<h1>
LiveCameraLinks カメラ管理
</h1>

<div class="header-sub">
camera_candidates / ADMIN v2.3
</div>

</div>

<a
    class="logout"
    href="?logout=1"
>
ログアウト
</a>

</div>

</header>


<main>


<div class="stats">


<a
    class="stat
    <?= $status === '' && $reviewStatus === ''
        ? 'stat-active'
        : '' ?>"
    href="./"
>
総候補
<strong>
<?= (int)$countRow['total'] ?>
</strong>
</a>


<a
    class="stat stat-unchecked
    <?= $reviewStatus === 'unchecked'
        ? 'stat-active'
        : '' ?>"
    href="?review_status=unchecked"
>
未確認
<strong>
<?= (int)$countRow['unchecked_count'] ?>
</strong>
</a>


<a
    class="stat stat-confirmed
    <?= $reviewStatus === 'confirmed'
        ? 'stat-active'
        : '' ?>"
    href="?review_status=confirmed"
>
確認済み
<strong>
<?= (int)$countRow['confirmed_count'] ?>
</strong>
</a>


<a
    class="stat stat-approved
    <?= $status === 'approved'
        ? 'stat-active'
        : '' ?>"
    href="?status=approved"
>
承認済み
<strong>
<?= (int)$countRow['approved_count'] ?>
</strong>
</a>


<a
    class="stat stat-needs
    <?= $status === 'needs_fix'
        ? 'stat-active'
        : '' ?>"
    href="?status=needs_fix"
>
要修正
<strong>
<?= (int)$countRow['needs_fix'] ?>
</strong>
</a>


<div class="stat">
正規化済み
<strong>
<?= (int)$countRow['normalized_count'] ?>
</strong>
</div>


<div class="stat">
表示件数
<strong>
<?= count($rows) ?>
</strong>
</div>


</div>


<div class="filters">

<form method="get">

<input
    type="text"
    name="q"
    value="<?= h($q) ?>"
    placeholder="カメラ名・ID・配信元を検索"
>


<select name="status">

<option value="">
全ステータス
</option>

<?php

foreach (
    [
        'new',
        'checking',
        'needs_fix',
        'approved',
        'rejected',
        'duplicate',
        'inactive',
    ]
    as $item
):

?>

<option
    value="<?= h($item) ?>"
    <?= $status === $item
        ? 'selected'
        : '' ?>
>
<?= h($item) ?>
</option>

<?php endforeach; ?>

</select>


<select name="review_status">

<option value="">
全確認状態
</option>

<option
    value="unchecked"
    <?= $reviewStatus === 'unchecked'
        ? 'selected'
        : '' ?>
>
unchecked
</option>

<option
    value="confirmed"
    <?= $reviewStatus === 'confirmed'
        ? 'selected'
        : '' ?>
>
confirmed
</option>

</select>


<select name="prefecture">

<option value="">
全都道府県
</option>

<?php foreach ($prefectures as $item): ?>

<option
    value="<?= h($item) ?>"
    <?= $prefecture === $item
        ? 'selected'
        : '' ?>
>
<?= h($item) ?>
</option>

<?php endforeach; ?>

</select>


<select name="category">

<option value="">
全カテゴリ
</option>

<?php foreach ($categories as $item): ?>

<option
    value="<?= h($item) ?>"
    <?= $category === $item
        ? 'selected'
        : '' ?>
>
<?= h($item) ?>
</option>

<?php endforeach; ?>

</select>


<button type="submit">
検索
</button>

<a
    class="reset"
    href="./"
>
リセット
</a>

</form>

</div>


<div class="result-info">

<?php if ($reviewStatus === 'unchecked'): ?>

現在「未確認」のカメラだけを表示しています。

<?php elseif ($reviewStatus === 'confirmed'): ?>

現在「確認済み」のカメラだけを表示しています。

<?php elseif ($status === 'approved'): ?>

現在「承認済み」のカメラだけを表示しています。

<?php elseif ($status === 'needs_fix'): ?>

現在「要修正」のカメラだけを表示しています。

<?php else: ?>

条件に一致するカメラを表示しています。

<?php endif; ?>

</div>


<div class="table-wrap">

<table>

<thead>

<tr>
<th>操作</th>
<th>ID</th>
<th>カメラ名</th>
<th>都道府県</th>
<th>地域</th>
<th>カテゴリ</th>
<th>配信元</th>
<th>配信元URL</th>
<th>映像URL</th>
<th>状態</th>
<th>確認</th>
<th>正規化</th>
<th>出力先</th>
</tr>

</thead>


<tbody>

<?php if (!$rows): ?>

<tr>

<td
    colspan="13"
    class="empty"
>
該当するカメラはありません。
</td>

</tr>

<?php endif; ?>


<?php foreach ($rows as $row): ?>

<tr
    class="
    <?= $row['status'] === 'needs_fix'
        ? 'row-needs-fix'
        : '' ?>

    <?= $row['review_status'] === 'unchecked'
        ? 'row-unchecked'
        : '' ?>

    <?= $row['status'] === 'approved'
        ? 'row-approved'
        : '' ?>
    "
>


<td>

<a
    class="edit-button"
    href="edit.php?id=<?= (int)$row['id'] ?>&return=<?= urlencode($currentRequestUri) ?>"
>
編集
</a>

</td>


<td>

<?= h($row['camera_id']) ?>

<div class="muted">
DB: <?= (int)$row['id'] ?>
</div>

</td>


<td>

<strong>
<?= h($row['name']) ?>
</strong>

</td>


<td>
<?= h($row['prefecture']) ?>
</td>


<td>
<?= h($row['area']) ?>
</td>


<td>
<?= h($row['category']) ?>
</td>


<td>
<?= h($row['provider_name']) ?>
</td>


<td>

<?php if (!empty($row['provider_url'])): ?>

<a
    href="<?= h($row['provider_url']) ?>"
    target="_blank"
    rel="noopener noreferrer"
>
配信元
</a>

<?php else: ?>

<span class="muted">
なし
</span>

<?php endif; ?>

</td>


<td>

<?php if (!empty($row['video_url'])): ?>

<a
    href="<?= h($row['video_url']) ?>"
    target="_blank"
    rel="noopener noreferrer"
>
映像
</a>

<?php else: ?>

<span class="muted">
なし
</span>

<?php endif; ?>

</td>


<td>

<?php if ($row['status'] === 'approved'): ?>

<span class="badge badge-approved">
approved
</span>

<?php elseif ($row['status'] === 'needs_fix'): ?>

<span class="badge badge-needs">
needs_fix
</span>

<?php else: ?>

<span class="badge">
<?= h($row['status']) ?>
</span>

<?php endif; ?>

</td>


<td>

<?php if ($row['review_status'] === 'unchecked'): ?>

<span class="badge badge-unchecked">
未確認
</span>

<?php elseif ($row['review_status'] === 'confirmed'): ?>

<span class="badge badge-confirmed">
確認済み
</span>

<?php else: ?>

<span class="badge">
<?= h($row['review_status']) ?>
</span>

<?php endif; ?>

</td>


<td>

<?php if ((int)$row['normalized'] === 1): ?>

<span class="badge badge-normalized">
済
</span>

<?php else: ?>

-

<?php endif; ?>

</td>


<td>
<?= h($row['target_file']) ?>
</td>


</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>


</main>

</body>

</html>