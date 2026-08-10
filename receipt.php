<?php
require_once __DIR__ . '/layout.php';

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);

if (!$id) { header('Location: sales.php'); exit; }

$sale = $pdo->prepare("SELECT * FROM sales WHERE id=?")->execute([$id]) ? ($s = $pdo->prepare("SELECT * FROM sales WHERE id=?")) && $s->execute([$id]) ? $s->fetch() : null : null;
$s = $pdo->prepare("SELECT * FROM sales WHERE id=?");
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

$pharmacyName    = getSetting('pharmacy_name', 'TADE PHARMACY');
$pharmacyPhone   = getSetting('pharmacy_phone');
$pharmacyAddress = getSetting('pharmacy_address');
$pharmacyEmail   = getSetting('pharmacy_email');
$receiptFooter   = getSetting('receipt_footer');
$currency        = getSetting('currency', 'ETB');

renderHead('Receipt #' . $sale['invoice_number']);
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Receipt', $sale['invoice_number']); ?>
<div class="page-body">

<div style="display:flex;gap:12px;margin-bottom:20px;" class="no-print">
  <button onclick="window.print()" class="btn btn-primary"><i data-lucide="printer"></i> Print Receipt</button>
  <a href="pos.php" class="btn btn-success"><i data-lucide="plus"></i> New Sale</a>
  <a href="sales.php" class="btn btn-ghost"><i data-lucide="arrow-left"></i> Back to Sales</a>
</div>

<div class="card" style="max-width:480px;margin:0 auto;" id="receiptContent">
  <!-- Header -->
  <div style="text-align:center;padding-bottom:18px;border-bottom:2px dashed var(--border);">
    <div style="font-size:22px;font-weight:800;color:var(--accent);letter-spacing:1px;"><?= htmlspecialchars($pharmacyName) ?></div>
    <div style="font-size:12px;color:var(--text-300);margin-top:4px;"><?= htmlspecialchars($pharmacyAddress) ?></div>
    <div style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($pharmacyPhone) ?> | <?= htmlspecialchars($pharmacyEmail) ?></div>
    <div style="margin-top:10px;font-size:18px;font-weight:700;color:var(--text-100);">RECEIPT</div>
  </div>

  <!-- Invoice Info -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:14px 0;border-bottom:1px dashed var(--border);font-size:13px;">
    <div><span style="color:var(--text-300);">Invoice:</span><br><strong><?= htmlspecialchars($sale['invoice_number']) ?></strong></div>
    <div style="text-align:right;"><span style="color:var(--text-300);">Date:</span><br><strong><?= date('M d, Y H:i', strtotime($sale['created_at'])) ?></strong></div>
    <div><span style="color:var(--text-300);">Customer:</span><br><strong><?= htmlspecialchars($sale['customer_name']) ?></strong></div>
    <div style="text-align:right;"><span style="color:var(--text-300);">Payment:</span><br><strong style="text-transform:capitalize;"><?= htmlspecialchars($sale['payment_method']) ?></strong></div>
    <?php if ($sale['customer_phone']): ?>
    <div><span style="color:var(--text-300);">Phone:</span><br><strong><?= htmlspecialchars($sale['customer_phone']) ?></strong></div>
    <?php endif; ?>
  </div>

  <!-- Items -->
  <div style="padding:14px 0;border-bottom:1px dashed var(--border);">
    <table style="font-size:13px;">
      <thead>
        <tr>
          <th>Item</th>
          <th style="text-align:center;">Qty</th>
          <th style="text-align:right;">Price</th>
          <th style="text-align:right;">Total</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($saleItems as $it): ?>
      <tr>
        <td style="padding:8px 4px;">
          <div style="font-weight:600;"><?= htmlspecialchars($it['name']) ?></div>
          <div style="font-size:11px;color:var(--text-300);">Batch: <?= htmlspecialchars($it['batch_number']) ?></div>
        </td>
        <td style="text-align:center;padding:8px 4px;"><?= $it['quantity'] ?> <?= htmlspecialchars($it['unit']) ?></td>
        <td style="text-align:right;padding:8px 4px;"><?= $currency ?> <?= number_format($it['unit_price'],2) ?></td>
        <td style="text-align:right;padding:8px 4px;font-weight:700;color:var(--accent2);"><?= $currency ?> <?= number_format($it['subtotal'],2) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Totals -->
  <div style="padding:14px 0;font-size:14px;">
    <div style="display:flex;justify-content:space-between;margin-bottom:6px;color:var(--text-200);">
      <span>Subtotal</span><span><?= $currency ?> <?= number_format($sale['total_amount'],2) ?></span>
    </div>
    <?php if ($sale['discount'] > 0): ?>
    <div style="display:flex;justify-content:space-between;margin-bottom:6px;color:var(--warning);">
      <span>Discount</span><span>− <?= $currency ?> <?= number_format($sale['discount'],2) ?></span>
    </div>
    <?php endif; ?>
    <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:800;color:var(--text-100);border-top:1px dashed var(--border);padding-top:10px;">
      <span>TOTAL</span><span style="color:var(--accent);"><?= $currency ?> <?= number_format($sale['total_amount'] - $sale['discount'],2) ?></span>
    </div>
    <div style="display:flex;justify-content:space-between;margin-top:6px;color:var(--text-300);font-size:13px;">
      <span>Amount Paid</span><span><?= $currency ?> <?= number_format($sale['paid_amount'],2) ?></span>
    </div>
    <?php
    $change = $sale['paid_amount'] - ($sale['total_amount'] - $sale['discount']);
    if ($change > 0):
    ?>
    <div style="display:flex;justify-content:space-between;color:var(--accent2);font-weight:700;font-size:14px;margin-top:4px;">
      <span>Change</span><span><?= $currency ?> <?= number_format($change,2) ?></span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Footer -->
  <div style="text-align:center;padding-top:14px;border-top:2px dashed var(--border);font-size:12px;color:var(--text-300);">
    <?= htmlspecialchars($receiptFooter) ?>
  </div>
</div>

</div></div>
<?php renderFooter(); ?>
