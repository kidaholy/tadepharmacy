<?php
/**
 * Shared helpers for the inventory movement modules:
 *   Stock Adjustment, Stock Transfer, Stock Exchange, External Pharmacies, Locations.
 *
 * Rules enforced here:
 *  - All valuation is INVENTORY COST (never selling price).
 *  - Every quantity change writes exactly one stock_movements row.
 *  - No Sales/Purchases rows are ever created by these modules.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_lib.php';

/** Generate a unique exchange number like EXC-20261004-001. */
function generateExchangeNumber(PDO $pdo): string
{
    return generateSequentialNumber($pdo, 'stock_exchanges', 'exchange_number', 'EXC');
}

/** Generate a unique adjustment number like ADJ-20261004-001. */
function generateAdjustmentNumber(PDO $pdo): string
{
    return generateSequentialNumber($pdo, 'stock_adjustments', 'adjustment_number', 'ADJ');
}

/** Generate a unique transfer number like TRF-20261004-001. */
function generateTransferNumber(PDO $pdo): string
{
    return generateSequentialNumber($pdo, 'stock_transfers', 'transfer_number', 'TRF');
}

function generateSequentialNumber(PDO $pdo, string $table, string $column, string $prefix): string
{
    $year = date('Y');
    $head = $prefix . '-' . $year . '-';
    $stmt = $pdo->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1");
    $stmt->execute([$head . '%']);
    $last = (string)$stmt->fetchColumn();
    $n = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $n = (int)$m[1] + 1;
    }
    return $head . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

/**
 * Create the tables used by the new inventory modules so a fresh install works.
 * Safe to run on every request (CREATE TABLE IF NOT EXISTS + guarded ALTERs).
 */
function initMovementModulesSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS locations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            code TEXT,
            address TEXT,
            status TEXT NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        "ALTER TABLE locations ADD COLUMN updated_at DATETIME",
        "ALTER TABLE external_pharmacies ADD COLUMN updated_at DATETIME",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* already applied */ }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS external_pharmacies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            contact_person TEXT,
            phone TEXT,
            email TEXT,
            address TEXT,
            notes TEXT,
            status TEXT NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_adjustments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            adjustment_number TEXT NOT NULL UNIQUE,
            adjustment_date DATE NOT NULL DEFAULT (date('now')),
            medicine_id INTEGER NOT NULL,
            batch_id INTEGER,
            category_id INTEGER,
            expiry_date DATE,
            system_qty INTEGER NOT NULL DEFAULT 0,
            physical_qty INTEGER NOT NULL DEFAULT 0,
            adjustment_qty INTEGER NOT NULL DEFAULT 0,
            unit_cost REAL NOT NULL DEFAULT 0,
            adjustment_value REAL NOT NULL DEFAULT 0,
            reason TEXT NOT NULL,
            notes TEXT,
            user_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE RESTRICT,
            FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_adjustments_date ON stock_adjustments(adjustment_date, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_adjustments_med ON stock_adjustments(medicine_id)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_transfers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transfer_number TEXT NOT NULL UNIQUE,
            transfer_date DATE NOT NULL DEFAULT (date('now')),
            from_location_id INTEGER NOT NULL,
            to_location_id INTEGER NOT NULL,
            sender_id INTEGER,
            receiver_id INTEGER,
            status TEXT NOT NULL DEFAULT 'draft',
            notes TEXT,
            transfer_value REAL NOT NULL DEFAULT 0,
            source_applied_at DATETIME,
            destination_applied_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
            FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_transfers_date ON stock_transfers(transfer_date, id)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_transfer_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transfer_id INTEGER NOT NULL,
            medicine_id INTEGER NOT NULL,
            batch_id INTEGER,
            quantity INTEGER NOT NULL,
            unit_cost REAL NOT NULL DEFAULT 0,
            unit_price REAL,
            transfer_value REAL NOT NULL DEFAULT 0,
            expiry_date DATE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE RESTRICT,
            FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_transfer_items_tr ON stock_transfer_items(transfer_id)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_exchanges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exchange_number TEXT NOT NULL UNIQUE,
            external_pharmacy_id INTEGER NOT NULL,
            exchange_date DATE NOT NULL DEFAULT (date('now')),
            transaction_type TEXT NOT NULL DEFAULT 'stock_exchange',
            status TEXT NOT NULL DEFAULT 'draft',
            settlement TEXT,
            settlement_amount REAL,
            settlement_reason TEXT,
            difference_value REAL,
            settled_at DATETIME,
            settled_by INTEGER,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            FOREIGN KEY (external_pharmacy_id) REFERENCES external_pharmacies(id) ON DELETE RESTRICT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_exchanges_date ON stock_exchanges(exchange_date, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_exchanges_ext ON stock_exchanges(external_pharmacy_id)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_exchange_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exchange_id INTEGER NOT NULL,
            side TEXT NOT NULL CHECK(side IN ('given','received')),
            medicine_id INTEGER NOT NULL,
            batch_id INTEGER,
            quantity INTEGER NOT NULL,
            unit_cost REAL NOT NULL DEFAULT 0,
            unit_price REAL,
            product_value REAL NOT NULL DEFAULT 0,
            expiry_date DATE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (exchange_id) REFERENCES stock_exchanges(id) ON DELETE CASCADE,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE RESTRICT,
            FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_exchange_products_ex ON stock_exchange_products(exchange_id, side)");

    // Outstanding temporary borrow/loan tracking (section 9 of the spec).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS external_stock_loans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            external_pharmacy_id INTEGER NOT NULL,
            direction TEXT NOT NULL CHECK(direction IN ('in','out')),
            medicine_id INTEGER NOT NULL,
            batch_id INTEGER,
            quantity INTEGER NOT NULL DEFAULT 0,
            returned_qty INTEGER NOT NULL DEFAULT 0,
            unit_cost REAL NOT NULL DEFAULT 0,
            transaction_type TEXT NOT NULL,
            exchange_id INTEGER,
            reference TEXT,
            status TEXT NOT NULL DEFAULT 'outstanding',
            notes TEXT,
            user_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            FOREIGN KEY (external_pharmacy_id) REFERENCES external_pharmacies(id) ON DELETE RESTRICT,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE RESTRICT,
            FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL,
            FOREIGN KEY (exchange_id) REFERENCES stock_exchanges(id) ON DELETE SET NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_external_stock_loans_ext ON external_stock_loans(external_pharmacy_id, direction, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_external_stock_loans_med ON external_stock_loans(medicine_id)");

    // Columns added after the first release of these tables.
    foreach ([
        "ALTER TABLE stock_exchanges ADD COLUMN transaction_type TEXT NOT NULL DEFAULT 'stock_exchange'",
        "ALTER TABLE stock_exchanges ADD COLUMN settlement_reason TEXT",
        "ALTER TABLE stock_exchanges ADD COLUMN settled_at DATETIME",
        "ALTER TABLE stock_exchanges ADD COLUMN settled_by INTEGER",
        "ALTER TABLE stock_exchanges ADD COLUMN settlement_amount REAL",
        "ALTER TABLE stock_exchanges ADD COLUMN created_by INTEGER",
        "ALTER TABLE stock_exchanges ADD COLUMN posted_at DATETIME",
        "ALTER TABLE stock_exchanges ADD COLUMN posted_by INTEGER",
        "ALTER TABLE stock_transfers ADD COLUMN source_applied_at DATETIME",
        "ALTER TABLE stock_transfers ADD COLUMN destination_applied_at DATETIME",
        "ALTER TABLE stock_transfer_items ADD COLUMN expiry_date DATE",
        "ALTER TABLE stock_movements ADD COLUMN unit_cost REAL",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* already applied */ }
    }

    // The pharmacy runs from at least one location; make sure one always exists.
    $count = (int)$pdo->query("SELECT COUNT(*) FROM locations")->fetchColumn();
    if ($count === 0) {
        $pdo->prepare("INSERT INTO locations (name, code, address, status) VALUES (?,?,?, 'active')")
            ->execute([getSetting('pharmacy_name', 'TADE PHARMACY') . ' — Main Branch', 'MAIN', getSetting('address', '')]);
    }
}

/* ── Exchange vocabulary ─────────────────────────────────────────────── */

/** Section 9 transaction types with their outstanding-tracking direction. */
function exchangeTransactionTypes(): array
{
    return [
        'stock_exchange'                => ['label' => 'Stock Exchange (permanent)',   'loan' => null],
        'temporary_borrow'              => ['label' => 'Temporary Borrow (Tade owes)', 'loan' => 'in'],
        'temporary_loan'                => ['label' => 'Temporary Loan (external owes)', 'loan' => 'out'],
        'received_from_external'        => ['label' => 'Received From External Pharmacy', 'loan' => 'in'],
        'given_to_external'             => ['label' => 'Given To External Pharmacy',  'loan' => 'out'],
        'return_to_external'            => ['label' => 'Return To External Pharmacy', 'loan' => 'settle_in'],
        'return_received_from_external' => ['label' => 'Return Received From External Pharmacy', 'loan' => 'settle_out'],
        'other'                         => ['label' => 'Other', 'loan' => null],
    ];
}

/** Section 8 settlement options (settlement is by inventory cost value). */
function exchangeSettlementOptions(): array
{
    return [
        ''                 => '— Select settlement —',
        'cash'             => 'Cash Settlement',
        'additional_product' => 'Additional Product',
        'credit_balance'   => 'Credit/Balance',
        'agreed_difference' => 'Agreed Difference',
        'waived'           => 'Waived Difference',
        'other'            => 'Other',
    ];
}

function exchangeStatusLabels(): array
{
    return [
        'draft'     => 'Draft',
        'pending'   => 'Pending / Difference',
        'settled'   => 'Settled',
        'returned'  => 'Returned',
        'cancelled' => 'Cancelled',
    ];
}

/* ── Stock movement helpers ──────────────────────────────────────────── */

/**
 * Find an existing batch for a product or create one (used when stock arrives
 * from an external pharmacy, which has no purchase record).
 */
function findOrCreateBatch(
    PDO $pdo,
    int $medicineId,
    ?int $batchId,
    string $batchNumber,
    string $expiryDate,
    float $unitCost,
    float $unitPrice = 0.0
): int {
    if ($batchId) {
        $st = $pdo->prepare("SELECT id FROM batches WHERE id = ? AND medicine_id = ?");
        $st->execute([$batchId, $medicineId]);
        if ($st->fetchColumn()) {
            return $batchId;
        }
    }
    $batchNumber = trim($batchNumber);
    if ($batchNumber !== '') {
        $st = $pdo->prepare("SELECT id FROM batches WHERE medicine_id = ? AND batch_number = ? ORDER BY id LIMIT 1");
        $st->execute([$medicineId, $batchNumber]);
        $existing = (int)$st->fetchColumn();
        if ($existing) {
            return $existing;
        }
    }
    if ($batchNumber === '') {
        $batchNumber = 'EXT-' . date('Ymd') . '-' . $medicineId;
    }
    $expiry = trim($expiryDate);
    if ($expiry === '') {
        $expiry = date('Y-m-d', strtotime('+365 days'));
    }
    if ($unitPrice <= 0) {
        // Selling price defaults to the product's last known selling price, never used for valuation.
        $st = $pdo->prepare("SELECT selling_price FROM batches WHERE medicine_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$medicineId]);
        $unitPrice = (float)$st->fetchColumn();
    }
    $pdo->prepare("
        INSERT INTO batches (medicine_id, batch_number, quantity, purchase_price, selling_price, expiry_date, status, notes)
        VALUES (?,?,0,?,?,?,'active',?)
    ")->execute([$medicineId, $batchNumber, $unitCost, $unitPrice, $expiry, 'Created by stock movement module']);
    return (int)$pdo->lastInsertId();
}

/**
 * Validate and apply a batch quantity delta, then write exactly one movement row.
 * Throws RuntimeException when stock would go negative.
 */
function applyBatchDelta(
    PDO $pdo,
    int $medicineId,
    ?int $batchId,
    int $delta,
    string $movementType,
    string $reason,
    ?string $refType,
    ?int $refId,
    ?string $reference,
    ?int $userId,
    string $label = 'item',
    ?float $unitCost = null
): void {
    if ($delta === 0) {
        return;
    }
    $prev = null;
    if ($batchId) {
        $st = $pdo->prepare("SELECT quantity FROM batches WHERE id = ?");
        $st->execute([$batchId]);
        $qty = $st->fetchColumn();
        if ($qty === false) {
            throw new RuntimeException("Batch not found for {$label}.");
        }
        $prev = (int)$qty;
        if ($delta < 0 && $prev < abs($delta)) {
            throw new RuntimeException("Insufficient stock for {$label}: available {$prev}, requested " . abs($delta) . '.');
        }
        $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id = ?")->execute([$delta, $batchId]);
    }
    recordStockMovement($pdo, $medicineId, $batchId, $movementType, $delta, $reason, $refType, $refId, $reference, $userId, $prev);

    // Inventory cost for this movement (valuation is always cost, never selling price).
    if ($unitCost === null && $batchId) {
        $st = $pdo->prepare("SELECT purchase_price FROM batches WHERE id = ?");
        $st->execute([$batchId]);
        $unitCost = (float)$st->fetchColumn();
    }
    if ($unitCost !== null) {
        $pdo->prepare("UPDATE stock_movements SET unit_cost = ? WHERE id = ?")
            ->execute([round((float)$unitCost, 4), (int)$pdo->lastInsertId()]);
    }
}

/**
 * Write one CSV row. Passes the escape character explicitly so PHP 8.4+ does not
 * emit a deprecation warning, and '' keeps output RFC-4180 compliant.
 */
function csvWrite($handle, array $row): void
{
    fputcsv($handle, $row, ',', '"', '');
}

/** Movement type => human label, shared by the movement history page. */
function stockMovementTypeLabels(): array
{
    return [
        'purchase'                  => 'Purchase',
        'sale'                      => 'Sale',
        'sale_return'               => 'Sale Return',
        'sale_return_unsellable'    => 'Sale Return (unfit)',
        'purchase_return'           => 'Purchase Return',
        'adjustment'                => 'Stock Adjustment',
        'transfer_out'              => 'Internal Transfer Out',
        'transfer_in'               => 'Internal Transfer In',
        'external_stock_given'      => 'External Stock Given',
        'external_stock_received'   => 'External Stock Received',
        'external_stock_return'     => 'External Stock Return (to pharmacy)',
        'external_stock_return_received' => 'External Stock Returned To Tade',
        'deactivate'                => 'Deactivate',
        'void_restore'              => 'Void Restore',
        'sale_correction'           => 'Sale Correction',
        'expiry'                    => 'Expiry',
        'writeoff'                  => 'Write-off',
        'manual'                    => 'Manual Movement',
    ];
}

/**
 * Recalculate an exchange's value totals / difference / status from its product lines.
 * Returns ['given'=>float,'received'=>float,'difference'=>float].
 */
function recalcExchangeTotals(PDO $pdo, int $exchangeId): array
{
    $st = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN side='given' THEN product_value END),0) AS given,
        COALESCE(SUM(CASE WHEN side='received' THEN product_value END),0) AS received
        FROM stock_exchange_products WHERE exchange_id = ?");
    $st->execute([$exchangeId]);
    $row = $st->fetch() ?: ['given' => 0, 'received' => 0];
    $given = round((float)$row['given'], 2);
    $received = round((float)$row['received'], 2);
    $difference = round($given - $received, 2);

    $cur = $pdo->prepare("SELECT status, settlement FROM stock_exchanges WHERE id = ?");
    $cur->execute([$exchangeId]);
    $c = $cur->fetch() ?: ['status' => 'draft', 'settlement' => null];

    $pdo->prepare("UPDATE stock_exchanges SET difference_value = ?, updated_at = datetime('now') WHERE id = ?")
        ->execute([$difference, $exchangeId]);

    refreshExchangeStatus($pdo, $exchangeId);

    return ['given' => $given, 'received' => $received, 'difference' => $difference];
}

/**
 * Recompute an exchange status from its value difference and outstanding loans.
 * draft / cancelled / returned are left untouched.
 */
function refreshExchangeStatus(PDO $pdo, int $exchangeId): string
{
    $st = $pdo->prepare("SELECT status, difference_value, settled_at FROM stock_exchanges WHERE id = ?");
    $st->execute([$exchangeId]);
    $row = $st->fetch();
    if (!$row) {
        return '';
    }
    $status = (string)$row['status'];
    if (in_array($status, ['draft', 'cancelled', 'returned'], true)) {
        return $status;
    }

    $loans = $pdo->prepare("SELECT COALESCE(SUM(quantity - returned_qty),0) FROM external_stock_loans
        WHERE exchange_id = ? AND status = 'outstanding'");
    $loans->execute([$exchangeId]);
    $outstanding = (int)$loans->fetchColumn();

    $difference = round((float)$row['difference_value'], 2);
    if ($outstanding > 0) {
        // Stock is still out (temporary borrow/loan): the exchange is not finished.
        $next = 'pending';
    } elseif (!empty($row['settled_at'])) {
        // The value difference has been settled (cash, credit, waived, agreed, ...).
        $next = 'settled';
    } else {
        $next = $difference === 0.0 ? 'settled' : 'pending';
    }
    if ($next !== $status) {
        $pdo->prepare("UPDATE stock_exchanges SET status = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$next, $exchangeId]);
    }
    return $next;
}

/** Outstanding un-returned quantity recorded against one exchange. */
function exchangeOutstandingQty(PDO $pdo, int $exchangeId): int
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(quantity - returned_qty),0) FROM external_stock_loans
        WHERE exchange_id = ? AND status = 'outstanding'");
    $st->execute([$exchangeId]);
    return (int)$st->fetchColumn();
}

/**
 * Open (or extend) the outstanding loan ledger for an exchange line.
 * direction 'in'  = Tade received stock and may owe a return.
 * direction 'out' = Tade gave stock and expects a return.
 */
function recordOutstandingLoan(
    PDO $pdo,
    int $externalPharmacyId,
    string $direction,
    int $medicineId,
    ?int $batchId,
    int $quantity,
    float $unitCost,
    string $transactionType,
    ?int $exchangeId,
    ?string $reference,
    ?int $userId,
    string $notes = ''
): void {
    if ($quantity <= 0) {
        return;
    }
    $pdo->prepare("
        INSERT INTO external_stock_loans
            (external_pharmacy_id, direction, medicine_id, batch_id, quantity, returned_qty, unit_cost,
             transaction_type, exchange_id, reference, status, notes, user_id)
        VALUES (?,?,?,?,?,0,?,?,?,?,'outstanding',?,?)
    ")->execute([
        $externalPharmacyId, $direction, $medicineId, $batchId, $quantity, $unitCost,
        $transactionType, $exchangeId, $reference, $notes !== '' ? $notes : null, $userId,
    ]);
}

/** Outstanding (un-returned) quantity for one product / direction. */
function outstandingLoanQuantity(PDO $pdo, int $medicineId, string $direction): int
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(quantity - returned_qty),0) FROM external_stock_loans WHERE medicine_id = ? AND direction = ? AND status = 'outstanding'");
    $st->execute([$medicineId, $direction]);
    return (int)$st->fetchColumn();
}

