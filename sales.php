<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';

$pdo    = getDB();
$search = trim($_GET['q'] ?? '');
$from   = $_GET['from'] ?? '';
$to     = $_GET['to']   ?? '';
$method = $_GET['method'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
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
if ($search)  { $where[] = "(s.invoice_number LIKE ? OR s.customer_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($from)    { $where[] = "date(s.created_at) >= ?"; $params[] = $from; }
if ($to)      { $where[] = "date(s.created_at) <= ?"; $params[] = $to; }
if ($method)  { $where[] = "s.payment_method = ?"; $params[] = $method; }
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM sales s" . $whereSql);
$countStmt->execute($params);
$totalSales = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalSales / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sumStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount - discount),0) FROM sales s" . $whereSql);
$sumStmt->execute($params);
$totalRevenue = (float)$sumStmt->fetchColumn();

$sql = "SELECT s.*,
        (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) as items
        FROM sales s
        $whereSql
        ORDER BY s.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$pagerBase = 'sales.php?' . http_build_query(array_filter([
    'q' => $search ?: null,
    'from' => $from ?: null,
    'to' => $to ?: null,
    'method' => $method ?: null,
]));

$flash = flashGet();
$msg = ($flash && $flash['type'] === 'success') ? $flash['message'] : '';

renderHead('Sales History');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Sales', 'All transactions'); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card mb-20">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <div class="form-group" style="margin:0;flex:1;min-width:180px;">
      <label>Search</label>
      <div class="search-bar"><i data-lucide="search"></i><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Invoice or customer..."></div>
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
      <label>Payment</label>
      <select name="method">
        <option value="">All</option>
        <?php foreach (array_merge(posPaymentMethods(), ['card' => 'Card', 'mpesa' => 'M-Pesa']) as $mk => $ml): ?>
        <option value="<?= $mk ?>" <?= $method === $mk ? 'selected' : '' ?>><?= htmlspecialchars($ml) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-bottom:1px;">Filter</button>
    <a href="sales.php" class="btn btn-ghost" style="margin-bottom:1px;">Clear</a>
  </form>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Sales (<?= number_format($totalSales) ?>) — Total: <?= currency($totalRevenue) ?></span>
    <a href="pos.php" class="btn btn-primary"><i data-lucide="plus"></i> New Sale</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Items</th><th>Net</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (empty($sales)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-300);">No sales found</td></tr>
      <?php else: ?>
      <?php foreach ($sales as $s):
        $net = saleNetAmount($s);
        $status = $s['payment_status'] ?? computePaymentStatus($net, (float)$s['paid_amount'], $s['payment_method']);
        $payLbl = posPaymentMethods()[$s['payment_method']] ?? ucfirst($s['payment_method']);
        $overdue = isSaleOverdue($s);
      ?>
      <tr style="<?= $overdue ? 'background:rgba(242,95,92,0.06);' : '' ?>">
        <td><span class="badge badge-blue"><?= htmlspecialchars($s['invoice_number']) ?></span></td>
        <td style="font-size:12px;"><?= date('M d, Y H:i', strtotime($s['created_at'])) ?></td>
        <td>
          <?php if ($s['customer_id']): ?>
          <a href="customers.php?id=<?= $s['customer_id'] ?>" style="color:inherit;font-weight:600;"><?= htmlspecialchars($s['customer_name']) ?></a>
          <?php else: ?>
          <?= htmlspecialchars($s['customer_name']) ?>
          <?php endif; ?>
        </td>
        <td style="text-align:center;"><?= $s['items'] ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency($net) ?></td>
        <td><span class="badge badge-gray"><?= htmlspecialchars($payLbl) ?></span></td>
        <td><span class="badge <?= paymentStatusBadge($status) ?>"><?= paymentStatusLabel($status) ?><?= $overdue ? ' · Overdue' : '' ?></span></td>
        <td>
          <div class="row-actions">
            <a href="receipt.php?id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm">Receipt</a>
            <form method="POST" onsubmit="return confirmDelete(this)">
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
