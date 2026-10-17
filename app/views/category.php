<nav class="crumbs" aria-label="Breadcrumb"><a href="/categories/">Categories</a> › <span><?= e($cat['name']) ?></span></nav>
<h1>Top <?= e($cat['name']) ?> Coins by Market Cap</h1>
<?= ad_slot('list_top') ?>
<p class="lead">The <?= e($cat['name']) ?> category has a total market cap of <strong><?= fmt_big($cat['market_cap']) ?></strong><?php if ($cat['change_24h'] !== null): ?>,
  <span class="<?= up_down($cat['change_24h']) ?>"><?= ($cat['change_24h'] >= 0 ? 'up ' : 'down ') . pct_raw($cat['change_24h']) ?></span> in the last 24 hours<?php endif; ?>,
  with <?= fmt_big($cat['volume_24h']) ?> traded. <?= count($coins) ?> coins listed.</p>
<?= render('partials/coin_table', ['coins' => $coins]) ?>
<?php if ($cat['content']): ?><section class="card prose"><h2>About <?= e($cat['name']) ?></h2><p><?= e(strip_tags($cat['content'])) ?></p></section><?php endif; ?>
