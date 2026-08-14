<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions_lib.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function currentUser(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'        => (int) $_SESSION['user_id'],
        'username'  => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role'] ?? 'staff',
        'role_id'   => (int) ($_SESSION['role_id'] ?? 0),
        'role_name' => $_SESSION['role_name'] ?? ucfirst($_SESSION['role'] ?? 'staff'),
    ];
}

function isLoggedIn(): bool {
    return currentUser() !== null;
}

function refreshUserSession(int $userId): void {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return;
    }

    $roleInfo = getUserRoleInfo($pdo, $userId);
    $_SESSION['user_id']    = (int) $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['full_name']  = $user['full_name'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['role_id']    = (int) ($user['role_id'] ?? 0);
    $_SESSION['role_name']  = $roleInfo['role_name'] ?? ucfirst($user['role']);
    $_SESSION['permissions'] = loadPermissionsForUser($pdo, $userId);
}

function can(string $permission): bool {
    if (!isLoggedIn()) {
        return false;
    }
    $perms = $_SESSION['permissions'] ?? null;
    if ($perms === null) {
        refreshUserSession((int) $_SESSION['user_id']);
        $perms = $_SESSION['permissions'] ?? [];
    }
    return in_array($permission, $perms, true);
}

function canAny(array $permissions): bool {
    foreach ($permissions as $permission) {
        if (can($permission)) {
            return true;
        }
    }
    return false;
}

function requireAuth(): void {
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
        header('Location: login.php?redirect=' . $redirect);
        exit;
    }
}

function requirePermission(string $permission): void {
    requireAuth();
    if (!can($permission)) {
        header('Location: index.php');
        exit;
    }
}

function requireAnyPermission(array $permissions): void {
    requireAuth();
    if (!canAny($permissions)) {
        header('Location: index.php');
        exit;
    }
}

function requireAdmin(): void {
    requireAuth();
    if ((currentUser()['role'] ?? '') !== 'admin' && !canAny(['users.manage', 'roles.manage'])) {
        header('Location: index.php');
        exit;
    }
}

function attemptLogin(string $username, string $password): bool {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    refreshUserSession((int) $user['id']);
    return true;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
?>
