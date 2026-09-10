<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';
require_once __DIR__ . '/inventory_lib.php';

$pdo = getDB();
$medId = (int)($_GET['id'] ?? 0);
$batchId = (int)($_GET['batch_id'] ?? 0) ?: null;
if (!$medId) {
    header('Location: inventory.php');
    exit;
}

$printMode = isset($_GET['print']);

$med = $pdo->prepare("
    SELECT m.*, COALESCE(c.name, 'Uncategorized') AS category_name,
           COALESCE(m.product_type, 'medicine') AS product_type,
           COALESCE(NULLIF(TRIM(m.brand_name), ''), m.name) AS brand_display
    FROM medicines m
    LEFT JOIN categories c ON c.id = m.category_id
    WHERE m.id = ?
");
$med->execute([$medId]);
$product = $med->fetch();
if (!$product) {
    header('Location: inventory.php');
    exit;
}

$movements = fetchStockHistory($pdo, $medId, $batchId);

$batches = $pdo->prepare("
    SELECT b.*, COALESCE(sup.name, 'Unknown') AS supplier_name
    FROM batches b
    LEFT JOIN suppliers sup ON sup.id = b.supplier_id
    WHERE b.medicine_id = ? AND COALESCE(b.status,'active')='active'
    ORDER BY b.expiry_date ASC
");
$batches->execute([$medId]);
$batches = $batches->fetchAll();
$totalStock = array_sum(array_column($batches, 'quantity'));
$pharmacyName = getSetting('pharmacy_name', 'TADE PHARMACY');

$typeLabels = [
    'purchase' => 'Purchase',
    'sale' => 'Sale',
    'sale_return' => 'Sale Return',
    'purchase_return' => 'Purchase Return',
    'adjustment' => 'Adjustment',
    'deactivate' => 'Deactivate',
];

if ($printMode):
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Stock History — <?= htmlspecialchars($product['name']) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Courier New', monospace; font-size: 11px; color: #000; padding: 20px; }
  .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 10px; }
  .header h1 { font-size: 16px; text-transform: uppercase; }
  .header h2 { font-size: 14px; margin-top: 4px; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; margin-bottom: 12px; border: 1px solid #000; padding: 8px; }
  .info-grid span { font-weight: bold; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
  th, td { border: 1px solid #000; padding: 4px 6px; text-align: left; font-size: 10px; }
  th { background: #eee; text-transform: uppercase; font-size: 9px; }
  @media print { body { padding: 10px; } }
</style>
</head>
<body>
<div class="header">
  <h1><?= htmlspecialchars($pharmacyName) ?></h1>
  <h2>Stock History — <?= htmlspecialchars($product['name']) ?></h2>
</div>
<div class="info-grid">
  <div><span>Generic:</span> <?= htmlspecialchars($product['generic_name'] ?: '—') ?></div>
  <div><span>Brand:</span> <?= htmlspecialchars($product['brand_display']) ?></div>
  <div><span>Category:</span> <?= htmlspecialchars($product['category_name']) ?></div>
  <div><span>Current Stock:</span> <?= number_format($totalStock) ?> <?= htmlspecialchars($product['unit']) ?></div>
</div>
<table>
  <thead>
    <tr><th>Date</th><th>Type</th><th>Batch</th><th>Reference</th><th>Reason</th><th>Qty In</th><th>Qty Out</th><th>Balance</th><th>User</th></tr>
  </thead>
  <tbody>
  <?php foreach ($movements as $mv): ?>
    <tr>
      <td><?= htmlspecialchars(substr((string)($mv['date'] ?? ''), 0, 19)) ?></td>
      <td><?= htmlspecialchars($typeLabels[$mv['type']] ?? ucfirst(str_replace('_', ' ', (string)$mv['type']))) ?></td>
      <td><?= htmlspecialchars($mv['batch_number'] ?: '—') ?></td>
      <td><?= htmlspecialchars($mv['reference'] ?: '—') ?></td>
      <td><?= htmlspecialchars($mv['reason'] ?: '—') ?></td>
      <td><?= (int)$mv['qty_in'] > 0 ? number_format((int)$mv['qty_in']) : '' ?></td>
      <td><?= (int)$mv['qty_out'] > 0 ? number_format((int)$mv['qty_out']) : '' ?></td>
      <td style="font-weight:bold;"><?= number_format((int)$mv['balance']) ?></td>
      <td><?= htmlspecialchars($mv['user'] ?: '—') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($movements)): ?>
    <tr><td colspan="9" style="text-align:center;">No stock history on record</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<div style="margin-top:12px;font-size:10px;">Printed: <?= date('M j, Y H:i') ?></div>
<script>window.onload = function () { window.print(); };</script>
</body>
</html>
<?php
exit;
endif;

renderHead('Stock History — ' . $product['name']);
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Stock History', htmlspecialchars($product['name'])); ?>
<div class="page-body">

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
  <a href="inventory.php?med=<?= $medId ?>" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Back to Inventory</a>
  <a href="bin_card.php?id=<?= $medId ?>" class="btn btn-ghost btn-sm"><i data-lucide="file-text"></i> Bin Card</a>
  <h2 style="font-size:18px;font-weight:700;flex:1;"><?= htmlspecialchars($product['name']) ?></h2>
  <a href="stock_history.php?id=<?= $medId ?>&print" target="_blank" class="btn btn-primary btn-sm"><i data-lucide="printer"></i> Print</a>
</div>

<div class="stats-grid mb-20">
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="package"></i></div><div><div class="stat-label">Current Stock</div><div class="stat-value"><?= number_format($totalStock) ?></div><div class="stat-sub"><?= htmlspecialchars($product['unit']) ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="layers"></i></div><div><div class="stat-label">Active Batches</div><div class="stat-value"><?= count($batches) ?></div></div></div>
  <div class="stat-card green"><div class="stat-icon green"><i data-lucide="tag"></i></div><div><div class="stat-label">Brand</div><div class="stat-value" style="font-size:16px;"><?= htmlspecialchars($product['brand_display']) ?></div></div></div>
  <div class="stat-card red"><div class="stat-icon red"><i data-lucide="history"></i></div><div><div class="stat-label">Movements</div><div class="stat-value"><?= count($movements) ?></div></div></div>
</div>

<div class="card mb-20">
  <div class="card-header">
    <span class="card-title">Stock History</span>
    <span style="font-size:12px;color:var(--text-300);">
      <?= htmlspecialchars($product['generic_name'] ?: '') ?>
      <?= $product['generic_name'] ? ' · ' : '' ?>
      <?= htmlspecialchars($product['category_name']) ?>
    </span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date</th><th>Type</th><th>Batch</th><th>Reference</th><th>Reason</th>
          <th>Qty In</th><th>Qty Out</th><th>Balance</th><th>User</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($movements as $mv):
        $isIn = (int)$mv['qty_in'] > 0;
        $label = $typeLabels[$mv['type']] ?? ucfirst(str_replace('_', ' ', (string)$mv['type']));
        $typeColor = $isIn ? 'var(--accent2)' : 'var(--danger)';
      ?>
      <tr>
        <td style="font-size:12px;"><?= htmlspecialchars(substr((string)($mv['date'] ?? ''), 0, 19)) ?></td>
        <td><span style="color:<?= $typeColor ?>;font-weight:600;"><?= htmlspecialchars($label) ?></span></td>
        <td><code style="font-size:12px;"><?= htmlspecialchars($mv['batch_number'] ?: '—') ?></code></td>
        <td style="font-size:12px;"><?= htmlspecialchars($mv['reference'] ?: '—') ?></td>
        <td style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($mv['reason'] ?: '—') ?></td>
        <td style="color:var(--accent2);font-weight:700;"><?= $isIn ? number_format((int)$mv['qty_in']) : '' ?></td>
        <td style="color:var(--danger);font-weight:700;"><?= (int)$mv['qty_out'] > 0 ? number_format((int)$mv['qty_out']) : '' ?></td>
        <td style="font-weight:700;"><?= number_format((int)$mv['balance']) ?></td>
        <td style="font-size:12px;"><?= htmlspecialchars($mv['user'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($movements)): ?>
      <tr><td colspan="9" style="text-align:center;padding:20px;color:var(--text-300);">No stock history on record</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div></div>
<?php renderFooter(); ?>
