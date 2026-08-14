<?php

function permissionCatalog(): array {
    return [
        'Dashboard' => [
            'dashboard.view' => 'View Dashboard',
        ],
        'Medicines' => [
            'medicines.view'   => 'View Medicines',
            'medicines.manage' => 'Add, Edit & Delete Medicines',
        ],
        'Sales' => [
            'sales.view'   => 'View Sales History',
            'sales.manage' => 'Manage Sales & Returns',
        ],
        'Customers' => [
            'customers.view'   => 'View Customers',
            'customers.manage' => 'Manage Customers & Credit',
        ],
        'Point of Sale' => [
            'pos.access' => 'Access POS / New Sale',
        ],
        'Purchases' => [
            'purchases.view'   => 'View Purchases',
            'purchases.manage' => 'Record & Manage Purchases',
        ],
        'Inventory' => [
            'inventory.view'   => 'View Inventory & Stock',
            'inventory.manage' => 'Adjust Stock & Batches',
        ],
        'Reports' => [
            'reports.view' => 'View & Export Reports',
        ],
        'Settings' => [
            'settings.view'   => 'View Settings',
            'settings.manage' => 'Edit Pharmacy & Notification Settings',
        ],
        'Landing Page' => [
            'landing.edit' => 'Edit Public Landing Page',
        ],
        'Administration' => [
            'users.manage' => 'Manage Users',
            'roles.manage' => 'Manage Roles & Permissions',
        ],
        'System' => [
            'demo.manage'      => 'Load & Remove Demo Data',
            'sales.clear_all'  => 'Clear All Sales Data',
        ],
    ];
}

function allPermissionKeys(): array {
    $keys = [];
    foreach (permissionCatalog() as $perms) {
        foreach ($perms as $key => $label) {
            $keys[] = $key;
        }
    }
    return $keys;
}

function initPermissionsSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            description TEXT,
            is_system INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            perm_key TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            module TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INTEGER NOT NULL,
            perm_key TEXT NOT NULL,
            effect TEXT NOT NULL CHECK(effect IN ('allow', 'deny')),
            PRIMARY KEY (role_id, perm_key),
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        );
    ");

    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN role_id INTEGER REFERENCES roles(id)');
    } catch (PDOException $e) {
        /* already applied */
    }

    seedPermissionsAndRoles($pdo);
}

function seedPermissionsAndRoles(PDO $pdo): void {
    $permStmt = $pdo->prepare('INSERT OR IGNORE INTO permissions (perm_key, label, module) VALUES (?, ?, ?)');
    foreach (permissionCatalog() as $module => $perms) {
        foreach ($perms as $key => $label) {
            $permStmt->execute([$key, $label, $module]);
        }
    }

    $roleStmt = $pdo->prepare('INSERT OR IGNORE INTO roles (slug, name, description, is_system) VALUES (?, ?, ?, ?)');

    $roleStmt->execute(['admin',       'Administrator', 'Full system access', 1]);
    $roleStmt->execute(['manager',     'Manager',       'Oversees pharmacy operations, inventory, and reports', 1]);
    $roleStmt->execute(['pharmacist',  'Pharmacist',    'Dispenses medicines and manages stock', 1]);
    $roleStmt->execute(['cashier',     'Cashier',       'Handles POS sales and customer payments', 1]);
    $roleStmt->execute(['staff',       'Staff',         'General team member access', 1]);

    $adminRoleId      = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1")->fetchColumn();
    $managerRoleId    = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'manager' LIMIT 1")->fetchColumn();
    $pharmacistRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'pharmacist' LIMIT 1")->fetchColumn();
    $cashierRoleId    = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'cashier' LIMIT 1")->fetchColumn();
    $staffRoleId      = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'staff' LIMIT 1")->fetchColumn();

    if ($adminRoleId) {
        seedRolePermissionsIfEmpty($pdo, $adminRoleId, array_fill_keys(allPermissionKeys(), 'allow'));
    }

    if ($managerRoleId) {
        seedRolePermissionsIfEmpty($pdo, $managerRoleId, allowPermissions([
            'dashboard.view', 'medicines.view', 'medicines.manage',
            'sales.view', 'sales.manage', 'customers.view', 'customers.manage',
            'pos.access', 'purchases.view', 'purchases.manage',
            'inventory.view', 'inventory.manage', 'reports.view',
            'settings.view', 'settings.manage',
        ]));
    }

    if ($pharmacistRoleId) {
        seedRolePermissionsIfEmpty($pdo, $pharmacistRoleId, allowPermissions([
            'dashboard.view', 'medicines.view', 'medicines.manage',
            'sales.view', 'customers.view', 'pos.access',
            'purchases.view', 'inventory.view', 'inventory.manage',
            'reports.view',
        ]));
    }

    if ($cashierRoleId) {
        seedRolePermissionsIfEmpty($pdo, $cashierRoleId, allowPermissions([
            'pos.access',
            'sales.view', 'sales.manage',
            'customers.view', 'customers.manage',
        ]));
    }

    if ($staffRoleId) {
        seedRolePermissionsIfEmpty($pdo, $staffRoleId, allowPermissions([
            'dashboard.view', 'medicines.view', 'medicines.manage',
            'sales.view', 'sales.manage', 'customers.view', 'customers.manage',
            'pos.access', 'purchases.view', 'purchases.manage',
            'inventory.view', 'inventory.manage', 'reports.view',
        ]));
    }

    migrateLegacyUserRoles($pdo, $adminRoleId, $staffRoleId);
}

