<?php
/** Finance: simple ledger (cash in / cash out), P&L, revenue per channel, reconciliation against ad and affiliate systems. */

const LEDGER_CATS = ['income' => ['ads' => 'Advertising', 'affiliate' => 'Affiliate commissions', 'sponsored' => 'Sponsored content', 'listing' => 'Listing / verification fees', 'data' => 'Data licensing / API', 'other' => 'Other income'],
                     'expense' => ['hosting' => 'Hosting & CDN', 'data_apis' => 'Data providers / APIs', 'tools' => 'Software & tools', 'marketing' => 'Marketing', 'payroll' => 'Salaries', 'contractors' => 'Contractors', 'legal' => 'Legal & compliance', 'other' => 'Other expense']];

function m_finance(string $sub): void
{
    $tabs = ['' => 'Revenue & P&L', 'ledger' => 'Ledger'];
    if (!isset($tabs[$sub])) { http_response_code(404); admin_page('Not found', '<div class="card">Unknown page.</div>'); return; }
    $body = $sub === '' ? fin_overview() : fin_ledger();
    admin_page('Finance · ' . $tabs[$sub], a_tabs('finance', $tabs, $sub) . $body);
}

function fin_cat_label(string $type, string $cat): string { return LEDGER_CATS[$type][$cat] ?? $cat; }

function fin_overview(): string
{
    traffic_rollup_fresh();
    [$from, $to, $days, $label, $pf, $pt] = a_range();
    $sum = fn($t, $f, $e) => (float)val('SELECT COALESCE(SUM(amount),0) FROM ledger WHERE type = ? AND day BETWEEN ? AND ?', [$t, $f, $e]);
    $inc = $sum('income', $from, $to); $exp = $sum('expense', $from, $to); $pinc = $sum('income', $pf, $pt); $pexp = $sum('expense', $pf, $pt);
    $ses = (int)val('SELECT COALESCE(SUM(sessions),0) FROM traffic_daily WHERE day BETWEEN ? AND ?', [$from, $to]);
    $h = '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px"><span class="muted">' . e($label) . ' · cash basis (when money moved)</span>' . a_range_picker('finance') . '</div>';
    $h .= '<div class="kpis">' . a_kpi('Income', a_money($inc), a_delta($inc, $pinc)) . a_kpi('Expenses', a_money($exp), a_delta($exp, $pexp)) . a_kpi('Net profit', a_money($inc - $exp), $inc > 0 ? 'margin ' . number_format(($inc - $exp) / $inc * 100, 1) . '%' : '', $inc - $exp < 0 ? 'bad' : '')
        . a_kpi('Revenue per 1,000 sessions', $ses ? a_money($inc / $ses * 1000) : '—', a_n($ses) . ' sessions') . '</div>';

    // monthly P&L, last 12 months
    $mm = rows("SELECT DATE_FORMAT(day, '%Y-%m') m, type, SUM(amount) a FROM ledger WHERE day >= DATE_FORMAT(UTC_DATE() - INTERVAL 11 MONTH, '%Y-%m-01') GROUP BY m, type");
    $by = []; foreach ($mm as $r) $by[$r['m']][$r['type']] = (float)$r['a'];
    $labels = []; for ($i = 11; $i >= 0; $i--) $labels[] = gmdate('Y-m', strtotime(gmdate('Y-m-15') . " -$i month"));
    $h .= a_card('Monthly income vs expenses (12 months)', svg_line([['name' => 'Income', 'values' => array_map(fn($m) => round($by[$m]['income'] ?? 0, 2), $labels), 'color' => '#16c784'], ['name' => 'Expenses', 'values' => array_map(fn($m) => round($by[$m]['expense'] ?? 0, 2), $labels), 'color' => '#ea3943'],
        ['name' => 'Net', 'values' => array_map(fn($m) => max(0, round(($by[$m]['income'] ?? 0) - ($by[$m]['expense'] ?? 0), 2)), $labels), 'color' => '#3861fb']], $labels, ['h' => 200]) . a_source('ledger'));

    $ic = rows("SELECT category, SUM(amount) a FROM ledger WHERE type = 'income' AND day BETWEEN ? AND ? GROUP BY category ORDER BY a DESC", [$from, $to]);
    $ec = rows("SELECT category, SUM(amount) a FROM ledger WHERE type = 'expense' AND day BETWEEN ? AND ? GROUP BY category ORDER BY a DESC", [$from, $to]);
    $h .= '<div class="grid g2">' . a_card('Income by source', hbars(array_map(fn($r) => [fin_cat_label('income', $r['category']), $r['a']], $ic), 'money')) . a_card('Expenses by category', hbars(array_map(fn($r) => [fin_cat_label('expense', $r['category']), $r['a']], $ec), 'money')) . '</div>';

    // reconciliation: what the ad / affiliate systems say vs what was booked as cash
    $adUsage = 0.0;
    foreach (rows('SELECT c.pricing, c.rate, SUM(s.impressions) i, SUM(s.clicks) k FROM ad_stats s JOIN ad_campaigns c ON c.id = s.campaign_id WHERE s.day BETWEEN ? AND ? GROUP BY c.id', [$from, $to]) as $r)
        $adUsage += $r['pricing'] === 'cpm' ? $r['rate'] * $r['i'] / 1000 : ($r['pricing'] === 'cpc' ? $r['rate'] * $r['k'] : 0);
    $adFlat = (float)val("SELECT COALESCE(SUM(rate),0) FROM ad_campaigns WHERE pricing = 'flat' AND status IN ('approved','paused','ended') AND start_at BETWEEN ? AND ? + INTERVAL 1 DAY", [$from, $to]);
    $aff = (float)val('SELECT COALESCE(SUM(commission),0) FROM affiliate_conversions WHERE day BETWEEN ? AND ?', [$from, $to]);
    $led = fn($c) => (float)val("SELECT COALESCE(SUM(amount),0) FROM ledger WHERE type = 'income' AND category = ? AND day BETWEEN ? AND ?", [$c, $from, $to]);
    $rec = [['s' => 'Advertising (usage-priced + flat fees starting in period)', 'sys' => $adUsage + $adFlat, 'led' => $led('ads')], ['s' => 'Affiliate commissions', 'sys' => $aff, 'led' => $led('affiliate')]];
    $h .= a_card('Reconciliation: what the platform measured vs what was received', a_table(['s' => 'Channel', 'sys' => ['label' => 'Measured / booked', 'cls' => 'num', 'fmt' => fn($r, $v) => a_money($v)], 'led' => ['label' => 'Received (ledger)', 'cls' => 'num', 'fmt' => fn($r, $v) => a_money($v)],
        'diff' => ['label' => 'Outstanding', 'cls' => 'num', 'fmt' => fn($r) => '<span class="' . ($r['sys'] - $r['led'] > 0.005 ? 'down' : '') . '">' . a_money($r['sys'] - $r['led']) . '</span>']], $rec)
        . a_help('“Measured” comes from the ad server and affiliate conversions you entered; “Received” is cash you recorded in the ledger. A positive outstanding amount means invoices to chase (or income not yet recorded). Timing differences are normal.'));
    $pa = rows("SELECT COALESCE(partner,'—') p, SUM(amount) a FROM ledger WHERE type = 'income' AND day BETWEEN ? AND ? GROUP BY p ORDER BY a DESC LIMIT 8", [$from, $to]);
    $h .= a_card('Top paying partners', hbars(array_map(fn($r) => [$r['p'], $r['a']], $pa), 'money'));
    return $h;
}

