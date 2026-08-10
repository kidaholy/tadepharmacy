<?php
require_once __DIR__ . '/layout.php';

$pdo    = getDB();
$search = trim($_GET['q'] ?? '');
$from   = $_GET['from'] ?? '';
$to     = $_GET['to']   ?? '';
$method = $_GET['method'] ?? '';

$sql    = "SELECT s.*, COUNT(si.id) as items FROM sales s LEFT JOIN sale_items si ON si.sale_id = s.id";
$params = [];
$where  = [];

if ($search)  { $where[] = "(s.invoice_number LIKE ? OR s.customer_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($from)    { $where[] = "date(s.created_at) >= ?"; $params[] = $from; }
if ($to)      { $where[] = "date(s.created_at) <= ?"; $params[] = $to; }
if ($method)  { $where[] = "s.payment_method = ?"; $params[] = $method; }

if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' GROUP BY s.id ORDER BY s.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$totalRevenue = array_sum(array_map(fn($s) => $s['total_amount'] - $s['discount'], $sales));

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete') {
    $did = (int)($_POST['id'] ?? 0);
    // Restore stock
    $sitems = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id=?");
    $sitems->execute([$did]);
    foreach ($sitems->fetchAll() as $si) {
        $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id=?")->execute([$si['quantity'], $si['batch_id']]);
    }
    $pdo->prepare("DELETE FROM sales WHERE id=?")->execute([$did]);
    header('Location: sales.php');
    exit;
}

renderHead('Sales History');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Sales', 'All transactions'); ?>
<div class="page-body">

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
        <?php foreach (['cash','telebirr','cbe','card','mpesa'] as $m): ?>
        <option value="<?= $m ?>" <?= $method === $m ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-bottom:1px;">Filter</button>
    <a href="sales.php" class="btn btn-ghost" style="margin-bottom:1px;">Clear</a>
  </form>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Sales (<?= count($sales) ?>) — Total: <?= currency($totalRevenue) ?></span>
    <a href="pos.php" class="btn btn-primary"><i data-lucide="plus"></i> New Sale</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Items</th><th>Total</th><th>Discount</th><th>Net</th><th>Payment</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (empty($sales)): ?>
        <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-300);">No sales found</td></tr>
      <?php else: ?>
      <?php foreach ($sales as $s): ?>
      <tr>
        <td><span class="badge badge-blue"><?= htmlspecialchars($s['invoice_number']) ?></span></td>
        <td style="font-size:12px;"><?= date('M d, Y H:i', strtotime($s['created_at'])) ?></td>
        <td><?= htmlspecialchars($s['customer_name']) ?></td>
        <td style="text-align:center;"><?= $s['items'] ?></td>
        <td><?= currency($s['total_amount']) ?></td>
        <td style="color:var(--warning);"><?= $s['discount'] > 0 ? currency($s['discount']) : '—' ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency($s['total_amount'] - $s['discount']) ?></td>
        <td><span class="badge badge-gray"><?= ucfirst(htmlspecialchars($s['payment_method'])) ?></span></td>
        <td>
          <div style="display:flex;gap:6px;">
            <a href="receipt.php?id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="receipt"></i></a>
            <form method="POST" onsubmit="return confirmDelete(this)">
              <input type="hidden" name="act" value="delete">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm"><i data-lucide="trash-2"></i></button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div></div>
<?php renderFooter(); ?>
