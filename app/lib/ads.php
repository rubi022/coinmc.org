<?php
/**
 * Advertising: placements, campaign selection, labeled rendering, impression/click accounting.
 * Slots render as empty containers and are filled by JS from /api/ad so the 60s full-page cache never
 * freezes or double-counts ads. Every paid placement is labeled "Sponsored" — the label is not configurable.
 */

function ad_slots(): array
{
    return [
        'home_top'          => ['label' => 'Homepage — above rankings table',    'page' => 'home',     'size' => '728×90 / native card'],
        'home_sponsored'    => ['label' => 'Homepage — sponsored coin listing',  'page' => 'home',     'size' => 'native row'],
        'list_top'          => ['label' => 'Rankings, categories, movers — top', 'page' => 'lists',    'size' => '728×90 / native card'],
        'coin_top'          => ['label' => 'Coin page — above chart',            'page' => 'coin',     'size' => '728×90 / native card'],
        'coin_sidebar'      => ['label' => 'Coin page — sidebar',                'page' => 'coin',     'size' => '300×250 / native card'],
        'exchange_top'      => ['label' => 'Exchange pages — top',               'page' => 'exchange', 'size' => '728×90 / native card'],
        'article_inline'    => ['label' => 'News articles — inline',             'page' => 'news',     'size' => '728×90 / native card'],
    ];
}

function ad_slot(string $slot): string
{
    if (!isset(ad_slots()[$slot]) || setting('ads_enabled', '1') !== '1') return '';
    return '<div class="ad-slot" data-slot="' . e($slot) . '" data-path="' . e(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)) . '"></div>';
}

function ad_pick(string $slot, ?string $device, ?string $country, string $vh): ?array
{
    $cands = rows("SELECT * FROM ad_campaigns WHERE status = 'approved' AND placement = ?
        AND (start_at IS NULL OR start_at <= UTC_TIMESTAMP()) AND (end_at IS NULL OR end_at >= UTC_TIMESTAMP())
        AND (budget = 0 OR spent < budget) ORDER BY priority DESC, RAND() LIMIT 20", [$slot]);
    foreach ($cands as $c) {
        if ($c['devices'] && $device && !in_array($device, array_map('trim', explode(',', $c['devices'])), true)) continue;
        if ($c['countries']) {
            $list = array_map('trim', explode(',', strtoupper($c['countries'])));
            if (!$country || !in_array($country, $list, true)) continue;
        }
        if ((int)$c['freq_cap'] > 0) {
            $n = (int)val('SELECT n FROM ad_freq WHERE vh = ? AND campaign_id = ? AND day = UTC_DATE()', [$vh, $c['id']]);
            if ($n >= (int)$c['freq_cap']) continue;
        }
        return $c;
    }
    return null;
}

function ad_record_request(string $slot, bool $filled): void
{
    q('INSERT INTO slot_impressions (day, placement, requests, filled) VALUES (UTC_DATE(), ?, 1, ?) ON DUPLICATE KEY UPDATE requests = requests + 1, filled = filled + VALUES(filled)', [$slot, $filled ? 1 : 0]);
}

function ad_record_impression(array $c, string $slot, string $vh): void
{
    q('INSERT INTO ad_stats (campaign_id, day, placement, impressions) VALUES (?, UTC_DATE(), ?, 1) ON DUPLICATE KEY UPDATE impressions = impressions + 1', [$c['id'], $slot]);
    q('INSERT INTO ad_freq (vh, campaign_id, day, n) VALUES (?, ?, UTC_DATE(), 1) ON DUPLICATE KEY UPDATE n = n + 1', [$vh, $c['id']]);
    if ($c['pricing'] === 'cpm' && (float)$c['rate'] > 0) {
        q('UPDATE ad_campaigns SET spent = spent + ? WHERE id = ?', [(float)$c['rate'] / 1000, $c['id']]);
        ad_end_if_exhausted((int)$c['id']);
    }
}

function ad_end_if_exhausted(int $id): void
{
    q("UPDATE ad_campaigns SET status = 'ended' WHERE id = ? AND status = 'approved' AND budget > 0 AND spent >= budget", [$id]);
}

function ad_render(array $c, string $slot): string
{
    $href = '/go/ad/' . (int)$c['id'] . '/?s=' . rawurlencode($slot);
    $a = 'href="' . e($href) . '" rel="sponsored nofollow noopener" target="_blank"';
    $label = $c['kind'] === 'sponsored_coin' ? 'Sponsored listing' : 'Sponsored';
    $tag = '<span class="ad-tag" title="Paid placement. ' . e(cfg('site_name')) . ' may be compensated.">' . $label . '</span>';
    $disc = '<a class="ad-why" href="/advertising-disclosure/" rel="nofollow">Why am I seeing this?</a>';
    if ($c['kind'] === 'banner' && $c['image_url']) {
        return '<div class="ad ad-banner">' . $tag . '<a ' . $a . '><img src="' . e($c['image_url']) . '" alt="' . e($c['title'] ?: 'Advertisement') . '" loading="lazy"></a>' . $disc . '</div>';
    }
    $img = $c['image_url'] ? '<img src="' . e($c['image_url']) . '" alt="" width="48" height="48" loading="lazy">' : '';
    return '<div class="ad ad-native">' . $tag . '<a class="ad-body" ' . $a . '>' . $img . '<span><strong>' . e($c['title']) . '</strong>'
        . ($c['body'] ? '<small>' . e($c['body']) . '</small>' : '') . '</span><em>' . e($c['cta'] ?: 'Learn more') . '</em></a>' . $disc . '</div>';
}

/** Validates an outbound URL for redirects (http/https only). */
function safe_url(?string $u): ?string
{
    if (!$u || !preg_match('~^https?://[^\s/$.?#].[^\s]*$~i', $u)) return null;
    return $u;
}
