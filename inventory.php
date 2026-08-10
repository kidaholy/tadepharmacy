<?php
require_once __DIR__ . '/layout.php';

$pdo = getDB();
$msg = '';

// Filter
$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$medId  = (int)($_GET['med'] ?? 0);

// Batch quick-edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'update_price') {
        $bid   = (int)$_POST['batch_id'];
        $price = (float)$_POST['selling_price'];
        $pdo->prepare("UPDATE batches SET selling_price=? WHERE id=?")->execute([$price,$bid]);
        $msg = 'Selling price updated.';
    }
    if ($act === 'delete_batch') {
        $pdo->prepare("DELETE FROM batches WHERE id=?")->execute([(int)$_POST['batch_id']]);
        $msg = 'Batch removed.';
    }
}

$sql = "
    SELECT b.*, m.name as med_name, m.reorder_level, m.unit, c.name as cat_name, s.name as supplier_name
    FROM batches b
    JOIN medicines m ON m.id = b.medicine_id
    LEFT JOIN categories c ON c.id = m.category_id
    LEFT JOIN suppliers s ON s.id = b.supplier_id
";

$params = [];
$where  = [];

if ($search) {
    $where[] = "(m.name LIKE ? OR b.batch_number LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($medId)   { $where[] = "b.medicine_id = ?"; $params[] = $medId; }

if ($filter === 'low')      { $where[] = "b.quantity > 0 AND b.quantity <= m.reorder_level"; }
if ($filter === 'expired')  { $where[] = "b.expiry_date < date('now')"; }
if ($filter === 'expiring') { $where[] = "b.expiry_date BETWEEN date('now') AND date('now','+30 days') AND b.quantity > 0"; }
if ($filter === 'out')      { $where[] = "b.quantity = 0"; }

if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY b.expiry_date ASC, m.name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll();

// Summary counts
$counts = [
    'all'      => $pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn(),
    'low'      => $pdo->query("SELECT COUNT(*) FROM batches b JOIN medicines m ON m.id=b.medicine_id WHERE b.quantity > 0 AND b.quantity <= m.reorder_level")->fetchColumn(),
    'expired'  => $pdo->query("SELECT COUNT(*) FROM batches WHERE expiry_date < date('now')")->fetchColumn(),
    'expiring' => $pdo->query("SELECT COUNT(*) FROM batches WHERE expiry_date BETWEEN date('now') AND date('now','+30 days') AND quantity > 0")->fetchColumn(),
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
          <th>Expiry</th><th>Status</th><th>Supplier</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($batches)): ?>
        <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-300);">No batches match your filter</td></tr>
      <?php else: ?>
      <?php foreach ($batches as $b):
        $days = (strtotime($b['expiry_date']) - time()) / 86400;
        if ($days < 0)        { $statusClass = 'badge-red';    $statusLabel = 'Expired'; }
        elseif ($days <= 7)   { $statusClass = 'badge-red';    $statusLabel = 'Critical'; }
        elseif ($days <= 30)  { $statusClass = 'badge-orange'; $statusLabel = 'Expiring'; }
        else                  { $statusClass = 'badge-green';  $statusLabel = 'Good'; }
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
        <td style="font-size:12px;"><?= date('M d, Y', strtotime($b['expiry_date'])) ?></td>
        <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
        <td style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></td>
        <td>
          <div style="display:flex;gap:6px;">
            <button class="btn btn-ghost btn-sm" onclick="openEditModal(<?= $b['id'] ?>, <?= $b['selling_price'] ?>)"><i data-lucide="pencil"></i></button>
            <form method="POST" onsubmit="return confirmDelete(this)">
              <input type="hidden" name="act" value="delete_batch">
              <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm"><i data-lucide="trash-2"></i></button>
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

<!-- Edit Price Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header">
      <h2>Update Selling Price</h2>
      <button class="modal-close" onclick="closeModal('editModal')"><i data-lucide="x"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="act" value="update_price">
        <input type="hidden" name="batch_id" id="editBatchId">
        <div class="form-group">
          <label>New Selling Price (<?= getSetting('currency','ETB') ?>)</label>
          <input type="number" name="selling_price" id="editPrice" step="0.01" min="0" required>
        </div>
        <div class="form-actions" style="padding-top:12px;margin-top:12px;">
          <button type="submit" class="btn btn-primary">Save</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

</div></div>

<script>
function openEditModal(batchId, price) {
  document.getElementById('editBatchId').value = batchId;
  document.getElementById('editPrice').value   = price;
  openModal('editModal');
}
</script>

<?php renderFooter(); ?>
