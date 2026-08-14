<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';

$pdo = getDB();
$msg = '';

// Filter
$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$medId  = (int)($_GET['med'] ?? 0);

// Batch edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'update_batch') {
        $bid            = (int)$_POST['batch_id'];
        $medicineId     = (int)$_POST['medicine_id'];
        $batchNumber    = trim($_POST['batch_number'] ?? '');
        $quantity       = (int)$_POST['quantity'];
        $purchasePrice  = (float)$_POST['purchase_price'];
        $sellingPrice   = (float)$_POST['selling_price'];
        $expiryDate     = trim($_POST['expiry_date'] ?? '');
        $manufactureDate = trim($_POST['manufacture_date'] ?? '') ?: null;

        $typeStmt = $pdo->prepare("SELECT COALESCE(product_type, 'medicine') FROM medicines WHERE id=?");
        $typeStmt->execute([$medicineId]);
        $productType = $typeStmt->fetchColumn() ?: 'medicine';
        $expiryRequired = productRequiresExpiry($productType);
        $expiryDate = normalizeExpiryDate($expiryDate, $expiryRequired);

        if (!$medicineId || !$batchNumber || ($expiryRequired && !$expiryDate)) {
            $error = $expiryRequired
                ? 'Medicine, batch number, and expiry date are required.'
                : 'Product and batch number are required.';
        } elseif ($quantity < 0 || $purchasePrice < 0 || $sellingPrice < 0) {
            $error = 'Quantity and prices cannot be negative.';
        } else {
            $soldStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM sale_items WHERE batch_id=?");
            $soldStmt->execute([$bid]);
            $soldQty = (int)$soldStmt->fetchColumn();

            if ($quantity < $soldQty) {
                $error = "Quantity cannot be less than already sold ($soldQty units).";
            } else {
                $dup = $pdo->prepare("SELECT id FROM batches WHERE medicine_id=? AND batch_number=? AND id!=?");
                $dup->execute([$medicineId, $batchNumber, $bid]);
                if ($dup->fetch()) {
                    $error = 'Another batch with this number already exists for this medicine.';
                } else {
                    $pdo->prepare("
                        UPDATE batches
                        SET medicine_id=?, batch_number=?, quantity=?, purchase_price=?,
                            selling_price=?, expiry_date=?, manufacture_date=?
                        WHERE id=?
                    ")->execute([
                        $medicineId, $batchNumber, $quantity, $purchasePrice,
                        $sellingPrice, $expiryDate, $manufactureDate, $bid
                    ]);
                    $msg = 'Batch updated successfully.';
                }
            }
        }
    }
    if ($act === 'delete_batch') {
        try {
            $pdo->prepare("DELETE FROM batches WHERE id=?")->execute([(int)$_POST['batch_id']]);
            $msg = 'Batch removed.';
        } catch (PDOException $e) {
            $error = 'Cannot delete batch: It is linked to existing sales records.';
        }
    }
}

$sql = "
    SELECT b.*, m.name as med_name, m.reorder_level, m.unit,
           COALESCE(m.product_type, 'medicine') AS product_type, c.name as cat_name
    FROM batches b
    JOIN medicines m ON m.id = b.medicine_id
    LEFT JOIN categories c ON c.id = m.category_id
";

$params = [];
$where  = [];

