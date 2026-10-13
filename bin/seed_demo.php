<?php
/**
 * OFFLINE DEMO DATA — only for previewing the UI without network access.
 * Live sync (php cron/run.php all) overwrites these rows with real data.
 *   php bin/seed_demo.php
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require __DIR__ . '/../app/bootstrap.php';
mt_srand(42);

$base = [
    ['bitcoin','btc','Bitcoin',62000,19.7e6,21e6,['layer-1','proof-of-work']], ['ethereum','eth','Ethereum',2500,120.3e6,null,['layer-1','smart-contract-platform']],
    ['tether','usdt','Tether',1,118e9,null,['stablecoins']], ['binancecoin','bnb','BNB',580,145e6,200e6,['layer-1','exchange-based-tokens']],
    ['solana','sol','Solana',150,470e6,null,['layer-1','smart-contract-platform']], ['usd-coin','usdc','USDC',1,35e9,null,['stablecoins']],
    ['ripple','xrp','XRP',0.55,56e9,100e9,['layer-1']], ['dogecoin','doge','Dogecoin',0.11,146e9,null,['meme-token','proof-of-work']],
    ['tron','trx','TRON',0.16,86e9,null,['layer-1','smart-contract-platform']], ['cardano','ada','Cardano',0.35,35e9,45e9,['layer-1','smart-contract-platform']],
    ['avalanche-2','avax','Avalanche',27,406e6,720e6,['layer-1','smart-contract-platform']], ['shiba-inu','shib','Shiba Inu',0.000018,589e12,null,['meme-token']],
    ['chainlink','link','Chainlink',11,627e6,1e9,['oracle','decentralized-finance-defi']], ['polkadot','dot','Polkadot',4.3,1.5e9,null,['layer-0']],
    ['bitcoin-cash','bch','Bitcoin Cash',330,19.7e6,21e6,['proof-of-work']], ['near','near','NEAR Protocol',5,1.2e9,null,['layer-1','artificial-intelligence']],
    ['uniswap','uni','Uniswap',7.5,600e6,1e9,['decentralized-finance-defi','decentralized-exchange']], ['litecoin','ltc','Litecoin',68,75e6,84e6,['proof-of-work']],
    ['pepe','pepe','Pepe',0.0000095,420e12,420.69e12,['meme-token']], ['internet-computer','icp','Internet Computer',8.5,470e6,null,['layer-1','artificial-intelligence']],
    ['aptos','apt','Aptos',9,500e6,null,['layer-1']], ['sui','sui','Sui',2,2.8e9,10e9,['layer-1']],
    ['ethereum-classic','etc','Ethereum Classic',19,148e6,210.7e6,['proof-of-work']], ['monero','xmr','Monero',160,18.4e6,null,['privacy-coins','proof-of-work']],
    ['stellar','xlm','Stellar',0.095,29e9,50e9,['layer-1']], ['render-token','render','Render',5.2,390e6,532e6,['artificial-intelligence']],
    ['aave','aave','Aave',150,15e6,16e6,['decentralized-finance-defi','lending-borrowing']], ['arbitrum','arb','Arbitrum',0.55,3.8e9,10e9,['layer-2']],
    ['optimism','op','Optimism',1.6,1.2e9,4.29e9,['layer-2']], ['filecoin','fil','Filecoin',3.6,590e6,null,['storage']],
    ['cosmos','atom','Cosmos Hub',4.5,390e6,null,['layer-0']], ['injective-protocol','inj','Injective',20,97e6,100e6,['decentralized-finance-defi']],
    ['bittensor','tao','Bittensor',550,7.4e6,21e6,['artificial-intelligence']], ['fetch-ai','fet','Artificial Superintelligence Alliance',1.3,2.5e9,2.7e9,['artificial-intelligence']],
    ['the-graph','grt','The Graph',0.16,9.5e9,10.7e9,['artificial-intelligence']], ['dogwifcoin','wif','dogwifhat',2.2,998e6,998e6,['meme-token']],
    ['bonk','bonk','Bonk',0.000021,75e12,93e12,['meme-token']], ['maker','mkr','Maker',1500,880e3,1e6,['decentralized-finance-defi','lending-borrowing']],
];
$cats = [
    'layer-1' => 'Layer 1 (L1)', 'layer-0' => 'Layer 0 (L0)', 'layer-2' => 'Layer 2 (L2)', 'smart-contract-platform' => 'Smart Contract Platform',
    'stablecoins' => 'Stablecoins', 'meme-token' => 'Meme', 'decentralized-finance-defi' => 'Decentralized Finance (DeFi)',
    'artificial-intelligence' => 'Artificial Intelligence (AI)', 'proof-of-work' => 'Proof of Work (PoW)', 'exchange-based-tokens' => 'Exchange-based Tokens',
    'oracle' => 'Oracle', 'privacy-coins' => 'Privacy Coins', 'storage' => 'Storage', 'decentralized-exchange' => 'Decentralized Exchange (DEX)',
    'lending-borrowing' => 'Lending/Borrowing',
];
// filler coins so pagination / long tail pages exist
$syl = ['zor', 'qua', 'lix', 'ner', 'vo', 'tal', 'mex', 'kin', 'ra', 'flo', 'syn', 'dex', 'nova', 'arc', 'pix', 'gen', 'orb', 'hex'];
for ($i = 0; $i < 210; $i++) {
    $n = ucfirst($syl[$i % 18] . $syl[($i * 7 + 3) % 18]) . ($i > 17 ? ' ' . ['Protocol', 'Network', 'Finance', 'AI', 'Chain', 'Swap'][$i % 6] : '');
    $p = round(exp(mt_rand(-900, 400) / 100), 8);
    $base[] = [slugify($n) . '-' . $i, strtolower(substr(preg_replace('~[^a-z]~i', '', $n), 0, 4)) . $i, $n, $p, mt_rand(10, 5000) * 1e6 / max($p, 0.0001) / 50, null, [array_rand($cats)]];
}

db()->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['coins', 'coin_history_daily', 'coin_history_hourly', 'categories', 'coin_categories', 'exchanges', 'markets', 'global_stats', 'global_history', 'trending', 'fiat_rates'] as $t) db()->exec("TRUNCATE $t");

foreach ($cats as $slug => $name) q('INSERT INTO categories (cg_id, slug, name, content, updated_at) VALUES (?,?,?,?,NOW())', [$slug, $slug, $name, "$name tokens ranked by market capitalization."]);
$catId = array_column(rows('SELECT id, cg_id FROM categories'), 'id', 'cg_id');

$now = time();
$rank = 0;
foreach ($base as $b) {
    [$id, $sym, $name, $price, $circ, $max, $cs] = $b;
    $rank++;
    // daily random walk backwards from today
    $days = $rank <= 40 ? 1100 : mt_rand(120, 900);
    $vol = in_array($sym, ['usdt', 'usdc']) ? 0.001 : ($rank <= 10 ? 0.032 : 0.055);
    $series = [$price];
    for ($d = 1; $d < $days; $d++) $series[] = max($price * 0.0005, end($series) / exp((mt_rand(-1000, 1000) / 1000) * $vol * 1.7 + ($vol > 0.01 ? 0.0012 : 0)));
    $series = array_reverse($series); // oldest first
    if (in_array($sym, ['usdt', 'usdc'])) $series = array_map(fn() => 1 + mt_rand(-20, 20) / 10000, $series);
    $mc = $price * $circ;
    $spark = [];
    $p = $price;
    for ($h = 0; $h < 168; $h++) { $spark[] = $p; $p = $p / exp((mt_rand(-1000, 1000) / 1000) * $vol / 4); }
    $spark = array_reverse($spark);
    $ath = max($series) * 1.05; $atl = min($series) * 0.9;
    $athI = array_search(max($series), $series); $atlI = array_search(min($series), $series);
    $chg = fn($k) => ($price / $series[max(0, count($series) - 1 - $k)] - 1) * 100;
    q('INSERT INTO coins (cg_id, slug, symbol, name, image, rank_no, price, market_cap, fdv, volume_24h, high_24h, low_24h, change_1h, change_24h, change_7d, change_30d, change_1y,
        circulating_supply, total_supply, max_supply, ath, ath_change, ath_date, atl, atl_change, atl_date, sparkline_7d, description, homepage, genesis_date, price_source, updated_at, meta_updated_at, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),?)', [
        $id, $id, $sym, $name, null, $rank, $price, $mc, $max ? $max * $price : $mc, $mc * mt_rand(2, 12) / 100,
        $price * 1.03, $price * 0.97, mt_rand(-150, 150) / 100, $chg(1), $chg(7), $chg(30), $chg(365),
        $circ, $max ?: $circ, $max, $ath, ($price / $ath - 1) * 100, gmdate('Y-m-d H:i:s', $now - (count($series) - $athI) * 86400),
        $atl, ($price / $atl - 1) * 100, gmdate('Y-m-d H:i:s', $now - (count($series) - $atlI) * 86400), json_encode(array_map(fn($v) => round($v, 10), $spark)),
        "<p>$name ($sym) is a cryptocurrency. This is demo text generated offline — the live sync replaces it with the project's real description.</p>",
        $rank <= 40 ? 'https://example.org' : null, $rank <= 40 ? gmdate('Y-m-d', $now - 3000 * 86400) : null, 'demo',
        $rank > 230 ? gmdate('Y-m-d H:i:s', $now - mt_rand(1, 20) * 86400) : '2020-01-01 00:00:00',
    ]);
    $cid = (int)db()->lastInsertId();
    foreach ($cs as $c) if (isset($catId[$c])) q('INSERT IGNORE INTO coin_categories VALUES (?,?)', [$cid, $catId[$c]]);
    // daily history
    $rowsH = []; $prev = $series[0];
    foreach ($series as $i => $close) {
        $ts = $now - (count($series) - 1 - $i) * 86400;
        $hi = max($prev, $close) * (1 + mt_rand(0, 300) / 10000); $lo = min($prev, $close) * (1 - mt_rand(0, 300) / 10000);
        $rowsH[] = ['coin_id' => $cid, 'd' => gmdate('Y-m-d', $ts), 'open' => $prev, 'high' => $hi, 'low' => $lo, 'close' => $close,
            'volume' => $close * $circ * mt_rand(2, 10) / 100, 'market_cap' => $close * $circ, 'source' => 'demo'];
        $prev = $close;
    }
    upsert('coin_history_daily', $rowsH, ['close'], 500);
    if ($rank <= 60) {
        $hr = []; $p = $price;
        for ($h = 0; $h < 30 * 24; $h++) { $hr[] = ['coin_id' => $cid, 'ts' => intdiv($now, 3600) * 3600 - $h * 3600, 'price' => $p, 'volume' => null]; $p = $p / exp((mt_rand(-1000, 1000) / 1000) * $vol / 4); }
        upsert('coin_history_hourly', $hr, ['price'], 500);
        // markets on exchanges
        foreach (['binance' => 1, 'coinbase' => 0.4, 'okx' => 0.5, 'bybit' => 0.35, 'kraken' => 0.2, 'kucoin' => 0.15] as $ex => $share) {
            foreach (['USDT', 'USDC', 'USD', 'BTC'] as $quote) {
                if ($quote === 'USD' && !in_array($ex, ['coinbase', 'kraken'])) continue;
                if (strtoupper($sym) === $quote || ($sym === 'btc' && $quote === 'BTC')) continue;
                $vu = $mc * 0.02 * $share * mt_rand(20, 100) / 100;
                $pu = $price * (1 + mt_rand(-20, 20) / 10000);
                q('INSERT INTO markets VALUES (?,?,?,?,?,?,?,?,?,?,NOW())', [$ex, strtoupper($sym) . "/$quote", strtoupper($sym), $quote,
                    $quote === 'BTC' ? $pu / 62000 : $pu, $pu, $vu / $pu, $vu, $chg(1), "https://example.org/$ex"]);
            }
        }
    }
}
foreach ([['binance', 'Binance', 2017, 'Cayman Islands', 10, 1], ['gdax', 'Coinbase Exchange', 2012, 'United States', 10, 2], ['okex', 'OKX', 2017, 'Seychelles', 10, 3],
          ['bybit_spot', 'Bybit', 2018, 'British Virgin Islands', 10, 4], ['kraken', 'Kraken', 2011, 'United States', 10, 5], ['kucoin', 'KuCoin', 2014, 'Seychelles', 9, 6],
          ['gate', 'Gate', 2013, 'Cayman Islands', 9, 7], ['bitget', 'Bitget', 2018, 'Seychelles', 9, 8], ['htx', 'HTX', 2013, 'Seychelles', 8, 9], ['mexc', 'MEXC', 2018, 'Seychelles', 8, 10]] as $x) {
    q('INSERT INTO exchanges (cg_id, slug, name, year_established, country, url, description, trust_score, trust_rank, volume_24h_btc, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
        [$x[0], slugify($x[1]), $x[1], $x[2], $x[3], 'https://example.org', "{$x[1]} is a centralized cryptocurrency exchange established in {$x[2]}.", $x[4], $x[5], 200000 / $x[5]]);
}
$f = [['usd', 'US Dollar', '$', 1], ['eur', 'Euro', '€', 0.92], ['gbp', 'British Pound Sterling', '£', 0.77], ['inr', 'Indian Rupee', '₹', 84], ['cad', 'Canadian Dollar', 'C$', 1.37],
      ['aud', 'Australian Dollar', 'A$', 1.49], ['jpy', 'Japanese Yen', '¥', 149], ['krw', 'South Korean Won', '₩', 1350], ['brl', 'Brazil Real', 'R$', 5.6], ['try', 'Turkish Lira', '₺', 34.3],
      ['ngn', 'Nigerian Naira', '₦', 1600], ['php', 'Philippine Peso', '₱', 57], ['idr', 'Indonesian Rupiah', 'Rp', 15600], ['rub', 'Russian Ruble', '₽', 96], ['cny', 'Chinese Yuan', '¥', 7.1],
      ['chf', 'Swiss Franc', 'CHF ', 0.86], ['mxn', 'Mexican Peso', 'MX$', 19.6], ['zar', 'South African Rand', 'R', 17.6], ['pkr', 'Pakistani Rupee', '₨', 278], ['aed', 'UAE Dirham', 'AED ', 3.67],
      ['sgd', 'Singapore Dollar', 'S$', 1.31], ['hkd', 'Hong Kong Dollar', 'HK$', 7.77], ['thb', 'Thai Baht', '฿', 33.5], ['vnd', 'Vietnamese đồng', '₫', 25000]];
foreach ($f as $r) q('INSERT INTO fiat_rates VALUES (?,?,?,?,NOW())', $r);
q('UPDATE categories c SET market_cap = (SELECT SUM(market_cap) FROM coins x JOIN coin_categories cc ON cc.coin_id = x.id WHERE cc.category_id = c.id),
   volume_24h = (SELECT SUM(volume_24h) FROM coins x JOIN coin_categories cc ON cc.coin_id = x.id WHERE cc.category_id = c.id), change_24h = ROUND(RAND()*10-4, 2)');
foreach (['pepe', 'sui', 'bittensor', 'dogwifcoin', 'render-token', 'near', 'aptos'] as $i => $id) q('INSERT INTO trending VALUES (?,?,NOW())', [$i + 1, $id]);
$mc = (float)val('SELECT SUM(market_cap) FROM coins');
for ($d = 365; $d >= 0; $d--) q('INSERT INTO global_history VALUES (?,?,?,?)', [gmdate('Y-m-d', $now - $d * 86400), $mc * (0.6 + 0.4 * (365 - $d) / 365) * (1 + mt_rand(-30, 30) / 1000), $mc * 0.04, 54 + mt_rand(-20, 20) / 10]);
echo "Demo data seeded: " . count($base) . " coins.\n";
