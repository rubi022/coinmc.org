<?php
/**
 * First-party, cookie-less analytics. One row per page request (bots flagged separately).
 * Visitors are pseudonymous hashes of IP+UA+server secret; sessions use a 30-minute inactivity window.
 */

function ua_bot(string $ua): ?string
{
    if ($ua === '') return 'No user agent';
    static $bots = [
        'Googlebot' => 'googlebot|google-inspectiontool|googleother|adsbot-google|mediapartners-google|apis-google',
        'Bingbot' => 'bingbot|bingpreview|msnbot', 'Yandex' => 'yandex', 'Baidu' => 'baiduspider', 'DuckDuckBot' => 'duckduckbot',
        'Applebot' => 'applebot', 'GPTBot' => 'gptbot|chatgpt-user|oai-searchbot', 'ClaudeBot' => 'claudebot|claude-web|anthropic-ai',
        'Perplexity' => 'perplexitybot', 'CCBot' => 'ccbot', 'Bytespider' => 'bytespider', 'Ahrefs' => 'ahrefsbot', 'Semrush' => 'semrushbot',
        'MJ12' => 'mj12bot', 'PetalBot' => 'petalbot', 'Facebook' => 'facebookexternalhit|facebot', 'Twitter' => 'twitterbot',
        'Slack' => 'slackbot|slack-imgproxy', 'Telegram' => 'telegrambot', 'WhatsApp' => 'whatsapp', 'Discord' => 'discordbot',
        'Uptime' => 'uptimerobot|pingdom|statuscake|site24x7|betterstack|datadog', 'Headless' => 'headlesschrome|phantomjs|puppeteer|playwright',
        'Script' => 'python-requests|python-urllib|curl/|wget/|go-http-client|java/|libwww|scrapy|aiohttp|httpx|okhttp|node-fetch|axios/|postmanruntime',
        'Other bot' => 'bot[\s/;)_-]|bot$|crawl|spider|scraper|monitor|fetch|checker',
    ];
    foreach ($bots as $name => $re) if (preg_match('~' . $re . '~i', $ua)) return $name;
    return null;
}

function ua_info(string $ua): array
{
    $bot = ua_bot($ua);
    $l = strtolower($ua);
    if (preg_match('~ipad|tablet|kindle|silk|playbook~', $l) || (strpos($l, 'android') !== false && strpos($l, 'mobile') === false)) $device = 'tablet';
    elseif (preg_match('~mobi|iphone|ipod|windows phone~', $l)) $device = 'mobile';
    else $device = 'desktop';

    if (strpos($l, 'edg/') !== false || strpos($l, 'edga/') !== false || strpos($l, 'edgios/') !== false) $browser = 'Edge';
    elseif (strpos($l, 'opr/') !== false || strpos($l, 'opera') !== false) $browser = 'Opera';
    elseif (strpos($l, 'samsungbrowser') !== false) $browser = 'Samsung';
    elseif (strpos($l, 'firefox') !== false || strpos($l, 'fxios') !== false) $browser = 'Firefox';
    elseif (strpos($l, 'chrome') !== false || strpos($l, 'crios') !== false) $browser = 'Chrome';
    elseif (strpos($l, 'safari') !== false) $browser = 'Safari';
    else $browser = 'Other';

    if (preg_match('~iphone|ipad|ipod~', $l)) $os = 'iOS';
    elseif (strpos($l, 'android') !== false) $os = 'Android';
    elseif (strpos($l, 'windows') !== false) $os = 'Windows';
    elseif (strpos($l, 'cros') !== false) $os = 'ChromeOS';
    elseif (strpos($l, 'mac os') !== false || strpos($l, 'macintosh') !== false) $os = 'macOS';
    elseif (strpos($l, 'linux') !== false) $os = 'Linux';
    else $os = 'Other';

    return ['device' => $device, 'browser' => $browser, 'os' => $os, 'bot' => $bot];
}

function country_code(): ?string
{
    foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE'] as $h) {
        $v = strtoupper(trim($_SERVER[$h] ?? ''));
        if (preg_match('~^[A-Z]{2}$~', $v) && !in_array($v, ['XX', 'T1', 'ZZ'], true)) return $v;
    }
    return null;
}

