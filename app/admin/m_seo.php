<?php
/** SEO Center. Search Console data is labelled as such; first-party data comes from our own logs. */

function m_seo(string $sub): void
{
    $tabs = ['' => 'Overview', 'queries' => 'Search queries', 'meta' => 'Metadata', 'redirects' => 'Redirects', '404' => '404 monitor', 'robots' => 'Robots & sitemaps', 'crawl' => 'Crawl stats', 'keywords' => 'Keywords', 'audit' => 'Site audit'];
    if (!isset($tabs[$sub])) { http_response_code(404); admin_page('Not found', '<div class="card">Unknown page.</div>'); return; }
    $need = $sub === 'meta' ? 'seo.meta' : 'seo.view';
    if (!can($need)) require_perm($need);
    $fn = ['' => 'seo_overview', 'queries' => 'seo_queries', 'meta' => 'seo_meta', 'redirects' => 'seo_redirects', '404' => 'seo_404', 'robots' => 'seo_robots', 'crawl' => 'seo_crawl', 'keywords' => 'seo_keywords', 'audit' => 'seo_audit'][$sub];
    $vis = array_filter($tabs, fn($k) => can($k === 'meta' ? 'seo.meta' : 'seo.view'), ARRAY_FILTER_USE_KEY);
    $range = in_array($sub, ['', 'queries', '404', 'crawl'], true) ? '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px"><span class="muted">' . e(a_range()[3]) . '</span>' . a_range_picker('seo' . ($sub ? "/$sub" : '')) . '</div>' : '';
    admin_page('SEO · ' . $tabs[$sub], a_tabs('seo', $vis, $sub) . $range . $fn());
}

function gsc_connected(): bool { return (int)val('SELECT COUNT(*) FROM gsc_totals') > 0; }

