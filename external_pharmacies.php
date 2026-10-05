<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory_lib.php';
require_once __DIR__ . '/sales_lib.php';

$pdo = getDB();
$userId = (int)(currentUser()['id'] ?? 0);
$pharmacyName = getSetting('pharmacy_name', 'TADE PHARMACY');

$canEdit = can('pharmacy.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'save_pharmacy') {
        if (!$canEdit) { flashSet('error', 'Permission denied.'); header('Location: external_pharmacies.php'); exit; }
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $status = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';
        if (!$name) { flashSet('error', 'Pharmacy name is required.'); header('Location: external_pharmacies.php'); exit; }
        if ($phone !== '') {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM external_pharmacies WHERE phone = ? AND id != ?");
            $dup->execute([$phone, (int)($_POST['id'] ?? 0)]);
            if ((int)$dup->fetchColumn() > 0) {
                flashSet('error', 'Phone already registered to another external pharmacy.');
                header('Location: external_pharmacies.php'); exit;
            }
        }
        $id = (int)($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare("UPDATE external_pharmacies SET name=?, contact_person=?, phone=?, email=?, address=?, notes=?, status=?, updated_at=datetime('now') WHERE id=?")->execute([$name, $contact, $phone, $email, $address, $notes, $status, $id]);
            } else {
                $pdo->prepare("INSERT INTO external_pharmacies (name, contact_person, phone, email, address, notes, status) VALUES (?,?,?,?,?,?,?)")->execute([$name, $contact, $phone, $email, $address, $notes, $status]);
            }
            $pdo->commit();
            flashSet('success', $id ? 'External pharmacy updated.' : 'External pharmacy created.');
            header('Location: external_pharmacies.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flashSet('error', 'Failed: ' . $e->getMessage());
            header('Location: external_pharmacies.php');
            exit;
        }
    }
    if ($act === 'delete') {
        if (!$canEdit) { flashSet('error', 'Permission denied.'); header('Location: external_pharmacies.php'); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_exchanges WHERE external_pharmacy_id = ?");
        $cntStmt->execute([$id]);
        $cnt = (int)$cntStmt->fetchColumn();
        if ($cnt > 0) { flashSet('error', "Cannot delete: $cnt exchange(s) reference this pharmacy. Mark inactive instead."); }
        else {
            $pdo->prepare("UPDATE external_pharmacies SET status='inactive', updated_at=datetime('now') WHERE id=?")->execute([$id]);
            flashSet('success', 'External pharmacy marked inactive.');
        }
        header('Location: external_pharmacies.php');
        exit;
    }
    flashSet('error', 'Unknown action.');
    header('Location: external_pharmacies.php');
    exit;
}

$action = $_GET['action'] ?? '';
$editRow = null;
if ($action === 'edit') {
    $editId = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM external_pharmacies WHERE id = ?");
    $st->execute([$editId]);
    $editRow = $st->fetch() ?: null;
    if (!$editRow) {
        flashSet('error', 'External pharmacy not found.');
        header('Location: external_pharmacies.php');
        exit;
    }
}
$showForm = $canEdit && ($action === 'add' || ($action === 'edit' && $editRow));
$pharmacies = $pdo->query("SELECT * FROM external_pharmacies ORDER BY name")->fetchAll();
?>
<?php renderHead($pharmacyName . ' — External Pharmacies'); ?>
<?php renderSidebar(); ?>
<div class="main-content">
<?php renderTopbar('External Pharmacies', 'Manage external pharmacy records'); ?>
<div class="page-body">

<?php if (flashGet()): ?>
<div class="alert alert-<?= flashGet()['type'] === 'success' ? 'success' : 'danger' ?>">
    <i data-lucide="<?= flashGet()['type'] === 'success' ? 'check-circle' : 'x-circle' ?>"></i>
    <?= htmlspecialchars(flashGet()['message']) ?>
</div>
<?php endif; ?>

<?php if ($showForm): ?>
<div class="card mb-20">
    <div class="card-header">
        <span class="card-title"><i data-lucide="handshake"></i> <?= $editRow ? 'Edit External Pharmacy' : 'New External Pharmacy' ?></span>
        <a href="external_pharmacies.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Cancel</a>
    </div>
    <form method="POST">
        <input type="hidden" name="act" value="save_pharmacy">
        <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Pharmacy Name *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars((string)($editRow['name'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label>Contact Person</label>
                <input type="text" name="contact_person" value="<?= htmlspecialchars((string)($editRow['contact_person'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="phone" value="<?= htmlspecialchars((string)($editRow['phone'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="active" <?= ($editRow['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($editRow['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars((string)($editRow['email'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label>Address</label>
                <input type="text" name="address" value="<?= htmlspecialchars((string)($editRow['address'] ?? '')) ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" rows="2"><?= htmlspecialchars((string)($editRow['notes'] ?? '')) ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> <?= $editRow ? 'Update Pharmacy' : 'Create Pharmacy' ?></button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card mb-20">
    <div class="card-header">
        <span class="card-title"><i data-lucide="handshake"></i> External Pharmacies</span>
        <?php if ($canEdit): ?><a href="external_pharmacies.php?action=add" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New External Pharmacy</a><?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Pharmacy Name</th><th>Contact Person</th><th>Phone</th><th>Address</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if (empty($pharmacies)): ?>
                <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-300);">No external pharmacies found.</td></tr>
            <?php else: ?>
            <?php foreach ($pharmacies as $e): ?>
            <tr>
                <td style="font-size:12px;font-weight:500;"><?= htmlspecialchars($e['name']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($e['contact_person'] ?: '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($e['phone'] ?: '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($e['address'] ?: '—') ?></td>
                <td style="font-size:12px;"><span class="badge <?= $e['status'] === 'active' ? 'badge-green' : 'badge-gray' ?>"><?= $e['status'] ?></span></td>
                <td style="font-size:12px;">
                    <div class="row-actions">
                        <a href="external_pharmacies.php?action=edit&id=<?= $e['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="pencil"></i> Edit</a>
                        <form method="POST" onsubmit="return confirm('Mark this pharmacy inactive?')" style="display:inline;">
                            <input type="hidden" name="act" value="delete">
                            <input type="hidden" name="id" value="<?= $e['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm"><i data-lucide="x"></i> Deactivate</button>
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

</div></div>
<?php renderFooter(); ?>