/** [ref_host, source-type] — source-type: direct|organic|social|ai|paid|email|referral|internal */
function classify_source(?string $referer, ?string $ownHost, ?string $utmMedium, ?string $utmSource): array
{
    $host = $referer ? strtolower((string)parse_url($referer, PHP_URL_HOST)) : '';
    $host = preg_replace('~^(www|m|l|lm)\.~', '', $host);
    $own = preg_replace('~^www\.~', '', strtolower((string)$ownHost));
    $medium = strtolower((string)$utmMedium);

    if (in_array($medium, ['cpc', 'ppc', 'paid', 'paidsearch', 'display', 'paid_social', 'paidsocial', 'cpm', 'banner'], true)) return [$host ?: null, 'paid'];
    if ($medium === 'email' || $medium === 'newsletter') return [$host ?: null, 'email'];
    if ($host === '' ) return [null, $utmSource ? ($medium === 'social' ? 'social' : 'referral') : 'direct'];
    if ($host === $own) return [null, 'internal'];
    if (preg_match('~(^|\.)(chatgpt\.com|chat\.openai\.com|perplexity\.ai|claude\.ai|gemini\.google\.com|copilot\.microsoft\.com|you\.com|phind\.com)$~', $host)) return [$host, 'ai'];
    if (preg_match('~(^|\.)(google|bing|duckduckgo|yahoo|yandex|baidu|ecosia|startpage|naver|seznam|qwant|brave)\.|search\.brave\.com|^(google|bing|yandex)\.~', $host . '.')) return [$host, 'organic'];
    if (preg_match('~(^|\.)(facebook|instagram|twitter|x|t|reddit|linkedin|youtube|youtu|pinterest|tiktok|discord|telegram|whatsapp|medium|quora|threads|bsky|mastodon|lnkd)\.~', $host . '.')) return [$host, 'social'];
    return [$host, 'referral'];
}

function path_type(string $path): string
{
    if ($path === '/') return 'home';
    if (preg_match('~^/currencies/[^/]+/historical-data~', $path)) return 'history';
    if (strpos($path, '/currencies/') === 0) return 'coin';
    if (strpos($path, '/convert/') === 0) return 'convert';
    if (strpos($path, '/categories/') === 0) return 'category';
    if (strpos($path, '/exchanges/') === 0) return 'exchange';
    if (preg_match('~^/(gainers-losers|trending|new|charts)/~', $path)) return 'list';
    if (strpos($path, '/news/') === 0) return 'news';
    if (preg_match('~^/(login|register|account|forgot|reset|verify|logout)/~', $path)) return 'account';
    if (preg_match('~^/(sitemap|robots)|^/sitemaps/~', $path)) return 'seo-file';
    return 'other';
}

function analytics_skip(string $path): bool
{
    return (bool)preg_match('~^/(admin|api|go|assets)/|^/favicon|\.(css|js|png|jpg|svg|ico|woff2?|map)$~', $path);
}

