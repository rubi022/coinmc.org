<?php $s = strtoupper($c['symbol']); $n = $c['name']; $base = coin_url($c) . 'historical-data/'; ?>
<nav class="crumbs" aria-label="Breadcrumb"><a href="/">Cryptocurrencies</a> › <a href="<?= e(coin_url($c)) ?>"><?= e($n) ?></a> › <?php if ($year): ?><a href="<?= e($base) ?>">Historical Data</a> › <span><?= $year ?></span><?php else: ?><span>Historical Data</span><?php endif; ?></nav>

<div class="page-head">
  <?= coin_img($c, 32) ?>
  <h1><?= e($n) ?> (<?= e($s) ?>) <?= $year ? "Price History $year" : 'Historical Data' ?></h1>
</div>

<?php if ($stats): ?>
<p class="lead">
  <?php if ($year): ?>
    In <?= $year ?>, <?= e($n) ?> opened at <strong><?= fmt_price($stats['open']) ?></strong> and <?= $year == date('Y') ? 'is currently trading at' : 'closed the year at' ?> <strong><?= fmt_price($stats['close']) ?></strong>,
    a change of <span class="<?= up_down($stats['change']) ?>"><?= ($stats['change'] >= 0 ? '+' : '−') . pct_raw($stats['change']) ?></span>. The highest price was <?= fmt_price($stats['high']) ?> and the lowest <?= fmt_price($stats['low']) ?>.
  <?php else: ?>
    Daily <?= e($n) ?> price history in USD — open, high, low, close, trading volume and market cap. Over the last <?= count($rows) ?> days <?= e($s) ?> moved
    from <?= fmt_price($stats['open']) ?> to <?= fmt_price($stats['close']) ?> (<span class="<?= up_down($stats['change']) ?>"><?= ($stats['change'] >= 0 ? '+' : '−') . pct_raw($stats['change']) ?></span>), with a high of <?= fmt_price($stats['high']) ?> and a low of <?= fmt_price($stats['low']) ?>.
  <?php endif; ?>
</p>
<?php endif; ?>

<div class="toolbar">
  <div class="chips">
    <a class="chip <?= !$year ? 'on' : '' ?>" href="<?= e($base) ?>">Last 365 days</a>
    <?php foreach ($years as $y): ?><a class="chip <?= $year === $y ? 'on' : '' ?>" href="<?= e($base . $y . '/') ?>"><?= $y ?></a><?php endforeach; ?>
  </div>
  <a class="btn" href="/currencies/<?= e($c['slug']) ?>/historical-data.csv" rel="nofollow" download>⬇ Download CSV</a>
</div>

<div class="tbl-wrap card flush">
<table class="tbl">
  <thead><tr><th class="left">Date</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Change</th><th class="hide-sm">Volume</th><th class="hide-sm">Market Cap</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $o = num($r['open']); $cl = num($r['close']); $ch = ($o && $cl) ? ($cl / $o - 1) * 100 : null; ?>
    <tr><td class="left"><?= fmt_date($r['d']) ?></td><td><?= fmt_price($r['open']) ?></td><td><?= fmt_price($r['high']) ?></td><td><?= fmt_price($r['low']) ?></td>
      <td><strong><?= fmt_price($r['close']) ?></strong></td><td><?= pct($ch) ?></td><td class="hide-sm"><?= fmt_usd($r['volume']) ?></td><td class="hide-sm"><?= fmt_usd($r['market_cap']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="empty">Historical data for <?= e($n) ?> is still being collected.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<section class="card prose">
  <h2>About <?= e($n) ?> historical data</h2>
  <p>This page lists daily <?= e($n) ?> (<?= e($s) ?>) prices in US dollars. “Open” and “Close” are the first and last traded prices of each UTC day, “High” and “Low” the extremes reached during the day.
     Candles are sourced directly from exchange trade data (Binance, Coinbase) where available; market capitalization is price × circulating supply.
     You can download the complete dataset as CSV for research, back-testing or tax reporting.</p>
  <p>See also: <a href="<?= e(coin_url($c)) ?>"><?= e($n) ?> live price</a> · <a href="<?= e(convert_url($c['symbol'], 'usd')) ?>"><?= e($s) ?> to USD converter</a></p>
</section>
