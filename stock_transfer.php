<?php
/**
 * Stock Transfer — move stock between Tade Pharmacy locations.
 *
 * Workflow:  Draft → Sent → In Transit → Received → Completed
 * Stock rule: source batches are deducted when the transfer is SENT, destination
 *             stock is added only when the transfer is RECEIVED. Each side is
 *             applied exactly once (guarded by source_applied_at / destination_applied_at),
 *             so stock can never be deducted or added twice.
 * Valuation:  transfer value = quantity × batch INVENTORY COST (never selling price).
 */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory_lib.php';
require_once __DIR__ . '/sales_lib.php';
require_once __DIR__ . '/exchange_functions.php';

$pdo = getDB();
initMovementModulesSchema($pdo);

$userId = (int)(currentUser()['id'] ?? 0);
$currency = getSetting('currency', 'ETB');
$pharmacyName = getSetting('pharmacy_name', 'TADE PHARMACY');

$canAdd    = can('inventory.transfer') || can('inventory.manage');
$canView   = $canAdd || can('inventory.view');
$canSend   = $canAdd;
$canReceive = can('inventory.transfer') || can('inventory.manage') || can('inventory.edit');

$transferStatuses = [
    'draft'      => 'Draft',
    'sent'       => 'Sent',
    'in_transit' => 'In Transit',
    'received'   => 'Received',
    'completed'  => 'Completed',
    'cancelled'  => 'Cancelled',
];
/** Next allowed status and whether it moves stock. */
$statusFlow = [
    'draft'      => ['sent', 'in_transit', 'received', 'completed', 'cancelled'],
    'sent'       => ['in_transit', 'received', 'completed'],
    'in_transit' => ['received', 'completed'],
    'received'   => ['completed'],
    'completed'  => [],
    'cancelled'  => [],
];

/** Parse posted parallel arrays into validated transfer lines. */
function parseTransferLines(array $src): array
{
    $meds = $src['med'] ?? [];
    $batches = $src['batch'] ?? [];
    $qtys = $src['qty'] ?? [];
    $costs = $src['cost'] ?? [];
    $prices = $src['price'] ?? [];
    $expiries = $src['expiry'] ?? [];
    $max = max([count($meds), count($batches), count($qtys), count($costs)]);
    $lines = [];
    for ($i = 0; $i < $max; $i++) {
        $mid = (int)($meds[$i] ?? 0);
        $qty = (int)($qtys[$i] ?? 0);
        if ($mid <= 0 || $qty <= 0) {
            continue;
        }
        $cost = round((float)($costs[$i] ?? 0), 2);
        if ($cost < 0) {
            $cost = 0.0;
        }
        $lines[] = [
            'medicine_id' => $mid,
            'batch_id' => (int)($batches[$i] ?? 0) ?: null,
            'quantity' => $qty,
            'unit_cost' => $cost,
            'unit_price' => round((float)($prices[$i] ?? 0), 2),
            'expiry_date' => trim((string)($expiries[$i] ?? '')) ?: null,
        ];
    }
    return $lines;
}

/** Validate every line against live batch stock before it can leave the source. */
function validateTransferAvailability(PDO $pdo, array $lines): void
{
    $needed = [];
    foreach ($lines as $line) {
        $key = $line['batch_id'] ?: ('med:' . $line['medicine_id']);
        $needed[$key] = ($needed[$key] ?? 0) + (int)$line['quantity'];
    }
    $st = $pdo->prepare("SELECT b.quantity, m.name FROM batches b JOIN medicines m ON m.id = b.medicine_id WHERE b.id = ?");
    foreach ($needed as $key => $qty) {
        if (strpos((string)$key, 'med:') === 0) {
            throw new RuntimeException('Every transfer line needs a batch so stock can be deducted.');
        }
        $st->execute([(int)$key]);
        $row = $st->fetch();
        if (!$row) {
            throw new RuntimeException('A selected batch no longer exists.');
        }
        if ((int)$row['quantity'] < $qty) {
            throw new RuntimeException("Insufficient stock for {$row['name']}: available {$row['quantity']}, requested {$qty}.");
        }
    }
}

function insertTransferLine(PDO $pdo, int $transferId, array $line): void
{
    $batchId = $line['batch_id'];
    if ($batchId) {
        $st = $pdo->prepare("SELECT expiry_date, purchase_price FROM batches WHERE id = ?");
        $st->execute([$batchId]);
        $b = $st->fetch();
        if ($b) {
            if (empty($line['expiry_date'])) {
                $line['expiry_date'] = $b['expiry_date'];
            }
            if ((float)$line['unit_cost'] <= 0) {
                $line['unit_cost'] = (float)$b['purchase_price'];
            }
        }
    }
    if (empty($line['expiry_date'])) {
        $line['expiry_date'] = date('Y-m-d', strtotime('+365 days'));
    }
    $pdo->prepare("
        INSERT INTO stock_transfer_items (transfer_id, medicine_id, batch_id, quantity, unit_cost, unit_price, transfer_value, expiry_date)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([
        $transferId, $line['medicine_id'], $batchId, $line['quantity'],
        $line['unit_cost'], $line['unit_price'], round($line['quantity'] * $line['unit_cost'], 2), $line['expiry_date'],
    ]);
}

function syncTransferValue(PDO $pdo, int $transferId): float
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(transfer_value),0) FROM stock_transfer_items WHERE transfer_id = ?");
    $st->execute([$transferId]);
    $value = round((float)$st->fetchColumn(), 2);
    $pdo->prepare("UPDATE stock_transfers SET transfer_value = ?, updated_at = datetime('now') WHERE id = ?")->execute([$value, $transferId]);
    return $value;
}

