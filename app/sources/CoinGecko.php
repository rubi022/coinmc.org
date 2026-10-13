<?php

/**
 * CoinGecko public API — used for the coin universe (ids, names, logos, supply, market caps),
 * metadata, categories, exchange directory, global stats, trending and 365 days of history.
 * Works without a key; a free Demo key raises limits.
 */
final class CoinGecko
{
    private static float $last = 0;

    private static function base(): string
    {
        return cfg('coingecko_pro') ? 'https://pro-api.coingecko.com/api/v3' : 'https://api.coingecko.com/api/v3';
    }

    public static function get(string $path, array $params = [])
    {
        // Stay under the free-tier rate limit (~10-30 req/min)
        $gap = cfg('coingecko_key') ? 2.2 : 6.5;
        $wait = self::$last + $gap - microtime(true);
        if ($wait > 0) usleep((int)($wait * 1e6));
        self::$last = microtime(true);

        $headers = [];
        if ($k = cfg('coingecko_key')) $headers[] = (cfg('coingecko_pro') ? 'x-cg-pro-api-key: ' : 'x-cg-demo-api-key: ') . $k;
        $url = self::base() . $path . ($params ? '?' . http_build_query($params) : '');
        return http_json($url, $headers);
    }

    public static function markets(int $page, int $per = 250): ?array
    {
        return self::get('/coins/markets', [
            'vs_currency' => 'usd', 'order' => 'market_cap_desc', 'per_page' => $per, 'page' => $page,
            'sparkline' => 'true', 'price_change_percentage' => '1h,24h,7d,30d,1y',
        ]);
    }

    public static function coin(string $id): ?array
    {
        return self::get('/coins/' . rawurlencode($id), [
            'localization' => 'false', 'tickers' => 'false', 'market_data' => 'false',
            'community_data' => 'false', 'developer_data' => 'false', 'sparkline' => 'false',
        ]);
    }

    /** Daily prices/market caps/volumes. Free tier allows up to 365 days. */
    public static function marketChart(string $id, $days = 365): ?array
    {
        $p = ['vs_currency' => 'usd', 'days' => $days];
        if ($days === 'max' || (int)$days > 90) $p['interval'] = 'daily';
        return self::get('/coins/' . rawurlencode($id) . '/market_chart', $p);
    }

    public static function categories(): ?array { return self::get('/coins/categories', ['order' => 'market_cap_desc']); }
    public static function exchanges(int $page = 1): ?array { return self::get('/exchanges', ['per_page' => 250, 'page' => $page]); }
    public static function global(): ?array { $r = self::get('/global'); return $r['data'] ?? null; }
    public static function trending(): ?array { return self::get('/search/trending'); }
    public static function exchangeRates(): ?array { $r = self::get('/exchange_rates'); return $r['rates'] ?? null; }
}
