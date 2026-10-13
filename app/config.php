<?php
// Copy to config.local.php to override any value without touching this file.
$config = [
    'site_name'   => 'CoinMC',
    'site_url'    => 'https://coinmc.org',   // canonical base URL, no trailing slash
    'tagline'     => 'Cryptocurrency Prices, Charts & Market Cap',

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'coinmc',
        'user' => 'root',
        'pass' => '',
    ],

    // Optional free CoinGecko "Demo" key (https://www.coingecko.com/en/api) — raises rate limits.
    'coingecko_key' => '',
    'coingecko_pro' => false,           // true if you have a paid Pro key

    // How many coins to track (CoinGecko /coins/markets, 250 per page)
    'max_coins' => 1000,

    // Exchanges to pull spot tickers from (one request each, no API key needed)
    'exchanges_enabled' => ['binance', 'coinbase', 'kraken', 'okx', 'bybit', 'kucoin'],
    // Binance blocks US IPs on api.binance.com; the public market-data mirror works everywhere.
    'binance_hosts' => ['https://data-api.binance.vision', 'https://api.binance.com', 'https://api.binance.us'],

    'cache_enabled' => true,
    'cache_ttl'     => 60,              // seconds of full-page HTML cache
    'per_page'      => 100,
    'timezone'      => 'UTC',
    'debug'         => false,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) $config = array_replace_recursive($config, $local);
}
return $config;
