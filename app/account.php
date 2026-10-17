<?php
/** Public accounts: register, login (+2FA), email verification, password reset, account area, watchlist API. */

function acct_page(string $mode, array $vars = [], string $title = ''): void
{
    $titles = ['login' => 'Log in', 'login_2fa' => 'Two-factor verification', 'register' => 'Create your free account', 'forgot' => 'Reset your password',
               'reset' => 'Choose a new password', 'account' => 'My account', 'security' => 'Account security', 'message' => $title ?: 'Notice'];
    echo page('account', ['mode' => $mode] + $vars, [
        'title' => ($title ?: $titles[$mode]) . ' | ' . cfg('site_name'), 'description' => $titles[$mode] . ' — ' . cfg('site_name'),
        'canonical' => null, 'robots' => 'noindex,nofollow',
    ]);
}

function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }

function acct_after_login(array $u, ?string $next): void
{
    $default = $u['role'] !== 'user' ? '/admin/' : '/account/';
    go(safe_next($next, $default));
}

const DUMMY_HASH = '$2y$10$RCH8yE/If8oEKRFkyJVeIOEjt4Tryv1FOr9kIkfl3k9P31PXhYKLG'; // valid bcrypt of a random string: equalises timing for unknown emails

function ctl_login(): void
{
    sess_start();
    $next = $_POST['next'] ?? $_GET['next'] ?? null;
    if (current_user()) acct_after_login(current_user(), $next);
    $err = null; $email = '';
    if (is_post()) {
        csrf_check();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pass = (string)($_POST['password'] ?? '');
        if (login_blocked($email)) {
            $err = 'Too many failed attempts. Please wait 15 minutes and try again.';
        } else {
            $u = row('SELECT * FROM users WHERE email = ?', [$email]);
            $ok = $u ? password_verify($pass, $u['password_hash']) : (bool)password_verify($pass, DUMMY_HASH) && false;
            if (!$ok) {
                log_login($u['id'] ?? null, $email, false, 'bad_password');
                $err = 'Incorrect email or password.';
            } elseif ($u['status'] !== 'active') {
                log_login((int)$u['id'], $email, false, 'inactive');
                $err = 'This account is not active. Contact support if you think this is a mistake.';
            } elseif ($u['totp_enabled']) {
                session_regenerate_id(true);
                $_SESSION['pending_2fa'] = ['uid' => (int)$u['id'], 'at' => time(), 'next' => $next];
                go('/login/2fa/');
            } else {
                if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
                auth_login($u);
                acct_after_login($u, $next);
            }
        }
    }
    acct_page('login', ['err' => $err, 'email' => $email, 'next' => $next, 'flash' => flash_take()]);
}

