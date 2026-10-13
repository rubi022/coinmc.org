<?php
/**
 * Usage:
 *   php cron/run.php schedule          # run from crontab every minute; runs whatever is due
 *   php cron/run.php all               # run every task once (first-time bootstrap)
 *   php cron/run.php markets|tickers|meta|history|hourly|global|categories|exchanges|trending|fiat
 *
 * crontab -e
 *   * * * * * cd /path/to/coinmc && php cron/run.php schedule >> storage/logs/cron.log 2>&1
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/tasks.php';
set_time_limit(0);

// task => minimum minutes between runs
$schedule = [
    'fiat'       => 60,
    'markets'    => 5,
    'tickers'    => 2,
    'global'     => 10,
    'trending'   => 15,
    'categories' => 60,
    'exchanges'  => 360,
    'meta'       => 10,
    'history'    => 5,
    'hourly'     => 60,
    'cache_clear'=> 60,
];

$arg = $argv[1] ?? 'schedule';

function run_task(string $t): void
{
    $lock = fopen(STORAGE . "/logs/.lock-$t", 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { log_msg('sync', "$t already running, skipped"); return; }
    $t0 = microtime(true);
    try {
        ('task_' . $t)();
        kv_set("last_run_$t", time());
    } catch (Throwable $e) {
        log_msg('sync', "$t FAILED: " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
    log_msg('sync', sprintf('%s done in %.1fs', $t, microtime(true) - $t0));
    flock($lock, LOCK_UN);
}

if ($arg === 'schedule') {
    // One scheduler at a time so we never double the CoinGecko request rate.
    $g = fopen(STORAGE . '/logs/.lock-scheduler', 'c');
    if (!flock($g, LOCK_EX | LOCK_NB)) exit(0);
    foreach ($schedule as $t => $mins) {
        $last = (int)kv_get("last_run_$t", 0);
        if (time() - $last >= $mins * 60 - 20) run_task($t);
    }
} elseif ($arg === 'all') {
    // Order matters: fiat + markets first, then categories (for meta mapping), tickers needs coin prices.
    foreach (['fiat', 'markets', 'global', 'categories', 'exchanges', 'trending', 'tickers', 'meta', 'history', 'hourly'] as $t) run_task($t);
} elseif (isset($schedule[$arg])) {
    run_task($arg);
} else {
    exit("Unknown task: $arg\n");
}
