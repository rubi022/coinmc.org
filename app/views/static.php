<?php $site = e(cfg('site_name')); ?>
<article class="card prose static">
<h1><?= e($h1) ?></h1>
<?php if ($p === 'about'): ?>
  <p><?= $site ?> is a cryptocurrency market data platform for tracking coin prices, market capitalizations, trading volume and market trends. We list thousands of digital assets with live prices, interactive charts, free historical data and conversion tools.</p>
  <h2>What we offer</h2>
  <ul><li>Live prices calculated from direct exchange market data</li><li>Market cap rankings, categories and exchange directories</li><li>Free daily OHLCV historical data with CSV download</li><li>Crypto-to-fiat and crypto-to-crypto converters</li><li>Gainers &amp; losers, trending and newly listed coins</li></ul>
  <h2>Independence</h2>
  <p>Rankings are determined purely by market data. Listing on <?= $site ?> is not an endorsement. Nothing on this site is investment advice.</p>
<?php elseif ($p === 'methodology'): ?>
  <h2>Prices</h2>
  <p>We pull every spot ticker directly from the public APIs of Binance, Coinbase, Kraken, OKX, Bybit and KuCoin every two minutes. For each asset, the displayed USD price is the volume-weighted average price (VWAP) of its USD and USD-stablecoin markets. Markets more than 5% away from the median price are treated as outliers and excluded. Assets without enough exchange liquidity fall back to aggregated market data.</p>
  <h2>Market capitalization</h2>
  <p>Market cap = price × circulating supply. Fully diluted valuation = price × maximum supply (or total supply where no maximum exists).</p>
  <h2>Volume</h2>
  <p>24-hour volume is the sum of the quote-currency volume of all markets, converted to USD at current rates.</p>
  <h2>Historical data</h2>
  <p>Daily OHLC candles come straight from exchange trade data (Binance from 2017, Coinbase from 2015 where available). Market-cap history combines daily closes with supply data. Days are UTC.</p>
  <h2>Update frequency</h2>
  <p>Exchange prices: every 2 minutes · Rankings and supply: every 5 minutes · Hourly chart data: hourly · Daily history: daily.</p>
<?php elseif ($p === 'privacy'): ?>
  <p><?= $site ?> does not require an account. Your watchlist and theme preference are stored only in your own browser (localStorage) and never sent to our servers. Standard server logs (IP address, user agent, requested URL) are kept for security and are deleted after 30 days. If we add analytics or advertising in the future, this policy will be updated.</p>
<?php elseif ($p === 'terms'): ?>
  <p>All data on <?= $site ?> is provided “as is”, for informational purposes only, without warranty of accuracy or completeness. You may reference and link to our pages and use downloaded historical data for personal and research use with attribution to <?= $site ?>. Automated bulk scraping of the site is not permitted.</p>
<?php else: ?>
  <p>Cryptocurrencies are highly volatile and risky. Content on <?= $site ?> is not financial, investment, tax or legal advice. Prices may be delayed or inaccurate. Always do your own research and consult a qualified professional before making investment decisions.</p>
<?php endif; ?>
</article>
