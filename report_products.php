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
<?php renderReportNav('report_products'); ?>
<?php renderReportFilters($dates, $filters, $options); ?>

<div class="report-view-tabs no-print mb-20">
  <a href="?tab=best&<?= reportQueryString($dates, $filters) ?>" class="btn btn-sm <?= $tab==='best'?'btn-primary':'btn-ghost' ?>">Best Selling</a>
  <a href="?tab=search&<?= reportQueryString($dates, $filters) ?>" class="btn btn-sm <?= $tab==='search'?'btn-primary':'btn-ghost' ?>">Product Performance</a>
  <a href="?tab=compare&<?= reportQueryString($dates, $filters) ?>" class="btn btn-sm <?= $tab==='compare'?'btn-primary':'btn-ghost' ?>">Compare Products</a>
</div>

<?php if ($tab === 'search' || $tab === 'detail'): ?>
<div class="card mb-20 no-print">
  <form method="GET" class="report-search-form">
    <?php foreach (reportQueryParams($dates, $filters, ['tab' => 'search']) as $k=>$v): ?>
    <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
    <?php endforeach; ?>
    <div class="search-bar" style="flex:1;"><i data-lucide="search"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by name, barcode, generic name, SKU...">
    </div>
    <button type="submit" class="btn btn-primary">Search</button>
  </form>
</div>
<?php endif; ?>

<?php if ($tab === 'detail' && $productDetail):
  $m = $productDetail['medicine'];
  $p = $productDetail['perf'];
  $trend = $productDetail['trend'];
