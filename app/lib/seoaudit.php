<?php
/** Technical SEO self-audit: fetches a sample of the site's own URLs and checks what search engines care about. */

function site_fetch(string $path, int $timeout = 8, bool $head = false): array
{
    $base = rtrim((string)setting('self_url', cfg('site_url')), '/');
    $url = preg_match('~^https?://~', $path) ? $path : $base . $path;
    $t0 = microtime(true);
    $ch = curl_init($url);
    $hdr = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_ENCODING => '', CURLOPT_NOBODY => $head,
        CURLOPT_USERAGENT => 'CoinMC-SiteAudit/1.0', CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdr) { if (strpos($l, ':') !== false) { [$k, $v] = explode(':', $l, 2); $hdr[strtolower(trim($k))] = trim($v); } return strlen($l); }]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['url' => $url, 'code' => $code, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'body' => (string)$body, 'headers' => $hdr, 'err' => $err];
}

/** Parses the SEO-relevant tags of an HTML document. */
function seo_parse_html(string $html): array
{
    $o = ['title' => null, 'description' => null, 'canonical' => null, 'robots' => null, 'h1' => 0, 'ld' => 0, 'og_image' => null, 'imgs_no_alt' => 0, 'links' => 0, 'words' => 0];
    if ($html === '') return $o;
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($d);
    $o['title'] = ($n = $x->query('//title')->item(0)) ? trim($n->textContent) : null;
    foreach ($x->query('//meta') as $m) {
        $nm = strtolower($m->getAttribute('name')); $pr = strtolower($m->getAttribute('property'));
        if ($nm === 'description') $o['description'] = trim($m->getAttribute('content'));
        if ($nm === 'robots') $o['robots'] = strtolower(trim($m->getAttribute('content')));
        if ($pr === 'og:image') $o['og_image'] = $m->getAttribute('content');
    }
    foreach ($x->query('//link[@rel="canonical"]') as $l) $o['canonical'] = $l->getAttribute('href');
    $o['h1'] = $x->query('//h1')->length;
    $o['ld'] = $x->query('//script[@type="application/ld+json"]')->length;
    foreach ($x->query('//img') as $i) if (!$i->hasAttribute('alt')) $o['imgs_no_alt']++;
    $o['links'] = $x->query('//a[@href]')->length;
    $main = $x->query('//main')->item(0);
    $o['words'] = str_word_count(strip_tags($main ? $d->saveHTML($main) : $html));
    return $o;
}

function seo_audit_sample(): array
{
    $u = ['/', '/categories/', '/exchanges/', '/gainers-losers/', '/trending/', '/convert/', '/charts/', '/about/', '/methodology/'];
    if ((int)val("SELECT COUNT(*) FROM articles WHERE status = 'published' AND (publish_at IS NULL OR publish_at <= UTC_TIMESTAMP())")) $u[] = '/news/';
    foreach (rows("SELECT slug FROM coins WHERE status = 'active' AND price IS NOT NULL ORDER BY rank_no IS NULL, rank_no LIMIT 12") as $c) $u[] = '/currencies/' . $c['slug'] . '/';
    foreach (rows("SELECT c.slug FROM coins c JOIN coin_history_daily h ON h.coin_id = c.id WHERE c.status = 'active' GROUP BY c.id ORDER BY c.rank_no IS NULL, c.rank_no LIMIT 3") as $c) $u[] = '/currencies/' . $c['slug'] . '/historical-data/';
    foreach (rows("SELECT symbol FROM coins WHERE status = 'active' AND rank_no <= 10 ORDER BY rank_no LIMIT 3") as $c) $u[] = '/convert/' . strtolower($c['symbol']) . '/usd/';
    foreach (rows("SELECT slug FROM exchanges WHERE status = 'active' ORDER BY trust_rank IS NULL, trust_rank LIMIT 3") as $x) $u[] = '/exchanges/' . $x['slug'] . '/';
    foreach (rows('SELECT slug FROM categories ORDER BY market_cap DESC LIMIT 2') as $x) $u[] = '/categories/' . $x['slug'] . '/';
    foreach (rows("SELECT slug FROM articles WHERE status = 'published' AND (publish_at IS NULL OR publish_at <= UTC_TIMESTAMP()) ORDER BY id DESC LIMIT 3") as $x) $u[] = '/news/' . $x['slug'] . '/';
    return array_values(array_unique($u));
}

