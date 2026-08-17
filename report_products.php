<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';

$pdo = getDB();
extract(reportInit());
$tab  = $_GET['tab'] ?? 'best';
$sort = $_GET['sort'] ?? 'qty';
$page = max(1, (int)($_GET['page'] ?? 1));
$medId = (int)($_GET['med'] ?? 0);
$q     = trim($_GET['q'] ?? '');
$compare = array_filter(array_map('intval', explode(',', $_GET['compare'] ?? '')));
$historyTab = $_GET['history_tab'] ?? 'purchases';
$perPage = 30;

$productDetail = null;
if ($medId) {
    $productDetail = reportProductDetail($pdo, $medId, $dates);
    $tab = 'detail';
} elseif ($q && !$medId) {
    $searchResults = reportSearchProducts($pdo, $q);
}

$products = reportTopProducts($pdo, $dates, $filters, $sort, 500);
$totalPages = max(1, (int)ceil(count($products) / $perPage));
$pageRows = array_slice($products, ($page - 1) * $perPage, $perPage);

$slowProducts = reportSlowMovingProducts($pdo, $dates, $filters);

$compareData = [];
if (count($compare) >= 2) {
    foreach ($compare as $cid) {
        $d = reportProductDetail($pdo, $cid, $dates);
        if ($d) $compareData[] = $d;
    }
    $tab = 'compare';
}

renderHead('Product Reports', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Product Reports', 'Performance, best sellers & comparison'); ?>
<div class="page-body">
<?php renderReportNav('report_products', $dates, $filters); ?>
<?php renderReportFilters($dates, $filters, $options); ?>

<!-- Main Tabs -->
<div class="report-view-tabs no-print mb-20">
  <a href="?tab=best&<?= reportQueryString($dates, $filters) ?>" class="btn btn-sm <?= $tab==='best'?'btn-primary':'btn-ghost' ?>">Best Selling</a>
  <a href="?tab=search&<?= reportQueryString($dates, $filters) ?>" class="btn btn-sm <?= $tab==='search'||$tab==='detail'?'btn-primary':'btn-ghost' ?>">Product Performance</a>
  <a href="?tab=compare&<?= reportQueryString($dates, $filters) ?>" class="btn btn-sm <?= $tab==='compare'?'btn-primary':'btn-ghost' ?>">Compare Products</a>
</div>

<?php if ($tab === 'best'): ?>
<?php renderReportMeta('Best Selling Products', $dates); ?>

<!-- Sort bar -->
<div class="report-view-tabs no-print mb-20">
  <?php foreach (['qty'=>'Units Sold','rev'=>'Revenue','profit'=>'Gross Profit','margin'=>'Profit Margin'] as $k=>$lbl): ?>
  <a href="?sort=<?= $k ?>&tab=best&<?= reportQueryString($dates, $filters) ?>" class="btn btn-sm <?= $sort===$k?'btn-primary':'btn-ghost' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<!-- Export buttons -->
<div class="no-print mb-20" style="display:flex;gap:8px;justify-content:flex-end;">
  <a href="report_export.php?format=csv&report=products&sort=<?= $sort ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm"><i data-lucide="file-spreadsheet"></i> CSV</a>
  <a href="report_export.php?format=excel&report=products&sort=<?= $sort ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm"><i data-lucide="file-spreadsheet"></i> Excel</a>
  <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()"><i data-lucide="printer"></i> Print</button>
</div>

<!-- Best Selling Table -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Best Selling Products</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Product</th><th>Category</th><th>Units Sold</th><th>Revenue</th><th>Cost</th><th>Profit</th><th>Profit %</th><th>Stock</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($pageRows as $i => $m): ?>
      <tr>
        <td><span class="badge badge-gray">#<?= ($page-1)*$perPage + $i + 1 ?></span></td>
        <td style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></td>
        <td><?= htmlspecialchars($m['category']) ?></td>
        <td><?= number_format($m['qty_sold']) ?></td>
        <td style="color:var(--accent2);font-weight:700;"><?= currency($m['revenue']) ?></td>
        <td><?= currency($m['purchase_cost']) ?></td>
        <td><?= currency($m['gross_profit']) ?></td>
        <td><?= number_format((float)$m['profit_margin'], 1) ?>%</td>
        <td><?= number_format($m['current_stock']) ?></td>
        <td><a href="?med=<?= $m['id'] ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm">Details</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php renderPagination($page, $totalPages, 'report_products.php?' . reportQueryString($dates, $filters, ['sort' => $sort, 'tab' => 'best'])); ?>
</div>

<!-- Slow Moving Products -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title"><i data-lucide="snail" style="width:16px;height:16px;"></i> Slow Moving Products</span></div>
  <?php if (empty($slowProducts)): ?>
  <p style="padding:20px;color:var(--text-300);">No slow moving products found.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Product</th><th>Category</th><th>Current Stock</th><th>Units Sold</th><th>Last Sale Date</th><th>Stock Value</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($slowProducts as $sp): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($sp['name']) ?></td>
        <td><?= htmlspecialchars($sp['category']) ?></td>
        <td><?= number_format($sp['current_stock']) ?></td>
        <td><?= number_format($sp['units_sold']) ?></td>
        <td><?= $sp['last_sale_date'] ? date('M j, Y', strtotime($sp['last_sale_date'])) : '<span style="color:var(--danger);">No sales</span>' ?></td>
        <td><?= currency($sp['stock_value']) ?></td>
        <td><a href="?med=<?= $sp['id'] ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm">Details</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'search' || $tab === 'detail'): ?>
