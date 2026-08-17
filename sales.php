<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';

$pdo    = getDB();
$q      = trim($_GET['q'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$method = $_GET['method'] ?? '';
$status = $_GET['status'] ?? '';
// Default view: the current business day.
if ($from === '' && $to === '') {
    $from = businessToday();
    $to   = businessToday();
}
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

// Handle delete first (PRG)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete') {
    $did = (int)($_POST['id'] ?? 0);
    $sitems = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id=?");
    $sitems->execute([$did]);
    foreach ($sitems->fetchAll() as $si) {
        $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id=?")->execute([$si['quantity'], $si['batch_id']]);
    }
    $pdo->prepare("DELETE FROM sales WHERE id=?")->execute([$did]);
    flashSet('success', 'Sale deleted and stock restored.');
    header('Location: sales.php');
    exit;
}

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = "(s.customer_name LIKE ? OR s.customer_phone LIKE ? OR s.invoice_number LIKE ?
        OR s.id IN (
            SELECT si.sale_id FROM sale_items si
            JOIN medicines m ON m.id = si.medicine_id
            LEFT JOIN batches b ON b.id = si.batch_id
            WHERE m.name LIKE ? OR m.generic_name LIKE ? OR b.batch_number LIKE ?
        ))";
    for ($i = 0; $i < 6; $i++) $params[] = "%$q%";
}
if ($from) { $where[] = "date(s.created_at, '+3 hours') >= ?"; $params[] = $from; }
if ($to)   { $where[] = "date(s.created_at, '+3 hours') <= ?"; $params[] = $to; }
if ($method) { $where[] = "s.payment_method = ?"; $params[] = $method; }
if ($status === 'paid')        $where[] = "s.payment_status = 'paid'";
elseif ($status === 'unpaid')  $where[] = "s.payment_status IN ('unpaid','partial')";
elseif ($status === 'credit')  $where[] = "(s.sale_type = 'credit' OR s.payment_method = 'credit')";
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$sumStmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt,
           COALESCE(SUM(s.total_amount - s.discount + COALESCE(s.tax,0)), 0) AS gross,
           COALESCE(SUM(s.paid_amount), 0) AS collected,
           COALESCE(SUM(CASE WHEN (s.sale_type = 'credit' OR s.payment_method = 'credit') THEN s.remaining_balance ELSE 0 END), 0) AS credit_outstanding
    FROM sales s $whereSql
");
$sumStmt->execute($params);
$sum = $sumStmt->fetch();

