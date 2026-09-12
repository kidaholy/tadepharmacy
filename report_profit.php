<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';
require_once __DIR__ . '/profit_lib.php';

$pdo = getDB();
if (!can('reports.view')) {
    redirectHome();
}

profitEnsureOverdueStatuses($pdo);

$flash = flashGet();
$msg = $flash && ($flash['type'] ?? '') === 'success' ? ($flash['message'] ?? '') : '';
$error = $flash && ($flash['type'] ?? '') === 'error' ? ($flash['message'] ?? '') : '';

// ── Expense CRUD (Profit page only) ──────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = $_POST['act'] ?? '';
    $userId = (int)(currentUser()['id'] ?? 0);
    try {
        if ($act === 'add_expense') {
            profitSaveExpense($pdo, $_POST, $userId);
            flashSet('success', 'Expense saved. Recurring items were generated without duplicates.');
        } elseif ($act === 'add_expense_category') {
            $n = profitAddExpenseCategory($pdo, $_POST['category_name'] ?? '');
            flashSet('success', 'Category "' . $n . '" added.');
        } elseif ($act === 'update_expense') {
            profitUpdateExpense($pdo, (int)($_POST['id'] ?? 0), $_POST);
            flashSet('success', 'Expense updated.');
        } elseif ($act === 'delete_expense') {
            profitDeleteExpense($pdo, (int)($_POST['id'] ?? 0), !empty($_POST['whole_series']));
            flashSet('success', 'Expense deleted.');
        }
    } catch (Throwable $e) {
        flashSet('error', $e->getMessage());
    }
    $qs = http_build_query(array_filter($_GET, fn($v) => $v !== null && $v !== ''));
    header('Location: report_profit.php' . ($qs !== '' ? ('?' . $qs) : ''));
    exit;
}

extract(reportInit());
$from = $dates['from'];
$to   = $dates['to'];
$cur  = getSetting('currency', 'ETB');

$catSort   = $_GET['cat_sort'] ?? 'revenue';
$prodSort  = $_GET['prod_sort'] ?? 'profit';
$prodLimit = (int)($_GET['prod_limit'] ?? 25);
if (!in_array($prodLimit, [10, 25, 50, 100], true)) $prodLimit = 25;
$marginThreshold = isset($_GET['margin_threshold']) ? (float)$_GET['margin_threshold'] : 25.0;
if ($marginThreshold < 0) $marginThreshold = 0;
if ($marginThreshold > 100) $marginThreshold = 100;
$cashierSort = $_GET['cashier_sort'] ?? 'gross';
$expFilters = profitParseExpenseFilters($_GET);

$kpis     = profitKpis($pdo, $dates, $filters);
$core     = $kpis['core'];
$variance = profitVarianceExplain($kpis['core'], $kpis['prev_core']);

$trend = [];
foreach (['daily', 'weekly', 'monthly', 'yearly'] as $v) {
    $trend[$v] = profitTrend($pdo, $dates, $filters, $v);
}
$trendFormat = [
    'daily'   => fn($l) => date('M j', strtotime($l)),
    'weekly'  => fn($l) => reportWeeklyLabel($l),
    'monthly' => fn($l) => date('M Y', strtotime($l . '-01')),
    'yearly'  => fn($l) => $l,
];
$trendLabels = $trendRevenue = $trendCogs = $trendGross = $trendExp = $trendNet = [];
foreach (['daily', 'weekly', 'monthly', 'yearly'] as $v) {
    $trendLabels[$v]  = array_map($trendFormat[$v], array_column($trend[$v], 'label'));
    $trendRevenue[$v] = array_map(fn($r) => (float)$r['revenue'], $trend[$v]);
    $trendCogs[$v]    = array_map(fn($r) => (float)$r['cogs'], $trend[$v]);
    $trendGross[$v]   = array_map(fn($r) => (float)$r['gross'], $trend[$v]);
    $trendExp[$v]     = array_map(fn($r) => (float)$r['expenses'], $trend[$v]);
    $trendNet[$v]     = array_map(fn($r) => (float)$r['net'], $trend[$v]);
}

$profitByCat = profitByCategory($pdo, $dates, $filters, $catSort);
$profitCatGroups = [];
$profitCatGrand = ['units' => 0, 'revenue' => 0, 'cogs' => 0, 'gross_profit' => 0];
foreach ($profitByCat as $c) {
    $t = $c['type'];
    $profitCatGroups[$t]['label'] = productTypeLabel($t);
    $profitCatGroups[$t]['rows'][] = $c;
    foreach ($profitCatGrand as $k => $_) $profitCatGrand[$k] += (float)$c[$k];
}

$products = profitProducts($pdo, $dates, $filters, $prodSort, $prodLimit);
$lowMargin = profitProducts($pdo, $dates, $filters, 'margin', 100, $marginThreshold);
$productDetail = !empty($filters['product']) ? profitProductDetail($pdo, (int)$filters['product'], $dates, $filters) : null;

$expSummary = profitExpenseSummary($pdo, $from, $to, $expFilters);
$expenseCats = profitExpenseCategories($pdo);
$payments = profitByPayment($pdo, $dates, $filters);
$cashiers = profitByCashier($pdo, $dates, $filters, $cashierSort);
$salesTypes = profitBySalesType($pdo, $dates, $filters);
$customers = profitByCustomer($pdo, $dates, $filters, 40);