/** Registered as a shutdown function so the response is never delayed by bookkeeping. */
function track_pageview(float $startedAt): void
{
    try {
        if (!cfg('analytics') || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        $path = '/' . ltrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $path = strtolower($path);
        if (analytics_skip($path)) return;
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();

        $status = http_response_code() ?: 200;
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
        $info = ua_info($ua);
        $ms = (int)round((microtime(true) - $startedAt) * 1000);
        $now = time();
        $row = [
            'ts' => gmdate('Y-m-d H:i:s', $now), 'day' => gmdate('Y-m-d', $now), 'path' => substr($path, 0, 190), 'ptype' => path_type($path),
            'status' => $status, 'vh' => null, 'sid' => null, 'landing' => 0, 'is_new' => 0, 'ref_host' => null, 'src' => null,
            'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'country' => country_code(),
            'device' => $info['device'], 'browser' => $info['browser'], 'os' => $info['os'], 'is_bot' => $info['bot'] ? 1 : 0,
            'bot_name' => $info['bot'], 'ms' => $ms,
        ];

        if (!$info['bot']) {
            $vh = substr(hash('sha256', client_ip() . '|' . $ua . '|' . app_key()), 0, 16);
            $clean = fn($k) => isset($_GET[$k]) && is_string($_GET[$k]) ? substr(preg_replace('~[^\w\-. +%]~u', '', $_GET[$k]), 0, 80) : null;
            $row['utm_source'] = $clean('utm_source'); $row['utm_medium'] = $clean('utm_medium'); $row['utm_campaign'] = $clean('utm_campaign');
            $v = row('SELECT sid, last_ts FROM visitors WHERE vh = ?', [$vh]);
            if ($v && $now - (int)$v['last_ts'] <= 1800) {
                $sid = $v['sid'];
                q('UPDATE visitors SET last_ts = ? WHERE vh = ?', [$now, $vh]);
            } else {
                $sid = bin2hex(random_bytes(8));
                $row['landing'] = 1;
                [$row['ref_host'], $row['src']] = classify_source($_SERVER['HTTP_REFERER'] ?? null, $_SERVER['HTTP_HOST'] ?? '', $row['utm_medium'], $row['utm_source']);
                if ($v) q('UPDATE visitors SET sid = ?, last_ts = ? WHERE vh = ?', [$sid, $now, $vh]);
                else { q('INSERT INTO visitors (vh, sid, first_day, last_ts) VALUES (?,?,?,?)', [$vh, $sid, gmdate('Y-m-d', $now), $now]); $row['is_new'] = 1; }
            }
            $row['vh'] = $vh; $row['sid'] = $sid;
        }
        $cols = array_keys($row);
        q('INSERT INTO pageviews (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
    } catch (Throwable $e) {
        log_msg('error', 'track_pageview: ' . $e->getMessage());
    }
}

/** Current visitor hash for ad frequency caps / affiliate click dedupe. */
function visitor_hash(): string
{
    return substr(hash('sha256', client_ip() . '|' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300) . '|' . app_key()), 0, 16);
}


/** Rebuilds traffic_daily for the last $days days from raw pageviews (idempotent). */
function traffic_rollup(int $days = 3): void
{
    for ($back = 0; $back < $days; $back++) {
        $d = gmdate('Y-m-d', time() - $back * 86400);
        $h = row('SELECT COUNT(*) views, COUNT(DISTINCT vh) visitors, COUNT(DISTINCT sid) sessions, COALESCE(SUM(is_new),0) newv FROM pageviews WHERE day = ? AND is_bot = 0', [$d]);
        $b = row('SELECT COUNT(*) c FROM (SELECT sid FROM pageviews WHERE day = ? AND is_bot = 0 GROUP BY sid HAVING COUNT(*) = 1) t', [$d]);
        $dur = row('SELECT COALESCE(SUM(dur),0) s FROM (SELECT TIMESTAMPDIFF(SECOND, MIN(ts), MAX(ts)) dur FROM pageviews WHERE day = ? AND is_bot = 0 GROUP BY sid) t', [$d]);
        $bots = row("SELECT COUNT(*) a, COALESCE(SUM(bot_name = 'Googlebot'),0) g FROM pageviews WHERE day = ? AND is_bot = 1", [$d]);
        $org = (int)val("SELECT COUNT(DISTINCT sid) FROM pageviews WHERE day = ? AND is_bot = 0 AND landing = 1 AND src = 'organic'", [$d]);
        q('REPLACE INTO traffic_daily (day, views, visitors, sessions, bounces, duration_sum, new_visitors, bot_views, googlebot, organic_sessions) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$d, (int)$h['views'], (int)$h['visitors'], (int)$h['sessions'], (int)$b['c'], (int)$dur['s'], (int)$h['newv'], (int)$bots['a'], (int)$bots['g'], $org]);
    }
}

/** Admin pages call this so figures for today are fresh without waiting for cron (at most once per 90s). */
function traffic_rollup_fresh(): void
{
    try {
        if (time() - (int)kv_get('rollup_ts', 0) < 90) return;
        kv_set('rollup_ts', time());
        traffic_rollup(2);
    } catch (Throwable $e) { log_msg('error', 'rollup: ' . $e->getMessage()); }
}

/** Daily traffic series for a date range, zero-filled. Returns [labels[], rows[day=>row]]. */
function traffic_series(string $from, string $to): array
{
    $m = [];
    foreach (rows('SELECT * FROM traffic_daily WHERE day BETWEEN ? AND ?', [$from, $to]) as $r) $m[$r['day']] = $r;
    $labels = []; $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = gmdate('Y-m-d', $t); $labels[] = $d;
        $out[$d] = $m[$d] ?? ['day' => $d, 'views' => 0, 'visitors' => 0, 'sessions' => 0, 'bounces' => 0, 'duration_sum' => 0, 'new_visitors' => 0, 'bot_views' => 0, 'googlebot' => 0, 'organic_sessions' => 0];
    }
    return [$labels, $out];
}
