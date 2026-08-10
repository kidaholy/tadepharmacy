<?php
require_once __DIR__ . '/layout.php';

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$msg    = '';
$error  = '';

// ── Handle POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $generic     = trim($_POST['generic_name'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
        $unit        = trim($_POST['unit'] ?? 'pcs');
        $description = trim($_POST['description'] ?? '');
        $reorder     = (int)($_POST['reorder_level'] ?? 10);

        if (!$name) { $error = 'Medicine name is required.'; }
        else {
            if ($id) {
                $pdo->prepare("UPDATE medicines SET name=?,generic_name=?,category_id=?,unit=?,description=?,reorder_level=? WHERE id=?")
                    ->execute([$name,$generic,$category_id,$unit,$description,$reorder,$id]);
                $msg = 'Medicine updated successfully.';
            } else {
                $pdo->prepare("INSERT INTO medicines (name,generic_name,category_id,unit,description,reorder_level) VALUES (?,?,?,?,?,?)")
                    ->execute([$name,$generic,$category_id,$unit,$description,$reorder]);
                $msg = 'Medicine added successfully.';
            }
            $action = 'list';
        }
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM medicines WHERE id=?")->execute([$id]);
        $msg = 'Medicine deleted.';
        $action = 'list';
    }
}

// ── Data ────────────────────────────────────────────────────
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $edit = $pdo->prepare("SELECT * FROM medicines WHERE id=?")->execute([(int)$_GET['id']]) && ($edit = $pdo->prepare("SELECT * FROM medicines WHERE id=?")) && $edit->execute([(int)$_GET['id']]) ? $edit->fetch() : null;
    // cleaner
    $stmt = $pdo->prepare("SELECT * FROM medicines WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    $edit = $stmt->fetch();
}

// Search / list
$search = trim($_GET['q'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);

$sql = "SELECT m.*, c.name as cat_name, COALESCE(SUM(b.quantity),0) as stock
        FROM medicines m
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN batches b ON b.medicine_id = m.id";
$params = [];
$where  = [];
if ($search) { $where[] = "(m.name LIKE ? OR m.generic_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catFilter) { $where[] = "m.category_id = ?"; $params[] = $catFilter; }
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' GROUP BY m.id ORDER BY m.name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$medicines = $stmt->fetchAll();

renderHead('Medicines');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Medicines', 'Manage your medicine catalogue'); ?>
<div class="page-body">

<?php if ($msg):  ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<!-- ── FORM ─────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <span class="card-title"><?= $action === 'edit' ? 'Edit Medicine' : 'Add New Medicine' ?></span>
    <a href="medicines.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Back</a>
  </div>
  <form method="POST">
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <div class="form-row">
      <div class="form-group">
        <label>Medicine Name *</label>
        <input type="text" name="name" value="<?= htmlspecialchars($edit['name'] ?? '') ?>" required placeholder="e.g. Amoxicillin 500mg">
      </div>
      <div class="form-group">
        <label>Generic Name</label>
        <input type="text" name="generic_name" value="<?= htmlspecialchars($edit['generic_name'] ?? '') ?>" placeholder="e.g. Amoxicillin">
      </div>
      <div class="form-group">
        <label>Category</label>
        <select name="category_id">
          <option value="">— Select Category —</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= (($edit['category_id'] ?? '') == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Unit</label>
        <select name="unit">
          <?php foreach (['pcs','tablet','capsule','strip','bottle','vial','sachet','tube','kg','g','ml','L'] as $u): ?>
          <option value="<?= $u ?>" <?= (($edit['unit'] ?? 'pcs') === $u) ? 'selected' : '' ?>><?= $u ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Reorder Level</label>
        <input type="number" name="reorder_level" value="<?= $edit['reorder_level'] ?? 10 ?>" min="0">
      </div>
    </div>
    <div class="form-group">
      <label>Description</label>
      <textarea name="description" placeholder="Optional notes..."><?= htmlspecialchars($edit['description'] ?? '') ?></textarea>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> <?= $action === 'edit' ? 'Update' : 'Add Medicine' ?></button>
      <a href="medicines.php" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>

<?php else: ?>
<!-- ── LIST ─────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <span class="card-title">All Medicines (<?= count($medicines) ?>)</span>
    <a href="medicines.php?action=add" class="btn btn-primary"><i data-lucide="plus"></i> Add Medicine</a>
  </div>

  <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
    <form method="GET" style="display:flex;gap:10px;flex:1;min-width:260px;">
      <div class="search-bar" style="flex:1;">
        <i data-lucide="search"></i>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search medicines...">
      </div>
      <select name="cat" style="width:180px;">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $catFilter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-ghost">Filter</button>
      <?php if ($search || $catFilter): ?><a href="medicines.php" class="btn btn-ghost">Clear</a><?php endif; ?>
    </form>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Name</th><th>Generic</th><th>Category</th><th>Unit</th><th>Stock</th><th>Reorder</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (empty($medicines)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-300);">No medicines found</td></tr>
      <?php else: ?>
      <?php foreach ($medicines as $i => $m):
        $stockClass = $m['stock'] == 0 ? 'badge-red' : ($m['stock'] <= $m['reorder_level'] ? 'badge-orange' : 'badge-green');
      ?>
        <tr>
          <td style="color:var(--text-300);"><?= $i+1 ?></td>
          <td style="font-weight:600;color:var(--text-100);"><?= htmlspecialchars($m['name']) ?></td>
          <td style="color:var(--text-300);font-style:italic;"><?= htmlspecialchars($m['generic_name'] ?: '—') ?></td>
          <td><span class="badge badge-gray"><?= htmlspecialchars($m['cat_name'] ?? 'Uncategorized') ?></span></td>
          <td><?= htmlspecialchars($m['unit']) ?></td>
          <td><span class="badge <?= $stockClass ?>"><?= number_format($m['stock']) ?></span></td>
          <td style="color:var(--text-300);"><?= $m['reorder_level'] ?></td>
          <td>
            <div style="display:flex;gap:6px;">
              <a href="medicines.php?action=edit&id=<?= $m['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="pencil"></i></a>
              <a href="inventory.php?med=<?= $m['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="package"></i></a>
              <form method="POST" onsubmit="return confirmDelete(this)">
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
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
<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
