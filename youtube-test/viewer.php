<?php
declare(strict_types=1);

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function mapUrl(array $r): string {
    $q = trim((string)($r['title'] ?? ''));
    $q = preg_replace('/(?:LIVE|Live|live|ライブカメラ|ライブ|24\/7|生中継|定点カメラ)/u', ' ', $q) ?? $q;
    $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
    if ($q === '') $q = (string)($r['matched_query'] ?? '日本');
    return 'https://www.openstreetmap.org/search?query=' . rawurlencode($q);
}
function channelUrl(array $r): string {
    $id = trim((string)($r['channel_id'] ?? ''));
    return $id !== '' ? 'https://www.youtube.com/channel/' . rawurlencode($id) : '#';
}
$outDir = __DIR__ . '/output';
$files = glob($outDir . '/candidates-*.json') ?: [];
rsort($files, SORT_STRING);
if (!$files) {
    http_response_code(404);
    echo 'まだ収集結果がありません。collector.php を先に実行してください。';
    exit;
}
$latest = $files[0];
$data = json_decode((string)file_get_contents($latest), true);
if (!is_array($data)) $data = [];

$status = (string)($_GET['status'] ?? 'all');
$discovery = (string)($_GET['discovery'] ?? 'all');
$tag = (string)($_GET['tag'] ?? 'all');
$q = trim((string)($_GET['q'] ?? ''));

$rows = array_values(array_filter($data, function(array $r) use ($status,$discovery,$tag,$q): bool {
    if ($status !== 'all' && ($r['candidate_status'] ?? '') !== $status) return false;
    if ($discovery !== 'all' && ($r['discovery_status'] ?? '既知') !== $discovery) return false;
    if ($tag !== 'all' && !in_array($tag, $r['tags'] ?? [], true)) return false;
    if ($q !== '') {
        $hay = mb_strtolower(($r['title'] ?? '').' '.($r['channel_title'] ?? '').' '.($r['description'] ?? ''), 'UTF-8');
        if (mb_strpos($hay, mb_strtolower($q,'UTF-8')) === false) return false;
    }
    return true;
}));

$counts = ['採用候補'=>0,'要確認'=>0,'除外候補'=>0];
$discoveryCounts = ['新規'=>0,'既知'=>0];
foreach ($data as $r) { $s=$r['candidate_status'] ?? '要確認'; if(isset($counts[$s])) $counts[$s]++; $d=$r['discovery_status'] ?? '既知'; if(isset($discoveryCounts[$d])) $discoveryCounts[$d]++; }
$allTags = ['道路','雪道','駅','繁華街','観光','河川'];
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LiveCameraLinks YouTube候補</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f6f7f9;color:#1d1d1f;margin:0}.wrap{max-width:1200px;margin:auto;padding:28px 18px 60px}h1{margin:0 0 8px}.meta{color:#666;margin-bottom:22px}.stats{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}.stat{background:#fff;border:1px solid #ddd;border-radius:10px;padding:10px 14px}.filters{display:flex;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #ddd;border-radius:12px;padding:14px;margin:18px 0}.filters input,.filters select{padding:9px 10px;border:1px solid #bbb;border-radius:8px}.filters button,.run{padding:9px 14px;border:0;border-radius:8px;background:#111;color:white;text-decoration:none;cursor:pointer}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px}.card{background:#fff;border:1px solid #ddd;border-radius:14px;overflow:hidden}.thumb{width:100%;aspect-ratio:16/9;object-fit:cover;background:#eee}.body{padding:14px}.title{font-size:17px;font-weight:700;line-height:1.4;margin:0 0 8px}.channel{color:#666;font-size:14px;margin-bottom:8px}.badges{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0}.badge{font-size:12px;padding:4px 7px;border-radius:999px;background:#eef1f4}.status{font-weight:700}.採用候補{color:#087f23}.要確認{color:#9a6700}.除外候補{color:#b42318}.score{font-size:13px;color:#555}.desc{font-size:13px;color:#666;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.actions a{padding:8px 10px;border-radius:8px;text-decoration:none;background:#f0f2f5;color:#111}.empty{background:#fff;padding:30px;border-radius:12px;text-align:center}.topline{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
</style>
</head>
<body><div class="wrap">
<div class="topline"><div><h1>YouTube LIVE候補</h1><div class="meta">最新収集: <?=h(basename($latest))?> / 表示 <?=count($rows)?>件</div></div><a class="run" href="collector.php">再収集する</a></div>
<div class="stats"><div class="stat">全件 <strong><?=count($data)?></strong></div><div class="stat">新規 <strong><?=$discoveryCounts['新規']?></strong></div><div class="stat">既知 <strong><?=$discoveryCounts['既知']?></strong></div><div class="stat">採用候補 <strong><?=$counts['採用候補']?></strong></div><div class="stat">要確認 <strong><?=$counts['要確認']?></strong></div><div class="stat">除外候補 <strong><?=$counts['除外候補']?></strong></div></div>
<form class="filters" method="get">
<input type="search" name="q" value="<?=h($q)?>" placeholder="タイトル・チャンネル検索">
<select name="discovery"><option value="all">新規・既知</option><?php foreach(['新規','既知'] as $d):?><option value="<?=h($d)?>" <?=$discovery===$d?'selected':''?>><?=h($d)?></option><?php endforeach;?></select>
<select name="status"><option value="all">全ステータス</option><?php foreach(['採用候補','要確認','除外候補'] as $s):?><option value="<?=h($s)?>" <?=$status===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select>
<select name="tag"><option value="all">全カテゴリ</option><?php foreach($allTags as $t):?><option value="<?=h($t)?>" <?=$tag===$t?'selected':''?>><?=h($t)?></option><?php endforeach;?></select>
<button type="submit">絞り込む</button><a href="viewer.php" style="padding:9px 8px">リセット</a>
</form>
<?php if(!$rows):?><div class="empty">条件に一致する候補はありません。</div><?php else:?><div class="grid">
<?php foreach($rows as $r): $s=(string)($r['candidate_status']??'要確認'); ?>
<article class="card">
<?php if(!empty($r['thumbnail'])):?><img class="thumb" src="<?=h((string)$r['thumbnail'])?>" alt="" loading="lazy"><?php endif;?>
<div class="body">
<div class="status <?=h($s)?>"><?=h($s)?><?php if(($r['discovery_status']??'既知')==='新規'):?> <span class="badge">NEW</span><?php endif;?></div>
<div class="score">スコア <?=h((string)($r['candidate_score']??0))?></div>
<h2 class="title"><?=h((string)($r['title']??''))?></h2>
<div class="channel"><?=h((string)($r['channel_title']??''))?></div>
<div class="badges"><?php foreach(($r['tags']??[]) as $t):?><span class="badge"><?=h((string)$t)?></span><?php endforeach;?><?php if(empty($r['tags'])):?><span class="badge">未分類</span><?php endif;?></div>
<div class="desc"><?=h((string)($r['description']??''))?></div>
<div class="actions">
<a href="<?=h(channelUrl($r))?>" target="_blank" rel="noopener">🏢 発信元</a>
<a href="<?=h((string)($r['url']??'#'))?>" target="_blank" rel="noopener">▶ 映像ビュー</a>
<a href="<?=h(mapUrl($r))?>" target="_blank" rel="noopener">地図 ↗</a>
<span class="badge">検索: <?=h((string)($r['matched_query']??''))?></span>
</div>
</div></article>
<?php endforeach;?></div><?php endif;?>
</div></body></html>