<?php renderReportMeta('Product Performance', $dates); ?>

<!-- Search Form -->
<div class="card mb-20 no-print">
  <form method="GET" class="report-search-form">
    <?php foreach (reportQueryParams($dates, $filters, ['tab' => 'search']) as $k=>$v): ?>
    <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
    <?php endforeach; ?>
    <div class="search-bar" style="flex:1;"><i data-lucide="search"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by product name, generic name, brand, batch number, or barcode...">
    </div>
    <button type="submit" class="btn btn-primary">Search</button>
  </form>
</div>

<?php if ($tab === 'detail' && $productDetail):
  $m = $productDetail['medicine'];
  $p = $productDetail['perf'];
  $trend = $productDetail['trend'];
  $trendWeekly = $productDetail['trend_weekly'];
  $trendMonthly = $productDetail['trend_monthly'];
  $trendYearly = $productDetail['trend_yearly'];
  $batchPerf = $productDetail['batch_performance'];
  $expirySummary = $productDetail['expiry_summary'];
?>

<!-- Product Name Header -->
<div class="mb-20" style="display:flex;align-items:center;gap:12px;">
  <a href="?tab=search&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Back to Search</a>
  <h2 style="font-size:18px;font-weight:700;"><?= htmlspecialchars($m['name']) ?></h2>
  <?php if ($m['generic_name']): ?>
  <span class="badge badge-blue"><?= htmlspecialchars($m['generic_name']) ?></span>
  <?php endif; ?>
</div>

<!-- KPI Stats -->
<div class="stats-grid mb-20">
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="shopping-bag"></i></div><div><div class="stat-label">Units Sold</div><div class="stat-value"><?= number_format($p['qty_sold'] ?? 0) ?></div></div></div>
  <div class="stat-card green"><div class="stat-icon green"><i data-lucide="wallet"></i></div><div><div class="stat-label">Revenue</div><div class="stat-value"><?= currency($p['revenue'] ?? 0) ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="trending-up"></i></div><div><div class="stat-label">Gross Profit</div><div class="stat-value"><?= currency($productDetail['gross']) ?></div></div></div>
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="percent"></i></div><div><div class="stat-label">Profit Margin</div><div class="stat-value"><?= number_format($productDetail['margin'], 1) ?>%</div></div></div>
  <div class="stat-card green"><div class="stat-icon green"><i data-lucide="receipt"></i></div><div><div class="stat-label">Transactions</div><div class="stat-value"><?= number_format($p['num_sales'] ?? 0) ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="calendar"></i></div><div><div class="stat-label">Avg Units/Day</div><div class="stat-value"><?= number_format($productDetail['avg_daily'], 1) ?></div></div></div>
