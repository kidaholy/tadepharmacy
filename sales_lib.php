<?php
require_once __DIR__ . '/db.php';

function posPaymentMethods(): array {
    return [
        'cash'      => 'Cash',
        'cbe'       => 'CBE',
        'abyssinia' => 'Abyssinia',
        'telebirr'  => 'Telebirr',
        'credit'    => 'Credit',
    ];
}

/** Short cashier-facing labels for the POS payment buttons. */
function posPaymentButtonLabels(): array {
    return [
        'cash'      => 'Cash',
        'cbe'       => 'CBE',
        'abyssinia' => 'Abyssinia',
        'telebirr'  => 'Telebirr',
        'credit'    => 'Credit',
    ];
}

function productTypes(): array {
    return [
        'medicine'  => 'Medicine',
        'cosmetic'  => 'Cosmetic',
        'equipment' => 'Equipment',
    ];
}

function productTypeMeta(): array {
    return [
        'medicine'  => ['title' => 'Medicine',  'plural' => 'Medicines',  'all' => 'All Medicines',  'icon' => 'pill'],
        'cosmetic'  => ['title' => 'Cosmetics', 'plural' => 'Cosmetics',  'all' => 'All Cosmetics',  'icon' => 'sparkles'],
        'equipment' => ['title' => 'Equipment', 'plural' => 'Equipment',  'all' => 'All Equipment',  'icon' => 'stethoscope'],
    ];
}

function categoriesByProductType(PDO $pdo): array {
    $out = ['medicine' => [], 'cosmetic' => [], 'equipment' => []];
    $rows = $pdo->query("SELECT id, name, COALESCE(product_type,'medicine') AS product_type FROM categories ORDER BY name COLLATE NOCASE")->fetchAll();
    foreach ($rows as $c) {
        $t = $c['product_type'] ?: 'medicine';
        if (isset($out[$t])) {
            $out[$t][] = $c;
        }
    }
    return $out;
}

/** Hide the generic "Cosmetics" / "Equipment" rows so chips show details like Skincare. */
function categoryDetailsForType(array $categories, string $type): array {
    $generic = [
        'medicine'  => ['medicine', 'medicines', 'other'],
        'cosmetic'  => ['cosmetic', 'cosmetics'],
        'equipment' => ['equipment', 'equipments', 'device', 'devices'],
    ];
    $skip = $generic[$type] ?? [];
    $details = [];
    foreach ($categories as $c) {
        $n = strtolower(trim((string)($c['name'] ?? '')));
        if (in_array($n, $skip, true)) {
            continue;
        }
        $details[] = $c;
    }
    return $details ?: $categories;
}

function renderTypeTabs(string $base, string $activeType, array $counts = [], array $extra = [], bool $includeAll = false): void {
    echo '<div class="admin-tabs catalogue-tabs' . ($includeAll ? ' catalogue-tabs-4' : '') . '">';
    if ($includeAll) {
        $qs = http_build_query(array_filter($extra));
        $href = $qs ? ($base . '?' . $qs) : $base;
        $cls = $activeType === '' ? ' active' : '';
        echo '<a href="' . htmlspecialchars($href) . '" class="admin-tab' . $cls . '">';
        echo '<i data-lucide="layout-grid"></i><span>All</span>';
        if (isset($counts['all'])) {
            $badge = $activeType === '' ? 'badge-blue' : 'badge-gray';
            echo '<span class="badge ' . $badge . '">' . number_format((int)$counts['all']) . '</span>';
        }
        echo '</a>';
    }
    foreach (productTypeMeta() as $key => $meta) {
        $qs = http_build_query(array_filter(array_merge($extra, ['type' => $key])));
        $cls = $activeType === $key ? ' active' : '';
        echo '<a href="' . htmlspecialchars($base . '?' . $qs) . '" class="admin-tab' . $cls . '">';
        echo '<i data-lucide="' . htmlspecialchars($meta['icon']) . '"></i>';
        echo '<span>' . htmlspecialchars($meta['title']) . '</span>';
        if (isset($counts[$key])) {
            $badge = $activeType === $key ? 'badge-blue' : 'badge-gray';
            echo '<span class="badge ' . $badge . '">' . number_format((int)$counts[$key]) . '</span>';
        }
        echo '</a>';
    }
    echo '</div>';
}