/** Returns ['at'=>, 'pages'=>[...], 'site'=>[...], 'summary'=>[critical, warning, ok]] and stores it in kv. */
function seo_audit_run(int $budgetSeconds = 70): array
{
    $t0 = time();
    $pages = []; $titles = []; $descs = [];
    foreach (seo_audit_sample() as $path) {
        if (time() - $t0 > $budgetSeconds) break;
        $r = site_fetch($path, 8);
        $p = seo_parse_html($r['body']);
        $iss = [];
        $add = function ($sev, $msg) use (&$iss) { $iss[] = [$sev, $msg]; };
        if ($r['code'] === 0) $add('critical', 'Unreachable: ' . ($r['err'] ?: 'no response'));
        elseif ($r['code'] >= 500) $add('critical', 'Server error ' . $r['code']);
        elseif ($r['code'] >= 400) $add('critical', 'Returns ' . $r['code']);
        elseif ($r['code'] >= 300) $add('warning', 'Redirects (' . $r['code'] . ') — sitemap/internal links should point at the final URL');
        if ($r['code'] === 200) {
            $tl = mb_strlen((string)$p['title']);
            if (!$p['title']) $add('critical', 'Missing <title>'); elseif ($tl > 65) $add('warning', "Title is $tl chars (may truncate over ~60)"); elseif ($tl < 20) $add('warning', "Title is short ($tl chars)");
            $dl = mb_strlen((string)$p['description']);
            if (!$p['description']) $add('critical', 'Missing meta description'); elseif ($dl > 165) $add('warning', "Description is $dl chars (truncates over ~160)"); elseif ($dl < 70) $add('warning', "Description is short ($dl chars)");
            if (!$p['canonical']) $add('critical', 'Missing canonical link');
            elseif (rtrim((string)parse_url($p['canonical'], PHP_URL_PATH), '/') !== rtrim($path, '/') && strpos($path, '?') === false && $path !== '/') $add('warning', 'Canonical points elsewhere: ' . $p['canonical']);
            if ($p['robots'] && strpos($p['robots'], 'noindex') !== false) $add('warning', 'Page is noindex');
            if ($p['h1'] !== 1) $add($p['h1'] === 0 ? 'critical' : 'warning', $p['h1'] === 0 ? 'No <h1>' : "{$p['h1']} <h1> tags");
            if (!$p['ld']) $add('warning', 'No structured data (JSON-LD)');
            if (!$p['og_image']) $add('warning', 'No og:image for social sharing');
            if ($p['imgs_no_alt']) $add('warning', $p['imgs_no_alt'] . ' image(s) without alt attribute');
            if ($p['words'] < 120 && !preg_match('~^/(search|watchlist)~', $path)) $add('warning', 'Thin content (' . $p['words'] . ' words)');
            if ($r['ms'] > 1500) $add('warning', 'Slow response (' . $r['ms'] . ' ms)');
            if ($p['title']) $titles[$p['title']][] = $path;
            if ($p['description']) $descs[$p['description']][] = $path;
        }
        $pages[] = ['path' => $path, 'code' => $r['code'], 'ms' => $r['ms'], 'title' => $p['title'], 'issues' => $iss, 'cache' => $r['headers']['x-cache'] ?? ''];
    }
    foreach ($pages as &$pg) {
        if ($pg['title'] && count($titles[$pg['title']] ?? []) > 1) $pg['issues'][] = ['warning', 'Duplicate title shared with ' . (count($titles[$pg['title']]) - 1) . ' other sampled page(s)'];
    }
    unset($pg);

    // site-wide checks
    $site = [];
    $rb = site_fetch('/robots.txt', 6);
    $site[] = ['robots.txt reachable', $rb['code'] === 200 ? 'ok' : 'critical', "HTTP {$rb['code']}"];
    $site[] = ['robots.txt references the sitemap', stripos($rb['body'], 'sitemap:') !== false ? 'ok' : 'warning', ''];
    $site[] = ['robots.txt does not block the whole site', !preg_match('~^\s*Disallow:\s*/\s*$~mi', $rb['body']) ? 'ok' : 'critical', preg_match('~^\s*Disallow:\s*/\s*$~mi', $rb['body']) ? '“Disallow: /” found!' : ''];
    $sm = site_fetch('/sitemap.xml', 8);
    $okXml = $sm['code'] === 200 && @simplexml_load_string($sm['body']) !== false;
    $site[] = ['sitemap.xml valid', $okXml ? 'ok' : 'critical', "HTTP {$sm['code']}"];
    if ($okXml) {
        $x = @simplexml_load_string($sm['body']); $bad = 0; $n = 0;
        foreach ($x->sitemap ?? [] as $s) { if (time() - $t0 > $budgetSeconds + 15 || $n++ > 12) break; $c = site_fetch((string)parse_url((string)$s->loc, PHP_URL_PATH), 10); if ($c['code'] !== 200) $bad++; }
        $site[] = ['Child sitemaps respond 200', $bad ? 'critical' : 'ok', $bad ? "$bad failing" : "$n checked"];
    }
    $nf = site_fetch('/this-page-should-not-exist-' . substr(md5((string)time()), 0, 6) . '/', 6, true);
    $site[] = ['Unknown URLs return a real 404', $nf['code'] === 404 ? 'ok' : 'critical', "HTTP {$nf['code']}"];
    $tr = site_fetch('/currencies/bitcoin', 6, true);
    $site[] = ['URLs without trailing slash 301 to the canonical', $tr['code'] === 301 || $tr['code'] === 404 ? 'ok' : 'warning', "HTTP {$tr['code']}"];
    $up = site_fetch('/Currencies/Bitcoin/', 6, true);
    $site[] = ['Uppercase URLs 301 to lowercase', $up['code'] === 301 || $up['code'] === 404 ? 'ok' : 'warning', "HTTP {$up['code']}"];
    $site[] = ['Site URL uses HTTPS', stripos((string)cfg('site_url'), 'https://') === 0 ? 'ok' : 'warning', (string)cfg('site_url')];
    $site[] = ['GSC verification tag or Search Console API configured', (setting('gsc_verification') || Google::configured('gsc')) ? 'ok' : 'warning', ''];
    $site[] = ['Gzip/Brotli compression', !empty($sm['headers']['content-encoding']) ? 'ok' : 'warning', $sm['headers']['content-encoding'] ?? 'none detected'];

    $sum = ['critical' => 0, 'warning' => 0, 'ok' => 0];
    foreach ($pages as $pg) { foreach ($pg['issues'] as [$s]) $sum[$s]++; if (!$pg['issues']) $sum['ok']++; }
    foreach ($site as [, $s]) $sum[$s]++;
    $res = ['at' => gmdate('Y-m-d H:i:s'), 'pages' => $pages, 'site' => $site, 'summary' => $sum, 'base' => (string)setting('self_url', cfg('site_url'))];
    kv_set('seo_audit', json_encode($res));
    return $res;
}
