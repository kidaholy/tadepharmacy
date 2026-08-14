<?php
require_once __DIR__ . '/auth.php';
requireAuth();

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
if (str_starts_with($currentPage, 'report_')) {
    $currentPage = 'reports';
}

$pagePermissions = [
    'index'         => 'dashboard.view',
    'medicines'     => 'medicines.view',
    'sales'         => 'sales.view',
    'customers'     => 'customers.view',
    'pos'           => 'pos.access',
    'purchases'     => 'purchases.view',
    'inventory'     => 'inventory.view',
    'reports'       => 'reports.view',
    'settings'      => 'settings.view',
    'landing_cms'   => 'landing.edit',
    'administrator' => null,
];

if ($currentPage === 'administrator') {
    if (!canAny(['users.manage', 'roles.manage'])) {
        header('Location: index.php');
        exit;
    }
} elseif (isset($pagePermissions[$currentPage]) && $pagePermissions[$currentPage] && !can($pagePermissions[$currentPage])) {
    header('Location: index.php');
    exit;
}

$pharmacyName = getSetting('pharmacy_name', 'TADE PHARMACY');
$user = currentUser();

$navItems = [];
$navDefs = [
    ['page' => 'index',         'icon' => 'grid-2x2',         'label' => 'Dashboard',          'perm' => 'dashboard.view'],
    ['page' => 'medicines',     'icon' => 'pill',              'label' => 'Medicines',          'perm' => 'medicines.view'],
    ['page' => 'sales',         'icon' => 'shopping-cart',     'label' => 'Sales',              'perm' => 'sales.view'],
    ['page' => 'customers',     'icon' => 'users',             'label' => 'Customers',          'perm' => 'customers.view'],
    ['page' => 'pos',           'icon' => 'scan-barcode',      'label' => 'New Sale (POS)',     'perm' => 'pos.access'],
    ['page' => 'purchases',     'icon' => 'package-open',      'label' => 'Purchases',          'perm' => 'purchases.view'],
    ['page' => 'inventory',     'icon' => 'boxes',             'label' => 'Inventory',          'perm' => 'inventory.view'],
    ['page' => 'reports',       'icon' => 'bar-chart-3',       'label' => 'Reports',              'perm' => 'reports.view'],
    ['page' => 'landing_cms',   'icon' => 'layout-template',   'label' => 'Landing Page',       'perm' => 'landing.edit'],
    ['page' => 'administrator', 'icon' => 'shield',            'label' => 'Administrator',      'perm' => ['users.manage', 'roles.manage']],
    ['page' => 'settings',      'icon' => 'settings',          'label' => 'Settings',           'perm' => 'settings.view'],
];
foreach ($navDefs as $item) {
    $perm = $item['perm'];
    $allowed = is_array($perm) ? canAny($perm) : can($perm);
    if ($allowed) {
        unset($item['perm']);
        $navItems[] = $item;
    }
}

function renderHead(string $title = '', string $bodyClass = ''): void {
    global $pharmacyName;
    $full = $title ? "$title — $pharmacyName" : $pharmacyName;
    echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
    echo "<meta charset=\"UTF-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
    echo "<title>$full</title>\n";
    echo "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n";
    echo "<link href=\"https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap\" rel=\"stylesheet\">\n";
    echo "<script src=\"https://unpkg.com/lucide@0.460.0\" defer></script>\n";
    echo "<link rel=\"stylesheet\" href=\"assets/style.css\">\n";
    renderPharmacyFavicon();
    echo "</head>\n";
    $cls = $bodyClass ? ' class="' . htmlspecialchars($bodyClass) . '"' : '';
    echo "<body$cls>\n";
}

function renderSidebar(): void {
    global $currentPage, $navItems, $pharmacyName, $user;
    echo '<aside class="sidebar" id="sidebar">';
    echo '<div class="sidebar-logo">';
    renderPharmacyLogo();
    echo '<div class="logo-text"><span class="logo-main">' . htmlspecialchars($pharmacyName) . '</span><span class="logo-sub">Management System</span></div>';
    echo '</div>';
    echo '<nav class="sidebar-nav">';
    foreach ($navItems as $item) {
        $active = ($currentPage === $item['page']) ? ' active' : '';
        echo "<a href=\"{$item['page']}.php\" class=\"nav-item{$active}\">";
        echo "<i data-lucide=\"{$item['icon']}\"></i>";
        echo "<span>{$item['label']}</span>";
        echo "</a>";
    }
    echo '</nav>';
    echo '<div class="sidebar-footer">';
    if ($user) {
        echo '<div class="sidebar-user">';
        echo '<div class="sidebar-user-avatar"><i data-lucide="user"></i></div>';
        echo '<div class="sidebar-user-info">';
        echo '<span class="sidebar-user-name">' . htmlspecialchars($user['full_name']) . '</span>';
        echo '<span class="sidebar-user-role">' . htmlspecialchars($user['role_name'] ?? ucfirst($user['role'])) . '</span>';
        echo '</div>';
        echo '</div>';
        echo '<a href="logout.php" class="sidebar-logout"><i data-lucide="log-out"></i> Logout</a>';
    }
    echo '<div class="sidebar-time" id="sidebarTime"></div>';
    echo '</div>';
    echo '</aside>';
}

function renderTopbar(string $title, string $subtitle = ''): void {
    global $user;
    echo '<div class="topbar">';
    echo '<button class="menu-toggle" onclick="toggleSidebar()"><i data-lucide="menu"></i></button>';
    echo '<div class="topbar-title"><h1>' . htmlspecialchars($title) . '</h1>';
    if ($subtitle) echo '<p>' . htmlspecialchars($subtitle) . '</p>';
    echo '</div>';
    echo '<div class="topbar-right">';
    echo '<div class="topbar-date" id="topbarDate"></div>';
    if ($user) {
        echo '<div class="topbar-user">';
        echo '<span>' . htmlspecialchars($user['full_name']) . '</span>';
        echo '<a href="logout.php" class="btn btn-ghost btn-sm" title="Logout"><i data-lucide="log-out"></i></a>';
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';
}

function renderFooter(): void {
    echo '<script src="assets/app.js"></script>';
    echo '</body></html>';
}
?>
