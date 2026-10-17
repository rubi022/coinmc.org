<?php
/**
 * Data sync tasks. Run through cron/run.php.
 */
require_once APP_DIR . '/lib/http.php';
require_once APP_DIR . '/sources/CoinGecko.php';
require_once APP_DIR . '/sources/Exchanges.php';

const STABLES = ['USDT', 'USDC', 'FDUSD', 'TUSD', 'BUSD', 'DAI', 'USD', 'USDE', 'PYUSD', 'USDP'];
const QUOTE_CG = ['BTC' => 'bitcoin', 'ETH' => 'ethereum', 'BNB' => 'binancecoin', 'SOL' => 'solana', 'XRP' => 'ripple', 'DOGE' => 'dogecoin', 'TRX' => 'tron'];

/** task => default minimum minutes between runs. Admin → Sync tasks can override (setting cron_intervals, JSON). */
function cron_schedule(): array
{
    $d = ['fiat' => 60, 'markets' => 5, 'tickers' => 2, 'global' => 10, 'trending' => 15, 'categories' => 60, 'exchanges' => 360, 'meta' => 10, 'history' => 5, 'hourly' => 60,
          'cache_clear' => 60, 'analytics_rollup' => 15, 'alerts' => 5, 'gsc' => 720, 'ga4' => 360, 'purge_accounts' => 360, 'seo_audit' => 10080, 'digest' => 10080];
    $o = json_decode((string)setting('cron_intervals', ''), true);
    if (is_array($o)) foreach ($o as $k => $v) if (isset($d[$k]) && (int)$v >= 1 && (int)$v <= 10080) $d[$k] = (int)$v;
    return $d;
}

function now_sql(): string { return gmdate('Y-m-d H:i:s'); }

function sig($v, int $s = 6)
{
    if ($v === null) return null;
    $v = (float)$v;
    if ($v == 0) return 0;
    return round($v, max(0, $s - (int)floor(log10(abs($v))) - 1));
}

function dt_or_null($s): ?string { return $s ? gmdate('Y-m-d H:i:s', strtotime($s)) : null; }

// ---------------------------------------------------------------- markets (coin universe)
function task_markets(): void
{
    $runStart = now_sql();
    $first = (int)val('SELECT COUNT(*) FROM coins') === 0;
    $pages = (int)ceil(cfg('max_coins', 1000) / 250);
    $total = 0;
    for ($p = 1; $p <= $pages; $p++) {
        $data = CoinGecko::markets($p);
        if (!is_array($data) || !$data) { log_msg('sync', "markets page $p: no data"); break; }
        $batch = [];
        foreach ($data as $c) {
            if (empty($c['id']) || !isset($c['current_price'])) continue;
            $spark = $c['sparkline_in_7d']['price'] ?? null;
            $batch[] = [
                'cg_id' => $c['id'], 'slug' => $c['id'], 'symbol' => strtolower($c['symbol']), 'name' => $c['name'],
                'image' => str_replace('/large/', '/small/', (string)$c['image']), 'rank_no' => $c['market_cap_rank'],
                'price' => $c['current_price'], 'market_cap' => $c['market_cap'], 'fdv' => $c['fully_diluted_valuation'],
                'volume_24h' => $c['total_volume'], 'high_24h' => $c['high_24h'], 'low_24h' => $c['low_24h'],
                'change_1h' => $c['price_change_percentage_1h_in_currency'] ?? null,
                'change_24h' => $c['price_change_percentage_24h_in_currency'] ?? $c['price_change_percentage_24h'],
                'change_7d' => $c['price_change_percentage_7d_in_currency'] ?? null,
                'change_30d' => $c['price_change_percentage_30d_in_currency'] ?? null,
                'change_1y' => $c['price_change_percentage_1y_in_currency'] ?? null,
                'circulating_supply' => $c['circulating_supply'], 'total_supply' => $c['total_supply'], 'max_supply' => $c['max_supply'],
                'ath' => $c['ath'], 'ath_change' => $c['ath_change_percentage'], 'ath_date' => dt_or_null($c['ath_date'] ?? null),
                'atl' => $c['atl'], 'atl_change' => $c['atl_change_percentage'], 'atl_date' => dt_or_null($c['atl_date'] ?? null),
                'sparkline_7d' => $spark ? json_encode(array_map('sig', $spark)) : null,
                'price_source' => 'coingecko', 'updated_at' => now_sql(),
                'created_at' => $first ? '2020-01-01 00:00:00' : now_sql(),
            ];
        }
        $cols = array_diff(array_keys($batch[0] ?? []), ['cg_id', 'slug', 'created_at']);
        upsert('coins', $batch, $cols);
        $total += count($batch);
        if (count($data) < 250) break;
    }
    if ($total >= 200) q("UPDATE coins SET rank_no = NULL WHERE updated_at < ? AND listing_source <> 'manual'", [$runStart]);
    task_overrides();
    log_msg('sync', "markets: $total coins");
}

