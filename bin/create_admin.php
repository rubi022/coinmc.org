<?php
/**
 * Create (or promote) an admin account.
 *   php bin/create_admin.php email password [role]
 * role: super_admin (default) | seo_manager | content_editor | ads_manager | data_ops | finance_manager | moderator
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require __DIR__ . '/../app/bootstrap.php';
[$_, $email, $pass] = array_pad($argv, 3, null);
$role = $argv[3] ?? 'super_admin';
if (!$email || !$pass) exit("Usage: php bin/create_admin.php email password [role]\n");
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) exit("Invalid email\n");
if (strlen($pass) < 12) exit("Admin passwords must be at least 12 characters.\n");
if (!isset(rbac_roles()[$role]) || $role === 'user') exit("Unknown role. Choose: " . implode(', ', array_diff(array_keys(rbac_roles()), ['user'])) . "\n");
$email = strtolower(trim($email));
$u = row('SELECT id FROM users WHERE email = ?', [$email]);
if ($u) {
    q('UPDATE users SET role = ?, status = "active", password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$role, password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    echo "Updated existing user #{$u['id']} -> $role\n";
} else {
    q('INSERT INTO users (email, password_hash, role, status, email_verified_at) VALUES (?, ?, ?, "active", NOW())', [$email, password_hash($pass, PASSWORD_DEFAULT), $role]);
    echo "Created $role: $email\n";
}
audit('admin.create_cli', 'user', $email, null, ['role' => $role]);
echo "Log in at /login/ then open /admin/. Enable 2FA under Account > Security.\n";
