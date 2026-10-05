<?php
/** AJAX endpoint for stock exchange: batch lookup and draft line removal. JSON only. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/inventory_lib.php';
require_once __DIR__ . '/exchange_functions.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$pdo = getDB();
$act = $_POST['act'] ?? ($_GET['act'] ?? '');

if ($act === 'batches') {
    $medicineId = (int)($_POST['medicine_id'] ?? ($_GET['medicine_id'] ?? 0));
    if (!$medicineId) {
        echo json_encode(['ok' => false, 'batches' => []]);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT id, batch_number, quantity, expiry_date, purchase_price, selling_price, status
        FROM batches
        WHERE medicine_id = ? AND COALESCE(status, 'active') = 'active'
        ORDER BY (quantity > 0) DESC, expiry_date ASC, id ASC
    ");
    $stmt->execute([$medicineId]);
    echo json_encode(['ok' => true, 'batches' => $stmt->fetchAll()]);
    exit;
}

if ($act === 'remove_exchange_product') {
    if (!can('inventory.exchange') && !can('inventory.manage')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }
    $lineId = (int)($_POST['product_id'] ?? 0);
    $exchangeId = (int)($_POST['exchange_id'] ?? 0);
    $chk = $pdo->prepare("SELECT status FROM stock_exchanges WHERE id = ?");
    $chk->execute([$exchangeId]);
    $status = $chk->fetchColumn();
    if ($status === false) {
        echo json_encode(['ok' => false, 'error' => 'Exchange not found']);
        exit;
    }
    if ($status !== 'draft') {
        echo json_encode(['ok' => false, 'error' => 'Posted exchanges cannot lose product lines']);
        exit;
    }
    $pdo->prepare("DELETE FROM stock_exchange_products WHERE id = ? AND exchange_id = ?")->execute([$lineId, $exchangeId]);
    recalcExchangeTotals($pdo, $exchangeId);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