</div>

<!-- Product Information & Performance -->
<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title"><i data-lucide="info" style="width:16px;height:16px;"></i> Product Information</span></div>
    <div class="report-summary-grid">
      <div><span class="report-k">Brand Name</span><span class="report-v"><?= htmlspecialchars($m['name']) ?></span></div>
      <div><span class="report-k">Generic Name</span><span class="report-v"><?= htmlspecialchars($m['generic_name'] ?? '—') ?></span></div>
      <div><span class="report-k">Category</span><span class="report-v"><?= htmlspecialchars($m['category_name']) ?></span></div>
      <?php if ($productDetail['main_supplier']): ?>
      <div><span class="report-k">Supplier</span><span class="report-v"><?= htmlspecialchars($productDetail['main_supplier']['supplier_name']) ?></span></div>
      <?php endif; ?>
      <div><span class="report-k">Selling Price</span><span class="report-v"><?= currency($m['avg_sell_price']) ?></span></div>
      <div><span class="report-k">Purchase Cost</span><span class="report-v"><?= currency($m['avg_buy_price']) ?></span></div>
      <div><span class="report-k">Current Stock</span><span class="report-v"><?= number_format($m['current_stock']) ?></span></div>
      <div><span class="report-k">Number of Batches</span><span class="report-v"><?= number_format($productDetail['batch_count']) ?></span></div>
      <div><span class="report-k">Barcode</span><span class="report-v"><?= htmlspecialchars($m['barcode'] ?? '—') ?></span></div>
      <div><span class="report-k">SKU</span><span class="report-v"><?= htmlspecialchars($m['sku'] ?? '—') ?></span></div>
      <div><span class="report-k">Inventory Value</span><span class="report-v"><?= currency($m['inventory_value']) ?></span></div>
    </div>
    <!-- Expiry Information -->
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
      <div style="font-size:12px;font-weight:600;color:var(--text-200);margin-bottom:8px;">EXPIRY INFORMATION</div>
      <div class="report-summary-grid">
        <div><span class="report-k">Good Stock</span><span class="report-v" style="color:var(--accent2);"><?= number_format($expirySummary['good']) ?></span></div>
        <div><span class="report-k">Near Expiry (30d)</span><span class="report-v" style="color:var(--warning);"><?= number_format($expirySummary['near_expiry']) ?></span></div>
        <div><span class="report-k">Expired</span><span class="report-v" style="color:var(--danger);"><?= number_format($expirySummary['expired']) ?></span></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title"><i data-lucide="bar-chart" style="width:16px;height:16px;"></i> Product Performance</span></div>
    <div class="report-summary-grid">
      <div><span class="report-k">Units Sold</span><span class="report-v"><?= number_format($p['qty_sold'] ?? 0) ?></span></div>
      <div><span class="report-k">Revenue</span><span class="report-v" style="color:var(--accent2);"><?= currency($p['revenue'] ?? 0) ?></span></div>
      <div><span class="report-k">Purchase Cost</span><span class="report-v"><?= currency($p['purchase_cost'] ?? 0) ?></span></div>
      <div><span class="report-k">Gross Profit</span><span class="report-v"><?= currency($productDetail['gross']) ?></span></div>
      <div><span class="report-k">Profit Margin</span><span class="report-v"><?= number_format($productDetail['margin'], 1) ?>%</span></div>
      <div><span class="report-k">Transactions</span><span class="report-v"><?= number_format($p['num_sales'] ?? 0) ?></span></div>
      <div><span class="report-k">Avg Units/Day</span><span class="report-v"><?= number_format($productDetail['avg_daily'], 1) ?></span></div>
      <div><span class="report-k">Avg Monthly Sales</span><span class="report-v"><?= number_format($productDetail['avg_monthly'], 1) ?></span></div>
    </div>
  </div>
