<?php

/** Public visibility: only active, non-scam coins appear in lists, search, sitemaps and converters. */
const COIN_OK = "status = 'active' AND risk_flag <> 'scam'";
const COIN_OK_C = "c.status = 'active' AND c.risk_flag <> 'scam'";

const COIN_LIST_COLS = 'id, cg_id, slug, symbol, name, image, verified, risk_flag, rank_no, price, market_cap, volume_24h, change_1h, change_24h, change_7d, circulating_supply, max_supply, sparkline_7d, created_at';

function global_stats(): array
{
    static $g = null;
    if ($g !== null) return $g;
    $g = row('SELECT * FROM global_stats WHERE id = 1') ?: [];
    if (empty($g['total_market_cap'])) {
        // Fallback: derive from tracked coins
        $agg = row('SELECT SUM(market_cap) mc, SUM(volume_24h) vol, COUNT(*) n FROM coins WHERE market_cap > 0 AND ' . COIN_OK);
        $btc = (float)val("SELECT market_cap FROM coins WHERE cg_id='bitcoin'");
        $eth = (float)val("SELECT market_cap FROM coins WHERE cg_id='ethereum'");
        $mc = (float)($agg['mc'] ?? 0);
        $g = [
            'total_market_cap' => $mc, 'total_volume' => (float)($agg['vol'] ?? 0), 'mcap_change_24h' => null,
            'btc_dominance' => $mc ? $btc / $mc * 100 : null, 'eth_dominance' => $mc ? $eth / $mc * 100 : null,
            'active_coins' => (int)($agg['n'] ?? 0), 'markets' => (int)val('SELECT COUNT(*) FROM markets'),
            'exchanges' => (int)val('SELECT COUNT(*) FROM exchanges'), 'updated_at' => null,
        ];
    }
    if (empty($g['exchanges'])) $g['exchanges'] = (int)val('SELECT COUNT(*) FROM exchanges');
    return $g;
}

function coins_page(int $page, int $per, string $order = 'rank_no IS NULL, rank_no ASC'): array
{
    $off = ($page - 1) * $per;
    return rows("SELECT " . COIN_LIST_COLS . " FROM coins WHERE price IS NOT NULL AND " . COIN_OK . " ORDER BY $order LIMIT $per OFFSET $off");
}

function coins_count(): int { return (int)val('SELECT COUNT(*) FROM coins WHERE price IS NOT NULL AND ' . COIN_OK); }

/** Public lookup: honours admin status. Delisted → 410, merged → 301 to target, hidden/pending → 404. Scam-flagged coins stay reachable (with warning + noindex). */
function coin_by_slug(string $slug): ?array
{
    $c = row('SELECT * FROM coins WHERE slug = ?', [$slug]);
    if (!$c) return null;
    if (!empty($c['merged_into'])) {
        $t = row('SELECT slug FROM coins WHERE id = ? AND status = \'active\'', [$c['merged_into']]);
        if ($t && PHP_SAPI !== 'cli' && !headers_sent()) { header('Location: ' . path_norm('/currencies/' . $t['slug']), true, 301); exit; }
    }
    $st = $c['status'] ?? 'active';
    if ($st === 'delisted') { $GLOBALS['__gone'] = true; return null; }
    if ($st !== 'active') return null;
    return $c;
}

function coin_by_symbol(string $sym): ?array
{
    return row('SELECT * FROM coins WHERE symbol = ? AND price IS NOT NULL AND ' . COIN_OK . ' ORDER BY rank_no IS NULL, rank_no LIMIT 1', [strtolower($sym)]);
}

function trending_coins(int $n = 7): array
{
    $r = rows("SELECT " . COIN_LIST_COLS . " FROM trending t JOIN coins c ON c.cg_id = t.coin_cg_id WHERE " . COIN_OK_C . " ORDER BY t.position LIMIT $n");
    if (!$r) { // fallback: biggest 24h volume/mcap ratio among top 300
        $r = rows("SELECT " . COIN_LIST_COLS . " FROM coins WHERE rank_no <= 300 AND market_cap > 0 AND " . COIN_OK . " ORDER BY volume_24h / market_cap DESC LIMIT $n");
    }
    return $r;
}

