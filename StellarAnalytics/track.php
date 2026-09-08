<?php
/**
 * StellarAnalytics 埋点接口（前台 JS 调用，公开访问）
 * action: pv（访问）/ read（阅读时长）/ search（搜索词）
 */
require dirname(__DIR__, 3) . '/config.inc.php';
require_once __DIR__ . '/Plugin.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$p = '\TypechoPlugin\StellarAnalytics\Plugin';
if ($p::opt('sa_enable', '1') !== '1') {
    echo '{"ok":false}';
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
switch ($action) {
    case 'pv':
        $p::recordVisit(
            (string) ($_GET['url'] ?? ''),
            (int) ($_GET['cid'] ?? 0),
            (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        echo '{"ok":true}';
        break;
    case 'read':
        $p::recordRead((int) ($_GET['cid'] ?? 0), (int) ($_GET['t'] ?? 0));
        echo '{"ok":true}';
        break;
    case 'search':
        $p::recordSearch((string) ($_GET['q'] ?? ''));
        echo '{"ok":true}';
        break;
    default:
        echo '{"ok":false}';
}
