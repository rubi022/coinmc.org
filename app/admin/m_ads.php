<?php
/** Advertising: advertisers, campaigns with a mandatory review workflow, inventory/fill, performance. Every paid unit renders with a fixed "Sponsored" label. */

const AD_STATUS_FLOW = ['draft' => 'Draft', 'pending' => 'Pending review', 'approved' => 'Live (approved)', 'rejected' => 'Rejected', 'paused' => 'Paused', 'ended' => 'Ended'];
const AD_KINDS = ['banner' => 'Banner image', 'native' => 'Native card (text + logo)', 'sponsored_coin' => 'Sponsored coin listing'];
const AD_PRICING = ['flat' => 'Flat fee (total contract value)', 'cpm' => 'CPM (per 1,000 impressions)', 'cpc' => 'CPC (per valid click)'];

function m_ads(string $sub): void
{
    $tabs = ['' => 'Campaigns', 'advertisers' => 'Advertisers', 'inventory' => 'Inventory & fill', 'report' => 'Performance'];
    if ($sub === 'edit') { ad_edit(a_int('id')); return; }
    if (!isset($tabs[$sub])) { http_response_code(404); admin_page('Not found', '<div class="card">Unknown page.</div>'); return; }
    $body = ['' => 'ad_list', 'advertisers' => 'ad_advertisers', 'inventory' => 'ad_inventory', 'report' => 'ad_report'][$sub]();
    admin_page('Ads · ' . $tabs[$sub], a_tabs('ads', $tabs, $sub) . $body);
}

function ad_totals(string $from, string $to): array
{
    $m = [];
    foreach (rows('SELECT campaign_id, SUM(impressions) i, SUM(clicks) c, SUM(invalid_clicks) ic FROM ad_stats WHERE day BETWEEN ? AND ? GROUP BY campaign_id', [$from, $to]) as $r) $m[$r['campaign_id']] = $r;
    return $m;
}

/** Revenue recognised for a campaign: usage-based = spent; flat = contract value once it has started. */
function ad_booked($c): float
{
    if ($c['pricing'] === 'flat') return in_array($c['status'], ['approved', 'ended', 'paused'], true) ? (float)$c['rate'] : 0.0;
    return (float)$c['spent'];
}

