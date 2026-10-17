<?php
/** System health & logs, security controls, audit log viewer, settings (integrations, email, notifications, registration, advanced). */

// ============================================================ SYSTEM
function m_system(string $sub): void
{
    $tabs = ['' => 'Health', 'logs' => 'Logs'];
    if (!isset($tabs[$sub])) { http_response_code(404); admin_page('Not found', '<div class="card">Unknown page.</div>'); return; }
    admin_page('System · ' . $tabs[$sub], a_tabs('system', $tabs, $sub) . ($sub === '' ? sys_health() : sys_logs()));
}

function sys_health(): string
{
    $chk = fn($l, $ok, $d = '', $sev = 'bad') => ['l' => $l, 's' => $ok ? 'ok' : $sev, 'd' => $d];
    $disk = @disk_free_space(STORAGE); $dt = @disk_total_space(STORAGE);
    $ext = []; foreach (['curl', 'pdo_mysql', 'openssl', 'mbstring', 'dom', 'json', 'intl'] as $e) $ext[] = $chk("PHP extension: $e", extension_loaded($e), extension_loaded($e) ? '' : 'missing', $e === 'intl' ? 'warn' : 'bad');
    $key = STORAGE . '/app.key';
    $rows = array_merge([
        $chk('PHP version ≥ 7.4', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION),
        $chk('Database reachable', (bool)val('SELECT 1'), 'MySQL ' . val('SELECT VERSION()')),
        $chk('storage/ writable', is_writable(STORAGE), STORAGE),
        $chk('Free disk space > 10%', $disk && $dt && $disk / $dt > .1, $disk ? round($disk / 1e9, 1) . ' GB free of ' . round($dt / 1e9, 1) . ' GB' : 'unknown', 'warn'),
        $chk('Market data fresh (<30 min)', time() - (int)kv_get('last_run_markets', 0) < 1800, kv_get('last_run_markets') ? time_ago(gmdate('Y-m-d H:i:s', (int)kv_get('last_run_markets'))) : 'never ran'),
        $chk('Debug mode off', !cfg('debug'), cfg('debug') ? 'debug=true leaks error details — turn off in production' : '', 'warn'),
        $chk('HTTPS in use', is_https() || (stripos((string)cfg('site_url'), 'https://') === 0 && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])), is_https() ? '' : 'This request was not over HTTPS', 'warn'),
        $chk('Encryption key present & private', is_file($key) && (fileperms($key) & 0077) === 0, is_file($key) ? 'mode ' . substr(sprintf('%o', fileperms($key)), -4) : 'will be created on first use', 'warn'),
        $chk('Database user is not root without a password', !(cfg('db')['user'] === 'root' && (cfg('db')['pass'] ?? '') === ''), 'Create a dedicated MySQL user with a password for production.', 'warn'),
        $chk('Page cache enabled', (bool)cfg('cache_enabled'), '', 'warn'),
    ], $ext);
    $h = a_card('Health checks', a_table(['l' => 'Check', 's' => ['label' => 'Result', 'fmt' => fn($r, $v) => a_status_badge($v === 'ok' ? 'ok' : ($v === 'warn' ? 'warning' : 'critical'))], 'd' => ['label' => 'Detail', 'cls' => 'wrap']], $rows));
    $db = (string)val('SELECT DATABASE()');
    $tb = rows('SELECT table_name t, table_rows r, ROUND((data_length + index_length) / 1048576, 1) mb FROM information_schema.tables WHERE table_schema = ? ORDER BY (data_length + index_length) DESC LIMIT 12', [$db]);
    $tot = (float)val('SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.tables WHERE table_schema = ?', [$db]);
    $h .= '<div class="grid g2">' . a_card('Database — ' . a_n($tot, 1) . ' MB', a_table(['t' => 'Table', 'r' => ['label' => '~Rows', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'mb' => ['label' => 'MB', 'cls' => 'num']], $tb) . a_help('Raw pageviews are pruned after ' . (int)cfg('analytics_retention_days', 90) . ' days by the rollup task; daily aggregates are kept forever.'));
    $cache = glob(STORAGE . '/cache/*.html') ?: []; $csz = 0; foreach ($cache as $f) $csz += filesize($f);
    $srv = [['PHP', PHP_VERSION . ' (' . PHP_SAPI . ')'], ['Server', $_SERVER['SERVER_SOFTWARE'] ?? '—'], ['memory_limit', ini_get('memory_limit')], ['max_execution_time', ini_get('max_execution_time')], ['Page cache', count($cache) . ' files, ' . round($csz / 1048576, 1) . ' MB'], ['Timezone (PHP)', date_default_timezone_get()], ['Site URL', cfg('site_url')], ['Admin IP (you)', client_ip()]];
    $h .= a_card('Server', a_table([0 => 'Item', 1 => ['label' => 'Value', 'cls' => 'wrap']], $srv)) . '</div>';
    return $h;
}

