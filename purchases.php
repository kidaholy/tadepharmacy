<?php
require_once __DIR__ . '/layout.php';

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$msg    = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'purchase') {
        $supplier_id = null;
        $reference   = trim($_POST['reference'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $meds        = $_POST['medicine_id']     ?? [];
        $batches     = $_POST['batch_number']    ?? [];
        $qtys        = $_POST['quantity']        ?? [];
        $pprices     = $_POST['purchase_price']  ?? [];
        $sprices     = $_POST['selling_price']   ?? [];
        $expiries    = $_POST['expiry_date']     ?? [];

        $validItems = [];
        for ($i = 0; $i < count($meds); $i++) {
            if (!$meds[$i] || !$qtys[$i] || !$expiries[$i]) continue;
            $validItems[] = [
                'medicine_id'    => (int)$meds[$i],
                'batch_number'   => $batches[$i] ?: 'BATCH-' . date('Ymd') . '-' . ($i+1),
                'quantity'       => (int)$qtys[$i],
                'purchase_price' => (float)$pprices[$i],
                'selling_price'  => (float)$sprices[$i],
                'expiry_date'    => $expiries[$i],
            ];
        }

        if (empty($validItems)) { $error = 'Add at least one item with medicine, quantity, and expiry date.'; }
        else {
            $total = array_sum(array_map(fn($it) => $it['purchase_price'] * $it['quantity'], $validItems));
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO purchases (reference,supplier_id,total_amount,notes) VALUES (?,?,?,?)")
                    ->execute([$reference,$supplier_id,$total,$notes]);
                $purchaseId = $pdo->lastInsertId();

                foreach ($validItems as $it) {
                    $pdo->prepare("INSERT INTO purchase_items (purchase_id,medicine_id,batch_number,quantity,purchase_price,selling_price,expiry_date) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$purchaseId,$it['medicine_id'],$it['batch_number'],$it['quantity'],$it['purchase_price'],$it['selling_price'],$it['expiry_date']]);

                    // Add to batches
                    $pdo->prepare("INSERT INTO batches (medicine_id,batch_number,quantity,purchase_price,selling_price,expiry_date,supplier_id) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$it['medicine_id'],$it['batch_number'],$it['quantity'],$it['purchase_price'],$it['selling_price'],$it['expiry_date'],$supplier_id]);
                }
                $pdo->commit();
                $msg = 'Purchase recorded and stock updated successfully.';
                $action = 'list';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed: ' . $e->getMessage();
            }
        }
    }

    if ($act === 'delete') {
        // Reverse stock
        $pitems = $pdo->prepare("SELECT * FROM purchase_items WHERE purchase_id=?");
        $pitems->execute([(int)$_POST['id']]);
        foreach ($pitems->fetchAll() as $pi) {
            // Find matching batch and reduce quantity
            $pdo->prepare("UPDATE batches SET quantity = MAX(0, quantity - ?) WHERE medicine_id=? AND batch_number=?")
                ->execute([$pi['quantity'],$pi['medicine_id'],$pi['batch_number']]);
        }
        $pdo->prepare("DELETE FROM purchases WHERE id=?")->execute([(int)$_POST['id']]);
        $msg = 'Purchase deleted and stock reversed.';
    }
}

$medicines  = $pdo->query("SELECT id,name FROM medicines ORDER BY name")->fetchAll();
$purchases  = $pdo->query("SELECT p.*, COUNT(pi.id) as items FROM purchases p LEFT JOIN purchase_items pi ON pi.purchase_id=p.id GROUP BY p.id ORDER BY p.created_at DESC")->fetchAll();

renderHead('Purchases');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Purchases', 'Stock receiving & inventory additions'); ?>
<div class="page-body">

<?php if ($msg):  ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($action === 'add'): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title">Record New Purchase</span>
    <a href="purchases.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Back</a>
  </div>
  <form method="POST">
    <input type="hidden" name="act" value="purchase">
    <div class="form-row">
      <div class="form-group">
        <label>Reference / Invoice #</label>
        <input type="text" name="reference" placeholder="Invoice or reference number">
      </div>
    </div>
    <div class="form-group">
      <label>Notes</label>
      <textarea name="notes" placeholder="Optional notes about this purchase..."></textarea>
    </div>

    <div style="margin:20px 0 12px;padding-top:16px;border-top:1px solid var(--border);">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <span style="font-weight:700;color:var(--text-100);">Purchase Items</span>
        <button type="button" onclick="addRow()" class="btn btn-ghost btn-sm"><i data-lucide="plus"></i> Add Item</button>
      </div>

      <div class="table-wrap">
        <table id="itemsTable">
          <thead>
            <tr>
              <th>Medicine</th><th>Batch #</th><th>Qty</th>
              <th>Buy Price</th><th>Sell Price</th><th>Expiry</th><th></th>
            </tr>
          </thead>
          <tbody id="itemsBody">
            <tr class="item-row">
              <td><select name="medicine_id[]" style="min-width:160px;padding:7px 10px;font-size:13px;background:var(--bg-600);border:1px solid var(--border-strong);color:var(--text-100);border-radius:6px;">
                <option value="">Select...</option>
                <?php foreach ($medicines as $m): ?>
                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                <?php endforeach; ?>
              </select></td>
              <td><input type="text" name="batch_number[]" placeholder="Auto if blank" style="<?= inputStyle() ?>"></td>
              <td><input type="number" name="quantity[]" min="1" value="1" style="<?= inputStyle(80) ?>"></td>
              <td><input type="number" name="purchase_price[]" min="0" step="0.01" value="0" style="<?= inputStyle(90) ?>"></td>
              <td><input type="number" name="selling_price[]" min="0" step="0.01" value="0" style="<?= inputStyle(90) ?>"></td>
              <td><input type="date" name="expiry_date[]" style="<?= inputStyle() ?>"></td>
              <td><button type="button" onclick="removeRow(this)" class="btn btn-danger btn-sm"><i data-lucide="trash-2"></i></button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-success"><i data-lucide="package-open"></i> Save Purchase & Update Stock</button>
      <a href="purchases.php" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>

<?php
function inputStyle(int $w = 0): string {
    return 'width:' . ($w ? "{$w}px" : '100%') . ';padding:7px 10px;font-size:13px;background:var(--bg-600);border:1px solid var(--border-strong);color:var(--text-100);border-radius:6px;';
}
?>

<script>
const MEDICINES_HTML = `<?php
$opts = '<option value="">Select...</option>';
foreach ($medicines as $m) $opts .= '<option value="' . $m['id'] . '">' . htmlspecialchars($m['name'], ENT_QUOTES) . '</option>';
echo addslashes($opts);
?>`;

function addRow() {
  const tbody = document.getElementById('itemsBody');
  const row = tbody.querySelector('.item-row').cloneNode(true);
  row.querySelectorAll('input').forEach(i => i.value = i.type === 'number' ? (i.name.includes('quantity') ? 1 : 0) : '');
  row.querySelector('select').innerHTML = MEDICINES_HTML;
  tbody.appendChild(row);
  lucide.createIcons();
}

function removeRow(btn) {
  const rows = document.querySelectorAll('.item-row');
  if (rows.length > 1) btn.closest('tr').remove();
}
</script>

<?php else: ?>
<div class="card">
  <div class="card-header">
    <span class="card-title">All Purchases (<?= count($purchases) ?>)</span>
    <a href="purchases.php?action=add" class="btn btn-primary"><i data-lucide="plus"></i> Record Purchase</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Date</th><th>Reference</th><th>Items</th><th>Total Cost</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (empty($purchases)): ?>
        <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-300);">No purchases yet</td></tr>
      <?php else: ?>
      <?php foreach ($purchases as $i => $p): ?>
      <tr>
        <td style="color:var(--text-300);"><?= $i+1 ?></td>
        <td style="font-size:12px;"><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
        <td style="font-weight:600;"><?= htmlspecialchars($p['reference'] ?: '—') ?></td>
        <td><span class="badge badge-blue"><?= $p['items'] ?></span></td>
        <td style="font-weight:700;color:var(--warning);"><?= currency($p['total_amount']) ?></td>
        <td>
          <form method="POST" onsubmit="return confirmDelete(this)" style="display:inline;">
            <input type="hidden" name="act" value="delete">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm"><i data-lucide="trash-2"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
