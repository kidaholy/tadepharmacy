<?php
require_once __DIR__ . '/layout.php';

$pdo  = getDB();
$type = $_GET['type'] ?? 'daily';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// Revenue summary
$revenue = $pdo->prepare("SELECT COALESCE(SUM(total_amount-discount),0) FROM sales WHERE date(created_at) BETWEEN ? AND ?")->execute([$from,$to]) ? ($q=$pdo->prepare("SELECT COALESCE(SUM(total_amount-discount),0) FROM sales WHERE date(created_at) BETWEEN ? AND ?")) && $q->execute([$from,$to]) ? $q->fetchColumn() : 0 : 0;
$q = $pdo->prepare("SELECT COALESCE(SUM(total_amount-discount),0) FROM sales WHERE date(created_at) BETWEEN ? AND ?");
$q->execute([$from,$to]);
$revenue = (float)$q->fetchColumn();

$q2 = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE date(created_at) BETWEEN ? AND ?");
$q2->execute([$from,$to]);
$saleCount = (int)$q2->fetchColumn();

// Cost (purchases in period)
$q3 = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE date(created_at) BETWEEN ? AND ?");
$q3->execute([$from,$to]);
$cost = (float)$q3->fetchColumn();
$profit = $revenue - $cost;

// Daily breakdown
$daily = $pdo->prepare("SELECT date(created_at) as day, COALESCE(SUM(total_amount-discount),0) as rev, COUNT(*) as txn FROM sales WHERE date(created_at) BETWEEN ? AND ? GROUP BY day ORDER BY day");
$daily->execute([$from,$to]);
$dailyRows = $daily->fetchAll();

