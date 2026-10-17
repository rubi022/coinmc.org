<?php
/**
 * Outgoing mail. Uses SMTP when config['smtp']['host'] is set (recommended — Mailgun, SES, Postmark, Gmail…),
 * otherwise PHP's mail(). Message bodies are only logged when debug is on (they contain one-time links).
 */
/** SMTP/from settings: Admin → Settings → Email overrides config.php. */
function mail_config(): array
{
    $c = cfg('smtp') ?: [];
    if (setting('smtp_host')) $c = ['host' => setting('smtp_host'), 'port' => (int)setting('smtp_port', 587), 'user' => (string)setting('smtp_user', ''),
        'pass' => (string)(secret_decrypt(setting('smtp_pass')) ?? ''), 'secure' => setting('smtp_secure', 'tls')];
    $c['from'] = setting('mail_from') ?: cfg('mail_from');
    return $c;
}

function send_mail(string $to, string $subject, string $text): bool
{
    $to = trim(str_replace(["\r", "\n"], '', $to));
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    $host = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
    $mc = mail_config();
    $from = $mc['from'] ?: 'noreply@' . $host;
    $fromName = str_replace(['"', "\r", "\n"], '', cfg('site_name'));
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = "From: \"$fromName\" <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nX-Mailer: CoinMC";

    $ok = false;
    $smtp = $mc;
    if (!empty($smtp['host'])) $ok = smtp_send($smtp, $from, $to, $encSubject, $headers, $text);
    elseif (function_exists('mail')) $ok = @mail($to, $encSubject, $text, $headers);

    log_msg('mail', ($ok ? 'sent' : 'FAILED') . " to=$to subject=\"$subject\"" . (cfg('debug') ? "\n$text\n" : ''));
    return $ok;
}

function smtp_send(array $c, string $from, string $to, string $encSubject, string $headers, string $text): bool
{
    $secure = $c['secure'] ?? 'tls'; // ssl (465) | tls (587 STARTTLS) | none
    $port = (int)($c['port'] ?? ($secure === 'ssl' ? 465 : 587));
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $port, $en, $es, 15);
    if (!$fp) { log_msg('mail', "smtp connect failed: $es"); return false; }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $out = '';
        while (($l = fgets($fp, 1024)) !== false) { $out .= $l; if (isset($l[3]) && $l[3] === ' ') break; }
        return $out;
    };
    $send = function (?string $line, string $expect) use ($fp, $read) {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $r = $read();
        return strpos($r, $expect) === 0 ? $r : false;
    };

    $ehloHost = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
    if (!$send(null, '220') || !$send("EHLO $ehloHost", '250')) return false;
    if ($secure === 'tls') {
        if (!$send('STARTTLS', '220') || !stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return false;
        if (!$send("EHLO $ehloHost", '250')) return false;
    }
    if (!empty($c['user'])) {
        if (!$send('AUTH LOGIN', '334') || !$send(base64_encode($c['user']), '334') || !$send(base64_encode((string)($c['pass'] ?? '')), '235')) {
            log_msg('mail', 'smtp auth failed'); return false;
        }
    }
    $fromAddr = preg_match('~<([^>]+)>~', $from, $m) ? $m[1] : $from;
    if (!$send("MAIL FROM:<$fromAddr>", '250') || !$send("RCPT TO:<$to>", '25')) return false;
    if (!$send('DATA', '354')) return false;
    $body = "To: <$to>\r\nSubject: $encSubject\r\nDate: " . date('r') . "\r\n$headers\r\n\r\n" . preg_replace('~^\.~m', '..', str_replace(["\r\n", "\r"], "\n", $text));
    $body = str_replace("\n", "\r\n", $body);
    fwrite($fp, $body . "\r\n.\r\n");
    $ok = strpos($read(), '250') === 0;
    $send('QUIT', '221');
    fclose($fp);
    return $ok;
}
