<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';

$pdo = getDB();
extract(reportInit());
$tab = $_GET['tab'] ?? 'value';
$from = $dates['from'];
$to   = $dates['to'];

$invValue = $pdo->query("
    SELECT COALESCE(SUM(quantity * purchase_price), 0) AS cost,
           COALESCE(SUM(quantity * selling_price), 0) AS retail,
           COALESCE(SUM(quantity), 0) AS units
    FROM batches WHERE quantity > 0
")->fetch();

$lowStock = $pdo->query("
    SELECT m.name, m.reorder_level, COALESCE(SUM(b.quantity),0) AS stock,
           COALESCE(SUM(b.quantity * b.purchase_price),0) AS value
    FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id
    GROUP BY m.id HAVING stock > 0 AND stock <= m.reorder_level
    ORDER BY stock ASC LIMIT 50
")->fetchAll();

$outStock = $pdo->query("
    SELECT m.name FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id
    GROUP BY m.id HAVING COALESCE(SUM(b.quantity),0) = 0 ORDER BY m.name LIMIT 50
")->fetchAll();

$nearExpiry = $pdo->query("
    SELECT m.name, b.batch_number, b.quantity, b.expiry_date,
           (b.quantity * b.purchase_price) AS value
    FROM batches b JOIN medicines m ON m.id = b.medicine_id
    WHERE b.expiry_date BETWEEN date('now') AND date('now','+30 days') AND b.quantity > 0
      AND b.expiry_date < '9000-01-01'
    ORDER BY b.expiry_date ASC LIMIT 50
")->fetchAll();

$expired = $pdo->query("
    SELECT m.name, b.batch_number, b.quantity, b.expiry_date,
           (b.quantity * b.purchase_price) AS value
    FROM batches b JOIN medicines m ON m.id = b.medicine_id
    WHERE b.expiry_date < date('now') AND b.quantity > 0
      AND b.expiry_date < '9000-01-01'
    ORDER BY b.expiry_date DESC LIMIT 50
")->fetchAll();

$fastMoving = reportTopProducts($pdo, $dates, $filters, 'qty', 20);
$slowMoving = $pdo->prepare("
    SELECT m.name, COALESCE(SUM(b.quantity),0) AS stock,
           COALESCE(SUM(b.quantity * b.purchase_price),0) AS value
    FROM medicines m JOIN batches b ON b.medicine_id = m.id AND b.quantity > 0
    WHERE m.id NOT IN (
      SELECT DISTINCT si.medicine_id FROM sale_items si JOIN sales s ON s.id = si.sale_id
      WHERE date(s.created_at) BETWEEN ? AND ?
    ) GROUP BY m.id ORDER BY value DESC LIMIT 20
");
$slowMoving->execute([$from, $to]);
$slowMoving = $slowMoving->fetchAll();

$deadStock = $pdo->prepare("
    SELECT m.name, COALESCE(SUM(b.quantity),0) AS stock,
           COALESCE(SUM(b.quantity * b.purchase_price),0) AS value
    FROM medicines m JOIN batches b ON b.medicine_id = m.id AND b.quantity > 0
    WHERE m.id NOT IN (
      SELECT DISTINCT si.medicine_id FROM sale_items si JOIN sales s ON s.id = si.sale_id
      WHERE date(s.created_at) BETWEEN date('now','-90 days') AND date('now')
    ) GROUP BY m.id ORDER BY value DESC LIMIT 20
");
$deadStock->execute();
$deadStock = $deadStock->fetchAll();

$aging = $pdo->query("
    SELECT CASE
      WHEN julianday('now') - julianday(b.created_at) <= 30 THEN '0-30 days'
      WHEN julianday('now') - julianday(b.created_at) <= 90 THEN '31-90 days'
      WHEN julianday('now') - julianday(b.created_at) <= 180 THEN '91-180 days'
      ELSE '180+ days'
    END AS bucket,
    SUM(b.quantity) AS units, SUM(b.quantity * b.purchase_price) AS value
    FROM batches b WHERE b.quantity > 0
    GROUP BY bucket ORDER BY MIN(julianday('now') - julianday(b.created_at))
")->fetchAll();

renderHead('Inventory Reports', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Inventory Reports', 'Stock analysis & risk'); ?>
<div class="page-body">
<?php renderReportNav('report_inventory'); ?>
<?php renderReportFilters($dates, $filters, $options); ?>
<?php renderReportMeta('Inventory Reports', $dates); ?>

<div class="stats-grid mb-20">
  <div class="stat-card green"><div class="stat-icon green"><i data-lucide="boxes"></i></div><div><div class="stat-label">Inventory Value (Cost)</div><div class="stat-value"><?= number_format($invValue['cost'], 0) ?></div><div class="stat-sub"><?= number_format($invValue['units']) ?> units</div></div></div>
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="tag"></i></div><div><div class="stat-label">Retail Value</div><div class="stat-value"><?= number_format($invValue['retail'], 0) ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="alert-triangle"></i></div><div><div class="stat-label">Low Stock Items</div><div class="stat-value"><?= count($lowStock) ?></div></div></div>
  <div class="stat-card red"><div class="stat-icon red"><i data-lucide="x-circle"></i></div><div><div class="stat-label">Out of Stock</div><div class="stat-value"><?= count($outStock) ?></div></div></div>
</div>

<div class="report-view-tabs no-print mb-20">
  <?php foreach (['value'=>'Overview','fast'=>'Fast Moving','slow'=>'Slow Moving','dead'=>'Dead Stock','low'=>'Low Stock','out'=>'Out of Stock','near'=>'Near Expiry','expired'=>'Expired','aging'=>'Aging'] as $k=>$lbl): ?>
  <a href="?tab=<?= $k ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-sm <?= $tab===$k?'btn-primary':'btn-ghost' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Inventory Distribution (Aging)</span></div>
    <div class="chart-wrap chart-donut"><canvas id="chartPayments" data-labels='<?= json_encode(array_column($aging,'bucket')) ?>' data-values='<?= json_encode(array_map(fn($r)=>(float)$r['value'], $aging)) ?>'></canvas></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Fast Moving Products</span></div>
    <div class="chart-wrap"><canvas id="chartTopProducts" data-labels='<?= json_encode(array_column(array_slice($fastMoving,0,8),'name')) ?>' data-values='<?= json_encode(array_map(fn($r)=>(int)$r['qty_sold'], array_slice($fastMoving,0,8))) ?>'></canvas></div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">
    <?php
    echo match($tab) {
      'fast' => 'Fast Moving Products', 'slow' => 'Slow Moving Products', 'dead' => 'Dead Stock (no sales 90 days)',
      'low' => 'Low Stock', 'out' => 'Out of Stock', 'near' => 'Near Expiry (30 days)', 'expired' => 'Expired Products',
      'aging' => 'Inventory Aging', default => 'Inventory Overview'
    };
    ?>
  </span></div>
  <div class="table-wrap"><table>
    <thead><tr>
      <?php if (in_array($tab, ['near','expired'])): ?>
      <th>Medicine</th><th>Batch</th><th>Qty</th><th>Expiry</th><th>Value</th>
      <?php elseif ($tab === 'aging'): ?>
      <th>Age Bucket</th><th>Units</th><th>Value</th>
      <?php elseif ($tab === 'out'): ?>
      <th>Medicine</th>
      <?php else: ?>
      <th>Medicine</th><th>Stock</th><?= $tab==='low'?'<th>Reorder</th>':'' ?><th>Value</th><?= $tab==='fast'?'<th>Qty Sold</th>':'' ?>
      <?php endif; ?>
    </tr></thead>
    <tbody>
    <?php
    $rows = match($tab) {
      'fast' => $fastMoving, 'slow' => $slowMoving, 'dead' => $deadStock,
      'low' => $lowStock, 'out' => $outStock, 'near' => $nearExpiry, 'expired' => $expired, 'aging' => $aging,
      default => $slowMoving
    };
    if (empty($rows)): ?>
      <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-300);">No records</td></tr>
    <?php else:
      foreach ($rows as $r):
        if ($tab === 'near' || $tab === 'expired'): ?>
      <tr><td><?= htmlspecialchars($r['name']) ?></td><td><code><?= htmlspecialchars($r['batch_number']) ?></code></td><td><?= number_format($r['quantity']) ?></td><td><?= date('M j, Y', strtotime($r['expiry_date'])) ?></td><td><?= currency($r['value']) ?></td></tr>
        <?php elseif ($tab === 'aging'): ?>
      <tr><td><?= htmlspecialchars($r['bucket']) ?></td><td><?= number_format($r['units']) ?></td><td><?= currency($r['value']) ?></td></tr>
        <?php elseif ($tab === 'out'): ?>
      <tr><td><?= htmlspecialchars($r['name']) ?></td></tr>
        <?php elseif ($tab === 'fast'): ?>
      <tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= number_format($r['current_stock']) ?></td><td><?= currency($r['purchase_cost']) ?></td><td><?= number_format($r['qty_sold']) ?></td></tr>
        <?php elseif ($tab === 'low'): ?>
      <tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= number_format($r['stock']) ?></td><td><?= number_format($r['reorder_level']) ?></td><td><?= currency($r['value']) ?></td></tr>
        <?php else: ?>
      <tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= number_format($r['stock'] ?? 0) ?></td><td><?= currency($r['value'] ?? $r['cost_value'] ?? 0) ?></td></tr>
        <?php endif;
      endforeach;
    endif; ?>
    </tbody>
  </table></div>
</div>
</div></div>
<?php renderFooter(); ?>
