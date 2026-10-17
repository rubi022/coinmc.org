<?php
require_once APP_DIR . '/sources/Exchanges.php';
require_once APP_DIR . '/account.php';
require_once APP_DIR . '/news.php';

function dispatch(string $path): void
{
    $routes = [
        '~^/$~'                                                   => 'ctl_home',
        '~^/currencies/([a-z0-9-]+)/$~'                           => 'ctl_coin',
        '~^/currencies/([a-z0-9-]+)/historical-data/$~'           => 'ctl_history',
        '~^/currencies/([a-z0-9-]+)/historical-data/(\d{4})/$~'   => 'ctl_history',
        '~^/currencies/([a-z0-9-]+)/historical-data\.csv$~'       => 'ctl_history_csv',
        '~^/convert/$~'                                           => 'ctl_convert_index',
        '~^/convert/([a-z0-9-]+)/([a-z0-9-]+)/$~'                 => 'ctl_convert',
        '~^/categories/$~'                                        => 'ctl_categories',
        '~^/categories/([a-z0-9-]+)/$~'                           => 'ctl_category',
        '~^/exchanges/$~'                                         => 'ctl_exchanges',
        '~^/exchanges/([a-z0-9-]+)/$~'                            => 'ctl_exchange',
        '~^/gainers-losers/$~'                                    => 'ctl_gainers',
        '~^/trending/$~'                                          => 'ctl_trending',
        '~^/new/$~'                                               => 'ctl_new',
        '~^/charts/$~'                                            => 'ctl_charts',
        '~^/watchlist/$~'                                         => 'ctl_watchlist',
        '~^/search/$~'                                            => 'ctl_search',
        '~^/(about|methodology|privacy|terms|disclaimer|advertising-disclosure)/$~' => 'ctl_static',
        '~^/login/$~' => 'ctl_login', '~^/login/2fa/$~' => 'ctl_login_2fa', '~^/register/$~' => 'ctl_register', '~^/logout/$~' => 'ctl_logout',
        '~^/verify/$~' => 'ctl_verify', '~^/forgot/$~' => 'ctl_forgot', '~^/reset/$~' => 'ctl_reset',
        '~^/account/$~' => 'ctl_account', '~^/account/security/$~' => 'ctl_account_security', '~^/account/export/$~' => 'ctl_account_export',
        '~^/news/$~' => 'ctl_news', '~^/news/([a-z0-9-]+)/$~' => 'ctl_article',
        '~^/go/ad/(\d+)/$~' => 'ctl_go_ad', '~^/go/([a-z0-9-]+)/$~' => 'ctl_go_aff',
        '~^/api/me/?$~' => 'api_me', '~^/api/watchlist/?$~' => 'api_watchlist', '~^/api/ad/?$~' => 'api_ad', '~^/api/vitals/?$~' => 'api_vitals',
        '~^/api/chart/?$~'                                        => 'api_chart',
        '~^/api/search/?$~'                                       => 'api_search',
        '~^/api/coins/?$~'                                        => 'api_coins',
        '~^/api/global-chart/?$~'                                 => 'api_global_chart',
        '~^/robots\.txt$~'                                        => 'ctl_robots',
        '~^/sitemap\.xml$~'                                       => 'ctl_sitemap_index',
        '~^/sitemaps/([a-z]+)-(\d+)\.xml$~'                       => 'ctl_sitemap',
    ];
    foreach ($routes as $re => $fn) {
        if (preg_match($re, $path, $m)) { array_shift($m); $fn(...$m); return; }
    }
    not_found();
}

function cur_page(): int { return max(1, min(500, (int)($_GET['page'] ?? 1))); }

