<?php
/** Cryptocurrency + exchange listings control. Status/flags are honoured by every public query (see COIN_OK). */

const COIN_STATUSES = ['active' => 'Active (public)', 'pending' => 'Pending review', 'hidden' => 'Hidden (404, kept in DB)', 'delisted' => 'Delisted (410 Gone)'];
const COIN_FLAGS = ['none' => 'None', 'warning' => 'Warning banner', 'scam' => 'Scam — noindex + banner, removed from lists'];
const COIN_OVERRIDE_FIELDS = ['name' => 'Name', 'image' => 'Logo URL', 'homepage' => 'Website', 'description' => 'Description (HTML allowed: p, a, strong, em, ul, li)',
    'circulating_supply' => 'Circulating supply', 'total_supply' => 'Total supply', 'max_supply' => 'Max supply', 'rank_no' => 'Rank (manual)', 'price' => 'Price USD (manual)', 'market_cap' => 'Market cap USD (manual)', 'volume_24h' => '24h volume USD (manual)'];

function coin_cache_clear(): void { foreach (glob(STORAGE . '/cache/*') ?: [] as $f) if (is_file($f) && substr($f, -4) !== '.key') @unlink($f); }

function m_coins(string $sub): void
{
    if ($sub === 'new') { coin_new(); return; }
    if ($sub === 'edit' || a_int('id') > 0) { coin_edit(a_int('id')); return; }
    if ($sub !== '') { http_response_code(404); admin_page('Not found', '<div class="card">Unknown page.</div>'); return; }

    if (a_action() === 'bulk' && can('crypto.edit')) {
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        $op = a_in('op');
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $map = ['status:active' => "status = 'active'", 'status:hidden' => "status = 'hidden'", 'status:delisted' => "status = 'delisted'", 'verify:1' => 'verified = 1', 'verify:0' => 'verified = 0'];
            if (isset($map[$op])) {
                q("UPDATE coins SET {$map[$op]} WHERE id IN ($ph)", $ids);
                audit('coin.bulk', 'coin', implode(',', array_slice($ids, 0, 20)), null, ['op' => $op, 'count' => count($ids)]);
                coin_cache_clear();
                a_go('coins', count($ids) . ' coin(s) updated: ' . $op);
            } elseif (in_array($op, ['flag:none', 'flag:warning', 'flag:scam'], true) && can('crypto.flag')) {
                q("UPDATE coins SET risk_flag = ? WHERE id IN ($ph)", array_merge([substr($op, 5)], $ids));
                audit('coin.bulk', 'coin', implode(',', array_slice($ids, 0, 20)), null, ['op' => $op, 'count' => count($ids)]);
                coin_cache_clear();
                a_go('coins', count($ids) . ' coin(s) updated: ' . $op);
            }
        }
        a_go('coins', 'Nothing changed.', 'warn');
    }

    $q = a_in('q'); $st = a_in('status'); $fl = a_in('flag'); $src = a_in('source'); $ver = a_in('verified');
    $w = ['1=1']; $p = [];
    if ($q !== '') { $w[] = '(name LIKE ? OR symbol LIKE ? OR slug LIKE ?)'; $l = '%' . addcslashes($q, '%_') . '%'; array_push($p, $l, $l, $l); }
    if (isset(COIN_STATUSES[$st])) { $w[] = 'status = ?'; $p[] = $st; }
    if (isset(COIN_FLAGS[$fl])) { $w[] = 'risk_flag = ?'; $p[] = $fl; }
    if (in_array($src, ['sync', 'manual'], true)) { $w[] = 'listing_source = ?'; $p[] = $src; }
    if ($ver === '1' || $ver === '0') { $w[] = 'verified = ?'; $p[] = (int)$ver; }
    $W = implode(' AND ', $w);
    $per = 50; $page = max(1, a_int('page', 1));
    $total = (int)val("SELECT COUNT(*) FROM coins WHERE $W", $p);
    $sort = ['rank' => 'rank_no IS NULL, rank_no', 'new' => 'created_at DESC', 'updated' => 'updated_at DESC', 'mcap' => 'market_cap DESC'][a_in('sort', 'rank')] ?? 'rank_no IS NULL, rank_no';
    $list = rows("SELECT id, slug, symbol, name, image, rank_no, price, market_cap, status, verified, risk_flag, listing_source, updated_at FROM coins WHERE $W ORDER BY $sort LIMIT $per OFFSET " . (($page - 1) * $per), $p);

    $opt = fn($arr, $cur, $all) => '<option value="">' . $all . '</option>' . implode('', array_map(fn($k, $l) => '<option value="' . e($k) . '"' . ((string)$cur === (string)$k ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($arr), $arr));
    $h = '<form class="filters" method="get"><input type="search" name="q" value="' . e($q) . '" placeholder="Search name, symbol, slug…">'
        . '<select name="status">' . $opt(COIN_STATUSES, $st, 'Any status') . '</select><select name="flag">' . $opt(COIN_FLAGS, $fl, 'Any risk flag') . '</select>'
        . '<select name="source">' . $opt(['sync' => 'Synced from API', 'manual' => 'Manually listed'], $src, 'Any source') . '</select><select name="verified">' . $opt(['1' => 'Verified', '0' => 'Not verified'], $ver, 'Verified?') . '</select>'
        . '<select name="sort">' . $opt(['rank' => 'Sort: rank', 'mcap' => 'Sort: market cap', 'new' => 'Sort: newest', 'updated' => 'Sort: last update'], a_in('sort', 'rank'), '') . '</select><button class="btn" type="submit">Filter</button>'
        . (can('crypto.edit') ? ' <a class="btn ghost" href="' . e(a_url('coins/new')) . '">+ Add coin manually</a>' : '') . '</form>';

    $cols = ['id' => ['label' => '', 'fmt' => fn($r) => can('crypto.edit') ? '<input type="checkbox" name="ids[]" value="' . (int)$r['id'] . '">' : ''],
        'rank_no' => ['label' => '#', 'cls' => 'num'],
        'name' => ['label' => 'Coin', 'fmt' => fn($r) => '<a href="' . e(a_url('coins/edit', ['id' => $r['id']])) . '"><strong>' . e($r['name']) . '</strong></a> <span class="muted">' . e(strtoupper($r['symbol'])) . '</span>'],
        'price' => ['label' => 'Price', 'cls' => 'num', 'fmt' => fn($r, $v) => fmt_price($v)], 'market_cap' => ['label' => 'Market cap', 'cls' => 'num', 'fmt' => fn($r, $v) => fmt_big($v)],
        'status' => ['label' => 'Status', 'fmt' => fn($r, $v) => a_status_badge($v)],
        'verified' => ['label' => 'Verified', 'fmt' => fn($r, $v) => $v ? a_badge('✓ verified', 'ok') : '<span class="muted">—</span>'],
        'risk_flag' => ['label' => 'Risk', 'fmt' => fn($r, $v) => $v === 'none' ? '<span class="muted">—</span>' : a_status_badge($v)],
        'listing_source' => ['label' => 'Source', 'fmt' => fn($r, $v) => a_badge($v === 'manual' ? 'manual' : 'API', $v === 'manual' ? 'info' : '')],
        'updated_at' => ['label' => 'Updated', 'fmt' => fn($r, $v) => e(time_ago($v))],
        'v' => ['label' => '', 'fmt' => fn($r) => '<a class="small" target="_blank" rel="noopener" href="/currencies/' . e($r['slug']) . '/">view ↗</a>']];
    $bulk = '';
    if (can('crypto.edit')) {
        $bulk = '<div class="filters" style="margin-top:10px"><select name="op"><option value="">Bulk action…</option><option value="status:active">Set Active</option><option value="status:hidden">Set Hidden</option><option value="status:delisted">Set Delisted</option><option value="verify:1">Mark verified</option><option value="verify:0">Remove verified</option>'
            . (can('crypto.flag') ? '<option value="flag:none">Clear risk flag</option><option value="flag:warning">Flag: warning</option><option value="flag:scam">Flag: scam</option>' : '') . '</select><input type="hidden" name="action" value="bulk">' . a_submit('Apply to selected', 'ghost') . '</div>';
    }
    $h .= a_card(a_n($total) . ' coins', a_form(a_table($cols, $list, 'No coins match.') . $bulk), '', '');
    $qs = array_filter(['q' => $q, 'status' => $st, 'flag' => $fl, 'source' => $src, 'verified' => $ver, 'sort' => a_in('sort')]);
    $h .= a_pager($page, (int)ceil($total / $per), 'coins', $qs);
    admin_page('Coins', $h);
}

