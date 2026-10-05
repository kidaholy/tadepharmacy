<?php
/**
 * Stock Exchange — Tade Pharmacy ↔ external pharmacy.
 *
 * Workflow:  Draft → Post (stock + movement rows applied exactly once) → Settle
 * Rules:     valuation by INVENTORY COST only; never creates a Sale or Purchase;
 *            unlimited products on both sides; settlement by value difference;
 *            temporary borrow/loan tracked until returned.
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

$canAdd    = can('inventory.exchange') || can('inventory.manage');
$canView   = $canAdd || can('inventory.view');
$canSettle = can('inventory.exchange') || can('inventory.manage');
$canAdmin  = can('inventory.exchange') || can('settings.manage') || can('inventory.delete');

$txTypes = exchangeTransactionTypes();
$settlementOptions = exchangeSettlementOptions();
$statusLabels = exchangeStatusLabels();

/** Parse posted parallel arrays for one side into validated lines. */
function parseExchangeLines(array $src, string $side): array
{
    $meds = $src['med'] ?? [];
    $batches = $src['batch'] ?? [];
    $qtys = $src['qty'] ?? [];
    $costs = $src['cost'] ?? [];
    $expiries = $src['expiry'] ?? [];
    $batchnos = $src['batchno'] ?? [];
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
            'side' => $side,
            'medicine_id' => $mid,
            'batch_id' => (int)($batches[$i] ?? 0) ?: null,
            'batch_number' => trim((string)($batchnos[$i] ?? '')),
            'quantity' => $qty,
            'unit_cost' => $cost,
            'expiry_date' => trim((string)($expiries[$i] ?? '')) ?: null,
            'value' => round($qty * $cost, 2),
        ];
    }
    return $lines;
}

/** Insert one product line and keep parent totals in sync. */
function insertExchangeLine(PDO $pdo, int $exchangeId, array $line): int
{
    $batchId = $line['batch_id'];
    if (!$batchId) {
        $batchId = findOrCreateBatch(
            $pdo,
            (int)$line['medicine_id'],
            null,
            (string)($line['batch_number'] ?? ''),
            (string)($line['expiry_date'] ?? ''),
            (float)$line['unit_cost']
        );
    }
    $expiry = $line['expiry_date'];
    if (!$expiry) {
        $st = $pdo->prepare("SELECT expiry_date FROM batches WHERE id = ?");
        $st->execute([$batchId]);
        $expiry = $st->fetchColumn() ?: null;
    }
    $pdo->prepare("
        INSERT INTO stock_exchange_products
            (exchange_id, side, medicine_id, batch_id, quantity, unit_cost, unit_price, product_value, expiry_date)
        VALUES (?,?,?,?,?,?,?,?,?)
    ")->execute([
        $exchangeId,
        $line['side'],
        $line['medicine_id'],
        $batchId,
        $line['quantity'],
        $line['unit_cost'],
        $line['unit_cost'],
        $line['value'],
        $expiry,
    ]);
    return (int)$pdo->lastInsertId();
}

/** Movement type for each side of an exchange. */
function exchangeMovementType(string $side, string $transactionType): string
{
    if ($side === 'given') {
        return $transactionType === 'return_to_external' ? 'external_stock_return' : 'external_stock_given';
    }
    return $transactionType === 'return_received_from_external' ? 'external_stock_return_received' : 'external_stock_received';
}

/**
 * Apply stock effects + movement rows for a posted exchange (idempotent: only from draft).
 */
function postExchangeLines(PDO $pdo, array $exchange, array $lines, int $userId, string $currency): void
{
    $exchangeId = (int)$exchange['id'];
    $number = $exchange['exchange_number'];
    $type = (string)$exchange['transaction_type'];
    $extId = (int)$exchange['external_pharmacy_id'];
    $txMeta = exchangeTransactionTypes()[$type] ?? ['loan' => null];

    foreach ($lines as $line) {
        $side = $line['side'];
        $isIn = $side === 'received';
        $delta = $isIn ? (int)$line['quantity'] : -1 * (int)$line['quantity'];
        // A return to the external pharmacy reverses the original inbound stock.
        if ($type === 'return_to_external') {
            $delta = -1 * (int)$line['quantity'];
        }
        if ($type === 'return_received_from_external') {
            $delta = (int)$line['quantity'];
        }
        $label = ($isIn ? 'received' : 'given') . ' product #' . $line['medicine_id'];
        $movementType = exchangeMovementType($side, $type);

        applyBatchDelta(
            $pdo,
            (int)$line['medicine_id'],
            $line['batch_id'] ? (int)$line['batch_id'] : null,
            $delta,
            $movementType,
            'Exchange ' . $number . ' (' . ($txTypes[$type]['label'] ?? $type) . ')',
            'stock_exchange',
            $exchangeId,
            $number,
            $userId,
            $label,
            (float)$line['unit_cost']
        );
    }

    // Outstanding (temporary borrow / loan / return) tracking.
    $loanDir = $txMeta['loan'] ?? null;
    foreach ($lines as $line) {
        if ($loanDir === 'in' && $line['side'] === 'received') {
            recordOutstandingLoan($pdo, $extId, 'in', (int)$line['medicine_id'], $line['batch_id'] ? (int)$line['batch_id'] : null,
                (int)$line['quantity'], (float)$line['unit_cost'], $type, $exchangeId, $number, $userId);
        } elseif ($loanDir === 'out' && $line['side'] === 'given') {
            recordOutstandingLoan($pdo, $extId, 'out', (int)$line['medicine_id'], $line['batch_id'] ? (int)$line['batch_id'] : null,
                (int)$line['quantity'], (float)$line['unit_cost'], $type, $exchangeId, $number, $userId);
        } elseif ($loanDir === 'settle_in' && $line['side'] === 'given') {
            applyLoanReturn($pdo, (int)$line['medicine_id'], 'in', (int)$line['quantity']);
        } elseif ($loanDir === 'settle_out' && $line['side'] === 'received') {
            applyLoanReturn($pdo, (int)$line['medicine_id'], 'out', (int)$line['quantity']);
        }
    }
}

/** Total outstanding loan value on the books (cost value). */
function outstandingStockValue(PDO $pdo): float
{
    $v = $pdo->query("SELECT COALESCE(SUM((quantity - returned_qty) * unit_cost),0) FROM external_stock_loans WHERE status='outstanding'")->fetchColumn();
    return round((float)$v, 2);
}

