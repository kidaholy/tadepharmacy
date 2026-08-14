<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sales_lib.php';

function findCustomerById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function findCustomerByPhone(PDO $pdo, string $phone): ?array {
    $phone = normalizePhone($phone);
    if (!$phone) return null;
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE phone = ?");
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function normalizePhone(string $phone): string {
    return preg_replace('/\s+/', '', trim($phone));
}

function registerCustomer(PDO $pdo, string $fullName, string $phone, float $creditLimit = 0): array {
    $fullName = trim($fullName);
    $phone    = normalizePhone($phone);

    if ($fullName === '') {
        throw new RuntimeException('Full name is required.');
    }
    if ($phone === '') {
        throw new RuntimeException('Phone number is required.');
    }
    if (findCustomerByPhone($pdo, $phone)) {
        throw new RuntimeException('A customer with this phone number already exists.');
    }

    $pdo->prepare("
        INSERT INTO customers (full_name, phone, credit_limit) VALUES (?, ?, ?)
    ")->execute([$fullName, $phone, max(0, $creditLimit)]);

    return findCustomerById($pdo, (int)$pdo->lastInsertId());
}

function refreshCustomerCredit(PDO $pdo, int $customerId): void {
    $netExpr = '(s.total_amount - s.discount)';

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN s.remaining_balance > 0.009 THEN s.remaining_balance ELSE 0 END), 0) AS outstanding,
            COALESCE(SUM(CASE WHEN s.payment_method = 'credit' OR s.sale_type = 'credit' THEN $netExpr ELSE 0 END), 0) AS total_credit,
            COALESCE(SUM(s.paid_amount), 0) AS total_paid_sales,
            MAX(CASE WHEN s.payment_method = 'credit' OR s.sale_type = 'credit' THEN s.created_at END) AS last_credit,
            MIN(CASE WHEN s.remaining_balance > 0.009 AND COALESCE(s.due_date, s.credit_due_date) IS NOT NULL
                THEN COALESCE(s.due_date, s.credit_due_date) END) AS next_due
        FROM sales s
        WHERE s.customer_id = ?
    ");
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();

    $payStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM payment_history WHERE customer_id = ?
    ");
    $payStmt->execute([$customerId]);
    $histPaid = (float)$payStmt->fetchColumn();

    $overdueStmt = $pdo->prepare("
        SELECT COALESCE(SUM(s.remaining_balance), 0)
        FROM sales s
        WHERE s.customer_id = ?
          AND s.remaining_balance > 0.009
          AND COALESCE(s.due_date, s.credit_due_date) < date('now')
    ");
    $overdueStmt->execute([$customerId]);
    $overdue = (float)$overdueStmt->fetchColumn();

    $pdo->prepare("
        UPDATE customers SET
            outstanding_balance = ?,
            total_credit = ?,
            total_paid = ?,
            overdue_amount = ?,
            last_credit_sale = ?,
            next_due_date = ?
        WHERE id = ?
    ")->execute([
        (float)$row['outstanding'],
        (float)$row['total_credit'],
        (float)$row['total_paid_sales'] + $histPaid,
        $overdue,
        $row['last_credit'],
        $row['next_due'],
        $customerId,
    ]);
}

function getCustomerCreditSales(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare("
        SELECT s.*,
               COALESCE(s.due_date, s.credit_due_date) AS due_on
        FROM sales s
        WHERE s.customer_id = ?
          AND (s.payment_method = 'credit' OR s.sale_type = 'credit')
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function getCustomerPayments(PDO $pdo, int $customerId, int $limit = 50): array {
    $stmt = $pdo->prepare("
        SELECT ph.*, s.invoice_number, u.full_name AS received_by_name
        FROM payment_history ph
        JOIN sales s ON s.id = ph.sale_id
        LEFT JOIN users u ON u.id = ph.received_by
        WHERE ph.customer_id = ?
        ORDER BY ph.payment_date DESC
        LIMIT $limit
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function receiveCreditPayment(PDO $pdo, int $saleId, float $amount, string $paymentMethod, ?string $reference, int $receivedBy, ?string $notes = null): void {
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }

    $sale = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
    $sale->execute([$saleId]);
    $s = $sale->fetch();
    if (!$s) {
        throw new RuntimeException('Invoice not found.');
    }

    $remaining = (float)$s['remaining_balance'];
    if ($remaining <= 0.009) {
        throw new RuntimeException('This invoice is already fully paid.');
    }
    if ($amount > $remaining + 0.009) {
        throw new RuntimeException('Payment exceeds remaining balance (' . number_format($remaining, 2) . ').');
    }

    $pdo->beginTransaction();
    try {
        $newPaid = (float)$s['paid_amount'] + $amount;
        $newRemaining = max(0, saleNetAmount($s) - $newPaid);
        $status = computePaymentStatus(saleNetAmount($s), $newPaid, $s['payment_method']);

        $pdo->prepare("UPDATE sales SET paid_amount = ?, remaining_balance = ?, payment_status = ? WHERE id = ?")
            ->execute([$newPaid, $newRemaining, $status, $saleId]);

        recordSalePayment($pdo, $saleId, $s['customer_id'] ? (int)$s['customer_id'] : null, $amount, $paymentMethod, $reference, $receivedBy, $notes);

        if ($s['customer_id']) {
            refreshCustomerCredit($pdo, (int)$s['customer_id']);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function searchCustomers(PDO $pdo, string $q = '', int $limit = 100): array {
    if ($q === '') {
        return $pdo->query("SELECT * FROM customers ORDER BY full_name LIMIT $limit")->fetchAll();
    }
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT * FROM customers
        WHERE full_name LIKE ? OR phone LIKE ?
        ORDER BY full_name LIMIT $limit
    ");
    $stmt->execute([$like, $like]);
    return $stmt->fetchAll();
}

function linkSaleToCustomer(PDO $pdo, int $saleId, int $customerId): void {
    $cust = findCustomerById($pdo, $customerId);
    if (!$cust) return;
    $pdo->prepare("UPDATE sales SET customer_id = ?, customer_name = ?, customer_phone = ? WHERE id = ?")
        ->execute([$customerId, $cust['full_name'], $cust['phone'], $saleId]);
}