?>
<?php renderReportMeta('Product Performance: ' . $m['name'], $dates); ?>
<div class="stats-grid mb-20">
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="pill"></i></div><div><div class="stat-label">Qty Sold</div><div class="stat-value"><?= number_format($p['qty_sold'] ?? 0) ?></div></div></div>
  <div class="stat-card green"><div class="stat-icon green"><i data-lucide="wallet"></i></div><div><div class="stat-label">Revenue</div><div class="stat-value"><?= number_format($p['revenue'] ?? 0, 0) ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="trending-up"></i></div><div><div class="stat-label">Gross Profit</div><div class="stat-value"><?= number_format($productDetail['gross'], 0) ?></div></div></div>
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="percent"></i></div><div><div class="stat-label">Margin</div><div class="stat-value"><?= number_format($productDetail['margin'], 1) ?>%</div></div></div>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Product Information</span></div>
    <div class="report-summary-grid">
      <div><span class="report-k">Name</span><span class="report-v"><?= htmlspecialchars($m['name']) ?></span></div>
      <div><span class="report-k">Generic</span><span class="report-v"><?= htmlspecialchars($m['generic_name'] ?? '—') ?></span></div>
      <div><span class="report-k">Barcode</span><span class="report-v"><?= htmlspecialchars($m['barcode'] ?? '—') ?></span></div>
      <div><span class="report-k">SKU</span><span class="report-v"><?= htmlspecialchars($m['sku'] ?? '—') ?></span></div>
      <div><span class="report-k">Category</span><span class="report-v"><?= htmlspecialchars($m['category_name']) ?></span></div>
      <div><span class="report-k">Current Stock</span><span class="report-v"><?= number_format($m['current_stock']) ?></span></div>
      <div><span class="report-k">Avg Sell Price</span><span class="report-v"><?= currency($m['avg_sell_price']) ?></span></div>
      <div><span class="report-k">Avg Buy Price</span><span class="report-v"><?= currency($m['avg_buy_price']) ?></span></div>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Performance Metrics</span></div>
    <div class="report-summary-grid">
      <div><span class="report-k">Sales Count</span><span class="report-v"><?= number_format($p['num_sales'] ?? 0) ?></span></div>
      <div><span class="report-k">Customers</span><span class="report-v"><?= number_format($p['num_customers'] ?? 0) ?></span></div>
      <div><span class="report-k">Purchase Cost</span><span class="report-v"><?= currency($p['purchase_cost'] ?? 0) ?></span></div>
      <div><span class="report-k">Avg Monthly Sales</span><span class="report-v"><?= number_format($productDetail['avg_monthly'], 1) ?></span></div>
      <div><span class="report-k">Inventory Value</span><span class="report-v"><?= currency($m['inventory_value']) ?></span></div>
      <div><span class="report-k">Expired Stock</span><span class="report-v"><?= number_format($productDetail['expired_stock']) ?></span></div>
      <div><span class="report-k">Near Expiry</span><span class="report-v"><?= number_format($productDetail['near_expiry_stock']) ?></span></div>
      <?php if ($productDetail['main_supplier']): ?>
      <div><span class="report-k">Main Supplier</span><span class="report-v"><?= htmlspecialchars($productDetail['main_supplier']['supplier_name']) ?></span></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Sales Trend</span></div>
    <div class="chart-wrap"><canvas id="chartDailyRevenue" data-labels='<?= json_encode(array_column($trend,'day')) ?>' data-values='<?= json_encode(array_map(fn($r)=>(float)$r['qty'], $trend)) ?>' data-label="Units"></canvas></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Profit Trend</span></div>
    <div class="chart-wrap"><canvas id="chartProfit" data-labels='<?= json_encode(array_column($trend,'day')) ?>' data-values='<?= json_encode(array_map(fn($r)=>(float)$r['profit'], $trend)) ?>'></canvas></div>
  </div>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Batch Information</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Batch</th><th>Expiry</th><th>Qty</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($productDetail['batches'] as $b):
        $noExpiry = isNoExpiryDate($b['expiry_date'] ?? '');
        $days = $noExpiry ? 99999 : (int)((strtotime($b['expiry_date']) - time()) / 86400);
        $st = $noExpiry ? 'No expiry' : ($days < 0 ? 'Expired' : ($days <= 30 ? 'Near Expiry' : 'Good'));
      ?>
      <tr>
        <td><code><?= htmlspecialchars($b['batch_number']) ?></code></td>
        <td><?= formatExpiryDate($b['expiry_date']) ?></td>
        <td><?= number_format($b['quantity']) ?></td>
        <td><span class="badge badge-gray"><?= $st ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Payment Breakdown</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Method</th><th>Qty</th><th>Amount</th></tr></thead>
      <tbody>
      <?php foreach ($productDetail['payments'] as $pay): ?>
      <tr>
        <td><?= htmlspecialchars(reportPaymentMethods()[$pay['payment_method']] ?? ucfirst($pay['payment_method'])) ?></td>
        <td><?= number_format($pay['qty']) ?></td>
        <td><?= currency($pay['amount']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Top Customers</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Customer</th><th>Qty</th><th>Spent</th></tr></thead>
    <tbody>
    <?php foreach ($productDetail['top_customers'] as $c): ?>
    <tr><td><?= htmlspecialchars($c['customer_name']) ?></td><td><?= number_format($c['qty']) ?></td><td><?= currency($c['spent']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php elseif ($tab === 'compare'): ?>
<?php renderReportMeta('Product Comparison', $dates); ?>
<div class="card mb-20 no-print">
  <form method="GET">
    <?php foreach (reportQueryParams($dates, $filters, ['tab' => 'compare']) as $k=>$v): ?>
    <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
    <?php endforeach; ?>
    <div class="form-group"><label>Select products (hold Ctrl/Cmd)</label>
      <select name="compare[]" multiple size="6" style="min-height:120px;">
        <?php foreach ($options['products'] as $p): ?>
        <option value="<?= $p['id'] ?>" <?= in_array($p['id'], $compare) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Compare</button>
  </form>
</div>
<?php if (count($compareData) >= 2): ?>
<div class="card mb-20">
  <div class="chart-wrap"><canvas id="chartCompare" data-labels='<?= json_encode(array_map(fn($d)=>$d['medicine']['name'], $compareData)) ?>'
    data-units='<?= json_encode(array_map(fn($d)=>(float)($d['perf']['qty_sold']??0), $compareData)) ?>'
    data-revenue='<?= json_encode(array_map(fn($d)=>(float)($d['perf']['revenue']??0), $compareData)) ?>'
    data-profit='<?= json_encode(array_map(fn($d)=>(float)$d['gross'], $compareData)) ?>'></canvas></div>
</div>
<div class="table-wrap"><table>
  <thead><tr><th>Product</th><th>Units Sold</th><th>Revenue</th><th>Gross Profit</th><th>Margin</th><th>Stock</th><th>Customers</th></tr></thead>
  <tbody>
  <?php foreach ($compareData as $d): $m=$d['medicine']; $p=$d['perf']; ?>
  <tr>
    <td style="font-weight:600;"><a href="?med=<?= $m['id'] ?>&<?= reportQueryString($dates, $filters) ?>"><?= htmlspecialchars($m['name']) ?></a></td>
    <td><?= number_format($p['qty_sold']??0) ?></td>
    <td><?= currency($p['revenue']??0) ?></td>
    <td><?= currency($d['gross']) ?></td>
    <td><?= number_format($d['margin'],1) ?>%</td>
    <td><?= number_format($m['current_stock']) ?></td>
    <td><?= number_format($p['num_customers']??0) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php else: ?>
<p style="color:var(--text-300);padding:20px;">Select at least 2 products to compare.</p>
<?php endif; ?>

<?php elseif ($tab === 'search' && !empty($searchResults)): ?>
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Search Results</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Product</th><th>Generic</th><th>Barcode</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($searchResults as $sr): ?>
    <tr>
      <td><?= htmlspecialchars($sr['name']) ?></td>
      <td><?= htmlspecialchars($sr['generic_name'] ?? '—') ?></td>
      <td><?= htmlspecialchars($sr['barcode'] ?? '—') ?></td>
      <td><a href="?med=<?= $sr['id'] ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-primary btn-sm">View Report</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php else: ?>
<?php renderReportMeta('Best Selling Products', $dates); ?>
<div class="report-view-tabs no-print mb-20">
  <?php foreach (['qty'=>'Units Sold','rev'=>'Revenue','profit'=>'Gross Profit','net'=>'Net Profit'] as $k=>$lbl): ?>
  <a href="?sort=<?= $k ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-sm <?= $sort===$k?'btn-primary':'btn-ghost' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>
<div class="card mb-20">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Product</th><th>Category</th><th>Qty Sold</th><th>Revenue</th><th>Cost</th><th>Profit</th><th>Margin</th><th>Stock</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($pageRows as $i => $m):
        $margin = (float)$m['revenue'] > 0 ? ((float)$m['gross_profit'] / (float)$m['revenue']) * 100 : 0;
      ?>
      <tr>
        <td><span class="badge badge-gray">#<?= ($page-1)*$perPage + $i + 1 ?></span></td>
        <td style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></td>
        <td><?= htmlspecialchars($m['category']) ?></td>
        <td><?= number_format($m['qty_sold']) ?></td>
        <td style="color:var(--accent2);font-weight:700;"><?= currency($m['revenue']) ?></td>
        <td><?= currency($m['purchase_cost']) ?></td>
        <td><?= currency($m['gross_profit']) ?></td>
        <td><?= number_format($margin, 1) ?>%</td>
        <td><?= number_format($m['current_stock']) ?></td>
        <td><a href="?med=<?= $m['id'] ?>&<?= reportQueryString($dates, $filters) ?>" class="btn btn-ghost btn-sm">Details</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php renderPagination($page, $totalPages, 'report_products.php?' . reportQueryString($dates, $filters, ['sort' => $sort])); ?>
</div>
<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
