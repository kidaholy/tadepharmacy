<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';

$pdo = getDB();
$dates = reportParseDateRange($_GET);
$f = reportInventoryFilters($_GET);
$options = reportFilterOptions($pdo);

// Filters shaped like the shared report filters so the report nav keeps the selection.
$navFilters = $f + ['customer' => '', 'cashier' => 0, 'payment_method' => '', 'sales_type' => '', 'branch' => 0];

$summary  = reportInventorySummary($pdo, $dates, $f);
$overview = reportInventoryOverview($pdo, $dates, $f);
$products = reportInventoryProducts($pdo, $dates, $f);
$expiryRows = reportInventoryExpiryRows($pdo, $dates, $f);

$productDetail = $f['product'] ? reportInventoryProductDetail($pdo, $f['product']) : null;

// ---- Stock status table (respects the active stock tab) ----
$stockTab = $f['stock'];
$stockRows = $products;
if ($stockTab === 'fast') {
    $stockRows = array_values(array_filter($products, fn($p) => (float)$p['qty_sold'] > 0));
    usort($stockRows, fn($a, $b) => $b['qty_sold'] <=> $a['qty_sold']);
} elseif ($stockTab === 'slow') {
    $stockRows = array_values(array_filter($products, fn($p) => (float)$p['stock'] > 0 && (float)$p['qty_sold'] == 0));
    usort($stockRows, fn($a, $b) => $b['retail_value'] <=> $a['retail_value']);
} elseif ($stockTab === 'dead') {
    $cutoff = date('Y-m-d', strtotime('-90 days'));
    $stockRows = array_values(array_filter($products, function ($p) use ($cutoff) {
        return (float)$p['stock'] > 0 && ($p['last_sale'] === null || substr((string)$p['last_sale'], 0, 10) < $cutoff);
    }));
    usort($stockRows, fn($a, $b) => $b['retail_value'] <=> $a['retail_value']);
} elseif (in_array($stockTab, ['in', 'low', 'out', 'over'], true)) {
    $stockRows = array_values(array_filter($products, fn($p) => inventoryStockStatus($p) === $stockTab));
    usort($stockRows, fn($a, $b) => strcasecmp($a['name'], $b['name']));
} else {
    usort($stockRows, fn($a, $b) => strcasecmp($a['name'], $b['name']));
}

// ---- Expiry table (respects the active expiry tab) ----
$expiryTab = $f['expiry'];
if ($expiryTab === 'expiring') {
    $expiryRows = array_values(array_filter($expiryRows, fn($r) => $r['status']['key'] === 'expiring'));
} elseif ($expiryTab === 'expired') {
    $expiryRows = array_values(array_filter($expiryRows, fn($r) => $r['status']['key'] === 'expired'));
} elseif ($expiryTab === 'safe') {
    $expiryRows = array_values(array_filter($expiryRows, fn($r) => $r['status']['key'] === 'safe'));
}

// ---- Query-string builders for links ----
$qs      = reportInventoryQueryString($dates, $f);
$stockQS = fn(string $k) => reportInventoryQueryString($dates, array_merge($f, ['stock' => $k]));
$expQS   = fn(string $k) => reportInventoryQueryString($dates, array_merge($f, ['expiry' => $k]));
$catQS   = fn(string $k) => reportInventoryQueryString($dates, array_merge($f, ['category' => $k, 'stock' => 'all', 'expiry' => 'all']));

