<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory_lib.php';
require_once __DIR__ . '/sales_lib.php';
require_once __DIR__ . '/exchange_functions.php';

$pdo = getDB();
$userId = (int)(currentUser()['id'] ?? 0);
$currency = getSetting('currency', 'ETB');
$pharmacyName = getSetting('pharmacy_name', 'TADE PHARMACY');

$canAdd = can('inventory.adjust') || can('inventory.manage') || can('inventory.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'save_movement') {
        $medicineId = (int)($_POST['medicine_id'] ?? 0);
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $direction = trim($_POST['direction'] ?? '');
        $qty = (int)($_POST['quantity'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (!$medicineId || !in_array($direction, ['in','out'], true) || $qty <= 0) {
            flashSet('error', 'Select a product and enter a positive quantity.');
            header('Location: stock_adjustment_manual.php');
            exit;
        }
        if ($batchId <= 0) {
            flashSet('error', 'Select the exact batch: a movement without a batch cannot change stock.');
            header('Location: stock_adjustment_manual.php');
            exit;
        }
        $b = $pdo->prepare('SELECT quantity, expiry_date, batch_number, purchase_price FROM batches WHERE id = ? AND medicine_id = ?');
        $b->execute([$batchId, $medicineId]);
        $batch = $b->fetch();
        if (!$batch) {
            flashSet('error', 'Batch not found for this product.');
            header('Location: stock_adjustment_manual.php');
            exit;
        }
        if ($direction === 'out' && $qty > (int)$batch['quantity']) {
            flashSet('error', 'Not enough stock in this batch (available ' . (int)$batch['quantity'] . ').');
            header('Location: stock_adjustment_manual.php');
            exit;
        }
        if ($direction === 'out' && $batch['expiry_date'] < date('Y-m-d')) {
            flashSet('error', 'Cannot issue an expired batch. Use a stock adjustment to write it off.');
            header('Location: stock_adjustment_manual.php');
            exit;
        }
        $delta = $direction === 'in' ? $qty : -$qty;

        $pdo->beginTransaction();
        try {
            // Applies the quantity change AND writes exactly one movement row.
            applyBatchDelta(
                $pdo,
                $medicineId,
                $batchId,
                $delta,
                'manual',
                trim($reason . ($notes !== '' ? ': ' . $notes : '')),
                'stock_movement',
                null,
                $batch['batch_number'],
                $userId,
                'batch ' . $batch['batch_number'],
                (float)$batch['purchase_price']
            );
            $pdo->commit();
            flashSet('success', 'Movement recorded: batch ' . $batch['batch_number'] . ' ' . ($delta > 0 ? '+' : '') . $delta . '.');
            header('Location: stock_adjustment_manual.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flashSet('error', $e->getMessage());
            header('Location: stock_adjustment_manual.php');
            exit;
        }
    }
    flashSet('error', 'Unknown action.');
    header('Location: stock_adjustment_manual.php');
    exit;
}

$medicines = $pdo->query("SELECT id, name, unit FROM medicines ORDER BY name")->fetchAll();
$allBatches = [];
$batchStmt = $pdo->prepare('SELECT id, batch_number, quantity, expiry_date FROM batches WHERE medicine_id = ? AND COALESCE(status, \'active\') = \'active\' ORDER BY (quantity > 0) DESC, expiry_date ASC');
foreach ($medicines as $m) {
    $batchStmt->execute([(int)$m['id']]);
    $allBatches[(int)$m['id']] = $batchStmt->fetchAll();
}
?>
<?php renderHead($pharmacyName . ' — Stock Movement History'); ?>
<?php renderSidebar(); ?>
<div class="main-content">
<?php renderTopbar('Manual Stock Movement', 'Record a movement that is not a purchase, sale, transfer or exchange'); ?>
<div class="page-body">

<div class="card mb-20">
    <div class="card-header"><span class="card-title"><i data-lucide="plus-circle"></i> New Manual Movement</span></div>
    <form method="POST">
        <input type="hidden" name="act" value="save_movement">
        <div class="form-row">
            <div class="form-group">
                <label>Product *</label>
                <select name="medicine_id" id="mv-med" required>
                    <option value="">— Select product —</option>
                    <?php foreach ($medicines as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Batch *</label>
                <select name="batch_id" id="mv-batch" required>
                    <option value="">— Select product first —</option>
                    <?php foreach ($allBatches as $mid => $batches): ?>
                    <?php if (!$batches) continue; ?>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" data-med="<?= $mid ?>"><?= htmlspecialchars($b['batch_number'] . ' — ' . $b['quantity'] . ' in stock') ?></option>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Direction *</label>
                <div style="display:flex;gap:12px;">
                    <label style="flex:1;text-align:center;padding:10px;border:1px solid var(--border-strong);border-radius:8px;cursor:pointer;">
                        <input type="radio" name="direction" value="in" style="margin:0;"> <span style="font-weight:600;">In</span>
                    </label>
                    <label style="flex:1;text-align:center;padding:10px;border:1px solid var(--border-strong);border-radius:8px;cursor:pointer;">
                        <input type="radio" name="direction" value="out" style="margin:0;"> <span style="font-weight:600;">Out</span>
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label>Quantity *</label>
                <input type="number" name="quantity" min="1" placeholder="Qty" required>
            </div>
            <div class="form-group">
                <label>Reason</label>
                <select name="reason">
                    <option value="Manual movement">Manual movement</option>
                    <option value="Physical count correction">Physical count correction</option>
                    <option value="Found stock">Found stock</option>
                    <option value="Data correction">Data correction</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label>Notes <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
                <textarea name="notes" rows="2"></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i data-lucide="plus-circle"></i> Save Movement</button>
        </div>
    </form>
</div>

<div class="card mb-20">
    <div class="card-header">
        <span class="card-title">Movements Log</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>Direction</th><th>Qty</th><th>Reason</th><th>Reference</th><th>User</th></tr>
            </thead>
            <tbody>
            <?php
            $where = ['1=1'];
            $params = [];
            $filter = $_GET['filter'] ?? 'all';
            if ($filter !== 'all') { $where[] = 'sm.movement_type = ?'; $params[] = $filter; }
            $rows = $pdo->prepare("SELECT sm.*, u.full_name AS user_name, b.batch_number FROM stock_movements sm LEFT JOIN users u ON u.id=sm.user_id LEFT JOIN batches b ON b.id=sm.batch_id WHERE ".implode(' AND ', $where)." ORDER BY sm.created_at DESC LIMIT 100");
            $rows->execute($params);
            foreach ($rows->fetchAll() as $mv): ?>
            <tr>
                <td style="font-size:12px;"><?= htmlspecialchars(substr((string)($mv['created_at'] ?? ''), 0, 19)) ?></td>
                <td style="font-size:12px;"><?= $mv['movement_type'] === 'receipt' ? 'In' : ($mv['movement_type'] === 'issue' ? 'Out' : ucfirst(str_replace('_',' ',$mv['movement_type']))) ?></td>
                <td style="font-size:12px;font-weight:600;"><?= $mv['movement_type'] === 'receipt' ? '+' : '－' ?><?= number_format((int)$mv['quantity_in'] > 0 ? $mv['quantity_in'] : $mv['quantity_out']) ?></td>
                <td style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($mv['reason'] ?? '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($mv['reference'] ?? '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($mv['user_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div>
<?php renderFooter(); ?>
<script>
document.getElementById('mv-med').addEventListener('change', function () {
    var sel = document.getElementById('mv-batch');
    var med = parseInt(this.value, 10);
    var opts = '';
    <?php foreach ($allBatches as $mid => $batches): ?>
    <?php if (!$batches) continue; ?>
    <?php foreach ($batches as $b): ?>
    opts += '<option value="<?= $b['id'] ?>" data-med="<?= $mid ?>"><?= htmlspecialchars($b['batch_number'] . ' — ' . $b['quantity'] . ' in stock') ?></option>';
    <?php endforeach; ?>
    <?php endforeach; ?>
    sel.innerHTML = '<option value="">All batches</option>' + opts;
    if (med) {
        var matched = Array.from(sel.options).filter(function (o) { return o.value && o.dataset.med == med; });
        sel.innerHTML = '<option value="">All batches</option>' + matched.map(function (o) { return o.outerHTML; }).join('');
    }
});
</script>
