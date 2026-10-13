<?php $offset = $offset ?? 0; $showAdded = $showAdded ?? false; ?>
<div class="tbl-wrap">
<table class="tbl coins">
  <thead><tr>
    <th class="star-col" aria-label="Watchlist"></th><th class="num">#</th><th class="left">Name</th><th>Price</th><th>1h %</th><th>24h %</th><th>7d %</th>
    <th class="hide-sm">Market Cap</th><th class="hide-sm">Volume (24h)</th><th class="hide-md"><?= $showAdded ? 'Added' : 'Circulating Supply' ?></th><th class="hide-md">Last 7 Days</th>
  </tr></thead>
  <tbody>
  <?php foreach ($coins as $i => $c): $u = coin_url($c); ?>
    <tr>
      <td class="star-col"><button class="star" data-coin="<?= e($c['slug']) ?>" aria-label="Add <?= e($c['name']) ?> to watchlist">☆</button></td>
      <td class="num muted"><?= e($c['rank_no'] ?: '—') ?></td>
      <td class="left"><a class="coin-cell" href="<?= e($u) ?>"><?= coin_img($c, 24) ?><span class="cn"><?= e($c['name']) ?></span><span class="cs"><?= e(strtoupper($c['symbol'])) ?></span></a></td>
      <td><a href="<?= e($u) ?>" class="price"><?= fmt_price($c['price']) ?></a></td>
      <td><?= pct($c['change_1h']) ?></td>
      <td><?= pct($c['change_24h']) ?></td>
      <td><?= pct($c['change_7d']) ?></td>
      <td class="hide-sm"><?= fmt_usd($c['market_cap']) ?></td>
      <td class="hide-sm"><?= fmt_usd($c['volume_24h']) ?></td>
      <td class="hide-md"><?= $showAdded ? e(time_ago($c['created_at'])) : fmt_supply($c['circulating_supply'], $c['symbol']) ?></td>
      <td class="hide-md"><a href="<?= e($u) ?>" aria-label="<?= e($c['name']) ?> 7 day chart"><?= sparkline_svg($c['sparkline_7d'], num($c['change_7d'])) ?></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$coins): ?><tr><td colspan="11" class="empty">No data yet — run <code>php cron/run.php all</code> to load market data.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
