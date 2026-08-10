<?php
require_once __DIR__ . '/layout.php';

$pdo  = getDB();
$msg  = '';
$error = '';

// ── Fetch available medicines (with batches not expired) ────
$availMeds = $pdo->query("
    SELECT m.id, m.name, m.generic_name, m.unit,
           b.id as batch_id, b.batch_number, b.selling_price, b.quantity as batch_qty, b.expiry_date
    FROM medicines m
    JOIN batches b ON b.medicine_id = m.id
    WHERE b.quantity > 0 AND b.expiry_date >= date('now')
    ORDER BY m.name, b.expiry_date ASC
")->fetchAll();

// Group by medicine, pick earliest-expiry batch (FEFO)
$medsMap = [];
foreach ($availMeds as $row) {
    $mid = $row['id'];
    if (!isset($medsMap[$mid])) {
        $medsMap[$mid] = [
            'id'          => $mid,
            'name'        => $row['name'],
            'generic_name'=> $row['generic_name'],
            'unit'        => $row['unit'],
            'batch_id'    => $row['batch_id'],
            'batch_number'=> $row['batch_number'],
            'selling_price'=> $row['selling_price'],
            'stock'       => $row['batch_qty'],
            'expiry_date' => $row['expiry_date'],
        ];
    }
}
$medsList = array_values($medsMap);

// ── Handle checkout ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer = trim($_POST['customer_name'] ?? 'Walk-in Customer');
    $phone    = trim($_POST['customer_phone'] ?? '');
    $discount = (float)($_POST['discount'] ?? 0);
    $payment  = $_POST['payment_method'] ?? 'cash';
    $paid     = (float)($_POST['paid_amount'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');
    $items    = json_decode($_POST['cart_json'] ?? '[]', true);

    if (empty($items)) {
        $error = 'Cart is empty. Please add at least one medicine.';
    } else {
        $total = 0;
        foreach ($items as $item) {
            $total += (float)$item['price'] * (int)$item['qty'];
        }

        $invoice = generateInvoice();
        $net     = $total - $discount;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO sales (invoice_number,customer_name,customer_phone,total_amount,discount,paid_amount,payment_method,notes)
                           VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$invoice,$customer,$phone,$total,$discount,$paid,$payment,$notes]);
            $saleId = $pdo->lastInsertId();

            foreach ($items as $item) {
                $mid = (int)$item['medicine_id'];
                $bid = (int)$item['batch_id'];
                $qty = (int)$item['qty'];
                $price = (float)$item['price'];

                // Check stock
                $avail = $pdo->prepare("SELECT quantity FROM batches WHERE id=?")->execute([$bid]) ? 
                         ($s=$pdo->prepare("SELECT quantity FROM batches WHERE id=?")) && $s->execute([$bid]) ? $s->fetchColumn() : 0 : 0;
                // clean check
                $sq = $pdo->prepare("SELECT quantity FROM batches WHERE id=?");
                $sq->execute([$bid]);
                $avail = (int)$sq->fetchColumn();

                if ($avail < $qty) throw new Exception("Insufficient stock for one of the items.");

                $pdo->prepare("INSERT INTO sale_items (sale_id,medicine_id,batch_id,quantity,unit_price,subtotal) VALUES (?,?,?,?,?,?)")
                    ->execute([$saleId,$mid,$bid,$qty,$price,$price*$qty]);

                $pdo->prepare("UPDATE batches SET quantity = quantity - ? WHERE id=?")->execute([$qty,$bid]);
            }

            $pdo->commit();
            header("Location: receipt.php?id=$saleId");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Transaction failed: ' . $e->getMessage();
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
<div class="alert alert-danger" style="margin-bottom:14px;"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="pos-layout">
  <!-- LEFT: Medicine grid -->
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
           data-batch-id="<?= $m['batch_id'] ?>"
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

  <!-- RIGHT: Cart -->
  <div class="pos-right">
    <div class="pos-cart-header">
      🛒 Cart <span id="cartCount" style="color:var(--accent);">(0 items)</span>
    </div>

    <div class="pos-cart-items" id="cartItems">
      <div style="text-align:center;padding:40px 20px;color:var(--text-300);font-size:13px;">
        Click medicines to add to cart
      </div>
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
        <input type="hidden" name="discount"  id="discountHidden">
        <div class="form-group" style="margin-bottom:10px;">
          <label style="margin-bottom:4px;">Customer Name</label>
          <input type="text" name="customer_name" value="Walk-in Customer" style="padding:8px 12px;font-size:13px;">
        </div>
        <div class="form-group" style="margin-bottom:10px;">
          <label style="margin-bottom:4px;">Phone</label>
          <input type="tel" name="customer_phone" placeholder="Optional" style="padding:8px 12px;font-size:13px;">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label style="margin-bottom:4px;">Payment</label>
            <select name="payment_method" style="padding:8px 12px;font-size:13px;">
              <option value="cash">Cash</option>
              <option value="telebirr">TeleBirr</option>
              <option value="cbe">CBE Birr</option>
              <option value="card">Card</option>
              <option value="mpesa">M-Pesa</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label style="margin-bottom:4px;">Amount Paid</label>
            <input type="number" name="paid_amount" id="paidAmount" min="0" step="0.01" style="padding:8px 12px;font-size:13px;" placeholder="0.00">
          </div>
        </div>
        <button type="submit" class="btn btn-success" id="checkoutBtn" onclick="return prepareCheckout()">
          <i data-lucide="receipt"></i> Complete Sale
        </button>
      </form>
    </div>
  </div>
</div>

</div></div>

<script>
const CUR = '<?= getSetting('currency','ETB') ?>';
let cart = {};

function addToCart(el) {
  const id      = el.dataset.id;
  const name    = el.dataset.name;
  const batchId = el.dataset.batchId;
  const price   = parseFloat(el.dataset.price);
  const stock   = parseInt(el.dataset.stock);
  const unit    = el.dataset.unit;

  if (cart[id]) {
    if (cart[id].qty < stock) cart[id].qty++;
    else { alert('Maximum stock reached!'); return; }
  } else {
    cart[id] = { medicine_id: id, batch_id: batchId, name, price, qty: 1, stock, unit };
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

function removeItem(id) {
  delete cart[id];
  renderCart();
}

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
      </div>
      <div class="qty-ctrl">
        <button class="qty-btn" onclick="adjustQty('${id}',-1)">−</button>
        <span class="qty-val">${it.qty}</span>
        <button class="qty-btn" onclick="adjustQty('${id}',1)">+</button>
      </div>
      <button class="cart-item-remove" onclick="removeItem('${id}')"><i data-lucide="x"></i></button>
    </div>`;
  }).join('');
  lucide.createIcons();
  recalc();
}

function recalc() {
  let sub = Object.values(cart).reduce((s, it) => s + it.price * it.qty, 0);
  let dis = parseFloat(document.getElementById('discountInput').value) || 0;
  let tot = Math.max(0, sub - dis);
  document.getElementById('subtotalVal').textContent = `${CUR} ${sub.toFixed(2)}`;
  document.getElementById('totalVal').textContent    = `${CUR} ${tot.toFixed(2)}`;
  document.getElementById('paidAmount').placeholder  = tot.toFixed(2);
}

function filterMeds(q) {
  const term = q.toLowerCase();
  document.querySelectorAll('.med-card').forEach(el => {
    const name    = el.dataset.name.toLowerCase();
    const generic = el.dataset.generic.toLowerCase();
    el.style.display = (!term || name.includes(term) || generic.includes(term)) ? '' : 'none';
  });
}

function prepareCheckout() {
  if (Object.keys(cart).length === 0) { alert('Cart is empty!'); return false; }
  const cartItems = Object.values(cart).map(it => ({
    medicine_id: it.medicine_id, batch_id: it.batch_id, qty: it.qty, price: it.price
  }));
  document.getElementById('cartJson').value    = JSON.stringify(cartItems);
  document.getElementById('discountHidden').value = document.getElementById('discountInput').value;
  return true;
}
</script>

<?php renderFooter(); ?>
