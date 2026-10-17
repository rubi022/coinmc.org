<?php
/** Admin UI helpers: URLs, forms, tables, filters, CSV and server-rendered SVG charts (no JS dependencies). */

function a_url(string $path = '', array $q = []): string
{
    $u = '/admin/' . ($path === '' ? '' : trim($path, '/') . '/');
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return $u . ($q ? '?' . http_build_query($q) : '');
}

function a_go(string $path, ?string $msg = null, string $type = 'ok', array $q = []): void
{
    if ($msg) flash($msg, $type);
    go(a_url($path, $q));
}

function a_in(string $k, $default = ''): string { return trim((string)($_POST[$k] ?? $_GET[$k] ?? $default)); }
function a_int(string $k, int $default = 0): int { return (int)($_POST[$k] ?? $_GET[$k] ?? $default); }
function a_action(): string { return is_post() ? (string)($_POST['action'] ?? '') : ''; }

function a_badge(string $text, string $cls = ''): string { return '<span class="pill ' . e($cls) . '">' . e($text) . '</span>'; }

function a_status_badge(string $s): string
{
    $map = ['active' => 'ok', 'approved' => 'ok', 'published' => 'ok', 'verified' => 'ok', 'resolved' => 'ok', 'ok' => 'ok',
            'pending' => 'warn', 'draft' => '', 'paused' => 'warn', 'warning' => 'warn', 'scheduled' => 'info', 'open' => 'warn',
            'hidden' => '', 'delisted' => 'bad', 'rejected' => 'bad', 'ended' => '', 'suspended' => 'bad', 'scam' => 'bad', 'critical' => 'bad', 'info' => 'info'];
    return a_badge($s, $map[$s] ?? '');
}

function a_kpi(string $label, string $value, string $sub = '', string $cls = ''): string
{
    return '<div class="kpi ' . e($cls) . '"><span>' . e($label) . '</span><strong>' . $value . '</strong>' . ($sub !== '' ? '<small>' . $sub . '</small>' : '') . '</div>';
}

function a_delta($now, $prev): string
{
    $now = (float)$now; $prev = (float)$prev;
    if ($prev <= 0) return $now > 0 ? '<span class="up">new</span>' : '';
    $d = ($now - $prev) / $prev * 100;
    return '<span class="' . ($d >= 0 ? 'up' : 'down') . '">' . ($d >= 0 ? '▲ ' : '▼ ') . number_format(abs($d), 1) . '%</span> vs prev.';
}

function a_card(string $title, string $body, string $actions = '', string $cls = ''): string
{
    return '<section class="card ' . e($cls) . '"><div class="card-h"><h2>' . e($title) . '</h2><div>' . $actions . '</div></div>' . $body . '</section>';
}

function a_help(string $t): string { return '<p class="help">' . $t . '</p>'; }
function a_source(string $s): string { return '<span class="src" title="Where this data comes from">Source: ' . e($s) . '</span>'; }

function a_form(string $inner, string $action = '', string $attrs = ''): string
{
    return '<form method="post" action="' . e($action) . '" ' . $attrs . '>' . csrf_field() . $inner . '</form>';
}

