<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';

$pdo = getDB();
extract(reportInit());
$payData = reportPaymentBreakdown($pdo, $dates, $filters);
$total = array_sum(array_column($payData, 'amount')) ?: 1;
$methods = reportPaymentMethods();

renderHead('Payment Reports', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Payment Reports', 'Payment method analysis'); ?>
<div class="page-body">
<?php renderReportNav('report_payments', $dates, $filters); ?>
<?php renderReportFilters($dates, $filters, $options); ?>
<?php renderReportMeta('Payment Reports', $dates); ?>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Payment Breakdown</span></div>
    <div class="chart-wrap chart-donut"><canvas id="chartPayments" data-labels='<?= json_encode(array_map(fn($p)=>$methods[$p['payment_method']]??ucfirst($p['payment_method']), $payData)) ?>' data-values='<?= json_encode(array_map(fn($p)=>(float)$p['amount'], $payData)) ?>'></canvas></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Transaction Count</span></div>
    <div class="chart-wrap"><canvas id="chartTopProducts" data-labels='<?= json_encode(array_map(fn($p)=>$methods[$p['payment_method']]??ucfirst($p['payment_method']), $payData)) ?>' data-values='<?= json_encode(array_map(fn($p)=>(int)$p['cnt'], $payData)) ?>' data-label="Transactions"></canvas></div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Payment Method Details</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Payment Method</th><th>Transactions</th><th>Amount</th><th>Share</th></tr></thead>
    <tbody>
    <?php foreach ($payData as $p):
      $pct = round((float)$p['amount'] / $total * 100, 1);
      $lbl = $methods[$p['payment_method']] ?? ucfirst($p['payment_method']);
    ?>
    <tr>
      <td style="font-weight:600;"><?= htmlspecialchars($lbl) ?></td>
      <td><?= number_format($p['cnt']) ?></td>
      <td style="font-weight:700;color:var(--accent2);"><?= currency($p['amount']) ?></td>
      <td>
        <div style="display:flex;align-items:center;gap:8px;">
          <div class="progress-bar" style="flex:1;"><div class="progress-fill green" style="width:<?= $pct ?>%;"></div></div>
          <span><?= $pct ?>%</span>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($payData)): ?><tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-300);">No payment data</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
</div></div>
<?php renderFooter(); ?>
