<?php
/** Admin control center: gate (IP allow-list → staff session → optional mandatory 2FA → CSRF), router, shell. */

require_once __DIR__ . '/ui.php';
require_once APP_DIR . '/sources/Google.php';
require_once APP_DIR . '/lib/seoaudit.php';
foreach (['dashboard', 'analytics', 'crypto', 'market', 'seo', 'ads', 'affiliates', 'content', 'users', 'finance', 'alerts', 'reports', 'system'] as $__m) require_once __DIR__ . "/m_$__m.php";

function admin_modules(): array
{
    // first segment => [permission(s) (any-of), handler]
    return [
        ''           => [['dashboard'], 'm_dashboard'],
        'analytics'  => [['analytics.view'], 'm_analytics'],
        'coins'      => [['crypto.view'], 'm_coins'],
        'exchanges'  => [['crypto.view'], 'm_exchanges'],
        'market'     => [['market.view'], 'm_market'],
        'seo'        => [['seo.view', 'seo.meta'], 'm_seo'],
        'ads'        => [['ads.view'], 'm_ads'],
        'affiliates' => [['affiliates.view'], 'm_affiliates'],
        'content'    => [['content.view'], 'm_content'],
        'users'      => [['users.view'], 'm_users'],
        'finance'    => [['finance.view'], 'm_finance'],
        'alerts'     => [['alerts.view'], 'm_alerts'],
        'reports'    => [['reports.view'], 'm_reports'],
        'audit'      => [['security.audit'], 'm_audit'],
        'security'   => [['security.manage'], 'm_security'],
        'settings'   => [['settings.manage'], 'm_settings'],
        'system'     => [['system.view'], 'm_system'],
    ];
}

function admin_nav(): array
{
    // [group label, [[label, path, perm(s)]]]
    return [
        ['Dashboard', [['Overview', '', 'dashboard']]],
        ['Analytics & Insights', [['Traffic', 'analytics', 'analytics.view'], ['Pages', 'analytics/pages', 'analytics.view'], ['Acquisition', 'analytics/acquisition', 'analytics.view'],
            ['Audience', 'analytics/audience', 'analytics.view'], ['Realtime', 'analytics/realtime', 'analytics.view'], ['Web Vitals', 'analytics/vitals', 'analytics.view'], ['Funnel', 'analytics/funnel', 'analytics.view']]],
        ['Crypto Data', [['Coins', 'coins', 'crypto.view'], ['Exchanges', 'exchanges', 'crypto.view'], ['Trading pairs', 'market/pairs', 'market.view'], ['Data quality', 'market/quality', 'market.view'], ['Manual overrides', 'market/overrides', 'market.view']]],
        ['SEO Center', [['Overview', 'seo', ['seo.view']], ['Search queries', 'seo/queries', ['seo.view']], ['Metadata', 'seo/meta', ['seo.meta']], ['Redirects', 'seo/redirects', ['seo.view']], ['404 monitor', 'seo/404', ['seo.view']],
            ['Robots & sitemaps', 'seo/robots', ['seo.view']], ['Crawl stats', 'seo/crawl', ['seo.view']], ['Keywords', 'seo/keywords', ['seo.view']], ['Site audit', 'seo/audit', ['seo.view']]]],
        ['Ads & Monetization', [['Campaigns', 'ads', 'ads.view'], ['Advertisers', 'ads/advertisers', 'ads.view'], ['Inventory & fill', 'ads/inventory', 'ads.view'], ['Performance', 'ads/report', 'ads.view'], ['Affiliate links', 'affiliates', 'affiliates.view']]],
        ['Content & CMS', [['Articles', 'content', 'content.view']]],
        ['Users & CRM', [['Users', 'users', 'users.view'], ['Staff & roles', 'users/staff', 'users.view']]],
        ['API & Providers', [['Provider health', 'market/providers', 'market.view'], ['Sync tasks', 'market', 'market.view']]],
        ['Finance', [['Revenue & P&L', 'finance', 'finance.view'], ['Ledger', 'finance/ledger', 'finance.view']]],
        ['Alerts & Reports', [['Alert center', 'alerts', 'alerts.view'], ['Export center', 'reports', 'reports.view']]],
        ['Security & Compliance', [['Security', 'security', 'security.manage'], ['Audit log', 'audit', 'security.audit']]],
        ['System & Settings', [['Settings', 'settings', 'settings.manage'], ['System health', 'system', 'system.view'], ['Logs', 'system/logs', 'system.view']]],
    ];
}

function a_can_any($perms): bool
{
    foreach ((array)$perms as $p) if (can($p)) return true;
    return false;
}

function admin_counts(): array
{
    static $c = null;
    if ($c !== null) return $c;
    try {
        return $c = [
            'alerts' => (int)val("SELECT COUNT(*) FROM alerts WHERE resolved_at IS NULL AND severity IN ('warning','critical')"),
            'ads' => (int)val("SELECT COUNT(*) FROM ad_campaigns WHERE status = 'pending'"),
            'coins' => (int)val("SELECT COUNT(*) FROM coins WHERE status = 'pending'"),
        ];
    } catch (Throwable $e) { return $c = ['alerts' => 0, 'ads' => 0, 'coins' => 0]; }
}

