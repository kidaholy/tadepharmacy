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
$hist    = $data['history'];
$cfgData = $data['config'];
$cur     = getSetting('currency', 'ETB');
$cfgQs   = invConfigQueryParams($cfgData);

// Selected product detail (from ranked table "Details" buttons).
$detailId = (int)($_GET['detail'] ?? 0);
$detail   = $detailId ? invProductDetail($rows, $pdo, $detailId) : null;

$recNames = [
    'invest' => '🟢 INVEST MORE', 'maintain' => '🟡 MAINTAIN', 'reduce' => '🟠 REDUCE',
    'dont_buy' => "🔴 DON'T BUY", 'urgent' => '🔥 BUY URGENTLY',
];
$recBadge = [
    'invest' => 'badge-green', 'maintain' => 'badge-blue',
    'reduce' => 'badge-orange', 'dont_buy' => 'badge-red', 'urgent' => 'badge-red',
];
$confBadge = ['high' => 'badge-green', 'medium' => 'badge-blue', 'low' => 'badge-orange', 'none' => 'badge-gray'];

/** KPI card that can render a non-numeric display value (e.g. N/A). */
if (!function_exists('invKpi')):
function invKpi(string $label, string $display, string $color, string $sub = '', string $icon = 'activity'): void {
    ?>
<div class="stat-card <?= $color ?> kpi-card">
  <div class="stat-icon <?= $color ?>"><i data-lucide="<?= htmlspecialchars($icon) ?>"></i></div>
  <div class="kpi-body">
    <div class="stat-label"><?= htmlspecialchars($label) ?></div>
    <div class="stat-value"><?= htmlspecialchars($display) ?></div>
    <div class="stat-sub"><?= htmlspecialchars($sub) ?></div>
  </div>
</div>
    <?php
}

function invConf(string $conf, array $confBadge): string {
    static $label = ['high' => 'HIGH', 'medium' => 'MEDIUM', 'low' => 'LOW', 'none' => 'NO DATA'];
    return '<span class="badge ' . ($confBadge[$conf] ?? 'badge-gray') . '">' . ($label[$conf] ?? strtoupper($conf)) . '</span>';
}

/** Renders the biggest score drivers as +/- chips. */
function invFactorChips(array $factors, int $maxPos = 4, int $maxNeg = 2): string {
    $pos = array_values(array_filter($factors, fn($f) => $f['points'] > 0));
    $neg = array_values(array_filter($factors, fn($f) => $f['points'] < 0));
    $out = [];
    foreach (array_slice($pos, 0, $maxPos) as $f) {
        $out[] = '<span class="badge badge-green" title="' . htmlspecialchars($f['label']) . '">+' . number_format($f['points'], 1) . ' ' . htmlspecialchars($f['label']) . '</span>';
    }
    foreach (array_slice($neg, 0, $maxNeg) as $f) {
        $out[] = '<span class="badge badge-red" title="' . htmlspecialchars($f['label']) . '">' . number_format($f['points'], 1) . ' ' . htmlspecialchars($f['label']) . '</span>';
    }
    return implode(' ', $out);
}

/** Money that may be N/A. */
function invMoney($v, string $cur, string $fallback = '—'): string {
    return $v === null ? $fallback : currency((float)$v);
}
endif;