$methodStmt = $pdo->prepare("
    SELECT s.payment_method,
           COUNT(*) AS cnt,
           COALESCE(SUM(s.total_amount - s.discount + COALESCE(s.tax,0)), 0) AS gross,
           COALESCE(SUM(s.paid_amount), 0) AS collected
    FROM sales s $whereSql
    GROUP BY s.payment_method
");
$methodStmt->execute($params);
$methodTotals = [];
foreach ($methodStmt->fetchAll() as $row) {
    $methodTotals[$row['payment_method']] = [
        'cnt'       => (int)$row['cnt'],
        'gross'     => (float)$row['gross'],
        'collected' => (float)$row['collected'],
    ];
}

$totalSales = (int)$sum['cnt'];
$totalPages = max(1, (int)ceil($totalSales / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT s.* FROM sales s $whereSql ORDER BY s.created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$itemsBySale = [];
if ($sales) {
    $ids = array_map('intval', array_column($sales, 'id'));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $itStmt = $pdo->prepare("
        SELECT si.sale_id, si.quantity, m.name, m.strength
        FROM sale_items si
        JOIN medicines m ON m.id = si.medicine_id
        WHERE si.sale_id IN ($ph)
        ORDER BY si.id
    ");
    $itStmt->execute($ids);
    foreach ($itStmt->fetchAll() as $row) {
        $itemsBySale[(int)$row['sale_id']][] = $row;
    }
}

$pagerBase = 'sales.php?' . http_build_query(array_filter([
    'q' => $q ?: null,
    'from' => $from ?: null,
    'to' => $to ?: null,
    'method' => $method ?: null,
    'status' => $status ?: null,
]));

$flash = flashGet();
$msg = ($flash && $flash['type'] === 'success') ? $flash['message'] : '';

$methods = posPaymentMethods();
$totalCollected = (float)$sum['collected'];
$totalGross     = (float)$sum['gross'];
$creditOutstanding = (float)$sum['credit_outstanding'];

renderHead('Sales / Daily Sales Record');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Sales / Daily Sales Record', 'Daily sales, payment breakdown & credit tracking'); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Filter Daily Sales</span></div>
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:0 16px 16px;">
    <div class="form-group" style="margin:0;flex:1;min-width:200px;">
      <label>Search</label>
      <div class="search-bar"><i data-lucide="search"></i><input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Customer, medicine, batch..."></div>
    </div>
    <div class="form-group" style="margin:0;min-width:140px;">
      <label>From Date</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    </div>
    <div class="form-group" style="margin:0;min-width:140px;">
      <label>To Date</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    </div>
    <div class="form-group" style="margin:0;min-width:130px;">
      <label>Payment Method</label>
      <select name="method">
        <option value="">All</option>
        <?php foreach ($methods as $mk => $ml): ?>
        <option value="<?= $mk ?>" <?= $method === $mk ? 'selected' : '' ?>><?= htmlspecialchars($ml) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:130px;">
      <label>Status</label>
      <select name="status">
        <option value="">All</option>
        <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
        <option value="unpaid" <?= $status === 'unpaid' ? 'selected' : '' ?>>Unpaid / Partial</option>
        <option value="credit" <?= $status === 'credit' ? 'selected' : '' ?>>Credit</option>
      </select>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-bottom:1px;">Filter</button>
    <a href="sales.php" class="btn btn-ghost" style="margin-bottom:1px;">Clear</a>
  </form>
</div>

<div class="stats-grid mb-20">
  <div class="stat-card blue">
    <div class="stat-icon blue"><i data-lucide="shopping-cart"></i></div>
    <div>
      <div class="stat-label">TOTAL SALES</div>
      <div class="stat-value"><?= currency($totalGross) ?></div>
      <div class="stat-sub"><?= number_format($totalSales) ?> transaction<?= $totalSales !== 1 ? 's' : '' ?></div>
    </div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon green"><i data-lucide="banknote"></i></div>
    <div>
      <div class="stat-label">CASH</div>
      <div class="stat-value"><?= currency($methodTotals['cash']['gross'] ?? 0) ?></div>
      <div class="stat-sub"><?= (int)($methodTotals['cash']['cnt'] ?? 0) ?> transaction<?= ($methodTotals['cash']['cnt'] ?? 0) !== 1 ? 's' : '' ?></div>
    </div>
  </div>
  <div class="stat-card blue">
    <div class="stat-icon blue"><i data-lucide="landmark"></i></div>
    <div>
      <div class="stat-label">CBE</div>
      <div class="stat-value"><?= currency($methodTotals['cbe']['gross'] ?? 0) ?></div>
      <div class="stat-sub"><?= (int)($methodTotals['cbe']['cnt'] ?? 0) ?> transaction<?= ($methodTotals['cbe']['cnt'] ?? 0) !== 1 ? 's' : '' ?></div>
    </div>
  </div>
  <div class="stat-card orange">
    <div class="stat-icon orange"><i data-lucide="building-2"></i></div>
    <div>
      <div class="stat-label">ABYSSINIA</div>
      <div class="stat-value"><?= currency($methodTotals['abyssinia']['gross'] ?? 0) ?></div>
      <div class="stat-sub"><?= (int)($methodTotals['abyssinia']['cnt'] ?? 0) ?> transaction<?= ($methodTotals['abyssinia']['cnt'] ?? 0) !== 1 ? 's' : '' ?></div>
    </div>
  </div>
  <div class="stat-card blue">
    <div class="stat-icon blue"><i data-lucide="smartphone"></i></div>
    <div>
      <div class="stat-label">TELEBIRR</div>
      <div class="stat-value"><?= currency($methodTotals['telebirr']['gross'] ?? 0) ?></div>
      <div class="stat-sub"><?= (int)($methodTotals['telebirr']['cnt'] ?? 0) ?> transaction<?= ($methodTotals['telebirr']['cnt'] ?? 0) !== 1 ? 's' : '' ?></div>
    </div>
  </div>
  <div class="stat-card red">
    <div class="stat-icon red"><i data-lucide="credit-card"></i></div>
    <div>
      <div class="stat-label">CREDIT</div>
      <div class="stat-value"><?= currency($methodTotals['credit']['gross'] ?? 0) ?></div>
      <div class="stat-sub"><?= currency($methodTotals['credit']['collected'] ?? 0) ?> collected · <?= currency($creditOutstanding) ?> outstanding</div>
    </div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Payment Summary</span></div>
  <div class="report-summary-grid">
    <div><span class="report-k">Cash</span><span class="report-v"><?= currency($methodTotals['cash']['gross'] ?? 0) ?></span></div>
    <div><span class="report-k">CBE</span><span class="report-v"><?= currency($methodTotals['cbe']['gross'] ?? 0) ?></span></div>
    <div><span class="report-k">Abyssinia</span><span class="report-v"><?= currency($methodTotals['abyssinia']['gross'] ?? 0) ?></span></div>
    <div><span class="report-k">Telebirr</span><span class="report-v"><?= currency($methodTotals['telebirr']['gross'] ?? 0) ?></span></div>
    <div><span class="report-k">Credit (sales value)</span><span class="report-v" style="color:var(--danger);"><?= currency($methodTotals['credit']['gross'] ?? 0) ?></span></div>
    <div><span class="report-k">Total Sales</span><span class="report-v"><?= currency($totalGross) ?></span></div>
    <div><span class="report-k">Total Collected</span><span class="report-v" style="color:var(--accent2);"><?= currency($totalCollected) ?></span></div>
    <div><span class="report-k">Credit Outstanding</span><span class="report-v" style="color:var(--warning);"><?= currency($creditOutstanding) ?></span></div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Daily Sales Record (<?= number_format($totalSales) ?>)</span>
    <a href="pos.php" class="btn btn-primary"><i data-lucide="plus"></i> New Sale</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Customer</th><th>Item List</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (empty($sales)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-300);">No sales found for this period</td></tr>
      <?php else: ?>
      <?php foreach ($sales as $s):
        $net = saleNetAmount($s);
        $status = $s['payment_status'] ?? computePaymentStatus($net, (float)$s['paid_amount'], $s['payment_method']);
        $payLbl = $methods[$s['payment_method']] ?? ucfirst($s['payment_method']);
        $overdue = isSaleOverdue($s);
        $lineLabels = [];
        foreach ($itemsBySale[(int)$s['id']] ?? [] as $it) {
            $lineLabels[] = htmlspecialchars(trim($it['name'] . ($it['strength'] ? ' ' . $it['strength'] : ''))) . ' × ' . (int)$it['quantity'];
        }
        $shown = array_slice($lineLabels, 0, 2);
        $more = count($lineLabels) - 2;
      ?>
      <tr style="<?= $overdue ? 'background:rgba(242,95,92,0.06);' : '' ?>">
        <td style="font-size:12px;white-space:nowrap;"><?= date('M d, Y', strtotime($s['created_at'])) ?><br><small style="color:var(--text-300);"><?= date('H:i', strtotime($s['created_at'])) ?></small></td>
        <td>
          <?php if ($s['customer_id']): ?>
          <a href="customers.php?id=<?= $s['customer_id'] ?>" style="color:inherit;font-weight:600;"><?= htmlspecialchars($s['customer_name']) ?></a>
          <?php else: ?>
          <?= htmlspecialchars($s['customer_name']) ?>
          <?php endif; ?>
        </td>
        <td style="font-size:12px;line-height:1.6;">
          <?php foreach ($shown as $i => $lbl): ?>
          <div><?= $lbl ?></div>
          <?php endforeach; ?>
          <?php if ($more > 0): ?>
          <a href="sale_details.php?id=<?= $s['id'] ?>" style="font-weight:600;color:var(--accent);">+ <?= $more ?> more</a>
          <?php endif; ?>
        </td>
        <td style="text-align:center;"><?= count($lineLabels) ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency($net) ?></td>
        <td><span class="badge badge-gray"><?= htmlspecialchars($payLbl) ?></span></td>
        <td><span class="badge <?= paymentStatusBadge($status) ?>"><?= paymentStatusLabel($status) ?><?= $overdue ? ' · Overdue' : '' ?></span></td>
        <td>
          <div class="row-actions">
            <a href="sale_details.php?id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm">Details</a>
            <a href="receipt.php?id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm">Receipt</a>
            <form method="POST" onsubmit="return confirm('Delete this sale and restore stock?')">
              <input type="hidden" name="act" value="delete">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Del</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php renderPagination($page, $totalPages, $pagerBase); ?>
</div>

</div></div>
<?php renderFooter(); ?>
