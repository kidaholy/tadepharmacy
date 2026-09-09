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
$currency        = getSetting('currency', 'ETB');

renderHead('Invoice ' . $p['purchase_number'], 'print-80mm');
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
    <?php if (!empty($p['due_date'])): ?><div class="rt-row"><span>Due date</span><span><?= htmlspecialchars($p['due_date']) ?></span></div><?php endif; ?>
    <div class="rt-row"><span>Status</span><span><?= htmlspecialchars($disp[1]) ?></span></div>
    <div class="rt-row"><span>Supplier</span><span><?= htmlspecialchars($p['supplier_name'] ?? '—') ?></span></div>
    <?php if (!empty($p['company_name'])): ?><div class="rt-row"><span>Company</span><span><?= htmlspecialchars($p['company_name']) ?></span></div><?php endif; ?>
    <?php if (!empty($p['supplier_phone'])): ?><div class="rt-row"><span>Phone</span><span><?= htmlspecialchars($p['supplier_phone']) ?></span></div><?php endif; ?>
    <?php if (!empty($p['supplier_tax'])): ?><div class="rt-row"><span>Tax No.</span><span><?= htmlspecialchars($p['supplier_tax']) ?></span></div><?php endif; ?>
  </div>

  <table class="rt-items">
    <thead><tr><th class="rt-col-item">Item</th><th class="rt-col-qty">Qty</th><th class="rt-col-amt">Amt</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it):
      $detail = trim(implode(' · ', array_filter([
          $it['variant'] ?? '', $it['model_number'] ?? '', $it['serial_number'] ?? '',
      ], fn($v) => $v !== '' && $v !== null)));
      $lineAmt = (float)($it['line_total'] ?: $it['quantity'] * $it['purchase_price']);
    ?>
      <tr>
        <td class="rt-col-item">
          <div class="rt-item-name"><?= htmlspecialchars($it['med_name']) ?></div>
          <div class="rt-item-sub">
            Batch: <?= htmlspecialchars($it['batch_number']) ?>
            <?= !empty($it['expiry_date']) && !isNoExpiryDate($it['expiry_date']) ? ' · Exp: ' . htmlspecialchars(date('d/m/y', strtotime($it['expiry_date']))) : '' ?>
            <?= $detail !== '' ? ' · ' . htmlspecialchars($detail) : '' ?>
            <br><?= $currency ?> <?= number_format((float)$it['purchase_price'], 2) ?> × <?= (int)$it['quantity'] ?>
          </div>
        </td>
        <td class="rt-col-qty"><?= (int)$it['quantity'] ?></td>
        <td class="rt-col-amt"><?= number_format($lineAmt, 2) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="rt-totals">
    <?php if ((float)$p['subtotal'] > 0): ?><div class="rt-row"><span>Subtotal</span><span><?= $currency ?> <?= number_format((float)$p['subtotal'], 2) ?></span></div><?php endif; ?>
    <?php if ((float)$p['discount'] > 0): ?><div class="rt-row"><span>Discount</span><span>-<?= $currency ?> <?= number_format((float)$p['discount'], 2) ?></span></div><?php endif; ?>
    <?php if ((float)$p['tax'] > 0): ?><div class="rt-row"><span>Tax</span><span><?= $currency ?> <?= number_format((float)$p['tax'], 2) ?></span></div><?php endif; ?>
    <div class="rt-row rt-total"><span>TOTAL</span><span><?= $currency ?> <?= number_format($grand, 2) ?></span></div>
    <div class="rt-row"><span>Paid</span><span><?= $currency ?> <?= number_format($paid, 2) ?></span></div>
    <?php if ($due > 0.009): ?><div class="rt-row"><span>Balance Due</span><span><?= $currency ?> <?= number_format($due, 2) ?></span></div><?php endif; ?>
  </div>

  <?php if ($payments): ?>
  <div class="rt-footer" style="text-align:left;">
    <div class="rt-meta" style="font-weight:700;">Payments</div>
    <?php foreach ($payments as $pp): ?>
    <div class="rt-row"><span><?= htmlspecialchars(date('d/m/y', strtotime($pp['payment_date']))) ?> · <?= htmlspecialchars($pp['payment_method']) ?></span><span><?= $currency ?> <?= number_format((float)$pp['amount'], 2) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="rt-sign">
    <div>Prepared by<br><strong><?= htmlspecialchars($p['created_by_name'] ?? '—') ?></strong></div>
    <div>Approved by<br>________________</div>
  </div>
</div>

</div></div>

<?php renderFooter(); ?>