function movers(string $dir = 'up', int $n = 30, int $minVol = 50000, string $field = 'change_24h'): array
{
    $ord = $dir === 'up' ? 'DESC' : 'ASC';
    $field = in_array($field, ['change_1h', 'change_24h', 'change_7d', 'change_30d'], true) ? $field : 'change_24h';
    return rows("SELECT " . COIN_LIST_COLS . " FROM coins WHERE $field IS NOT NULL AND volume_24h >= ? AND rank_no <= 1000 AND " . COIN_OK . " ORDER BY $field $ord LIMIT $n", [$minVol]);
}

function recently_added(int $n = 30): array
{
    return rows("SELECT " . COIN_LIST_COLS . " FROM coins WHERE price IS NOT NULL AND " . COIN_OK . " ORDER BY created_at DESC, id DESC LIMIT $n");
}

function coin_markets(array $coin, int $limit = 30): array
{
    return rows('SELECT * FROM markets WHERE base = ? AND volume_usd > 0 ORDER BY volume_usd DESC LIMIT ' . (int)$limit, [strtoupper($coin['symbol'])]);
}

function coin_categories(int $coinId): array
{
    return rows('SELECT c.* FROM coin_categories cc JOIN categories c ON c.id = cc.category_id WHERE cc.coin_id = ? ORDER BY c.market_cap DESC', [$coinId]);
}

function related_coins(array $coin, int $n = 8): array
{
    $r = rows("SELECT " . COIN_LIST_COLS . " FROM coins c JOIN coin_categories cc ON cc.coin_id = c.id
              WHERE cc.category_id IN (SELECT category_id FROM coin_categories WHERE coin_id = ?) AND c.id <> ? AND c.price IS NOT NULL AND " . COIN_OK_C . "
              GROUP BY c.id ORDER BY c.rank_no IS NULL, c.rank_no LIMIT $n", [$coin['id'], $coin['id']]);
    if (count($r) < $n) {
        $rank = (int)($coin['rank_no'] ?: 50);
        $more = rows("SELECT " . COIN_LIST_COLS . " FROM coins WHERE id <> ? AND rank_no BETWEEN ? AND ? AND " . COIN_OK . " ORDER BY rank_no LIMIT $n", [$coin['id'], max(1, $rank - 5), $rank + 10]);
        $ids = array_column($r, 'id');
        foreach ($more as $m) if (!in_array($m['id'], $ids) && count($r) < $n) $r[] = $m;
    }
    return $r;
}

function fiats(): array
{
    static $f = null;
    if ($f === null) {
        $f = [];
        foreach (rows('SELECT * FROM fiat_rates') as $r) $f[$r['code']] = $r;
        if (!isset($f['usd'])) $f['usd'] = ['code' => 'usd', 'name' => 'US Dollar', 'sign' => '$', 'rate_usd' => 1];
    }
    return $f;
}

/** Fiat currencies we generate converter pages for (ordered by search demand). */
function top_fiats(): array
{
    return ['usd', 'eur', 'gbp', 'inr', 'cad', 'aud', 'jpy', 'krw', 'brl', 'try', 'ngn', 'php', 'idr', 'rub', 'cny', 'chf', 'mxn', 'zar', 'pkr', 'aed', 'sgd', 'hkd', 'thb', 'vnd'];
}

function history_daily(int $coinId, ?string $from = null, ?string $to = null): array
{
    $w = 'coin_id = ?'; $p = [$coinId];
    if ($from) { $w .= ' AND d >= ?'; $p[] = $from; }
    if ($to) { $w .= ' AND d <= ?'; $p[] = $to; }
    return rows("SELECT d, open, high, low, close, volume, market_cap FROM coin_history_daily WHERE $w ORDER BY d DESC", $p);
}

function history_years(int $coinId): array
{
    return array_map('intval', array_column(rows('SELECT DISTINCT YEAR(d) y FROM coin_history_daily WHERE coin_id = ? ORDER BY y DESC', [$coinId]), 'y'));
}