function admin_render(string $title, string $html, string $active = ''): string
{
    $u = current_user();
    $cnt = admin_counts();
    $badge = ['alerts' => $cnt['alerts'], 'ads' => $cnt['ads'], 'coins' => $cnt['coins']];
    $nav = '';
    $cur = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $cur = preg_replace('~^admin/?~', '', $cur);
    $best = '';
    foreach (admin_nav() as [$g0, $its]) foreach ($its as [$l0, $p0, $pm0]) {
        $pp = trim($p0, '/'); $cc = trim($cur, '/');
        if (a_can_any($pm0) && ($pp === $cc || ($pp !== '' && strpos($cc . '/', $pp . '/') === 0)) && strlen($pp) >= strlen($best)) $best = $pp;
    }
    foreach (admin_nav() as [$group, $items]) {
        $li = '';
        foreach ($items as [$label, $path, $perm]) {
            if (!a_can_any($perm)) continue;
            $on = trim($path, '/') === $best && (trim($cur, '/') !== '' || $path === '');
            $b = '';
            $key = ['alerts' => 'alerts', 'ads' => 'ads', 'coins' => 'coins'][$path] ?? null;
            if ($key && $badge[$key] > 0) $b = '<em>' . $badge[$key] . '</em>';
            $li .= '<a class="' . ($on ? 'on' : '') . '" href="' . e(a_url($path)) . '">' . e($label) . $b . '</a>';
        }
        if ($li) $nav .= '<div class="ng"><h4>' . e($group) . '</h4>' . $li . '</div>';
    }
    $flash = '';
    foreach (flash_take() as [$t, $m]) $flash .= '<div class="notice ' . e($t) . '">' . e($m) . '</div>';
    ob_start(); ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= e($title) ?> · Admin · <?= e(cfg('site_name')) ?></title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/admin.css?v=1">
<script>try{var t=localStorage.getItem('theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head><body class="adm">
<aside class="side" id="side">
  <a class="brand" href="<?= e(a_url()) ?>"><img src="/assets/img/logo.svg" width="26" height="26" alt=""><span><?= e(cfg('site_name')) ?> <small>Control Center</small></span></a>
  <?= $nav ?>
</aside>
<div class="main">
  <header class="top">
    <button class="burger" onclick="document.getElementById('side').classList.toggle('open')" aria-label="Menu">☰</button>
    <h1><?= e($title) ?></h1>
    <div class="top-r">
      <?php if ($cnt['alerts'] > 0 && can('alerts.view')): ?><a class="pill bad" href="<?= e(a_url('alerts')) ?>">⚠ <?= (int)$cnt['alerts'] ?> alert<?= $cnt['alerts'] > 1 ? 's' : '' ?></a><?php endif; ?>
      <a class="btn sm ghost" href="/" target="_blank" rel="noopener">View site ↗</a>
      <span class="who" title="<?= e($u['email'] ?? '') ?>"><?= e($u['name'] ?: ($u['email'] ?? '')) ?> <small><?= e(rbac_roles()[$u['role'] ?? 'user']['label'] ?? '') ?></small></span>
      <a class="btn sm ghost" href="/account/">Account</a>
      <?= a_btn('logout', 'Log out', [], 'sm ghost', null, '/logout/') ?>
    </div>
  </header>
  <main class="content"><?= $flash ?><?= $html ?></main>
  <footer class="foot">Times shown in UTC · <?= e(cfg('site_name')) ?> admin · build <?= e(date('Y-m-d', filemtime(__FILE__))) ?></footer>
</div>
</body></html><?php
    return ob_get_clean();
}

function admin_page(string $title, string $html): void { echo admin_render($title, $html); }

function admin_dispatch(string $path): void
{
    // 1) optional IP allow-list: admins outside it see a plain 404 (do not reveal the panel exists)
    $allow = trim((string)setting('admin_ip_allow', ''));
    if ($allow !== '' && !ip_in_list(client_ip(), $allow)) { http_response_code(404); echo '<h1>Not Found</h1>'; return; }

    // 2) must be a signed-in, active staff member
    $u = current_user();
    if (!$u || $u['status'] !== 'active' || !is_staff()) {
        if (!$u) go('/login/?next=' . rawurlencode('/admin/'));
        http_response_code(403);
        echo '<h1>Forbidden</h1><p>Your account does not have admin access.</p>';
        return;
    }

    // 3) mandatory two-factor for staff (Security → "Require 2FA")
    if (setting('admin_require_2fa', '1') === '1' && !$u['totp_enabled']) {
        flash('Staff accounts must enable two-factor authentication before using the admin panel.', 'warn');
        go('/account/security/');
    }

    // 4) CSRF on every state-changing request
    if (is_post()) csrf_check();

    $seg = array_values(array_filter(explode('/', trim(substr($path, 6), '/')), 'strlen'));
    $mod = $seg[0] ?? '';
    $sub = $seg[1] ?? '';
    $mods = admin_modules();
    if (!isset($mods[$mod]) || count($seg) > 2) { http_response_code(404); admin_page('Not found', '<div class="card"><h2>Page not found</h2><p><a href="' . e(a_url()) . '">Back to dashboard</a></p></div>'); return; }
    [$perms, $fn] = $mods[$mod];
    if (!a_can_any($perms)) { require_perm($perms[0]); return; }
    $fn($sub);
}
