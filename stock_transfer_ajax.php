<?php
/**
 * AJAX endpoint for stock transfer. JSON only.
 * The transfer page posts its forms directly to stock_transfer.php; this endpoint
 * exists for batch lookups and for status buttons used outside the full page reload.
 */
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
$userId = (int)($_SESSION['user_id'] ?? 0);
$act = $_POST['act'] ?? ($_GET['act'] ?? '');

if ($act === 'batches') {
    $medicineId = (int)($_POST['medicine_id'] ?? ($_GET['medicine_id'] ?? 0));
    if (!$medicineId) {
        echo json_encode(['ok' => false, 'batches' => []]);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT id, batch_number, quantity, expiry_date, purchase_price, selling_price
        FROM batches
        WHERE medicine_id = ? AND COALESCE(status, 'active') = 'active'
        ORDER BY (quantity > 0) DESC, expiry_date ASC, id ASC
    ");
    $stmt->execute([$medicineId]);
    echo json_encode(['ok' => true, 'batches' => $stmt->fetchAll()]);
    exit;
}

if ($act === 'update_status') {
    if (!can('inventory.transfer') && !can('inventory.manage')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $allowed = ['sent', 'in_transit', 'received', 'completed', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid status']);
        exit;
    }
    $chk = $pdo->prepare("SELECT * FROM stock_transfers WHERE id = ?");
    $chk->execute([$id]);
    $transfer = $chk->fetch();
    if (!$transfer) {
        echo json_encode(['ok' => false, 'error' => 'Transfer not found']);
        exit;
    }
    try {
        $pdo->beginTransaction();
        if (in_array($status, ['sent', 'in_transit', 'received', 'completed'], true) && empty($transfer['source_applied_at'])) {
            // Deduct source stock exactly once.
            $items = $pdo->prepare("SELECT i.*, m.name AS med_name, b.batch_number FROM stock_transfer_items i
                JOIN medicines m ON m.id = i.medicine_id LEFT JOIN batches b ON b.id = i.batch_id
                WHERE i.transfer_id = ? ORDER BY i.id");
            $items->execute([$id]);
            $rows = $items->fetchAll();
            if (!$rows) {
                throw new RuntimeException('This transfer has no products.');
            }
            foreach ($rows as $item) {
                applyBatchDelta($pdo, (int)$item['medicine_id'], $item['batch_id'] ? (int)$item['batch_id'] : null,
                    -1 * (int)$item['quantity'], 'transfer_out', 'Transfer ' . $transfer['transfer_number'],
                    'stock_transfer', $id, $transfer['transfer_number'], $userId,
                    $item['med_name'] . ' (batch ' . ($item['batch_number'] ?: '—') . ')');
            }
            $pdo->prepare("UPDATE stock_transfers SET source_applied_at = datetime('now') WHERE id = ?")->execute([$id]);
        }
        if (in_array($status, ['received', 'completed'], true) && empty($transfer['destination_applied_at'])) {
            if (empty($transfer['source_applied_at'])) {
                throw new RuntimeException('Source stock must leave before destination stock is added.');
            }
            $items = $pdo->prepare("SELECT i.*, m.name AS med_name, b.batch_number FROM stock_transfer_items i
                JOIN medicines m ON m.id = i.medicine_id LEFT JOIN batches b ON b.id = i.batch_id
                WHERE i.transfer_id = ? ORDER BY i.id");
            $items->execute([$id]);
            foreach ($items->fetchAll() as $item) {
                applyBatchDelta($pdo, (int)$item['medicine_id'], $item['batch_id'] ? (int)$item['batch_id'] : null,
                    (int)$item['quantity'], 'transfer_in', 'Transfer ' . $transfer['transfer_number'] . ' received',
                    'stock_transfer', $id, $transfer['transfer_number'], $userId,
                    $item['med_name'] . ' (batch ' . ($item['batch_number'] ?: '—') . ')');
            }
            $pdo->prepare("UPDATE stock_transfers SET destination_applied_at = datetime('now') WHERE id = ?")->execute([$id]);
        }
        $pdo->prepare("UPDATE stock_transfers SET status = ?, updated_at = datetime('now') WHERE id = ?")->execute([$status, $id]);
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
