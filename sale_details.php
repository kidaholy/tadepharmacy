<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';
require_once __DIR__ . '/customers_lib.php';

$pdo = getDB();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: sales.php'); exit; }

$msg = '';
$error = '';

// Record a payment against this sale (e.g. customer settling credit).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'record_payment') {
    if (!can('sales.manage')) {
        $error = 'You do not have permission to record payments.';
    } else {
        try {
            receiveCreditPayment(
                $pdo,
                $id,
                (float)($_POST['amount'] ?? 0),
                $_POST['payment_method'] ?? 'cash',
                trim($_POST['reference'] ?? ''),
                (int)(currentUser()['id'] ?? 0),
                trim($_POST['notes'] ?? '')
            );
            flashSet('success', 'Payment recorded.');
            header('Location: sale_details.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$flash = flashGet();
if ($flash && $flash['type'] === 'success') $msg = $flash['message'];

$s = $pdo->prepare("
    SELECT s.*, u.full_name AS cashier_name
    FROM sales s
    LEFT JOIN users u ON u.id = s.user_id
    WHERE s.id = ?
");
$s->execute([$id]);
$sale = $s->fetch();
if (!$sale) { header('Location: sales.php'); exit; }

$items = fetchSaleItemsDetailed($pdo, $id);

$payStmt = $pdo->prepare("
    SELECT ph.*, u.full_name AS received_by_name
    FROM payment_history ph
    LEFT JOIN users u ON u.id = ph.received_by
    WHERE ph.sale_id = ?
    ORDER BY ph.payment_date ASC
");
$payStmt->execute([$id]);
$paymentHistory = $payStmt->fetchAll();

$methods = posPaymentMethods();
$currency = getSetting('currency', 'ETB');
$subtotal    = (float)$sale['total_amount'];
$discount    = (float)$sale['discount'];
$tax         = (float)($sale['tax'] ?? 0);
$discounted  = saleDiscountedSubtotal($sale);
$netTotal    = saleNetAmount($sale);
$paid        = (float)$sale['paid_amount'];
$remaining   = (float)($sale['remaining_balance'] ?? max(0, $netTotal - $paid));
$status      = $sale['payment_status'] ?? computePaymentStatus($netTotal, $paid, $sale['payment_method']);
$payLabel    = $methods[$sale['payment_method']] ?? ucfirst($sale['payment_method']);
$dueDate     = $sale['due_date'] ?? $sale['credit_due_date'] ?? null;

renderHead('Sale Details');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Sale Details', $sale['invoice_number']); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="alert-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="mb-20" style="display:flex;gap:8px;flex-wrap:wrap;">
  <a href="sales.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Sales / Daily Record</a>
  <a href="pos.php" class="btn btn-success btn-sm"><i data-lucide="plus"></i> New Sale</a>
  <a href="receipt.php?id=<?= $sale['id'] ?>" class="btn btn-primary btn-sm"><i data-lucide="printer"></i> Print Receipt</a>
  <?php if ($sale['customer_id']): ?>
  <a href="customers.php?id=<?= $sale['customer_id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="user"></i> Customer</a>
  <?php endif; ?>
</div>

<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><?= htmlspecialchars($sale['invoice_number']) ?></span>
    <span class="badge <?= paymentStatusBadge($status) ?>"><?= paymentStatusLabel($status) ?></span>
  </div>
  <div class="report-summary-grid">
    <div><span class="report-k">Date / Time</span><span class="report-v" style="font-size:14px;"><?= date('M d, Y H:i', strtotime($sale['created_at'])) ?></span></div>
    <div><span class="report-k">Customer</span><span class="report-v" style="font-size:14px;"><?= htmlspecialchars($sale['customer_name']) ?></span></div>
    <div><span class="report-k">Phone</span><span class="report-v" style="font-size:14px;"><?= htmlspecialchars($sale['customer_phone'] ?: '—') ?></span></div>
    <div><span class="report-k">Cashier</span><span class="report-v" style="font-size:14px;"><?= htmlspecialchars($sale['cashier_name'] ?: '—') ?></span></div>
    <div><span class="report-k">Payment Method</span><span class="report-v" style="font-size:14px;"><?= htmlspecialchars($payLabel) ?></span></div>
    <div><span class="report-k">Due Date</span><span class="report-v" style="font-size:14px;"><?= $dueDate ? htmlspecialchars($dueDate) : '—' ?></span></div>
    <?php if ($sale['payment_reference']): ?>
    <div><span class="report-k">Reference</span><span class="report-v" style="font-size:14px;"><?= htmlspecialchars($sale['payment_reference']) ?></span></div>
    <?php endif; ?>
  </div>
  <?php if ($sale['credit_notes'] || $sale['notes']): ?>
  <p style="margin-top:12px;font-size:13px;color:var(--text-300);"><?= nl2br(htmlspecialchars(trim($sale['credit_notes'] ?: $sale['notes']))) ?></p>
  <?php endif; ?>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Medicines</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Medicine</th><th>Generic</th><th>Strength / Form</th><th>Batch</th><th>Expiry</th>
          <th>Qty</th><th>Unit Price</th><th>Discount</th><th>Tax</th><th>Line Total</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($it['name']) ?></td>
        <td><?= htmlspecialchars($it['generic_name'] ?: '—') ?></td>
        <td><?= htmlspecialchars(trim(($it['strength'] ?: '') . ' ' . ($it['dosage_form'] ?: '')) ?: '—') ?></td>
        <td><code><?= htmlspecialchars($it['batch_number'] ?: '—') ?></code></td>
        <td><?= formatExpiryDate($it['expiry_date'] ?? null) ?></td>
        <td><?= (int)$it['quantity'] ?> <?= htmlspecialchars($it['unit'] ?: '') ?></td>
        <td><?= currency((float)$it['unit_price']) ?></td>
        <td><?= currency((float)($it['discount'] ?? 0)) ?></td>
        <td><?= currency((float)($it['tax'] ?? 0)) ?></td>
        <td style="font-weight:700;"><?= currency((float)($it['subtotal'] ?? 0) - (float)($it['discount'] ?? 0) + (float)($it['tax'] ?? 0)) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="report-summary-grid" style="margin-top:16px;">
    <div><span class="report-k">Subtotal</span><span class="report-v"><?= currency($subtotal) ?></span></div>
    <div><span class="report-k">Discount</span><span class="report-v"><?= currency($discount) ?></span></div>
    <div><span class="report-k">Discounted Subtotal</span><span class="report-v"><?= currency($discounted) ?></span></div>
    <div><span class="report-k">Tax</span><span class="report-v"><?= currency($tax) ?></span></div>
    <div><span class="report-k">TOTAL</span><span class="report-v" style="color:var(--accent2);"><?= currency($netTotal) ?></span></div>
    <div><span class="report-k">Paid</span><span class="report-v"><?= currency($paid) ?></span></div>
    <div><span class="report-k">Balance Due</span><span class="report-v" style="color:var(--warning);"><?= currency($remaining) ?></span></div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Payment History</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Received By</th><th>Notes</th></tr></thead>
      <tbody>
      <?php if (!$paymentHistory): ?>
        <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-300);">No payments yet</td></tr>
      <?php else: foreach ($paymentHistory as $p): ?>
      <tr>
        <td><?= date('M j, Y H:i', strtotime($p['payment_date'])) ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency((float)$p['amount']) ?></td>
        <td><?= htmlspecialchars($methods[$p['payment_method']] ?? $p['payment_method']) ?></td>
        <td><?= htmlspecialchars($p['reference_number'] ?: '—') ?></td>
        <td><?= htmlspecialchars($p['received_by_name'] ?: '—') ?></td>
        <td><?= htmlspecialchars($p['notes'] ?? '') ?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($remaining > 0.009 && can('sales.manage')): ?>
<div class="card">
  <div class="card-header"><span class="card-title">Record Payment</span></div>
  <form method="POST" style="padding:0 16px 16px;">
    <input type="hidden" name="act" value="record_payment">
    <p style="font-size:13px;color:var(--text-300);margin-bottom:12px;">
      Invoice <?= htmlspecialchars($sale['invoice_number']) ?> · Total <?= currency($netTotal) ?> · Paid <?= currency($paid) ?> ·
      Remaining <strong style="color:var(--warning);"><?= currency($remaining) ?></strong>
    </p>
    <div class="form-row">
      <div class="form-group" style="min-width:120px;">
        <label>Amount</label>
        <input type="number" name="amount" min="0.01" step="0.01" max="<?= $remaining ?>" value="<?= number_format($remaining, 2, '.', '') ?>" required>
      </div>
      <div class="form-group" style="min-width:150px;">
        <label>Method</label>
        <select name="payment_method">
          <?php foreach ($methods as $k => $lbl): if ($k === 'credit') continue; ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Reference</label>
        <input type="text" name="reference" placeholder="Optional">
      </div>
      <div class="form-group" style="flex:2;">
        <label>Notes</label>
        <input type="text" name="notes" placeholder="Optional">
      </div>
    </div>
    <button type="submit" class="btn btn-primary"><i data-lucide="wallet"></i> Record Payment</button>
  </form>
</div>
<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