$cur = ' ' . getSetting('currency', 'ETB');
// Category dropdown data, grouped by product type like the Sales Report filter.
$catGroups = [];
foreach ($options['categories'] as $c) {
    $catGroups[$c['product_type']][] = $c;
}
$catLabels = ['medicine' => 'Medicine', 'cosmetic' => 'Cosmetics', 'equipment' => 'Equipment', 'other' => 'Other'];
foreach (array_keys($catGroups) as $pt) { if (!isset($catLabels[$pt])) $catLabels[$pt] = ucfirst($pt); }
$catNameById = [];
foreach ($options['categories'] as $c) { $catNameById[(int)$c['id']] = $c['name']; }
$productLabels = [];
foreach ($options['products'] as $p) {
    $productLabels[(string)$p['id']] = $p['name'] . ($p['generic_name'] ? ' — ' . $p['generic_name'] : '');
}
$stockTabs = ['all' => 'All', 'in' => 'In Stock', 'low' => 'Low Stock', 'out' => 'Out of Stock', 'over' => 'Overstock', 'fast' => 'Fast Moving', 'slow' => 'Slow Moving', 'dead' => 'Dead Stock'];
$expiryTabs = ['all' => 'All', 'expiring' => 'Expiring Soon', 'expired' => 'Expired', 'safe' => 'Safe'];