</div>

<!-- Product Sales Trend -->
<div class="card mb-20">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span class="card-title"><i data-lucide="trending-up" style="width:16px;height:16px;"></i> Product Sales Trend</span>
    <div class="no-print" style="display:flex;gap:4px;">
      <button type="button" class="btn btn-sm btn-primary" data-trend-view="daily" onclick="switchProductTrend('daily', this)">Daily</button>
      <button type="button" class="btn btn-sm btn-ghost" data-trend-view="weekly" onclick="switchProductTrend('weekly', this)">Weekly</button>
      <button type="button" class="btn btn-sm btn-ghost" data-trend-view="monthly" onclick="switchProductTrend('monthly', this)">Monthly</button>
      <button type="button" class="btn btn-sm btn-ghost" data-trend-view="yearly" onclick="switchProductTrend('yearly', this)">Yearly</button>
    </div>
  </div>
  <div class="chart-wrap" style="height:300px;">
    <canvas id="chartProductTrend"
      data-daily-labels='<?= json_encode(array_column($trend, 'day')) ?>'
      data-daily-units='<?= json_encode(array_map(fn($r)=>(float)$r['qty'], $trend)) ?>'
      data-daily-revenue='<?= json_encode(array_map(fn($r)=>(float)$r['revenue'], $trend)) ?>'
      data-daily-profit='<?= json_encode(array_map(fn($r)=>(float)$r['profit'], $trend)) ?>'
      data-weekly-labels='<?= json_encode(array_column($trendWeekly, 'period')) ?>'
      data-weekly-units='<?= json_encode(array_map(fn($r)=>(float)$r['qty'], $trendWeekly)) ?>'
      data-weekly-revenue='<?= json_encode(array_map(fn($r)=>(float)$r['revenue'], $trendWeekly)) ?>'
      data-weekly-profit='<?= json_encode(array_map(fn($r)=>(float)$r['profit'], $trendWeekly)) ?>'
      data-monthly-labels='<?= json_encode(array_column($trendMonthly, 'period')) ?>'
      data-monthly-units='<?= json_encode(array_map(fn($r)=>(float)$r['qty'], $trendMonthly)) ?>'
      data-monthly-revenue='<?= json_encode(array_map(fn($r)=>(float)$r['revenue'], $trendMonthly)) ?>'
      data-monthly-profit='<?= json_encode(array_map(fn($r)=>(float)$r['profit'], $trendMonthly)) ?>'
      data-yearly-labels='<?= json_encode(array_column($trendYearly, 'period')) ?>'
      data-yearly-units='<?= json_encode(array_map(fn($r)=>(float)$r['qty'], $trendYearly)) ?>'
      data-yearly-revenue='<?= json_encode(array_map(fn($r)=>(float)$r['revenue'], $trendYearly)) ?>'
      data-yearly-profit='<?= json_encode(array_map(fn($r)=>(float)$r['profit'], $trendYearly)) ?>'
    ></canvas>
  </div>
</div>

