<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';
require_once __DIR__ . '/investment_lib.php';

$pdo = getDB();
if (!can('reports.view')) {
    redirectHome();
}

$cfg = invConfig($_GET);

// ── The single permitted write: create a purchase DRAFT from selected rows ──
$msg = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['act'] ?? '') === 'create_draft') {
    try {
        $draftId = invCreatePurchaseDraft($pdo, $_POST, (int)(currentUser()['id'] ?? 0));
        flashSet('success', 'Purchase draft created. Open it in Purchases to review batch numbers, expiry dates and selling prices before receiving.');
        header('Location: purchases.php?action=view&id=' . $draftId);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$dates   = reportParseDateRange($_GET);
$filters = reportParseFilters($_GET);
$options = reportFilterOptions($pdo);
$data    = invAnalyze($pdo, $dates, $filters, $cfg);

$summary = $data['summary'];
$rows    = $data['rows'];
$cfgData = $data['config'];
$cur     = getSetting('currency', 'ETB');
$cfgQs   = invConfigQueryParams($cfgData);

// Selected product detail (from ranked table "Details" buttons).
$detailId = (int)($_GET['detail'] ?? 0);
$detail   = $detailId ? invProductDetail($rows, $pdo, $detailId) : null;

$recBadge = ['invest' => 'badge-green', 'maintain' => 'badge-blue', 'reduce' => 'badge-orange', 'dont_buy' => 'badge-red', 'urgent' => 'badge-red'];
$recNames = [
    'invest' => '🟢 INVEST MORE', 'maintain' => '🟡 MAINTAIN', 'reduce' => '🟠 REDUCE',
    'dont_buy' => "🔴 DON'T BUY", 'urgent' => '🔥 BUY URGENTLY',
];

// Simulator (GET-driven so results survive refresh; never saves anything).
$budgetInput = trim($_GET['budget'] ?? '');
$sim = null;
if ($budgetInput !== '' && is_numeric($budgetInput) && (float)$budgetInput > 0) {
    $sim = invSimulate($data['recommended'], (float)$budgetInput);
    $cfgQs['budget'] = $budgetInput;
}

// Preselected rows for the purchase-draft form (from simulator or top opportunities).
$preselect = [];
if ($sim) {
    foreach ($sim['plan'] as $p) {
        $preselect[(int)$p['row']['id']] = $p['qty'];
    }
} else {
    foreach (array_slice($data['recommended'], 0, 8) as $m) {
        $preselect[(int)$m['id']] = (int)$m['purchase']['recommended_qty'];
    }
}

// Next-month plan buckets (spec §12).
$planBuckets = [
    'urgent'   => ['title' => '🔥 BUY IMMEDIATELY', 'rows' => []],
    'invest'   => ['title' => '🟢 BUY MORE', 'rows' => []],
    'maintain' => ['title' => '🟡 MAINTAIN', 'rows' => []],
    'dont_buy' => ['title' => "🔴 DON'T BUY", 'rows' => []],
];
foreach ($rows as $m) {
    $key = $m['rec'][0];
    if ($key === 'reduce') {
        // Fold REDUCE into DON'T BUY for the purchase plan (do not restock more).
        $key = 'dont_buy';
    }
    if (!isset($planBuckets[$key])) continue;
    if (in_array($key, ['urgent', 'invest', 'maintain'], true) && $m['purchase']['recommended_qty'] <= 0 && $key !== 'maintain') {
        continue;
    }
    $planBuckets[$key]['rows'][] = $m;
}
foreach ($planBuckets as &$bucket) {
    usort($bucket['rows'], fn($a, $b) => $b['score'] <=> $a['score']);
    if ($bucket['title'] === "🔴 DON'T BUY") {
        $bucket['rows'] = array_slice($bucket['rows'], 0, 25);
    } else {
        $bucket['rows'] = array_slice($bucket['rows'], 0, 40);
    }
}
unset($bucket);

$topWhy = array_slice($data['recommended'], 0, 8);

renderHead('Investment & Growth', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Investment & Growth', 'Where should TADE Pharmacy invest next month?'); ?>
<div class="page-body">

<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php renderReportNav('investment', $dates, $filters); ?>
<?php renderReportFilters($dates, $filters, $options, 'investment.php', null, true, true, 'investment'); ?>
<?php renderReportMeta('Investment & Growth Analysis', $dates); ?>

<div class="alert alert-info mb-20" style="display:flex;align-items:flex-start;gap:8px;">
  <i data-lucide="shield-check" style="margin-top:2px;"></i>
  <div><strong>Read-only analysis.</strong> This page does not change stock, prices, sales or purchases. Projections marked <strong>ESTIMATE</strong> are calculated from your selected period's data and are not guarantees. The only action available is creating a purchase <strong>draft</strong> from products you select.</div>
</div>

<!-- ─── 1. TOP SUMMARY ─────────────────────────────────────────────── -->
<div class="stats-grid kpi-grid">
  <?php
  renderKpiCard('Recommended Investment', ['current' => $summary['invest'], 'previous' => 0, 'change' => 0, 'dir' => 'up', 'good' => true], 'orange', ' ' . $cur);
  renderKpiCard('Expected Additional Revenue', ['current' => $summary['revenue'], 'previous' => 0, 'change' => 0, 'dir' => 'up', 'good' => true], 'blue', ' ' . $cur);
  renderKpiCard('Expected Gross Profit', ['current' => $summary['profit'], 'previous' => 0, 'change' => 0, 'dir' => 'up', 'good' => true], 'green', ' ' . $cur);
  renderKpiCard('Estimated ROI', ['current' => $summary['roi'], 'previous' => 0, 'change' => 0, 'dir' => 'up', 'good' => true], 'green', '%');
  ?>
</div>
<div class="stats-grid kpi-grid mb-20">
  <?php
  renderKpiCard('Current Inventory Value', ['current' => $summary['stock_value'], 'previous' => 0, 'change' => 0, 'dir' => 'up', 'good' => true], 'orange', ' ' . $cur);
  renderKpiCard('Products Recommended', ['current' => $summary['rec_count'], 'previous' => 0, 'change' => 0, 'dir' => 'up', 'good' => true], 'blue');
  renderKpiCard('Potential Missed Sales', ['current' => $summary['missed_revenue'], 'previous' => 0, 'change' => 0, 'dir' => 'down', 'good' => false], 'red', ' ' . $cur);
  ?>
</div>

<!-- ─── 2. WHERE SHOULD I INVEST? ──────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><i data-lucide="target" style="width:16px;height:16px;"></i> Where Should I Invest?</span>
    <span style="font-size:12px;color:var(--text-300);">Based on sales performance, profitability, stock coverage, demand trend &amp; turnover — <?= (int)$dates['days'] ?>-day period</span>
  </div>

  <!-- Configuration (transparent & adjustable) -->
  <form method="GET" action="investment.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:0 16px 12px;">
    <?php foreach (['preset','from','to','type','product','category','supplier','customer','cashier','payment_method','sales_type','budget'] as $hf):
      if (isset($_GET[$hf]) && $_GET[$hf] !== ''): ?>
    <input type="hidden" name="<?= $hf ?>" value="<?= htmlspecialchars((string)$_GET[$hf]) ?>">
    <?php endif; endforeach; ?>
    <div class="form-group" style="margin:0;"><label>Target coverage (days)</label>
      <input type="number" name="coverage_days" value="<?= (int)$cfgData['coverage_days'] ?>" min="7" max="180" style="width:90px;"></div>
    <div class="form-group" style="margin:0;"><label>Safety stock (days)</label>
      <input type="number" name="safety_days" value="<?= (int)$cfgData['safety_days'] ?>" min="0" max="60" style="width:80px;"></div>
    <div class="form-group" style="margin:0;"><label>Max stock (days)</label>
      <input type="number" name="max_stock_days" value="<?= (int)$cfgData['max_stock_days'] ?>" min="30" max="365" style="width:90px;"></div>
    <div class="form-group" style="margin:0;"><label>Expiry risk window</label>
      <input type="number" name="expiry_risk_days" value="<?= (int)$cfgData['expiry_risk_days'] ?>" min="30" max="365" style="width:90px;"></div>
    <div class="form-group" style="margin:0;"><label>Min margin %</label>
      <input type="number" name="min_margin_pct" value="<?= (int)$cfgData['min_margin_pct'] ?>" min="0" max="80" style="width:80px;"></div>
    <button class="btn btn-ghost btn-sm">Recalculate</button>
    <a href="investment.php" class="btn btn-ghost btn-sm">Reset</a>
  </form>

  <div class="report-summary-grid" style="padding:0 16px 14px;">
    <?php $recCounts = ['urgent' => 0, 'invest' => 0, 'maintain' => 0, 'reduce' => 0, 'dont_buy' => 0];
    foreach ($rows as $m) $recCounts[$m['rec'][0]]++; ?>
    <?php foreach ($recCounts as $k => $n): ?>
    <div><span class="report-k"><?= $recNames[$k] ?></span><span class="report-v"><?= $n ?></span></div>
    <?php endforeach; ?>
  </div>

  <?php if ($topWhy): ?>
  <div style="padding:0 16px 14px;">
    <div class="card-header" style="padding:4px 0 8px;"><span class="card-title" style="font-size:14px;">Best opportunities right now</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Recommendation</th><th>Product</th><th>Category</th><th>Stock</th><th>Score</th><th>Why</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($topWhy as $m): ?>
        <tr>
          <td><span class="badge <?= $recBadge[$m['rec'][0]] ?>"><?= $recNames[$m['rec'][0]] ?></span></td>
          <td style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></td>
          <td style="font-size:12px;"><?= htmlspecialchars($m['category']) ?></td>
          <td><?= number_format($m['stock']) ?> <?= htmlspecialchars($m['unit']) ?></td>
          <td><span class="badge badge-green"><?= (int)$m['score'] ?></span></td>
          <td style="font-size:12px;color:var(--text-300);max-width:360px;"><?= htmlspecialchars(implode(' · ', array_slice($m['why'], 0, 3))) ?></td>
          <td><a href="investment.php?<?= reportQueryString($dates, $filters, array_merge($cfgQs, ['detail' => $m['id']])) ?>#productDetail" class="btn btn-ghost btn-sm">Details</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Recommendation</th><th>Products</th><th>What it means</th></tr></thead>
      <tbody>
        <tr><td><span class="badge badge-red">🔥 BUY URGENTLY</span></td><td><?= $recCounts['urgent'] ?></td><td style="font-size:12px;color:var(--text-300);">Selling fast, usable stock ≤ 2 days of demand — restock before you lose sales</td></tr>
        <tr><td><span class="badge badge-green">🟢 INVEST MORE</span></td><td><?= $recCounts['invest'] ?></td><td style="font-size:12px;color:var(--text-300);">Good margin and stock covers less than the target window</td></tr>
        <tr><td><span class="badge badge-blue">🟡 MAINTAIN</span></td><td><?= $recCounts['maintain'] ?></td><td style="font-size:12px;color:var(--text-300);">Coverage is close to target — buy roughly what you sell</td></tr>
        <tr><td><span class="badge badge-orange">🟠 REDUCE</span></td><td><?= $recCounts['reduce'] ?></td><td style="font-size:12px;color:var(--text-300);">Overstocked or demand is fading — buy less next time</td></tr>
        <tr><td><span class="badge badge-red">🔴 DON'T BUY</span></td><td><?= $recCounts['dont_buy'] ?></td><td style="font-size:12px;color:var(--text-300);">Not selling, dead stock, or far more stock than demand needs</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ─── 3. TOP INVESTMENT OPPORTUNITIES (ranked) ───────────────────── -->
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><i data-lucide="trophy" style="width:16px;height:16px;"></i> Top Investment Opportunities</span>
    <span style="font-size:12px;color:var(--text-300);">Investment Score 0–100: demand + margin + urgency + growth − expiry risk</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Product</th><th>Category</th><th>Units Sold</th><th>Sales Revenue</th>
          <th>Gross Profit</th><th>Margin %</th><th>Current Stock</th><th>Avg Daily Sales</th>
          <th>Days of Stock</th><th>Sales Growth</th><th>Score</th><th>Recommendation</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php $ranked = $rows;
      usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
      $ranked = array_slice($ranked, 0, 40);
      if (!$ranked): ?>
        <tr><td colspan="14" style="text-align:center;padding:40px;color:var(--text-300);">No product data in this period — adjust the filters</td></tr>
      <?php else: foreach ($ranked as $i => $m):
        $cov = $m['coverage'] >= 9999 ? '—' : number_format($m['coverage'], 0);
      ?>
      <tr>
        <td><span class="badge <?= $i < 3 ? 'badge-blue' : 'badge-gray' ?>"><?= $i + 1 ?></span></td>
        <td><div style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></div>
            <?php if ($m['generic_name']): ?><div style="font-size:11px;color:var(--text-300);"><?= htmlspecialchars($m['generic_name']) ?></div><?php endif; ?></td>
        <td style="font-size:12px;"><?= htmlspecialchars($m['category']) ?></td>
        <td><?= number_format($m['units_sold']) ?></td>
        <td style="color:var(--accent2);font-weight:600;"><?= currency($m['revenue']) ?></td>
        <td style="font-weight:600;"><?= currency($m['gross_profit']) ?></td>
        <td><?= number_format($m['margin_pct'], 1) ?>%</td>
        <td><?= number_format($m['stock']) ?> <?= htmlspecialchars($m['unit']) ?></td>
        <td><?= number_format($m['avg_daily'], 1) ?></td>
        <td><?= $cov ?></td>
        <td><?= ($m['growth_pct'] >= 0 ? '<span style="color:var(--accent2);">▲ +' : '<span style="color:var(--danger);">▼ ') . number_format($m['growth_pct'], 0) . '%</span>' ?></td>
        <td>
          <span class="badge <?= $m['score'] >= 60 ? 'badge-green' : ($m['score'] >= 35 ? 'badge-orange' : 'badge-gray') ?>"><?= $m['score'] ?></span>
        </td>
        <td><span class="badge <?= $recBadge[$m['rec'][0]] ?>"><?= $recNames[$m['rec'][0]] ?></span></td>
        <td><a href="investment.php?<?= reportQueryString($dates, $filters, array_merge($cfgQs, ['detail' => $m['id']])) ?>#productDetail" class="btn btn-ghost btn-sm">Details</a></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ─── 4. PRODUCT INVESTMENT DETAILS ─────────────────────────────── -->
<?php if ($detail): ?>
<div class="card mb-20" id="productDetail">
  <div class="card-header">
    <span class="card-title"><i data-lucide="microscope" style="width:16px;height:16px;"></i> Product Investment Details — <?= htmlspecialchars($detail['name']) ?></span>
    <a href="investment.php?<?= reportQueryString($dates, $filters, $cfgQs) ?>" class="btn btn-ghost btn-sm">Close ✕</a>
  </div>
  <div class="report-summary-grid" style="padding:14px 16px;">
    <div><span class="report-k">Current Stock</span><span class="report-v"><?= number_format($detail['stock']) ?> <?= htmlspecialchars($detail['unit']) ?></span></div>
    <div><span class="report-k">Units Sold (period)</span><span class="report-v"><?= number_format($detail['units_sold']) ?></span></div>
    <div><span class="report-k">Sales Revenue</span><span class="report-v"><?= currency($detail['revenue']) ?></span></div>
    <div><span class="report-k">COGS</span><span class="report-v"><?= currency($detail['cogs']) ?></span></div>
    <div><span class="report-k">Gross Profit</span><span class="report-v"><?= currency($detail['gross_profit']) ?></span></div>
    <div><span class="report-k">Margin</span><span class="report-v"><?= number_format($detail['margin_pct'], 1) ?>%</span></div>
    <div><span class="report-k">Sales Growth</span><span class="report-v"><?= ($detail['growth_pct'] >= 0 ? '+' : '') . number_format($detail['growth_pct'], 0) ?>%</span></div>
    <div><span class="report-k">Avg Daily Sales</span><span class="report-v"><?= number_format($detail['avg_daily'], 2) ?></span></div>
    <div><span class="report-k">Days of Stock</span><span class="report-v"><?= $detail['coverage'] >= 9999 ? '—' : number_format($detail['coverage'], 0) ?></span></div>
    <div><span class="report-k">Inventory Turnover</span><span class="report-v"><?= number_format($detail['turnover'], 2) ?>×</span></div>
    <div><span class="report-k">Last Purchase Price</span><span class="report-v"><?= $detail['last_price'] !== null ? currency($detail['last_price']) : '—' ?></span></div>
    <div><span class="report-k">Avg Purchase Price</span><span class="report-v"><?= $detail['avg_price'] !== null ? currency($detail['avg_price']) : '—' ?></span></div>
    <div><span class="report-k">Selling Price (avg)</span><span class="report-v"><?= $detail['avg_sell'] > 0 ? currency($detail['avg_sell']) : '—' ?></span></div>
    <div><span class="report-k">Next Expiry</span><span class="report-v"><?= $detail['next_expiry'] ? formatExpiryDate($detail['next_expiry']) . ' (' . expiryDaysLabel($detail['next_expiry']) . ')' : 'No expiry' ?></span></div>
    <div><span class="report-k">Last Supplier</span><span class="report-v"><?= htmlspecialchars($detail['supplier_name'] ?? '—') ?></span></div>
    <div><span class="report-k">Recommendation</span><span class="report-v"><?= $recNames[$detail['rec'][0]] ?></span></div>
  </div>

  <div style="padding:0 16px 14px;">
    <div class="card-header" style="padding:12px 0 6px;"><span class="card-title">Recommended Purchase</span></div>
    <div class="report-summary-grid">
      <div><span class="report-k">Recommended Quantity</span><span class="report-v" style="color:var(--accent2);"><?= number_format($detail['purchase']['recommended_qty']) ?> <?= htmlspecialchars($detail['unit']) ?></span></div>
      <div><span class="report-k">Estimated Purchase Cost</span><span class="report-v"><?= currency($detail['purchase']['est_cost']) ?></span></div>
      <div><span class="report-k">Required Investment</span><span class="report-v"><?= currency($detail['purchase']['est_cost']) ?></span></div>
      <div><span class="report-k">Expected Revenue (EST.)</span><span class="report-v"><?= currency($detail['purchase']['est_revenue']) ?></span></div>
      <div><span class="report-k">Expected Gross Profit (EST.)</span><span class="report-v" style="color:var(--accent2);"><?= currency($detail['purchase']['est_profit']) ?></span></div>
      <div><span class="report-k">Estimated ROI (EST.)</span><span class="report-v"><?= $detail['purchase']['est_roi'] ?>%</span></div>
    </div>

    <div class="card-header" style="padding:14px 0 6px;"><span class="card-title">Why This Product?</span></div>
    <div class="insights-list">
      <?php foreach ($detail['why'] as $w): ?>
      <div class="insight-item insight-good"><i data-lucide="check-circle-2"></i><span><?= htmlspecialchars($w) ?></span></div>
      <?php endforeach; ?>
    </div>

    <?php if ($detail['suppliers']): ?>
    <div class="card-header" style="padding:14px 0 6px;"><span class="card-title">Purchase Price by Supplier</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Supplier</th><th>Orders</th><th>Last Price</th><th>Avg Price</th><th>Lowest Price</th><th>Last Purchase</th></tr></thead>
        <tbody>
        <?php foreach ($detail['suppliers'] as $s): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($s['supplier_name']) ?></td>
          <td><?= (int)$s['orders'] ?></td>
          <td><?= currency((float)$s['last_price']) ?></td>
          <td><?= currency((float)$s['avg_price']) ?></td>
          <td style="color:var(--accent2);font-weight:600;"><?= currency((float)$s['low_price']) ?></td>
          <td><?= $s['last_purchase'] ? htmlspecialchars(date('M j, Y', strtotime($s['last_purchase']))) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid-2 mb-20">
  <!-- ─── 6. CATEGORY INVESTMENT ─────────────────────────────────── -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i data-lucide="layers" style="width:16px;height:16px;"></i> Category Investment</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Category</th><th>Sales</th><th>Gross Profit</th><th>Margin</th><th>Inv. Value</th><th>Turnover</th><th>Growth</th><th>Rec. Investment</th><th>Score</th></tr></thead>
        <tbody>
        <?php if (!$data['categories']): ?>
          <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-300);">No data</td></tr>
        <?php else: foreach ($data['categories'] as $c): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($c['category']) ?></td>
          <td><?= currency($c['sales']) ?></td>
          <td><?= currency($c['profit']) ?></td>
          <td><?= number_format($c['margin_pct'], 1) ?>%</td>
          <td><?= currency($c['stock_value']) ?></td>
          <td><?= number_format($c['turnover'], 2) ?>×</td>
          <td><?= ($c['growth_pct'] >= 0 ? '<span style="color:var(--accent2);">▲ +' : '<span style="color:var(--danger);">▼ ') . number_format($c['growth_pct'], 0) . '%</span>' ?></td>
          <td style="font-weight:600;"><?= currency($c['invest']) ?></td>
          <td><span class="badge <?= $c['score'] >= 55 ? 'badge-green' : ($c['score'] >= 30 ? 'badge-orange' : 'badge-gray') ?>"><?= $c['score'] ?></span></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ─── 11. INVESTMENT SIMULATOR ───────────────────────────────── -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i data-lucide="calculator" style="width:16px;height:16px;"></i> Investment Simulator</span></div>
    <form method="GET" action="investment.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;padding:0 16px 12px;">
      <?php foreach (['preset','from','to','type','product','category','supplier','customer','cashier','payment_method','sales_type'] as $hf):
        if (isset($_GET[$hf]) && $_GET[$hf] !== ''): ?>
      <input type="hidden" name="<?= $hf ?>" value="<?= htmlspecialchars((string)$_GET[$hf]) ?>">
      <?php endif; endforeach; ?>
      <?php foreach ($cfgQs as $ck => $cv): if ($ck === 'budget') continue; ?>
      <input type="hidden" name="<?= htmlspecialchars($ck) ?>" value="<?= htmlspecialchars((string)$cv) ?>">
      <?php endforeach; ?>
      <div class="form-group" style="margin:0;flex:1;min-width:150px;">
        <label>Investment Budget (<?= htmlspecialchars($cur) ?>)</label>
        <input type="number" name="budget" value="<?= htmlspecialchars($budgetInput) ?>" min="1" step="any" placeholder="e.g. 100000">
      </div>
      <button class="btn btn-primary">Optimize Investment</button>
    </form>
    <?php if ($sim): ?>
    <div style="padding:0 16px 14px;">
      <div class="report-summary-grid">
        <div><span class="report-k">Invested (EST.)</span><span class="report-v"><?= currency($sim['cost']) ?></span></div>
        <div><span class="report-k">Expected Revenue (EST.)</span><span class="report-v"><?= currency($sim['revenue']) ?></span></div>
        <div><span class="report-k">Expected Gross Profit (EST.)</span><span class="report-v" style="color:var(--accent2);"><?= currency($sim['profit']) ?></span></div>
        <div><span class="report-k">Estimated ROI (EST.)</span><span class="report-v"><?= $sim['roi'] ?>%</span></div>
      </div>
      <?php if ($sim['cats']): ?>
      <p style="font-size:12px;color:var(--text-300);margin:8px 0 4px;">Suggested category allocation:</p>
      <?php foreach ($sim['cats'] as $cat => $amt):
        $pct = $sim['cost'] > 0 ? round($amt / $sim['cost'] * 100) : 0; ?>
      <div style="margin-bottom:8px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;"><span><?= htmlspecialchars($cat) ?></span><span><?= $pct ?>% · <?= currency($amt) ?></span></div>
        <div class="progress-bar"><div class="progress-fill green" style="width:<?= $pct ?>%;"></div></div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($sim['plan']): ?>
      <div class="table-wrap" style="max-height:280px;overflow-y:auto;">
        <table>
          <thead><tr><th>Product</th><th>Qty</th><th>Cost (EST.)</th><th>Profit (EST.)</th></tr></thead>
          <tbody>
          <?php foreach ($sim['plan'] as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['row']['name']) ?></td>
            <td><?= number_format($p['qty']) ?></td>
            <td><?= currency($p['cost']) ?></td>
            <td style="color:var(--accent2);font-weight:600;"><?= currency($p['profit']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($sim['unspent'] > 0): ?>
      <p style="font-size:12px;color:var(--text-300);margin:8px 0 0;">Unallocated budget: <?= currency($sim['unspent']) ?> (no more qualifying products)</p>
      <?php endif; ?>
      <?php else: ?>
      <p style="font-size:13px;color:var(--text-300);">No qualifying investment opportunities for this budget in the selected period.</p>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <p style="padding:0 16px 16px;font-size:12px;color:var(--text-300);">Enter a budget and press Optimize — the simulator allocates it across the highest-scoring opportunities and shows estimated returns. Nothing is saved.</p>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2 mb-20">
  <!-- ─── 7. STOCKOUT / MISSED SALES ─────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i data-lucide="alert-triangle" style="width:16px;height:16px;"></i> Stockout / Missed Sales Opportunity</span>
      <span class="badge badge-orange">ESTIMATES</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Product</th><th>Current Stock</th><th>Avg Daily</th><th>Days Remaining</th><th>Stockout Risk</th><th>Missed Units (EST.)</th><th>Missed Revenue (EST.)</th><th>Missed Profit (EST.)</th></tr></thead>
        <tbody>
        <?php if (!$data['stockouts']): ?>
          <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-300);">No stockout risk detected for the target coverage window</td></tr>
        <?php else: foreach (array_slice($data['stockouts'], 0, 15) as $s): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($s['row']['name']) ?></td>
          <td><?= number_format($s['row']['stock']) ?></td>
          <td><?= number_format($s['row']['avg_daily'], 1) ?></td>
          <td><?= $s['days_remaining'] <= 0 ? 'Out' : (int)$s['days_remaining'] ?></td>
          <td><span class="badge <?= $s['risk_badge'] ?>"><?= $s['stockout_risk'] ?></span></td>
          <td><?= number_format($s['missed_units']) ?></td>
          <td><?= currency($s['missed_revenue']) ?></td>
          <td style="font-weight:600;color:var(--warning);"><?= currency($s['missed_profit']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ─── 9. EXPIRY-AWARE INVESTMENT ─────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i data-lucide="calendar-clock" style="width:16px;height:16px;"></i> Expiry-Aware Investment Risk</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Product</th><th>Stock</th><th>Expiry</th><th>Days Until</th><th>Avg Sales</th><th>Expected Sales Before Expiry</th><th>Excess Stock</th><th>Recommendation</th></tr></thead>
        <tbody>
        <?php if (!$data['expiry_risk']): ?>
          <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-300);">No expiry-risk products in the next <?= (int)$cfgData['expiry_risk_days'] * 2 ?> days</td></tr>
        <?php else: foreach (array_slice($data['expiry_risk'], 0, 15) as $e): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($e['row']['name']) ?></td>
          <td><?= number_format($e['row']['stock']) ?></td>
          <td><?= formatExpiryDate($e['expiry_date']) ?></td>
          <td><?= $e['days_until'] ?>d</td>
          <td><?= number_format($e['avg_sales'], 1) ?>/day</td>
          <td>≈ <?= number_format($e['expected_sales']) ?></td>
          <td style="font-weight:600;color:var(--warning);"><?= number_format($e['excess_stock']) ?></td>
          <td><span class="badge badge-orange"><?= htmlspecialchars($e['recommendation']) ?></span></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ─── 8. SLOW / DEAD STOCK ───────────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><i data-lucide="package-x" style="width:16px;height:16px;"></i> Slow / Dead Stock — Money Tied Up</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Product</th><th>Current Stock</th><th>Cost Value</th><th>Units Sold (period)</th><th>Last Sale</th><th>Days Since Last Sale</th><th>Expiry</th><th>Recommendation</th></tr></thead>
      <tbody>
      <?php if (!$data['slow']): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-300);">No slow or dead stock — healthy inventory</td></tr>
      <?php else: foreach (array_slice($data['slow'], 0, 20) as $s): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($s['row']['name']) ?></td>
        <td><?= number_format($s['row']['stock']) ?> <?= htmlspecialchars($s['row']['unit']) ?></td>
        <td style="color:var(--warning);font-weight:600;"><?= currency($s['row']['stock_value']) ?></td>
        <td><?= number_format($s['row']['units_sold']) ?></td>
        <td><?= $s['since_label'] ?></td>
        <td><?= $s['days_since'] !== null ? (int)$s['days_since'] : 'Never' ?></td>
        <td><?= $s['row']['next_expiry'] ? formatExpiryDate($s['row']['next_expiry']) : 'No expiry' ?></td>
        <td><span class="badge <?= $s['rec_badge'] ?>"><?= $s['recommendation'] ?></span></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ─── 12. NEXT-MONTH PURCHASE PLAN ──────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><i data-lucide="clipboard-list" style="width:16px;height:16px;"></i> Next-Month Purchase Plan</span>
    <span style="font-size:12px;color:var(--text-300);">Creates a purchase DRAFT only — nothing is confirmed or received automatically</span>
  </div>
  <form method="POST" action="investment.php?<?= reportQueryString($dates, $filters, $cfgQs) ?>" id="draftForm">
    <input type="hidden" name="act" value="create_draft">
    <?php
    $planHasRows = false;
    foreach ($planBuckets as $bucketKey => $bucket):
      if (!$bucket['rows']) continue;
      $planHasRows = true;
      $canSelect = in_array($bucketKey, ['urgent', 'invest', 'maintain'], true);
    ?>
    <div class="card-header" style="padding:12px 16px 6px;border-top:1px solid var(--border);">
      <span class="card-title" style="font-size:14px;"><?= $bucket['title'] ?></span>
      <span class="badge badge-gray"><?= count($bucket['rows']) ?></span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <?php if ($canSelect): ?><th><input type="checkbox" onclick="toggleBucketInv(this, '<?= $bucketKey ?>')"></th><?php else: ?><th></th><?php endif; ?>
            <th>Product</th><th>Category</th>
            <th>Rec. Quantity</th><th>Unit Cost</th><th>Total Investment</th>
            <th>Expected Revenue (EST.)</th><th>Expected Profit (EST.)</th><th>Recommendation</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($bucket['rows'] as $m):
          $rp = $m['purchase'];
          $qty = (int)$rp['recommended_qty'];
          if ($bucketKey === 'dont_buy') $qty = 0;
        ?>
          <tr>
            <td>
              <?php if ($canSelect && $qty > 0): ?>
              <input type="checkbox" name="inv_sel[<?= $m['id'] ?>]" value="1" <?= isset($preselect[$m['id']]) ? 'checked' : '' ?> class="inv-check inv-check-<?= $bucketKey ?>">
              <?php endif; ?>
            </td>
            <td style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></td>
            <td style="font-size:12px;"><?= htmlspecialchars($m['category']) ?></td>
            <td>
              <?php if ($canSelect && $qty > 0): ?>
              <input type="number" name="inv_qty[<?= $m['id'] ?>]" value="<?= isset($preselect[$m['id']]) ? (int)$preselect[$m['id']] : $qty ?>" min="0" step="1" style="width:80px;padding:5px 8px;" class="inv-qty">
              <?php else: ?>
              <?= $qty > 0 ? number_format($qty) : '0' ?>
              <?php endif; ?>
            </td>
            <td><?= $rp['unit_cost'] > 0 ? currency($rp['unit_cost']) : '—' ?></td>
            <td style="font-weight:600;"><?= $qty > 0 ? currency($rp['est_cost']) : currency(0) ?></td>
            <td><?= $qty > 0 ? currency($rp['est_revenue']) : currency(0) ?></td>
            <td style="color:var(--accent2);font-weight:600;"><?= $qty > 0 ? currency($rp['est_profit']) : currency(0) ?></td>
            <td><span class="badge <?= $recBadge[$m['rec'][0]] ?>"><?= $recNames[$m['rec'][0]] ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>

    <?php if (!$planHasRows): ?>
    <p style="padding:40px 16px;text-align:center;color:var(--text-300);">No purchase plan rows for this period &amp; configuration</p>
    <?php elseif (can('purchases.manage')): ?>
    <div class="form-actions" style="padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <label style="font-size:13px;color:var(--text-300);">Supplier (optional):
        <select name="supplier_id" style="max-width:220px;">
          <option value="">— Keep unassigned —</option>
          <?php foreach ($options['suppliers'] as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-primary"><i data-lucide="clipboard-plus"></i> Add Selected to Purchase Draft</button>
      <span style="font-size:12px;color:var(--text-300);">A draft is created in Purchases — review and receive it there.</span>
    </div>
    <?php else: ?>
    <p style="padding:12px 16px;font-size:12px;color:var(--text-300);border-top:1px solid var(--border);">You can view recommendations, but creating a purchase draft requires purchase permission.</p>
    <?php endif; ?>
  </form>
</div>

<p style="font-size:12px;color:var(--text-300);margin-bottom:8px;">
  Model inputs: coverage target <strong><?= (int)$cfgData['coverage_days'] ?>d</strong> + safety <strong><?= (int)$cfgData['safety_days'] ?>d</strong>,
  max stock <strong><?= (int)$cfgData['max_stock_days'] ?>d</strong>, expiry risk window <strong><?= (int)$cfgData['expiry_risk_days'] ?>d</strong>,
  min margin <strong><?= (int)$cfgData['min_margin_pct'] ?>%</strong>, dead-stock threshold <strong><?= (int)$cfgData['dead_stock_days'] ?>d</strong>.
  Adjust above. All future values are ESTIMATES based on the selected period (<?= htmlspecialchars($dates['label']) ?>).
</p>

</div></div>

<script>
function toggleAllInv(cb) {
  document.querySelectorAll('.inv-check').forEach(c => { c.checked = cb.checked; });
}
function toggleBucketInv(cb, key) {
  document.querySelectorAll('.inv-check-' + key).forEach(c => { c.checked = cb.checked; });
}
(function () {
  // Keep the draft total visible as quantities change (client-side convenience only).
  const form = document.getElementById('draftForm');
  if (!form) return;
  form.addEventListener('input', e => {
    if (!e.target.classList.contains('inv-qty')) return;
    const row = e.target.closest('tr');
    const qty = parseFloat(e.target.value) || 0;
    const costCells = row.querySelectorAll('td');
    // Simple live total recalculation for the row total column (index 5).
    const unitCostCell = costCells[4];
    const totalCell = costCells[5];
    if (unitCostCell && totalCell) {
      const unit = parseFloat((unitCostCell.textContent || '').replace(/[^0-9.]/g, '')) || 0;
      totalCell.textContent = 'ETB ' + (qty * unit).toFixed(2);
    }
  });
})();
document.getElementById('productDetail')?.scrollIntoView({ block: 'start' });
</script>
<?php renderFooter(); ?>
