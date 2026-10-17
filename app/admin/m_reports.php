<?php
/** Export center: CSV downloads of every reporting dataset, plus the weekly e-mail digest. */

function report_defs(): array
{
    // key => [title, description, perm, range?, header[], sql generator(from,to): rows]
    return [
        'traffic' => ['Daily traffic', 'Views, visitors, sessions, bounces, organic sessions and bot hits per day (first-party).', 'analytics.view', true,
            ['day', 'views', 'visitors', 'sessions', 'bounces', 'duration_seconds', 'new_visitors', 'organic_sessions', 'bot_views', 'googlebot'],
            fn($f, $t) => rows('SELECT day, views, visitors, sessions, bounces, duration_sum, new_visitors, organic_sessions, bot_views, googlebot FROM traffic_daily WHERE day BETWEEN ? AND ? ORDER BY day', [$f, $t])],
        'pages' => ['Top pages', 'Page views, visitors and entrances per URL (humans only).', 'analytics.view', true, ['path', 'type', 'views', 'visitors', 'entrances', 'avg_ms'],
            fn($f, $t) => rows('SELECT path, ptype, COUNT(*), COUNT(DISTINCT vh), SUM(landing), ROUND(AVG(ms)) FROM pageviews WHERE is_bot = 0 AND status < 400 AND day BETWEEN ? AND ? GROUP BY path, ptype ORDER BY 3 DESC LIMIT 5000', [$f, $t])],
        'sources' => ['Traffic sources', 'Sessions by channel and referrer host.', 'analytics.view', true, ['channel', 'referrer', 'sessions', 'new_visitors'],
            fn($f, $t) => rows("SELECT COALESCE(src,'direct'), COALESCE(ref_host,''), COUNT(*), SUM(is_new) FROM pageviews WHERE is_bot = 0 AND landing = 1 AND day BETWEEN ? AND ? GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 5000", [$f, $t])],
        'errors' => ['404 / error URLs', 'Not-found URLs requested by visitors and bots.', 'seo.view', true, ['path', 'human_hits', 'bot_hits', 'googlebot_hits', 'last_seen'],
            fn($f, $t) => rows("SELECT path, SUM(is_bot = 0), SUM(is_bot = 1), SUM(bot_name = 'Googlebot'), MAX(ts) FROM pageviews WHERE status = 404 AND day BETWEEN ? AND ? GROUP BY path ORDER BY 2 DESC, 3 DESC LIMIT 5000", [$f, $t])],
        'gsc_queries' => ['Search Console — queries', 'Clicks, impressions and position per query (Google data).', 'seo.view', true, ['query', 'clicks', 'impressions', 'avg_position'],
            fn($f, $t) => rows('SELECT query, SUM(clicks), SUM(impressions), ROUND(AVG(position),2) FROM gsc_queries WHERE day BETWEEN ? AND ? GROUP BY query ORDER BY 2 DESC, 3 DESC LIMIT 10000', [$f, $t])],
        'gsc_pages' => ['Search Console — pages', 'Clicks, impressions and position per page (Google data).', 'seo.view', true, ['page', 'clicks', 'impressions', 'avg_position'],
            fn($f, $t) => rows('SELECT page, SUM(clicks), SUM(impressions), ROUND(AVG(position),2) FROM gsc_pages WHERE day BETWEEN ? AND ? GROUP BY page ORDER BY 2 DESC, 3 DESC LIMIT 10000', [$f, $t])],
        'ads' => ['Ad performance', 'Impressions, clicks, invalid clicks and usage revenue per campaign.', 'ads.view', true, ['campaign', 'advertiser', 'placement', 'pricing', 'rate', 'impressions', 'clicks', 'invalid_clicks', 'usage_revenue'],
            fn($f, $t) => rows("SELECT c.name, COALESCE(a.name,''), c.placement, c.pricing, c.rate, SUM(s.impressions), SUM(s.clicks), SUM(s.invalid_clicks),
                ROUND(CASE c.pricing WHEN 'cpm' THEN c.rate * SUM(s.impressions) / 1000 WHEN 'cpc' THEN c.rate * SUM(s.clicks) ELSE 0 END, 2)
                FROM ad_stats s JOIN ad_campaigns c ON c.id = s.campaign_id LEFT JOIN ad_advertisers a ON a.id = c.advertiser_id WHERE s.day BETWEEN ? AND ? GROUP BY c.id ORDER BY 6 DESC", [$f, $t])],
        'affiliates' => ['Affiliate performance', 'Clicks, conversions and commission per link.', 'affiliates.view', true, ['link', 'partner', 'clicks', 'conversions', 'commission'],
            fn($f, $t) => rows('SELECT l.name, COALESCE(l.partner,\'\'), (SELECT COUNT(*) FROM affiliate_clicks k WHERE k.link_id = l.id AND k.day BETWEEN ? AND ?), (SELECT COALESCE(SUM(conversions),0) FROM affiliate_conversions v WHERE v.link_id = l.id AND v.day BETWEEN ? AND ?), (SELECT COALESCE(SUM(commission),0) FROM affiliate_conversions v WHERE v.link_id = l.id AND v.day BETWEEN ? AND ?) FROM affiliate_links l', [$f, $t, $f, $t, $f, $t])],
        'ledger' => ['Finance ledger', 'All income and expense entries.', 'finance.view', true, ['date', 'type', 'category', 'partner', 'amount_usd', 'note'],
            fn($f, $t) => rows('SELECT day, type, category, partner, amount, note FROM ledger WHERE day BETWEEN ? AND ? ORDER BY day, id', [$f, $t])],
        'coins' => ['Coin catalogue (snapshot)', 'Every coin with status, flags, price and market cap right now.', 'crypto.view', false, ['id', 'slug', 'symbol', 'name', 'rank', 'price_usd', 'market_cap', 'volume_24h', 'status', 'verified', 'risk_flag', 'source'],
            fn($f, $t) => rows('SELECT id, slug, symbol, name, rank_no, price, market_cap, volume_24h, status, verified, risk_flag, listing_source FROM coins ORDER BY rank_no IS NULL, rank_no')],
        'audit' => ['Audit log', 'Who changed what, with before/after values (secrets masked).', 'security.audit', true, ['time_utc', 'actor', 'action', 'entity', 'entity_id', 'ip', 'before', 'after'],
            fn($f, $t) => rows('SELECT created_at, actor, action, entity, entity_id, ip, before_json, after_json FROM audit_log WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY ORDER BY id', [$f, $t])],
    ];
}

function m_reports(string $sub): void
{
    $defs = report_defs();
    if (a_in('dataset') !== '' && isset($defs[a_in('dataset')])) {
        [$title, , $perm, $ranged, $header, $gen] = $defs[a_in('dataset')];
        require_perm($perm);
        [$f, $t] = a_range();
        $data = $gen($f, $t);
        audit('report.export', 'report', a_in('dataset'), null, ['from' => $f, 'to' => $t, 'rows' => count($data)]);
        a_csv(a_in('dataset') . '-' . ($ranged ? "$f-to-$t" : gmdate('Ymd')) . '.csv', $header, $data);
    }
    if (is_post() && a_action() === 'digest') {
        require_perm('reports.view');
        require_once APP_DIR . '/../cron/tasks.php';
        $to = setting('digest_email');
        if (!$to) a_go('reports', 'Set a digest email in Settings → Notifications first.', 'warn');
        $ok = send_mail($to, cfg('site_name') . ' digest (test send)', digest_text());
        a_go('reports', $ok ? "Digest sent to $to." : 'Sending failed — check Settings → Email.', $ok ? 'ok' : 'err');
    }
    [$f, $t, , $label] = a_range();
    $rows = [];
    foreach ($defs as $k => [$title, $desc, $perm, $ranged]) {
        if (!can($perm)) continue;
        $rows[] = ['title' => $title, 'desc' => $desc, 'ranged' => $ranged, 'k' => $k];
    }
    $h = '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px"><span class="muted">Range applies to datasets marked “range”: ' . e($label) . '</span>' . a_range_picker('reports') . '</div>';
    $qs = a_in('range') === 'custom' ? ['range' => 'custom', 'from' => a_in('from'), 'to' => a_in('to')] : ['range' => a_in('range', '30')];
    $h .= a_card('CSV exports', a_table(['title' => ['label' => 'Dataset', 'fmt' => fn($r, $v) => '<strong>' . e($v) . '</strong><br><span class="muted small">' . e($r['desc']) . '</span>'], 'ranged' => ['label' => 'Range', 'fmt' => fn($r, $v) => $v ? 'yes' : 'snapshot'],
        'x' => ['label' => '', 'fmt' => fn($r) => '<a class="btn sm" href="' . e(a_url('reports', $qs + ['dataset' => $r['k']])) . '">Download CSV</a>']], $rows)
        . a_help('Exports open cleanly in Excel and Google Sheets. Every export is recorded in the audit log. Cells starting with = + - @ are neutralised against spreadsheet formula injection.'));
    $to = setting('digest_email');
    $h .= a_card('Weekly digest email', '<p class="muted">A plain-text summary of traffic, search, members, revenue and open alerts, emailed weekly by the scheduler to <b>' . e($to ?: 'no address set') . '</b>. Configure it in <a href="' . e(a_url('settings/notifications')) . '">Settings → Notifications</a>.</p>' . (can('reports.view') && $to ? a_btn('digest', 'Send a test digest now', [], 'ghost') : ''));
    admin_page('Export center', $h);
}