$keepQs = reportQueryParams($dates, $filters, [
    'cat_sort' => $catSort,
    'prod_sort' => $prodSort,
    'prod_limit' => $prodLimit,
    'margin_threshold' => $marginThreshold,
    'cashier_sort' => $cashierSort,
    'exp_category' => $expFilters['category'] ?: null,
    'exp_status' => $expFilters['status'] ?: null,
    'exp_payment' => $expFilters['payment_method'] ?: null,
]);

$statusBadge = ['paid' => 'badge-green', 'pending' => 'badge-orange', 'overdue' => 'badge-red'];
$payMethods = reportPaymentMethods();

renderHead('Profit & Loss', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Profit & Loss', 'Pharmacy profitability analysis'); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success no-print"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger no-print"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php renderReportNav('report_profit', $dates, $filters); ?>
<?php renderReportFilters($dates, $filters, $options, 'report_profit.php', profitDatePresets(), true, true, 'profit', ['excel', 'pdf', 'print'], true, [
  'cat_sort' => $catSort,
  'prod_sort' => $prodSort,
  'prod_limit' => $prodLimit,
  'margin_threshold' => $marginThreshold,
  'cashier_sort' => $cashierSort,
  'exp_category' => $expFilters['category'] ?: null,
  'exp_status' => $expFilters['status'] ?: null,
  'exp_payment' => $expFilters['payment_method'] ?: null,
]); ?>
<?php renderReportMeta('Profit & Loss', $dates); ?>

<?php if ($core['item_filtered']): ?>
<div class="alert alert-info mb-20 no-print" style="font-size:13px;">
  Product filters are active — Net Profit below shows <strong>product contribution (Gross Profit)</strong>. Full pharmacy Operating Expenses still appear in the OpEx section and KPI.
</div>
<?php endif; ?>

<!-- ─── PROFIT SUMMARY KPIs ─────────────────────────────────────────── -->
<div class="stats-grid kpi-grid">
  <?php
  renderKpiCard('Total Revenue', $kpis['revenue'], 'blue', ' ' . $cur);
  renderKpiCard('COGS', $kpis['cogs'], 'orange', ' ' . $cur);
  renderKpiCard('Gross Profit', $kpis['gross_profit'], 'green', ' ' . $cur);
  renderKpiCard('Operating Expenses', $kpis['expenses'], 'red', ' ' . $cur);
  ?>
</div>
<div class="stats-grid kpi-grid mb-20">
  <?php
  renderKpiCard('Net Profit', $kpis['net_profit'], 'green', ' ' . $cur);
  renderKpiCard('Gross Margin %', $kpis['gross_margin'], 'blue', '', true);
  renderKpiCard('Net Margin %', $kpis['net_margin'], 'blue', '', true);
  renderKpiCard('Profit / ETB 1,000 Sales', $kpis['profit_per_1000'], 'green', ' ' . $cur);
  ?>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Profit &amp; Loss Summary</span></div>
  <div class="report-summary-grid">
    <div><span class="report-k">Revenue</span><span class="report-v" style="color:var(--accent2);"><?= currency($core['revenue']) ?></span></div>
    <div><span class="report-k">Cost of Goods Sold</span><span class="report-v"><?= currency($core['cogs']) ?></span></div>
    <div><span class="report-k">Gross Profit</span><span class="report-v"><?= currency($core['gross_profit']) ?></span></div>
    <div><span class="report-k">Gross Margin</span><span class="report-v"><?= number_format($core['gross_margin'], 1) ?>%</span></div>
    <div><span class="report-k">Operating Expenses</span><span class="report-v" style="color:var(--danger);"><?= currency($core['expenses_full']) ?></span></div>
    <div><span class="report-k">Net Profit</span><span class="report-v" style="color:<?= $core['net_profit'] >= 0 ? 'var(--accent2)' : 'var(--danger)' ?>;"><?= currency($core['item_filtered'] ? $core['gross_profit'] : $core['net_profit']) ?></span></div>
    <div><span class="report-k">Net Margin</span><span class="report-v"><?= number_format($core['item_filtered'] ? $core['gross_margin'] : $core['net_margin'], 1) ?>%</span></div>
    <div><span class="report-k">Profit per ETB 1,000</span><span class="report-v"><?= currency($core['item_filtered'] ? ($core['revenue'] > 0 ? ($core['gross_profit'] / $core['revenue']) * 1000 : 0) : $core['profit_per_1000']) ?></span></div>
    <?php if ($core['returns_amount'] > 0): ?>
    <div><span class="report-k">Returns (netted)</span><span class="report-v" style="color:var(--danger);"><?= currency($core['returns_amount']) ?></span></div>
    <?php endif; ?>
  </div>
</div>

<!-- ─── WHY DID PROFIT CHANGE? ──────────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header"><span class="card-title"><i data-lucide="git-compare" style="width:16px;height:16px;"></i> Why Did My Profit Change?</span>
    <span style="font-size:12px;color:var(--text-300);">vs <?= htmlspecialchars(date('M j, Y', strtotime($dates['prevFrom'])) . ' – ' . date('M j, Y', strtotime($dates['prevTo']))) ?></span>
  </div>
  <?php if (!$variance['has_previous']): ?>
    <div class="empty-state" style="padding:24px;"><p>No previous-period data</p></div>
  <?php else:
    $nd = $variance['net_delta'];
  ?>
  <div style="padding:0 16px 16px;">
    <p style="font-size:15px;font-weight:700;margin-bottom:12px;color:<?= $nd >= 0 ? 'var(--accent2)' : 'var(--danger)' ?>;">
      Net Profit <?= $nd >= 0 ? 'increased' : 'decreased' ?> by <?= currency(abs($nd)) ?>
    </p>
    <div class="report-summary-grid" style="margin-bottom:12px;">
      <div><span class="report-k">Revenue</span><span class="report-v" style="color:<?= $variance['revenue_delta'] >= 0 ? 'var(--accent2)' : 'var(--danger)' ?>;"><?= ($variance['revenue_delta'] >= 0 ? '+' : '') . currency($variance['revenue_delta']) ?></span></div>
      <div><span class="report-k">COGS</span><span class="report-v" style="color:<?= $variance['cogs_delta'] <= 0 ? 'var(--accent2)' : 'var(--danger)' ?>;"><?= ($variance['cogs_delta'] >= 0 ? '+' : '') . currency($variance['cogs_delta']) ?></span></div>
      <div><span class="report-k">Operating Expenses</span><span class="report-v" style="color:<?= $variance['expenses_delta'] <= 0 ? 'var(--accent2)' : 'var(--danger)' ?>;"><?= ($variance['expenses_delta'] >= 0 ? '+' : '') . currency($variance['expenses_delta']) ?></span></div>
      <div><span class="report-k">Net impact</span><span class="report-v" style="font-weight:800;"><?= ($variance['net_impact'] >= 0 ? '+' : '') . currency($variance['net_impact']) ?></span></div>
    </div>
    <div class="grid-2" style="gap:12px;">
      <div>
        <div style="font-size:12px;font-weight:700;margin-bottom:6px;color:var(--accent2);">Biggest positive contributors</div>
        <?php if (empty($variance['positive'])): ?>
          <p style="font-size:13px;color:var(--text-300);">None this period</p>
        <?php else: foreach ($variance['positive'] as $c): ?>
          <div style="font-size:13px;margin-bottom:4px;"><?= htmlspecialchars($c['label']) ?>: <strong>+<?= currency($c['delta']) ?></strong> <span style="color:var(--text-300);">(<?= htmlspecialchars($c['note']) ?>)</span></div>
        <?php endforeach; endif; ?>
      </div>
      <div>
        <div style="font-size:12px;font-weight:700;margin-bottom:6px;color:var(--danger);">Biggest negative contributors</div>
        <?php if (empty($variance['negative'])): ?>
          <p style="font-size:13px;color:var(--text-300);">None this period</p>
        <?php else: foreach ($variance['negative'] as $c): ?>
          <div style="font-size:13px;margin-bottom:4px;"><?= htmlspecialchars($c['label']) ?>: <strong><?= currency($c['delta']) ?></strong> <span style="color:var(--text-300);">(<?= htmlspecialchars($c['note']) ?>)</span></div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ─── PROFIT TREND ────────────────────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <span class="card-title">Profit Trend</span>
    <div class="no-print" style="display:flex;gap:4px;flex-wrap:wrap;">
      <button type="button" class="btn btn-sm btn-primary" data-profit-trend-view="daily" onclick="switchProfitTrend('daily', this)">Daily</button>
      <button type="button" class="btn btn-sm btn-ghost" data-profit-trend-view="weekly" onclick="switchProfitTrend('weekly', this)">Weekly</button>
      <button type="button" class="btn btn-sm btn-ghost" data-profit-trend-view="monthly" onclick="switchProfitTrend('monthly', this)">Monthly</button>
      <button type="button" class="btn btn-sm btn-ghost" data-profit-trend-view="yearly" onclick="switchProfitTrend('yearly', this)">Yearly</button>
    </div>
  </div>
  <div class="chart-wrap" style="height:320px;">
    <canvas id="chartProfitTrend"
      data-daily-labels='<?= json_encode($trendLabels['daily']) ?>'
      data-daily-revenue='<?= json_encode($trendRevenue['daily']) ?>'
      data-daily-cogs='<?= json_encode($trendCogs['daily']) ?>'
      data-daily-gross='<?= json_encode($trendGross['daily']) ?>'
      data-daily-expenses='<?= json_encode($trendExp['daily']) ?>'
      data-daily-net='<?= json_encode($trendNet['daily']) ?>'
      data-weekly-labels='<?= json_encode($trendLabels['weekly']) ?>'
      data-weekly-revenue='<?= json_encode($trendRevenue['weekly']) ?>'
      data-weekly-cogs='<?= json_encode($trendCogs['weekly']) ?>'
      data-weekly-gross='<?= json_encode($trendGross['weekly']) ?>'
      data-weekly-expenses='<?= json_encode($trendExp['weekly']) ?>'
      data-weekly-net='<?= json_encode($trendNet['weekly']) ?>'
      data-monthly-labels='<?= json_encode($trendLabels['monthly']) ?>'
      data-monthly-revenue='<?= json_encode($trendRevenue['monthly']) ?>'
      data-monthly-cogs='<?= json_encode($trendCogs['monthly']) ?>'
      data-monthly-gross='<?= json_encode($trendGross['monthly']) ?>'
      data-monthly-expenses='<?= json_encode($trendExp['monthly']) ?>'
      data-monthly-net='<?= json_encode($trendNet['monthly']) ?>'
      data-yearly-labels='<?= json_encode($trendLabels['yearly']) ?>'
      data-yearly-revenue='<?= json_encode($trendRevenue['yearly']) ?>'
      data-yearly-cogs='<?= json_encode($trendCogs['yearly']) ?>'
      data-yearly-gross='<?= json_encode($trendGross['yearly']) ?>'
      data-yearly-expenses='<?= json_encode($trendExp['yearly']) ?>'
      data-yearly-net='<?= json_encode($trendNet['yearly']) ?>'
    ></canvas>
  </div>
</div>

<!-- ─── PROFIT BY CATEGORY ──────────────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <span class="card-title">Profit by Category</span>
    <form method="GET" class="no-print" style="display:flex;gap:8px;align-items:center;">
      <?php foreach ($keepQs as $k => $v): if ($k === 'cat_sort') continue; ?>
      <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
      <?php endforeach; ?>
      <select name="cat_sort" onchange="this.form.submit()" style="min-width:180px;">
        <option value="revenue" <?= $catSort === 'revenue' ? 'selected' : '' ?>>Highest Revenue</option>
        <option value="gross" <?= $catSort === 'gross' ? 'selected' : '' ?>>Highest Gross Profit</option>
        <option value="margin_high" <?= $catSort === 'margin_high' ? 'selected' : '' ?>>Highest Margin</option>
        <option value="margin_low" <?= $catSort === 'margin_low' ? 'selected' : '' ?>>Lowest Margin</option>
        <option value="units" <?= $catSort === 'units' ? 'selected' : '' ?>>Highest Units Sold</option>
      </select>
    </form>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Category</th><th>Units</th><th>Revenue</th><th>COGS</th><th>Gross Profit</th><th>Margin %</th><th>Net Profit*</th></tr></thead>
    <tbody>
    <?php if (empty($profitCatGroups)): ?>
      <tr><td colspan="7" style="text-align:center;padding:20px;color:var(--text-300);">No sales in this range</td></tr>
    <?php else: foreach ($profitCatGroups as $grp): ?>
      <tr style="background:var(--bg-500);"><td colspan="7" style="font-weight:700;"><?= htmlspecialchars($grp['label']) ?></td></tr>
      <?php
      $tTot = ['units' => 0, 'revenue' => 0, 'cogs' => 0, 'gross_profit' => 0];
      foreach ($grp['rows'] as $c):
        foreach ($tTot as $k => $_) $tTot[$k] += (float)$c[$k];
      ?>
      <tr>
        <td style="padding-left:28px;"><?= htmlspecialchars($c['category']) ?></td>
        <td><?= number_format($c['units']) ?></td>
        <td><?= currency($c['revenue']) ?></td>
        <td><?= currency($c['cogs']) ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency($c['gross_profit']) ?></td>
        <td><?= number_format($c['gross_margin'], 1) ?>%</td>
        <td><?= currency($c['net_profit']) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr style="background:var(--bg-600);">
        <td style="font-weight:700;"><?= htmlspecialchars($grp['label']) ?> Subtotal</td>
        <td style="font-weight:600;"><?= number_format($tTot['units']) ?></td>
        <td style="font-weight:600;"><?= currency($tTot['revenue']) ?></td>
        <td style="font-weight:600;"><?= currency($tTot['cogs']) ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency($tTot['gross_profit']) ?></td>
        <td style="font-weight:600;"><?= $tTot['revenue'] > 0 ? number_format(($tTot['gross_profit'] / $tTot['revenue']) * 100, 1) : '0.0' ?>%</td>
        <td style="font-weight:700;"><?= currency($tTot['gross_profit']) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr style="background:var(--bg-500);">
        <td style="font-weight:800;">Total</td>
        <td style="font-weight:700;"><?= number_format($profitCatGrand['units']) ?></td>
        <td style="font-weight:700;"><?= currency($profitCatGrand['revenue']) ?></td>
        <td style="font-weight:700;"><?= currency($profitCatGrand['cogs']) ?></td>
        <td style="font-weight:800;color:var(--accent2);"><?= currency($profitCatGrand['gross_profit']) ?></td>
        <td style="font-weight:700;"><?= $profitCatGrand['revenue'] > 0 ? number_format(($profitCatGrand['gross_profit'] / $profitCatGrand['revenue']) * 100, 1) : '0.0' ?>%</td>
        <td style="font-weight:800;"><?= currency($profitCatGrand['gross_profit']) ?></td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table></div>
  <p style="font-size:11px;color:var(--text-300);padding:8px 16px 12px;">* Category Net Profit equals Gross Profit (operating expenses are not allocated by category).</p>
</div>

<!-- ─── MOST PROFITABLE PRODUCTS ────────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <span class="card-title">Most Profitable Products</span>
    <form method="GET" class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
      <?php foreach ($keepQs as $k => $v): if (in_array($k, ['prod_sort', 'prod_limit', 'product'], true)) continue; ?>
      <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
      <?php endforeach; ?>
      <div class="form-group" style="margin:0;">
        <label style="font-size:11px;">Search product</label>
        <input type="text" id="profitProductQ" list="reportProductList" placeholder="e.g. Ciprofloxacin…" value="<?= htmlspecialchars($productDetail['name'] ?? '') ?>" style="min-width:180px;">
        <input type="hidden" name="product" id="profitProductId" value="<?= (int)$filters['product'] ?>">
      </div>
      <div class="form-group" style="margin:0;">
        <label style="font-size:11px;">Sort</label>
        <select name="prod_sort">
          <option value="profit" <?= $prodSort === 'profit' ? 'selected' : '' ?>>Gross Profit</option>
          <option value="revenue" <?= $prodSort === 'revenue' ? 'selected' : '' ?>>Revenue</option>
          <option value="margin" <?= $prodSort === 'margin' ? 'selected' : '' ?>>Margin %</option>
          <option value="units" <?= $prodSort === 'units' ? 'selected' : '' ?>>Units Sold</option>
          <option value="per_unit" <?= $prodSort === 'per_unit' ? 'selected' : '' ?>>Profit per Unit</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;">
        <label style="font-size:11px;">Top</label>
        <select name="prod_limit">
          <?php foreach ([10, 25, 50, 100] as $n): ?>
          <option value="<?= $n ?>" <?= $prodLimit === $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    </form>
  </div>

  <?php if ($productDetail): ?>
  <div style="padding:12px 16px;border-bottom:1px solid var(--border);background:var(--bg-500);">
    <div style="font-weight:700;margin-bottom:8px;">Single Product Profit — <?= htmlspecialchars($productDetail['name']) ?></div>
    <div class="report-summary-grid">
      <div><span class="report-k">Units Sold</span><span class="report-v"><?= number_format((float)$productDetail['units']) ?></span></div>
      <div><span class="report-k">Revenue</span><span class="report-v"><?= currency($productDetail['revenue']) ?></span></div>
      <div><span class="report-k">COGS</span><span class="report-v"><?= currency($productDetail['cogs']) ?></span></div>
      <div><span class="report-k">Gross Profit</span><span class="report-v" style="color:var(--accent2);"><?= currency($productDetail['gross_profit']) ?></span></div>
      <div><span class="report-k">Gross Margin</span><span class="report-v"><?= number_format((float)$productDetail['margin_pct'], 1) ?>%</span></div>
      <div><span class="report-k">Avg Selling Price</span><span class="report-v"><?= currency($productDetail['avg_sell']) ?></span></div>
      <div><span class="report-k">Avg Cost</span><span class="report-v"><?= currency($productDetail['avg_cost']) ?></span></div>
      <div><span class="report-k">Profit / Unit</span><span class="report-v"><?= currency($productDetail['profit_per_unit']) ?></span></div>
      <div><span class="report-k">Transactions</span><span class="report-v"><?= number_format((int)$productDetail['transactions']) ?></span></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="table-wrap" style="max-height:480px;overflow:auto;"><table>
    <thead><tr><th>Product</th><th>Units</th><th>Revenue</th><th>COGS</th><th>Gross Profit</th><th>Margin %</th><th>Avg Sell</th><th>Avg Cost</th><th>Profit/Unit</th><th>Txns</th></tr></thead>
    <tbody>
    <?php if (empty($products)): ?>
      <tr><td colspan="10" style="text-align:center;padding:20px;color:var(--text-300);">No product sales in this range</td></tr>
    <?php else: foreach ($products as $p): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></td>
        <td><?= number_format((float)$p['units']) ?></td>
        <td><?= currency($p['revenue']) ?></td>
        <td><?= currency($p['cogs']) ?></td>
        <td style="color:var(--accent2);font-weight:700;"><?= currency($p['gross_profit']) ?></td>
        <td><?= number_format((float)$p['margin_pct'], 1) ?>%</td>
        <td><?= currency($p['avg_sell']) ?></td>
        <td><?= currency($p['avg_cost']) ?></td>
        <td><?= currency($p['profit_per_unit']) ?></td>
        <td><?= number_format((int)$p['transactions']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- ─── LOW-MARGIN PRODUCTS ─────────────────────────────────────────── -->
<details class="card mb-20" open>
  <summary class="card-header" style="cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <span class="card-title">Low-Margin Products</span>
    <form method="GET" class="no-print" onclick="event.stopPropagation()" style="display:flex;gap:8px;align-items:flex-end;">
      <?php foreach ($keepQs as $k => $v): if ($k === 'margin_threshold') continue; ?>
      <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
      <?php endforeach; ?>
      <div class="form-group" style="margin:0;">
        <label style="font-size:11px;">Margin threshold %</label>
        <input type="number" name="margin_threshold" value="<?= htmlspecialchars((string)$marginThreshold) ?>" min="0" max="100" step="0.5" style="width:90px;">
      </div>
      <button type="submit" class="btn btn-ghost btn-sm">Update</button>
    </form>
  </summary>
  <div class="table-wrap"><table>
    <thead><tr><th>Product</th><th>Revenue</th><th>Gross Profit</th><th>Margin %</th></tr></thead>
    <tbody>
    <?php if (empty($lowMargin)): ?>
      <tr><td colspan="4" style="text-align:center;padding:20px;color:var(--text-300);">No products below <?= number_format($marginThreshold, 1) ?>% margin</td></tr>
    <?php else: foreach ($lowMargin as $p): ?>
      <tr style="background:rgba(220,38,38,0.06);">
        <td style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></td>
        <td><?= currency($p['revenue']) ?></td>
        <td><?= currency($p['gross_profit']) ?></td>
        <td style="color:var(--danger);font-weight:700;"><?= number_format((float)$p['margin_pct'], 1) ?>%</td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</details>

<!-- ─── OPERATING EXPENSES ──────────────────────────────────────────── -->
<div class="card mb-20" id="operating-expenses">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <span class="card-title">Operating Expenses</span>
    <button type="button" class="btn btn-primary btn-sm no-print" onclick="document.getElementById('expenseModal').style.display='flex'"><i data-lucide="plus"></i> Add Expense</button>
  </div>

  <div class="stats-grid" style="padding:12px 16px;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;">
    <div class="stat-card red"><div class="kpi-body"><div class="stat-label">Total OpEx</div><div class="stat-value" style="font-size:18px;"><?= currency($expSummary['total']) ?></div></div></div>
    <div class="stat-card green"><div class="kpi-body"><div class="stat-label">Paid</div><div class="stat-value" style="font-size:18px;"><?= currency($expSummary['paid']) ?></div></div></div>
    <div class="stat-card orange"><div class="kpi-body"><div class="stat-label">Pending</div><div class="stat-value" style="font-size:18px;"><?= currency($expSummary['pending']) ?></div></div></div>
    <div class="stat-card red"><div class="kpi-body"><div class="stat-label">Overdue</div><div class="stat-value" style="font-size:18px;"><?= currency($expSummary['overdue']) ?></div></div></div>
  </div>

  <form method="GET" class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;padding:0 16px 12px;align-items:flex-end;">
    <?php foreach ($keepQs as $k => $v): if (str_starts_with($k, 'exp_')) continue; ?>
    <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
    <?php endforeach; ?>
    <div class="form-group" style="margin:0;"><label style="font-size:11px;">Category</label>
      <select name="exp_category">
        <option value="">All</option>
        <?php foreach ($expenseCats as $ec): ?>
        <option value="<?= htmlspecialchars($ec['name']) ?>" <?= $expFilters['category'] === $ec['name'] ? 'selected' : '' ?>><?= htmlspecialchars($ec['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;"><label style="font-size:11px;">Status</label>
      <select name="exp_status">
        <option value="">All</option>
        <?php foreach (profitExpenseStatuses() as $sk => $sl): ?>
        <option value="<?= $sk ?>" <?= $expFilters['status'] === $sk ? 'selected' : '' ?>><?= $sl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;"><label style="font-size:11px;">Payment method</label>
      <select name="exp_payment">
        <option value="">All</option>
        <?php foreach ($payMethods as $pk => $pl): ?>
        <option value="<?= $pk ?>" <?= $expFilters['payment_method'] === $pk ? 'selected' : '' ?>><?= htmlspecialchars($pl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-ghost btn-sm">Filter expenses</button>
  </form>

  <div class="grid-2" style="padding:0 16px 16px;gap:16px;">
    <div>
      <div style="font-weight:700;font-size:13px;margin-bottom:8px;">Expense breakdown</div>
      <div class="table-wrap"><table>
        <thead><tr><th>Category</th><th>Amount</th><th>% of OpEx</th></tr></thead>
        <tbody>
        <?php if (empty($expSummary['breakdown'])): ?>
          <tr><td colspan="3" style="text-align:center;padding:16px;color:var(--text-300);">No expenses</td></tr>
        <?php else: foreach ($expSummary['breakdown'] as $e): ?>
          <tr>
            <td><?= htmlspecialchars($e['category']) ?></td>
            <td style="color:var(--danger);"><?= currency($e['total']) ?></td>
            <td><?= number_format((float)$e['pct'], 1) ?>%</td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
    <div>
      <div style="font-weight:700;font-size:13px;margin-bottom:8px;">Expense list</div>
      <div class="table-wrap" style="max-height:320px;overflow:auto;"><table>
        <thead><tr><th>Name</th><th>Category</th><th>Amount</th><th>Freq</th><th>Due</th><th>Status</th><th class="no-print"></th></tr></thead>
        <tbody>
        <?php if (empty($expSummary['rows'])): ?>
          <tr><td colspan="7" style="text-align:center;padding:16px;color:var(--text-300);">No expenses in range</td></tr>
        <?php else: foreach ($expSummary['rows'] as $e):
          $st = strtolower($e['status'] ?? 'paid');
        ?>
          <tr>
            <td style="font-weight:600;"><?= htmlspecialchars($e['expense_name']) ?></td>
            <td><?= htmlspecialchars($e['category']) ?></td>
            <td style="color:var(--danger);"><?= currency($e['amount']) ?></td>
            <td><?= htmlspecialchars(profitExpenseFrequencies()[$e['frequency'] ?? 'one_time'] ?? $e['frequency']) ?></td>
            <td><?= htmlspecialchars($e['due_date'] ?? $e['expense_date']) ?></td>
            <td><span class="badge <?= $statusBadge[$st] ?? 'badge-gray' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
            <td class="no-print">
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this expense?')">
                <input type="hidden" name="act" value="delete_expense">
                <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" title="Delete"><i data-lucide="trash-2"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<!-- Expense modal -->
<div id="expenseModal" class="overlay-bg no-print" style="display:none;align-items:center;justify-content:center;z-index:1000;" onclick="if(event.target===this)this.style.display='none'">
  <div class="card" style="width:min(560px,94vw);max-height:90vh;overflow:auto;margin:20px;" onclick="event.stopPropagation()">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span class="card-title">Add Expense</span>
      <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('expenseModal').style.display='none'"><i data-lucide="x"></i></button>
    </div>
    <form method="POST" style="padding:16px;display:grid;gap:12px;">
      <input type="hidden" name="act" value="add_expense">
      <div class="form-group"><label>Expense Name *</label><input type="text" name="expense_name" required placeholder="e.g. Shop Rent"></div>
      <div class="form-group"><label>Expense Category *</label>
        <select name="category" id="expCatSelect" required>
          <?php foreach ($expenseCats as $ec): ?>
          <option value="<?= htmlspecialchars($ec['name']) ?>"><?= htmlspecialchars($ec['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="display:flex;gap:8px;align-items:flex-end;">
        <div style="flex:1;"><label style="font-size:11px;">Or add category</label><input type="text" id="newExpCat" placeholder="New category name"></div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="addProfitExpenseCategory()">Add</button>
      </div>
      <div class="grid-2" style="gap:12px;">
        <div class="form-group"><label>Amount (<?= htmlspecialchars($cur) ?>) *</label><input type="number" name="amount" min="0.01" step="0.01" required></div>
        <div class="form-group"><label>Frequency</label>
          <select name="frequency">
            <?php foreach (profitExpenseFrequencies() as $fk => $fl): ?>
            <option value="<?= $fk ?>"><?= $fl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid-2" style="gap:12px;">
        <div class="form-group"><label>Due Date</label><input type="date" name="due_date" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label>Expense / Start Date</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="grid-2" style="gap:12px;">
        <div class="form-group"><label>Payment Date</label><input type="date" name="payment_date"></div>
        <div class="form-group"><label>Payment Method</label>
          <select name="payment_method">
            <option value="">—</option>
            <?php foreach ($payMethods as $pk => $pl): ?>
            <option value="<?= $pk ?>"><?= htmlspecialchars($pl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group"><label>Status</label>
        <select name="status">
          <?php foreach (profitExpenseStatuses() as $sk => $sl): ?>
          <option value="<?= $sk ?>" <?= $sk === 'paid' ? 'selected' : '' ?>><?= $sl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Notes</label><textarea name="notes" rows="2" placeholder="Optional notes"></textarea></div>
      <p style="font-size:12px;color:var(--text-300);">Monthly/recurring expenses create dated occurrences for 24 months and skip duplicates.</p>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('expenseModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Expense</button>
      </div>
    </form>
    <form method="POST" id="addCatForm" style="display:none;">
      <input type="hidden" name="act" value="add_expense_category">
      <input type="hidden" name="category_name" id="addCatName">
    </form>
  </div>
</div>

<!-- ─── PROFIT BY PAYMENT / CASHIER / SALES TYPE / CUSTOMER ──────────── -->
<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Profit by Payment Method</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Method</th><th>Txns</th><th>Revenue</th><th>COGS</th><th>Gross Profit</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): if ($p['transactions'] <= 0 && $p['revenue'] <= 0) continue; ?>
        <tr>
          <td><?= htmlspecialchars($p['label']) ?></td>
          <td><?= number_format($p['transactions']) ?></td>
          <td><?= currency($p['revenue']) ?></td>
          <td><?= currency($p['cogs']) ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($p['gross_profit']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
      <span class="card-title">Profit by Cashier</span>
      <form method="GET" class="no-print">
        <?php foreach ($keepQs as $k => $v): if ($k === 'cashier_sort') continue; ?>
        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
        <?php endforeach; ?>
        <select name="cashier_sort" onchange="this.form.submit()">
          <option value="gross" <?= $cashierSort === 'gross' ? 'selected' : '' ?>>Gross Profit</option>
          <option value="revenue" <?= $cashierSort === 'revenue' ? 'selected' : '' ?>>Revenue</option>
          <option value="margin" <?= $cashierSort === 'margin' ? 'selected' : '' ?>>Margin %</option>
          <option value="txns" <?= $cashierSort === 'txns' ? 'selected' : '' ?>>Transactions</option>
        </select>
      </form>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>Cashier</th><th>Txns</th><th>Revenue</th><th>Gross Profit</th><th>Margin %</th></tr></thead>
      <tbody>
      <?php if (empty($cashiers)): ?>
        <tr><td colspan="5" style="text-align:center;padding:16px;color:var(--text-300);">No data</td></tr>
      <?php else: foreach ($cashiers as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['cashier']) ?></td>
          <td><?= number_format($c['transactions']) ?></td>
          <td><?= currency($c['revenue']) ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($c['gross_profit']) ?></td>
          <td><?= number_format($c['margin_pct'], 1) ?>%</td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
    <p style="font-size:11px;color:var(--text-300);padding:8px 16px;">Operational reporting only — not a performance appraisal.</p>
  </div>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Profit by Sales Type</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Type</th><th>Txns</th><th>Revenue</th><th>COGS</th><th>Gross Profit</th><th>Margin %</th></tr></thead>
      <tbody>
      <?php if (empty($salesTypes)): ?>
        <tr><td colspan="6" style="text-align:center;padding:16px;color:var(--text-300);">No data</td></tr>
      <?php else: foreach ($salesTypes as $s): ?>
        <tr>
          <td><?= htmlspecialchars($s['label']) ?></td>
          <td><?= number_format($s['transactions']) ?></td>
          <td><?= currency($s['revenue']) ?></td>
          <td><?= currency($s['cogs']) ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($s['gross_profit']) ?></td>
          <td><?= number_format($s['margin_pct'], 1) ?>%</td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Profit by Customer</span></div>
    <div class="table-wrap" style="max-height:360px;overflow:auto;"><table>
      <thead><tr><th>Customer</th><th>Txns</th><th>Revenue</th><th>Gross Profit</th><th>Margin %</th></tr></thead>
      <tbody>
      <?php if (empty($customers)): ?>
        <tr><td colspan="5" style="text-align:center;padding:16px;color:var(--text-300);">No identified customer sales</td></tr>
      <?php else: foreach ($customers as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['customer']) ?></td>
          <td><?= number_format($c['transactions']) ?></td>
          <td><?= currency($c['revenue']) ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($c['gross_profit']) ?></td>
          <td><?= number_format($c['margin_pct'], 1) ?>%</td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<!-- ─── DETAILED PROFIT DATA ────────────────────────────────────────── -->
<details class="card mb-20">
  <summary class="card-header" style="cursor:pointer;list-style:none;">
    <span class="card-title">Detailed Profit Data</span>
  </summary>
  <div class="table-wrap"><table>
    <thead><tr><th>Metric</th><th>Current period</th><th>Previous period</th><th>Change</th></tr></thead>
    <tbody>
    <?php
    $detailRows = [
      ['Revenue', $core['revenue'], $kpis['prev_core']['revenue']],
      ['COGS', $core['cogs'], $kpis['prev_core']['cogs']],
      ['Gross Profit', $core['gross_profit'], $kpis['prev_core']['gross_profit']],
      ['Operating Expenses', $core['expenses_full'], $kpis['prev_core']['expenses_full']],
      ['Net Profit', $core['net_profit'], $kpis['prev_core']['net_profit']],
      ['Returns (amount)', $core['returns_amount'], $kpis['prev_core']['returns_amount']],
    ];
    foreach ($detailRows as [$lbl, $c, $p]):
      $d = $c - $p;
    ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($lbl) ?></td>
        <td><?= currency($c) ?></td>
        <td><?= currency($p) ?></td>
        <td style="color:<?= $d >= 0 ? 'var(--accent2)' : 'var(--danger)' ?>;"><?= ($d >= 0 ? '+' : '') . currency($d) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr>
        <td style="font-weight:600;">Gross Margin %</td>
        <td><?= number_format($core['gross_margin'], 1) ?>%</td>
        <td><?= number_format($kpis['prev_core']['gross_margin'], 1) ?>%</td>
        <td><?= number_format($core['gross_margin'] - $kpis['prev_core']['gross_margin'], 1) ?> pp</td>
      </tr>
      <tr>
        <td style="font-weight:600;">Net Margin %</td>
        <td><?= number_format($core['net_margin'], 1) ?>%</td>
        <td><?= number_format($kpis['prev_core']['net_margin'], 1) ?>%</td>
        <td><?= number_format($core['net_margin'] - $kpis['prev_core']['net_margin'], 1) ?> pp</td>
      </tr>
    </tbody>
  </table></div>
</details>

</div></div>
<script>
function addProfitExpenseCategory() {
  const v = (document.getElementById('newExpCat') || {}).value || '';
  if (!v.trim()) return;
  document.getElementById('addCatName').value = v.trim();
  document.getElementById('addCatForm').submit();
}
(function(){
  const q = document.getElementById('profitProductQ');
  const h = document.getElementById('profitProductId');
  if (!q || !h || !window.REPORT_FILTER_DATA) return;
  q.addEventListener('input', () => {
    const val = q.value.trim().toLowerCase();
    if (!val) { h.value = ''; return; }
    const found = window.REPORT_FILTER_DATA.products.find(p => p.name.toLowerCase() === val)
      || window.REPORT_FILTER_DATA.products.find(p => p.name.toLowerCase().includes(val));
    h.value = found ? String(found.id) : '';
  });
})();
</script>
<?php renderFooter(); ?>
