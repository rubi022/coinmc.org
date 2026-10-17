<?php

/**
 * Minimal JSON HTTP client with retries and 429 back-off.
 * Returns decoded JSON or null on failure (error is logged).
 */
if (!function_exists('http_json')) {
function http_json(string $url, array $headers = [], int $retries = 2, int $timeout = 25)
{
    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'CoinMC/1.0 (+' . base_url() . ')',
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        if ($body !== false && $code >= 200 && $code < 300) {
            $j = json_decode($body, true);
            if ($j !== null) { api_log_record($url, true, $code, $ms, null); return $j; }
            $err = 'invalid JSON';
        }
        api_log_record($url, false, $code, $ms, $err ?: substr((string)$body, 0, 120));
        $short = preg_replace('~\?.*$~', '', $url);
        log_msg('http', "HTTP $code $short " . ($err ?: substr((string)$body, 0, 160)));

        if ($code === 429) { sleep(min(65, 20 * ($attempt + 1))); continue; }
        if (in_array($code, [400, 401, 403, 404, 451], true)) return null; // not retryable
        if ($attempt < $retries) sleep(2 * ($attempt + 1));
    }
    return null;
}
}

/** Records every outbound data-provider call so the admin panel can show API health (success rate, latency, errors). */
function api_log_record(string $url, bool $ok, int $status, int $ms, ?string $err): void
{
    try {
        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '' || !function_exists('q')) return;
        q('INSERT INTO api_log (ts, host, ok, status, ms, err) VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?)', [substr($host, 0, 80), $ok ? 1 : 0, $status ?: null, $ms, $err ? substr($err, 0, 160) : null]);
    } catch (Throwable $e) { /* never let monitoring break a sync */ }
}
