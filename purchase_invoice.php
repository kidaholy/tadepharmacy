<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/purchases_lib.php';

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: purchases.php'); exit; }
$p = fetchPurchase($pdo, $id);
if (!$p) { header('Location: purchases.php'); exit; }
$items = fetchPurchaseItems($pdo, $id);
$payments = fetchPurchasePayments($pdo, $id);
$disp = purchaseDisplayStatus($p);
$grand = (float)($p['grand_total'] ?? $p['total_amount']);
$paid = (float)$p['total_paid'];
$due = purchaseOutstanding($p);

$pharmacyName    = getSetting('pharmacy_name', 'TADE PHARMACY');
$pharmacyPhone   = getSetting('pharmacy_phone');
$pharmacyAddress = getSetting('pharmacy_address');
$pharmacyEmail   = getSetting('pharmacy_email');
$taxInfo         = getSetting('pharmacy_tax', '');

renderHead('Invoice ' . $p['purchase_number']);
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Purchase Invoice', $p['purchase_number']); ?>
<div class="page-body">

<div class="receipt-actions no-print">
  <button onclick="window.print()" class="btn btn-primary"><i data-lucide="printer"></i> Print</button>
  <a href="purchase_invoice.php?id=<?= $p['id'] ?>" class="btn btn-ghost"><i data-lucide="refresh-cw"></i> Reprint</a>
  <a href="purchases.php?action=view&id=<?= $p['id'] ?>" class="btn btn-ghost">Back</a>
</div>

<div class="receipt-ticket purchase-invoice" id="invoiceContent">
  <div class="rt-header">
    <?php if (pharmacyLogoExists()): ?>
    <img src="<?= htmlspecialchars(pharmacyLogoUrl()) ?>" alt="<?= htmlspecialchars($pharmacyName) ?>" class="rt-logo">
    <?php endif; ?>
    <div class="rt-name"><?= htmlspecialchars($pharmacyName) ?></div>
    <?php if ($pharmacyAddress): ?><div class="rt-meta"><?= htmlspecialchars($pharmacyAddress) ?></div><?php endif; ?>
    <?php if ($pharmacyPhone || $pharmacyEmail): ?>
    <div class="rt-meta"><?= htmlspecialchars(trim($pharmacyPhone . ($pharmacyPhone && $pharmacyEmail ? ' | ' : '') . $pharmacyEmail)) ?></div>
    <?php endif; ?>
    <?php if ($taxInfo): ?><div class="rt-meta">TIN: <?= htmlspecialchars($taxInfo) ?></div><?php endif; ?>
    <div class="rt-title">PURCHASE INVOICE</div>
  </div>

  <div class="rt-info">
    <div class="rt-row"><span>Invoice</span><span><?= htmlspecialchars($p['purchase_number']) ?></span></div>
    <div class="rt-row"><span>Date</span><span><?= htmlspecialchars($p['purchase_date'] ?: date('Y-m-d', strtotime($p['created_at']))) ?></span></div>
    <div class="rt-row"><span>Due date</span><span><?= htmlspecialchars($p['due_date'] ?: '—') ?></span></div>
    <div class="rt-row"><span>Status</span><span><?= htmlspecialchars($disp[1]) ?></span></div>
  </div>

  <div class="rt-info" style="margin-top:8px;">
    <div class="rt-row"><span>Supplier</span><span><?= htmlspecialchars($p['supplier_name'] ?? '—') ?></span></div>
    <?php if (!empty($p['company_name'])): ?><div class="rt-row"><span>Company</span><span><?= htmlspecialchars($p['company_name']) ?></span></div><?php endif; ?>
    <?php if (!empty($p['supplier_phone'])): ?><div class="rt-row"><span>Phone</span><span><?= htmlspecialchars($p['supplier_phone']) ?></span></div><?php endif; ?>
    <?php if (!empty($p['supplier_tax'])): ?><div class="rt-row"><span>Tax No.</span><span><?= htmlspecialchars($p['supplier_tax']) ?></span></div><?php endif; ?>
  </div>

  <table class="rt-items">
    <thead>
      <tr><th>Product</th><th>Batch</th><th>Exp</th><th>Qty</th><th>Price</th><th>Total</th></tr>
    </thead>
    <tbody>
    <?php foreach ($items as $it):
      $detail = trim(implode(' · ', array_filter([
          $it['variant'] ?? '', $it['model_number'] ?? '', $it['serial_number'] ?? '',
      ], fn($v) => $v !== '' && $v !== null)));
    ?>
      <tr>
        <td><?= htmlspecialchars($it['med_name']) ?><?= $detail !== '' ? '<br><small>' . htmlspecialchars($detail) . '</small>' : '' ?></td>
        <td><?= htmlspecialchars($it['batch_number']) ?></td>
        <td><?= formatExpiryDate($it['expiry_date']) ?></td>
        <td><?= (int)$it['quantity'] ?></td>
        <td><?= number_format((float)$it['purchase_price'], 2) ?></td>
        <td><?= number_format((float)($it['line_total'] ?: $it['quantity'] * $it['purchase_price']), 2) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="rt-info">
    <div class="rt-row"><span>Subtotal</span><span><?= currency((float)$p['subtotal']) ?></span></div>
    <div class="rt-row"><span>Discount</span><span><?= currency((float)$p['discount']) ?></span></div>
    <div class="rt-row"><span>Tax</span><span><?= currency((float)$p['tax']) ?></span></div>
    <div class="rt-row"><span><strong>Grand Total</strong></span><span><strong><?= currency($grand) ?></strong></span></div>
    <div class="rt-row"><span>Paid</span><span><?= currency($paid) ?></span></div>
    <div class="rt-row"><span>Remaining</span><span><?= currency($due) ?></span></div>
  </div>

  <?php if ($payments): ?>
  <div class="rt-meta" style="margin-top:10px;text-align:left;">Payments</div>
  <?php foreach ($payments as $pp): ?>
  <div class="rt-row"><span><?= htmlspecialchars($pp['payment_date']) ?> · <?= htmlspecialchars($pp['payment_method']) ?></span><span><?= currency((float)$pp['amount']) ?></span></div>
  <?php endforeach; ?>
  <?php endif; ?>

  <div class="rt-sign">
    <div>Prepared by<br><strong><?= htmlspecialchars($p['created_by_name'] ?? '—') ?></strong></div>
    <div>Approved by<br>________________</div>
  </div>
</div>

</div></div>
<?php renderFooter(); ?>
