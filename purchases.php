<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/purchases_lib.php';

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$msg    = '';
$error  = '';
$userId = (int)(currentUser()['id'] ?? 0);

function inputStyle(int $w = 0): string {
    return 'width:' . ($w ? "{$w}px" : '100%') . ';padding:7px 10px;font-size:13px;background:var(--bg-600);border:1px solid var(--border-strong);color:var(--text-100);border-radius:6px;';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'quick_supplier') {
            if (!can('purchases.manage') && !can('suppliers.manage')) {
                throw new RuntimeException('You cannot create suppliers.');
            }
            $sid = saveSupplier($pdo, $_POST);
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                $s = $pdo->prepare("SELECT * FROM suppliers WHERE id=?");
                $s->execute([$sid]);
                echo json_encode(['ok' => true, 'supplier' => $s->fetch()]);
                exit;
            }
            flashSet('success', 'Supplier created.');
            header('Location: purchases.php?action=add&supplier_id=' . $sid);
            exit;
        }
        if (!can('purchases.manage')) {
            throw new RuntimeException('You do not have permission to manage purchases.');
        }
        if ($act === 'save_purchase') {
            $pid = createPurchase($pdo, $_POST, $userId);
            $print = ($_POST['save_intent'] ?? '') === 'print';
            flashSet('success', 'Purchase saved.');
            header('Location: ' . ($print ? 'purchase_invoice.php?id=' . $pid : 'purchases.php?action=view&id=' . $pid));
            exit;
        }
        if ($act === 'receive') {
            receivePurchase($pdo, (int)$_POST['id'], $userId);
            flashSet('success', 'Purchase received and stock updated.');
            header('Location: purchases.php?action=view&id=' . (int)$_POST['id']);
            exit;
        }
        if ($act === 'pay') {
            recordPurchasePayment(
                $pdo,
                (int)$_POST['id'],
                (float)$_POST['amount'],
                $_POST['payment_method'] ?? 'cash',
                trim($_POST['payment_date'] ?? '') ?: businessToday(),
                trim($_POST['reference_number'] ?? ''),
                $userId,
                trim($_POST['notes'] ?? '')
            );
            flashSet('success', 'Payment recorded.');
            header('Location: purchases.php?action=view&id=' . (int)$_POST['id']);
            exit;
        }
        if ($act === 'return') {
            createPurchaseReturn($pdo, (int)$_POST['id'], $_POST, $userId);
            flashSet('success', 'Purchase return recorded and stock reduced.');
            header('Location: purchases.php?action=view&id=' . (int)$_POST['id']);
            exit;
        }
        if ($act === 'cancel') {
            cancelPurchase($pdo, (int)$_POST['id'], $userId);
            flashSet('success', 'Purchase cancelled.');
            header('Location: purchases.php');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($act === 'save_purchase') $action = 'add';
        elseif (in_array($act, ['pay', 'return', 'receive', 'cancel'], true)) {
            $action = 'view';
            $id = (int)($_POST['id'] ?? $id);
        }
    }
}

$flash = flashGet();
if ($flash && $flash['type'] === 'success') $msg = $flash['message'];

$suppliers = $pdo->query("SELECT * FROM suppliers WHERE COALESCE(status,'active')='active' ORDER BY name COLLATE NOCASE")->fetchAll();
$medicines = $pdo->query("SELECT id, name, generic_name, unit, sku, COALESCE(product_type,'medicine') AS product_type FROM medicines ORDER BY name COLLATE NOCASE")->fetchAll();
$taxDefault = (float)getSetting('tax_rate', '0');

$purchase = null;
$items = [];
$payments = [];
$returns = [];
if ($action === 'view' && $id) {
    $purchase = fetchPurchase($pdo, $id);
    if (!$purchase) {
        header('Location: purchases.php');
        exit;
    }
    $items = fetchPurchaseItems($pdo, $id);
    $payments = fetchPurchasePayments($pdo, $id);
    $returns = fetchPurchaseReturns($pdo, $id);
}

