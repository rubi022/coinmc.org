<?php

/** JSON-LD helpers. All output is rendered in <head> by the layout. */

function ld_breadcrumbs(array $items): array
{
    $list = [];
    $i = 1;
    foreach ($items as $name => $path) {
        $list[] = ['@type' => 'ListItem', 'position' => $i++, 'name' => $name, 'item' => abs_url($path)];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

function ld_faq(array $qa): array
{
    return [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn($q, $a) => [
            '@type' => 'Question', 'name' => $q,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags($a)],
        ], array_keys($qa), $qa),
    ];
}

function ld_site(): array
{
    return [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Organization', '@id' => abs_url('/') . '#org',
                'name' => cfg('site_name'), 'url' => abs_url('/'),
                'logo' => abs_url('/assets/img/logo.svg'),
            ],
            [
                '@type' => 'WebSite', '@id' => abs_url('/') . '#website',
                'url' => abs_url('/'), 'name' => cfg('site_name'),
                'publisher' => ['@id' => abs_url('/') . '#org'],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => abs_url('/search/') . '?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ],
    ];
}

function ld_itemlist(array $coins, int $offset = 0): array
{
    $items = [];
    foreach (array_slice($coins, 0, 50) as $i => $c) {
        $items[] = ['@type' => 'ListItem', 'position' => $offset + $i + 1, 'url' => abs_url(coin_url($c)), 'name' => $c['name']];
    }
    return ['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $items];
}

/** Coin page: a digital-currency "Product"-like entity is not supported for rich results; use
 *  ExchangeRateSpecification + WebPage with about + Dataset for history (eligible for Dataset search). */
function ld_coin(array $c): array
{
    return [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => $c['name'] . ' (' . strtoupper($c['symbol']) . ') Price',
        'url' => abs_url(coin_url($c)),
        'dateModified' => gmdate('c', strtotime(($c['updated_at'] ?? 'now') . ' UTC')),
        'about' => [
            '@type' => 'ExchangeRateSpecification',
            'currency' => strtoupper($c['symbol']),
            'currentExchangeRate' => [
                '@type' => 'UnitPriceSpecification',
                'price' => (float)$c['price'],
                'priceCurrency' => 'USD',
            ],
        ],
    ];
}

function ld_dataset(array $c, string $from, string $to, int $count): array
{
    return [
        '@context' => 'https://schema.org',
        '@type' => 'Dataset',
        'name' => $c['name'] . ' (' . strtoupper($c['symbol']) . ') historical price data',
        'description' => 'Daily open, high, low, close (OHLC), trading volume and market capitalization for ' . $c['name'] . ' in USD from ' . $from . ' to ' . $to . '. ' . $count . ' daily records.',
        'url' => abs_url(coin_url($c) . 'historical-data/'),
        'keywords' => [$c['name'] . ' price history', strtoupper($c['symbol']) . ' historical data', 'cryptocurrency OHLC'],
        'temporalCoverage' => $from . '/' . $to,
        'creator' => ['@type' => 'Organization', 'name' => cfg('site_name'), 'url' => abs_url('/')],
        'isAccessibleForFree' => true,
        'license' => abs_url('/terms/'),
        'distribution' => [[
            '@type' => 'DataDownload', 'encodingFormat' => 'text/csv',
            'contentUrl' => abs_url('/currencies/' . $c['slug'] . '/historical-data.csv'),
        ]],
    ];
}