/** One form control. $o: options (select: value=>label), hint, required, rows, attrs, placeholder */
function a_field(string $type, string $name, string $label, $value = '', array $o = []): string
{
    $id = 'f_' . preg_replace('~\W~', '_', $name);
    $req = !empty($o['required']) ? ' required' : '';
    $attrs = ($o['attrs'] ?? '') . $req . (isset($o['placeholder']) ? ' placeholder="' . e($o['placeholder']) . '"' : '') . (isset($o['max']) ? ' maxlength="' . (int)$o['max'] . '"' : '');
    $hint = isset($o['hint']) ? '<small class="help">' . $o['hint'] . '</small>' : '';
    switch ($type) {
        case 'textarea':
            $c = '<textarea id="' . $id . '" name="' . e($name) . '" rows="' . ($o['rows'] ?? 4) . '" ' . $attrs . '>' . e($value) . '</textarea>'; break;
        case 'select':
            $c = '<select id="' . $id . '" name="' . e($name) . '" ' . $attrs . '>';
            foreach ($o['options'] as $k => $l) $c .= '<option value="' . e($k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . e($l) . '</option>';
            $c .= '</select>'; break;
        case 'checkbox':
            return '<label class="chk"><input type="checkbox" name="' . e($name) . '" value="1"' . ($value ? ' checked' : '') . ' ' . ($o['attrs'] ?? '') . '> ' . e($label) . $hint . '</label>';
        default:
            $c = '<input id="' . $id . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '" ' . $attrs . '>';
    }
    return '<label class="fld" for="' . $id . '"><span>' . e($label) . '</span>' . $c . $hint . '</label>';
}

function a_submit(string $label = 'Save', string $cls = ''): string { return '<button class="btn ' . e($cls) . '" type="submit">' . e($label) . '</button>'; }

/** Small POST button (confirm optional) used for row actions. */
function a_btn(string $action, string $label, array $hidden = [], string $cls = 'sm ghost', ?string $confirm = null, string $to = ''): string
{
    $h = '<input type="hidden" name="action" value="' . e($action) . '">';
    foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return '<form method="post" action="' . e($to) . '" class="inline"' . ($confirm ? ' onsubmit="return confirm(\'' . e(addslashes($confirm)) . '\')"' : '') . '>' . csrf_field() . $h
        . '<button class="btn ' . e($cls) . '" type="submit">' . e($label) . '</button></form>';
}

/**
 * Table. $cols: key => label | key => ['label'=>, 'fmt'=>callable(row, value): html (trusted), 'cls'=>'num'].
 * Cells are escaped unless a 'fmt' is supplied.
 */
function a_table(array $cols, array $rows, string $empty = 'Nothing here yet.'): string
{
    if (!$rows) return '<p class="muted pad">' . e($empty) . '</p>';
    $h = '<div class="tbl-wrap"><table class="tbl"><thead><tr>';
    foreach ($cols as $k => $c) { $c = is_array($c) ? $c : ['label' => $c]; $h .= '<th class="' . e($c['cls'] ?? '') . '">' . e($c['label']) . '</th>'; }
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach ($cols as $k => $c) {
            $c = is_array($c) ? $c : ['label' => $c];
            $v = $r[$k] ?? null;
            $h .= '<td class="' . e($c['cls'] ?? '') . '">' . (isset($c['fmt']) ? $c['fmt']($r, $v) : e($v)) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

function a_pager(int $page, int $pages, string $path, array $q = []): string
{
    if ($pages <= 1) return '';
    $h = '<div class="pager">';
    $set = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn($p) => $p >= 1 && $p <= $pages)); sort($set);
    $prev = 0;
    foreach ($set as $p) {
        if ($p - $prev > 1) $h .= '<span>…</span>';
        $h .= $p === $page ? '<b>' . $p . '</b>' : '<a href="' . e(a_url($path, $q + ['page' => $p])) . '">' . $p . '</a>';
        $prev = $p;
    }
    return $h . '</div>';
}

function a_tabs(string $base, array $tabs, string $active): string
{
    $h = '<nav class="tabs">';
    foreach ($tabs as $k => $l) $h .= '<a class="' . ($k === $active ? 'on' : '') . '" href="' . e(a_url($base . ($k === '' ? '' : '/' . $k))) . '">' . e($l) . '</a>';
    return $h . '</nav>';
}

/** Date range from ?range=7|30|90|custom&from=&to=. Returns [from, to, days, label, prevFrom, prevTo]. */
function a_range(): array
{
    $r = a_in('range', '30');
    $today = gmdate('Y-m-d');
    if ($r === 'custom' && preg_match('~^\d{4}-\d\d-\d\d$~', a_in('from')) && preg_match('~^\d{4}-\d\d-\d\d$~', a_in('to'))) {
        $from = min(a_in('from'), a_in('to')); $to = min($today, max(a_in('from'), a_in('to')));
    } else {
        $r = in_array($r, ['1', '7', '14', '30', '90', '180'], true) ? $r : '30';
        $to = $today; $from = gmdate('Y-m-d', time() - ((int)$r - 1) * 86400);
    }
    $days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
    $pt = gmdate('Y-m-d', strtotime($from) - 86400); $pf = gmdate('Y-m-d', strtotime($from) - $days * 86400);
    return [$from, $to, $days, $r === 'custom' ? "$from → $to" : "Last $r days", $pf, $pt];
}

function a_range_picker(string $path, array $keep = []): string
{
    $cur = a_in('range', '30');
    $h = '<form class="rangepick" method="get" action="' . e(a_url($path)) . '">';
    foreach ($keep as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    $h .= '<select name="range" onchange="this.form.submit()">';
    foreach (['1' => 'Today', '7' => 'Last 7 days', '14' => 'Last 14 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '180' => 'Last 180 days', 'custom' => 'Custom…'] as $k => $l)
        $h .= '<option value="' . $k . '"' . ($cur === (string)$k ? ' selected' : '') . '>' . $l . '</option>';
    $h .= '</select>';
    if ($cur === 'custom') $h .= '<input type="date" name="from" value="' . e(a_in('from')) . '"><input type="date" name="to" value="' . e(a_in('to')) . '"><button class="btn sm" type="submit">Apply</button>';
    return $h . '</form>';
}

/** Streams a CSV download and exits. Neutralises spreadsheet formula injection. */
function a_csv(string $filename, array $header, array $rows): void
{
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('~[^\w.-]~', '_', $filename) . '"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    $safe = function ($v) { $v = (string)$v; return $v !== '' && strpos("=+-@\t\r", $v[0]) !== false ? "'" . $v : $v; };
    fputcsv($o, $header);
    foreach ($rows as $r) fputcsv($o, array_map($safe, array_values($r)));
    fclose($o);
    exit;
}

function a_money($v): string { return ($v < 0 ? '−' : '') . '$' . number_format(abs((float)$v), 2); }
function a_n($v, int $d = 0): string { return number_format((float)$v, $d); }
function a_pct($a, $b, int $d = 1): string { return $b > 0 ? number_format($a / $b * 100, $d) . '%' : '—'; }

// ------------------------------------------------------------------ SVG charts

function chart_nice_max(float $m): float
{
    if ($m <= 0) return 1;
    $p = pow(10, floor(log10($m)));
    foreach ([1, 2, 2.5, 5, 10] as $s) if ($m <= $s * $p) return $s * $p;
    return 10 * $p;
}

function chart_fmt(float $v): string
{
    if ($v >= 1e9) return round($v / 1e9, 1) . 'B';
    if ($v >= 1e6) return round($v / 1e6, 1) . 'M';
    if ($v >= 1e4) return round($v / 1e3, 1) . 'K';
    return $v == floor($v) ? (string)(int)$v : (string)round($v, 2);
}

/** Line/area chart. $series: [['name'=>, 'values'=>[], 'color'=>'#..']]. $labels: x labels (same length). */
function svg_line(array $series, array $labels, array $o = []): string
{
    $W = 760; $H = $o['h'] ?? 220; $pl = 46; $pr = 10; $pt = 10; $pb = 24;
    $n = count($labels);
    if ($n < 2) return '<p class="muted pad">Not enough data yet for this chart.</p>';
    $max = 0; foreach ($series as $s) foreach ($s['values'] as $v) $max = max($max, (float)$v);
    $ymax = chart_nice_max($max);
    $x = fn($i) => $pl + ($W - $pl - $pr) * $i / ($n - 1);
    $y = fn($v) => $pt + ($H - $pt - $pb) * (1 - $v / $ymax);
    $g = '';
    for ($t = 0; $t <= 4; $t++) {
        $v = $ymax * $t / 4; $yy = round($y($v), 1);
        $g .= '<line class="grid" x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $yy . '" y2="' . $yy . '"/><text class="ax" x="' . ($pl - 6) . '" y="' . ($yy + 4) . '" text-anchor="end">' . chart_fmt($v) . '</text>';
    }
    foreach ([0, (int)floor(($n - 1) / 2), $n - 1] as $i) $g .= '<text class="ax" x="' . round($x($i), 1) . '" y="' . ($H - 6) . '" text-anchor="' . ($i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle')) . '">' . e(substr($labels[$i], 5) ?: $labels[$i]) . '</text>';
    $out = '';
    foreach ($series as $si => $s) {
        $c = $s['color'] ?? ['#3861fb', '#16c784', '#f5a623', '#ea3943', '#8b5cf6'][$si % 5];
        $d = ''; foreach ($s['values'] as $i => $v) $d .= ($i ? 'L' : 'M') . round($x($i), 1) . ' ' . round($y((float)$v), 1);
        if (!empty($o['area']) || $si === 0 && count($series) === 1) $out .= '<path d="' . $d . 'L' . round($x($n - 1), 1) . ' ' . ($H - $pb) . 'L' . $pl . ' ' . ($H - $pb) . 'Z" fill="' . $c . '" opacity=".10"/>';
        $out .= '<path d="' . $d . '" fill="none" stroke="' . $c . '" stroke-width="2" stroke-linejoin="round"/>';
        if ($n <= 45) foreach ($s['values'] as $i => $v) $out .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y((float)$v), 1) . '" r="6" fill="transparent"><title>' . e($labels[$i] . ' — ' . ($s['name'] ?? '') . ': ' . chart_fmt((float)$v)) . '</title></circle>';
    }
    $legend = '';
    if (count($series) > 1) foreach ($series as $si => $s) $legend .= '<span><i style="background:' . ($s['color'] ?? ['#3861fb', '#16c784', '#f5a623', '#ea3943', '#8b5cf6'][$si % 5]) . '"></i>' . e($s['name'] ?? '') . '</span>';
    return '<div class="chart"><svg viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-label="' . e($o['title'] ?? 'chart') . '">' . $g . $out . '</svg>' . ($legend ? '<div class="legend">' . $legend . '</div>' : '') . '</div>';
}

/** Vertical bars (single series, optionally stacked with 2 series). */
function svg_bars(array $values, array $labels, array $o = []): string
{
    $W = 760; $H = $o['h'] ?? 200; $pl = 46; $pr = 10; $pt = 10; $pb = 24; $n = count($values);
    if (!$n) return '<p class="muted pad">No data yet.</p>';
    $max = chart_nice_max((float)max($values) ?: 1);
    $bw = ($W - $pl - $pr) / $n; $c = $o['color'] ?? '#3861fb';
    $g = '';
    for ($t = 0; $t <= 4; $t++) { $v = $max * $t / 4; $yy = round($pt + ($H - $pt - $pb) * (1 - $v / $max), 1); $g .= '<line class="grid" x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $yy . '" y2="' . $yy . '"/><text class="ax" x="' . ($pl - 6) . '" y="' . ($yy + 4) . '" text-anchor="end">' . chart_fmt($v) . '</text>'; }
    $b = '';
    foreach ($values as $i => $v) {
        $h = ($H - $pt - $pb) * ($v / $max);
        $b .= '<rect x="' . round($pl + $i * $bw + $bw * .12, 1) . '" y="' . round($H - $pb - $h, 1) . '" width="' . round($bw * .76, 1) . '" height="' . round($h, 1) . '" fill="' . $c . '" rx="2"><title>' . e($labels[$i] . ': ' . chart_fmt((float)$v)) . '</title></rect>';
        if ($n <= 14 || $i % (int)ceil($n / 8) === 0) $g .= '<text class="ax" x="' . round($pl + $i * $bw + $bw / 2, 1) . '" y="' . ($H - 6) . '" text-anchor="middle">' . e(mb_substr($labels[$i], 0, 10)) . '</text>';
    }
    return '<div class="chart"><svg viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none">' . $g . $b . '</svg></div>';
}

/** Horizontal bar list: $items = [[label, value, extraHtml?]] */
function hbars(array $items, string $fmt = 'n'): string
{
    if (!$items) return '<p class="muted pad">No data yet.</p>';
    $max = max(array_map(fn($i) => (float)$i[1], $items)) ?: 1;
    $h = '<div class="hbars">';
    foreach ($items as $i) {
        $v = (float)$i[1];
        $h .= '<div class="hb"><span class="hl" title="' . e($i[0]) . '">' . ($i[2] ?? e($i[0])) . '</span><span class="hv"><i style="width:' . round($v / $max * 100, 1) . '%"></i></span><b>' . ($fmt === 'money' ? a_money($v) : a_n($v)) . '</b></div>';
    }
    return $h . '</div>';
}

/** Donut with legend. $parts = [[label, value]] */
function svg_donut(array $parts): string
{
    $tot = array_sum(array_map(fn($p) => (float)$p[1], $parts));
    if ($tot <= 0) return '<p class="muted pad">No data yet.</p>';
    $cols = ['#3861fb', '#16c784', '#f5a623', '#8b5cf6', '#ea3943', '#14b8d4', '#e056a0', '#94a3b8'];
    $r = 15.9155; $off = 0; $arcs = ''; $leg = '';
    foreach ($parts as $i => $p) {
        $pct = (float)$p[1] / $tot * 100; $c = $cols[$i % count($cols)];
        $arcs .= '<circle r="' . $r . '" cx="21" cy="21" fill="transparent" stroke="' . $c . '" stroke-width="6" stroke-dasharray="' . round($pct, 2) . ' ' . round(100 - $pct, 2) . '" stroke-dashoffset="' . round(25 - $off, 2) . '"><title>' . e($p[0] . ': ' . a_n($p[1]) . ' (' . round($pct, 1) . '%)') . '</title></circle>';
        $off += $pct;
        $leg .= '<li><i style="background:' . $c . '"></i><span>' . e($p[0]) . '</span><b>' . round($pct, 1) . '%</b></li>';
    }
    return '<div class="donut"><svg viewBox="0 0 42 42" width="130" height="130">' . $arcs . '</svg><ul>' . $leg . '</ul></div>';
}