$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'supplier' => (int)($_GET['supplier'] ?? 0),
    'from' => $_GET['from'] ?? '',
    'to' => $_GET['to'] ?? '',
    'status' => $_GET['status'] ?? '',
    'sort' => $_GET['sort'] ?? 'newest',
];
$page = max(1, (int)($_GET['page'] ?? 1));
$list = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$dash = [];
if ($action === 'list') {
    $list = listPurchases($pdo, $filters, $page, 40);
    $dash = payableDashboard($pdo);
}

$nextNumber = nextPurchaseNumber($pdo);
$preSupplier = (int)($_GET['supplier_id'] ?? 0);

renderHead($action === 'add' ? 'New Purchase' : ($action === 'view' ? 'Purchase' : 'Purchases'));
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar($action === 'add' ? 'New Purchase' : ($action === 'view' ? ($purchase['purchase_number'] ?? 'Purchase') : 'Purchases'), 'Procurement, supplier credit & accounts payable'); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($action === 'add'): ?>
<div class="mb-20"><a href="purchases.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> All Purchases</a></div>
<form method="POST" id="purchaseForm">
  <input type="hidden" name="act" value="save_purchase">
  <input type="hidden" name="save_intent" id="saveIntent" value="receive">

  <div class="card mb-20">
    <div class="card-header"><span class="card-title">Supplier & Invoice</span></div>
    <div class="form-row">
      <div class="form-group">
        <label>Invoice Number</label>
        <input type="text" value="<?= htmlspecialchars($nextNumber) ?>" disabled>
      </div>
      <div class="form-group">
        <label>Supplier *</label>
        <div style="display:flex;gap:8px;">
          <select name="supplier_id" id="supplierSelect" required style="flex:1;">
            <option value="">Select supplier...</option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= $s['id'] ?>" data-terms="<?= htmlspecialchars($s['payment_terms'] ?? '30') ?>" data-days="<?= (int)($s['default_credit_days'] ?? 30) ?>" <?= $preSupplier === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-ghost" onclick="openModal('newSupModal')">+ New</button>
        </div>
      </div>
      <div class="form-group">
        <label>Purchase Date</label>
        <input type="date" name="purchase_date" id="purchaseDate" value="<?= htmlspecialchars(businessToday()) ?>" required>
      </div>
      <div class="form-group">
        <label>Payment Terms</label>
        <select name="payment_terms" id="paymentTerms" onchange="syncDueDate()">
          <?php foreach (supplierPaymentTerms() as $k => $lbl): ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Due Date</label>
        <input type="date" name="due_date" id="dueDate">
      </div>
      <div class="form-group">
        <label>Reference</label>
        <input type="text" name="reference" placeholder="Supplier invoice #">
      </div>
      <div class="form-group">
        <label>Warehouse / Store</label>
        <input type="text" name="warehouse" value="Main Store">
      </div>
    </div>
    <div class="form-group">
      <label>Notes</label>
      <textarea name="notes" placeholder="Optional notes..."></textarea>
    </div>
  </div>

  <div class="card mb-20">
    <div class="card-header">
      <span class="card-title">Products</span>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addRow()">+ Add Item</button>
    </div>
    <div id="itemsBody" class="purchase-items">
      <div class="purchase-item item-row">
        <div class="form-group pg-wide">
          <label>Product</label>
          <div class="product-picker">
            <input type="hidden" name="medicine_id[]" class="product-id" value="">
            <input type="hidden" name="product_code[]" class="product-code">
            <input type="text" class="product-search" placeholder="Type to search product..." autocomplete="off"
                   onfocus="openProductPicker(this)" oninput="filterProductPicker(this)" onkeydown="productPickerKey(event)">
            <div class="product-picker-list" hidden></div>
          </div>
        </div>
        <div class="form-group">
          <label>Batch</label>
          <input type="text" name="batch_number[]" placeholder="Batch #">
        </div>
        <div class="form-group">
          <label>Mfg</label>
          <input type="date" name="manufacturing_date[]">
        </div>
        <div class="form-group">
          <label>Expiry</label>
          <input type="date" name="expiry_date[]" class="expiry-input">
        </div>
        <div class="form-group">
          <label>Qty</label>
          <input type="number" name="quantity[]" min="0" value="1" oninput="recalc()">
        </div>
        <div class="form-group">
          <label>Free</label>
          <input type="number" name="free_quantity[]" min="0" value="0">
        </div>
        <div class="form-group">
          <label>Buy</label>
          <input type="number" name="purchase_price[]" min="0" step="0.01" value="0" oninput="recalc()">
        </div>
        <div class="form-group">
          <label>Sell</label>
          <input type="number" name="selling_price[]" min="0" step="0.01" value="0">
        </div>
        <div class="form-group">
          <label>Disc %</label>
          <input type="number" name="line_discount[]" min="0" step="0.01" value="0" oninput="recalc()">
        </div>
        <div class="form-group">
          <label>Tax %</label>
          <input type="number" name="line_tax[]" min="0" step="0.01" value="<?= htmlspecialchars((string)$taxDefault) ?>" oninput="recalc()">
        </div>
        <div class="form-group">
          <label>Line Total</label>
          <div class="purchase-item-total line-total">0.00</div>
        </div>
        <div class="form-group">
          <label>&nbsp;</label>
          <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">Del</button>
        </div>
      </div>
    </div>
  </div>

  <div class="grid-2 mb-20">
    <div class="card">
      <div class="card-header"><span class="card-title">Payment</span></div>
      <div class="form-group">
        <label>Payment Type</label>
        <select name="payment_type" id="paymentType" onchange="syncPaymentType()">
          <option value="paid">Paid</option>
          <option value="credit">Credit / Unpaid</option>
          <option value="partial">Partial Payment</option>
        </select>
      </div>
      <div class="form-group" id="paidWrap">
        <label>Amount Paid</label>
        <input type="number" name="amount_paid" id="amountPaid" min="0" step="0.01" value="0">
      </div>
      <div class="form-group">
        <label>Payment Method</label>
        <select name="payment_method">
          <?php foreach (supplierPaymentMethods() as $k => $lbl): ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Payment Reference</label>
        <input type="text" name="payment_reference" placeholder="Optional">
      </div>
    </div>
    <div class="card">
      <div class="card-header"><span class="card-title">Summary</span></div>
      <div class="form-row">
        <div class="form-group">
          <label>Extra Discount</label>
          <input type="number" name="header_discount" id="headerDiscount" min="0" step="0.01" value="0" oninput="recalc()">
        </div>
        <div class="form-group">
          <label>Extra Tax</label>
          <input type="number" name="header_tax" id="headerTax" min="0" step="0.01" value="0" oninput="recalc()">
        </div>
      </div>
      <div class="report-summary-grid">
        <div><span class="report-k">Subtotal</span><span class="report-v" id="sumSub">0.00</span></div>
        <div><span class="report-k">Grand Total</span><span class="report-v" id="sumGrand">0.00</span></div>
        <div><span class="report-k">Paid</span><span class="report-v" id="sumPaid">0.00</span></div>
        <div><span class="report-k">Remaining</span><span class="report-v" id="sumDue" style="color:var(--warning);">0.00</span></div>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <?php if (can('purchases.manage')): ?>
    <button type="submit" class="btn btn-success" onclick="document.getElementById('saveIntent').value='receive'"><i data-lucide="package-check"></i> Save & Receive</button>
    <button type="submit" class="btn btn-primary" onclick="document.getElementById('saveIntent').value='print'"><i data-lucide="printer"></i> Save & Print</button>
    <button type="submit" class="btn btn-ghost" onclick="document.getElementById('saveIntent').value='draft'">Save Draft</button>
    <?php endif; ?>
    <a href="purchases.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<div class="modal-overlay" id="newSupModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header"><h2>New Supplier</h2><button class="modal-close" onclick="closeModal('newSupModal')"><i data-lucide="x"></i></button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="act" value="quick_supplier">
        <div class="form-group"><label>Name *</label><input type="text" name="name" required></div>
        <div class="form-group"><label>Phone</label><input type="tel" name="phone"></div>
        <div class="form-group"><label>Payment Terms</label>
          <select name="payment_terms">
            <?php foreach (supplierPaymentTerms() as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $k === '30' ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Create</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('newSupModal')">Close</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function daysFromTerms(terms) {
  if (terms === 'cash' || terms === 'immediate') return 0;
  if (terms === 'custom') return null;
  const n = parseInt(terms, 10);
  return isNaN(n) ? 30 : n;
}
function addDays(dateStr, days) {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}
function syncDueDate() {
  const terms = document.getElementById('paymentTerms').value;
  const days = daysFromTerms(terms);
  if (days === null) return;
  document.getElementById('dueDate').value = addDays(document.getElementById('purchaseDate').value, days);
}
document.getElementById('supplierSelect').addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  if (!opt || !opt.value) return;
  document.getElementById('paymentTerms').value = opt.dataset.terms || '30';
  syncDueDate();
});
document.getElementById('purchaseDate').addEventListener('change', syncDueDate);

