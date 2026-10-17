<?php
function m_dashboard(string $sub): void
{
    traffic_rollup_fresh();
    $u = current_user();
    $today = gmdate('Y-m-d'); $yest = gmdate('Y-m-d', time() - 86400);
    [$labels, $ser] = traffic_series(gmdate('Y-m-d', time() - 29 * 86400), $today);
    $T = $ser[$today]; $Y = $ser[$yest];
    $h = '';

    // setup checklist: things that quietly cost money or safety if left undone
    $todo = [];
    if (!$u['totp_enabled']) $todo[] = 'Turn on two-factor authentication for your own account (<a href="/account/security/">Account → Security</a>).';
    if (setting('admin_require_2fa', '1') !== '1') $todo[] = 'Mandatory 2FA for staff is switched off (<a href="' . e(a_url('security')) . '">Security</a>).';
    if (empty(mail_config()['host'])) $todo[] = 'Outgoing email is not configured — verification and password-reset emails depend on PHP <code>mail()</code> (<a href="' . e(a_url('settings/email')) . '">Settings → Email</a>).';
    if (!Google::configured('gsc')) $todo[] = 'Google Search Console is not connected, so SEO queries and CTR are unavailable (<a href="' . e(a_url('settings/integrations')) . '">Settings → Integrations</a>).';
    if (!setting('ga4_id') && !Google::configured('ga4')) $todo[] = 'Google Analytics is not configured (optional — first-party analytics already work).';
    if (time() - (int)kv_get('last_run_markets', 0) > 3600) $todo[] = 'Market data has not synced in over an hour — is the cron job installed? (<a href="' . e(a_url('market')) . '">Sync tasks</a>)';
    if ($todo && can('settings.manage')) $h .= '<div class="warnbox"><strong>Setup checklist</strong><ul style="margin:6px 0 0 18px">' . implode('', array_map(fn($t) => "<li>$t</li>", $todo)) . '</ul></div>';

    // KPIs
    $new = (int)val("SELECT COUNT(*) FROM users WHERE created_at >= UTC_DATE()");
    $newY = (int)val("SELECT COUNT(*) FROM users WHERE created_at >= UTC_DATE() - INTERVAL 1 DAY AND created_at < UTC_DATE()");
    $mStart = gmdate('Y-m-01');
    $inc = (float)val("SELECT COALESCE(SUM(amount),0) FROM ledger WHERE type = 'income' AND day >= ?", [$mStart]);
    $exp = (float)val("SELECT COALESCE(SUM(amount),0) FROM ledger WHERE type = 'expense' AND day >= ?", [$mStart]);
    $cnt = admin_counts();
    $gb = (int)$T['googlebot'];
    $h .= '<div class="kpis">'
        . a_kpi('Page views today', a_n($T['views']), a_delta($T['views'], $Y['views']))
        . a_kpi('Visitors today', a_n($T['visitors']), a_delta($T['visitors'], $Y['visitors']))
        . a_kpi('Organic sessions today', a_n($T['organic_sessions']), a_delta($T['organic_sessions'], $Y['organic_sessions']))
        . a_kpi('Googlebot hits today', a_n($gb), a_delta($gb, $Y['googlebot']))
        . a_kpi('New signups today', a_n($new), a_delta($new, $newY))
        . (can('finance.view') ? a_kpi('Net revenue (month to date)', a_money($inc - $exp), 'Income ' . a_money($inc) . ' · Expenses ' . a_money($exp)) : '')
        . a_kpi('Open alerts', a_n($cnt['alerts']), '<a href="' . e(a_url('alerts')) . '">View</a>', $cnt['alerts'] ? 'warn' : '')
        . a_kpi('Awaiting approval', a_n($cnt['ads'] + $cnt['coins']), $cnt['ads'] . ' ad campaigns · ' . $cnt['coins'] . ' coins', ($cnt['ads'] + $cnt['coins']) ? 'warn' : '')
        . '</div>';

    // traffic chart
    $h .= '<div class="grid g21"><div>'
        . a_card('Traffic — last 30 days', svg_line([
            ['name' => 'Page views', 'values' => array_map('intval', array_column($ser, 'views')), 'color' => '#3861fb'],
            ['name' => 'Visitors', 'values' => array_map('intval', array_column($ser, 'visitors')), 'color' => '#16c784'],
            ['name' => 'Organic sessions', 'values' => array_map('intval', array_column($ser, 'organic_sessions')), 'color' => '#f5a623'],
        ], $labels), '<a class="btn sm ghost" href="' . e(a_url('analytics')) . '">Open analytics</a>' . a_source('first-party analytics'))
        . '</div><div>';

    $src = rows("SELECT COALESCE(src,'direct') s, COUNT(*) n FROM pageviews WHERE is_bot = 0 AND landing = 1 AND day >= UTC_DATE() - INTERVAL 29 DAY GROUP BY s ORDER BY n DESC");
    $h .= a_card('Traffic sources (30d sessions)', svg_donut(array_map(fn($r) => [ucfirst($r['s']), $r['n']], $src))) . '</div></div>';

    // second row
    $top = rows("SELECT path, COUNT(*) n FROM pageviews WHERE is_bot = 0 AND day = UTC_DATE() GROUP BY path ORDER BY n DESC LIMIT 8");
    $h .= '<div class="grid g3">';
    $h .= a_card('Top pages today', hbars(array_map(fn($r) => [$r['path'], $r['n']], $top)));

    $gsc = rows('SELECT query, SUM(clicks) c, SUM(impressions) i, AVG(position) p FROM gsc_queries WHERE day >= UTC_DATE() - INTERVAL 28 DAY GROUP BY query ORDER BY c DESC, i DESC LIMIT 8');
    $h .= a_card('Top search queries (28d)', $gsc
        ? a_table(['query' => 'Query', 'c' => ['label' => 'Clicks', 'cls' => 'num'], 'i' => ['label' => 'Impr.', 'cls' => 'num'], 'p' => ['label' => 'Pos.', 'cls' => 'num', 'fmt' => fn($r, $v) => number_format((float)$v, 1)]], $gsc)
        : '<p class="muted pad">Connect Google Search Console in Settings → Integrations to see queries, CTR and positions here.</p>', '', '');

    $tasks = [];
    foreach (['markets' => 5, 'tickers' => 2, 'global' => 10, 'history' => 5] as $t => $mins) {
        $last = (int)kv_get("last_run_$t", 0); $age = $last ? time() - $last : null;
        $tasks[] = ['t' => $t, 'last' => $last ? time_ago(gmdate('Y-m-d H:i:s', $last)) : 'never', 'st' => $age === null ? 'never' : ($age > $mins * 60 * 4 ? 'stale' : 'ok')];
    }
    $h .= a_card('Data freshness', a_table(['t' => 'Task', 'last' => 'Last run', 'st' => ['label' => 'Status', 'fmt' => fn($r, $v) => a_badge($v, $v === 'ok' ? 'ok' : 'bad')]], $tasks), '<a class="btn sm ghost" href="' . e(a_url('market')) . '">Details</a>');
    $h .= '</div>';

    // third row
    $h .= '<div class="grid g2">';
    $al = rows("SELECT severity, message, created_at FROM alerts WHERE resolved_at IS NULL ORDER BY FIELD(severity,'critical','warning','info'), id DESC LIMIT 6");
    $h .= a_card('Open alerts', a_table(['severity' => ['label' => '', 'fmt' => fn($r, $v) => a_status_badge($v)], 'message' => ['label' => 'Alert', 'cls' => 'wrap'], 'created_at' => ['label' => 'Since', 'fmt' => fn($r, $v) => e(time_ago($v))]], $al, 'All clear — no open alerts. ✅'));
    if (can('ads.view')) {
        $ad = row("SELECT COALESCE(SUM(impressions),0) i, COALESCE(SUM(clicks),0) c FROM ad_stats WHERE day >= UTC_DATE() - INTERVAL 6 DAY");
        $live = (int)val("SELECT COUNT(*) FROM ad_campaigns WHERE status = 'approved'");
        $h .= a_card('Advertising — last 7 days', '<div class="kpis" style="margin:0">' . a_kpi('Live campaigns', (string)$live) . a_kpi('Impressions', a_n($ad['i'])) . a_kpi('Clicks', a_n($ad['c']), 'CTR ' . a_pct($ad['c'], $ad['i'], 2)) . '</div>', '<a class="btn sm ghost" href="' . e(a_url('ads')) . '">Manage ads</a>');
    } else $h .= '<div></div>';
    $h .= '</div>';

    // quick actions
    $qa = '';
    foreach ([['coins', 'Add / review coins', 'crypto.view'], ['content/edit', 'Write an article', 'content.edit'], ['seo/meta', 'Edit page metadata', 'seo.meta'], ['ads/edit', 'New ad campaign', 'ads.edit'], ['seo/redirects', 'Add a redirect', 'seo.view'], ['reports', 'Export data', 'reports.view']] as [$p, $l, $perm])
        if (can($perm)) $qa .= '<a class="btn ghost" href="' . e(a_url($p)) . '">' . e($l) . '</a> ';
    if ($qa) $h .= a_card('Quick actions', $qa);

    admin_page('Dashboard', $h);
}