function allowPermissions(array $keys): array {
    $perms = [];
    foreach ($keys as $key) {
        $perms[$key] = 'allow';
    }
    return $perms;
}

function seedRolePermissionsIfEmpty(PDO $pdo, int $roleId, array $permissions): void {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?');
    $stmt->execute([$roleId]);
    if ((int) $stmt->fetchColumn() === 0) {
        setRolePermissions($pdo, $roleId, $permissions);
    }
}

function migrateLegacyUserRoles(PDO $pdo, int $adminRoleId, int $staffRoleId): void {
    $users = $pdo->query('SELECT id, role, role_id FROM users')->fetchAll();
    $update = $pdo->prepare('UPDATE users SET role_id = ? WHERE id = ?');

    foreach ($users as $user) {
        if (!empty($user['role_id'])) {
            continue;
        }
        $roleId = ($user['role'] ?? 'staff') === 'admin' ? $adminRoleId : $staffRoleId;
        if ($roleId) {
            $update->execute([$roleId, $user['id']]);
        }
    }
}

function setRolePermissions(PDO $pdo, int $roleId, array $permissions): void {
    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
    $stmt = $pdo->prepare('INSERT INTO role_permissions (role_id, perm_key, effect) VALUES (?, ?, ?)');
    foreach ($permissions as $key => $effect) {
        if ($effect === 'allow' || $effect === 'deny') {
            $stmt->execute([$roleId, $key, $effect]);
        }
    }
}

function getRolePermissions(PDO $pdo, int $roleId): array {
    $rows = $pdo->prepare('SELECT perm_key, effect FROM role_permissions WHERE role_id = ?');
    $rows->execute([$roleId]);
    $map = [];
    foreach ($rows->fetchAll() as $row) {
        $map[$row['perm_key']] = $row['effect'];
    }
    return $map;
}

function resolvePermissions(array $rolePerms): array {
    $allowed = [];
    $denied = [];
    foreach ($rolePerms as $key => $effect) {
        if ($effect === 'deny') {
            $denied[] = $key;
        } elseif ($effect === 'allow') {
            $allowed[] = $key;
        }
    }
    return array_values(array_diff($allowed, $denied));
}

