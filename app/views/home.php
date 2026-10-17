<?php
$mc = $g['total_market_cap'] ?? null; $chg = $g['mcap_change_24h'] ?? null;
$mini = function (string $title, string $href, array $list, string $field = 'change_24h') { ?>
  <div class="card mini">
    <div class="mini-h"><h2><?= e($title) ?></h2><a href="<?= e($href) ?>">More ›</a></div>
    <ol>
    <?php foreach ($list as $i => $c): ?>
      <li><span class="muted"><?= $i + 1 ?></span><a class="coin-cell" href="<?= e(coin_url($c)) ?>"><?= coin_img($c, 20) ?><span class="cn"><?= e($c['name']) ?></span><span class="cs"><?= e(strtoupper($c['symbol'])) ?></span></a>
        <span class="r"><?= fmt_price($c['price']) ?> <?= pct($c[$field]) ?></span></li>
    <?php endforeach; ?>
    </ol>
  </div>
<?php }; ?>
<?= ad_slot('home_top') ?>
<section class="intro">
  <h1><?= $page > 1 ? 'Cryptocurrency Prices by Market Cap – Page ' . $page : 'Today’s Cryptocurrency Prices by Market Cap' ?></h1>
  <p class="lead">The global crypto market cap is <strong><?= fmt_big($mc) ?></strong><?php if ($chg !== null): ?>, a <span class="<?= up_down($chg) ?>"><?= pct_raw($chg) ?> <?= $chg >= 0 ? 'increase' : 'decrease' ?></span> over the last day<?php endif; ?>.
    <a href="#about-market" class="muted">Read more</a></p>
</section>

<?php if ($page === 1): ?>
<section class="highlights">
  <div class="card mini stats-card">
    <div class="stat"><div class="muted small">Market Cap</div><div class="big"><?= fmt_usd($mc) ?></div><?= $chg !== null ? pct($chg) : '' ?></div>
    <div class="stat"><div class="muted small">24h Trading Volume</div><div class="big"><?= fmt_usd($g['total_volume'] ?? null) ?></div></div>
    <div class="stat"><div class="muted small">Bitcoin Dominance</div><div class="big"><?= pct_raw($g['btc_dominance'] ?? null, 1) ?></div></div>
  </div>
  <?php $mini('🔥 Trending', '/trending/', $trending); ?>
  <?php $mini('🚀 Top Gainers', '/gainers-losers/', $gainers); ?>
</section>
<?php endif; ?>

<?= render('partials/coin_table', ['coins' => $coins, 'offset' => ($page - 1) * $per]) ?>
<?= pager($page, $pages, '/') ?>

<?php if ($page === 1): $btc = $coins[0] ?? null; ?>
<section class="seo-text card" id="about-market">
  <h2>Cryptocurrency Market Today</h2>
  <p>The total value of all cryptocurrencies tracked by <?= e(cfg('site_name')) ?> is <?= fmt_usd($mc) ?>, with <?= fmt_usd($g['total_volume'] ?? null) ?> traded in the past 24 hours.
     Bitcoin accounts for <?= pct_raw($g['btc_dominance'] ?? null, 1) ?> of the market and Ethereum for <?= pct_raw($g['eth_dominance'] ?? null, 1) ?>.
     We list <?= number_format($total) ?> coins and tokens, ranked by market capitalization, with prices taken directly from exchanges such as Binance, Coinbase, Kraken, OKX, Bybit and KuCoin.</p>
  <h3>How are cryptocurrency prices calculated?</h3>
  <p>Each price is a volume-weighted average of live trades across USD and stablecoin markets on the exchanges we monitor. Outlier markets that deviate more than 5% from the median are excluded. Market cap is the price multiplied by circulating supply. See our <a href="/methodology/">methodology</a>.</p>
  <h3>What is market capitalization?</h3>
  <p>Market capitalization is the total value of a cryptocurrency’s circulating supply. It is the most common way to compare the relative size of coins — Bitcoin, for example, has a market cap of <?= $btc ? fmt_big($btc['market_cap']) : '—' ?>.</p>
  <h3>Where can I find historical crypto prices?</h3>
  <p>Every coin page links to free daily historical data (open, high, low, close, volume and market cap), downloadable as CSV — for example <a href="/currencies/bitcoin/historical-data/">Bitcoin historical data</a> and <a href="/currencies/ethereum/historical-data/">Ethereum historical data</a>.</p>
</section>
<?php endif; ?>
