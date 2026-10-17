<?php
/** Analytics & Insights. All figures here come from first-party pageviews (bots excluded unless stated); GA4 / Search Console figures are labelled separately. */

function m_analytics(string $sub): void
{
    traffic_rollup_fresh();
    $tabs = ['' => 'Traffic', 'pages' => 'Pages', 'acquisition' => 'Acquisition', 'audience' => 'Audience', 'realtime' => 'Realtime', 'vitals' => 'Web Vitals', 'funnel' => 'Funnel'];
    if (!isset($tabs[$sub])) { http_response_code(404); admin_page('Not found', '<div class="card">Unknown analytics view.</div>'); return; }
    $fn = 'an_' . ($sub ?: 'traffic');
    $body = $fn();
    $picker = $sub === 'realtime' ? '' : '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px"><span class="muted">' . e(a_range()[3]) . ' · UTC</span>' . a_range_picker('analytics' . ($sub ? "/$sub" : '')) . '</div>';
    admin_page('Analytics · ' . $tabs[$sub], a_tabs('analytics', $tabs, $sub) . $picker . $body);
}

function an_traffic(): string
{
    [$from, $to, $days, $label, $pf, $pt] = a_range();
    [$labels, $ser] = traffic_series($from, $to);
    $sum = function (array $r, string $k) { return array_sum(array_map('intval', array_column($r, $k))); };
    [, $prev] = traffic_series($pf, $pt);
    $v = $sum($ser, 'views'); $vis = $sum($ser, 'visitors'); $ses = $sum($ser, 'sessions'); $bn = $sum($ser, 'bounces'); $dur = $sum($ser, 'duration_sum'); $new = $sum($ser, 'new_visitors'); $org = $sum($ser, 'organic_sessions');
    $pv = $sum($prev, 'views'); $pvis = $sum($prev, 'visitors'); $pses = $sum($prev, 'sessions'); $porg = $sum($prev, 'organic_sessions');
    $h = '<div class="kpis">'
        . a_kpi('Page views', a_n($v), a_delta($v, $pv))
        . a_kpi('Unique visitors*', a_n($vis), a_delta($vis, $pvis))
        . a_kpi('Sessions', a_n($ses), a_delta($ses, $pses))
        . a_kpi('Organic sessions', a_n($org), a_delta($org, $porg) . ' · ' . a_pct($org, $ses) . ' of sessions')
        . a_kpi('Pages / session', $ses ? number_format($v / $ses, 2) : '—')
        . a_kpi('Bounce rate', a_pct($bn, $ses))
        . a_kpi('Avg. session', $ses ? gmdate('i:s', (int)($dur / $ses)) : '—', 'min:sec')
        . a_kpi('New visitors', a_pct($new, $vis), a_n($new) . ' first-time')
        . '</div><p class="help">*Visitors are summed per day; the same person on two days counts twice. Visitors are pseudonymous hashes (no cookies). Bots are excluded here and shown separately below.</p>';
    $h .= a_card('Page views, visitors and sessions', svg_line([
        ['name' => 'Page views', 'values' => array_map('intval', array_column($ser, 'views')), 'color' => '#3861fb'],
        ['name' => 'Visitors', 'values' => array_map('intval', array_column($ser, 'visitors')), 'color' => '#16c784'],
        ['name' => 'Sessions', 'values' => array_map('intval', array_column($ser, 'sessions')), 'color' => '#f5a623'],
    ], $labels), a_range_picker('analytics') . a_source('first-party'));
    $h .= '<div class="grid g2">';
    $h .= a_card('Bot & crawler traffic (not counted above)', svg_line([
        ['name' => 'All bots', 'values' => array_map('intval', array_column($ser, 'bot_views')), 'color' => '#94a3b8'],
        ['name' => 'Googlebot', 'values' => array_map('intval', array_column($ser, 'googlebot')), 'color' => '#ea3943'],
    ], $labels, ['h' => 170]));
    $ga = rows('SELECT * FROM ga_daily WHERE day BETWEEN ? AND ?', [$from, $to]);
    if ($ga) {
        $gu = array_sum(array_column($ga, 'users')); $gs = array_sum(array_column($ga, 'sessions')); $gp = array_sum(array_column($ga, 'pageviews'));
        $h .= a_card('Cross-check with Google Analytics 4', a_table(['m' => 'Metric', 'fp' => ['label' => 'First-party', 'cls' => 'num'], 'ga' => ['label' => 'GA4', 'cls' => 'num'], 'd' => ['label' => 'Difference', 'cls' => 'num']], [
            ['m' => 'Sessions', 'fp' => a_n($ses), 'ga' => a_n($gs), 'd' => $gs ? number_format(($ses - $gs) / $gs * 100, 1) . '%' : '—'],
            ['m' => 'Page views', 'fp' => a_n($v), 'ga' => a_n($gp), 'd' => $gp ? number_format(($v - $gp) / $gp * 100, 1) . '%' : '—'],
            ['m' => 'Users (GA) / Visitors (1st-party)', 'fp' => a_n($vis), 'ga' => a_n($gu), 'd' => $gu ? number_format(($vis - $gu) / $gu * 100, 1) . '%' : '—'],
        ]) . a_help('Expect GA4 to be lower when visitors decline the cookie banner and higher where it counts engaged-time sessions differently. Large gaps usually mean tracking is broken on one side.') . a_source('GA4 Data API + first-party'));
    } else {
        $h .= a_card('Google Analytics 4', '<p class="muted pad">Not connected. Add a service account in Settings → Integrations to compare GA4 with first-party numbers here.</p>');
    }
    $h .= '</div>';
    return $h;
}

