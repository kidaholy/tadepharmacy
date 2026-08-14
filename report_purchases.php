<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_ui.php';
require_once __DIR__ . '/purchases_lib.php';

$pdo = getDB();
extract(reportInit());
$tab = $_GET['tab'] ?? 'purchases';
$from = $dates['from'];
$to = $dates['to'];
$supplierId = (int)($filters['supplier'] ?? 0);
$supWhere = $supplierId ? ' AND p.supplier_id = ' . $supplierId : '';

$purchases = $pdo->prepare("
    SELECT p.*, s.name AS supplier_name
    FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id
    WHERE date(COALESCE(p.purchase_date,p.created_at)) BETWEEN ? AND ? $supWhere
    ORDER BY p.id DESC
");
$purchases->execute([$from, $to]);
$purchases = $purchases->fetchAll();

$payRows = $pdo->prepare("
    SELECT pp.*, p.purchase_number, s.name AS supplier_name
    FROM purchase_payments pp
    JOIN purchases p ON p.id=pp.purchase_id
    LEFT JOIN suppliers s ON s.id=p.supplier_id
    WHERE pp.payment_date BETWEEN ? AND ? " . ($supplierId ? ' AND p.supplier_id=' . $supplierId : '') . "
    ORDER BY pp.payment_date DESC
");
$payRows->execute([$from, $to]);
$payRows = $payRows->fetchAll();

$overdue = $pdo->query("
    SELECT p.*, s.name AS supplier_name,
           (COALESCE(p.grand_total,p.total_amount)-COALESCE(p.total_paid,0)-COALESCE(p.total_returned,0)) AS due_amt,
           CAST(julianday('now') - julianday(p.due_date) AS INTEGER) AS days_overdue
    FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id
    WHERE p.status='received' AND p.due_date < date('now')
      AND (COALESCE(p.grand_total,p.total_amount)-COALESCE(p.total_paid,0)-COALESCE(p.total_returned,0)) > 0.009
    ORDER BY p.due_date ASC
")->fetchAll();

$returns = $pdo->prepare("
    SELECT r.*, p.purchase_number, s.name AS supplier_name
    FROM purchase_returns r
    LEFT JOIN purchases p ON p.id=r.purchase_id
    LEFT JOIN suppliers s ON s.id=r.supplier_id
    WHERE r.return_date BETWEEN ? AND ? " . ($supplierId ? ' AND r.supplier_id=' . $supplierId : '') . "
    ORDER BY r.id DESC
");
$returns->execute([$from, $to]);
$returns = $returns->fetchAll();

$bySupplier = $pdo->query("
    SELECT s.id, s.name,
           COALESCE(SUM(CASE WHEN p.status='received' THEN COALESCE(p.grand_total,p.total_amount) ELSE 0 END),0) AS purchased,
           COALESCE(SUM(CASE WHEN p.status!='cancelled' THEN COALESCE(p.total_paid,0) ELSE 0 END),0) AS paid,
           COALESCE(SUM(CASE WHEN p.status='received' THEN COALESCE(p.grand_total,p.total_amount)-COALESCE(p.total_paid,0)-COALESCE(p.total_returned,0) ELSE 0 END),0) AS outstanding
    FROM suppliers s
    LEFT JOIN purchases p ON p.supplier_id = s.id
    GROUP BY s.id
    HAVING purchased > 0 OR outstanding > 0
    ORDER BY outstanding DESC, s.name
")->fetchAll();

$totalPurch = array_sum(array_map(fn($p) => (float)($p['grand_total'] ?? $p['total_amount']), $purchases));
$totalPaid = array_sum(array_map(fn($p) => (float)$p['total_paid'], $purchases));
$totalDue = array_sum(array_map(fn($p) => purchaseOutstanding($p), $purchases));

renderHead('Purchase Reports');
renderSidebar();
renderReportExtras();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Purchase Reports', 'Purchases, payables, payments & returns'); ?>
<div class="page-body">
<?php renderReportNav('report_purchases'); ?>
<?php renderReportFilters($dates, $filters, $options, 'report_purchases.php'); ?>

<div class="admin-tabs no-print">
  <?php foreach (['purchases'=>'Purchases','credit'=>'Credit / Payable','payments'=>'Payments','overdue'=>'Overdue','returns'=>'Returns'] as $k=>$lbl): ?>
  <a class="admin-tab<?= $tab===$k?' active':'' ?>" href="report_purchases.php?tab=<?= $k ?>&<?= htmlspecialchars(http_build_query(array_filter(['preset'=>$dates['preset']??null,'from'=>$from,'to'=>$to,'supplier'=>$supplierId?:null]))) ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="no-print mb-20" style="display:flex;gap:8px;">
  <a class="btn btn-ghost btn-sm" href="report_export.php?report=purchases&tab=<?= urlencode($tab) ?>&<?= htmlspecialchars(http_build_query(['from'=>$from,'to'=>$to,'supplier'=>$supplierId?:null,'format'=>'csv'])) ?>">Export CSV</a>
  <a class="btn btn-ghost btn-sm" href="report_export.php?report=purchases&tab=<?= urlencode($tab) ?>&<?= htmlspecialchars(http_build_query(['from'=>$from,'to'=>$to,'supplier'=>$supplierId?:null,'format'=>'excel'])) ?>">Export Excel</a>
  <button class="btn btn-ghost btn-sm" onclick="window.print()">Print</button>
</div>

<?php if ($tab === 'purchases'): ?>
<div class="stats-grid mb-20">
  <div class="stat-card blue"><div class="stat-label">Purchases</div><div class="stat-value"><?= currency($totalPurch) ?></div></div>
  <div class="stat-card green"><div class="stat-label">Paid</div><div class="stat-value"><?= currency($totalPaid) ?></div></div>
  <div class="stat-card orange"><div class="stat-label">Outstanding</div><div class="stat-value"><?= currency($totalDue) ?></div></div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">Purchase Report</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Invoice</th><th>Supplier</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($purchases as $p): $d=purchaseDisplayStatus($p); ?>
      <tr>
        <td><code><?= htmlspecialchars($p['purchase_number']) ?></code></td>
        <td><?= htmlspecialchars($p['supplier_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($p['purchase_date'] ?: substr($p['created_at'],0,10)) ?></td>
        <td><?= currency((float)($p['grand_total']??$p['total_amount'])) ?></td>
        <td><?= currency((float)$p['total_paid']) ?></td>
        <td><?= currency(purchaseOutstanding($p)) ?></td>
        <td><span class="badge <?= $d[2] ?>"><?= htmlspecialchars($d[1]) ?></span></td>
      </tr>
    <?php endforeach; if (!$purchases): ?>
      <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-300);">No purchases in this range</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php elseif ($tab === 'credit'): ?>
<div class="card">
  <div class="card-header"><span class="card-title">Supplier Payable Report</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Supplier</th><th>Total Purchases</th><th>Paid</th><th>Outstanding</th></tr></thead>
    <tbody>
    <?php foreach ($bySupplier as $s): ?>
      <tr>
        <td><a href="suppliers.php?id=<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></a></td>
        <td><?= currency((float)$s['purchased']) ?></td>
        <td><?= currency((float)$s['paid']) ?></td>
        <td style="font-weight:700;color:var(--warning);"><?= currency((float)$s['outstanding']) ?></td>
      </tr>
    <?php endforeach; if (!$bySupplier): ?>
      <tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-300);">No supplier balances</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php elseif ($tab === 'payments'): ?>
<div class="card">
  <div class="card-header"><span class="card-title">Payment Report</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Supplier</th><th>Invoice</th><th>Amount</th><th>Method</th><th>Reference</th></tr></thead>
    <tbody>
    <?php foreach ($payRows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['payment_date']) ?></td>
        <td><?= htmlspecialchars($r['supplier_name'] ?? '—') ?></td>
        <td><code><?= htmlspecialchars($r['purchase_number']) ?></code></td>
        <td><?= currency((float)$r['amount']) ?></td>
        <td><?= htmlspecialchars(supplierPaymentMethods()[$r['payment_method']] ?? $r['payment_method']) ?></td>
        <td><?= htmlspecialchars($r['reference_number'] ?: '—') ?></td>
      </tr>
    <?php endforeach; if (!$payRows): ?>
      <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-300);">No payments in this range</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php elseif ($tab === 'overdue'): ?>
<div class="card">
  <div class="card-header"><span class="card-title">Overdue Report</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Supplier</th><th>Invoice</th><th>Original</th><th>Paid</th><th>Due</th><th>Due Date</th><th>Days Overdue</th></tr></thead>
    <tbody>
    <?php foreach ($overdue as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['supplier_name'] ?? '—') ?></td>
        <td><code><?= htmlspecialchars($p['purchase_number']) ?></code></td>
        <td><?= currency((float)($p['grand_total']??$p['total_amount'])) ?></td>
        <td><?= currency((float)$p['total_paid']) ?></td>
        <td style="color:var(--danger);font-weight:700;"><?= currency((float)$p['due_amt']) ?></td>
        <td><?= htmlspecialchars($p['due_date']) ?></td>
        <td><?= (int)$p['days_overdue'] ?></td>
      </tr>
    <?php endforeach; if (!$overdue): ?>
      <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-300);">No overdue purchases</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php else: ?>
<div class="card">
  <div class="card-header"><span class="card-title">Purchase Return Report</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Supplier</th><th>Purchase</th><th>Return</th><th>Amount</th><th>Reason</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($returns as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['supplier_name'] ?? '—') ?></td>
        <td><code><?= htmlspecialchars($r['purchase_number'] ?? '') ?></code></td>
        <td><code><?= htmlspecialchars($r['return_number']) ?></code></td>
        <td><?= currency((float)$r['total_amount']) ?></td>
        <td><?= htmlspecialchars(purchaseReturnReasons()[$r['reason']] ?? $r['reason']) ?></td>
        <td><?= htmlspecialchars($r['return_date']) ?></td>
      </tr>
    <?php endforeach; if (!$returns): ?>
      <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-300);">No returns in this range</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
