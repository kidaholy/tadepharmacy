<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/customers_lib.php';
require_once __DIR__ . '/notifications_lib.php';

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'register') {
        try {
            $cust = registerCustomer($pdo, $_POST['full_name'] ?? '', $_POST['phone'] ?? '', (float)($_POST['credit_limit'] ?? 0));
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'customer' => $cust]);
                exit;
            }
            flashSet('success', 'Customer registered successfully.');
            header('Location: customers.php?id=' . $cust['id']);
            exit;
        } catch (Exception $e) {
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $error = $e->getMessage();
            $action = 'add';
        }
    }

    if ($act === 'receive_payment') {
        $saleId = (int)($_POST['sale_id'] ?? 0);
        try {
            receiveCreditPayment(
                $pdo,
                $saleId,
                (float)($_POST['amount'] ?? 0),
                $_POST['payment_method'] ?? 'cash',
                trim($_POST['reference_number'] ?? '') ?: null,
                currentUser()['id'] ?? 0,
                trim($_POST['notes'] ?? '') ?: null
            );
            flashSet('success', 'Payment recorded successfully.');
            $cid = (int)($_POST['customer_id'] ?? 0);
            header('Location: customers.php?id=' . $cid);
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
            $id = (int)($_POST['customer_id'] ?? 0);
            $action = 'view';
        }
    }
}

$flash = flashGet();
if ($flash && $flash['type'] === 'success') $msg = $flash['message'];

$customer = null;
$creditSales = [];
$payments = [];

if ($id) {
    $customer = findCustomerById($pdo, $id);
    if (!$customer) {
        header('Location: customers.php');
        exit;
    }
    $creditSales = getCustomerCreditSales($pdo, $id);
    $payments = getCustomerPayments($pdo, $id);
    $action = 'view';
}

if ($action === 'list') {
    $all = searchCustomers($pdo, $search, 500);
    $totalPages = max(1, (int)ceil(count($all) / $perPage));
    $customers = array_slice($all, ($page - 1) * $perPage, $perPage);
}

renderHead('Customers');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Customers', $action === 'view' ? 'Customer profile & credit' : 'Registered customers'); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($action === 'view' && $customer): ?>

<div class="mb-20" style="display:flex;gap:10px;flex-wrap:wrap;">
  <a href="customers.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> All Customers</a>
  <a href="pos.php?customer_id=<?= $customer['id'] ?>" class="btn btn-primary btn-sm"><i data-lucide="scan-barcode"></i> New Sale</a>
</div>

<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><?= htmlspecialchars($customer['full_name']) ?></span>
    <span class="badge badge-blue"><?= htmlspecialchars($customer['phone']) ?></span>
  </div>
  <div class="report-summary-grid">
    <div><span class="report-k">Outstanding Balance</span><span class="report-v" style="color:var(--warning);"><?= currency((float)$customer['outstanding_balance']) ?></span></div>
    <div><span class="report-k">Total Credit Used</span><span class="report-v"><?= currency((float)$customer['total_credit']) ?></span></div>
    <div><span class="report-k">Total Paid</span><span class="report-v" style="color:var(--accent2);"><?= currency((float)$customer['total_paid']) ?></span></div>
    <div><span class="report-k">Overdue Amount</span><span class="report-v" style="color:var(--danger);"><?= currency((float)$customer['overdue_amount']) ?></span></div>
    <div><span class="report-k">Last Credit Sale</span><span class="report-v"><?= $customer['last_credit_sale'] ? date('M j, Y', strtotime($customer['last_credit_sale'])) : '—' ?></span></div>
    <div><span class="report-k">Next Due Date</span><span class="report-v"><?= $customer['next_due_date'] ? date('M j, Y', strtotime($customer['next_due_date'])) : '—' ?></span></div>
    <div><span class="report-k">Credit Limit</span><span class="report-v"><?= currency((float)$customer['credit_limit']) ?></span></div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Credit Invoices</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Invoice</th><th>Sale Date</th><th>Due Date</th><th>Total</th>
          <th>Paid</th><th>Balance</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($creditSales)): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-300);">No credit invoices</td></tr>
      <?php else: foreach ($creditSales as $s):
        $net = saleNetAmount($s);
        $remaining = (float)$s['remaining_balance'];
        $overdue = isSaleOverdue($s);
        $status = $s['payment_status'] ?? computePaymentStatus($net, (float)$s['paid_amount'], $s['payment_method']);
        $rowStyle = $overdue ? 'background:rgba(242,95,92,0.08);' : '';
      ?>
      <tr style="<?= $rowStyle ?>">
        <td><code><?= htmlspecialchars($s['invoice_number']) ?></code></td>
        <td><?= date('M j, Y', strtotime($s['created_at'])) ?></td>
        <td style="<?= $overdue ? 'color:var(--danger);font-weight:700;' : '' ?>">
          <?= ($s['due_on'] ?? null) ? date('M j, Y', strtotime($s['due_on'])) : '—' ?>
          <?= $overdue ? ' <span class="badge badge-red">Overdue</span>' : '' ?>
        </td>
        <td><?= currency($net) ?></td>
        <td><?= currency((float)$s['paid_amount']) ?></td>
        <td style="font-weight:700;color:var(--warning);"><?= currency($remaining) ?></td>
        <td><span class="badge <?= paymentStatusBadge($status) ?>"><?= paymentStatusLabel($status) ?></span></td>
        <td>
          <div class="row-actions">
            <a href="receipt.php?id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm">View</a>
            <?php if ($remaining > 0.009): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openPayModal(<?= htmlspecialchars(json_encode([
              'sale_id' => $s['id'],
              'invoice' => $s['invoice_number'],
              'balance' => $remaining,
            ])) ?>)">Receive Payment</button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Payment History</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Invoice</th><th>Amount</th><th>Method</th><th>Reference</th><th>Received By</th></tr></thead>
      <tbody>
      <?php if (empty($payments)): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-300);">No payments recorded</td></tr>
      <?php else: foreach ($payments as $p): ?>
      <tr>
        <td><?= date('M j, Y H:i', strtotime($p['payment_date'])) ?></td>
        <td><code><?= htmlspecialchars($p['invoice_number']) ?></code></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency((float)$p['amount']) ?></td>
        <td><?= htmlspecialchars(posPaymentMethods()[$p['payment_method']] ?? ucfirst($p['payment_method'])) ?></td>
        <td><?= htmlspecialchars($p['reference_number'] ?? '—') ?></td>
        <td><?= htmlspecialchars($p['received_by_name'] ?? '—') ?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Receive Payment Modal -->
