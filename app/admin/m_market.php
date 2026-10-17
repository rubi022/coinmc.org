<?php
/** Market data operations: sync tasks, provider (API) health, trading pairs, data-quality checks, manual overrides. */

function m_market(string $sub): void
{
    $tabs = ['' => 'Sync tasks', 'providers' => 'Provider health', 'pairs' => 'Trading pairs', 'quality' => 'Data quality', 'overrides' => 'Manual overrides'];
    if (!isset($tabs[$sub])) { http_response_code(404); admin_page('Not found', '<div class="card">Unknown page.</div>'); return; }
    $body = ['' => 'mk_tasks', 'providers' => 'mk_providers', 'pairs' => 'mk_pairs', 'quality' => 'mk_quality', 'overrides' => 'mk_overrides'][$sub]();
    admin_page('Market data · ' . $tabs[$sub], a_tabs('market', $tabs, $sub) . $body);
}

function mk_tasks(): string
{
    require_once APP_DIR . '/../cron/tasks.php';
    $sched = cron_schedule();
    if (is_post()) {
        $act = a_action();
        if ($act === 'intervals') {
            require_perm('market.edit');
            $o = [];
            foreach ($sched as $t => $d) { $v = (int)($_POST['iv_' . $t] ?? $d); if ($v >= 1 && $v <= 10080) $o[$t] = $v; }
            audit('cron.intervals', 'setting', 'cron_intervals', ['v' => setting('cron_intervals')], ['v' => json_encode($o)]);
            set_setting('cron_intervals', json_encode($o));
            a_go('market', 'Schedule saved. Be careful: the CoinGecko free tier allows roughly 10–30 calls a minute.');
        }
        if ($act === 'run') {
            require_perm('market.edit');
            $t = a_in('task');
            if (!isset($sched[$t])) a_go('market', 'Unknown task.', 'err');
            $php = preg_replace('~[^\w/.\-]~', '', (string)setting('php_cli', 'php')) ?: 'php';
            if (!function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) a_go('market', 'This server blocks process execution — run it from SSH: php cron/run.php ' . $t, 'warn');
            $cmd = 'cd ' . escapeshellarg(dirname(APP_DIR)) . ' && nohup ' . escapeshellarg($php) . ' cron/run.php ' . escapeshellarg($t) . ' >> storage/logs/cron.log 2>&1 &';
            @pclose(@popen($cmd, 'r'));
            audit('cron.run', 'task', $t);
            a_go('market', "Task “$t” started in the background. Refresh in a moment.");
        }
        if ($act === 'purge_cache') { require_perm('market.edit'); coin_cache_clear(); audit('cache.purge', 'cache', 'page'); a_go('market', 'Page cache cleared.'); }
    }
    $rows = [];
    foreach ($sched as $t => $mins) {
        $last = (int)kv_get("last_run_$t", 0);
        $age = $last ? time() - $last : null;
        $rows[] = ['t' => $t, 'mins' => $mins, 'last' => $last, 'dur' => kv_get("last_dur_$t"), 'err' => kv_get("last_err_$t"),
                   'st' => kv_get("last_err_$t") ? 'failed' : ($age === null ? 'never' : ($age > $mins * 60 * 4 + 120 ? 'overdue' : 'ok'))];
    }
    $can = can('market.edit');
    $cols = ['t' => ['label' => 'Task', 'fmt' => fn($r, $v) => '<strong>' . e($v) . '</strong>'],
        'mins' => ['label' => 'Every (min)', 'cls' => 'num', 'fmt' => fn($r, $v) => $can ? '<input form="ivf" type="number" min="1" max="10080" name="iv_' . e($r['t']) . '" value="' . (int)$v . '" style="width:80px">' : (int)$v],
        'last' => ['label' => 'Last success', 'fmt' => fn($r, $v) => $v ? e(gmdate('M j H:i', $v)) . ' <span class="muted">(' . e(time_ago(gmdate('Y-m-d H:i:s', $v))) . ')</span>' : '—'],
        'dur' => ['label' => 'Took', 'cls' => 'num', 'fmt' => fn($r, $v) => $v !== null ? e($v) . 's' : '—'],
        'st' => ['label' => 'Status', 'fmt' => fn($r, $v) => a_badge($v, $v === 'ok' ? 'ok' : ($v === 'never' ? '' : 'bad'))],
        'err' => ['label' => 'Last error', 'cls' => 'wrap', 'fmt' => fn($r, $v) => $v ? '<span class="down small">' . e($v) . '</span>' : ''],
        'x' => ['label' => '', 'fmt' => fn($r) => $can ? a_btn('run', 'Run now', ['task' => $r['t']], 'sm ghost') : '']];
    $h = a_help('Install one cron entry — <code>* * * * * cd /path/to/coinmc &amp;&amp; php cron/run.php schedule</code> — and this scheduler runs each task when due. ' . (kv_get('last_run_markets') ? '' : '<b class="down">No market sync has ever completed on this install.</b>'));
    $h .= a_card('Scheduled tasks', a_table($cols, $rows) . ($can ? '<form id="ivf" method="post">' . csrf_field() . '<input type="hidden" name="action" value="intervals"><p>' . a_submit('Save intervals') . '</p></form>' : ''));
    if ($can) $h .= a_card('Cache', '<p class="muted">Public pages are cached for ' . (int)cfg('cache_ttl', 60) . 's. Clear it after bulk edits if you need changes to appear immediately.</p>' . a_btn('purge_cache', 'Clear page cache', [], 'ghost'));
    return $h;
}