function sys_logs(): string
{
    $files = ['error' => 'PHP / application errors', 'sync' => 'Data sync tasks', 'http' => 'Outbound API failures', 'mail' => 'Outgoing mail', 'cron' => 'Cron output'];
    $f = isset($files[a_in('file')]) ? a_in('file') : 'error';
    $path = STORAGE . "/logs/$f.log";
    if (is_post() && a_action() === 'clear') { require_perm('system.edit'); @file_put_contents($path, ''); audit('log.clear', 'log', $f); a_go('system/logs', "$f.log cleared.", 'ok', ['file' => $f]); }
    $tail = '';
    if (is_file($path)) { $sz = filesize($path); $fh = fopen($path, 'r'); fseek($fh, max(0, $sz - 60000)); $tail = (string)stream_get_contents($fh); fclose($fh); $lines = explode("\n", $tail); $tail = implode("\n", array_slice($lines, -250)); }
    $tabs = '<nav class="tabs">';
    foreach ($files as $k => $l) $tabs .= '<a class="' . ($k === $f ? 'on' : '') . '" href="' . e(a_url('system/logs', ['file' => $k])) . '">' . e($l) . '</a>';
    $tabs .= '</nav>';
    return $tabs . a_card($f . '.log — last 250 lines (' . (is_file($path) ? round(filesize($path) / 1024, 1) . ' KB' : 'empty') . ')', '<pre class="log">' . e($tail ?: 'Nothing logged.') . '</pre>' . (can('system.edit') ? a_btn('clear', 'Clear this log', [], 'ghost', 'Clear this log file?') : '') . (cfg('debug') && $f === 'mail' ? '<p class="warnbox">Debug mode logs email bodies, which contain one-time links. Turn debug off.</p>' : ''));
}