/** All outstanding loan rows, optionally for one product, newest first. */
function outstandingLoans(PDO $pdo, ?int $medicineId = null, ?int $exchangeId = null): array
{
    $where = ["l.status = 'outstanding'", '(l.quantity - l.returned_qty) > 0'];
    $params = [];
    if ($medicineId) { $where[] = 'l.medicine_id = ?'; $params[] = $medicineId; }
    if ($exchangeId) { $where[] = 'l.exchange_id = ?'; $params[] = $exchangeId; }
    $st = $pdo->prepare("
        SELECT l.*, m.name AS med_name, m.unit, e.name AS ext_name
        FROM external_stock_loans l
        JOIN medicines m ON m.id = l.medicine_id
        JOIN external_pharmacies e ON e.id = l.external_pharmacy_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.created_at ASC, l.id ASC
    ");
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Apply a return against outstanding loans (oldest first) for one product/direction.
 * Returns the quantity actually applied (never more than what is outstanding).
 */
function applyLoanReturn(PDO $pdo, int $medicineId, string $direction, int $qty): int
{
    if ($qty <= 0) {
        return 0;
    }
    $st = $pdo->prepare("SELECT id, quantity, returned_qty FROM external_stock_loans
        WHERE medicine_id = ? AND direction = ? AND status = 'outstanding'
        ORDER BY created_at ASC, id ASC");
    $st->execute([$medicineId, $direction]);
    $remaining = $qty;
    $applied = 0;
    foreach ($st->fetchAll() as $row) {
        if ($remaining <= 0) {
            break;
        }
        $open = (int)$row['quantity'] - (int)$row['returned_qty'];
        if ($open <= 0) {
            continue;
        }
        $take = min($open, $remaining);
        $returned = (int)$row['returned_qty'] + $take;
        $status = $returned >= (int)$row['quantity'] ? 'settled' : 'outstanding';
        $pdo->prepare("UPDATE external_stock_loans SET returned_qty = ?, status = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$returned, $status, (int)$row['id']]);
        $remaining -= $take;
        $applied += $take;
    }
    return $applied;
}
