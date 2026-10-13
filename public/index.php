<?php
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/routes.php';

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$path = '/' . ltrim(rawurldecode($path), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- canonical URL hygiene: lowercase + trailing slash (301), drop index.php
$norm = strtolower(preg_replace('~/index\.php$~', '/', $path));
$norm = path_norm($norm);
if ($norm !== $path && $method === 'GET' && strpos($path, '/api/') !== 0) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . $norm . ($qs ? '?' . $qs : ''), true, 301);
    exit;
}

// --- full-page cache (only for anonymous GETs with no/simple query)
$cacheable = $method === 'GET' && cfg('cache_enabled') && strpos($path, '/api/') !== 0
    && (empty($_GET) || array_keys($_GET) === ['page']);
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
if ($code === 200 && !headers_sent()) header('Cache-Control: public, max-age=' . cfg('cache_ttl', 60));
echo $out;