function fin_ledger(): string
{
    $me = current_user();
    if (is_post()) {
        require_perm('finance.edit');
        $act = a_action();
        if ($act === 'save') {
            $id = a_int('id'); $type = a_in('type'); $cat = a_in('category');
            $amt = round((float)str_replace(',', '', a_in('amount')), 2);
            $day = preg_match('~^\d{4}-\d\d-\d\d$~', a_in('day')) ? a_in('day') : null;
            if (!isset(LEDGER_CATS[$type][$cat]) || $amt <= 0 || !$day || $day > gmdate('Y-m-d', time() + 86400)) a_go('finance/ledger', 'Choose a valid type, category, positive amount and a date that is not in the future.', 'err');
            $d = [$day, $type, $cat, mb_substr(a_in('partner'), 0, 120) ?: null, $amt, mb_substr(a_in('note'), 0, 300) ?: null];
            if ($id) { $old = row('SELECT day, type, category, partner, amount, note FROM ledger WHERE id = ?', [$id]); q('UPDATE ledger SET day=?, type=?, category=?, partner=?, amount=?, note=? WHERE id=?', array_merge($d, [$id])); audit('ledger.update', 'ledger', $id, $old, array_combine(array_keys($old), $d)); }
            else { q('INSERT INTO ledger (day, type, category, partner, amount, note, created_by) VALUES (?,?,?,?,?,?,?)', array_merge($d, [$me['id']])); audit('ledger.create', 'ledger', (int)db()->lastInsertId(), null, array_combine(['day', 'type', 'category', 'partner', 'amount', 'note'], $d)); }
            a_go('finance/ledger', 'Saved.');
        }
        if ($act === 'delete') { $old = row('SELECT * FROM ledger WHERE id = ?', [a_int('id')]); if ($old) { q('DELETE FROM ledger WHERE id = ?', [a_int('id')]); audit('ledger.delete', 'ledger', a_int('id'), $old); } a_go('finance/ledger', 'Entry deleted (kept in the audit log).'); }
    }
    $month = preg_match('~^\d{4}-\d\d$~', a_in('month')) ? a_in('month') : ''; $type = a_in('type');
    $w = ['1=1']; $p = [];
    if ($month) { $w[] = "DATE_FORMAT(day, '%Y-%m') = ?"; $p[] = $month; }
    if (in_array($type, ['income', 'expense'], true)) { $w[] = 'type = ?'; $p[] = $type; }
    $W = implode(' AND ', $w);
    if (a_in('export') === '1' && can('reports.view')) a_csv('ledger-' . ($month ?: 'all') . '.csv', ['date', 'type', 'category', 'partner', 'amount_usd', 'note'], rows("SELECT day, type, category, partner, amount, note FROM ledger WHERE $W ORDER BY day, id", $p));
    $rows = rows("SELECT * FROM ledger WHERE $W ORDER BY day DESC, id DESC LIMIT 300", $p);
    $edit = a_int('edit') ? row('SELECT * FROM ledger WHERE id = ?', [a_int('edit')]) : null;
    $e = $edit ?: ['id' => 0, 'day' => gmdate('Y-m-d'), 'type' => 'income', 'category' => 'ads', 'partner' => '', 'amount' => '', 'note' => ''];
    $catOpts = []; foreach (LEDGER_CATS as $t => $c) foreach ($c as $k => $l) $catOpts[$k] = ($t === 'income' ? 'Income — ' : 'Expense — ') . $l;
    $h = '';
    if (can('finance.edit')) $h .= a_card($edit ? 'Edit entry' : 'Add an entry', a_form('<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . (int)$e['id'] . '"><div class="cols3">'
        . a_field('date', 'day', 'Date', $e['day'], ['required' => 1]) . a_field('select', 'type', 'Type', $e['type'], ['options' => ['income' => 'Income (cash in)', 'expense' => 'Expense (cash out)']])
        . a_field('select', 'category', 'Category', $e['category'], ['options' => $catOpts, 'hint' => 'Must match the type chosen (income categories with income).']) . a_field('number', 'amount', 'Amount (USD)', $e['amount'], ['required' => 1, 'attrs' => ' step="0.01" min="0.01"'])
        . a_field('text', 'partner', 'Partner / payer / vendor', $e['partner']) . a_field('text', 'note', 'Note / invoice #', $e['note']) . '</div>' . a_submit($edit ? 'Save entry' : 'Add entry')));
    $h .= '<form class="filters" method="get"><input type="month" name="month" value="' . e($month) . '"><select name="type"><option value="">Income & expenses</option><option value="income"' . ($type === 'income' ? ' selected' : '') . '>Income only</option><option value="expense"' . ($type === 'expense' ? ' selected' : '') . '>Expenses only</option></select><button class="btn" type="submit">Filter</button>'
        . (can('reports.view') ? ' <button class="btn ghost" name="export" value="1" type="submit">Export CSV</button>' : '') . '</form>';
    $net = 0; foreach ($rows as $r) $net += $r['type'] === 'income' ? $r['amount'] : -$r['amount'];
    $h .= a_card(count($rows) . ' entries · net ' . a_money($net), a_table(['day' => 'Date', 'type' => ['label' => 'Type', 'fmt' => fn($r, $v) => a_badge($v, $v === 'income' ? 'ok' : 'bad')], 'category' => ['label' => 'Category', 'fmt' => fn($r, $v) => e(fin_cat_label($r['type'], $v))], 'partner' => 'Partner', 'note' => ['label' => 'Note', 'cls' => 'wrap'],
        'amount' => ['label' => 'Amount', 'cls' => 'num', 'fmt' => fn($r, $v) => '<span class="' . ($r['type'] === 'income' ? 'up' : 'down') . '">' . ($r['type'] === 'income' ? '+' : '−') . a_money($v) . '</span>'],
        'x' => ['label' => '', 'fmt' => fn($r) => can('finance.edit') ? '<a class="btn sm ghost" href="' . e(a_url('finance/ledger', ['edit' => $r['id']])) . '">Edit</a> ' . a_btn('delete', '✕', ['id' => $r['id']], 'sm ghost', 'Delete this entry?') : '']], $rows, 'No entries yet.'));
    return $h;
}
