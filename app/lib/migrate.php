<?php
/**
 * Idempotent schema management. Safe to run any number of times (install and upgrade).
 * Works on MySQL 5.7/8.x and MariaDB (no "ADD COLUMN IF NOT EXISTS" — checked via information_schema).
 */
function migrate_run(PDO $pdo, string $dbName): array
{
    $log = [];
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");

    foreach (['schema.sql', 'admin.sql'] as $file) {
        $sql = file_get_contents(APP_ROOT . '/database/' . $file);
        $n = 0;
        foreach (array_filter(array_map('trim', preg_split('~;\s*$~m', $sql))) as $stmt) {
            if (preg_match('~^(--[^\n]*\n?)*$~', $stmt)) continue;
            $pdo->exec($stmt);
            $n++;
        }
        $log[] = "$file: $n statements";
    }

    $add = [
        'coins' => [
            'status'         => "VARCHAR(12) NOT NULL DEFAULT 'active'",
            'verified'       => 'TINYINT NOT NULL DEFAULT 0',
            'risk_flag'      => "VARCHAR(12) NOT NULL DEFAULT 'none'",
            'listing_source' => "VARCHAR(10) NOT NULL DEFAULT 'sync'",
            'merged_into'    => 'INT UNSIGNED NULL',
            'admin_notes'    => 'TEXT NULL',
        ],
        'exchanges' => [
            'status'         => "VARCHAR(12) NOT NULL DEFAULT 'active'",
            'verified'       => 'TINYINT NOT NULL DEFAULT 0',
            'ex_type'        => "VARCHAR(12) NOT NULL DEFAULT 'CEX'",
            'listing_source' => "VARCHAR(10) NOT NULL DEFAULT 'sync'",
            'risk_note'      => 'VARCHAR(300) NULL',
        ],
    ];
    foreach ($add as $table => $cols) {
        foreach ($cols as $col => $ddl) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$dbName, $table, $col]);
            if (!(int)$st->fetchColumn()) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
                $log[] = "added $table.$col";
            }
        }
    }
    // column widenings for installs created by an earlier version of this file
    $modify = [['exchanges', 'ex_type', 'varchar(12)', "VARCHAR(12) NOT NULL DEFAULT 'CEX'"], ['ad_campaigns', 'spent', 'decimal(14,6)', 'DECIMAL(14,6) NOT NULL DEFAULT 0']];
    foreach ($modify as [$t, $col, $want, $ddl]) {
        $st = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$dbName, $t, $col]);
        $cur = strtolower((string)$st->fetchColumn());
        if ($cur !== '' && $cur !== $want) { $pdo->exec("ALTER TABLE `$t` MODIFY `$col` $ddl"); $log[] = "widened $t.$col ($cur → $want)"; }
    }
    foreach ([['coins', 'k_status', 'status'], ['exchanges', 'k_status', 'status']] as [$t, $idx, $col]) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute([$dbName, $t, $idx]);
        if (!(int)$st->fetchColumn()) { $pdo->exec("ALTER TABLE `$t` ADD INDEX `$idx` (`$col`)"); $log[] = "index $t.$idx"; }
    }
    return $log;
}