function ad_list(): string
{
    $st = a_in('status'); $pl = a_in('placement'); $q = a_in('q');
    $w = ['1=1']; $p = [];
    if (isset(AD_STATUS_FLOW[$st])) { $w[] = 'c.status = ?'; $p[] = $st; }
    if (isset(ad_slots()[$pl])) { $w[] = 'c.placement = ?'; $p[] = $pl; }
    if ($q !== '') { $w[] = '(c.name LIKE ? OR a.name LIKE ?)'; $p[] = $p[] = '%' . addcslashes($q, '%_') . '%'; }
    $rows = rows('SELECT c.*, a.name adv FROM ad_campaigns c LEFT JOIN ad_advertisers a ON a.id = c.advertiser_id WHERE ' . implode(' AND ', $w) . ' ORDER BY FIELD(c.status,\'pending\',\'approved\',\'paused\',\'draft\',\'rejected\',\'ended\'), c.id DESC LIMIT 300', $p);
    $tot = ad_totals('2000-01-01', '2100-01-01');
    foreach ($rows as &$r) { $t = $tot[$r['id']] ?? ['i' => 0, 'c' => 0, 'ic' => 0]; $r['i'] = (int)$t['i']; $r['c'] = (int)$t['c']; $r['booked'] = ad_booked($r); }
    unset($r);
    $opt = fn($arr, $cur, $all) => '<option value="">' . $all . '</option>' . implode('', array_map(fn($k, $l) => '<option value="' . e($k) . '"' . ((string)$cur === (string)$k ? ' selected' : '') . '>' . e(is_array($l) ? $l['label'] : $l) . '</option>', array_keys($arr), $arr));
    $h = '<form class="filters" method="get"><input type="search" name="q" value="' . e($q) . '" placeholder="Search campaign or advertiser…"><select name="status">' . $opt(AD_STATUS_FLOW, $st, 'Any status') . '</select><select name="placement">' . $opt(ad_slots(), $pl, 'Any placement') . '</select><button class="btn" type="submit">Filter</button>'
        . (can('ads.edit') ? ' <a class="btn ghost" href="' . e(a_url('ads/edit')) . '">+ New campaign</a>' : '') . '</form>';
    $h .= a_card(count($rows) . ' campaigns', a_table([
        'name' => ['label' => 'Campaign', 'fmt' => fn($r, $v) => '<a href="' . e(a_url('ads/edit', ['id' => $r['id']])) . '"><strong>' . e($v) . '</strong></a><br><span class="muted small">' . e($r['adv'] ?: 'no advertiser') . '</span>'],
        'status' => ['label' => 'Status', 'fmt' => fn($r, $v) => a_status_badge($v)], 'placement' => ['label' => 'Placement', 'fmt' => fn($r, $v) => e(ad_slots()[$v]['label'] ?? $v)],
        'i' => ['label' => 'Impr.', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'c' => ['label' => 'Clicks', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'ctr' => ['label' => 'CTR', 'cls' => 'num', 'fmt' => fn($r) => a_pct($r['c'], $r['i'], 2)],
        'booked' => ['label' => 'Booked revenue', 'cls' => 'num', 'fmt' => fn($r, $v) => a_money($v)], 'budget' => ['label' => 'Budget', 'cls' => 'num', 'fmt' => fn($r, $v) => $v > 0 ? a_money($v) : '∞'],
        'end_at' => ['label' => 'Ends', 'fmt' => fn($r, $v) => $v ? e(substr($v, 0, 10)) : '—'],
    ], $rows, 'No campaigns yet.') . a_help('Booked revenue: CPM/CPC = delivery so far; flat fee = contract value once live. Cash actually received belongs in Finance → Ledger.'));
    return $h;
}

function ad_flags(array $c): array
{
    $f = [];
    $text = mb_strtolower(($c['title'] ?? '') . ' ' . ($c['body'] ?? '') . ' ' . ($c['cta'] ?? ''));
    foreach (['guaranteed', 'risk-free', 'risk free', '100x', '1000x', 'get rich', 'no risk', 'can\'t lose', 'double your', 'passive income', 'financial freedom'] as $w) if (strpos($text, $w) !== false) $f[] = "Contains “$w” — misleading return claims are not allowed.";
    if ($c['target_url'] && !preg_match('~^https://~i', $c['target_url'])) $f[] = 'Destination is not HTTPS.';
    if ($c['kind'] === 'banner' && !$c['image_url']) $f[] = 'Banner has no image.';
    if ($c['image_url'] && !preg_match('~^https://~i', $c['image_url'])) $f[] = 'Image is not served over HTTPS (mixed content).';
    if (!$c['end_at']) $f[] = 'No end date — open-ended campaigns need periodic review.';
    return $f;
}

function ad_can_self_approve(): bool
{
    $n = 0;
    foreach (rows("SELECT role FROM users WHERE status = 'active' AND role <> 'user'") as $u) if (role_has($u['role'], 'ads.approve')) $n++;
    return $n <= 1;
}

function ad_edit(int $id): void
{
    require_perm('ads.view');
    $c = $id ? row('SELECT * FROM ad_campaigns WHERE id = ?', [$id]) : null;
    if ($id && !$c) { http_response_code(404); admin_page('Not found', '<div class="card">Campaign not found.</div>'); return; }
    $me = current_user();
    if (is_post()) {
        $act = a_action();
        if ($act === 'save') {
            require_perm('ads.edit');
            $d = ['name' => mb_substr(a_in('name'), 0, 160), 'advertiser_id' => a_int('advertiser_id') ?: null, 'placement' => a_in('placement'), 'kind' => a_in('kind'), 'title' => mb_substr(a_in('title'), 0, 160) ?: null, 'body' => mb_substr(a_in('body'), 0, 300) ?: null,
                'image_url' => safe_url(a_in('image_url')), 'target_url' => safe_url(a_in('target_url')), 'cta' => mb_substr(a_in('cta'), 0, 40) ?: null, 'pricing' => a_in('pricing'), 'rate' => max(0, (float)a_in('rate')), 'budget' => max(0, (float)a_in('budget')),
                'start_at' => a_in('start_at') ? gmdate('Y-m-d H:i:s', strtotime(a_in('start_at') . ' UTC')) : null, 'end_at' => a_in('end_at') ? gmdate('Y-m-d H:i:s', strtotime(a_in('end_at') . ' UTC')) : null,
                'devices' => implode(',', array_intersect((array)($_POST['devices'] ?? []), ['desktop', 'mobile', 'tablet'])) ?: null,
                'countries' => implode(',', array_filter(array_map(fn($x) => preg_match('~^[A-Z]{2}$~', $x) ? $x : null, array_map('trim', explode(',', strtoupper(a_in('countries'))))))) ?: null,
                'freq_cap' => max(0, min(100, a_int('freq_cap'))), 'priority' => max(1, min(10, a_int('priority', 5)))];
            $err = null;
            if ($d['name'] === '') $err = 'Name is required.';
            elseif (!isset(ad_slots()[$d['placement']])) $err = 'Choose a placement.';
            elseif (!isset(AD_KINDS[$d['kind']])) $err = 'Choose a format.';
            elseif (!isset(AD_PRICING[$d['pricing']])) $err = 'Choose pricing.';
            elseif (!$d['target_url']) $err = 'Destination must be a valid http(s) URL.';
            elseif ($d['kind'] !== 'banner' && !$d['title']) $err = 'Native and sponsored-listing ads need a headline.';
            elseif ($d['start_at'] && $d['end_at'] && $d['end_at'] <= $d['start_at']) $err = 'End must be after start.';
            if ($err) a_go('ads/edit', $err, 'err', $id ? ['id' => $id] : []);
            $reset = false;
            if ($c) {
                $creative = ['kind', 'title', 'body', 'image_url', 'target_url', 'cta'];
                foreach ($creative as $k) if ((string)$c[$k] !== (string)($d[$k] ?? '')) $reset = true;
                $d['status'] = ($reset && in_array($c['status'], ['approved', 'paused'], true)) ? 'pending' : ($c['status'] === 'rejected' ? 'draft' : $c['status']);
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($d)));
                q("UPDATE ad_campaigns SET $sets WHERE id = ?", array_merge(array_values($d), [$id]));
                audit('ad.update', 'ad_campaign', $id, array_intersect_key($c, $d), $d);
                a_go('ads/edit', $reset && $d['status'] === 'pending' ? 'Saved. Creative or destination changed, so the campaign went back to “Pending review”.' : 'Saved.', $reset && $d['status'] === 'pending' ? 'warn' : 'ok', ['id' => $id]);
            }
            $d['status'] = 'draft'; $d['created_by'] = $me['id'];
            q('INSERT INTO ad_campaigns (`' . implode('`,`', array_keys($d)) . '`) VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')', array_values($d));
            $nid = (int)db()->lastInsertId();
            audit('ad.create', 'ad_campaign', $nid, null, $d);
            a_go('ads/edit', 'Campaign created as draft. Submit it for review when ready.', 'ok', ['id' => $nid]);
        }
        if ($c && in_array($act, ['submit', 'approve', 'reject', 'pause', 'resume', 'end', 'reopen'], true)) {
            $from = $c['status']; $to = null; $extra = '';
            if ($act === 'submit') { require_perm('ads.edit'); if (in_array($from, ['draft', 'rejected'], true)) $to = 'pending'; }
            if ($act === 'approve' || $act === 'reject') {
                require_perm('ads.approve');
                if ($from !== 'pending') a_go('ads/edit', 'Only pending campaigns can be reviewed.', 'err', ['id' => $id]);
                if ($act === 'approve') {
                    if ((int)$c['created_by'] === (int)$me['id'] && !ad_can_self_approve()) a_go('ads/edit', 'Four-eyes rule: another reviewer must approve a campaign you created.', 'err', ['id' => $id]);
                    if (empty($_POST['policy_ok'])) a_go('ads/edit', 'Tick the policy confirmation to approve.', 'err', ['id' => $id]);
                    $adv = $c['advertiser_id'] ? row('SELECT verified, status FROM ad_advertisers WHERE id = ?', [$c['advertiser_id']]) : null;
                    if (!$adv || !$adv['verified'] || $adv['status'] !== 'active') a_go('ads/edit', 'The advertiser must be a verified, active advertiser before a campaign can go live.', 'err', ['id' => $id]);
                    $to = 'approved';
                    q('UPDATE ad_campaigns SET approved_by = ?, approved_at = UTC_TIMESTAMP(), reject_reason = NULL WHERE id = ?', [$me['id'], $id]);
                } else {
                    $why = mb_substr(a_in('reason'), 0, 300);
                    if ($why === '') a_go('ads/edit', 'Please give a reason for rejecting.', 'err', ['id' => $id]);
                    $to = 'rejected'; q('UPDATE ad_campaigns SET reject_reason = ? WHERE id = ?', [$why, $id]); $extra = $why;
                }
            }
            if ($act === 'pause' && $from === 'approved') { require_perm('ads.edit'); $to = 'paused'; }
            if ($act === 'resume' && $from === 'paused') { require_perm('ads.edit'); $to = 'approved'; }
            if ($act === 'end' && in_array($from, ['approved', 'paused'], true)) { require_perm('ads.edit'); $to = 'ended'; }
            if ($act === 'reopen' && $from === 'ended') { require_perm('ads.approve'); $to = 'pending'; }
            if (!$to) a_go('ads/edit', 'That action is not available for status “' . $from . '”.', 'err', ['id' => $id]);
            q('UPDATE ad_campaigns SET status = ? WHERE id = ?', [$to, $id]);
            audit('ad.' . $act, 'ad_campaign', $id, ['status' => $from], ['status' => $to] + ($extra ? ['reason' => $extra] : []));
            a_go('ads/edit', "Campaign is now “$to”.", 'ok', ['id' => $id]);
        }
        if ($c && $act === 'delete') {
            require_perm('ads.edit');
            if (!in_array($c['status'], ['draft', 'rejected'], true)) a_go('ads/edit', 'Only drafts and rejected campaigns can be deleted. End the campaign instead to keep its history.', 'err', ['id' => $id]);
            audit('ad.delete', 'ad_campaign', $id, ['name' => $c['name']]);
            q('DELETE FROM ad_campaigns WHERE id = ?', [$id]); a_go('ads', 'Deleted.');
        }
    }

    $c = $c ?: ['id' => 0, 'name' => '', 'advertiser_id' => null, 'placement' => 'home_top', 'kind' => 'banner', 'title' => '', 'body' => '', 'image_url' => '', 'target_url' => '', 'cta' => 'Learn more', 'pricing' => 'flat', 'rate' => 0, 'budget' => 0,
        'spent' => 0, 'start_at' => null, 'end_at' => null, 'devices' => '', 'countries' => '', 'freq_cap' => 0, 'priority' => 5, 'status' => 'new', 'reject_reason' => null, 'created_by' => null];
    $can = can('ads.edit');
    $dis = $can ? '' : ' disabled';
    $advs = ['' => '— choose advertiser —'] + array_column(rows('SELECT id, CONCAT(name, IF(verified, \' ✓\', \' (unverified)\')) n FROM ad_advertisers WHERE status = \'active\' ORDER BY name'), 'n', 'id');
    $h = '<p><a href="' . e(a_url('ads')) . '">← All campaigns</a></p>';
    if ($c['id']) {
        $t = row('SELECT COALESCE(SUM(impressions),0) i, COALESCE(SUM(clicks),0) c, COALESCE(SUM(invalid_clicks),0) ic FROM ad_stats WHERE campaign_id = ?', [$c['id']]);
        $h .= '<div class="kpis">' . a_kpi('Status', a_status_badge($c['status']) . ($c['reject_reason'] ? '<br><small class="down">' . e($c['reject_reason']) . '</small>' : '')) . a_kpi('Impressions', a_n($t['i'])) . a_kpi('Valid clicks', a_n($t['c']), 'CTR ' . a_pct($t['c'], $t['i'], 2) . ' · ' . a_n($t['ic']) . ' invalid')
            . a_kpi('Booked revenue', a_money(ad_booked($c)), $c['budget'] > 0 ? 'of ' . a_money($c['budget']) . ' budget' : 'no budget cap') . '</div>';
        $flags = ad_flags($c);
        if ($flags && in_array($c['status'], ['draft', 'pending', 'rejected', 'approved'], true)) $h .= '<div class="warnbox"><strong>Review flags</strong><ul style="margin:6px 0 0 18px">' . implode('', array_map(fn($f) => '<li>' . e($f) . '</li>', $flags)) . '</ul></div>';
        // workflow buttons
        $wf = '';
        if (in_array($c['status'], ['draft', 'rejected'], true) && can('ads.edit')) $wf .= a_btn('submit', 'Submit for review', [], '');
        if ($c['status'] === 'pending' && can('ads.approve')) {
            $wf .= a_form('<input type="hidden" name="action" value="approve"><label class="chk"><input type="checkbox" name="policy_ok" value="1"> I reviewed the creative, destination and claims against the advertising policy (clear risk language, no guaranteed returns, no impersonation, destination safe).</label>' . a_submit('Approve & go live', 'ok'))
                . a_form('<input type="hidden" name="action" value="reject">' . a_field('text', 'reason', 'Rejection reason (shown to the team)', '', ['max' => 300]) . a_submit('Reject', 'danger'));
        }
        if ($c['status'] === 'approved') $wf .= a_btn('pause', 'Pause', [], 'ghost');
        if ($c['status'] === 'paused') $wf .= a_btn('resume', 'Resume', [], 'ok');
        if (in_array($c['status'], ['approved', 'paused'], true)) $wf .= a_btn('end', 'End campaign', [], 'danger', 'End this campaign?');
        if ($c['status'] === 'ended' && can('ads.approve')) $wf .= a_btn('reopen', 'Re-open (needs review)', [], 'ghost');
        if ($wf) $h .= a_card('Workflow', $wf);
    }
    $devs = array_filter(explode(',', (string)$c['devices']));
    $dchk = '';
    foreach (['desktop', 'mobile', 'tablet'] as $dv) $dchk .= '<label class="chk" style="display:inline-flex;margin-right:14px"><input type="checkbox" name="devices[]" value="' . $dv . '"' . (in_array($dv, $devs, true) ? ' checked' : '') . $dis . '> ' . $dv . '</label>';
    $dt = fn($v) => $v ? gmdate('Y-m-d\TH:i', strtotime($v . ' UTC')) : '';
    $form = '<input type="hidden" name="action" value="save"><div class="cols2"><div>'
        . a_field('text', 'name', 'Internal name', $c['name'], ['required' => 1, 'attrs' => $dis]) . a_field('select', 'advertiser_id', 'Advertiser', $c['advertiser_id'], ['options' => $advs, 'attrs' => $dis, 'hint' => 'Must be verified before approval.'])
        . a_field('select', 'placement', 'Placement', $c['placement'], ['options' => array_map(fn($s) => $s['label'] . ' — ' . $s['size'], ad_slots()), 'attrs' => $dis]) . a_field('select', 'kind', 'Format', $c['kind'], ['options' => AD_KINDS, 'attrs' => $dis])
        . a_field('text', 'title', 'Headline', $c['title'], ['max' => 160, 'attrs' => $dis]) . a_field('textarea', 'body', 'Description', $c['body'], ['rows' => 2, 'max' => 300, 'attrs' => $dis])
        . a_field('text', 'image_url', 'Image / logo URL (https)', $c['image_url'], ['attrs' => $dis]) . a_field('text', 'target_url', 'Destination URL (https)', $c['target_url'], ['required' => 1, 'attrs' => $dis]) . a_field('text', 'cta', 'Button text', $c['cta'], ['max' => 40, 'attrs' => $dis])
        . '</div><div>'
        . a_field('select', 'pricing', 'Pricing model', $c['pricing'], ['options' => AD_PRICING, 'attrs' => $dis]) . a_field('number', 'rate', 'Rate / fee (USD)', $c['rate'], ['attrs' => ' step="0.0001" min="0"' . $dis, 'hint' => 'Flat: total fee · CPM: per 1,000 · CPC: per click.'])
        . a_field('number', 'budget', 'Budget cap (USD, 0 = none)', $c['budget'], ['attrs' => ' step="0.01" min="0"' . $dis, 'hint' => 'Usage-priced campaigns end automatically when spend reaches the cap.'])
        . a_field('datetime-local', 'start_at', 'Start (UTC)', $dt($c['start_at']), ['attrs' => $dis]) . a_field('datetime-local', 'end_at', 'End (UTC)', $dt($c['end_at']), ['attrs' => $dis])
        . '<label class="fld"><span>Devices (none ticked = all)</span>' . $dchk . '</label>'
        . a_field('text', 'countries', 'Countries (ISO codes, comma separated; blank = all)', $c['countries'], ['placeholder' => 'US, GB, DE', 'attrs' => $dis, 'hint' => 'Needs a CDN country header (e.g. Cloudflare).'])
        . a_field('number', 'freq_cap', 'Frequency cap (impressions per visitor per day, 0 = none)', $c['freq_cap'], ['attrs' => ' min="0" max="100"' . $dis]) . a_field('number', 'priority', 'Priority (1–10, higher wins)', $c['priority'], ['attrs' => ' min="1" max="10"' . $dis])
        . '</div></div>' . ($can ? a_submit($c['id'] ? 'Save changes' : 'Create campaign') : '');
    $h .= a_card($c['id'] ? 'Campaign settings' : 'New campaign', a_form($form));
    if ($c['id']) {
        $h .= a_card('Preview (as visitors see it — always labelled)', ad_render($c + ['kind' => $c['kind']], $c['placement']) . '<style>.ad-slot{display:block}</style>');
        $log = rows("SELECT a.created_at, a.action, a.actor, a.after_json FROM audit_log a WHERE a.entity = 'ad_campaign' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 30", [(string)$c['id']]);
        $h .= a_card('Review history', a_table(['created_at' => 'UTC', 'action' => 'Action', 'actor' => 'By', 'after_json' => ['label' => 'Detail', 'cls' => 'wrap small mono', 'fmt' => fn($r, $v) => e(mb_strimwidth((string)$v, 0, 160, '…'))]], $log));
        $days = rows('SELECT day, SUM(impressions) i, SUM(clicks) c FROM ad_stats WHERE campaign_id = ? AND day >= UTC_DATE() - INTERVAL 29 DAY GROUP BY day', [$c['id']]);
        if ($days) { $m = array_column($days, null, 'day'); $labels = []; for ($t = time() - 29 * 86400; $t <= time(); $t += 86400) $labels[] = gmdate('Y-m-d', $t);
            $h .= a_card('Delivery — last 30 days', svg_line([['name' => 'Impressions', 'values' => array_map(fn($d) => (int)($m[$d]['i'] ?? 0), $labels)], ['name' => 'Clicks', 'values' => array_map(fn($d) => (int)($m[$d]['c'] ?? 0), $labels), 'color' => '#16c784']], $labels, ['h' => 170])); }
        if (can('ads.edit') && in_array($c['status'], ['draft', 'rejected'], true)) $h .= a_card('Danger zone', a_btn('delete', 'Delete campaign', [], 'danger', 'Delete this campaign permanently?'));
    }
    admin_page($c['id'] ? $c['name'] : 'New campaign', $h);
}