// ---------------------------------------------------------------- exchange tickers + live prices
function quote_usd_map(): array
{
    $m = array_fill_keys(STABLES, 1.0);
    if ($t = val("SELECT price FROM coins WHERE cg_id='tether'")) $m['USDT'] = (float)$t;
    if ($t = val("SELECT price FROM coins WHERE cg_id='usd-coin'")) $m['USDC'] = (float)$t;
    $m['USD'] = 1.0;
    foreach (QUOTE_CG as $sym => $id) if ($p = val('SELECT price FROM coins WHERE cg_id = ?', [$id])) $m[$sym] = (float)$p;
    foreach (rows('SELECT code, rate_usd FROM fiat_rates') as $f) {
        $code = strtoupper($f['code']);
        if (!isset($m[$code]) && $f['rate_usd'] > 0) $m[$code] = 1 / (float)$f['rate_usd'];
    }
    return $m;
}

function task_tickers(): void
{
    $qusd = quote_usd_map();
    $now = now_sql();
    foreach (cfg('exchanges_enabled') as $ex) {
        $list = Exchanges::tickers($ex);
        if (!$list) { log_msg('sync', "tickers $ex: none"); continue; }
        $batch = [];
        foreach ($list as $t) {
            $rate = $qusd[$t['quote']] ?? null;
            $batch[] = [
                'exchange' => $ex, 'pair' => substr($t['pair'], 0, 60), 'base' => substr($t['base'], 0, 40), 'quote' => substr($t['quote'], 0, 40),
                'price' => $t['price'], 'price_usd' => $rate ? $t['price'] * $rate : null,
                'volume_base' => $t['volume_base'], 'volume_usd' => $rate ? $t['volume_quote'] * $rate : null,
                'change_24h' => $t['change_24h'] !== null ? max(-9999, min(99999, $t['change_24h'])) : null,
                'trade_url' => $t['trade_url'], 'updated_at' => $now,
            ];
        }
        upsert('markets', $batch, ['price', 'price_usd', 'volume_base', 'volume_usd', 'change_24h', 'trade_url', 'updated_at'], 500);
        q('DELETE FROM markets WHERE exchange = ? AND updated_at < ?', [$ex, gmdate('Y-m-d H:i:s', time() - 3600)]);
        log_msg('sync', "tickers $ex: " . count($batch));
    }
    apply_exchange_prices();
}

/** Overwrite coin prices with a volume-weighted average of live exchange USD/stablecoin pairs. */
function apply_exchange_prices(): void
{
    $fresh = gmdate('Y-m-d H:i:s', time() - 600);
    $in = "'" . implode("','", STABLES) . "'";
    $rowsM = rows("SELECT base, price_usd, volume_usd FROM markets WHERE quote IN ($in) AND updated_at >= ? AND price_usd > 0 AND volume_usd > 1000", [$fresh]);
    $by = [];
    foreach ($rowsM as $r) $by[$r['base']][] = $r;
    $n = 0;
    foreach ($by as $base => $list) {
        $prices = array_map(fn($r) => (float)$r['price_usd'], $list);
        sort($prices);
        $median = $prices[intdiv(count($prices), 2)];
        $sw = 0; $sv = 0;
        foreach ($list as $r) {
            if (abs($r['price_usd'] / $median - 1) > 0.05) continue;
            $sw += $r['price_usd'] * $r['volume_usd']; $sv += $r['volume_usd'];
        }
        if ($sv <= 0) continue;
        $vwap = $sw / $sv;
        $coin = row('SELECT id, price, circulating_supply, total_supply, max_supply FROM coins WHERE symbol = ? AND price > 0 ORDER BY rank_no IS NULL, rank_no LIMIT 1', [strtolower($base)]);
        if (!$coin || abs($vwap / $coin['price'] - 1) > 0.25) continue; // ticker collision guard
        $circ = (float)$coin['circulating_supply'];
        $fdvSupply = (float)($coin['max_supply'] ?: $coin['total_supply']);
        q('UPDATE coins SET price = ?, market_cap = IF(? > 0, ?, market_cap), fdv = IF(? > 0, ?, fdv), price_source = ?, updated_at = ? WHERE id = ?', [
            $vwap, $circ, $vwap * $circ, $fdvSupply, $vwap * $fdvSupply, 'exchanges', now_sql(), $coin['id'],
        ]);
        $n++;
    }
    log_msg('sync', "live exchange prices applied to $n coins");
}

