<?php
require_once __DIR__ . '/db.php';

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$pharmacyName = getSetting('pharmacy_name', 'TADE PHARMACY');

$navItems = [
    ['page' => 'index',     'icon' => 'grid-2x2',         'label' => 'Dashboard'],
    ['page' => 'medicines', 'icon' => 'pill',              'label' => 'Medicines'],
    ['page' => 'sales',     'icon' => 'shopping-cart',     'label' => 'Sales'],
    ['page' => 'pos',       'icon' => 'scan-barcode',      'label' => 'New Sale (POS)'],
    ['page' => 'purchases', 'icon' => 'package-open',      'label' => 'Purchases'],
    ['page' => 'inventory', 'icon' => 'boxes',             'label' => 'Inventory'],
    ['page' => 'reports',   'icon' => 'bar-chart-3',       'label' => 'Reports'],
    ['page' => 'settings',  'icon' => 'settings',          'label' => 'Settings'],
];

function renderHead(string $title = ''): void {
    global $pharmacyName;
    $full = $title ? "$title — $pharmacyName" : $pharmacyName;
    echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
    echo "<meta charset=\"UTF-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
    echo "<title>$full</title>\n";
    echo "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n";
    echo "<link href=\"https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap\" rel=\"stylesheet\">\n";
    echo "<script src=\"https://unpkg.com/lucide@latest\"></script>\n";
    echo "<link rel=\"stylesheet\" href=\"assets/style.css\">\n";
    echo "</head>\n<body>\n";
}

function renderSidebar(): void {
    global $currentPage, $navItems, $pharmacyName;
    echo '<aside class="sidebar" id="sidebar">';
    echo '<div class="sidebar-logo">';
    echo '<div class="logo-icon"><i data-lucide="cross" style="width:20px;height:20px;"></i></div>';
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
    echo '<div class="sidebar-footer"><div class="sidebar-time" id="sidebarTime"></div></div>';
    echo '</aside>';
}

function renderTopbar(string $title, string $subtitle = ''): void {
    echo '<div class="topbar">';
    echo '<button class="menu-toggle" onclick="toggleSidebar()"><i data-lucide="menu"></i></button>';
    echo '<div class="topbar-title"><h1>' . htmlspecialchars($title) . '</h1>';
    if ($subtitle) echo '<p>' . htmlspecialchars($subtitle) . '</p>';
    echo '</div>';
    echo '<div class="topbar-right">';
    echo '<div class="topbar-date" id="topbarDate"></div>';
    echo '</div>';
    echo '</div>';
}

function renderFooter(): void {
    echo '<script>lucide.createIcons();</script>';
    echo '<script src="assets/app.js"></script>';
    echo '</body></html>';
}
?>
