<?php
/** Public CMS (news), outbound redirects (affiliate + ad clicks), ad serving API and Core Web Vitals beacon. */

const ARTICLE_LIVE = "status = 'published' AND (publish_at IS NULL OR publish_at <= UTC_TIMESTAMP())";

/** Allow-list HTML sanitiser for CMS bodies (DOM based; no regex tricks). */
function html_sanitize(?string $html): string
{
    if ($html === null || trim($html) === '') return '';
    $allowed = ['p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'ul' => [], 'ol' => [], 'li' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'blockquote' => [], 'a' => ['href'], 'img' => ['src', 'alt'], 'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [], 'code' => [], 'pre' => [], 'hr' => []];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('__root');
    if (!$root) return '';
    $walk = function (DOMNode $n) use (&$walk, $allowed) {
        foreach (iterator_to_array($n->childNodes) as $ch) {
            if ($ch->nodeType === XML_COMMENT_NODE || $ch->nodeType === XML_PI_NODE) { $n->removeChild($ch); continue; }
            if ($ch->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($ch->nodeName);
            if (!isset($allowed[$tag])) {
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'noscript', 'svg', 'math', 'template'], true)) { $n->removeChild($ch); continue; }
                $walk($ch);
                while ($ch->firstChild) $n->insertBefore($ch->firstChild, $ch);
                $n->removeChild($ch);
                continue;
            }
            foreach (iterator_to_array($ch->attributes) as $a) if (!in_array(strtolower($a->name), $allowed[$tag], true)) $ch->removeAttribute($a->name);
            if ($tag === 'a') {
                $h = trim($ch->getAttribute('href'));
                if (!preg_match('~^(https?://|/(?!/)|mailto:)~i', $h)) $ch->removeAttribute('href');
                elseif (preg_match('~^https?://~i', $h)) { $ch->setAttribute('rel', 'nofollow noopener'); }
            }
            if ($tag === 'img') {
                $s = trim($ch->getAttribute('src'));
                if (!preg_match('~^(https?://|/(?!/))~i', $s)) { $n->removeChild($ch); continue; }
                $ch->setAttribute('loading', 'lazy');
            }
            $walk($ch);
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}

function article_url(array $a): string { return path_norm('/news/' . $a['slug']); }

