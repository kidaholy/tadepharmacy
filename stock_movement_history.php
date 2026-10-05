<?php
/**
 * Stock Movement History — one searchable ledger for every inventory quantity change.
 *
 * Inventory value is always quantity × inventory cost (batch cost), never selling price.
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

$canView = can('inventory.history') || can('inventory.view') || can('inventory.manage') || can('inventory.adjust');
if (!$canView) {
    redirectHome();
}

$typeLabels = stockMovementTypeLabels();

$medId = (int)($_GET['med'] ?? 0);
$batchId = (int)($_GET['batch_id'] ?? 0) ?: null;
$filter = $_GET['filter'] ?? 'all';
$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;

/** Movement type groups so "Return" and "Transfer" filters match every variant. */
$typeGroups = [
    'adjustment' => ['adjustment'],
    'purchase' => ['purchase'],
    'sale' => ['sale', 'sale_correction', 'void_restore', 'sale_return', 'sale_return_unsellable'],
    'return' => ['sale_return', 'purchase_return', 'external_stock_return', 'external_stock_return_received'],
    'transfer' => ['transfer_out', 'transfer_in'],
    'external' => ['external_stock_given', 'external_stock_received'],
    'manual' => ['manual', 'receipt', 'issue'],
    'writeoff' => ['deactivate', 'writeoff', 'expiry'],
];
$validFilters = array_merge(['all'], array_keys($typeGroups));
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

/** Source / destination labels derived from the reference transaction. */
$sourceExpr = "
    CASE
        WHEN sm.reference_type = 'stock_transfer' THEN (SELECT f.name FROM stock_transfers t JOIN locations f ON f.id = t.from_location_id WHERE t.id = sm.reference_id)
        WHEN sm.reference_type = 'stock_exchange' AND sm.movement_type = 'external_stock_received' THEN (SELECT e.name FROM stock_exchanges x JOIN external_pharmacies e ON e.id = x.external_pharmacy_id WHERE x.id = sm.reference_id)
        WHEN sm.reference_type = 'stock_exchange' AND sm.movement_type = 'external_stock_return_received' THEN (SELECT e.name FROM stock_exchanges x JOIN external_pharmacies e ON e.id = x.external_pharmacy_id WHERE x.id = sm.reference_id)
        WHEN sm.reference_type = 'purchase' THEN (SELECT COALESCE(s.name,'Supplier') FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.id = sm.reference_id)
        WHEN sm.reference_type = 'sale' THEN (SELECT COALESCE(c.name,'Walk-in customer') FROM sales sa LEFT JOIN customers c ON c.id = sa.customer_id WHERE sa.id = sm.reference_id)
        WHEN sm.reference_type = 'stock_adjustment' THEN 'Adjustment entry'
        WHEN sm.reference_type = 'stock_movement' THEN 'Manual entry'
        ELSE '—'
    END
";
$destExpr = "
    CASE
        WHEN sm.reference_type = 'stock_transfer' THEN (SELECT d.name FROM stock_transfers t JOIN locations d ON d.id = t.to_location_id WHERE t.id = sm.reference_id)
        WHEN sm.reference_type = 'stock_exchange' THEN (SELECT e.name FROM stock_exchanges x JOIN external_pharmacies e ON e.id = x.external_pharmacy_id WHERE x.id = sm.reference_id)
        WHEN sm.reference_type = 'stock_exchange' AND sm.movement_type = 'external_stock_received' THEN 'TADE PHARMACY'
        WHEN sm.reference_type = 'purchase' THEN 'TADE PHARMACY'
        WHEN sm.reference_type = 'sale' THEN 'Customer'
        WHEN sm.reference_type = 'stock_adjustment' THEN 'TADE PHARMACY'
        WHEN sm.reference_type = 'stock_movement' THEN 'TADE PHARMACY'
        ELSE '—'
    END
";