renderHead('Inventory Reports', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Inventory Reports', 'Current stock, movement, value & expiry risk'); ?>
<div class="page-body">
<?php renderReportNav('report_inventory', $dates, $navFilters); ?>

<!-- ── Filters ─────────────────────────────────────────────────────────── -->
<div class="card mb-20 no-print report-filters-card">
  <form method="GET" action="report_inventory.php">
    <div class="report-filters-grid">
      <div class="form-group">
        <label>Date Range</label>
        <select name="preset">
          <?php foreach (reportDatePresets() as $key => $label): ?>
          <option value="<?= $key ?>" <?= $dates['preset'] === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Product (searchable)</label>
        <input type="text" id="invProductQ" list="invProductList" placeholder="Type to search product…" autocomplete="off"
               value="<?= htmlspecialchars($productDetail ? ($productDetail['medicine']['name'] . ($productDetail['medicine']['generic_name'] ? ' — ' . $productDetail['medicine']['generic_name'] : '')) : '') ?>">
        <input type="hidden" name="product" id="invProductId" value="<?= (int)$f['product'] ?>">
        <datalist id="invProductList">
          <?php foreach ($options['products'] as $p): $lbl = $p['name'] . ($p['generic_name'] ? ' — ' . $p['generic_name'] : ''); ?>
          <option value="<?= htmlspecialchars($lbl) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="form-group">
        <label>Category</label>
        <select name="category">
          <option value="">All Categories</option>
          <?php foreach ($catLabels as $pt => $ptLabel): ?>
            <?php if (empty($catGroups[$pt])) continue; ?>
          <optgroup label="<?= htmlspecialchars($ptLabel) ?>">
            <?php foreach ($catGroups[$pt] as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $f['category'] == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Supplier</label>
        <select name="supplier">
          <option value="">All Suppliers</option>
          <?php foreach ($options['suppliers'] as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $f['supplier'] == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Brand</label>
        <input type="text" name="brand" value="<?= htmlspecialchars($f['brand']) ?>" placeholder="Brand / product name…">
      </div>
      <div class="form-group">
        <label>Batch Number</label>
        <input type="text" name="batch" value="<?= htmlspecialchars($f['batch']) ?>" placeholder="e.g. BATCH-12345">
      </div>
      <div class="form-group">
        <label>Stock Status</label>
        <select name="stock">
          <?php foreach ($stockTabs as $key => $label): ?>
          <option value="<?= $key ?>" <?= $stockTab === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Expiry Status</label>
        <select name="expiry">
          <?php foreach ($expiryTabs as $key => $label): ?>
          <option value="<?= $key ?>" <?= $expiryTab === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="report-filter-actions">
      <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Apply Filter</button>
      <a href="report_inventory.php" class="btn btn-ghost">Clear</a>
      <div class="report-export-btns">
        <a href="report_export.php?format=csv&report=inventory&<?= htmlspecialchars($qs) ?>" class="btn btn-ghost btn-sm"><i data-lucide="file-spreadsheet"></i> CSV</a>
        <a href="report_export.php?format=excel&report=inventory&<?= htmlspecialchars($qs) ?>" class="btn btn-ghost btn-sm"><i data-lucide="file-spreadsheet"></i> Excel</a>
        <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()"><i data-lucide="printer"></i> Print</button>
      </div>
    </div>
  </form>
  <?php
  $chips = ['Period: ' . $dates['label']];
  if ($f['product']) { foreach ($options['products'] as $p) { if ((int)$p['id'] === (int)$f['product']) { $chips[] = 'Product: ' . $p['name']; break; } } }
  if ($f['category']) $chips[] = 'Category: ' . ($catNameById[(int)$f['category']] ?? '#' . (int)$f['category']);
  if ($f['supplier']) { foreach ($options['suppliers'] as $s) { if ((int)$s['id'] === (int)$f['supplier']) { $chips[] = 'Supplier: ' . $s['name']; break; } } }
  if ($f['brand'] !== '') $chips[] = 'Brand: ' . $f['brand'];
  if ($f['batch'] !== '') $chips[] = 'Batch: ' . $f['batch'];
  if ($stockTab !== 'all') $chips[] = 'Stock: ' . $stockTabs[$stockTab];
  if ($expiryTab !== 'all') $chips[] = 'Expiry: ' . $expiryTabs[$expiryTab];
  ?>
  <div class="report-active-filters" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
    <?php foreach ($chips as $chip): ?>
    <span class="badge badge-blue" style="font-weight:500;"><?= htmlspecialchars($chip) ?></span>
    <?php endforeach; ?>
    <?php if (count($chips) > 1): ?>
    <a href="report_inventory.php" style="font-size:12px;align-self:center;">Clear filters</a>
    <?php endif; ?>
  </div>
</div>

<?php renderReportMeta('Inventory Reports', $dates); ?>

<!-- ── Inventory Summary Cards ─────────────────────────────────────────── -->
<div class="stats-grid mb-20">
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="banknote"></i></div><div><div class="stat-label">Inventory Cost Value</div><div class="stat-value"><?= number_format($summary['cost'], 0) ?><?= $cur ?></div></div></div>
  <div class="stat-card green"><div class="stat-icon green"><i data-lucide="tag"></i></div><div><div class="stat-label">Retail Value</div><div class="stat-value"><?= number_format($summary['retail'], 0) ?><?= $cur ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="trending-up"></i></div><div><div class="stat-label">Expected Gross Profit</div><div class="stat-value"><?= number_format($summary['profit'], 0) ?><?= $cur ?></div></div></div>
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="boxes"></i></div><div><div class="stat-label">Units in Stock</div><div class="stat-value"><?= number_format($summary['units'], 0) ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="alert-triangle"></i></div><div><div class="stat-label">Low Stock Items</div><div class="stat-value"><?= number_format($summary['low']) ?></div></div></div>
  <div class="stat-card red"><div class="stat-icon red"><i data-lucide="x-circle"></i></div><div><div class="stat-label">Out of Stock Items</div><div class="stat-value"><?= number_format($summary['out']) ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="calendar-clock"></i></div><div><div class="stat-label">Near Expiry Items</div><div class="stat-value"><?= number_format($summary['near']) ?></div></div></div>
  <div class="stat-card red"><div class="stat-icon red"><i data-lucide="calendar-x"></i></div><div><div class="stat-label">Expired Items</div><div class="stat-value"><?= number_format($summary['expired']) ?></div></div></div>
</div>

<!-- ── Inventory Overview ──────────────────────────────────────────────── -->
<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title"><i data-lucide="layout-dashboard" style="width:16px;height:16px;"></i> Inventory Overview</span></div>
    <div class="report-summary-grid" style="padding:20px;">
      <div><span class="report-k">Total Products</span><span class="report-v"><?= number_format($overview['totals']['products']) ?></span></div>
      <div><span class="report-k">Total Units</span><span class="report-v"><?= number_format($overview['totals']['units']) ?></span></div>
      <div><span class="report-k">Inventory Cost Value</span><span class="report-v"><?= currency($overview['totals']['cost']) ?></span></div>
      <div><span class="report-k">Inventory Retail Value</span><span class="report-v" style="color:var(--accent2);"><?= currency($overview['totals']['retail']) ?></span></div>
      <div><span class="report-k">Expected Profit</span><span class="report-v"><?= currency($overview['totals']['profit']) ?></span></div>
      <div><span class="report-k">Avg Stock Value / Product</span><span class="report-v"><?= currency($overview['totals']['avg_value']) ?></span></div>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Distribution by Product Type (Value)</span></div>
    <div class="chart-wrap chart-donut">
      <canvas id="chartPayments"
        data-labels='<?= json_encode(array_column($overview['donut'], 'label')) ?>'
        data-values='<?= json_encode(array_column($overview['donut'], 'value')) ?>'></canvas>
    </div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Distribution by Category — click a category to filter</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Category</th><th>Products</th><th>Units</th><th>Cost Value</th><th>Retail Value</th><th>Expected Profit</th></tr></thead>
    <tbody>
    <?php if (empty($overview['groups'])): ?>
      <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-300);">No products match the current filters</td></tr>
    <?php else: ?>
    <?php foreach ($overview['groups'] as $grp): ?>
      <tr style="background:var(--bg-500);">
        <td colspan="6" style="font-weight:700;letter-spacing:0.3px;"><?= htmlspecialchars($grp['label']) ?></td>
      </tr>
      <?php
      $gTot = ['products' => 0, 'units' => 0, 'cost' => 0, 'retail' => 0];
      foreach ($grp['rows'] as $d):
          foreach ($gTot as $k => $v) $gTot[$k] += $d[$k];
      ?>
      <tr style="<?= $f['category'] === $d['id'] ? 'background:var(--bg-600);' : '' ?>">
        <td style="padding-left:28px;">
          <a href="?<?= htmlspecialchars($catQS($d['id'])) ?>" style="font-weight:600;"><?= htmlspecialchars($d['category']) ?></a>
          <?php if ($f['category'] === $d['id']): ?><span class="badge badge-blue" style="margin-left:8px;">filtered</span><?php endif; ?>
        </td>
        <td style="text-align:center;"><?= number_format($d['products']) ?></td>
        <td style="text-align:center;"><?= number_format($d['units']) ?></td>
        <td><?= currency($d['cost']) ?></td>
        <td style="color:var(--accent2);font-weight:700;"><?= currency($d['retail']) ?></td>
        <td><?= currency($d['retail'] - $d['cost']) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr style="background:var(--bg-600);">
        <td style="font-weight:700;"><?= htmlspecialchars($grp['label']) ?> Subtotal</td>
        <td style="text-align:center;font-weight:600;"><?= number_format($gTot['products']) ?></td>
        <td style="text-align:center;font-weight:600;"><?= number_format($gTot['units']) ?></td>
        <td style="font-weight:700;"><?= currency($gTot['cost']) ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency($gTot['retail']) ?></td>
        <td style="font-weight:700;"><?= currency($gTot['retail'] - $gTot['cost']) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr style="background:var(--bg-500);">
        <td style="font-weight:800;">Total</td>
        <td style="text-align:center;font-weight:700;"><?= number_format($overview['totals']['products']) ?></td>
        <td style="text-align:center;font-weight:700;"><?= number_format($overview['totals']['units']) ?></td>
        <td style="font-weight:700;"><?= currency($overview['totals']['cost']) ?></td>
        <td style="font-weight:800;color:var(--accent2);"><?= currency($overview['totals']['retail']) ?></td>
        <td style="font-weight:700;"><?= currency($overview['totals']['profit']) ?></td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php if ($productDetail): $m = $productDetail['medicine']; $pf = $productDetail['perf']; ?>
<!-- ── Searchable Product Details ──────────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <span class="card-title"><i data-lucide="package" style="width:16px;height:16px;"></i> Product Inventory Card</span>
    <span class="badge badge-blue"><?= htmlspecialchars(productTypeLabel($m['product_type'])) ?></span>
  </div>
  <div style="padding:20px;">
    <h3 style="font-size:19px;font-weight:800;letter-spacing:0.3px;margin-bottom:2px;"><?= htmlspecialchars(strtoupper((string)$m['name'])) ?></h3>
    <p style="font-size:13px;color:var(--text-300);">
      <?= htmlspecialchars(implode(' · ', array_filter([$m['generic_name'], $m['category_name'], $m['unit']]))) ?>
      <?php if ($m['strength']): ?> · <?= htmlspecialchars($m['strength']) ?><?php endif; ?>
      <?php if ($m['dosage_form']): ?> · <?= htmlspecialchars($m['dosage_form']) ?><?php endif; ?>
    </p>

    <div class="report-summary-grid" style="margin-top:16px;">
      <div><span class="report-k">Category</span><span class="report-v"><?= htmlspecialchars($m['category_name']) ?></span></div>
      <div><span class="report-k">Supplier</span><span class="report-v"><?= htmlspecialchars($productDetail['supplier']['supplier_name'] ?? '—') ?></span></div>
      <div><span class="report-k">Barcode</span><span class="report-v"><?= htmlspecialchars($m['barcode'] ?? '—') ?></span></div>
      <div><span class="report-k">SKU</span><span class="report-v"><?= htmlspecialchars($m['sku'] ?? '—') ?></span></div>
      <div><span class="report-k">Current Stock</span><span class="report-v"><?= number_format($m['stock']) ?> <?= htmlspecialchars($m['unit']) ?></span></div>
      <div><span class="report-k">Reorder Level</span><span class="report-v"><?= number_format($m['reorder_level']) ?></span></div>
      <div><span class="report-k">Number of Batches</span><span class="report-v"><?= number_format($productDetail['batch_count']) ?></span></div>
      <div><span class="report-k">Stock Status</span><span class="report-v"><span class="badge <?= inventoryStockStatusBadge(inventoryStockStatus($m)) ?>"><?= htmlspecialchars(inventoryStockStatusLabel(inventoryStockStatus($m))) ?></span></span></div>
    </div>

    <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border);">
      <div style="font-size:12px;font-weight:600;color:var(--text-200);margin-bottom:10px;">VALUE &amp; PROFIT</div>
      <div class="report-summary-grid">
        <div><span class="report-k">Cost Price</span><span class="report-v"><?= currency($m['avg_cost']) ?></span></div>
        <div><span class="report-k">Selling Price</span><span class="report-v"><?= currency($m['avg_sell']) ?></span></div>
        <div><span class="report-k">Inventory Cost Value</span><span class="report-v"><?= currency($m['cost_value']) ?></span></div>
        <div><span class="report-k">Potential Sales Value</span><span class="report-v" style="color:var(--accent2);"><?= currency($m['retail_value']) ?></span></div>
        <div><span class="report-k">Expected Profit</span><span class="report-v"><?= currency($productDetail['profit']) ?></span></div>
        <div><span class="report-k">Profit Margin</span><span class="report-v"><?= number_format($productDetail['margin'], 1) ?>%</span></div>
      </div>
    </div>

    <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border);">
      <div style="font-size:12px;font-weight:600;color:var(--text-200);margin-bottom:10px;">STOCK MOVEMENT</div>
      <div class="report-summary-grid">
        <div><span class="report-k">Units Purchased</span><span class="report-v"><?= number_format($pf['units_purchased'] ?? 0) ?></span></div>
        <div><span class="report-k">Units Sold</span><span class="report-v"><?= number_format($pf['units_sold'] ?? 0) ?></span></div>
        <div><span class="report-k">Units Returned</span><span class="report-v"><?= number_format($pf['units_returned'] ?? 0) ?></span></div>
        <div><span class="report-k">Current Stock</span><span class="report-v"><?= number_format($m['stock']) ?></span></div>
        <div><span class="report-k">Last Purchase</span><span class="report-v"><?= $pf['last_purchase'] ? date('M j, Y', strtotime($pf['last_purchase'])) : '—' ?></span></div>
        <div><span class="report-k">Last Sale</span><span class="report-v"><?= $pf['last_sale'] ? date('M j, Y', strtotime($pf['last_sale'])) : '—' ?></span></div>
      </div>
    </div>

    <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border);">
      <div style="font-size:12px;font-weight:600;color:var(--text-200);margin-bottom:10px;">BATCHES (<?= count($productDetail['batches']) ?>)</div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Batch Number</th><th>Manufacture Date</th><th>Expiry Date</th><th>Days Left</th><th>Qty</th><th>Cost</th><th>Selling</th><th>Supplier</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($productDetail['batches'] as $b): ?>
            <tr>
              <td><code><?= htmlspecialchars($b['batch_number']) ?></code></td>
              <td><?= $b['manufacture_date'] ? date('M Y', strtotime($b['manufacture_date'])) : '—' ?></td>
              <td><?= $b['expiry_date'] && $b['expiry_date'] < '9000-01-01' ? date('M j, Y', strtotime($b['expiry_date'])) : 'No expiry' ?></td>
              <td><?= $b['status']['days'] !== null ? ($b['status']['days'] < 0 ? 'Expired ' . abs($b['status']['days']) . 'd ago' : $b['status']['days'] . 'd') : '—' ?></td>
              <td style="text-align:center;"><?= number_format($b['quantity']) ?></td>
              <td><?= currency((float)$b['purchase_price']) ?></td>
              <td><?= currency((float)$b['selling_price']) ?></td>
              <td><?= htmlspecialchars($b['supplier_name']) ?></td>
              <td><span class="badge <?= $b['status']['badge'] ?>"><?= htmlspecialchars($b['status']['label']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($productDetail['batches'])): ?>
            <tr><td colspan="9" style="text-align:center;padding:24px;color:var(--text-300);">No batches recorded for this product</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Stock Status ────────────────────────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <span class="card-title"><i data-lucide="boxes" style="width:16px;height:16px;"></i> Stock Status
      <span style="font-size:12px;color:var(--text-300);font-weight:400;">Low stock = at or below reorder level · Overstock = above 3× reorder level</span>
    </span>
    <span class="badge badge-gray"><?= number_format(count($stockRows)) ?> products</span>
  </div>
  <div class="report-view-tabs no-print mb-20" style="padding:0 20px;">
    <?php foreach ($stockTabs as $key => $label): ?>
    <a href="?<?= htmlspecialchars($stockQS($key)) ?>" class="btn btn-sm <?= $stockTab === $key ? 'btn-primary' : 'btn-ghost' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Product</th><th>Category</th><th>Stock</th><th>Reorder</th><th>Sold (period)</th><th>Last Sale</th>
          <th>Cost Value</th><th>Retail Value</th><th>Expected Profit</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($stockRows as $p): $st = inventoryStockStatus($p); ?>
        <tr>
          <td style="font-weight:600;">
            <a href="?<?= htmlspecialchars(reportInventoryQueryString($dates, array_merge($f, ['product' => $p['id'], 'stock' => 'all', 'expiry' => 'all']))) ?>"><?= htmlspecialchars($p['name']) ?></a>
            <?php if ($p['generic_name']): ?><div style="font-size:12px;color:var(--text-300);font-weight:400;"><?= htmlspecialchars($p['generic_name']) ?></div><?php endif; ?>
          </td>
          <td><?= htmlspecialchars(productTypeLabel($p['product_type'] ?? '')) ?> · <?= htmlspecialchars($p['category']) ?></td>
          <td style="text-align:center;font-weight:600;"><?= number_format($p['stock']) ?></td>
          <td style="text-align:center;"><?= number_format($p['reorder_level']) ?></td>
          <td style="text-align:center;"><?= number_format($p['qty_sold']) ?></td>
          <td><?= $p['last_sale'] ? date('M j, Y', strtotime($p['last_sale'])) : '<span style="color:var(--text-300);">—</span>' ?></td>
          <td><?= currency($p['cost_value']) ?></td>
          <td style="color:var(--accent2);font-weight:700;"><?= currency($p['retail_value']) ?></td>
          <td><?= currency($p['retail_value'] - $p['cost_value']) ?></td>
          <td><span class="badge <?= inventoryStockStatusBadge($st) ?>"><?= htmlspecialchars(inventoryStockStatusLabel($st)) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($stockRows)): ?>
        <tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text-300);">No products match this stock status</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Expiry Management ───────────────────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <span class="card-title"><i data-lucide="calendar-clock" style="width:16px;height:16px;"></i> Expiry Management
      <span style="font-size:12px;color:var(--text-300);font-weight:400;">Expiring soon = within 90 days</span>
    </span>
    <span class="badge badge-gray"><?= number_format(count($expiryRows)) ?> batches</span>
  </div>
  <div class="report-view-tabs no-print mb-20" style="padding:0 20px;">
    <?php foreach ($expiryTabs as $key => $label): ?>
    <a href="?<?= htmlspecialchars($expQS($key)) ?>" class="btn btn-sm <?= $expiryTab === $key ? 'btn-primary' : 'btn-ghost' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Product</th><th>Batch</th><th>Qty</th><th>Expiry Date</th><th>Days Remaining</th><th>Cost Value</th><th>Retail Value</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($expiryRows as $r): ?>
        <tr>
          <td style="font-weight:600;">
            <a href="?<?= htmlspecialchars(reportInventoryQueryString($dates, array_merge($f, ['product' => $r['medicine_id'], 'stock' => 'all', 'expiry' => 'all']))) ?>"><?= htmlspecialchars($r['name']) ?></a>
          </td>
          <td><code><?= htmlspecialchars($r['batch_number']) ?></code></td>
          <td style="text-align:center;"><?= number_format($r['quantity']) ?></td>
          <td><?= $r['expiry_date'] && $r['expiry_date'] < '9000-01-01' ? date('M j, Y', strtotime($r['expiry_date'])) : '—' ?></td>
          <td>
            <?php if ($r['status']['days'] === null): ?>—<?php elseif ($r['status']['days'] < 0): ?><span style="color:var(--danger);font-weight:600;">Expired <?= abs($r['status']['days']) ?>d ago</span><?php else: ?><span style="font-weight:600;"><?= $r['status']['days'] ?>d</span><?php endif; ?>
          </td>
          <td><?= currency($r['cost_value']) ?></td>
          <td style="color:var(--accent2);font-weight:700;"><?= currency($r['retail_value']) ?></td>
          <td><span class="badge <?= $r['status']['badge'] ?>"><?= htmlspecialchars($r['status']['label']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($expiryRows)): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-300);">No batches match this expiry status</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div></div>

<script>
(function () {
  var map = <?= json_encode($productLabels, JSON_UNESCAPED_UNICODE) ?>;
  var q = document.getElementById('invProductQ');
  var hid = document.getElementById('invProductId');
  if (q && hid) {
    if (hid.value && map[hid.value]) q.value = map[hid.value];
    q.addEventListener('change', function () {
      var v = q.value.trim();
      var id = '';
      for (var k in map) { if (Object.prototype.hasOwnProperty.call(map, k) && map[k] === v) { id = k; break; } }
      hid.value = id;
    });
  }
})();
</script>
<?php renderFooter(); ?>