<!-- Batch Performance -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title"><i data-lucide="layers" style="width:16px;height:16px;"></i> Batch Performance</span></div>
  <?php if (empty($batchPerf)): ?>
  <p style="padding:20px;color:var(--text-300);">No batch data available.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Batch Number</th><th>Expiry Date</th><th>Qty Purchased</th><th>Qty Sold</th><th>Remaining</th><th>Sales Value</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($batchPerf as $b):
        $noExpiry = isNoExpiryDate($b['expiry_date'] ?? '');
        $days = $noExpiry ? 99999 : (int)((strtotime($b['expiry_date']) - time()) / 86400);
        $st = $noExpiry ? 'No expiry' : ($days < 0 ? 'Expired' : ($days <= 30 ? 'Near Expiry' : 'Good'));
        $stClass = $noExpiry ? 'badge-blue' : ($days < 0 ? 'badge-red' : ($days <= 30 ? 'badge-orange' : 'badge-green'));
      ?>
      <tr>
        <td><code><?= htmlspecialchars($b['batch_number']) ?></code></td>
        <td><?= formatExpiryDate($b['expiry_date']) ?></td>
        <td><?= number_format($b['qty_purchased']) ?></td>
        <td><?= number_format($b['qty_sold']) ?></td>
        <td><?= number_format($b['remaining']) ?></td>
        <td><?= currency($b['sales_value']) ?></td>
        <td><span class="badge <?= $stClass ?>"><?= $st ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Product History -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title"><i data-lucide="clock" style="width:16px;height:16px;"></i> Product History</span></div>
  <div class="report-view-tabs no-print" style="padding:0 16px;">
    <?php foreach (['purchases'=>'Purchases','sales'=>'Sales','returns'=>'Returns','stock_changes'=>'Stock Changes','batch_changes'=>'Batch Changes','price_changes'=>'Price Changes'] as $k=>$lbl): ?>
    <a href="?med=<?= $medId ?>&history_tab=<?= $k ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-sm <?= $historyTab===$k?'btn-primary':'btn-ghost' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($historyTab === 'purchases'): ?>
  <div class="table-wrap">
    <?php if (empty($productDetail['purchases_history'])): ?>
    <p style="padding:20px;color:var(--text-300);">No purchase history found.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Date</th><th>Invoice</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>Supplier</th></tr></thead>
      <tbody>
      <?php foreach ($productDetail['purchases_history'] as $ph): ?>
      <tr>
        <td><?= $ph['purchase_date'] ? date('M j, Y', strtotime($ph['purchase_date'])) : '—' ?></td>
        <td><code><?= htmlspecialchars($ph['purchase_number'] ?? '—') ?></code></td>
        <td><?= number_format($ph['quantity']) ?></td>
        <td><?= currency($ph['purchase_price']) ?></td>
        <td><?= currency($ph['quantity'] * $ph['purchase_price']) ?></td>
        <td><?= htmlspecialchars($ph['supplier_name']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php elseif ($historyTab === 'sales'): ?>
  <div class="table-wrap">
    <?php if (empty($productDetail['sales_history'])): ?>
    <p style="padding:20px;color:var(--text-300);">No sales history found.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Date</th><th>Invoice</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>Customer</th></tr></thead>
      <tbody>
      <?php foreach ($productDetail['sales_history'] as $sh): ?>
      <tr>
        <td><?= date('M j, Y', strtotime($sh['sale_date'])) ?></td>
        <td><code><?= htmlspecialchars($sh['invoice_number']) ?></code></td>
        <td><?= number_format($sh['quantity']) ?></td>
        <td><?= currency($sh['unit_price']) ?></td>
        <td><?= currency($sh['subtotal']) ?></td>
        <td><?= htmlspecialchars($sh['customer_name'] ?? 'Walk-in') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php elseif ($historyTab === 'returns'): ?>
  <div class="table-wrap">
    <?php if (empty($productDetail['returns_history'])): ?>
    <p style="padding:20px;color:var(--text-300);">No returns found.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Date</th><th>Invoice</th><th>Qty</th><th>Amount</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($productDetail['returns_history'] as $rh): ?>
      <tr>
        <td><?= date('M j, Y', strtotime($rh['created_at'])) ?></td>
        <td><code><?= htmlspecialchars($rh['invoice_number'] ?? '—') ?></code></td>
        <td><?= number_format($rh['quantity']) ?></td>
        <td><?= currency($rh['amount']) ?></td>
        <td><?= htmlspecialchars($rh['reason'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php elseif ($historyTab === 'stock_changes'): ?>
  <div class="table-wrap">
    <?php if (empty($productDetail['stock_changes'])): ?>
    <p style="padding:20px;color:var(--text-300);">No stock changes found.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Date</th><th>Type</th><th>Quantity</th><th>Reference</th></tr></thead>
      <tbody>
      <?php foreach ($productDetail['stock_changes'] as $sc):
        $typeColor = $sc['type'] === 'Purchase' ? 'var(--accent2)' : ($sc['type'] === 'Return' ? 'var(--warning)' : 'var(--danger)');
        $typeIcon = $sc['type'] === 'Purchase' ? 'arrow-down' : ($sc['type'] === 'Return' ? 'rotate-ccw' : 'arrow-up');
      ?>
      <tr>
        <td><?= date('M j, Y', strtotime($sc['date'])) ?></td>
        <td><span style="color:<?= $typeColor ?>;font-weight:600;"><i data-lucide="<?= $typeIcon ?>" style="width:14px;height:14px;display:inline;"></i> <?= $sc['type'] ?></span></td>
        <td><?= $sc['type'] === 'Sale' ? '-' : '+' ?><?= number_format($sc['quantity']) ?></td>
        <td><code><?= htmlspecialchars($sc['reference'] ?? '—') ?></code></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php elseif ($historyTab === 'batch_changes'): ?>
  <div class="table-wrap">
    <?php if (empty($productDetail['batch_changes'])): ?>
    <p style="padding:20px;color:var(--text-300);">No batch changes found.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Date</th><th>Batch Number</th><th>Expiry</th><th>Qty Purchased</th><th>Supplier</th><th>Reference</th></tr></thead>
      <tbody>
      <?php foreach ($productDetail['batch_changes'] as $bc): ?>
      <tr>
        <td><?= date('M j, Y', strtotime($bc['date'])) ?></td>
        <td><code><?= htmlspecialchars($bc['batch_number']) ?></code></td>
        <td><?= formatExpiryDate($bc['expiry_date']) ?></td>
        <td><?= number_format($bc['qty_purchased']) ?></td>
        <td><?= htmlspecialchars($bc['supplier_name'] ?? '—') ?></td>
        <td><code><?= htmlspecialchars($bc['reference'] ?? '—') ?></code></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php elseif ($historyTab === 'price_changes'): ?>
  <div class="table-wrap">
    <?php if (empty($productDetail['price_history'])): ?>
    <p style="padding:20px;color:var(--text-300);">No price history found.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Date</th><th>Batch Number</th><th>Purchase Price</th><th>Selling Price</th><th>Supplier</th></tr></thead>
      <tbody>
      <?php foreach ($productDetail['price_history'] as $prh): ?>
      <tr>
        <td><?= date('M j, Y', strtotime($prh['created_at'])) ?></td>
        <td><code><?= htmlspecialchars($prh['batch_number']) ?></code></td>
        <td><?= currency($prh['purchase_price']) ?></td>
        <td><?= currency($prh['selling_price']) ?></td>
        <td><?= htmlspecialchars($prh['supplier_name'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Export buttons for product detail -->
<div class="no-print mb-20" style="display:flex;gap:8px;justify-content:flex-end;">
  <a href="report_export.php?format=csv&report=product_detail&med=<?= $medId ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm"><i data-lucide="file-spreadsheet"></i> CSV</a>
  <a href="report_export.php?format=excel&report=product_detail&med=<?= $medId ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm"><i data-lucide="file-spreadsheet"></i> Excel</a>
  <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()"><i data-lucide="printer"></i> Print</button>
</div>

<?php elseif (!empty($searchResults)): ?>
<!-- Search Results -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Search Results for "<?= htmlspecialchars($q) ?>"</span></div>
  <?php if (empty($searchResults)): ?>
  <p style="padding:20px;color:var(--text-300);">No products found matching your search.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Product</th><th>Generic Name</th><th>Category</th><th>Barcode</th><th>Stock</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($searchResults as $sr): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($sr['name']) ?></td>
        <td><?= htmlspecialchars($sr['generic_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($sr['category'] ?? '—') ?></td>
        <td><?= htmlspecialchars($sr['barcode'] ?? '—') ?></td>
        <td><?= number_format($sr['current_stock'] ?? 0) ?></td>
        <td><a href="?med=<?= $sr['id'] ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-primary btn-sm">View Report</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- Empty state -->
<div class="card mb-20">
  <div style="padding:40px;text-align:center;">
    <i data-lucide="search" style="width:48px;height:48px;color:var(--text-300);margin-bottom:12px;"></i>
    <p style="font-size:15px;color:var(--text-300);">Search for a product to view its detailed performance report.</p>
    <p style="font-size:13px;color:var(--text-300);margin-top:4px;">Search by product name, generic name, brand, batch number, or barcode.</p>
  </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'compare'): ?>
<?php renderReportMeta('Product Comparison', $dates); ?>

<!-- Compare Form -->
<div class="card mb-20 no-print">
  <form method="GET">
    <?php foreach (reportQueryParams($dates, $filters, ['tab' => 'compare']) as $k=>$v): ?>
    <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
    <?php endforeach; ?>
    <div class="form-group"><label>Select 2–5 products to compare (hold Ctrl/Cmd)</label>
      <select name="compare[]" multiple size="6" style="min-height:120px;">
        <?php foreach ($options['products'] as $p): ?>
        <option value="<?= $p['id'] ?>" <?= in_array((int)$p['id'], $compare) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary"><i data-lucide="layers"></i> Compare</button>
  </form>
</div>

<?php if (count($compareData) >= 2): ?>
<!-- Export buttons -->
<div class="no-print mb-20" style="display:flex;gap:8px;justify-content:flex-end;">
  <a href="report_export.php?format=csv&report=compare&compare=<?= implode(',', $compare) ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm"><i data-lucide="file-spreadsheet"></i> CSV</a>
  <a href="report_export.php?format=excel&report=compare&compare=<?= implode(',', $compare) ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm"><i data-lucide="file-spreadsheet"></i> Excel</a>
  <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()"><i data-lucide="printer"></i> Print</button>
</div>

<!-- Comparison Chart -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Comparison Chart</span></div>
  <div class="chart-wrap" style="height:300px;">
    <canvas id="chartCompare"
      data-labels='<?= json_encode(array_map(fn($d)=>$d['medicine']['name'], $compareData)) ?>'
      data-units='<?= json_encode(array_map(fn($d)=>(float)($d['perf']['qty_sold']??0), $compareData)) ?>'
      data-revenue='<?= json_encode(array_map(fn($d)=>(float)($d['perf']['revenue']??0), $compareData)) ?>'
      data-profit='<?= json_encode(array_map(fn($d)=>(float)$d['gross'], $compareData)) ?>'
    ></canvas>
  </div>
</div>

<!-- Comparison Table -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Comparison Details</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Product</th><th>Units Sold</th><th>Revenue</th><th>Cost</th><th>Profit</th><th>Profit Margin</th><th>Current Stock</th></tr>
      </thead>
      <tbody>
      <?php foreach ($compareData as $d): $m=$d['medicine']; $p=$d['perf']; ?>
      <tr>
        <td style="font-weight:600;"><a href="?med=<?= $m['id'] ?>&<?= reportQueryString($dates, $filters) ?>"><?= htmlspecialchars($m['name']) ?></a></td>
        <td><?= number_format($p['qty_sold']??0) ?></td>
        <td style="color:var(--accent2);font-weight:700;"><?= currency($p['revenue']??0) ?></td>
        <td><?= currency($p['purchase_cost']??0) ?></td>
        <td><?= currency($d['gross']) ?></td>
        <td><?= number_format($d['margin'],1) ?>%</td>
        <td><?= number_format($m['current_stock']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<p style="color:var(--text-300);padding:40px;text-align:center;">Select at least 2 products to compare.</p>
<?php endif; ?>

<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