// ============================================================ HOME
function ctl_home(): void
{
    $per = (int)cfg('per_page', 100);
    $page = cur_page();
    $total = coins_count();
    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) { not_found(); return; }
    $coins = coins_page($page, $per);
    $g = global_stats();
    $site = cfg('site_name');
    $title = $page > 1
        ? "Cryptocurrency Prices, Charts and Market Caps – Page $page | $site"
        : "Cryptocurrency Prices, Charts And Market Capitalizations | $site";
    $desc = 'Top cryptocurrency prices and charts, listed by market capitalization. Free access to current and historic data for Bitcoin and '
        . number_format($total) . '+ cryptocurrencies. Global crypto market cap: ' . fmt_big($g['total_market_cap'] ?? null) . '.';
    echo page('home', [
        'coins' => $coins, 'page' => $page, 'pages' => $pages, 'per' => $per, 'g' => $g, 'total' => $total,
        'trending' => $page === 1 ? trending_coins(3) : [], 'gainers' => $page === 1 ? movers('up', 3) : [], 'recent' => $page === 1 ? recently_added(3) : [],
    ], [
        'title' => $title, 'description' => $desc,
        'canonical' => $page > 1 ? '/?page=' . $page : '/',
        'prev' => $page > 1 ? ($page == 2 ? '/' : '/?page=' . ($page - 1)) : null,
        'next' => $page < $pages ? '/?page=' . ($page + 1) : null,
        'ld' => array_filter([$page === 1 ? ld_site() : null, ld_itemlist($coins, ($page - 1) * $per)]),
    ]);
}

// ============================================================ COIN
function coin_faq(array $c, array $markets): array
{
    $n = $c['name']; $s = strtoupper($c['symbol']);
    $exs = array_values(array_unique(array_map(fn($m) => Exchanges::NAMES[$m['exchange']] ?? ucfirst($m['exchange']), $markets)));
    $faq = [
        "What is the price of $n ($s) today?" => "The live price of $n is " . fmt_price($c['price']) . ' USD, ' . (num($c['change_24h']) >= 0 ? 'up ' : 'down ') . pct_raw($c['change_24h']) . " in the last 24 hours. Prices are updated every few minutes from exchange order books.",
        "What is the market cap of $n?" => "$n has a market capitalization of " . fmt_usd($c['market_cap']) . ($c['rank_no'] ? ", ranking #{$c['rank_no']} among all cryptocurrencies tracked by " . cfg('site_name') . '.' : '.'),
        "How many $s coins are in circulation?" => 'There are ' . fmt_supply($c['circulating_supply'], $s) . ' in circulation' . (num($c['max_supply']) ? ' out of a maximum supply of ' . fmt_supply($c['max_supply'], $s) . '.' : '. There is no fixed maximum supply.'),
        "What is the all-time high of $n?" => "The all-time high (ATH) of $n is " . fmt_price($c['ath']) . ($c['ath_date'] ? ', reached on ' . fmt_date($c['ath_date']) : '') . '. The current price is ' . pct_raw($c['ath_change']) . ' below its ATH.',
        "What was the 24-hour trading volume of $n?" => "In the last 24 hours, $n recorded a trading volume of " . fmt_usd($c['volume_24h']) . '.',
    ];
    if ($exs) $faq["Where can I buy $n ($s)?"] = "$s is actively traded on " . implode(', ', array_slice($exs, 0, 5)) . '. See the markets table on this page for live pairs, prices and volumes.';
    return $faq;
}

function ctl_coin(string $slug): void
{
    $c = coin_by_slug($slug);
    if (!$c) { not_found('We could not find that cryptocurrency.'); return; }
    $s = strtoupper($c['symbol']); $n = $c['name']; $site = cfg('site_name');
    $markets = coin_markets($c, 25);
    $faq = coin_faq($c, $markets);
    $title = "$n price today, $s to USD live price, marketcap and chart | $site";
    $desc = "The live $n price today is " . fmt_price($c['price']) . " USD with a 24-hour trading volume of " . fmt_usd($c['volume_24h'])
        . " USD. $n is " . (num($c['change_24h']) >= 0 ? 'up ' : 'down ') . pct_raw($c['change_24h']) . ' in the last 24 hours.'
        . ($c['rank_no'] ? " The current $site ranking is #{$c['rank_no']}, with a live market cap of " . fmt_usd($c['market_cap']) . ' USD.' : '');
    echo page('coin', [
        'c' => $c, 'markets' => $markets, 'faq' => $faq,
        'cats' => coin_categories((int)$c['id']), 'related' => related_coins($c),
        'recent' => array_slice(history_daily((int)$c['id']), 0, 7),
    ], [
        'title' => $title, 'description' => $desc, 'canonical' => coin_url($c),
        'og_image' => $c['image'] ? str_replace('/small/', '/large/', $c['image']) : null,
        'ld' => [ld_coin($c), ld_breadcrumbs(['Cryptocurrencies' => '/', $n => coin_url($c)]), ld_faq($faq)],
        'charts' => true,
        'robots' => $c['risk_flag'] === 'scam' ? 'noindex,follow' : null,
    ]);
}