const productCatalog = <?= json_encode(array_map(static function ($m) {
    return [
        'id' => (int)$m['id'],
        'name' => $m['name'],
        'generic' => $m['generic_name'] ?? '',
        'unit' => $m['unit'] ?? '',
        'sku' => $m['sku'] ?? '',
        'requiresExpiry' => productRequiresExpiry($m['product_type'] ?? 'medicine') ? 1 : 0,
    ];
}, $medicines), JSON_UNESCAPED_UNICODE) ?>;

function pickerFrom(el) {
  return el.closest('.product-picker');
}
function hideAllPickers() {
  document.querySelectorAll('.product-picker-list').forEach(list => { list.hidden = true; list.innerHTML = ''; });
}
function openProductPicker(input) {
  filterProductPicker(input);
}
function filterProductPicker(input) {
  const picker = pickerFrom(input);
  const list = picker.querySelector('.product-picker-list');
  const q = (input.value || '').trim().toLowerCase();
  if (q) {
    picker.querySelector('.product-id').value = '';
  }
  const matches = productCatalog.filter(p => {
    if (!q) return true;
    return (p.name || '').toLowerCase().includes(q)
      || (p.generic || '').toLowerCase().includes(q)
      || (p.sku || '').toLowerCase().includes(q);
  }).slice(0, 40);
  if (!matches.length) {
    list.innerHTML = '<div class="product-picker-empty">No matching product</div>';
    list.hidden = false;
    return;
  }
  list.innerHTML = matches.map((p, i) =>
    `<button type="button" class="product-picker-item${i === 0 ? ' active' : ''}" data-id="${p.id}">`
    + `${escapeHtml(p.name)}`
    + `<small>${escapeHtml([p.generic, p.unit, p.sku].filter(Boolean).join(' · '))}</small>`
    + `</button>`
  ).join('');
  list.hidden = false;
  list.querySelectorAll('.product-picker-item').forEach(btn => {
    btn.addEventListener('mousedown', e => e.preventDefault());
    btn.addEventListener('click', () => selectProduct(picker, parseInt(btn.dataset.id, 10)));
  });
}
function selectProduct(picker, id) {
  const p = productCatalog.find(x => x.id === id);
  if (!p) return;
  picker.querySelector('.product-id').value = p.id;
  picker.querySelector('.product-code').value = p.sku || '';
  picker.querySelector('.product-search').value = p.name;
  picker.querySelector('.product-picker-list').hidden = true;
  const row = picker.closest('.item-row');
  const exp = row.querySelector('.expiry-input');
  if (exp) exp.required = !!p.requiresExpiry;
}
function productPickerKey(e) {
  const picker = pickerFrom(e.target);
  const list = picker.querySelector('.product-picker-list');
  if (e.key === 'Escape') { list.hidden = true; return; }
  const items = [...list.querySelectorAll('.product-picker-item')];
  if (!items.length) return;
  const idx = items.findIndex(el => el.classList.contains('active'));
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    const next = items[Math.min(items.length - 1, idx + 1)] || items[0];
    items.forEach(el => el.classList.remove('active'));
    next.classList.add('active');
    next.scrollIntoView({ block: 'nearest' });
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    const prev = items[Math.max(0, idx - 1)] || items[0];
    items.forEach(el => el.classList.remove('active'));
    prev.classList.add('active');
    prev.scrollIntoView({ block: 'nearest' });
  } else if (e.key === 'Enter') {
    e.preventDefault();
    const active = items[idx] || items[0];
    if (active) selectProduct(picker, parseInt(active.dataset.id, 10));
  }
}
function escapeHtml(s) {
  return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
document.addEventListener('click', e => {
  if (!e.target.closest('.product-picker')) hideAllPickers();
});
function lineTotal(row) {
  const qty = parseFloat(row.querySelector('[name="quantity[]"]').value) || 0;
  const price = parseFloat(row.querySelector('[name="purchase_price[]"]').value) || 0;
  const disc = parseFloat(row.querySelector('[name="line_discount[]"]').value) || 0;
  const tax = parseFloat(row.querySelector('[name="line_tax[]"]').value) || 0;
  const gross = qty * price;
  const net = gross - gross * disc / 100;
  return net + net * tax / 100;
}
function recalc() {
  let sub = 0;
  document.querySelectorAll('.item-row').forEach(row => {
    const t = lineTotal(row);
    sub += t;
    row.querySelector('.line-total').textContent = t.toFixed(2);
  });
  const extraD = parseFloat(document.getElementById('headerDiscount').value) || 0;
  const extraT = parseFloat(document.getElementById('headerTax').value) || 0;
  const grand = Math.max(0, sub - extraD + extraT);
  document.getElementById('sumSub').textContent = sub.toFixed(2);
  document.getElementById('sumGrand').textContent = grand.toFixed(2);
  syncPaymentType();
}
function syncPaymentType() {
  const type = document.getElementById('paymentType').value;
  const grand = parseFloat(document.getElementById('sumGrand').textContent) || 0;
  const paidInput = document.getElementById('amountPaid');
  if (type === 'paid') paidInput.value = grand.toFixed(2);
  if (type === 'credit') paidInput.value = '0';
  const paid = parseFloat(paidInput.value) || 0;
  document.getElementById('sumPaid').textContent = paid.toFixed(2);
  document.getElementById('sumDue').textContent = Math.max(0, grand - paid).toFixed(2);
}
document.getElementById('amountPaid').addEventListener('input', syncPaymentType);
function addRow() {
  const tbody = document.getElementById('itemsBody');
  const row = tbody.querySelector('.item-row').cloneNode(true);
  row.querySelectorAll('input').forEach(i => {
    if (i.type === 'number') i.value = i.name.indexOf('quantity') !== -1 && i.name.indexOf('free') === -1 ? '1' : '0';
    else if (i.type !== 'hidden') i.value = '';
    else if (i.classList.contains('product-id') || i.classList.contains('product-code')) i.value = '';
  });
  const tax = row.querySelector('[name="line_tax[]"]');
  if (tax) tax.value = <?= json_encode((string)$taxDefault) ?>;
  const list = row.querySelector('.product-picker-list');
  if (list) { list.hidden = true; list.innerHTML = ''; }
  const exp = row.querySelector('.expiry-input');
  if (exp) exp.required = false;
  tbody.appendChild(row);
}
function removeRow(btn) {
  if (document.querySelectorAll('.item-row').length > 1) btn.closest('.item-row').remove();
  recalc();
}
syncDueDate();
recalc();
</script>

<?php elseif ($action === 'view' && $purchase):
  $disp = purchaseDisplayStatus($purchase);
  $grand = (float)($purchase['grand_total'] ?? $purchase['total_amount']);
  $paid = (float)$purchase['total_paid'];
  $returned = (float)($purchase['total_returned'] ?? 0);
  $due = purchaseOutstanding($purchase);
?>
<div class="mb-20" style="display:flex;gap:8px;flex-wrap:wrap;">
  <a href="purchases.php" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> All Purchases</a>
  <a href="purchase_invoice.php?id=<?= $purchase['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="printer"></i> Print Invoice</a>
  <?php if ($purchase['supplier_id']): ?>
  <a href="suppliers.php?id=<?= $purchase['supplier_id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="truck"></i> Supplier</a>
  <?php endif; ?>
</div>

<div class="card mb-20">
  <div class="card-header">
    <span class="card-title"><?= htmlspecialchars($purchase['purchase_number']) ?></span>
    <span class="badge <?= $disp[2] ?>"><?= htmlspecialchars($disp[1]) ?></span>
  </div>
  <div class="report-summary-grid">
    <div><span class="report-k">Supplier</span><span class="report-v"><?= htmlspecialchars($purchase['supplier_name'] ?? '—') ?></span></div>
    <div><span class="report-k">Date</span><span class="report-v"><?= htmlspecialchars($purchase['purchase_date'] ?: date('Y-m-d', strtotime($purchase['created_at']))) ?></span></div>
    <div><span class="report-k">Due Date</span><span class="report-v"><?= $purchase['due_date'] ? htmlspecialchars($purchase['due_date']) : '—' ?></span></div>
    <div><span class="report-k">Original Total</span><span class="report-v"><?= currency($grand) ?></span></div>
    <div><span class="report-k">Paid</span><span class="report-v" style="color:var(--accent2);"><?= currency($paid) ?></span></div>
    <div><span class="report-k">Returned</span><span class="report-v"><?= currency($returned) ?></span></div>
    <div><span class="report-k">Outstanding</span><span class="report-v" style="color:var(--warning);"><?= currency($due) ?></span></div>
    <div><span class="report-k">Prepared by</span><span class="report-v"><?= htmlspecialchars($purchase['created_by_name'] ?? '—') ?></span></div>
  </div>
  <?php if (can('purchases.manage')): ?>
  <div class="form-actions" style="padding-top:8px;">
    <?php if (in_array($purchase['status'], ['draft','pending_approval'], true)): ?>
    <form method="POST"><input type="hidden" name="act" value="receive"><input type="hidden" name="id" value="<?= $purchase['id'] ?>">
      <button class="btn btn-success">Receive into Inventory</button></form>
    <?php endif; ?>
    <?php if ($purchase['status'] === 'received' && $due > 0.009): ?>
    <button type="button" class="btn btn-primary" onclick="openModal('payModal')">Make Payment</button>
    <button type="button" class="btn btn-ghost" onclick="openModal('returnModal')">Return</button>
    <?php endif; ?>
    <?php if ($purchase['status'] !== 'cancelled' && $paid <= 0.009): ?>
    <form method="POST" onsubmit="return confirm('Cancel this purchase? Stock will be reversed if already received.')">
      <input type="hidden" name="act" value="cancel"><input type="hidden" name="id" value="<?= $purchase['id'] ?>">
      <button class="btn btn-danger">Cancel Purchase</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Items</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th>Qty</th><th>Free</th><th>Buy</th><th>Sell</th><th>Disc %</th><th>Tax %</th><th>Total</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($it['med_name']) ?></td>
        <td><code><?= htmlspecialchars($it['batch_number']) ?></code></td>
        <td><?= formatExpiryDate($it['expiry_date']) ?></td>
        <td><?= (int)$it['quantity'] ?></td>
        <td><?= (int)($it['free_quantity'] ?? 0) ?></td>
        <td><?= currency((float)$it['purchase_price']) ?></td>
        <td><?= currency((float)$it['selling_price']) ?></td>
        <td><?= number_format((float)($it['discount'] ?? 0), 1) ?></td>
        <td><?= number_format((float)($it['tax'] ?? 0), 1) ?></td>
        <td style="font-weight:700;"><?= currency((float)($it['line_total'] ?: $it['quantity'] * $it['purchase_price'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">Payment History</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded by</th><th>Notes</th></tr></thead>
      <tbody>
      <?php if (!$payments): ?>
        <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-300);">No payments yet</td></tr>
      <?php else: foreach ($payments as $pp): ?>
      <tr>
        <td><?= htmlspecialchars($pp['payment_date']) ?></td>
        <td style="font-weight:700;color:var(--accent2);"><?= currency((float)$pp['amount']) ?></td>
        <td><?= htmlspecialchars(supplierPaymentMethods()[$pp['payment_method']] ?? $pp['payment_method']) ?></td>
        <td><?= htmlspecialchars($pp['reference_number'] ?: '—') ?></td>
        <td><?= htmlspecialchars($pp['created_by_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($pp['notes'] ?? '') ?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($returns): ?>
<div class="card mb-20">
  <div class="card-header"><span class="card-title">Returns</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Return #</th><th>Date</th><th>Amount</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($returns as $r): ?>
      <tr>
        <td><code><?= htmlspecialchars($r['return_number']) ?></code></td>
        <td><?= htmlspecialchars($r['return_date']) ?></td>
        <td><?= currency((float)$r['total_amount']) ?></td>
        <td><?= htmlspecialchars(purchaseReturnReasons()[$r['reason']] ?? $r['reason']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="modal-overlay" id="payModal">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header"><h2>Make Payment</h2><button class="modal-close" onclick="closeModal('payModal')"><i data-lucide="x"></i></button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="act" value="pay">
        <input type="hidden" name="id" value="<?= $purchase['id'] ?>">
        <p style="font-size:13px;color:var(--text-300);margin-bottom:12px;">
          Original <?= currency($grand) ?> · Paid <?= currency($paid) ?> · Remaining <strong style="color:var(--warning);"><?= currency($due) ?></strong>
        </p>
        <div class="form-group"><label>Amount</label><input type="number" name="amount" step="0.01" min="0.01" max="<?= $due ?>" value="<?= number_format($due, 2, '.', '') ?>" required></div>
        <div class="form-group"><label>Date</label><input type="date" name="payment_date" value="<?= htmlspecialchars(businessToday()) ?>" required></div>
        <div class="form-group"><label>Method</label>
          <select name="payment_method">
            <?php foreach (supplierPaymentMethods() as $k => $lbl): ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Reference</label><input type="text" name="reference_number"></div>
        <div class="form-group"><label>Notes</label><textarea name="notes"></textarea></div>
        <div class="form-actions"><button class="btn btn-primary">Record Payment</button><button type="button" class="btn btn-ghost" onclick="closeModal('payModal')">Close</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal-overlay" id="returnModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header"><h2>Purchase Return</h2><button class="modal-close" onclick="closeModal('returnModal')"><i data-lucide="x"></i></button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="act" value="return">
        <input type="hidden" name="id" value="<?= $purchase['id'] ?>">
        <div class="form-group"><label>Date</label><input type="date" name="return_date" value="<?= htmlspecialchars(businessToday()) ?>"></div>
        <div class="form-group"><label>Reason</label>
          <select name="reason">
            <?php foreach (purchaseReturnReasons() as $k => $lbl): ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Product</th><th>Batch</th><th>Remaining</th><th>Return qty</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it):
              $remain = (int)$it['quantity'] + (int)($it['free_quantity'] ?? 0) - (int)($it['returned_quantity'] ?? 0);
              if ($remain <= 0) continue;
            ?>
            <tr>
              <td><?= htmlspecialchars($it['med_name']) ?></td>
              <td><code><?= htmlspecialchars($it['batch_number']) ?></code></td>
              <td><?= $remain ?></td>
              <td><input type="number" name="return_qty[<?= $it['id'] ?>]" min="0" max="<?= $remain ?>" value="0" style="<?= inputStyle(80) ?>"></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="form-group"><label>Notes</label><textarea name="notes"></textarea></div>
        <div class="form-actions"><button class="btn btn-primary">Save Return</button><button type="button" class="btn btn-ghost" onclick="closeModal('returnModal')">Close</button></div>
      </form>
    </div>
  </div>
</div>

<?php else:
  $pagerBase = 'purchases.php?' . http_build_query(array_filter($filters, fn($v) => $v !== '' && $v !== 0 && $v !== 'newest'));
?>
<div class="stats-grid mb-20">
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="wallet"></i></div><div><div class="stat-label">Total Payable</div><div class="stat-value"><?= number_format($dash['total'] ?? 0, 0) ?></div></div></div>
  <div class="stat-card red"><div class="stat-icon red"><i data-lucide="alert-circle"></i></div><div><div class="stat-label">Overdue</div><div class="stat-value"><?= number_format($dash['overdue'] ?? 0, 0) ?></div></div></div>
  <div class="stat-card orange"><div class="stat-icon orange"><i data-lucide="calendar"></i></div><div><div class="stat-label">Due This Week</div><div class="stat-value"><?= number_format($dash['dueWeek'] ?? 0, 0) ?></div></div></div>
  <div class="stat-card blue"><div class="stat-icon blue"><i data-lucide="calendar-clock"></i></div><div><div class="stat-label">Due This Month</div><div class="stat-value"><?= number_format($dash['dueMonth'] ?? 0, 0) ?></div></div></div>
</div>
<p style="color:var(--text-300);font-size:13px;margin:-8px 0 16px;">
  Paid invoices: <?= (int)($dash['paidCnt'] ?? 0) ?> · Credit: <?= (int)($dash['creditCnt'] ?? 0) ?> · Partial: <?= (int)($dash['partialCnt'] ?? 0) ?> · Suppliers with balances: <?= (int)($dash['suppliers'] ?? 0) ?>
  · Due today: <?= currency((float)($dash['dueToday'] ?? 0)) ?>
</p>

<div class="card">
  <div class="card-header">
    <span class="card-title">Purchase Invoices (<?= number_format($list['total']) ?>)</span>
    <div style="display:flex;gap:8px;">
      <a href="suppliers.php" class="btn btn-ghost btn-sm"><i data-lucide="truck"></i> Suppliers</a>
      <?php if (can('purchases.manage')): ?>
      <a href="purchases.php?action=add" class="btn btn-primary"><i data-lucide="plus"></i> New Purchase</a>
      <?php endif; ?>
    </div>
  </div>
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;padding:0 16px 14px;align-items:flex-end;">
    <div class="form-group" style="margin:0;flex:1;min-width:160px;"><label>Search</label>
      <div class="search-bar"><i data-lucide="search"></i><input type="text" name="q" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Invoice, supplier, product, batch..."></div>
    </div>
    <div class="form-group" style="margin:0;min-width:140px;"><label>Supplier</label>
      <select name="supplier"><option value="">All</option>
        <?php foreach ($suppliers as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $filters['supplier']==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;"><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($filters['from']) ?>"></div>
    <div class="form-group" style="margin:0;"><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($filters['to']) ?>"></div>
    <div class="form-group" style="margin:0;min-width:130px;"><label>Status</label>
      <select name="status">
        <option value="">All</option>
        <option value="paid" <?= $filters['status']==='paid'?'selected':'' ?>>Paid</option>
        <option value="partial" <?= $filters['status']==='partial'?'selected':'' ?>>Partial</option>
        <option value="unpaid" <?= $filters['status']==='unpaid'?'selected':'' ?>>Credit</option>
        <option value="overdue" <?= $filters['status']==='overdue'?'selected':'' ?>>Overdue</option>
        <option value="due_today" <?= $filters['status']==='due_today'?'selected':'' ?>>Due today</option>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:130px;"><label>Sort</label>
      <select name="sort">
        <option value="newest" <?= $filters['sort']==='newest'?'selected':'' ?>>Newest</option>
        <option value="oldest" <?= $filters['sort']==='oldest'?'selected':'' ?>>Oldest</option>
        <option value="highest" <?= $filters['sort']==='highest'?'selected':'' ?>>Highest amount</option>
        <option value="lowest" <?= $filters['sort']==='lowest'?'selected':'' ?>>Lowest amount</option>
        <option value="due" <?= $filters['sort']==='due'?'selected':'' ?>>Nearest due</option>
        <option value="overdue" <?= $filters['sort']==='overdue'?'selected':'' ?>>Most overdue</option>
      </select>
    </div>
    <button class="btn btn-ghost">Filter</button>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Invoice</th><th>Supplier</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Due Date</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$list['rows']): ?>
        <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-300);">No purchases yet</td></tr>
      <?php else: foreach ($list['rows'] as $p):
        $d = purchaseDisplayStatus($p);
        $g = (float)($p['grand_total'] ?? $p['total_amount']);
        $pd = (float)$p['total_paid'];
        $du = purchaseOutstanding($p);
      ?>
      <tr>
        <td><code><?= htmlspecialchars($p['purchase_number'] ?: $p['reference']) ?></code></td>
        <td style="font-weight:600;"><?= htmlspecialchars($p['supplier_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($p['purchase_date'] ?: date('M j, Y', strtotime($p['created_at']))) ?></td>
        <td><?= currency($g) ?></td>
        <td><?= currency($pd) ?></td>
        <td style="font-weight:700;color:var(--warning);"><?= currency($du) ?></td>
        <td><?= $p['due_date'] ? htmlspecialchars($p['due_date']) : '—' ?></td>
        <td><span class="badge <?= $d[2] ?>"><?= htmlspecialchars($d[1]) ?></span></td>
        <td>
          <div class="row-actions">
            <a href="purchases.php?action=view&id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">View</a>
            <a href="purchase_invoice.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">Print</a>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php renderPagination($list['page'], $list['pages'], $pagerBase ?: 'purchases.php'); ?>
</div>
<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
