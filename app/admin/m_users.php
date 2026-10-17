<?php
/** Users & CRM: member list/segments, account actions, staff roles. Password hashes, tokens and 2FA secrets are never displayed or exported. */

function m_users(string $sub): void
{
    if ($sub === 'staff') { usr_staff(); return; }
    if ($sub === 'detail' || a_int('id') > 0) { usr_detail(a_int('id')); return; }
    if ($sub !== '') { http_response_code(404); admin_page('Not found', '<div class="card">Unknown page.</div>'); return; }

    $q = a_in('q'); $role = a_in('role'); $st = a_in('status'); $ver = a_in('verified'); $nl = a_in('newsletter');
    $w = ['1=1']; $p = [];
    if ($q !== '') { $w[] = '(email LIKE ? OR name LIKE ?)'; $p[] = $p[] = '%' . addcslashes($q, '%_') . '%'; }
    if (isset(rbac_roles()[$role])) { $w[] = 'role = ?'; $p[] = $role; }
    if (in_array($st, ['active', 'suspended'], true)) { $w[] = 'status = ?'; $p[] = $st; }
    if ($ver === '1') $w[] = 'email_verified_at IS NOT NULL'; elseif ($ver === '0') $w[] = 'email_verified_at IS NULL';
    if ($nl === '1') $w[] = 'newsletter = 1';
    if (a_in('pending_delete') === '1') $w[] = 'deletion_requested_at IS NOT NULL';
    $W = implode(' AND ', $w);
    if (a_in('export') === '1' && can('users.export')) {
        audit('users.export', 'user', null, null, ['filter' => $W, 'rows' => (int)val("SELECT COUNT(*) FROM users WHERE $W", $p)]);
        a_csv('users-' . gmdate('Ymd') . '.csv', ['id', 'email', 'name', 'role', 'status', 'verified', 'newsletter', 'country', 'created_at', 'last_login_at'],
            rows("SELECT id, email, name, role, status, email_verified_at IS NOT NULL, newsletter, country, created_at, last_login_at FROM users WHERE $W ORDER BY id", $p));
    }
    $per = 50; $page = max(1, a_int('page', 1));
    $total = (int)val("SELECT COUNT(*) FROM users WHERE $W", $p);
    $list = rows("SELECT id, email, name, role, status, email_verified_at, newsletter, totp_enabled, created_at, last_login_at, deletion_requested_at FROM users WHERE $W ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
    $s = row("SELECT COUNT(*) t, SUM(email_verified_at IS NOT NULL) v, SUM(newsletter) n, SUM(created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY) w, SUM(last_login_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) a FROM users WHERE role = 'user'");
    $h = '<div class="kpis">' . a_kpi('Members', a_n($s['t'])) . a_kpi('Verified email', a_pct($s['v'], $s['t']), a_n($s['v'])) . a_kpi('Newsletter opt-in', a_pct($s['n'], $s['t']), a_n($s['n'])) . a_kpi('New (7 days)', a_n($s['w'])) . a_kpi('Active (30 days)', a_n($s['a'])) . '</div>';
    $sel = fn($name, $arr, $cur, $all) => '<select name="' . $name . '"><option value="">' . $all . '</option>' . implode('', array_map(fn($k, $l) => '<option value="' . e($k) . '"' . ((string)$cur === (string)$k ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($arr), $arr)) . '</select>';
    $h .= '<form class="filters" method="get"><input type="search" name="q" value="' . e($q) . '" placeholder="Search email or name…">' . $sel('role', array_map(fn($r) => $r['label'], rbac_roles()), $role, 'Any role') . $sel('status', ['active' => 'Active', 'suspended' => 'Suspended'], $st, 'Any status')
        . $sel('verified', ['1' => 'Verified', '0' => 'Unverified'], $ver, 'Email verified?') . $sel('newsletter', ['1' => 'Subscribed'], $nl, 'Newsletter') . '<label class="chk" style="margin:0"><input type="checkbox" name="pending_delete" value="1"' . (a_in('pending_delete') === '1' ? ' checked' : '') . '> Deletion pending</label><button class="btn" type="submit">Filter</button>'
        . (can('users.export') ? ' <button class="btn ghost" name="export" value="1" type="submit">Export CSV</button>' : '') . '</form>';
    $h .= a_card(a_n($total) . ' accounts', a_table(['email' => ['label' => 'Account', 'fmt' => fn($r, $v) => '<a href="' . e(a_url('users/detail', ['id' => $r['id']])) . '"><strong>' . e($v) . '</strong></a>' . ($r['name'] ? '<br><span class="muted small">' . e($r['name']) . '</span>' : '')],
        'role' => ['label' => 'Role', 'fmt' => fn($r, $v) => $v === 'user' ? '<span class="muted">member</span>' : a_badge(rbac_roles()[$v]['label'] ?? $v, 'info')], 'status' => ['label' => 'Status', 'fmt' => fn($r, $v) => a_status_badge($v) . ($r['deletion_requested_at'] ? ' ' . a_badge('deleting', 'bad') : '')],
        'email_verified_at' => ['label' => 'Email', 'fmt' => fn($r, $v) => $v ? a_badge('✓', 'ok') : a_badge('unverified', 'warn')], 'newsletter' => ['label' => 'News', 'fmt' => fn($r, $v) => $v ? '✓' : ''], 'totp_enabled' => ['label' => '2FA', 'fmt' => fn($r, $v) => $v ? '✓' : ''],
        'created_at' => ['label' => 'Joined', 'fmt' => fn($r, $v) => e(substr($v, 0, 10))], 'last_login_at' => ['label' => 'Last login', 'fmt' => fn($r, $v) => $v ? e(time_ago($v)) : '—']], $list, 'No accounts match.'));
    $h .= a_pager($page, (int)ceil($total / $per), 'users', array_filter(['q' => $q, 'role' => $role, 'status' => $st, 'verified' => $ver, 'newsletter' => $nl]));
    admin_page('Users', $h);
}

function usr_super_count(): int { return (int)val("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active'"); }

function usr_detail(int $id): void
{
    require_perm('users.view');
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$u) { http_response_code(404); admin_page('Not found', '<div class="card">User not found. <a href="' . e(a_url('users')) . '">Back</a></div>'); return; }
    $me = current_user(); $self = (int)$me['id'] === $id;
    if (is_post()) {
        $act = a_action();
        $back = fn($m, $t = 'ok') => a_go('users/detail', $m, $t, ['id' => $id]);
        $isStaffTarget = $u['role'] !== 'user';
        if (in_array($act, ['suspend', 'unsuspend', 'logout', 'verify', 'reset_link', 'notes'], true)) {
            require_perm($act === 'notes' ? 'users.moderate' : 'users.moderate');
            if ($isStaffTarget && !can('users.roles') && !$self) $back('Only a Super Admin can act on staff accounts.', 'err');
        }
        if ($act === 'suspend') {
            if ($self) $back('You cannot suspend yourself.', 'err');
            if ($u['role'] === 'super_admin' && usr_super_count() <= 1) $back('That is the last active Super Admin.', 'err');
            q("UPDATE users SET status = 'suspended', sessions_valid_after = UTC_TIMESTAMP() WHERE id = ?", [$id]); audit('user.suspend', 'user', $id, ['status' => $u['status']], ['status' => 'suspended']); $back('Account suspended and signed out everywhere.');
        }
        if ($act === 'unsuspend') { q("UPDATE users SET status = 'active' WHERE id = ?", [$id]); audit('user.unsuspend', 'user', $id, ['status' => $u['status']], ['status' => 'active']); $back('Account re-activated.'); }
        if ($act === 'logout') { q('UPDATE users SET sessions_valid_after = UTC_TIMESTAMP() WHERE id = ?', [$id]); audit('user.force_logout', 'user', $id); $back('All sessions for this account were invalidated.'); }
        if ($act === 'verify') { q('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ? AND email_verified_at IS NULL', [$id]); audit('user.verify_email', 'user', $id); $back('Email marked as verified.'); }
        if ($act === 'reset_link') {
            $t = token_create($id, 'reset', 60);
            $ok = send_mail($u['email'], 'Reset your ' . cfg('site_name') . ' password', "Hi,\n\nAn administrator started a password reset for your account. Use this link within 1 hour:\n\n" . base_url() . '/reset/?token=' . $t . "\n\nIf you did not expect this, contact support.\n");
            audit('user.send_reset', 'user', $id); $back($ok ? 'Password-reset email sent.' : 'Email could not be sent — check Settings → Email.', $ok ? 'ok' : 'warn');
        }
        if ($act === 'notes') { q('UPDATE users SET admin_notes = ? WHERE id = ?', [mb_substr(a_in('admin_notes'), 0, 4000) ?: null, $id]); audit('user.notes', 'user', $id); $back('Notes saved.'); }
        if ($act === 'role') {
            require_perm('users.roles');
            $r = a_in('role');
            if (!isset(rbac_roles()[$r])) $back('Unknown role.', 'err');
            if ($self && $r !== $u['role']) $back('You cannot change your own role.', 'err');
            if ($u['role'] === 'super_admin' && $r !== 'super_admin' && usr_super_count() <= 1) $back('That is the last active Super Admin.', 'err');
            if ($r !== 'user' && !$u['email_verified_at']) $back('Verify the account’s email before giving it staff access.', 'err');
            q('UPDATE users SET role = ?, sessions_valid_after = UTC_TIMESTAMP() WHERE id = ?', [$r, $id]);
            audit('user.role', 'user', $id, ['role' => $u['role']], ['role' => $r]); $back('Role changed to ' . rbac_roles()[$r]['label'] . '. Their sessions were reset so the change applies immediately.');
        }
        if ($act === 'reset_2fa') {
            require_perm('users.roles');
            q('UPDATE users SET totp_enabled = 0, totp_secret = NULL, sessions_valid_after = UTC_TIMESTAMP() WHERE id = ?', [$id]); q("DELETE FROM user_tokens WHERE user_id = ? AND type = 'recovery'", [$id]);
            audit('user.reset_2fa', 'user', $id); $back('Two-factor authentication was reset. They must set it up again.');
        }
        if ($act === 'delete') {
            require_perm('users.manage');
            if ($self) $back('You cannot delete yourself.', 'err');
            if ($u['role'] === 'super_admin') $back('Demote a Super Admin before deleting.', 'err');
            if (a_in('confirm_email') !== $u['email']) $back('Type the account’s email to confirm deletion.', 'err');
            foreach (['user_watchlist', 'user_tokens'] as $t) q("DELETE FROM $t WHERE user_id = ?", [$id]);
            q('UPDATE login_history SET user_id = NULL, email = NULL, ip = NULL, user_agent = NULL WHERE user_id = ?', [$id]);
            q('UPDATE articles SET author_id = NULL WHERE author_id = ?', [$id]);
            q('DELETE FROM users WHERE id = ?', [$id]);
            audit('user.delete', 'user', $id, ['email' => $u['email'], 'role' => $u['role']]);
            a_go('users', 'Account deleted.');
        }
    }
    $wl = (int)val('SELECT COUNT(*) FROM user_watchlist WHERE user_id = ?', [$id]);
    $h = '<p><a href="' . e(a_url('users')) . '">← All users</a></p><div class="kpis">' . a_kpi('Role', e(rbac_roles()[$u['role']]['label'] ?? $u['role'])) . a_kpi('Status', a_status_badge($u['status'])) . a_kpi('Email', $u['email_verified_at'] ? a_badge('verified', 'ok') : a_badge('unverified', 'warn'))
        . a_kpi('2FA', $u['totp_enabled'] ? 'On' : 'Off') . a_kpi('Watchlist', (string)$wl) . a_kpi('Joined', e(substr($u['created_at'], 0, 10)), 'last login ' . ($u['last_login_at'] ? e(time_ago($u['last_login_at'])) : 'never')) . '</div>';
    $h .= a_card($u['email'], '<p><b>Name:</b> ' . e($u['name'] ?: '—') . ' · <b>Newsletter:</b> ' . ($u['newsletter'] ? 'subscribed' : 'no') . ' · <b>Country:</b> ' . e($u['country'] ?: '—') . ' · <b>Last IP:</b> ' . e($u['last_login_ip'] ?: '—')
        . ($u['deletion_requested_at'] ? '<br><b class="down">Deletion requested ' . e($u['deletion_requested_at']) . ' UTC — purged automatically after 7 days.</b>' : '') . '</p>');
    $acts = '';
    if (can('users.moderate')) {
        $acts .= $u['status'] === 'active' ? a_btn('suspend', 'Suspend', [], 'danger', 'Suspend this account and sign it out everywhere?') : a_btn('unsuspend', 'Re-activate', [], 'ok');
        $acts .= a_btn('logout', 'Force sign-out', [], 'ghost', 'Invalidate all sessions?') . a_btn('reset_link', 'Email password reset', [], 'ghost');
        if (!$u['email_verified_at']) $acts .= a_btn('verify', 'Mark email verified', [], 'ghost');
    }
    if (can('users.roles') && $u['totp_enabled']) $acts .= a_btn('reset_2fa', 'Reset 2FA', [], 'ghost', 'Remove two-factor for this account?');
    if ($acts) $h .= a_card('Actions', $acts);
    if (can('users.roles') && !$self) $h .= a_card('Role', a_form('<input type="hidden" name="action" value="role"><div class="filters">' . a_field('select', 'role', 'Role', $u['role'], ['options' => array_map(fn($r) => $r['label'], rbac_roles())]) . a_submit('Change role') . '</div>' . a_help('Role changes sign the user out so the new permissions apply on their next login. Staff must have a verified email and (if enforced) 2FA.')));
    $h .= a_card('Internal notes', a_form('<input type="hidden" name="action" value="notes">' . a_field('textarea', 'admin_notes', '', $u['admin_notes'], ['rows' => 3, 'attrs' => can('users.moderate') ? '' : ' disabled']) . (can('users.moderate') ? a_submit('Save notes', 'ghost') : '')));
    $hist = rows('SELECT created_at, ip, success, reason, user_agent FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT 25', [$id]);
    $h .= a_card('Sign-in history', a_table(['created_at' => 'UTC', 'ip' => 'IP', 'success' => ['label' => 'Result', 'fmt' => fn($r, $v) => $v ? a_badge('ok', 'ok') : a_badge($r['reason'] ?: 'failed', 'bad')], 'user_agent' => ['label' => 'Browser', 'cls' => 'wrap small', 'fmt' => fn($r, $v) => e(mb_strimwidth((string)$v, 0, 80, '…'))]], $hist));
    $au = rows("SELECT created_at, actor, action, after_json FROM audit_log WHERE entity = 'user' AND entity_id = ? ORDER BY id DESC LIMIT 15", [(string)$id]);
    if (can('security.audit')) $h .= a_card('Admin actions on this account', a_table(['created_at' => 'UTC', 'actor' => 'By', 'action' => 'Action', 'after_json' => ['label' => 'Detail', 'cls' => 'wrap small mono', 'fmt' => fn($r, $v) => e(mb_strimwidth((string)$v, 0, 120, '…'))]], $au));
    if (can('users.manage') && !$self && $u['role'] !== 'super_admin') $h .= a_card('Danger zone', a_form('<input type="hidden" name="action" value="delete">' . a_field('text', 'confirm_email', 'Type the email to confirm permanent deletion', '', ['placeholder' => $u['email']]) . a_submit('Delete account permanently', 'danger')));
    admin_page($u['email'], $h);
}

function usr_staff(): void
{
    require_perm('users.view');
    if (is_post() && a_action() === 'promote') {
        require_perm('users.roles');
        $u = row('SELECT * FROM users WHERE email = ?', [strtolower(a_in('email'))]);
        $r = a_in('role');
        if (!$u) a_go('users/staff', 'No registered account has that email. Ask them to register first.', 'err');
        if (!isset(rbac_roles()[$r]) || $r === 'user') a_go('users/staff', 'Choose a staff role.', 'err');
        if (!$u['email_verified_at']) a_go('users/staff', 'That account has not verified its email yet.', 'err');
        q('UPDATE users SET role = ?, sessions_valid_after = UTC_TIMESTAMP() WHERE id = ?', [$r, $u['id']]);
        audit('user.role', 'user', $u['id'], ['role' => $u['role']], ['role' => $r]);
        a_go('users/staff', $u['email'] . ' is now ' . rbac_roles()[$r]['label'] . '.');
    }
    $staff = rows("SELECT id, email, name, role, status, totp_enabled, last_login_at FROM users WHERE role <> 'user' ORDER BY FIELD(role,'super_admin') DESC, email");
    $h = a_card(count($staff) . ' staff accounts', a_table(['email' => ['label' => 'Staff', 'fmt' => fn($r, $v) => '<a href="' . e(a_url('users/detail', ['id' => $r['id']])) . '">' . e($v) . '</a>'], 'role' => ['label' => 'Role', 'fmt' => fn($r, $v) => a_badge(rbac_roles()[$v]['label'] ?? $v, 'info')],
        'status' => ['label' => 'Status', 'fmt' => fn($r, $v) => a_status_badge($v)], 'totp_enabled' => ['label' => '2FA', 'fmt' => fn($r, $v) => $v ? a_badge('on', 'ok') : a_badge('OFF', 'bad')], 'last_login_at' => ['label' => 'Last login', 'fmt' => fn($r, $v) => $v ? e(time_ago($v)) : 'never']], $staff));
    if (can('users.roles')) $h .= a_card('Give an existing member staff access', a_form('<input type="hidden" name="action" value="promote"><div class="filters">' . a_field('email', 'email', 'Member email', '', ['required' => 1]) . a_field('select', 'role', 'Role', 'seo_manager', ['options' => array_map(fn($r) => $r['label'], array_filter(rbac_roles(), fn($k) => $k !== 'user', ARRAY_FILTER_USE_KEY))]) . a_submit('Grant access') . '</div>'));
    // role matrix
    $perms = ['dashboard', 'analytics.view', 'crypto.view', 'crypto.edit', 'crypto.flag', 'market.view', 'market.edit', 'seo.view', 'seo.meta', 'seo.redirects', 'seo.robots', 'ads.view', 'ads.edit', 'ads.approve', 'ads.network', 'affiliates.view', 'affiliates.edit',
        'content.view', 'content.edit', 'content.publish', 'users.view', 'users.moderate', 'users.roles', 'users.manage', 'users.export', 'finance.view', 'finance.edit', 'alerts.view', 'alerts.manage', 'reports.view', 'security.manage', 'security.audit', 'settings.manage', 'system.view'];
    $rows = []; foreach ($perms as $pm) { $r = ['perm' => $pm]; foreach (rbac_roles() as $k => $meta) if ($k !== 'user') $r[$k] = role_has($k, $pm) ? '●' : ''; $rows[] = $r; }
    $cols = ['perm' => ['label' => 'Permission', 'cls' => 'mono']]; foreach (rbac_roles() as $k => $meta) if ($k !== 'user') $cols[$k] = ['label' => $meta['label'], 'cls' => 'num'];
    $h .= a_card('Role permissions', a_table($cols, $rows) . a_help('Roles are defined in code (app/lib/rbac.php) so they are reviewed with every release. Principle of least privilege: give people the narrowest role that does their job.'));
    admin_page('Staff & roles', $h);
}
