<?php
/** Alert center: automatic rule-based alerts (see cron task_alerts) with acknowledge / resolve and history. */

function m_alerts(string $sub): void
{
    if (is_post()) {
        require_perm('alerts.manage');
        $id = a_int('id');
        if (a_action() === 'ack') { q('UPDATE alerts SET acked_by = ? WHERE id = ?', [current_user()['id'], $id]); audit('alert.ack', 'alert', $id); a_go('alerts', 'Acknowledged.'); }
        if (a_action() === 'resolve') { q('UPDATE alerts SET resolved_at = UTC_TIMESTAMP() WHERE id = ?', [$id]); audit('alert.resolve', 'alert', $id); a_go('alerts', 'Marked resolved.'); }
        if (a_action() === 'run') {
            require_once APP_DIR . '/../cron/tasks.php';
            task_alerts(); audit('alert.run_checks', 'alert'); a_go('alerts', 'Checks re-run.');
        }
    }
    $show = a_in('show') === 'history' ? 'history' : 'open';
    $rows = $show === 'open'
        ? rows("SELECT a.*, u.email acker FROM alerts a LEFT JOIN users u ON u.id = a.acked_by WHERE a.resolved_at IS NULL ORDER BY FIELD(a.severity,'critical','warning','info'), a.id DESC")
        : rows('SELECT a.*, u.email acker FROM alerts a LEFT JOIN users u ON u.id = a.acked_by WHERE a.resolved_at IS NOT NULL ORDER BY a.resolved_at DESC LIMIT 200');
    $h = a_tabs('alerts', ['' => 'Open', 'history' => 'History'], $show === 'open' ? '' : 'history');
    $h = '<nav class="tabs"><a class="' . ($show === 'open' ? 'on' : '') . '" href="' . e(a_url('alerts')) . '">Open</a><a class="' . ($show === 'history' ? 'on' : '') . '" href="' . e(a_url('alerts', ['show' => 'history'])) . '">Resolved history</a></nav>';
    $h .= a_card(count($rows) . ($show === 'open' ? ' open alerts' : ' resolved alerts'), a_table(['severity' => ['label' => '', 'fmt' => fn($r, $v) => a_status_badge($v)], 'type' => 'Rule', 'message' => ['label' => 'Alert', 'cls' => 'wrap'],
        'created_at' => ['label' => 'Raised', 'fmt' => fn($r, $v) => e(time_ago($v))], 'last_seen_at' => ['label' => 'Last seen', 'fmt' => fn($r, $v) => $v ? e(time_ago($v)) : ''], 'acker' => 'Acked by',
        'x' => ['label' => '', 'fmt' => fn($r) => $r['resolved_at'] ? e(substr($r['resolved_at'], 0, 16)) : (can('alerts.manage') ? ($r['acked_by'] ? '' : a_btn('ack', 'Ack', ['id' => $r['id']])) . a_btn('resolve', 'Resolve', ['id' => $r['id']], 'sm ghost') : '')]], $rows, $show === 'open' ? 'All clear — no open alerts. ✅' : 'Nothing resolved yet.'));
    if ($show === 'open') {
        $h .= a_card('What is monitored', '<ul class="help"><li>Stale data: markets, tickers, global stats not refreshed in time</li><li>Provider failures: >50% failed calls to a host in 30 minutes</li><li>5xx spikes and 404 share of traffic</li><li>Traffic and Googlebot crawl drops vs the 7-day average</li><li>Ad campaigns awaiting approval or nearly out of budget; coins awaiting review</li><li>Search Console / GA4 sync failures; weekly SEO audit criticals; low disk space</li></ul>'
            . a_help('New warning/critical alerts are sent to the webhook (Slack-compatible) and email set in Settings → Notifications. Alerts resolve themselves when the condition clears.') . (can('alerts.manage') ? a_btn('run', 'Re-run checks now', [], 'ghost') : ''));
    }
    admin_page('Alert center', $h);
}
