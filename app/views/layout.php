<?php
$g = global_stats();
$site = cfg('site_name');
$canon = array_key_exists('canonical', $seo) ? $seo['canonical'] : null;
$canonAbs = $canon ? (strpos($canon, '?') !== false ? base_url() . $canon : abs_url($canon)) : null;
$ogImage = $seo['og_image'] ?? abs_url('/assets/img/og.png');
$footerCoins = rows('SELECT slug, name, symbol FROM coins WHERE rank_no IS NOT NULL ORDER BY rank_no LIMIT 12');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($seo['title']) ?></title>
<meta name="description" content="<?= e(mb_substr($seo['description'] ?? '', 0, 300)) ?>">
<?php if (!empty($seo['robots'])): ?><meta name="robots" content="<?= e($seo['robots']) ?>">
<?php else: ?><meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1">
<?php endif; ?>
<?php if ($canonAbs): ?><link rel="canonical" href="<?= e($canonAbs) ?>">
<link rel="alternate" hreflang="en" href="<?= e($canonAbs) ?>">
<link rel="alternate" hreflang="x-default" href="<?= e($canonAbs) ?>">
<?php endif; ?>
<?php if (!empty($seo['prev'])): ?><link rel="prev" href="<?= e(base_url() . $seo['prev']) ?>"><?php endif; ?>
<?php if (!empty($seo['next'])): ?><link rel="next" href="<?= e(base_url() . $seo['next']) ?>"><?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($site) ?>">
<meta property="og:title" content="<?= e($seo['title']) ?>">
<meta property="og:description" content="<?= e(mb_substr($seo['description'] ?? '', 0, 200)) ?>">
<?php if ($canonAbs): ?><meta property="og:url" content="<?= e($canonAbs) ?>"><?php endif; ?>
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($seo['title']) ?>">
<meta name="twitter:description" content="<?= e(mb_substr($seo['description'] ?? '', 0, 200)) ?>">
<meta name="theme-color" content="#3861fb">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://coin-images.coingecko.com" crossorigin>
<link rel="stylesheet" href="/assets/css/app.css?v=3">
<script>try{var t=localStorage.getItem('theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
<?php foreach ($seo['ld'] ?? [] as $ld): ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
</head>
<body>
<div class="topbar">
  <div class="wrap topbar-in">
    <span>Cryptos: <a href="/"><?= fmt_num($g['active_coins'] ?? null) ?></a></span>
    <span>Exchanges: <a href="/exchanges/"><?= fmt_num($g['exchanges'] ?? null) ?></a></span>
    <span>Market Cap: <a href="/charts/"><?= fmt_usd($g['total_market_cap'] ?? null) ?></a> <?= isset($g['mcap_change_24h']) ? pct($g['mcap_change_24h']) : '' ?></span>
    <span>24h Vol: <a href="/charts/"><?= fmt_usd($g['total_volume'] ?? null) ?></a></span>
    <span>Dominance: <a href="/charts/">BTC <?= pct_raw($g['btc_dominance'] ?? null, 1) ?> ETH <?= pct_raw($g['eth_dominance'] ?? null, 1) ?></a></span>
  </div>
</div>
<header class="hdr">
  <div class="wrap hdr-in">
    <a class="logo" href="/" aria-label="<?= e($site) ?> home"><img src="/assets/img/logo.svg" width="28" height="28" alt=""><span><?= e($site) ?></span></a>
    <button class="nav-toggle" aria-label="Menu" aria-expanded="false">☰</button>
    <nav class="nav" aria-label="Main">
      <a href="/">Cryptocurrencies</a>
      <a href="/categories/">Categories</a>
      <a href="/exchanges/">Exchanges</a>
      <a href="/gainers-losers/">Gainers &amp; Losers</a>
      <a href="/trending/">Trending</a>
      <a href="/new/">New</a>
      <a href="/convert/">Converter</a>
      <a href="/charts/">Charts</a>
    </nav>
    <div class="hdr-right">
      <form class="search" action="/search/" method="get" role="search">
        <input type="search" name="q" placeholder="Search coin…" autocomplete="off" aria-label="Search cryptocurrencies">
        <div class="search-pop" hidden></div>
      </form>
      <a class="icon-btn" href="/watchlist/" title="Watchlist" aria-label="Watchlist">★</a>
      <button class="icon-btn theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode">◐</button>
    </div>
  </div>
</header>
<main class="wrap main">
<?= $content ?>
</main>
<footer class="ftr">
  <div class="wrap ftr-grid">
    <div>
      <a class="logo" href="/"><img src="/assets/img/logo.svg" width="24" height="24" alt=""><span><?= e($site) ?></span></a>
      <p class="muted small"><?= e($site) ?> tracks live cryptocurrency prices, market capitalization, trading volume and historical data, sourced directly from major exchanges.</p>
    </div>
    <div><h4>Top Cryptocurrencies</h4><ul>
      <?php foreach (array_slice($footerCoins, 0, 6) as $fc): ?><li><a href="/currencies/<?= e($fc['slug']) ?>/"><?= e($fc['name']) ?> price</a></li><?php endforeach; ?>
    </ul></div>
    <div><h4>Popular Conversions</h4><ul>
      <?php foreach (array_slice($footerCoins, 0, 6) as $fc): ?><li><a href="<?= e(convert_url($fc['symbol'], 'usd')) ?>"><?= e(strtoupper($fc['symbol'])) ?> to USD</a></li><?php endforeach; ?>
    </ul></div>
    <div><h4>Historical Data</h4><ul>
      <?php foreach (array_slice($footerCoins, 0, 6) as $fc): ?><li><a href="/currencies/<?= e($fc['slug']) ?>/historical-data/"><?= e($fc['name']) ?> history</a></li><?php endforeach; ?>
    </ul></div>
    <div><h4><?= e($site) ?></h4><ul>
      <li><a href="/about/">About</a></li><li><a href="/methodology/">Methodology</a></li><li><a href="/categories/">Categories</a></li>
      <li><a href="/terms/">Terms</a></li><li><a href="/privacy/">Privacy</a></li><li><a href="/disclaimer/">Disclaimer</a></li>
    </ul></div>
  </div>
  <div class="wrap small muted ftr-bottom">© <?= date('Y') ?> <?= e($site) ?>. Data is provided for information only and is not investment advice.</div>
</footer>
<?php if (!empty($seo['charts'])): ?><script src="/assets/js/vendor/lightweight-charts.standalone.production.js" defer></script><?php endif; ?>
<script src="/assets/js/app.js?v=3" defer></script>
</body>
</html>
