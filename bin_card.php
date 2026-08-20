<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';
require_once __DIR__ . '/report_lib.php';

$pdo  = getDB();
$medId = (int)($_GET['id'] ?? 0);
if (!$medId) { header('Location: inventory.php'); exit; }

$printMode = isset($_GET['print']);

// Fetch product info
$med = $pdo->prepare("
    SELECT m.*, COALESCE(c.name, 'Uncategorized') AS category_name,
           COALESCE(m.product_type, 'medicine') AS product_type
    FROM medicines m
    LEFT JOIN categories c ON c.id = m.category_id
    WHERE m.id = ?
");
$med->execute([$medId]);
$product = $med->fetch();
if (!$product) { header('Location: inventory.php'); exit; }

$currency = getSetting('currency', 'ETB');
$day = reportLocalDateExpr('s');

// Stock movements: purchases (+), sales (-), returns (+)
$movements = [];

// Purchases
$purStmt = $pdo->prepare("
    SELECT p.purchase_number, p.purchase_date AS date,
           'Purchase' AS type,
           pi.quantity, pi.purchase_price AS unit_cost,
           COALESCE(sup.name, 'Unknown') AS reference_name
    FROM purchase_items pi
    JOIN purchases p ON p.id = pi.purchase_id
    LEFT JOIN suppliers sup ON sup.id = p.supplier_id
    WHERE pi.medicine_id = ?
    ORDER BY p.purchase_date ASC
");
$purStmt->execute([$medId]);
foreach ($purStmt->fetchAll() as $row) {
    $movements[] = $row;
}

// Sales
$salStmt = $pdo->prepare("
    SELECT s.invoice_number, $day AS date,
           'Sale' AS type,
           si.quantity, si.unit_price AS unit_cost,
           COALESCE(NULLIF(s.customer_name, ''), 'Walk-in') AS reference_name
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    WHERE si.medicine_id = ?
    ORDER BY s.created_at ASC
");
$salStmt->execute([$medId]);
foreach ($salStmt->fetchAll() as $row) {
    $movements[] = $row;
}

// Returns
$retStmt = $pdo->prepare("
    SELECT COALESCE(s.invoice_number, '—') AS invoice_number,
           date(sr.created_at, '+3 hours') AS date,
           'Return' AS type,
           sr.quantity, sr.amount AS unit_cost,
           COALESCE(sr.reason, 'Return') AS reference_name
    FROM sale_returns sr
    LEFT JOIN sales s ON s.id = sr.sale_id
    WHERE sr.medicine_id = ?
    ORDER BY sr.created_at ASC
");
$retStmt->execute([$medId]);
foreach ($retStmt->fetchAll() as $row) {
    $movements[] = $row;
}

// Sort all movements by date ascending
usort($movements, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));

// Calculate running balance
$balance = 0;
foreach ($movements as &$mv) {
    if ($mv['type'] === 'Purchase' || $mv['type'] === 'Return') {
        $balance += (int)$mv['quantity'];
    } else {
        $balance -= (int)$mv['quantity'];
    }
    $mv['balance'] = $balance;
}
unset($mv);

// Batch summary
$batches = $pdo->prepare("
    SELECT b.*, COALESCE(sup.name, 'Unknown') AS supplier_name
    FROM batches b
    LEFT JOIN suppliers sup ON sup.id = b.supplier_id
    WHERE b.medicine_id = ?
    ORDER BY b.expiry_date ASC
");
$batches->execute([$medId]);
$batches = $batches->fetchAll();

$totalStock = array_sum(array_column($batches, 'quantity'));

if ($printMode):
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Bin Card — <?= htmlspecialchars($product['name']) ?></title>
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
  .stock-in { color: #166534; }
  .stock-out { color: #991b1b; }
  .totals { font-weight: bold; border-top: 2px solid #000; }
  .footer { margin-top: 16px; border-top: 1px solid #000; padding-top: 8px; font-size: 10px; }
  .batch-summary { margin-bottom: 14px; }
  @media print { body { padding: 10px; } }
</style>
</head>
<body>
<div class="header">
  <h1>Bin Card / Stock Card</h1>
  <h2><?= htmlspecialchars($product['name']) ?></h2>
</div>

<div class="info-grid">
  <div><span>Category:</span> <?= htmlspecialchars($product['category_name']) ?></div>
  <div><span>Product Type:</span> <?= htmlspecialchars(ucfirst($product['product_type'])) ?></div>
  <div><span>Unit:</span> <?= htmlspecialchars($product['unit']) ?></div>
  <div><span>Reorder Level:</span> <?= number_format($product['reorder_level']) ?></div>
  <div><span>Current Stock:</span> <?= number_format($totalStock) ?> <?= htmlspecialchars($product['unit']) ?></div>
  <div><span>Active Batches:</span> <?= count($batches) ?></div>
</div>

<!-- Batch Summary -->
<div class="batch-summary">
  <strong>Current Batch Status</strong>
  <table>
    <thead>
      <tr><th>Batch #</th><th>Qty</th><th>Buy Price</th><th>Sell Price</th><th>Expiry</th><th>Supplier</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b):
      $noExpiry = isNoExpiryDate($b['expiry_date'] ?? '');
      if ($noExpiry) { $st = 'OK'; }
      else {
          $days = (strtotime($b['expiry_date']) - time()) / 86400;
          $st = $days < 0 ? 'EXPIRED' : ($days <= 30 ? 'NEAR' : 'OK');
      }
      if ($b['quantity'] == 0) $st = 'OUT';
    ?>
      <tr>
        <td><?= htmlspecialchars($b['batch_number']) ?></td>
        <td><?= number_format($b['quantity']) ?></td>
        <td><?= currency($b['purchase_price']) ?></td>
        <td><?= currency($b['selling_price']) ?></td>
        <td><?= formatExpiryDate($b['expiry_date']) ?></td>
        <td><?= htmlspecialchars($b['supplier_name']) ?></td>
        <td><?= $st ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($batches)): ?>
      <tr><td colspan="7" style="text-align:center;">No batches on record</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Stock Movement History -->
<strong>Stock Movement History</strong>
<table>
  <thead>
    <tr><th>Date</th><th>Type</th><th>Reference</th><th>From/To</th><th>Qty In</th><th>Qty Out</th><th>Unit Cost</th><th>Balance</th></tr>
  </thead>
  <tbody>
  <?php foreach ($movements as $mv):
    $isIn = ($mv['type'] === 'Purchase' || $mv['type'] === 'Return');
    $ref = $mv['invoice_number'] ?? '—';
    $party = $mv['reference_name'] ?? '—';
  ?>
    <tr>
      <td><?= htmlspecialchars($mv['date'] ?? '—') ?></td>
      <td><?= $mv['type'] ?></td>
      <td><?= htmlspecialchars($ref) ?></td>
      <td><?= htmlspecialchars($party) ?></td>
      <td class="<?= $isIn ? 'stock-in' : '' ?>"><?= $isIn ? number_format($mv['quantity']) : '' ?></td>
      <td class="<?= !$isIn ? 'stock-out' : '' ?>"><?= !$isIn ? number_format($mv['quantity']) : '' ?></td>
      <td><?= currency((float)($mv['unit_cost'] ?? 0)) ?></td>
      <td style="font-weight:bold;"><?= number_format($mv['balance']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($movements)): ?>
    <tr><td colspan="8" style="text-align:center;">No stock movements on record</td></tr>
  <?php endif; ?>
  <tr class="totals">
    <td colspan="4" style="text-align:right;">Current Stock:</td>
    <td><?= number_format($totalStock) ?></td>
    <td colspan="3"></td>
  </tr>
  </tbody>
</table>

<div class="footer">
  Printed: <?= date('M j, Y H:i') ?> · <?= htmlspecialchars(getSetting('pharmacy_name', 'TADE PHARMACY')) ?>
</div>

<script>window.onload = function() { window.print(); };</script>
</body>
</html>
<?php exit; endif; ?>

<?php renderHead('Bin Card — ' . $product['name']); ?>
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Bin Card', htmlspecialchars($product['name'])); ?>
<div class="page-body">

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
  <a href="inventory.php?med=<?= $medId ?>" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Back to Inventory</a>
  <h2 style="font-size:18px;font-weight:700;flex:1;"><?= htmlspecialchars($product['name']) ?></h2>
  <a href="bin_card.php?id=<?= $medId ?>&print" target="_blank" class="btn btn-primary btn-sm"><i data-lucide="printer"></i> Print Bin Card</a>
</div>

<!-- Product Info -->
<div class="stats-grid mb-20">
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="package"></i></div><div><div class="stat-label">Current Stock</div><div class="stat-value"><?= number_format($totalStock) ?></div><div class="stat-sub"><?= htmlspecialchars($product['unit']) ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="layers"></i></div><div><div class="stat-label">Active Batches</div><div class="stat-value"><?= count($batches) ?></div></div></div>
  <div class="stat-card green"><div class="stat-icon green"><i data-lucide="tag"></i></div><div><div class="stat-label">Reorder Level</div><div class="stat-value"><?= number_format($product['reorder_level']) ?></div></div></div>
  <div class="stat-card red"><div class="stat-icon red"><i data-lucide="shopping-cart"></i></div><div><div class="stat-label">Total Movements</div><div class="stat-value"><?= count($movements) ?></div></div></div>
</div>

<!-- Batch Summary -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Current Batches</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Batch #</th><th>Qty</th><th>Buy Price</th><th>Sell Price</th><th>Expiry</th><th>Supplier</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($batches as $b):
        $noExpiry = isNoExpiryDate($b['expiry_date'] ?? '');
        if ($noExpiry) { $stCls = 'badge-green'; $stLbl = 'Good'; }
        else {
            $days = (strtotime($b['expiry_date']) - time()) / 86400;
            if ($days < 0) { $stCls = 'badge-red'; $stLbl = 'Expired'; }
            elseif ($days <= 30) { $stCls = 'badge-orange'; $stLbl = 'Near Expiry'; }
            else { $stCls = 'badge-green'; $stLbl = 'Good'; }
        }
        if ($b['quantity'] == 0) { $stCls = 'badge-gray'; $stLbl = 'Out'; }
      ?>
      <tr>
        <td><code><?= htmlspecialchars($b['batch_number']) ?></code></td>
        <td><strong><?= number_format($b['quantity']) ?></strong></td>
        <td><?= currency($b['purchase_price']) ?></td>
        <td><?= currency($b['selling_price']) ?></td>
        <td><?= formatExpiryDate($b['expiry_date']) ?></td>
        <td><?= htmlspecialchars($b['supplier_name']) ?></td>
        <td><span class="badge <?= $stCls ?>"><?= $stLbl ?></span></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($batches)): ?>
      <tr><td colspan="7" style="text-align:center;padding:20px;color:var(--text-300);">No batches on record</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Stock Movement History -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Stock Movement History</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>From/To</th><th>Qty In</th><th>Qty Out</th><th>Balance</th></tr></thead>
      <tbody>
      <?php foreach ($movements as $mv):
        $isIn = ($mv['type'] === 'Purchase' || $mv['type'] === 'Return');
        $ref = $mv['invoice_number'] ?? '—';
        $party = $mv['reference_name'] ?? '—';
        $typeColor = $isIn ? 'var(--accent2)' : 'var(--danger)';
        $typeIcon = $mv['type'] === 'Purchase' ? 'arrow-down' : ($mv['type'] === 'Return' ? 'rotate-ccw' : 'arrow-up');
      ?>
      <tr>
        <td style="font-size:12px;"><?= htmlspecialchars($mv['date'] ?? '—') ?></td>
        <td><span style="color:<?= $typeColor ?>;font-weight:600;"><i data-lucide="<?= $typeIcon ?>" style="width:14px;height:14px;display:inline;"></i> <?= $mv['type'] ?></span></td>
        <td><code style="font-size:12px;"><?= htmlspecialchars($ref) ?></code></td>
        <td style="font-size:12px;"><?= htmlspecialchars($party) ?></td>
        <td style="color:var(--accent2);font-weight:700;"><?= $isIn ? number_format($mv['quantity']) : '' ?></td>
        <td style="color:var(--danger);font-weight:700;"><?= !$isIn ? number_format($mv['quantity']) : '' ?></td>
        <td style="font-weight:700;"><?= number_format($mv['balance']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($movements)): ?>
      <tr><td colspan="7" style="text-align:center;padding:20px;color:var(--text-300);">No stock movements on record</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div></div>
<?php renderFooter(); ?>
