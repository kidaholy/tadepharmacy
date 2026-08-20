<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';
require_once __DIR__ . '/customers_lib.php';
require_once __DIR__ . '/notifications_lib.php';

$pdo  = getDB();
$error = '';
$success = '';
$preselectCustomer = (int)($_GET['customer_id'] ?? 0);

$catalog    = fetchPosCatalog($pdo);
$posCategories = categoriesByProductType($pdo);
foreach ($posCategories as $type => $list) {
    $posCategories[$type] = categoryDetailsForType($list, $type);
}
$customers  = searchCustomers($pdo, '', 500);
$payMethods = posPaymentMethods();
$payButtons = posPaymentButtonLabels();
$taxDefault = (float)getSetting('tax_rate', '0');
$currency   = getSetting('currency', 'ETB');

$flash = flashGet();
if ($flash && $flash['type'] === 'success') $success = $flash['message'];
if (isset($_GET['done'])) $success = 'Sale completed successfully. The receipt is available from Sales History.';

// ── Handle checkout ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId   = (int)($_POST['customer_id'] ?? 0);
    $discountType = ($_POST['discount_type'] ?? 'amount') === 'percent' ? 'percent' : 'amount';
    $discountRaw  = max(0, (float)($_POST['discount'] ?? 0));
    $taxRate      = max(0, (float)($_POST['tax_rate'] ?? 0));
    $payment      = $_POST['payment_method'] ?? 'cash';
    $notes        = trim($_POST['notes'] ?? '');
    $creditNotes  = trim($_POST['credit_notes'] ?? '');
    $reference    = trim($_POST['payment_reference'] ?? '');
    $dueDate      = trim($_POST['due_date'] ?? '');
    $amountPaidIn = max(0, (float)($_POST['amount_paid'] ?? 0));
    $items        = json_decode($_POST['cart_json'] ?? '[]', true);

    if (!isset($payMethods[$payment])) {
        $error = 'Invalid payment method selected.';
    } elseif (empty($items) || !is_array($items)) {
        $error = 'Cart is empty. Please add at least one medicine.';
    } elseif ($payment === 'credit' && !$customerId) {
        $error = 'Credit sales require a registered customer. Select or register a customer before completing the sale.';
    } elseif ($payment === 'credit' && !$dueDate) {
        $error = 'Due date is required for credit sales.';
    } else {
        $customer = $customerId ? findCustomerById($pdo, $customerId) : null;
        if ($payment === 'credit' && !$customer) {
            $error = 'Credit sales require a registered customer. Select or register a customer before completing the sale.';
        } else {
            $pdo->beginTransaction();
            try {
                $lineItems = buildBatchLineItems($pdo, $items);
                $subtotal  = array_sum(array_column($lineItems, 'subtotal'));

                $discount = $discountRaw;
                if ($discountType === 'percent') {
                    $discount = round($subtotal * min($discountRaw, 100) / 100, 2);
                }
                if ($discount > $subtotal) $discount = $subtotal;
                $discounted = round($subtotal - $discount, 2);
                $tax        = round($discounted * min($taxRate, 100) / 100, 2);
                $net        = round($discounted + $tax, 2);

                if ($payment === 'credit') {
                    if ($customer['credit_limit'] > 0 && ((float)$customer['outstanding_balance'] + $net) > (float)$customer['credit_limit']) {
                        throw new RuntimeException('Credit limit exceeded for this customer.');
                    }
                    $paid = min($amountPaidIn, $net);
                } else {
                    $paid = $net;
                }

                $remaining = max(0, $net - $paid);
                $status    = computePaymentStatus($net, $paid, $payment);
                $saleType  = ($payment === 'credit') ? 'credit' : 'cash';

                $custName  = $customer ? $customer['full_name'] : 'Walk-in Customer';
                $custPhone = $customer ? $customer['phone'] : trim($_POST['customer_phone'] ?? '');

                $invoice   = generateInvoice();
                $cashierId = currentUser()['id'] ?? null;
                $allNotes  = trim($notes . ($creditNotes ? "\nCredit: $creditNotes" : ''));

                // Prorate the invoice discount & tax onto each line for receipt/details display.
                foreach ($lineItems as $k => $li) {
                    $share = $subtotal > 0 ? ($li['subtotal'] / $subtotal) : 0;
                    $lineDisc = round($discount * $share, 2);
                    $lineTax  = round(($li['subtotal'] - $lineDisc) * min($taxRate, 100) / 100, 2);
                    $lineItems[$k]['discount']   = $lineDisc;
                    $lineItems[$k]['tax']        = $lineTax;
                    $lineItems[$k]['line_total'] = round($li['subtotal'] - $lineDisc + $lineTax, 2);
                }

                $pdo->prepare("
                    INSERT INTO sales (
                        invoice_number, customer_id, customer_name, customer_phone,
                        total_amount, discount, tax, paid_amount, remaining_balance,
                        payment_method, payment_status, payment_reference,
                        notes, credit_notes, user_id, sale_type,
                        due_date, credit_due_date
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $invoice, $customerId ?: null, $custName, $custPhone,
                    $subtotal, $discount, $tax, $paid, $remaining,
                    $payment, $status, $reference ?: null,
                    $allNotes, $creditNotes ?: null, $cashierId, $saleType,
                    $payment === 'credit' ? $dueDate : null,
                    $payment === 'credit' ? $dueDate : null,
                ]);
                $saleId = (int)$pdo->lastInsertId();

                $insItem  = $pdo->prepare("INSERT INTO sale_items (sale_id, medicine_id, batch_id, quantity, unit_price, discount, tax, subtotal) VALUES (?,?,?,?,?,?,?,?)");
                $updBatch = $pdo->prepare("UPDATE batches SET quantity = quantity - ? WHERE id = ?");
                foreach ($lineItems as $li) {
                    $insItem->execute([
                        $saleId, $li['medicine_id'], $li['batch_id'], $li['qty'],
                        $li['price'], $li['discount'], $li['tax'], $li['subtotal'],
                    ]);
                    $updBatch->execute([$li['qty'], $li['batch_id']]);
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

                // After the sale, either show the receipt preview (optionally auto-print)
                // or return straight to the New Sale screen per Receipt Settings.
                $showPreview = getSetting('receipt_show_preview', '1') === '1';
                $autoPrint   = getSetting('receipt_auto_print', '1') === '1';
                $printAfter  = getSetting('receipt_print_after_sale', '0') === '1';
                if ($showPreview) {
                    header('Location: receipt.php?id=' . $saleId . '&autoprint=' . (($autoPrint && $printAfter) ? '1' : '0') . '&back=pos');
                } else {
                    flashSet('success', 'Sale completed — invoice ' . $invoice . ' saved. Receipt available from Sales History.');
                    header('Location: pos.php');
                }
                exit;
            } catch (Throwable $e) {
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

<?php if ($success): ?>
<div class="alert alert-success auto-hide" id="posSuccess"><i data-lucide="check-circle"></i><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger" id="posError"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="pos-layout">
  <div class="pos-left">
    <div class="pos-filters">
      <div class="pos-type-pills" id="posTypePills" role="tablist" aria-label="Product type">
        <button type="button" class="pill active" data-type="" onclick="setPosType('')"><i data-lucide="layout-grid"></i> All</button>
        <button type="button" class="pill" data-type="medicine" onclick="setPosType('medicine')"><i data-lucide="pill"></i> Medicine</button>
        <button type="button" class="pill" data-type="cosmetic" onclick="setPosType('cosmetic')"><i data-lucide="sparkles"></i> Cosmetics</button>
        <button type="button" class="pill" data-type="equipment" onclick="setPosType('equipment')"><i data-lucide="stethoscope"></i> Equipment</button>
      </div>
      <div class="cat-chips pos-cat-chips" id="posCatChips" hidden></div>
    </div>
    <div class="pos-search">
      <div class="pos-search-icon"><i data-lucide="search"></i></div>
      <input type="text" id="posSearch" placeholder="Search brand, generic, strength, batch or barcode…" oninput="renderGrid(this.value)">
      <div class="pos-search-hint" id="posSearchHint">Pick Medicine, Cosmetics, or Equipment — then a detail like Skincare if you need it.</div>
    </div>
    <div class="pos-items-grid" id="medGrid">
      <?php if (empty($catalog)): ?>
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
      <div class="pos-total-row"><span>Subtotal</span><span id="subtotalVal"><?= $currency ?> 0.00</span></div>
      <div class="pos-total-row">
        <span>Discount</span>
        <span class="pos-discount-inputs">
          <select id="discountType" onchange="renderCart()" style="width:74px;padding:4px 6px;font-size:12px;">
            <option value="amount">ETB</option>
            <option value="percent">%</option>
          </select>
          <input type="number" id="discountInput" value="0" min="0" step="0.01" style="width:84px;padding:4px 8px;font-size:13px;text-align:right;" oninput="renderCart()">
        </span>
      </div>
      <div class="pos-total-row"><span>Discounted Subtotal</span><span id="discountedVal"><?= $currency ?> 0.00</span></div>
      <div class="pos-total-row">
        <span>Tax <span style="color:var(--text-300);font-weight:400;">(%)</span></span>
        <span>
          <input type="number" id="taxRateInput" value="<?= htmlspecialchars(number_format($taxDefault, 0)) ?>" min="0" max="100" step="0.01" style="width:84px;padding:4px 8px;font-size:13px;text-align:right;" oninput="renderCart()">
          <span id="taxAmountVal" style="display:inline-block;min-width:70px;text-align:right;"><?= $currency ?> 0.00</span>
        </span>
      </div>
      <div class="pos-total-row grand"><span>TOTAL</span><span id="totalVal"><?= $currency ?> 0.00</span></div>
    </div>

    <div class="pos-checkout">
      <form id="checkoutForm" method="POST">
        <input type="hidden" name="cart_json" id="cartJson">
        <input type="hidden" name="discount" id="discountHidden">
        <input type="hidden" name="discount_type" id="discountTypeHidden" value="amount">
        <input type="hidden" name="tax_rate" id="taxRateHidden">
        <input type="hidden" name="payment_method" id="paymentMethodHidden" value="cash">

        <div class="form-group" style="margin-bottom:12px;">
          <label style="margin-bottom:6px;display:block;">Payment Method</label>
          <div class="pay-methods" id="payMethods">
            <?php foreach ($payButtons as $key => $label): ?>
            <button type="button" class="pay-method-btn<?= $key === 'cash' ? ' active' : '' ?>" data-method="<?= $key ?>" onclick="selectPayment(this)">
              <?= htmlspecialchars($label) ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Customer -->
        <div class="form-group" style="margin-bottom:10px;">
          <label style="margin-bottom:4px;display:flex;justify-content:space-between;align-items:center;">
            Customer
            <button type="button" class="btn btn-ghost btn-sm" onclick="openRegisterModal()" style="padding:2px 8px;font-size:11px;">+ Register Customer</button>
          </label>
          <input type="text" id="customerFilter" placeholder="Search customer by name or phone…" oninput="filterCustomers(this.value)" style="padding:7px 10px;font-size:12px;margin-bottom:6px;">
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

        <div id="refGroup" class="form-group" style="margin-bottom:10px;display:none;">
          <label style="margin-bottom:4px;">Transaction Reference <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
          <input type="text" name="payment_reference" placeholder="e.g. Telebirr ref #" style="padding:8px 12px;font-size:13px;">
        </div>

        <!-- Credit panel -->
        <div id="creditPanel" style="display:none;" class="credit-panel mb-10">
          <div class="credit-panel-title"><i data-lucide="credit-card"></i> Credit Sale</div>
          <div id="creditWarning" class="alert alert-warning" style="display:none;margin-bottom:10px;padding:10px;font-size:12px;">
            Credit sales require a registered customer. Select a customer or press <strong>+ Register Customer</strong> before completing the sale.
          </div>
          <div class="form-group" style="margin-bottom:8px;">
            <label>Customer Name <span style="color:var(--danger);">*</span></label>
            <input type="text" id="creditCustName" readonly style="padding:8px 12px;font-size:13px;background:var(--bg-700);">
          </div>
          <div class="form-group" style="margin-bottom:8px;">
            <label>Phone Number <span style="color:var(--danger);">*</span></label>
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
          <span id="paidDisplay" style="color:var(--accent2);font-weight:700;"><?= $currency ?> 0.00</span>
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
const CUR = <?= json_encode($currency) ?>;
const TAX_DEFAULT = <?= json_encode($taxDefault) ?>;
const CREDIT_MSG = 'Credit sales require a registered customer. Select or register a customer before completing the sale.';
const CATALOG = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE) ?>;
const POS_CATEGORIES = <?= json_encode($posCategories, JSON_UNESCAPED_UNICODE) ?>;
const POS_TYPE_LABELS = { medicine: 'Medicine', cosmetic: 'Cosmetics', equipment: 'Equipment' };

let posType = '';
let posCat = 0;

let cart = {};
let currentTotal = 0;

// ── Medicine search & grid ───────────────────────────────────
const medGroups = (() => {
  const groups = new Map();
  CATALOG.forEach(b => {
    if (!groups.has(b.medicine_id)) {
      groups.set(b.medicine_id, {
        medicine_id: b.medicine_id,
        name: b.name,
        generic_name: b.generic_name || '',
        strength: b.strength || '',
        dosage_form: b.dosage_form || '',
        unit: b.unit || '',
        product_type: b.product_type || 'medicine',
        category_id: Number(b.category_id) || 0,
        category_name: b.category_name || '',
        batches: [],
      });
    }
    groups.get(b.medicine_id).batches.push(b);
  });
  return [...groups.values()];
})();

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function expShort(d) {
  const m = String(d || '').match(/^(\d{4})-(\d{2})/);
  return m ? ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][parseInt(m[2], 10) - 1] + ' ' + m[1] : (d || '—');
}
function expSoon(d) {
  if (!d) return false;
  const t = new Date(d + 'T00:00:00').getTime();
  return !isNaN(t) && t - Date.now() < 45 * 86400000 && t >= Date.now() - 86400000;
}
function medMatches(b, term) {
  const hay = [
    b.name, b.generic_name, b.strength, b.dosage_form,
    b.batch_number, b.barcode, b.sku, b.unit, b.product_type, b.category_name,
  ].filter(v => v != null).join(' ').toLowerCase();
  return term.split(/\s+/).every(w => hay.includes(w));
}

function setPosType(type) {
  posType = type || '';
  posCat = 0;
  document.querySelectorAll('#posTypePills .pill').forEach(btn => {
    btn.classList.toggle('active', (btn.dataset.type || '') === posType);
  });
  renderPosCatChips();
  renderGrid(document.getElementById('posSearch').value);
}

function setPosCat(id) {
  posCat = Number(id) || 0;
  renderPosCatChips();
  renderGrid(document.getElementById('posSearch').value);
}

function renderPosCatChips() {
  const wrap = document.getElementById('posCatChips');
  const cats = POS_CATEGORIES[posType] || [];
  if (!posType || !cats.length) {
    wrap.hidden = true;
    wrap.innerHTML = '';
    return;
  }
  const allLabel = posType === 'cosmetic' ? 'All Cosmetics' : (posType === 'equipment' ? 'All Equipment' : 'All Medicines');
  let html = `<button type="button" class="cat-chip${posCat ? '' : ' active'}" onclick="setPosCat(0)">${esc(allLabel)}</button>`;
  cats.forEach(c => {
    const id = Number(c.id);
    html += `<button type="button" class="cat-chip${posCat === id ? ' active' : ''}" onclick="setPosCat(${id})">${esc(c.name)}</button>`;
  });
  wrap.innerHTML = html;
  wrap.hidden = false;
}

function renderGrid(q) {
  const grid = document.getElementById('medGrid');
  const term = (q || '').trim().toLowerCase();
  const hint = document.getElementById('posSearchHint');
  const typeLabel = POS_TYPE_LABELS[posType] || '';
  if (hint) {
    if (posType && posCat) {
      const cat = (POS_CATEGORIES[posType] || []).find(c => Number(c.id) === posCat);
      hint.textContent = `Showing ${typeLabel}${cat ? ' · ' + cat.name : ''} · earliest expiry first`;
    } else if (posType) {
      hint.textContent = `Showing ${typeLabel} — pick a detail like Skincare to narrow the list.`;
    } else {
      hint.textContent = 'Pick Medicine, Cosmetics, or Equipment — then a detail like Skincare if you need it.';
    }
  }
  let html = '';
  let any = false;

  for (const g of medGroups) {
    if (posType && g.product_type !== posType) continue;
    if (posCat && g.category_id !== posCat) continue;
    const batches = g.batches.filter(b => !term || medMatches(b, term));
    if (!batches.length) continue;
    any = true;
    const stock = batches.reduce((s, b) => s + b.stock, 0);
    const sub = [g.generic_name, g.strength, g.dosage_form, g.category_name].filter(Boolean).join(' · ');
    html += `<div class="pos-med-group">
      <div class="pos-med-header">
        <div class="pos-med-head-main">
          <span class="pos-med-name">${esc(g.name)}</span>
          ${sub ? `<span class="pos-med-sub">${esc(sub)}</span>` : ''}
        </div>
        <span class="pos-med-stock">${stock} ${esc(g.unit)}</span>
      </div>`;
    batches.forEach(b => {
      const soon = expSoon(b.expiry_date);
      html += `<div class="pos-batch-row" data-batch-id="${b.batch_id}" onclick="addToCart(this)">
        <div class="pos-batch-info">
          <span class="pos-batch-num">Batch ${esc(b.batch_number)}</span>
          <span class="pos-batch-exp">Exp ${expShort(b.expiry_date)}${soon ? ' <span class="badge badge-orange" style="font-size:9px;padding:1px 5px;">Soon</span>' : ''}</span>
          <span class="pos-batch-stock">${b.stock} ${esc(g.unit)}</span>
        </div>
        <div class="pos-batch-price">${CUR} ${Number(b.selling_price).toFixed(2)}</div>
      </div>`;
    });
    html += '</div>';
  }

  const emptyLabel = posType ? (POS_TYPE_LABELS[posType] || 'products').toLowerCase() : 'products';
  grid.innerHTML = any ? html : `<div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--text-300);">
    <p>No ${esc(emptyLabel)} match${term ? ' "' + esc(q) + '"' : ''}.</p></div>`;
  if (window.lucide) lucide.createIcons();
}

// ── Cart ─────────────────────────────────────────────────────
function addToCart(el) {
  const bid = el.dataset.batchId;
  const b = CATALOG.find(x => String(x.batch_id) === String(bid));
  if (!b) return;
  if (cart[bid]) {
    if (cart[bid].qty < b.stock) cart[bid].qty++;
    else { flashMsg('Maximum stock reached for this batch.'); return; }
  } else {
    cart[bid] = {
      batch_id: b.batch_id, medicine_id: b.medicine_id,
      name: b.name, generic: b.generic_name || '', strength: b.strength || '',
      dosage_form: b.dosage_form || '', unit: b.unit || '',
      batch_number: b.batch_number, expiry_date: b.expiry_date,
      price: Number(b.selling_price), qty: 1, stock: b.stock,
    };
  }
  renderCart();
}

function adjustQty(bid, delta) {
  if (!cart[bid]) return;
  cart[bid].qty += delta;
  if (cart[bid].qty <= 0) delete cart[bid];
  else if (cart[bid].qty > cart[bid].stock) cart[bid].qty = cart[bid].stock;
  renderCart();
}

function removeItem(bid) { delete cart[bid]; renderCart(); }

function flashMsg(msg) {
  let el = document.getElementById('posCartMsg');
  if (!el) {
    el = document.createElement('div');
    el.id = 'posCartMsg';
    el.className = 'alert alert-warning';
    el.style.cssText = 'margin:0 10px 6px;padding:8px 12px;font-size:12px;';
    document.getElementById('cartItems').parentElement.insertBefore(el, document.getElementById('cartItems'));
  }
  el.textContent = msg;
  clearTimeout(el._t);
  el._t = setTimeout(() => { if (el) el.remove(); }, 2500);
}

function discountAmount(sub) {
  const type = document.getElementById('discountType').value;
  const raw = parseFloat(document.getElementById('discountInput').value) || 0;
  if (type === 'percent') return Math.min(sub, sub * Math.min(raw, 100) / 100);
  return Math.min(sub, raw);
}
function taxRate() {
  return Math.min(100, Math.max(0, parseFloat(document.getElementById('taxRateInput').value) || 0));
}

function renderCart() {
  const container = document.getElementById('cartItems');
  const items = Object.values(cart);
  document.getElementById('cartCount').textContent = `(${items.length} item${items.length !== 1 ? 's' : ''})`;

  if (items.length === 0) {
    container.innerHTML = '<div style="text-align:center;padding:40px 20px;color:var(--text-300);font-size:13px;">Click medicines to add to cart</div>';
    updateTotals(items);
    return;
  }

  const sub = items.reduce((s, it) => s + it.price * it.qty, 0);
  const disc = discountAmount(sub);
  const discounted = Math.max(0, sub - disc);
  const tax = discounted * taxRate() / 100;
  const total = Math.max(0, discounted + tax);
  currentTotal = total;

  container.innerHTML = items.map(it => {
    const lineSub = it.price * it.qty;
    const share = sub > 0 ? lineSub / sub : 0;
    const lineDisc = disc * share;
    const lineTax = (lineSub - lineDisc) * taxRate() / 100;
    const lineTotal = lineSub - lineDisc + lineTax;
    const subLine = [it.generic, it.strength, it.dosage_form].filter(Boolean).join(' · ');
    return `<div class="cart-item">
      <div style="flex:1;min-width:0;">
        <div class="cart-item-name">${esc(it.name)}</div>
        ${subLine ? `<div class="cart-item-sub">${esc(subLine)}</div>` : ''}
        <div class="cart-item-meta">Batch ${esc(it.batch_number)} · Exp ${expShort(it.expiry_date)}</div>
        <div class="cart-item-price">Unit ${CUR} ${it.price.toFixed(2)}</div>
        <div class="cart-item-line">
          <span>Disc ${CUR} ${lineDisc.toFixed(2)}</span>
          <span>Tax ${CUR} ${lineTax.toFixed(2)}</span>
          <span class="cart-item-total">${CUR} ${lineTotal.toFixed(2)}</span>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
        <div class="qty-ctrl">
          <button type="button" class="qty-btn" onclick="adjustQty(${it.batch_id},-1)">−</button>
          <span class="qty-val">${it.qty}</span>
          <button type="button" class="qty-btn" onclick="adjustQty(${it.batch_id},1)">+</button>
        </div>
        <button type="button" class="cart-item-remove" onclick="removeItem(${it.batch_id})" title="Remove">×</button>
      </div>
    </div>`;
  }).join('');

  updateTotals(items);
}

function updateTotals(items) {
  const sub = items.reduce((s, it) => s + it.price * it.qty, 0);
  const disc = discountAmount(sub);
  const discounted = Math.max(0, sub - disc);
  const tax = discounted * taxRate() / 100;
  const total = Math.max(0, discounted + tax);
  currentTotal = total;

  document.getElementById('subtotalVal').textContent   = `${CUR} ${sub.toFixed(2)}`;
  document.getElementById('discountedVal').textContent = `${CUR} ${discounted.toFixed(2)}`;
  document.getElementById('taxAmountVal').textContent  = `${CUR} ${tax.toFixed(2)}`;
  document.getElementById('totalVal').textContent      = `${CUR} ${total.toFixed(2)}`;
  document.getElementById('paidDisplay').textContent   = `${CUR} ${total.toFixed(2)}`;
  document.getElementById('creditInvoiceTotal').value  = `${CUR} ${total.toFixed(2)}`;
  updateCreditBalance();
  validateCheckout();
}

// ── Payment method buttons ───────────────────────────────────
function getPaymentMethod() {
  return document.getElementById('paymentMethodHidden').value;
}
function selectPayment(btn) {
  document.querySelectorAll('.pay-method-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('paymentMethodHidden').value = btn.dataset.method;
  onPaymentChange();
}

function onPaymentChange() {
  const method = getPaymentMethod();
  const isCredit = method === 'credit';
  const isDigital = ['telebirr', 'cbe', 'abyssinia'].includes(method);
  document.getElementById('creditPanel').style.display = isCredit ? '' : 'none';
  document.getElementById('refGroup').style.display = isDigital ? '' : 'none';
  document.getElementById('dueDate').required = isCredit;
  onCustomerChange();
  renderCart();
}

// ── Customer ─────────────────────────────────────────────────
function getSelectedCustomer() {
  const sel = document.getElementById('customerSelect');
  const opt = sel.options[sel.selectedIndex];
  return { id: parseInt(sel.value) || 0, name: opt.dataset.name || '', phone: opt.dataset.phone || '' };
}

function filterCustomers(q) {
  const term = q.trim().toLowerCase();
  const sel = document.getElementById('customerSelect');
  for (const opt of sel.options) {
    if (opt.value === '0') { opt.style.display = ''; continue; }
    const text = (opt.textContent || '').toLowerCase();
    opt.style.display = (!term || text.includes(term)) ? '' : 'none';
  }
}

function onCustomerChange() {
  const c = getSelectedCustomer();
  document.getElementById('walkinPhone').style.display = c.id ? 'none' : '';
  document.getElementById('creditCustName').value  = c.name;
  document.getElementById('creditCustPhone').value = c.phone;
  validateCheckout();
}

function updateCreditBalance() {
  const paid = parseFloat(document.getElementById('amountPaid').value) || 0;
  const remaining = Math.max(0, currentTotal - paid);
  document.getElementById('creditRemaining').value = `${CUR} ${remaining.toFixed(2)}`;
}

function validateCheckout() {
  const method = getPaymentMethod();
  const c = getSelectedCustomer();
  const btn = document.getElementById('checkoutBtn');
  const warn = document.getElementById('creditWarning');
  const cartEmpty = Object.keys(cart).length === 0;

  let blocked = cartEmpty;
  if (method === 'credit') {
    if (!c.id) {
      blocked = true;
      warn.style.display = '';
    } else {
      warn.style.display = 'none';
    }
  } else {
    warn.style.display = 'none';
  }

  btn.disabled = blocked;
  btn.style.opacity = blocked ? '0.5' : '1';
  btn.title = (method === 'credit' && !c.id) ? CREDIT_MSG : '';
}

function prepareCheckout() {
  if (Object.keys(cart).length === 0) { alert('Cart is empty!'); return false; }
  const method = getPaymentMethod();
  const c = getSelectedCustomer();
  if (method === 'credit' && !c.id) {
    alert(CREDIT_MSG);
    return false;
  }
  const cartItems = Object.values(cart).map(it => ({ batch_id: it.batch_id, qty: it.qty }));
  document.getElementById('cartJson').value = JSON.stringify(cartItems);
  document.getElementById('discountHidden').value = document.getElementById('discountInput').value;
  document.getElementById('discountTypeHidden').value = document.getElementById('discountType').value;
  document.getElementById('taxRateHidden').value = document.getElementById('taxRateInput').value;
  return true;
}

// ── Register customer ────────────────────────────────────────
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

if (CATALOG.length) renderGrid('');
onCustomerChange();
onPaymentChange();
renderCart();
</script>

<?php renderFooter(); ?>
