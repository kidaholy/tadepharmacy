<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';

$pdo = getDB();
extract(reportInit());
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$from = $dates['from'];
$to   = $dates['to'];
$ctx = reportBuildSalesContext($filters);
$extra = $ctx['where'] ? ' AND ' . implode(' AND ', $ctx['where']) : '';
$dayExpr = reportLocalDateExpr('s');

$stmt = $pdo->prepare("
    SELECT label AS customer_name, COUNT(*) AS visits, SUM(net) AS spent,
           SUM(paid) AS paid, AVG(net) AS avg_order,
           MIN(first_at) AS first_visit, MAX(last_at) AS last_visit
    FROM (
        SELECT DISTINCT s.id,
               COALESCE(NULLIF(TRIM(s.customer_name), ''), cust.full_name) AS label,
               (s.total_amount - s.discount) AS net,
               s.paid_amount AS paid,
               s.created_at AS first_at,
               s.created_at AS last_at
        FROM sales s
        {$ctx['joins']}
        WHERE $dayExpr BETWEEN ? AND ?
          $extra
          AND COALESCE(NULLIF(TRIM(s.customer_name), ''), cust.full_name) IS NOT NULL
          AND TRIM(COALESCE(NULLIF(TRIM(s.customer_name), ''), cust.full_name)) != ''
          AND COALESCE(NULLIF(TRIM(s.customer_name), ''), cust.full_name) != 'Walk-in Customer'
    ) t
    GROUP BY label
    ORDER BY spent DESC
");
$stmt->execute(array_merge([$from, $to], $ctx['params']));
$all = $stmt->fetchAll();
$totalPages = max(1, (int)ceil(count($all) / $perPage));
$customers = array_slice($all, ($page - 1) * $perPage, $perPage);

$totalCustomers = count($all);
$totalSpent = array_sum(array_column($all, 'spent'));
$avgSpent = $totalCustomers > 0 ? $totalSpent / $totalCustomers : 0;

$topChart = array_slice($all, 0, 10);

renderHead('Customer Reports', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Customer Reports', 'Customer analytics & spending'); ?>
<div class="page-body">
<?php renderReportNav('report_customers', $dates, $filters); ?>
<?php renderReportFilters($dates, $filters, $options); ?>
<?php renderReportMeta('Customer Analytics', $dates); ?>

<div class="stats-grid mb-20">
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="users"></i></div><div><div class="stat-label">Total Customers</div><div class="stat-value"><?= number_format($totalCustomers) ?></div></div></div>
  <div class="stat-card green"><div class="stat-icon green"><i data-lucide="wallet"></i></div><div><div class="stat-label">Total Spent</div><div class="stat-value"><?= number_format($totalSpent, 0) ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="receipt"></i></div><div><div class="stat-label">Avg Customer Value</div><div class="stat-value"><?= number_format($avgSpent, 0) ?></div></div></div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Top Customers by Spending</span></div>
  <div class="chart-wrap"><canvas id="chartTopProducts" data-labels='<?= json_encode(array_column($topChart, 'customer_name')) ?>' data-values='<?= json_encode(array_map(fn($r)=>(float)$r['spent'], $topChart)) ?>' data-label="Spent"></canvas></div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Customer List</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Customer</th><th>Visits</th><th>Total Spent</th><th>Avg Order</th><th>First Visit</th><th>Last Visit</th></tr></thead>
    <tbody>
    <?php foreach ($customers as $c): ?>
    <tr>
      <td style="font-weight:600;"><?= htmlspecialchars($c['customer_name']) ?></td>
      <td><?= (int)$c['visits'] ?></td>
      <td style="font-weight:700;color:var(--accent2);"><?= currency($c['spent']) ?></td>
      <td><?= currency($c['avg_order']) ?></td>
      <td><?= date('M j, Y', strtotime($c['first_visit'])) ?></td>
      <td><?= date('M j, Y', strtotime($c['last_visit'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($customers)): ?><tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-300);">No customer data</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php renderPagination($page, $totalPages, 'report_customers.php?' . reportQueryString($dates, $filters)); ?>
</div>
</div></div>
<?php renderFooter(); ?>