function ad_advertisers(): string
{
    if (is_post()) {
        $act = a_action();
        if ($act === 'save') {
            require_perm('ads.edit');
            $id = a_int('id'); $name = mb_substr(a_in('name'), 0, 160);
            if ($name === '') a_go('ads/advertisers', 'Name is required.', 'err');
            $d = [$name, mb_substr(a_in('contact_email'), 0, 190) ?: null, safe_url(a_in('website')), mb_substr(a_in('notes'), 0, 2000) ?: null, in_array(a_in('status'), ['active', 'blocked'], true) ? a_in('status') : 'active'];
            if ($id) {
                $old = row('SELECT * FROM ad_advertisers WHERE id = ?', [$id]);
                q('UPDATE ad_advertisers SET name=?, contact_email=?, website=?, notes=?, status=? WHERE id = ?', array_merge($d, [$id]));
                if (can('ads.approve') && (int)$old['verified'] !== (isset($_POST['verified']) ? 1 : 0)) { q('UPDATE ad_advertisers SET verified = ? WHERE id = ?', [isset($_POST['verified']) ? 1 : 0, $id]); audit('advertiser.verify', 'advertiser', $id, ['verified' => $old['verified']], ['verified' => isset($_POST['verified']) ? 1 : 0]); }
                audit('advertiser.update', 'advertiser', $id, array_intersect_key($old, array_flip(['name', 'contact_email', 'website', 'status'])), ['name' => $d[0], 'contact_email' => $d[1], 'website' => $d[2], 'status' => $d[4]]);
                if ($d[4] === 'blocked') { q("UPDATE ad_campaigns SET status = 'paused' WHERE advertiser_id = ? AND status = 'approved'", [$id]); }
            } else {
                q('INSERT INTO ad_advertisers (name, contact_email, website, notes, status) VALUES (?,?,?,?,?)', $d);
                audit('advertiser.create', 'advertiser', (int)db()->lastInsertId(), null, ['name' => $name]);
            }
            a_go('ads/advertisers', 'Saved.');
        }
    }
    $edit = a_int('edit') ? row('SELECT * FROM ad_advertisers WHERE id = ?', [a_int('edit')]) : null;
    $e = $edit ?: ['id' => 0, 'name' => '', 'contact_email' => '', 'website' => '', 'notes' => '', 'status' => 'active', 'verified' => 0];
    $h = '';
    if (can('ads.edit')) $h .= a_card($edit ? 'Edit advertiser' : 'New advertiser', a_form('<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . (int)$e['id'] . '"><div class="cols3">'
        . a_field('text', 'name', 'Company / project', $e['name'], ['required' => 1]) . a_field('email', 'contact_email', 'Contact email', $e['contact_email']) . a_field('text', 'website', 'Website', $e['website']) . '</div><div class="cols2">'
        . a_field('select', 'status', 'Status', $e['status'], ['options' => ['active' => 'Active', 'blocked' => 'Blocked (pauses live campaigns)']])
        . (can('ads.approve') ? a_field('checkbox', 'verified', 'Verified (identity, business and website checked)', $e['verified']) : '<p class="muted">Verification needs the “ads.approve” permission.</p>') . '</div>'
        . a_field('textarea', 'notes', 'Internal notes (KYC, contracts, past issues)', $e['notes'], ['rows' => 3]) . a_submit()));
    $rows = rows('SELECT a.*, (SELECT COUNT(*) FROM ad_campaigns c WHERE c.advertiser_id = a.id) campaigns, (SELECT COALESCE(SUM(spent),0) FROM ad_campaigns c WHERE c.advertiser_id = a.id) spent FROM ad_advertisers a ORDER BY a.name');
    $h .= a_card(count($rows) . ' advertisers', a_table(['name' => ['label' => 'Advertiser', 'fmt' => fn($r, $v) => '<a href="' . e(a_url('ads/advertisers', ['edit' => $r['id']])) . '"><strong>' . e($v) . '</strong></a>'], 'contact_email' => 'Contact', 'website' => ['label' => 'Website', 'cls' => 'wrap'],
        'verified' => ['label' => 'Verified', 'fmt' => fn($r, $v) => $v ? a_badge('✓ verified', 'ok') : a_badge('unverified', 'warn')], 'status' => ['label' => 'Status', 'fmt' => fn($r, $v) => a_status_badge($v)], 'campaigns' => ['label' => 'Campaigns', 'cls' => 'num'], 'spent' => ['label' => 'Usage spend', 'cls' => 'num', 'fmt' => fn($r, $v) => a_money($v)]], $rows, 'No advertisers yet.'));
    return $h;
}