function ctl_news(): void
{
    $page = cur_page(); $per = 12;
    $total = (int)val('SELECT COUNT(*) FROM articles WHERE ' . ARTICLE_LIVE);
    $items = rows('SELECT id, slug, title, excerpt, category, image_url, publish_at, created_at, sponsored, sponsor_name FROM articles WHERE ' . ARTICLE_LIVE
        . ' ORDER BY COALESCE(publish_at, created_at) DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    $site = cfg('site_name');
    echo page('news', ['mode' => 'index', 'items' => $items, 'page' => $page, 'pages' => (int)ceil($total / $per)], [
        'title' => "Crypto News, Guides and Market Analysis | $site", 'description' => "Latest cryptocurrency news, explainers and market analysis from $site.",
        'canonical' => $page > 1 ? "/news/?page=$page" : '/news/',
        'ld' => [ld_breadcrumbs(['News' => '/news/'])],
    ]);
}

function ctl_article(string $slug): void
{
    $a = row('SELECT a.*, (SELECT name FROM users WHERE id = a.author_id) author_name FROM articles a WHERE slug = ? AND ' . ARTICLE_LIVE, [$slug]);
    if (!$a) { not_found('We could not find that article.'); return; }
    q('UPDATE articles SET views = views + 1 WHERE id = ?', [$a['id']]);
    $site = cfg('site_name');
    $pub = gmdate('c', strtotime(($a['publish_at'] ?: $a['created_at']) . ' UTC'));
    $mod = gmdate('c', strtotime(($a['updated_at'] ?: $a['publish_at'] ?: $a['created_at']) . ' UTC'));
    $ld = ['@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => mb_substr($a['title'], 0, 110), 'datePublished' => $pub, 'dateModified' => $mod,
        'mainEntityOfPage' => abs_url(article_url($a)), 'author' => ['@type' => 'Person', 'name' => $a['author_name'] ?: $site . ' Editorial'],
        'publisher' => ['@type' => 'Organization', 'name' => $site, 'logo' => ['@type' => 'ImageObject', 'url' => abs_url('/assets/img/logo.svg')]]];
    if ($a['image_url']) $ld['image'] = [$a['image_url']];
    $related = rows('SELECT slug, title, publish_at, created_at FROM articles WHERE id <> ? AND ' . ARTICLE_LIVE . ' ORDER BY COALESCE(publish_at, created_at) DESC LIMIT 5', [$a['id']]);
    echo page('news', ['mode' => 'article', 'a' => $a, 'related' => $related], [
        'title' => ($a['meta_title'] ?: $a['title']) . " | $site",
        'description' => $a['meta_description'] ?: ($a['excerpt'] ?: mb_substr(trim(strip_tags($a['body'])), 0, 160)),
        'canonical' => article_url($a), 'og_image' => $a['image_url'] ?: null,
        'robots' => $a['sponsored'] ? 'index,follow' : null,
        'ld' => [$ld, ld_breadcrumbs(['News' => '/news/', $a['title'] => article_url($a)])],
    ]);
}

/** /go/ad/{id}/?s=slot — logs the click (with invalid-click guard) then redirects. Never cached, never indexed. */
function ctl_go_ad(string $id): void
{
    header('X-Robots-Tag: noindex, nofollow');
    $c = row('SELECT * FROM ad_campaigns WHERE id = ?', [(int)$id]);
    $url = $c ? safe_url($c['target_url']) : null;
    if (!$c || !$url) { not_found(); return; }
    $slot = preg_match('~^[a-z_]{1,30}$~', $_GET['s'] ?? '') ? $_GET['s'] : '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $vh = visitor_hash();
    $valid = !ua_bot($ua) && in_array($c['status'], ['approved', 'paused'], true);
    try {
        if ($valid) {
            // invalid-click guard: >3 clicks per visitor per campaign per day are counted separately and never billed
            $n = (int)val('SELECT clicks FROM ad_freq WHERE vh = ? AND campaign_id = ? AND day = UTC_DATE()', [$vh, $c['id']]);
            if ($n >= 3) $valid = false;
            q('INSERT INTO ad_freq (vh, campaign_id, day, n, clicks) VALUES (?, ?, UTC_DATE(), 0, 1) ON DUPLICATE KEY UPDATE clicks = clicks + 1', [$vh, $c['id']]);
        }
        $col = $valid ? 'clicks' : 'invalid_clicks';
        q("INSERT INTO ad_stats (campaign_id, day, placement, $col) VALUES (?, UTC_DATE(), ?, 1) ON DUPLICATE KEY UPDATE $col = $col + 1", [$c['id'], $slot]);
        if ($valid && $c['pricing'] === 'cpc' && (float)$c['rate'] > 0) {
            q('UPDATE ad_campaigns SET spent = spent + ? WHERE id = ?', [(float)$c['rate'], $c['id']]);
            ad_end_if_exhausted((int)$c['id']);
        }
    } catch (Throwable $e) { log_msg('error', 'ad click: ' . $e->getMessage()); }
    header('Cache-Control: private, no-store');
    header('Location: ' . $url, true, 302);
}

/** /go/{slug}/ — affiliate redirect with click logging. */
function ctl_go_aff(string $slug): void
{
    header('X-Robots-Tag: noindex, nofollow');
    $l = row('SELECT * FROM affiliate_links WHERE slug = ? AND enabled = 1', [$slug]);
    $url = $l ? safe_url($l['target_url']) : null;
    if (!$l || !$url) { not_found(); return; }
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!ua_bot($ua)) {
        try {
            $ref = (string)parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH);
            q('INSERT INTO affiliate_clicks (link_id, ts, day, from_path, country, device, vh) VALUES (?, UTC_TIMESTAMP(), UTC_DATE(), ?, ?, ?, ?)',
                [$l['id'], mb_substr($ref, 0, 190) ?: null, country_code(), ua_info($ua)['device'], visitor_hash()]);
        } catch (Throwable $e) { log_msg('error', 'aff click: ' . $e->getMessage()); }
    }
    header('Cache-Control: private, no-store');
    header('Location: ' . $url, true, 302);
}

/** Client-side ad loader endpoint: returns rendered HTML for a slot (or empty). Counts one impression per fill. */
function api_ad(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $slot = (string)($_GET['slot'] ?? '');
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!isset(ad_slots()[$slot]) || setting('ads_enabled', '1') !== '1' || ua_bot($ua)) { echo '{"html":""}'; return; }
    try {
        $info = ua_info($ua); $vh = visitor_hash();
        $c = ad_pick($slot, $info['device'], country_code(), $vh);
        ad_record_request($slot, (bool)$c);
        if ($c) { ad_record_impression($c, $slot, $vh); echo json_encode(['html' => ad_render($c, $slot)]); return; }
        $fb = setting('ad_fallback_' . $slot, '');
        echo json_encode(['html' => $fb !== '' ? '<div class="ad ad-network"><span class="ad-tag">Advertisement</span>' . $fb . '</div>' : '']);
    } catch (Throwable $e) { echo '{"html":""}'; }
}

/** Core Web Vitals beacon (sendBeacon JSON). Sampled, validated, tiny. */
function api_vitals(): void
{
    http_response_code(204);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $d = json_decode((string)file_get_contents('php://input', false, null, 0, 2000), true);
    if (!is_array($d) || !isset($d['m'], $d['v'])) return;
    $m = strtoupper((string)$d['m']);
    if (!in_array($m, ['LCP', 'CLS', 'INP', 'TTFB', 'FCP'], true) || !is_numeric($d['v']) || $d['v'] < 0 || $d['v'] > 600000) return;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (ua_bot($ua)) return;
    $p = strtolower((string)parse_url((string)($d['p'] ?? '/'), PHP_URL_PATH)) ?: '/';
    try {
        q('INSERT INTO cwv (ts, path, ptype, metric, value, device) VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?)', [mb_substr($p, 0, 190), path_type($p), $m, (float)$d['v'], ua_info($ua)['device']]);
    } catch (Throwable $e) {}
}
