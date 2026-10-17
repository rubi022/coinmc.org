<?php
/**
 * Creates the database (if missing) and applies / upgrades the schema. Safe to re-run after updates.
 *   php bin/install.php
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/lib/migrate.php';
$config = require APP_ROOT . '/app/config.php';
$c = $config['db'];
$pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (migrate_run($pdo, $c['name']) as $line) echo "  $line\n";
@mkdir(APP_ROOT . '/storage/cache', 0775, true);
@mkdir(APP_ROOT . '/storage/logs', 0775, true);
echo "Database `{$c['name']}` ready.\n\n";
echo "Create your first admin:   php bin/create_admin.php you@example.com 'a-long-password'\n";
echo "Pull live data:            php cron/run.php all\n";
echo "Run locally:               php -S localhost:8000 -t public public/router.php   (admin at /admin/)\n";