function coin_edit(int $id): void
{
    $c = row('SELECT * FROM coins WHERE id = ?', [$id]);
    if (!$c) { http_response_code(404); admin_page('Not found', '<div class="card">Coin not found. <a href="' . e(a_url('coins')) . '">Back</a></div>'); return; }
    $ov = array_column(rows('SELECT field, value FROM coin_overrides WHERE coin_id = ?', [$id]), 'value', 'field');

    if (is_post()) {
        $act = a_action();
        if ($act === 'save') {
            require_perm('crypto.flag');
            $before = ['status' => $c['status'], 'verified' => $c['verified'], 'risk_flag' => $c['risk_flag'], 'admin_notes' => $c['admin_notes'], 'merged_into' => $c['merged_into']];
            $after = $before;
            $after['risk_flag'] = isset(COIN_FLAGS[a_in('risk_flag')]) ? a_in('risk_flag') : $c['risk_flag'];
            if (can('crypto.edit')) {
                $after['status'] = isset(COIN_STATUSES[a_in('status')]) ? a_in('status') : $c['status'];
                $after['verified'] = isset($_POST['verified']) ? 1 : 0;
                $after['admin_notes'] = mb_substr(a_in('admin_notes'), 0, 2000) ?: null;
                $mslug = a_in('merged_slug');
                $after['merged_into'] = null;
                if ($mslug !== '') {
                    $t = row('SELECT id FROM coins WHERE slug = ? AND id <> ?', [$mslug, $id]);
                    if (!$t) a_go('coins/edit', 'Merge target “' . $mslug . '” was not found.', 'err', ['id' => $id]);
                    $after['merged_into'] = (int)$t['id'];
                }
            }
            q('UPDATE coins SET status = ?, verified = ?, risk_flag = ?, admin_notes = ?, merged_into = ? WHERE id = ?', [$after['status'], $after['verified'], $after['risk_flag'], $after['admin_notes'], $after['merged_into'], $id]);
            audit('coin.update', 'coin', $id, $before, $after);
            coin_cache_clear();
            a_go('coins/edit', 'Saved.', 'ok', ['id' => $id]);
        }
        if ($act === 'overrides') {
            require_perm('crypto.edit');
            $changes = [];
            foreach (COIN_OVERRIDE_FIELDS as $f => $_) {
                $val = trim((string)($_POST['ov_' . $f] ?? ''));
                if (in_array($f, ['circulating_supply', 'total_supply', 'max_supply', 'rank_no', 'price', 'market_cap', 'volume_24h'], true) && $val !== '' && !is_numeric($val)) a_go('coins/edit', "“$f” must be a number.", 'err', ['id' => $id]);
                if ($f === 'image' && $val !== '' && !safe_url($val)) a_go('coins/edit', 'Logo must be an http(s) URL.', 'err', ['id' => $id]);
                if ($f === 'homepage' && $val !== '' && !safe_url($val)) a_go('coins/edit', 'Website must be an http(s) URL.', 'err', ['id' => $id]);
                if ($f === 'description') $val = html_sanitize($val);
                $old = $ov[$f] ?? '';
                if ($val === (string)$old) continue;
                if ($val === '') q('DELETE FROM coin_overrides WHERE coin_id = ? AND field = ?', [$id, $f]);
                else {
                    q('REPLACE INTO coin_overrides (coin_id, field, value, set_by, set_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$id, $f, $val, current_user()['id']]);
                    q("UPDATE coins SET `$f` = ? WHERE id = ?", [$val, $id]);
                }
                $changes[$f] = [$old, $val];
            }
            if ($changes) { audit('coin.override', 'coin', $id, array_map(fn($x) => $x[0], $changes), array_map(fn($x) => $x[1], $changes)); coin_cache_clear(); }
            a_go('coins/edit', $changes ? 'Overrides saved. They are re-applied after every market sync; cleared fields return to API values on the next sync.' : 'No override changes.', 'ok', ['id' => $id]);
        }
        if ($act === 'delete_manual') {
            require_perm('crypto.edit');
            if ($c['listing_source'] !== 'manual') a_go('coins/edit', 'Only manually-listed coins can be deleted; hide or delist synced coins instead.', 'err', ['id' => $id]);
            audit('coin.delete', 'coin', $id, ['slug' => $c['slug'], 'name' => $c['name']]);
            q('DELETE FROM coin_overrides WHERE coin_id = ?', [$id]); q('DELETE FROM coins WHERE id = ?', [$id]);
            coin_cache_clear();
            a_go('coins', 'Coin deleted.');
        }
    }

    $can = can('crypto.edit');
    $dis = $can ? '' : ' disabled';
    $h = '<p><a href="' . e(a_url('coins')) . '">← All coins</a> · <a target="_blank" rel="noopener" href="/currencies/' . e($c['slug']) . '/">Public page ↗</a> · <a href="' . e(a_url('seo/meta', ['path' => '/currencies/' . $c['slug'] . '/'])) . '">Edit SEO metadata</a></p>';
    $h .= '<div class="kpis">' . a_kpi('Price', fmt_price($c['price'])) . a_kpi('Market cap', fmt_big($c['market_cap'])) . a_kpi('24h volume', fmt_big($c['volume_24h'])) . a_kpi('Rank', $c['rank_no'] ? '#' . (int)$c['rank_no'] : '—')
        . a_kpi('Source', e($c['listing_source'] === 'manual' ? 'Manual listing' : 'API sync'), 'price via ' . e($c['price_source'] ?: '—')) . a_kpi('Last update', e(time_ago($c['updated_at']))) . '</div>';
    $h .= '<div class="grid g2">';
    $h .= a_card('Listing control', a_form(
        '<input type="hidden" name="action" value="save">'
        . a_field('select', 'status', 'Status', $c['status'], ['options' => COIN_STATUSES, 'attrs' => $dis, 'hint' => 'Hidden/pending return 404, delisted returns 410 Gone. All three are removed from lists, search, sitemaps and the API.'])
        . a_field('checkbox', 'verified', 'Verified listing (shows a ✓ badge)', $c['verified'], ['attrs' => $dis])
        . a_field('select', 'risk_flag', 'Risk flag', $c['risk_flag'], ['options' => COIN_FLAGS, 'attrs' => can('crypto.flag') ? '' : ' disabled'])
        . a_field('text', 'merged_slug', 'Merge duplicate into (target slug)', $c['merged_into'] ? (string)val('SELECT slug FROM coins WHERE id = ?', [$c['merged_into']]) : '', ['attrs' => $dis, 'hint' => 'Public URL of this coin will 301-redirect to the target.'])
        . a_field('textarea', 'admin_notes', 'Internal notes (never public)', $c['admin_notes'], ['rows' => 3, 'attrs' => $dis])
        . (can('crypto.flag') ? a_submit('Save listing') : '')
    ));
    $fields = '<input type="hidden" name="action" value="overrides">' . a_help('Leave blank to use the API value. Anything you fill in is written to the coin and re-applied after every sync.');
    foreach (COIN_OVERRIDE_FIELDS as $f => $l) {
        $cur = $ov[$f] ?? '';
        $fields .= a_field($f === 'description' ? 'textarea' : 'text', 'ov_' . $f, $l . ($cur !== '' ? ' — overridden' : ''), $cur, ['rows' => 4, 'attrs' => $dis, 'placeholder' => $f === 'description' ? '' : (string)($c[$f] ?? '')]);
    }
    $h .= a_card('Data overrides', a_form($fields . ($can ? a_submit('Save overrides') : '')));
    $h .= '</div>';
    if ($can && $c['listing_source'] === 'manual') $h .= a_card('Danger zone', a_btn('delete_manual', 'Delete this manual coin permanently', [], 'danger', 'Delete this coin and its overrides?'));
    admin_page($c['name'], $h);
}

function coin_new(): void
{
    require_perm('crypto.edit');
    if (is_post()) {
        $name = a_in('name'); $sym = strtolower(a_in('symbol')); $slug = slugify(a_in('slug') ?: $name);
        if ($name === '' || !preg_match('~^[a-z0-9]{1,20}$~', $sym)) a_go('coins/new', 'Name and a symbol (letters/numbers) are required.', 'err');
        if (row('SELECT id FROM coins WHERE slug = ?', [$slug])) a_go('coins/new', "Slug “$slug” already exists.", 'err');
        foreach (['price', 'market_cap', 'volume_24h', 'circulating_supply'] as $f) if (a_in($f) !== '' && !is_numeric(a_in($f))) a_go('coins/new', "$f must be a number.", 'err');
        $status = a_in('status') === 'active' ? 'active' : 'pending';
        q("INSERT INTO coins (cg_id, slug, symbol, name, image, price, market_cap, volume_24h, circulating_supply, description, homepage, price_source, status, listing_source, updated_at, created_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?, 'manual', ?, 'manual', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            ['manual-' . $slug, $slug, $sym, $name, safe_url(a_in('image')), a_in('price') ?: null, a_in('market_cap') ?: null, a_in('volume_24h') ?: null, a_in('circulating_supply') ?: null,
             html_sanitize(a_in('description')) ?: null, safe_url(a_in('homepage')), $status]);
        $id = (int)db()->lastInsertId();
        audit('coin.create', 'coin', $id, null, ['slug' => $slug, 'name' => $name, 'status' => $status]);
        coin_cache_clear();
        a_go('coins/edit', 'Coin created as ' . $status . '.', 'ok', ['id' => $id]);
    }
    $h = a_card('Add a coin manually', a_help('For assets the API sources don’t cover. Manual coins are never overwritten or un-ranked by syncs. They start as <b>pending</b> unless you choose Active.')
        . a_form('<div class="cols2">' . a_field('text', 'name', 'Name', '', ['required' => 1]) . a_field('text', 'symbol', 'Symbol', '', ['required' => 1]) . a_field('text', 'slug', 'URL slug', '', ['hint' => 'Defaults to the name.'])
        . a_field('select', 'status', 'Initial status', 'pending', ['options' => ['pending' => 'Pending review', 'active' => 'Active immediately']])
        . a_field('text', 'price', 'Price (USD)') . a_field('text', 'market_cap', 'Market cap (USD)') . a_field('text', 'volume_24h', '24h volume (USD)') . a_field('text', 'circulating_supply', 'Circulating supply')
        . a_field('text', 'image', 'Logo URL') . a_field('text', 'homepage', 'Website') . '</div>' . a_field('textarea', 'description', 'Description', '', ['rows' => 5]) . a_submit('Create coin')));
    admin_page('Add coin', $h);
}

