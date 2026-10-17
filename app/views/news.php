<?php if ($mode === 'index'): ?>
<h1>Crypto News &amp; Guides</h1>
<?php if (!$items): ?><p class="muted">No articles published yet.</p><?php endif; ?>
<div class="news-grid">
<?php foreach ($items as $a): ?>
  <article class="card news-card">
    <?php if ($a['image_url']): ?><a href="<?= e(article_url($a)) ?>"><img src="<?= e($a['image_url']) ?>" alt="" loading="lazy"></a><?php endif; ?>
    <div class="nc-body">
      <?php if ($a['sponsored']): ?><span class="ad-tag">Sponsored<?= $a['sponsor_name'] ? ' by ' . e($a['sponsor_name']) : '' ?></span><?php endif; ?>
      <h2><a href="<?= e(article_url($a)) ?>"><?= e($a['title']) ?></a></h2>
      <p class="muted"><?= e($a['excerpt']) ?></p>
      <small class="muted"><?= e(fmt_date($a['publish_at'] ?: $a['created_at'])) ?><?= $a['category'] ? ' · ' . e($a['category']) : '' ?></small>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?= pager($page, $pages, '/news/') ?>
<?php else: ?>
<article class="prose article">
  <p class="crumbs"><a href="/news/">News</a> › <?= e($a['category'] ?: 'Article') ?></p>
  <?php if ($a['sponsored']): ?><div class="notice warn"><strong>Sponsored content</strong><?= $a['sponsor_name'] ? ' — paid for by ' . e($a['sponsor_name']) : '' ?>. This is not investment advice. <a href="/advertising-disclosure/">Learn more</a></div><?php endif; ?>
  <h1><?= e($a['title']) ?></h1>
  <p class="muted small">By <?= e($a['author_name'] ?: cfg('site_name') . ' Editorial') ?> · <time datetime="<?= e(gmdate('c', strtotime(($a['publish_at'] ?: $a['created_at']) . ' UTC'))) ?>"><?= e(fmt_date($a['publish_at'] ?: $a['created_at'])) ?></time><?= $a['updated_at'] ? ' · Updated ' . e(fmt_date($a['updated_at'])) : '' ?></p>
  <?php if ($a['image_url']): ?><img class="hero" src="<?= e($a['image_url']) ?>" alt="<?= e($a['title']) ?>"><?php endif; ?>
  <?= ad_slot('article_inline') ?>
  <div class="body"><?= html_sanitize($a['body']) ?></div>
  <p class="muted small">Cryptoassets are volatile. Nothing here is financial advice.</p>
</article>
<?php if ($related): ?><section class="card"><h2>More from <?= e(cfg('site_name')) ?></h2><ul><?php foreach ($related as $r): ?><li><a href="<?= e(article_url($r)) ?>"><?= e($r['title']) ?></a></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php endif; ?>
