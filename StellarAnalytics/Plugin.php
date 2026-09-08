<?php

namespace TypechoPlugin\StellarAnalytics;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * StellarAnalytics —— 站点访问统计插件
 * 记录访问（PV/UV/IP 哈希/来源/UA/爬虫分离）、文章阅读时长、站内搜索词；后台独立报表页。
 * 数据存站点 SQLite（IP 只存 SHA-256 前 24 位脱敏），保留 90 天自动清理。
 *
 * @package StellarAnalytics
 * @author 咔咔
 * @version 1.0.0
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        self::installTable();
        \Typecho\Plugin::factory('Widget_Archive')->footer = __CLASS__ . '::renderFooter';
        \Typecho\Plugin::factory('admin/footer.php')->end = __CLASS__ . '::adminFooter';
        return 'StellarAnalytics 已启用：前台自动埋点，后台顶栏出现「访问统计」入口';
    }

    public static function deactivate()
    {
    }

    public static function adminFooter(): void
    {
        $panel = rtrim(\Typecho\Widget::widget('Widget_Options')->siteUrl, '/') . '/usr/plugins/StellarAnalytics/panel.php';
        echo <<<JS
<script>
(function () {
    if (document.getElementById('sa-an-btn')) return;
    var b = document.createElement('a');
    b.id = 'sa-an-btn';
    b.href = '{$panel}';
    b.title = '访问统计';
    b.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;background:linear-gradient(135deg,#0ea5e9,#2563eb);color:#fff;font-size:13px;font-weight:600;text-decoration:none;margin-right:8px;';
    b.innerHTML = '📊 访问统计';
    var bar = document.querySelector('.sa-topbar-right') || document.body;
    bar.insertBefore(b, bar.firstChild);
})();
</script>
JS;
    }

    public static function config(Form $form)
    {
        $on = new \Typecho\Widget\Helper\Form\Element\Radio(
            'sa_enable', ['1' => '开启', '0' => '关闭'], '1',
            _t('访问记录'), _t('是否记录访问、阅读时长与站内搜索词（关闭后停止埋点）')
        );
        $form->addInput($on);
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function opt($key, $default = '')
    {
        try {
            $cfg = \Typecho\Widget::widget('Widget_Options')->plugin('StellarAnalytics');
        } catch (\Throwable $e) {
            return $default;
        }
        $v = isset($cfg->{$key}) ? $cfg->{$key} : null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function installTable()
    {
        try {
            $db = \Typecho\Db::get();
            $p = $db->getPrefix();
            $sqlite = $db->getAdapterName() === 'Pdo_SQLite';
            $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
            $db->query("CREATE TABLE IF NOT EXISTS {$p}sa_visits (
                id {$pk}, ip_hash VARCHAR(64), url TEXT, cid INT DEFAULT 0,
                referer TEXT, ua TEXT, is_bot INT DEFAULT 0, created INT)");
            $db->query("CREATE TABLE IF NOT EXISTS {$p}sa_reads (
                id {$pk}, cid INT, ip_hash VARCHAR(64), seconds INT, created INT)");
            $db->query("CREATE TABLE IF NOT EXISTS {$p}sa_searches (
                id {$pk}, keyword TEXT, created INT)");
            if (!$sqlite) {
                foreach (['sa_visits' => 'created', 'sa_reads' => 'cid'] as $t => $c) {
                    try {
                        $db->query("CREATE INDEX {$p}{$t}_{$c} ON {$p}{$t} ({$c})");
                    } catch (\Throwable $e) {
                    }
                }
            }
            /* 兼容旧版本表结构：补齐缺失列（已存在则报错忽略） */
            foreach (['cid' => 'INT DEFAULT 0'] as $col => $def) {
                try {
                    $db->query("ALTER TABLE {$p}sa_visits ADD COLUMN {$col} {$def}");
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /* 前台 footer 埋点（heredoc 插值，JS 原样输出） */
    public static function renderFooter()
    {
        if (self::opt('sa_enable', '1') !== '1') {
            return;
        }
        $track = rtrim(\Typecho\Widget::widget('Widget_Options')->siteUrl, '/') . '/usr/plugins/StellarAnalytics/track.php';
        echo <<<JS
<script>
(function () {
    var T = '{$track}';
    function post(o) { try { navigator.sendBeacon(T + '?' + o); } catch (e) { try { var x = new XMLHttpRequest(); x.open('POST', T + '?' + o); x.send(); } catch (_) {} } }
    var p = location.pathname;
    var cid = 0, m = p.match(/archives\/([0-9]+)/); if (m) cid = parseInt(m[1], 10);
    post('action=pv&url=' + encodeURIComponent(p) + '&cid=' + cid);
    var acc = 0, start = Date.now(), sent = false;
    function flush() {
        var s = acc + (document.hidden ? 0 : (Date.now() - start) / 1000);
        if (s < 5 || sent) return;
        sent = true;
        post('action=read&cid=' + cid + '&t=' + Math.round(s));
    }
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { acc += (Date.now() - start) / 1000; }
        start = Date.now();
    });
    setInterval(function () {
        if (document.hidden) return;
        acc += (Date.now() - start) / 1000; start = Date.now();
        if (acc >= 5) post('action=read&cid=' + cid + '&t=' + Math.round(acc));
    }, 15000);
    window.addEventListener('beforeunload', function () {
        if (!document.hidden) acc += (Date.now() - start) / 1000;
        flush();
    });
    var qm = p.match(/^\/search\/([^\/]+)/);
    if (qm) post('action=search&q=' + encodeURIComponent(decodeURIComponent(qm[1])));
})();
</script>
JS;
    }

    /* ===== 服务端记录 ===== */
    public static function clientIp(): string
    {
        foreach (['X-Forwarded-For', 'X-Real-Ip', 'Client-Ip'] as $h) {
            $v = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $h))] ?? '';
            if ($v) {
                $ip = trim(explode(',', $v)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function hashIp($ip): string
    {
        return substr(hash('sha256', $ip), 0, 24);
    }

    public static function isBot($ua): bool
    {
        return (bool) preg_match('/bot|spider|crawl|baiduspider|googlebot|bingbot|slurp|sogou|yandex|bytespider|petalbot/i', (string) $ua);
    }

    public static function recordVisit($url, $cid, $referer, $ua)
    {
        try {
            $db = \Typecho\Db::get();
            $ip = self::hashIp(self::clientIp());
            $last = $db->fetchRow($db->select('created')->from('table.sa_visits')
                ->where('ip_hash = ? AND url = ?', $ip, $url)->order('id', \Typecho\Db::SORT_DESC)->limit(1));
            if ($last && time() - (int) $last['created'] < 60) {
                return;
            }
            $host = parse_url((string) $referer, PHP_URL_HOST);
            $db->query($db->insert('table.sa_visits')->rows([
                'ip_hash' => $ip, 'url' => $url, 'cid' => (int) $cid,
                'referer' => $host ?: '(直接访问)', 'ua' => (string) $ua,
                'is_bot' => self::isBot($ua) ? 1 : 0, 'created' => time(),
            ]));
            self::maybeClean();
        } catch (\Throwable $e) {
        }
    }

    public static function recordRead($cid, $seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds < 5) {
            return;
        }
        try {
            $db = \Typecho\Db::get();
            $db->query($db->insert('table.sa_reads')->rows([
                'cid' => (int) $cid, 'ip_hash' => self::hashIp(self::clientIp()), 'seconds' => $seconds, 'created' => time(),
            ]));
        } catch (\Throwable $e) {
        }
    }

    public static function recordSearch($q)
    {
        $q = trim((string) $q);
        if ($q === '') {
            return;
        }
        try {
            $db = \Typecho\Db::get();
            $db->query($db->insert('table.sa_searches')->rows(['keyword' => $q, 'created' => time()]));
        } catch (\Throwable $e) {
        }
    }

    public static function maybeClean()
    {
        if (mt_rand(1, 100) !== 1) {
            return;
        }
        try {
            $db = \Typecho\Db::get();
            $t = time() - 90 * 86400;
            foreach (['sa_visits', 'sa_reads', 'sa_searches'] as $tb) {
                $db->query($db->delete('table.' . $tb)->where('created < ?', $t));
            }
        } catch (\Throwable $e) {
        }
    }

    /* ===== 管理员鉴权 ===== */
    public static function detectCookiePrefix(): void
    {
        try {
            foreach ($_COOKIE as $k => $v) {
                if (substr($k, -13) === '__typecho_uid') {
                    $ref = new \ReflectionProperty(\Typecho\Cookie::class, 'prefix');
                    $ref->setAccessible(true);
                    $ref->setValue(null, substr($k, 0, -13));
                    return;
                }
            }
        } catch (\Throwable $e) {
        }
        \Typecho\Cookie::setPrefix(\Typecho\Widget::widget('Widget_Options')->rootUrl);
    }

    public static function manualLogin(): bool
    {
        $uid = $auth = null;
        foreach ($_COOKIE as $k => $v) {
            if (substr($k, -13) === '__typecho_uid') {
                $uid = $v;
            } elseif (substr($k, -18) === '__typecho_authCode') {
                $auth = $v;
            }
        }
        if ($uid === null || $auth === null) {
            return false;
        }
        try {
            $db = \Typecho\Db::get();
            $user = $db->fetchRow($db->select('uid, authCode, group')->from('table.users')->where('uid = ?', (int) $uid));
            if ($user && $user['group'] === 'administrator'
                && \Typecho\Common::hashValidate((string) $user['authCode'], (string) $auth)) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        return false;
    }

    public static function requireAdmin(): bool
    {
        self::detectCookiePrefix();
        $user = \Typecho\Widget::widget('Widget_User');
        if ($user->hasLogin() && $user->pass('administrator', true)) {
            return true;
        }
        return self::manualLogin();
    }
}
