<h1><?= e($h1) ?></h1>
<?= ad_slot('list_top') ?>
<?php if (!empty($intro)): ?><p class="lead"><?= e($intro) ?></p><?php endif; ?>
<?= render('partials/coin_table', ['coins' => $coins, 'showAdded' => !empty($show_added)]) ?>
