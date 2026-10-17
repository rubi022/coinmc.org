<?php
/**
 * Google Search Console + GA4 Data API via a service account (JWT bearer). No SDK required.
 * Setup: create a service account, enable "Search Console API" and "Google Analytics Data API", add the account's
 * email as a user on the GSC property and the GA4 property, then paste its JSON key in Admin → Settings → Integrations.
 */
class Google
{
    public static function configured(string $what): bool
    {
        if (!self::sa()) return false;
        return $what === 'gsc' ? (bool)setting('gsc_site') : (bool)setting('ga4_property');
    }

    private static function sa(): ?array
    {
        $j = secret_decrypt(setting('google_sa_json'));
        $a = $j ? json_decode($j, true) : null;
        return (is_array($a) && !empty($a['client_email']) && !empty($a['private_key'])) ? $a : null;
    }

    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    public static function token(string $scope): ?string
    {
        $cacheKey = 'gtok_' . md5($scope);
        $c = json_decode((string)kv_get($cacheKey, ''), true);
        if (is_array($c) && ($c['exp'] ?? 0) > time() + 60) return $c['t'];
        $sa = self::sa();
        if (!$sa) return null;
        $url = cfg('google_oauth_url', $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $now = time();
        $head = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64(json_encode(['iss' => $sa['client_email'], 'scope' => $scope, 'aud' => $url, 'iat' => $now, 'exp' => $now + 3600]));
        if (!openssl_sign("$head.$claims", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) { log_msg('http', 'google: bad private key'); return null; }
        $r = self::post($url, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$head.$claims.".self::b64($sig)]));
        if (empty($r['access_token'])) return null;
        kv_set($cacheKey, json_encode(['t' => $r['access_token'], 'exp' => $now + (int)($r['expires_in'] ?? 3000)]));
        return $r['access_token'];
    }

    public static function post(string $url, array $headers, string $body): ?array
    {
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)]);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ok = $out !== false && $code >= 200 && $code < 300;
        api_log_record($url, $ok, $code, (int)round((microtime(true) - $t0) * 1000), $ok ? null : ($err ?: substr((string)$out, 0, 140)));
        if (!$ok) { log_msg('http', "POST $code " . preg_replace('~\?.*$~', '', $url) . ' ' . ($err ?: substr((string)$out, 0, 200))); return null; }
        $j = json_decode((string)$out, true);
        return is_array($j) ? $j : null;
    }

    /** Search Console searchAnalytics.query → rows. */
    public static function gscQuery(array $body): ?array
    {
        $t = self::token('https://www.googleapis.com/auth/webmasters.readonly');
        if (!$t) return null;
        $base = cfg('gsc_api_base', 'https://searchconsole.googleapis.com');
        $r = self::post($base . '/webmasters/v3/sites/' . rawurlencode((string)setting('gsc_site')) . '/searchAnalytics/query',
            ['Authorization: Bearer ' . $t, 'Content-Type: application/json'], json_encode($body));
        return $r === null ? null : ($r['rows'] ?? []);
    }

    /** GA4 runReport → rows. */
    public static function ga4Report(array $body): ?array
    {
        $t = self::token('https://www.googleapis.com/auth/analytics.readonly');
        if (!$t) return null;
        $base = cfg('ga4_api_base', 'https://analyticsdata.googleapis.com');
        $r = self::post($base . '/v1beta/properties/' . rawurlencode((string)setting('ga4_property')) . ':runReport',
            ['Authorization: Bearer ' . $t, 'Content-Type: application/json'], json_encode($body));
        return $r === null ? null : ($r['rows'] ?? []);
    }
}
