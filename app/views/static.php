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
  <p>This policy explains what <?= $site ?> collects and why.</p>
  <h2>Browsing</h2>
  <p>We run privacy-friendly first-party analytics: page views, referrer, approximate country (from our CDN), device type and a pseudonymous visitor identifier derived from a salted hash of your IP address and browser. No analytics cookie is set, raw IPs are not stored with page views, and detailed records are deleted after a limited retention period. Standard server logs are kept briefly for security.</p>
  <h2>Accounts</h2>
  <p>If you register we store your email address, an optional name, a salted password hash, your watchlist, newsletter preference and sign-in history (time, IP, result) for security. You can download all of it or delete your account from the Account page; deletion removes your data after a 7-day grace period. Without an account your watchlist stays in your own browser.</p>
  <h2>Cookies</h2>
  <p>We set one strictly-necessary session cookie when you log in or register. If Google Analytics is enabled you will be asked for consent first and nothing is loaded until you accept.</p>
  <h2>Advertising &amp; affiliate links</h2>
  <p>Sponsored placements are labeled and are chosen by page, device and country, not by profiling you. Clicking a sponsored or affiliate link sends you to a third party whose own policy applies. See our <a href="/advertising-disclosure/">advertising disclosure</a>.</p>
  <h2>Contact</h2>
  <p>For privacy requests use the contact address on the About page.</p>
<?php elseif ($p === 'advertising-disclosure'): ?>
  <p><?= $site ?> earns revenue from advertising, sponsored placements and affiliate links. This page explains how that works so you can judge it for yourself.</p>
  <h2>Sponsored placements</h2>
  <p>Anything marked <strong>Sponsored</strong> or <strong>Sponsored listing</strong> is a paid placement. Sponsored items are reviewed before they run, can never be styled to look like organic rankings, and do not change a coin’s rank, price or any market data.</p>
  <h2>Sponsored articles</h2>
  <p>Articles paid for by a project are labeled as sponsored at the top of the page and carry the sponsor’s name.</p>
  <h2>Affiliate links</h2>
  <p>Some links to exchanges and services are affiliate links (they pass through a <code>/go/</code> address). If you sign up we may receive a commission at no extra cost to you. Affiliate relationships do not influence our rankings or data.</p>
  <h2>Not advice</h2>
  <p>Sponsored content is not investment advice or an endorsement. Cryptoassets are volatile and you can lose all of your money.</p>
  <h2>Report an ad</h2>
  <p>If a sponsored placement looks misleading or harmful, tell us via the contact address on the About page and we will review it promptly.</p>
<?php elseif ($p === 'terms'): ?>
  <p>All data on <?= $site ?> is provided “as is”, for informational purposes only, without warranty of accuracy or completeness. You may reference and link to our pages and use downloaded historical data for personal and research use with attribution to <?= $site ?>. Automated bulk scraping of the site is not permitted.</p>
<?php else: ?>
  <p>Cryptocurrencies are highly volatile and risky. Content on <?= $site ?> is not financial, investment, tax or legal advice. Prices may be delayed or inaccurate. Always do your own research and consult a qualified professional before making investment decisions.</p>
<?php endif; ?>
</article>
