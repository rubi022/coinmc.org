<?php

/**
 * Direct exchange market data (public endpoints, no API keys).
 * Each tickers() call is ONE request returning every spot pair on that exchange.
 * Each adapter returns a list of:
 *   ['pair','base','quote','price','volume_base','volume_quote','change_24h','trade_url']
 */
final class Exchanges
{
    const QUOTES = ['FDUSD', 'USDT', 'USDC', 'TUSD', 'BUSD', 'USDE', 'DAI', 'PYUSD', 'USD', 'EUR', 'GBP', 'TRY', 'BRL', 'JPY',
                    'AUD', 'CAD', 'CHF', 'BTC', 'ETH', 'BNB', 'SOL', 'XRP', 'DOGE', 'TRX', 'IDR', 'ZAR', 'UAH', 'PLN', 'ARS', 'MXN', 'KRW', 'INR'];

    const NAMES = ['binance' => 'Binance', 'coinbase' => 'Coinbase', 'kraken' => 'Kraken', 'okx' => 'OKX', 'bybit' => 'Bybit', 'kucoin' => 'KuCoin'];

    /** Map an exchange key to the CoinGecko exchange id (for logos/links in our exchanges table). */
    const CG_IDS = ['binance' => 'binance', 'coinbase' => 'gdax', 'kraken' => 'kraken', 'okx' => 'okex', 'bybit' => 'bybit_spot', 'kucoin' => 'kucoin'];

    public static function tickers(string $ex): array
    {
        $m = 'fetch' . ucfirst($ex);
        if (!method_exists(self::class, $m)) return [];
        try { return self::$m(); } catch (Throwable $e) { log_msg('exchanges', "$ex: " . $e->getMessage()); return []; }
    }

    public static function splitSymbol(string $sym): ?array
    {
        foreach (self::QUOTES as $q) {
            if (strlen($sym) > strlen($q) && substr($sym, -strlen($q)) === $q) return [substr($sym, 0, -strlen($q)), $q];
        }
        return null;
    }

    public static function binanceGet(string $path, array $params = [])
    {
        foreach (cfg('binance_hosts') as $h) {
            $r = http_json($h . $path . ($params ? '?' . http_build_query($params) : ''), [], 1);
            if (is_array($r) && !isset($r['code'])) return $r;
        }
        return null;
    }

    private static function fetchBinance(): array
    {
        $out = [];
        foreach (self::binanceGet('/api/v3/ticker/24hr') ?: [] as $t) {
            if (!($s = self::splitSymbol($t['symbol'])) || (float)$t['lastPrice'] <= 0) continue;
            [$b, $q] = $s;
            $out[] = ['pair' => "$b/$q", 'base' => $b, 'quote' => $q, 'price' => (float)$t['lastPrice'],
                'volume_base' => (float)$t['volume'], 'volume_quote' => (float)$t['quoteVolume'],
                'change_24h' => (float)$t['priceChangePercent'], 'trade_url' => "https://www.binance.com/en/trade/{$b}_{$q}?type=spot"];
        }
        return $out;
    }

    private static function fetchCoinbase(): array
    {
        $out = [];
        $r = http_json('https://api.coinbase.com/api/v3/brokerage/market/products?product_type=SPOT');
        foreach ($r['products'] ?? [] as $p) {
            if (!empty($p['trading_disabled']) || (float)($p['price'] ?? 0) <= 0) continue;
            $b = strtoupper($p['base_currency_id'] ?? ''); $q = strtoupper($p['quote_currency_id'] ?? '');
            if (!$b || !$q) continue;
            $vol = (float)($p['volume_24h'] ?? 0);
            $out[] = ['pair' => "$b/$q", 'base' => $b, 'quote' => $q, 'price' => (float)$p['price'],
                'volume_base' => $vol, 'volume_quote' => $vol * (float)$p['price'],
                'change_24h' => isset($p['price_percentage_change_24h']) ? (float)$p['price_percentage_change_24h'] : null,
                'trade_url' => "https://www.coinbase.com/advanced-trade/spot/{$b}-{$q}"];
        }
        return $out;
    }

    private static function fetchKraken(): array
    {
        $pairs = http_json('https://api.kraken.com/0/public/AssetPairs');
        $tick = http_json('https://api.kraken.com/0/public/Ticker');
        if (empty($pairs['result']) || empty($tick['result'])) return [];
        $fix = ['XBT' => 'BTC', 'XDG' => 'DOGE'];
        $out = [];
        foreach ($tick['result'] as $key => $t) {
            $ws = $pairs['result'][$key]['wsname'] ?? null;
            if (!$ws || strpos($ws, '/') === false) continue;
            [$b, $q] = explode('/', $ws);
            $b = $fix[$b] ?? $b; $q = $fix[$q] ?? $q;
            $last = (float)$t['c'][0]; $open = (float)($t['o'] ?? 0);
            if ($last <= 0) continue;
            $vol = (float)$t['v'][1];
            $out[] = ['pair' => "$b/$q", 'base' => $b, 'quote' => $q, 'price' => $last, 'volume_base' => $vol,
                'volume_quote' => $vol * $last, 'change_24h' => $open > 0 ? ($last - $open) / $open * 100 : null,
                'trade_url' => 'https://pro.kraken.com/app/trade/' . strtolower("$b-$q")];
        }
        return $out;
    }