/** Deduct every line from the source location (runs once per transfer). */
function applyTransferSource(PDO $pdo, array $transfer, int $userId): int
{
    $transferId = (int)$transfer['id'];
    if (!empty($transfer['source_applied_at'])) {
        return 0;
    }
    $st = $pdo->prepare("SELECT i.*, m.name AS med_name, b.batch_number FROM stock_transfer_items i
        JOIN medicines m ON m.id = i.medicine_id LEFT JOIN batches b ON b.id = i.batch_id
        WHERE i.transfer_id = ? ORDER BY i.id");
    $st->execute([$transferId]);
    $items = $st->fetchAll();
    if (!$items) {
        throw new RuntimeException('This transfer has no products.');
    }
    validateTransferAvailability($pdo, array_map(fn($i) => [
        'medicine_id' => (int)$i['medicine_id'],
        'batch_id' => $i['batch_id'] ? (int)$i['batch_id'] : null,
        'quantity' => (int)$i['quantity'],
    ], $items));
    foreach ($items as $item) {
        applyBatchDelta(
            $pdo,
            (int)$item['medicine_id'],
            $item['batch_id'] ? (int)$item['batch_id'] : null,
            -1 * (int)$item['quantity'],
            'transfer_out',
            'Transfer ' . $transfer['transfer_number'],
            'stock_transfer',
            $transferId,
            $transfer['transfer_number'],
            $userId,
            $item['med_name'] . ' (batch ' . ($item['batch_number'] ?: '—') . ')',
            (float)$item['unit_cost']
        );
    }
    $pdo->prepare("UPDATE stock_transfers SET source_applied_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")->execute([$transferId]);
    return count($items);
}

/** Add every line to the destination location (runs once per transfer, after the source). */
function applyTransferDestination(PDO $pdo, array $transfer, int $userId): int
{
    $transferId = (int)$transfer['id'];
    if (!empty($transfer['destination_applied_at'])) {
        return 0;
    }
    if (empty($transfer['source_applied_at'])) {
        throw new RuntimeException('Send the transfer before receiving it: source stock must leave first.');
    }
    $st = $pdo->prepare("SELECT i.*, m.name AS med_name, b.batch_number FROM stock_transfer_items i
        JOIN medicines m ON m.id = i.medicine_id LEFT JOIN batches b ON b.id = i.batch_id
        WHERE i.transfer_id = ? ORDER BY i.id");
    $st->execute([$transferId]);
    $items = $st->fetchAll();
    if (!$items) {
        throw new RuntimeException('This transfer has no products.');
    }
    foreach ($items as $item) {
        $batchId = $item['batch_id'] ? (int)$item['batch_id'] : null;
        if (!$batchId) {
            $batchId = findOrCreateBatch($pdo, (int)$item['medicine_id'], null, '', (string)$item['expiry_date'], (float)$item['unit_cost'], (float)$item['unit_price']);
            $pdo->prepare("UPDATE stock_transfer_items SET batch_id = ? WHERE id = ?")->execute([$batchId, (int)$item['id']]);
        }
        applyBatchDelta(
            $pdo,
            (int)$item['medicine_id'],
            $batchId,
            (int)$item['quantity'],
            'transfer_in',
            'Transfer ' . $transfer['transfer_number'] . ' received',
            'stock_transfer',
            $transferId,
            $transfer['transfer_number'],
            $userId,
            $item['med_name'] . ' (batch ' . ($item['batch_number'] ?: '—') . ')',
            (float)$item['unit_cost']
        );
    }
    $pdo->prepare("UPDATE stock_transfers SET destination_applied_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")->execute([$transferId]);
    return count($items);
}

// ── POST actions ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $idForRedirect = (int)($_POST['id'] ?? 0);

    try {
        if ($act === 'save_transfer' || $act === 'update_transfer') {
            if (!$canAdd) {
                throw new RuntimeException('You do not have permission to create a stock transfer.');
            }
            $editingId = $act === 'update_transfer' ? $idForRedirect : 0;
            $fromId = (int)($_POST['from_location_id'] ?? 0);
            $toId = (int)($_POST['to_location_id'] ?? 0);
            $date = trim($_POST['transfer_date'] ?? '') ?: date('Y-m-d');
            $notes = trim($_POST['notes'] ?? '');
            $senderId = (int)($_POST['sender_id'] ?? 0) ?: null;
            $receiverId = (int)($_POST['receiver_id'] ?? 0) ?: null;

            if (!$fromId || !$toId) {
                throw new RuntimeException('Select both the source and destination location.');
            }
            if ($fromId === $toId) {
                throw new RuntimeException('Source and destination locations must be different.');
            }
            $lines = parseTransferLines([
                'med' => $_POST['product_id'] ?? [], 'batch' => $_POST['batch_id'] ?? [],
                'qty' => $_POST['quantity'] ?? [], 'cost' => $_POST['unit_cost'] ?? [],
                'price' => $_POST['unit_price'] ?? [], 'expiry' => $_POST['expiry_date'] ?? [],
            ]);
            if (!$lines) {
                throw new RuntimeException('Add at least one product line.');
            }

            $pdo->beginTransaction();
            if ($editingId) {
                $chk = $pdo->prepare("SELECT * FROM stock_transfers WHERE id = ?");
                $chk->execute([$editingId]);
                $existing = $chk->fetch();
                if (!$existing) {
                    throw new RuntimeException('Transfer not found.');
                }
                if (!empty($existing['source_applied_at'])) {
                    throw new RuntimeException('Stock has already left for this transfer, so its products can no longer be changed.');
                }
                $pdo->prepare("DELETE FROM stock_transfer_items WHERE transfer_id = ?")->execute([$editingId]);
                $pdo->prepare("UPDATE stock_transfers SET transfer_date=?, from_location_id=?, to_location_id=?, sender_id=?, receiver_id=?, notes=?, updated_at=datetime('now') WHERE id=?")
                    ->execute([$date, $fromId, $toId, $senderId, $receiverId, $notes, $editingId]);
                $transferId = $editingId;
                $number = $existing['transfer_number'];
            } else {
                $number = generateTransferNumber($pdo);
                $pdo->prepare("
                    INSERT INTO stock_transfers
                        (transfer_number, transfer_date, from_location_id, to_location_id, sender_id, receiver_id, status, notes, transfer_value)
                    VALUES (?,?,?,?,?,?,'draft',?,0)
                ")->execute([$number, $date, $fromId, $toId, $senderId, $receiverId, $notes]);
                $transferId = (int)$pdo->lastInsertId();
            }
            foreach ($lines as $line) {
                insertTransferLine($pdo, $transferId, $line);
            }
            syncTransferValue($pdo, $transferId);
            $pdo->commit();
            flashSet('success', "Transfer #$number saved as draft. Stock moves when you send it.");
            header('Location: stock_transfer.php?action=view&id=' . $transferId);
            exit;
        }

        if ($act === 'add_item') {
            if (!$canAdd) {
                throw new RuntimeException('You do not have permission to edit this transfer.');
            }
            $transferId = (int)($_POST['id'] ?? $_POST['transfer_id'] ?? 0);
            $pdo->beginTransaction();
            $chk = $pdo->prepare("SELECT * FROM stock_transfers WHERE id = ?");
            $chk->execute([$transferId]);
            $transfer = $chk->fetch();
            if (!$transfer) {
                throw new RuntimeException('Transfer not found.');
            }
            if (!empty($transfer['source_applied_at'])) {
                throw new RuntimeException('Stock has already left: products can no longer be added.');
            }
            $lines = parseTransferLines([
                'med' => $_POST['med'] ?? [], 'batch' => $_POST['batch'] ?? [],
                'qty' => $_POST['qty'] ?? [], 'cost' => $_POST['cost'] ?? [],
                'price' => $_POST['price'] ?? [], 'expiry' => $_POST['expiry'] ?? [],
            ]);
            if (!$lines) {
                throw new RuntimeException('Select a product, batch and quantity greater than zero.');
            }
            foreach ($lines as $line) {
                insertTransferLine($pdo, $transferId, $line);
            }
            syncTransferValue($pdo, $transferId);
            $pdo->commit();
            flashSet('success', count($lines) . ' product line(s) added.');
            header('Location: stock_transfer.php?action=view&id=' . $transferId);
            exit;
        }

        if ($act === 'remove_item') {
            if (!$canAdd) {
                throw new RuntimeException('You do not have permission to edit this transfer.');
            }
            $itemId = (int)($_POST['item_id'] ?? 0);
            $transferId = (int)($_POST['transfer_id'] ?? 0);
            $pdo->beginTransaction();
            $chk = $pdo->prepare("SELECT * FROM stock_transfers WHERE id = ?");
            $chk->execute([$transferId]);
            $transfer = $chk->fetch();
            if (!$transfer) {
                throw new RuntimeException('Transfer not found.');
            }
            if (!empty($transfer['source_applied_at'])) {
                throw new RuntimeException('Stock has already left: product lines can no longer be removed.');
            }
            $pdo->prepare("DELETE FROM stock_transfer_items WHERE id = ? AND transfer_id = ?")->execute([$itemId, $transferId]);
            syncTransferValue($pdo, $transferId);
            $pdo->commit();
            flashSet('success', 'Product line removed.');
            header('Location: stock_transfer.php?action=view&id=' . $transferId);
            exit;
        }

        if ($act === 'update_status') {
            $transferId = (int)($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if (!isset($transferStatuses[$status]) || $status === 'draft') {
                throw new RuntimeException('Invalid target status.');
            }
            $pdo->beginTransaction();
            $chk = $pdo->prepare("SELECT * FROM stock_transfers WHERE id = ?");
            $chk->execute([$transferId]);
            $transfer = $chk->fetch();
            if (!$transfer) {
                throw new RuntimeException('Transfer not found.');
            }
            $current = (string)$transfer['status'];
            if ($status === $current) {
                throw new RuntimeException('The transfer is already ' . $transferStatuses[$status] . '.');
            }
            if (!in_array($status, $statusFlow[$current] ?? [], true)) {
                throw new RuntimeException('Cannot move from ' . ($transferStatuses[$current] ?? $current) . ' to ' . $transferStatuses[$status] . '.');
            }

            $applied = [];
            if (in_array($status, ['sent', 'in_transit', 'received', 'completed'], true)) {
                if (!$canSend) {
                    throw new RuntimeException('You do not have permission to send this transfer.');
                }
                $moved = applyTransferSource($pdo, $transfer, $userId);
                if ($moved > 0) {
                    $applied[] = "source stock deducted for {$moved} product(s)";
                }
            }
            if (in_array($status, ['received', 'completed'], true)) {
                if (!$canReceive) {
                    throw new RuntimeException('You do not have permission to receive this transfer.');
                }
                $moved = applyTransferDestination($pdo, $transfer, $userId);
                if ($moved > 0) {
                    $applied[] = "destination stock increased for {$moved} product(s)";
                }
            }

            $pdo->prepare("UPDATE stock_transfers SET status = ?, updated_at = datetime('now') WHERE id = ?")->execute([$status, $transferId]);
            if ($status === 'received' || $status === 'completed') {
                $pdo->prepare("UPDATE stock_transfers SET receiver_id = COALESCE(receiver_id, ?) WHERE id = ?")->execute([$userId ?: null, $transferId]);
            }
            if (function_exists('auditLog')) {
                auditLog($pdo, 'transfer_status', 'stock_transfer', $transferId,
                    "Transfer {$transfer['transfer_number']} → {$transferStatuses[$status]}" . ($applied ? ' (' . implode('; ', $applied) . ')' : ''));
            }
            $pdo->commit();
            flashSet('success', 'Transfer #' . $transfer['transfer_number'] . ' marked ' . $transferStatuses[$status] . '.'
                . ($applied ? ' ' . ucfirst(implode('; ', $applied)) . '.' : ''));
            header('Location: stock_transfer.php?action=view&id=' . $transferId);
            exit;
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flashSet('error', $e->getMessage());
        header('Location: ' . ($idForRedirect ? 'stock_transfer.php?action=view&id=' . $idForRedirect : 'stock_transfer.php'));
        exit;
    }
}

// ── GET: print / export / view / list ────────────────────────────────────
$action = $_GET['action'] ?? '';

if ($action === 'export' && $canView) {
    $transferId = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("
        SELECT t.transfer_number, t.transfer_date, t.status, f.name AS from_name, d.name AS to_name,
               m.name AS med_name, b.batch_number, i.expiry_date, i.quantity, i.unit_cost, i.transfer_value
        FROM stock_transfer_items i
        JOIN stock_transfers t ON t.id = i.transfer_id
        JOIN locations f ON f.id = t.from_location_id
        JOIN locations d ON d.id = t.to_location_id
        JOIN medicines m ON m.id = i.medicine_id
        LEFT JOIN batches b ON b.id = i.batch_id
        WHERE i.transfer_id = ?
        ORDER BY i.id
    ");
    $st->execute([$transferId]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transfer-' . $transferId . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    csvWrite($out, ['Transfer', 'Date', 'From', 'To', 'Status', 'Product', 'Batch', 'Expiry', 'Quantity', 'Unit Cost (inventory)', 'Transfer Value']);
    $total = 0.0;
    foreach ($st->fetchAll() as $r) {
        $total += (float)$r['transfer_value'];
        csvWrite($out, [$r['transfer_number'], $r['transfer_date'], $r['from_name'], $r['to_name'], $r['status'],
            $r['med_name'], $r['batch_number'] ?: '—', $r['expiry_date'] ?: '—', (int)$r['quantity'],
            number_format((float)$r['unit_cost'], 2, '.', ''), number_format((float)$r['transfer_value'], 2, '.', '')]);
    }
    csvWrite($out, ['', '', '', '', '', '', '', 'TOTAL', '', '', number_format($total, 2, '.', '')]);
    fclose($out);
    exit;
}

$locations = $pdo->query("SELECT id, name, code, address, status FROM locations ORDER BY name")->fetchAll();
$allMeds = $pdo->query("SELECT id, name, unit FROM medicines ORDER BY name")->fetchAll();
$staffUsers = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();

$viewTransfer = null;
$viewItems = [];
if ($action === 'view' || $action === 'edit') {
    $viewId = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("
        SELECT t.*, f.name AS from_name, d.name AS to_name,
               COALESCE(s.full_name,'—') AS sender_name, COALESCE(r.full_name,'—') AS receiver_name
        FROM stock_transfers t
        JOIN locations f ON f.id = t.from_location_id
        JOIN locations d ON d.id = t.to_location_id
        LEFT JOIN users s ON s.id = t.sender_id
        LEFT JOIN users r ON r.id = t.receiver_id
        WHERE t.id = ?
    ");
    $st->execute([$viewId]);
    $viewTransfer = $st->fetch() ?: null;
    if ($viewTransfer) {
        $is = $pdo->prepare("
            SELECT i.*, m.name AS med_name, m.unit, b.batch_number, b.quantity AS batch_qty
            FROM stock_transfer_items i
            JOIN medicines m ON m.id = i.medicine_id
            LEFT JOIN batches b ON b.id = i.batch_id
            WHERE i.transfer_id = ?
            ORDER BY i.id
        ");
        $is->execute([$viewId]);
        $viewItems = $is->fetchAll();
    }
}

$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, array_keys($transferStatuses), true)) {
    $filter = 'all';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$agg = $pdo->query("SELECT
    COUNT(*) AS total,
    COUNT(CASE WHEN status='draft' THEN 1 END) AS draft,
    COUNT(CASE WHEN status='sent' THEN 1 END) AS sent,
    COUNT(CASE WHEN status='in_transit' THEN 1 END) AS transit,
    COUNT(CASE WHEN status='received' THEN 1 END) AS received,
    COUNT(CASE WHEN status='completed' THEN 1 END) AS completed,
    COALESCE(SUM(CASE WHEN status != 'cancelled' THEN transfer_value ELSE 0 END),0) AS total_value
FROM stock_transfers")->fetch();

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(t.transfer_number LIKE ? OR f.name LIKE ? OR d.name LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filter !== 'all') {
    $where[] = 't.status = ?';
    $params[] = $filter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_transfers t JOIN locations f ON f.id=t.from_location_id JOIN locations d ON d.id=t.to_location_id WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$rowsStmt = $pdo->prepare("
    SELECT t.*, f.name AS from_name, d.name AS to_name,
           COALESCE(s.full_name,'—') AS sender_name, COALESCE(r.full_name,'—') AS receiver_name,
           (SELECT COUNT(*) FROM stock_transfer_items i WHERE i.transfer_id = t.id) AS item_count,
           (SELECT COALESCE(SUM(i.quantity),0) FROM stock_transfer_items i WHERE i.transfer_id = t.id) AS total_qty
    FROM stock_transfers t
    JOIN locations f ON f.id = t.from_location_id
    JOIN locations d ON d.id = t.to_location_id
    LEFT JOIN users s ON s.id = t.sender_id
    LEFT JOIN users r ON r.id = t.receiver_id
    WHERE $whereSql
    ORDER BY t.transfer_date DESC, t.id DESC
    LIMIT $perPage OFFSET $offset
");
$rowsStmt->execute($params);
$transfers = $rowsStmt->fetchAll();

/** Product line table for a transfer. */
function renderTransferItems(array $items, string $currency, bool $editable, int $transferId): void
{
    $total = array_sum(array_map(fn($i) => (float)$i['transfer_value'], $items));
    ?>
    <div class="card mb-20">
        <div class="card-header">
            <span class="card-title">Products</span>
            <span style="font-weight:800;">Total Transfer Value: <?= currency($total) ?></span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th>Available</th><th>Transfer Qty</th><th>Unit Cost</th><th>Transfer Value</th><?php if ($editable): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (!$items): ?>
                    <tr><td colspan="<?= $editable ? 8 : 7 ?>" style="text-align:center;padding:18px;color:var(--text-300);">No product lines yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $i): ?>
                    <tr>
                        <td style="font-size:12px;font-weight:500;"><?= htmlspecialchars($i['med_name']) ?></td>
                        <td style="font-size:12px;"><?= htmlspecialchars($i['batch_number'] ?: '—') ?></td>
                        <td style="font-size:12px;"><?= htmlspecialchars($i['expiry_date'] ?: '—') ?></td>
                        <td style="font-size:12px;color:var(--text-300);"><?= isset($i['batch_qty']) ? number_format((int)$i['batch_qty']) : '—' ?></td>
                        <td style="font-size:12px;"><?= number_format((int)$i['quantity']) ?> <?= htmlspecialchars($i['unit'] ?? '') ?></td>
                        <td style="font-size:12px;"><?= currency((float)$i['unit_cost']) ?></td>
                        <td style="font-size:12px;font-weight:600;"><?= currency((float)$i['transfer_value']) ?></td>
                        <?php if ($editable): ?>
                        <td>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this line?');">
                                <input type="hidden" name="act" value="remove_item">
                                <input type="hidden" name="transfer_id" value="<?= $transferId ?>">
                                <input type="hidden" name="item_id" value="<?= (int)$i['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm"><i data-lucide="x"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

renderHead($pharmacyName . ' — Stock Transfer');
renderSidebar();
?>
<div class="main-content">
<?php renderTopbar('Stock Transfer', 'Move stock between Tade Pharmacy locations'); ?>
<div class="page-body">

<?php if (flashGet()): ?>
<div class="alert alert-<?= flashGet()['type'] === 'success' ? 'success' : 'danger' ?>">
    <i data-lucide="<?= flashGet()['type'] === 'success' ? 'check-circle' : 'x-circle' ?>"></i>
    <?= htmlspecialchars(flashGet()['message']) ?>
</div>
<?php endif; ?>

<?php if ($action === 'new' || ($action === 'edit' && $viewTransfer && empty($viewTransfer['source_applied_at']))): ?>
    <?php $isEdit = $action === 'edit' && $viewTransfer; ?>
    <form method="POST" id="transferForm">
        <input type="hidden" name="act" value="<?= $isEdit ? 'update_transfer' : 'save_transfer' ?>">
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$viewTransfer['id'] ?>"><?php endif; ?>
        <div class="card mb-20">
            <div class="card-header">
                <span class="card-title"><?= $isEdit ? 'Edit Draft Transfer' : 'New Transfer' ?></span>
                <a href="stock_transfer.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Cancel</a>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Transfer Number</label><input type="text" readonly value="<?= htmlspecialchars($isEdit ? $viewTransfer['transfer_number'] : 'Generated on save') ?>"></div>
                <div class="form-group"><label>Transfer Date *</label><input type="date" name="transfer_date" value="<?= htmlspecialchars($isEdit ? $viewTransfer['transfer_date'] : date('Y-m-d')) ?>" required></div>
                <div class="form-group">
                    <label>From Location *</label>
                    <select name="from_location_id" required>
                        <option value="">— Select source —</option>
                        <?php foreach ($locations as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" <?= $isEdit && (int)$viewTransfer['from_location_id'] === (int)$l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?><?= $l['code'] ? ' (' . htmlspecialchars($l['code']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>To Location *</label>
                    <select name="to_location_id" required>
                        <option value="">— Select destination —</option>
                        <?php foreach ($locations as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" <?= $isEdit && (int)$viewTransfer['to_location_id'] === (int)$l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?><?= $l['code'] ? ' (' . htmlspecialchars($l['code']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Sender</label>
                    <select name="sender_id">
                        <option value="">— Optional —</option>
                        <?php foreach ($staffUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $isEdit && (int)$viewTransfer['sender_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Receiver</label>
                    <select name="receiver_id">
                        <option value="">— Optional —</option>
                        <?php foreach ($staffUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $isEdit && (int)$viewTransfer['receiver_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Notes</label><input type="text" name="notes" value="<?= htmlspecialchars($isEdit ? (string)$viewTransfer['notes'] : '') ?>"></div>
            </div>
            <?php if ($isEdit && count($viewItems) > 0): ?>
            <p style="font-size:12px;color:var(--text-300);">Existing lines are shown below and will be re-saved together with the lines you add.</p>
            <?php endif; ?>
        </div>

        <div class="card mb-20">
            <div class="card-header"><span class="card-title">Products</span><span class="badge badge-gray" id="tfTotal">0.00</span></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Product *</th><th>Batch *</th><th>Expiry</th><th>Available</th><th>Transfer Qty *</th><th>Unit Cost (inventory) *</th><th>Transfer Value</th><th></th></tr></thead>
                    <tbody id="tfRows">
                    <?php foreach (($isEdit ? $viewItems : []) as $i): ?>
                        <tr class="tf-row">
                            <td>
                                <select name="product_id[]" class="tf-med" required>
                                    <option value="">— Select —</option>
                                    <?php foreach ($allMeds as $m): ?>
                                    <option value="<?= (int)$m['id'] ?>" <?= (int)$i['medicine_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="batch_id[]" value="<?= (int)$i['batch_id'] ?>">
                            </td>
                            <td><input type="text" class="tf-batchno" readonly value="<?= htmlspecialchars($i['batch_number'] ?: '') ?>" style="width:100px;"></td>
                            <td><input type="date" name="expiry_date[]" value="<?= htmlspecialchars((string)$i['expiry_date']) ?>"></td>
                            <td class="tf-avail" style="font-size:12px;color:var(--text-300);"><?= number_format((int)$i['batch_qty']) ?></td>
                            <td><input type="number" name="quantity[]" class="tf-qty" min="1" value="<?= (int)$i['quantity'] ?>" required></td>
                            <td><input type="number" name="unit_cost[]" class="tf-cost" step="0.01" min="0" value="<?= number_format((float)$i['unit_cost'], 2, '.', '') ?>" required></td>
                            <td class="tf-value" style="font-weight:600;"><?= currency((float)$i['transfer_value']) ?></td>
                            <td><button type="button" class="btn btn-danger btn-sm" onclick="removeTransferRow(this)"><i data-lucide="x"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-actions" style="justify-content:flex-start;">
                <button type="button" class="btn btn-ghost btn-sm" onclick="addTransferRow()"><i data-lucide="plus"></i> Add Product</button>
            </div>
            <p style="font-size:12px;color:var(--text-300);padding:0 10px 10px;">
                Valuation uses batch inventory cost, never selling price. Stock leaves the source when the transfer is sent
                and reaches the destination only when it is received.
            </p>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-primary" onclick="reviewTransfer()"><i data-lucide="check-circle"></i> Review &amp; Confirm</button>
            <button type="submit" class="btn btn-ghost"><i data-lucide="save"></i> Save Draft</button>
        </div>
    </form>

    <div id="tfReview" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:var(--bg-400,#fff);max-width:720px;width:92%;max-height:86vh;overflow:auto;border-radius:12px;padding:20px;">
            <h3 style="margin-top:0;">Review &amp; Confirm Transfer</h3>
            <div id="tfReviewBody" style="font-size:13px;"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('tfReview').style.display='none'">Back to Edit</button>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('transferForm').submit()">Confirm &amp; Save Draft</button>
            </div>
        </div>
    </div>

    <script>
    var TF_MEDS = `<?php foreach ($allMeds as $m): ?><option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['name'], ENT_QUOTES) ?></option><?php endforeach; ?>`;
    function addTransferRow() {
        var tr = document.createElement('tr');
        tr.className = 'tf-row';
        tr.innerHTML =
            '<td><select name="product_id[]" class="tf-med" required><option value="">— Select —</option>' + TF_MEDS + '</select>'
            + '<input type="hidden" name="batch_id[]" value=""></td>'
            + '<td><input type="text" class="tf-batchno" readonly placeholder="auto" style="width:100px;"></td>'
            + '<td><input type="date" name="expiry_date[]"></td>'
            + '<td class="tf-avail" style="font-size:12px;color:var(--text-300);">—</td>'
            + '<td><input type="number" name="quantity[]" class="tf-qty" min="1" required></td>'
            + '<td><input type="number" name="unit_cost[]" class="tf-cost" step="0.01" min="0" required></td>'
            + '<td class="tf-value" style="font-weight:600;">—</td>'
            + '<td><button type="button" class="btn btn-danger btn-sm" onclick="removeTransferRow(this)"><i data-lucide="x"></i></button></td>';
        document.getElementById('tfRows').appendChild(tr);
        bindTransferRow(tr);
        if (window.lucide) { lucide.createIcons(); }
        recalcTransfer();
    }
    function removeTransferRow(btn) {
        btn.closest('tr').remove();
        recalcTransfer();
    }
    function loadTransferBatch(row) {
        var med = row.querySelector('.tf-med').value;
        if (!med) { return; }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'stock_exchange_ajax.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            var data = {};
            try { data = JSON.parse(xhr.responseText); } catch (e) { return; }
            if (!data.ok || !data.batches || !data.batches.length) {
                row.querySelector('.tf-batchno').value = 'no batch';
                row.querySelector('.tf-avail').textContent = '0';
                return;
            }
            var b = data.batches[0];
            row.querySelector('input[name="batch_id[]"]').value = b.id;
            row.querySelector('.tf-batchno').value = b.batch_number;
            row.querySelector('.tf-avail').textContent = b.quantity;
            var qty = row.querySelector('.tf-qty');
            if (qty && (!qty.value || parseInt(qty.value, 10) > parseInt(b.quantity, 10))) { qty.value = b.quantity; }
            var cost = row.querySelector('.tf-cost');
            if (cost && (!cost.value || parseFloat(cost.value) === 0)) { cost.value = parseFloat(b.purchase_price || 0).toFixed(2); }
            var exp = row.querySelector('input[name="expiry_date[]"]');
            if (exp && !exp.value && b.expiry_date) { exp.value = b.expiry_date; }
            recalcTransfer();
        };
        xhr.send('act=batches&medicine_id=' + encodeURIComponent(med));
    }
    function bindTransferRow(row) {
        var med = row.querySelector('.tf-med');
        if (med) { med.addEventListener('change', function () { loadTransferBatch(row); }); }
        row.querySelectorAll('.tf-qty,.tf-cost').forEach(function (el) { el.addEventListener('input', recalcTransfer); });
    }
    function recalcTransfer() {
        var total = 0;
        document.querySelectorAll('#tfRows .tf-row').forEach(function (row) {
            var q = parseFloat(row.querySelector('.tf-qty').value) || 0;
            var c = parseFloat(row.querySelector('.tf-cost').value) || 0;
            var v = q * c;
            total += v;
            row.querySelector('.tf-value').textContent = v > 0 ? v.toFixed(2) : '—';
        });
        document.getElementById('tfTotal').textContent = total.toFixed(2);
        return total;
    }
    function reviewTransfer() {
        var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr><th style="text-align:left;">Product</th><th>Batch</th><th>Qty</th><th>Unit Cost</th><th>Value</th></tr></thead><tbody>';
        document.querySelectorAll('#tfRows .tf-row').forEach(function (row) {
            var sel = row.querySelector('.tf-med');
            var q = parseFloat(row.querySelector('.tf-qty').value) || 0;
            var c = parseFloat(row.querySelector('.tf-cost').value) || 0;
            if (!sel.value || q <= 0) { return; }
            html += '<tr><td>' + (sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text : '—') + '</td><td>'
                 + (row.querySelector('.tf-batchno').value || '—') + '</td><td style="text-align:right;">' + q
                 + '</td><td style="text-align:right;">' + c.toFixed(2) + '</td><td style="text-align:right;">' + (q * c).toFixed(2) + '</td></tr>';
        });
        html += '</tbody></table><p style="margin-top:12px;"><strong>Total Transfer Value:</strong> ' + recalcTransfer().toFixed(2) + '</p>';
        html += '<p style="color:var(--text-300);">Saving creates a DRAFT. Source stock is deducted when you send, and destination stock is added when you receive.</p>';
        document.getElementById('tfReviewBody').innerHTML = html;
        document.getElementById('tfReview').style.display = 'flex';
    }
    document.querySelectorAll('#tfRows .tf-row').forEach(bindTransferRow);
    recalcTransfer();
    </script>

<?php elseif ($viewTransfer): ?>
    <div class="card mb-20">
        <div class="card-header">
            <span class="card-title"><?= htmlspecialchars($viewTransfer['transfer_number']) ?></span>
            <div class="row-actions">
                <a href="stock_transfer.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> All Transfers</a>
                <?php if (empty($viewTransfer['source_applied_at']) && $canAdd): ?>
                <a href="stock_transfer.php?action=edit&id=<?= (int)$viewTransfer['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="pencil"></i> Edit Draft</a>
                <?php endif; ?>
                <a href="stock_transfer.php?action=export&id=<?= (int)$viewTransfer['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="download"></i> Export</a>
                <a href="stock_movement_history.php?q=<?= urlencode((string)$viewTransfer['transfer_number']) ?>" class="btn btn-ghost btn-sm"><i data-lucide="history"></i> Movement History</a>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>From Location</label><input type="text" readonly value="<?= htmlspecialchars($viewTransfer['from_name']) ?>"></div>
            <div class="form-group"><label>To Location</label><input type="text" readonly value="<?= htmlspecialchars($viewTransfer['to_name']) ?>"></div>
            <div class="form-group"><label>Date</label><input type="text" readonly value="<?= htmlspecialchars($viewTransfer['transfer_date']) ?>"></div>
            <div class="form-group"><label>Status</label><input type="text" readonly value="<?= htmlspecialchars($transferStatuses[$viewTransfer['status']] ?? $viewTransfer['status']) ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Sender</label><input type="text" readonly value="<?= htmlspecialchars($viewTransfer['sender_name']) ?>"></div>
            <div class="form-group"><label>Receiver</label><input type="text" readonly value="<?= htmlspecialchars($viewTransfer['receiver_name']) ?>"></div>
            <div class="form-group"><label>Source Stock Applied</label><input type="text" readonly value="<?= htmlspecialchars($viewTransfer['source_applied_at'] ?: 'Not yet (stock still at source)') ?>"></div>
            <div class="form-group"><label>Destination Stock Applied</label><input type="text" readonly value="<?= htmlspecialchars($viewTransfer['destination_applied_at'] ?: 'Not yet') ?>"></div>
        </div>
        <?php if ($viewTransfer['notes']): ?>
        <div class="form-group"><label>Notes</label><textarea readonly rows="2"><?= htmlspecialchars((string)$viewTransfer['notes']) ?></textarea></div>
        <?php endif; ?>
        <?php
        $nextStatuses = $statusFlow[$viewTransfer['status']] ?? [];
        if ($nextStatuses && $canAdd):
        ?>
        <div class="form-actions" style="flex-wrap:wrap;">
            <?php foreach ($nextStatuses as $next):
                if ($next === 'cancelled' && !$canReceive) { continue; }
            ?>
            <form method="POST" onsubmit="return confirm('Move transfer to <?= htmlspecialchars($transferStatuses[$next]) ?>?<?= in_array($next, ['sent','in_transit','received','completed'], true) ? ' Stock will move.' : '' ?>');">
                <input type="hidden" name="act" value="update_status">
                <input type="hidden" name="id" value="<?= (int)$viewTransfer['id'] ?>">
                <input type="hidden" name="status" value="<?= htmlspecialchars($next) ?>">
                <button type="submit" class="btn <?= in_array($next, ['received','completed'], true) ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
                    <i data-lucide="arrow-right"></i> <?= htmlspecialchars($transferStatuses[$next]) ?>
                    <?= $next === 'sent' ? '(deduct source)' : (in_array($next, ['received','completed'], true) ? '(add destination)' : '') ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php renderTransferItems($viewItems, $currency, empty($viewTransfer['source_applied_at']) && $canAdd, (int)$viewTransfer['id']); ?>

    <?php if (empty($viewTransfer['source_applied_at']) && $canAdd): ?>
    <div class="card mb-20">
        <div class="card-header"><span class="card-title"><i data-lucide="plus"></i> Add Product Line</span></div>
        <form method="POST">
            <input type="hidden" name="act" value="add_item">
            <input type="hidden" name="id" value="<?= (int)$viewTransfer['id'] ?>">
            <div class="form-row" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));">
                <div class="form-group"><label>Product *</label>
                    <select name="med[]" class="tf-med" required>
                        <option value="">— Select —</option>
                        <?php foreach ($allMeds as $m): ?><option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Batch *</label><input type="text" class="tf-batchno" readonly placeholder="auto"><input type="hidden" name="batch[]" value=""></div>
                <div class="form-group"><label>Available</label><input type="text" class="tf-avail" readonly value="—"></div>
                <div class="form-group"><label>Quantity *</label><input type="number" name="qty[]" class="tf-qty" min="1" required></div>
                <div class="form-group"><label>Unit Cost *</label><input type="number" name="cost[]" class="tf-cost" step="0.01" min="0" required></div>
                <div class="form-group"><label>Expiry</label><input type="date" name="expiry[]"></div>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Add Product</button></div>
        </form>
    </div>
    <script>
    function loadTransferBatch(row) {
        var med = row.querySelector('.tf-med').value;
        if (!med) { return; }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'stock_exchange_ajax.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            var data = {};
            try { data = JSON.parse(xhr.responseText); } catch (e) { return; }
            if (!data.ok || !data.batches || !data.batches.length) { return; }
            var b = data.batches[0];
            var hidden = row.querySelector('input[name="batch[]"]');
            if (hidden) { hidden.value = b.id; }
            var info = row.querySelector('.tf-batchno');
            if (info) { info.value = b.batch_number; }
            var avail = row.querySelector('.tf-avail');
            if (avail) { avail.value = b.quantity; }
            var cost = row.querySelector('.tf-cost');
            if (cost && (!cost.value || parseFloat(cost.value) === 0)) { cost.value = parseFloat(b.purchase_price || 0).toFixed(2); }
            var qty = row.querySelector('.tf-qty');
            if (qty) { qty.max = b.quantity; }
            var exp = row.querySelector('input[name="expiry[]"]');
            if (exp && !exp.value && b.expiry_date) { exp.value = b.expiry_date; }
        };
        xhr.send('act=batches&medicine_id=' + encodeURIComponent(med));
    }
    document.querySelector('.tf-med').addEventListener('change', function () { loadTransferBatch(this.closest('.form-row')); });
    </script>
    <?php endif; ?>

<?php else: ?>

<div class="stats-grid">
    <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="truck"></i></div><div><div class="stat-label">Total Transfers</div><div class="stat-value"><?= number_format((int)$agg['total']) ?></div></div></div>
    <div class="stat-card gray"><div class="stat-icon gray"><i data-lucide="file-text"></i></div><div><div class="stat-label">Draft</div><div class="stat-value"><?= number_format((int)$agg['draft']) ?></div></div></div>
    <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="paper-airplane"></i></div><div><div class="stat-label">Sent</div><div class="stat-value"><?= number_format((int)$agg['sent']) ?></div></div></div>
    <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="clock"></i></div><div><div class="stat-label">In Transit</div><div class="stat-value"><?= number_format((int)$agg['transit']) ?></div></div></div>
    <div class="stat-card yellow"><div class="stat-icon yellow"><i data-lucide="check-double"></i></div><div><div class="stat-label">Received</div><div class="stat-value"><?= number_format((int)$agg['received']) ?></div></div></div>
    <div class="stat-card green"><div class="stat-icon green"><i data-lucide="check-circle"></i></div><div><div class="stat-label">Completed</div><div class="stat-value"><?= number_format((int)$agg['completed']) ?></div></div></div>
    <div class="stat-card red"><div class="stat-icon red"><i data-lucide="dollar-sign"></i></div><div><div class="stat-label">Total Inventory Value Transferred</div><div class="stat-value"><?= currency((float)$agg['total_value']) ?></div></div></div>
</div>

<div class="card mb-20">
    <div class="card-header">
        <span class="card-title"><i data-lucide="filter"></i> Filters</span>
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-left:auto;">
            <div class="form-group" style="margin:0;"><label>Status</label>
                <select name="filter">
                    <option value="all">All</option>
                    <?php foreach ($transferStatuses as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $filter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;"><label>Search</label><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Number or location"></div>
            <button type="submit" class="btn btn-ghost btn-sm"><i data-lucide="search"></i> Apply</button>
            <a href="stock_transfer.php" class="btn btn-ghost btn-sm">Clear</a>
        </form>
    </div>
</div>

<div class="card mb-20">
    <div class="card-header">
        <span class="card-title"><i data-lucide="truck"></i> Transfers</span>
        <?php if ($canAdd): ?><a href="stock_transfer.php?action=new" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New Transfer</a><?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Number</th><th>Date</th><th>From</th><th>To</th><th>Products</th><th>Status</th><th>Sender</th><th>Receiver</th><th>Value</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$transfers): ?>
                <tr><td colspan="10" style="text-align:center;padding:24px;color:var(--text-300);">No transfers found.</td></tr>
            <?php else: ?>
            <?php foreach ($transfers as $t):
                $badge = $t['status'] === 'completed' ? 'badge-green' : ($t['status'] === 'draft' ? 'badge-gray' : 'badge-orange');
            ?>
            <tr>
                <td style="font-size:12px;font-weight:600;"><?= htmlspecialchars($t['transfer_number']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($t['transfer_date']) ?></td>
                <td style="font-size:12px;font-weight:500;"><?= htmlspecialchars($t['from_name']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($t['to_name']) ?></td>
                <td style="font-size:12px;"><?= (int)$t['item_count'] ?> item(s)<br><span style="color:var(--text-300);"><?= number_format((int)$t['total_qty']) ?> units</span></td>
                <td style="font-size:12px;"><span class="badge <?= $badge ?>"><?= htmlspecialchars($transferStatuses[$t['status']] ?? $t['status']) ?></span></td>
                <td style="font-size:12px;"><?= htmlspecialchars($t['sender_name']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($t['receiver_name']) ?></td>
                <td style="font-size:12px;font-weight:600;"><?= currency((float)$t['transfer_value']) ?></td>
                <td style="font-size:12px;">
                    <div class="row-actions">
                        <a href="stock_transfer.php?action=view&id=<?= (int)$t['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="eye"></i> View</a>
                        <?php if (empty($t['source_applied_at']) && $canAdd): ?>
                        <a href="stock_transfer.php?action=edit&id=<?= (int)$t['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="pencil"></i> Edit</a>
                        <?php endif; ?>
                        <a href="stock_transfer.php?action=export&id=<?= (int)$t['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="download"></i></a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:8px;margin-top:12px;">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="stock_transfer.php?page=<?= $page - 1 ?>&filter=<?= urlencode($filter) ?>&q=<?= urlencode($search) ?>">Previous</a><?php endif; ?>
        <span class="pagination-info" style="align-self:center;">Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a class="btn btn-ghost btn-sm" href="stock_transfer.php?page=<?= $page + 1 ?>&filter=<?= urlencode($filter) ?>&q=<?= urlencode($search) ?>">Next</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>
</div></div>
<?php renderFooter(); ?>
