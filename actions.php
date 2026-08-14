<?php
require_once __DIR__ . '/auth.php';
requireAuth();

$act = $_POST['act'] ?? '';

if ($act === 'seed_demo') {
    if (!can('demo.manage')) {
        flashSet('error', 'You do not have permission to manage demo data.');
        header('Location: settings.php');
        exit;
    }
    require_once __DIR__ . '/seed_demo.php';
    $force = !empty($_POST['force']);
    $result = seedDemoData(getDB(), $force);
    flashSet($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: settings.php');
    exit;
}

if ($act === 'clear_demo') {
    if (!can('demo.manage')) {
        flashSet('error', 'You do not have permission to manage demo data.');
        header('Location: settings.php');
        exit;
    }
    require_once __DIR__ . '/seed_demo.php';
    $result = clearDemoData(getDB());
    flashSet($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: settings.php');
    exit;
}

if ($act === 'clear_sales') {
    if (!can('sales.clear_all')) {
        flashSet('error', 'You do not have permission to clear sales data.');
        header('Location: settings.php');
        exit;
    }
    $pdo = getDB();
    // Restore stock from all sales
    $items = $pdo->query("SELECT * FROM sale_items")->fetchAll();
    foreach ($items as $si) {
        $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id=?")->execute([$si['quantity'],$si['batch_id']]);
    }
    $pdo->exec("DELETE FROM sale_items");
    $pdo->exec("DELETE FROM sales");
    header('Location: settings.php?msg=sales_cleared');
    exit;
}

header('Location: index.php');
exit;
