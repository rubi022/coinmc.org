<?php $fi = array_intersect(top_fiats(), array_keys(fiats())); ?>
<h1>Cryptocurrency Converter and Calculator</h1>
<p class="lead">Convert any cryptocurrency to US Dollar, Euro, British Pound, Indian Rupee and <?= count($fi) ?> other currencies with live rates.</p>
<section class="card conv-pick">
  <form onsubmit="location.href='/convert/'+this.f.value.toLowerCase()+'/'+this.t.value.toLowerCase()+'/';return false">
    <label>From <select name="f"><?php foreach ($coins as $c): ?><option value="<?= e($c['symbol']) ?>"><?= e($c['name']) ?> (<?= e(strtoupper($c['symbol'])) ?>)</option><?php endforeach; ?></select></label>
    <label>To <select name="t"><?php foreach ($fi as $f): ?><option value="<?= e($f) ?>"><?= e(strtoupper($f)) ?> – <?= e(fiats()[$f]['name']) ?></option><?php endforeach; ?></select></label>
    <button class="btn" type="submit">Convert</button>
  </form>
</section>
<section class="card">
  <h2>Popular conversions</h2>
  <div class="conv-grid">
  <?php foreach (array_slice($coins, 0, 40) as $c): ?>
    <div><a class="coin-cell" href="<?= e(coin_url($c)) ?>"><?= coin_img($c, 20) ?><span class="cn"><?= e($c['name']) ?></span></a>
      <div class="chips sm"><?php foreach (array_slice($fi, 0, 5) as $f): ?><a class="chip" href="<?= e(convert_url($c['symbol'], $f)) ?>"><?= e(strtoupper($c['symbol'])) ?>/<?= e(strtoupper($f)) ?></a><?php endforeach; ?></div></div>
  <?php endforeach; ?>
  </div>
</section>
