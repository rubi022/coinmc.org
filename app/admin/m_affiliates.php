<?php
/** Affiliate & partner links: /go/{slug}/ redirects with click logging, manual conversion/commission entry, EPC reporting. */

function m_affiliates(string $sub): void
{
    if ($sub === 'edit') { aff_edit(a_int('id')); return; }
    if ($sub !== '') { http_response_code(404); admin_page('Not found', '<div class="card">Unknown page.</div>'); return; }
    [$from, $to] = a_range();
    if (is_post() && a_action() === 'conversion') {
        require_perm('affiliates.edit');
        $lid = a_int('link_id');
        if (!row('SELECT id FROM affiliate_links WHERE id = ?', [$lid])) a_go('affiliates', 'Unknown link.', 'err');
        $n = max(1, a_int('conversions', 1)); $amt = max(0, (float)a_in('commission'));
        $day = preg_match('~^\d{4}-\d\d-\d\d$~', a_in('day')) ? a_in('day') : gmdate('Y-m-d');
        q('INSERT INTO affiliate_conversions (link_id, day, conversions, commission, note, created_by) VALUES (?,?,?,?,?,?)', [$lid, $day, $n, $amt, mb_substr(a_in('note'), 0, 200) ?: null, current_user()['id']]);
        audit('affiliate.conversion', 'affiliate_link', $lid, null, ['conversions' => $n, 'commission' => $amt, 'day' => $day]);
        a_go('affiliates', 'Conversion recorded. Record the actual payout in Finance → Ledger when it arrives.');
    }
    $links = rows('SELECT l.*, (SELECT COUNT(*) FROM affiliate_clicks k WHERE k.link_id = l.id AND k.day BETWEEN ? AND ?) clicks,
        (SELECT COALESCE(SUM(conversions),0) FROM affiliate_conversions v WHERE v.link_id = l.id AND v.day BETWEEN ? AND ?) conv,
        (SELECT COALESCE(SUM(commission),0) FROM affiliate_conversions v WHERE v.link_id = l.id AND v.day BETWEEN ? AND ?) comm FROM affiliate_links l ORDER BY clicks DESC, l.id DESC', [$from, $to, $from, $to, $from, $to]);
    $tc = array_sum(array_column($links, 'clicks')); $tv = array_sum(array_column($links, 'conv')); $tm = array_sum(array_column($links, 'comm'));
    $h = '<div style="display:flex;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px"><span>' . (can('affiliates.edit') ? '<a class="btn" href="' . e(a_url('affiliates/edit')) . '">+ New affiliate link</a>' : '') . '</span>' . a_range_picker('affiliates') . '</div>';
    $h .= '<div class="kpis">' . a_kpi('Clicks', a_n($tc)) . a_kpi('Conversions (recorded)', a_n($tv), 'click→conv ' . a_pct($tv, $tc)) . a_kpi('Commission', a_money($tm)) . a_kpi('EPC', $tc ? a_money($tm / $tc) : '—', 'earnings per click') . '</div>';
    $h .= a_card(count($links) . ' links', a_table(['name' => ['label' => 'Link', 'fmt' => fn($r, $v) => '<a href="' . e(a_url('affiliates/edit', ['id' => $r['id']])) . '"><strong>' . e($v) . '</strong></a><br><span class="muted small mono">' . e(rtrim(cfg('site_url'), '/') . '/go/' . $r['slug'] . '/') . '</span>'],
        'partner' => 'Partner', 'enabled' => ['label' => 'State', 'fmt' => fn($r, $v) => $v ? a_badge('on', 'ok') : a_badge('off')], 'clicks' => ['label' => 'Clicks', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'conv' => ['label' => 'Conv.', 'cls' => 'num'],
        'cr' => ['label' => 'CR', 'cls' => 'num', 'fmt' => fn($r) => a_pct($r['conv'], $r['clicks'])], 'comm' => ['label' => 'Commission', 'cls' => 'num', 'fmt' => fn($r, $v) => a_money($v)], 'epc' => ['label' => 'EPC', 'cls' => 'num', 'fmt' => fn($r) => $r['clicks'] ? a_money($r['comm'] / $r['clicks']) : '—'],
        'x' => ['label' => '', 'fmt' => fn($r) => can('affiliates.edit') ? '<details><summary class="small">+ conversion</summary>' . a_form('<input type="hidden" name="action" value="conversion"><input type="hidden" name="link_id" value="' . (int)$r['id'] . '">' . a_field('number', 'conversions', 'Conversions', 1, ['attrs' => ' min="1"']) . a_field('number', 'commission', 'Commission (USD)', '', ['attrs' => ' step="0.01" min="0"']) . a_field('date', 'day', 'Date', gmdate('Y-m-d')) . a_field('text', 'note', 'Note') . a_submit('Add', 'sm')) . '</details>' : '']], $links, 'No affiliate links yet.')
        . a_help('Links are served through <code>/go/slug/</code> (nofollow, noindex, excluded from caching). Conversions come from the partner’s dashboard, so you enter them here. Disclose affiliate relationships on the page where you link — the footer’s Advertising page covers the site-wide disclosure.'));
    $days = rows('SELECT day, COUNT(*) n FROM affiliate_clicks WHERE day BETWEEN ? AND ? GROUP BY day', [$from, $to]);
    $m = array_column($days, 'n', 'day'); $labels = []; for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) $labels[] = gmdate('Y-m-d', $t);
    $h .= a_card('Clicks per day', svg_bars(array_map(fn($d) => (int)($m[$d] ?? 0), $labels), $labels, ['h' => 170, 'color' => '#8b5cf6']));
    $src = rows('SELECT from_path, COUNT(*) n FROM affiliate_clicks WHERE day BETWEEN ? AND ? AND from_path IS NOT NULL GROUP BY from_path ORDER BY n DESC LIMIT 10', [$from, $to]);
    $h .= a_card('Pages that drive clicks', hbars(array_map(fn($r) => [$r['from_path'], $r['n']], $src)));
    admin_page('Affiliate links', $h);
}

function aff_edit(int $id): void
{
    require_perm('affiliates.view');
    $l = $id ? row('SELECT * FROM affiliate_links WHERE id = ?', [$id]) : null;
    if (is_post()) {
        require_perm('affiliates.edit');
        if (a_action() === 'save') {
            $slug = slugify(a_in('slug') ?: a_in('name'));
            $url = safe_url(a_in('target_url'));
            if (a_in('name') === '' || !$url) a_go('affiliates/edit', 'Name and a valid http(s) destination are required.', 'err', $id ? ['id' => $id] : []);
            if (in_array($slug, ['ad', 'admin'], true)) a_go('affiliates/edit', 'That slug is reserved.', 'err', $id ? ['id' => $id] : []);
            $dup = row('SELECT id FROM affiliate_links WHERE slug = ? AND id <> ?', [$slug, $id]);
            if ($dup) a_go('affiliates/edit', 'Slug already in use.', 'err', $id ? ['id' => $id] : []);
            $d = [mb_substr(a_in('name'), 0, 160), $slug, mb_substr(a_in('partner'), 0, 120) ?: null, $url, mb_substr(a_in('commission_note'), 0, 200) ?: null, isset($_POST['enabled']) ? 1 : 0];
            if ($l) { q('UPDATE affiliate_links SET name=?, slug=?, partner=?, target_url=?, commission_note=?, enabled=? WHERE id=?', array_merge($d, [$id])); audit('affiliate.update', 'affiliate_link', $id, $l, array_combine(['name', 'slug', 'partner', 'target_url', 'commission_note', 'enabled'], $d)); a_go('affiliates', 'Saved.'); }
            q('INSERT INTO affiliate_links (name, slug, partner, target_url, commission_note, enabled) VALUES (?,?,?,?,?,?)', $d);
            audit('affiliate.create', 'affiliate_link', (int)db()->lastInsertId(), null, ['slug' => $slug, 'target_url' => $url]);
            a_go('affiliates', 'Link created.');
        }
        if (a_action() === 'delete' && $l) { audit('affiliate.delete', 'affiliate_link', $id, $l); foreach (['affiliate_clicks', 'affiliate_conversions'] as $t) q("DELETE FROM $t WHERE link_id = ?", [$id]); q('DELETE FROM affiliate_links WHERE id = ?', [$id]); a_go('affiliates', 'Deleted with its click history.'); }
    }
    $l = $l ?: ['name' => '', 'slug' => '', 'partner' => '', 'target_url' => '', 'commission_note' => '', 'enabled' => 1];
    $dis = can('affiliates.edit') ? '' : ' disabled';
    $h = '<p><a href="' . e(a_url('affiliates')) . '">← Affiliate links</a></p>' . a_card($id ? 'Edit link' : 'New affiliate link', a_form('<input type="hidden" name="action" value="save"><div class="cols2">'
        . a_field('text', 'name', 'Name', $l['name'], ['required' => 1, 'attrs' => $dis]) . a_field('text', 'slug', 'Short URL slug', $l['slug'], ['hint' => 'Becomes /go/<slug>/ — letters, numbers, dashes.', 'attrs' => $dis])
        . a_field('text', 'partner', 'Partner / network', $l['partner'], ['attrs' => $dis]) . a_field('text', 'commission_note', 'Commission terms (internal)', $l['commission_note'], ['attrs' => $dis]) . '</div>'
        . a_field('text', 'target_url', 'Destination (your tracked affiliate URL)', $l['target_url'], ['required' => 1, 'attrs' => $dis]) . a_field('checkbox', 'enabled', 'Enabled', $l['enabled'], ['attrs' => $dis]) . (can('affiliates.edit') ? a_submit() : '')));
    if ($id && can('affiliates.edit')) $h .= a_card('Danger zone', a_btn('delete', 'Delete link and its click history', [], 'danger', 'Delete this link permanently?'));
    admin_page($id ? 'Edit affiliate link' : 'New affiliate link', $h);
}
