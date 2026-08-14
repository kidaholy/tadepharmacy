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
$dayExpr = reportLocalDateExpr('s');

function salesGrouped(PDO $pdo, string $labelExpr, array $params, string $extra, string $joins, string $dayExpr): array {
    $stmt = $pdo->prepare("
        SELECT label, COUNT(*) AS orders, SUM(net) AS revenue FROM (
            SELECT DISTINCT s.id, $labelExpr AS label, (s.total_amount - s.discount) AS net
            FROM sales s $joins
            WHERE $dayExpr BETWEEN ? AND ? $extra
        ) t GROUP BY label ORDER BY revenue DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$reports = [];
switch ($view) {
    case 'weekly':
        $reports = salesGrouped($pdo, "strftime('%Y Week %W', datetime(s.created_at, '+3 hours'))", $params, $extra, $ctx['joins'], $dayExpr);
        $title = 'Weekly Sales';
        break;
    case 'monthly':
        $reports = salesGrouped($pdo, "strftime('%b %Y', datetime(s.created_at, '+3 hours'))", $params, $extra, $ctx['joins'], $dayExpr);
        $title = 'Monthly Sales';
        break;
    case 'yearly':
        $reports = salesGrouped($pdo, "strftime('%Y', datetime(s.created_at, '+3 hours'))", $params, $extra, $ctx['joins'], $dayExpr);
        $title = 'Yearly Sales';
        break;
    case 'hourly':
        $reports = salesGrouped($pdo, "strftime('%H:00', datetime(s.created_at, '+3 hours')) || ' – ' || strftime('%H:59', datetime(s.created_at, '+3 hours'))", $params, $extra, $ctx['joins'], $dayExpr);
        $title = 'Hourly Sales';
        break;
    case 'cashier':
        $reports = salesGrouped($pdo, "COALESCE(u.full_name, 'Unknown')", $params, $extra, $ctx['joins'] . "\nLEFT JOIN users u ON u.id = s.user_id", $dayExpr);
        $title = 'Sales by Cashier';
        break;
    case 'customer':
        $reports = salesGrouped(
            $pdo,
            "COALESCE(NULLIF(TRIM(s.customer_name), ''), cust.full_name, 'Unknown')",
            $params,
            $extra . " AND COALESCE(NULLIF(TRIM(s.customer_name), ''), cust.full_name) IS NOT NULL AND TRIM(COALESCE(NULLIF(TRIM(s.customer_name), ''), cust.full_name)) != ''",
            $ctx['joins'],
            $dayExpr
        );
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
        $itemCtx = reportItemFilterContext($filters, $from, $to);
        $stmt = $pdo->prepare("
            SELECT COALESCE(sup.name, 'Unknown') AS label, SUM(si.quantity) AS orders,
                   SUM(si.subtotal) AS revenue
            FROM sale_items si
            {$itemCtx['joins']}
            LEFT JOIN suppliers sup ON sup.id = b.supplier_id
            WHERE {$itemCtx['where']}
            GROUP BY b.supplier_id ORDER BY revenue DESC
        ");
        $stmt->execute($itemCtx['params']);
        $reports = $stmt->fetchAll();
        $title = 'Sales by Supplier';
        break;
    default:
        $reports = salesGrouped($pdo, $dayExpr, $params, $extra, $ctx['joins'], $dayExpr);
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
<?php renderReportNav('report_sales', $dates, $filters); ?>
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
