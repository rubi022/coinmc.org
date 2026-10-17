<?php
/** Sessions, CSRF, client IP, login/registration primitives, one-time tokens. */

const SESSION_NAME = 'cmcsid';

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return cfg('trusted_proxy') && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function client_ip(): string
{
    if (cfg('trusted_proxy')) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) if (!empty($_SERVER[$h]) && filter_var($_SERVER[$h], FILTER_VALIDATE_IP)) return $_SERVER[$h];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ip_in_list(string $ip, string $list): bool
{
    foreach (preg_split('~[\s,]+~', trim($list)) as $entry) {
        if ($entry === '') continue;
        if (strpos($entry, '/') === false) { if ($entry === $ip) return true; continue; }
        [$net, $bits] = explode('/', $entry, 2);
        $ipl = ip2long($ip); $netl = ip2long($net); $bits = (int)$bits;
        if ($ipl === false || $netl === false || $bits < 0 || $bits > 32) continue;
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
        if (($ipl & $mask) === ($netl & $mask)) return true;
    }
    return false;
}

function sess_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(SESSION_NAME);
    session_cache_limiter('');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function sess_destroy(): void
{
    sess_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) setcookie(SESSION_NAME, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    setcookie('cmc_u', '', ['expires' => time() - 3600, 'path' => '/']);
    session_destroy();
}

/** Logged-in user row, or null. Never starts a session for anonymous visitors. */
function current_user(bool $refresh = false): ?array
{
    static $u = false;
    if ($refresh) $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (PHP_SAPI === 'cli' || empty($_COOKIE[SESSION_NAME])) return null;
    sess_start();
    if (empty($_SESSION['uid'])) return null;
    $row = row('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']]);
    if (!$row || $row['status'] !== 'active') { sess_destroy(); return null; }
    if ($row['sessions_valid_after'] && ($_SESSION['login_at'] ?? 0) < strtotime($row['sessions_valid_after'] . ' UTC')) { sess_destroy(); return null; }
    $idle = ($row['role'] !== 'user') ? 4 * 3600 : 30 * 86400;
    if (time() - ($_SESSION['last'] ?? time()) > $idle) { sess_destroy(); return null; }
    $_SESSION['last'] = time();
    return $u = $row;
}

function auth_login(array $user): void
{
    sess_start();
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$user['id'], 'login_at' => time(), 'last' => time()];
    setcookie('cmc_u', '1', ['expires' => 0, 'path' => '/', 'secure' => is_https(), 'samesite' => 'Lax']); // lets cached public pages know to show "Account"
    q('UPDATE users SET last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?', [client_ip(), $user['id']]);
    log_login((int)$user['id'], $user['email'], true, 'login');
    current_user(true);
}

function log_login(?int $uid, ?string $email, bool $ok, string $reason): void
{
    q('INSERT INTO login_history (user_id, email, ip, user_agent, success, reason) VALUES (?,?,?,?,?,?)', [
        $uid, $email ? substr($email, 0, 190) : null, client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $ok ? 1 : 0, $reason,
    ]);
}

function login_blocked(string $email): bool
{
    $ip = client_ip();
    $byIp = (int)val('SELECT COUNT(*) FROM login_history WHERE success = 0 AND ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE', [$ip]);
    $byEmail = (int)val('SELECT COUNT(*) FROM login_history WHERE success = 0 AND email = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE', [strtolower($email)]);
    return $byIp >= 10 || $byEmail >= 5;
}

function password_error(string $pw, string $email = '', bool $staff = false): ?string
{
    $min = $staff ? 12 : 8;
    if (strlen($pw) < $min) return "Password must be at least $min characters.";
    if (strlen($pw) > 200) return 'Password is too long.';
    if ($email && strcasecmp($pw, $email) === 0) return 'Password must not be your email address.';
    if (preg_match('~^(.)\1+$~', $pw) || in_array(strtolower($pw), ['password', 'password1', '12345678', '123456789', 'qwertyuiop', 'iloveyou1'], true)) return 'That password is too easy to guess.';
    return null;
}

// ---------------------------------------------------------------- CSRF + flash
function csrf_token(): string
{
    sess_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }

function csrf_check(): void
{
    sess_start();
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf']) || !is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(419);
        echo 'Your session expired or the form was invalid. Go back, refresh the page and try again.';
        exit;
    }
}

function flash(string $msg, string $type = 'ok'): void { sess_start(); $_SESSION['flash'][] = [$type, $msg]; }

function flash_take(): array { sess_start(); $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function safe_next(?string $n, string $default = '/account/'): string
{
    if (!$n || $n[0] !== '/' || strpos($n, '//') === 0 || strpos($n, '\\') !== false || preg_match('~[\r\n]~', $n)) return $default;
    return $n;
}

function go(string $url): void { header('Location: ' . $url); exit; }

// ---------------------------------------------------------------- one-time tokens (verify email, reset password)
function token_create(int $uid, string $type, int $ttlMinutes): string
{
    $raw = bin2hex(random_bytes(32));
    q('UPDATE user_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND type = ? AND used_at IS NULL', [$uid, $type]);
    q('INSERT INTO user_tokens (user_id, type, token_hash, expires_at) VALUES (?,?,?, UTC_TIMESTAMP() + INTERVAL ? MINUTE)', [$uid, $type, hash('sha256', $raw), $ttlMinutes]);
    return $raw;
}

function token_peek(string $raw, string $type): ?int
{
    $r = row('SELECT user_id FROM user_tokens WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()', [hash('sha256', $raw), $type]);
    return $r ? (int)$r['user_id'] : null;
}

function token_consume(string $raw, string $type): ?int
{
    $uid = token_peek($raw, $type);
    if ($uid) q('UPDATE user_tokens SET used_at = UTC_TIMESTAMP() WHERE token_hash = ?', [hash('sha256', $raw)]);
    return $uid;
}

function send_verification(array $user): void
{
    $t = token_create((int)$user['id'], 'verify', 60 * 48);
    send_mail($user['email'], 'Confirm your ' . cfg('site_name') . ' email', "Hi,\n\nConfirm your email address to finish setting up your " . cfg('site_name') . " account:\n\n" . base_url() . '/verify/?token=' . $t . "\n\nThis link expires in 48 hours. If you didn't create an account you can ignore this email.\n");
}
