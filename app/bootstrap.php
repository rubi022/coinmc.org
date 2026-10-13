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

function cfg(string $key, $default = null)
{
    $v = $GLOBALS['config'];
    foreach (explode('.', $key) as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) return $default;
        $v = $v[$p];
    }
    return $v;
}
