<?php
require_once __DIR__ . '/layout.php';

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$msg    = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact = trim($_POST['contact_person'] ?? '');

        if (!$name) { $error = 'Supplier name is required.'; }
        else {
            if ($id) {
                $pdo->prepare("UPDATE suppliers SET name=?,phone=?,email=?,address=?,contact_person=? WHERE id=?")
                    ->execute([$name,$phone,$email,$address,$contact,$id]);
                $msg = 'Supplier updated.';
            } else {
                $pdo->prepare("INSERT INTO suppliers (name,phone,email,address,contact_person) VALUES (?,?,?,?,?)")
                    ->execute([$name,$phone,$email,$address,$contact]);
                $msg = 'Supplier added.';
            }
            $action = 'list';
        }
    }

    if ($act === 'delete') {
        $pdo->prepare("DELETE FROM suppliers WHERE id=?")->execute([(int)$_POST['id']]);
        $msg = 'Supplier deleted.';
        $action = 'list';
    }
}

$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $s = $pdo->prepare("SELECT * FROM suppliers WHERE id=?");
    $s->execute([(int)$_GET['id']]);
    $edit = $s->fetch();
}

$suppliers = $pdo->query("SELECT s.*, COUNT(p.id) as purchases FROM suppliers s LEFT JOIN purchases p ON p.supplier_id = s.id GROUP BY s.id ORDER BY s.name")->fetchAll();

renderHead('Suppliers');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Suppliers', 'Manage your supplier directory'); ?>
<div class="page-body">

<?php if ($msg):  ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title"><?= $action === 'edit' ? 'Edit Supplier' : 'Add Supplier' ?></span>
    <a href="suppliers.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Back</a>
  </div>
  <form method="POST">
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <div class="form-row">
      <div class="form-group"><label>Supplier Name *</label><input type="text" name="name" value="<?= htmlspecialchars($edit['name'] ?? '') ?>" required placeholder="e.g. Ethiopian Pharmaceuticals"></div>
      <div class="form-group"><label>Contact Person</label><input type="text" name="contact_person" value="<?= htmlspecialchars($edit['contact_person'] ?? '') ?>" placeholder="Sales representative name"></div>
      <div class="form-group"><label>Phone</label><input type="tel" name="phone" value="<?= htmlspecialchars($edit['phone'] ?? '') ?>" placeholder="+251..."></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($edit['email'] ?? '') ?>" placeholder="supplier@email.com"></div>
    </div>
    <div class="form-group"><label>Address</label><textarea name="address" placeholder="Full address..."><?= htmlspecialchars($edit['address'] ?? '') ?></textarea></div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Save Supplier</button>
      <a href="suppliers.php" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>

<?php else: ?>
<div class="card">
  <div class="card-header">
    <span class="card-title">All Suppliers (<?= count($suppliers) ?>)</span>
    <a href="suppliers.php?action=add" class="btn btn-primary"><i data-lucide="plus"></i> Add Supplier</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Name</th><th>Contact</th><th>Phone</th><th>Email</th><th>Purchases</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (empty($suppliers)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-300);">No suppliers yet</td></tr>
      <?php else: ?>
      <?php foreach ($suppliers as $i => $sup): ?>
      <tr>
        <td style="color:var(--text-300);"><?= $i+1 ?></td>
        <td style="font-weight:600;color:var(--text-100);"><?= htmlspecialchars($sup['name']) ?></td>
        <td><?= htmlspecialchars($sup['contact_person'] ?: '—') ?></td>
        <td><?= htmlspecialchars($sup['phone'] ?: '—') ?></td>
        <td style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($sup['email'] ?: '—') ?></td>
        <td><span class="badge badge-blue"><?= $sup['purchases'] ?></span></td>
        <td>
          <div style="display:flex;gap:6px;">
            <a href="suppliers.php?action=edit&id=<?= $sup['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="pencil"></i></a>
            <form method="POST" onsubmit="return confirmDelete(this)">
              <input type="hidden" name="act" value="delete">
              <input type="hidden" name="id" value="<?= $sup['id'] ?>">
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