// ============================================================ EXCHANGES
const EX_STATUSES = ['active' => 'Active (public)', 'hidden' => 'Hidden', 'delisted' => 'Delisted'];

function m_exchanges(string $sub): void
{
    $id = a_int('id');
    if ($id > 0 || $sub === 'edit') { exchange_edit($id); return; }
    $q = a_in('q'); $st = a_in('status');
    $w = ['1=1']; $p = [];
    if ($q !== '') { $w[] = '(name LIKE ? OR slug LIKE ?)'; $l = '%' . addcslashes($q, '%_') . '%'; array_push($p, $l, $l); }
    if (isset(EX_STATUSES[$st])) { $w[] = 'status = ?'; $p[] = $st; }
    $list = rows('SELECT * FROM exchanges WHERE ' . implode(' AND ', $w) . ' ORDER BY trust_rank IS NULL, trust_rank LIMIT 300', $p);
    $h = '<form class="filters" method="get"><input type="search" name="q" value="' . e($q) . '" placeholder="Search exchanges…"><select name="status"><option value="">Any status</option>'
        . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . ($st === $k ? ' selected' : '') . '>' . e($l) . '</option>', array_keys(EX_STATUSES), EX_STATUSES)) . '</select><button class="btn" type="submit">Filter</button></form>';
    $btc = (float)val("SELECT price FROM coins WHERE cg_id='bitcoin'");
    $h .= a_card(count($list) . ' exchanges', a_table([
        'trust_rank' => ['label' => '#', 'cls' => 'num'], 'name' => ['label' => 'Exchange', 'fmt' => fn($r) => '<a href="' . e(a_url('exchanges/edit', ['id' => $r['id']])) . '"><strong>' . e($r['name']) . '</strong></a>'],
        'ex_type' => ['label' => 'Type', 'fmt' => fn($r, $v) => e($v ?: 'CEX')], 'trust_score' => ['label' => 'Trust', 'cls' => 'num'],
        'volume_24h_btc' => ['label' => '24h volume', 'cls' => 'num', 'fmt' => fn($r, $v) => fmt_big($v * $btc)],
        'status' => ['label' => 'Status', 'fmt' => fn($r, $v) => a_status_badge($v)], 'verified' => ['label' => 'Verified', 'fmt' => fn($r, $v) => $v ? a_badge('✓', 'ok') : '—'],
        'risk_note' => ['label' => 'Risk note', 'cls' => 'wrap'], 'updated_at' => ['label' => 'Updated', 'fmt' => fn($r, $v) => e(time_ago($v))],
    ], $list));
    admin_page('Exchanges', $h);
}