function mk_providers(): string
{
    $rows = rows('SELECT host, COUNT(*) n, SUM(ok) good, ROUND(AVG(ms)) avg_ms, MAX(ms) max_ms, MAX(ts) last_ts FROM api_log WHERE ts >= UTC_TIMESTAMP() - INTERVAL 24 HOUR GROUP BY host ORDER BY n DESC');
    $h = '<div class="kpis">' . a_kpi('Providers called (24h)', (string)count($rows)) . a_kpi('Total calls (24h)', a_n(array_sum(array_column($rows, 'n'))))
        . a_kpi('Failed calls (24h)', a_n(array_sum(array_column($rows, 'n')) - array_sum(array_column($rows, 'good'))), '', array_sum(array_column($rows, 'n')) > array_sum(array_column($rows, 'good')) ? 'warn' : '') . '</div>';
    $h .= a_card('Data providers — last 24 hours', a_table(['host' => ['label' => 'Provider', 'fmt' => fn($r, $v) => '<strong>' . e($v) . '</strong>'], 'n' => ['label' => 'Calls', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)],
        'rate' => ['label' => 'Success', 'cls' => 'num', 'fmt' => fn($r) => '<span class="' . ($r['good'] / max(1, $r['n']) < .9 ? 'down' : 'up') . '">' . a_pct($r['good'], $r['n']) . '</span>'],
        'avg_ms' => ['label' => 'Avg ms', 'cls' => 'num'], 'max_ms' => ['label' => 'Max ms', 'cls' => 'num'], 'last_ts' => ['label' => 'Last call', 'fmt' => fn($r, $v) => e(time_ago($v))]], $rows, 'No outbound API calls logged yet — run a sync.') . a_source('api_log'));
    $hrs = rows("SELECT DATE_FORMAT(ts, '%m-%d %H:00') h, COUNT(*) n, SUM(ok = 0) bad FROM api_log WHERE ts >= UTC_TIMESTAMP() - INTERVAL 48 HOUR GROUP BY h ORDER BY h");
    if (count($hrs) > 1) $h .= a_card('Calls and failures per hour (48h)', svg_line([['name' => 'Calls', 'values' => array_map('intval', array_column($hrs, 'n')), 'color' => '#3861fb'], ['name' => 'Failures', 'values' => array_map('intval', array_column($hrs, 'bad')), 'color' => '#ea3943']], array_column($hrs, 'h'), ['h' => 170]));
    $err = rows('SELECT ts, host, status, ms, err FROM api_log WHERE ok = 0 ORDER BY id DESC LIMIT 25');
    $h .= a_card('Recent failures', a_table(['ts' => 'UTC', 'host' => 'Provider', 'status' => 'HTTP', 'ms' => ['label' => 'ms', 'cls' => 'num'], 'err' => ['label' => 'Error', 'cls' => 'wrap']], $err, 'No failures logged. ✅'));
    return $h;
}

