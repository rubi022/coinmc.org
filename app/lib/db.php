<?php

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function row(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r ?: null; }
function val(string $sql, array $params = []) { $r = q($sql, $params)->fetchColumn(); return $r === false ? null : $r; }

function kv_get(string $k, $default = null)
{
    $v = val('SELECT v FROM kv WHERE k = ?', [$k]);
    return $v === null ? $default : $v;
}

function kv_set(string $k, $v): void
{
    q('INSERT INTO kv (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, (string)$v]);
}

/** Bulk upsert helper: $rows = list of assoc arrays with identical keys. */
function upsert(string $table, array $rowsIn, array $updateCols, int $chunk = 200): int
{
    if (!$rowsIn) return 0;
    $cols = array_keys($rowsIn[0]);
    $colSql = '`' . implode('`,`', $cols) . '`';
    $upd = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $updateCols));
    $n = 0;
    foreach (array_chunk($rowsIn, $chunk) as $part) {
        $ph = []; $params = [];
        foreach ($part as $r) {
            $ph[] = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            foreach ($cols as $c) $params[] = $r[$c] ?? null;
        }
        $sql = "INSERT INTO `$table` ($colSql) VALUES " . implode(',', $ph) . ($upd ? " ON DUPLICATE KEY UPDATE $upd" : '');
        $n += q($sql, $params)->rowCount();
    }
    return $n;
}
