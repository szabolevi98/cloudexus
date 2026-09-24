<?php

/**
 * Creates the initial super admin (or resets its password if it already exists).
 * Run it after database/migrate.php, which creates the roles.
 *
 * Usage: php database/create_admin.php [username] [password]
 * Defaults: admin / admin123
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Cloudexus\Core\Config;
use Cloudexus\Core\RoleCode;
use Cloudexus\Model\Account\RoleModel;
use Cloudexus\Model\Account\UserModel;

Config::load(dirname(__DIR__) . '/config/config.ini');

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? 'admin123';

$role = (new RoleModel())->findByCode(RoleCode::SUPER_ADMIN);
if ($role === null) {
    fwrite(STDERR, "The roles table is empty: run php database/migrate.php first.\n");
    exit(1);
}

$users = new UserModel();
$existing = $users->findByUsernameOrEmail($username);

if ($existing) {
    $users->update((int) $existing['id'], [
        'username' => $existing['username'],
        'email' => $existing['email'],
        'full_name' => $existing['full_name'],
        'role_id' => (int) $role['id'],
        'is_active' => 1,
        'password' => $password,
    ]);
    echo "User '$username' already existed — password reset, role set to super admin.\n";
} else {
    $users->create([
        'username' => $username,
        'email' => $username . '@cloudexus.local',
        'password' => $password,
        'full_name' => ucfirst($username),
        'role_id' => (int) $role['id'],
        'is_active' => 1,
    ]);
    echo "Super admin '$username' created.\n";
}
