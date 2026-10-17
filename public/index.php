<?php
$__t0 = microtime(true);
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/routes.php';

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$path = '/' . ltrim(rawurldecode($path), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- baseline security headers on every response
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (is_https()) header('Strict-Transport-Security: max-age=31536000');

// --- first-party analytics (runs after the response is sent)
register_shutdown_function('track_pageview', $__t0);

// --- admin area: separate bootstrap, never cached, never indexed
if (preg_match('~^/admin(/|$)~i', $path)) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    if (strtolower($path) !== $path || substr($path, -1) !== '/') {
        if ($method === 'GET') { header('Location: ' . path_norm(strtolower($path)) . (($qs = $_SERVER['QUERY_STRING'] ?? '') ? '?' . $qs : ''), true, 301); exit; }
    }
    require APP_DIR . '/admin/bootstrap.php';
    try {
        admin_dispatch(strtolower($path));
    } catch (Throwable $e) {
        http_response_code(500);
        log_msg('error', 'admin: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        echo cfg('debug') ? '<pre>' . e((string)$e) . '</pre>' : 'Admin error — see storage/logs/error.log';
    }
    exit;
}

// --- managed redirects (SEO Center). Served from a JSON file so cached pages stay DB-free.
if ($method === 'GET' || $method === 'HEAD') {
    $map = redirect_map();
    if ($map) {
        $lp = strtolower($path);
        $hit = $map[$lp] ?? ($map[rtrim($lp, '/')] ?? ($map[rtrim($lp, '/') . '/'] ?? null));
        if ($hit) {
            try { q('UPDATE redirects SET hits = hits + 1 WHERE id = ?', [$hit['id']]); } catch (Throwable $e) {}
            if ((int)$hit['code'] === 410) { http_response_code(410); header('Content-Type: text/html; charset=utf-8'); echo '<h1>410 Gone</h1><p>This page has been permanently removed.</p>'; exit; }
            header('Location: ' . $hit['target'], true, (int)$hit['code']);
            exit;
        }
    }
}

// --- canonical URL hygiene: lowercase + trailing slash (301), drop index.php
$norm = strtolower(preg_replace('~/index\.php$~', '/', $path));
$norm = path_norm($norm);
if ($norm !== $path && $method === 'GET' && strpos($path, '/api/') !== 0) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . $norm . ($qs ? '?' . $qs : ''), true, 301);
    exit;
}

// --- full-page cache (anonymous public GETs only)
$private = (bool)preg_match('~^/(login|register|logout|account|verify|forgot|reset|go)(/|$)|^/api/~', $path) || $path === '/search/';
$cacheable = $method === 'GET' && cfg('cache_enabled') && !$private && (empty($_GET) || array_keys($_GET) === ['page']);
$cacheFile = STORAGE . '/cache/' . md5($path . '?' . ($_GET['page'] ?? '')) . '.html';
if ($cacheable && is_file($cacheFile) && filemtime($cacheFile) > time() - cfg('cache_ttl', 60)) {
    $meta = json_decode((string)@file_get_contents($cacheFile . '.meta'), true) ?: [];
    if (!empty($meta['type'])) header('Content-Type: ' . $meta['type']);
    header('X-Cache: HIT');
    header('Cache-Control: public, max-age=' . cfg('cache_ttl', 60));
    readfile($cacheFile);
    exit;
}

ob_start();
try {
    dispatch($path);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    log_msg('error', $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    echo cfg('debug') ? '<pre>' . e((string)$e) . '</pre>' : '<h1>Temporary error</h1><p>Please try again shortly.</p>';
}
$out = ob_get_clean();

$code = http_response_code();
if ($cacheable && $code === 200) {
    @file_put_contents($cacheFile, $out, LOCK_EX);
    $type = '';
    foreach (headers_list() as $h) if (stripos($h, 'Content-Type:') === 0) $type = trim(substr($h, 13));
    @file_put_contents($cacheFile . '.meta', json_encode(['type' => $type]));
}
if (!headers_sent()) {
    if ($private) header('Cache-Control: private, no-store');
    elseif ($code === 200) header('Cache-Control: public, max-age=' . cfg('cache_ttl', 60));
}
echo $out;

// ---------------------------------------------------------------------------
function redirect_map(): array
{
    static $m = null;
    if ($m !== null) return $m;
    $f = STORAGE . '/cache/redirects.json';
    if (!is_file($f)) { if (!redirect_rebuild()) return $m = []; }
    return $m = (json_decode((string)@file_get_contents($f), true) ?: []);
}

function redirect_rebuild(): bool
{
    try {
        $m = [];
        foreach (rows('SELECT id, source, target, code FROM redirects WHERE enabled = 1') as $r) $m[strtolower($r['source'])] = ['id' => (int)$r['id'], 'target' => $r['target'], 'code' => (int)$r['code']];
        file_put_contents(STORAGE . '/cache/redirects.json', json_encode($m), LOCK_EX);
        return true;
    } catch (Throwable $e) { return false; }
}
