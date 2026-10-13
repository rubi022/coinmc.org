<h1>Top Cryptocurrency Categories by Market Cap</h1>
<p class="lead">Crypto sectors ranked by total market capitalization. Click a category to see every coin in it.</p>
<div class="tbl-wrap card flush"><table class="tbl">
  <thead><tr><th class="num">#</th><th class="left">Category</th><th class="hide-sm">Top Coins</th><th>24h</th><th>Market Cap</th><th class="hide-sm">Volume (24h)</th></tr></thead>
  <tbody>
  <?php foreach ($cats as $i => $c): $imgs = json_decode((string)$c['top_images'], true) ?: []; ?>
    <tr><td class="num muted"><?= $i + 1 ?></td><td class="left"><a href="<?= e(category_url($c)) ?>"><strong><?= e($c['name']) ?></strong></a></td>
      <td class="hide-sm imgs"><?php foreach (array_slice($imgs, 0, 3) as $src): ?><img src="<?= e($src) ?>" width="20" height="20" alt="" loading="lazy"><?php endforeach; ?></td>
      <td><?= pct($c['change_24h']) ?></td><td><?= fmt_usd($c['market_cap']) ?></td><td class="hide-sm"><?= fmt_usd($c['volume_24h']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$cats): ?><tr><td colspan="6" class="empty">Categories load with the next data sync.</td></tr><?php endif; ?>
  </tbody>
</table></div>
