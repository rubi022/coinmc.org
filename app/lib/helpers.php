<?php

function e($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function base_url(): string { return rtrim((string)cfg('site_url'), '/'); }

/** Absolute URL for canonical / sitemaps. Paths always end with "/" unless they contain a dot. */
function abs_url(string $path = '/'): string { return base_url() . path_norm($path); }

function path_norm(string $path): string
{
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;
    $q = '';
    if (($p = strpos($path, '?')) !== false) { $q = substr($path, $p); $path = substr($path, 0, $p); }
    if (substr($path, -1) !== '/' && strpos(basename($path), '.') === false) $path .= '/';
    return $path . $q;
}

function coin_url(array $c): string { return path_norm('/currencies/' . $c['slug']); }
function exchange_url(array $x): string { return path_norm('/exchanges/' . $x['slug']); }
function category_url(array $c): string { return path_norm('/categories/' . $c['slug']); }
function convert_url(string $from, string $to): string { return path_norm('/convert/' . strtolower($from) . '/' . strtolower($to)); }

function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    return trim($s, '-') ?: 'n-a';
}

function num($v): ?float { return ($v === null || $v === '') ? null : (float)$v; }

/** Price with sensible precision for anything from $0.00000012 to $100,000. */
function fmt_price($v, string $sign = '$'): string
{
    $v = num($v);
    if ($v === null) return '—';
    $a = abs($v);
    if ($a == 0) $d = 2;
    elseif ($a >= 1000) $d = 2;
    elseif ($a >= 1) $d = ($a >= 100 ? 2 : 4);
    elseif ($a >= 0.01) $d = 4;
    else $d = min(12, (int)ceil(-log10($a)) + 3);
    $s = number_format($v, $d, '.', ',');
    if ($a >= 1 && $a < 100) $s = preg_replace('~(\.\d\d)0+$~', '$1', $s);
    return $sign . $s;
}

function fmt_usd($v, int $dec = 0): string
{
    $v = num($v);
    return $v === null ? '—' : '$' . number_format($v, $dec, '.', ',');
}

/** Abbreviated: $1.23T / $456.7B / $12.3M / $45.6K */
function fmt_big($v, string $sign = '$'): string
{
    $v = num($v);
    if ($v === null) return '—';
    $a = abs($v);
    foreach ([[1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'K']] as [$n, $u]) {
        if ($a >= $n) return $sign . number_format($v / $n, 2) . $u;
    }
    return $sign . number_format($v, 2);
}

function fmt_num($v, int $dec = 0): string
{
    $v = num($v);
    return $v === null ? '—' : number_format($v, $dec, '.', ',');
}

function fmt_supply($v, string $sym = ''): string
{
    $v = num($v);
    if ($v === null || $v <= 0) return '—';
    return number_format($v, 0) . ($sym ? ' ' . e(strtoupper($sym)) : '');
}

function pct_raw($v, int $dec = 2): string
{
    $v = num($v);
    return $v === null ? '—' : number_format(abs($v), $dec) . '%';
}

function pct($v, int $dec = 2): string
{
    $v = num($v);
    if ($v === null) return '<span class="pct">—</span>';
    $cls = $v >= 0 ? 'up' : 'down';
    return '<span class="pct ' . $cls . '"><i class="tri"></i>' . number_format(abs($v), $dec) . '%</span>';
}

function up_down($v, string $up = 'up', string $down = 'down'): string { return (num($v) ?? 0) >= 0 ? $up : $down; }

function fmt_date($v, string $f = 'M j, Y'): string { return $v ? gmdate($f, is_numeric($v) ? (int)$v : strtotime($v . ' UTC')) : '—'; }

function time_ago($dt): string
{
    if (!$dt) return 'never';
    $s = time() - strtotime($dt . ' UTC');
    if ($s < 60) return $s . 's ago';
    if ($s < 3600) return floor($s / 60) . 'm ago';
    if ($s < 86400) return floor($s / 3600) . 'h ago';
    return floor($s / 86400) . 'd ago';
}

/** Server-rendered SVG sparkline — zero JS, crawlable, fast. */
function sparkline_svg(?string $json, ?float $change = null, int $w = 135, int $h = 40): string
{
    $pts = $json ? json_decode($json, true) : null;
    if (!is_array($pts) || count($pts) < 2) return '';
    $step = max(1, (int)floor(count($pts) / 60));
    $p = [];
    for ($i = 0; $i < count($pts); $i += $step) $p[] = (float)$pts[$i];
    $p[] = (float)end($pts);
    $min = min($p); $max = max($p); $rng = ($max - $min) ?: 1;
    $n = count($p) - 1;
    $d = '';
    foreach ($p as $i => $v) {
        $x = round($i / $n * $w, 1);
        $y = round($h - 2 - (($v - $min) / $rng) * ($h - 4), 1);
        $d .= ($i ? 'L' : 'M') . $x . ' ' . $y;
    }
    $up = $change !== null ? $change >= 0 : end($p) >= $p[0];
    return '<svg class="spark ' . ($up ? 'up' : 'down') . '" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

function coin_img(array $c, int $size = 24): string
{
    $src = $c['image'] ?? '';
    $alt = e(($c['name'] ?? '') . ' logo');
    if (!$src) {
        $l = e(mb_substr((string)($c['symbol'] ?? '?'), 0, 1));
        return '<span class="ph" style="width:' . $size . 'px;height:' . $size . 'px">' . strtoupper($l) . '</span>';
    }
    return '<img src="' . e($src) . '" alt="' . $alt . '" width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async">';
}

function json_out($data, int $ttl = 30): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=' . $ttl);
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
}