$where = ['1=1'];
$params = [];
if ($medId) { $where[] = 'sm.medicine_id = ?'; $params[] = $medId; }
if ($batchId) { $where[] = 'sm.batch_id = ?'; $params[] = $batchId; }
if ($fromDate !== '') { $where[] = "date(sm.created_at) >= ?"; $params[] = $fromDate; }
if ($toDate !== '') { $where[] = "date(sm.created_at) <= ?"; $params[] = $toDate; }
if ($search !== '') {
    $where[] = "(COALESCE(sm.reference,'') LIKE ? OR m.name LIKE ? OR COALESCE(sm.reason,'') LIKE ? OR COALESCE(b.batch_number,'') LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($filter !== 'all') {
    $group = $typeGroups[$filter];
    $where[] = 'sm.movement_type IN (' . implode(',', array_fill(0, count($group), '?')) . ')';
    foreach ($group as $t) { $params[] = $t; }
}
$whereSql = implode(' AND ', $where);

$baseFrom = "FROM stock_movements sm
    LEFT JOIN medicines m ON m.id = sm.medicine_id
    LEFT JOIN batches b ON b.id = sm.batch_id
    LEFT JOIN categories c ON c.id = m.category_id
    LEFT JOIN users u ON u.id = sm.user_id
    WHERE $whereSql";

