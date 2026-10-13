<h1><?= e($h1) ?></h1>
<?php if (!empty($intro)): ?><p class="lead"><?= e($intro) ?></p><?php endif; ?>
<?= render('partials/coin_table', ['coins' => $coins, 'showAdded' => !empty($show_added)]) ?>
