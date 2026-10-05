<?php
/** AJAX endpoint for stock adjustment batch population. Must not print HTML. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'load_batch') {
    $medicineId = (int)($_POST['medicine_id'] ?? 0);
    if (!$medicineId) {
        echo json_encode(['ok' => false, 'batches' => []]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, batch_number, quantity, expiry_date, purchase_price
        FROM batches
        WHERE medicine_id = ? AND COALESCE(status, 'active') = 'active'
        ORDER BY (quantity > 0) DESC, expiry_date ASC, id ASC
    ");
    $stmt->execute([$medicineId]);
    $rows = $stmt->fetchAll();

    $options = '';
    foreach ($rows as $r) {
        $options .= '<option value="' . (int)$r['id'] . '"'
            . ' data-qty="' . (int)$r['quantity'] . '"'
            . ' data-cost="' . number_format((float)$r['purchase_price'], 2, '.', '') . '"'
            . ' data-expiry="' . htmlspecialchars((string)$r['expiry_date']) . '">'
            . htmlspecialchars($r['batch_number'] . ' — ' . $r['quantity'] . ' in stock')
            . '</option>';
    }

    echo json_encode([
        'ok' => true,
        'system_qty' => $rows ? (int)$rows[0]['quantity'] : 0,
        'batch_price' => $rows ? (float)$rows[0]['purchase_price'] : 0.0,
        'expiry' => $rows ? (string)$rows[0]['expiry_date'] : '',
        'options' => $options,
    ]);
    exit;
}

echo json_encode(['ok' => false]);
