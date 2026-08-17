<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';

$pdo = getDB();
extract(reportInit());
$from = $dates['from'];
$to   = $dates['to'];

$summary    = reportSalesSummary($pdo, $dates, $filters);
$trend      = [];
foreach (['daily', 'weekly', 'monthly', 'yearly'] as $v) {
    $trend[$v] = reportSalesTrend($pdo, $dates, $filters, $v);
}
$daily      = reportSalesDaily($pdo, $dates, $filters);
$payments   = reportSalesPayments($pdo, $dates, $filters);
$customers  = reportSalesCustomers($pdo, $dates, $filters);
$cashiers   = reportSalesCashiers($pdo, $dates, $filters);
$categories = reportSalesCategories($pdo, $dates, $filters);
// Group category rows by product type (Medicine / Cosmetics / Equipment / Other) with per-type totals.
$catGroups = [];
$catGrand  = ['transactions' => 0, 'units' => 0, 'revenue' => 0, 'profit' => 0];
foreach ($categories as $cat) {
    $catGroups[$cat['type']]['label'] = $cat['type_label'];
    $catGroups[$cat['type']]['rows'][] = $cat;
    foreach ($catGrand as $k => $v) $catGrand[$k] += $cat[$k];
}
$returns    = reportSalesReturns($pdo, $dates, $filters);

// Sales history pagination
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$historyCount = reportSalesHistoryCount($pdo, $dates, $filters);
$totalPages = max(1, (int)ceil($historyCount / $perPage));
if ($page > $totalPages) $page = $totalPages;
$history = reportSalesHistory($pdo, $dates, $filters, $perPage, ($page - 1) * $perPage);

// Trend chart datasets (net sales = total_amount - discount)
$trendLabels = [];
$trendValues = [];
$trendFormat = [
    'daily'   => fn($l) => date('M j', strtotime($l)),
    'weekly'  => fn($l) => reportWeeklyLabel($l),
    'monthly' => fn($l) => date('M Y', strtotime($l . '-01')),
    'yearly'  => fn($l) => $l,
];
foreach (['daily', 'weekly', 'monthly', 'yearly'] as $v) {
    $trendLabels[$v] = array_map($trendFormat[$v], array_column($trend[$v], 'label'));
    $trendValues[$v] = array_map(fn($r) => (float)$r['revenue'], $trend[$v]);
}

$paymentsTotal = array_sum(array_column($payments, 'amount'));
$currencySuffix = ' ' . getSetting('currency', 'ETB');
$methods = posPaymentMethods();

