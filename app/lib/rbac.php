<?php
/**
 * Role-based access control. Permissions are dotted strings; a role grants either an exact
 * permission, "area.*" (everything in the area) or "*" (everything).
 */
function rbac_roles(): array
{
    return [
        'super_admin'     => ['label' => 'Super Admin',          'perms' => ['*']],
        'seo_manager'     => ['label' => 'SEO Manager',          'perms' => ['dashboard', 'analytics.view', 'seo.*', 'content.view', 'crypto.view', 'alerts.view']],
        'content_editor'  => ['label' => 'Content Editor',       'perms' => ['dashboard', 'content.*', 'seo.meta', 'analytics.view']],
        'ads_manager'     => ['label' => 'Advertising Manager',  'perms' => ['dashboard', 'ads.*', 'affiliates.*', 'analytics.view', 'alerts.view']],
        'data_ops'        => ['label' => 'Data Operations',      'perms' => ['dashboard', 'crypto.*', 'market.*', 'alerts.*', 'system.view']],
        'finance_manager' => ['label' => 'Finance Manager',      'perms' => ['dashboard', 'finance.*', 'ads.view', 'affiliates.view', 'analytics.view', 'reports.view']],
        'moderator'       => ['label' => 'Moderator',            'perms' => ['dashboard', 'users.view', 'users.moderate', 'crypto.view', 'crypto.flag']],
        'user'            => ['label' => 'Member',               'perms' => []],
    ];
}

function role_has(string $role, string $perm): bool
{
    $roles = rbac_roles();
    foreach ($roles[$role]['perms'] ?? [] as $p) {
        if ($p === '*' || $p === $perm) return true;
        if (substr($p, -2) === '.*' && strpos($perm, substr($p, 0, -1)) === 0) return true;
    }
    return false;
}

function can(string $perm): bool
{
    $u = current_user();
    return $u && $u['status'] === 'active' && role_has($u['role'], $perm);
}

function is_staff(): bool
{
    $u = current_user();
    return $u && $u['role'] !== 'user' && isset(rbac_roles()[$u['role']]);
}

function require_perm(string $perm): void
{
    if (!can($perm)) {
        audit('access.denied', 'perm', $perm);
        http_response_code(403);
        echo admin_render('Access denied', '<div class="card"><h2>Access denied</h2><p>Your role (' . e(current_user()['role'] ?? '') . ') does not include <code>' . e($perm) . '</code>. Ask a Super Admin if you need it.</p></div>');
        exit;
    }
}
