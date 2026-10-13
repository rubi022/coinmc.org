<?php

/**
 * Minimal JSON HTTP client with retries and 429 back-off.
 * Returns decoded JSON or null on failure (error is logged).
 */
if (!function_exists('http_json')) {
function http_json(string $url, array $headers = [], int $retries = 2, int $timeout = 25)
{
    for ($attempt = 0; $attempt <= $retries; $attempt++) {
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

        if ($body !== false && $code >= 200 && $code < 300) {
            $j = json_decode($body, true);
            if ($j !== null) return $j;
            $err = 'invalid JSON';
        }
        $short = preg_replace('~\?.*$~', '', $url);
        log_msg('http', "HTTP $code $short " . ($err ?: substr((string)$body, 0, 160)));

        if ($code === 429) { sleep(min(65, 20 * ($attempt + 1))); continue; }
        if (in_array($code, [400, 401, 403, 404, 451], true)) return null; // not retryable
        if ($attempt < $retries) sleep(2 * ($attempt + 1));
    }
    return null;
}
}
