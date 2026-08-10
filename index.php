<?php
require_once __DIR__ . '/layout.php';

$pdo = getDB();

// KPIs
$todayRevenue = $pdo->query("SELECT COALESCE(SUM(total_amount - discount), 0) FROM sales WHERE date(created_at) = date('now')")->fetchColumn();
$todaySales   = $pdo->query("SELECT COUNT(*) FROM sales WHERE date(created_at) = date('now')")->fetchColumn();
$monthRevenue = $pdo->query("SELECT COALESCE(SUM(total_amount - discount), 0) FROM sales WHERE strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')")->fetchColumn();
$totalMeds    = $pdo->query("SELECT COUNT(*) FROM medicines")->fetchColumn();
$lowStock     = $pdo->query("SELECT COUNT(DISTINCT m.id) FROM medicines m JOIN batches b ON b.medicine_id = m.id GROUP BY m.id HAVING SUM(b.quantity) <= m.reorder_level")->fetchColumn();
$expiringSoon = $pdo->query("SELECT COUNT(*) FROM batches WHERE expiry_date BETWEEN date('now') AND date('now', '+30 days') AND quantity > 0")->fetchColumn();
$expired      = $pdo->query("SELECT COUNT(*) FROM batches WHERE expiry_date < date('now') AND quantity > 0")->fetchColumn();
$totalStock   = $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM batches")->fetchColumn();

// Recent sales
$recentSales = $pdo->query("SELECT s.*, COUNT(si.id) as items FROM sales s LEFT JOIN sale_items si ON si.sale_id = s.id GROUP BY s.id ORDER BY s.created_at DESC LIMIT 8")->fetchAll();

