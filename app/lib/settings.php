<?php
/** Key/value settings stored in `kv` with an "s_" prefix, plus encrypted secrets and the audit trail. */

function setting(string $k, $default = null)
{
    if (!isset($GLOBALS['_settings'])) {
        $GLOBALS['_settings'] = [];
        try {
            foreach (rows("SELECT k, v FROM kv WHERE k LIKE 's\\_%'") as $r) $GLOBALS['_settings'][substr($r['k'], 2)] = $r['v'];
        } catch (Throwable $e) { /* DB unavailable: defaults */ }
    }
    $v = $GLOBALS['_settings'][$k] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function set_setting(string $k, $v): void
{
    kv_set('s_' . $k, (string)$v);
    unset($GLOBALS['_settings']);
}

function app_key(): string
{
    static $key = null;
    if ($key) return $key;
    $f = STORAGE . '/app.key';
    if (!is_file($f)) {
        file_put_contents($f, base64_encode(random_bytes(32)));
        @chmod($f, 0600);
    }
    return $key = (string)base64_decode((string)file_get_contents($f));
}

function secret_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function secret_decrypt(?string $s): ?string
{
    if (!$s || strpos($s, 'v1:') !== 0) return null;
    $raw = base64_decode(substr($s, 3));
    if ($raw === false || strlen($raw) < 29) return null;
    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $out === false ? null : $out;
}

function audit(string $action, string $entity = '', $entityId = null, $before = null, $after = null): void
{
    try {
        $u = PHP_SAPI === 'cli' ? null : current_user();
        if (is_array($before) && is_array($after)) {
            $b = []; $a = [];
            foreach ($after as $k => $v) {
                $old = $before[$k] ?? null;
                if ((string)$old !== (string)$v) { $b[$k] = $old; $a[$k] = $v; }
            }
            if (!$a) return; // nothing changed
            $before = $b; $after = $a;
        }
        $mask = function ($arr) {
            if (!is_array($arr)) return $arr;
            foreach ($arr as $k => $v) if (preg_match('~pass|secret|token|api_?key|service_account|totp~i', (string)$k)) $arr[$k] = '***';
            return $arr;
        };
        q('INSERT INTO audit_log (user_id, actor, action, entity, entity_id, before_json, after_json, ip) VALUES (?,?,?,?,?,?,?,?)', [
            $u['id'] ?? null, $u['email'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'system'), $action, $entity ?: null, $entityId !== null ? (string)$entityId : null,
            $before !== null ? json_encode($mask($before), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $after !== null ? json_encode($mask($after), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            PHP_SAPI === 'cli' ? null : client_ip(),
        ]);
    } catch (Throwable $e) { log_msg('error', 'audit failed: ' . $e->getMessage()); }
}