<div class="modal-overlay" id="payModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <h2>Receive Payment</h2>
      <button class="modal-close" onclick="closeModal('payModal')"><i data-lucide="x"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="act" value="receive_payment">
        <input type="hidden" name="customer_id" value="<?= $customer['id'] ?>">
        <input type="hidden" name="sale_id" id="paySaleId">
        <p style="font-size:13px;color:var(--text-300);margin-bottom:14px;">Invoice: <strong id="payInvoice" style="color:var(--text-100);"></strong></p>
        <p style="font-size:13px;margin-bottom:14px;">Remaining: <strong id="payBalance" style="color:var(--warning);"></strong></p>
        <div class="form-group">
          <label>Amount Paid</label>
          <input type="number" name="amount" id="payAmount" step="0.01" min="0.01" required>
        </div>
        <div class="form-group">
          <label>Payment Method</label>
          <select name="payment_method">
            <?php foreach (posPaymentMethods() as $k => $lbl): if ($k === 'credit') continue; ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Reference Number <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
          <input type="text" name="reference_number" placeholder="Transaction ref...">
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" placeholder="Optional notes..."></textarea>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Record Payment</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('payModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openPayModal(data) {
  document.getElementById('paySaleId').value = data.sale_id;
  document.getElementById('payInvoice').textContent = data.invoice;
  document.getElementById('payBalance').textContent = '<?= getSetting('currency','ETB') ?> ' + Number(data.balance).toFixed(2);
  document.getElementById('payAmount').value = Number(data.balance).toFixed(2);
  document.getElementById('payAmount').max = data.balance;
  openModal('payModal');
}
</script>

<?php elseif ($action === 'add'): ?>

<div class="card" style="max-width:520px;">
  <div class="card-header"><span class="card-title">Register Customer</span></div>
  <form method="POST">
    <input type="hidden" name="act" value="register">
    <div class="form-group">
      <label>Full Name</label>
      <input type="text" name="full_name" required placeholder="Customer full name">
    </div>
    <div class="form-group">
      <label>Phone Number</label>
      <input type="tel" name="phone" required placeholder="e.g. 0912345678">
    </div>
    <div class="form-group">
      <label>Credit Limit <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
      <input type="number" name="credit_limit" min="0" step="0.01" value="0">
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><i data-lucide="user-plus"></i> Register</button>
      <a href="customers.php" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>

<?php else: ?>

<div class="card mb-20">
  <div class="card-header">
    <span class="card-title">Customer List</span>
    <a href="customers.php?action=add" class="btn btn-primary btn-sm"><i data-lucide="user-plus"></i> Register Customer</a>
  </div>
  <form method="GET" style="padding:0 20px 16px;display:flex;gap:10px;">
    <div class="search-bar" style="flex:1;"><i data-lucide="search"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or phone...">
    </div>
    <button type="submit" class="btn btn-ghost">Search</button>
  </form>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Name</th><th>Phone</th><th>Outstanding</th><th>Overdue</th><th>Next Due</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($customers)): ?>
        <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-300);">No registered customers yet</td></tr>
      <?php else: foreach ($customers as $c): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($c['full_name']) ?></td>
        <td><?= htmlspecialchars($c['phone']) ?></td>
        <td style="color:var(--warning);font-weight:700;"><?= currency((float)$c['outstanding_balance']) ?></td>
        <td style="color:var(--danger);"><?= currency((float)$c['overdue_amount']) ?></td>
        <td><?= $c['next_due_date'] ? date('M j, Y', strtotime($c['next_due_date'])) : '—' ?></td>
        <td><a href="customers.php?id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">View</a></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php renderPagination($page, $totalPages, 'customers.php?' . http_build_query(array_filter(['q' => $search ?: null]))); ?>
</div>

<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
