<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';

$pdo = getDB();
extract(reportInit());
$kpis = reportOverviewKpis($pdo, $dates, $filters);
$from = $dates['from'];
$to   = $dates['to'];

$revenue = $kpis['revenue']['current'];
$cogs    = $kpis['cogs']['current'];
$gross   = $kpis['gross_profit']['current'];
$expenses= $kpis['expenses']['current'];
$net     = $kpis['net_profit']['current'];
$margin  = $revenue > 0 ? ($net / $revenue) * 100 : 0;

$expByCat = $pdo->prepare("
    SELECT category, SUM(amount) AS total, COUNT(*) AS cnt
    FROM operating_expenses WHERE expense_date BETWEEN ? AND ?
    GROUP BY category ORDER BY total DESC
");
$expByCat->execute([$from, $to]);
$expByCat = $expByCat->fetchAll();

$profitByProduct = reportTopProducts($pdo, $dates, $filters, 'profit', 25);
$profitByCat = $pdo->prepare("
    SELECT COALESCE(c.name,'Uncategorized') AS category,
           SUM(si.subtotal) AS revenue,
           SUM(si.quantity * b.purchase_price) AS cogs,
           SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS gross_profit
    FROM sale_items si JOIN sales s ON s.id = si.sale_id
    JOIN batches b ON b.id = si.batch_id
    JOIN medicines m ON m.id = si.medicine_id
    LEFT JOIN categories c ON c.id = m.category_id
    WHERE date(s.created_at) BETWEEN ? AND ?
    GROUP BY c.id ORDER BY gross_profit DESC
");
$profitByCat->execute([$from, $to]);
$profitByCat = $profitByCat->fetchAll();

$dailyProfit = $pdo->prepare("
    SELECT date(s.created_at) AS day,
           SUM(si.subtotal) AS revenue,
           SUM(si.quantity * b.purchase_price) AS cogs,
           SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS gross
    FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN batches b ON b.id = si.batch_id
    WHERE date(s.created_at) BETWEEN ? AND ?
    GROUP BY day ORDER BY day ASC
");
$dailyProfit->execute([$from, $to]);
$dailyProfit = $dailyProfit->fetchAll();

renderHead('Profit Reports', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Profit Reports', 'Revenue, COGS & net profit'); ?>
<div class="page-body">
<?php renderReportNav('report_profit'); ?>
<?php renderReportFilters($dates, $filters, $options); ?>
<?php renderReportMeta('Profit & Loss', $dates); ?>

<div class="stats-grid mb-20">
  <?php renderKpiCard('Revenue', $kpis['revenue'], 'blue'); ?>
  <?php renderKpiCard('Cost of Goods Sold', $kpis['cogs'], 'orange'); ?>
  <?php renderKpiCard('Gross Profit', $kpis['gross_profit'], 'green'); ?>
  <?php renderKpiCard('Operating Expenses', $kpis['expenses'], 'red'); ?>
  <?php renderKpiCard('Net Profit', $kpis['net_profit'], 'green'); ?>
  <?php renderKpiCard('Profit Margin', $kpis['profit_margin'], 'blue', '', true); ?>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Profit & Loss Summary</span></div>
  <div class="report-summary-grid">
    <div><span class="report-k">Revenue</span><span class="report-v" style="color:var(--accent2);"><?= currency($revenue) ?></span></div>
    <div><span class="report-k">Cost of Goods Sold</span><span class="report-v"><?= currency($cogs) ?></span></div>
    <div><span class="report-k">Gross Profit</span><span class="report-v"><?= currency($gross) ?></span></div>
    <div><span class="report-k">Gross Margin</span><span class="report-v"><?= $revenue > 0 ? number_format(($gross/$revenue)*100,1) : 0 ?>%</span></div>
    <div><span class="report-k">Operating Expenses</span><span class="report-v" style="color:var(--danger);"><?= currency($expenses) ?></span></div>
    <div><span class="report-k">Net Profit</span><span class="report-v" style="color:<?= $net >= 0 ? 'var(--accent2)' : 'var(--danger)' ?>;"><?= currency($net) ?></span></div>
    <div><span class="report-k">Net Margin</span><span class="report-v"><?= number_format($margin, 1) ?>%</span></div>
  </div>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Profit Trend</span></div>
    <div class="chart-wrap"><canvas id="chartProfit" data-labels='<?= json_encode(array_map(fn($r)=>date('M j',strtotime($r['day'])), $dailyProfit)) ?>' data-values='<?= json_encode(array_map(fn($r)=>(float)$r['gross'], $dailyProfit)) ?>'></canvas></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Revenue vs COGS</span></div>
    <div class="chart-wrap"><canvas id="chartCompare" data-labels='<?= json_encode(array_map(fn($r)=>date('M j',strtotime($r['day'])), $dailyProfit)) ?>'
      data-revenue='<?= json_encode(array_map(fn($r)=>(float)$r['revenue'], $dailyProfit)) ?>'
      data-units='<?= json_encode(array_map(fn($r)=>(float)$r['cogs'], $dailyProfit)) ?>'></canvas></div>
  </div>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Profit by Category</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Category</th><th>Revenue</th><th>COGS</th><th>Gross Profit</th></tr></thead>
      <tbody>
      <?php foreach ($profitByCat as $c): ?>
      <tr><td><?= htmlspecialchars($c['category']) ?></td><td><?= currency($c['revenue']) ?></td><td><?= currency($c['cogs']) ?></td><td style="font-weight:700;color:var(--accent2);"><?= currency($c['gross_profit']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Operating Expenses</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Category</th><th>Count</th><th>Amount</th></tr></thead>
      <tbody>
      <?php foreach ($expByCat as $e): ?>
      <tr><td><?= htmlspecialchars($e['category']) ?></td><td><?= (int)$e['cnt'] ?></td><td style="color:var(--danger);"><?= currency($e['total']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (empty($expByCat)): ?><tr><td colspan="3" style="text-align:center;padding:20px;color:var(--text-300);">No expenses recorded</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Most Profitable Products</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Product</th><th>Revenue</th><th>COGS</th><th>Gross Profit</th><th>Margin</th></tr></thead>
    <tbody>
    <?php foreach ($profitByProduct as $p):
      $m = (float)$p['revenue'] > 0 ? ((float)$p['gross_profit']/(float)$p['revenue'])*100 : 0;
    ?>
    <tr>
      <td style="font-weight:600;"><a href="report_products.php?med=<?= $p['id'] ?>&<?= reportQueryString($dates, $filters) ?>"><?= htmlspecialchars($p['name']) ?></a></td>
      <td><?= currency($p['revenue']) ?></td>
      <td><?= currency($p['purchase_cost']) ?></td>
      <td style="color:var(--accent2);font-weight:700;"><?= currency($p['gross_profit']) ?></td>
      <td><?= number_format($m, 1) ?>%</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
</div></div>
<?php renderFooter(); ?>
