<?php
require_once __DIR__ . '/report_lib.php';

function renderReportExtras(): void {
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>';
    echo '<script src="assets/reports.js" defer></script>';
}

function renderReportNav(string $activePage, array $dates = [], array $filters = []): void {
    $qs = $dates ? reportQueryString($dates, $filters) : '';
    $suffix = $qs !== '' ? ('?' . $qs) : '';
    echo '<div class="report-nav no-print mb-20">';
    foreach (reportNavItems() as $item) {
        $active = ($activePage === $item['page']) ? ' active' : '';
        echo '<a href="' . $item['page'] . '.php' . $suffix . '" class="report-nav-item' . $active . '">';
        echo '<i data-lucide="' . $item['icon'] . '"></i> ' . htmlspecialchars($item['label']);
        echo '</a>';
    }
    echo '</div>';
}

function reportActiveFilterChips(array $dates, array $filters, array $options): array {
    $chips = [];
    $chips[] = 'Period: ' . $dates['label'];
    if (!empty($filters['product'])) {
        foreach ($options['products'] as $p) {
            if ((int)$p['id'] === (int)$filters['product']) {
                $chips[] = 'Product: ' . $p['name'];
                break;
            }
        }
    }
    if (!empty($filters['category'])) {
        foreach ($options['categories'] as $c) {
            if ((int)$c['id'] === (int)$filters['category']) {
                $chips[] = 'Category: ' . $c['name'];
                break;
            }
        }
    }
    if (!empty($filters['supplier'])) {
        foreach ($options['suppliers'] as $s) {
            if ((int)$s['id'] === (int)$filters['supplier']) {
                $chips[] = 'Supplier: ' . $s['name'];
                break;
            }
        }
    }
    if ($filters['customer'] !== '') $chips[] = 'Customer: ' . $filters['customer'];
    if (!empty($filters['cashier'])) {
        foreach ($options['cashiers'] as $u) {
            if ((int)$u['id'] === (int)$filters['cashier']) {
                $chips[] = 'Cashier: ' . $u['full_name'];
                break;
            }
        }
    }
    if ($filters['payment_method'] !== '') {
        $chips[] = 'Payment: ' . (reportPaymentMethods()[$filters['payment_method']] ?? $filters['payment_method']);
    }
    if ($filters['sales_type'] !== '') $chips[] = 'Type: ' . ucfirst($filters['sales_type']);
    return $chips;
}

function renderReportFilters(array $dates, array $filters, array $options, string $formAction = '', ?array $presets = null, bool $showProduct = true, bool $showSupplier = true, string $exportReport = 'overview'): void {
    $presets = $presets ?? reportDatePresets();
    $payments = reportPaymentMethods();
    $isCustom = $dates['preset'] === 'custom';
    $chips = reportActiveFilterChips($dates, $filters, $options);
    $hasExtra = $filters['product'] || $filters['category'] || $filters['supplier']
        || $filters['customer'] !== '' || $filters['cashier'] || $filters['payment_method'] !== ''
        || $filters['sales_type'] !== '';
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
      <div class="form-group report-custom-dates" id="customDateFrom" style="<?= $isCustom ? '' : 'display:none;' ?>">
        <label>Start Date</label>
        <input type="date" name="from" id="reportDateFrom" value="<?= htmlspecialchars($dates['from']) ?>" <?= $isCustom ? '' : 'disabled' ?>>
      </div>
      <div class="form-group report-custom-dates" id="customDateTo" style="<?= $isCustom ? '' : 'display:none;' ?>">
        <label>End Date</label>
        <input type="date" name="to" id="reportDateTo" value="<?= htmlspecialchars($dates['to']) ?>" <?= $isCustom ? '' : 'disabled' ?>>
      </div>
      <?php if ($showProduct): ?>
      <div class="form-group">
        <label>Product</label>
        <select name="product">
          <option value="">All Products</option>
          <?php foreach ($options['products'] as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $filters['product'] == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Category</label>
        <select name="category">
          <option value="">All Categories</option>
          <?php
          // Group categories by product type so Medicine / Cosmetics / Equipment stay separate.
          $catGroups = [];
          foreach ($options['categories'] as $c) {
              $pt = $c['product_type'] ?? 'medicine';
              $catGroups[$pt][] = $c;
          }
          $catLabels = ['medicine' => 'Medicine', 'cosmetic' => 'Cosmetics', 'equipment' => 'Equipment'];
          foreach (array_keys($catGroups) as $pt) {
              if (!isset($catLabels[$pt])) $catLabels[$pt] = ucfirst($pt);
          }
          foreach ($catLabels as $pt => $ptLabel):
              if (empty($catGroups[$pt])) continue;
          ?>
          <optgroup label="<?= htmlspecialchars($ptLabel) ?>">
            <?php foreach ($catGroups[$pt] as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filters['category'] == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($showSupplier): ?>
      <div class="form-group">
        <label>Supplier</label>
        <select name="supplier">
          <option value="">All Suppliers</option>
          <?php foreach ($options['suppliers'] as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filters['supplier'] == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
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
      <?php renderReportExportButtons($dates, $filters, $exportReport); ?>
    </div>
  </form>
  <div class="report-active-filters" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
    <?php foreach ($chips as $chip): ?>
    <span class="badge badge-blue" style="font-weight:500;"><?= htmlspecialchars($chip) ?></span>
    <?php endforeach; ?>
    <?php if ($hasExtra): ?>
    <a href="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>" style="font-size:12px;align-self:center;">Clear filters</a>
    <?php endif; ?>
  </div>
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