function seo_overview(): string
{
    traffic_rollup_fresh();
    [$from, $to, $days, $label, $pf, $pt] = a_range();
    [$labels, $ser] = traffic_series($from, $to);
    $h = '';
    if (gsc_connected()) {
        $cur = row('SELECT COALESCE(SUM(clicks),0) c, COALESCE(SUM(impressions),0) i, AVG(position) p FROM gsc_totals WHERE day BETWEEN ? AND ?', [$from, $to]);
        $prv = row('SELECT COALESCE(SUM(clicks),0) c, COALESCE(SUM(impressions),0) i, AVG(position) p FROM gsc_totals WHERE day BETWEEN ? AND ?', [$pf, $pt]);
        $h .= '<div class="kpis">' . a_kpi('Search clicks', a_n($cur['c']), a_delta($cur['c'], $prv['c'])) . a_kpi('Impressions', a_n($cur['i']), a_delta($cur['i'], $prv['i']))
            . a_kpi('Average CTR', a_pct($cur['c'], $cur['i'], 2), 'prev ' . a_pct($prv['c'], $prv['i'], 2)) . a_kpi('Average position', $cur['p'] ? number_format($cur['p'], 1) : '—', 'prev ' . ($prv['p'] ? number_format($prv['p'], 1) : '—')) . '</div>';
        $g = []; foreach (rows('SELECT day, clicks, impressions FROM gsc_totals WHERE day BETWEEN ? AND ?', [$from, $to]) as $r) $g[$r['day']] = $r;
        $h .= '<div class="grid g2">' . a_card('Clicks (Search Console)', svg_line([['name' => 'Clicks', 'values' => array_map(fn($d) => (int)($g[$d]['clicks'] ?? 0), $labels)]], $labels, ['h' => 180]) . a_source('Google Search Console'))
            . a_card('Impressions (Search Console)', svg_line([['name' => 'Impressions', 'values' => array_map(fn($d) => (int)($g[$d]['impressions'] ?? 0), $labels), 'color' => '#8b5cf6']], $labels, ['h' => 180]) . a_source('Google Search Console')) . '</div>';
        $h .= a_help('Search Console data lags 2–3 days and uses its own definitions; first-party “organic sessions” below count visits, not clicks, so the two will not match exactly.');
    } else {
        $h .= '<div class="warnbox"><strong>Search Console is not connected.</strong> Impressions, CTR, positions and query data come from Google and cannot be measured by us. Connect it in <a href="' . e(a_url('settings/integrations')) . '">Settings → Integrations</a>. Everything else on this page uses first-party data.</div>';
    }
    $h .= a_card('Organic search sessions (first-party)', svg_line([['name' => 'Organic sessions', 'values' => array_map('intval', array_column($ser, 'organic_sessions')), 'color' => '#16c784'], ['name' => 'Googlebot hits', 'values' => array_map('intval', array_column($ser, 'googlebot')), 'color' => '#ea3943']], $labels, ['h' => 180]) . a_source('first-party analytics'));

    // sitemap coverage vs. impressions
    $cnt = sitemap_counts();
    $rowsC = [];
    foreach ($cnt as $t => $n) $rowsC[] = ['t' => $t, 'files' => $n, 'urls' => 'up to ' . a_n($n * SITEMAP_CHUNK)];
    $imp = (int)val('SELECT COUNT(DISTINCT page) FROM gsc_pages WHERE day BETWEEN ? AND ?', [$from, $to]);
    $h .= '<div class="grid g2">' . a_card('Sitemap coverage', a_table(['t' => 'Sitemap', 'files' => ['label' => 'Files', 'cls' => 'num'], 'urls' => ['label' => 'Capacity', 'cls' => 'num']], $rowsC) . ($imp ? a_help("$imp distinct pages received impressions in this period.") : ''));

    if (gsc_connected()) {
        $ops = rows('SELECT query, SUM(clicks) c, SUM(impressions) i, AVG(position) p FROM gsc_queries WHERE day BETWEEN ? AND ? GROUP BY query HAVING p BETWEEN 8 AND 20 AND i >= 50 ORDER BY i DESC LIMIT 12', [$from, $to]);
        $h .= a_card('Opportunities: page-2 queries (positions 8–20)', a_table(['query' => 'Query', 'i' => ['label' => 'Impr.', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'c' => ['label' => 'Clicks', 'cls' => 'num'], 'p' => ['label' => 'Pos.', 'cls' => 'num', 'fmt' => fn($r, $v) => number_format($v, 1)]], $ops, 'None yet.') . a_help('Small title/content improvements here often move pages onto page 1.')) . '</div>';
        $low = rows('SELECT page, SUM(clicks) c, SUM(impressions) i, AVG(position) p FROM gsc_pages WHERE day BETWEEN ? AND ? GROUP BY page HAVING i >= 200 AND c / i < 0.01 AND p <= 10 ORDER BY i DESC LIMIT 10', [$from, $to]);
        $h .= a_card('Low-CTR pages (rank top 10 but CTR < 1%) — rewrite title/description', a_table(['page' => ['label' => 'Page', 'cls' => 'wrap', 'fmt' => fn($r, $v) => '<a href="' . e(a_url('seo/meta', ['path' => (string)parse_url($v, PHP_URL_PATH) ?: $v])) . '">' . e($v) . '</a>'], 'i' => ['label' => 'Impr.', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'c' => ['label' => 'Clicks', 'cls' => 'num'], 'ctr' => ['label' => 'CTR', 'cls' => 'num', 'fmt' => fn($r) => a_pct($r['c'], $r['i'], 2)], 'p' => ['label' => 'Pos.', 'cls' => 'num', 'fmt' => fn($r, $v) => number_format($v, 1)]], $low, 'No low-CTR pages. ✅'));
        $drop = rows('SELECT a.page, a.c now, COALESCE(b.c,0) prev FROM (SELECT page, SUM(clicks) c FROM gsc_pages WHERE day BETWEEN ? AND ? GROUP BY page) a LEFT JOIN (SELECT page, SUM(clicks) c FROM gsc_pages WHERE day BETWEEN ? AND ? GROUP BY page) b ON b.page = a.page WHERE COALESCE(b.c,0) >= 10 AND a.c < b.c * 0.6 ORDER BY b.c - a.c DESC LIMIT 10', [$from, $to, $pf, $pt]);
        $h .= a_card('Pages losing clicks (down >40% vs previous period)', a_table(['page' => ['label' => 'Page', 'cls' => 'wrap'], 'prev' => ['label' => 'Previous', 'cls' => 'num'], 'now' => ['label' => 'Now', 'cls' => 'num']], $drop, 'No significant drops. ✅'));
    } else $h .= '</div>';
    return $h;
}

function seo_queries(): string
{
    [$from, $to] = a_range();
    if (!gsc_connected()) return a_card('Search queries', '<p class="muted pad">Connect Google Search Console to see queries. Settings → Integrations.</p>');
    $by = a_in('by') === 'page' ? 'page' : 'query'; $q = a_in('q');
    $tbl = $by === 'page' ? 'gsc_pages' : 'gsc_queries';
    $w = 'day BETWEEN ? AND ?'; $p = [$from, $to];
    if ($q !== '') { $w .= " AND `$by` LIKE ?"; $p[] = '%' . addcslashes($q, '%_') . '%'; }
    $rows = rows("SELECT `$by` k, SUM(clicks) c, SUM(impressions) i, AVG(position) p FROM $tbl WHERE $w GROUP BY k ORDER BY c DESC, i DESC LIMIT 200", $p);
    $h = '<form class="filters" method="get"><input type="hidden" name="range" value="' . e(a_in('range', '30')) . '"><input type="hidden" name="from" value="' . e(a_in('from')) . '"><input type="hidden" name="to" value="' . e(a_in('to')) . '">'
        . '<select name="by" onchange="this.form.submit()"><option value="query">Queries</option><option value="page"' . ($by === 'page' ? ' selected' : '') . '>Pages</option></select><input type="search" name="q" value="' . e($q) . '" placeholder="Filter…"><button class="btn" type="submit">Filter</button></form>';
    return $h . a_card('Top ' . $by . 's', a_table(['k' => ['label' => ucfirst($by), 'cls' => 'wrap'], 'c' => ['label' => 'Clicks', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'i' => ['label' => 'Impressions', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)],
        'ctr' => ['label' => 'CTR', 'cls' => 'num', 'fmt' => fn($r) => a_pct($r['c'], $r['i'], 2)], 'p' => ['label' => 'Avg position', 'cls' => 'num', 'fmt' => fn($r, $v) => number_format($v, 1)]], $rows, 'No data for this filter.') . a_source('Google Search Console'));
}

// ------------------------------------------------------------------ metadata overrides
function seo_norm_path(string $p): string
{
    $p = '/' . ltrim(strtolower(trim($p)), '/');
    $p = (string)parse_url($p, PHP_URL_PATH);
    return path_norm($p);
}

function seo_meta(): string
{
    $path = a_in('path');
    if (is_post()) {
        $act = a_action();
        if ($act === 'save') {
            $p = seo_norm_path(a_in('path'));
            if (preg_match('~^/(admin|api|go)(/|$)~', $p)) a_go('seo/meta', 'That path is not public.', 'err');
            $ld = trim((string)($_POST['extra_schema'] ?? ''));
            if ($ld !== '') { $j = json_decode($ld, true); if (!is_array($j)) a_go('seo/meta', 'Extra JSON-LD is not valid JSON.', 'err', ['path' => $p]); $ld = json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
            $robots = in_array(a_in('robots'), ['noindex,follow', 'noindex,nofollow', 'index,follow', 'index,nofollow'], true) ? a_in('robots') : null;
            $data = ['title' => mb_substr(a_in('title'), 0, 200) ?: null, 'description' => mb_substr(a_in('description'), 0, 400) ?: null, 'robots' => $robots,
                'canonical' => a_in('canonical') !== '' ? (preg_match('~^(https?://|/)~', a_in('canonical')) ? a_in('canonical') : null) : null, 'og_image' => safe_url(a_in('og_image')), 'extra_schema' => $ld ?: null];
            $old = row('SELECT title, description, robots, canonical, og_image, extra_schema FROM seo_meta WHERE path = ?', [$p]);
            if (!array_filter($data)) { q('DELETE FROM seo_meta WHERE path = ?', [$p]); audit('seo.meta.delete', 'seo_meta', $p, $old); a_go('seo/meta', 'Override removed — the page uses its automatic metadata again.'); }
            q('INSERT INTO seo_meta (path, title, description, robots, canonical, og_image, extra_schema, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
               ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), robots=VALUES(robots), canonical=VALUES(canonical), og_image=VALUES(og_image), extra_schema=VALUES(extra_schema), updated_by=VALUES(updated_by), updated_at=VALUES(updated_at)',
                [$p, $data['title'], $data['description'], $data['robots'], $data['canonical'], $data['og_image'], $data['extra_schema'], current_user()['id']]);
            audit('seo.meta.save', 'seo_meta', $p, $old ?: array_fill_keys(array_keys($data), null), $data);
            coin_cache_clear();
            a_go('seo/meta', 'Metadata saved for ' . $p, 'ok', ['path' => $p]);
        }
        if ($act === 'delete') {
            $p = seo_norm_path(a_in('path'));
            audit('seo.meta.delete', 'seo_meta', $p, row('SELECT * FROM seo_meta WHERE path = ?', [$p]));
            q('DELETE FROM seo_meta WHERE path = ?', [$p]); coin_cache_clear();
            a_go('seo/meta', 'Override removed.');
        }
    }
    $h = '';
    if ($path !== '') {
        $p = seo_norm_path($path);
        $o = row('SELECT * FROM seo_meta WHERE path = ?', [$p]) ?: ['title' => '', 'description' => '', 'robots' => '', 'canonical' => '', 'og_image' => '', 'extra_schema' => ''];
        // what the page generates by itself right now
        $auto = null;
        if (a_in('fetch') === '1') { $r = site_fetch($p, 8); $auto = ['r' => $r, 'p' => seo_parse_html($r['body'])]; }
        $autoHtml = $auto ? '<div class="notice">Current live output (<code>HTTP ' . (int)$auto['r']['code'] . '</code>): <br><b>Title:</b> ' . e($auto['p']['title'] ?? '—') . '<br><b>Description:</b> ' . e($auto['p']['description'] ?? '—') . '<br><b>Robots:</b> ' . e($auto['p']['robots'] ?? 'default index,follow') . ' · <b>Canonical:</b> ' . e($auto['p']['canonical'] ?? '—') . '</div>'
            : '<p><a class="btn sm ghost" href="' . e(a_url('seo/meta', ['path' => $p, 'fetch' => 1])) . '">Fetch current live metadata</a></p>';
        $form = a_form('<div class="cols2"><div>'
            . a_field('text', 'path', 'Page path', $p, ['required' => 1, 'attrs' => $o['title'] || $o['description'] ? ' readonly' : '', 'hint' => 'e.g. /currencies/bitcoin/ — exact path, lowercase, trailing slash.'])
            . a_field('text', 'title', 'Title', $o['title'], ['max' => 200, 'attrs' => ' data-count="60" oninput="cnt(this)"', 'hint' => '<span class="cnt"></span> Aim for ≤ 60 characters. Leave blank to keep the automatic title.'])
            . a_field('textarea', 'description', 'Meta description', $o['description'], ['rows' => 3, 'max' => 400, 'attrs' => ' data-count="160" oninput="cnt(this)"', 'hint' => '<span class="cnt"></span> Aim for 120–160 characters.'])
            . a_field('select', 'robots', 'Robots', $o['robots'], ['options' => ['' => 'Default (index, follow)', 'noindex,follow' => 'noindex, follow', 'noindex,nofollow' => 'noindex, nofollow', 'index,follow' => 'index, follow (force)', 'index,nofollow' => 'index, nofollow'], 'hint' => 'Noindex removes the page from Google. Use carefully.'])
            . '</div><div>'
            . a_field('text', 'canonical', 'Canonical URL override', $o['canonical'], ['hint' => 'Path (/…) or full URL. Blank = the page’s own URL.'])
            . a_field('text', 'og_image', 'Social image URL (og:image)', $o['og_image'])
            . a_field('textarea', 'extra_schema', 'Extra JSON-LD structured data', $o['extra_schema'], ['rows' => 7, 'hint' => 'Paste one JSON object, e.g. a FAQPage. Validated as JSON on save.'])
            . '</div></div>' . a_submit('Save metadata'));
        $title = $o['title'] ?: ($auto['p']['title'] ?? 'Page title appears here');
        $serp = '<div class="serp"><div class="u">' . e(rtrim(cfg('site_url'), '/') . $p) . '</div><div class="t" id="serp_t">' . e(mb_substr($title, 0, 70)) . '</div><div class="d" id="serp_d">' . e(mb_substr($o['description'] ?: ($auto['p']['description'] ?? 'Meta description appears here.'), 0, 170)) . '</div></div>';
        $js = '<script>function cnt(el){var m=el.parentNode.querySelector(".cnt");var n=el.value.length,l=+el.dataset.count;m.textContent=n+" / "+l;m.style.color=n>l?"#e5353f":"inherit";var t=document.getElementById(el.name==="title"?"serp_t":"serp_d");if(t&&el.value)t.textContent=el.value.slice(0,el.name==="title"?70:170)}document.querySelectorAll("[data-count]").forEach(cnt)</script>';
        $h .= '<p><a href="' . e(a_url('seo/meta')) . '">← All overrides</a></p>' . a_card('Edit metadata — ' . $p, $autoHtml . $form . '<h3>Search preview</h3>' . $serp . $js, $o['title'] || $o['description'] ? a_btn('delete', 'Remove override', ['path' => $p], 'sm danger', 'Remove this override?') : '');
    } else {
        $h .= a_card('Find or add a page', '<form class="filters" method="get"><input type="text" name="path" placeholder="/currencies/bitcoin/" style="min-width:320px" required><button class="btn" type="submit">Edit metadata for this path</button></form>'
            . a_help('Overrides replace the automatic title, description, robots, canonical, social image or add structured data for one page. They survive cache and data syncs.'));
        $q = a_in('q');
        $rows = rows('SELECT m.*, u.email FROM seo_meta m LEFT JOIN users u ON u.id = m.updated_by' . ($q !== '' ? ' WHERE m.path LIKE ?' : '') . ' ORDER BY m.updated_at DESC LIMIT 200', $q !== '' ? ['%' . addcslashes($q, '%_') . '%'] : []);
        $h .= a_card(count($rows) . ' overrides', a_table(['path' => ['label' => 'Path', 'fmt' => fn($r, $v) => '<a href="' . e(a_url('seo/meta', ['path' => $v])) . '">' . e($v) . '</a>'], 'title' => ['label' => 'Title', 'cls' => 'wrap'], 'robots' => 'Robots', 'email' => 'By', 'updated_at' => ['label' => 'Updated', 'fmt' => fn($r, $v) => e(time_ago($v))]], $rows, 'No overrides yet.'));
    }
    return $h;
}

// ------------------------------------------------------------------ redirects
function seo_redirects(): string
{
    if (is_post()) {
        require_perm('seo.redirects');
        $act = a_action();
        $clean = function (string $src, string $dst, int $code): array {
            $src = '/' . ltrim(trim($src), '/'); $src = strtolower((string)parse_url($src, PHP_URL_PATH));
            if (!in_array($code, [301, 302, 307, 308, 410], true)) return [null, 'Status code must be 301, 302, 307, 308 or 410.'];
            if ($src === '/' || preg_match('~^/(admin|api|assets)(/|$)~', $src)) return [null, 'That source path cannot be redirected.'];
            if ($code !== 410) {
                if (!preg_match('~^(https?://|/(?!/))~', $dst)) return [null, 'Target must be a path starting with / or a full http(s) URL.'];
                $tp = strtolower((string)parse_url($dst, PHP_URL_PATH));
                if (!preg_match('~^https?://~', $dst) && rtrim($tp, '/') === rtrim($src, '/')) return [null, 'Source and target are the same — that would loop.'];
            }
            return [[$src, $code === 410 ? '' : $dst, $code], null];
        };
        if ($act === 'save') {
            [$d, $err] = $clean(a_in('source'), a_in('target'), a_int('code', 301));
            if ($err) a_go('seo/redirects', $err, 'err', ['source' => a_in('source')]);
            if ($d[1] !== '' && ($t = row('SELECT id FROM redirects WHERE source = ? AND enabled = 1', [strtolower((string)parse_url($d[1], PHP_URL_PATH))])) && !preg_match('~^https?://~', $d[1])) a_go('seo/redirects', 'The target is itself redirected — this makes a chain. Point to the final URL.', 'warn', ['source' => $d[0]]);
            q('INSERT INTO redirects (source, target, code) VALUES (?,?,?) ON DUPLICATE KEY UPDATE target = VALUES(target), code = VALUES(code), enabled = 1', $d);
            audit('redirect.save', 'redirect', $d[0], null, ['source' => $d[0], 'target' => $d[1], 'code' => $d[2]]);
            redirect_rebuild(); coin_cache_clear();
            a_go('seo/redirects', 'Redirect saved.');
        }
        if ($act === 'toggle') { q('UPDATE redirects SET enabled = 1 - enabled WHERE id = ?', [a_int('id')]); audit('redirect.toggle', 'redirect', a_int('id')); redirect_rebuild(); a_go('seo/redirects'); }
        if ($act === 'delete') { $o = row('SELECT * FROM redirects WHERE id = ?', [a_int('id')]); q('DELETE FROM redirects WHERE id = ?', [a_int('id')]); audit('redirect.delete', 'redirect', a_int('id'), $o); redirect_rebuild(); a_go('seo/redirects', 'Deleted.'); }
        if ($act === 'import') {
            $n = 0; $bad = 0;
            foreach (preg_split('~\r?\n~', (string)($_POST['csv'] ?? '')) as $line) {
                $line = trim($line); if ($line === '' || $line[0] === '#') continue;
                $c = str_getcsv($line);
                [$d, $err] = $clean($c[0] ?? '', $c[1] ?? '', (int)($c[2] ?? 301));
                if ($err) { $bad++; continue; }
                q('INSERT INTO redirects (source, target, code) VALUES (?,?,?) ON DUPLICATE KEY UPDATE target = VALUES(target), code = VALUES(code), enabled = 1', $d); $n++;
            }
            audit('redirect.import', 'redirect', null, null, ['imported' => $n, 'rejected' => $bad]);
            redirect_rebuild(); coin_cache_clear();
            a_go('seo/redirects', "$n redirect(s) imported" . ($bad ? ", $bad rejected" : '') . '.', $bad ? 'warn' : 'ok');
        }
    }
    $q = a_in('q');
    $rows = rows('SELECT * FROM redirects' . ($q !== '' ? ' WHERE source LIKE ? OR target LIKE ?' : '') . ' ORDER BY id DESC LIMIT 300', $q !== '' ? array_fill(0, 2, '%' . addcslashes($q, '%_') . '%') : []);
    $can = can('seo.redirects');
    $h = '';
    if ($can) $h .= '<div class="grid g2">' . a_card('Add a redirect', a_form('<div class="cols2">' . a_field('text', 'source', 'From path', a_in('source'), ['required' => 1, 'placeholder' => '/old-page/']) . a_field('text', 'target', 'To (path or URL)', '', ['placeholder' => '/new-page/'])
        . a_field('select', 'code', 'Type', 301, ['options' => [301 => '301 Permanent', 302 => '302 Temporary', 307 => '307 Temporary (keep method)', 308 => '308 Permanent (keep method)', 410 => '410 Gone (no target)']]) . '</div><input type="hidden" name="action" value="save">' . a_submit('Save redirect')))
        . a_card('Bulk import', a_form('<input type="hidden" name="action" value="import">' . a_field('textarea', 'csv', 'One per line: source,target,code', '', ['rows' => 5, 'placeholder' => "/old/,/new/,301\n/removed/,,410"]) . a_submit('Import', 'ghost'))) . '</div>';
    $h .= '<form class="filters" method="get"><input type="search" name="q" value="' . e($q) . '" placeholder="Search redirects…"><button class="btn" type="submit">Search</button></form>';
    $h .= a_card(count($rows) . ' redirects', a_table(['source' => ['label' => 'From', 'cls' => 'wrap mono'], 'target' => ['label' => 'To', 'cls' => 'wrap mono'], 'code' => 'Code', 'hits' => ['label' => 'Hits', 'cls' => 'num'],
        'enabled' => ['label' => 'State', 'fmt' => fn($r, $v) => $v ? a_badge('on', 'ok') : a_badge('off')],
        'x' => ['label' => '', 'fmt' => fn($r) => $can ? a_btn('toggle', $r['enabled'] ? 'Disable' : 'Enable', ['id' => $r['id']]) . a_btn('delete', 'Delete', ['id' => $r['id']], 'sm ghost', 'Delete redirect?') : '']], $rows, 'No redirects yet.')
        . a_help('Redirects are served before the page cache from a small JSON file, so they are fast even under load. Avoid chains (A→B→C): point every source at the final URL.'));
    return $h;
}

function seo_404(): string
{
    [$from, $to] = a_range();
    $rows = rows("SELECT path, SUM(is_bot = 0) humans, SUM(is_bot = 1) bots, SUM(bot_name = 'Googlebot') gbot, MAX(ts) last_seen FROM pageviews WHERE status = 404 AND day BETWEEN ? AND ? GROUP BY path ORDER BY humans DESC, bots DESC LIMIT 100", [$from, $to]);
    $refs = [];
    foreach (array_slice($rows, 0, 0) as $r) {}
    return a_card('Not-found URLs (' . count($rows) . ')', a_table(['path' => ['label' => 'Path', 'cls' => 'wrap mono'], 'humans' => ['label' => 'Visitors', 'cls' => 'num'], 'bots' => ['label' => 'Bots', 'cls' => 'num'], 'gbot' => ['label' => 'Googlebot', 'cls' => 'num'],
        'last_seen' => ['label' => 'Last seen', 'fmt' => fn($r, $v) => e(time_ago($v))],
        'x' => ['label' => '', 'fmt' => fn($r) => can('seo.redirects') ? '<a class="btn sm" href="' . e(a_url('seo/redirects', ['source' => $r['path']])) . '">Create redirect</a>' : '']], $rows, 'No 404s in this period. ✅')
        . a_help('Fix URLs that real visitors or Googlebot request: redirect them (301) to the best replacement, or 410 if the content is gone for good. Random bot probes (wp-login.php etc.) can be ignored.') . a_source('first-party logs'));
}

// ------------------------------------------------------------------ robots + sitemaps
function robots_default(): string
{
    return "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /search/\nDisallow: /watchlist/\nDisallow: /admin/\nDisallow: /go/\nDisallow: /account/\nDisallow: /login/\nDisallow: /register/\nDisallow: /*.csv$\n\nSitemap: " . abs_url('/sitemap.xml') . "\n";
}

/** Minimal robots.txt matcher (group "*"): longest matching Allow/Disallow wins, Allow on ties. */
function robots_allows(string $txt, string $path): bool
{
    $rules = []; $applies = false;
    foreach (preg_split('~\r?\n~', $txt) as $l) {
        $l = trim(preg_replace('~#.*$~', '', $l)); if ($l === '' || strpos($l, ':') === false) continue;
        [$k, $v] = array_map('trim', explode(':', $l, 2)); $k = strtolower($k);
        if ($k === 'user-agent') $applies = $v === '*' ? true : ($applies && false);
        elseif ($applies && ($k === 'allow' || $k === 'disallow') && $v !== '') $rules[] = [$k, $v];
    }
    $best = ['allow', '']; 
    foreach ($rules as [$k, $v]) {
        $re = '~^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($v, '~')) . '~';
        if (preg_match($re, $path) && (strlen($v) > strlen($best[1]) || (strlen($v) === strlen($best[1]) && $k === 'allow'))) $best = [$k, $v];
    }
    return $best[0] === 'allow';
}

function seo_robots(): string
{
    if (is_post() && a_action() === 'save') {
        require_perm('seo.robots');
        $t = str_replace("\r", '', (string)($_POST['robots'] ?? ''));
        if (preg_match('~^\s*Disallow:\s*/\s*$~mi', $t) && empty($_POST['confirm_block'])) a_go('seo/robots', 'This blocks the entire site from search engines. Tick the confirmation box if you really mean it.', 'err');
        audit('robots.save', 'setting', 'robots_txt', ['v' => setting('robots_txt')], ['v' => $t]);
        set_setting('robots_txt', trim($t)); coin_cache_clear();
        a_go('seo/robots', trim($t) === '' ? 'Reset to the default robots.txt.' : 'robots.txt saved.');
    }
    $cur = trim((string)setting('robots_txt', '')) ?: robots_default();
    $test = a_in('test');
    $res = $test !== '' ? ('<p>Googlebot / * may crawl <code>' . e($test) . '</code>: ' . (robots_allows($cur, $test) ? a_badge('allowed', 'ok') : a_badge('blocked', 'bad')) . '</p>') : '';
    $h = a_card('robots.txt', (can('seo.robots') ? a_form(a_field('textarea', 'robots', 'Contents', $cur, ['rows' => 14, 'hint' => 'Clear the box and save to return to the default.']) . a_field('checkbox', 'confirm_block', 'I understand “Disallow: /” blocks all search engines') . '<input type="hidden" name="action" value="save">' . a_submit('Save robots.txt'))
        : '<pre class="log">' . e($cur) . '</pre>') . '<p><a href="/robots.txt" target="_blank" rel="noopener">View live robots.txt ↗</a></p>');
    $h .= a_card('Test a URL against these rules', '<form class="filters" method="get"><input type="text" name="test" value="' . e($test) . '" placeholder="/currencies/bitcoin/" style="min-width:300px"><button class="btn" type="submit">Test</button></form>' . $res);
    $cnt = sitemap_counts(); $rows = [];
    foreach ($cnt as $t => $n) for ($i = 1; $i <= $n; $i++) $rows[] = ['f' => "sitemaps/$t-$i.xml"];
    $h .= a_card('Sitemaps', a_table(['f' => ['label' => 'File', 'fmt' => fn($r, $v) => '<a href="/' . e($v) . '" target="_blank" rel="noopener">/' . e($v) . '</a>']], $rows)
        . a_help('Index: <a href="/sitemap.xml" target="_blank" rel="noopener">/sitemap.xml</a>. Submit it once in Search Console → Sitemaps (and Bing Webmaster Tools); it is generated live so it never goes stale. Hidden, pending, delisted and scam-flagged coins are excluded automatically.'));
    return $h;
}

// ------------------------------------------------------------------ crawl stats
function seo_crawl(): string
{
    [$from, $to] = a_range();
    $b = 'is_bot = 1 AND day BETWEEN ? AND ?'; $p = [$from, $to];
    $names = ['Googlebot', 'Bingbot', 'GPTBot', 'ClaudeBot', 'Perplexity', 'Applebot', 'Yandex', 'Ahrefs', 'Semrush'];
    $per = rows("SELECT bot_name, COUNT(*) n, ROUND(AVG(ms)) ms, SUM(status >= 500) e5, SUM(status = 404) e4, SUM(status BETWEEN 300 AND 399) r3 FROM pageviews WHERE $b AND bot_name IS NOT NULL GROUP BY bot_name ORDER BY n DESC LIMIT 20", $p);
    $h = a_card('Crawlers', a_table(['bot_name' => 'Bot', 'n' => ['label' => 'Requests', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'ms' => ['label' => 'Avg ms', 'cls' => 'num'], 'e5' => ['label' => '5xx', 'cls' => 'num'], 'e4' => ['label' => '404', 'cls' => 'num'], 'r3' => ['label' => '3xx', 'cls' => 'num']], $per, 'No crawler visits recorded yet.') . a_help('AI crawlers (GPTBot, ClaudeBot, PerplexityBot) are listed so you can see who reads the site. Block them in robots.txt if you do not want that.') . a_source('first-party logs'));
    $series = [];
    $m = [];
    foreach (rows("SELECT day, bot_name, COUNT(*) n FROM pageviews WHERE $b AND bot_name IN ('Googlebot','Bingbot','GPTBot','ClaudeBot') GROUP BY day, bot_name", $p) as $r) $m[$r['bot_name']][$r['day']] = (int)$r['n'];
    $labels = []; for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) $labels[] = gmdate('Y-m-d', $t);
    foreach (['Googlebot' => '#3861fb', 'Bingbot' => '#16c784', 'GPTBot' => '#f5a623', 'ClaudeBot' => '#8b5cf6'] as $n => $c) $series[] = ['name' => $n, 'color' => $c, 'values' => array_map(fn($d) => $m[$n][$d] ?? 0, $labels)];
    $h .= a_card('Crawl requests per day', svg_line($series, $labels, ['h' => 190]));
    $g = rows("SELECT status, COUNT(*) n FROM pageviews WHERE $b AND bot_name = 'Googlebot' GROUP BY status ORDER BY n DESC", $p);
    $t = rows("SELECT COALESCE(ptype,'other') t, COUNT(*) n FROM pageviews WHERE $b AND bot_name = 'Googlebot' GROUP BY t ORDER BY n DESC", $p);
    $h .= '<div class="grid g2">' . a_card('Googlebot by response code', svg_donut(array_map(fn($r) => ['HTTP ' . $r['status'], $r['n']], $g))) . a_card('Googlebot by page type', svg_donut(array_map(fn($r) => [$r['t'], $r['n']], $t))) . '</div>';
    $top = rows("SELECT path, COUNT(*) n FROM pageviews WHERE $b AND bot_name = 'Googlebot' GROUP BY path ORDER BY n DESC LIMIT 15", $p);
    $h .= a_card('Most-crawled URLs (Googlebot)', hbars(array_map(fn($r) => [$r['path'], $r['n']], $top)));
    $unc = rows("SELECT c.slug, c.name, c.rank_no FROM coins c WHERE c.status = 'active' AND c.rank_no <= 100 AND NOT EXISTS (SELECT 1 FROM pageviews v WHERE v.is_bot = 1 AND v.bot_name = 'Googlebot' AND v.day BETWEEN ? AND ? AND v.path = CONCAT('/currencies/', c.slug, '/')) ORDER BY c.rank_no LIMIT 15", $p);
    $h .= a_card('Top-100 coins Googlebot has not fetched in this period', a_table(['rank_no' => ['label' => '#', 'cls' => 'num'], 'name' => ['label' => 'Coin', 'fmt' => fn($r, $v) => '<a href="/currencies/' . e($r['slug']) . '/" target="_blank" rel="noopener">' . e($v) . '</a>']], $unc, 'All top-100 coins were crawled. ✅') . a_help('Only meaningful once the site has been live for the selected period.'));
    return $h;
}

// ------------------------------------------------------------------ keywords
function seo_keywords(): string
{
    if (is_post()) {
        require_perm('seo.edit');
        if (a_action() === 'add') {
            $k = mb_strtolower(a_in('keyword'));
            if ($k !== '') { q('INSERT IGNORE INTO seo_keywords (keyword, target_url, notes) VALUES (?,?,?)', [mb_substr($k, 0, 160), mb_substr(a_in('target_url'), 0, 190) ?: null, mb_substr(a_in('notes'), 0, 300) ?: null]); audit('keyword.add', 'seo_keyword', $k); }
            a_go('seo/keywords', 'Keyword added.');
        }
        if (a_action() === 'delete') { q('DELETE FROM seo_keywords WHERE id = ?', [a_int('id')]); audit('keyword.delete', 'seo_keyword', a_int('id')); a_go('seo/keywords'); }
    }
    $kws = rows('SELECT * FROM seo_keywords ORDER BY keyword');
    foreach ($kws as &$k) {
        $a = row('SELECT SUM(clicks) c, SUM(impressions) i, AVG(position) p FROM gsc_queries WHERE query = ? AND day >= UTC_DATE() - INTERVAL 28 DAY', [$k['keyword']]);
        $b = row('SELECT AVG(position) p FROM gsc_queries WHERE query = ? AND day BETWEEN UTC_DATE() - INTERVAL 56 DAY AND UTC_DATE() - INTERVAL 29 DAY', [$k['keyword']]);
        $k += ['c' => $a['c'], 'i' => $a['i'], 'p' => $a['p'], 'pp' => $b['p']];
    }
    unset($k);
    $h = '';
    if (can('seo.edit')) $h .= a_card('Track a keyword', a_form('<input type="hidden" name="action" value="add"><div class="cols3">' . a_field('text', 'keyword', 'Keyword', '', ['required' => 1, 'placeholder' => 'bitcoin price']) . a_field('text', 'target_url', 'Target page', '', ['placeholder' => '/currencies/bitcoin/']) . a_field('text', 'notes', 'Notes') . '</div>' . a_submit('Add')));
    $h .= a_card(count($kws) . ' tracked keywords', a_table(['keyword' => 'Keyword', 'target_url' => ['label' => 'Target', 'cls' => 'mono'], 'p' => ['label' => 'Avg position (28d)', 'cls' => 'num', 'fmt' => fn($r, $v) => $v ? number_format($v, 1) : '—'],
        'chg' => ['label' => 'Trend', 'cls' => 'num', 'fmt' => fn($r) => ($r['p'] && $r['pp']) ? '<span class="' . ($r['p'] < $r['pp'] ? 'up' : 'down') . '">' . ($r['p'] < $r['pp'] ? '▲ ' : '▼ ') . number_format(abs($r['pp'] - $r['p']), 1) . '</span>' : '—'],
        'i' => ['label' => 'Impressions', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'c' => ['label' => 'Clicks', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)],
        'x' => ['label' => '', 'fmt' => fn($r) => can('seo.edit') ? a_btn('delete', '✕', ['id' => $r['id']], 'sm ghost', 'Stop tracking?') : '']], $kws, 'No keywords tracked yet.')
        . a_help('Position data is Google’s own, from Search Console (average over the last 28 days vs the 28 before). We do not scrape search results.') . a_source('Google Search Console'));
    return $h;
}

// ------------------------------------------------------------------ audit
function seo_audit(): string
{
    require_once APP_DIR . '/lib/seoaudit.php';
    if (is_post() && a_action() === 'run') {
        require_perm('seo.edit');
        @set_time_limit(180);
        $r = seo_audit_run(90);
        audit('seo.audit.run', 'seo', null, null, ['critical' => $r['summary']['critical'], 'warnings' => $r['summary']['warning']]);
        a_go('seo/audit', 'Audit finished.');
    }
    $r = json_decode((string)kv_get('seo_audit', ''), true);
    $h = a_card('Automated technical audit', '<p class="muted">Fetches a sample of ~45 of your own URLs (home, lists, top coins, history, converters, exchanges, articles) plus robots.txt and sitemaps, and checks titles, descriptions, canonicals, headings, structured data, status codes and speed. It also runs weekly via cron and raises an alert on critical findings.</p>'
        . (can('seo.edit') ? a_btn('run', 'Run audit now (takes up to a minute)', [], '') : '') . ($r ? '<p class="muted small">Last run: ' . e($r['at']) . ' UTC against ' . e($r['base']) . '</p>' : '<p class="muted">Never run.</p>'));
    if (!$r) return $h;
    $s = $r['summary'];
    $h .= '<div class="kpis">' . a_kpi('Critical issues', (string)$s['critical'], '', $s['critical'] ? 'bad' : '') . a_kpi('Warnings', (string)$s['warning'], '', $s['warning'] ? 'warn' : '') . a_kpi('Passed checks', (string)$s['ok']) . '</div>';
    $h .= a_card('Site-wide checks', a_table(['0' => 'Check', '1' => ['label' => 'Result', 'fmt' => fn($row, $v) => a_status_badge($v === 'ok' ? 'ok' : $v)], '2' => ['label' => 'Detail', 'cls' => 'wrap']], array_map(fn($x) => [$x[0], $x[1], $x[2]], $r['site'])));
    $pages = array_map(function ($p) {
        $p['n'] = count($p['issues']);
        $p['iss'] = $p['issues'] ? implode('<br>', array_map(fn($i) => a_badge($i[0], $i[0] === 'critical' ? 'bad' : 'warn') . ' ' . e($i[1]), $p['issues'])) : a_badge('no issues', 'ok');
        return $p;
    }, $r['pages']);
    usort($pages, fn($a, $b) => $b['n'] <=> $a['n']);
    $h .= a_card('Pages (' . count($pages) . ' sampled)', a_table(['path' => ['label' => 'URL', 'cls' => 'wrap mono', 'fmt' => fn($row, $v) => '<a href="' . e($v) . '" target="_blank" rel="noopener">' . e($v) . '</a>'], 'code' => 'HTTP', 'ms' => ['label' => 'ms', 'cls' => 'num'], 'iss' => ['label' => 'Findings', 'cls' => 'wrap', 'fmt' => fn($row, $v) => $v]], $pages));
    return $h;
}