// Low stock medicines
$lowMeds = $pdo->query("
    SELECT m.name, m.reorder_level, COALESCE(SUM(b.quantity),0) as stock
    FROM medicines m
    LEFT JOIN batches b ON b.medicine_id = m.id
    GROUP BY m.id
    HAVING stock <= m.reorder_level
    ORDER BY stock ASC
    LIMIT 6
")->fetchAll();

// Expiring batches
$expiringBatches = $pdo->query("
    SELECT b.batch_number, b.expiry_date, b.quantity, m.name
    FROM batches b
    JOIN medicines m ON m.id = b.medicine_id
    WHERE b.expiry_date <= date('now', '+30 days') AND b.quantity > 0
    ORDER BY b.expiry_date ASC
    LIMIT 6
")->fetchAll();

// Last 7 days revenue
$weekData = $pdo->query("
    SELECT date(created_at) as day, COALESCE(SUM(total_amount-discount),0) as rev
    FROM sales
    WHERE created_at >= date('now', '-6 days')
    GROUP BY day ORDER BY day
")->fetchAll();

renderHead('Dashboard');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Dashboard', 'Welcome to TADE PHARMACY'); ?>
<div class="page-body">

<?php if ($expired > 0): ?>
<div class="alert alert-danger auto-hide" style="transition:opacity 0.3s,transform 0.3s;">
  <i data-lucide="alert-triangle"></i>
  <strong><?= $expired ?> batches have expired</strong> — Please review and dispose of expired stock immediately.
  <a href="inventory.php" style="color:inherit;margin-left:auto;font-weight:700;">View →</a>
</div>
<?php endif; ?>

<?php if ($expiringSoon > 0): ?>
<div class="alert alert-warning auto-hide" style="transition:opacity 0.3s,transform 0.3s;">
  <i data-lucide="clock"></i>
  <strong><?= $expiringSoon ?> batches expire within 30 days</strong> — Prioritize selling these items (FEFO).
  <a href="inventory.php" style="color:inherit;margin-left:auto;font-weight:700;">View →</a>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card blue">
    <div class="stat-icon blue"><i data-lucide="trending-up"></i></div>
    <div>
      <div class="stat-label">Today's Revenue</div>
      <div class="stat-value"><?= number_format($todayRevenue, 0) ?></div>
      <div class="stat-sub"><?= getSetting('currency','ETB') ?> · <?= $todaySales ?> sales</div>
    </div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon green"><i data-lucide="calendar"></i></div>
    <div>
      <div class="stat-label">Monthly Revenue</div>
      <div class="stat-value"><?= number_format($monthRevenue, 0) ?></div>
      <div class="stat-sub"><?= getSetting('currency','ETB') ?> this month</div>
    </div>
  </div>
  <div class="stat-card orange">
    <div class="stat-icon orange"><i data-lucide="pill"></i></div>
    <div>
      <div class="stat-label">Medicines</div>
      <div class="stat-value"><?= $totalMeds ?></div>
      <div class="stat-sub"><?= number_format($totalStock) ?> total units</div>
    </div>
  </div>
  <div class="stat-card red">
    <div class="stat-icon red"><i data-lucide="alert-triangle"></i></div>
    <div>
      <div class="stat-label">Low Stock</div>
      <div class="stat-value"><?= $lowStock ?></div>
      <div class="stat-sub"><?= $expiringSoon ?> expiring soon</div>
    </div>
  </div>
</div>

<!-- Main Grid -->
<div class="grid-2 mb-20">
  <!-- Recent Sales -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Recent Sales</span>
      <a href="sales.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-right"></i> View All</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Invoice</th><th>Customer</th><th>Items</th><th>Amount</th><th>Time</th></tr></thead>
        <tbody>
        <?php if (empty($recentSales)): ?>
          <tr><td colspan="5" class="text-right" style="text-align:center;color:var(--text-300);padding:30px">No sales yet</td></tr>
        <?php else: ?>
          <?php foreach ($recentSales as $s): ?>
          <tr>
            <td><span class="badge badge-blue"><?= htmlspecialchars($s['invoice_number']) ?></span></td>
            <td><?= htmlspecialchars($s['customer_name']) ?></td>
            <td><?= $s['items'] ?></td>
            <td style="color:var(--accent2);font-weight:700;"><?= currency($s['total_amount'] - $s['discount']) ?></td>
            <td style="font-size:11px;"><?= date('H:i', strtotime($s['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Low Stock -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Low Stock Alert</span>
      <a href="inventory.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-right"></i> View All</a>
    </div>
    <?php if (empty($lowMeds)): ?>
      <div class="empty-state"><i data-lucide="check-circle"></i><p>All stock levels are healthy</p></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px;">
      <?php foreach ($lowMeds as $m):
        $pct = $m['reorder_level'] > 0 ? min(100, round($m['stock'] / $m['reorder_level'] * 50)) : 0;
        $cls = $m['stock'] == 0 ? 'red' : ($m['stock'] <= 5 ? 'red' : 'orange');
      ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px;">
          <span style="font-weight:600;color:var(--text-100);"><?= htmlspecialchars($m['name']) ?></span>
          <span style="color:var(--<?= $cls ?>);font-weight:700;"><?= $m['stock'] ?> / <?= $m['reorder_level'] ?></span>
        </div>
        <div class="progress-bar"><div class="progress-fill <?= $cls ?>" style="width:<?= $pct ?>%;"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Expiring Batches -->
<?php if (!empty($expiringBatches)): ?>
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title">Expiring Batches (next 30 days)</span>
    <a href="inventory.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-right"></i> View All</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Medicine</th><th>Batch #</th><th>Qty</th><th>Expiry Date</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($expiringBatches as $b):
        $days = (strtotime($b['expiry_date']) - time()) / 86400;
        $cls  = $days < 0 ? 'badge-red' : ($days <= 7 ? 'badge-red' : ($days <= 14 ? 'badge-orange' : 'badge-blue'));
        $lbl  = $days < 0 ? 'Expired' : ($days <= 7 ? 'Critical' : ($days <= 14 ? 'Urgent' : 'Warning'));
      ?>
      <tr>
        <td style="font-weight:600;color:var(--text-100);"><?= htmlspecialchars($b['name']) ?></td>
        <td><code style="background:var(--bg-600);padding:2px 7px;border-radius:4px;font-size:12px;"><?= htmlspecialchars($b['batch_number']) ?></code></td>
        <td><?= $b['quantity'] ?></td>
        <td><?= date('M d, Y', strtotime($b['expiry_date'])) ?></td>
        <td><span class="badge <?= $cls ?>"><?= $lbl ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="card">
  <div class="card-title" style="margin-bottom:16px;">Quick Actions</div>
  <div style="display:flex;gap:12px;flex-wrap:wrap;">
    <a href="pos.php"       class="btn btn-primary"><i data-lucide="scan-barcode"></i> New Sale</a>
    <a href="medicines.php?action=add" class="btn btn-ghost"><i data-lucide="plus"></i> Add Medicine</a>
    <a href="purchases.php?action=add" class="btn btn-ghost"><i data-lucide="package-open"></i> Record Purchase</a>
    <a href="suppliers.php?action=add" class="btn btn-ghost"><i data-lucide="truck"></i> Add Supplier</a>
    <a href="reports.php"   class="btn btn-ghost"><i data-lucide="bar-chart-3"></i> View Reports</a>
  </div>
</div>

</div></div>
<?php renderFooter(); ?>
