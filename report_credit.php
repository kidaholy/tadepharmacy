<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';

$pdo = getDB();
extract(reportInit());
$net = reportSaleNetExpr('s');
$today = date('Y-m-d');
$weekEnd = date('Y-m-d', strtotime('+7 days'));
$monthEnd = date('Y-m-t');

$outstanding = $pdo->query("
    SELECT s.*, ($net - s.paid_amount) AS balance
    FROM sales s
    WHERE (s.sale_type = 'credit' OR s.payment_method = 'credit')
      AND ($net - s.paid_amount) > 0.009
    ORDER BY CASE WHEN s.credit_due_date IS NULL THEN 1 ELSE 0 END, s.credit_due_date ASC, s.created_at DESC
")->fetchAll();

$overdue = array_filter($outstanding, fn($r) => $r['credit_due_date'] && $r['credit_due_date'] < $today);
$dueToday = array_filter($outstanding, fn($r) => $r['credit_due_date'] === $today);
$dueWeek = array_filter($outstanding, fn($r) => $r['credit_due_date'] && $r['credit_due_date'] >= $today && $r['credit_due_date'] <= $weekEnd);
$dueMonth = array_filter($outstanding, fn($r) => $r['credit_due_date'] && $r['credit_due_date'] >= $today && $r['credit_due_date'] <= $monthEnd);

$collected = $pdo->prepare("
    SELECT COALESCE(SUM(s.paid_amount), 0) FROM sales s
    WHERE (s.sale_type = 'credit' OR s.payment_method = 'credit')
      AND date(s.created_at) BETWEEN ? AND ?
");
$collected->execute([$dates['from'], $dates['to']]);
$collectedAmt = (float)$collected->fetchColumn();

$topCredit = $pdo->query("
    SELECT s.customer_name, COUNT(*) AS orders,
           SUM($net - s.paid_amount) AS outstanding,
           MAX(s.created_at) AS last_sale
    FROM sales s
    WHERE (s.sale_type = 'credit' OR s.payment_method = 'credit')
      AND ($net - s.paid_amount) > 0.009
      AND s.customer_name IS NOT NULL AND TRIM(s.customer_name) != ''
    GROUP BY s.customer_name ORDER BY outstanding DESC LIMIT 20
")->fetchAll();

$totalOutstanding = array_sum(array_column($outstanding, 'balance'));
$totalOverdue = array_sum(array_column($overdue, 'balance'));

renderHead('Credit Reports', 'report-page');
renderReportExtras();
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Credit Reports', 'Outstanding & collections'); ?>
<div class="page-body">
<?php renderReportNav('report_credit'); ?>
<?php renderReportFilters($dates, $filters, $options); ?>
<?php renderReportMeta('Credit Reports', $dates); ?>

<div class="stats-grid mb-20">
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="credit-card"></i></div><div><div class="stat-label">Outstanding Credit</div><div class="stat-value"><?= number_format($totalOutstanding, 0) ?></div></div></div>
  <div class="stat-card red"><div class="stat-icon red"><i data-lucide="alert-circle"></i></div><div><div class="stat-label">Overdue Credit</div><div class="stat-value"><?= number_format($totalOverdue, 0) ?></div></div></div>
  <div class="stat-card green"><div class="stat-icon green"><i data-lucide="check-circle"></i></div><div><div class="stat-label">Credit Collected</div><div class="stat-value"><?= number_format($collectedAmt, 0) ?></div><div class="stat-sub">In selected period</div></div></div>
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="users"></i></div><div><div class="stat-label">Credit Accounts</div><div class="stat-value"><?= count($outstanding) ?></div></div></div>
</div>

<div class="grid-2 mb-20">
  <div class="card">
    <div class="card-header"><span class="card-title">Due Today (<?= count($dueToday) ?>)</span></div>
    <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Balance</th></tr></thead><tbody>
    <?php foreach ($dueToday as $r): ?><tr><td><?= htmlspecialchars($r['customer_name']) ?></td><td><?= currency($r['balance']) ?></td></tr><?php endforeach; ?>
    <?php if (empty($dueToday)): ?><tr><td colspan="2" style="text-align:center;color:var(--text-300);padding:20px;">None due today</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Due This Week (<?= count($dueWeek) ?>)</span></div>
    <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Due</th><th>Balance</th></tr></thead><tbody>
    <?php foreach ($dueWeek as $r): ?><tr><td><?= htmlspecialchars($r['customer_name']) ?></td><td><?= $r['credit_due_date'] ? date('M j', strtotime($r['credit_due_date'])) : '—' ?></td><td><?= currency($r['balance']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Top Credit Customers</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Customer</th><th>Orders</th><th>Outstanding</th><th>Last Sale</th></tr></thead>
    <tbody>
    <?php foreach ($topCredit as $c): ?>
    <tr>
      <td style="font-weight:600;"><?= htmlspecialchars($c['customer_name']) ?></td>
      <td><?= (int)$c['orders'] ?></td>
      <td style="color:var(--warning);font-weight:700;"><?= currency($c['outstanding']) ?></td>
      <td><?= date('M j, Y', strtotime($c['last_sale'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($topCredit)): ?><tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-300);">No credit customers</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">All Outstanding Credit</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Invoice</th><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th><th>Due Date</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($outstanding as $r):
      $isOverdue = $r['credit_due_date'] && $r['credit_due_date'] < $today;
    ?>
    <tr>
      <td><code><?= htmlspecialchars($r['invoice_number']) ?></code></td>
      <td><?= htmlspecialchars($r['customer_name']) ?></td>
      <td><?= currency($r['total_amount'] - $r['discount']) ?></td>
      <td><?= currency($r['paid_amount']) ?></td>
      <td style="font-weight:700;color:var(--warning);"><?= currency($r['balance']) ?></td>
      <td><?= $r['credit_due_date'] ? date('M j, Y', strtotime($r['credit_due_date'])) : '—' ?></td>
      <td><span class="badge <?= $isOverdue ? 'badge-red' : 'badge-orange' ?>"><?= $isOverdue ? 'Overdue' : 'Open' ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
</div></div>
<?php renderFooter(); ?>
