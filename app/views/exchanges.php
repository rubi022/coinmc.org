<h1>Top Cryptocurrency Spot Exchanges</h1>
<p class="lead">Exchanges ranked by trust score — a combination of liquidity, trading volume, cybersecurity and API coverage.</p>
<div class="tbl-wrap card flush"><table class="tbl">
  <thead><tr><th class="num">#</th><th class="left">Exchange</th><th>Trust Score</th><th>Volume (24h)</th><th class="hide-sm">Established</th><th class="hide-sm left">Country</th></tr></thead>
  <tbody>
  <?php foreach ($list as $i => $x): ?>
    <tr><td class="num muted"><?= e($x['trust_rank'] ?: $i + 1) ?></td>
      <td class="left"><a class="coin-cell" href="<?= e(exchange_url($x)) ?>"><?= coin_img(['image' => $x['image'], 'name' => $x['name'], 'symbol' => $x['name']], 22) ?><span class="cn"><?= e($x['name']) ?></span></a></td>
      <td><span class="score s<?= (int)$x['trust_score'] ?>"><?= e($x['trust_score'] ?? '—') ?>/10</span></td>
      <td><?= $btc ? fmt_usd($x['volume_24h_btc'] * $btc) : fmt_num($x['volume_24h_btc']) . ' BTC' ?></td>
      <td class="hide-sm"><?= e($x['year_established'] ?: '—') ?></td><td class="hide-sm left"><?= e($x['country'] ?: '—') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="6" class="empty">Exchange data loads with the next sync.</td></tr><?php endif; ?>
  </tbody>
</table></div>