// ---------------------------------------------------------------- coin metadata (descriptions, links, categories)
function task_meta(int $limit = 8): void
{
    $list = rows("SELECT id, cg_id FROM coins WHERE rank_no IS NOT NULL AND (meta_updated_at IS NULL OR meta_updated_at < NOW() - INTERVAL 14 DAY)
                  ORDER BY meta_updated_at IS NOT NULL, rank_no LIMIT " . (int)$limit);
    $catByName = [];
    foreach (rows('SELECT id, name FROM categories') as $c) $catByName[strtolower($c['name'])] = (int)$c['id'];
    foreach ($list as $c) {
        $d = CoinGecko::coin($c['cg_id']);
        if (!$d) { q('UPDATE coins SET meta_updated_at = NOW() - INTERVAL 13 DAY WHERE id = ?', [$c['id']]); continue; }
        $l = $d['links'] ?? [];
        $first = fn($a) => is_array($a) ? (array_values(array_filter($a))[0] ?? null) : ($a ?: null);
        $wp = $l['whitepaper'] ?? null; if (is_array($wp)) $wp = $wp['link'] ?? null;
        q('UPDATE coins SET description=?, homepage=?, whitepaper=?, explorer=?, twitter=?, reddit=?, github=?, genesis_date=?, hashing_algorithm=?, platforms=?, meta_updated_at=NOW() WHERE id=?', [
            $d['description']['en'] ?? null, $first($l['homepage'] ?? []), $wp ?: null, $first($l['blockchain_site'] ?? []),
            $l['twitter_screen_name'] ?? null, $l['subreddit_url'] ?? null, $first($l['repos_url']['github'] ?? []),
            !empty($d['genesis_date']) ? $d['genesis_date'] : null, $d['hashing_algorithm'] ?? null,
            !empty($d['platforms']) ? json_encode(array_filter($d['platforms'])) : null, $c['id'],
        ]);
        q('DELETE FROM coin_categories WHERE coin_id = ?', [$c['id']]);
        foreach ($d['categories'] ?? [] as $name) {
            if ($name && isset($catByName[strtolower($name)])) q('INSERT IGNORE INTO coin_categories VALUES (?, ?)', [$c['id'], $catByName[strtolower($name)]]);
        }
    }
    log_msg('sync', 'meta: ' . count($list) . ' coins');
}

// ---------------------------------------------------------------- daily OHLCV history
function save_candles(int $coinId, array $candles, string $source, ?float $circ): int
{
    $recent = time() - 30 * 86400;
    $batch = [];
    foreach ($candles as [$ts, $o, $h, $l, $c, $v]) {
        $batch[] = ['coin_id' => $coinId, 'd' => gmdate('Y-m-d', $ts), 'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c,
            'volume' => $v, 'market_cap' => ($circ && $ts >= $recent) ? $c * $circ : null, 'source' => $source];
    }
    if (!$batch) return 0;
    $cols = array_keys($batch[0]);
    $sql = 'INSERT INTO coin_history_daily (`' . implode('`,`', $cols) . '`) VALUES ';
    foreach (array_chunk($batch, 300) as $part) {
        $ph = []; $p = [];
        foreach ($part as $r) { $ph[] = '(?,?,?,?,?,?,?,?,?)'; array_push($p, ...array_values($r)); }
        q($sql . implode(',', $ph) . ' ON DUPLICATE KEY UPDATE open=VALUES(open), high=VALUES(high), low=VALUES(low), close=VALUES(close),
             volume=VALUES(volume), market_cap=COALESCE(VALUES(market_cap), market_cap), source=VALUES(source)', $p);
    }
    return count($batch);
}

function save_cg_chart(int $coinId, array $chart): int
{
    $caps = []; $vols = [];
    foreach ($chart['market_caps'] ?? [] as [$t, $v]) $caps[gmdate('Y-m-d', intdiv((int)$t, 1000))] = $v;
    foreach ($chart['total_volumes'] ?? [] as [$t, $v]) $vols[gmdate('Y-m-d', intdiv((int)$t, 1000))] = $v;
    $prev = null; $n = 0; $p = []; $ph = [];
    foreach ($chart['prices'] ?? [] as [$t, $v]) {
        $d = gmdate('Y-m-d', intdiv((int)$t, 1000));
        $ph[] = '(?,?,?,?,?,?,?)';
        array_push($p, $coinId, $d, $prev ?? $v, $v, $vols[$d] ?? null, $caps[$d] ?? null, 'coingecko');
        $prev = $v; $n++;
        if (count($ph) >= 300) { cg_flush($ph, $p); $ph = []; $p = []; }
    }
    if ($ph) cg_flush($ph, $p);
    return $n;
}

function cg_flush(array $ph, array $p): void
{
    q('INSERT INTO coin_history_daily (coin_id, d, open, close, volume, market_cap, source) VALUES ' . implode(',', $ph) . '
       ON DUPLICATE KEY UPDATE market_cap = COALESCE(VALUES(market_cap), market_cap),
       open = COALESCE(open, VALUES(open)), close = COALESCE(close, VALUES(close)), volume = COALESCE(volume, VALUES(volume))', $p);
}

function task_history(int $limit = 6): void
{
    $list = rows("SELECT id, cg_id, symbol, circulating_supply FROM coins WHERE rank_no IS NOT NULL
                  AND (history_synced_at IS NULL OR history_synced_at < UTC_DATE()) ORDER BY history_synced_at IS NOT NULL, rank_no LIMIT " . (int)$limit);
    foreach ($list as $c) {
        $sym = strtoupper($c['symbol']);
        $last = val('SELECT MAX(d) FROM coin_history_daily WHERE coin_id = ? AND source IN (\'binance\',\'coinbase\')', [$c['id']]);
        $since = $last ? strtotime($last . ' UTC') : null;
        $circ = (float)$c['circulating_supply'] ?: null;
        $got = 0; $src = '';

        if (val("SELECT 1 FROM markets WHERE exchange='binance' AND base=? AND quote='USDT'", [$sym])) {
            $got = save_candles($c['id'], Exchanges::binanceDailyAll($sym . 'USDT', $since), 'binance', $circ); $src = 'binance';
        }
        if (!$got && val("SELECT 1 FROM markets WHERE exchange='coinbase' AND base=? AND quote='USD'", [$sym])) {
            $got = save_candles($c['id'], Exchanges::coinbaseDailyAll("$sym-USD", $since ?: strtotime('2015-01-01')), 'coinbase', $circ); $src = 'coinbase';
        }
        // Market caps (and prices for coins not on our exchanges) for the past year from CoinGecko.
        $needCg = !$last || date('N') == 1 || !$got;
        if ($needCg && ($chart = CoinGecko::marketChart($c['cg_id'], 365))) {
            $got += save_cg_chart($c['id'], $chart); $src .= '+cg';
        }
        q('UPDATE coins SET history_synced_at = NOW() WHERE id = ?', [$c['id']]);
        log_msg('sync', "history {$c['cg_id']}: $got rows ($src)");
    }
}

// ---------------------------------------------------------------- hourly prices for 1D/7D/1M charts
function task_hourly(int $limit = 200): void
{
    $list = rows("SELECT c.id, c.symbol FROM coins c WHERE c.rank_no IS NOT NULL AND c.rank_no <= ? ORDER BY c.rank_no", [$limit]);
    $n = 0;
    foreach ($list as $c) {
        $sym = strtoupper($c['symbol']);
        if (!val("SELECT 1 FROM markets WHERE exchange='binance' AND base=? AND quote='USDT'", [$sym])) continue;
        $last = (int)val('SELECT MAX(ts) FROM coin_history_hourly WHERE coin_id = ?', [$c['id']]);
        $start = $last ? ($last + 3600) * 1000 : (time() - 31 * 86400) * 1000;
        $k = Exchanges::binanceKlines($sym . 'USDT', '1h', $start, 1000);
        if ($k) {
            $b = array_map(fn($r) => ['coin_id' => $c['id'], 'ts' => $r[0], 'price' => $r[4], 'volume' => $r[5]], $k);
            upsert('coin_history_hourly', $b, ['price', 'volume'], 500);
            $n++;
        }
        usleep(120000);
    }
    q('DELETE FROM coin_history_hourly WHERE ts < ?', [time() - 92 * 86400]);
    log_msg('sync', "hourly: $n coins");
}

// ---------------------------------------------------------------- global / categories / exchanges / trending / fiat
function task_global(): void
{
    $g = CoinGecko::global();
    if (!$g) return;
    $row = [
        'id' => 1, 'total_market_cap' => $g['total_market_cap']['usd'] ?? null, 'total_volume' => $g['total_volume']['usd'] ?? null,
        'mcap_change_24h' => $g['market_cap_change_percentage_24h_usd'] ?? null,
        'btc_dominance' => $g['market_cap_percentage']['btc'] ?? null, 'eth_dominance' => $g['market_cap_percentage']['eth'] ?? null,
        'active_coins' => $g['active_cryptocurrencies'] ?? null, 'markets' => $g['markets'] ?? null,
        'exchanges' => (int)val('SELECT COUNT(*) FROM exchanges') ?: null, 'updated_at' => now_sql(),
    ];
    upsert('global_stats', [$row], array_diff(array_keys($row), ['id']));
    upsert('global_history', [['d' => gmdate('Y-m-d'), 'total_market_cap' => $row['total_market_cap'], 'total_volume' => $row['total_volume'], 'btc_dominance' => $row['btc_dominance']]],
        ['total_market_cap', 'total_volume', 'btc_dominance']);
    log_msg('sync', 'global updated');
}

function task_categories(): void
{
    $data = CoinGecko::categories();
    if (!$data) return;
    $b = [];
    foreach ($data as $c) {
        if (empty($c['id'])) continue;
        $b[] = ['cg_id' => $c['id'], 'slug' => slugify($c['id']), 'name' => $c['name'], 'market_cap' => $c['market_cap'] ?? null,
            'change_24h' => isset($c['market_cap_change_24h']) ? max(-9999, min(99999, $c['market_cap_change_24h'])) : null,
            'volume_24h' => $c['volume_24h'] ?? null, 'top_images' => json_encode($c['top_3_coins'] ?? []),
            'content' => $c['content'] ?? null, 'updated_at' => now_sql()];
    }
    upsert('categories', $b, ['name', 'market_cap', 'change_24h', 'volume_24h', 'top_images', 'content', 'updated_at']);
    log_msg('sync', 'categories: ' . count($b));
}

function task_exchanges(): void
{
    $data = CoinGecko::exchanges(1);
    if (!$data) return;
    $b = []; $seen = [];
    foreach ($data as $x) {
        $slug = slugify($x['name']);
        if (isset($seen[$slug])) $slug = slugify($x['id']);
        $seen[$slug] = 1;
        $b[] = ['cg_id' => $x['id'], 'slug' => $slug, 'name' => $x['name'], 'image' => $x['image'] ?? null,
            'year_established' => $x['year_established'] ?? null, 'country' => $x['country'] ?? null, 'url' => $x['url'] ?? null,
            'description' => $x['description'] ?? null, 'trust_score' => $x['trust_score'] ?? null, 'trust_rank' => $x['trust_score_rank'] ?? null,
            'volume_24h_btc' => $x['trade_volume_24h_btc'] ?? null, 'updated_at' => now_sql()];
    }
    upsert('exchanges', $b, ['name', 'image', 'year_established', 'country', 'url', 'description', 'trust_score', 'trust_rank', 'volume_24h_btc', 'updated_at']);
    log_msg('sync', 'exchanges: ' . count($b));
}

function task_trending(): void
{
    $t = CoinGecko::trending();
    if (empty($t['coins'])) return;
    q('DELETE FROM trending');
    foreach ($t['coins'] as $i => $c) q('INSERT INTO trending VALUES (?, ?, ?)', [$i + 1, $c['item']['id'], now_sql()]);
    log_msg('sync', 'trending: ' . count($t['coins']));
}

function task_fiat(): void
{
    $r = CoinGecko::exchangeRates();
    if (!$r || empty($r['usd']['value'])) return;
    $usd = (float)$r['usd']['value'];
    $signs = ['usd' => '$', 'eur' => '€', 'gbp' => '£', 'jpy' => '¥', 'inr' => '₹', 'krw' => '₩', 'cny' => '¥', 'rub' => '₽', 'try' => '₺',
              'ngn' => '₦', 'php' => '₱', 'brl' => 'R$', 'cad' => 'C$', 'aud' => 'A$', 'vnd' => '₫', 'thb' => '฿', 'idr' => 'Rp', 'zar' => 'R',
              'mxn' => 'MX$', 'chf' => 'CHF ', 'sgd' => 'S$', 'hkd' => 'HK$', 'pkr' => '₨', 'aed' => 'AED '];
    $b = [];
    foreach ($r as $code => $x) {
        if (($x['type'] ?? '') !== 'fiat') continue;
        $b[] = ['code' => $code, 'name' => $x['name'], 'sign' => $signs[$code] ?? strtoupper($code) . ' ', 'rate_usd' => (float)$x['value'] / $usd, 'updated_at' => now_sql()];
    }
    upsert('fiat_rates', $b, ['name', 'sign', 'rate_usd', 'updated_at']);
    log_msg('sync', 'fiat: ' . count($b));
}

function task_cache_clear(): void
{
    $n = 0;
    foreach (glob(STORAGE . '/cache/*.html') ?: [] as $f) if (filemtime($f) < time() - 3600) { @unlink($f); $n++; }
    log_msg('sync', "cache: removed $n stale files");
}

// ================================================================ admin-panel tasks
require_once APP_DIR . '/sources/Google.php';

/** Re-applies admin manual overrides after every market sync so the sync never silently undoes an admin edit. */
function task_overrides(): void
{
    $allowed = ['name', 'image', 'description', 'homepage', 'circulating_supply', 'total_supply', 'max_supply', 'price', 'market_cap', 'volume_24h', 'rank_no'];
    $n = 0;
    foreach (rows('SELECT coin_id, field, value FROM coin_overrides') as $o) {
        if (!in_array($o['field'], $allowed, true)) continue;
        $v = $o['value'] === '' ? null : $o['value'];
        q("UPDATE coins SET `{$o['field']}` = ? WHERE id = ?", [$v, $o['coin_id']]);
        $n++;
    }
    if ($n) log_msg('sync', "overrides applied: $n");
}

/** Daily rollups, retention purge. Safe to re-run: rebuilds the last 3 days from raw pageviews. */
function task_analytics_rollup(): void
{
    traffic_rollup(3);
    $ret = max(7, (int)cfg('analytics_retention_days', 90));
    $n = q('DELETE FROM pageviews WHERE ts < UTC_TIMESTAMP() - INTERVAL ' . $ret . ' DAY LIMIT 50000')->rowCount();
    q('DELETE FROM visitors WHERE last_ts < ?', [time() - 2 * 86400]);
    q('DELETE FROM ad_freq WHERE day < UTC_DATE() - INTERVAL 3 DAY');
    q('DELETE FROM cwv WHERE ts < UTC_TIMESTAMP() - INTERVAL 90 DAY LIMIT 50000');
    q('DELETE FROM api_log WHERE ts < UTC_TIMESTAMP() - INTERVAL 14 DAY LIMIT 50000');
    q('DELETE FROM login_history WHERE created_at < UTC_TIMESTAMP() - INTERVAL 180 DAY');
    q('DELETE FROM user_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    log_msg('sync', "rollup done, purged $n old pageviews");
}

/** Permanently deletes accounts whose 7-day deletion grace period elapsed. */
function task_purge_accounts(): void
{
    $ids = array_column(rows("SELECT id FROM users WHERE deletion_requested_at IS NOT NULL AND deletion_requested_at < UTC_TIMESTAMP() - INTERVAL 7 DAY AND role = 'user'"), 'id');
    foreach ($ids as $id) {
        foreach (['user_watchlist', 'user_tokens'] as $t) q("DELETE FROM $t WHERE user_id = ?", [$id]);
        q('UPDATE login_history SET user_id = NULL, email = NULL, ip = NULL, user_agent = NULL WHERE user_id = ?', [$id]);
        q('DELETE FROM users WHERE id = ?', [$id]);
        audit('user.purged', 'user', $id);
    }
    if ($ids) log_msg('sync', 'accounts purged: ' . count($ids));
}

function task_gsc(): void
{
    if (!Google::configured('gsc')) return;
    $end = gmdate('Y-m-d', time() - 2 * 86400);                          // GSC data lags ~2 days
    $last = kv_get('gsc_last_day');
    $start = $last ? gmdate('Y-m-d', strtotime($last . ' -2 days')) : gmdate('Y-m-d', time() - 90 * 86400);
    if ($start > $end) return;
    $base = ['startDate' => $start, 'endDate' => $end, 'rowLimit' => 25000, 'dataState' => 'final'];
    $tot = Google::gscQuery($base + ['dimensions' => ['date']]);
    if ($tot === null) { alert_raise('gsc_sync', 'gsc', 'warning', 'Search Console sync failed. Check the service account has access to the property.'); return; }
    foreach ($tot as $r) q('REPLACE INTO gsc_totals (day, clicks, impressions, ctr, position) VALUES (?,?,?,?,?)', [$r['keys'][0], $r['clicks'], $r['impressions'], $r['ctr'], $r['position']]);
    foreach ([['query', 'gsc_queries', 'query'], ['page', 'gsc_pages', 'page']] as [$dim, $table, $col]) {
        $rows = Google::gscQuery($base + ['dimensions' => ['date', $dim], 'rowLimit' => 5000]) ?: [];
        foreach ($rows as $r) q("REPLACE INTO $table (day, `$col`, clicks, impressions, position) VALUES (?,?,?,?,?)", [$r['keys'][0], mb_substr($r['keys'][1], 0, $dim === 'query' ? 160 : 200), $r['clicks'], $r['impressions'], $r['position']]);
    }
    foreach (Google::gscQuery($base + ['dimensions' => ['date', 'country'], 'rowLimit' => 5000]) ?: [] as $r)
        q('REPLACE INTO gsc_countries (day, country, clicks, impressions) VALUES (?,?,?,?)', [$r['keys'][0], strtoupper(substr($r['keys'][1], 0, 3)), $r['clicks'], $r['impressions']]);
    q('DELETE FROM gsc_queries WHERE day < UTC_DATE() - INTERVAL 120 DAY'); q('DELETE FROM gsc_pages WHERE day < UTC_DATE() - INTERVAL 120 DAY');
    kv_set('gsc_last_day', $end);
    alert_resolve('gsc_sync', 'gsc');
    log_msg('sync', 'gsc: ' . count($tot) . ' days');
}

function task_ga4(): void
{
    if (!Google::configured('ga4')) return;
    $rows = Google::ga4Report(['dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']], 'dimensions' => [['name' => 'date']],
        'metrics' => [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews'], ['name' => 'engagedSessions']]]);
    if ($rows === null) { alert_raise('ga4_sync', 'ga4', 'warning', 'Google Analytics sync failed. Check the service account has Viewer access to the GA4 property.'); return; }
    foreach ($rows as $r) {
        $d = $r['dimensionValues'][0]['value']; $m = array_column($r['metricValues'], 'value');
        q('REPLACE INTO ga_daily (day, users, sessions, pageviews, engaged_sessions) VALUES (?,?,?,?,?)', [substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2), $m[0], $m[1], $m[2], $m[3]]);
    }
    alert_resolve('ga4_sync', 'ga4');
    log_msg('sync', 'ga4: ' . count($rows) . ' days');
}

/** Weekly automated technical SEO audit; raises an alert when critical issues are found. */
function task_seo_audit(): void
{
    require_once APP_DIR . '/lib/seoaudit.php';
    $r = seo_audit_run(120);
    $c = $r['summary']['critical'];
    if ($c > 0) alert_raise('seo_audit', '', 'warning', "Automated SEO audit found $c critical issue(s). Open SEO Center → Site audit.");
    else alert_resolve('seo_audit', '');
    log_msg('sync', "seo audit: {$r['summary']['critical']} critical, {$r['summary']['warning']} warnings");
}

// ---------------------------------------------------------------- alerts
function alert_raise(string $type, string $key, string $sev, string $msg): void
{
    $open = row('SELECT id, message FROM alerts WHERE type = ? AND dedupe_key = ? AND resolved_at IS NULL', [$type, $key]);
    if ($open) { q('UPDATE alerts SET last_seen_at = UTC_TIMESTAMP(), message = ?, severity = ? WHERE id = ?', [$msg, $sev, $open['id']]); return; }
    q('INSERT INTO alerts (severity, type, dedupe_key, message, last_seen_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$sev, $type, $key, $msg]);
}

function alert_resolve(string $type, string $key = ''): void
{
    q('UPDATE alerts SET resolved_at = UTC_TIMESTAMP() WHERE type = ? AND dedupe_key = ? AND resolved_at IS NULL', [$type, $key]);
}

/** Rule engine: runs every few minutes; raises, refreshes and auto-resolves alerts, then notifies on new ones. */
function task_alerts(): void
{
    $check = function (string $type, string $key, bool $firing, string $sev, string $msg) {
        if ($firing) alert_raise($type, $key, $sev, $msg); else alert_resolve($type, $key);
    };
    // data freshness
    foreach (['markets' => 20, 'tickers' => 15, 'global' => 40] as $t => $mins) {
        $last = (int)kv_get("last_run_$t", 0);
        $check('stale_data', $t, $last > 0 && time() - $last > $mins * 60, 'critical', "Data task '$t' has not run successfully for over $mins minutes (last: " . ($last ? gmdate('H:i', $last) . ' UTC' : 'never') . ').');
    }
    // external API health (last 30 min)
    foreach (rows("SELECT host, COUNT(*) n, SUM(ok = 0) bad FROM api_log WHERE ts > UTC_TIMESTAMP() - INTERVAL 30 MINUTE GROUP BY host") as $h) {
        $check('api_errors', $h['host'], $h['n'] >= 5 && $h['bad'] / $h['n'] > 0.5, 'warning', "{$h['host']}: {$h['bad']} of {$h['n']} requests failed in the last 30 minutes.");
    }
    // server errors / 404 spikes (last hour)
    $p = row("SELECT SUM(status >= 500) e5, SUM(status = 404) e4, COUNT(*) n FROM pageviews WHERE ts > UTC_TIMESTAMP() - INTERVAL 1 HOUR AND is_bot = 0");
    $check('errors_5xx', '', (int)$p['e5'] >= 5, 'critical', (int)$p['e5'] . ' server errors (5xx) served to visitors in the last hour.');
    $check('errors_404', '', (int)$p['n'] >= 50 && $p['e4'] / max(1, $p['n']) > 0.15, 'warning', 'More than 15% of page views in the last hour were 404 — check the SEO Center → 404 monitor.');
    // traffic anomaly: yesterday vs average of previous 7 days
    $y = row('SELECT views, googlebot FROM traffic_daily WHERE day = UTC_DATE() - INTERVAL 1 DAY');
    $avg = row('SELECT AVG(views) v, AVG(googlebot) g, COUNT(*) n FROM traffic_daily WHERE day BETWEEN UTC_DATE() - INTERVAL 8 DAY AND UTC_DATE() - INTERVAL 2 DAY');
    if ($y && $avg && (int)$avg['n'] >= 5) {
        $check('traffic_drop', '', $avg['v'] > 200 && $y['views'] < $avg['v'] * 0.6, 'warning', 'Yesterday’s page views (' . (int)$y['views'] . ') were over 40% below the 7-day average (' . (int)$avg['v'] . ').');
        $check('googlebot_drop', '', $avg['g'] > 50 && $y['googlebot'] < $avg['g'] * 0.4, 'warning', 'Googlebot crawl activity dropped by over 60% versus the 7-day average — check robots.txt, server errors and Search Console.');
    }
    // workflow
    $pend = (int)val("SELECT COUNT(*) FROM ad_campaigns WHERE status = 'pending'");
    $check('ads_pending', '', $pend > 0, 'info', "$pend ad campaign(s) waiting for approval.");
    $pc = (int)val("SELECT COUNT(*) FROM coins WHERE status = 'pending'");
    $check('coins_pending', '', $pc > 0, 'info', "$pc coin listing(s) waiting for review.");
    $ending = rows("SELECT id, name FROM ad_campaigns WHERE status = 'approved' AND budget > 0 AND spent >= budget * 0.9");
    foreach ($ending as $c) alert_raise('ad_budget', (string)$c['id'], 'info', "Campaign “{$c['name']}” has used over 90% of its budget.");
    // disk
    $free = @disk_free_space(STORAGE); $tot = @disk_total_space(STORAGE);
    if ($free && $tot) $check('disk_low', '', $free / $tot < 0.1, 'critical', 'Server disk is over 90% full (' . round($free / 1e9, 1) . ' GB free).');

    // notify (Slack-compatible webhook and/or email) for new warning/critical alerts
    $new = rows("SELECT id, severity, message FROM alerts WHERE notified = 0 AND resolved_at IS NULL AND severity IN ('warning','critical')");
    if (!$new) return;
    $text = cfg('site_name') . " alerts:\n" . implode("\n", array_map(fn($a) => '• [' . strtoupper($a['severity']) . '] ' . $a['message'], $new));
    $hook = setting('alert_webhook');
    if ($hook && preg_match('~^https://~', $hook)) {
        $ch = curl_init($hook);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['text' => $text]), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($ch); curl_close($ch);
    }
    if ($to = setting('alert_email')) send_mail($to, '[' . cfg('site_name') . '] ' . count($new) . ' new alert(s)', $text);
    foreach ($new as $a) q('UPDATE alerts SET notified = 1 WHERE id = ?', [$a['id']]);
}

/** Weekly plain-text digest to Settings → Notifications → digest email. Returns the text (sent only when an address is configured). */
function digest_text(): string
{
    traffic_rollup(8);
    $a = row('SELECT COALESCE(SUM(views),0) v, COALESCE(SUM(visitors),0) u, COALESCE(SUM(sessions),0) s, COALESCE(SUM(organic_sessions),0) o, COALESCE(SUM(googlebot),0) g FROM traffic_daily WHERE day BETWEEN UTC_DATE() - INTERVAL 7 DAY AND UTC_DATE() - INTERVAL 1 DAY');
    $b = row('SELECT COALESCE(SUM(views),0) v, COALESCE(SUM(sessions),0) s, COALESCE(SUM(organic_sessions),0) o FROM traffic_daily WHERE day BETWEEN UTC_DATE() - INTERVAL 14 DAY AND UTC_DATE() - INTERVAL 8 DAY');
    $d = fn($x, $y) => $y > 0 ? sprintf('%+.1f%%', ($x - $y) / $y * 100) : 'n/a';
    $t = cfg('site_name') . " weekly digest\n" . str_repeat('=', 32) . "\n\n";
    $t .= "TRAFFIC (last 7 full days vs previous 7)\n  Page views: {$a['v']} ({$d($a['v'], $b['v'])})\n  Sessions:   {$a['s']} ({$d($a['s'], $b['s'])})\n  Organic:    {$a['o']} ({$d($a['o'], $b['o'])})\n  Googlebot hits: {$a['g']}\n\n";
    $top = rows('SELECT path, COUNT(*) n FROM pageviews WHERE is_bot = 0 AND day BETWEEN UTC_DATE() - INTERVAL 7 DAY AND UTC_DATE() - INTERVAL 1 DAY GROUP BY path ORDER BY n DESC LIMIT 5');
    $t .= "TOP PAGES\n" . implode("\n", array_map(fn($r) => "  {$r['n']}  {$r['path']}", $top)) . "\n\n";
    $g = row('SELECT COALESCE(SUM(clicks),0) c, COALESCE(SUM(impressions),0) i FROM gsc_totals WHERE day >= UTC_DATE() - INTERVAL 9 DAY');
    if ($g['i']) $t .= "SEARCH CONSOLE (last ~7 days)\n  Clicks: {$g['c']}  Impressions: {$g['i']}\n\n";
    $su = (int)val('SELECT COUNT(*) FROM users WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY');
    $inc = (float)val("SELECT COALESCE(SUM(amount),0) FROM ledger WHERE type='income' AND day >= UTC_DATE() - INTERVAL 7 DAY");
    $ex = (float)val("SELECT COALESCE(SUM(amount),0) FROM ledger WHERE type='expense' AND day >= UTC_DATE() - INTERVAL 7 DAY");
    $t .= "BUSINESS\n  New members: $su\n  Income recorded: $" . number_format($inc, 2) . "\n  Expenses recorded: $" . number_format($ex, 2) . "\n\n";
    $al = rows("SELECT severity, message FROM alerts WHERE resolved_at IS NULL ORDER BY id DESC LIMIT 8");
    $t .= $al ? "OPEN ALERTS\n" . implode("\n", array_map(fn($r) => "  [{$r['severity']}] {$r['message']}", $al)) . "\n\n" : "No open alerts.\n\n";
    return $t . 'Open the control center: ' . base_url() . "/admin/\n";
}

function task_digest(): void
{
    $to = setting('digest_email');
    if (!$to) return;
    send_mail($to, cfg('site_name') . ' weekly digest', digest_text());
    log_msg('sync', 'digest sent');
}