function render(string $view, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_DIR . '/views/' . $view . '.php';
    return ob_get_clean();
}

/** Admin-managed overrides (SEO Center > Metadata): title, description, robots, canonical, og image, extra JSON-LD. */
function seo_apply_overrides(array $seo): array
{
    try {
        $paths = [strtolower(path_norm((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)))];
        if (!empty($seo['canonical']) && !preg_match('~^https?://~', $seo['canonical'])) $paths[] = strtolower(path_norm((string)parse_url($seo['canonical'], PHP_URL_PATH)));
        $paths = array_values(array_unique($paths));
        $ph = implode(',', array_fill(0, count($paths), '?'));
        $o = row("SELECT * FROM seo_meta WHERE path IN ($ph) LIMIT 1", $paths);
        if (!$o) return $seo;
        foreach (['title', 'description', 'canonical', 'og_image'] as $k) if ($o[$k] !== null && $o[$k] !== '') $seo[$k] = $o[$k];
        if ($o['robots']) $seo['robots'] = $o['robots'];
        if ($o['extra_schema'] && ($j = json_decode($o['extra_schema'], true)) && is_array($j)) $seo['ld'][] = $j;
    } catch (Throwable $e) { /* table missing before migration: ignore */ }
    return $seo;
}

function page(string $view, array $vars, array $seo): string
{
    $seo = seo_apply_overrides($seo);
    $vars['seo'] = $seo;
    $content = render($view, $vars);
    return render('layout', ['content' => $content, 'seo' => $seo]);
}

function not_found(string $msg = 'Page not found'): void
{
    http_response_code(!empty($GLOBALS['__gone']) ? 410 : 404);
    if (!empty($GLOBALS['__gone'])) $msg = 'This asset has been delisted and is no longer tracked.';
    echo page('404', ['msg' => $msg], ['title' => 'Page Not Found | ' . cfg('site_name'), 'description' => $msg, 'robots' => 'noindex,follow', 'canonical' => null]);
}

/** Simple pagination links. */
function pager(int $page, int $pages, string $basePath): string
{
    if ($pages <= 1) return '';
    $link = fn($p) => $p <= 1 ? path_norm($basePath) : path_norm($basePath) . '?page=' . $p;
    $out = '<nav class="pager" aria-label="Pagination">';
    if ($page > 1) $out .= '<a rel="prev" href="' . e($link($page - 1)) . '">‹ Prev</a>';
    $set = array_unique(array_filter([1, 2, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages - 1, $pages], fn($p) => $p >= 1 && $p <= $pages));
    sort($set);
    $prev = 0;
    foreach ($set as $p) {
        if ($p - $prev > 1) $out .= '<span class="gap">…</span>';
        $out .= $p == $page ? '<span class="cur" aria-current="page">' . $p . '</span>' : '<a href="' . e($link($p)) . '">' . $p . '</a>';
        $prev = $p;
    }
    if ($page < $pages) $out .= '<a rel="next" href="' . e($link($page + 1)) . '">Next ›</a>';
    return $out . '</nav>';
}

function clean_html(?string $html): string
{
    if (!$html) return '';
    $html = strip_tags($html, '<p><br><a><strong><b><em><i><ul><ol><li><h3><h4>');
    // force safe links
    $html = preg_replace_callback('~<a\s[^>]*href=("|\')(.*?)\1[^>]*>~i', function ($m) {
        $href = $m[2];
        if (!preg_match('~^https?://~i', $href)) return '<a>';
        return '<a href="' . e($href) . '" rel="nofollow noopener" target="_blank">';
    }, $html);
    $html = preg_replace('~<(p|li|b|strong|em|i|ul|ol|h3|h4)\s[^>]*>~i', '<$1>', $html);
    if (strpos($html, '<p>') === false) $html = '<p>' . preg_replace("~\r?\n\r?\n~", '</p><p>', $html) . '</p>';
    return $html;
}

function log_msg(string $file, string $msg): void
{
    $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents(STORAGE . '/logs/' . $file . '.log', $line, FILE_APPEND);
    if (PHP_SAPI === 'cli') echo $line;
}
