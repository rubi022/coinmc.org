<nav class="crumbs" aria-label="Breadcrumb"><a href="/exchanges/">Exchanges</a> › <span><?= e($x['name']) ?></span></nav>
<div class="page-head"><?= coin_img(['image' => $x['image'], 'name' => $x['name'], 'symbol' => $x['name']], 36) ?><h1><?= e($x['name']) ?></h1>
  <?php if ($x['trust_score']): ?><span class="score s<?= (int)$x['trust_score'] ?>">Trust <?= (int)$x['trust_score'] ?>/10</span><?php endif; ?></div>
<div class="stat-row">
  <div class="card stat"><div class="muted small">24h Volume</div><div class="big"><?= fmt_big($vol) ?></div></div>
  <div class="card stat"><div class="muted small">Trust Rank</div><div class="big">#<?= e($x['trust_rank'] ?: '—') ?></div></div>
  <div class="card stat"><div class="muted small">Established</div><div class="big"><?= e($x['year_established'] ?: '—') ?></div></div>
  <div class="card stat"><div class="muted small">Country</div><div class="big sm"><?= e($x['country'] ?: '—') ?></div></div>
</div>
<?php if ($x['description']): ?><section class="card prose"><h2>About <?= e($x['name']) ?></h2><p><?= e(strip_tags($x['description'])) ?></p>
  <?php if ($x['url']): ?><p><a href="<?= e($x['url']) ?>" rel="nofollow noopener" target="_blank">Visit <?= e($x['name']) ?> ›</a></p><?php endif; ?></section><?php endif; ?>
<?php if ($markets): ?>
<section class="card"><h2><?= e($x['name']) ?> Markets</h2>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th class="num">#</th><th class="left">Coin</th><th class="left">Pair</th><th>Price</th><th>24h</th><th>Volume (24h)</th></tr></thead>
    <tbody><?php foreach ($markets as $i => $m): ?>
      <tr><td class="num muted"><?= $i + 1 ?></td>
        <td class="left"><?php if ($m['coin_slug']): ?><a class="coin-cell" href="/currencies/<?= e($m['coin_slug']) ?>/"><?= coin_img(['image' => $m['image'], 'name' => $m['coin_name'], 'symbol' => $m['base']], 20) ?><span class="cn"><?= e($m['coin_name']) ?></span></a><?php else: ?><?= e($m['base']) ?><?php endif; ?></td>
        <td class="left"><a href="<?= e($m['trade_url']) ?>" rel="nofollow noopener sponsored" target="_blank"><?= e($m['pair']) ?></a></td>
        <td><?= fmt_price($m['price_usd']) ?></td><td><?= pct($m['change_24h']) ?></td><td><?= fmt_usd($m['volume_usd']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