function an_pages(): string
{
    [$from, $to] = a_range();
    $pt = a_in('ptype');
    $types = ['' => 'All page types', 'home' => 'Home', 'coin' => 'Coin', 'history' => 'Historical data', 'convert' => 'Converter', 'category' => 'Category', 'exchange' => 'Exchange', 'news' => 'News', 'list' => 'Lists', 'other' => 'Other'];
    $w = 'is_bot = 0 AND day BETWEEN ? AND ?'; $p = [$from, $to];
    if ($pt !== '' && isset($types[$pt])) { $w .= ' AND ptype = ?'; $p[] = $pt; }
    $h = '<form class="filters" method="get"><input type="hidden" name="range" value="' . e(a_in('range', '30')) . '"><input type="hidden" name="from" value="' . e(a_in('from')) . '"><input type="hidden" name="to" value="' . e(a_in('to')) . '">'
        . '<select name="ptype" onchange="this.form.submit()">' . implode('', array_map(fn($k, $l) => '<option value="' . e($k) . '"' . ($k === $pt ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($types), $types)) . '</select></form>';
    $top = rows("SELECT path, ptype, COUNT(*) views, COUNT(DISTINCT vh) visitors, SUM(landing) landings, ROUND(AVG(ms)) ms FROM pageviews WHERE $w AND status < 400 GROUP BY path, ptype ORDER BY views DESC LIMIT 50", $p);
    $h .= a_card('Top pages', a_table(['path' => ['label' => 'Page', 'cls' => 'wrap', 'fmt' => fn($r, $v) => '<a href="' . e($v) . '" target="_blank" rel="noopener">' . e($v) . '</a>'], 'ptype' => 'Type', 'views' => ['label' => 'Views', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)],
        'visitors' => ['label' => 'Visitors', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'landings' => ['label' => 'Entrances', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'ms' => ['label' => 'Avg server ms', 'cls' => 'num']], $top), a_source('first-party'));
    $types2 = rows("SELECT COALESCE(ptype,'other') t, COUNT(*) n FROM pageviews WHERE is_bot = 0 AND day BETWEEN ? AND ? GROUP BY t ORDER BY n DESC", [$from, $to]);
    $slow = rows("SELECT path, COUNT(*) n, ROUND(AVG(ms)) ms, MAX(ms) mx FROM pageviews WHERE is_bot = 0 AND day BETWEEN ? AND ? GROUP BY path HAVING n >= 5 ORDER BY ms DESC LIMIT 10", [$from, $to]);
    $h .= '<div class="grid g2">' . a_card('Views by page type', svg_donut(array_map(fn($r) => [$r['t'], $r['n']], $types2)))
        . a_card('Slowest pages (server time)', a_table(['path' => ['label' => 'Page', 'cls' => 'wrap'], 'n' => ['label' => 'Views', 'cls' => 'num'], 'ms' => ['label' => 'Avg ms', 'cls' => 'num'], 'mx' => ['label' => 'Max ms', 'cls' => 'num']], $slow, 'Needs ≥5 views per page.'))
        . '</div>';
    return $h;
}

function an_acquisition(): string
{
    [$from, $to] = a_range();
    $b = 'is_bot = 0 AND landing = 1 AND day BETWEEN ? AND ?'; $p = [$from, $to];
    $src = rows("SELECT COALESCE(src,'direct') s, COUNT(*) sessions, SUM(is_new) newv FROM pageviews WHERE $b GROUP BY s ORDER BY sessions DESC", $p);
    $tot = array_sum(array_column($src, 'sessions'));
    $h = '<div class="grid g2">' . a_card('Channels', svg_donut(array_map(fn($r) => [ucfirst($r['s']), $r['sessions']], $src)) . a_help('“AI” = visits arriving from chatbots / AI search (ChatGPT, Perplexity, Gemini, Claude, Copilot…).'), '', '')
        . a_card('Channel detail', a_table(['s' => ['label' => 'Channel', 'fmt' => fn($r, $v) => e(ucfirst($v))], 'sessions' => ['label' => 'Sessions', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'sh' => ['label' => 'Share', 'cls' => 'num'], 'newv' => ['label' => 'New visitors', 'cls' => 'num']],
            array_map(fn($r) => $r + ['sh' => a_pct($r['sessions'], $tot)], $src))) . '</div>';
    $ref = rows("SELECT ref_host, COALESCE(src,'?') src, COUNT(*) n FROM pageviews WHERE $b AND ref_host IS NOT NULL AND ref_host <> '' GROUP BY ref_host, src ORDER BY n DESC LIMIT 25", $p);
    $h .= a_card('Top referrers', a_table(['ref_host' => 'Referrer', 'src' => ['label' => 'Channel', 'fmt' => fn($r, $v) => a_badge($v)], 'n' => ['label' => 'Sessions', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)]], $ref));
    $utm = rows("SELECT utm_source, utm_medium, utm_campaign, COUNT(*) n FROM pageviews WHERE $b AND (utm_source IS NOT NULL OR utm_campaign IS NOT NULL) GROUP BY utm_source, utm_medium, utm_campaign ORDER BY n DESC LIMIT 25", $p);
    $h .= a_card('UTM campaigns', a_table(['utm_source' => 'Source', 'utm_medium' => 'Medium', 'utm_campaign' => 'Campaign', 'n' => ['label' => 'Sessions', 'cls' => 'num']], $utm, 'No tagged campaigns yet — add ?utm_source=…&utm_medium=…&utm_campaign=… to links you share.'));
    $org = rows("SELECT path, COUNT(*) n FROM pageviews WHERE $b AND src = 'organic' GROUP BY path ORDER BY n DESC LIMIT 15", $p);
    $ai = rows("SELECT path, ref_host, COUNT(*) n FROM pageviews WHERE $b AND src = 'ai' GROUP BY path, ref_host ORDER BY n DESC LIMIT 15", $p);
    $h .= '<div class="grid g2">' . a_card('Top organic landing pages', hbars(array_map(fn($r) => [$r['path'], $r['n']], $org)))
        . a_card('Landing pages from AI assistants', a_table(['path' => ['label' => 'Page', 'cls' => 'wrap'], 'ref_host' => 'From', 'n' => ['label' => 'Sessions', 'cls' => 'num']], $ai, 'No AI-referred sessions in this period.')) . '</div>';
    return $h;
}

function an_audience(): string
{
    [$from, $to] = a_range();
    $b = 'is_bot = 0 AND landing = 1 AND day BETWEEN ? AND ?'; $p = [$from, $to];
    $dim = fn($col) => rows("SELECT COALESCE($col,'Unknown') k, COUNT(*) n FROM pageviews WHERE $b GROUP BY k ORDER BY n DESC LIMIT 12", $p);
    $c = $dim('country');
    $h = '<div class="grid g2">' . a_card('Countries (sessions)', hbars(array_map(fn($r) => [$r['k'], $r['n']], $c)) . (count($c) === 1 && $c[0]['k'] === 'Unknown' ? a_help('Country comes from a CDN header (e.g. Cloudflare CF-IPCountry). Put the site behind Cloudflare to populate it.') : ''))
        . a_card('Devices', svg_donut(array_map(fn($r) => [$r['k'], $r['n']], $dim('device')))) . '</div>';
    $h .= '<div class="grid g2">' . a_card('Browsers', svg_donut(array_map(fn($r) => [$r['k'], $r['n']], $dim('browser')))) . a_card('Operating systems', svg_donut(array_map(fn($r) => [$r['k'], $r['n']], $dim('os')))) . '</div>';
    $nv = row("SELECT SUM(is_new) n, COUNT(*) t FROM pageviews WHERE $b", $p);
    $h .= a_card('New vs returning', svg_donut([['New visitors', (int)$nv['n']], ['Returning', max(0, (int)$nv['t'] - (int)$nv['n'])]]));
    $su = rows('SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY GROUP BY d ORDER BY d', [$from, $to]);
    $m = array_column($su, 'n', 'd'); $labels = []; $vals = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) { $d = gmdate('Y-m-d', $t); $labels[] = $d; $vals[] = (int)($m[$d] ?? 0); }
    $h .= a_card('Registrations per day', svg_bars($vals, $labels, ['color' => '#16c784']), '', '') ;
    return $h;
}

function an_realtime(): string
{
    $act = (int)val('SELECT COUNT(DISTINCT vh) FROM pageviews WHERE is_bot = 0 AND ts >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE');
    $m = array_column(rows("SELECT TIMESTAMPDIFF(MINUTE, ts, UTC_TIMESTAMP()) ago, COUNT(*) n FROM pageviews WHERE is_bot = 0 AND ts >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE GROUP BY ago"), 'n', 'ago');
    $vals = []; $labels = [];
    for ($i = 29; $i >= 0; $i--) { $vals[] = (int)($m[$i] ?? 0); $labels[] = "-{$i}m"; }
    $h = '<meta http-equiv="refresh" content="30"><div class="kpis">' . a_kpi('Active now (5 min)', a_n($act)) . a_kpi('Views (30 min)', a_n(array_sum($vals))) . a_kpi('Googlebot (30 min)', a_n(val("SELECT COUNT(*) FROM pageviews WHERE bot_name = 'Googlebot' AND ts >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE")))
        . a_kpi('Errors (30 min)', a_n(val('SELECT COUNT(*) FROM pageviews WHERE status >= 400 AND is_bot = 0 AND ts >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE'))) . '</div>';
    $h .= a_card('Views per minute — last 30 minutes', svg_bars($vals, $labels, ['h' => 160]), '<span class="muted small">auto-refreshes every 30s</span>');
    $top = rows('SELECT path, COUNT(*) n FROM pageviews WHERE is_bot = 0 AND ts >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE GROUP BY path ORDER BY n DESC LIMIT 10');
    $rec = rows('SELECT ts, path, status, src, country, device, bot_name FROM pageviews ORDER BY id DESC LIMIT 30');
    $h .= '<div class="grid g2">' . a_card('Active pages', hbars(array_map(fn($r) => [$r['path'], $r['n']], $top)))
        . a_card('Latest requests', a_table(['ts' => ['label' => 'UTC', 'fmt' => fn($r, $v) => e(substr($v, 11))], 'path' => ['label' => 'Path', 'cls' => 'wrap'], 'status' => 'Status', 'who' => ['label' => 'Who', 'fmt' => fn($r, $v) => $r['bot_name'] ? a_badge($r['bot_name'], 'info') : e(trim(($r['src'] ?: '') . ' ' . ($r['country'] ?: '') . ' ' . $r['device']))]], $rec)) . '</div>';
    return $h;
}

function an_vitals(): string
{
    [$from, $to] = a_range();
    $rows = rows('SELECT metric, ptype, device, value FROM cwv WHERE ts >= ? AND ts < ? + INTERVAL 1 DAY ORDER BY value LIMIT 100000', [$from, $to]);
    if (!$rows) return a_card('Core Web Vitals (field data)', '<p class="muted pad">No field data yet. Real-user measurements are collected from a sample of visitors’ browsers once the site has traffic.</p>');
    $thr = ['LCP' => [2500, 4000, 'ms'], 'INP' => [200, 500, 'ms'], 'CLS' => [0.1, 0.25, ''], 'TTFB' => [800, 1800, 'ms'], 'FCP' => [1800, 3000, 'ms']];
    $p75 = function (array $v) { sort($v); return $v[(int)max(0, ceil(count($v) * .75) - 1)]; };
    $rate = fn($m, $v) => $v <= $thr[$m][0] ? 'ok' : ($v <= $thr[$m][1] ? 'warn' : 'bad');
    $fmt = fn($m, $v) => $m === 'CLS' ? number_format($v, 3) : number_format($v) . ' ms';
    $by = [];
    foreach ($rows as $r) { $by['all']['all'][$r['metric']][] = (float)$r['value']; $by['type'][$r['ptype'] ?: 'other'][$r['metric']][] = (float)$r['value']; $by['dev'][$r['device'] ?: '?'][$r['metric']][] = (float)$r['value']; }
    $h = '<div class="kpis">';
    foreach ($thr as $m => $_) if (!empty($by['all']['all'][$m])) { $v = $p75($by['all']['all'][$m]); $h .= a_kpi($m . ' (p75)', $fmt($m, $v), a_badge($rate($m, $v) === 'ok' ? 'Good' : ($rate($m, $v) === 'warn' ? 'Needs work' : 'Poor'), $rate($m, $v)) . ' ' . count($by['all']['all'][$m]) . ' samples'); }
    $h .= '</div>' . a_help('Google judges the 75th percentile of real users. Good: LCP ≤ 2.5s · INP ≤ 200ms · CLS ≤ 0.1 · TTFB ≤ 0.8s.');
    foreach (['type' => 'By page type', 'dev' => 'By device'] as $g => $title) {
        $tr = [];
        foreach ($by[$g] as $k => $ms) {
            $row = ['k' => $k];
            foreach (['LCP', 'INP', 'CLS', 'TTFB'] as $m) $row[$m] = isset($ms[$m]) ? '<span class="pill ' . $rate($m, $p75($ms[$m])) . '">' . $fmt($m, $p75($ms[$m])) . '</span> <small class="muted">n=' . count($ms[$m]) . '</small>' : '—';
            $tr[] = $row;
        }
        $f = fn($r, $v) => $v;
        $h .= a_card($title, a_table(['k' => 'Group', 'LCP' => ['label' => 'LCP', 'fmt' => $f], 'INP' => ['label' => 'INP', 'fmt' => $f], 'CLS' => ['label' => 'CLS', 'fmt' => $f], 'TTFB' => ['label' => 'TTFB', 'fmt' => $f]], $tr), '', '') ;
    }
    return $h;
}

function an_funnel(): string
{
    [$from, $to] = a_range();
    $ses = (int)val('SELECT COUNT(DISTINCT sid) FROM pageviews WHERE is_bot = 0 AND day BETWEEN ? AND ?', [$from, $to]);
    $coin = (int)val("SELECT COUNT(DISTINCT sid) FROM pageviews WHERE is_bot = 0 AND ptype = 'coin' AND day BETWEEN ? AND ?", [$from, $to]);
    $reg = (int)val("SELECT COUNT(DISTINCT sid) FROM pageviews WHERE is_bot = 0 AND path = '/register/' AND day BETWEEN ? AND ?", [$from, $to]);
    $sig = (int)val('SELECT COUNT(*) FROM users WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY', [$from, $to]);
    $ver = (int)val('SELECT COUNT(*) FROM users WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY AND email_verified_at IS NOT NULL', [$from, $to]);
    $adc = (int)val('SELECT COALESCE(SUM(clicks),0) FROM ad_stats WHERE day BETWEEN ? AND ?', [$from, $to]);
    $afc = (int)val('SELECT COUNT(*) FROM affiliate_clicks WHERE day BETWEEN ? AND ?', [$from, $to]);
    $aff = row('SELECT COALESCE(SUM(conversions),0) c, COALESCE(SUM(commission),0) m FROM affiliate_conversions WHERE day BETWEEN ? AND ?', [$from, $to]);
    $steps = [['Sessions', $ses], ['Viewed a coin page', $coin], ['Opened the sign-up page', $reg], ['Created an account', $sig], ['Verified email', $ver]];
    $h = a_card('Visitor → member funnel', hbars($steps) . a_help('Sessions and page views are first-party; sign-ups and verifications are counted from the users table, so a sign-up is not always from a session in this range. Rates: visit→sign-up ' . a_pct($sig, $ses, 2) . ' · sign-up→verified ' . a_pct($ver, $sig)), '', '');
    $h .= '<div class="kpis">' . a_kpi('Ad clicks (valid)', a_n($adc)) . a_kpi('Affiliate clicks', a_n($afc), 'per 1,000 sessions: ' . ($ses ? number_format($afc / $ses * 1000, 1) : '—'))
        . a_kpi('Affiliate conversions', a_n($aff['c']), 'click→conv ' . a_pct($aff['c'], $afc)) . a_kpi('Affiliate commission', a_money($aff['m']), 'EPC ' . ($afc ? a_money($aff['m'] / $afc) : '—')) . '</div>';
    $pg = rows("SELECT path, COUNT(*) n FROM pageviews WHERE is_bot = 0 AND path IN ('/register/','/login/','/forgot/') AND day BETWEEN ? AND ? GROUP BY path", [$from, $to]);
    $h .= a_card('Definitions', '<ul class="help"><li><b>Session</b>: activity from one visitor with no gap over 30 minutes.</li><li><b>Visitor</b>: pseudonymous hash of IP + browser + secret; resets if the secret rotates.</li><li><b>Organic</b>: first page of a session arrived from a search engine referrer.</li><li><b>Bounce</b>: a session with exactly one page view.</li><li><b>Valid ad click</b>: not a bot and at most 3 per visitor per campaign per day.</li></ul>');
    return $h;
}
