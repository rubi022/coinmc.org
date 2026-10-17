<?php
/** CMS: articles with drafts, scheduling, revisions and clearly-labelled sponsored posts. */

function m_content(string $sub): void
{
    if ($sub === 'edit') { art_edit(a_int('id')); return; }
    if ($sub !== '') { http_response_code(404); admin_page('Not found', '<div class="card">Unknown page.</div>'); return; }
    $st = a_in('status'); $q = a_in('q');
    $w = ['1=1']; $p = [];
    if (in_array($st, ['draft', 'published', 'scheduled', 'archived'], true)) { $w[] = $st === 'scheduled' ? "(status = 'published' AND publish_at > UTC_TIMESTAMP())" : 'status = ?'; if ($st !== 'scheduled') $p[] = $st; }
    if ($q !== '') { $w[] = '(title LIKE ? OR slug LIKE ?)'; $p[] = $p[] = '%' . addcslashes($q, '%_') . '%'; }
    $rows = rows('SELECT a.*, u.email author FROM articles a LEFT JOIN users u ON u.id = a.author_id WHERE ' . implode(' AND ', $w) . ' ORDER BY COALESCE(a.updated_at, a.created_at) DESC LIMIT 200', $p);
    $h = '<form class="filters" method="get"><input type="search" name="q" value="' . e($q) . '" placeholder="Search articles…"><select name="status"><option value="">Any status</option>'
        . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . ($st === $k ? ' selected' : '') . '>' . $l . '</option>', ['draft', 'published', 'scheduled', 'archived'], ['Draft', 'Published', 'Scheduled', 'Archived'])) . '</select><button class="btn" type="submit">Filter</button>'
        . (can('content.edit') ? ' <a class="btn ghost" href="' . e(a_url('content/edit')) . '">+ New article</a>' : '') . '</form>';
    $h .= a_card(count($rows) . ' articles', a_table(['title' => ['label' => 'Title', 'fmt' => fn($r, $v) => '<a href="' . e(a_url('content/edit', ['id' => $r['id']])) . '"><strong>' . e($v) . '</strong></a>' . ($r['sponsored'] ? ' ' . a_badge('sponsored', 'warn') : '')],
        'status' => ['label' => 'Status', 'fmt' => fn($r, $v) => $v === 'published' && $r['publish_at'] && $r['publish_at'] > gmdate('Y-m-d H:i:s') ? a_status_badge('scheduled') : a_status_badge($v)], 'category' => 'Category', 'author' => 'Author',
        'views' => ['label' => 'Views', 'cls' => 'num', 'fmt' => fn($r, $v) => a_n($v)], 'publish_at' => ['label' => 'Publish (UTC)', 'fmt' => fn($r, $v) => e($v ? substr($v, 0, 16) : '—')],
        'v' => ['label' => '', 'fmt' => fn($r) => $r['status'] === 'published' ? '<a class="small" target="_blank" rel="noopener" href="/news/' . e($r['slug']) . '/">view ↗</a>' : '']], $rows, 'No articles yet.'));
    admin_page('Articles', $h);
}

