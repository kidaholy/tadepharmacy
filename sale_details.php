<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';
require_once __DIR__ . '/customers_lib.php';
require_once __DIR__ . '/inventory_lib.php';

$pdo = getDB();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: sales.php'); exit; }

$msg = '';
$error = '';
$userId = (int)(currentUser()['id'] ?? 0);

// ── POST handlers (before sale fetch) — PRG ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'void_sale') {
        if (!can('sales.void')) {
            flashSet('error', 'You do not have permission to void sales.');
        } else {
            $result = voidSale($pdo, $id, trim($_POST['reason'] ?? ''), $userId);
            flashSet($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
        }
        header('Location: sale_details.php?id=' . $id);
        exit;
    }

    if ($act === 'correct_item') {
        if (!can('sales.edit')) {
            flashSet('error', 'You do not have permission to correct sales.');
        } else {
            $result = correctSaleItemQty(
                $pdo,
                (int)($_POST['sale_item_id'] ?? 0),
                (int)($_POST['new_qty'] ?? -1),
                trim($_POST['reason'] ?? ''),
                $userId
            );
            flashSet($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
        }
        header('Location: sale_details.php?id=' . $id);
        exit;
    }

    if ($act === 'return_item') {
        if (!can('sales.returns')) {
            flashSet('error', 'You do not have permission to process returns.');
        } else {
            $result = processSaleReturn(
                $pdo,
                $id,
                (int)($_POST['sale_item_id'] ?? 0),
                (int)($_POST['qty'] ?? 0),
                trim($_POST['reason_code'] ?? ''),
                !empty($_POST['resalable']),
                trim($_POST['notes'] ?? ''),
                $userId
            );
            flashSet($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
        }
        header('Location: sale_details.php?id=' . $id);
        exit;
    }

    if ($act === 'record_payment') {
        if (!can('sales.manage')) {
            $error = 'You do not have permission to record payments.';
        } else {
            // Block payments on voided sales (checked after we could load status; soft-check here via DB)
            $chk = $pdo->prepare("SELECT status, payment_status FROM sales WHERE id = ?");
            $chk->execute([$id]);
            $row = $chk->fetch();
            if ($row && (($row['status'] ?? '') === 'voided' || ($row['payment_status'] ?? '') === 'voided')) {
                flashSet('error', 'Cannot record payment on a voided sale.');
                header('Location: sale_details.php?id=' . $id);
                exit;
            }
            try {
                receiveCreditPayment(
                    $pdo,
                    $id,
                    (float)($_POST['amount'] ?? 0),
                    $_POST['payment_method'] ?? 'cash',
                    trim($_POST['reference'] ?? ''),
                    $userId,
                    trim($_POST['notes'] ?? '')
                );
                flashSet('success', 'Payment recorded.');
                header('Location: sale_details.php?id=' . $id);
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$flash = flashGet();
if ($flash) {
    if ($flash['type'] === 'success') $msg = $flash['message'];
    elseif ($flash['type'] === 'error') $error = $flash['message'];
}

$s = $pdo->prepare("
    SELECT s.*, u.full_name AS cashier_name, vu.full_name AS voided_by_name
    FROM sales s
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN users vu ON vu.id = s.voided_by
    WHERE s.id = ?
");
$s->execute([$id]);
$sale = $s->fetch();
if (!$sale) { header('Location: sales.php'); exit; }

$isVoided = (($sale['status'] ?? 'active') === 'voided') || (($sale['payment_status'] ?? '') === 'voided');
$actualAt = $sale['sale_at'] ?? $sale['created_at'];
$enteredAt = $sale['entered_at'] ?? $sale['created_at'];

$items = fetchSaleItemsDetailed($pdo, $id);
$returnReasons = saleReturnReasons();

$payStmt = $pdo->prepare("
    SELECT ph.*, u.full_name AS received_by_name
    FROM payment_history ph
    LEFT JOIN users u ON u.id = ph.received_by
    WHERE ph.sale_id = ?
    ORDER BY ph.payment_date ASC
");
$payStmt->execute([$id]);
$paymentHistory = $payStmt->fetchAll();

$methods = posPaymentMethods();
$currency = getSetting('currency', 'ETB');
$subtotal    = (float)$sale['total_amount'];
$discount    = (float)$sale['discount'];
$tax         = (float)($sale['tax'] ?? 0);
$discounted  = saleDiscountedSubtotal($sale);
$netTotal    = saleNetAmount($sale);
$paid        = (float)$sale['paid_amount'];
$remaining   = (float)($sale['remaining_balance'] ?? max(0, $netTotal - $paid));
$status      = $sale['payment_status'] ?? computePaymentStatus($netTotal, $paid, $sale['payment_method']);
$payLabel    = $methods[$sale['payment_method']] ?? ucfirst($sale['payment_method']);
$dueDate     = $sale['due_date'] ?? $sale['credit_due_date'] ?? null;

$canEdit    = !$isVoided && can('sales.edit');
$canReturn  = !$isVoided && can('sales.returns');
$canVoid    = !$isVoided && can('sales.void');
$canPay     = !$isVoided && $remaining > 0.009 && can('sales.manage');

renderHead('Sale Details');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Sale Details', $sale['invoice_number']); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="alert-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="mb-20" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a href="sales.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Sales / Daily Record</a>
  <a href="pos.php" class="btn btn-success btn-sm"><i data-lucide="plus"></i> New Sale</a>
  <a href="receipt.php?id=<?= $sale['id'] ?>" class="btn btn-primary btn-sm"><i data-lucide="printer"></i> Print Receipt</a>
  <?php if ($sale['customer_id']): ?>
  <a href="customers.php?id=<?= $sale['customer_id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="user"></i> Customer</a>
  <?php endif; ?>
  <?php if ($canVoid): ?>
  <button type="button" class="btn btn-danger btn-sm" onclick="openModal('voidModal')"><i data-lucide="ban"></i> Void Sale</button>
  <?php endif; ?>
</div>

<?php if ($isVoided): ?>
<div class="alert alert-danger" style="display:flex;align-items:flex-start;gap:12px;">
  <span class="badge badge-gray" style="font-size:16px;padding:6px 14px;letter-spacing:1px;">VOID</span>
  <div style="font-size:13px;line-height:1.5;">
    <div><strong>This sale has been voided.</strong></div>
    <?php if (!empty($sale['void_reason'])): ?>
    <div>Reason: <?= htmlspecialchars($sale['void_reason']) ?></div>
    <?php endif; ?>
    <div style="color:var(--text-300);margin-top:4px;">
      Voided at: <?= !empty($sale['voided_at']) ? date('M d, Y H:i', strtotime($sale['voided_at'])) : '—' ?>
      · By: <?= htmlspecialchars($sale['voided_by_name'] ?: '—') ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><?= htmlspecialchars($sale['invoice_number']) ?></span>
    <span class="badge <?= paymentStatusBadge($status) ?>"><?= paymentStatusLabel($status) ?></span>
  </div>
  <div class="report-summary-grid">
    <div><span class="report-k">Actual sale date</span><span class="report-v" style="font-size:14px;"><?= $actualAt ? date('M d, Y H:i', strtotime($actualAt)) : '—' ?></span></div>
    <div><span class="report-k">Entered at</span><span class="report-v" style="font-size:14px;"><?= $enteredAt ? date('M d, Y H:i', strtotime($enteredAt)) : '—' ?></span></div>
    <div><span class="report-k">Customer</span><span class="report-v" style="font-size:14px;"><?= htmlspecialchars($sale['customer_name']) ?></span></div>
    <div><span class="report-k">Phone</span><span class="report-v" style="font-size:14px;"><?= htmlspecialchars($sale['customer_phone'] ?: '—') ?></span></div>
    <div><span class="report-k">Cashier</span><span class="report-v" style="font-size:14px;"><?= htmlspecialchars($sale['cashier_name'] ?: '—') ?></span></div>
    <div><span class="report-k">Payment Method</span><span class="report-v" style="font-size:14px;"><?= htmlspecialchars($payLabel) ?></span></div>
    <div><span class="report-k">Due Date</span><span class="report-v" style="font-size:14px;"><?= $dueDate ? htmlspecialchars($dueDate) : '—' ?></span></div>
    <?php if ($sale['payment_reference']): ?>
    <div><span class="report-k">Reference</span><span class="report-v" style="font-size:14px;"><?= htmlspecialchars($sale['payment_reference']) ?></span></div>
    <?php endif; ?>
  </div>
  <?php if ($sale['credit_notes'] || $sale['notes']): ?>
  <p style="margin-top:12px;font-size:13px;color:var(--text-300);"><?= nl2br(htmlspecialchars(trim($sale['credit_notes'] ?: $sale['notes']))) ?></p>
  <?php endif; ?>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Medicines</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Medicine</th><th>Generic</th><th>Strength / Form</th><th>Batch</th><th>Expiry</th>
          <th>Qty</th><th>Unit Price</th><th>Discount</th><th>Tax</th><th>Line Total</th>
          <?php if ($canEdit || $canReturn): ?><th>Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $it):
        $lineTotal = (float)($it['subtotal'] ?? 0) - (float)($it['discount'] ?? 0) + (float)($it['tax'] ?? 0);
        $itemName = trim($it['name'] . ($it['strength'] ? ' ' . $it['strength'] : ''));
      ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($it['name']) ?></td>
        <td><?= htmlspecialchars($it['generic_name'] ?: '—') ?></td>
        <td><?= htmlspecialchars(trim(($it['strength'] ?: '') . ' ' . ($it['dosage_form'] ?: '')) ?: '—') ?></td>
        <td><code><?= htmlspecialchars($it['batch_number'] ?: '—') ?></code></td>
        <td><?= formatExpiryDate($it['expiry_date'] ?? null) ?></td>
        <td><?= (int)$it['quantity'] ?> <?= htmlspecialchars($it['unit'] ?: '') ?></td>
        <td><?= currency((float)$it['unit_price']) ?></td>
        <td><?= currency((float)($it['discount'] ?? 0)) ?></td>
        <td><?= currency((float)($it['tax'] ?? 0)) ?></td>
        <td style="font-weight:700;"><?= currency($lineTotal) ?></td>
        <?php if ($canEdit || $canReturn): ?>
        <td>
          <div class="row-actions">
            <?php if ($canEdit): ?>
            <button type="button" class="btn btn-ghost btn-sm"
              onclick="openCorrectModal(<?= (int)$it['id'] ?>, <?= (int)$it['quantity'] ?>, <?= htmlspecialchars(json_encode($itemName), ENT_QUOTES) ?>)">Correct</button>
            <?php endif; ?>
            <?php if ($canReturn): ?>
            <button type="button" class="btn btn-ghost btn-sm"
              onclick="openReturnModal(<?= (int)$it['id'] ?>, <?= (int)$it['quantity'] ?>, <?= htmlspecialchars(json_encode($itemName), ENT_QUOTES) ?>)">Return</button>
            <?php endif; ?>
          </div>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="report-summary-grid" style="margin-top:16px;">
    <div><span class="report-k">Subtotal</span><span class="report-v"><?= currency($subtotal) ?></span></div>
    <div><span class="report-k">Discount</span><span class="report-v"><?= currency($discount) ?></span></div>
    <div><span class="report-k">Discounted Subtotal</span><span class="report-v"><?= currency($discounted) ?></span></div>
    <div><span class="report-k">Tax</span><span class="report-v"><?= currency($tax) ?></span></div>
    <div><span class="report-k">TOTAL</span><span class="report-v" style="color:var(--accent2);"><?= currency($netTotal) ?></span></div>
    <div><span class="report-k">Paid</span><span class="report-v"><?= currency($paid) ?></span></div>
    <div><span class="report-k">Balance Due</span><span class="report-v" style="color:var(--warning);"><?= currency($remaining) ?></span></div>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Payment History</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Received By</th><th>Notes</th></tr></thead>
      <tbody>
      <?php if (!$paymentHistory): ?>
        <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-300);">No payments yet</td></tr>
      <?php else: foreach ($paymentHistory as $p): ?>
      <tr>
        <td><?= date('M j, Y H:i', strtotime($p['payment_date'])) ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency((float)$p['amount']) ?></td>
        <td><?= htmlspecialchars($methods[$p['payment_method']] ?? $p['payment_method']) ?></td>
        <td><?= htmlspecialchars($p['reference_number'] ?: '—') ?></td>
        <td><?= htmlspecialchars($p['received_by_name'] ?: '—') ?></td>
        <td><?= htmlspecialchars($p['notes'] ?? '') ?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canPay): ?>
<div class="card">
  <div class="card-header"><span class="card-title">Record Payment</span></div>
  <form method="POST" style="padding:0 16px 16px;">
    <input type="hidden" name="act" value="record_payment">
    <p style="font-size:13px;color:var(--text-300);margin-bottom:12px;">
      Invoice <?= htmlspecialchars($sale['invoice_number']) ?> · Total <?= currency($netTotal) ?> · Paid <?= currency($paid) ?> ·
      Remaining <strong style="color:var(--warning);"><?= currency($remaining) ?></strong>
    </p>
    <div class="form-row">
      <div class="form-group" style="min-width:120px;">
        <label>Amount</label>
        <input type="number" name="amount" min="0.01" step="0.01" max="<?= $remaining ?>" value="<?= number_format($remaining, 2, '.', '') ?>" required>
      </div>
      <div class="form-group" style="min-width:150px;">
        <label>Method</label>
        <select name="payment_method">
          <?php foreach ($methods as $k => $lbl): if ($k === 'credit') continue; ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Reference</label>
        <input type="text" name="reference" placeholder="Optional">
      </div>
      <div class="form-group" style="flex:2;">
        <label>Notes</label>
        <input type="text" name="notes" placeholder="Optional">
      </div>
    </div>
    <button type="submit" class="btn btn-primary"><i data-lucide="wallet"></i> Record Payment</button>
  </form>
</div>
<?php endif; ?>

</div></div>

<?php if ($canVoid): ?>
<div class="modal-overlay" id="voidModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <h2>Void Sale</h2>
      <button type="button" class="modal-close" onclick="closeModal('voidModal')"><i data-lucide="x"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:13px;color:var(--text-300);margin-bottom:12px;">
        Voiding <?= htmlspecialchars($sale['invoice_number']) ?> restores stock and marks the sale VOID. This cannot be undone from the UI.
      </p>
      <form method="POST">
        <input type="hidden" name="act" value="void_sale">
        <div class="form-group">
          <label>Reason <span style="color:var(--danger);">*</span></label>
          <textarea name="reason" rows="3" required placeholder="Why is this sale being voided?"></textarea>
        </div>
        <div class="form-actions" style="padding-top:12px;margin-top:12px;">
          <button type="submit" class="btn btn-danger">Void Sale</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('voidModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="modal-overlay" id="correctModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <h2>Correct Quantity</h2>
      <button type="button" class="modal-close" onclick="closeModal('correctModal')"><i data-lucide="x"></i></button>
    </div>
    <div class="modal-body">
      <p id="correctLabel" style="font-size:13px;color:var(--text-300);margin-bottom:12px;"></p>
      <form method="POST">
        <input type="hidden" name="act" value="correct_item">
        <input type="hidden" name="sale_item_id" id="correctItemId">
        <div class="form-group">
          <label>New quantity</label>
          <input type="number" name="new_qty" id="correctQty" min="0" step="1" required>
        </div>
        <div class="form-group">
          <label>Reason <span style="color:var(--danger);">*</span></label>
          <textarea name="reason" rows="2" required placeholder="Why is the quantity changing?"></textarea>
        </div>
        <div class="form-actions" style="padding-top:12px;margin-top:12px;">
          <button type="submit" class="btn btn-primary">Save Correction</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('correctModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canReturn): ?>
<div class="modal-overlay" id="returnModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <h2>Return Item</h2>
      <button type="button" class="modal-close" onclick="closeModal('returnModal')"><i data-lucide="x"></i></button>
    </div>
    <div class="modal-body">
      <p id="returnLabel" style="font-size:13px;color:var(--text-300);margin-bottom:12px;"></p>
      <form method="POST">
        <input type="hidden" name="act" value="return_item">
        <input type="hidden" name="sale_item_id" id="returnItemId">
        <div class="form-group">
          <label>Quantity to return</label>
          <input type="number" name="qty" id="returnQty" min="1" step="1" required>
          <div style="font-size:12px;color:var(--text-300);margin-top:4px;">Max: <span id="returnMaxQty">0</span></div>
        </div>
        <div class="form-group">
          <label>Reason</label>
          <select name="reason_code" required>
            <?php foreach ($returnReasons as $rk => $rl): ?>
            <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:8px;font-weight:500;">
            <input type="checkbox" name="resalable" value="1" checked>
            Return to stock (resalable)
          </label>
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" rows="2" placeholder="Optional"></textarea>
        </div>
        <div class="form-actions" style="padding-top:12px;margin-top:12px;">
          <button type="submit" class="btn btn-primary">Process Return</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('returnModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function openCorrectModal(itemId, qty, name) {
  document.getElementById('correctItemId').value = itemId;
  document.getElementById('correctQty').value = qty;
  document.getElementById('correctLabel').textContent = name + ' — current qty: ' + qty;
  openModal('correctModal');
}
function openReturnModal(itemId, qty, name) {
  document.getElementById('returnItemId').value = itemId;
  document.getElementById('returnQty').value = 1;
  document.getElementById('returnQty').max = qty;
  document.getElementById('returnMaxQty').textContent = qty;
  document.getElementById('returnLabel').textContent = name + ' — sold: ' + qty;
  openModal('returnModal');
}
</script>
<?php renderFooter(); ?>