function renderCategoryChips(string $base, string $type, int $catFilter, array $categories, array $extra = []): void {
    $meta = productTypeMeta()[$type] ?? productTypeMeta()['medicine'];
    $details = categoryDetailsForType($categories, $type);
    if (!$details) {
        return;
    }
    echo '<div class="cat-chips" role="navigation" aria-label="Filter by ' . htmlspecialchars($meta['title']) . ' category">';
    $allQs = http_build_query(array_filter(array_merge($extra, ['type' => $type])));
    echo '<a class="cat-chip' . ($catFilter ? '' : ' active') . '" href="' . htmlspecialchars($base . '?' . $allQs) . '">';
    echo htmlspecialchars($meta['all']);
    echo '</a>';
    foreach ($details as $c) {
        $qs = http_build_query(array_filter(array_merge($extra, ['type' => $type, 'cat' => (int)$c['id']])));
        $active = $catFilter === (int)$c['id'] ? ' active' : '';
        echo '<a class="cat-chip' . $active . '" href="' . htmlspecialchars($base . '?' . $qs) . '">' . htmlspecialchars($c['name']) . '</a>';
    }
    echo '</div>';
}

function productTypeFromCategory(?string $categoryName, string $fallback = 'medicine'): string {
    $name = strtolower(trim((string) $categoryName));
    if (str_contains($name, 'cosmetic')) {
        return 'cosmetic';
    }
    if (str_contains($name, 'equipment') || str_contains($name, 'device')) {
        return 'equipment';
    }
    return in_array($fallback, array_keys(productTypes()), true) ? $fallback : 'medicine';
}

function productUnits(): array {
    return [
        'strip', 'pk', 'bottle', 'sachet', 'ampoule', 'tube', 'jar', 'box', 'pack',
        'suppository', 'effervescent', 'puff', 'pis', 'tin', 'vial',
        'pcs', 'piece', 'pair', 'set', 'kit', 'tablet', 'capsule', 'ml', 'L',
    ];
}

function noExpiryDate(): string {
    return '9999-12-31';
}

function productRequiresExpiry(?string $productType): bool {
    return ($productType ?: 'medicine') === 'medicine';
}

function isNoExpiryDate(?string $date): bool {
    $date = trim((string) $date);
    return $date === '' || $date === '9999-12-31' || $date === '0000-00-00';
}

function normalizeExpiryDate(?string $date, bool $required): ?string {
    $date = trim((string) $date);
    if ($date === '') {
        return $required ? null : noExpiryDate();
    }
    return $date;
}

function formatExpiryDate(?string $date): string {
    if (isNoExpiryDate($date)) {
        return 'No expiry';
    }
    $ts = strtotime((string) $date);
    return $ts ? date('M d, Y', $ts) : '—';
}

function expiryTrackedSql(string $column = 'expiry_date'): string {
    return $column . " < '9000-01-01'";
}

/** Discounted subtotal: gross line total minus the invoice discount (excludes tax). */
function saleDiscountedSubtotal(array $sale): float {
    return (float)$sale['total_amount'] - (float)$sale['discount'];
}

/** Amount the customer owes / pays: subtotal − discount + tax. */
function saleNetAmount(array $sale): float {
    return saleDiscountedSubtotal($sale) + (float)($sale['tax'] ?? 0);
}

function computePaymentStatus(float $net, float $paid, string $paymentMethod): string {
    if ($paymentMethod === 'credit') {
        if ($paid <= 0.009) return 'unpaid';
        if ($paid < $net - 0.009) return 'partial';
        return 'paid';
    }
    if ($paid >= $net - 0.009) return 'paid';
    if ($paid > 0.009) return 'partial';
    return 'unpaid';
}

function paymentStatusLabel(string $status): string {
    return match ($status) {
        'paid'    => 'Paid',
        'partial' => 'Partially Paid',
        'unpaid'  => 'Unpaid (Credit)',
        default   => ucfirst($status),
    };
}

function paymentStatusBadge(string $status): string {
    return match ($status) {
        'paid'    => 'badge-green',
        'partial' => 'badge-orange',
        'unpaid'  => 'badge-red',
        default   => 'badge-gray',
    };
}

/** Total non-expired stock for a medicine (FEFO-eligible). */
function medicineAvailableStock(PDO $pdo, int $medicineId): int {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity), 0) FROM batches
        WHERE medicine_id = ? AND quantity > 0 AND expiry_date >= date('now')
    ");
    $stmt->execute([$medicineId]);
    return (int)$stmt->fetchColumn();
}

