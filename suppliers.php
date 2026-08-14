<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/purchases_lib.php';

$pdo = getDB();
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'overview';
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    try {
        if (!can('suppliers.manage') && !can('purchases.manage')) {
            throw new RuntimeException('You cannot manage suppliers.');
        }
        if ($act === 'save') {
            $sid = saveSupplier($pdo, $_POST, (int)($_POST['id'] ?? 0));
            flashSet('success', 'Supplier saved.');
            header('Location: suppliers.php?id=' . $sid);
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $action = 'edit';
    }
}

$flash = flashGet();
if ($flash && $flash['type'] === 'success') $msg = $flash['message'];

$supplier = null;
$totals = [];
$ledger = [];
$purchases = [];
$payments = [];
$returns = [];
if ($id) {
    $st = $pdo->prepare("SELECT * FROM suppliers WHERE id=?");
    $st->execute([$id]);
    $supplier = $st->fetch();
    if (!$supplier) { header('Location: suppliers.php'); exit; }
    $totals = supplierTotals($pdo, $id);
    $action = ($action === 'edit') ? 'edit' : 'view';
    if ($action === 'view') {
        if ($tab === 'ledger') $ledger = supplierLedger($pdo, $id);
        if ($tab === 'purchases' || $tab === 'overview') {
            $ps = $pdo->prepare("SELECT * FROM purchases WHERE supplier_id=? ORDER BY id DESC LIMIT 100");
            $ps->execute([$id]);
            $purchases = $ps->fetchAll();
        }
        if ($tab === 'payments') {
            $pm = $pdo->prepare("SELECT pp.*, p.purchase_number FROM purchase_payments pp JOIN purchases p ON p.id=pp.purchase_id WHERE p.supplier_id=? ORDER BY pp.payment_date DESC, pp.id DESC");
            $pm->execute([$id]);
            $payments = $pm->fetchAll();
        }
        if ($tab === 'returns') {
            $rt = $pdo->prepare("SELECT r.*, p.purchase_number FROM purchase_returns r LEFT JOIN purchases p ON p.id=r.purchase_id WHERE r.supplier_id=? ORDER BY r.id DESC");
            $rt->execute([$id]);
            $returns = $rt->fetchAll();
        }
        if ($tab === 'overview') $ledger = array_slice(array_reverse(supplierLedger($pdo, $id)), 0, 8);
    }
}

$search = trim($_GET['q'] ?? '');
$list = [];
if ($action === 'list') {
    $sql = "SELECT * FROM suppliers";
    $params = [];
    if ($search) { $sql .= " WHERE name LIKE ? OR phone LIKE ? OR supplier_code LIKE ?"; $params = ["%$search%","%$search%","%$search%"]; }
    $sql .= " ORDER BY name COLLATE NOCASE";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $list = $st->fetchAll();
    foreach ($list as &$row) {
        $row['totals'] = supplierTotals($pdo, (int)$row['id']);
    }
    unset($row);
}

renderHead('Suppliers');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar($supplier ? $supplier['name'] : 'Suppliers', 'Supplier accounts & payables'); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($action === 'edit' || ($action === 'add')):
  $edit = $supplier ?: [];
