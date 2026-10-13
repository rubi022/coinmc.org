<?php
/**
 * Data sync tasks. Run through cron/run.php.
 */
require_once APP_DIR . '/lib/http.php';
require_once APP_DIR . '/sources/CoinGecko.php';
require_once APP_DIR . '/sources/Exchanges.php';

const STABLES = ['USDT', 'USDC', 'FDUSD', 'TUSD', 'BUSD', 'DAI', 'USD', 'USDE', 'PYUSD', 'USDP'];
const QUOTE_CG = ['BTC' => 'bitcoin', 'ETH' => 'ethereum', 'BNB' => 'binancecoin', 'SOL' => 'solana', 'XRP' => 'ripple', 'DOGE' => 'dogecoin', 'TRX' => 'tron'];

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
    if ($total >= 200) q('UPDATE coins SET rank_no = NULL WHERE updated_at < ?', [$runStart]);
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