// ============================================================ HISTORICAL DATA
function ctl_history(string $slug, ?string $year = null): void
{
    $c = coin_by_slug($slug);
    if (!$c) { not_found(); return; }
    $years = history_years((int)$c['id']);
    $year = $year ? (int)$year : null;
    if ($year && !in_array($year, $years, true)) { not_found('No historical data for that year.'); return; }
    $rowsH = $year ? history_daily((int)$c['id'], "$year-01-01", "$year-12-31") : array_slice(history_daily((int)$c['id']), 0, 365);
    $s = strtoupper($c['symbol']); $n = $c['name']; $site = cfg('site_name');
    $first = $rowsH ? end($rowsH) : null; $last = $rowsH[0] ?? null;
    $stats = null;
    if ($rowsH) {
        $highs = array_filter(array_map(fn($r) => num($r['high'] ?? null) ?? num($r['close']), $rowsH));
        $lows = array_filter(array_map(fn($r) => num($r['low'] ?? null) ?? num($r['close']), $rowsH));
        $stats = ['open' => num($first['open']) ?: num($first['close']), 'close' => num($last['close']), 'high' => $highs ? max($highs) : null, 'low' => $lows ? min($lows) : null,
            'from' => $first['d'], 'to' => $last['d']];
        $stats['change'] = $stats['open'] ? ($stats['close'] / $stats['open'] - 1) * 100 : null;
    }
    if ($year) {
        $title = "$n ($s) Price History $year – Daily OHLC Data | $site";
        $desc = "$n price history for $year: opened at " . fmt_price($stats['open'] ?? null) . ', closed at ' . fmt_price($stats['close'] ?? null)
            . ', high ' . fmt_price($stats['high'] ?? null) . ', low ' . fmt_price($stats['low'] ?? null) . ". Daily $s open, high, low, close, volume and market cap.";
    } else {
        $title = "$n Historical Data ($s) – Price History, OHLC & Market Cap | $site";
        $desc = "Download $n ($s) historical price data: daily open, high, low, close (OHLC), volume and market cap" . ($years ? ' from ' . min($years) . ' to today' : '') . '. Free CSV export.';
    }
    $path = coin_url($c) . 'historical-data/' . ($year ? "$year/" : '');
    $ld = [ld_breadcrumbs(['Cryptocurrencies' => '/', $n => coin_url($c), 'Historical Data' => coin_url($c) . 'historical-data/'] + ($year ? [(string)$year => $path] : []))];
    if ($stats) $ld[] = ld_dataset($c, $stats['from'], $stats['to'], count($rowsH));
    echo page('history', ['c' => $c, 'rows' => $rowsH, 'years' => $years, 'year' => $year, 'stats' => $stats], [
        'title' => $title, 'description' => $desc, 'canonical' => $path, 'ld' => $ld,
        'robots' => $rowsH ? null : 'noindex,follow',
    ]);
}

function ctl_history_csv(string $slug): void
{
    $c = coin_by_slug($slug);
    if (!$c) { not_found(); return; }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $c['slug'] . '-historical-data.csv"');
    header('X-Robots-Tag: noindex');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['date', 'open', 'high', 'low', 'close', 'volume_usd', 'market_cap_usd']);
    foreach (history_daily((int)$c['id']) as $r) fputcsv($f, [$r['d'], $r['open'], $r['high'], $r['low'], $r['close'], $r['volume'], $r['market_cap']]);
    fclose($f);
}

// ============================================================ CONVERTER
function resolve_asset(string $code): ?array
{
    $fi = fiats();
    if (isset($fi[$code])) {
        $f = $fi[$code];
        return ['type' => 'fiat', 'code' => $code, 'symbol' => strtoupper($code), 'name' => $f['name'], 'usd' => 1 / (float)$f['rate_usd'], 'sign' => $f['sign']];
    }
    $c = coin_by_symbol($code);
    if (!$c) return null;
    return ['type' => 'coin', 'code' => strtolower($c['symbol']), 'symbol' => strtoupper($c['symbol']), 'name' => $c['name'], 'usd' => (float)$c['price'], 'coin' => $c, 'sign' => ''];
}

