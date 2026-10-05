<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory_lib.php';
require_once __DIR__ . '/sales_lib.php';
require_once __DIR__ . '/exchange_functions.php';

$pdo = getDB();
$userId = (int)(currentUser()['id'] ?? 0);
$currency = getSetting('currency', 'ETB');
$pharmacyName = getSetting('pharmacy_name', 'TADE PHARMACY');

$canAdd = can('inventory.adjust') || can('inventory.manage');
$canView = $canAdd || can('inventory.view');

/** Section 2 reasons for the adjustment module. */
$adjReasons = [
    'damaged'         => 'Damaged',
    'expired'         => 'Expired',
    'lost'            => 'Lost/Missing',
    'physical_count'  => 'Physical Count Correction',
    'found'           => 'Found Stock',
    'returned'        => 'Returned',
    'data_correction' => 'Data Correction',
    'other'           => 'Other',
];

// ── POST: create adjustment ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'save_adjustment') {
        if (!$canAdd) {
            flashSet('error', 'You do not have permission to create a stock adjustment.');
            header('Location: stock_adjustment.php');
            exit;
        }
        $medicineId = (int)($_POST['medicine_id'] ?? 0);
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $physicalQty = (int)($_POST['physical_qty'] ?? 0);
        $reasonKey = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $unitCost = (float)($_POST['unit_cost'] ?? 0);
        $adjDate = trim($_POST['adjustment_date'] ?? '') ?: date('Y-m-d');

        if (!$medicineId) {
            flashSet('error', 'Product is required.');
            header('Location: stock_adjustment.php');
            exit;
        }
        if (!$batchId) {
            flashSet('error', 'Select the exact batch to adjust so batch and expiry stay intact.');
            header('Location: stock_adjustment.php?med=' . $medicineId);
            exit;
        }
        if (!isset($adjReasons[$reasonKey])) {
            flashSet('error', 'Select a valid adjustment reason.');
            header('Location: stock_adjustment.php?med=' . $medicineId);
            exit;
        }
        if ($physicalQty < 0) {
            flashSet('error', 'Physical quantity cannot be negative.');
            header('Location: stock_adjustment.php?med=' . $medicineId);
            exit;
        }

        $b = $pdo->prepare('SELECT id, quantity, medicine_id, batch_number, expiry_date, purchase_price FROM batches WHERE id = ? AND medicine_id = ?');
        $b->execute([$batchId, $medicineId]);
        $batch = $b->fetch();
        if (!$batch) {
            flashSet('error', 'Batch not found for this product.');
            header('Location: stock_adjustment.php?med=' . $medicineId);
            exit;
        }

        $systemQty = (int)$batch['quantity'];
        $adjustmentQty = $physicalQty - $systemQty;
        if ($adjustmentQty === 0) {
            flashSet('error', 'Physical quantity equals system quantity. No adjustment needed.');
            header('Location: stock_adjustment.php?med=' . $medicineId);
            exit;
        }
        // Valuation is always inventory cost: the batch cost unless the user entered one.
        if ($unitCost <= 0) {
            $unitCost = (float)$batch['purchase_price'];
        }
        $adjustmentValue = round(abs($adjustmentQty) * $unitCost, 2);
        $reasonLabel = $adjReasons[$reasonKey];

        $pdo->beginTransaction();
        try {
            $number = generateAdjustmentNumber($pdo);
            $pdo->prepare("INSERT INTO stock_adjustments (adjustment_number, adjustment_date, medicine_id, batch_id, category_id, expiry_date, system_qty, physical_qty, adjustment_qty, unit_cost, adjustment_value, reason, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $number,
                    $adjDate,
                    $medicineId,
                    $batchId,
                    (int)($_POST['category_id'] ?? 0) ?: null,
                    $batch['expiry_date'] ?? null,
                    $systemQty,
                    $physicalQty,
                    $adjustmentQty,
                    $unitCost,
                    $adjustmentValue,
                    $reasonKey,
                    $notes,
                    $userId ?: null,
                ]);
            $adjustmentId = (int)$pdo->lastInsertId();

            // Applies the quantity change AND writes exactly one movement row.
            applyBatchDelta(
                $pdo,
                $medicineId,
                $batchId,
                $adjustmentQty,
                'adjustment',
                $number . ' — ' . $reasonLabel . ($notes !== '' ? ': ' . $notes : ''),
                'stock_adjustment',
                $adjustmentId,
                $number,
                $userId,
                'batch ' . $batch['batch_number'],
                (float)$unitCost
            );

            if (function_exists('auditLog')) {
                auditLog($pdo, 'stock_adjustment', 'stock_adjustment', $adjustmentId,
                    "{$number}: batch {$batch['batch_number']} {$systemQty} → {$physicalQty} ({$reasonLabel})");
            }
            $pdo->commit();
            flashSet('success', "Adjustment #$number saved: {$systemQty} → {$physicalQty} ({$reasonLabel}). Stock and movement history updated.");
            header('Location: stock_adjustment.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flashSet('error', 'Adjustment failed: ' . $e->getMessage());
            header('Location: stock_adjustment.php?med=' . $medicineId);
            exit;
        }
    }
    flashSet('error', 'Unknown action.');
    header('Location: stock_adjustment.php');
    exit;
}