function mk_pairs(): string
{
    $ex = a_in('exchange'); $base = strtoupper(a_in('base')); $quote = strtoupper(a_in('quote')); $out = a_in('outliers') === '1';
    $w = ['m.volume_usd > 0']; $p = [];
    if ($ex !== '') { $w[] = 'm.exchange = ?'; $p[] = $ex; }
    if ($base !== '') { $w[] = 'm.base = ?'; $p[] = $base; }
    if ($quote !== '') { $w[] = 'm.quote = ?'; $p[] = $quote; }
    $rows = rows('SELECT m.exchange, m.pair, m.base, m.quote, m.price_usd, m.volume_usd, m.updated_at, c.price coin_price, c.slug FROM markets m
        LEFT JOIN coins c ON c.id = (SELECT id FROM coins WHERE symbol = LOWER(m.base) AND price IS NOT NULL ORDER BY rank_no IS NULL, rank_no LIMIT 1)
        WHERE ' . implode(' AND ', $w) . ' ORDER BY m.volume_usd DESC LIMIT 400', $p);
    foreach ($rows as &$r) $r['dev'] = ($r['coin_price'] > 0 && $r['price_usd'] > 0) ? ($r['price_usd'] - $r['coin_price']) / $r['coin_price'] * 100 : null;
    unset($r);
    if ($out) $rows = array_values(array_filter($rows, fn($r) => $r['dev'] !== null && abs($r['dev']) > 5));
    $exs = array_column(rows('SELECT DISTINCT exchange FROM markets ORDER BY exchange'), 'exchange');
    $h = '<form class="filters" method="get"><select name="exchange"><option value="">All exchanges</option>' . implode('', array_map(fn($x) => '<option' . ($x === $ex ? ' selected' : '') . '>' . e($x) . '</option>', $exs)) . '</select>'
        . '<input type="text" name="base" value="' . e($base) . '" placeholder="Base (BTC)"><input type="text" name="quote" value="' . e($quote) . '" placeholder="Quote (USDT)">'
        . '<label class="chk" style="margin:0"><input type="checkbox" name="outliers" value="1"' . ($out ? ' checked' : '') . '> Only price outliers (&gt;5% from VWAP)</label><button class="btn" type="submit">Filter</button></form>';
    $h .= a_card(count($rows) . ' pairs (top 400 by volume)', a_table(['exchange' => 'Exchange', 'pair' => 'Pair', 'price_usd' => ['label' => 'Price USD', 'cls' => 'num', 'fmt' => fn($r, $v) => fmt_price($v)],
        'coin_price' => ['label' => 'Aggregated', 'cls' => 'num', 'fmt' => fn($r, $v) => fmt_price($v)], 'dev' => ['label' => 'Deviation', 'cls' => 'num', 'fmt' => fn($r, $v) => $v === null ? '—' : '<span class="' . (abs($v) > 5 ? 'down' : '') . '">' . number_format($v, 2) . '%</span>'],
        'volume_usd' => ['label' => '24h volume', 'cls' => 'num', 'fmt' => fn($r, $v) => fmt_big($v)], 'updated_at' => ['label' => 'Updated', 'fmt' => fn($r, $v) => e(time_ago($v))]], $rows) . a_source('exchange public APIs'));
    return $h;
}

function mk_quality(): string
{
    $checks = [
        ['Active coins with no price', "SELECT id, name, slug FROM coins WHERE status='active' AND (price IS NULL OR price <= 0) LIMIT 15", "SELECT COUNT(*) FROM coins WHERE status='active' AND (price IS NULL OR price <= 0)", 'bad'],
        ['Ranked coins not updated for over 2 hours (stale data)', "SELECT id, name, slug FROM coins WHERE status='active' AND rank_no IS NOT NULL AND listing_source <> 'manual' AND updated_at < UTC_TIMESTAMP() - INTERVAL 2 HOUR ORDER BY rank_no LIMIT 15", "SELECT COUNT(*) FROM coins WHERE status='active' AND rank_no IS NOT NULL AND listing_source <> 'manual' AND updated_at < UTC_TIMESTAMP() - INTERVAL 2 HOUR", 'bad'],
        ['Top-500 coins with zero 24h volume', "SELECT id, name, slug FROM coins WHERE status='active' AND rank_no <= 500 AND (volume_24h IS NULL OR volume_24h = 0) ORDER BY rank_no LIMIT 15", "SELECT COUNT(*) FROM coins WHERE status='active' AND rank_no <= 500 AND (volume_24h IS NULL OR volume_24h = 0)", 'warn'],
        ['Top-500 coins without a description (thin content hurts SEO)', "SELECT id, name, slug FROM coins WHERE status='active' AND rank_no <= 500 AND (description IS NULL OR description = '') ORDER BY rank_no LIMIT 15", "SELECT COUNT(*) FROM coins WHERE status='active' AND rank_no <= 500 AND (description IS NULL OR description = '')", 'warn'],
        ['Active coins without a logo', "SELECT id, name, slug FROM coins WHERE status='active' AND rank_no <= 1000 AND (image IS NULL OR image = '') ORDER BY rank_no LIMIT 15", "SELECT COUNT(*) FROM coins WHERE status='active' AND rank_no <= 1000 AND (image IS NULL OR image = '')", 'warn'],
        ['Top-100 coins missing historical data', "SELECT c.id, c.name, c.slug FROM coins c WHERE c.status='active' AND c.rank_no <= 100 AND NOT EXISTS (SELECT 1 FROM coin_history_daily h WHERE h.coin_id = c.id) ORDER BY c.rank_no LIMIT 15", "SELECT COUNT(*) FROM coins c WHERE c.status='active' AND c.rank_no <= 100 AND NOT EXISTS (SELECT 1 FROM coin_history_daily h WHERE h.coin_id = c.id)", 'warn'],
        ['Implausible market caps (market cap < volume ÷ 100 in top 1000)', "SELECT id, name, slug FROM coins WHERE status='active' AND rank_no <= 1000 AND market_cap > 0 AND volume_24h > market_cap * 100 ORDER BY rank_no LIMIT 15", "SELECT COUNT(*) FROM coins WHERE status='active' AND rank_no <= 1000 AND market_cap > 0 AND volume_24h > market_cap * 100", 'warn'],
        ['Active exchanges with no markets', "SELECT id, name, slug FROM exchanges WHERE status='active' AND volume_24h_btc IS NULL LIMIT 15", "SELECT COUNT(*) FROM exchanges WHERE status='active' AND volume_24h_btc IS NULL", ''],
    ];
    $h = a_help('Automated checks over the public data set. Fix the source (override, hide, or wait for the next sync) — counts update live.');
    foreach ($checks as [$title, $sampleSql, $countSql, $sev]) {
        try { $n = (int)val($countSql); $sample = $n ? rows($sampleSql) : []; } catch (Throwable $e) { $n = 0; $sample = []; }
        $links = implode(' ', array_map(fn($r) => '<a class="pill" href="' . e(a_url(strpos($title, 'exchanges') !== false ? 'exchanges/edit' : 'coins/edit', ['id' => $r['id']])) . '">' . e($r['name']) . '</a>', $sample));
        $h .= '<section class="card"><div class="card-h"><h2>' . e($title) . '</h2>' . ($n ? a_badge(a_n($n), $sev) : a_badge('✓ OK', 'ok')) . '</div>' . ($n ? '<p>' . $links . ($n > count($sample) ? ' <span class="muted">+ ' . a_n($n - count($sample)) . ' more</span>' : '') . '</p>' : '') . '</section>';
    }
    // price deviation vs. exchange VWAP for the top 100
    $dev = rows('SELECT c.id, c.name, c.slug, c.price, x.p mkt FROM coins c JOIN (SELECT UPPER(base) b, SUM(price_usd * volume_usd) / SUM(volume_usd) p FROM markets WHERE volume_usd > 1000 AND price_usd > 0 GROUP BY base) x ON x.b = UPPER(c.symbol)
        WHERE c.status = \'active\' AND c.rank_no <= 100 AND c.price > 0 AND ABS(c.price - x.p) / c.price > 0.03 ORDER BY c.rank_no LIMIT 15');
    $h .= a_card('Top-100 price differs >3% from exchange VWAP', $dev ? a_table(['name' => ['label' => 'Coin', 'fmt' => fn($r, $v) => '<a href="' . e(a_url('coins/edit', ['id' => $r['id']])) . '">' . e($v) . '</a>'], 'price' => ['label' => 'Listed', 'cls' => 'num', 'fmt' => fn($r, $v) => fmt_price($v)], 'mkt' => ['label' => 'Exchange VWAP', 'cls' => 'num', 'fmt' => fn($r, $v) => fmt_price($v)]], $dev) : '<p class="muted pad">No material deviations. ✅</p>');
    return $h;
}

function mk_overrides(): string
{
    if (is_post() && a_action() === 'remove') {
        require_perm('market.edit');
        $cid = a_int('coin_id'); $f = a_in('field');
        q('DELETE FROM coin_overrides WHERE coin_id = ? AND field = ?', [$cid, $f]);
        audit('coin.override.remove', 'coin', $cid, ['field' => $f], ['field' => null]);
        a_go('market/overrides', 'Override removed — the API value returns at the next sync.');
    }
    $rows = rows('SELECT o.coin_id, o.field, o.value, o.set_at, c.name, u.email FROM coin_overrides o JOIN coins c ON c.id = o.coin_id LEFT JOIN users u ON u.id = o.set_by ORDER BY o.set_at DESC LIMIT 300');
    return a_card(count($rows) . ' active overrides', a_table(['name' => ['label' => 'Coin', 'fmt' => fn($r, $v) => '<a href="' . e(a_url('coins/edit', ['id' => $r['coin_id']])) . '">' . e($v) . '</a>'], 'field' => 'Field',
        'value' => ['label' => 'Value', 'cls' => 'wrap', 'fmt' => fn($r, $v) => e(mb_strimwidth((string)$v, 0, 90, '…'))], 'email' => 'Set by', 'set_at' => ['label' => 'When', 'fmt' => fn($r, $v) => e(time_ago($v))],
        'x' => ['label' => '', 'fmt' => fn($r) => can('market.edit') ? a_btn('remove', 'Remove', ['coin_id' => $r['coin_id'], 'field' => $r['field']], 'sm ghost', 'Remove this override?') : '']], $rows, 'No manual overrides. Edit a coin to add one.')
        . a_help('Overrides are re-applied after every market sync so API refreshes never silently undo an admin change.'));
}
