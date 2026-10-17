<?php
/** RFC 6238 TOTP (Google Authenticator / 1Password / Authy compatible), no dependencies. */

const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function b32_encode(string $bin): string
{
    $bits = ''; foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = ''; foreach (str_split($bits, 5) as $chunk) $out .= B32[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function b32_decode(string $s): string
{
    $s = strtoupper(preg_replace('~[^A-Z2-7]~', '', $s));
    $bits = ''; foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos(B32, $c)), 5, '0', STR_PAD_LEFT);
    $out = ''; foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_new_secret(): string { return b32_encode(random_bytes(20)); }

function totp_code(string $secretB32, ?int $time = null, int $step = 30): string
{
    $counter = intdiv($time ?? time(), $step);
    $hash = hash_hmac('sha1', pack('N*', 0, $counter), b32_decode($secretB32), true);
    $o = ord($hash[19]) & 0xf;
    $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $secretB32, string $code, int $window = 1): bool
{
    $code = preg_replace('~\s+~', '', $code);
    if (!preg_match('~^\d{6}$~', $code)) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secretB32, $now + $i * 30), $code)) return true;
    }
    return false;
}

function totp_uri(string $secret, string $email): string
{
    $issuer = cfg('site_name');
    return 'otpauth://totp/' . rawurlencode("$issuer:$email") . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