function ctl_convert(string $from, string $to): void
{
    $a = resolve_asset($from); $b = resolve_asset($to);
    if (!$a || !$b || $from === $to || $a['usd'] <= 0 || $b['usd'] <= 0) { not_found('Conversion pair not found.'); return; }
    if ($a['type'] === 'fiat' && $b['type'] === 'fiat') { not_found(); return; }
    $rate = $a['usd'] / $b['usd'];
    $site = cfg('site_name');
    $fmtB = fn($v) => $b['type'] === 'fiat' ? fmt_price($v, $b['sign']) : fmt_num($v, $v >= 1 ? 4 : 8) . ' ' . $b['symbol'];
    $title = "{$a['symbol']} to {$b['symbol']}: Convert {$a['name']} to {$b['name']} | $site";
    $desc = "1 {$a['symbol']} = " . strip_tags($fmtB($rate)) . " right now. Convert {$a['name']} ({$a['symbol']}) to {$b['name']} ({$b['symbol']}) with our live calculator and conversion tables.";
    $coin = $a['coin'] ?? $b['coin'] ?? null;
    $faq = [
        "How much is 1 {$a['symbol']} in {$b['symbol']}?" => "1 {$a['name']} is currently worth " . strip_tags($fmtB($rate)) . '.',
        "How do I convert {$a['symbol']} to {$b['symbol']}?" => "Enter the amount of {$a['symbol']} in the calculator above and the equivalent value in {$b['symbol']} is shown instantly, using live exchange rates updated every few minutes.",
    ];
    // canonical: coin→fiat direction is primary; fiat→coin still indexable (separate search intent)
    echo page('convert', ['a' => $a, 'b' => $b, 'rate' => $rate, 'coin' => $coin, 'faq' => $faq], [
        'title' => $title, 'description' => $desc, 'canonical' => convert_url($a['code'], $b['code']),
        'ld' => [ld_breadcrumbs(['Converter' => '/convert/', "{$a['symbol']} to {$b['symbol']}" => convert_url($a['code'], $b['code'])]), ld_faq($faq)],
    ]);
}

function ctl_convert_index(): void
{
    $coins = coins_page(1, 60);
    echo page('convert_index', ['coins' => $coins], [
        'title' => 'Cryptocurrency Converter & Calculator – Crypto to USD, EUR, INR | ' . cfg('site_name'),
        'description' => 'Convert Bitcoin, Ethereum and ' . number_format(coins_count()) . '+ cryptocurrencies to US Dollar, Euro and 20+ fiat currencies with live rates.',
        'canonical' => '/convert/',
    ]);
}

// ============================================================ CATEGORIES
function ctl_categories(): void
{
    $cats = rows('SELECT * FROM categories WHERE market_cap > 0 ORDER BY market_cap DESC');
    echo page('categories', ['cats' => $cats], [
        'title' => 'Top Crypto Categories By Market Capitalization | ' . cfg('site_name'),
        'description' => 'Explore ' . count($cats) . ' cryptocurrency categories — DeFi, Layer 1, Meme, AI, Stablecoins and more — ranked by market cap and 24h volume.',
        'canonical' => '/categories/',
        'ld' => [ld_breadcrumbs(['Categories' => '/categories/'])],
    ]);
}

function ctl_category(string $slug): void
{
    $cat = row('SELECT * FROM categories WHERE slug = ?', [$slug]);
    if (!$cat) { not_found(); return; }
    $coins = rows('SELECT ' . COIN_LIST_COLS . ' FROM coins c JOIN coin_categories cc ON cc.coin_id = c.id WHERE cc.category_id = ? AND c.price IS NOT NULL AND ' . COIN_OK_C . ' ORDER BY c.rank_no IS NULL, c.rank_no LIMIT 300', [$cat['id']]);
    $site = cfg('site_name');
    echo page('category', ['cat' => $cat, 'coins' => $coins], [
        'title' => "Top {$cat['name']} Coins by Market Cap | $site",
        'description' => "Live prices of the top {$cat['name']} tokens ranked by market capitalization. Total {$cat['name']} market cap: " . fmt_big($cat['market_cap']) . ', 24h volume ' . fmt_big($cat['volume_24h']) . '.',
        'canonical' => category_url($cat),
        'robots' => count($coins) ? null : 'noindex,follow',
        'ld' => [ld_breadcrumbs(['Categories' => '/categories/', $cat['name'] => category_url($cat)]), ld_itemlist($coins)],
    ]);
}