/** FEFO batch allocation — may split across multiple batches. */
function fefoAllocate(PDO $pdo, int $medicineId, int $qtyNeeded): array {
    if ($qtyNeeded <= 0) {
        throw new RuntimeException('Invalid quantity requested.');
    }

    $stmt = $pdo->prepare("
        SELECT id, batch_number, quantity, selling_price, expiry_date
        FROM batches
        WHERE medicine_id = ? AND quantity > 0 AND expiry_date >= date('now')
        ORDER BY expiry_date ASC, id ASC
    ");
    $stmt->execute([$medicineId]);
    $batches = $stmt->fetchAll();

    if (empty($batches)) {
        throw new RuntimeException('No valid stock available for this medicine.');
    }

    $remaining = $qtyNeeded;
    $lines = [];
    $totalAvail = array_sum(array_column($batches, 'quantity'));

    if ($totalAvail < $qtyNeeded) {
        throw new RuntimeException('Insufficient stock for one of the items.');
    }

    foreach ($batches as $b) {
        if ($remaining <= 0) break;
        $take = min((int)$b['quantity'], $remaining);
        if ($take <= 0) continue;
        $price = (float)$b['selling_price'];
        $lines[] = [
            'medicine_id'  => $medicineId,
            'batch_id'     => (int)$b['id'],
            'batch_number' => $b['batch_number'],
            'qty'          => $take,
            'price'        => $price,
            'subtotal'     => $price * $take,
        ];
        $remaining -= $take;
    }

    return $lines;
}

/** Build sale line items from cart using FEFO. */
function buildFefoLineItems(PDO $pdo, array $cartItems): array {
    $allLines = [];
    foreach ($cartItems as $item) {
        $mid = (int)($item['medicine_id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        if ($mid <= 0 || $qty <= 0) {
            throw new RuntimeException('Invalid cart item.');
        }
        $allLines = array_merge($allLines, fefoAllocate($pdo, $mid, $qty));
    }
    return $allLines;
}

/**
 * POS search catalog: one row per sellable batch (non-expired, in stock),
 * enriched with the fields the cashier searches and sees — brand, generic,
 * strength, dosage form, batch number, barcode/SKU, expiry, stock, price.
 * Batches are ordered by expiry so the earliest-valid batch always appears first.
 */
function fetchPosCatalog(PDO $pdo): array {
    return $pdo->query("
        SELECT m.id AS medicine_id, m.name, m.generic_name, m.strength, m.dosage_form,
               m.unit, m.barcode, m.sku, COALESCE(m.product_type,'medicine') AS product_type,
               m.category_id, c.name AS category_name,
               b.id AS batch_id, b.batch_number, b.selling_price, b.quantity AS stock, b.expiry_date
        FROM medicines m
        JOIN batches b ON b.medicine_id = m.id
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE b.quantity > 0 AND b.expiry_date >= date('now')
        ORDER BY m.name COLLATE NOCASE, b.expiry_date ASC, b.id ASC
    ")->fetchAll();
}

/**
 * Build sale line items directly from cart batches (each cart entry carries the
 * exact batch the cashier picked, so no FEFO re-splitting is needed at checkout).
 * Validates stock, expiry and quantity for every batch before returning.
 */
function buildBatchLineItems(PDO $pdo, array $cartItems): array {
    $merged = [];
    foreach ($cartItems as $item) {
        $batchId = (int)($item['batch_id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        if ($batchId <= 0 || $qty <= 0) {
            throw new RuntimeException('Invalid cart item.');
        }
        $merged[$batchId] = ($merged[$batchId] ?? 0) + $qty;
    }
    if (!$merged) {
        throw new RuntimeException('Cart is empty. Add at least one medicine.');
    }

    $lines = [];
    $st = $pdo->prepare("
        SELECT b.*, m.id AS medicine_id, m.name, m.generic_name, m.strength, m.dosage_form, m.unit
        FROM batches b
        JOIN medicines m ON m.id = b.medicine_id
        WHERE b.id = ?
    ");
    foreach ($merged as $batchId => $qty) {
        $st->execute([$batchId]);
        $b = $st->fetch();
        if (!$b) {
            throw new RuntimeException('A selected batch no longer exists. Refresh and try again.');
        }
        if (!isNoExpiryDate($b['expiry_date']) && $b['expiry_date'] < date('Y-m-d')) {
            throw new RuntimeException("Batch {$b['batch_number']} of {$b['name']} is expired.");
        }
        if ((int)$b['quantity'] < $qty) {
            throw new RuntimeException("Insufficient stock for {$b['name']} (batch {$b['batch_number']}). Available: {$b['quantity']}.");
        }
        $price = (float)$b['selling_price'];
        $lines[] = [
            'medicine_id'  => (int)$b['medicine_id'],
            'batch_id'     => (int)$b['id'],
            'batch_number' => $b['batch_number'],
            'med_name'     => $b['name'],
            'generic'      => $b['generic_name'] ?? '',
            'strength'     => $b['strength'] ?? '',
            'dosage_form'  => $b['dosage_form'] ?? '',
            'unit'         => $b['unit'] ?? '',
            'qty'          => $qty,
            'price'        => $price,
            'subtotal'     => round($price * $qty, 2),
        ];
    }
    return $lines;
}

/** Sale items enriched with brand, generic, strength, form, batch and expiry — for details/receipt views. */
function fetchSaleItemsDetailed(PDO $pdo, int $saleId): array {
    $st = $pdo->prepare("
        SELECT si.*, m.name, m.generic_name, m.strength, m.dosage_form, m.unit,
               b.batch_number, b.expiry_date
        FROM sale_items si
        JOIN medicines m ON m.id = si.medicine_id
        LEFT JOIN batches b ON b.id = si.batch_id
        WHERE si.sale_id = ?
        ORDER BY si.id
    ");
    $st->execute([$saleId]);
    return $st->fetchAll();
}

function recordSalePayment(PDO $pdo, int $saleId, ?int $customerId, float $amount, string $method, ?string $reference, ?int $receivedBy, ?string $notes = null): void {
    if ($amount <= 0) return;
    $pdo->prepare("
        INSERT INTO payment_history (sale_id, customer_id, amount, payment_method, payment_date, reference_number, received_by, notes)
        VALUES (?, ?, ?, ?, datetime('now'), ?, ?, ?)
    ")->execute([$saleId, $customerId, $amount, $method, $reference, $receivedBy, $notes]);
}

function updateSaleBalances(PDO $pdo, int $saleId): void {
    $sale = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
    $sale->execute([$saleId]);
    $s = $sale->fetch();
    if (!$s) return;

    $net = saleNetAmount($s);
    $paid = (float)$s['paid_amount'];
    $remaining = max(0, $net - $paid);
    $status = computePaymentStatus($net, $paid, $s['payment_method']);

    $pdo->prepare("UPDATE sales SET payment_status = ?, remaining_balance = ? WHERE id = ?")
        ->execute([$status, $remaining, $saleId]);
}

function isSaleOverdue(array $sale): bool {
    $due = $sale['due_date'] ?? $sale['credit_due_date'] ?? null;
    if (!$due) return false;
    $remaining = (float)($sale['remaining_balance'] ?? 0);
    if ($remaining <= 0.009) return false;
    return $due < date('Y-m-d');
}

function dashboardSnapshot(PDO $pdo): array {
    $today = businessToday();
    [$dayStart, $dayEnd] = businessDayUtcRange($today);
    [$monthStart, $monthEnd] = businessMonthUtcRange();

    $todayRevenue = (float) scalarBind($pdo, "SELECT COALESCE(SUM(total_amount - discount), 0) FROM sales WHERE created_at >= ? AND created_at < ?", [$dayStart, $dayEnd]);
    $todaySales = (int) scalarBind($pdo, "SELECT COUNT(*) FROM sales WHERE created_at >= ? AND created_at < ?", [$dayStart, $dayEnd]);
    $monthRevenue = (float) scalarBind($pdo, "SELECT COALESCE(SUM(total_amount - discount), 0) FROM sales WHERE created_at >= ? AND created_at < ?", [$monthStart, $monthEnd]);
    $itemsSold = (int) scalarBind($pdo, "
        SELECT COALESCE(SUM(si.quantity), 0)
        FROM sale_items si JOIN sales s ON s.id = si.sale_id
        WHERE s.created_at >= ? AND s.created_at < ?
    ", [$dayStart, $dayEnd]);

    $totalMeds = (int) $pdo->query("SELECT COUNT(*) FROM medicines")->fetchColumn();
    $totalStock = (int) $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM batches")->fetchColumn();

    $lowStock = (int) $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT m.id FROM medicines m
            LEFT JOIN batches b ON b.medicine_id = m.id
            GROUP BY m.id
            HAVING COALESCE(SUM(b.quantity), 0) > 0
               AND COALESCE(SUM(b.quantity), 0) <= m.reorder_level
        ) AS low_meds
    ")->fetchColumn();

    $expiringSoon = (int) scalarBind($pdo, "
        SELECT COUNT(DISTINCT medicine_id) FROM batches
        WHERE expiry_date BETWEEN ? AND date(?, '+30 days') AND quantity > 0
          AND " . expiryTrackedSql() . "
    ", [$today, $today]);
    $expired = (int) scalarBind($pdo, "
        SELECT COUNT(DISTINCT medicine_id) FROM batches
        WHERE expiry_date < ? AND quantity > 0 AND " . expiryTrackedSql() . "
    ", [$today]);

    $outstandingCredit = (float) $pdo->query("
        SELECT COALESCE(SUM(remaining_balance), 0) FROM sales
        WHERE remaining_balance > 0.009 AND (payment_method = 'credit' OR sale_type = 'credit')
    ")->fetchColumn();
    $overdueCredit = (float) scalarBind($pdo, "
        SELECT COALESCE(SUM(remaining_balance), 0) FROM sales
        WHERE remaining_balance > 0.009 AND (payment_method = 'credit' OR sale_type = 'credit')
          AND COALESCE(due_date, credit_due_date) < ?
    ", [$today]);
    $creditCollectedToday = (float) scalarBind($pdo, "
        SELECT COALESCE(SUM(ph.amount), 0) FROM payment_history ph
        JOIN sales s ON s.id = ph.sale_id
        WHERE ph.payment_date >= ? AND ph.payment_date < ?
          AND (s.payment_method = 'credit' OR s.sale_type = 'credit')
    ", [$dayStart, $dayEnd]);

    $payStmt = $pdo->prepare("
        SELECT payment_method, COUNT(*) AS cnt, COALESCE(SUM(total_amount - discount), 0) AS rev
        FROM sales WHERE created_at >= ? AND created_at < ?
        GROUP BY payment_method ORDER BY rev DESC
    ");
    $payStmt->execute([$dayStart, $dayEnd]);
    $todayPayments = $payStmt->fetchAll();

    $lowMeds = $pdo->query("
        SELECT m.name, m.reorder_level, COALESCE(SUM(b.quantity),0) as stock
        FROM medicines m
        LEFT JOIN batches b ON b.medicine_id = m.id
        GROUP BY m.id
        HAVING COALESCE(SUM(b.quantity), 0) > 0 AND COALESCE(SUM(b.quantity), 0) <= m.reorder_level
        ORDER BY stock ASC
        LIMIT 6
    ")->fetchAll();

    $expiringBatches = $pdo->prepare("
        SELECT b.batch_number, b.expiry_date, b.quantity, m.name
        FROM batches b
        JOIN medicines m ON m.id = b.medicine_id
        WHERE b.expiry_date <= date(?, '+30 days') AND b.quantity > 0
          AND " . expiryTrackedSql('b.expiry_date') . "
        ORDER BY b.expiry_date ASC
        LIMIT 6
    ");
    $expiringBatches->execute([$today]);
    $expiringBatches = $expiringBatches->fetchAll();

    $weekStart = (new DateTime($today . ' 00:00:00', new DateTimeZone('Africa/Addis_Ababa')))
        ->modify('-6 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $weekStmt = $pdo->prepare("
        SELECT date(created_at, '+3 hours') as day, COALESCE(SUM(total_amount-discount),0) as rev
        FROM sales
        WHERE created_at >= ?
        GROUP BY day ORDER BY day
    ");
    $weekStmt->execute([$weekStart]);
    $weekData = $weekStmt->fetchAll();

    $recentSales = $pdo->query("SELECT s.*, COUNT(si.id) as items FROM sales s LEFT JOIN sale_items si ON si.sale_id = s.id GROUP BY s.id ORDER BY s.created_at DESC LIMIT 8")->fetchAll();

    $topStmt = $pdo->prepare("
        SELECT m.name, SUM(si.quantity) AS qty, SUM(si.subtotal) AS rev
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        JOIN medicines m ON m.id = si.medicine_id
        WHERE s.created_at >= ? AND s.created_at < ?
        GROUP BY m.id
        ORDER BY qty DESC
        LIMIT 5
    ");
    $topStmt->execute([$dayStart, $dayEnd]);
    $topProducts = $topStmt->fetchAll();

    return compact(
        'today', 'todayRevenue', 'todaySales', 'monthRevenue', 'itemsSold',
        'totalMeds', 'totalStock', 'lowStock', 'expiringSoon', 'expired',
        'outstandingCredit', 'overdueCredit', 'creditCollectedToday',
        'todayPayments', 'lowMeds', 'expiringBatches', 'weekData', 'recentSales', 'topProducts'
    );
}

function scalarBind(PDO $pdo, string $sql, array $params) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