// ============================================================ SECURITY
function m_security(string $sub): void
{
    if ($sub !== '') { http_response_code(404); admin_page('Not found', '<div class="card">Unknown page.</div>'); return; }
    $me = current_user();
    if (is_post()) {
        $act = a_action();
        if ($act === 'save') {
            $req = isset($_POST['require_2fa']) ? '1' : '0';
            $allow = trim(str_replace(["\r", ','], ["", "\n"], (string)($_POST['ip_allow'] ?? '')));
            $lines = array_values(array_filter(array_map('trim', explode("\n", $allow))));
            foreach ($lines as $l) if (!preg_match('~^[0-9a-fA-F:.]+(/\d{1,3})?$~', $l)) a_go('security', "“$l” is not a valid IP or CIDR range.", 'err');
            $list = implode(', ', $lines);
            if ($list !== '' && !ip_in_list(client_ip(), $list)) a_go('security', 'Refused: your current IP (' . client_ip() . ') is not in that list, so you would lock yourself out.', 'err');
            audit('security.save', 'setting', 'security', ['require_2fa' => setting('admin_require_2fa', '1'), 'ip_allow' => setting('admin_ip_allow', '')], ['require_2fa' => $req, 'ip_allow' => $list]);
            set_setting('admin_require_2fa', $req); set_setting('admin_ip_allow', $list);
            a_go('security', 'Security settings saved.');
        }
        if ($act === 'logout_staff') { q("UPDATE users SET sessions_valid_after = UTC_TIMESTAMP() WHERE role <> 'user' AND id <> ?", [$me['id']]); audit('security.logout_staff', 'user'); a_go('security', 'All other staff sessions were invalidated.'); }
        if ($act === 'logout_all') { q('UPDATE users SET sessions_valid_after = UTC_TIMESTAMP() WHERE id <> ?', [$me['id']]); audit('security.logout_all', 'user'); a_go('security', 'Every other session on the site was invalidated.'); }
    }
    $staff = rows("SELECT COUNT(*) n, SUM(totp_enabled) t FROM users WHERE role <> 'user' AND status = 'active'")[0];
    $h = '<div class="kpis">' . a_kpi('Staff accounts', a_n($staff['n'])) . a_kpi('Staff with 2FA', a_n($staff['t']), $staff['t'] < $staff['n'] ? '<span class="down">some staff have no 2FA</span>' : 'all covered', $staff['t'] < $staff['n'] ? 'warn' : '')
        . a_kpi('Failed logins (24h)', a_n(val("SELECT COUNT(*) FROM login_history WHERE success = 0 AND reason NOT IN ('reset_request') AND created_at > UTC_TIMESTAMP() - INTERVAL 24 HOUR"))) . a_kpi('Your IP', e(client_ip())) . '</div>';
    $h .= a_card('Admin access policy', a_form('<input type="hidden" name="action" value="save">' . a_field('checkbox', 'require_2fa', 'Require two-factor authentication for every staff account (recommended)', setting('admin_require_2fa', '1') === '1')
        . a_field('textarea', 'ip_allow', 'Admin IP allow-list (one IPv4/IPv6 address or CIDR per line; empty = anywhere)', str_replace(', ', "\n", (string)setting('admin_ip_allow', '')), ['rows' => 4, 'hint' => 'Visitors outside the list get a plain 404 for /admin/. Behind a proxy/CDN set <code>trusted_proxy</code> in config so the real client IP is used. Your own IP is checked to prevent lock-out.'])
        . a_submit('Save policy')));
    $h .= a_card('Session controls', '<p class="muted">Immediately invalidate sessions after a suspected compromise. Your own session stays signed in.</p>' . a_btn('logout_staff', 'Sign out all other staff', [], 'ghost', 'Sign out all other staff members?') . a_btn('logout_all', 'Sign out everyone else (members too)', [], 'danger', 'Sign out every other user on the site?'));
    $ips = rows("SELECT ip, COUNT(*) n, MAX(created_at) last FROM login_history WHERE success = 0 AND reason NOT IN ('reset_request') AND created_at > UTC_TIMESTAMP() - INTERVAL 24 HOUR GROUP BY ip ORDER BY n DESC LIMIT 10");
    $h .= '<div class="grid g2">' . a_card('Failed sign-ins by IP (24h)', a_table(['ip' => 'IP', 'n' => ['label' => 'Failures', 'cls' => 'num'], 'last' => ['label' => 'Last', 'fmt' => fn($r, $v) => e(time_ago($v))]], $ips, 'None. ✅') . a_help('Accounts lock for 15 minutes after repeated failures per email and per IP.'));
    $sl = rows("SELECT h.created_at, h.email, h.ip, h.success, h.reason FROM login_history h JOIN users u ON u.id = h.user_id WHERE u.role <> 'user' ORDER BY h.id DESC LIMIT 12");
    $h .= a_card('Recent staff sign-ins', a_table(['created_at' => 'UTC', 'email' => 'Account', 'ip' => 'IP', 'success' => ['label' => 'Result', 'fmt' => fn($r, $v) => $v ? a_badge('ok', 'ok') : a_badge($r['reason'] ?: 'failed', 'bad')]], $sl)) . '</div>';
    $h .= a_card('Compliance notes', '<ul class="help"><li><b>Paid placements</b> always render with a non-removable “Sponsored” label and a disclosure link; campaigns need a verified advertiser and a reviewer’s sign-off (four-eyes) before going live.</li><li><b>Sponsored articles</b> must name the sponsor.</li><li><b>Risk flags</b>: warning / scam flags on coins show a banner and (for scam) remove the coin from lists and search engines.</li><li><b>Privacy</b>: analytics are cookie-less and pseudonymous; members can export or delete their data; deletions complete after 7 days.</li><li><b>Audit trail</b>: every admin change is recorded with before/after values (secrets masked) in the Audit log.</li></ul>');
    admin_page('Security', $h);
}