// ── POST actions ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $redirect = 'stock_exchange.php';

    try {
        if ($act === 'save_exchange' || $act === 'update_exchange') {
            if (!$canAdd) {
                throw new RuntimeException('You do not have permission to create a stock exchange.');
            }
            $editingId = $act === 'update_exchange' ? (int)($_POST['id'] ?? 0) : 0;
            $externalId = (int)($_POST['external_pharmacy_id'] ?? 0);
            $date = trim($_POST['exchange_date'] ?? '') ?: date('Y-m-d');
            $type = trim($_POST['transaction_type'] ?? 'stock_exchange');
            if (!isset($txTypes[$type])) {
                $type = 'stock_exchange';
            }
            $notes = trim($_POST['notes'] ?? '');
            $settlement = trim($_POST['settlement'] ?? '');
            if ($settlement !== '' && !isset($settlementOptions[$settlement])) {
                $settlement = '';
            }
            $settlementAmount = round((float)($_POST['settlement_amount'] ?? 0), 2);
            $settlementReason = trim($_POST['settlement_reason'] ?? '');

            if (!$externalId) {
                throw new RuntimeException('Select an external pharmacy.');
            }
            $given = parseExchangeLines([
                'med' => $_POST['given_med'] ?? [], 'batch' => $_POST['given_batch'] ?? [],
                'qty' => $_POST['given_qty'] ?? [], 'cost' => $_POST['given_cost'] ?? [],
                'expiry' => $_POST['given_expiry'] ?? [], 'batchno' => $_POST['given_batchno'] ?? [],
            ], 'given');
            $received = parseExchangeLines([
                'med' => $_POST['received_med'] ?? [], 'batch' => $_POST['received_batch'] ?? [],
                'qty' => $_POST['received_qty'] ?? [], 'cost' => $_POST['received_cost'] ?? [],
                'expiry' => $_POST['received_expiry'] ?? [], 'batchno' => $_POST['received_batchno'] ?? [],
            ], 'received');

            if (!$given && !$received) {
                throw new RuntimeException('Add at least one product on either side.');
            }
            if ($settlement === 'waived' && $settlementReason === '') {
                throw new RuntimeException('A reason is required when waiving the difference.');
            }

            $pdo->beginTransaction();
            if ($editingId) {
                $chk = $pdo->prepare("SELECT * FROM stock_exchanges WHERE id = ?");
                $chk->execute([$editingId]);
                $existing = $chk->fetch();
                if (!$existing) {
                    throw new RuntimeException('Exchange not found.');
                }
                if ($existing['status'] !== 'draft') {
                    throw new RuntimeException('Only draft exchanges can be edited. Post or cancel it first.');
                }
                $pdo->prepare("DELETE FROM stock_exchange_products WHERE exchange_id = ?")->execute([$editingId]);
                $pdo->prepare("UPDATE stock_exchanges SET external_pharmacy_id=?, exchange_date=?, transaction_type=?, settlement=?, settlement_amount=?, settlement_reason=?, notes=?, updated_at=datetime('now') WHERE id=?")
                    ->execute([$externalId, $date, $type, $settlement ?: null, $settlementAmount, $settlementReason ?: null, $notes, $editingId]);
                $exchangeId = $editingId;
                $number = $existing['exchange_number'];
            } else {
                $number = generateExchangeNumber($pdo);
                $pdo->prepare("
                    INSERT INTO stock_exchanges
                        (exchange_number, external_pharmacy_id, exchange_date, transaction_type, status, settlement,
                         settlement_amount, settlement_reason, difference_value, notes, created_by)
                    VALUES (?,?,?,?,'draft',?,?,?,0,?,?)
                ")->execute([$number, $externalId, $date, $type, $settlement ?: null, $settlementAmount,
                    $settlementReason ?: null, $notes, $userId ?: null]);
                $exchangeId = (int)$pdo->lastInsertId();
            }

            foreach (array_merge($given, $received) as $line) {
                insertExchangeLine($pdo, $exchangeId, $line);
            }
            recalcExchangeTotals($pdo, $exchangeId);
            $pdo->commit();

            flashSet('success', "Exchange #$number saved as draft. Review it, then post to apply stock.");
            header('Location: stock_exchange.php?action=view&id=' . $exchangeId);
            exit;
        }

        if ($act === 'add_exchange_product') {
            if (!$canAdd) {
                throw new RuntimeException('You do not have permission to edit this exchange.');
            }
            $exchangeId = (int)($_POST['exchange_id'] ?? 0);
            $side = ($_POST['side'] ?? 'given') === 'received' ? 'received' : 'given';
            $chk = $pdo->prepare("SELECT * FROM stock_exchanges WHERE id = ?");
            $chk->execute([$exchangeId]);
            $exchange = $chk->fetch();
            if (!$exchange) {
                throw new RuntimeException('Exchange not found.');
            }
            if ($exchange['status'] !== 'draft') {
                throw new RuntimeException('Products can only be added while the exchange is a draft.');
            }
            $lines = parseExchangeLines([
                'med' => $_POST['med'] ?? [], 'batch' => $_POST['batch'] ?? [],
                'qty' => $_POST['qty'] ?? [], 'cost' => $_POST['cost'] ?? [],
                'expiry' => $_POST['expiry'] ?? [], 'batchno' => $_POST['batchno'] ?? [],
            ], $side);
            if (!$lines) {
                throw new RuntimeException('Select a product, batch and quantity greater than zero.');
            }
            $pdo->beginTransaction();
            foreach ($lines as $line) {
                insertExchangeLine($pdo, $exchangeId, $line);
            }
            recalcExchangeTotals($pdo, $exchangeId);
            $pdo->commit();
            flashSet('success', count($lines) . ' product line(s) added.');
            header('Location: stock_exchange.php?action=view&id=' . $exchangeId);
            exit;
        }

        if ($act === 'post_exchange') {
            if (!$canAdd) {
                throw new RuntimeException('You do not have permission to post this exchange.');
            }
            $exchangeId = (int)($_POST['id'] ?? 0);
            $pdo->beginTransaction();
            $chk = $pdo->prepare("SELECT * FROM stock_exchanges WHERE id = ?");
            $chk->execute([$exchangeId]);
            $exchange = $chk->fetch();
            if (!$exchange) {
                throw new RuntimeException('Exchange not found.');
            }
            if ($exchange['status'] !== 'draft') {
                throw new RuntimeException('This exchange has already been posted.');
            }
            $linesStmt = $pdo->prepare("SELECT * FROM stock_exchange_products WHERE exchange_id = ? ORDER BY side, id");
            $linesStmt->execute([$exchangeId]);
            $lines = $linesStmt->fetchAll();
            if (!$lines) {
                throw new RuntimeException('Add at least one product before posting.');
            }
            postExchangeLines($pdo, $exchange, $lines, $userId, $currency);
            // Leaving draft first lets the value difference and outstanding loans decide the status.
            $pdo->prepare("UPDATE stock_exchanges SET status = 'pending', posted_at = datetime('now'), posted_by = ? WHERE id = ?")
                ->execute([$userId ?: null, $exchangeId]);
            recalcExchangeTotals($pdo, $exchangeId);
            $pdo->commit();
            flashSet('success', 'Exchange #' . $exchange['exchange_number'] . ' posted. Stock movements recorded.');
            header('Location: stock_exchange.php?action=view&id=' . $exchangeId);
            exit;
        }

        if ($act === 'remove_exchange_product') {
            if (!$canAdd) {
                throw new RuntimeException('You do not have permission to edit this exchange.');
            }
            $lineId = (int)($_POST['product_id'] ?? 0);
            $exchangeId = (int)($_POST['exchange_id'] ?? 0);
            $pdo->beginTransaction();
            $chk = $pdo->prepare("SELECT * FROM stock_exchanges WHERE id = ?");
            $chk->execute([$exchangeId]);
            $exchange = $chk->fetch();
            if (!$exchange) {
                throw new RuntimeException('Exchange not found.');
            }
            if ($exchange['status'] !== 'draft') {
                throw new RuntimeException('Posted exchanges cannot have product lines removed. Record a return instead.');
            }
            $pdo->prepare("DELETE FROM stock_exchange_products WHERE id = ? AND exchange_id = ?")->execute([$lineId, $exchangeId]);
            recalcExchangeTotals($pdo, $exchangeId);
            $pdo->commit();
            flashSet('success', 'Product line removed.');
            header('Location: stock_exchange.php?action=view&id=' . $exchangeId);
            exit;
        }

        if ($act === 'settle_exchange') {
            if (!$canSettle) {
                throw new RuntimeException('You do not have permission to settle this exchange.');
            }
            $exchangeId = (int)($_POST['id'] ?? 0);
            $settlement = trim($_POST['settlement'] ?? '');
            $amount = round((float)($_POST['settlement_amount'] ?? 0), 2);
            $reason = trim($_POST['settlement_reason'] ?? '');
            if ($settlement === '' || !isset($settlementOptions[$settlement])) {
                throw new RuntimeException('Choose a settlement option.');
            }
            if ($settlement === 'waived' && $reason === '') {
                throw new RuntimeException('A reason is required when waiving the difference.');
            }
            $chk = $pdo->prepare("SELECT * FROM stock_exchanges WHERE id = ?");
            $chk->execute([$exchangeId]);
            $exchange = $chk->fetch();
            if (!$exchange) {
                throw new RuntimeException('Exchange not found.');
            }
            if ($exchange['status'] === 'draft') {
                throw new RuntimeException('Post the exchange before settling it.');
            }
            $pdo->prepare("UPDATE stock_exchanges SET settlement=?, settlement_amount=?, settlement_reason=?, settled_at=datetime('now'), settled_by=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$settlement, $amount, $reason ?: null, $userId ?: null, $exchangeId]);

            // A value settlement closes the exchange unless stock is still outstanding.
            $outstanding = exchangeOutstandingQty($pdo, $exchangeId);
            refreshExchangeStatus($pdo, $exchangeId);
            if (function_exists('auditLog')) {
                auditLog($pdo, 'exchange_settle', 'stock_exchange', $exchangeId,
                    "Settlement '{$settlement}' of " . number_format($amount, 2) . " on {$exchange['exchange_number']}" . ($outstanding > 0 ? " ({$outstanding} unit(s) still outstanding)" : ''));
            }
            flashSet('success', 'Settlement recorded for #' . $exchange['exchange_number'] . '.');
            header('Location: stock_exchange.php?action=view&id=' . $exchangeId);
            exit;
        }

        if ($act === 'cancel_exchange') {
            if (!$canAdmin) {
                throw new RuntimeException('You do not have permission to cancel this exchange.');
            }
            $exchangeId = (int)($_POST['id'] ?? 0);
            $chk = $pdo->prepare("SELECT * FROM stock_exchanges WHERE id = ?");
            $chk->execute([$exchangeId]);
            $exchange = $chk->fetch();
            if (!$exchange) {
                throw new RuntimeException('Exchange not found.');
            }
            if ($exchange['status'] !== 'draft') {
                throw new RuntimeException('Only draft exchanges can be cancelled. Posted exchanges must be returned, not deleted.');
            }
            $pdo->prepare("UPDATE stock_exchanges SET status='cancelled', updated_at=datetime('now') WHERE id=?")->execute([$exchangeId]);
            flashSet('success', 'Draft exchange cancelled.');
            header('Location: stock_exchange.php');
            exit;
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flashSet('error', $e->getMessage());
        header('Location: ' . (($_POST['id'] ?? 0) ? 'stock_exchange.php?action=view&id=' . (int)$_POST['id'] : $redirect));
        exit;
    }
}

