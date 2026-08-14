<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';
require_once __DIR__ . '/customers_lib.php';
require_once __DIR__ . '/notifications_lib.php';

$pdo  = getDB();
$error = '';
$preselectCustomer = (int)($_GET['customer_id'] ?? 0);

$medsList   = fetchPosMedicines($pdo);
$customers  = searchCustomers($pdo, '', 200);
$payMethods = posPaymentMethods();

// ── Handle checkout ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId     = (int)($_POST['customer_id'] ?? 0);
    $discount       = max(0, (float)($_POST['discount'] ?? 0));
    $payment        = $_POST['payment_method'] ?? 'cash';
    $notes          = trim($_POST['notes'] ?? '');
    $creditNotes    = trim($_POST['credit_notes'] ?? '');
    $reference      = trim($_POST['payment_reference'] ?? '');
    $dueDate        = trim($_POST['due_date'] ?? '');
    $amountPaidIn   = max(0, (float)($_POST['amount_paid'] ?? 0));
    $items          = json_decode($_POST['cart_json'] ?? '[]', true);

    if (!isset($payMethods[$payment])) {
        $error = 'Invalid payment method selected.';
    } elseif (empty($items) || !is_array($items)) {
        $error = 'Cart is empty. Please add at least one medicine.';
    } elseif ($payment === 'credit' && !$customerId) {
        $error = 'Credit sales are only available for registered customers. Please select or register a customer before completing the sale.';
    } elseif ($payment === 'credit' && !$dueDate) {
        $error = 'Due date is required for credit sales.';
    } else {
        $customer = $customerId ? findCustomerById($pdo, $customerId) : null;
        if ($payment === 'credit' && !$customer) {
            $error = 'Credit sales are only available for registered customers. Please select or register a customer before completing the sale.';
        } else {
            $pdo->beginTransaction();
            try {
                $lineItems = buildFefoLineItems($pdo, $items);
                $total = array_sum(array_column($lineItems, 'subtotal'));

                if ($discount > $total) $discount = $total;
                $net = $total - $discount;

                if ($payment === 'credit') {
                    if ($customer['credit_limit'] > 0 && ((float)$customer['outstanding_balance'] + $net) > (float)$customer['credit_limit']) {
                        throw new RuntimeException('Credit limit exceeded for this customer.');
                    }
                    $paid = min($amountPaidIn, $net);
                } else {
                    $paid = $net;
                }

                $remaining = max(0, $net - $paid);
                $status = computePaymentStatus($net, $paid, $payment);
                $saleType = ($payment === 'credit') ? 'credit' : 'cash';

                $custName  = $customer ? $customer['full_name'] : 'Walk-in Customer';
                $custPhone = $customer ? $customer['phone'] : trim($_POST['customer_phone'] ?? '');

                $invoice   = generateInvoice();
                $cashierId = currentUser()['id'] ?? null;
                $allNotes  = trim($notes . ($creditNotes ? "\nCredit: $creditNotes" : ''));

                $pdo->prepare("
                    INSERT INTO sales (
                        invoice_number, customer_id, customer_name, customer_phone,
                        total_amount, discount, paid_amount, remaining_balance,
                        payment_method, payment_status, payment_reference,
                        notes, credit_notes, user_id, sale_type,
                        due_date, credit_due_date
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $invoice, $customerId ?: null, $custName, $custPhone,
                    $total, $discount, $paid, $remaining,
                    $payment, $status, $reference ?: null,
                    $notes, $creditNotes ?: null, $cashierId, $saleType,
                    $payment === 'credit' ? $dueDate : null,
                    $payment === 'credit' ? $dueDate : null,
                ]);
                $saleId = (int)$pdo->lastInsertId();

                foreach ($lineItems as $li) {
                    $pdo->prepare("
                        INSERT INTO sale_items (sale_id, medicine_id, batch_id, quantity, unit_price, subtotal)
                        VALUES (?,?,?,?,?,?)
                    ")->execute([$saleId, $li['medicine_id'], $li['batch_id'], $li['qty'], $li['price'], $li['subtotal']]);
                    $pdo->prepare("UPDATE batches SET quantity = quantity - ? WHERE id = ?")
                        ->execute([$li['qty'], $li['batch_id']]);
                    checkStockAlerts($pdo, $li['medicine_id']);
                }

                if ($paid > 0) {
                    recordSalePayment($pdo, $saleId, $customerId ?: null, $paid, $payment, $reference ?: null, $cashierId, 'Initial payment');
                }

                if ($customerId) {
                    refreshCustomerCredit($pdo, $customerId);
                }

                $pdo->commit();

                if ($payment === 'credit' && $customer) {
                    $saleRow = $pdo->query("SELECT * FROM sales WHERE id = $saleId")->fetch();
                    notifyNewCreditSale($saleRow, $customer);
                }

                header("Location: receipt.php?id=$saleId");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Transaction failed: ' . $e->getMessage();
            }
        }
    }
}