function ctl_login_2fa(): void
{
    sess_start();
    $p = $_SESSION['pending_2fa'] ?? null;
    if (!$p || time() - $p['at'] > 300) { unset($_SESSION['pending_2fa']); go('/login/'); }
    $u = row('SELECT * FROM users WHERE id = ? AND status = "active"', [$p['uid']]);
    if (!$u) go('/login/');
    $err = null;
    if (is_post()) {
        csrf_check();
        $code = trim((string)($_POST['code'] ?? ''));
        if (login_blocked($u['email'])) {
            $err = 'Too many failed attempts. Please wait 15 minutes.';
        } else {
            $secret = secret_decrypt($u['totp_secret']);
            $ok = $secret && preg_match('~^\s*\d{3}\s?\d{3}\s*$~', $code) && totp_verify($secret, $code);
            if (!$ok && preg_match('~^[0-9a-f]{4}-[0-9a-f]{4}$~i', $code)) { // recovery code
                $r = row('SELECT id FROM user_tokens WHERE user_id = ? AND type = "recovery" AND token_hash = ? AND used_at IS NULL', [$u['id'], hash('sha256', strtolower($code))]);
                if ($r) { q('UPDATE user_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$r['id']]); $ok = true; audit('2fa.recovery_used', 'user', $u['id']); }
            }
            if ($ok) {
                $next = $p['next'];
                unset($_SESSION['pending_2fa']);
                auth_login($u);
                acct_after_login($u, $next);
            }
            log_login((int)$u['id'], $u['email'], false, 'bad_2fa');
            $err = 'That code is not valid.';
        }
    }
    acct_page('login_2fa', ['err' => $err]);
}

function ctl_register(): void
{
    sess_start();
    if (current_user()) go('/account/');
    if (setting('registration_open', '1') !== '1') { acct_page('message', ['text' => 'Registrations are currently closed.'], 'Registration closed'); return; }
    $err = null; $v = ['email' => '', 'name' => '', 'newsletter' => ''];
    if (!is_post()) $_SESSION['reg_form_at'] = time();
    else {
        csrf_check();
        $v = ['email' => strtolower(trim((string)($_POST['email'] ?? ''))), 'name' => trim((string)($_POST['name'] ?? '')), 'newsletter' => !empty($_POST['newsletter']) ? '1' : ''];
        $pw = (string)($_POST['password'] ?? '');
        $recent = (int)val('SELECT COUNT(*) FROM login_history WHERE reason = "register" AND ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR', [client_ip()]);
        if (!empty($_POST['website']) || time() - ($_SESSION['reg_form_at'] ?? time()) < 3) { $err = 'Please try again.'; }          // honeypot / too fast
        elseif ($recent >= 5) { $err = 'Too many sign-ups from your network. Please try again later.'; }
        elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) { $err = 'Enter a valid email address.'; }
        elseif ($e = password_error($pw, $v['email'])) { $err = $e; }
        elseif ($pw !== (string)($_POST['password2'] ?? '')) { $err = 'The two passwords do not match.'; }
        elseif (empty($_POST['terms'])) { $err = 'Please accept the Terms and Privacy Policy.'; }
        else {
            $exists = row('SELECT id FROM users WHERE email = ?', [$v['email']]);
            if ($exists) {
                send_mail($v['email'], 'Someone tried to register with your email', "Hi,\n\nSomeone just tried to create a " . cfg('site_name') . " account with this email address, but you already have one.\nIf it was you, log in or reset your password: " . base_url() . "/forgot/\n\nIf it wasn't you, you can ignore this message.\n");
                log_login((int)$exists['id'], $v['email'], false, 'register_existing');
            } else {
                q('INSERT INTO users (email, name, password_hash, newsletter, country) VALUES (?,?,?,?,?)', [$v['email'], mb_substr($v['name'], 0, 120) ?: null, password_hash($pw, PASSWORD_DEFAULT), $v['newsletter'] ? 1 : 0, country_code()]);
                $u = row('SELECT * FROM users WHERE email = ?', [$v['email']]);
                log_login((int)$u['id'], $v['email'], true, 'register');
                send_verification($u);
            }
            // identical response either way: no account enumeration
            acct_page('message', ['text' => 'Almost done! If this address can be registered, we sent a confirmation link to it. Open it to verify your email, then log in.', 'cta' => ['/login/', 'Go to log in']], 'Check your email');
            return;
        }
    }
    acct_page('register', ['err' => $err, 'v' => $v]);
}

function ctl_logout(): void
{
    if (is_post()) { csrf_check(); sess_destroy(); go('/'); }
    sess_start();
    acct_page('message', ['text' => 'Do you want to log out?', 'post' => ['/logout/', 'Log out']], 'Log out');
}

function ctl_verify(): void
{
    $t = (string)($_GET['token'] ?? '');
    $uid = $t !== '' ? token_consume($t, 'verify') : null;
    if ($uid) {
        q('UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?', [$uid]);
        acct_page('message', ['text' => 'Your email address is confirmed. Thank you!', 'cta' => ['/account/', 'Go to my account']], 'Email confirmed');
    } else {
        acct_page('message', ['text' => 'This confirmation link is invalid or has expired. Log in and request a new one from your account page.', 'cta' => ['/login/', 'Log in']], 'Link expired');
    }
}

function ctl_forgot(): void
{
    sess_start();
    $done = false;
    if (is_post()) {
        csrf_check();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $recent = (int)val('SELECT COUNT(*) FROM login_history WHERE reason = "reset_request" AND ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR', [client_ip()]);
        if ($recent < 5) {
            log_login(null, $email, true, 'reset_request');
            $u = filter_var($email, FILTER_VALIDATE_EMAIL) ? row('SELECT * FROM users WHERE email = ? AND status = "active"', [$email]) : null;
            if ($u) {
                $t = token_create((int)$u['id'], 'reset', 60);
                send_mail($u['email'], 'Reset your ' . cfg('site_name') . ' password', "Hi,\n\nUse this link to choose a new password (valid for 1 hour):\n\n" . base_url() . '/reset/?token=' . $t . "\n\nIf you didn't ask for this, ignore this email — your password stays the same.\n");
            }
        }
        $done = true;
    }
    acct_page('forgot', ['done' => $done]);
}

function ctl_reset(): void
{
    sess_start();
    $t = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    $uid = $t !== '' ? token_peek($t, 'reset') : null;
    if (!$uid) { acct_page('message', ['text' => 'This reset link is invalid or has expired.', 'cta' => ['/forgot/', 'Request a new link']], 'Link expired'); return; }
    $err = null;
    if (is_post()) {
        csrf_check();
        $u = row('SELECT * FROM users WHERE id = ?', [$uid]);
        $pw = (string)($_POST['password'] ?? '');
        if ($e = password_error($pw, $u['email'], $u['role'] !== 'user')) $err = $e;
        elseif ($pw !== (string)($_POST['password2'] ?? '')) $err = 'The two passwords do not match.';
        else {
            token_consume($t, 'reset');
            q('UPDATE users SET password_hash = ?, sessions_valid_after = UTC_TIMESTAMP(), email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $uid]);
            log_login($uid, $u['email'], true, 'password_reset');
            audit('user.password_reset', 'user', $uid);
            flash('Password updated. You can log in now.');
            go('/login/');
        }
    }
    acct_page('reset', ['err' => $err, 'token' => $t]);
}

function acct_require(): array
{
    $u = current_user();
    if (!$u) go('/login/?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/account/'));
    return $u;
}

function ctl_account(): void
{
    sess_start();
    $u = acct_require();
    $msg = null; $err = null;
    if (is_post()) {
        csrf_check();
        $a = $_POST['action'] ?? '';
        if ($a === 'profile') {
            q('UPDATE users SET name = ?, newsletter = ? WHERE id = ?', [mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120) ?: null, !empty($_POST['newsletter']) ? 1 : 0, $u['id']]);
            $msg = 'Profile saved.';
        } elseif ($a === 'password') {
            if (!password_verify((string)($_POST['current'] ?? ''), $u['password_hash'])) $err = 'Your current password is incorrect.';
            elseif ($e = password_error((string)$_POST['password'], $u['email'], $u['role'] !== 'user')) $err = $e;
            elseif ($_POST['password'] !== ($_POST['password2'] ?? '')) $err = 'The two passwords do not match.';
            else {
                q('UPDATE users SET password_hash = ?, sessions_valid_after = UTC_TIMESTAMP() WHERE id = ?', [password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $u['id']]);
                audit('user.password_change', 'user', $u['id']);
                auth_login($u); // keep this session, drop the others
                $msg = 'Password changed. Other devices were logged out.';
            }
        } elseif ($a === 'resend' && !$u['email_verified_at']) {
            if ((int)val('SELECT COUNT(*) FROM user_tokens WHERE user_id = ? AND type = "verify" AND created_at > UTC_TIMESTAMP() - INTERVAL 5 MINUTE', [$u['id']]) === 0) { send_verification($u); $msg = 'Confirmation email sent.'; }
            else $err = 'A confirmation email was just sent. Check your inbox (and spam).';
        } elseif ($a === 'delete') {
            if (!password_verify((string)($_POST['confirm_password'] ?? ''), $u['password_hash'])) $err = 'Password incorrect — account not scheduled for deletion.';
            elseif ($u['role'] !== 'user') $err = 'Staff accounts must be removed by a Super Admin.';
            else { q('UPDATE users SET deletion_requested_at = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]); audit('user.deletion_requested', 'user', $u['id']); $msg = 'Your account will be permanently deleted in 7 days. You can cancel any time before then.'; }
        } elseif ($a === 'cancel_delete') {
            q('UPDATE users SET deletion_requested_at = NULL WHERE id = ?', [$u['id']]);
            $msg = 'Deletion cancelled.';
        }
        $u = current_user(true);
    }
    $watch = rows('SELECT c.slug, c.name, c.symbol, c.image, c.price, c.change_24h FROM user_watchlist w JOIN coins c ON c.id = w.coin_id WHERE w.user_id = ? ORDER BY c.rank_no IS NULL, c.rank_no', [$u['id']]);
    acct_page('account', ['u' => $u, 'msg' => $msg, 'err' => $err, 'watch' => $watch, 'flash' => flash_take()]);
}

function ctl_account_security(): void
{
    sess_start();
    $u = acct_require();
    $msg = null; $err = null; $codes = null;
    if (is_post()) {
        csrf_check();
        $a = $_POST['action'] ?? '';
        if ($a === 'enable' && !$u['totp_enabled']) {
            $secret = $_SESSION['totp_setup'] ?? null;
            if ($secret && totp_verify($secret, (string)($_POST['code'] ?? ''))) {
                q('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?', [secret_encrypt($secret), $u['id']]);
                q('DELETE FROM user_tokens WHERE user_id = ? AND type = "recovery"', [$u['id']]);
                $codes = [];
                for ($i = 0; $i < 8; $i++) {
                    $c = bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2));
                    $codes[] = $c;
                    q('INSERT INTO user_tokens (user_id, type, token_hash, expires_at) VALUES (?, "recovery", ?, UTC_TIMESTAMP() + INTERVAL 10 YEAR)', [$u['id'], hash('sha256', $c)]);
                }
                unset($_SESSION['totp_setup']);
                audit('2fa.enabled', 'user', $u['id']);
                $msg = 'Two-factor authentication is on. Save these recovery codes — each works once and they will not be shown again.';
            } else $err = 'That code did not match. Check the time on your phone and try again.';
        } elseif ($a === 'disable' && $u['totp_enabled']) {
            $secret = secret_decrypt($u['totp_secret']);
            if (!password_verify((string)($_POST['password'] ?? ''), $u['password_hash']) || !$secret || !totp_verify($secret, (string)($_POST['code'] ?? ''))) $err = 'Password or code incorrect.';
            else {
                q('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [$u['id']]);
                q('DELETE FROM user_tokens WHERE user_id = ? AND type = "recovery"', [$u['id']]);
                audit('2fa.disabled', 'user', $u['id']);
                $msg = 'Two-factor authentication turned off.';
            }
        }
        $u = current_user(true);
    }
    $setup = null;
    if (!$u['totp_enabled']) {
        if (empty($_SESSION['totp_setup'])) $_SESSION['totp_setup'] = totp_new_secret();
        $setup = ['secret' => $_SESSION['totp_setup'], 'uri' => totp_uri($_SESSION['totp_setup'], $u['email'])];
    }
    $hist = rows('SELECT created_at, ip, success, reason, user_agent FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$u['id']]);
    acct_page('security', ['u' => $u, 'msg' => $msg, 'err' => $err, 'codes' => $codes, 'setup' => $setup, 'hist' => $hist]);
}

function ctl_account_export(): void
{
    $u = acct_require();
    $data = [
        'exported_at' => gmdate('c'),
        'profile' => ['email' => $u['email'], 'name' => $u['name'], 'role' => $u['role'], 'created_at' => $u['created_at'], 'email_verified_at' => $u['email_verified_at'], 'newsletter' => (bool)$u['newsletter'], 'country' => $u['country']],
        'watchlist' => array_column(rows('SELECT c.slug FROM user_watchlist w JOIN coins c ON c.id = w.coin_id WHERE w.user_id = ?', [$u['id']]), 'slug'),
        'login_history' => rows('SELECT created_at, ip, success, reason FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT 200', [$u['id']]),
    ];
    audit('user.data_export', 'user', $u['id']);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . cfg('site_name') . '-my-data.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------- JSON API for the browser
function api_me(): void
{
    header('Cache-Control: no-store');
    $u = current_user();
    if (!$u) { json_out(['loggedIn' => false], 0); return; }
    $w = array_column(rows('SELECT c.slug FROM user_watchlist w JOIN coins c ON c.id = w.coin_id WHERE w.user_id = ?', [$u['id']]), 'slug');
    json_out(['loggedIn' => true, 'csrf' => csrf_token(), 'watchlist' => $w, 'staff' => is_staff()], 0);
    header('Cache-Control: no-store');
}

function api_watchlist(): void
{
    header('Cache-Control: no-store');
    if (!is_post()) { http_response_code(405); return; }
    $u = current_user();
    if (!$u) { http_response_code(401); json_out(['error' => 'login'], 0); return; }
    csrf_check();
    $in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
    $c = coin_by_slug((string)($in['coin'] ?? ''));
    if (!$c) { http_response_code(404); json_out(['error' => 'coin'], 0); return; }
    if (!empty($in['on'])) {
        if ((int)val('SELECT COUNT(*) FROM user_watchlist WHERE user_id = ?', [$u['id']]) < 500) q('INSERT IGNORE INTO user_watchlist (user_id, coin_id) VALUES (?, ?)', [$u['id'], $c['id']]);
    } else q('DELETE FROM user_watchlist WHERE user_id = ? AND coin_id = ?', [$u['id'], $c['id']]);
    json_out(['ok' => true], 0);
}