// Top selling medicines
$topMeds = $pdo->prepare("
    SELECT m.name, SUM(si.quantity) as qty_sold, SUM(si.subtotal) as revenue
    FROM sale_items si
    JOIN medicines m ON m.id = si.medicine_id
    JOIN sales s ON s.id = si.sale_id
    WHERE date(s.created_at) BETWEEN ? AND ?
    GROUP BY m.id ORDER BY qty_sold DESC LIMIT 10
");
$topMeds->execute([$from,$to]);
$topMedsData = $topMeds->fetchAll();

// Payment method breakdown
$payBreak = $pdo->prepare("SELECT payment_method, COUNT(*) as cnt, SUM(total_amount-discount) as rev FROM sales WHERE date(created_at) BETWEEN ? AND ? GROUP BY payment_method");
$payBreak->execute([$from,$to]);
$payData = $payBreak->fetchAll();

renderHead('Reports');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Reports', 'Financial & inventory analytics'); ?>
<div class="page-body">

<!-- Filters -->
<div class="card mb-20">
  <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
    <div class="form-group" style="margin:0;min-width:140px;">
      <label>From Date</label>
      <input type="date" name="from" value="<?= $from ?>">
    </div>
    <div class="form-group" style="margin:0;min-width:140px;">
      <label>To Date</label>
      <input type="date" name="to" value="<?= $to ?>">
    </div>
    <button type="submit" class="btn btn-primary">Generate Report</button>
    <a href="reports.php?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-ghost">This Month</a>
    <a href="reports.php?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-ghost">Today</a>
    <a href="reports.php?from=<?= date('Y-01-01') ?>&to=<?= date('Y-12-31') ?>" class="btn btn-ghost">This Year</a>
  </form>
</div>

<!-- KPIs -->
<div class="stats-grid">
  <div class="stat-card blue">
    <div class="stat-icon blue"><i data-lucide="trending-up"></i></div>
    <div>
      <div class="stat-label">Total Revenue</div>
      <div class="stat-value"><?= number_format($revenue, 0) ?></div>
      <div class="stat-sub"><?= getSetting('currency','ETB') ?> · <?= $saleCount ?> sales</div>
    </div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon green"><i data-lucide="dollar-sign"></i></div>
    <div>
      <div class="stat-label">Est. Profit</div>
      <div class="stat-value"><?= number_format($profit, 0) ?></div>
      <div class="stat-sub"><?= $revenue > 0 ? number_format($profit/$revenue*100,1) : '0' ?>% margin</div>
    </div>
  </div>
  <div class="stat-card orange">
    <div class="stat-icon orange"><i data-lucide="package-open"></i></div>
    <div>
      <div class="stat-label">Purchase Cost</div>
      <div class="stat-value"><?= number_format($cost, 0) ?></div>
      <div class="stat-sub"><?= getSetting('currency','ETB') ?> in period</div>
    </div>
  </div>
  <div class="stat-card blue">
    <div class="stat-icon blue"><i data-lucide="receipt"></i></div>
    <div>
      <div class="stat-label">Avg Sale Value</div>
      <div class="stat-value"><?= $saleCount > 0 ? number_format($revenue/$saleCount, 0) : '0' ?></div>
      <div class="stat-sub"><?= getSetting('currency','ETB') ?> per transaction</div>
    </div>
  </div>
</div>

<div class="grid-2 mb-20">
  <!-- Daily Sales Table -->
  <div class="card">
    <div class="card-header"><span class="card-title">Daily Sales Breakdown</span></div>
    <div class="table-wrap" style="max-height:340px;overflow-y:auto;">
      <table>
        <thead><tr><th>Date</th><th>Transactions</th><th>Revenue</th></tr></thead>
        <tbody>
        <?php if (empty($dailyRows)): ?>
          <tr><td colspan="3" style="text-align:center;padding:30px;color:var(--text-300);">No data in range</td></tr>
        <?php else: ?>
        <?php foreach ($dailyRows as $d): ?>
        <tr>
          <td><?= date('D, M d', strtotime($d['day'])) ?></td>
          <td style="text-align:center;"><?= $d['txn'] ?></td>
          <td style="font-weight:700;color:var(--accent2);"><?= currency($d['rev']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Payment Methods -->
  <div class="card">
    <div class="card-header"><span class="card-title">Payment Methods</span></div>
    <?php if (empty($payData)): ?>
      <div class="empty-state"><i data-lucide="pie-chart"></i><p>No data</p></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:14px;">
      <?php
      $totalRev = $revenue ?: 1;
      foreach ($payData as $p):
        $pct = round($p['rev'] / $totalRev * 100, 1);
        $cls = ['cash'=>'green','telebirr'=>'blue','cbe'=>'blue','card'=>'orange','mpesa'=>'green'][$p['payment_method']] ?? 'gray';
      ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px;">
          <span style="font-weight:600;color:var(--text-100);text-transform:capitalize;"><?= htmlspecialchars($p['payment_method']) ?> <span class="badge badge-gray" style="font-size:10px;"><?= $p['cnt'] ?> sales</span></span>
          <span style="color:var(--accent2);font-weight:700;"><?= $pct ?>%</span>
        </div>
        <div class="progress-bar"><div class="progress-fill <?= $cls ?>" style="width:<?= $pct ?>%;"></div></div>
        <div style="font-size:11px;color:var(--text-300);margin-top:3px;"><?= currency($p['rev']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Top Medicines -->
<div class="card">
  <div class="card-header"><span class="card-title">Top Selling Medicines</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Rank</th><th>Medicine</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
      <tbody>
      <?php if (empty($topMedsData)): ?>
        <tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-300);">No sales data</td></tr>
      <?php else: ?>
      <?php foreach ($topMedsData as $i => $m): ?>
      <tr>
        <td>
          <?php if ($i === 0): ?><span style="font-size:18px;">🥇</span>
          <?php elseif ($i === 1): ?><span style="font-size:18px;">🥈</span>
          <?php elseif ($i === 2): ?><span style="font-size:18px;">🥉</span>
          <?php else: ?><span style="color:var(--text-300);font-weight:600;">#<?= $i+1 ?></span><?php endif; ?>
        </td>
        <td style="font-weight:600;color:var(--text-100);"><?= htmlspecialchars($m['name']) ?></td>
        <td><?= number_format($m['qty_sold']) ?> units</td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency($m['revenue']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div></div>
<?php renderFooter(); ?>
