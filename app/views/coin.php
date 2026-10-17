<?php
$s = strtoupper($c['symbol']); $n = $c['name'];
$circ = num($c['circulating_supply']); $max = num($c['max_supply']) ?: num($c['total_supply']);
$supplyPct = ($circ && $max) ? min(100, $circ / $max * 100) : null;
$lo = num($c['low_24h']); $hi = num($c['high_24h']); $p = num($c['price']);
$rangePct = ($lo !== null && $hi && $hi > $lo) ? max(0, min(100, ($p - $lo) / ($hi - $lo) * 100)) : null;
$volMc = num($c['market_cap']) ? num($c['volume_24h']) / num($c['market_cap']) * 100 : null;
$links = array_filter([
    'Website' => $c['homepage'], 'Whitepaper' => $c['whitepaper'], 'Explorer' => $c['explorer'],
    'X / Twitter' => $c['twitter'] ? 'https://x.com/' . $c['twitter'] : null, 'Reddit' => $c['reddit'], 'GitHub' => $c['github'],
]);
$fiatList = array_intersect(top_fiats(), array_keys(fiats()));
?>
<nav class="crumbs" aria-label="Breadcrumb"><a href="/">Cryptocurrencies</a> › <span><?= e($n) ?> Price</span></nav>

<?php if (($c['risk_flag'] ?? '') === 'warning' || ($c['risk_flag'] ?? '') === 'scam'): ?><div class="notice err"><strong>Risk warning:</strong> <?= e($c['name']) ?> has been flagged by our team as <?= $c['risk_flag'] === 'scam' ? 'a suspected scam' : 'high risk' ?>. Do your own research and never invest more than you can afford to lose.</div><?php endif; ?>
<?= ad_slot('coin_top') ?>
<div class="coin-layout">
  <aside class="coin-side">
    <div class="coin-title">
      <?= coin_img($c, 36) ?>
      <h1><?= e($n) ?> <span class="sym"><?= e($s) ?> price</span></h1>
      <?php if ($c['rank_no']): ?><span class="badge">#<?= (int)$c['rank_no'] ?></span><?php endif; ?>
      <?php if (!empty($c['verified'])): ?><span class="badge ok" title="Reviewed by the <?= e(cfg('site_name')) ?> team">✓ Verified</span><?php endif; ?>
      <button class="star big" data-coin="<?= e($c['slug']) ?>" aria-label="Add to watchlist">☆</button>
    </div>
    <div class="coin-price">
      <span class="price-big" data-live-price><?= fmt_price($c['price']) ?></span>
      <?= pct($c['change_24h']) ?> <span class="muted small">(24h)</span>
    </div>
    <?php if ($rangePct !== null): ?>
    <div class="range">
      <div class="range-bar"><i style="left:<?= round($rangePct, 1) ?>%"></i></div>
      <div class="range-lbl small"><span>Low: <?= fmt_price($lo) ?></span><span>24h</span><span>High: <?= fmt_price($hi) ?></span></div>
    </div>
    <?php endif; ?>

    <dl class="stats">
      <div><dt>Market cap</dt><dd><?= fmt_usd($c['market_cap']) ?></dd></div>
      <div><dt>Volume (24h)</dt><dd><?= fmt_usd($c['volume_24h']) ?></dd></div>
      <div><dt>Vol/Mkt Cap (24h)</dt><dd><?= $volMc !== null ? number_format($volMc, 2) . '%' : '—' ?></dd></div>
      <div><dt>Fully diluted valuation</dt><dd><?= fmt_usd($c['fdv']) ?></dd></div>
      <div><dt>Circulating supply</dt><dd><?= fmt_supply($c['circulating_supply'], $s) ?>
        <?php if ($supplyPct !== null): ?><div class="bar"><i style="width:<?= round($supplyPct, 1) ?>%"></i></div><span class="muted small"><?= number_format($supplyPct, 1) ?>% of max</span><?php endif; ?></dd></div>
      <div><dt>Total supply</dt><dd><?= fmt_supply($c['total_supply'], $s) ?></dd></div>
      <div><dt>Max supply</dt><dd><?= num($c['max_supply']) ? fmt_supply($c['max_supply'], $s) : '∞' ?></dd></div>
    </dl>

    <?php if ($links): ?>
    <div class="side-block"><h3>Links</h3><div class="chips">
      <?php foreach ($links as $label => $href): ?><a class="chip" href="<?= e($href) ?>" rel="nofollow noopener" target="_blank"><?= e($label) ?></a><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
    <?php if ($cats): ?>
    <div class="side-block"><h3>Categories</h3><div class="chips">
      <?php foreach (array_slice($cats, 0, 8) as $cat): ?><a class="chip" href="<?= e(category_url($cat)) ?>"><?= e($cat['name']) ?></a><?php endforeach; ?>
    </div></div>
    <?php endif; ?>

    <div class="side-block conv-box" data-rate="<?= e((string)$c['price']) ?>">
      <h3><?= e($s) ?> to USD converter</h3>
      <label class="conv-row"><span><?= e($s) ?></span><input type="number" inputmode="decimal" value="1" step="any" data-conv="a" aria-label="<?= e($s) ?> amount"></label>
      <label class="conv-row"><span>USD</span><input type="number" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format((float)$c['price'], (float)$c['price'] >= 1 ? 2 : 10, '.', ''), '0'), '.')) ?>" step="any" data-conv="b" aria-label="USD amount"></label>
      <a class="small" href="<?= e(convert_url($c['symbol'], 'usd')) ?>">Full <?= e($s) ?> to USD converter ›</a>
    </div>
  </aside>

  <div class="coin-main">
    <?= ad_slot('coin_sidebar') ?>
    <section class="card chart-card">
      <div class="chart-head">
        <h2><?= e($n) ?> to USD Chart</h2>
        <div class="seg" role="tablist" aria-label="Chart range">
          <?php foreach (['1d' => '1D', '7d' => '7D', '1m' => '1M', '3m' => '3M', '1y' => '1Y', 'all' => 'All'] as $k => $l): ?>
            <button data-range="<?= $k ?>" class="<?= $k === '7d' ? 'on' : '' ?>"><?= $l ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="chart" class="chart" data-coin="<?= e($c['slug']) ?>" data-ccy="USD">
        <noscript><?= sparkline_svg($c['sparkline_7d'], num($c['change_7d']), 800, 300) ?></noscript>
      </div>
      <div class="perf">
        <?php foreach (['1h' => 'change_1h', '24h' => 'change_24h', '7d' => 'change_7d', '30d' => 'change_30d', '1y' => 'change_1y'] as $l => $f): ?>
          <div><span class="muted small"><?= $l ?></span><?= pct($c[$f], 1) ?></div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <h2><?= e($n) ?> price today</h2>
      <p>The live <?= e($n) ?> price today is <strong><?= fmt_price($c['price']) ?> USD</strong> with a 24-hour trading volume of <strong><?= fmt_usd($c['volume_24h']) ?></strong>.
        <?= e($s) ?> is <?= num($c['change_24h']) >= 0 ? 'up' : 'down' ?> <?= pct_raw($c['change_24h']) ?> in the last 24 hours and <?= num($c['change_7d']) >= 0 ? 'up' : 'down' ?> <?= pct_raw($c['change_7d']) ?> over the past 7 days.
        <?php if ($c['rank_no']): ?>The current <?= e(cfg('site_name')) ?> ranking is <strong>#<?= (int)$c['rank_no'] ?></strong>, with a live market cap of <?= fmt_usd($c['market_cap']) ?>.<?php endif; ?>
        It has a circulating supply of <?= fmt_supply($c['circulating_supply'], $s) ?><?= num($c['max_supply']) ? ' and a max supply of ' . fmt_supply($c['max_supply'], $s) : '' ?>.
        Prices are updated every few minutes from <?= count($markets) ? 'live exchange order books' : 'aggregated market data' ?>.</p>
    </section>

    <?php if ($markets): ?>
    <section class="card">
      <h2><?= e($n) ?> Markets</h2>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th class="num">#</th><th class="left">Exchange</th><th class="left">Pair</th><th>Price</th><th>Volume (24h)</th><th class="hide-sm">Volume %</th><th class="hide-sm">Updated</th></tr></thead>
        <tbody>
        <?php $tv = array_sum(array_column($markets, 'volume_usd')) ?: 1; foreach ($markets as $i => $m): ?>
          <tr><td class="num muted"><?= $i + 1 ?></td>
            <td class="left"><strong><?= e(Exchanges::NAMES[$m['exchange']] ?? ucfirst($m['exchange'])) ?></strong></td>
            <td class="left"><a href="<?= e($m['trade_url']) ?>" rel="nofollow noopener sponsored" target="_blank"><?= e($m['pair']) ?></a></td>
            <td><?= fmt_price($m['price_usd']) ?></td><td><?= fmt_usd($m['volume_usd']) ?></td>
            <td class="hide-sm"><?= number_format($m['volume_usd'] / $tv * 100, 2) ?>%</td><td class="hide-sm muted small"><?= e(time_ago($m['updated_at'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <?php endif; ?>

    <section class="card">
      <h2><?= e($s) ?> price statistics</h2>
      <div class="tbl-wrap"><table class="tbl kv">
        <tr><th><?= e($n) ?> price</th><td><?= fmt_price($c['price']) ?></td></tr>
        <tr><th>24h low / 24h high</th><td><?= fmt_price($lo) ?> / <?= fmt_price($hi) ?></td></tr>
        <tr><th>All-time high</th><td><?= fmt_price($c['ath']) ?> <span class="muted small"><?= fmt_date($c['ath_date']) ?></span> <?= pct($c['ath_change'], 1) ?></td></tr>
        <tr><th>All-time low</th><td><?= fmt_price($c['atl']) ?> <span class="muted small"><?= fmt_date($c['atl_date']) ?></span> <?= pct($c['atl_change'], 1) ?></td></tr>
        <tr><th>Market cap rank</th><td><?= $c['rank_no'] ? '#' . (int)$c['rank_no'] : '—' ?></td></tr>
        <?php if ($c['genesis_date']): ?><tr><th>Launched</th><td><?= fmt_date($c['genesis_date']) ?></td></tr><?php endif; ?>
        <?php if ($c['hashing_algorithm']): ?><tr><th>Hashing algorithm</th><td><?= e($c['hashing_algorithm']) ?></td></tr><?php endif; ?>
      </table></div>
    </section>

    <?php if ($recent): ?>
    <section class="card">
      <div class="mini-h"><h2><?= e($n) ?> historical prices (last 7 days)</h2><a href="<?= e(coin_url($c)) ?>historical-data/">Full history ›</a></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th class="left">Date</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th class="hide-sm">Volume</th></tr></thead>
        <tbody><?php foreach ($recent as $r): ?>
          <tr><td class="left"><?= fmt_date($r['d']) ?></td><td><?= fmt_price($r['open']) ?></td><td><?= fmt_price($r['high']) ?></td><td><?= fmt_price($r['low']) ?></td><td><?= fmt_price($r['close']) ?></td><td class="hide-sm"><?= fmt_big($r['volume']) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </section>
    <?php endif; ?>

    <?php if ($c['description']): ?>
    <section class="card prose">
      <h2>What is <?= e($n) ?> (<?= e($s) ?>)?</h2>
      <div class="desc collapsible"><?= clean_html($c['description']) ?></div>
      <button class="link-btn expand">Read more</button>
    </section>
    <?php endif; ?>

    <section class="card faq">
      <h2><?= e($n) ?> FAQ</h2>
      <?php foreach ($faq as $qq => $a): ?>
        <details><summary><?= e($qq) ?></summary><p><?= e($a) ?></p></details>
      <?php endforeach; ?>
    </section>

    <section class="card">
      <h2>Convert <?= e($n) ?> to other currencies</h2>
      <div class="chips">
        <?php foreach (array_slice($fiatList, 0, 16) as $f): ?><a class="chip" href="<?= e(convert_url($c['symbol'], $f)) ?>"><?= e($s) ?> to <?= e(strtoupper($f)) ?></a><?php endforeach; ?>
      </div>
    </section>

    <?php if ($related): ?>
    <section class="card">
      <h2>People also watch</h2>
      <div class="related">
        <?php foreach ($related as $r): ?>
          <a class="rel" href="<?= e(coin_url($r)) ?>"><?= coin_img($r, 28) ?><div><strong><?= e($r['name']) ?></strong><div><?= fmt_price($r['price']) ?> <?= pct($r['change_24h'], 1) ?></div></div></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
    <p class="muted small">Last updated <?= e(time_ago($c['updated_at'])) ?> · Source: <?= e($c['price_source'] === 'exchanges' ? 'volume-weighted exchange prices' : 'aggregated market data') ?> · Not financial advice.</p>
  </div>
</div>