// ============================================================ AUDIT
function m_audit(string $sub): void
{
    $act = a_in('action_f'); $ent = a_in('entity'); $who = a_in('actor'); $q = a_in('q');
    [$f, $t] = a_in('range') === '' ? [gmdate('Y-m-d', time() - 29 * 86400), gmdate('Y-m-d')] : array_slice(a_range(), 0, 2);
    $w = ['created_at >= ? AND created_at < ? + INTERVAL 1 DAY']; $p = [$f, $t];
    if ($act !== '') { $w[] = 'action LIKE ?'; $p[] = addcslashes($act, '%_') . '%'; }
    if ($ent !== '') { $w[] = 'entity = ?'; $p[] = $ent; }
    if ($who !== '') { $w[] = 'actor LIKE ?'; $p[] = '%' . addcslashes($who, '%_') . '%'; }
    if ($q !== '') { $w[] = '(entity_id = ? OR after_json LIKE ? OR before_json LIKE ?)'; array_push($p, $q, '%' . addcslashes($q, '%_') . '%', '%' . addcslashes($q, '%_') . '%'); }
    $W = implode(' AND ', $w);
    $per = 100; $page = max(1, a_int('page', 1));
    $total = (int)val("SELECT COUNT(*) FROM audit_log WHERE $W", $p);
    $rows = rows("SELECT * FROM audit_log WHERE $W ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
    $ents = array_column(rows('SELECT DISTINCT entity FROM audit_log WHERE entity IS NOT NULL AND entity <> \'\' ORDER BY entity'), 'entity');
    $h = '<form class="filters" method="get"><input type="hidden" name="range" value="custom"><input type="date" name="from" value="' . e($f) . '"><input type="date" name="to" value="' . e($t) . '"><input type="text" name="action_f" value="' . e($act) . '" placeholder="Action (e.g. coin.)">'
        . '<select name="entity"><option value="">Any entity</option>' . implode('', array_map(fn($x) => '<option' . ($x === $ent ? ' selected' : '') . '>' . e($x) . '</option>', $ents)) . '</select><input type="text" name="actor" value="' . e($who) . '" placeholder="Actor email"><input type="search" name="q" value="' . e($q) . '" placeholder="Entity ID / text"><button class="btn" type="submit">Filter</button></form>';
    $h .= a_card(a_n($total) . ' events', a_table(['created_at' => ['label' => 'UTC', 'cls' => 'nw'], 'actor' => 'Actor', 'action' => ['label' => 'Action', 'fmt' => fn($r, $v) => '<code>' . e($v) . '</code>'], 'entity' => ['label' => 'Entity', 'fmt' => fn($r) => e(trim(($r['entity'] ?? '') . ' ' . ($r['entity_id'] ?? '')))],
        'ip' => ['label' => 'IP', 'cls' => 'small'],
        'diff' => ['label' => 'Change', 'cls' => 'wrap diff', 'fmt' => function ($r) {
            $b = json_decode((string)$r['before_json'], true); $a = json_decode((string)$r['after_json'], true);
            if (!is_array($a) && !is_array($b)) return '';
            $o = '';
            foreach (array_unique(array_merge(array_keys((array)$a), array_keys((array)$b))) as $k) {
                $bv = is_array($b) && array_key_exists($k, $b) ? $b[$k] : null; $av = is_array($a) && array_key_exists($k, $a) ? $a[$k] : null;
                $o .= '<div><b>' . e($k) . '</b>: ' . ($bv !== null ? '<del>' . e(mb_strimwidth(is_scalar($bv) ? (string)$bv : json_encode($bv), 0, 100, '…')) . '</del> ' : '') . ($av !== null ? '<ins>' . e(mb_strimwidth(is_scalar($av) ? (string)$av : json_encode($av), 0, 100, '…')) . '</ins>' : '') . '</div>';
            }
            return $o;
        }]], $rows, 'No events match.') . a_help('Passwords, tokens, API keys and 2FA secrets are masked before they reach this log. Entries cannot be edited or deleted from the panel.'));
    $h .= a_pager($page, (int)ceil($total / $per), 'audit', array_filter(['range' => 'custom', 'from' => $f, 'to' => $t, 'action_f' => $act, 'entity' => $ent, 'actor' => $who, 'q' => $q]));
    admin_page('Audit log', $h);
}

// ============================================================ SETTINGS
function m_settings(string $sub): void
{
    $tabs = ['' => 'Integrations', 'email' => 'Email', 'notifications' => 'Notifications', 'registration' => 'Registration', 'advanced' => 'Advanced'];
    if ($sub === 'integrations') $sub = '';
    if (!isset($tabs[$sub])) { http_response_code(404); admin_page('Not found', '<div class="card">Unknown page.</div>'); return; }
    $body = ['' => 'set_integrations', 'email' => 'set_email', 'notifications' => 'set_notifications', 'registration' => 'set_registration', 'advanced' => 'set_advanced'][$sub]();
    admin_page('Settings · ' . $tabs[$sub], a_tabs('settings', $tabs, $sub) . $body);
}

function set_save(array $keys, string $back, string $audit): void
{
    $before = []; $after = [];
    foreach ($keys as $k => $filter) {
        $v = $filter(trim((string)($_POST[$k] ?? '')));
        if ($v === false) a_go($back, "Invalid value for $k.", 'err');
        $before[$k] = setting($k, ''); $after[$k] = $v;
        set_setting($k, $v);
    }
    audit($audit, 'setting', $audit, $before, $after);
}

function set_integrations(): string
{
    if (is_post()) {
        $act = a_action();
        if ($act === 'save') {
            set_save([
                'ga4_id' => fn($v) => $v === '' || preg_match('~^G-[A-Z0-9]{4,20}$~', $v) ? $v : false,
                'gsc_verification' => fn($v) => $v === '' || preg_match('~^[\w\-]{10,120}$~', $v) ? $v : false,
                'bing_verification' => fn($v) => $v === '' || preg_match('~^[\w\-]{10,120}$~', $v) ? $v : false,
                'gsc_site' => fn($v) => $v === '' || preg_match('~^(sc-domain:[\w.\-]+|https?://[^\s]+/)$~', $v) ? $v : false,
                'ga4_property' => fn($v) => $v === '' || preg_match('~^\d{4,15}$~', $v) ? $v : false,
                'head_html' => fn($v) => mb_substr((string)($_POST['head_html'] ?? ''), 0, 5000),
            ], 'settings', 'settings.integrations');
            coin_cache_clear();
            a_go('settings', 'Integration settings saved.');
        }
        if ($act === 'sa_save') {
            $j = json_decode((string)($_POST['sa_json'] ?? ''), true);
            if (!is_array($j) || empty($j['client_email']) || empty($j['private_key']) || strpos($j['private_key'], 'BEGIN PRIVATE KEY') === false) a_go('settings', 'That is not a valid service-account JSON key.', 'err');
            set_setting('google_sa_json', secret_encrypt(json_encode($j)));
            audit('settings.google_sa', 'setting', 'google_sa_json', ['configured' => (bool)setting('google_sa_json')], ['client_email' => $j['client_email']]);
            a_go('settings', 'Service account stored (encrypted). Now set the Search Console property / GA4 property ID and test.');
        }
        if ($act === 'sa_remove') { set_setting('google_sa_json', ''); audit('settings.google_sa_remove', 'setting', 'google_sa_json'); a_go('settings', 'Service account removed.'); }
        if ($act === 'test_gsc' || $act === 'test_ga4') {
            require_once APP_DIR . '/sources/Google.php';
            if (!Google::configured($act === 'test_gsc' ? 'gsc' : 'ga4')) a_go('settings', 'Add the service account and ' . ($act === 'test_gsc' ? 'Search Console property' : 'GA4 property ID') . ' first.', 'err');
            $r = $act === 'test_gsc'
                ? Google::gscQuery(['startDate' => gmdate('Y-m-d', time() - 10 * 86400), 'endDate' => gmdate('Y-m-d', time() - 3 * 86400), 'dimensions' => ['date'], 'rowLimit' => 10])
                : Google::ga4Report(['dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']], 'metrics' => [['name' => 'sessions']]]);
            if ($r === null) a_go('settings', 'Connection failed. Check the property ID/URL format, that the API is enabled and that the service account email was added as a user. See Logs → Outbound API failures.', 'err');
            a_go('settings', 'Connected ✓ — ' . count($r) . ' rows returned. The next scheduled sync will import the data (or run “gsc” / “ga4” under Market data → Sync tasks).');
        }
    }
    $sa = secret_decrypt(setting('google_sa_json')); $saj = $sa ? json_decode($sa, true) : null;
    $h = a_card('Google Search Console & Analytics 4 (read-only API)', ($saj ? '<p>Service account: <b>' . e($saj['client_email']) . '</b> ' . a_badge('stored encrypted', 'ok') . ' ' . a_btn('sa_remove', 'Remove', [], 'sm ghost', 'Remove the service account?') . '</p>'
        : a_form('<input type="hidden" name="action" value="sa_save">' . a_field('textarea', 'sa_json', 'Service-account JSON key', '', ['rows' => 5, 'hint' => 'Google Cloud → IAM → Service accounts → Keys → Add key (JSON). Enable the “Google Search Console API” and “Google Analytics Data API”, then add the service-account email as a user in Search Console (Settings → Users) and as Viewer on the GA4 property. The key is encrypted with this server’s key and never shown again.']) . a_submit('Store key')))
        . ($saj ? '<div class="filters" style="margin-top:8px">' . a_btn('test_gsc', 'Test Search Console', [], 'ghost') . a_btn('test_ga4', 'Test GA4', [], 'ghost') . '</div>' : ''));
    $h .= a_card('Properties, verification and tracking', a_form('<input type="hidden" name="action" value="save"><div class="cols2">'
        . a_field('text', 'gsc_site', 'Search Console property', setting('gsc_site'), ['placeholder' => 'sc-domain:coinmc.org  or  https://coinmc.org/', 'hint' => 'Exactly as shown in Search Console (domain properties start with sc-domain:).'])
        . a_field('text', 'ga4_property', 'GA4 property ID (numeric, for the Data API)', setting('ga4_property'), ['placeholder' => '123456789'])
        . a_field('text', 'ga4_id', 'GA4 measurement ID (tag, loaded only after cookie consent)', setting('ga4_id'), ['placeholder' => 'G-XXXXXXXXXX', 'hint' => 'Leave empty to rely on first-party analytics only. Setting it shows a cookie banner.'])
        . a_field('text', 'gsc_verification', 'Google site-verification token', setting('gsc_verification'), ['hint' => 'The content="…" value from the HTML-tag method.'])
        . a_field('text', 'bing_verification', 'Bing Webmaster verification token', setting('bing_verification')) . '</div>'
        . a_field('textarea', 'head_html', 'Custom <head> HTML (advanced — analytics/ad-network tags)', setting('head_html'), ['rows' => 4, 'hint' => '<b>Runs on every public page with full privileges.</b> Only paste code you trust. Scripts that set cookies need your own consent handling.']) . a_submit('Save')));
    return $h;
}