if ($search) {
    $where[] = "(m.name LIKE ? OR b.batch_number LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($medId)   { $where[] = "b.medicine_id = ?"; $params[] = $medId; }

if ($filter === 'low')      { $where[] = "b.quantity > 0 AND b.quantity <= m.reorder_level"; }
if ($filter === 'expired')  { $where[] = "b.expiry_date < date('now') AND b.expiry_date < '9000-01-01'"; }
if ($filter === 'expiring') { $where[] = "b.expiry_date BETWEEN date('now') AND date('now','+30 days') AND b.quantity > 0 AND b.expiry_date < '9000-01-01'"; }
if ($filter === 'out')      { $where[] = "b.quantity = 0"; }

if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY b.expiry_date ASC, m.name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll();

$medicines = $pdo->query("SELECT id, name, unit, COALESCE(product_type,'medicine') AS product_type FROM medicines ORDER BY name")->fetchAll();
$currency  = getSetting('currency', 'ETB');

// Summary counts
$counts = [
    'all'      => $pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn(),
    'low'      => $pdo->query("SELECT COUNT(*) FROM batches b JOIN medicines m ON m.id=b.medicine_id WHERE b.quantity > 0 AND b.quantity <= m.reorder_level")->fetchColumn(),
    'expired'  => $pdo->query("SELECT COUNT(*) FROM batches WHERE expiry_date < date('now') AND expiry_date < '9000-01-01'")->fetchColumn(),
    'expiring' => $pdo->query("SELECT COUNT(*) FROM batches WHERE expiry_date BETWEEN date('now') AND date('now','+30 days') AND quantity > 0 AND expiry_date < '9000-01-01'")->fetchColumn(),
    'out'      => $pdo->query("SELECT COUNT(*) FROM batches WHERE quantity = 0")->fetchColumn(),
];

renderHead('Inventory');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Inventory', 'Batch-level stock management'); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if (isset($error) && $error): ?><div class="alert alert-danger"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Filter tabs -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;">
  <?php
  $tabs = [
    ['key'=>'all',      'label'=>'All Batches',    'cnt'=>$counts['all'],      'class'=>'badge-blue'],
    ['key'=>'low',      'label'=>'Low Stock',       'cnt'=>$counts['low'],      'class'=>'badge-orange'],
    ['key'=>'expiring', 'label'=>'Expiring Soon',   'cnt'=>$counts['expiring'], 'class'=>'badge-orange'],
    ['key'=>'expired',  'label'=>'Expired',         'cnt'=>$counts['expired'],  'class'=>'badge-red'],
    ['key'=>'out',      'label'=>'Out of Stock',    'cnt'=>$counts['out'],      'class'=>'badge-gray'],
  ];
  foreach ($tabs as $tab):
    $active = $filter === $tab['key'];
  ?>
  <a href="inventory.php?filter=<?= $tab['key'] ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
     class="btn <?= $active ? 'btn-primary' : 'btn-ghost' ?>" style="gap:8px;">
    <?= $tab['label'] ?>
    <span class="badge <?= $tab['class'] ?>"><?= $tab['cnt'] ?></span>
  </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Batches (<?= count($batches) ?>)</span>
    <form method="GET" style="display:flex;gap:8px;">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
      <div class="search-bar"><i data-lucide="search"></i><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search medicine or batch..."></div>
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    </form>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Medicine</th><th>Category</th><th>Batch #</th>
          <th>Qty</th><th>Buy Price</th><th>Sell Price</th>
          <th>Expiry</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($batches)): ?>
        <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-300);">No batches match your filter</td></tr>
      <?php else: ?>
      <?php foreach ($batches as $b):
        $noExpiry = isNoExpiryDate($b['expiry_date'] ?? '');
        if ($noExpiry) {
            $statusClass = 'badge-green';
            $statusLabel = 'No expiry';
        } else {
            $days = (strtotime($b['expiry_date']) - time()) / 86400;
            if ($days < 0)        { $statusClass = 'badge-red';    $statusLabel = 'Expired'; }
            elseif ($days <= 7)   { $statusClass = 'badge-red';    $statusLabel = 'Critical'; }
            elseif ($days <= 30)  { $statusClass = 'badge-orange'; $statusLabel = 'Expiring'; }
            else                  { $statusClass = 'badge-green';  $statusLabel = 'Good'; }
        }
        if ($b['quantity'] == 0) { $statusClass = 'badge-gray'; $statusLabel = 'Out'; }

        $qtyClass = $b['quantity'] == 0 ? 'badge-gray' : ($b['quantity'] <= $b['reorder_level'] ? 'badge-orange' : 'badge-green');
      ?>
      <tr>
        <td style="font-weight:600;color:var(--text-100);"><?= htmlspecialchars($b['med_name']) ?></td>
        <td><span class="badge badge-gray" style="font-size:11px;"><?= htmlspecialchars($b['cat_name'] ?? '—') ?></span></td>
        <td><code style="background:var(--bg-600);padding:2px 7px;border-radius:4px;font-size:12px;"><?= htmlspecialchars($b['batch_number']) ?></code></td>
        <td><span class="badge <?= $qtyClass ?>"><?= number_format($b['quantity']) ?> <?= htmlspecialchars($b['unit']) ?></span></td>
        <td style="color:var(--text-300);"><?= currency($b['purchase_price']) ?></td>
        <td style="color:var(--accent2);font-weight:700;"><?= currency($b['selling_price']) ?></td>
        <td style="font-size:12px;"><?= formatExpiryDate($b['expiry_date']) ?></td>
        <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
        <td>
          <div class="row-actions">
            <button type="button" class="btn btn-ghost btn-sm"
              onclick='openEditModal(<?= htmlspecialchars(json_encode([
                'id'              => (int)$b['id'],
                'medicine_id'     => (int)$b['medicine_id'],
                'med_name'        => $b['med_name'],
                'batch_number'    => $b['batch_number'],
                'quantity'        => (int)$b['quantity'],
                'purchase_price'  => (float)$b['purchase_price'],
                'selling_price'   => (float)$b['selling_price'],
                'expiry_date'     => isNoExpiryDate($b['expiry_date'] ?? '') ? '' : $b['expiry_date'],
                'manufacture_date'=> $b['manufacture_date'] ?? '',
              ]), ENT_QUOTES) ?>)'>Edit</button>
            <form method="POST" onsubmit="return confirmDelete(this)">
              <input type="hidden" name="act" value="delete_batch">
              <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Del</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Edit Batch Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <h2>Edit Batch</h2>
      <button class="modal-close" onclick="closeModal('editModal')"><i data-lucide="x"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="act" value="update_batch">
        <input type="hidden" name="batch_id" id="editBatchId">
        <div class="form-group">
          <label>Medicine</label>
          <select name="medicine_id" id="editMedicineId" required onchange="syncEditExpiryRequired()">
            <?php foreach ($medicines as $m): ?>
            <option value="<?= $m['id'] ?>" data-requires-expiry="<?= productRequiresExpiry($m['product_type'] ?? 'medicine') ? '1' : '0' ?>"><?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['unit']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Batch Number</label>
          <input type="text" name="batch_number" id="editBatchNumber" required placeholder="e.g. BN-2024-001">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Quantity</label>
            <input type="number" name="quantity" id="editQuantity" min="0" step="1" required>
          </div>
          <div class="form-group">
            <label>Expiry Date <span id="editExpiryHint" style="color:var(--text-300);font-weight:400;"></span></label>
            <input type="date" name="expiry_date" id="editExpiryDate">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Buy Price (<?= htmlspecialchars($currency) ?>)</label>
            <input type="number" name="purchase_price" id="editPurchasePrice" step="0.01" min="0" required>
          </div>
          <div class="form-group">
            <label>Sell Price (<?= htmlspecialchars($currency) ?>)</label>
            <input type="number" name="selling_price" id="editSellingPrice" step="0.01" min="0" required>
          </div>
        </div>
        <div class="form-group">
          <label>Manufacture Date <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
          <input type="date" name="manufacture_date" id="editManufactureDate">
        </div>
        <div class="form-actions" style="padding-top:12px;margin-top:12px;">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

</div></div>

<script>
function syncEditExpiryRequired() {
  const sel = document.getElementById('editMedicineId');
  const opt = sel.options[sel.selectedIndex];
  const requires = !!(opt && opt.dataset.requiresExpiry === '1');
  const input = document.getElementById('editExpiryDate');
  const hint = document.getElementById('editExpiryHint');
  input.required = requires;
  if (hint) hint.textContent = requires ? '' : '(optional for cosmetics & equipment)';
}

function openEditModal(batch) {
  document.getElementById('editBatchId').value         = batch.id;
  document.getElementById('editMedicineId').value      = batch.medicine_id;
  document.getElementById('editBatchNumber').value     = batch.batch_number;
  document.getElementById('editQuantity').value        = batch.quantity;
  document.getElementById('editPurchasePrice').value   = batch.purchase_price;
  document.getElementById('editSellingPrice').value    = batch.selling_price;
  document.getElementById('editExpiryDate').value      = batch.expiry_date || '';
  document.getElementById('editManufactureDate').value = batch.manufacture_date || '';
  syncEditExpiryRequired();
  openModal('editModal');
}
</script>

<?php renderFooter(); ?>