renderHead('Sales Report', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Sales Report', 'What we sold, when, how and to whom'); ?>
<div class="page-body">

<?php renderReportNav('report_sales', $dates, $filters); ?>
<?php renderReportFilters($dates, $filters, $options, '', reportSalesDatePresets(), true, false, 'sales_report'); ?>
<?php renderReportMeta('Sales Report', $dates); ?>

<!-- Main Sales Summary -->
<div class="stats-grid kpi-grid">
  <?php
  $salesKpis = [
      ['Total Revenue',       (float)$summary['revenue'],       'blue',   $currencySuffix],
      ['Total Transactions',  (float)$summary['transactions'],  'green',  ''],
      ['Total Units Sold',    (float)$summary['units'],         'blue',   ''],
      ['Average Sale Value',  (float)$summary['avg_sale'],      'green',  $currencySuffix],
      ['Total Discount',      (float)$summary['discount'],      'orange', $currencySuffix],
      ['Total Tax',           (float)$summary['tax'],           'blue',   $currencySuffix],
      ['Total Returns',       (float)$summary['returns'],       'red',    $currencySuffix],
      ['Net Sales',           (float)$summary['net'],           'green',  $currencySuffix],
  ];
  foreach ($salesKpis as [$lbl, $val, $color, $suffix]) {
      renderKpiCard($lbl, ['current' => $val, 'previous' => 0, 'change' => null, 'up' => true, 'good' => true, 'dir' => 'up'], $color, $suffix);
  }
  ?>
</div>

<!-- Sales Trend -->
<div class="card mb-20">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <span class="card-title">Sales Trend</span>
    <div class="no-print" style="display:flex;gap:4px;flex-wrap:wrap;">
      <button type="button" class="btn btn-sm btn-primary" data-sales-trend-view="daily" onclick="switchSalesTrend('daily', this)">Daily</button>
      <button type="button" class="btn btn-sm btn-ghost" data-sales-trend-view="weekly" onclick="switchSalesTrend('weekly', this)">Weekly</button>
      <button type="button" class="btn btn-sm btn-ghost" data-sales-trend-view="monthly" onclick="switchSalesTrend('monthly', this)">Monthly</button>
      <button type="button" class="btn btn-sm btn-ghost" data-sales-trend-view="yearly" onclick="switchSalesTrend('yearly', this)">Yearly</button>
    </div>
  </div>
  <div class="chart-wrap" style="height:300px;">
    <canvas id="chartSalesTrend"
      data-daily-labels='<?= json_encode($trendLabels['daily']) ?>'
      data-daily-values='<?= json_encode($trendValues['daily']) ?>'
      data-weekly-labels='<?= json_encode($trendLabels['weekly']) ?>'
      data-weekly-values='<?= json_encode($trendValues['weekly']) ?>'
      data-monthly-labels='<?= json_encode($trendLabels['monthly']) ?>'
      data-monthly-values='<?= json_encode($trendValues['monthly']) ?>'
      data-yearly-labels='<?= json_encode($trendLabels['yearly']) ?>'
      data-yearly-values='<?= json_encode($trendValues['yearly']) ?>'
    ></canvas>
  </div>
</div>

<!-- Daily Sales -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Daily Sales</span></div>
  <div class="table-wrap" style="max-height:420px;overflow-y:auto;">
    <table>
      <thead>
        <tr><th>Date</th><th>Transactions</th><th>Units Sold</th><th>Revenue</th><th>Discount</th><th>Tax</th><th>Net Sales</th></tr>
      </thead>
      <tbody>
      <?php if (empty($daily)): ?>
        <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-300);">No sales for selected filters</td></tr>
      <?php else: foreach ($daily as $d): ?>
        <tr>
          <td style="font-weight:600;white-space:nowrap;"><?= date('D, M j, Y', strtotime($d['day'])) ?></td>
          <td style="text-align:center;"><?= number_format((int)$d['transactions']) ?></td>
          <td style="text-align:center;"><?= number_format((int)$d['units']) ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($d['revenue']) ?></td>
          <td><?= currency($d['discount']) ?></td>
          <td><?= currency($d['tax']) ?></td>
          <td style="font-weight:700;"><?= currency($d['net']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Payment Report -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Payment Report</span></div>
  <?php if ($paymentsTotal <= 0): ?>
    <div class="empty-state"><i data-lucide="wallet"></i><p>No payment data for this period</p></div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:14px;">
    <?php foreach ($payments as $p):
      $barColor = $p['method'] === 'credit' ? 'red' : ($p['method'] === 'cash' ? 'green' : 'blue');
    ?>
    <div>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px;">
        <span style="font-weight:600;color:var(--text-100);"><?= htmlspecialchars($p['label']) ?>
          <span class="badge badge-gray" style="font-size:10px;"><?= number_format($p['transactions']) ?> txns</span></span>
        <span style="color:var(--accent2);font-weight:700;"><?= number_format($p['pct'], 1) ?>%</span>
      </div>
      <div class="progress-bar"><div class="progress-fill <?= $barColor ?>" style="width:<?= max(0.5, $p['pct']) ?>%;"></div></div>
      <div style="font-size:12px;color:var(--text-300);margin-top:3px;"><?= currency($p['amount']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Customer Sales -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Customer Sales</span></div>
  <div class="table-wrap" style="max-height:420px;overflow-y:auto;">
    <table>
      <thead>
        <tr><th>Customer</th><th>Transactions</th><th>Units</th><th>Total Spent</th><th>Credit Amount</th></tr>
      </thead>
      <tbody>
      <?php if (empty($customers)): ?>
        <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text-300);">No customer sales for selected filters</td></tr>
      <?php else: foreach ($customers as $c): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($c['label']) ?></td>
          <td style="text-align:center;"><?= number_format((int)$c['transactions']) ?></td>
          <td style="text-align:center;"><?= number_format((int)$c['units']) ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($c['spent']) ?></td>
          <td style="color:<?= (float)$c['credit_amount'] > 0 ? 'var(--warning)' : 'var(--text-300)' ?>;"><?= (float)$c['credit_amount'] > 0 ? currency($c['credit_amount']) : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Cashier Sales -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Cashier Sales</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Cashier</th><th>Transactions</th><th>Units Sold</th><th>Total Sales</th><th>Discounts</th><th>Net Sales</th></tr>
      </thead>
      <tbody>
      <?php if (empty($cashiers)): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-300);">No cashier sales for selected filters</td></tr>
      <?php else: foreach ($cashiers as $c): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($c['label']) ?></td>
          <td style="text-align:center;"><?= number_format((int)$c['transactions']) ?></td>
          <td style="text-align:center;"><?= number_format((int)$c['units']) ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($c['total_sales']) ?></td>
          <td><?= currency($c['discount']) ?></td>
          <td style="font-weight:700;"><?= currency($c['net']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Category Sales -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Category Sales</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Category</th><th>Transactions</th><th>Units Sold</th><th>Revenue</th><th>Profit</th></tr>
      </thead>
      <tbody>
      <?php if (empty($catGroups)): ?>
        <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text-300);">No sales in this range</td></tr>
      <?php else: ?>
      <?php foreach ($catGroups as $type => $grp): ?>
        <tr style="background:var(--bg-500);">
          <td colspan="5" style="font-weight:700;letter-spacing:0.3px;"><?= htmlspecialchars($grp['label']) ?></td>
        </tr>
        <?php
        $tTot = ['transactions' => 0, 'units' => 0, 'revenue' => 0, 'profit' => 0];
        foreach ($grp['rows'] as $cat):
            foreach ($tTot as $k => $v) $tTot[$k] += $cat[$k];
        ?>
        <tr>
          <td style="padding-left:28px;"><?= htmlspecialchars($cat['category']) ?></td>
          <td style="text-align:center;"><?= number_format($cat['transactions']) ?></td>
          <td style="text-align:center;"><?= number_format((int)$cat['units']) ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($cat['revenue']) ?></td>
          <td style="font-weight:700;color:<?= (float)$cat['profit'] >= 0 ? 'var(--accent2)' : 'var(--danger)' ?>;"><?= currency($cat['profit']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:var(--bg-600);">
          <td style="font-weight:700;"><?= htmlspecialchars($grp['label']) ?> Subtotal</td>
          <td style="text-align:center;font-weight:600;"><?= number_format($tTot['transactions']) ?></td>
          <td style="text-align:center;font-weight:600;"><?= number_format((int)$tTot['units']) ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($tTot['revenue']) ?></td>
          <td style="font-weight:700;color:<?= (float)$tTot['profit'] >= 0 ? 'var(--accent2)' : 'var(--danger)' ?>;"><?= currency($tTot['profit']) ?></td>
        </tr>
      <?php endforeach; ?>
        <tr style="background:var(--bg-500);">
          <td style="font-weight:800;">Total</td>
          <td style="text-align:center;font-weight:700;"><?= number_format($catGrand['transactions']) ?></td>
          <td style="text-align:center;font-weight:700;"><?= number_format((int)$catGrand['units']) ?></td>
          <td style="font-weight:800;color:var(--accent2);"><?= currency($catGrand['revenue']) ?></td>
          <td style="font-weight:800;color:<?= (float)$catGrand['profit'] >= 0 ? 'var(--accent2)' : 'var(--danger)' ?>;"><?= currency($catGrand['profit']) ?></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Returns -->
<div class="stats-grid mb-20" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
  <div class="stat-card red"><div class="stat-icon red"><i data-lucide="rotate-ccw"></i></div><div><div class="stat-label">Returned Transactions</div><div class="stat-value"><?= number_format($returns['transactions']) ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="package-x"></i></div><div><div class="stat-label">Returned Units</div><div class="stat-value"><?= number_format((int)$returns['units']) ?></div></div></div>
  <div class="stat-card red"><div class="stat-icon red"><i data-lucide="banknote"></i></div><div><div class="stat-label">Returned Amount</div><div class="stat-value" style="font-size:22px;"><?= currency($returns['amount']) ?></div></div></div>
</div>

<!-- Sales History -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Sales History (<?= number_format($historyCount) ?>)</span>
    <a href="pos.php" class="btn btn-primary"><i data-lucide="plus"></i> New Sale</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Items</th><th>Units</th><th>Total</th><th>Discount</th><th>Tax</th><th>Payment</th><th>Cashier</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (empty($history)): ?>
        <tr><td colspan="12" style="text-align:center;padding:40px;color:var(--text-300);">No sales for selected filters</td></tr>
      <?php else: foreach ($history as $s):
        $status = $s['payment_status'] ?? computePaymentStatus((float)$s['total_amount'] - (float)$s['discount'] + (float)$s['tax'], (float)$s['paid_amount'], $s['payment_method']);
        $payLbl = $methods[$s['payment_method']] ?? ucfirst($s['payment_method']);
      ?>
        <tr>
          <td style="font-size:12px;white-space:nowrap;"><?= date('M d, Y', strtotime($s['created_at'])) ?><br><small style="color:var(--text-300);"><?= date('H:i', strtotime($s['created_at'])) ?></small></td>
          <td><code><?= htmlspecialchars($s['invoice_number']) ?></code></td>
          <td>
            <?php if ($s['customer_id']): ?>
            <a href="customers.php?id=<?= (int)$s['customer_id'] ?>" style="color:inherit;font-weight:600;"><?= htmlspecialchars($s['customer_name']) ?></a>
            <?php else: ?>
            <?= htmlspecialchars($s['customer_name']) ?>
            <?php endif; ?>
          </td>
          <td style="text-align:center;"><?= number_format((int)$s['items']) ?></td>
          <td style="text-align:center;"><?= number_format((int)$s['units']) ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency((float)$s['total_amount'] - (float)$s['discount'] + (float)$s['tax']) ?></td>
          <td><?= currency($s['discount']) ?></td>
          <td><?= currency($s['tax']) ?></td>
          <td><span class="badge badge-gray"><?= htmlspecialchars($payLbl) ?></span></td>
          <td><?= htmlspecialchars($s['cashier']) ?></td>
          <td><span class="badge <?= paymentStatusBadge($status) ?>"><?= paymentStatusLabel($status) ?></span></td>
          <td><a href="sale_details.php?id=<?= (int)$s['id'] ?>" class="btn btn-ghost btn-sm">Details</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php renderPagination($page, $totalPages, 'report_sales.php?' . reportQueryString($dates, $filters)); ?>
</div>

</div></div>
<?php renderFooter(); ?>