// Simulator (GET-driven so results survive refresh; never saves anything).
$budgetInput = trim($_GET['budget'] ?? '');
$sim = null;
if ($budgetInput !== '' && is_numeric($budgetInput) && (float)$budgetInput > 0) {
    $sim = invSimulate($data['recommended'], (float)$budgetInput, $cfgData);
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

// Next-month plan buckets.
$planBuckets = [
    'urgent'   => ['title' => '🔥 BUY IMMEDIATELY', 'rows' => []],
    'invest'   => ['title' => '🟢 BUY MORE', 'rows' => []],
    'maintain' => ['title' => '🟡 MAINTAIN', 'rows' => []],
    'dont_buy' => ['title' => "🔴 DON'T BUY", 'rows' => []],
];
foreach ($rows as $m) {
    $key = $m['rec']['key'];
    if ($key === 'reduce') $key = 'dont_buy'; // folding REDUCE into DON'T BUY for the buy plan
    if (!isset($planBuckets[$key])) continue;
    if ($key !== 'maintain' && $m['purchase']['recommended_qty'] <= 0 && $key !== 'dont_buy') continue;
    $planBuckets[$key]['rows'][] = $m;
}
foreach ($planBuckets as &$bucket) {
    usort($bucket['rows'], fn($a, $b) => $b['score'] <=> $a['score']);
    $bucket['rows'] = array_slice($bucket['rows'], 0, $bucket['title'] === "🔴 DON'T BUY" ? 25 : 40);
}
unset($bucket);

$ranked = $rows;
usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
$ranked = array_slice($ranked, 0, 40);
$recCounts = ['urgent' => 0, 'invest' => 0, 'maintain' => 0, 'reduce' => 0, 'dont_buy' => 0];
foreach ($rows as $m) $recCounts[$m['rec']['key']]++;

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
  <div><strong>Read-only decision engine.</strong> This page does not change stock, prices, sales or purchases. Every projection is clearly marked <strong>ESTIMATE</strong>. The only action available is creating a purchase <strong>draft</strong> from products you explicitly select — nothing is confirmed or received automatically.</div>
</div>

<!-- ─── DATA WINDOW: selected period vs real ERP history ─────────────── -->
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><i data-lucide="calendar-range" style="width:16px;height:16px;"></i> Selected Period vs Available ERP History</span>
    <span class="badge <?= $hist['full_period'] ? 'badge-green' : 'badge-orange' ?>">
      <?= $hist['full_period'] ? 'Full period covered' : 'Limited history' ?>
    </span>
  </div>
  <div class="report-summary-grid" style="padding:14px 16px;">
    <div><span class="report-k">Selected Period</span><span class="report-v"><?= (int)$hist['period_days'] ?> days</span>
      <div style="font-size:11px;color:var(--text-300);"><?= htmlspecialchars(date('M j, Y', strtotime($hist['period_from'])) . ' – ' . date('M j, Y', strtotime($hist['period_to']))) ?></div></div>
    <div><span class="report-k">Available ERP History</span><span class="report-v"><?= (int)$hist['erp_days'] ?> days</span>
      <div style="font-size:11px;color:var(--text-300);"><?= $hist['erp_first'] ? htmlspecialchars(date('M j, Y', strtotime($hist['erp_first'])) . ' – ' . date('M j, Y', strtotime($hist['erp_last']))) . ' · ' . (int)$hist['erp_trading_days'] . ' trading days' : 'No sales yet' ?></div></div>
    <div><span class="report-k">Analysis Window Used</span><span class="report-v"><?= (int)$hist['analysis_days'] ?> days</span>
      <div style="font-size:11px;color:var(--text-300);"><?= $hist['analysis_empty'] ? 'No ERP data inside this period' : htmlspecialchars(date('M j, Y', strtotime($hist['analysis_from'])) . ' – ' . date('M j, Y', strtotime($hist['analysis_to']))) ?></div></div>
    <div><span class="report-k">Products With Sales</span><span class="report-v"><?= number_format((int)$summary['products_selling']) ?> / <?= number_format((int)$summary['products_total']) ?></span>
      <div style="font-size:11px;color:var(--text-300);"><?= number_format((int)$summary['products_no_data']) ?> products have no sales history yet</div></div>
  </div>
  <?php if (!$hist['full_period'] && !$hist['analysis_empty']): ?>
  <div class="alert alert-info" style="margin:0 16px 14px;">
    <i data-lucide="info"></i>
    <div>The selected period is longer than the ERP's real sales history. Calendar averages (units ÷ selected days) therefore <strong>understate demand</strong>, so this page also uses active-selling, in-stock and recent velocity to build a <strong>forecast velocity</strong>. Confidence ratings show how much evidence backs each product.</div>
  </div>
  <?php elseif ($hist['analysis_empty']): ?>
  <div class="alert alert-danger" style="margin:0 16px 14px;">
    <i data-lucide="x-circle"></i>
    <div>No ERP sales fall inside the selected period, so no demand can be forecast. Pick a period that overlaps your recorded sales (<?= htmlspecialchars($hist['erp_first'] ? date('M j, Y', strtotime($hist['erp_first'])) . ' – ' . date('M j, Y', strtotime($hist['erp_last'])) : 'none') ?>).</div>
  </div>
  <?php endif; ?>
</div>

<!-- ─── TOP SUMMARY ─────────────────────────────────────────────────── -->
<div class="stats-grid kpi-grid">
  <?php
  invKpi('Recommended Investment', currency((float)$summary['invest']), 'orange', 'Total cost of recommended stock', 'wallet');
  invKpi('Expected Additional Revenue', currency((float)$summary['revenue']), 'blue', 'Next ' . (int)$cfgData['horizon_days'] . ' days · ESTIMATE', 'trending-up');
  invKpi('Expected Gross Profit', currency((float)$summary['profit']), 'green', 'Next ' . (int)$cfgData['horizon_days'] . ' days · ESTIMATE', 'piggy-bank');
  invKpi('Estimated ROI', $summary['roi'] === null ? 'N/A' : number_format((float)$summary['roi'], 1) . '%', 'green', $summary['roi'] === null ? 'No investment recommended' : 'Full sell-through ROI: ' . number_format((float)$summary['roi_full'], 1) . '%', 'percent');
  ?>
</div>
<div class="stats-grid kpi-grid mb-20">
  <?php
  invKpi('Current Inventory Value', currency((float)$summary['stock_value']), 'orange', 'Stock at purchase cost', 'box');
  invKpi('Products Recommended', number_format((int)$summary['rec_count']), 'blue', '🔥 ' . $recCounts['urgent'] . ' urgent · 🟢 ' . $recCounts['invest'] . ' invest more', 'package-check');
  invKpi('Potential Missed Sales', currency((float)$summary['missed_revenue']), 'red', 'Next ' . (int)min((int)$cfgData['coverage_days'], (int)$cfgData['horizon_days']) . ' days if not restocked · ESTIMATE', 'alert-triangle');
  ?>
</div>

<!-- ─── WHERE SHOULD I INVEST? ──────────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><i data-lucide="target" style="width:16px;height:16px;"></i> Where Should I Invest?</span>
    <span style="font-size:12px;color:var(--text-300);">Ranked on demand, profitability, stock cover, stockout risk, turnover, expiry, incoming stock &amp; data confidence</span>
  </div>

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
    <div class="form-group" style="margin:0;"><label>Urgent at (days cover)</label>
      <input type="number" name="urgent_days" value="<?= (int)$cfgData['urgent_days'] ?>" min="1" max="60" style="width:80px;"></div>
    <div class="form-group" style="margin:0;"><label>Forecast horizon (days)</label>
      <input type="number" name="horizon_days" value="<?= (int)$cfgData['horizon_days'] ?>" min="7" max="120" style="width:90px;"></div>
    <div class="form-group" style="margin:0;"><label>Expiry risk window</label>
      <input type="number" name="expiry_risk_days" value="<?= (int)$cfgData['expiry_risk_days'] ?>" min="30" max="365" style="width:90px;"></div>
    <div class="form-group" style="margin:0;"><label>Min margin %</label>
      <input type="number" name="min_margin_pct" value="<?= (int)$cfgData['min_margin_pct'] ?>" min="0" max="80" style="width:80px;"></div>
    <button class="btn btn-ghost btn-sm">Recalculate</button>
    <a href="investment.php" class="btn btn-ghost btn-sm">Reset</a>
  </form>

  <div class="report-summary-grid" style="padding:0 16px 14px;">
    <?php foreach ($recCounts as $k => $n): ?>
    <div><span class="report-k"><?= $recNames[$k] ?></span><span class="report-v"><?= (int)$n ?></span></div>
    <?php endforeach; ?>
  </div>

  <?php $topWhy = array_slice($data['recommended'], 0, 8); ?>
  <?php if ($topWhy): ?>
  <div style="padding:0 16px 14px;">
    <div class="card-header" style="padding:4px 0 8px;"><span class="card-title" style="font-size:14px;">Best opportunities right now</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Recommendation</th><th>Product</th><th>Category</th><th>Stock</th><th>Forecast /day</th><th>Confidence</th><th>Score</th><th>Why</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($topWhy as $m): ?>
        <tr>
          <td><span class="badge <?= $recBadge[$m['rec']['key']] ?>"><?= $recNames[$m['rec']['key']] ?></span></td>
          <td style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></td>
          <td style="font-size:12px;"><?= htmlspecialchars($m['category']) ?></td>
          <td><?= number_format($m['usable_stock']) ?> <?= htmlspecialchars($m['unit']) ?></td>
          <td><?= number_format((float)$m['forecast'], 2) ?></td>
          <td><?= invConf($m['confidence'], $confBadge) ?></td>
          <td><span class="badge <?= $m['score'] >= 60 ? 'badge-green' : ($m['score'] >= 35 ? 'badge-orange' : 'badge-gray') ?>"><?= (int)$m['score'] ?></span></td>
          <td style="font-size:12px;color:var(--text-300);max-width:380px;"><?= htmlspecialchars($m['rec']['why']) ?></td>
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
        <tr><td><span class="badge badge-red">🔥 BUY URGENTLY</span></td><td><?= $recCounts['urgent'] ?></td><td style="font-size:12px;color:var(--text-300);">Proven demand and stock covers ≤ <?= (int)$cfgData['urgent_days'] ?> days (or nothing left) — restock before sales are lost</td></tr>
        <tr><td><span class="badge badge-green">🟢 INVEST MORE</span></td><td><?= $recCounts['invest'] ?></td><td style="font-size:12px;color:var(--text-300);">Demand and margin justify buying: coverage is below the target window</td></tr>
        <tr><td><span class="badge badge-blue">🟡 MAINTAIN</span></td><td><?= $recCounts['maintain'] ?></td><td style="font-size:12px;color:var(--text-300);">Demand is healthy and stock is already near target — buy roughly what you sell</td></tr>
        <tr><td><span class="badge badge-orange">🟠 REDUCE</span></td><td><?= $recCounts['reduce'] ?></td><td style="font-size:12px;color:var(--text-300);">Overstocked, margin below threshold, or demand fading / expiry risk — buy less next time</td></tr>
        <tr><td><span class="badge badge-red">🔴 DON'T BUY</span></td><td><?= $recCounts['dont_buy'] ?></td><td style="font-size:12px;color:var(--text-300);">No/weak demand, dead stock, or too little evidence to justify investment</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ─── TOP INVESTMENT OPPORTUNITIES (ranked) ───────────────────────── -->
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><i data-lucide="trophy" style="width:16px;height:16px;"></i> Top Investment Opportunities</span>
    <span style="font-size:12px;color:var(--text-300);">Investment Score 0–100 = demand + velocity + trend + profitability + margin + stock need + stockout + turnover + confidence − expiry − overstock − incoming</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Product</th><th>Category</th><th>Recommendation</th><th>Score</th>
          <th>Stock</th><th>Units Sold</th><th>Forecast /day</th><th>Days of Stock</th><th>Margin</th>
          <th>Rec. Qty</th><th>Required Investment</th><th>Expected Revenue (EST.)</th><th>Expected GP (EST.)</th><th>ROI (EST.)</th><th>Growth</th><th>Confidence</th><th>Why</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$ranked): ?>
        <tr><td colspan="19" style="text-align:center;padding:40px;color:var(--text-300);">No product data in this period — adjust the filters</td></tr>
      <?php else: foreach ($ranked as $i => $m): ?>
      <tr>
        <td><span class="badge <?= $i < 3 ? 'badge-blue' : 'badge-gray' ?>"><?= $i + 1 ?></span></td>
        <td><div style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></div>
            <?php if ($m['generic_name']): ?><div style="font-size:11px;color:var(--text-300);"><?= htmlspecialchars($m['generic_name']) ?></div><?php endif; ?></td>
        <td style="font-size:12px;"><?= htmlspecialchars($m['category']) ?></td>
        <td><span class="badge <?= $recBadge[$m['rec']['key']] ?>"><?= $recNames[$m['rec']['key']] ?></span></td>
        <td><span class="badge <?= $m['score'] >= 60 ? 'badge-green' : ($m['score'] >= 35 ? 'badge-orange' : 'badge-gray') ?>"><?= (int)$m['score'] ?></span></td>
        <td><?= number_format($m['usable_stock']) ?> <?= htmlspecialchars($m['unit']) ?></td>
        <td><?= number_format($m['units_sold']) ?></td>
        <td><?= number_format((float)$m['forecast'], 2) ?></td>
        <td><?= $m['coverage'] === null ? '<span title="Insufficient demand history">N/A</span>' : number_format((float)$m['coverage'], 0) ?></td>
        <td><?= number_format((float)$m['margin_pct'], 1) ?>%</td>
        <td><?= $m['purchase']['recommended_qty'] > 0 ? number_format($m['purchase']['recommended_qty']) : '—' ?></td>
        <td><?= $m['purchase']['recommended_qty'] > 0 ? currency((float)$m['purchase']['required_investment']) : '—' ?></td>
        <td><?= $m['purchase']['recommended_qty'] > 0 ? currency((float)$m['purchase']['est_revenue']) : '—' ?></td>
        <td style="font-weight:600;"><?= $m['purchase']['recommended_qty'] > 0 ? currency((float)$m['purchase']['est_profit']) : '—' ?></td>
        <td><?= $m['purchase']['est_roi'] === null ? '—' : number_format((float)$m['purchase']['est_roi'], 1) . '%' ?></td>
        <td style="font-size:12px;"><?= $m['growth_pct'] !== null
            ? ($m['growth_pct'] >= 0 ? '<span style="color:var(--accent2);">▲ ' : '<span style="color:var(--danger);">▼ ') . number_format((float)$m['growth_pct'], 0) . '%</span>'
            : '<span style="color:var(--text-300);font-size:11px;">' . htmlspecialchars($m['growth_label']) . '</span>' ?></td>
        <td><?= invConf($m['confidence'], $confBadge) ?></td>
        <td style="font-size:12px;color:var(--text-300);max-width:340px;"><?= htmlspecialchars($m['rec']['why']) ?></td>
        <td><a href="investment.php?<?= reportQueryString($dates, $filters, array_merge($cfgQs, ['detail' => $m['id']])) ?>#productDetail" class="btn btn-ghost btn-sm">Details</a></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ─── PRODUCT INVESTMENT DETAILS ──────────────────────────────────── -->
