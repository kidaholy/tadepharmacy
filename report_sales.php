<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';

$pdo = getDB();
extract(reportInit());
$view = $_GET['view'] ?? 'daily';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$from = $dates['from'];
$to   = $dates['to'];
$ctx  = reportBuildSalesContext($filters);
$extra = $ctx['where'] ? ' AND ' . implode(' AND ', $ctx['where']) : '';
$params = array_merge([$from, $to], $ctx['params']);

function salesGrouped(PDO $pdo, string $groupExpr, string $labelExpr, array $params, string $extra, string $joins): array {
    $stmt = $pdo->prepare("
        SELECT label, COUNT(*) AS orders, SUM(net) AS revenue FROM (
            SELECT DISTINCT s.id, $labelExpr AS label, (s.total_amount - s.discount) AS net
            FROM sales s $joins
            WHERE date(s.created_at) BETWEEN ? AND ? $extra
        ) t GROUP BY label ORDER BY revenue DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$reports = [];
switch ($view) {
    case 'weekly':
        $reports = salesGrouped($pdo, "strftime('%Y-W%W', s.created_at)", "strftime('%Y Week %W', s.created_at)", $params, $extra, $ctx['joins']);
        $title = 'Weekly Sales';
        break;
    case 'monthly':
        $reports = salesGrouped($pdo, "strftime('%Y-%m', s.created_at)", "strftime('%b %Y', s.created_at)", $params, $extra, $ctx['joins']);
        $title = 'Monthly Sales';
        break;
    case 'yearly':
        $reports = salesGrouped($pdo, "strftime('%Y', s.created_at)", "strftime('%Y', s.created_at)", $params, $extra, $ctx['joins']);
        $title = 'Yearly Sales';
        break;
    case 'hourly':
        $reports = salesGrouped($pdo, "strftime('%H', s.created_at)", "strftime('%H:00', s.created_at) || ' – ' || strftime('%H:59', s.created_at)", $params, $extra, $ctx['joins']);
        $title = 'Hourly Sales';
        break;
    case 'cashier':
        $stmt = $pdo->prepare("
            SELECT COALESCE(u.full_name, 'Unknown') AS label, COUNT(DISTINCT s.id) AS orders,
                   COALESCE(SUM(s.total_amount - s.discount), 0) AS revenue
            FROM sales s LEFT JOIN users u ON u.id = s.user_id {$ctx['joins']}
            WHERE date(s.created_at) BETWEEN ? AND ? $extra
            GROUP BY s.user_id ORDER BY revenue DESC
        ");
        $stmt->execute($params);
        $reports = $stmt->fetchAll();
        $title = 'Sales by Cashier';
        break;
    case 'customer':
        $stmt = $pdo->prepare("
            SELECT s.customer_name AS label, COUNT(*) AS orders,
                   SUM(s.total_amount - s.discount) AS revenue
            FROM sales s {$ctx['joins']}
            WHERE date(s.created_at) BETWEEN ? AND ? $extra
              AND s.customer_name IS NOT NULL AND TRIM(s.customer_name) != ''
            GROUP BY s.customer_name ORDER BY revenue DESC LIMIT 100
        ");
        $stmt->execute($params);
        $reports = $stmt->fetchAll();
        $title = 'Sales by Customer';
        break;
    case 'product':
        $reports = reportTopProducts($pdo, $dates, $filters, 'rev', 100);
        $title = 'Sales by Product';
        break;
    case 'category':
        $cat = reportCategoryPerformance($pdo, $dates, $filters);
        $reports = array_map(fn($c) => ['label' => $c['category'], 'orders' => $c['qty'], 'revenue' => $c['revenue']], $cat);
        $title = 'Sales by Category';
        break;
    case 'supplier':
        $stmt = $pdo->prepare("
            SELECT COALESCE(sup.name, 'Unknown') AS label, SUM(si.quantity) AS orders,
                   SUM(si.subtotal) AS revenue
            FROM sale_items si JOIN sales s ON s.id = si.sale_id
            JOIN batches b ON b.id = si.batch_id
            LEFT JOIN suppliers sup ON sup.id = b.supplier_id
            WHERE date(s.created_at) BETWEEN ? AND ?
            GROUP BY b.supplier_id ORDER BY revenue DESC
        ");
        $stmt->execute([$from, $to]);
        $reports = $stmt->fetchAll();
        $title = 'Sales by Supplier';
        break;
    default:
        $reports = salesGrouped($pdo, 'date(s.created_at)', 'date(s.created_at)', $params, $extra, $ctx['joins']);
        $title = 'Daily Sales';
        $view = 'daily';
}

$totalPages = max(1, (int)ceil(count($reports) / $perPage));
$offset = ($page - 1) * $perPage;
$pageRows = array_slice($reports, $offset, $perPage);
$daily = reportDailyRevenue($pdo, $dates, $filters);

renderHead('Sales Reports', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Sales Reports', $title); ?>
<div class="page-body">
<?php renderReportNav('report_sales'); ?>
<?php renderReportFilters($dates, $filters, $options); ?>
<?php renderReportMeta($title, $dates); ?>

<div class="report-view-tabs no-print mb-20">
  <?php foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly','hourly'=>'Hourly','cashier'=>'Cashier','customer'=>'Customer','product'=>'Product','category'=>'Category','supplier'=>'Supplier'] as $k=>$lbl): ?>
  <a href="?view=<?= $k ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-sm <?= $view===$k?'btn-primary':'btn-ghost' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Sales Trend</span></div>
  <div class="chart-wrap"><canvas id="chartDailyRevenue" data-labels='<?= json_encode(array_map(fn($r)=>date('M j',strtotime($r['day'])), $daily)) ?>' data-values='<?= json_encode(array_map(fn($r)=>(float)$r['revenue'], $daily)) ?>'></canvas></div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title"><?= htmlspecialchars($title) ?></span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th><?= $view === 'product' ? 'Product' : 'Period / Name' ?></th>
          <th><?= in_array($view, ['product','category','supplier']) ? 'Units/Qty' : 'Orders' ?></th>
          <th>Revenue</th>
          <?php if ($view === 'product'): ?><th>Profit</th><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($pageRows)): ?>
        <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text-300);">No data for selected filters</td></tr>
      <?php else: foreach ($pageRows as $r):
        $label = $view === 'daily' ? date('D, M j, Y', strtotime($r['label'])) : ($r['label'] ?? $r['name'] ?? '—');
        $orders = $r['orders'] ?? $r['qty_sold'] ?? 0;
        $rev = $r['revenue'] ?? 0;
      ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($label) ?></td>
        <td><?= number_format($orders) ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency($rev) ?></td>
        <?php if ($view === 'product'): ?>
        <td><?= currency($r['gross_profit'] ?? 0) ?></td>
        <td><a href="report_products.php?med=<?= $r['id'] ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm">View</a></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php renderPagination($page, $totalPages, 'report_sales.php?' . reportQueryString($dates, $filters, ['view' => $view])); ?>
</div>
</div></div>
<?php renderFooter(); ?>