function art_edit(int $id): void
{
    require_perm('content.view');
    $a = $id ? row('SELECT * FROM articles WHERE id = ?', [$id]) : null;
    $me = current_user();
    if ($id && !$a) { http_response_code(404); admin_page('Not found', '<div class="card">Article not found.</div>'); return; }
    if (is_post()) {
        $act = a_action();
        if ($act === 'save') {
            require_perm('content.edit');
            $title = mb_substr(a_in('title'), 0, 220);
            $slug = slugify(a_in('slug') ?: $title);
            $status = in_array(a_in('status'), ['draft', 'published', 'archived'], true) ? a_in('status') : 'draft';
            if ($status === 'published') require_perm('content.publish');
            if ($title === '') a_go('content/edit', 'Title is required.', 'err', $id ? ['id' => $id] : []);
            if (in_array($slug, ['', 'n-a'], true) || row('SELECT id FROM articles WHERE slug = ? AND id <> ?', [$slug, $id])) a_go('content/edit', 'That slug is empty or already used.', 'err', $id ? ['id' => $id] : []);
            $spons = isset($_POST['sponsored']) ? 1 : 0; $sname = mb_substr(a_in('sponsor_name'), 0, 120) ?: null;
            if ($spons && !$sname) a_go('content/edit', 'Sponsored articles must name the sponsor — the label shown to readers includes it.', 'err', $id ? ['id' => $id] : []);
            $pub = a_in('publish_at') ? gmdate('Y-m-d H:i:s', strtotime(a_in('publish_at') . ' UTC')) : null;
            if ($status === 'published' && !$pub) $pub = $a && $a['publish_at'] ? $a['publish_at'] : gmdate('Y-m-d H:i:s');
            $body = html_sanitize((string)($_POST['body'] ?? ''));
            $d = ['slug' => $slug, 'title' => $title, 'excerpt' => mb_substr(a_in('excerpt'), 0, 400) ?: null, 'body' => $body, 'category' => mb_substr(a_in('category'), 0, 60) ?: null, 'tags' => mb_substr(a_in('tags'), 0, 200) ?: null,
                'image_url' => safe_url(a_in('image_url')), 'status' => $status, 'publish_at' => $pub, 'meta_title' => mb_substr(a_in('meta_title'), 0, 200) ?: null, 'meta_description' => mb_substr(a_in('meta_description'), 0, 400) ?: null, 'sponsored' => $spons, 'sponsor_name' => $spons ? $sname : null];
            if ($a) {
                q('INSERT INTO article_revisions (article_id, title, body, editor_id, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$id, $a['title'], $a['body'], $me['id']]);
                q('UPDATE articles SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($d))) . ', updated_at = UTC_TIMESTAMP() WHERE id = ?', array_merge(array_values($d), [$id]));
                audit('article.update', 'article', $id, array_diff_key(array_intersect_key($a, $d), ['body' => 1]), array_diff_key($d, ['body' => 1]));
                coin_cache_clear();
                a_go('content/edit', 'Saved.', 'ok', ['id' => $id]);
            }
            $d['author_id'] = $me['id'];
            q('INSERT INTO articles (`' . implode('`,`', array_keys($d)) . '`) VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')', array_values($d));
            $nid = (int)db()->lastInsertId();
            audit('article.create', 'article', $nid, null, ['title' => $title, 'status' => $status]);
            coin_cache_clear();
            a_go('content/edit', 'Article created.', 'ok', ['id' => $nid]);
        }
        if ($a && $act === 'delete') { require_perm('content.publish'); audit('article.delete', 'article', $id, ['title' => $a['title']]); q('DELETE FROM article_revisions WHERE article_id = ?', [$id]); q('DELETE FROM articles WHERE id = ?', [$id]); coin_cache_clear(); a_go('content', 'Article deleted.'); }
        if ($a && $act === 'restore') {
            require_perm('content.edit');
            $r = row('SELECT * FROM article_revisions WHERE id = ? AND article_id = ?', [a_int('rev'), $id]);
            if ($r) { q('INSERT INTO article_revisions (article_id, title, body, editor_id, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$id, $a['title'], $a['body'], $me['id']]); q('UPDATE articles SET title = ?, body = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$r['title'], $r['body'], $id]); audit('article.restore', 'article', $id, null, ['revision' => $r['id']]); coin_cache_clear(); a_go('content/edit', 'Revision restored.', 'ok', ['id' => $id]); }
        }
    }
    $a = $a ?: ['id' => 0, 'slug' => '', 'title' => '', 'excerpt' => '', 'body' => '', 'category' => '', 'tags' => '', 'image_url' => '', 'status' => 'draft', 'publish_at' => null, 'meta_title' => '', 'meta_description' => '', 'sponsored' => 0, 'sponsor_name' => ''];
    $dis = can('content.edit') ? '' : ' disabled';
    $pubOpts = can('content.publish') ? ['draft' => 'Draft', 'published' => 'Published (live, or scheduled if the date is in the future)', 'archived' => 'Archived'] : ['draft' => 'Draft'];
    $form = '<input type="hidden" name="action" value="save"><div class="grid g21"><div>'
        . a_field('text', 'title', 'Title', $a['title'], ['required' => 1, 'max' => 220, 'attrs' => $dis]) . a_field('textarea', 'body', 'Body (HTML: h2–h4, p, ul/ol, a, strong/em, blockquote, img, table)', $a['body'], ['rows' => 22, 'attrs' => $dis, 'hint' => 'Scripts, iframes, inline styles and event handlers are stripped on save. External links get rel="nofollow noopener".'])
        . a_field('textarea', 'excerpt', 'Excerpt (used on the news index and as the fallback description)', $a['excerpt'], ['rows' => 2, 'max' => 400, 'attrs' => $dis])
        . '</div><div>'
        . a_field('select', 'status', 'Status', $a['status'], ['options' => $pubOpts, 'attrs' => $dis]) . a_field('datetime-local', 'publish_at', 'Publish date (UTC)', $a['publish_at'] ? gmdate('Y-m-d\TH:i', strtotime($a['publish_at'] . ' UTC')) : '', ['attrs' => $dis, 'hint' => 'Future date = scheduled; it goes live automatically.'])
        . a_field('text', 'slug', 'URL slug', $a['slug'], ['attrs' => $dis, 'hint' => '/news/<slug>/ — changing it later breaks inbound links; add a redirect.']) . a_field('text', 'category', 'Category', $a['category'], ['attrs' => $dis]) . a_field('text', 'tags', 'Tags (comma separated)', $a['tags'], ['attrs' => $dis])
        . a_field('text', 'image_url', 'Hero image URL', $a['image_url'], ['attrs' => $dis]) . a_field('text', 'meta_title', 'SEO title override', $a['meta_title'], ['max' => 200, 'attrs' => $dis]) . a_field('textarea', 'meta_description', 'SEO description override', $a['meta_description'], ['rows' => 3, 'max' => 400, 'attrs' => $dis])
        . '<div class="warnbox">' . a_field('checkbox', 'sponsored', 'This is sponsored / paid content', $a['sponsored'], ['attrs' => $dis]) . a_field('text', 'sponsor_name', 'Sponsor name (required if sponsored)', $a['sponsor_name'], ['attrs' => $dis, 'hint' => 'Readers see a “Sponsored” banner naming the sponsor. Never publish paid placements without it.']) . '</div>'
        . '</div></div>' . (can('content.edit') ? a_submit($a['id'] ? 'Save article' : 'Create article') : '');
    $h = '<p><a href="' . e(a_url('content')) . '">← Articles</a>' . ($a['id'] && $a['status'] === 'published' ? ' · <a target="_blank" rel="noopener" href="/news/' . e($a['slug']) . '/">View live ↗</a>' : '') . '</p>' . a_card($a['id'] ? 'Edit article' : 'New article', a_form($form));
    if ($a['id']) {
        $revs = rows('SELECT r.id, r.title, r.created_at, u.email FROM article_revisions r LEFT JOIN users u ON u.id = r.editor_id WHERE r.article_id = ? ORDER BY r.id DESC LIMIT 20', [$a['id']]);
        $h .= a_card('Revision history', a_table(['created_at' => 'Saved (UTC)', 'email' => 'Edited by', 'title' => 'Title then', 'x' => ['label' => '', 'fmt' => fn($r) => can('content.edit') ? a_btn('restore', 'Restore', ['rev' => $r['id']], 'sm ghost', 'Replace the current body with this revision? (current version is kept in history)') : '']], $revs, 'No earlier versions.'));
        if (can('content.publish')) $h .= a_card('Danger zone', a_btn('delete', 'Delete article', [], 'danger', 'Delete this article permanently?'));
    }
    admin_page($a['id'] ? 'Edit article' : 'New article', $h);
}