<?php if ($detail): $dp = $detail['purchase']; ?>
<div class="card mb-20" id="productDetail">
  <div class="card-header">
    <span class="card-title"><i data-lucide="microscope" style="width:16px;height:16px;"></i> Product Investment Details — <?= htmlspecialchars($detail['name']) ?></span>
    <a href="investment.php?<?= reportQueryString($dates, $filters, $cfgQs) ?>" class="btn btn-ghost btn-sm">Close ✕</a>
  </div>

  <div style="padding:0 16px 14px;">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
      <span class="badge <?= $recBadge[$detail['rec']['key']] ?>"><?= $recNames[$detail['rec']['key']] ?></span>
      <?= invConf($detail['confidence'], $confBadge) ?>
      <span class="badge <?= $detail['score'] >= 60 ? 'badge-green' : ($detail['score'] >= 35 ? 'badge-orange' : 'badge-gray') ?>">Investment Score <?= (int)$detail['score'] ?>/100</span>
      <span style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($detail['rec']['why']) ?></span>
    </div>

    <div class="card-header" style="padding:8px 0 6px;"><span class="card-title" style="font-size:14px;">Product Performance</span></div>
    <div class="report-summary-grid">
      <div><span class="report-k">Units Sold</span><span class="report-v"><?= number_format($detail['units_sold']) ?></span></div>
      <div><span class="report-k">Transactions</span><span class="report-v"><?= number_format($detail['transactions']) ?></span></div>
      <div><span class="report-k">Revenue</span><span class="report-v"><?= currency((float)$detail['revenue']) ?></span></div>
      <div><span class="report-k">COGS</span><span class="report-v"><?= currency((float)$detail['cogs']) ?></span></div>
      <div><span class="report-k">Gross Profit</span><span class="report-v"><?= currency((float)$detail['gross_profit']) ?></span></div>
      <div><span class="report-k">Gross Margin</span><span class="report-v"><?= number_format((float)$detail['margin_pct'], 1) ?>%</span></div>
      <div><span class="report-k">Calendar Avg Units/Day</span><span class="report-v"><?= number_format((float)$detail['calendar_avg'], 2) ?></span></div>
      <div><span class="report-k">Active Selling Velocity</span><span class="report-v"><?= number_format((float)$detail['active_velocity'], 2) ?></span></div>
      <div><span class="report-k">In-Stock Velocity</span><span class="report-v"><?= number_format((float)$detail['in_stock_velocity'], 2) ?></span></div>
      <div><span class="report-k">Last 7-Day Velocity</span><span class="report-v"><?= $detail['recent7'] === null ? 'N/A' : number_format((float)$detail['recent7'], 2) ?></span></div>
      <div><span class="report-k">Last 14-Day Velocity</span><span class="report-v"><?= $detail['recent14'] === null ? 'N/A' : number_format((float)$detail['recent14'], 2) ?></span></div>
      <div><span class="report-k">Last 30-Day Velocity</span><span class="report-v"><?= $detail['recent30'] === null ? 'N/A' : number_format((float)$detail['recent30'], 2) ?></span></div>
      <div><span class="report-k">Forecast Velocity</span><span class="report-v" style="color:var(--accent2);"><?= number_format((float)$detail['forecast'], 2) ?>/day</span></div>
      <div><span class="report-k">Available History</span><span class="report-v"><?= (int)$detail['analysis_days'] ?> of <?= (int)$detail['period_days'] ?> days</span>
        <div style="font-size:11px;color:var(--text-300);"><?= (int)$detail['selling_days'] ?> selling days · product history <?= (int)$detail['history_days'] ?> days</div></div>
      <div><span class="report-k">Data Confidence</span><span class="report-v"><?= invConf($detail['confidence'], $confBadge) ?></span></div>
      <div><span class="report-k">Sales Growth</span><span class="report-v"><?= htmlspecialchars($detail['growth_label']) ?></span></div>
    </div>

    <div class="card-header" style="padding:14px 0 6px;"><span class="card-title" style="font-size:14px;">Inventory</span></div>
    <div class="report-summary-grid">
      <div><span class="report-k">Current Stock</span><span class="report-v"><?= number_format($detail['stock']) ?> <?= htmlspecialchars($detail['unit']) ?></span></div>
      <div><span class="report-k">Usable Stock</span><span class="report-v"><?= number_format($detail['usable_stock']) ?></span>
        <?php if ($detail['expired_qty'] > 0): ?><div style="font-size:11px;color:var(--danger);"><?= number_format($detail['expired_qty']) ?> expired excluded</div><?php endif; ?></div>
      <div><span class="report-k">Days of Stock</span><span class="report-v"><?= $detail['coverage'] === null ? 'N/A — insufficient demand history' : number_format((float)$detail['coverage'], 0) . ' days' ?></span></div>
      <div><span class="report-k">In-Stock Days (est.)</span><span class="report-v"><?= number_format($detail['in_stock_days']) ?></span></div>
      <div><span class="report-k">Out-of-Stock Days (est.)</span><span class="report-v"><?= $detail['oos_unknown'] ? 'Unknown' : number_format($detail['out_of_stock_days']) ?></span></div>
      <div><span class="report-k">Incoming Stock</span><span class="report-v"><?= number_format($detail['pending_qty']) ?> <?= htmlspecialchars($detail['unit']) ?></span></div>
      <div><span class="report-k">Inventory Turnover</span><span class="report-v"><?= $detail['turnover'] === null ? 'N/A — insufficient inventory history' : number_format((float)$detail['turnover'], 2) . '×' ?></span></div>
      <div><span class="report-k">Expiry</span><span class="report-v"><?= $detail['next_expiry'] ? formatExpiryDate($detail['next_expiry']) . ' (' . expiryDaysLabel($detail['next_expiry']) . ')' : 'No expiry' ?></span>
        <?php if ($detail['at_risk_qty'] > 0): ?><div style="font-size:11px;color:var(--warning);"><?= number_format($detail['at_risk_qty']) ?> within <?= (int)$cfgData['expiry_risk_days'] ?> days</div><?php endif; ?></div>
    </div>

    <div class="card-header" style="padding:14px 0 6px;"><span class="card-title" style="font-size:14px;">Purchase</span></div>
    <div class="report-summary-grid">
      <div><span class="report-k">Last Purchase Price</span><span class="report-v"><?= $detail['last_price'] !== null ? currency((float)$detail['last_price']) : '—' ?></span></div>
      <div><span class="report-k">Average Purchase Price</span><span class="report-v"><?= $detail['avg_price'] !== null ? currency((float)$detail['avg_price']) : '—' ?></span></div>
      <div><span class="report-k">Lowest Purchase Price</span><span class="report-v"><?= $detail['low_price'] !== null ? currency((float)$detail['low_price']) : '—' ?></span></div>
      <div><span class="report-k">Selling Price (avg)</span><span class="report-v"><?= (float)$detail['avg_sell'] > 0 ? currency((float)$detail['avg_sell']) : '—' ?></span></div>
      <div><span class="report-k">Supplier</span><span class="report-v"><?= htmlspecialchars($detail['supplier_name'] ?? '—') ?></span></div>
      <div><span class="report-k">Reorder Level</span><span class="report-v"><?= number_format($detail['reorder_level']) ?></span></div>
    </div>

    <div class="card-header" style="padding:14px 0 6px;"><span class="card-title" style="font-size:14px;">Investment &amp; Recommended Purchase</span></div>
    <div class="report-summary-grid">
      <div><span class="report-k">Target Coverage</span><span class="report-v"><?= (int)$dp['target_coverage'] ?> days</span></div>
      <div><span class="report-k">Target Demand Stock</span><span class="report-v"><?= number_format($dp['target_units']) ?> <?= htmlspecialchars($detail['unit']) ?></span></div>
      <div><span class="report-k">Safety Stock</span><span class="report-v"><?= number_format($dp['safety_units']) ?></span></div>
      <div><span class="report-k">Recommended Quantity</span><span class="report-v" style="color:var(--accent2);"><?= number_format($dp['recommended_qty']) ?> <?= htmlspecialchars($detail['unit']) ?></span>
        <div style="font-size:11px;color:var(--text-300);">target + safety − usable − incoming</div></div>
      <div><span class="report-k">Unit Cost</span><span class="report-v"><?= $dp['unit_cost'] > 0 ? currency((float)$dp['unit_cost']) : '—' ?></span>
        <div style="font-size:11px;color:var(--text-300);"><?= htmlspecialchars($dp['unit_cost_source']) ?></div></div>
      <div><span class="report-k">Required Investment</span><span class="report-v"><?= currency((float)$dp['required_investment']) ?></span></div>
      <div><span class="report-k">Expected Units Sold (EST.)</span><span class="report-v"><?= number_format($dp['expected_units']) ?></span>
        <div style="font-size:11px;color:var(--text-300);">next <?= (int)$dp['horizon_days'] ?> days, allocated share of demand</div></div>
      <div><span class="report-k">Expected Revenue (EST.)</span><span class="report-v"><?= currency((float)$dp['est_revenue']) ?></span></div>
      <div><span class="report-k">Expected Gross Profit (EST.)</span><span class="report-v" style="color:var(--accent2);"><?= currency((float)$dp['est_profit']) ?></span></div>
      <div><span class="report-k">Estimated ROI (EST.)</span><span class="report-v"><?= $dp['est_roi'] === null ? 'N/A — no investment required' : number_format((float)$dp['est_roi'], 1) . '%' ?></span>
        <div style="font-size:11px;color:var(--text-300);"><?= $dp['roi_full'] === null ? '' : 'Full sell-through: ' . number_format((float)$dp['roi_full'], 1) . '% on ' . currency((float)$dp['full_profit']) . ' profit' ?></div></div>
      <div><span class="report-k">Coverage After Purchase</span><span class="report-v"><?= $dp['coverage_after'] === null ? 'N/A' : number_format((float)$dp['coverage_after'], 0) . ' days' ?></span></div>
    </div>

    <div class="card-header" style="padding:14px 0 6px;"><span class="card-title" style="font-size:14px;">Why This Product?</span></div>
    <div class="insights-list">
      <div class="insight-item insight-good"><i data-lucide="target"></i><span><strong><?= htmlspecialchars($detail['rec']['label']) ?>:</strong> <?= htmlspecialchars($detail['rec']['why']) ?></span></div>
      <?php foreach ($detail['why'] as $w): ?>
      <div class="insight-item insight-good"><i data-lucide="check-circle-2"></i><span><?= htmlspecialchars($w) ?></span></div>
      <?php endforeach; ?>
    </div>

    <div class="card-header" style="padding:14px 0 6px;"><span class="card-title" style="font-size:14px;">Score Drivers</span></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;"><?= invFactorChips($detail['score_factors'], 5, 3) ?></div>

    <?php if ($detail['suppliers']): ?>
    <div class="card-header" style="padding:14px 0 6px;"><span class="card-title" style="font-size:14px;">Purchase Price by Supplier — best option first</span></div>
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
  <!-- ─── CATEGORY INVESTMENT ─────────────────────────────────────── -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i data-lucide="layers" style="width:16px;height:16px;"></i> Category Investment</span>
      <span style="font-size:12px;color:var(--text-300);">Dynamic — totals reconcile with product rows</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Category</th><th>Sales</th><th>Gross Profit</th><th>Margin</th><th>Inv. Value</th><th>Turnover</th><th>Growth</th><th>Rec. Investment</th><th>Expected Profit</th><th>ROI</th><th>Score</th></tr></thead>
        <tbody>
        <?php if (!$data['categories']): ?>
          <tr><td colspan="11" style="text-align:center;padding:30px;color:var(--text-300);">No data</td></tr>
        <?php else: foreach ($data['categories'] as $c): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($c['category']) ?></td>
          <td><?= currency((float)$c['sales']) ?></td>
          <td><?= currency((float)$c['profit']) ?></td>
          <td><?= number_format((float)$c['margin_pct'], 1) ?>%</td>
          <td><?= currency((float)$c['stock_value']) ?></td>
          <td><?= $c['turnover'] === null ? 'N/A' : number_format((float)$c['turnover'], 2) . '×' ?></td>
          <td style="font-size:12px;"><?= $c['growth_pct'] === null
              ? '<span style="color:var(--text-300);font-size:11px;">NO COMPARABLE HISTORY</span>'
              : ($c['growth_pct'] >= 0 ? '<span style="color:var(--accent2);">▲ +' : '<span style="color:var(--danger);">▼ ') . number_format((float)$c['growth_pct'], 0) . '%</span>' ?></td>
          <td style="font-weight:600;"><?= currency((float)$c['invest']) ?></td>
          <td><?= currency((float)$c['expected_profit']) ?></td>
          <td><?= $c['roi'] === null ? 'N/A' : number_format((float)$c['roi'], 1) . '%' ?></td>
          <td><span class="badge <?= $c['score'] >= 55 ? 'badge-green' : ($c['score'] >= 30 ? 'badge-orange' : 'badge-gray') ?>"><?= (int)$c['score'] ?></span></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ─── INVESTMENT SIMULATOR ───────────────────────────────────── -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i data-lucide="calculator" style="width:16px;height:16px;"></i> Investment Simulator</span>
      <span style="font-size:12px;color:var(--text-300);">Never forces the whole budget to be spent</span></div>
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
        <div><span class="report-k">Budget</span><span class="report-v"><?= currency((float)$sim['budget']) ?></span></div>
        <div><span class="report-k">Qualifying Opportunity</span><span class="report-v"><?= currency((float)$sim['opportunity']) ?></span>
          <div style="font-size:11px;color:var(--text-300);"><?= (int)$sim['candidates'] ?> products meet the margin / stock / expiry rules</div></div>
        <div><span class="report-k">Recommended Investment (EST.)</span><span class="report-v" style="color:var(--accent2);"><?= currency((float)$sim['cost']) ?></span></div>
        <div><span class="report-k">Expected Revenue (EST.)</span><span class="report-v"><?= currency((float)$sim['revenue']) ?></span></div>
        <div><span class="report-k">Expected Gross Profit (EST.)</span><span class="report-v" style="color:var(--accent2);"><?= currency((float)$sim['profit']) ?></span></div>
        <div><span class="report-k">Estimated ROI (EST.)</span><span class="report-v"><?= $sim['roi'] === null ? 'N/A' : number_format((float)$sim['roi'], 1) . '%' ?></span></div>
      </div>
      <?php if ($sim['cats']): ?>
      <p style="font-size:12px;color:var(--text-300);margin:8px 0 4px;">Recommended category allocation:</p>
      <?php foreach ($sim['cats'] as $cat => $amt):
        $pct = $sim['cost'] > 0 ? round($amt / $sim['cost'] * 100) : 0; ?>
      <div style="margin-bottom:8px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;"><span><?= htmlspecialchars($cat) ?></span><span><?= $pct ?>% · <?= currency((float)$amt) ?></span></div>
        <div class="progress-bar"><div class="progress-fill green" style="width:<?= $pct ?>%;"></div></div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($sim['plan']): ?>
      <div class="table-wrap" style="max-height:280px;overflow-y:auto;">
        <table>
          <thead><tr><th>Product</th><th>Qty</th><th>Cost (EST.)</th><th>Profit (EST.)</th><th>ROI (EST.)</th></tr></thead>
          <tbody>
          <?php foreach ($sim['plan'] as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['row']['name']) ?><?= $p['partial'] ? ' <span class="badge badge-gray">partial</span>' : '' ?></td>
            <td><?= number_format($p['qty']) ?></td>
            <td><?= currency((float)$p['cost']) ?></td>
            <td style="color:var(--accent2);font-weight:600;"><?= currency((float)$p['profit']) ?></td>
            <td><?= $p['roi'] === null ? 'N/A' : number_format((float)$p['roi'], 1) . '%' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($sim['unspent'] > 0): ?>
      <p style="font-size:12px;color:var(--text-300);margin:8px 0 0;">Unallocated budget: <strong><?= currency((float)$sim['unspent']) ?></strong> — no further products met the rules. Leaving it unspent is intentional.</p>
      <?php endif; ?>
      <?php else: ?>
      <p style="font-size:13px;color:var(--text-300);">No qualifying investment opportunities for this budget in the selected period.</p>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <p style="padding:0 16px 16px;font-size:12px;color:var(--text-300);">Enter a budget and press Optimize — the simulator prioritises urgent restocks, then the highest-scoring opportunities that pass the margin, expiry and incoming-stock rules. Nothing is saved.</p>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2 mb-20">
  <!-- ─── STOCKOUT / MISSED SALES ─────────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i data-lucide="alert-triangle" style="width:16px;height:16px;"></i> Stockout / Missed Sales Opportunity</span>
      <span class="badge badge-orange">ESTIMATES</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Product</th><th>Current Stock</th><th>Forecast /day</th><th>Days Remaining</th><th>Out-of-Stock Days</th><th>Stockout Risk</th><th>Missed Units (EST.)</th><th>Missed Revenue (EST.)</th><th>Missed Profit (EST.)</th></tr></thead>
        <tbody>
        <?php if (!$data['stockouts']): ?>
          <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-300);">No stockout risk detected for the target window</td></tr>
        <?php else: foreach (array_slice($data['stockouts'], 0, 15) as $s): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($s['row']['name']) ?></td>
          <td><?= number_format($s['row']['usable_stock']) ?></td>
          <td><?= number_format((float)$s['forecast'], 2) ?></td>
          <td><?= $s['days_remaining'] <= 0 ? 'Out' : (int)$s['days_remaining'] ?></td>
          <td><?= (int)$s['out_of_stock_days'] ?></td>
          <td><span class="badge <?= $s['risk_badge'] ?>"><?= $s['stockout_risk'] ?></span></td>
          <td><?= number_format($s['missed_units']) ?></td>
          <td><?= currency((float)$s['missed_revenue']) ?></td>
          <td style="font-weight:600;color:var(--warning);"><?= currency((float)$s['missed_profit']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <p style="font-size:11px;color:var(--text-300);padding:0 16px 12px;margin:0;">Estimated lost sales over the next <?= (int)min((int)$cfgData['coverage_days'], (int)$cfgData['horizon_days']) ?> days if nothing is restocked. Days with no stock are never treated as zero demand.</p>
  </div>

  <!-- ─── EXPIRY-AWARE INVESTMENT ─────────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i data-lucide="calendar-clock" style="width:16px;height:16px;"></i> Expiry-Aware Investment Risk</span>
      <span style="font-size:12px;color:var(--text-300);">Buying is blocked when stock cannot sell before expiry</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Product</th><th>Stock</th><th>Expiry</th><th>Days Until</th><th>Forecast Sales</th><th>Expected Sales Before Expiry</th><th>Excess Stock</th><th>Value at Risk</th><th>Recommendation</th></tr></thead>
        <tbody>
        <?php if (!$data['expiry_risk']): ?>
          <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-300);">No expiry-risk products in the next <?= (int)$cfgData['expiry_risk_days'] * 2 ?> days</td></tr>
        <?php else: foreach (array_slice($data['expiry_risk'], 0, 15) as $e): ?>
        <tr>
          <td style="font-weight:600;"><?= htmlspecialchars($e['row']['name']) ?></td>
          <td><?= number_format($e['stock']) ?></td>
          <td><?= formatExpiryDate($e['expiry_date']) ?></td>
          <td><?= (int)$e['days_until'] ?>d</td>
          <td><?= number_format((float)$e['forecast'], 2) ?>/day</td>
          <td>≈ <?= number_format($e['expected_sales']) ?></td>
          <td style="font-weight:600;color:var(--warning);"><?= number_format($e['excess_stock']) ?></td>
          <td><?= currency((float)$e['value_at_risk']) ?></td>
          <td><span class="badge <?= $e['rec_badge'] ?>"><?= htmlspecialchars($e['recommendation']) ?></span></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ─── SLOW / DEAD STOCK ───────────────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><i data-lucide="package-x" style="width:16px;height:16px;"></i> Slow / Dead Stock — Money Tied Up</span>
    <span style="font-size:12px;color:var(--text-300);">No prices or stock are changed automatically — these are review prompts only</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Product</th><th>Current Stock</th><th>Cost Value</th><th>Units Sold</th><th>Last Sale</th><th>Days Since Last Sale</th><th>Turnover</th><th>Expiry</th><th>Recommendation</th></tr></thead>
      <tbody>
      <?php if (!$data['slow']): ?>
        <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-300);">No slow or dead stock — healthy inventory</td></tr>
      <?php else: foreach (array_slice($data['slow'], 0, 20) as $s): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($s['row']['name']) ?></td>
        <td><?= number_format($s['row']['stock']) ?> <?= htmlspecialchars($s['row']['unit']) ?></td>
        <td style="color:var(--warning);font-weight:600;"><?= currency((float)$s['row']['stock_value']) ?></td>
        <td><?= number_format($s['row']['units_sold']) ?></td>
        <td><?= htmlspecialchars($s['since_label']) ?></td>
        <td><?= $s['days_since'] !== null ? (int)$s['days_since'] : 'Never' ?></td>
        <td><?= $s['turnover'] === null ? 'N/A' : number_format((float)$s['turnover'], 2) . '×' ?></td>
        <td><?= $s['row']['next_expiry'] ? formatExpiryDate($s['row']['next_expiry']) : 'No expiry' ?></td>
        <td><span class="badge <?= $s['rec_badge'] ?>"><?= htmlspecialchars($s['recommendation']) ?></span></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ─── NEXT-MONTH PURCHASE PLAN ─────────────────────────────────────── -->
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
            <th>Expected Revenue (EST.)</th><th>Expected Profit (EST.)</th><th>ROI (EST.)</th><th>Confidence</th><th>Recommendation</th><th>Why</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($bucket['rows'] as $m):
          $rp = $m['purchase'];
          $qty = (int)$rp['recommended_qty'];
          if ($bucketKey === 'dont_buy') $qty = 0;
          // Scale the row economics to the quantity actually being kept.
          $ratio = ($rp['recommended_qty'] > 0 && $qty > 0) ? $qty / $rp['recommended_qty'] : 0;
          $rowCost = $rp['required_investment'] * $ratio;
          $rowRev = $rp['est_revenue'] * $ratio;
          $rowProfit = $rp['est_profit'] * $ratio;
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
            <td data-unit-cost="<?= (float)$rp['unit_cost'] ?>"><?= $rp['unit_cost'] > 0 ? currency((float)$rp['unit_cost']) : '—' ?></td>
            <td style="font-weight:600;" data-row-cost><?= $qty > 0 ? currency($rowCost) : currency(0) ?></td>
            <td data-row-rev><?= $qty > 0 ? currency($rowRev) : currency(0) ?></td>
            <td style="color:var(--accent2);font-weight:600;" data-row-profit><?= $qty > 0 ? currency($rowProfit) : currency(0) ?></td>
            <td><?= $rp['est_roi'] === null ? 'N/A' : number_format((float)$rp['est_roi'], 1) . '%' ?></td>
            <td><?= invConf($m['confidence'], $confBadge) ?></td>
            <td><span class="badge <?= $recBadge[$m['rec']['key']] ?>"><?= $recNames[$m['rec']['key']] ?></span></td>
            <td style="font-size:12px;color:var(--text-300);max-width:320px;"><?= htmlspecialchars($m['rec']['why']) ?></td>
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
      <span style="font-size:12px;color:var(--text-300);">A draft is created in Purchases — review batch numbers, expiry and prices there before receiving.</span>
    </div>
    <?php else: ?>
    <p style="padding:12px 16px;font-size:12px;color:var(--text-300);border-top:1px solid var(--border);">You can view recommendations, but creating a purchase draft requires purchase permission.</p>
    <?php endif; ?>
  </form>
