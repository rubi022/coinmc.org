<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);
define('STORAGE', APP_ROOT . '/storage');

$GLOBALS['config'] = require APP_DIR . '/config.php';
date_default_timezone_set(cfg('timezone', 'UTC'));
mb_internal_encoding('UTF-8');

if (cfg('debug')) { ini_set('display_errors', '1'); error_reporting(E_ALL); }
else { ini_set('display_errors', '0'); }

foreach (['cache', 'logs'] as $d) {
    if (!is_dir(STORAGE . "/$d")) @mkdir(STORAGE . "/$d", 0775, true);
}

require APP_DIR . '/lib/db.php';
require APP_DIR . '/lib/helpers.php';
require APP_DIR . '/lib/seo.php';
require APP_DIR . '/lib/repo.php';
require APP_DIR . '/lib/settings.php';
require APP_DIR . '/lib/auth.php';
require APP_DIR . '/lib/rbac.php';
require APP_DIR . '/lib/totp.php';
require APP_DIR . '/lib/mail.php';
require APP_DIR . '/lib/analytics.php';
require APP_DIR . '/lib/ads.php';

// PHP 7.x compatibility
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n === '' || strpos($h, $n) === 0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n === '' || substr($h, -strlen($n)) === $n; } }
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; } }

function cfg(string $key, $default = null)
{
    $v = $GLOBALS['config'];
    foreach (explode('.', $key) as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) return $default;
        $v = $v[$p];
    }
    return $v;
}
