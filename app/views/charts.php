<h1>Global Cryptocurrency Market Charts</h1>
<p class="lead">Total crypto market capitalization is <strong><?= fmt_usd($g['total_market_cap'] ?? null) ?></strong> with <?= fmt_usd($g['total_volume'] ?? null) ?> traded over 24 hours. Bitcoin dominance: <?= pct_raw($g['btc_dominance'] ?? null, 1) ?>.</p>
<section class="card chart-card">
  <div class="chart-head"><h2>Total Cryptocurrency Market Cap</h2></div>
  <div id="gchart" class="chart" data-series="1"></div>
</section>
<section class="card chart-card">
  <div class="chart-head"><h2>Bitcoin Dominance</h2></div>
  <div id="dchart" class="chart sm" data-series="3"></div>
</section>
<section class="card"><h2>Largest cryptocurrencies by market share</h2>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th class="left">Coin</th><th>Market Cap</th><th>Share</th></tr></thead><tbody>
  <?php $tot = (float)($g['total_market_cap'] ?? 0) ?: 1; foreach ($top as $c): ?>
    <tr><td class="left"><a class="coin-cell" href="<?= e(coin_url($c)) ?>"><?= coin_img($c, 20) ?><span class="cn"><?= e($c['name']) ?></span></a></td><td><?= fmt_usd($c['market_cap']) ?></td><td><?= number_format($c['market_cap'] / $tot * 100, 2) ?>%</td></tr>
  <?php endforeach; ?></tbody></table></div>
</section>