?>
<div class="mb-20"><a href="suppliers.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> All Suppliers</a></div>
<div class="card" style="max-width:760px;">
  <div class="card-header"><span class="card-title"><?= $edit ? 'Edit Supplier' : 'New Supplier' ?></span></div>
  <form method="POST">
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div class="form-group"><label>Supplier Name *</label><input type="text" name="name" required value="<?= htmlspecialchars($edit['name'] ?? '') ?>"></div>
      <div class="form-group"><label>Company Name</label><input type="text" name="company_name" value="<?= htmlspecialchars($edit['company_name'] ?? '') ?>"></div>
      <div class="form-group"><label>Contact Person</label><input type="text" name="contact_person" value="<?= htmlspecialchars($edit['contact_person'] ?? '') ?>"></div>
      <div class="form-group"><label>Phone</label><input type="tel" name="phone" value="<?= htmlspecialchars($edit['phone'] ?? '') ?>"></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($edit['email'] ?? '') ?>"></div>
      <div class="form-group"><label>Tax / VAT Number</label><input type="text" name="tax_number" value="<?= htmlspecialchars($edit['tax_number'] ?? '') ?>"></div>
      <div class="form-group"><label>Payment Terms</label>
        <select name="payment_terms">
          <?php foreach (supplierPaymentTerms() as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= (($edit['payment_terms'] ?? '30') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Default Credit Days</label><input type="number" name="default_credit_days" min="0" value="<?= (int)($edit['default_credit_days'] ?? 30) ?>"></div>
      <div class="form-group"><label>Opening Balance</label><input type="number" name="opening_balance" min="0" step="0.01" value="<?= htmlspecialchars((string)($edit['opening_balance'] ?? 0)) ?>"></div>
      <div class="form-group"><label>Status</label>
        <select name="status">
          <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
    </div>
    <div class="form-group"><label>Address</label><textarea name="address"><?= htmlspecialchars($edit['address'] ?? '') ?></textarea></div>
    <div class="form-group"><label>Notes</label><textarea name="notes"><?= htmlspecialchars($edit['notes'] ?? '') ?></textarea></div>
    <div class="form-actions">
      <button class="btn btn-primary">Save Supplier</button>
      <a href="suppliers.php<?= !empty($edit['id']) ? '?id='.(int)$edit['id'] : '' ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>

<?php elseif ($action === 'view' && $supplier): ?>
<div class="mb-20" style="display:flex;gap:8px;flex-wrap:wrap;">
  <a href="suppliers.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> All Suppliers</a>
  <?php if (can('purchases.manage')): ?>
  <a href="purchases.php?action=add&supplier_id=<?= $supplier['id'] ?>" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New Purchase</a>
  <?php endif; ?>
  <?php if (can('suppliers.manage') || can('purchases.manage')): ?>
  <a href="suppliers.php?action=edit&id=<?= $supplier['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
  <?php endif; ?>
</div>

<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><?= htmlspecialchars($supplier['name']) ?></span>
    <span class="badge <?= ($supplier['status'] ?? 'active') === 'active' ? 'badge-green' : 'badge-gray' ?>"><?= htmlspecialchars($supplier['supplier_code'] ?? '') ?></span>
  </div>
  <div class="report-summary-grid">
    <div><span class="report-k">Company</span><span class="report-v"><?= htmlspecialchars($supplier['company_name'] ?: '—') ?></span></div>
    <div><span class="report-k">Contact</span><span class="report-v"><?= htmlspecialchars($supplier['contact_person'] ?: '—') ?></span></div>
    <div><span class="report-k">Phone</span><span class="report-v"><?= htmlspecialchars($supplier['phone'] ?: '—') ?></span></div>
    <div><span class="report-k">Terms</span><span class="report-v"><?= htmlspecialchars(supplierPaymentTerms()[$supplier['payment_terms'] ?? '30'] ?? '30 days') ?></span></div>
    <div><span class="report-k">Total Purchases</span><span class="report-v"><?= currency($totals['total_purchases']) ?></span></div>
    <div><span class="report-k">Total Paid</span><span class="report-v" style="color:var(--accent2);"><?= currency($totals['total_paid']) ?></span></div>
    <div><span class="report-k">Outstanding</span><span class="report-v" style="color:var(--warning);"><?= currency($totals['outstanding']) ?></span></div>
    <div><span class="report-k">Overdue</span><span class="report-v" style="color:var(--danger);"><?= currency($totals['overdue']) ?></span></div>
  </div>
</div>

<div class="admin-tabs">
  <?php foreach (['overview'=>'Overview','purchases'=>'Purchases','payments'=>'Payments','returns'=>'Returns','ledger'=>'Ledger'] as $k=>$lbl): ?>
  <a href="suppliers.php?id=<?= $supplier['id'] ?>&tab=<?= $k ?>" class="admin-tab<?= $tab===$k?' active':'' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'purchases' || $tab === 'overview'): ?>
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Purchases</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Invoice</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$purchases): ?>
        <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-300);">No purchases</td></tr>
      <?php else: foreach ($purchases as $p): $d = purchaseDisplayStatus($p); ?>
      <tr>
        <td><code><?= htmlspecialchars($p['purchase_number']) ?></code></td>
        <td><?= htmlspecialchars($p['purchase_date'] ?: substr($p['created_at'],0,10)) ?></td>
        <td><?= currency((float)($p['grand_total'] ?? $p['total_amount'])) ?></td>
        <td><?= currency((float)$p['total_paid']) ?></td>
        <td><?= currency(purchaseOutstanding($p)) ?></td>
        <td><span class="badge <?= $d[2] ?>"><?= htmlspecialchars($d[1]) ?></span></td>
        <td><a href="purchases.php?action=view&id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">View</a></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'payments'): ?>
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Payments</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Invoice</th><th>Amount</th><th>Method</th><th>Reference</th></tr></thead>
      <tbody>
      <?php if (!$payments): ?>
        <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-300);">No payments</td></tr>
      <?php else: foreach ($payments as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['payment_date']) ?></td>
        <td><code><?= htmlspecialchars($p['purchase_number']) ?></code></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency((float)$p['amount']) ?></td>
        <td><?= htmlspecialchars(supplierPaymentMethods()[$p['payment_method']] ?? $p['payment_method']) ?></td>
        <td><?= htmlspecialchars($p['reference_number'] ?: '—') ?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'returns'): ?>
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Returns</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Return #</th><th>Purchase</th><th>Date</th><th>Amount</th><th>Reason</th></tr></thead>
      <tbody>
      <?php if (!$returns): ?>
        <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-300);">No returns</td></tr>
      <?php else: foreach ($returns as $r): ?>
      <tr>
        <td><code><?= htmlspecialchars($r['return_number']) ?></code></td>
        <td><code><?= htmlspecialchars($r['purchase_number'] ?? '') ?></code></td>
        <td><?= htmlspecialchars($r['return_date']) ?></td>
        <td><?= currency((float)$r['total_amount']) ?></td>
        <td><?= htmlspecialchars(purchaseReturnReasons()[$r['reason']] ?? $r['reason']) ?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'ledger' || $tab === 'overview'): ?>
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Account Ledger</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Description</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead>
      <tbody>
      <?php if (!$ledger): ?>
        <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-300);">No ledger activity</td></tr>
      <?php else: foreach ($ledger as $row): ?>
      <tr>
        <td><?= htmlspecialchars($row['date']) ?></td>
        <td><?= htmlspecialchars($row['description']) ?></td>
        <td><?= $row['debit'] ? currency($row['debit']) : '—' ?></td>
        <td><?= $row['credit'] ? currency($row['credit']) : '—' ?></td>
        <td style="font-weight:700;"><?= currency($row['balance']) ?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card">
  <div class="card-header">
    <span class="card-title">Suppliers</span>
    <div style="display:flex;gap:8px;">
      <a href="purchases.php" class="btn btn-ghost btn-sm">Purchases</a>
      <?php if (can('suppliers.manage') || can('purchases.manage')): ?>
      <a href="suppliers.php?action=add" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> New Supplier</a>
      <?php endif; ?>
    </div>
  </div>
  <form method="GET" style="display:flex;gap:8px;padding:0 16px 14px;">
    <div class="search-bar" style="flex:1;"><i data-lucide="search"></i><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search suppliers..."></div>
    <button class="btn btn-ghost">Search</button>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Code</th><th>Name</th><th>Phone</th><th>Purchases</th><th>Paid</th><th>Outstanding</th><th>Overdue</th><th></th></tr></thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-300);">No suppliers yet</td></tr>
      <?php else: foreach ($list as $s): $t = $s['totals']; ?>
      <tr>
        <td><code><?= htmlspecialchars($s['supplier_code'] ?? '') ?></code></td>
        <td style="font-weight:600;"><?= htmlspecialchars($s['name']) ?></td>
        <td><?= htmlspecialchars($s['phone'] ?: '—') ?></td>
        <td><?= currency($t['total_purchases']) ?></td>
        <td><?= currency($t['total_paid']) ?></td>
        <td style="font-weight:700;color:var(--warning);"><?= currency($t['outstanding']) ?></td>
        <td style="color:var(--danger);"><?= currency($t['overdue']) ?></td>
        <td><a href="suppliers.php?id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm">View</a></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