</div>

<!-- ─── DATA RECONCILIATION ─────────────────────────────────────────── -->
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><i data-lucide="scale" style="width:16px;height:16px;"></i> Data Reconciliation</span>
    <span class="badge <?= $data['reconcile']['ok'] ? 'badge-green' : 'badge-red' ?>"><?= $data['reconcile']['ok'] ? 'All checks passed' : 'Check mismatch' ?></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Check</th><th>Detail</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($data['reconcile']['checks'] as $c): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($c['label']) ?></td>
        <td style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($c['detail']) ?></td>
        <td><?php if (!empty($c['info'])): ?><span class="badge badge-gray">REFERENCE</span>
            <?php else: ?><span class="badge <?= $c['ok'] ? 'badge-green' : 'badge-red' ?>"><?= $c['ok'] ? 'MATCH' : 'MISMATCH' ?></span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p style="font-size:12px;color:var(--text-300);margin-bottom:8px;">
  Model inputs: target coverage <strong><?= (int)$cfgData['coverage_days'] ?>d</strong> (scaled by data confidence) + safety stock <strong><?= (int)$cfgData['safety_days'] ?>d</strong>,
  max stock <strong><?= (int)$cfgData['max_stock_days'] ?>d</strong>, urgent at <strong><?= (int)$cfgData['urgent_days'] ?>d</strong> cover,
  forecast horizon <strong><?= (int)$cfgData['horizon_days'] ?>d</strong>, expiry risk window <strong><?= (int)$cfgData['expiry_risk_days'] ?>d</strong>,
  min margin <strong><?= (int)$cfgData['min_margin_pct'] ?>%</strong>, dead-stock threshold <strong><?= (int)$cfgData['dead_stock_days'] ?>d</strong>.
  Velocity is a blend of recent, in-stock, active-selling and calendar demand, shrunk toward the calendar average when evidence is thin, and capped against single-day spikes.
  All future values are ESTIMATES — never guarantees.
