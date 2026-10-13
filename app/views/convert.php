<?php
$fmt = function ($v, array $asset) {
    return $asset['type'] === 'fiat' ? fmt_price($v, $asset['sign']) : rtrim(rtrim(number_format($v, $v >= 1 ? 6 : 10, '.', ','), '0'), '.') . ' ' . $asset['symbol'];
};
$amounts = [0.01, 0.1, 0.5, 1, 5, 10, 25, 50, 100, 500, 1000, 10000];
$amountsRev = [1, 5, 10, 25, 50, 100, 500, 1000, 5000, 10000, 50000, 100000];
$fiatList = array_intersect(top_fiats(), array_keys(fiats()));
?>
<nav class="crumbs" aria-label="Breadcrumb"><a href="/convert/">Converter</a> › <span><?= e($a['symbol']) ?> to <?= e($b['symbol']) ?></span></nav>
<h1><?= e($a['name']) ?> to <?= e($b['name']) ?> Converter (<?= e($a['symbol']) ?> to <?= e($b['symbol']) ?>)</h1>
<p class="lead">1 <?= e($a['symbol']) ?> = <strong><?= $fmt($rate, $b) ?></strong> · 1 <?= e($b['symbol']) ?> = <strong><?= $fmt(1 / $rate, $a) ?></strong>
<?php if ($coin): ?> · <?= e($coin['name']) ?> 24h: <?= pct($coin['change_24h']) ?><?php endif; ?></p>

<section class="card conv-big" data-rate="<?= e(sprintf('%.12F', $rate)) ?>">
  <label class="conv-row"><span><?= e($a['symbol']) ?></span><input type="number" step="any" value="1" data-conv="a" aria-label="<?= e($a['symbol']) ?> amount"></label>
  <a class="swap" href="<?= e(convert_url($b['code'], $a['code'])) ?>" title="Swap" aria-label="Swap currencies">⇄</a>
  <label class="conv-row"><span><?= e($b['symbol']) ?></span><input type="number" step="any" value="<?= e(rtrim(rtrim(sprintf($rate >= 1 ? '%.4F' : '%.10F', $rate), '0'), '.')) ?>" data-conv="b" aria-label="<?= e($b['symbol']) ?> amount"></label>
</section>

<div class="grid2">
  <section class="card">
    <h2>Convert <?= e($a['name']) ?> to <?= e($b['name']) ?></h2>
    <table class="tbl kv"><thead><tr><th class="left"><?= e($a['symbol']) ?></th><th><?= e($b['symbol']) ?></th></tr></thead><tbody>
    <?php foreach ($amounts as $x): ?><tr><th class="left"><?= rtrim(rtrim(number_format($x, 2), '0'), '.') ?> <?= e($a['symbol']) ?></th><td><?= $fmt($x * $rate, $b) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </section>
  <section class="card">
    <h2>Convert <?= e($b['name']) ?> to <?= e($a['name']) ?></h2>
    <table class="tbl kv"><thead><tr><th class="left"><?= e($b['symbol']) ?></th><th><?= e($a['symbol']) ?></th></tr></thead><tbody>
    <?php foreach ($amountsRev as $x): ?><tr><th class="left"><?= number_format($x) ?> <?= e($b['symbol']) ?></th><td><?= $fmt($x / $rate, $a) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </section>
</div>

<?php if ($coin): ?>
<section class="card">
  <h2><?= e($coin['name']) ?> (<?= e(strtoupper($coin['symbol'])) ?>) market data</h2>
  <p><?= e($coin['name']) ?> is trading at <?= fmt_price($coin['price']) ?> USD, <?= num($coin['change_24h']) >= 0 ? 'up' : 'down' ?> <?= pct_raw($coin['change_24h']) ?> in the last 24 hours,
     with a market cap of <?= fmt_usd($coin['market_cap']) ?> and 24h volume of <?= fmt_usd($coin['volume_24h']) ?>.
     <a href="<?= e(coin_url($coin)) ?>">See <?= e($coin['name']) ?> price chart ›</a> · <a href="<?= e(coin_url($coin)) ?>historical-data/">Historical data ›</a></p>
</section>
<section class="card">
  <h2>Convert <?= e(strtoupper($coin['symbol'])) ?> to other currencies</h2>
  <div class="chips"><?php foreach ($fiatList as $f): if ($f === $b['code']) continue; ?><a class="chip" href="<?= e(convert_url($coin['symbol'], $f)) ?>"><?= e(strtoupper($coin['symbol'])) ?> to <?= e(strtoupper($f)) ?></a><?php endforeach; ?></div>
</section>
<?php endif; ?>

<section class="card faq">
  <h2>Frequently asked questions</h2>
  <?php foreach ($faq as $qq => $ans): ?><details><summary><?= e($qq) ?></summary><p><?= e($ans) ?></p></details><?php endforeach; ?>
</section>