function loadPermissionsForUser(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('
        SELECT rp.perm_key, rp.effect
        FROM users u
        JOIN role_permissions rp ON rp.role_id = u.role_id
        WHERE u.id = ?
    ');
    $stmt->execute([$userId]);
    $rolePerms = [];
    foreach ($stmt->fetchAll() as $row) {
        $rolePerms[$row['perm_key']] = $row['effect'];
    }
    return resolvePermissions($rolePerms);
}

function getUserRoleInfo(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare('
        SELECT u.role_id, u.role AS legacy_role, r.slug, r.name AS role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getAllRoles(PDO $pdo): array {
    return $pdo->query('
        SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count
        FROM roles r
        ORDER BY r.is_system DESC, r.name COLLATE NOCASE
    ')->fetchAll();
}

function getRoleById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM roles WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function slugifyRoleName(string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    return trim($slug, '_') ?: 'role';
}

function createRole(PDO $pdo, string $name, string $description = ''): array {
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Role name is required.'];
    }

    $baseSlug = slugifyRoleName($name);
    $slug = $baseSlug;
    $i = 1;
    $check = $pdo->prepare('SELECT id FROM roles WHERE slug = ? LIMIT 1');
    while (true) {
        $check->execute([$slug]);
        if (!$check->fetch()) {
            break;
        }
        $slug = $baseSlug . '_' . $i++;
    }

    $pdo->prepare('INSERT INTO roles (slug, name, description, is_system) VALUES (?, ?, ?, 0)')
        ->execute([$slug, $name, trim($description)]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

function updateRole(PDO $pdo, int $id, string $name, string $description = ''): array {
    $role = getRoleById($pdo, $id);
    if (!$role) {
        return ['ok' => false, 'error' => 'Role not found.'];
    }

    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Role name is required.'];
    }

    $pdo->prepare('UPDATE roles SET name = ?, description = ? WHERE id = ?')
        ->execute([$name, trim($description), $id]);

    return ['ok' => true];
}

function deleteRole(PDO $pdo, int $id): array {
    $role = getRoleById($pdo, $id);
    if (!$role) {
        return ['ok' => false, 'error' => 'Role not found.'];
    }
    if ((int) $role['is_system'] === 1) {
        return ['ok' => false, 'error' => 'System roles cannot be deleted.'];
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = ?');
    $stmt->execute([$id]);
    $count = (int) $stmt->fetchColumn();
    if ($count > 0) {
        return ['ok' => false, 'error' => "Cannot delete role assigned to $count user(s). Reassign them first."];
    }

    $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
    return ['ok' => true];
}

function getAllUsers(PDO $pdo): array {
    return $pdo->query('
        SELECT u.id, u.username, u.full_name, u.role, u.role_id, u.created_at,
               r.name AS role_name, r.slug AS role_slug
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        ORDER BY u.full_name COLLATE NOCASE, u.username COLLATE NOCASE
    ')->fetchAll();
}

function createUser(PDO $pdo, string $username, string $password, string $fullName, int $roleId): array {
    $username = trim($username);
    $fullName = trim($fullName);

    if ($username === '' || $password === '' || $fullName === '') {
        return ['ok' => false, 'error' => 'Username, password, and full name are required.'];
    }
    if (!getRoleById($pdo, $roleId)) {
        return ['ok' => false, 'error' => 'Invalid role selected.'];
    }

    $exists = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $exists->execute([$username]);
    if ($exists->fetch()) {
        return ['ok' => false, 'error' => 'Username already exists.'];
    }

    $role = getRoleById($pdo, $roleId);
    $legacyRole = ($role['slug'] ?? '') === 'admin' ? 'admin' : 'staff';

    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, role_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $legacyRole, $roleId]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

function updateUser(PDO $pdo, int $userId, string $username, string $fullName, int $roleId, string $password = ''): array {
    $username = trim($username);
    $fullName = trim($fullName);

    if ($username === '') {
        return ['ok' => false, 'error' => 'Username is required.'];
    }
    if ($fullName === '') {
        return ['ok' => false, 'error' => 'Full name is required.'];
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    $exists = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
    $exists->execute([$username, $userId]);
    if ($exists->fetch()) {
        return ['ok' => false, 'error' => 'Username already exists.'];
    }

    if (!getRoleById($pdo, $roleId)) {
        return ['ok' => false, 'error' => 'Invalid role selected.'];
    }

    $role = getRoleById($pdo, $roleId);
    $legacyRole = ($role['slug'] ?? '') === 'admin' ? 'admin' : 'staff';

    if ($password !== '') {
        $pdo->prepare('UPDATE users SET username = ?, full_name = ?, role_id = ?, role = ?, password_hash = ? WHERE id = ?')
            ->execute([$username, $fullName, $roleId, $legacyRole, password_hash($password, PASSWORD_DEFAULT), $userId]);
    } else {
        $pdo->prepare('UPDATE users SET username = ?, full_name = ?, role_id = ?, role = ? WHERE id = ?')
            ->execute([$username, $fullName, $roleId, $legacyRole, $userId]);
    }

    return ['ok' => true];
}

function deleteUser(PDO $pdo, int $userId, int $currentUserId): array {
    if ($userId === $currentUserId) {
        return ['ok' => false, 'error' => 'You cannot delete your own account.'];
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    return ['ok' => true];
}
