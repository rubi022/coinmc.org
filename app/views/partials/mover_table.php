<div class="tbl-wrap card flush"><table class="tbl">
  <thead><tr><th class="num">#</th><th class="left">Name</th><th>Price</th><th>24h</th><th class="hide-sm">Volume (24h)</th></tr></thead>
  <tbody><?php foreach ($coins as $i => $c): ?>
    <tr><td class="num muted"><?= $i + 1 ?></td><td class="left"><a class="coin-cell" href="<?= e(coin_url($c)) ?>"><?= coin_img($c, 20) ?><span class="cn"><?= e($c['name']) ?></span><span class="cs"><?= e(strtoupper($c['symbol'])) ?></span></a></td>
      <td><?= fmt_price($c['price']) ?></td><td><?= pct($c['change_24h']) ?></td><td class="hide-sm"><?= fmt_usd($c['volume_24h']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$coins): ?><tr><td colspan="5" class="empty">No data yet.</td></tr><?php endif; ?></tbody>
</table></div>
