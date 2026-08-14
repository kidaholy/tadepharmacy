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