// ── GET: list + KPI ─────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'added', 'removed'], true)) $filter = 'all';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$agg = $pdo->query("SELECT
    COUNT(*) AS total,
    COALESCE(SUM(CASE WHEN adjustment_qty > 0 THEN adjustment_value ELSE 0 END),0) AS total_added,
    COALESCE(SUM(CASE WHEN adjustment_qty < 0 THEN -1*adjustment_value ELSE 0 END),0) AS total_removed
FROM stock_adjustments")->fetch();

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(sa.adjustment_number LIKE ? OR m.name LIKE ? OR b.batch_number LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filter === 'added') { $where[] = 'sa.adjustment_qty > 0'; }
if ($filter === 'removed') { $where[] = 'sa.adjustment_qty < 0'; }
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments sa JOIN medicines m ON m.id=sa.medicine_id LEFT JOIN batches b ON b.id=sa.batch_id WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$rows = $pdo->prepare("SELECT sa.*, m.name AS med_name, m.unit, b.batch_number, b.expiry_date, c.name AS cat_name, COALESCE(u.full_name, '—') AS user_name
    FROM stock_adjustments sa
    JOIN medicines m ON m.id = sa.medicine_id
    LEFT JOIN batches b ON b.id = sa.batch_id
    LEFT JOIN categories c ON c.id = sa.category_id
    LEFT JOIN users u ON u.id = sa.user_id
    WHERE $whereSql
    ORDER BY sa.adjustment_date DESC, sa.id DESC
    LIMIT $perPage OFFSET $offset");
$rows->execute($params);
$adjustments = $rows->fetchAll();

$typeLabels = [
    'damaged' => 'Damaged',
    'lost' => 'Lost/Missing',
    'expired' => 'Expired',
    'physical_count' => 'Physical count correction',
    'returned_supplier' => 'Returned to supplier',
    'found' => 'Found stock',
    'disposed' => 'Disposed',
    'other' => 'Other',
];

$allMeds = $pdo->query("SELECT id, name, unit FROM medicines ORDER BY name")->fetchAll();
$allCats = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$medId = (int)($_GET['med'] ?? 0);

// ── Report exports: Excel-compatible CSV and a print view ───────────────
if (($_GET['export'] ?? '') === 'csv' && $canView) {
    $expStmt = $pdo->prepare("SELECT sa.*, m.name AS med_name, m.unit, b.batch_number, c.name AS cat_name, COALESCE(u.full_name,'—') AS user_name
        FROM stock_adjustments sa
        JOIN medicines m ON m.id = sa.medicine_id
        LEFT JOIN batches b ON b.id = sa.batch_id
        LEFT JOIN categories c ON c.id = sa.category_id
        LEFT JOIN users u ON u.id = sa.user_id
        WHERE $whereSql
        ORDER BY sa.adjustment_date DESC, sa.id DESC");
    $expStmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock-adjustments.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    csvWrite($out, ['Adjustment #', 'Date', 'Product', 'Batch', 'Category', 'System Qty', 'Physical Qty', 'Adjustment Qty', 'Unit Cost', 'Adjustment Value', 'Reason', 'Notes', 'User']);
    $added = 0.0;
    $removed = 0.0;
    foreach ($expStmt->fetchAll() as $r) {
        $delta = (int)$r['adjustment_qty'];
        $value = (float)$r['adjustment_value'];
        if ($delta >= 0) { $added += $value; } else { $removed += $value; }
        csvWrite($out, [
            $r['adjustment_number'], $r['adjustment_date'], $r['med_name'], $r['batch_number'] ?: '—',
            $r['cat_name'] ?: '—', (int)$r['system_qty'], (int)$r['physical_qty'], $delta,
            number_format((float)$r['unit_cost'], 2, '.', ''), number_format($value, 2, '.', ''),
            $adjReasons[$r['reason']] ?? $r['reason'], $r['notes'] ?: '', $r['user_name'],
        ]);
    }
    csvWrite($out, ['', '', '', '', '', '', '', 'TOTAL ADDED', '', number_format($added, 2, '.', ''), '', '', '']);
    csvWrite($out, ['', '', '', '', '', '', '', 'TOTAL REMOVED', '', number_format($removed, 2, '.', ''), '', '', '']);
    fclose($out);
    exit;
}

if (($_GET['print'] ?? '') === '1' && $canView) {
    $prStmt = $pdo->prepare("SELECT sa.*, m.name AS med_name, b.batch_number, COALESCE(u.full_name,'—') AS user_name
        FROM stock_adjustments sa
        JOIN medicines m ON m.id = sa.medicine_id
        LEFT JOIN batches b ON b.id = sa.batch_id
        LEFT JOIN users u ON u.id = sa.user_id
        WHERE $whereSql
        ORDER BY sa.adjustment_date DESC, sa.id DESC");
    $prStmt->execute($params);
    $printRows = $prStmt->fetchAll();
    ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Stock Adjustment Report</title>
<style>body{font-family:Arial,Helvetica,sans-serif;margin:24px;font-size:12px;}table{width:100%;border-collapse:collapse;}
th,td{border:1px solid #999;padding:5px;text-align:left;}th{background:#eee;}.right{text-align:right;}</style>
</head><body onload="window.print()">
<h1 style="font-size:16px;"><?= htmlspecialchars($pharmacyName) ?> — Stock Adjustment Report</h1>
<p>Filter: <?= htmlspecialchars($filter) ?><?= $search !== '' ? ' · search: ' . htmlspecialchars($search) : '' ?> · <?= count($printRows) ?> record(s)</p>
<table><thead><tr><th>Adjustment #</th><th>Date</th><th>Product</th><th>Batch</th><th class="right">System</th><th class="right">Physical</th><th class="right">Adj Qty</th><th class="right">Unit Cost</th><th class="right">Value</th><th>Reason</th><th>User</th></tr></thead><tbody>
<?php foreach ($printRows as $r): ?>
<tr><td><?= htmlspecialchars($r['adjustment_number']) ?></td><td><?= htmlspecialchars($r['adjustment_date']) ?></td>
<td><?= htmlspecialchars($r['med_name']) ?></td><td><?= htmlspecialchars($r['batch_number'] ?: '—') ?></td>
<td class="right"><?= number_format((int)$r['system_qty']) ?></td><td class="right"><?= number_format((int)$r['physical_qty']) ?></td>
<td class="right"><?= number_format((int)$r['adjustment_qty']) ?></td><td class="right"><?= number_format((float)$r['unit_cost'], 2) ?></td>
<td class="right"><?= number_format((float)$r['adjustment_value'], 2) ?></td>
<td><?= htmlspecialchars($adjReasons[$r['reason']] ?? $r['reason']) ?></td><td><?= htmlspecialchars($r['user_name']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<p>All values use batch inventory cost.</p>
</body></html>
    <?php
    exit;
}
?>
<?php renderHead($pharmacyName . ' — Stock Adjustment'); ?>
<?php renderSidebar(); ?>
<div class="main-content">
<?php renderTopbar('Stock Adjustment', 'Correct physical stock without creating purchase or sale'); ?>
<div class="page-body">

<?php if (flashGet()): ?>
<div class="alert alert-<?= flashGet()['type'] === 'success' ? 'success' : 'danger' ?>">
    <i data-lucide="<?= flashGet()['type'] === 'success' ? 'check-circle' : 'x-circle' ?>"></i>
    <?= htmlspecialchars(flashGet()['message']) ?>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card blue">
        <div class="stat-icon blue"><i data-lucide="pencil"></i></div>
        <div><div class="stat-label">Total Adjustments</div><div class="stat-value"><?= number_format((int)$agg['total']) ?></div></div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon green"><i data-lucide="plus-circle"></i></div>
        <div><div class="stat-label">Stock Added</div><div class="stat-value"><?= currency($agg['total_added']) ?></div></div>
    </div>
    <div class="stat-card orange">
        <div class="stat-icon orange"><i data-lucide="minus-circle"></i></div>
        <div><div class="stat-label">Stock Removed</div><div class="stat-value"><?= currency($agg['total_removed']) ?></div></div>
    </div>
    <div class="stat-card red">
        <div class="stat-icon red"><i data-lucide="history"></i></div>
        <div><div class="stat-label">Total Adjustment Value</div><div class="stat-value"><?= currency($agg['total_added'] + $agg['total_removed']) ?></div></div>
    </div>
</div>

<div class="card mb-20">
    <div class="card-header">
        <span class="card-title"><i data-lucide="pencil"></i> New Adjustment</span>
        <a href="stock_adjustment.php?action=view" class="btn btn-ghost btn-sm"><i data-lucide="list"></i> View Adjustments</a>
    </div>
    <form method="POST">
        <input type="hidden" name="act" value="save_adjustment">
        <div class="card mb-20" style="padding:18px;">
            <div class="form-row">
                <div class="form-group">
                    <label>Product *</label>
                    <select name="medicine_id" id="adj-med" required>
                        <option value="">— Select product —</option>
                        <?php foreach ($allMeds as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $medId === (int)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Batch <span style="color:var(--text-300);font-weight:400;">(required — exact batch)</span></label>
                    <select name="batch_id" id="adj-batch" required>
                        <option value="">— Select product first —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date *</label>
                    <input type="date" name="adjustment_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id" id="adj-cat">
                        <option value="">— Select category —</option>
                        <?php foreach ($allCats as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row" style="margin-top:12px;">
                <div class="form-group">
                    <label>Physical Quantity Inspected *</label>
                    <input type="number" name="physical_qty" id="adj-physical" min="0" placeholder="System quantity will auto-populate" required>
                </div>
                <div class="form-group">
                    <label>Current System Quantity</label>
                    <input type="number" name="system_qty" id="adj-system" readonly placeholder="System qty">
                </div>
                <div class="form-group">
                    <label>Unit Cost (<?= $currency ?>)</label>
                    <input type="number" name="unit_cost" id="adj-cost" step="0.01" min="0" value="0" placeholder="Auto from batch">
                </div>
                <div class="form-group">
                    <label>Adjustment Quantity <span style="color:var(--text-300);font-weight:400;">(auto)</span></label>
                    <input type="number" name="adjustment_qty" id="adj-delta" readonly>
                </div>
            </div>
            <div class="form-row" style="margin-top:12px;">
                <div class="form-group" style="flex:1;min-width:240px;">
                    <label>Reason</label>
                    <select name="reason" id="adj-reason" required>
                        <option value="">— Select reason —</option>
                        <?php foreach ($adjReasons as $key => $label): ?>
                        <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:240px;">
                    <label>Adjustment Value <span style="color:var(--text-300);font-weight:400;">(auto)</span></label>
                    <input type="number" name="adjustment_value" id="adj-value" readonly>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>Notes <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
                    <textarea name="notes" id="adj-notes" rows="2" placeholder="Damaged / expired / found / data correction ..."></textarea>
                </div>
            </div>
            <div class="form-actions" style="margin-top:18px;">
                <button type="submit" class="btn btn-primary"><i data-lucide="pencil"></i> Save Adjustment</button>
            </div>
        </div>
    </form>
</div>

<div class="card mb-20">
    <div class="card-header">
        <span class="card-title">Adjustment History</span>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <?php if ($canAdd): ?><a href="stock_adjustment.php" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New Adjustment</a><?php endif; ?>
            <a href="stock_adjustment.php?export=csv&filter=<?= urlencode($filter) ?>&q=<?= urlencode($search) ?>" class="btn btn-ghost btn-sm"><i data-lucide="download"></i> Excel</a>
            <a href="stock_adjustment.php?print=1&filter=<?= urlencode($filter) ?>&q=<?= urlencode($search) ?>" target="_blank" class="btn btn-ghost btn-sm"><i data-lucide="printer"></i> Print</a>
            <select name="filter" id="adjFilter" style="max-width:180px;" onchange="location=this.value==='all'?'stock_adjustment.php':'stock_adjustment.php?filter='+this.value;">
                <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="added" <?= $filter === 'added' ? 'selected' : '' ?>>Added</option>
                <option value="removed" <?= $filter === 'removed' ? 'selected' : '' ?>>Removed</option>
            </select>
            <form method="GET" style="margin-left:auto;display:flex;gap:8px;">
                <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="filter" value="<?= $filter ?>">
                <input type="text" name="med" value="<?= $medId ?>" style="display:none;">
                <button type="submit" class="btn btn-ghost btn-sm"><i data-lucide="search"></i> Search</button>
            </form>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Number</th><th>Date</th><th>Product</th><th>Batch</th><th>System Qty</th><th>Physical Qty</th><th>Adj Qty</th><th>Unit Cost</th><th>Value</th><th>Reason</th><th>User</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($adjustments)): ?>
                <tr><td colspan="11" style="text-align:center;padding:24px;color:var(--text-300);">No adjustments found.</td></tr>
            <?php else: ?>
            <?php foreach ($adjustments as $a):
                $sign = $a['adjustment_qty'] >= 0 ? '＋' : '－';
                $label = $typeLabels[$a['reason']] ?? $a['reason'];
            ?>
            <tr>
                <td style="font-size:12px;color:var(--text-200);"><?= htmlspecialchars($a['adjustment_number']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($a['adjustment_date']) ?></td>
                <td style="font-size:12px;font-weight:500;"><?= htmlspecialchars($a['med_name']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($a['batch_number'] ?: '—') ?></td>
                <td style="font-size:12px;"><?= number_format((int)$a['system_qty']) ?></td>
                <td style="font-size:12px;"><?= number_format((int)$a['physical_qty']) ?></td>
                <td style="font-size:12px;font-weight:600;"><?= $sign ?><?= number_format((int)$a['adjustment_qty']) ?></td>
                <td style="font-size:12px;"><?= currency($a['unit_cost']) ?></td>
                <td style="font-size:12px;font-weight:600;"><?= currency($a['adjustment_value']) ?></td>
                <td style="font-size:12px;color:var(--text-200);"><?= htmlspecialchars($label) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($a['user_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;margin-top:12px;">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="stock_adjustment.php?page=<?= $page-1 ?>&filter=<?= $filter ?>&q=<?= urlencode($search) ?>&med=<?= $medId ?>">Previous</a><?php endif; ?>
        <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a class="btn btn-ghost btn-sm" href="stock_adjustment.php?page=<?= $page+1 ?>&filter=<?= $filter ?>&q=<?= urlencode($search) ?>&med=<?= $medId ?>">Next</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

</div></div>
<?php renderFooter(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var med = document.getElementById('adj-med');
    var batch = document.getElementById('adj-batch');
    var sys = document.getElementById('adj-system');
    var cost = document.getElementById('adj-cost');
    var delta = document.getElementById('adj-delta');
    var value = document.getElementById('adj-value');

    function update() {
        var system = parseInt(sys.value, 10) || 0;
        var physicalRaw = document.getElementById('adj-physical').value;
        var costVal = parseFloat(cost.value) || 0;
        if (physicalRaw === '') { delta.value = ''; value.value = ''; return; }
        var physical = parseInt(physicalRaw, 10) || 0;
        var adj = physical - system;
        delta.value = adj;
        // Adjustment value always uses inventory cost on the ABSOLUTE quantity.
        value.value = (Math.abs(adj) * costVal).toFixed(2);
    }

    document.getElementById('adj-physical').addEventListener('input', update);
    cost.addEventListener('input', update);
    batch.addEventListener('change', function () {
        var opt = batch.options[batch.selectedIndex];
        if (!opt || !opt.value) { sys.value = ''; return; }
        sys.value = opt.getAttribute('data-qty') || 0;
        var batchCost = parseFloat(opt.getAttribute('data-cost') || '0');
        if (batchCost > 0) { cost.value = batchCost.toFixed(2); }
        update();
    });

    med.addEventListener('change', function () {
        var id = parseInt(med.value, 10);
        if (!id) return;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'stock_adjustment_ajax.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            try { var data = JSON.parse(xhr.responseText); }
            catch (e) { return; }
            if (data && data.ok) {
                batch.innerHTML = '<option value="">— Select batch —</option>' + (data.options || '');
                sys.value = '';
                delta.value = '';
                value.value = '';
                if (batch.options.length === 2) {
                    batch.selectedIndex = 1;
                    batch.dispatchEvent(new Event('change'));
                }
            }
        };
        xhr.send('medicine_id=' + id + '&act=load_batch');
    });
});
</script>