// ── GET: export / print / single view / list ─────────────────────────────
$action = $_GET['action'] ?? '';

if ($action === 'export' && $canView) {
    $exchangeId = (int)($_GET['id'] ?? 0);
    if ($exchangeId) {
        $st = $pdo->prepare("
            SELECT p.*, m.name AS med_name, b.batch_number, COALESCE(e.name,'—') AS ext_name, x.exchange_number, x.exchange_date, x.status
            FROM stock_exchange_products p
            JOIN stock_exchanges x ON x.id = p.exchange_id
            JOIN external_pharmacies e ON e.id = x.external_pharmacy_id
            JOIN medicines m ON m.id = p.medicine_id
            LEFT JOIN batches b ON b.id = p.batch_id
            WHERE p.exchange_id = ?
            ORDER BY p.side, p.id
        ");
        $st->execute([$exchangeId]);
        $rows = $st->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="exchange-' . $exchangeId . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        csvWrite($out, ['Exchange', 'Date', 'External Pharmacy', 'Status', 'Side', 'Product', 'Batch', 'Expiry', 'Quantity', 'Unit Cost', 'Inventory Value']);
        $tGiven = 0.0;
        $tReceived = 0.0;
        foreach ($rows as $r) {
            $value = (float)$r['product_value'];
            if ($r['side'] === 'given') { $tGiven += $value; } else { $tReceived += $value; }
            csvWrite($out, [
                $r['exchange_number'], $r['exchange_date'], $r['ext_name'], $r['status'],
                $r['side'] === 'given' ? 'Given by Tade' : 'Received by Tade',
                $r['med_name'], $r['batch_number'] ?: '—', $r['expiry_date'] ?: '—',
                (int)$r['quantity'], number_format((float)$r['unit_cost'], 2, '.', ''), number_format($value, 2, '.', ''),
            ]);
        }
        csvWrite($out, ['', '', '', '', 'TOTAL GIVEN', '', '', '', '', '', number_format($tGiven, 2, '.', '')]);
        csvWrite($out, ['', '', '', '', 'TOTAL RECEIVED', '', '', '', '', '', number_format($tReceived, 2, '.', '')]);
        csvWrite($out, ['', '', '', '', 'DIFFERENCE', '', '', '', '', '', number_format($tGiven - $tReceived, 2, '.', '')]);
        fclose($out);
        exit;
    }
}

if ($action === 'print' && $canView) {
    $exchangeId = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT x.*, e.name AS ext_name, e.contact_person, e.phone, e.address, COALESCE(u.full_name,'—') AS user_name
        FROM stock_exchanges x
        JOIN external_pharmacies e ON e.id = x.external_pharmacy_id
        LEFT JOIN users u ON u.id = x.created_by
        WHERE x.id = ?");
    $st->execute([$exchangeId]);
    $x = $st->fetch();
    if (!$x) {
        flashSet('error', 'Exchange not found.');
        header('Location: stock_exchange.php');
        exit;
    }
    $linesStmt = $pdo->prepare("SELECT p.*, m.name AS med_name, m.unit, b.batch_number FROM stock_exchange_products p
        JOIN medicines m ON m.id = p.medicine_id LEFT JOIN batches b ON b.id = p.batch_id
        WHERE p.exchange_id = ? ORDER BY p.side, p.id");
    $linesStmt->execute([$exchangeId]);
    $lines = $linesStmt->fetchAll();
    $given = array_values(array_filter($lines, fn($l) => $l['side'] === 'given'));
    $received = array_values(array_filter($lines, fn($l) => $l['side'] === 'received'));
    $givenValue = array_sum(array_map(fn($l) => (float)$l['product_value'], $given));
    $receivedValue = array_sum(array_map(fn($l) => (float)$l['product_value'], $received));
    $difference = round($givenValue - $receivedValue, 2);
    $loans = outstandingLoans($pdo, null, $exchangeId);
    ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Exchange <?= htmlspecialchars($x['exchange_number']) ?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:24px;font-size:12px;}
h1{font-size:18px;margin:0 0 2px;}
h2{font-size:13px;margin:18px 0 6px;text-transform:uppercase;letter-spacing:.04em;}
table{width:100%;border-collapse:collapse;margin-top:6px;}
th,td{border:1px solid #999;padding:5px 6px;text-align:left;}
th{background:#eee;}
.right{text-align:right;}
.head{display:flex;justify-content:space-between;align-items:flex-start;}
.muted{color:#555;}
.totals{margin-top:10px;}
.totals div{padding:3px 0;}
</style></head><body onload="window.print()">
<div class="head">
    <div><h1><?= htmlspecialchars($pharmacyName) ?></h1><div class="muted">Stock Exchange Receipt</div></div>
    <div class="right"><strong><?= htmlspecialchars($x['exchange_number']) ?></strong><br><?= htmlspecialchars($x['exchange_date']) ?><br>
    <?= htmlspecialchars($statusLabels[$x['status']] ?? $x['status']) ?></div>
</div>
<h2>External Pharmacy</h2>
<div><?= htmlspecialchars($x['ext_name']) ?><?= $x['contact_person'] ? ' — ' . htmlspecialchars($x['contact_person']) : '' ?>
<?= $x['phone'] ? '<br>Phone: ' . htmlspecialchars($x['phone']) : '' ?><?= $x['address'] ? '<br>' . htmlspecialchars($x['address']) : '' ?></div>
<?php foreach ([['Products Given By TADE', $given], ['Products Received By TADE', $received]] as [$title, $list]): ?>
<h2><?= htmlspecialchars($title) ?></h2>
<table><thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th class="right">Qty</th><th class="right">Unit Cost</th><th class="right">Inventory Value</th></tr></thead><tbody>
<?php if (!$list): ?><tr><td colspan="6" class="muted">None</td></tr><?php endif; ?>
<?php foreach ($list as $l): ?>
<tr><td><?= htmlspecialchars($l['med_name']) ?></td><td><?= htmlspecialchars($l['batch_number'] ?: '—') ?></td>
<td><?= htmlspecialchars($l['expiry_date'] ?: '—') ?></td><td class="right"><?= number_format((int)$l['quantity']) ?></td>
<td class="right"><?= number_format((float)$l['unit_cost'], 2) ?></td><td class="right"><?= number_format((float)$l['product_value'], 2) ?></td></tr>
<?php endforeach; ?>
<tr><th colspan="5">Subtotal</th><th class="right"><?= number_format(array_sum(array_map(fn($l) => (float)$l['product_value'], $list)), 2) ?></th></tr>
</tbody></table>
<?php endforeach; ?>
<div class="totals">
<div><strong>Total Given Value:</strong> <?= number_format($givenValue, 2) ?> <?= htmlspecialchars($currency) ?></div>
<div><strong>Total Received Value:</strong> <?= number_format($receivedValue, 2) ?> <?= htmlspecialchars($currency) ?></div>
<div><strong>Value Difference:</strong> <?= number_format($difference, 2) ?> <?= htmlspecialchars($currency) ?>
<?= $difference > 0 ? '(Tade gave more)' : ($difference < 0 ? '(Tade received more)' : '(balanced)') ?></div>
<?php if ($x['settlement']): ?>
<div><strong>Settlement:</strong> <?= htmlspecialchars($settlementOptions[$x['settlement']] ?? $x['settlement']) ?>
<?= (float)$x['settlement_amount'] > 0 ? ' — ' . number_format((float)$x['settlement_amount'], 2) . ' ' . htmlspecialchars($currency) : '' ?>
<?= $x['settlement_reason'] ? ' (' . htmlspecialchars($x['settlement_reason']) . ')' : '' ?></div>
<?php endif; ?>
<?php if ($loans): ?>
<div><strong>Outstanding (temporary borrow/loan):</strong>
<?php foreach ($loans as $l): ?>
<?= htmlspecialchars($l['med_name']) ?> — <?= (int)$l['quantity'] - (int)$l['returned_qty'] ?> outstanding;
<?php endforeach; ?></div>
<?php endif; ?>
</div>
<p class="muted">Transaction type: <?= htmlspecialchars($txTypes[$x['transaction_type']]['label'] ?? $x['transaction_type']) ?> · Recorded by <?= htmlspecialchars($x['user_name']) ?> on <?= htmlspecialchars((string)$x['created_at']) ?></p>
<?php if ($x['notes']): ?><p class="muted">Notes: <?= nl2br(htmlspecialchars($x['notes'])) ?></p><?php endif; ?>
<p class="muted">Values use batch inventory cost. This document is not a purchase or a sale.</p>
</body></html>
    <?php
    exit;
}

$viewExchange = null;
$viewLines = [];
$viewLoans = [];
if ($action === 'view' || $action === 'edit') {
    $viewId = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT x.*, e.name AS ext_name, e.phone, e.address, e.contact_person, COALESCE(u.full_name,'—') AS user_name
        FROM stock_exchanges x
        JOIN external_pharmacies e ON e.id = x.external_pharmacy_id
        LEFT JOIN users u ON u.id = x.created_by
        WHERE x.id = ?");
    $st->execute([$viewId]);
    $viewExchange = $st->fetch() ?: null;
    if ($viewExchange) {
        $ls = $pdo->prepare("SELECT p.*, m.name AS med_name, m.unit, b.batch_number FROM stock_exchange_products p
            JOIN medicines m ON m.id = p.medicine_id LEFT JOIN batches b ON b.id = p.batch_id
            WHERE p.exchange_id = ? ORDER BY p.side, p.id");
        $ls->execute([$viewId]);
        $viewLines = $ls->fetchAll();
        $viewLoans = outstandingLoans($pdo, null, $viewId);
    }
}

$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'draft', 'pending', 'settled', 'returned', 'cancelled'], true)) {
    $filter = 'all';
}
$typeFilter = $_GET['type'] ?? 'all';
if ($typeFilter !== 'all' && !isset($txTypes[$typeFilter])) {
    $typeFilter = 'all';
}
$extFilter = (int)($_GET['ext'] ?? 0);
$medFilter = (int)($_GET['med'] ?? 0);
$batchFilter = (int)($_GET['batch_id'] ?? 0);
$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$agg = $pdo->query("SELECT
    COUNT(*) AS total,
    COUNT(CASE WHEN status='draft' THEN 1 END) AS draft,
    COUNT(CASE WHEN status='pending' THEN 1 END) AS pending,
    COUNT(CASE WHEN status='settled' THEN 1 END) AS settled,
    COUNT(CASE WHEN status='returned' THEN 1 END) AS returned,
    COALESCE(SUM(CASE WHEN status NOT IN ('cancelled') THEN difference_value ELSE 0 END),0) AS difference_total
FROM stock_exchanges")->fetch();

$givenValueTotal = (float)$pdo->query("SELECT COALESCE(SUM(product_value),0) FROM stock_exchange_products WHERE side='given'")->fetchColumn();
$receivedValueTotal = (float)$pdo->query("SELECT COALESCE(SUM(product_value),0) FROM stock_exchange_products WHERE side='received'")->fetchColumn();
$outstandingUnits = (int)$pdo->query("SELECT COALESCE(SUM(quantity - returned_qty),0) FROM external_stock_loans WHERE status='outstanding'")->fetchColumn();
$outstandingValue = outstandingStockValue($pdo);

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(x.exchange_number LIKE ? OR e.name LIKE ? OR COALESCE(x.notes,\'\') LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filter !== 'all') { $where[] = 'x.status = ?'; $params[] = $filter; }
if ($typeFilter !== 'all') { $where[] = 'x.transaction_type = ?'; $params[] = $typeFilter; }
if ($extFilter) { $where[] = 'x.external_pharmacy_id = ?'; $params[] = $extFilter; }
if ($fromDate !== '') { $where[] = 'x.exchange_date >= ?'; $params[] = $fromDate; }
if ($toDate !== '') { $where[] = 'x.exchange_date <= ?'; $params[] = $toDate; }
if ($medFilter) {
    $where[] = 'EXISTS (SELECT 1 FROM stock_exchange_products p WHERE p.exchange_id = x.id AND p.medicine_id = ?)';
    $params[] = $medFilter;
}
if ($batchFilter) {
    $where[] = 'EXISTS (SELECT 1 FROM stock_exchange_products p WHERE p.exchange_id = x.id AND p.batch_id = ?)';
    $params[] = $batchFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_exchanges x JOIN external_pharmacies e ON e.id = x.external_pharmacy_id WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$rowsStmt = $pdo->prepare("
    SELECT x.*, e.name AS ext_name, COALESCE(u.full_name,'—') AS user_name,
        (SELECT COUNT(*) FROM stock_exchange_products p WHERE p.exchange_id = x.id AND p.side='given') AS given_lines,
        (SELECT COALESCE(SUM(p.quantity),0) FROM stock_exchange_products p WHERE p.exchange_id = x.id AND p.side='given') AS given_qty,
        (SELECT COALESCE(SUM(p.product_value),0) FROM stock_exchange_products p WHERE p.exchange_id = x.id AND p.side='given') AS given_value,
        (SELECT COUNT(*) FROM stock_exchange_products p WHERE p.exchange_id = x.id AND p.side='received') AS received_lines,
        (SELECT COALESCE(SUM(p.quantity),0) FROM stock_exchange_products p WHERE p.exchange_id = x.id AND p.side='received') AS received_qty,
        (SELECT COALESCE(SUM(p.product_value),0) FROM stock_exchange_products p WHERE p.exchange_id = x.id AND p.side='received') AS received_value,
        (SELECT COALESCE(SUM(l.quantity - l.returned_qty),0) FROM external_stock_loans l WHERE l.exchange_id = x.id AND l.status='outstanding') AS outstanding_qty
    FROM stock_exchanges x
    JOIN external_pharmacies e ON e.id = x.external_pharmacy_id
    LEFT JOIN users u ON u.id = x.created_by
    WHERE $whereSql
    ORDER BY x.exchange_date DESC, x.id DESC
    LIMIT $perPage OFFSET $offset
");
$rowsStmt->execute($params);
$exchanges = $rowsStmt->fetchAll();

$externalList = $pdo->query("SELECT id, name, phone, status FROM external_pharmacies ORDER BY name")->fetchAll();
$allMeds = $pdo->query("SELECT id, name, unit FROM medicines ORDER BY name")->fetchAll();

/** Render one side's line table (used on the view page). */
function renderExchangeLineTable(PDO $pdo, array $lines, string $heading, string $currency, bool $editable, int $exchangeId): void
{
    $total = array_sum(array_map(fn($l) => (float)$l['product_value'], $lines));
    ?>
    <div class="card mb-20">
        <div class="card-header"><span class="card-title"><?= htmlspecialchars($heading) ?></span>
            <span style="font-weight:800;"><?= currency($total) ?></span></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th>Qty</th><th>Unit Cost</th><th>Value</th><?php if ($editable): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (!$lines): ?>
                    <tr><td colspan="<?= $editable ? 7 : 6 ?>" style="text-align:center;padding:18px;color:var(--text-300);">No products on this side yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($lines as $l): ?>
                    <tr>
                        <td style="font-size:12px;font-weight:500;"><?= htmlspecialchars($l['med_name']) ?></td>
                        <td style="font-size:12px;"><?= htmlspecialchars($l['batch_number'] ?: '—') ?></td>
                        <td style="font-size:12px;"><?= htmlspecialchars($l['expiry_date'] ?: '—') ?></td>
                        <td style="font-size:12px;"><?= number_format((int)$l['quantity']) ?> <?= htmlspecialchars($l['unit'] ?? '') ?></td>
                        <td style="font-size:12px;"><?= currency((float)$l['unit_cost']) ?></td>
                        <td style="font-size:12px;font-weight:600;"><?= currency((float)$l['product_value']) ?></td>
                        <?php if ($editable): ?>
                        <td>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this product line?');">
                                <input type="hidden" name="act" value="remove_exchange_product">
                                <input type="hidden" name="exchange_id" value="<?= $exchangeId ?>">
                                <input type="hidden" name="product_id" value="<?= (int)$l['id'] ?>">
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

/** Add-product panel for a draft exchange side. */
function renderAddProductPanel(array $medicines, int $exchangeId, string $side, string $label): void
{
    ?>
    <div class="card mb-20">
        <div class="card-header"><span class="card-title"><i data-lucide="plus"></i> <?= htmlspecialchars($label) ?></span></div>
        <form method="POST">
            <input type="hidden" name="act" value="add_exchange_product">
            <input type="hidden" name="exchange_id" value="<?= $exchangeId ?>">
            <input type="hidden" name="side" value="<?= htmlspecialchars($side) ?>">
            <div class="form-row" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));">
                <div class="form-group">
                    <label>Product *</label>
                    <select name="med[]" class="ex-med" required>
                        <option value="">— Select —</option>
                        <?php foreach ($medicines as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Batch</label>
                    <select name="batch[]" class="ex-batch"><option value="">— Select product first —</option></select>
                </div>
                <div class="form-group">
                    <label>Expiry</label>
                    <input type="date" name="expiry[]" class="ex-expiry">
                </div>
                <div class="form-group">
                    <label>Quantity *</label>
                    <input type="number" name="qty[]" class="ex-qty" min="1" required>
                </div>
                <div class="form-group">
                    <label>Unit Cost (inventory) *</label>
                    <input type="number" name="cost[]" class="ex-cost" step="0.01" min="0" required>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Add Product</button>
            </div>
        </form>
    </div>
    <?php
}

renderHead($pharmacyName . ' — Stock Exchange');
renderSidebar();
?>
<div class="main-content">
<?php renderTopbar('Stock Exchange', 'Exchange stock with external pharmacies — valued at inventory cost'); ?>
<div class="page-body">

<?php if (flashGet()): ?>
<div class="alert alert-<?= flashGet()['type'] === 'success' ? 'success' : 'danger' ?>">
    <i data-lucide="<?= flashGet()['type'] === 'success' ? 'check-circle' : 'x-circle' ?>"></i>
    <?= htmlspecialchars(flashGet()['message']) ?>
</div>
<?php endif; ?>

<?php if ($action === 'new' || ($action === 'edit' && $viewExchange && $viewExchange['status'] === 'draft')): ?>
    <?php
    $isEdit = $action === 'edit' && $viewExchange;
    $eGiven = $eReceived = [];
    if ($isEdit) {
        foreach ($viewLines as $l) {
            if ($l['side'] === 'given') { $eGiven[] = $l; } else { $eReceived[] = $l; }
        }
    }
    ?>
    <form method="POST" id="exchangeForm">
        <input type="hidden" name="act" value="<?= $isEdit ? 'update_exchange' : 'save_exchange' ?>">
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$viewExchange['id'] ?>"><?php endif; ?>
        <div class="card mb-20">
            <div class="card-header">
                <span class="card-title"><?= $isEdit ? 'Edit Draft Exchange' : 'New Exchange' ?></span>
                <a href="stock_exchange.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Cancel</a>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Exchange Number</label>
                    <input type="text" readonly value="<?= htmlspecialchars($isEdit ? $viewExchange['exchange_number'] : 'Generated on save') ?>">
                </div>
                <div class="form-group">
                    <label>Date *</label>
                    <input type="date" name="exchange_date" value="<?= htmlspecialchars($isEdit ? $viewExchange['exchange_date'] : date('Y-m-d')) ?>" required>
                </div>
                <div class="form-group">
                    <label>External Pharmacy *</label>
                    <select name="external_pharmacy_id" required>
                        <option value="">— Select —</option>
                        <?php foreach ($externalList as $e): ?>
                        <option value="<?= (int)$e['id'] ?>" <?= $isEdit && (int)$viewExchange['external_pharmacy_id'] === (int)$e['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['name']) ?><?= $e['phone'] ? ' (' . htmlspecialchars($e['phone']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Transaction Type *</label>
                    <select name="transaction_type" required>
                        <?php foreach ($txTypes as $key => $meta): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $isEdit && $viewExchange['transaction_type'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Notes <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
                <textarea name="notes" rows="2"><?= htmlspecialchars($isEdit ? (string)$viewExchange['notes'] : '') ?></textarea>
            </div>
        </div>

        <?php foreach ([['given', 'PRODUCTS GIVEN BY TADE'], ['received', 'PRODUCTS RECEIVED BY TADE']] as [$side, $heading]): ?>
        <div class="card mb-20 exchange-side <?= $side ?>-side">
            <div class="card-header">
                <span class="card-title"><?= $heading ?></span>
                <span class="badge badge-gray">Subtotal: <?= currency(array_sum(array_map(fn($l) => (float)$l['product_value'], $side === 'given' ? $eGiven : $eReceived))) ?></span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Product *</th><th>Batch</th><th>Expiry</th><th>Qty *</th><th>Unit Cost *</th><th>Value</th><th></th></tr></thead>
                    <tbody class="ex-rows" data-side="<?= $side ?>">
                    <?php
                    $existing = $side === 'given' ? $eGiven : $eReceived;
                    foreach ($existing as $l):
                    ?>
                        <tr class="ex-row">
                            <td>
                                <select name="<?= $side ?>_med[]" class="ex-med" required>
                                    <option value="">— Select —</option>
                                    <?php foreach ($allMeds as $m): ?>
                                    <option value="<?= (int)$m['id'] ?>" <?= (int)$l['medicine_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="<?= $side ?>_batch[]" value="<?= (int)$l['batch_id'] ?>">
                                <input type="hidden" name="<?= $side ?>_batchno[]" value="<?= htmlspecialchars((string)$l['batch_number']) ?>">
                            </td>
                            <td style="font-size:12px;"><?= htmlspecialchars($l['batch_number'] ?: '—') ?></td>
                            <td><input type="date" name="<?= $side ?>_expiry[]" value="<?= htmlspecialchars((string)$l['expiry_date']) ?>"></td>
                            <td><input type="number" name="<?= $side ?>_qty[]" class="ex-qty" min="1" value="<?= (int)$l['quantity'] ?>"></td>
                            <td><input type="number" name="<?= $side ?>_cost[]" class="ex-cost" step="0.01" min="0" value="<?= number_format((float)$l['unit_cost'], 2, '.', '') ?>"></td>
                            <td class="ex-value" style="font-weight:600;"><?= currency((float)$l['product_value']) ?></td>
                            <td><button type="button" class="btn btn-danger btn-sm" onclick="removeExchangeRow(this)"><i data-lucide="x"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-actions" style="justify-content:flex-start;">
                <button type="button" class="btn btn-ghost btn-sm" onclick="addExchangeRow('<?= $side ?>')"><i data-lucide="plus"></i> Add Product</button>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="card mb-20">
            <div class="card-header"><span class="card-title">Value &amp; Settlement</span></div>
            <div class="form-row">
                <div class="form-group">
                    <label>Settlement Option</label>
                    <select name="settlement">
                        <?php foreach ($settlementOptions as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $isEdit && (string)$viewExchange['settlement'] === (string)$key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Settlement Amount (<?= htmlspecialchars($currency) ?>)</label>
                    <input type="number" name="settlement_amount" step="0.01" min="0" value="<?= $isEdit ? number_format((float)$viewExchange['settlement_amount'], 2, '.', '') : '0.00' ?>">
                </div>
                <div class="form-group">
                    <label>Waive / Settlement Reason</label>
                    <input type="text" name="settlement_reason" value="<?= $isEdit ? htmlspecialchars((string)$viewExchange['settlement_reason']) : '' ?>" placeholder="Required when waiving">
                </div>
            </div>
            <div id="exTotals" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:8px;">
                <div style="padding:12px;background:var(--bg-600);border-radius:8px;">
                    <div style="font-size:12px;color:var(--text-300);">Total Given Value</div>
                    <div style="font-size:20px;font-weight:800;" id="exGiven"><?= currency(0) ?></div>
                </div>
                <div style="padding:12px;background:var(--bg-600);border-radius:8px;">
                    <div style="font-size:12px;color:var(--text-300);">Total Received Value</div>
                    <div style="font-size:20px;font-weight:800;" id="exReceived"><?= currency(0) ?></div>
                </div>
                <div style="padding:12px;background:var(--bg-600);border-radius:8px;">
                    <div style="font-size:12px;color:var(--text-300);">Value Difference</div>
                    <div style="font-size:20px;font-weight:800;" id="exDiff"><?= currency(0) ?></div>
                </div>
            </div>
            <p style="font-size:12px;color:var(--text-300);margin-top:8px;">
                Settlement is by inventory cost value: Total Given − Total Received. Equal unit counts on both sides do not mean the exchange is settled.
            </p>
            <div class="form-actions">
                <button type="button" class="btn btn-primary" onclick="reviewExchange()"><i data-lucide="check-circle"></i> Review &amp; Confirm</button>
                <button type="submit" class="btn btn-ghost"><i data-lucide="save"></i> Save Draft</button>
            </div>
        </div>
    </form>

    <div id="exReview" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:var(--bg-400,#fff);max-width:720px;width:92%;max-height:86vh;overflow:auto;border-radius:12px;padding:20px;">
            <h3 style="margin-top:0;">Review &amp; Confirm Exchange</h3>
            <div id="exReviewBody" style="font-size:13px;"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('exReview').style.display='none'">Back to Edit</button>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('exchangeForm').submit()">Confirm &amp; Save Draft</button>
            </div>
        </div>
    </div>

    <script>
    var MED_OPTIONS = `<?php foreach ($allMeds as $m): ?><option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['name'], ENT_QUOTES) ?></option><?php endforeach; ?>`;
    function addExchangeRow(side) {
        var tbody = document.querySelector('.ex-rows[data-side="' + side + '"]');
        var tr = document.createElement('tr');
        tr.className = 'ex-row';
        tr.innerHTML =
            '<td><select name="' + side + '_med[]" class="ex-med" required><option value="">— Select —</option>' + MED_OPTIONS + '</select>'
            + '<input type="hidden" name="' + side + '_batch[]" value=""><input type="hidden" name="' + side + '_batchno[]" value=""></td>'
            + '<td><input type="text" class="ex-batchno" readonly placeholder="auto" style="width:90px;"></td>'
            + '<td><input type="date" name="' + side + '_expiry[]"></td>'
            + '<td><input type="number" name="' + side + '_qty[]" class="ex-qty" min="1" required></td>'
            + '<td><input type="number" name="' + side + '_cost[]" class="ex-cost" step="0.01" min="0" required></td>'
            + '<td class="ex-value" style="font-weight:600;">—</td>'
            + '<td><button type="button" class="btn btn-danger btn-sm" onclick="removeExchangeRow(this)"><i data-lucide="x"></i></button></td>';
        tbody.appendChild(tr);
        bindExchangeRow(tr);
        if (window.lucide) { lucide.createIcons(); }
        recalcExchange();
    }
    function removeExchangeRow(btn) {
        btn.closest('tr').remove();
        recalcExchange();
    }
    function findBatch(row) {
        var med = row.querySelector('.ex-med').value;
        var side = row.closest('.ex-rows').getAttribute('data-side');
        var hidden = row.querySelector('input[name="' + side + '_batch[]"]');
        var batchno = row.querySelector('input[name="' + side + '_batchno[]"]');
        var expInput = row.querySelector('input[name="' + side + '_expiry[]"]');
        var costInput = row.querySelector('.ex-cost');
        var info = row.querySelector('.ex-batchno');
        if (!med) { return; }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'stock_exchange_ajax.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            var data = {};
            try { data = JSON.parse(xhr.responseText); } catch (e) { return; }
            if (!data.ok || !data.batches || !data.batches.length) {
                if (info) { info.value = 'no batch'; }
                return;
            }
            var b = data.batches[0];
            hidden.value = b.id;
            batchno.value = b.batch_number;
            if (info) { info.value = b.batch_number; }
            if (expInput && !expInput.value) { expInput.value = b.expiry_date || ''; }
            if (costInput && (!costInput.value || parseFloat(costInput.value) === 0)) {
                costInput.value = parseFloat(b.purchase_price || 0).toFixed(2);
            }
            recalcExchange();
        };
        xhr.send('act=batches&medicine_id=' + encodeURIComponent(med));
    }
    function bindExchangeRow(row) {
        var med = row.querySelector('.ex-med');
        if (med) { med.addEventListener('change', function () { findBatch(row); }); }
        row.querySelectorAll('.ex-qty,.ex-cost').forEach(function (el) {
            el.addEventListener('input', recalcExchange);
        });
    }
    function recalcExchange() {
        var fmt = function (n) { return parseFloat(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
        var given = 0, received = 0;
        document.querySelectorAll('.ex-rows').forEach(function (tbody) {
            var side = tbody.getAttribute('data-side');
            var sub = 0;
            tbody.querySelectorAll('.ex-row').forEach(function (row) {
                var q = parseFloat(row.querySelector('.ex-qty') ? row.querySelector('.ex-qty').value : 0) || 0;
                var c = parseFloat(row.querySelector('.ex-cost') ? row.querySelector('.ex-cost').value : 0) || 0;
                var v = q * c;
                sub += v;
                var cell = row.querySelector('.ex-value');
                if (cell) { cell.textContent = q > 0 ? fmt(v) : '—'; }
            });
            if (side === 'given') { given = sub; } else { received = sub; }
        });
        var diff = given - received;
        document.getElementById('exGiven').textContent = fmt(given);
        document.getElementById('exReceived').textContent = fmt(received);
        document.getElementById('exDiff').textContent = fmt(diff) + (diff > 0 ? ' (Tade gave more)' : (diff < 0 ? ' (Tade received more)' : ' (balanced)'));
    }
    function reviewExchange() {
        var rows = '';
        document.querySelectorAll('.ex-rows').forEach(function (tbody) {
            var side = tbody.getAttribute('data-side');
            rows += '<h4 style="margin:12px 0 4px;">' + (side === 'given' ? 'Products Given By TADE' : 'Products Received By TADE') + '</h4>';
            rows += '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr><th style="text-align:left;">Product</th><th>Qty</th><th>Unit Cost</th><th>Value</th></tr></thead><tbody>';
            tbody.querySelectorAll('.ex-row').forEach(function (row) {
                var sel = row.querySelector('.ex-med');
                var name = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text : '—';
                var q = parseFloat(row.querySelector('.ex-qty').value) || 0;
                var c = parseFloat(row.querySelector('.ex-cost').value) || 0;
                if (!sel.value || q <= 0) { return; }
                rows += '<tr><td>' + name + '</td><td style="text-align:right;">' + q + '</td><td style="text-align:right;">' + c.toFixed(2) + '</td><td style="text-align:right;">' + (q * c).toFixed(2) + '</td></tr>';
            });
            rows += '</tbody></table>';
        });
        rows += '<p style="margin-top:12px;"><strong>Total Given:</strong> ' + document.getElementById('exGiven').textContent
             + ' &nbsp; <strong>Total Received:</strong> ' + document.getElementById('exReceived').textContent
             + ' &nbsp; <strong>Difference:</strong> ' + document.getElementById('exDiff').textContent + '</p>';
        rows += '<p style="color:var(--text-300);">Saving creates a DRAFT. No stock moves until you post the exchange, and each product line writes exactly one movement record.</p>';
        document.getElementById('exReviewBody').innerHTML = rows;
        document.getElementById('exReview').style.display = 'flex';
    }
    document.querySelectorAll('.ex-row').forEach(function (row) {
        var side = row.closest('.ex-rows').getAttribute('data-side');
        var info = row.querySelector('.ex-batchno');
        var hiddenBatch = row.querySelector('input[name="' + side + '_batch[]"]');
        if (info && hiddenBatch && hiddenBatch.value) { info.value = hiddenBatch.value; }
        bindExchangeRow(row);
    });
    recalcExchange();
    </script>

<?php elseif ($viewExchange): ?>
    <div class="card mb-20">
        <div class="card-header">
            <span class="card-title"><?= htmlspecialchars($viewExchange['exchange_number']) ?></span>
            <div class="row-actions">
                <a href="stock_exchange.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> All Exchanges</a>
                <?php if ($viewExchange['status'] === 'draft'): ?>
                <a href="stock_exchange.php?action=edit&id=<?= (int)$viewExchange['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="pencil"></i> Edit Draft</a>
                <?php endif; ?>
                <a href="stock_exchange.php?action=print&id=<?= (int)$viewExchange['id'] ?>" target="_blank" class="btn btn-ghost btn-sm"><i data-lucide="printer"></i> Print</a>
                <a href="stock_exchange.php?action=export&id=<?= (int)$viewExchange['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="download"></i> Export</a>
                <a href="stock_movement_history.php?q=<?= urlencode((string)$viewExchange['exchange_number']) ?>" class="btn btn-ghost btn-sm"><i data-lucide="history"></i> Movement History</a>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>External Pharmacy</label><input type="text" readonly value="<?= htmlspecialchars($viewExchange['ext_name']) ?>"></div>
            <div class="form-group"><label>Date</label><input type="text" readonly value="<?= htmlspecialchars($viewExchange['exchange_date']) ?>"></div>
            <div class="form-group"><label>Transaction Type</label><input type="text" readonly value="<?= htmlspecialchars($txTypes[$viewExchange['transaction_type']]['label'] ?? $viewExchange['transaction_type']) ?>"></div>
            <div class="form-group"><label>Status</label><input type="text" readonly value="<?= htmlspecialchars($statusLabels[$viewExchange['status']] ?? $viewExchange['status']) ?>"></div>
        </div>
        <?php if ($viewExchange['notes']): ?>
        <div class="form-group"><label>Notes</label><textarea readonly rows="2"><?= htmlspecialchars((string)$viewExchange['notes']) ?></textarea></div>
        <?php endif; ?>
        <?php if ($viewExchange['status'] === 'draft'): ?>
        <div class="form-actions">
            <form method="POST" onsubmit="return confirm('Post this exchange? Stock will move and movement records will be written.');">
                <input type="hidden" name="act" value="post_exchange">
                <input type="hidden" name="id" value="<?= (int)$viewExchange['id'] ?>">
                <button type="submit" class="btn btn-primary"><i data-lucide="check-circle"></i> Post Exchange (Apply Stock)</button>
            </form>
            <?php if ($canAdmin): ?>
            <form method="POST" onsubmit="return confirm('Cancel this draft exchange?');">
                <input type="hidden" name="act" value="cancel_exchange">
                <input type="hidden" name="id" value="<?= (int)$viewExchange['id'] ?>">
                <button type="submit" class="btn btn-ghost"><i data-lucide="x-circle"></i> Cancel Draft</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php
    $vGiven = array_values(array_filter($viewLines, fn($l) => $l['side'] === 'given'));
    $vReceived = array_values(array_filter($viewLines, fn($l) => $l['side'] === 'received'));
    $editable = $viewExchange['status'] === 'draft' && $canAdd;
    renderExchangeLineTable($pdo, $vGiven, 'PRODUCTS GIVEN BY TADE', $currency, $editable, (int)$viewExchange['id']);
    renderExchangeLineTable($pdo, $vReceived, 'PRODUCTS RECEIVED BY TADE', $currency, $editable, (int)$viewExchange['id']);
    $vGivenValue = array_sum(array_map(fn($l) => (float)$l['product_value'], $vGiven));
    $vReceivedValue = array_sum(array_map(fn($l) => (float)$l['product_value'], $vReceived));
    $vDifference = round($vGivenValue - $vReceivedValue, 2);
    ?>
    <div class="card mb-20">
        <div class="card-header"><span class="card-title">Value &amp; Settlement</span></div>
        <div class="stats-grid">
            <div class="stat-card blue"><div><div class="stat-label">Total Given Value</div><div class="stat-value"><?= currency($vGivenValue) ?></div></div></div>
            <div class="stat-card green"><div><div class="stat-label">Total Received Value</div><div class="stat-value"><?= currency($vReceivedValue) ?></div></div></div>
            <div class="stat-card <?= $vDifference === 0.0 ? 'green' : 'orange' ?>"><div><div class="stat-label">Value Difference</div><div class="stat-value"><?= currency($vDifference) ?></div></div></div>
        </div>
        <?php if ($vDifference !== 0.0): ?>
        <p style="font-size:13px;color:var(--text-200);margin:8px 0;">
            <?= $vDifference > 0 ? 'Tade gave more value; the external pharmacy owes the difference.' : 'Tade received more value; Tade owes the difference.' ?>
        </p>
        <?php endif; ?>
        <div class="form-row" style="margin-top:8px;">
            <div class="form-group"><label>Settlement Option</label><input type="text" readonly value="<?= htmlspecialchars($settlementOptions[(string)$viewExchange['settlement']] ?? '— Not settled —') ?>"></div>
            <div class="form-group"><label>Settlement Amount</label><input type="text" readonly value="<?= currency((float)$viewExchange['settlement_amount']) ?>"></div>
            <div class="form-group"><label>Reason</label><input type="text" readonly value="<?= htmlspecialchars((string)$viewExchange['settlement_reason'] ?: '—') ?>"></div>
        </div>
        <?php if ($viewExchange['status'] !== 'draft' && $canSettle): ?>
        <form method="POST" style="margin-top:8px;">
            <input type="hidden" name="act" value="settle_exchange">
            <input type="hidden" name="id" value="<?= (int)$viewExchange['id'] ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Settle With</label>
                    <select name="settlement" required>
                        <?php foreach ($settlementOptions as $key => $label): if ($key === '') { continue; } ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount (<?= htmlspecialchars($currency) ?>)</label>
                    <input type="number" name="settlement_amount" step="0.01" min="0" value="<?= number_format(abs($vDifference), 2, '.', '') ?>">
                </div>
                <div class="form-group">
                    <label>Reason / Note</label>
                    <input type="text" name="settlement_reason" placeholder="Required when waiving">
                </div>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary btn-sm"><i data-lucide="check"></i> Record Settlement</button></div>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($viewLoans): ?>
    <div class="card mb-20">
        <div class="card-header"><span class="card-title"><i data-lucide="rotate-cw"></i> Outstanding Stock (temporary borrow / loan)</span></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Product</th><th>Direction</th><th>Received / Given</th><th>Returned</th><th>Outstanding</th><th>Cost Value</th></tr></thead>
                <tbody>
                <?php foreach ($viewLoans as $l):
                    $open = (int)$l['quantity'] - (int)$l['returned_qty'];
                ?>
                <tr>
                    <td style="font-size:12px;"><?= htmlspecialchars($l['med_name']) ?></td>
                    <td style="font-size:12px;"><?= $l['direction'] === 'in' ? 'Tade received (owes return)' : 'Tade gave (expects return)' ?></td>
                    <td style="font-size:12px;"><?= number_format((int)$l['quantity']) ?></td>
                    <td style="font-size:12px;"><?= number_format((int)$l['returned_qty']) ?></td>
                    <td style="font-size:12px;font-weight:700;"><?= number_format($open) ?></td>
                    <td style="font-size:12px;"><?= currency($open * (float)$l['unit_cost']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="font-size:12px;color:var(--text-300);padding:10px;">
            Record a return with a new exchange using transaction type “Return To External Pharmacy” (goods go back) or
            “Return Received From External Pharmacy” (goods come back). Returns settle the oldest outstanding lines first.
        </p>
    </div>
    <?php endif; ?>

    <?php if ($editable): ?>
        <?php
        renderAddProductPanel($allMeds, (int)$viewExchange['id'], 'given', 'Add Product To GIVEN Side');
        renderAddProductPanel($allMeds, (int)$viewExchange['id'], 'received', 'Add Product To RECEIVED Side');
        ?>
        <script>
        function findBatch(row) {
            var med = row.querySelector('.ex-med').value;
            var batchSel = row.querySelector('.ex-batch');
            var expInput = row.querySelector('.ex-expiry');
            var costInput = row.querySelector('.ex-cost');
            if (!med) { return; }
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'stock_exchange_ajax.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function () {
                var data = {};
                try { data = JSON.parse(xhr.responseText); } catch (e) { return; }
                if (!data.ok || !data.batches) { return; }
                var html = '<option value="">— External / new batch —</option>';
                data.batches.forEach(function (b) {
                    html += '<option value="' + b.id + '" data-exp="' + (b.expiry_date || '') + '" data-cost="' + (b.purchase_price || 0) + '">'
                        + b.batch_number + ' — ' + b.quantity + ' in stock</option>';
                });
                batchSel.innerHTML = html;
            };
            xhr.send('act=batches&medicine_id=' + encodeURIComponent(med));
        }
        document.querySelectorAll('.ex-med').forEach(function (sel) {
            sel.addEventListener('change', function () { findBatch(sel.closest('.form-row')); });
        });
        document.querySelectorAll('.ex-batch').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var row = sel.closest('.form-row');
                var opt = sel.options[sel.selectedIndex];
                var cost = row.querySelector('.ex-cost');
                var exp = row.querySelector('.ex-expiry');
                if (opt && opt.getAttribute('data-cost') && (!cost.value || parseFloat(cost.value) === 0)) {
                    cost.value = parseFloat(opt.getAttribute('data-cost')).toFixed(2);
                }
                if (opt && opt.getAttribute('data-exp') && exp && !exp.value) {
                    exp.value = opt.getAttribute('data-exp');
                }
            });
        });
        </script>
    <?php endif; ?>

<?php else: ?>

<div class="stats-grid">
    <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="arrow-right-left"></i></div><div><div class="stat-label">Total Exchanges</div><div class="stat-value"><?= number_format((int)$agg['total']) ?></div></div></div>
    <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="arrow-up-right"></i></div><div><div class="stat-label">Total Value Given</div><div class="stat-value"><?= currency($givenValueTotal) ?></div></div></div>
    <div class="stat-card green"><div class="stat-icon green"><i data-lucide="arrow-down-left"></i></div><div><div class="stat-label">Total Value Received</div><div class="stat-value"><?= currency($receivedValueTotal) ?></div></div></div>
    <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="scale"></i></div><div><div class="stat-label">Value Difference</div><div class="stat-value"><?= currency($agg['difference_total']) ?></div></div></div>
    <div class="stat-card red"><div class="stat-icon red"><i data-lucide="rotate-cw"></i></div><div><div class="stat-label">Outstanding Stock</div><div class="stat-value"><?= number_format($outstandingUnits) ?></div><div style="font-size:11px;color:var(--text-300);"><?= currency($outstandingValue) ?> at cost</div></div></div>
    <div class="stat-card green"><div class="stat-icon green"><i data-lucide="check-circle"></i></div><div><div class="stat-label">Settled Exchanges</div><div class="stat-value"><?= number_format((int)$agg['settled']) ?></div></div></div>
    <div class="stat-card gray"><div class="stat-icon gray"><i data-lucide="clock"></i></div><div><div class="stat-label">Pending Exchanges</div><div class="stat-value"><?= number_format((int)$agg['pending']) ?></div></div></div>
</div>

<div class="card mb-20">
    <div class="card-header">
        <span class="card-title"><i data-lucide="filter"></i> Filters</span>
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-left:auto;">
            <div class="form-group" style="margin:0;"><label>From</label><input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>"></div>
            <div class="form-group" style="margin:0;"><label>To</label><input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>"></div>
            <div class="form-group" style="margin:0;"><label>Pharmacy</label>
                <select name="ext"><option value="0">All</option>
                <?php foreach ($externalList as $e): ?>
                <option value="<?= (int)$e['id'] ?>" <?= $extFilter === (int)$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;"><label>Type</label>
                <select name="type">
                    <option value="all">All</option>
                    <?php foreach ($txTypes as $key => $meta): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= htmlspecialchars($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;"><label>Status</label>
                <select name="filter">
                    <option value="all">All</option>
                    <?php foreach ($statusLabels as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $filter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;"><label>Product</label>
                <select name="med"><option value="0">All</option>
                <?php foreach ($allMeds as $m): ?>
                <option value="<?= (int)$m['id'] ?>" <?= $medFilter === (int)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;"><label>Search</label><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Number / pharmacy / notes"></div>
            <button type="submit" class="btn btn-ghost btn-sm"><i data-lucide="search"></i> Apply</button>
            <a href="stock_exchange.php" class="btn btn-ghost btn-sm">Clear</a>
        </form>
    </div>
</div>

<div class="card mb-20">
    <div class="card-header">
        <span class="card-title"><i data-lucide="arrow-right-left"></i> Exchanges</span>
        <?php if ($canAdd): ?><a href="stock_exchange.php?action=new" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New Exchange</a><?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Exchange ID</th><th>Date</th><th>External Pharmacy</th><th>Products Given</th><th>Products Received</th><th>Total Given</th><th>Total Received</th><th>Difference</th><th>Settlement Status</th><th>User</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$exchanges): ?>
                <tr><td colspan="11" style="text-align:center;padding:24px;color:var(--text-300);">No exchanges found.</td></tr>
            <?php else: ?>
            <?php foreach ($exchanges as $e):
                $diff = (float)$e['difference_value'];
                $badge = $e['status'] === 'settled' ? 'badge-green' : ($e['status'] === 'pending' ? 'badge-orange' : 'badge-gray');
                $outQty = (int)$e['outstanding_qty'];
            ?>
            <tr>
                <td style="font-size:12px;font-weight:600;"><?= htmlspecialchars($e['exchange_number']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($e['exchange_date']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($e['ext_name']) ?></td>
                <td style="font-size:12px;"><?= (int)$e['given_lines'] ?> item(s)<br><span style="color:var(--text-300);"><?= number_format((int)$e['given_qty']) ?> units</span></td>
                <td style="font-size:12px;"><?= (int)$e['received_lines'] ?> item(s)<br><span style="color:var(--text-300);"><?= number_format((int)$e['received_qty']) ?> units</span></td>
                <td style="font-size:12px;"><?= currency((float)$e['given_value']) ?></td>
                <td style="font-size:12px;"><?= currency((float)$e['received_value']) ?></td>
                <td style="font-size:12px;font-weight:600;color:<?= $diff > 0 ? '#dc2626' : ($diff < 0 ? '#d97706' : 'inherit') ?>;"><?= currency($diff) ?></td>
                <td style="font-size:12px;">
                    <span class="badge <?= $badge ?>"><?= htmlspecialchars($statusLabels[$e['status']] ?? $e['status']) ?></span>
                    <?php if ($outQty > 0): ?><br><span style="font-size:11px;color:var(--text-300);"><?= number_format($outQty) ?> outstanding</span><?php endif; ?>
                    <?php if ($e['settlement']): ?><br><span style="font-size:11px;color:var(--text-300);"><?= htmlspecialchars($settlementOptions[$e['settlement']] ?? $e['settlement']) ?></span><?php endif; ?>
                </td>
                <td style="font-size:12px;"><?= htmlspecialchars($e['user_name']) ?></td>
                <td style="font-size:12px;">
                    <div class="row-actions">
                        <a href="stock_exchange.php?action=view&id=<?= (int)$e['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="eye"></i> View</a>
                        <?php if ($e['status'] === 'draft' && $canAdd): ?>
                        <a href="stock_exchange.php?action=edit&id=<?= (int)$e['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="pencil"></i> Edit</a>
                        <?php endif; ?>
                        <a href="stock_exchange.php?action=print&id=<?= (int)$e['id'] ?>" target="_blank" class="btn btn-ghost btn-sm"><i data-lucide="printer"></i></a>
                        <a href="stock_exchange.php?action=export&id=<?= (int)$e['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="download"></i></a>
                        <a href="stock_movement_history.php?q=<?= urlencode((string)$e['exchange_number']) ?>" class="btn btn-ghost btn-sm"><i data-lucide="history"></i></a>
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
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="stock_exchange.php?page=<?= $page - 1 ?>&filter=<?= urlencode($filter) ?>&q=<?= urlencode($search) ?>">Previous</a><?php endif; ?>
        <span class="pagination-info" style="align-self:center;">Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a class="btn btn-ghost btn-sm" href="stock_exchange.php?page=<?= $page + 1 ?>&filter=<?= urlencode($filter) ?>&q=<?= urlencode($search) ?>">Next</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>
</div></div>
<?php renderFooter(); ?>
