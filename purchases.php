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
        if ($act === 'quick_product') {
            if (!can('purchases.manage')) {
                throw new RuntimeException('You do not have permission to create products.');
            }
            header('Content-Type: application/json');
            try {
                $product = quickCreateProduct($pdo, $_POST);
                echo json_encode(['ok' => true, 'product' => $product], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
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
$medicines = $pdo->query("
    SELECT m.id, m.name, m.generic_name, m.unit, m.sku, m.barcode,
           COALESCE(m.product_type,'medicine') AS product_type,
           c.name AS category_name
    FROM medicines m
    LEFT JOIN categories c ON c.id = m.category_id
    ORDER BY m.name COLLATE NOCASE
")->fetchAll();
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
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
        <label>Supplier <span style="color:var(--text-300);font-weight:400;">(optional)</span></label>
        <div style="display:flex;gap:8px;">
          <select name="supplier_id" id="supplierSelect" style="flex:1;">
            <option value="">No supplier / Walk-in</option>
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
    </div>
    <p class="purchase-flow-hint">Pick the product type → search &amp; select the product → enter batch &amp; prices → press <strong>＋ ADD PRODUCT</strong> to move to the next item. The dispensing unit comes from the product master — no unit conversion needed.</p>
    <div id="purchaseValidateMsg" class="alert alert-danger" hidden style="margin:0 0 14px;"></div>
    <div id="itemsBody" class="purchase-items">
      <div class="purchase-item item-row" data-type="medicine">
        <div class="purchase-card-head" onclick="toggleCard(this)" role="button" title="Collapse / expand">
          <button type="button" class="purchase-card-toggle" onclick="event.stopPropagation(); toggleCard(this)" tabindex="-1"><i data-lucide="chevron-down"></i></button>
          <div class="purchase-card-title">
            <span class="card-index">1.</span>
            <span class="card-name">Select Product…</span>
          </div>
          <div class="purchase-card-actions" onclick="event.stopPropagation()">
            <button type="button" class="btn btn-ghost btn-sm" onclick="addBatchOfSame(this)">+ Same product, new batch</button>
            <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">Remove</button>
          </div>
        </div>
        <div class="purchase-card-summary" hidden></div>
        <div class="purchase-card-body">
          <div class="purchase-type-pills">
            <button type="button" class="pill active" data-type="medicine" onclick="setRowType(this)"><i data-lucide="pill"></i> Medication</button>
            <button type="button" class="pill" data-type="cosmetic" onclick="setRowType(this)"><i data-lucide="sparkles"></i> Cosmetics</button>
            <button type="button" class="pill" data-type="equipment" onclick="setRowType(this)"><i data-lucide="stethoscope"></i> Equipment</button>
          </div>

          <div class="purchase-item-section">
            <div class="purchase-section-label">1. Product</div>
            <div class="form-group pg-wide">
              <label>Search product (brand, generic, SKU, barcode)</label>
              <div class="product-picker">
                <input type="hidden" name="medicine_id[]" class="product-id" value="">
                <input type="hidden" name="product_code[]" class="product-code">
                <input type="text" class="product-search" placeholder="Search brand, generic, SKU, or barcode…" autocomplete="off"
                       onfocus="openProductPicker(this)" oninput="filterProductPicker(this)" onkeydown="productPickerKey(event)">
                <button type="button" class="product-scan" onclick="this.parentElement.querySelector('.product-search').focus()" title="Scan barcode (focus for scanner)"><i data-lucide="scan-line"></i></button>
                <div class="product-picker-list" hidden></div>
              </div>
            </div>
            <div class="purchase-med-info">
              <div class="form-group">
                <label class="lbl-brand">Brand Name</label>
                <input type="text" class="med-brand" readonly tabindex="-1" placeholder="—">
              </div>
              <div class="form-group">
                <label class="lbl-generic">Generic Name</label>
                <input type="text" class="med-generic" readonly tabindex="-1" placeholder="—">
              </div>
              <div class="form-group">
                <label>Dispensing Unit</label>
                <input type="text" class="med-unit" readonly tabindex="-1" placeholder="—">
              </div>
              <div class="form-group">
                <label class="lbl-category">Category</label>
                <input type="text" class="med-category" readonly tabindex="-1" placeholder="—">
              </div>
              <div class="form-group">
                <label>SKU</label>
                <input type="text" class="med-sku" readonly tabindex="-1" placeholder="—">
              </div>
              <div class="form-group">
                <label>Barcode</label>
                <input type="text" class="med-barcode" readonly tabindex="-1" placeholder="—">
              </div>
            </div>
          </div>

          <div class="purchase-item-section">
            <div class="purchase-section-label batch-section-label">2. Batch Information</div>
            <div class="purchase-batch-fields">
              <div class="form-group f-variant">
                <label>Variant <span style="color:var(--text-300);font-weight:400;">(shade / color / size / volume)</span></label>
                <input type="text" name="variant[]" class="variant-input" placeholder="e.g. Shade 20, 50 ml">
              </div>
              <div class="form-group f-batch">
                <label>Batch Number</label>
                <input type="text" name="batch_number[]" class="batch-number" placeholder="Batch #">
              </div>
              <div class="form-group f-mfg">
                <label>Manufacturing Date</label>
                <input type="date" name="manufacturing_date[]">
              </div>
              <div class="form-group f-expiry">
                <label>Expiry Date <span class="expiry-hint" style="color:var(--text-300);font-weight:400;"></span></label>
                <input type="date" name="expiry_date[]" class="expiry-input" onchange="warnExpiry(this)">
              </div>
              <div class="form-group f-model">
                <label>Model Number</label>
                <input type="text" name="model_number[]" class="model-input" placeholder="e.g. DT-200">
              </div>
              <div class="form-group f-serial">
                <label>Serial Number <span style="color:var(--text-300);font-weight:400;">(if applicable)</span></label>
                <input type="text" name="serial_number[]" class="serial-input" placeholder="Serial #">
              </div>
              <div class="form-group f-warranty-period">
                <label>Warranty Period <span style="color:var(--text-300);font-weight:400;">(if applicable)</span></label>
                <input type="text" name="warranty_period[]" class="warranty-period-input" placeholder="e.g. 1 year">
              </div>
              <div class="form-group f-warranty-expiry">
                <label>Warranty Expiry <span style="color:var(--text-300);font-weight:400;">(if applicable)</span></label>
                <input type="date" name="warranty_expiry[]" class="warranty-expiry-input">
              </div>
            </div>
          </div>

          <div class="purchase-item-section">
            <div class="purchase-section-label">3. Quantity &amp; Pricing</div>
            <div class="purchase-qty-fields">
              <div class="form-group">
                <label>Quantity</label>
                <input type="number" name="quantity[]" class="qty-paid" min="0" value="1" oninput="recalc()">
              </div>
              <div class="form-group">
                <label>Purchase Price</label>
                <input type="number" name="purchase_price[]" min="0" step="0.01" value="0" oninput="recalc()">
              </div>
              <div class="form-group">
                <label>Selling Price <span style="color:var(--text-300);font-weight:400;">(batch)</span></label>
                <input type="number" name="selling_price[]" min="0" step="0.01" value="0" oninput="recalc()">
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
                <label>Item Total</label>
                <div class="purchase-item-total line-total">0.00</div>
              </div>
            </div>
            <p class="purchase-line-note">Item total = quantity × purchase price. Stock received equals the entered quantity. Selling price is stored on this batch.</p>
          </div>
        </div>
        <div class="purchase-add-footer">
          <button type="button" class="btn btn-success btn-add-product" onclick="addProductFromCard(this)">＋ ADD PRODUCT</button>
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
          <label>Invoice Discount</label>
          <input type="number" name="header_discount" id="headerDiscount" min="0" step="0.01" value="0" oninput="recalc()">
        </div>
        <div class="form-group">
          <label>Invoice Tax</label>
          <input type="number" name="header_tax" id="headerTax" min="0" step="0.01" value="0" oninput="recalc()">
        </div>
      </div>
      <div class="report-summary-grid purchase-summary-grid">
        <div><span class="report-k">Subtotal</span><span class="report-v" id="sumSub">0.00</span></div>
        <div><span class="report-k">Item Discounts</span><span class="report-v" id="sumItemDisc">0.00</span></div>
        <div><span class="report-k">Invoice Discount</span><span class="report-v" id="sumInvDisc">0.00</span></div>
        <div><span class="report-k">Tax</span><span class="report-v" id="sumTax">0.00</span></div>
        <div><span class="report-k">Grand Total</span><span class="report-v" id="sumGrand">0.00</span></div>
        <div><span class="report-k">Amount Paid</span><span class="report-v" id="sumPaid">0.00</span></div>
        <div><span class="report-k">Supplier Credit / Balance Due</span><span class="report-v" id="sumDue" style="color:var(--warning);">0.00</span></div>
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

<div class="modal-overlay" id="newProductModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header"><h2>New <span id="npType">Medication</span></h2><button class="modal-close" onclick="closeModal('newProductModal')"><i data-lucide="x"></i></button></div>
    <div class="modal-body">
      <form id="npForm" onsubmit="submitNewProduct(event)">
        <div id="npError" class="alert alert-danger" hidden style="margin:0 0 12px;"></div>
        <div class="form-group">
          <label>Product Name *</label>
          <input type="text" name="name" required placeholder="e.g. Nivea Cream 50ml">
        </div>
        <div class="form-group">
          <label>Category</label>
          <select name="category_id">
            <option value="">— Select Category —</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Dispensing Unit</label>
            <input type="text" name="unit" list="npUnits" placeholder="pcs">
            <datalist id="npUnits">
              <?php foreach (productUnits() as $u): ?>
              <option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="form-group">
            <label>SKU</label>
            <input type="text" name="sku" placeholder="Optional">
          </div>
          <div class="form-group">
            <label>Barcode</label>
            <input type="text" name="barcode" placeholder="Optional">
          </div>
        </div>
        <p style="font-size:12px;color:var(--text-300);margin:0 0 4px;">The product is added to the master catalogue and selected on this purchase automatically. It will not be duplicated for different batches or prices.</p>
        <div class="form-actions" style="margin-top:16px;padding-top:16px;">
          <button type="submit" class="btn btn-primary">Create &amp; Select</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('newProductModal')">Close</button>
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
    $type = $m['product_type'] ?? 'medicine';
    return [
        'id' => (int)$m['id'],
        'name' => $m['name'],
        'generic' => $m['generic_name'] ?? '',
        'unit' => $m['unit'] ?? '',
        'sku' => $m['sku'] ?? '',
        'barcode' => $m['barcode'] ?? '',
        'category' => $m['category_name'] ?? '',
        'type' => $type,
        'requiresExpiry' => productRequiresExpiry($type) ? 1 : 0,
    ];
}, $medicines), JSON_UNESCAPED_UNICODE) ?>;
const taxDefault = <?= json_encode((string)$taxDefault) ?>;
const currencyLabel = <?= json_encode(getSetting('currency', 'ETB')) ?>;
const typeMeta = {
  medicine:  { label: 'Medication', requiresExpiry: true,  placeholder: 'Search medication by brand, generic, SKU, or barcode…' },
  cosmetic:  { label: 'Cosmetics',  requiresExpiry: false, placeholder: 'Search cosmetics by name, brand, SKU, or barcode…' },
  equipment: { label: 'Equipment',  requiresExpiry: false, placeholder: 'Search equipment by name, brand, SKU, or barcode…' },
};

function rowType(row) { return row.dataset.type || 'medicine'; }
function selectedProduct(row) {
  const id = parseInt(row.querySelector('.product-id').value, 10) || 0;
  return productCatalog.find(x => x.id === id) || null;
}
function fmtMoney(v) { return currencyLabel + ' ' + Number(v || 0).toFixed(2); }
function formatExpiryShort(d) {
  const m = String(d || '').match(/^(\d{4})-(\d{2})/);
  return m ? (m[2] + '/' + m[1]) : (d || '');
}
function todayStr() {
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function addDaysTo(dateStr, days) {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

function fillMedInfo(row, p) {
  row.querySelector('.med-brand').value = p ? (p.name || '') : '';
  row.querySelector('.med-generic').value = p ? (p.generic || '') : '';
  row.querySelector('.med-unit').value = p ? (p.unit || '') : '';
  row.querySelector('.med-category').value = p ? (p.category || '') : '';
  row.querySelector('.med-sku').value = p ? (p.sku || '') : '';
  row.querySelector('.med-barcode').value = p ? (p.barcode || '') : '';
}

function updateCardTitle(row) {
  const p = selectedProduct(row);
  row.querySelector('.card-name').textContent = p ? p.name : 'Select Product…';
}

function syncLabels(row) {
  const t = rowType(row);
  const labels = {
    medicine:  { brand: 'Brand Name', generic: 'Generic Name', category: 'Medication Category', sec: '2. Batch Information' },
    cosmetic:  { brand: 'Product / Brand Name', generic: 'Manufacturer / Brand', category: 'Product Category', sec: '2. Batch & Variant' },
    equipment: { brand: 'Equipment Name', generic: 'Brand', category: 'Equipment Category', sec: '2. Unit Details' },
  }[t] || {};
  const set = (sel, v) => { const el = row.querySelector(sel); if (el) el.textContent = v; };
  set('.lbl-brand', labels.brand);
  set('.lbl-generic', labels.generic);
  set('.lbl-category', labels.category);
  set('.batch-section-label', labels.sec);
  const search = row.querySelector('.product-search');
  if (search) search.placeholder = typeMeta[t].placeholder;
}

function syncTypeFields(row) {
  const t = rowType(row);
  const show = {
    variant: t === 'cosmetic',
    batch: t !== 'equipment',
    mfg: t !== 'equipment',
    expiry: t !== 'equipment',
    model: t === 'equipment',
    serial: t === 'equipment',
    'warranty-period': t === 'equipment',
    'warranty-expiry': t === 'equipment',
  };
  row.querySelectorAll('.purchase-batch-fields > .form-group').forEach(g => {
    const key = [...g.classList].find(c => c.startsWith('f-'));
    if (key) g.style.display = show[key.slice(2)] ? '' : 'none';
  });
  const exp = row.querySelector('.expiry-input');
  if (exp) exp.required = typeMeta[t].requiresExpiry;
  warnExpiry(exp);
}

function syncTypePills(row) {
  const t = rowType(row);
  row.querySelectorAll('.purchase-type-pills .pill').forEach(b => {
    b.classList.toggle('active', b.dataset.type === t);
  });
}

function setRowType(btn) {
  const row = btn.closest('.item-row');
  const t = btn.dataset.type;
  if (rowType(row) === t) return;
  row.dataset.type = t;
  row.querySelector('.product-id').value = '';
  row.querySelector('.product-code').value = '';
  fillMedInfo(row, null);
  updateCardTitle(row);
  syncTypePills(row);
  syncLabels(row);
  syncTypeFields(row);
  const search = row.querySelector('.product-search');
  if (search) filterProductPicker(search);
  recalc();
}

function warnExpiry(input) {
  if (!input) return;
  const row = input.closest('.item-row');
  const hint = row.querySelector('.expiry-hint');
  input.classList.remove('expiry-bad', 'expiry-warn');
  const required = typeMeta[rowType(row)].requiresExpiry;
  if (hint) { hint.textContent = required ? '*' : '(optional)'; hint.classList.remove('expiry-warn-text', 'expiry-bad-text'); }
  const val = input.value;
  if (!val) return;
  const purchaseDate = document.getElementById('purchaseDate').value || todayStr();
  const today = todayStr();
  if (val < purchaseDate) {
    input.classList.add('expiry-bad');
    if (hint) { hint.textContent = 'Cannot be before purchase date'; hint.classList.add('expiry-bad-text'); }
  } else if (val < today) {
    input.classList.add('expiry-warn');
    if (hint) { hint.textContent = 'Already expired — verify with supplier'; hint.classList.add('expiry-warn-text'); }
  } else if (val <= addDaysTo(today, 90)) {
    input.classList.add('expiry-warn');
    if (hint) { hint.textContent = 'Expires soon (within 90 days)'; hint.classList.add('expiry-warn-text'); }
  }
}

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
  const row = picker.closest('.item-row');
  const t = rowType(row);
  const q = (input.value || '').trim().toLowerCase();
  if (q) {
    picker.querySelector('.product-id').value = '';
    fillMedInfo(row, null);
  }
  let matches = productCatalog.filter(p => {
    if (p.type !== t) return false;
    if (!q) return true;
    return (p.name || '').toLowerCase().includes(q)
      || (p.generic || '').toLowerCase().includes(q)
      || (p.sku || '').toLowerCase().includes(q)
      || (p.barcode || '').toLowerCase().includes(q)
      || (p.category || '').toLowerCase().includes(q);
  });
  if (q) {
    const rank = p => {
      const n = (p.name || '').toLowerCase();
      const b = (p.barcode || '').toLowerCase();
      const s = (p.sku || '').toLowerCase();
      if (q === b || q === s || q === n) return 0;
      if (n.startsWith(q) || b.startsWith(q) || s.startsWith(q)) return 1;
      return 2;
    };
    matches.sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name));
  }
  matches = matches.slice(0, 40);
  const typeLabel = typeMeta[t].label;
  const newBtn = `<div class="product-picker-new"><button type="button" onclick="openNewProductModal('${t}', this)">＋ New ${typeLabel}</button></div>`;
  if (!matches.length) {
    list.innerHTML = `<div class="product-picker-empty">No matching ${typeLabel.toLowerCase()}. Create it now:</div>` + newBtn;
    list.hidden = false;
    return;
  }
  list.innerHTML = matches.map((p, i) =>
    `<button type="button" class="product-picker-item${i === 0 ? ' active' : ''}" data-id="${p.id}">`
    + `${escapeHtml(p.name)}`
    + `<small>${escapeHtml([p.generic, p.unit, p.category, p.sku ? 'SKU ' + p.sku : '', p.barcode ? 'BAR ' + p.barcode : ''].filter(Boolean).join(' · '))}</small>`
    + `</button>`
  ).join('') + newBtn;
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
  row.dataset.type = p.type;
  syncTypePills(row);
  syncLabels(row);
  syncTypeFields(row);
  fillMedInfo(row, p);
  updateCardTitle(row);
  const t = rowType(row);
  if (t === 'medicine') {
    const b = row.querySelector('.batch-number');
    if (b) b.focus();
  } else if (t === 'cosmetic') {
    const v = row.querySelector('.variant-input');
    if (v) v.focus();
  } else {
    const m = row.querySelector('.model-input');
    if (m) m.focus();
  }
}
function productPickerKey(e) {
  const picker = pickerFrom(e.target);
  const list = picker.querySelector('.product-picker-list');
  if (e.key === 'Escape') { list.hidden = true; return; }
  const items = [...list.querySelectorAll('.product-picker-item')];
  if (e.key === 'Enter') {
    e.preventDefault();
    const idx = items.findIndex(el => el.classList.contains('active'));
    const active = items[idx] || items[0];
    if (active) selectProduct(picker, parseInt(active.dataset.id, 10));
    return;
  }
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
  }
}
function escapeHtml(s) {
  return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
document.addEventListener('click', e => {
  if (!e.target.closest('.product-picker')) hideAllPickers();
});

let newProductType = 'medicine';
let newProductRow = null;
function openNewProductModal(type, btn) {
  newProductType = type;
  newProductRow = btn ? btn.closest('.item-row') : null;
  document.getElementById('npType').textContent = typeMeta[type].label;
  document.getElementById('npForm').reset();
  const err = document.getElementById('npError');
  err.hidden = true;
  err.textContent = '';
  openModal('newProductModal');
}
function submitNewProduct(e) {
  e.preventDefault();
  const err = document.getElementById('npError');
  err.hidden = true;
  err.textContent = '';
  const fd = new FormData(document.getElementById('npForm'));
  fd.set('act', 'quick_product');
  fd.set('product_type', newProductType);
  fetch('purchases.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (!res.ok) { err.textContent = res.error || 'Could not create the product.'; err.hidden = false; return; }
      const p = res.product;
      const entry = {
        id: p.id,
        name: p.name,
        generic: p.generic_name || '',
        unit: p.unit || '',
        sku: p.sku || '',
        barcode: p.barcode || '',
        category: p.category_name || '',
        type: p.product_type || newProductType,
        requiresExpiry: (p.product_type === 'medicine') ? 1 : 0,
      };
      productCatalog.push(entry);
      closeModal('newProductModal');
      if (newProductRow) selectProduct(pickerFrom(newProductRow.querySelector('.product-search')), entry.id);
    })
    .catch(() => { err.textContent = 'Could not save. Check your connection and try again.'; err.hidden = false; });
}