// ============================================================ EXCHANGES
function ctl_exchanges(): void
{
    $list = rows("SELECT * FROM exchanges WHERE status = 'active' ORDER BY trust_rank IS NULL, trust_rank LIMIT 250");
    $btc = (float)val("SELECT price FROM coins WHERE cg_id='bitcoin'");
    echo page('exchanges', ['list' => $list, 'btc' => $btc], [
        'title' => 'Top Cryptocurrency Spot Exchanges Ranked by Trust Score | ' . cfg('site_name'),
        'description' => 'The top ' . count($list) . ' crypto exchanges ranked by trust score, trading volume and liquidity — Binance, Coinbase, OKX, Kraken and more.',
        'canonical' => '/exchanges/',
        'ld' => [ld_breadcrumbs(['Exchanges' => '/exchanges/'])],
    ]);
}

function ctl_exchange(string $slug): void
{
    $x = row("SELECT * FROM exchanges WHERE slug = ? AND status = 'active'", [$slug]);
    if (!$x) { not_found(); return; }
    $key = array_search($x['cg_id'], Exchanges::CG_IDS, true);
    $markets = $key ? rows('SELECT m.*, c.slug coin_slug, c.name coin_name, c.image FROM markets m LEFT JOIN coins c ON c.id = (SELECT id FROM coins WHERE symbol = LOWER(m.base) ORDER BY rank_no IS NULL, rank_no LIMIT 1)
        WHERE m.exchange = ? AND m.volume_usd > 0 ORDER BY m.volume_usd DESC LIMIT 100', [$key]) : [];
    $btc = (float)val("SELECT price FROM coins WHERE cg_id='bitcoin'");
    $vol = $x['volume_24h_btc'] * $btc;
    echo page('exchange', ['x' => $x, 'markets' => $markets, 'vol' => $vol], [
        'title' => "{$x['name']} Trade Volume, Trading Pairs and Info | " . cfg('site_name'),
        'description' => "{$x['name']} 24h trading volume is " . fmt_big($vol) . '.' . ($x['year_established'] ? " Established in {$x['year_established']}" . ($x['country'] ? ", based in {$x['country']}." : '.') : '') . " See {$x['name']} markets, trust score and live pairs.",
        'canonical' => exchange_url($x),
        'ld' => [ld_breadcrumbs(['Exchanges' => '/exchanges/', $x['name'] => exchange_url($x)])],
    ]);
}

// ============================================================ LISTS
function ctl_gainers(): void
{
    echo page('gainers', ['up' => movers('up', 30), 'down' => movers('down', 30)], [
        'title' => 'Top Crypto Gainers and Losers Today (24h) | ' . cfg('site_name'),
        'description' => 'Discover the biggest crypto gainers and losers in the last 24 hours. Coins with the largest price increases and decreases, with at least $50K volume.',
        'canonical' => '/gainers-losers/',
    ]);
}

function ctl_trending(): void
{
    $coins = trending_coins(15);
    echo page('list', ['coins' => $coins, 'h1' => 'Trending Cryptocurrencies Today', 'intro' => 'The most searched and fastest-moving coins on ' . cfg('site_name') . ' right now, updated every 15 minutes.'], [
        'title' => 'Trending Cryptocurrencies Today – Most Searched Coins | ' . cfg('site_name'),
        'description' => 'See which cryptocurrencies are trending today: ' . implode(', ', array_map(fn($c) => $c['name'], array_slice($coins, 0, 5))) . ' and more.',
        'canonical' => '/trending/',
    ]);
}

function ctl_new(): void
{
    $coins = recently_added(50);
    echo page('list', ['coins' => $coins, 'h1' => 'New Cryptocurrencies – Recently Added', 'intro' => 'Newly listed cryptocurrencies, most recent first. New tokens can be extremely volatile — always do your own research.', 'show_added' => true], [
        'title' => 'New Cryptocurrencies – Recently Added Coins | ' . cfg('site_name'),
        'description' => 'Discover the newest cryptocurrencies added to ' . cfg('site_name') . ': price, market cap, volume and 7-day charts for recently listed coins.',
        'canonical' => '/new/',
    ]);
}

function ctl_charts(): void
{
    $g = global_stats();
    echo page('charts', ['g' => $g, 'top' => coins_page(1, 10)], [
        'title' => 'Global Cryptocurrency Market Cap Charts | ' . cfg('site_name'),
        'description' => 'Total cryptocurrency market capitalization is ' . fmt_big($g['total_market_cap'] ?? null) . '. Charts of total crypto market cap, 24h volume and Bitcoin dominance.',
        'canonical' => '/charts/', 'charts' => true,
    ]);
}

function ctl_watchlist(): void
{
    echo page('watchlist', [], ['title' => 'My Watchlist | ' . cfg('site_name'), 'description' => 'Your personal cryptocurrency watchlist.', 'canonical' => '/watchlist/', 'robots' => 'noindex,follow']);
}

function ctl_search(): void
{
    $qq = trim((string)($_GET['q'] ?? ''));
    $res = $qq !== '' ? search_coins($qq, 50) : [];
    if (count($res) === 1 || ($res && (strtolower($res[0]['symbol']) === strtolower($qq) || strtolower($res[0]['name']) === strtolower($qq)))) {
        header('Location: ' . coin_url($res[0]), true, 302);
        return;
    }
    echo page('list', ['coins' => $res, 'h1' => $qq ? 'Search results for “' . $qq . '”' : 'Search', 'intro' => $res ? '' : 'No coins matched your search.'], [
        'title' => 'Search | ' . cfg('site_name'), 'description' => 'Search cryptocurrencies.', 'canonical' => '/search/', 'robots' => 'noindex,follow',
    ]);
}

function search_coins(string $qq, int $n = 10): array
{
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $qq) . '%';
    return rows('SELECT ' . COIN_LIST_COLS . ' FROM coins WHERE price IS NOT NULL AND ' . COIN_OK . ' AND (name LIKE ? OR symbol LIKE ?)
                 ORDER BY (symbol = ?) DESC, (name = ?) DESC, rank_no IS NULL, rank_no LIMIT ' . (int)$n, [$like, $like, strtolower($qq), $qq]);
}

function ctl_static(string $p): void
{
    $titles = ['about' => 'About ' . cfg('site_name'), 'methodology' => 'Methodology & Data Sources', 'privacy' => 'Privacy Policy', 'terms' => 'Terms of Use', 'disclaimer' => 'Disclaimer', 'advertising-disclosure' => 'Advertising & Sponsored Content Disclosure'];
    echo page('static', ['p' => $p, 'h1' => $titles[$p]], [
        'title' => $titles[$p] . ' | ' . cfg('site_name'),
        'description' => $p === 'methodology' ? 'How ' . cfg('site_name') . ' calculates prices, market caps and volumes from direct exchange data.' : $titles[$p] . ' for ' . cfg('site_name') . '.',
        'canonical' => "/$p/",
    ]);
}

// ============================================================ API (JSON)
function api_chart(): void
{
    $c = coin_by_slug((string)($_GET['coin'] ?? ''));
    if (!$c) { http_response_code(404); json_out(['error' => 'not found']); return; }
    $range = (string)($_GET['range'] ?? '7d');
    $id = (int)$c['id'];
    $pts = [];
    $hours = ['1d' => 24, '7d' => 168, '1m' => 720][$range] ?? null;
    if ($hours) {
        foreach (rows('SELECT ts, price FROM coin_history_hourly WHERE coin_id = ? AND ts >= ? ORDER BY ts', [$id, time() - $hours * 3600]) as $r) $pts[] = [(int)$r['ts'], (float)$r['price']];
        if (count($pts) < 5 && $hours <= 168 && $c['sparkline_7d']) { // fallback to the 7-day sparkline
            $sp = json_decode($c['sparkline_7d'], true) ?: [];
            $start = time() - count($sp) * 3600;
            foreach ($sp as $i => $v) if ($start + $i * 3600 >= time() - $hours * 3600) $pts[] = [$start + $i * 3600, (float)$v];
        }
    }
    if (!$pts) {
        $days = ['1d' => 2, '7d' => 7, '1m' => 31, '3m' => 92, '1y' => 366, 'ytd' => (int)date('z') + 1][$range] ?? 100000;
        foreach (rows('SELECT UNIX_TIMESTAMP(d) ts, close FROM coin_history_daily WHERE coin_id = ? AND d >= ? AND close IS NOT NULL ORDER BY d', [$id, gmdate('Y-m-d', time() - $days * 86400)]) as $r)
            $pts[] = [(int)$r['ts'], (float)$r['close']];
    }
    if ($pts && $c['price']) $pts[] = [max(time(), end($pts)[0] + 1), (float)$c['price']];
    json_out(['coin' => $c['slug'], 'range' => $range, 'prices' => $pts], $hours ? 60 : 600);
}

function api_global_chart(): void
{
    $pts = [];
    foreach (rows('SELECT UNIX_TIMESTAMP(d) ts, total_market_cap, total_volume, btc_dominance FROM global_history ORDER BY d') as $r)
        $pts[] = [(int)$r['ts'], (float)$r['total_market_cap'], (float)$r['total_volume'], (float)$r['btc_dominance']];
    json_out(['points' => $pts], 600);
}

function api_search(): void
{
    $qq = trim((string)($_GET['q'] ?? ''));
    $out = [];
    if ($qq !== '') foreach (search_coins($qq, 8) as $c) $out[] = ['name' => $c['name'], 'symbol' => strtoupper($c['symbol']), 'rank' => $c['rank_no'], 'url' => coin_url($c), 'image' => $c['image'], 'price' => fmt_price($c['price'])];
    json_out($out, 120);
}

function api_coins(): void
{
    $ids = array_slice(array_filter(array_map('trim', explode(',', (string)($_GET['ids'] ?? '')))), 0, 200);
    if (!$ids) { json_out([]); return; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $list = rows('SELECT ' . COIN_LIST_COLS . " FROM coins WHERE slug IN ($ph) AND " . COIN_OK . " ORDER BY rank_no IS NULL, rank_no", $ids);
    json_out(['html' => render('partials/coin_table', ['coins' => $list, 'offset' => 0])], 30);
}

// ============================================================ ROBOTS / SITEMAPS
function ctl_robots(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    $custom = trim((string)setting('robots_txt', ''));
    if ($custom !== '') { echo $custom, "
"; if (stripos($custom, 'sitemap:') === false) echo "
Sitemap: " . abs_url('/sitemap.xml') . "
"; return; }
    echo "User-agent: *
Allow: /
Disallow: /api/
Disallow: /search/
Disallow: /watchlist/
Disallow: /admin/
Disallow: /go/
Disallow: /account/
Disallow: /login/
Disallow: /register/
Disallow: /*.csv$

Sitemap: " . abs_url('/sitemap.xml') . "
";
}

const SITEMAP_CHUNK = 5000;

function sitemap_urls(string $type, int $n): array
{
    $off = ($n - 1) * SITEMAP_CHUNK; $lim = SITEMAP_CHUNK;
    $u = [];
    switch ($type) {
        case 'pages':
            $now = gmdate('c');
            foreach (['/', '/categories/', '/exchanges/', '/gainers-losers/', '/trending/', '/new/', '/charts/', '/convert/', '/about/', '/methodology/', '/news/'] as $p) $u[] = [$p, $now, 'hourly', '1.0'];
            $pages = (int)ceil(coins_count() / cfg('per_page', 100));
            for ($i = 2; $i <= $pages; $i++) $u[] = ["/?page=$i", $now, 'hourly', '0.6'];
            break;
        case 'coins':
            foreach (rows("SELECT slug, updated_at FROM coins WHERE price IS NOT NULL AND " . COIN_OK . " ORDER BY rank_no IS NULL, rank_no LIMIT $lim OFFSET $off") as $r)
                $u[] = ['/currencies/' . $r['slug'] . '/', gmdate('c', strtotime($r['updated_at'] . ' UTC')), 'hourly', '0.9'];
            break;
        case 'history':
            foreach (rows("SELECT c.slug, c.id, MAX(h.d) last FROM coins c JOIN coin_history_daily h ON h.coin_id = c.id WHERE c.price IS NOT NULL AND " . COIN_OK_C . " GROUP BY c.id ORDER BY c.rank_no IS NULL, c.rank_no LIMIT 2000 OFFSET " . (($n - 1) * 2000)) as $r) {
                $u[] = ['/currencies/' . $r['slug'] . '/historical-data/', $r['last'], 'daily', '0.7'];
                foreach (history_years((int)$r['id']) as $y) $u[] = ['/currencies/' . $r['slug'] . "/historical-data/$y/", $y == date('Y') ? $r['last'] : "$y-12-31", $y == date('Y') ? 'daily' : 'monthly', '0.5'];
            }
            break;
        case 'convert':
            $coins = rows('SELECT symbol FROM coins WHERE price IS NOT NULL AND rank_no IS NOT NULL AND ' . COIN_OK . ' ORDER BY rank_no LIMIT 300');
            $fi = array_intersect(top_fiats(), array_keys(fiats()));
            $all = [];
            $seen = [];
            foreach ($coins as $i => $c) {
                $s = strtolower($c['symbol']);
                if (isset($seen[$s])) continue; $seen[$s] = 1;
                foreach ($fi as $f) $all[] = "/convert/$s/$f/";
                if ($i < 10) foreach (array_slice($fi, 0, 6) as $f) $all[] = "/convert/$f/$s/";
                if ($i < 30) foreach (array_slice($coins, 0, 10) as $o) { $os = strtolower($o['symbol']); if ($os !== $s) $all[] = "/convert/$s/$os/"; }
            }
            $now = gmdate('c');
            foreach (array_slice($all, $off, $lim) as $p) $u[] = [$p, $now, 'daily', '0.6'];
            break;
        case 'categories':
            foreach (rows('SELECT slug, updated_at FROM categories WHERE market_cap > 0 ORDER BY market_cap DESC') as $r) $u[] = ['/categories/' . $r['slug'] . '/', gmdate('c', strtotime($r['updated_at'] . ' UTC')), 'daily', '0.6'];
            break;
        case 'news':
            foreach (rows('SELECT slug, COALESCE(updated_at, publish_at, created_at) m FROM articles WHERE ' . ARTICLE_LIVE . ' ORDER BY m DESC LIMIT ' . $lim . ' OFFSET ' . $off) as $r)
                $u[] = ['/news/' . $r['slug'] . '/', gmdate('c', strtotime($r['m'] . ' UTC')), 'weekly', '0.7'];
            break;
        case 'exchanges':
            foreach (rows("SELECT slug, updated_at FROM exchanges WHERE status = 'active' ORDER BY trust_rank") as $r) $u[] = ['/exchanges/' . $r['slug'] . '/', gmdate('c', strtotime($r['updated_at'] . ' UTC')), 'daily', '0.6'];
            break;
    }
    return $u;
}

function sitemap_counts(): array
{
    $coins = coins_count();
    $conv = min(300, (int)val('SELECT COUNT(*) FROM coins WHERE rank_no IS NOT NULL')) * (count(top_fiats()) + 10) + 60;
    $hist = (int)val('SELECT COUNT(DISTINCT coin_id) FROM coin_history_daily');
    return ['pages' => 1, 'coins' => max(1, (int)ceil($coins / SITEMAP_CHUNK)), 'history' => max(1, (int)ceil($hist / 2000)),
            'convert' => max(1, (int)ceil($conv / SITEMAP_CHUNK)), 'categories' => 1, 'exchanges' => 1,
            'news' => max(1, (int)ceil((int)val('SELECT COUNT(*) FROM articles WHERE ' . ARTICLE_LIVE) / SITEMAP_CHUNK))];
}

function ctl_sitemap_index(): void
{
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach (sitemap_counts() as $t => $n) for ($i = 1; $i <= $n; $i++)
        echo '  <sitemap><loc>' . e(abs_url("/sitemaps/$t-$i.xml")) . '</loc><lastmod>' . gmdate('c') . "</lastmod></sitemap>\n";
    echo '</sitemapindex>';
}

function ctl_sitemap(string $type, string $n): void
{
    $counts = sitemap_counts();
    if (!isset($counts[$type]) || (int)$n < 1 || (int)$n > $counts[$type]) { not_found(); return; }
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach (sitemap_urls($type, (int)$n) as [$p, $mod, $freq, $pri]) {
        $loc = strpos($p, '?') !== false ? base_url() . $p : abs_url($p);
        echo '  <url><loc>' . e($loc) . '</loc>' . ($mod ? '<lastmod>' . e(substr((string)$mod, 0, 25)) . '</lastmod>' : '') . "<changefreq>$freq</changefreq><priority>$pri</priority></url>\n";
    }
    echo '</urlset>';
}