$countStmt = $pdo->prepare("SELECT COUNT(*) $baseFrom");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$kpiStmt = $pdo->prepare("SELECT
    COUNT(*) AS total,
    COALESCE(SUM(sm.quantity_in),0) AS qty_in,
    COALESCE(SUM(sm.quantity_out),0) AS qty_out,
    COALESCE(SUM(sm.quantity_in * COALESCE(sm.unit_cost, b.purchase_price, 0)),0) AS value_in,
    COALESCE(SUM(sm.quantity_out * COALESCE(sm.unit_cost, b.purchase_price, 0)),0) AS value_out
    $baseFrom");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch() ?: ['total' => 0, 'qty_in' => 0, 'qty_out' => 0, 'value_in' => 0, 'value_out' => 0];

$rowsStmt = $pdo->prepare("
    SELECT sm.*, m.name AS med_name, m.unit, b.batch_number, b.expiry_date, b.purchase_price,
           c.name AS cat_name, COALESCE(u.full_name,'—') AS user_name,
           COALESCE(sm.unit_cost, b.purchase_price, 0) AS effective_cost,
           ($sourceExpr) AS source_label,
           ($destExpr) AS destination_label
    $baseFrom
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT $perPage OFFSET $offset
");
$rowsStmt->execute($params);
$movements = $rowsStmt->fetchAll();

// ── CSV export ──────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $expStmt = $pdo->prepare("
        SELECT sm.*, m.name AS med_name, b.batch_number, b.expiry_date, c.name AS cat_name,
               COALESCE(u.full_name,'—') AS user_name,
               COALESCE(sm.unit_cost, b.purchase_price, 0) AS effective_cost,
               ($sourceExpr) AS source_label,
               ($destExpr) AS destination_label
        $baseFrom
        ORDER BY sm.created_at DESC, sm.id DESC
    ");
    $expStmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock-movement-history.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    csvWrite($out, ['Movement ID', 'Date/Time', 'Type', 'Product', 'Category', 'Batch', 'Expiry', 'Qty In', 'Qty Out',
        'Unit Cost', 'Inventory Value', 'Source', 'Destination', 'Reference', 'Reason', 'User']);
    foreach ($expStmt->fetchAll() as $r) {
        $qty = (int)$r['quantity_in'] > 0 ? (int)$r['quantity_in'] : (int)$r['quantity_out'];
        csvWrite($out, [
            (int)$r['id'], $r['created_at'], $typeLabels[$r['movement_type']] ?? $r['movement_type'],
            $r['med_name'], $r['cat_name'] ?: '—', $r['batch_number'] ?: '—', $r['expiry_date'] ?: '—',
            (int)$r['quantity_in'], (int)$r['quantity_out'],
            number_format((float)$r['effective_cost'], 2, '.', ''),
            number_format($qty * (float)$r['effective_cost'], 2, '.', ''),
            $r['source_label'], $r['destination_label'], $r['reference'] ?: '—',
            $r['reason'] ?: '', $r['user_name'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Print view ──────────────────────────────────────────────────────────
if (($_GET['print'] ?? '') === '1') {
    $prStmt = $pdo->prepare("
        SELECT sm.*, m.name AS med_name, b.batch_number, b.expiry_date, c.name AS cat_name,
               COALESCE(u.full_name,'—') AS user_name,
               COALESCE(sm.unit_cost, b.purchase_price, 0) AS effective_cost,
               ($sourceExpr) AS source_label, ($destExpr) AS destination_label
        $baseFrom
        ORDER BY sm.created_at DESC, sm.id DESC
    ");
    $prStmt->execute($params);
    $printRows = $prStmt->fetchAll();
    ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Stock Movement History</title>
<style>body{font-family:Arial,Helvetica,sans-serif;margin:20px;font-size:11px;}
table{width:100%;border-collapse:collapse;}th,td{border:1px solid #999;padding:4px;}th{background:#eee;}td.r{text-align:right;}</style>
</head><body onload="window.print()">
<h1 style="font-size:16px;"><?= htmlspecialchars($pharmacyName) ?> — Stock Movement History</h1>
<p><?= count($printRows) ?> movement(s)<?= $fromDate !== '' ? ' · from ' . htmlspecialchars($fromDate) : '' ?><?= $toDate !== '' ? ' · to ' . htmlspecialchars($toDate) : '' ?>
<?= $search !== '' ? ' · search: ' . htmlspecialchars($search) : '' ?></p>
<table><thead><tr><th>ID</th><th>Date</th><th>Type</th><th>Product</th><th>Batch</th><th>Expiry</th>
<th class="r">In</th><th class="r">Out</th><th class="r">Unit Cost</th><th class="r">Value</th><th>Source</th><th>Destination</th><th>Reference</th><th>User</th></tr></thead><tbody>
<?php foreach ($printRows as $r):
    $qty = (int)$r['quantity_in'] > 0 ? (int)$r['quantity_in'] : (int)$r['quantity_out'];
?>
<tr><td><?= (int)$r['id'] ?></td><td><?= htmlspecialchars(substr((string)$r['created_at'], 0, 19)) ?></td>
<td><?= htmlspecialchars($typeLabels[$r['movement_type']] ?? $r['movement_type']) ?></td>
<td><?= htmlspecialchars($r['med_name'] ?? '—') ?></td><td><?= htmlspecialchars($r['batch_number'] ?: '—') ?></td>
<td><?= htmlspecialchars($r['expiry_date'] ?: '—') ?></td>
<td class="r"><?= (int)$r['quantity_in'] ?: '' ?></td><td class="r"><?= (int)$r['quantity_out'] ?: '' ?></td>
<td class="r"><?= number_format((float)$r['effective_cost'], 2) ?></td>
<td class="r"><?= number_format($qty * (float)$r['effective_cost'], 2) ?></td>
<td><?= htmlspecialchars((string)$r['source_label']) ?></td><td><?= htmlspecialchars((string)$r['destination_label']) ?></td>
<td><?= htmlspecialchars($r['reference'] ?: '—') ?></td><td><?= htmlspecialchars($r['user_name']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<p>Inventory value uses quantity × inventory cost.</p>
</body></html>
    <?php
    exit;
}

$medicines = $pdo->query("SELECT id, name, unit FROM medicines ORDER BY name")->fetchAll();
$medBatches = [];
if ($medId) {
    $bst = $pdo->prepare("SELECT id, batch_number, quantity FROM batches WHERE medicine_id = ? ORDER BY expiry_date ASC");
    $bst->execute([$medId]);
    $medBatches = $bst->fetchAll();
}

renderHead($pharmacyName . ' — Stock Movement History');
renderSidebar();
?>
<div class="main-content">
<?php renderTopbar('Stock Movement History', 'Full audit trail of every inventory quantity change'); ?>
<div class="page-body">

<?php if (flashGet()): ?>
<div class="alert alert-<?= flashGet()['type'] === 'success' ? 'success' : 'danger' ?>">
    <i data-lucide="<?= flashGet()['type'] === 'success' ? 'check-circle' : 'x-circle' ?>"></i>
    <?= htmlspecialchars(flashGet()['message']) ?>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="history"></i></div><div><div class="stat-label">Movements</div><div class="stat-value"><?= number_format((int)$kpi['total']) ?></div></div></div>
    <div class="stat-card green"><div class="stat-icon green"><i data-lucide="arrow-down-left"></i></div><div><div class="stat-label">Quantity In</div><div class="stat-value"><?= number_format((int)$kpi['qty_in']) ?></div></div></div>
    <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="arrow-up-right"></i></div><div><div class="stat-label">Quantity Out</div><div class="stat-value"><?= number_format((int)$kpi['qty_out']) ?></div></div></div>
    <div class="stat-card green"><div class="stat-icon green"><i data-lucide="trending-up"></i></div><div><div class="stat-label">Value In (cost)</div><div class="stat-value"><?= currency((float)$kpi['value_in']) ?></div></div></div>
    <div class="stat-card red"><div class="stat-icon red"><i data-lucide="trending-down"></i></div><div><div class="stat-label">Value Out (cost)</div><div class="stat-value"><?= currency((float)$kpi['value_out']) ?></div></div></div>
</div>

<div class="card mb-20">
    <div class="card-header">
        <span class="card-title"><i data-lucide="filter"></i> Filters</span>
        <?php if ($canView): ?>
        <div class="row-actions">
            <a href="stock_adjustment_manual.php" class="btn btn-ghost btn-sm"><i data-lucide="plus"></i> Manual Movement</a>
            <a href="stock_movement_history.php?export=csv&<?= http_build_query(array_filter(['med' => $medId ?: null, 'batch_id' => $batchId, 'filter' => $filter, 'from_date' => $fromDate, 'to_date' => $toDate, 'q' => $search])) ?>" class="btn btn-ghost btn-sm"><i data-lucide="download"></i> Excel</a>
            <a href="stock_movement_history.php?print=1&<?= http_build_query(array_filter(['med' => $medId ?: null, 'batch_id' => $batchId, 'filter' => $filter, 'from_date' => $fromDate, 'to_date' => $toDate, 'q' => $search])) ?>" target="_blank" class="btn btn-ghost btn-sm"><i data-lucide="printer"></i> Print</a>
        </div>
        <?php endif; ?>
    </div>
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:12px;">
        <div class="form-group" style="margin:0;"><label>From</label><input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>"></div>
        <div class="form-group" style="margin:0;"><label>To</label><input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>"></div>
        <div class="form-group" style="margin:0;">
            <label>Movement Type</label>
            <select name="filter">
                <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All types</option>
                <?php
                $groupLabels = [
                    'adjustment' => 'Stock Adjustment',
                    'purchase' => 'Purchase',
                    'sale' => 'Sale / Corrections',
                    'return' => 'Returns',
                    'transfer' => 'Internal Transfers',
                    'external' => 'External Exchange',
                    'manual' => 'Manual / Other',
                    'writeoff' => 'Write-offs',
                ];
                foreach ($groupLabels as $key => $label): ?>
                <option value="<?= $key ?>" <?= $filter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label>Product</label>
            <select name="med">
                <option value="0">All products</option>
                <?php foreach ($medicines as $m): ?>
                <option value="<?= (int)$m['id'] ?>" <?= $medId === (int)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($medBatches): ?>
        <div class="form-group" style="margin:0;">
            <label>Batch</label>
            <select name="batch_id">
                <option value="0">All batches</option>
                <?php foreach ($medBatches as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= $batchId === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['batch_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-group" style="margin:0;"><label>Search</label><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Reference / product / reason"></div>
        <button type="submit" class="btn btn-ghost btn-sm"><i data-lucide="search"></i> Apply</button>
        <a href="stock_movement_history.php" class="btn btn-ghost btn-sm">Clear</a>
    </form>
</div>

<div class="card mb-20">
    <div class="card-header">
        <span class="card-title">Movement Log</span>
        <div style="font-size:12px;color:var(--text-300);"><?= number_format($totalRows) ?> records</div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>Date/Time</th><th>Type</th><th>Product</th><th>Category</th><th>Batch</th><th>Expiry</th>
                    <th>Qty In</th><th>Qty Out</th><th>Unit Cost</th><th>Inventory Value</th><th>Source</th><th>Destination</th>
                    <th>Reference</th><th>Reason</th><th>User</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$movements): ?>
                <tr><td colspan="16" style="text-align:center;padding:24px;color:var(--text-300);">No movements found.</td></tr>
            <?php else: ?>
            <?php foreach ($movements as $mv):
                $qtyIn = (int)$mv['quantity_in'];
                $qtyOut = (int)$mv['quantity_out'];
                $qty = $qtyIn > 0 ? $qtyIn : $qtyOut;
                $cost = (float)$mv['effective_cost'];
            ?>
            <tr>
                <td style="font-size:12px;color:var(--text-300);">#<?= (int)$mv['id'] ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars(substr((string)($mv['created_at'] ?? ''), 0, 19)) ?></td>
                <td style="font-size:12px;"><span class="badge <?= $qtyIn > 0 ? 'badge-green' : 'badge-gray' ?>"><?= htmlspecialchars($typeLabels[$mv['movement_type']] ?? ucfirst(str_replace('_', ' ', (string)$mv['movement_type']))) ?></span></td>
                <td style="font-size:12px;"><?= htmlspecialchars($mv['med_name'] ?? '—') ?></td>
                <td style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($mv['cat_name'] ?: '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($mv['batch_number'] ?: '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($mv['expiry_date'] ?: '—') ?></td>
                <td style="font-size:12px;color:var(--accent2);"><?= $qtyIn > 0 ? number_format($qtyIn) : '' ?></td>
                <td style="font-size:12px;color:var(--danger);"><?= $qtyOut > 0 ? number_format($qtyOut) : '' ?></td>
                <td style="font-size:12px;"><?= currency($cost) ?></td>
                <td style="font-size:12px;font-weight:600;"><?= currency($qty * $cost) ?></td>
                <td style="font-size:12px;color:var(--text-200);"><?= htmlspecialchars((string)($mv['source_label'] ?? '—')) ?></td>
                <td style="font-size:12px;color:var(--text-200);"><?= htmlspecialchars((string)($mv['destination_label'] ?? '—')) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($mv['reference'] ?: '—') ?></td>
                <td style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($mv['reason'] ?: '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($mv['user_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:8px;margin-top:12px;">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="stock_movement_history.php?<?= http_build_query(array_filter(['page' => $page - 1, 'med' => $medId ?: null, 'batch_id' => $batchId, 'filter' => $filter, 'from_date' => $fromDate, 'to_date' => $toDate, 'q' => $search])) ?>">Previous</a><?php endif; ?>
        <span class="pagination-info" style="align-self:center;">Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a class="btn btn-ghost btn-sm" href="stock_movement_history.php?<?= http_build_query(array_filter(['page' => $page + 1, 'med' => $medId ?: null, 'batch_id' => $batchId, 'filter' => $filter, 'from_date' => $fromDate, 'to_date' => $toDate, 'q' => $search])) ?>">Next</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($medId):
    $medName = '';
    foreach ($medicines as $m) { if ((int)$m['id'] === $medId) { $medName = $m['name']; break; } }
    $timeline = fetchStockHistory($pdo, $medId, $batchId);
?>
<div class="card mb-20">
    <div class="card-header">
        <span class="card-title">Running Balance — <?= htmlspecialchars($medName) ?><?= $batchId ? ' (one batch)' : '' ?></span>
        <a href="stock_movement_history.php" class="btn btn-ghost btn-sm"><i data-lucide="x"></i> Close</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Type</th><th>Batch</th><th>In</th><th>Out</th><th>Balance</th><th>Reference</th><th>Source</th></tr></thead>
            <tbody>
            <?php foreach ($timeline as $row): ?>
            <tr>
                <td style="font-size:12px;"><?= htmlspecialchars(substr((string)$row['date'], 0, 19)) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($typeLabels[$row['type']] ?? ucfirst(str_replace('_', ' ', (string)$row['type']))) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($row['batch_number'] ?: '—') ?></td>
                <td style="font-size:12px;color:var(--accent2);"><?= (int)$row['qty_in'] ?: '' ?></td>
                <td style="font-size:12px;color:var(--danger);"><?= (int)$row['qty_out'] ?: '' ?></td>
                <td style="font-size:12px;font-weight:600;"><?= number_format((int)$row['balance']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars((string)$row['reference']) ?></td>
                <td style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($row['source'] === 'ledger' ? 'Ledger' : 'Reconstructed from history') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