function set_email(): string
{
    if (is_post()) {
        $act = a_action();
        if ($act === 'save') {
            set_save(['smtp_host' => fn($v) => $v === '' || preg_match('~^[\w.\-]+$~', $v) ? $v : false, 'smtp_port' => fn($v) => $v === '' || (ctype_digit($v) && $v < 65536) ? $v : false,
                'smtp_secure' => fn($v) => in_array($v, ['tls', 'ssl', 'none'], true) ? $v : 'tls', 'smtp_user' => fn($v) => mb_substr($v, 0, 190), 'mail_from' => fn($v) => $v === '' || filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : false], 'settings/email', 'settings.email');
            if (($_POST['smtp_pass'] ?? '') !== '') { set_setting('smtp_pass', secret_encrypt((string)$_POST['smtp_pass'])); audit('settings.smtp_pass', 'setting', 'smtp_pass', null, ['changed' => true]); }
            a_go('settings/email', 'Email settings saved.');
        }
        if ($act === 'test') {
            $to = current_user()['email'];
            $ok = send_mail($to, 'Test email from ' . cfg('site_name'), "This is a test message from the admin panel.\nIf you can read it, outgoing email works.\n");
            a_go('settings/email', $ok ? "Test email accepted for delivery to $to. Check your inbox (and spam)." : 'Sending failed — see Logs → Outgoing mail.', $ok ? 'ok' : 'err');
        }
    }
    $mc = mail_config();
    return a_card('Outgoing email (SMTP)', a_help('Used for sign-up confirmation, password resets, admin alerts and the weekly digest. Use a transactional provider (Postmark, Mailgun, SES, Brevo…) for good deliverability and set SPF/DKIM/DMARC for your domain. With no SMTP host, PHP <code>mail()</code> is used, which often lands in spam.')
        . a_form('<input type="hidden" name="action" value="save"><div class="cols3">' . a_field('text', 'smtp_host', 'SMTP host', setting('smtp_host'), ['placeholder' => 'smtp.postmarkapp.com']) . a_field('text', 'smtp_port', 'Port', setting('smtp_port', '587'))
        . a_field('select', 'smtp_secure', 'Encryption', setting('smtp_secure', 'tls'), ['options' => ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None']]) . a_field('text', 'smtp_user', 'Username', setting('smtp_user'))
        . a_field('password', 'smtp_pass', 'Password / API token', '', ['placeholder' => setting('smtp_pass') ? '•••••••• (stored, leave blank to keep)' : '', 'attrs' => ' autocomplete="new-password"']) . a_field('email', 'mail_from', 'From address', setting('mail_from'), ['placeholder' => 'noreply@' . (parse_url(cfg('site_url'), PHP_URL_HOST) ?: 'example.com')]) . '</div>' . a_submit('Save'))
        . '<hr>' . a_btn('test', 'Send a test email to me (' . current_user()['email'] . ')', [], 'ghost') . '<p class="muted small">Currently using: ' . ($mc['host'] ? 'SMTP ' . e($mc['host']) : 'PHP mail()') . '</p>');
}

function set_notifications(): string
{
    if (is_post()) {
        $act = a_action();
        if ($act === 'save') { set_save(['alert_webhook' => fn($v) => $v === '' || preg_match('~^https://[^\s]+$~', $v) ? $v : false, 'alert_email' => fn($v) => $v === '' || filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : false, 'digest_email' => fn($v) => $v === '' || filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : false], 'settings/notifications', 'settings.notifications'); a_go('settings/notifications', 'Saved.'); }
        if ($act === 'test') {
            $hook = setting('alert_webhook');
            if (!$hook) a_go('settings/notifications', 'Save a webhook URL first.', 'warn');
            $ch = curl_init($hook); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['text' => '✅ Test alert from ' . cfg('site_name') . ' admin']), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            a_go('settings/notifications', $code >= 200 && $code < 300 ? 'Webhook responded OK.' : "Webhook returned HTTP $code.", $code >= 200 && $code < 300 ? 'ok' : 'err');
        }
    }
    return a_card('Where alerts and reports go', a_form('<input type="hidden" name="action" value="save">' . a_field('text', 'alert_webhook', 'Slack / Discord-compatible webhook URL (https)', setting('alert_webhook'), ['hint' => 'Warning and critical alerts post here as {"text": "…"} — Slack incoming webhooks work as-is; for Discord append /slack to the webhook URL.'])
        . '<div class="cols2">' . a_field('email', 'alert_email', 'Alert email', setting('alert_email')) . a_field('email', 'digest_email', 'Weekly digest email', setting('digest_email')) . '</div>' . a_submit('Save')) . (setting('alert_webhook') ? '<hr>' . a_btn('test', 'Send a test webhook message', [], 'ghost') : ''));
}

function set_registration(): string
{
    if (is_post() && a_action() === 'save') { $open = isset($_POST['registration_open']) ? '1' : '0'; audit('settings.registration', 'setting', 'registration_open', ['v' => setting('registration_open', '1')], ['v' => $open]); set_setting('registration_open', $open); a_go('settings/registration', 'Saved.'); }
    return a_card('Public registration', a_form('<input type="hidden" name="action" value="save">' . a_field('checkbox', 'registration_open', 'Allow new members to register', setting('registration_open', '1') === '1') . a_submit('Save'))
        . a_help('Closing registration blocks new sign-ups but does not affect existing members. New accounts must confirm their email before they can use account features. Registration is rate-limited per IP and protected with a honeypot and minimum form time.'));
}

function set_advanced(): string
{
    if (is_post() && a_action() === 'save') { set_save(['self_url' => fn($v) => $v === '' || preg_match('~^https?://[^\s]+$~', $v) ? rtrim($v, '/') : false, 'php_cli' => fn($v) => $v === '' || preg_match('~^[\w/.\-]+$~', $v) ? $v : false], 'settings/advanced', 'settings.advanced'); a_go('settings/advanced', 'Saved.'); }
    return a_card('Advanced', a_form('<input type="hidden" name="action" value="save">' . a_field('text', 'self_url', 'Internal URL for the site audit', setting('self_url'), ['placeholder' => cfg('site_url'), 'hint' => 'If the server cannot reach its own public URL (firewall / no hairpin NAT), set e.g. http://127.0.0.1:8080 so the SEO audit can fetch pages.'])
        . a_field('text', 'php_cli', 'PHP command-line binary', setting('php_cli', 'php'), ['hint' => 'Used by “Run now” in Sync tasks. e.g. php8.2 or /usr/bin/php8.2.']) . a_submit('Save'))
        . '<hr><p class="muted small">Config-file options (database, site URL, cache TTL, trusted proxy, debug) live in <code>app/config.local.php</code> and are intentionally not editable from the web.</p>');
}
