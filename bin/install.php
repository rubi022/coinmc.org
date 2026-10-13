<?php
/**
 * Creates the database (if missing) and applies database/schema.sql.
 *   php bin/install.php
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$config = require __DIR__ . '/../app/config.php';
$c = $config['db'];
$pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$c['name']}`");
$sql = file_get_contents(__DIR__ . '/../database/schema.sql');
foreach (array_filter(array_map('trim', preg_split('~;\s*$~m', $sql))) as $stmt) {
    if (preg_match('~^(--[^\n]*\n?)*$~', $stmt)) continue;
    $pdo->exec($stmt);
}
@mkdir(__DIR__ . '/../storage/cache', 0775, true);
@mkdir(__DIR__ . '/../storage/logs', 0775, true);
echo "Database `{$c['name']}` ready.\n";
echo "Next: php cron/run.php all   (pulls live data — takes a few minutes)\n";
echo "Then: php -S localhost:8000 -t public public/router.php\n";