    private static function fetchOkx(): array
    {
        $r = http_json('https://www.okx.com/api/v5/market/tickers?instType=SPOT');
        $out = [];
        foreach ($r['data'] ?? [] as $t) {
            $parts = explode('-', $t['instId']);
            if (count($parts) !== 2 || (float)$t['last'] <= 0) continue;
            [$b, $q] = $parts;
            $open = (float)$t['open24h']; $last = (float)$t['last'];
            $out[] = ['pair' => "$b/$q", 'base' => $b, 'quote' => $q, 'price' => $last, 'volume_base' => (float)$t['vol24h'],
                'volume_quote' => (float)$t['volCcy24h'], 'change_24h' => $open > 0 ? ($last - $open) / $open * 100 : null,
                'trade_url' => 'https://www.okx.com/trade-spot/' . strtolower("$b-$q")];
        }
        return $out;
    }

    private static function fetchBybit(): array
    {
        $r = http_json('https://api.bybit.com/v5/market/tickers?category=spot');
        $out = [];
        foreach ($r['result']['list'] ?? [] as $t) {
            if (!($s = self::splitSymbol($t['symbol'])) || (float)$t['lastPrice'] <= 0) continue;
            [$b, $q] = $s;
            $out[] = ['pair' => "$b/$q", 'base' => $b, 'quote' => $q, 'price' => (float)$t['lastPrice'],
                'volume_base' => (float)$t['volume24h'], 'volume_quote' => (float)$t['turnover24h'],
                'change_24h' => (float)$t['price24hPcnt'] * 100, 'trade_url' => "https://www.bybit.com/en/trade/spot/$b/$q"];
        }
        return $out;
    }

    private static function fetchKucoin(): array
    {
        $r = http_json('https://api.kucoin.com/api/v1/market/allTickers');
        $out = [];
        foreach ($r['data']['ticker'] ?? [] as $t) {
            $parts = explode('-', $t['symbol']);
            if (count($parts) !== 2 || (float)($t['last'] ?? 0) <= 0) continue;
            [$b, $q] = $parts;
            $out[] = ['pair' => "$b/$q", 'base' => $b, 'quote' => $q, 'price' => (float)$t['last'],
                'volume_base' => (float)$t['vol'], 'volume_quote' => (float)$t['volValue'],
                'change_24h' => (float)$t['changeRate'] * 100, 'trade_url' => "https://www.kucoin.com/trade/$b-$q"];
        }
        return $out;
    }

    // ---------- Historical candles ----------

    /** Binance daily/hourly klines. Returns [[ts_seconds, o, h, l, c, volume_usd], ...] ascending. */
    public static function binanceKlines(string $symbol, string $interval, ?int $startMs = null, int $limit = 1000): array
    {
        $p = ['symbol' => $symbol, 'interval' => $interval, 'limit' => $limit];
        if ($startMs) $p['startTime'] = $startMs;
        $r = self::binanceGet('/api/v3/klines', $p);
        $out = [];
        foreach ($r ?: [] as $k) $out[] = [intdiv((int)$k[0], 1000), (float)$k[1], (float)$k[2], (float)$k[3], (float)$k[4], (float)$k[7]];
        return $out;
    }

    /** Full daily history from Binance, paging forward 1000 days at a time. */
    public static function binanceDailyAll(string $symbol, ?int $sinceTs = null): array
    {
        $all = [];
        $start = ($sinceTs ?: strtotime('2017-07-01')) * 1000;
        for ($i = 0; $i < 10; $i++) {
            $chunk = self::binanceKlines($symbol, '1d', $start, 1000);
            if (!$chunk) break;
            $all = array_merge($all, $chunk);
            if (count($chunk) < 1000) break;
            $start = (end($chunk)[0] + 86400) * 1000;
            usleep(250000);
        }
        return $all;
    }

    /** Coinbase Exchange daily candles (USD pairs, history back to 2015 for BTC). Same row format as Binance. */
    public static function coinbaseDailyAll(string $product, int $sinceTs): array
    {
        $all = [];
        $end = time();
        for ($i = 0; $i < 15 && $end > $sinceTs; $i++) {
            $start = max($sinceTs, $end - 299 * 86400);
            $url = "https://api.exchange.coinbase.com/products/$product/candles?granularity=86400&start=" . gmdate('c', $start) . '&end=' . gmdate('c', $end);
            $r = http_json($url, [], 1);
            if (!is_array($r) || !$r || isset($r['message'])) break;
            foreach ($r as $c) $all[$c[0]] = [(int)$c[0], (float)$c[3], (float)$c[2], (float)$c[1], (float)$c[4], (float)$c[5] * (float)$c[4]];
            $end = $start - 86400;
            usleep(400000);
        }
        ksort($all);
        return array_values($all);
    }
}
