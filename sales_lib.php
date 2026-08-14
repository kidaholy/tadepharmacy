<?php
require_once __DIR__ . '/db.php';

function posPaymentMethods(): array {
    return [
        'cash'      => 'Cash',
        'telebirr'  => 'Telebirr',
        'cbe'       => 'Commercial Bank of Ethiopia',
        'abyssinia' => 'Abyssinia Bank',
        'credit'    => 'Credit',
    ];
}

function saleNetAmount(array $sale): float {
    return (float)$sale['total_amount'] - (float)$sale['discount'];
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

function fetchPosMedicines(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT m.id, m.name, m.generic_name, m.unit,
               b.id AS batch_id, b.batch_number, b.selling_price, b.quantity AS batch_qty, b.expiry_date
        FROM medicines m
        JOIN batches b ON b.medicine_id = m.id
        WHERE b.quantity > 0 AND b.expiry_date >= date('now')
        ORDER BY m.name, b.expiry_date ASC
    ")->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $mid = (int)$row['id'];
        if (!isset($map[$mid])) {
            $map[$mid] = [
                'id'           => $mid,
                'name'         => $row['name'],
                'generic_name' => $row['generic_name'],
                'unit'         => $row['unit'],
                'batch_id'     => (int)$row['batch_id'],
                'batch_number' => $row['batch_number'],
                'selling_price'=> (float)$row['selling_price'],
                'stock'        => 0,
                'expiry_date'  => $row['expiry_date'],
            ];
        }
        $map[$mid]['stock'] += (int)$row['batch_qty'];
    }
    return array_values($map);
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
            HAVING COALESCE(SUM(b.quantity), 0) <= m.reorder_level
        ) AS low_meds
    ")->fetchColumn();

    $expiringSoon = (int) scalarBind($pdo, "
        SELECT COUNT(*) FROM batches
        WHERE expiry_date BETWEEN ? AND date(?, '+30 days') AND quantity > 0
    ", [$today, $today]);
    $expired = (int) scalarBind($pdo, "SELECT COUNT(*) FROM batches WHERE expiry_date < ? AND quantity > 0", [$today]);

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
        HAVING stock <= m.reorder_level
        ORDER BY stock ASC
        LIMIT 6
    ")->fetchAll();

    $expiringBatches = $pdo->prepare("
        SELECT b.batch_number, b.expiry_date, b.quantity, m.name
        FROM batches b
        JOIN medicines m ON m.id = b.medicine_id
        WHERE b.expiry_date <= date(?, '+30 days') AND b.quantity > 0
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
