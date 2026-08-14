<?php
require_once __DIR__ . '/report_lib.php';

function renderReportExtras(): void {
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>';
    echo '<script src="assets/reports.js" defer></script>';
}

function renderReportNav(string $activePage): void {
    echo '<div class="report-nav no-print mb-20">';
    foreach (reportNavItems() as $item) {
        $active = ($activePage === $item['page']) ? ' active' : '';
        echo '<a href="' . $item['page'] . '.php" class="report-nav-item' . $active . '">';
        echo '<i data-lucide="' . $item['icon'] . '"></i> ' . htmlspecialchars($item['label']);
        echo '</a>';
    }
    echo '</div>';
}

function renderReportFilters(array $dates, array $filters, array $options, string $formAction = ''): void {
    $presets = reportDatePresets();
    $payments = reportPaymentMethods();
    $qs = reportQueryString($dates, $filters);
    ?>
<div class="card mb-20 no-print report-filters-card">
  <form method="GET" action="<?= htmlspecialchars($formAction) ?>" id="reportFilterForm">
    <div class="report-filters-grid">
      <div class="form-group">
        <label>Date Range</label>
        <select name="preset" id="reportPreset" onchange="toggleCustomDates()">
          <?php foreach ($presets as $key => $label): ?>
          <option value="<?= $key ?>" <?= $dates['preset'] === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group report-custom-dates" id="customDateFrom" style="<?= $dates['preset'] === 'custom' ? '' : 'display:none;' ?>">
        <label>Start Date</label>
        <input type="date" name="from" value="<?= htmlspecialchars($dates['from']) ?>">
      </div>
      <div class="form-group report-custom-dates" id="customDateTo" style="<?= $dates['preset'] === 'custom' ? '' : 'display:none;' ?>">
        <label>End Date</label>
        <input type="date" name="to" value="<?= htmlspecialchars($dates['to']) ?>">
      </div>
      <div class="form-group">
        <label>Product</label>
        <select name="product">
          <option value="">All Products</option>
          <?php foreach ($options['products'] as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $filters['product'] == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Category</label>
        <select name="category">
          <option value="">All Categories</option>
          <?php foreach ($options['categories'] as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filters['category'] == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Supplier</label>
        <select name="supplier">
          <option value="">All Suppliers</option>
          <?php foreach ($options['suppliers'] as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filters['supplier'] == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Customer</label>
        <input type="text" name="customer" value="<?= htmlspecialchars($filters['customer']) ?>" placeholder="Customer name..." list="customerList">
        <datalist id="customerList">
          <?php foreach ($options['customers'] as $c): ?>
          <option value="<?= htmlspecialchars($c['name']) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="form-group">
        <label>Cashier</label>
        <select name="cashier">
          <option value="">All Cashiers</option>
          <?php foreach ($options['cashiers'] as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $filters['cashier'] == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Payment Method</label>
        <select name="payment_method">
          <option value="">All Methods</option>
          <?php foreach ($payments as $key => $label): ?>
          <option value="<?= $key ?>" <?= $filters['payment_method'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Sales Type</label>
        <select name="sales_type">
          <option value="">All Types</option>
          <option value="cash" <?= $filters['sales_type'] === 'cash' ? 'selected' : '' ?>>Cash</option>
          <option value="credit" <?= $filters['sales_type'] === 'credit' ? 'selected' : '' ?>>Credit</option>
        </select>
      </div>
      <div class="form-group">
        <label>Branch</label>
        <select name="branch" disabled title="Future ready">
          <option value="">All Branches</option>
        </select>
      </div>
    </div>
    <div class="report-filter-actions">
      <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Apply Filter</button>
      <a href="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>" class="btn btn-ghost">Clear</a>
      <?php renderReportExportButtons($dates, $filters); ?>
    </div>
  </form>
</div>
    <?php
}

function renderReportExportButtons(array $dates, array $filters, string $report = 'overview'): void {
    $base = reportQueryString($dates, $filters, ['report' => $report]);
    echo '<div class="report-export-btns">';
    echo '<a href="report_export.php?format=csv&' . htmlspecialchars($base) . '" class="btn btn-ghost btn-sm"><i data-lucide="file-spreadsheet"></i> CSV</a>';
    echo '<a href="report_export.php?format=excel&' . htmlspecialchars($base) . '" class="btn btn-ghost btn-sm"><i data-lucide="file-spreadsheet"></i> Excel</a>';
    echo '<button type="button" class="btn btn-ghost btn-sm" onclick="window.print()"><i data-lucide="printer"></i> Print</button>';
    echo '</div>';
}

function renderKpiCard(string $label, array $meta, string $color = 'blue', string $suffix = '', bool $isPct = false): void {
    $val = $meta['current'] ?? 0;
    $change = $meta['change'] ?? null;
    $good = $meta['good'] ?? true;
    $dir = $meta['dir'] ?? 'up';
    $display = $isPct ? number_format($val, 1) . '%' : number_format($val, 0) . $suffix;
    $trendClass = $good ? 'trend-good' : 'trend-bad';
    $icon = $dir === 'up' ? 'arrow-up-right' : 'arrow-down-right';
    ?>
<div class="stat-card <?= $color ?> kpi-card">
  <div class="stat-icon <?= $color ?>"><i data-lucide="activity"></i></div>
  <div class="kpi-body">
    <div class="stat-label"><?= htmlspecialchars($label) ?></div>
    <div class="stat-value"><?= $display ?></div>
    <?php if ($change !== null && ($meta['previous'] ?? 0) != 0 || abs($change) > 0.01): ?>
    <div class="kpi-trend <?= $trendClass ?>">
      <i data-lucide="<?= $icon ?>"></i>
      <?= ($change >= 0 ? '+' : '') . number_format($change, 1) ?>% vs prev period
    </div>
    <?php else: ?>
    <div class="stat-sub">Current period</div>
    <?php endif; ?>
  </div>
</div>
    <?php
}

function renderReportMeta(string $title, array $dates): void {
    ?>
<div class="report-meta mb-20">
  <div>
    <h2 style="font-size:18px;font-weight:700;"><?= htmlspecialchars(getSetting('pharmacy_name', 'TADE PHARMACY')) ?></h2>
    <p style="font-size:13px;color:var(--text-300);margin-top:4px;"><?= htmlspecialchars($title) ?> · <?= htmlspecialchars($dates['label']) ?></p>
  </div>
  <div style="font-size:12px;color:var(--text-300);text-align:right;">
    Generated <?= date('M j, Y H:i') ?><br>
    <?= htmlspecialchars(getSetting('pharmacy_address', '')) ?>
  </div>
</div>
    <?php
}

function renderInsightsCard(array $insights): void {
    if (empty($insights)) return;
    ?>
<div class="card mb-20 insights-card">
  <div class="card-header">
    <span class="card-title"><i data-lucide="sparkles" style="width:16px;height:16px;"></i> Business Insights</span>
  </div>
  <div class="insights-list">
    <?php foreach ($insights as $ins): ?>
    <div class="insight-item insight-<?= htmlspecialchars($ins['type']) ?>">
      <i data-lucide="<?= htmlspecialchars($ins['icon']) ?>"></i>
      <span><?= $ins['text'] ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
    <?php
}

function reportInit(): array {
    $dates   = reportParseDateRange($_GET);
    $filters = reportParseFilters($_GET);
    $options = reportFilterOptions(getDB());
    return compact('dates', 'filters', 'options');
}
