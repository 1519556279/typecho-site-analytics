/* StellarAnalytics 前端采集：访问 / 阅读时长（可见计时+心跳+beacon）/ 站内搜索词 */
(function () {
    'use strict';
    var API = '/usr/plugins/StellarAnalytics/track.php';
    var sent = false;

    function post(o) {
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(API, new URLSearchParams(o));
                return;
            }
        } catch (e) { /* fallthrough */ }
        try {
            var x = new XMLHttpRequest();
            x.open('POST', API, true);
            x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            x.send(Object.keys(o).map(function (k) {
                return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]);
            }).join('&'));
        } catch (e) { /* 忽略上报失败 */ }
    }

    if (document.visibilityState === undefined) { return; }

    /* 访问（每会话一次） */
    if (!sessionStorage.getItem('sa_visited')) {
        sessionStorage.setItem('sa_visited', '1');
        post({ t: 'visit', u: location.pathname + location.search, r: document.referrer });
    }

    /* 站内搜索词（全站页面生效） */
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f && f.id === 'search-form') {
            var i = document.getElementById('search-input');
            if (i && i.value.trim()) { post({ t: 'search', q: i.value.trim() }); }
        }
    });

    /* 阅读时长：仅文章页（/archives/{cid}/） */
    var m = location.pathname.match(/\/archives\/(\d+)\//);
    if (!m) { return; }
    var cid = m[1];
    var sec = 0, acc = 0, last = Date.now(), vis = !document.hidden;

    function tick() {
        if (!vis) { return; }
        var now = Date.now();
        acc += now - last;
        last = now;
        if (acc >= 15000) {
            sec += 15;
            acc = 0;
            post({ t: 'read', cid: cid, s: sec });
        }
    }
    setInterval(tick, 10000);

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            var now = Date.now();
            if (vis) { sec += (now - last) / 1000; }
            vis = false;
        } else {
            last = Date.now();
            vis = true;
        }
    });

    window.addEventListener('pagehide', function () {
        var now = Date.now();
        if (vis) { sec += (now - last) / 1000; }
        if (sec >= 5) { post({ t: 'read', cid: cid, s: Math.round(sec) }); }
    });
})();