function lineAmounts(row) {
  const qty = parseFloat(row.querySelector('[name="quantity[]"]').value) || 0;
  const price = parseFloat(row.querySelector('[name="purchase_price[]"]').value) || 0;
  const disc = parseFloat(row.querySelector('[name="line_discount[]"]').value) || 0;
  const tax = parseFloat(row.querySelector('[name="line_tax[]"]').value) || 0;
  const gross = qty * price;
  const discountAmt = gross * disc / 100;
  const net = gross - discountAmt;
  const taxAmt = net * tax / 100;
  return { gross, discountAmt, taxAmt, total: net + taxAmt, qty, price };
}
function recalc() {
  let subGross = 0;
  let itemDisc = 0;
  let itemTax = 0;
  let subNet = 0;
  document.querySelectorAll('.item-row').forEach(row => {
    const a = lineAmounts(row);
    subGross += a.gross;
    itemDisc += a.discountAmt;
    itemTax += a.taxAmt;
    subNet += a.total;
    row.querySelector('.line-total').textContent = a.total.toFixed(2);
    const summary = row.querySelector('.purchase-card-summary');
    if (summary && !summary.hidden) summary.innerHTML = buildCardSummary(row);
  });
  const invDisc = parseFloat(document.getElementById('headerDiscount').value) || 0;
  const invTax = parseFloat(document.getElementById('headerTax').value) || 0;
  const grand = Math.max(0, subNet - invDisc + invTax);
  document.getElementById('sumSub').textContent = subGross.toFixed(2);
  document.getElementById('sumItemDisc').textContent = itemDisc.toFixed(2);
  document.getElementById('sumInvDisc').textContent = invDisc.toFixed(2);
  document.getElementById('sumTax').textContent = (itemTax + invTax).toFixed(2);
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

function resetRowFields(row, opts = {}) {
  row.querySelectorAll('input').forEach(i => {
    if (i.classList.contains('med-brand') || i.classList.contains('med-generic')
        || i.classList.contains('med-unit') || i.classList.contains('med-category')
        || i.classList.contains('med-sku') || i.classList.contains('med-barcode')) {
      if (!opts.keepMedicine) i.value = '';
      return;
    }
    if (i.type === 'number') {
      i.value = (i.name.indexOf('quantity') !== -1) ? '1' : '0';
    } else if (i.type !== 'hidden') {
      if (opts.keepMedicine && i.classList.contains('product-search')) return;
      i.value = '';
    } else if (i.classList.contains('product-id') || i.classList.contains('product-code')) {
      if (!opts.keepMedicine) i.value = '';
    }
  });
  const tax = row.querySelector('[name="line_tax[]"]');
  if (tax) tax.value = taxDefault;
  const list = row.querySelector('.product-picker-list');
  if (list) { list.hidden = true; list.innerHTML = ''; }
  const summary = row.querySelector('.purchase-card-summary');
  if (summary) { summary.hidden = true; summary.innerHTML = ''; }
  const total = row.querySelector('.line-total');
  if (total) total.textContent = '0.00';
  if (!opts.keepMedicine) {
    fillMedInfo(row, null);
    syncLabels(row);
    syncTypeFields(row);
    updateCardTitle(row);
  }
}

function buildCardSummary(row) {
  const p = selectedProduct(row);
  const t = rowType(row);
  const val = sel => { const el = row.querySelector(sel); return el ? (el.value || '').trim() : ''; };
  const parts = [];
  if (t !== 'equipment') {
    const batch = val('[name="batch_number[]"]');
    if (batch) parts.push('Batch: ' + escapeHtml(batch));
  } else {
    const serial = val('[name="serial_number[]"]');
    if (serial) parts.push('Serial: ' + escapeHtml(serial));
  }
  const variant = val('[name="variant[]"]');
  if (variant) parts.push('Variant: ' + escapeHtml(variant));
  const exp = val('[name="expiry_date[]"]');
  if (exp && !String(exp).startsWith('9999')) parts.push('Exp: ' + formatExpiryShort(exp));
  const qty = parseFloat(val('[name="quantity[]"]')) || 0;
  parts.push('Qty: ' + qty);
  parts.push('Buy: ' + fmtMoney(parseFloat(val('[name="purchase_price[]"]')) || 0));
  parts.push('Sell: ' + fmtMoney(parseFloat(val('[name="selling_price[]"]')) || 0));
  parts.push('Total: ' + fmtMoney(lineAmounts(row).total));
  return `<div class="card-summary-line"><strong>${escapeHtml(p ? p.name : 'Select Product…')}</strong><span class="card-summary-meta">${parts.join(' · ')}</span></div>`;
}

function collapseCard(row) {
  if (row.classList.contains('collapsed')) return;
  row.classList.add('collapsed');
  const summary = row.querySelector('.purchase-card-summary');
  summary.hidden = false;
  summary.innerHTML = buildCardSummary(row);
  const icon = row.querySelector('.purchase-card-toggle i');
  if (icon) icon.setAttribute('data-lucide', 'chevron-right');
  if (window.lucide) lucide.createIcons();
}
function expandCard(row) {
  row.classList.remove('collapsed');
  const summary = row.querySelector('.purchase-card-summary');
  if (summary) summary.hidden = true;
  const icon = row.querySelector('.purchase-card-toggle i');
  if (icon) icon.setAttribute('data-lucide', 'chevron-down');
  if (window.lucide) lucide.createIcons();
}
function toggleCard(el) {
  const row = el.closest('.item-row');
  if (row.classList.contains('collapsed')) expandCard(row); else collapseCard(row);
}

function renumberCards() {
  document.querySelectorAll('#itemsBody .item-row').forEach((row, i) => {
    const idx = row.querySelector('.card-index');
    if (idx) idx.textContent = (i + 1) + '.';
  });
}

function newCard() {
  const tbody = document.getElementById('itemsBody');
  const row = tbody.querySelector('.item-row').cloneNode(true);
  resetRowFields(row);
  tbody.appendChild(row);
  return row;
}

function addProductFromCard(btn) {
  const src = btn.closest('.item-row');
  if (!selectedProduct(src)) {
    const search = src.querySelector('.product-search');
    if (search) search.focus();
    return;
  }
  collapseCard(src);
  const row = newCard();
  expandCard(row);
  renumberCards();
  row.scrollIntoView({ behavior: 'smooth', block: 'start' });
  const search = row.querySelector('.product-search');
  if (search) search.focus();
  if (window.lucide) lucide.createIcons();
  hideValidate();
  recalc();
}

function addBatchOfSame(btn) {
  const src = btn.closest('.item-row');
  const mid = parseInt(src.querySelector('.product-id').value, 10) || 0;
  if (!mid) {
    showValidate('Select a product before adding another batch.');
    return;
  }
  const p = productCatalog.find(x => x.id === mid);
  collapseCard(src);
  const row = newCard();
  resetRowFields(row, { keepMedicine: true });
  if (p) {
    row.querySelector('.product-id').value = p.id;
    row.querySelector('.product-code').value = p.sku || '';
    row.querySelector('.product-search').value = p.name;
    row.dataset.type = p.type;
    syncTypePills(row);
    syncLabels(row);
    syncTypeFields(row);
    fillMedInfo(row, p);
    updateCardTitle(row);
  }
  expandCard(row);
  renumberCards();
  row.scrollIntoView({ behavior: 'smooth', block: 'start' });
  const t = rowType(row);
  if (t !== 'equipment') {
    const b = row.querySelector('.batch-number');
    if (b) b.focus();
  } else {
    const m = row.querySelector('.model-input');
    if (m) m.focus();
  }
  if (window.lucide) lucide.createIcons();
  hideValidate();
  recalc();
}

function removeRow(btn) {
  const rows = document.querySelectorAll('.item-row');
  if (rows.length > 1) {
    btn.closest('.item-row').remove();
    renumberCards();
    if (!document.querySelector('.item-row:not(.collapsed)')) {
      const first = document.querySelector('.item-row');
      if (first) expandCard(first);
    }
  }
  recalc();
}

function showValidate(msg) {
  const el = document.getElementById('purchaseValidateMsg');
  el.textContent = msg;
  el.hidden = false;
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function hideValidate() {
  const el = document.getElementById('purchaseValidateMsg');
  el.hidden = true;
  el.textContent = '';
}

function validatePurchaseForm() {
  hideValidate();
  const purchaseDate = document.getElementById('purchaseDate').value;
  const seen = new Map();
  const rows = [...document.querySelectorAll('.item-row')];
  let hasLine = false;

  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    const mid = parseInt(row.querySelector('.product-id').value, 10) || 0;
    const qty = parseFloat(row.querySelector('[name="quantity[]"]').value) || 0;
    const buy = parseFloat(row.querySelector('[name="purchase_price[]"]').value);
    const sell = parseFloat(row.querySelector('[name="selling_price[]"]').value);
    const batch = (row.querySelector('[name="batch_number[]"]').value || '').trim();
    const expiry = (row.querySelector('[name="expiry_date[]"]').value || '').trim();
    const variant = (row.querySelector('[name="variant[]"]').value || '').trim();
    const t = rowType(row);
    const n = i + 1;

    if (!mid && qty <= 0 && !batch) continue;
    hasLine = true;

    if (!mid) {
      showValidate(`Line ${n}: select a product from the list.`);
      return false;
    }
    if (qty <= 0) {
      showValidate(`Line ${n}: enter a quantity greater than 0.`);
      return false;
    }
    if (!(buy >= 0) || Number.isNaN(buy)) {
      showValidate(`Line ${n}: enter a valid purchase price.`);
      return false;
    }
    if (!(sell >= 0) || Number.isNaN(sell)) {
      showValidate(`Line ${n}: enter a valid selling price for this batch.`);
      return false;
    }
    if (t !== 'equipment' && !batch) {
      showValidate(`Line ${n}: enter a batch number.`);
      return false;
    }
    if (t === 'medicine' && !expiry) {
      showValidate(`Line ${n}: expiry date is required for medicines.`);
      return false;
    }
    if (expiry && expiry !== '9999-12-31' && purchaseDate && expiry < purchaseDate) {
      showValidate(`Line ${n}: expiry date cannot be before the purchase date.`);
      return false;
    }
    if (batch) {
      const key = mid + '|' + batch.toLowerCase() + '|' + variant.toLowerCase();
      if (seen.has(key)) {
        showValidate(`Line ${n}: duplicate batch "${batch}" for the same product on this invoice. Combine quantities or use a different batch number.`);
        return false;
      }
      seen.set(key, n);
    }
  }

  if (!hasLine) {
    showValidate('Add at least one product with quantity.');
    return false;
  }

  const grand = parseFloat(document.getElementById('sumGrand').textContent) || 0;
  const paid = parseFloat(document.getElementById('amountPaid').value) || 0;
  if (paid - grand > 0.009) {
    showValidate('Amount paid cannot exceed the grand total.');
    return false;
  }
  return true;
}

document.getElementById('purchaseForm').addEventListener('submit', function(e) {
  if (!validatePurchaseForm()) e.preventDefault();
});

document.getElementById('purchaseDate').addEventListener('change', function() {
  syncDueDate();
  document.querySelectorAll('.expiry-input').forEach(warnExpiry);
});

syncDueDate();
document.querySelectorAll('.item-row').forEach(row => {
  syncTypePills(row);
  syncLabels(row);
  syncTypeFields(row);
  warnExpiry(row.querySelector('.expiry-input'));
});
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
    <div><span class="report-k">Invoice Number</span><span class="report-v"><?= htmlspecialchars($purchase['purchase_number']) ?></span></div>
    <div><span class="report-k">Purchase Date</span><span class="report-v"><?= htmlspecialchars($purchase['purchase_date'] ?: date('Y-m-d', strtotime($purchase['created_at']))) ?></span></div>
    <div><span class="report-k">Payment Terms</span><span class="report-v"><?= htmlspecialchars(supplierPaymentTerms()[$purchase['payment_terms'] ?? ''] ?? ($purchase['payment_terms'] ?: '—')) ?></span></div>
    <div><span class="report-k">Due Date</span><span class="report-v"><?= $purchase['due_date'] ? htmlspecialchars($purchase['due_date']) : '—' ?></span></div>
    <div><span class="report-k">Warehouse</span><span class="report-v"><?= htmlspecialchars($purchase['warehouse'] ?: '—') ?></span></div>
    <div><span class="report-k">Reference</span><span class="report-v"><?= htmlspecialchars($purchase['reference'] ?: '—') ?></span></div>
    <div><span class="report-k">Prepared by</span><span class="report-v"><?= htmlspecialchars($purchase['created_by_name'] ?? '—') ?></span></div>
  </div>
  <?php if (!empty($purchase['notes'])): ?>
  <p style="margin-top:12px;font-size:13px;color:var(--text-300);"><?= nl2br(htmlspecialchars($purchase['notes'])) ?></p>
  <?php endif; ?>
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
  <div class="card-header"><span class="card-title">Purchased Products</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Product</th>
          <th>Generic / Brand</th>
          <th>Category</th>
          <th>Unit</th>
          <th>Batch</th>
          <th>Mfg</th>
          <th>Expiry</th>
          <th>Qty</th>
          <th>Variant / Model / Serial</th>
          <th>Buy</th>
          <th>Sell</th>
          <th>Disc %</th>
          <th>Tax %</th>
          <th>Warranty</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $it):
        $itType = $it['product_type'] ?? 'medicine';
        $itTypeLabel = productTypes()[$itType] ?? ucfirst($itType);
        $variantInfo = trim(implode(' · ', array_filter([
            $it['variant'] ?? '', $it['model_number'] ?? '', $it['serial_number'] ?? '',
        ], fn($v) => $v !== '' && $v !== null)));
        $warranty = trim(implode(' · ', array_filter([
            $it['warranty_period'] ?? '', !empty($it['warranty_expiry']) ? ('until ' . $it['warranty_expiry']) : '',
        ], fn($v) => $v !== '' && $v !== null)));
      ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($it['med_name']) ?><br><small style="font-weight:400;color:var(--text-300);"><?= htmlspecialchars($itTypeLabel) ?></small></td>
        <td><?= htmlspecialchars($it['generic_name'] ?: '—') ?></td>
        <td><?= htmlspecialchars($it['category_name'] ?: '—') ?></td>
        <td><?= htmlspecialchars($it['unit'] ?: '—') ?></td>
        <td><code><?= htmlspecialchars($it['batch_number']) ?></code></td>
        <td><?= !empty($it['manufacturing_date']) ? htmlspecialchars($it['manufacturing_date']) : '—' ?></td>
        <td><?= formatExpiryDate($it['expiry_date']) ?></td>
        <td><?= (int)$it['quantity'] ?></td>
        <td style="font-size:12px;"><?= $variantInfo !== '' ? htmlspecialchars($variantInfo) : '—' ?></td>
        <td><?= currency((float)$it['purchase_price']) ?></td>
        <td><?= currency((float)$it['selling_price']) ?></td>
        <td><?= number_format((float)($it['discount'] ?? 0), 1) ?></td>
        <td><?= number_format((float)($it['tax'] ?? 0), 1) ?></td>
        <td style="font-size:12px;"><?= $warranty !== '' ? htmlspecialchars($warranty) : '—' ?></td>
        <td style="font-weight:700;"><?= currency((float)($it['line_total'] ?: $it['quantity'] * $it['purchase_price'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="report-summary-grid" style="margin-top:16px;">
    <div><span class="report-k">Subtotal</span><span class="report-v"><?= currency((float)($purchase['subtotal'] ?? $grand)) ?></span></div>
    <div><span class="report-k">Invoice Discount</span><span class="report-v"><?= currency((float)($purchase['discount'] ?? 0)) ?></span></div>
    <div><span class="report-k">Tax</span><span class="report-v"><?= currency((float)($purchase['tax'] ?? 0)) ?></span></div>
    <div><span class="report-k">Grand Total</span><span class="report-v"><?= currency($grand) ?></span></div>
    <div><span class="report-k">Amount Paid</span><span class="report-v" style="color:var(--accent2);"><?= currency($paid) ?></span></div>
    <div><span class="report-k">Returned</span><span class="report-v"><?= currency($returned) ?></span></div>
    <div><span class="report-k">Supplier Balance Due</span><span class="report-v" style="color:var(--warning);"><?= currency($due) ?></span></div>
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
              $remain = (int)$it['quantity'] - (int)($it['returned_quantity'] ?? 0);
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