</p>

</div></div>

<script>
function toggleBucketInv(cb, key) {
  document.querySelectorAll('.inv-check-' + key).forEach(c => { c.checked = cb.checked; });
}
(function () {
  // Live row totals as quantities change (client-side convenience only).
  const form = document.getElementById('draftForm');
  if (!form) return;
  const money = v => 'ETB ' + (Math.round(v * 100) / 100).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  form.addEventListener('input', e => {
    if (!e.target.classList.contains('inv-qty')) return;
    const row = e.target.closest('tr');
    const qty = parseFloat(e.target.value) || 0;
    const unit = parseFloat(row.querySelector('[data-unit-cost]')?.dataset.unitCost || '0') || 0;
    const recQty = parseFloat(row.querySelector('.inv-qty')?.defaultValue || '0') || 0;
    const ratio = recQty > 0 ? Math.min(1, qty / recQty) : 0;
    const fullCost = parseFloat((row.querySelector('[data-row-cost]')?.textContent || '').replace(/[^0-9.]/g, '')) || 0;
    const fullProfit = parseFloat((row.querySelector('[data-row-profit]')?.textContent || '').replace(/[^0-9.]/g, '')) || 0;
    const fullRev = parseFloat((row.querySelector('[data-row-rev]')?.textContent || '').replace(/[^0-9.]/g, '')) || 0;
    // Scale the displayed economics by the quantity the user keeps.
    const origRatio = (window.__invOrig = window.__invOrig || {});
    if (!origRatio[row.rowIndex]) origRatio[row.rowIndex] = { cost: fullCost, profit: fullProfit, rev: fullRev, recQty };
    const o = origRatio[row.rowIndex];
    const f = o.recQty > 0 ? qty / o.recQty : 0;
    row.querySelector('[data-row-cost]').textContent = money(o.cost * f);
    row.querySelector('[data-row-profit]').textContent = money(o.profit * f);
    row.querySelector('[data-row-rev]').textContent = money(o.rev * f);
  });
})();
document.getElementById('productDetail')?.scrollIntoView({ block: 'start' });
</script>
<?php renderFooter(); ?>
