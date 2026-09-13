<?php
/**
 * StellarAnalytics 访问统计报表（独立页面）
 */
require dirname(__DIR__, 3) . '/config.inc.php';
require_once __DIR__ . '/Plugin.php';

$options = \Typecho\Widget::widget('Widget_Options');
$bp = '\TypechoPlugin\StellarAnalytics\Plugin';
if (!$bp::requireAdmin()) {
    header('Location: ' . rtrim($options->siteUrl, '/') . '/admin/login.php');
    exit;
}

$db = \Typecho\Db::get();
$todayStart = strtotime('today');
$yesterdayStart = $todayStart - 86400;

/* 计数辅助 */
function cnt($db, $sql)
{
    try {
        $r = $db->fetchRow($sql);
        return (int) ($r['c'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

/* 概览 */
$todayPv = cnt($db, $db->select(['COUNT(id)' => 'c'])->from('table.sa_visits')->where('created >= ?', $todayStart));
$todayUv = cnt($db, $db->select(['COUNT(DISTINCT ip_hash)' => 'c'])->from('table.sa_visits')->where('created >= ?', $todayStart));
$yestPv = cnt($db, $db->select(['COUNT(id)' => 'c'])->from('table.sa_visits')->where('created >= ? AND created < ?', $yesterdayStart, $todayStart));
$yestUv = cnt($db, $db->select(['COUNT(DISTINCT ip_hash)' => 'c'])->from('table.sa_visits')->where('created >= ? AND created < ?', $yesterdayStart, $todayStart));
$totalPv = cnt($db, $db->select(['COUNT(id)' => 'c'])->from('table.sa_visits'));
$totalUv = cnt($db, $db->select(['COUNT(DISTINCT ip_hash)' => 'c'])->from('table.sa_visits'));
$botPv = cnt($db, $db->select(['COUNT(id)' => 'c'])->from('table.sa_visits')->where('is_bot = ?', 1));
$readSec = cnt($db, $db->select(['SUM(seconds)' => 'c'])->from('table.sa_reads'));

/* 近 14 天趋势 */
$trend = [];
try {
    $rows = $db->fetchAll($db->select('created', 'ip_hash')->from('table.sa_visits')
        ->where('created >= ?', $todayStart - 13 * 86400));
    $buckets = [];
    foreach ($rows as $r) {
        $d = date('m-d', (int) $r['created']);
        if (!isset($buckets[$d])) $buckets[$d] = ['pv' => 0, 'ips' => []];
        $buckets[$d]['pv']++;
        $buckets[$d]['ips'][$r['ip_hash']] = true;
    }
    for ($i = 13; $i >= 0; $i--) {
        $d = date('m-d', $todayStart - $i * 86400);
        $trend[$d] = isset($buckets[$d]) ? ['pv' => $buckets[$d]['pv'], 'uv' => count($buckets[$d]['ips'])] : ['pv' => 0, 'uv' => 0];
    }
} catch (\Throwable $e) {
    $trend = [];
}
$trendMax = 1;
foreach ($trend as $t) {
    $trendMax = max($trendMax, $t['pv']);
}

/* Top 文章 */
$topPosts = [];
try {
    $topPosts = $db->fetchAll($db->select('table.sa_visits.cid', ['COUNT(table.sa_visits.id)' => 'c'], 'table.contents.title')
        ->from('table.sa_visits')
        ->join('table.contents', 'table.sa_visits.cid = table.contents.cid', \Typecho\Db::LEFT_JOIN)
        ->where('table.sa_visits.cid > 0 AND table.sa_visits.is_bot = 0')
        ->group('table.sa_visits.cid')->order('c', \Typecho\Db::SORT_DESC)->limit(10));
} catch (\Throwable $e) {
    $topPosts = [];
}

/* Top 来源 */
$topRefs = [];
try {
    $topRefs = $db->fetchAll($db->select('referer', ['COUNT(id)' => 'c'])->from('table.sa_visits')
        ->where('is_bot = ?', 0)->group('referer')->order('c', \Typecho\Db::SORT_DESC)->limit(10));
} catch (\Throwable $e) {
    $topRefs = [];
}

/* 爬虫分类（近 30 天） */
$bots = ['baiduspider' => 0, 'googlebot' => 0, 'bingbot' => 0, '其他爬虫' => 0];
try {
    $botRows = $db->fetchAll($db->select('ua')->from('table.sa_visits')
        ->where('is_bot = ? AND created >= ?', 1, $todayStart - 30 * 86400));
    foreach ($botRows as $r) {
        $u = strtolower((string) $r['ua']);
        if (strpos($u, 'baiduspider') !== false) $bots['baiduspider']++;
        elseif (strpos($u, 'googlebot') !== false) $bots['googlebot']++;
        elseif (strpos($u, 'bingbot') !== false) $bots['bingbot']++;
        else $bots['其他爬虫']++;
    }
} catch (\Throwable $e) {
}

/* 搜索词 Top */
$topSearch = [];
try {
    $topSearch = $db->fetchAll($db->select('keyword', ['COUNT(id)' => 'c'])->from('table.sa_searches')
        ->group('keyword')->order('c', \Typecho\Db::SORT_DESC)->limit(10));
} catch (\Throwable $e) {
    $topSearch = [];
}

/* 最近访问明细 */
$recent = [];
try {
    $recent = $db->fetchAll($db->select('ip_hash', 'ip', 'region', 'url', 'referer', 'ua', 'is_bot', 'created')
        ->from('table.sa_visits')->order('id', \Typecho\Db::SORT_DESC)->limit(50));
} catch (\Throwable $e) {
    $recent = [];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>访问统计 - <?php $options->title(); ?></title>
<style>
:root { --ink:#1c2030; --ink2:#5b6272; --line:#e6e8f0; --accent:#0ea5e9; --bg:#f6f7fb; --card:#fff; --ok:#30a46c; --err:#e5484d; }
* { box-sizing:border-box; }
body { margin:0; font-family:-apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif; background:var(--bg); color:var(--ink); font-size:15px; line-height:1.6; }
.wrap { max-width:960px; margin:0 auto; padding:28px 20px 60px; }
h1 { font-size:22px; margin:0 0 4px; }
.sub { color:var(--ink2); font-size:13px; margin:0 0 20px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:16px; }
.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:12px; }
.metric { background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:14px; }
.metric b { display:block; font-size:20px; color:var(--accent); }
.metric span { color:var(--ink2); font-size:12px; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th,td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); }
th { color:var(--ink2); font-weight:600; }
.url-cell { word-break:break-all; }
.bot { color:var(--err); }
.human { color:var(--ok); }
.chart { display:flex; gap:6px; align-items:flex-end; height:110px; margin-top:12px; }
.chart .bar { flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; height:100%; justify-content:flex-end; }
.chart .bar i { width:100%; background:linear-gradient(180deg,#0ea5e9,#2563eb); border-radius:4px 4px 0 0; display:block; }
.chart .bar span { font-size:10px; color:var(--ink2); }
.chart .bar b { font-size:11px; color:var(--ink); }
a.back { display:inline-block; margin-bottom:16px; color:var(--accent); font-size:14px; text-decoration:none; }
.hint { color:var(--ink2); font-size:12px; margin-top:8px; }
@media (max-width:640px){ .wrap{padding:20px 12px 50px;} }
</style>
</head>
<body>
<div class="wrap">
<a class="back" href="<?php echo rtrim($options->siteUrl, '/') . '/admin/'; ?>">← 返回后台</a>
<h1>📊 访问统计</h1>
<p class="sub">记录访客访问（IP 脱敏）、文章阅读时长、站内搜索词；爬虫与真人流量分离。</p>

<div class="card">
  <b>概览</b>
  <div class="grid" style="margin-top:12px;">
    <div class="metric"><b><?php echo $todayPv; ?></b><span>今日 PV</span></div>
    <div class="metric"><b><?php echo $todayUv; ?></b><span>今日 UV</span></div>
    <div class="metric"><b><?php echo $yestPv; ?></b><span>昨日 PV</span></div>
    <div class="metric"><b><?php echo $yestUv; ?></b><span>昨日 UV</span></div>
    <div class="metric"><b><?php echo $totalPv; ?></b><span>累计 PV</span></div>
    <div class="metric"><b><?php echo $totalUv; ?></b><span>累计 UV</span></div>
    <div class="metric"><b><?php echo $botPv; ?></b><span>爬虫访问</span></div>
    <div class="metric"><b><?php echo round($readSec / 3600, 1); ?></b><span>累计阅读(时)</span></div>
  </div>
</div>

<div class="card">
  <b>近 14 天 PV / UV 趋势</b>
  <div class="chart">
    <?php foreach ($trend as $d => $t): ?>
      <div class="bar" title="<?php echo $d; ?>：PV <?php echo $t['pv']; ?> / UV <?php echo $t['uv']; ?>">
        <i style="height:<?php echo max(4, round($t['pv'] / $trendMax * 100)); ?>%;"></i>
        <span><?php echo $d; ?></span>
        <b><?php echo $t['pv']; ?></b>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <b>Top 文章（按访问）</b>
  <table style="margin-top:10px;">
    <tr><th>#</th><th>标题</th><th>访问</th></tr>
    <?php if (empty($topPosts)): ?>
      <tr><td colspan="3" style="color:var(--ink2);">暂无数据</td></tr>
    <?php else: foreach ($topPosts as $i => $p): ?>
      <tr><td><?php echo $i + 1; ?></td><td class="url-cell"><?php echo htmlspecialchars($p['title'] ?: ('文章 #' . $p['cid'])); ?></td><td><?php echo (int) $p['c']; ?></td></tr>
    <?php endforeach; endif; ?>
  </table>
</div>

<div class="card">
  <b>爬虫流量（近 30 天）</b>
  <div class="grid" style="margin-top:12px;">
    <div class="metric"><b><?php echo $bots['baiduspider']; ?></b><span>百度蜘蛛</span></div>
    <div class="metric"><b><?php echo $bots['googlebot']; ?></b><span>Googlebot</span></div>
    <div class="metric"><b><?php echo $bots['bingbot']; ?></b><span>Bingbot</span></div>
    <div class="metric"><b><?php echo $bots['其他爬虫']; ?></b><span>其他爬虫</span></div>
  </div>
</div>

<div class="card">
  <b>Top 来源</b>
  <table style="margin-top:10px;">
    <tr><th>#</th><th>来源域名</th><th>次数</th></tr>
    <?php if (empty($topRefs)): ?>
      <tr><td colspan="3" style="color:var(--ink2);">暂无数据</td></tr>
    <?php else: foreach ($topRefs as $i => $r): ?>
      <tr><td><?php echo $i + 1; ?></td><td class="url-cell"><?php echo htmlspecialchars($r['referer']); ?></td><td><?php echo (int) $r['c']; ?></td></tr>
    <?php endforeach; endif; ?>
  </table>
</div>

<div class="card">
  <b>站内搜索词 Top</b>
  <table style="margin-top:10px;">
    <tr><th>#</th><th>关键词</th><th>次数</th></tr>
    <?php if (empty($topSearch)): ?>
      <tr><td colspan="3" style="color:var(--ink2);">暂无数据</td></tr>
    <?php else: foreach ($topSearch as $i => $s): ?>
      <tr><td><?php echo $i + 1; ?></td><td class="url-cell"><?php echo htmlspecialchars($s['keyword']); ?></td><td><?php echo (int) $s['c']; ?></td></tr>
    <?php endforeach; endif; ?>
  </table>
</div>

<div class="card">
  <b>最近访问明细</b>
  <div style="margin-top:10px; overflow-x:auto;">
    <table>
      <tr><th>时间</th><th>IP 地址</th><th>归属地</th><th>页面</th><th>来源</th><th>类型</th></tr>
      <?php if (empty($recent)): ?>
        <tr><td colspan="6" style="color:var(--ink2);">暂无数据</td></tr>
      <?php else: foreach ($recent as $r): ?>
        <tr>
          <td style="white-space:nowrap;"><?php echo date('m-d H:i', (int) $r['created']); ?></td>
          <td style="white-space:nowrap;"><?php echo htmlspecialchars($r['ip'] ?: '—'); ?></td>
          <td style="white-space:nowrap;"><?php echo htmlspecialchars($r['region'] ?: '—'); ?></td>
          <td class="url-cell"><?php echo htmlspecialchars($r['url']); ?></td>
          <td class="url-cell"><?php echo htmlspecialchars($r['referer']); ?></td>
          <td class="<?php echo $r['is_bot'] ? 'bot' : 'human'; ?>"><?php echo $r['is_bot'] ? '爬虫' : '真人'; ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </table>
  </div>
  <p class="hint">归属地由 ip2region 离线库解析；数据保留 90 天自动清理。</p>
</div>

</div>
</body>
</html>
