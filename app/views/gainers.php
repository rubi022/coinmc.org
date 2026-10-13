<h1>Top Crypto Gainers and Losers</h1>
<p class="lead">The cryptocurrencies with the biggest price moves in the last 24 hours. Only coins with at least $50,000 in 24h trading volume are shown.</p>
<div class="grid2">
  <section><h2 class="up">Top Gainers</h2><?= render('partials/mover_table', ['coins' => $up]) ?></section>
  <section><h2 class="down">Top Losers</h2><?= render('partials/mover_table', ['coins' => $down]) ?></section>
</div>