function ad_inventory(): string
{
    if (is_post()) {
        $act = a_action();
        if ($act === 'toggle') { require_perm('ads.network'); $v = setting('ads_enabled', '1') === '1' ? '0' : '1'; audit('ads.toggle', 'setting', 'ads_enabled', ['v' => setting('ads_enabled', '1')], ['v' => $v]); set_setting('ads_enabled', $v); a_go('ads/inventory', 'Ads are now ' . ($v === '1' ? 'ON' : 'OFF') . ' site-wide.'); }
        if ($act === 'fallback') {
            require_perm('ads.network');
            $slot = a_in('slot'); if (!isset(ad_slots()[$slot])) a_go('ads/inventory', 'Unknown slot.', 'err');
            $code = (string)($_POST['code'] ?? '');
            audit('ads.fallback', 'setting', 'ad_fallback_' . $slot, ['len' => strlen((string)setting('ad_fallback_' . $slot, ''))], ['len' => strlen($code)]);
            set_setting('ad_fallback_' . $slot, $code); a_go('ads/inventory', 'Fallback saved for ' . $slot . '.');
        }
    }
    $rows = [];
    foreach (ad_slots() as $slot => $meta) {
        $r = row('SELECT COALESCE(SUM(requests),0) rq, COALESCE(SUM(filled),0) f FROM slot_impressions WHERE placement = ? AND day >= UTC_DATE() - INTERVAL 29 DAY', [$slot]);
        $s = row('SELECT COALESCE(SUM(impressions),0) i, COALESCE(SUM(clicks),0) c FROM ad_stats WHERE placement = ? AND day >= UTC_DATE() - INTERVAL 29 DAY', [$slot]);
        $live = (int)val("SELECT COUNT(*) FROM ad_campaigns WHERE placement = ? AND status = 'approved'", [$slot]);
        $rows[] = ['slot' => $slot, 'label' => $meta['label'], 'size' => $meta['size'], 'rq' => (int)$r['rq'], 'f' => (int)$r['f'], 'i' => (int)$s['i'], 'c' => (int)$s['c'], 'live' => $live];
    }
    $on = setting('ads_enabled', '1') === '1';
    $h = a_card('Site-wide switch', '<p>Ads are currently <b>' . ($on ? 'ON' : 'OFF') . '</b>. Turning them off hides every slot immediately (cached pages need no purge — slots load by JavaScript).</p>' . (can('ads.network') ? a_btn('toggle', $on ? 'Turn ads OFF' : 'Turn ads ON', [], $on ? 'danger' : 'ok') : ''));
    $h .= a_card('Slots — last 30 days', a_table(['label' => ['label' => 'Slot', 'fmt' => fn($r, $v) => '<strong>' . e($v) . '</strong><br><span class="muted small">' . e($r['slot']) . ' · ' . e($r['size']) . '</span>'], 'live' => ['label' => 'Live campaigns', 'cls' => 'num'], 'rq' => ['label' => 'Slot requests', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)],
        'f' => ['label' => 'Filled', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'fill' => ['label' => 'Fill rate', 'cls' => 'num', 'fmt' => fn($r) => a_pct($r['f'], $r['rq'])], 'i' => ['label' => 'Impressions', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'ctr' => ['label' => 'CTR', 'cls' => 'num', 'fmt' => fn($r) => a_pct($r['c'], $r['i'], 2)]], $rows)
        . a_help('A low fill rate on a busy slot means unsold inventory — sell it or set a network fallback below. Bot traffic is never counted.'));
    if (can('ads.network')) {
        $f = '';
        foreach (ad_slots() as $slot => $meta) $f .= '<details><summary>' . e($meta['label']) . ' <span class="muted small">(' . e($slot) . ')' . (setting('ad_fallback_' . $slot) ? ' — fallback set' : '') . '</span></summary>'
            . a_form('<input type="hidden" name="action" value="fallback"><input type="hidden" name="slot" value="' . e($slot) . '">' . a_field('textarea', 'code', 'Fallback HTML / ad-network tag', (string)setting('ad_fallback_' . $slot, ''), ['rows' => 4, 'hint' => 'Shown only when no direct campaign fills the slot. Rendered inside a block labelled “Advertisement”. Third-party scripts run with full page privileges — only paste code from networks you trust.']) . a_submit('Save', 'ghost')) . '</details>';
        $h .= a_card('Network fallback code (e.g. AdSense, Carbon)', $f);
    }
    return $h;
}

function ad_report(): string
{
    [$from, $to] = a_range();
    $tot = ad_totals($from, $to);
    $camps = rows('SELECT c.*, a.name adv FROM ad_campaigns c LEFT JOIN ad_advertisers a ON a.id = c.advertiser_id');
    $rows = []; $sumI = 0; $sumC = 0; $rev = 0.0;
    foreach ($camps as $c) {
        $t = $tot[$c['id']] ?? null; if (!$t) continue;
        $r = $c['pricing'] === 'cpm' ? (float)$c['rate'] * $t['i'] / 1000 : ($c['pricing'] === 'cpc' ? (float)$c['rate'] * $t['c'] : 0.0);
        $rows[] = ['id' => $c['id'], 'name' => $c['name'], 'adv' => $c['adv'], 'pricing' => $c['pricing'], 'i' => (int)$t['i'], 'c' => (int)$t['c'], 'ic' => (int)$t['ic'], 'rev' => $r];
        $sumI += $t['i']; $sumC += $t['c']; $rev += $r;
    }
    usort($rows, fn($a, $b) => $b['i'] <=> $a['i']);
    $h = '<div style="display:flex;justify-content:flex-end;margin-bottom:12px">' . a_range_picker('ads/report') . '</div><div class="kpis">' . a_kpi('Impressions', a_n($sumI)) . a_kpi('Valid clicks', a_n($sumC), 'CTR ' . a_pct($sumC, $sumI, 2)) . a_kpi('Usage revenue (CPM + CPC)', a_money($rev), 'eCPM ' . ($sumI ? a_money($rev / $sumI * 1000) : '—')) . '</div>';
    $h .= a_help('Flat-fee campaigns are not pro-rated here — their contract value is booked in Finance. “Invalid” clicks (bots, repeat clicks) are never billed.');
    $h .= a_card('By campaign', a_table(['name' => ['label' => 'Campaign', 'fmt' => fn($r, $v) => '<a href="' . e(a_url('ads/edit', ['id' => $r['id']])) . '">' . e($v) . '</a><br><span class="muted small">' . e($r['adv']) . '</span>'], 'pricing' => 'Model', 'i' => ['label' => 'Impr.', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)],
        'c' => ['label' => 'Clicks', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'ic' => ['label' => 'Invalid', 'cls' => 'num'], 'ctr' => ['label' => 'CTR', 'cls' => 'num', 'fmt' => fn($r) => a_pct($r['c'], $r['i'], 2)], 'rev' => ['label' => 'Usage revenue', 'cls' => 'num', 'fmt' => fn($r, $v) => a_money($v)]], $rows, 'No ad delivery in this period.'));
    $pl = rows('SELECT placement, SUM(impressions) i, SUM(clicks) c FROM ad_stats WHERE day BETWEEN ? AND ? GROUP BY placement ORDER BY i DESC', [$from, $to]);
    $h .= a_card('By placement', hbars(array_map(fn($r) => [ad_slots()[$r['placement']]['label'] ?? $r['placement'], $r['i']], $pl)));
    $days = rows('SELECT day, SUM(impressions) i, SUM(clicks) c FROM ad_stats WHERE day BETWEEN ? AND ? GROUP BY day', [$from, $to]);
    $m = array_column($days, null, 'day'); $labels = []; for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) $labels[] = gmdate('Y-m-d', $t);
    $h .= a_card('Daily delivery', svg_line([['name' => 'Impressions', 'values' => array_map(fn($d) => (int)($m[$d]['i'] ?? 0), $labels)], ['name' => 'Clicks', 'values' => array_map(fn($d) => (int)($m[$d]['c'] ?? 0), $labels), 'color' => '#16c784']], $labels, ['h' => 190]));
    return $h;
}
