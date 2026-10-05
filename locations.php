<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory_lib.php';
require_once __DIR__ . '/sales_lib.php';

$pdo = getDB();
$userId = (int)(currentUser()['id'] ?? 0);
$pharmacyName = getSetting('pharmacy_name', 'TADE PHARMACY');

$canEdit = can('locations.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'save_location') {
        if (!$canEdit) { flashSet('error', 'Permission denied.'); header('Location: locations.php'); exit; }
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';
        if (!$name) { flashSet('error', 'Location name is required.'); header('Location: locations.php'); exit; }
        if ($code !== '') {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE code = ? AND id != ?");
            $dup->execute([$code, (int)($_POST['id'] ?? 0)]);
            if ((int)$dup->fetchColumn() > 0) {
                flashSet('error', 'Location code already exists.');
                header('Location: locations.php'); exit;
            }
        }
        $id = (int)($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare("UPDATE locations SET name=?, code=?, address=?, status=?, updated_at=datetime('now') WHERE id=?")->execute([$name, $code, $address, $status, $id]);
            } else {
                $pdo->prepare("INSERT INTO locations (name, code, address, status) VALUES (?,?,?,?)")->execute([$name, $code, $address, $status]);
            }
            $pdo->commit();
            flashSet('success', $id ? 'Location updated.' : 'Location created.');
            header('Location: locations.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flashSet('error', 'Failed: ' . $e->getMessage());
            header('Location: locations.php');
            exit;
        }
    }
    if ($act === 'delete') {
        if (!$canEdit) { flashSet('error', 'Permission denied.'); header('Location: locations.php'); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_transfers WHERE from_location_id = ? OR to_location_id = ?");
        $cntStmt->execute([$id, $id]);
        $cnt = (int)$cntStmt->fetchColumn();
        if ($cnt > 0) { flashSet('error', "Cannot delete: $cnt transfer(s) use this location. Mark inactive instead."); }
        else {
            $pdo->prepare("UPDATE locations SET status='inactive', updated_at=datetime('now') WHERE id=?")->execute([$id]);
            flashSet('success', 'Location marked inactive.');
        }
        header('Location: locations.php');
        exit;
    }
    flashSet('error', 'Unknown action.');
    header('Location: locations.php');
    exit;
}

$action = $_GET['action'] ?? '';
$editRow = null;
if ($action === 'edit') {
    $editId = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM locations WHERE id = ?");
    $st->execute([$editId]);
    $editRow = $st->fetch() ?: null;
    if (!$editRow) {
        flashSet('error', 'Location not found.');
        header('Location: locations.php');
        exit;
    }
}
$showForm = $canEdit && ($action === 'add' || ($action === 'edit' && $editRow));
$locations = $pdo->query("SELECT * FROM locations ORDER BY name")->fetchAll();
?>
<?php renderHead($pharmacyName . ' — Locations'); ?>
<?php renderSidebar(); ?>
<div class="main-content">
<?php renderTopbar('Locations', 'Manage pharmacy locations'); ?>
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
        <span class="card-title"><i data-lucide="map-pin"></i> <?= $editRow ? 'Edit Location' : 'New Location' ?></span>
        <a href="locations.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Cancel</a>
    </div>
    <form method="POST">
        <input type="hidden" name="act" value="save_location">
        <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Location Name *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars((string)($editRow['name'] ?? '')) ?>" placeholder="e.g. Tade Pharmacy Main Branch">
            </div>
            <div class="form-group">
                <label>Code</label>
                <input type="text" name="code" value="<?= htmlspecialchars((string)($editRow['code'] ?? '')) ?>" placeholder="e.g. MAIN">
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="active" <?= ($editRow['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($editRow['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Address</label>
            <input type="text" name="address" value="<?= htmlspecialchars((string)($editRow['address'] ?? '')) ?>">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> <?= $editRow ? 'Update Location' : 'Create Location' ?></button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card mb-20">
    <div class="card-header">
        <span class="card-title"><i data-lucide="map-pin"></i> Locations</span>
        <?php if ($canEdit): ?><a href="locations.php?action=add" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New Location</a><?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Code</th><th>Address</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if (empty($locations)): ?>
                <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-300);">No locations found.</td></tr>
            <?php else: ?>
            <?php foreach ($locations as $l): ?>
            <tr>
                <td style="font-size:12px;font-weight:500;"><?= htmlspecialchars($l['name']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($l['code'] ?: '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($l['address'] ?: '—') ?></td>
                <td style="font-size:12px;"><span class="badge <?= $l['status'] === 'active' ? 'badge-green' : 'badge-gray' ?>"><?= $l['status'] ?></span></td>
                <td style="font-size:12px;">
                    <div class="row-actions">
                        <a href="locations.php?action=edit&id=<?= $l['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="pencil"></i> Edit</a>
                        <form method="POST" onsubmit="return confirm('Mark this location inactive?')" style="display:inline;">
                            <input type="hidden" name="act" value="delete">
                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
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