renderHead('POS — New Sale');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Point of Sale', 'New Sale Transaction'); ?>
<div class="page-body" style="overflow:hidden;">

<?php if ($error): ?>
<div class="alert alert-danger" id="posError"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="pos-layout">
  <div class="pos-left">
    <div class="pos-search">
      <div class="pos-search-icon"><i data-lucide="search"></i></div>
      <input type="text" id="posSearch" placeholder="Search medicines..." oninput="filterMeds(this.value)">
    </div>
    <div class="pos-items-grid" id="medGrid">
      <?php foreach ($medsList as $m):
        $expDays = (strtotime($m['expiry_date']) - time()) / 86400;
        $expLabel = $expDays <= 30 ? '<span class="badge badge-orange" style="font-size:10px;">Exp soon</span>' : '';
      ?>
      <div class="med-card"
           data-id="<?= $m['id'] ?>"
           data-name="<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>"
           data-generic="<?= htmlspecialchars($m['generic_name'] ?? '', ENT_QUOTES) ?>"
           data-price="<?= $m['selling_price'] ?>"
           data-stock="<?= $m['stock'] ?>"
           data-unit="<?= htmlspecialchars($m['unit'], ENT_QUOTES) ?>"
           onclick="addToCart(this)">
        <div class="med-card-name"><?= htmlspecialchars($m['name']) ?></div>
        <div class="med-card-generic"><?= htmlspecialchars($m['generic_name'] ?: '') ?></div>
        <div class="med-card-price"><?= getSetting('currency','ETB') ?> <?= number_format($m['selling_price'], 2) ?></div>
        <div class="med-card-stock">Stock: <?= $m['stock'] ?> <?= htmlspecialchars($m['unit']) ?> <?= $expLabel ?></div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($medsList)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--text-300);">
          <p>No medicines available in stock.</p>
          <a href="purchases.php?action=add" class="btn btn-primary" style="margin-top:14px;">Add Purchase/Stock</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="pos-right">
    <div class="pos-cart-header">Cart <span id="cartCount" style="color:var(--accent);">(0 items)</span></div>
    <div class="pos-cart-items" id="cartItems">
      <div style="text-align:center;padding:40px 20px;color:var(--text-300);font-size:13px;">Click medicines to add to cart</div>
    </div>

    <div class="pos-cart-total">
      <div class="pos-total-row"><span>Subtotal</span><span id="subtotalVal">ETB 0.00</span></div>
      <div class="pos-total-row">
        <span>Discount</span>
        <span><input type="number" id="discountInput" value="0" min="0" step="0.01" style="width:90px;padding:4px 8px;font-size:13px;text-align:right;" onchange="recalc()"></span>
      </div>
      <div class="pos-total-row grand"><span>TOTAL</span><span id="totalVal">ETB 0.00</span></div>
    </div>

    <div class="pos-checkout">
      <form id="checkoutForm" method="POST">
        <input type="hidden" name="cart_json" id="cartJson">
        <input type="hidden" name="discount" id="discountHidden">

        <!-- Customer -->
        <div class="form-group" style="margin-bottom:10px;">
          <label style="margin-bottom:4px;display:flex;justify-content:space-between;align-items:center;">
            Customer
            <button type="button" class="btn btn-ghost btn-sm" onclick="openRegisterModal()" style="padding:2px 8px;font-size:11px;">+ Register</button>
          </label>
          <select name="customer_id" id="customerSelect" onchange="onCustomerChange()" style="padding:8px 12px;font-size:13px;">
            <option value="0" data-name="Walk-in Customer" data-phone="">Walk-in Customer</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>" data-name="<?= htmlspecialchars($c['full_name'], ENT_QUOTES) ?>"
              data-phone="<?= htmlspecialchars($c['phone'], ENT_QUOTES) ?>"
              <?= $preselectCustomer === (int)$c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['full_name']) ?> — <?= htmlspecialchars($c['phone']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="walkinPhone" class="form-group" style="margin-bottom:10px;">
          <label style="margin-bottom:4px;">Phone <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
          <input type="tel" name="customer_phone" id="customerPhone" placeholder="Optional for walk-in" style="padding:8px 12px;font-size:13px;">
        </div>

        <!-- Payment -->
        <div class="form-group" style="margin-bottom:10px;">
          <label style="margin-bottom:4px;">Payment Method</label>
          <select name="payment_method" id="paymentMethod" onchange="onPaymentChange()" style="padding:8px 12px;font-size:13px;">
            <?php foreach ($payMethods as $key => $label): ?>
            <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="refGroup" class="form-group" style="margin-bottom:10px;display:none;">
          <label style="margin-bottom:4px;">Transaction Reference <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
          <input type="text" name="payment_reference" placeholder="e.g. Telebirr ref #" style="padding:8px 12px;font-size:13px;">
        </div>

        <!-- Credit panel -->
        <div id="creditPanel" style="display:none;" class="credit-panel mb-10">
          <div class="credit-panel-title"><i data-lucide="credit-card"></i> Credit Sale</div>
          <div id="creditWarning" class="alert alert-warning" style="display:none;margin-bottom:10px;padding:10px;font-size:12px;">
            Credit sales are only available for registered customers. Please select or register a customer before completing the sale.
          </div>
          <div class="form-group" style="margin-bottom:8px;">
            <label>Customer Name</label>
            <input type="text" id="creditCustName" readonly style="padding:8px 12px;font-size:13px;background:var(--bg-700);">
          </div>
          <div class="form-group" style="margin-bottom:8px;">
            <label>Phone Number</label>
            <input type="text" id="creditCustPhone" readonly style="padding:8px 12px;font-size:13px;background:var(--bg-700);">
          </div>
          <div class="form-group" style="margin-bottom:8px;">
            <label>Invoice Total</label>
            <input type="text" id="creditInvoiceTotal" readonly style="padding:8px 12px;font-size:13px;background:var(--bg-700);">
          </div>
          <div class="form-group" style="margin-bottom:8px;">
            <label>Amount Paid <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
            <input type="number" name="amount_paid" id="amountPaid" min="0" step="0.01" value="0" onchange="updateCreditBalance()" style="padding:8px 12px;font-size:13px;">
          </div>
          <div class="form-group" style="margin-bottom:8px;">
            <label>Remaining Balance</label>
            <input type="text" id="creditRemaining" readonly style="padding:8px 12px;font-size:13px;background:var(--bg-700);color:var(--warning);font-weight:700;">
          </div>
          <div class="form-group" style="margin-bottom:8px;">
            <label>Due Date <span style="color:var(--danger);">*</span></label>
            <input type="date" name="due_date" id="dueDate" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" style="padding:8px 12px;font-size:13px;">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>Credit Notes <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
            <textarea name="credit_notes" placeholder="Optional credit notes..." style="min-height:60px;font-size:13px;"></textarea>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:10px;">
          <label style="margin-bottom:4px;">Sale Notes</label>
          <input type="text" name="notes" placeholder="Optional" style="padding:8px 12px;font-size:13px;">
        </div>

        <div class="pos-total-row" style="margin-bottom:12px;font-size:12px;color:var(--text-300);">
          <span>Amount due</span>
          <span id="paidDisplay" style="color:var(--accent2);font-weight:700;">ETB 0.00</span>
        </div>

        <button type="submit" class="btn btn-success" id="checkoutBtn" onclick="return prepareCheckout()">
          <i data-lucide="receipt"></i> Complete Sale
        </button>
      </form>
    </div>
  </div>
</div>

</div></div>

<!-- Register Customer Modal -->
<div class="modal-overlay" id="registerModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header">
      <h2>Register Customer</h2>
      <button class="modal-close" onclick="closeModal('registerModal')"><i data-lucide="x"></i></button>
    </div>
    <div class="modal-body">
      <div id="regError" class="alert alert-danger" style="display:none;margin-bottom:12px;"></div>
      <div class="form-group"><label>Full Name</label><input type="text" id="regName" required></div>
      <div class="form-group"><label>Phone Number</label><input type="tel" id="regPhone" required placeholder="Must be unique"></div>
      <div class="form-actions">
        <button type="button" class="btn btn-primary" onclick="submitRegister()">Register & Select</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('registerModal')">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script>
const CUR = '<?= getSetting('currency','ETB') ?>';
const CREDIT_MSG = 'Credit sales are only available for registered customers. Please select or register a customer before completing the sale.';
let cart = {};
let currentTotal = 0;

function addToCart(el) {
  const id    = el.dataset.id;
  const name  = el.dataset.name;
  const price = parseFloat(el.dataset.price);
  const stock = parseInt(el.dataset.stock);
  const unit  = el.dataset.unit;

  if (cart[id]) {
    if (cart[id].qty < stock) cart[id].qty++;
    else { alert('Maximum stock reached!'); return; }
  } else {
    cart[id] = { medicine_id: id, name, price, qty: 1, stock, unit };
  }
  renderCart();
}

function adjustQty(id, delta) {
  if (!cart[id]) return;
  cart[id].qty += delta;
  if (cart[id].qty <= 0) delete cart[id];
  else if (cart[id].qty > cart[id].stock) cart[id].qty = cart[id].stock;
  renderCart();
}

function removeItem(id) { delete cart[id]; renderCart(); }

function renderCart() {
  const container = document.getElementById('cartItems');
  const keys = Object.keys(cart);
  document.getElementById('cartCount').textContent = `(${keys.length} item${keys.length !== 1 ? 's' : ''})`;

  if (keys.length === 0) {
    container.innerHTML = '<div style="text-align:center;padding:40px 20px;color:var(--text-300);font-size:13px;">Click medicines to add to cart</div>';
    recalc(); return;
  }

  container.innerHTML = keys.map(id => {
    const it = cart[id];
    return `<div class="cart-item">
      <div style="flex:1;">
        <div class="cart-item-name">${it.name}</div>
        <div class="cart-item-price">${CUR} ${it.price.toFixed(2)} × ${it.qty} = ${CUR} ${(it.price * it.qty).toFixed(2)}</div>
        <div style="font-size:10px;color:var(--text-300);">FEFO · ${it.unit}</div>
      </div>
      <div class="qty-ctrl">
        <button type="button" class="qty-btn" onclick="adjustQty('${id}',-1)">−</button>
        <span class="qty-val">${it.qty}</span>
        <button type="button" class="qty-btn" onclick="adjustQty('${id}',1)">+</button>
      </div>
      <button type="button" class="cart-item-remove" onclick="removeItem('${id}')">×</button>
    </div>`;
  }).join('');
  recalc();
}

function recalc() {
  let sub = Object.values(cart).reduce((s, it) => s + it.price * it.qty, 0);
  let dis = parseFloat(document.getElementById('discountInput').value) || 0;
  if (dis > sub) { dis = sub; document.getElementById('discountInput').value = dis; }
  currentTotal = Math.max(0, sub - dis);
  document.getElementById('subtotalVal').textContent = `${CUR} ${sub.toFixed(2)}`;
  document.getElementById('totalVal').textContent    = `${CUR} ${currentTotal.toFixed(2)}`;
  document.getElementById('paidDisplay').textContent = `${CUR} ${currentTotal.toFixed(2)}`;
  document.getElementById('creditInvoiceTotal').value = `${CUR} ${currentTotal.toFixed(2)}`;
  updateCreditBalance();
  validateCheckout();
}

function getSelectedCustomer() {
  const sel = document.getElementById('customerSelect');
  const opt = sel.options[sel.selectedIndex];
  return { id: parseInt(sel.value) || 0, name: opt.dataset.name || '', phone: opt.dataset.phone || '' };
}

function onCustomerChange() {
  const c = getSelectedCustomer();
  document.getElementById('walkinPhone').style.display = c.id ? 'none' : '';
  document.getElementById('creditCustName').value  = c.id ? c.name : '';
  document.getElementById('creditCustPhone').value = c.id ? c.phone : '';
  validateCheckout();
}

function onPaymentChange() {
  const method = document.getElementById('paymentMethod').value;
  const isCredit = method === 'credit';
  const isDigital = ['telebirr','cbe','abyssinia'].includes(method);

  document.getElementById('creditPanel').style.display = isCredit ? '' : 'none';
  document.getElementById('refGroup').style.display = isDigital ? '' : 'none';
  document.getElementById('dueDate').required = isCredit;

  onCustomerChange();
  recalc();
}

function updateCreditBalance() {
  const paid = parseFloat(document.getElementById('amountPaid').value) || 0;
  const remaining = Math.max(0, currentTotal - paid);
  document.getElementById('creditRemaining').value = `${CUR} ${remaining.toFixed(2)}`;
}

function validateCheckout() {
  const method = document.getElementById('paymentMethod').value;
  const c = getSelectedCustomer();
  const btn = document.getElementById('checkoutBtn');
  const warn = document.getElementById('creditWarning');
  const cartEmpty = Object.keys(cart).length === 0;

  let blocked = cartEmpty;
  if (method === 'credit' && !c.id) {
    blocked = true;
    warn.style.display = '';
  } else {
    warn.style.display = 'none';
  }

  btn.disabled = blocked;
  btn.style.opacity = blocked ? '0.5' : '1';
  btn.title = (method === 'credit' && !c.id) ? CREDIT_MSG : '';
}

function prepareCheckout() {
  if (Object.keys(cart).length === 0) { alert('Cart is empty!'); return false; }
  const method = document.getElementById('paymentMethod').value;
  const c = getSelectedCustomer();
  if (method === 'credit' && !c.id) {
    alert(CREDIT_MSG);
    return false;
  }
  const cartItems = Object.values(cart).map(it => ({ medicine_id: it.medicine_id, qty: it.qty }));
  document.getElementById('cartJson').value = JSON.stringify(cartItems);
  document.getElementById('discountHidden').value = document.getElementById('discountInput').value;
  return true;
}

function openRegisterModal() {
  document.getElementById('regError').style.display = 'none';
  document.getElementById('regName').value = '';
  document.getElementById('regPhone').value = '';
  openModal('registerModal');
}

function submitRegister() {
  const name = document.getElementById('regName').value.trim();
  const phone = document.getElementById('regPhone').value.trim();
  if (!name || !phone) return;

  const fd = new FormData();
  fd.append('act', 'register');
  fd.append('full_name', name);
  fd.append('phone', phone);
  fd.append('ajax', '1');

  fetch('customers.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) {
        const el = document.getElementById('regError');
        el.textContent = data.error;
        el.style.display = '';
        return;
      }
      const sel = document.getElementById('customerSelect');
      const opt = document.createElement('option');
      opt.value = data.customer.id;
      opt.dataset.name = data.customer.full_name;
      opt.dataset.phone = data.customer.phone;
      opt.textContent = data.customer.full_name + ' — ' + data.customer.phone;
      opt.selected = true;
      sel.appendChild(opt);
      closeModal('registerModal');
      onCustomerChange();
    })
    .catch(() => alert('Registration failed. Please try again.'));
}

function filterMeds(q) {
  const term = q.toLowerCase();
  document.querySelectorAll('.med-card').forEach(el => {
    const name = el.dataset.name.toLowerCase();
    const generic = el.dataset.generic.toLowerCase();
    el.style.display = (!term || name.includes(term) || generic.includes(term)) ? '' : 'none';
  });
}

onCustomerChange();
onPaymentChange();
validateCheckout();
</script>

<?php renderFooter(); ?>
