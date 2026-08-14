<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';

$pdo = getDB();
extract(reportInit());
$cur = getSetting('currency', 'ETB');

$kpis     = reportOverviewKpis($pdo, $dates, $filters);
$insights = reportInsights($pdo, $dates, $filters);
$daily    = reportDailyRevenue($pdo, $dates, $filters);
$payData  = reportPaymentBreakdown($pdo, $dates, $filters);
$catData  = reportCategoryPerformance($pdo, $dates, $filters);
$topMeds  = reportTopProducts($pdo, $dates, $filters, 'qty', 15);

$revenue      = $kpis['revenue']['current'];
$grossProfit  = $kpis['gross_profit']['current'];
$cogs         = $kpis['cogs']['current'];
$saleCount    = (int)$kpis['total_orders']['current'];
$marginPct    = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;
$avgSale      = $kpis['avg_order_value']['current'];
$purchaseTotal= $kpis['purchase_cost']['current'];
$stockCost    = $kpis['inventory_value']['current'];
$stockRetail  = $kpis['inventory_retail'];

$from = $dates['from'];
$to   = $dates['to'];

$dailyRows = $pdo->prepare("
    SELECT d.day, d.txn, d.rev, d.disc, COALESCE(u.units, 0) AS units
    FROM (
      SELECT date(created_at) AS day, COUNT(*) AS txn,
             COALESCE(SUM(total_amount - discount), 0) AS rev,
             COALESCE(SUM(discount), 0) AS disc
      FROM sales WHERE date(created_at) BETWEEN ? AND ?
      GROUP BY day
    ) d
    LEFT JOIN (
      SELECT date(s.created_at) AS day, SUM(si.quantity) AS units
      FROM sale_items si JOIN sales s ON s.id = si.sale_id
      WHERE date(s.created_at) BETWEEN ? AND ?
      GROUP BY day
    ) u ON u.day = d.day
    ORDER BY d.day DESC
");
$dailyRows->execute([$from, $to, $from, $to]);
$dailyRows = $dailyRows->fetchAll();

$expiryBatches = $pdo->query("
    SELECT b.batch_number, b.expiry_date, b.quantity, b.purchase_price,
           m.name, (b.quantity * b.purchase_price) AS value
    FROM batches b JOIN medicines m ON m.id = b.medicine_id
    WHERE b.expiry_date <= date('now', '+60 days') AND b.quantity > 0
      AND b.expiry_date < '9000-01-01'
    ORDER BY b.expiry_date ASC LIMIT 20
")->fetchAll();

$lowMeds = $pdo->query("
    SELECT m.name, m.reorder_level, COALESCE(SUM(b.quantity), 0) AS stock
    FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id
    GROUP BY m.id HAVING stock <= m.reorder_level
    ORDER BY stock ASC, m.name ASC LIMIT 15
")->fetchAll();

$slowMovers = $pdo->prepare("
    SELECT m.name, COALESCE(SUM(b.quantity), 0) AS stock,
           COALESCE(SUM(b.quantity * b.purchase_price), 0) AS cost_value
    FROM medicines m JOIN batches b ON b.medicine_id = m.id AND b.quantity > 0
    WHERE m.id NOT IN (
      SELECT DISTINCT si.medicine_id FROM sale_items si JOIN sales s ON s.id = si.sale_id
      WHERE date(s.created_at) BETWEEN ? AND ?
    ) GROUP BY m.id ORDER BY cost_value DESC LIMIT 10
");
$slowMovers->execute([$from, $to]);
$slowData = $slowMovers->fetchAll();

$topCust = $pdo->prepare("
    SELECT customer_name, COUNT(*) AS visits, SUM(total_amount - discount) AS spent
    FROM sales WHERE date(created_at) BETWEEN ? AND ?
      AND customer_name IS NOT NULL AND TRIM(customer_name) != ''
    GROUP BY customer_name ORDER BY spent DESC LIMIT 10
");
$topCust->execute([$from, $to]);
$custData = $topCust->fetchAll();

$supPurch = $pdo->prepare("
    SELECT COALESCE(s.name, 'Unknown') AS supplier, COUNT(p.id) AS orders,
           COALESCE(SUM(p.total_amount), 0) AS total
    FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id
    WHERE date(p.created_at) BETWEEN ? AND ?
    GROUP BY p.supplier_id ORDER BY total DESC
");
$supPurch->execute([$from, $to]);
$supData = $supPurch->fetchAll();

$chartDailyLabels = array_map(fn($r) => date('M j', strtotime($r['day'])), $daily);
$chartDailyRevenue = array_map(fn($r) => (float)$r['revenue'], $daily);
$chartCatLabels = array_column($catData, 'category');
$chartCatRevenue = array_map(fn($r) => (float)$r['revenue'], $catData);
$chartPayLabels = array_map(fn($r) => reportPaymentMethods()[$r['payment_method']] ?? ucfirst($r['payment_method']), $payData);
$chartPayAmounts = array_map(fn($r) => (float)$r['amount'], $payData);

renderHead('Reports', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Reports', 'Business intelligence overview'); ?>
<div class="page-body">

<?php renderReportNav('reports'); ?>
<?php renderReportFilters($dates, $filters, $options); ?>
<?php renderReportMeta('Overview Dashboard', $dates); ?>
<?php renderInsightsCard($insights); ?>

<!-- Primary KPIs -->
<div class="stats-grid kpi-grid">
  <?php
  renderKpiCard('Total Revenue', $kpis['revenue'], 'blue');
  renderKpiCard('Gross Profit', $kpis['gross_profit'], 'green');
  renderKpiCard('Net Profit', $kpis['net_profit'], 'green');
  renderKpiCard('Profit Margin', $kpis['profit_margin'], 'blue', '', true);
  renderKpiCard('Inventory Value', $kpis['inventory_value'], 'orange');
  renderKpiCard('Total Orders', $kpis['total_orders'], 'blue');
  renderKpiCard('Customers Served', $kpis['customers_served'], 'blue');
  renderKpiCard('Avg Order Value', $kpis['avg_order_value'], 'green');
  ?>
</div>

<!-- Secondary KPIs -->
<div class="stats-grid kpi-grid">
  <?php
  renderKpiCard('Outstanding Credit', $kpis['outstanding_credit'], 'orange');
  renderKpiCard('Credit Collected', $kpis['credit_collected'], 'green');
  renderKpiCard('Credit Collected Today', $kpis['credit_collected_today'], 'green');
  renderKpiCard('Overdue Credit', $kpis['overdue_credit'], 'red');
  renderKpiCard('Purchase Cost', $kpis['purchase_cost'], 'orange');
  renderKpiCard('Low Stock', $kpis['low_stock'], 'orange');
  renderKpiCard('Out of Stock', $kpis['out_of_stock'], 'red');
  renderKpiCard('Expiring Products', $kpis['expiring_products'], 'orange');
  renderKpiCard('Returned Products', $kpis['returned_products'], 'red');
  ?>
</div>

<?php if ($kpis['low_stock']['current'] > 0 || $kpis['out_of_stock']['current'] > 0): ?>
<div class="alert alert-warning mb-20">
  <i data-lucide="info"></i>
  <span><strong>Inventory alerts:</strong>
    <?= (int)$kpis['out_of_stock']['current'] ?> out of stock ·
    <?= (int)$kpis['low_stock']['current'] ?> at/below reorder ·
    <a href="report_inventory.php" style="color:inherit;font-weight:700;">View inventory reports →</a>
  </span>
</div>
<?php endif; ?>

<!-- Charts -->
<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Daily Revenue</span></div>
    <div class="chart-wrap"><canvas id="chartDailyRevenue" data-labels='<?= json_encode($chartDailyLabels) ?>' data-values='<?= json_encode($chartDailyRevenue) ?>'></canvas></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Payment Breakdown</span></div>
    <div class="chart-wrap chart-donut"><canvas id="chartPayments" data-labels='<?= json_encode($chartPayLabels) ?>' data-values='<?= json_encode($chartPayAmounts) ?>'></canvas></div>
  </div>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Category Performance</span></div>
    <div class="chart-wrap"><canvas id="chartCategories" data-labels='<?= json_encode($chartCatLabels) ?>' data-values='<?= json_encode($chartCatRevenue) ?>'></canvas></div>
  </div>
  <div class="card">
    <div class="card-header">
      <span class="card-title">Best Selling Products</span>
      <a href="report_products.php?<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm">View All →</a>
    </div>
    <div class="chart-wrap"><canvas id="chartTopProducts" data-labels='<?= json_encode(array_column(array_slice($topMeds, 0, 8), 'name')) ?>' data-values='<?= json_encode(array_map(fn($r) => (int)$r['qty_sold'], array_slice($topMeds, 0, 8))) ?>'></canvas></div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Period Sales Summary</span></div>
  <div class="report-summary-grid">
    <div><span class="report-k">Net revenue</span><span class="report-v" style="color:var(--accent2);"><?= currency($revenue) ?></span></div>
    <div><span class="report-k">COGS</span><span class="report-v"><?= currency($cogs) ?></span></div>
    <div><span class="report-k">Gross profit</span><span class="report-v" style="color:var(--accent2);"><?= currency($grossProfit) ?></span></div>
    <div><span class="report-k">Gross margin</span><span class="report-v"><?= number_format($marginPct, 1) ?>%</span></div>
    <div><span class="report-k">Net profit</span><span class="report-v"><?= currency($kpis['net_profit']['current']) ?></span></div>
    <div><span class="report-k">Operating expenses</span><span class="report-v"><?= currency($kpis['expenses']['current']) ?></span></div>
    <div><span class="report-k">Transactions</span><span class="report-v"><?= number_format($saleCount) ?></span></div>
    <div><span class="report-k">Stock value (cost)</span><span class="report-v"><?= currency($stockCost) ?></span></div>
    <div><span class="report-k">Stock value (retail)</span><span class="report-v"><?= currency($stockRetail) ?></span></div>
    <div><span class="report-k">Purchases (period)</span><span class="report-v"><?= currency($purchaseTotal) ?></span></div>
  </div>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Daily Sales Breakdown</span></div>
    <div class="table-wrap" style="max-height:380px;overflow-y:auto;">
      <table>
        <thead><tr><th>Date</th><th>Txns</th><th>Units</th><th>Revenue</th></tr></thead>
        <tbody>
        <?php if (empty($dailyRows)): ?>
          <tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-300);">No sales in this period</td></tr>
        <?php else: foreach ($dailyRows as $d): ?>
        <tr>
          <td><?= date('D, M j', strtotime($d['day'])) ?></td>
          <td style="text-align:center;"><?= (int)$d['txn'] ?></td>
          <td style="text-align:center;"><?= number_format((int)$d['units']) ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($d['rev']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Payment Methods</span></div>
    <?php if (empty($payData)): ?>
      <div class="empty-state"><i data-lucide="pie-chart"></i><p>No payment data</p></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:14px;">
      <?php $totalRev = $revenue ?: 1;
      foreach ($payData as $p):
        $pct = round((float)$p['amount'] / $totalRev * 100, 1);
        $lbl = reportPaymentMethods()[$p['payment_method']] ?? ucfirst($p['payment_method']);
      ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px;">
          <span style="font-weight:600;color:var(--text-100);"><?= htmlspecialchars($lbl) ?>
            <span class="badge badge-gray" style="font-size:10px;"><?= (int)$p['cnt'] ?> txns</span></span>
          <span style="color:var(--accent2);font-weight:700;"><?= $pct ?>%</span>
        </div>
        <div class="progress-bar"><div class="progress-fill green" style="width:<?= $pct ?>%;"></div></div>
        <div style="font-size:11px;color:var(--text-300);margin-top:3px;"><?= currency($p['amount']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header">
    <span class="card-title">Top Selling Medicines</span>
    <a href="report_products.php?<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm">Product Reports →</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Rank</th><th>Medicine</th><th>Qty Sold</th><th>Revenue</th><th>Profit</th><th></th></tr></thead>
      <tbody>
      <?php if (empty($topMeds)): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-300);">No sales data</td></tr>
      <?php else: foreach ($topMeds as $i => $m): ?>
      <tr>
        <td><span class="badge <?= $i < 3 ? 'badge-blue' : 'badge-gray' ?>">#<?= $i + 1 ?></span></td>
        <td><div style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></div>
          <div style="font-size:11px;color:var(--text-300);"><?= htmlspecialchars($m['category']) ?></div></td>
        <td><?= number_format($m['qty_sold']) ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency($m['revenue']) ?></td>
        <td style="font-weight:700;"><?= currency($m['gross_profit']) ?></td>
        <td><a href="report_products.php?med=<?= $m['id'] ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm">Details</a></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Sales by Category</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Category</th><th>Units</th><th>Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($catData as $c): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($c['category']) ?></td>
          <td><?= number_format($c['qty']) ?></td>
          <td style="color:var(--accent2);font-weight:700;"><?= currency($c['revenue']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <span class="card-title">Top Customers</span>
      <a href="report_customers.php?<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm">View All →</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Customer</th><th>Visits</th><th>Spent</th></tr></thead>
        <tbody>
        <?php foreach ($custData as $c): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($c['customer_name']) ?></td>
          <td><?= (int)$c['visits'] ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($c['spent']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Low Stock / Reorder</span>
      <a href="report_inventory.php" class="btn btn-ghost btn-sm">Inventory Reports</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Medicine</th><th>Stock</th><th>Reorder</th></tr></thead>
        <tbody>
        <?php foreach ($lowMeds as $m): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></td>
          <td><?= number_format($m['stock']) ?></td>
          <td><?= number_format($m['reorder_level']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Slow Movers</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Medicine</th><th>Stock</th><th>Cost Tied Up</th></tr></thead>
        <tbody>
        <?php foreach ($slowData as $m): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></td>
          <td><?= number_format($m['stock']) ?></td>
          <td style="color:var(--warning);"><?= currency($m['cost_value']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Expiry Risk</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Medicine</th><th>Batch</th><th>Qty</th><th>Expiry</th><th>Value</th></tr></thead>
      <tbody>
      <?php foreach ($expiryBatches as $b): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($b['name']) ?></td>
        <td><code><?= htmlspecialchars($b['batch_number']) ?></code></td>
        <td><?= number_format($b['quantity']) ?></td>
        <td><?= date('M j, Y', strtotime($b['expiry_date'])) ?></td>
        <td><?= currency($b['value']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Purchases by Supplier</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Supplier</th><th>Orders</th><th>Total</th></tr></thead>
      <tbody>
      <?php foreach ($supData as $s): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($s['supplier']) ?></td>
        <td><?= (int)$s['orders'] ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency($s['total']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

</div></div>
<?php renderFooter(); ?>