function exchange_edit(int $id): void
{
    $x = row('SELECT * FROM exchanges WHERE id = ?', [$id]);
    if (!$x) { http_response_code(404); admin_page('Not found', '<div class="card">Exchange not found.</div>'); return; }
    if (is_post() && a_action() === 'save') {
        require_perm('crypto.edit');
        $after = ['status' => isset(EX_STATUSES[a_in('status')]) ? a_in('status') : $x['status'], 'verified' => isset($_POST['verified']) ? 1 : 0, 'ex_type' => in_array(a_in('ex_type'), ['CEX', 'DEX', 'Derivatives'], true) ? a_in('ex_type') : 'CEX',
            'risk_note' => mb_substr(a_in('risk_note'), 0, 250) ?: null, 'description' => html_sanitize(a_in('description')) ?: null, 'url' => safe_url(a_in('url')), 'country' => mb_substr(a_in('country'), 0, 120) ?: null];
        $before = array_intersect_key($x, $after);
        q('UPDATE exchanges SET status = ?, verified = ?, ex_type = ?, risk_note = ? WHERE id = ?', array_merge(array_values($after), [$id]));
        audit('exchange.update', 'exchange', $id, $before, $after);
        coin_cache_clear();
        a_go('exchanges/edit', 'Saved.', 'ok', ['id' => $id]);
    }
    $dis = can('crypto.edit') ? '' : ' disabled';
    $h = '<p><a href="' . e(a_url('exchanges')) . '">← All exchanges</a> · <a target="_blank" rel="noopener" href="/exchanges/' . e($x['slug']) . '/">Public page ↗</a></p>'
        . a_card($x['name'], a_form('<input type="hidden" name="action" value="save"><div class="cols2">'
        . a_field('select', 'status', 'Status', $x['status'], ['options' => EX_STATUSES, 'attrs' => $dis]) . a_field('select', 'ex_type', 'Type', $x['ex_type'] ?: 'CEX', ['options' => ['CEX' => 'Centralized', 'DEX' => 'Decentralized', 'Derivatives' => 'Derivatives'], 'attrs' => $dis])
        . '</div>'
        . a_field('checkbox', 'verified', 'Verified exchange', $x['verified'], ['attrs' => $dis])
        . a_field('text', 'risk_note', 'Risk / compliance note (internal)', $x['risk_note'], ['max' => 250, 'attrs' => $dis, 'hint' => 'e.g. “Unlicensed in US”, “Withdrawal issues reported”. Not shown publicly.'])
        . (can('crypto.edit') ? a_submit() : '')));
    admin_page($x['name'], $h);
}
