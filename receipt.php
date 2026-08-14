<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';

$pdo = getDB();
$id  = (int)($_GET['id'] ?? 0);

if (!$id) { header('Location: sales.php'); exit; }

$s = $pdo->prepare("SELECT s.*, c.full_name AS reg_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id=?");
$s->execute([$id]);
$sale = $s->fetch();

if (!$sale) { header('Location: sales.php'); exit; }

$items = $pdo->prepare("
    SELECT si.*, m.name, m.unit, b.batch_number
    FROM sale_items si
    JOIN medicines m ON m.id = si.medicine_id
    JOIN batches b ON b.id = si.batch_id
    WHERE si.sale_id = ?
");
$items->execute([$id]);
$saleItems = $items->fetchAll();

$payments = $pdo->prepare("
    SELECT ph.*, u.full_name AS received_by_name
    FROM payment_history ph
    LEFT JOIN users u ON u.id = ph.received_by
    WHERE ph.sale_id = ?
    ORDER BY ph.payment_date ASC
");
$payments->execute([$id]);
$paymentHistory = $payments->fetchAll();

$pharmacyName    = getSetting('pharmacy_name', 'TADE PHARMACY');
$pharmacyPhone   = getSetting('pharmacy_phone');
$pharmacyAddress = getSetting('pharmacy_address');
$pharmacyEmail   = getSetting('pharmacy_email');
$receiptFooter   = getSetting('receipt_footer');
$currency        = getSetting('currency', 'ETB');
$netTotal        = saleNetAmount($sale);
$remaining       = (float)($sale['remaining_balance'] ?? max(0, $netTotal - (float)$sale['paid_amount']));
$status          = $sale['payment_status'] ?? computePaymentStatus($netTotal, (float)$sale['paid_amount'], $sale['payment_method']);
$payLabel        = posPaymentMethods()[$sale['payment_method']] ?? ucfirst($sale['payment_method']);
$dueDate         = $sale['due_date'] ?? $sale['credit_due_date'] ?? null;
$change          = (float)$sale['paid_amount'] - $netTotal;

renderHead('Receipt #' . $sale['invoice_number']);
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Receipt', $sale['invoice_number']); ?>
<div class="page-body">

<div class="receipt-actions no-print">
  <button onclick="window.print()" class="btn btn-primary"><i data-lucide="printer"></i> Print Receipt</button>
  <a href="pos.php" class="btn btn-success"><i data-lucide="plus"></i> New Sale</a>
  <?php if ($sale['customer_id']): ?>
  <a href="customers.php?id=<?= $sale['customer_id'] ?>" class="btn btn-ghost"><i data-lucide="user"></i> Customer</a>
  <?php endif; ?>
  <a href="sales.php" class="btn btn-ghost"><i data-lucide="arrow-left"></i> Back to Sales</a>
</div>

<div class="receipt-ticket" id="receiptContent">
  <div class="rt-header">
    <?php if (pharmacyLogoExists()): ?>
    <img src="<?= htmlspecialchars(pharmacyLogoUrl()) ?>" alt="<?= htmlspecialchars($pharmacyName) ?>" class="rt-logo">
    <?php endif; ?>
    <div class="rt-name"><?= htmlspecialchars($pharmacyName) ?></div>
    <?php if ($pharmacyAddress): ?><div class="rt-meta"><?= htmlspecialchars($pharmacyAddress) ?></div><?php endif; ?>
    <?php if ($pharmacyPhone || $pharmacyEmail): ?>
    <div class="rt-meta"><?= htmlspecialchars(trim($pharmacyPhone . ($pharmacyPhone && $pharmacyEmail ? ' | ' : '') . $pharmacyEmail)) ?></div>
    <?php endif; ?>
    <div class="rt-title">RECEIPT</div>
  </div>

  <div class="rt-info">
    <div class="rt-row"><span>Invoice</span><span><?= htmlspecialchars($sale['invoice_number']) ?></span></div>
    <div class="rt-row"><span>Date</span><span><?= date('d/m/Y H:i', strtotime($sale['created_at'])) ?></span></div>
    <div class="rt-row"><span>Customer</span><span><?= htmlspecialchars($sale['customer_name']) ?></span></div>
    <?php if ($sale['customer_phone']): ?>
    <div class="rt-row"><span>Phone</span><span><?= htmlspecialchars($sale['customer_phone']) ?></span></div>
    <?php endif; ?>
    <div class="rt-row"><span>Payment</span><span class="rt-cap"><?= htmlspecialchars($payLabel) ?></span></div>
    <div class="rt-row"><span>Status</span><span><?= paymentStatusLabel($status) ?></span></div>
    <?php if ($dueDate && $sale['payment_method'] === 'credit'): ?>
    <div class="rt-row"><span>Due Date</span><span><?= date('d/m/Y', strtotime($dueDate)) ?></span></div>
    <?php endif; ?>
    <?php if ($sale['payment_reference']): ?>
    <div class="rt-row"><span>Reference</span><span><?= htmlspecialchars($sale['payment_reference']) ?></span></div>
    <?php endif; ?>
  </div>

  <table class="rt-items">
    <thead><tr><th class="rt-col-item">Item</th><th class="rt-col-qty">Qty</th><th class="rt-col-amt">Amt</th></tr></thead>
    <tbody>
    <?php foreach ($saleItems as $it): ?>
      <tr>
        <td class="rt-col-item">
          <div class="rt-item-name"><?= htmlspecialchars($it['name']) ?></div>
          <div class="rt-item-sub">Batch: <?= htmlspecialchars($it['batch_number']) ?> · <?= $currency ?> <?= number_format($it['unit_price'], 2) ?></div>
        </td>
        <td class="rt-col-qty"><?= (int)$it['quantity'] ?></td>
        <td class="rt-col-amt"><?= number_format($it['subtotal'], 2) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="rt-totals">
    <div class="rt-row"><span>Subtotal</span><span><?= $currency ?> <?= number_format($sale['total_amount'], 2) ?></span></div>
    <?php if ((float)$sale['discount'] > 0): ?>
    <div class="rt-row"><span>Discount</span><span>-<?= $currency ?> <?= number_format($sale['discount'], 2) ?></span></div>
    <?php endif; ?>
    <div class="rt-row rt-total"><span>TOTAL</span><span><?= $currency ?> <?= number_format($netTotal, 2) ?></span></div>
    <div class="rt-row"><span>Paid</span><span><?= $currency ?> <?= number_format($sale['paid_amount'], 2) ?></span></div>
    <?php if ($remaining > 0.009): ?>
    <div class="rt-row"><span>Balance Due</span><span><?= $currency ?> <?= number_format($remaining, 2) ?></span></div>
    <?php endif; ?>
    <?php if ($change > 0 && $sale['payment_method'] !== 'credit'): ?>
    <div class="rt-row"><span>Change</span><span><?= $currency ?> <?= number_format($change, 2) ?></span></div>
    <?php endif; ?>
  </div>

  <?php if ($sale['credit_notes']): ?>
  <div class="rt-footer" style="margin-top:8px;">Note: <?= htmlspecialchars($sale['credit_notes']) ?></div>
  <?php endif; ?>
  <?php if ($receiptFooter): ?>
  <div class="rt-footer"><?= htmlspecialchars($receiptFooter) ?></div>
  <?php endif; ?>
</div>

<?php if (!empty($paymentHistory)): ?>
<div class="card mt-20 no-print">
  <div class="card-header"><span class="card-title">Payment History</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Received By</th></tr></thead>
    <tbody>
    <?php foreach ($paymentHistory as $p): ?>
    <tr>
      <td><?= date('M j, Y H:i', strtotime($p['payment_date'])) ?></td>
      <td><?= currency((float)$p['amount']) ?></td>
      <td><?= htmlspecialchars(posPaymentMethods()[$p['payment_method']] ?? $p['payment_method']) ?></td>
      <td><?= htmlspecialchars($p['reference_number'] ?? '—') ?></td>
      <td><?= htmlspecialchars($p['received_by_name'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
