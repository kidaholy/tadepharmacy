<?php
/**
 * Inventory helpers: stock ledger, soft-delete, category cleanup, export titles.
 * Extends the existing inventory/sales/purchase flows without replacing them.
 */
require_once __DIR__ . '/db.php';

function initInventorySchema(PDO $pdo): void {
    foreach ([
        "ALTER TABLE medicines ADD COLUMN brand_name TEXT",
        "ALTER TABLE medicines ADD COLUMN notes TEXT",
        "ALTER TABLE batches ADD COLUMN notes TEXT",
        "ALTER TABLE sales ADD COLUMN status TEXT DEFAULT 'active'",
        "ALTER TABLE sales ADD COLUMN voided_at DATETIME",
        "ALTER TABLE sales ADD COLUMN voided_by INTEGER",
        "ALTER TABLE sales ADD COLUMN void_reason TEXT",
        "ALTER TABLE sales ADD COLUMN sale_at DATETIME",
        "ALTER TABLE sales ADD COLUMN entered_at DATETIME",
        "ALTER TABLE sale_returns ADD COLUMN reason_code TEXT",
        "ALTER TABLE sale_returns ADD COLUMN resalable INTEGER DEFAULT 1",
        "ALTER TABLE sale_returns ADD COLUMN created_by INTEGER",
        "ALTER TABLE sale_returns ADD COLUMN refund_amount REAL DEFAULT 0",
        "ALTER TABLE sale_returns ADD COLUMN sale_item_id INTEGER",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* exists */ }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_movements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            medicine_id INTEGER NOT NULL,
            batch_id INTEGER,
            movement_type TEXT NOT NULL,
            quantity_in INTEGER NOT NULL DEFAULT 0,
            quantity_out INTEGER NOT NULL DEFAULT 0,
            previous_qty INTEGER,
            new_qty INTEGER,
            balance_after INTEGER,
            reason TEXT,
            reference_type TEXT,
            reference_id INTEGER,
            reference TEXT,
            user_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
            FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_movements_med ON stock_movements(medicine_id, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_movements_batch ON stock_movements(batch_id, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_batches_status ON batches(status)");

    // Backfill sale_at / entered_at from created_at once.
    $pdo->exec("UPDATE sales SET sale_at = created_at WHERE sale_at IS NULL");
    $pdo->exec("UPDATE sales SET entered_at = created_at WHERE entered_at IS NULL");
    $pdo->exec("UPDATE sales SET status = 'active' WHERE status IS NULL OR TRIM(status) = ''");

    // Brand name defaults to product name when empty (search/display).
    $pdo->exec("UPDATE medicines SET brand_name = name WHERE brand_name IS NULL OR TRIM(brand_name) = ''");

    mergeDuplicateCategories($pdo);
}

/**
 * Normalize category names so spelling variants collapse to one canonical form.
 */
function canonicalizeCategoryName(string $name): string {
    $trim = trim($name);
    $key = preg_replace('/\s+/', ' ', strtolower($trim));
    $aliases = [
        'supplements & vitamins' => 'Vitamins & Supplements',
        'supplements and vitamins' => 'Vitamins & Supplements',
        'vitamins and supplements' => 'Vitamins & Supplements',
        'vitamin & supplements' => 'Vitamins & Supplements',
        'vitamins & supplement' => 'Vitamins & Supplements',
    ];
    return $aliases[$key] ?? $trim;
}

/**
 * Merge known duplicate category spellings and fix mis-typed product_type seeds.
 * Never deletes medicine/batch rows — only remaps category_id then removes empty dup categories.
 */
function mergeDuplicateCategories(PDO $pdo): void {
    $flag = $pdo->query("SELECT value FROM settings WHERE key='inventory_cats_merged_v1'")->fetchColumn();
    if ($flag === '1') {
        return;
    }

    // Keep the canonical name on the right; merge left → right.
    $pairs = [
        ['Supplements & Vitamins', 'Vitamins & Supplements'],
        ['Vitamins and Supplements', 'Vitamins & Supplements'],
        ['Supplements and Vitamins', 'Vitamins & Supplements'],
    ];

    foreach ($pairs as [$fromName, $toName]) {
        $from = $pdo->prepare("SELECT id, product_type FROM categories WHERE LOWER(TRIM(name)) = LOWER(?) LIMIT 1");
        $from->execute([$fromName]);
        $fromRow = $from->fetch();
        if (!$fromRow) {
            continue;
        }
        $to = $pdo->prepare("SELECT id FROM categories WHERE LOWER(TRIM(name)) = LOWER(?) LIMIT 1");
        $to->execute([$toName]);
        $toId = (int)$to->fetchColumn();
        if (!$toId) {
            // Rename the from-row to the canonical name.
            $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?")->execute([$toName, (int)$fromRow['id']]);
            continue;
        }
        if ($toId === (int)$fromRow['id']) {
            continue;
        }
        $pdo->prepare("UPDATE medicines SET category_id = ? WHERE category_id = ?")
            ->execute([$toId, (int)$fromRow['id']]);
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([(int)$fromRow['id']]);
    }

    // Cosmetic seed names were inserted as medicine — retag so chips stay accurate.
    $cosmeticNames = ['Skincare', 'Haircare', 'Makeup', 'Body Care', 'Sunscreen', 'Cleansing'];
    $retag = $pdo->prepare("UPDATE categories SET product_type = 'cosmetic' WHERE LOWER(TRIM(name)) = LOWER(?) AND COALESCE(product_type,'medicine') = 'medicine'");
    foreach ($cosmeticNames as $n) {
        $retag->execute([$n]);
    }

    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('inventory_cats_merged_v1', '1')")->execute();
}

function stockAdjustmentReasons(): array {
    return [
        'damaged'           => 'Damaged',
        'lost'              => 'Lost',
        'expired'           => 'Expired',
        'physical_count'    => 'Physical count correction',
        'returned_supplier' => 'Returned to supplier',
        'found'             => 'Found stock',
        'disposed'          => 'Disposed',
        'other'             => 'Other',
    ];
}

function saleReturnReasons(): array {
    return [
        'customer_return' => 'Customer return',
        'damaged'         => 'Damaged',
        'wrong_product'   => 'Wrong product',
        'wrong_quantity'  => 'Wrong quantity',
        'other'           => 'Other',
    ];
}

function batchHasHistoricalLinks(PDO $pdo, int $batchId): bool {
    $checks = [
        "SELECT 1 FROM sale_items WHERE batch_id = ? LIMIT 1",
        "SELECT 1 FROM sale_returns WHERE batch_id = ? LIMIT 1",
        "SELECT 1 FROM purchase_items WHERE batch_id = ? LIMIT 1",
        "SELECT 1 FROM purchase_return_items WHERE batch_id = ? LIMIT 1",
        "SELECT 1 FROM stock_movements WHERE batch_id = ? LIMIT 1",
    ];
    foreach ($checks as $sql) {
        $st = $pdo->prepare($sql);
        $st->execute([$batchId]);
        if ($st->fetchColumn()) {
            return true;
        }
    }
    return false;
}

/**
 * Record a stock movement and optionally update batch quantity.
 * $delta: positive = stock in, negative = stock out.
 */
function recordStockMovement(
    PDO $pdo,
    int $medicineId,
    ?int $batchId,
    string $type,
    int $delta,
    string $reason = '',
    ?string $refType = null,
    ?int $refId = null,
    ?string $reference = null,
    ?int $userId = null,
    ?int $forcedPrevious = null
): void {
    $prev = $forcedPrevious;
    if ($prev === null && $batchId) {
        $st = $pdo->prepare("SELECT quantity FROM batches WHERE id = ?");
        $st->execute([$batchId]);
        $prev = (int)$st->fetchColumn();
    }
    $prev = (int)$prev;
    $new = $prev + $delta;
    $qtyIn = $delta > 0 ? $delta : 0;
    $qtyOut = $delta < 0 ? abs($delta) : 0;

    $pdo->prepare("
        INSERT INTO stock_movements (
            medicine_id, batch_id, movement_type, quantity_in, quantity_out,
            previous_qty, new_qty, balance_after, reason,
            reference_type, reference_id, reference, user_id
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $medicineId, $batchId, $type, $qtyIn, $qtyOut,
        $prev, $new, $new, $reason !== '' ? $reason : null,
        $refType, $refId, $reference, $userId,
    ]);
}

function adjustBatchStock(
    PDO $pdo,
    int $batchId,
    int $adjustmentQty,
    string $reasonKey,
    string $notes = '',
    ?int $userId = null
): array {
    $reasons = stockAdjustmentReasons();
    if (!isset($reasons[$reasonKey])) {
        return ['ok' => false, 'error' => 'Invalid adjustment reason.'];
    }
    if ($adjustmentQty === 0) {
        return ['ok' => false, 'error' => 'Adjustment quantity cannot be zero.'];
    }

    $st = $pdo->prepare("
        SELECT b.*, m.name AS med_name
        FROM batches b JOIN medicines m ON m.id = b.medicine_id
        WHERE b.id = ?
    ");
    $st->execute([$batchId]);
    $batch = $st->fetch();
    if (!$batch) {
        return ['ok' => false, 'error' => 'Batch not found.'];
    }
    if (($batch['status'] ?? 'active') !== 'active') {
        return ['ok' => false, 'error' => 'Cannot adjust an inactive batch.'];
    }

    $prev = (int)$batch['quantity'];
    $new = $prev + $adjustmentQty;
    if ($new < 0) {
        return ['ok' => false, 'error' => "Adjustment would make quantity negative (current: {$prev})."];
    }

    $label = $reasons[$reasonKey];
    $detail = $label . ($notes !== '' ? (': ' . $notes) : '');

    $pdo->prepare("UPDATE batches SET quantity = ? WHERE id = ?")->execute([$new, $batchId]);

    // Damaged / disposed / expired write-offs leave stock unavailable for sale (qty already reduced).
    if (in_array($reasonKey, ['damaged', 'disposed', 'expired'], true) && $new === 0) {
        // Keep status active with 0 qty so filters still work; qty=0 means not sellable.
    }

    recordStockMovement(
        $pdo,
        (int)$batch['medicine_id'],
        $batchId,
        'adjustment',
        $adjustmentQty,
        $detail,
        'stock_adjustment',
        $batchId,
        $batch['batch_number'],
        $userId,
        $prev
    );

    if (function_exists('auditLog')) {
        auditLog($pdo, 'stock_adjustment', 'batch', $batchId,
            "{$batch['med_name']} batch {$batch['batch_number']}: {$prev} → {$new} ({$detail})");
    }

    return [
        'ok' => true,
        'previous' => $prev,
        'adjustment' => $adjustmentQty,
        'new' => $new,
        'message' => "Stock adjusted: {$prev} → {$new} ({$label}).",
    ];
}

/**
 * Soft-deactivate a batch when it has sales/purchase history; hard-delete only when unused.
 */
function deleteOrDeactivateBatch(PDO $pdo, int $batchId, ?int $userId = null): array {
    $st = $pdo->prepare("
        SELECT b.*, m.name AS med_name
        FROM batches b JOIN medicines m ON m.id = b.medicine_id
        WHERE b.id = ?
    ");
    $st->execute([$batchId]);
    $batch = $st->fetch();
    if (!$batch) {
        return ['ok' => false, 'error' => 'Batch not found (it may have already been deleted).'];
    }

    $hasHistory = batchHasHistoricalLinks($pdo, $batchId);
    if ($hasHistory) {
        $prev = (int)$batch['quantity'];
        if ($prev > 0) {
            $pdo->prepare("UPDATE batches SET quantity = 0, status = 'inactive' WHERE id = ?")->execute([$batchId]);
            recordStockMovement(
                $pdo,
                (int)$batch['medicine_id'],
                $batchId,
                'deactivate',
                -$prev,
                'Batch deactivated (history preserved)',
                'batch',
                $batchId,
                $batch['batch_number'],
                $userId,
                $prev
            );
        } else {
            $pdo->prepare("UPDATE batches SET status = 'inactive' WHERE id = ?")->execute([$batchId]);
        }
        if (function_exists('auditLog')) {
            auditLog($pdo, 'batch_deactivate', 'batch', $batchId,
                "Deactivated batch {$batch['batch_number']} of {$batch['med_name']} (history preserved)");
        }
        return ['ok' => true, 'mode' => 'deactivate', 'message' => 'Batch deactivated (linked to sales/purchases — history preserved).'];
    }

    // No history — safe hard delete.
    $pdo->prepare("DELETE FROM batches WHERE id = ?")->execute([$batchId]);
    if (function_exists('auditLog')) {
        auditLog($pdo, 'batch_delete', 'batch', $batchId,
            "Deleted batch {$batch['batch_number']} of {$batch['med_name']} (qty={$batch['quantity']})");
    }
    return ['ok' => true, 'mode' => 'delete', 'message' => 'Batch removed.'];
}

function inventorySearchSql(string $aliasMed = 'm', string $aliasBatch = 'b', string $aliasCat = 'c'): string {
    return "(
        {$aliasMed}.name LIKE ?
        OR {$aliasMed}.generic_name LIKE ?
        OR COALESCE({$aliasMed}.brand_name, '') LIKE ?
        OR {$aliasBatch}.batch_number LIKE ?
        OR COALESCE({$aliasCat}.name, '') LIKE ?
        OR COALESCE({$aliasMed}.barcode, '') LIKE ?
        OR COALESCE({$aliasMed}.sku, '') LIKE ?
    )";
}

function inventorySearchParams(string $search): array {
    $like = '%' . $search . '%';
    return [$like, $like, $like, $like, $like, $like, $like];
}

/** Dynamic PDF/Print title from current inventory filters. */
function inventoryReportTitle(array $ctx): string {
    $parts = [];
    $type = $ctx['type'] ?? '';
    $catName = $ctx['cat_name'] ?? '';
    $filter = $ctx['filter'] ?? 'all';
    $withinLabel = $ctx['within_label'] ?? '';
    $search = trim((string)($ctx['search'] ?? ''));

    $typeLabels = [
        'medicine' => 'MEDICATION',
        'cosmetic' => 'COSMETICS',
        'equipment' => 'EQUIPMENT',
    ];
    if ($type && isset($typeLabels[$type])) {
        $parts[] = $typeLabels[$type];
    }
    if ($catName !== '') {
        $parts[] = strtoupper($catName);
    }

    $filterTitle = match ($filter) {
        'low' => 'LOW STOCK INVENTORY REPORT',
        'out' => 'OUT OF STOCK INVENTORY REPORT',
        'expired' => 'EXPIRED INVENTORY REPORT',
        'expiring' => $withinLabel !== ''
            ? 'PRODUCTS EXPIRING WITHIN ' . strtoupper($withinLabel)
            : 'PRODUCTS EXPIRING SOON',
        default => 'INVENTORY REPORT',
    };

    if ($parts) {
        $prefix = implode(' — ', $parts);
        if ($filter === 'all') {
            return $prefix . ' — INVENTORY REPORT';
        }
        // e.g. COSMETICS — OUT OF STOCK REPORT
        $suffix = match ($filter) {
            'low' => 'LOW STOCK REPORT',
            'out' => 'OUT OF STOCK REPORT',
            'expired' => 'EXPIRED REPORT',
            'expiring' => $withinLabel !== ''
                ? 'EXPIRING WITHIN ' . strtoupper($withinLabel)
                : 'EXPIRING SOON REPORT',
            default => 'INVENTORY REPORT',
        };
        $title = $prefix . ' — ' . $suffix;
    } else {
        $title = $filterTitle;
    }

    if ($search !== '') {
        $title .= ' — SEARCH: ' . strtoupper($search);
    }
    return $title;
}

function inventoryPerPageOptions(): array {
    return [25, 50, 100];
}

function inventoryParsePerPage(?string $raw): int {
    $n = (int)$raw;
    return in_array($n, inventoryPerPageOptions(), true) ? $n : 25;
}

/**
 * Numbered pagination: Previous | 1 | 2 | 3 | … | Next
 */
function renderInventoryPagination(int $page, int $totalPages, string $baseUrl): void {
    if ($totalPages <= 1) {
        return;
    }
    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    echo '<div class="pagination" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:center;padding:16px;">';
    if ($page > 1) {
        echo '<a class="btn btn-ghost btn-sm" href="' . htmlspecialchars($baseUrl . $sep . 'page=' . ($page - 1)) . '">Previous</a>';
    } else {
        echo '<span class="btn btn-ghost btn-sm" style="opacity:.4;pointer-events:none;">Previous</span>';
    }

    $window = 2;
    $shown = [];
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i === 1 || $i === $totalPages || ($i >= $page - $window && $i <= $page + $window)) {
            $shown[] = $i;
        }
    }
    $last = 0;
    foreach ($shown as $i) {
        if ($last && $i > $last + 1) {
            echo '<span style="padding:0 4px;color:var(--text-300);">…</span>';
        }
        $cls = $i === $page ? 'btn-primary' : 'btn-ghost';
        echo '<a class="btn ' . $cls . ' btn-sm" href="' . htmlspecialchars($baseUrl . $sep . 'page=' . $i) . '">' . $i . '</a>';
        $last = $i;
    }

    if ($page < $totalPages) {
        echo '<a class="btn btn-ghost btn-sm" href="' . htmlspecialchars($baseUrl . $sep . 'page=' . ($page + 1)) . '">Next</a>';
    } else {
        echo '<span class="btn btn-ghost btn-sm" style="opacity:.4;pointer-events:none;">Next</span>';
    }
    echo '</div>';
}

function fetchStockHistory(PDO $pdo, int $medicineId, ?int $batchId = null): array {
    $rows = [];

    // Explicit ledger rows (adjustments, voids, returns recorded via stock_movements)
    $sql = "
        SELECT sm.*, u.full_name AS user_name, b.batch_number
        FROM stock_movements sm
        LEFT JOIN users u ON u.id = sm.user_id
        LEFT JOIN batches b ON b.id = sm.batch_id
        WHERE sm.medicine_id = ?
    ";
    $params = [$medicineId];
    if ($batchId) {
        $sql .= " AND sm.batch_id = ?";
        $params[] = $batchId;
    }
    $sql .= " ORDER BY sm.created_at ASC, sm.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'date' => $r['created_at'],
            'type' => $r['movement_type'],
            'qty_in' => (int)$r['quantity_in'],
            'qty_out' => (int)$r['quantity_out'],
            'balance' => $r['balance_after'],
            'user' => $r['user_name'] ?: '—',
            'reference' => $r['reference'] ?: '—',
            'reason' => $r['reason'] ?: '',
            'batch_number' => $r['batch_number'] ?? '',
            'source' => 'ledger',
        ];
    }

    // Reconstruct classic purchase/sale/return if no ledger yet for those events
    if (!$batchId) {
        $pur = $pdo->prepare("
            SELECT p.purchase_number AS reference, COALESCE(p.purchase_date, date(p.created_at)) AS date,
                   pi.quantity AS qty_in, 0 AS qty_out, 'purchase' AS type,
                   COALESCE(u.full_name, '—') AS user_name, '' AS reason, pi.batch_number
            FROM purchase_items pi
            JOIN purchases p ON p.id = pi.purchase_id
            LEFT JOIN users u ON u.id = p.created_by
            WHERE pi.medicine_id = ?
            ORDER BY p.purchase_date ASC
        ");
        $pur->execute([$medicineId]);
        foreach ($pur->fetchAll() as $r) {
            $rows[] = [
                'date' => $r['date'],
                'type' => 'purchase',
                'qty_in' => (int)$r['qty_in'],
                'qty_out' => 0,
                'balance' => null,
                'user' => $r['user_name'],
                'reference' => $r['reference'] ?: '—',
                'reason' => '',
                'batch_number' => $r['batch_number'] ?? '',
                'source' => 'reconstructed',
            ];
        }

        $sal = $pdo->prepare("
            SELECT s.invoice_number AS reference, COALESCE(s.sale_at, s.created_at) AS date,
                   0 AS qty_in, si.quantity AS qty_out, 'sale' AS type,
                   COALESCE(u.full_name, '—') AS user_name, '' AS reason,
                   COALESCE(si.batch_number, b.batch_number) AS batch_number
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN batches b ON b.id = si.batch_id
            WHERE si.medicine_id = ? AND COALESCE(s.status, 'active') != 'voided'
            ORDER BY COALESCE(s.sale_at, s.created_at) ASC
        ");
        $sal->execute([$medicineId]);
        foreach ($sal->fetchAll() as $r) {
            $rows[] = [
                'date' => $r['date'],
                'type' => 'sale',
                'qty_in' => 0,
                'qty_out' => (int)$r['qty_out'],
                'balance' => null,
                'user' => $r['user_name'],
                'reference' => $r['reference'] ?: '—',
                'reason' => '',
                'batch_number' => $r['batch_number'] ?? '',
                'source' => 'reconstructed',
            ];
        }

        $ret = $pdo->prepare("
            SELECT COALESCE(s.invoice_number, '—') AS reference, sr.created_at AS date,
                   sr.quantity AS qty_in, 0 AS qty_out, 'sale_return' AS type,
                   COALESCE(u.full_name, '—') AS user_name, COALESCE(sr.reason, '') AS reason,
                   COALESCE(b.batch_number, '') AS batch_number
            FROM sale_returns sr
            LEFT JOIN sales s ON s.id = sr.sale_id
            LEFT JOIN users u ON u.id = sr.created_by
            LEFT JOIN batches b ON b.id = sr.batch_id
            WHERE sr.medicine_id = ?
            ORDER BY sr.created_at ASC
        ");
        $ret->execute([$medicineId]);
        foreach ($ret->fetchAll() as $r) {
            $rows[] = [
                'date' => $r['date'],
                'type' => 'sale_return',
                'qty_in' => (int)$r['qty_in'],
                'qty_out' => 0,
                'balance' => null,
                'user' => $r['user_name'],
                'reference' => $r['reference'],
                'reason' => $r['reason'],
                'batch_number' => $r['batch_number'] ?? '',
                'source' => 'reconstructed',
            ];
        }

        $pret = $pdo->prepare("
            SELECT pr.return_number AS reference, pr.return_date AS date,
                   0 AS qty_in, pri.quantity AS qty_out, 'purchase_return' AS type,
                   COALESCE(u.full_name, '—') AS user_name, COALESCE(pr.reason, '') AS reason,
                   COALESCE(b.batch_number, '') AS batch_number
            FROM purchase_return_items pri
            JOIN purchase_returns pr ON pr.id = pri.return_id
            LEFT JOIN users u ON u.id = pr.created_by
            LEFT JOIN batches b ON b.id = pri.batch_id
            WHERE pri.medicine_id = ?
            ORDER BY pr.return_date ASC
        ");
        $pret->execute([$medicineId]);
        foreach ($pret->fetchAll() as $r) {
            $rows[] = [
                'date' => $r['date'],
                'type' => 'purchase_return',
                'qty_in' => 0,
                'qty_out' => (int)$r['qty_out'],
                'balance' => null,
                'user' => $r['user_name'],
                'reference' => $r['reference'] ?: '—',
                'reason' => $r['reason'],
                'batch_number' => $r['batch_number'] ?? '',
                'source' => 'reconstructed',
            ];
        }
    }

    // Prefer ledger when present for adjustments; merge reconstructed + ledger carefully.
    // Dedupe: if we have ledger adjustments, keep reconstructed purchase/sale too (they aren't double-written yet).
    usort($rows, function ($a, $b) {
        $cmp = strcmp((string)$a['date'], (string)$b['date']);
        return $cmp !== 0 ? $cmp : strcmp((string)$a['type'], (string)$b['type']);
    });

    $balance = 0;
    foreach ($rows as &$r) {
        if ($r['balance'] === null) {
            $balance += (int)$r['qty_in'] - (int)$r['qty_out'];
            $r['balance'] = $balance;
        } else {
            $balance = (int)$r['balance'];
        }
    }
    unset($r);

    return $rows;
}

function voidSale(PDO $pdo, int $saleId, string $reason, ?int $userId = null): array {
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'A void reason is required.'];
    }
    $st = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
    $st->execute([$saleId]);
    $sale = $st->fetch();
    if (!$sale) {
        return ['ok' => false, 'error' => 'Sale not found.'];
    }
    if (($sale['status'] ?? 'active') === 'voided') {
        return ['ok' => false, 'error' => 'Sale is already voided.'];
    }

    $pdo->beginTransaction();
    try {
        $items = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
        $items->execute([$saleId]);
        foreach ($items->fetchAll() as $si) {
            if ($si['batch_id']) {
                $bq = $pdo->prepare("SELECT quantity, medicine_id, batch_number FROM batches WHERE id = ?");
                $bq->execute([(int)$si['batch_id']]);
                $batch = $bq->fetch();
                if ($batch) {
                    $prev = (int)$batch['quantity'];
                    $qty = (int)$si['quantity'];
                    $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id = ?")
                        ->execute([$qty, (int)$si['batch_id']]);
                    recordStockMovement(
                        $pdo,
                        (int)$si['medicine_id'],
                        (int)$si['batch_id'],
                        'void_restore',
                        $qty,
                        'Void sale: ' . $reason,
                        'sale',
                        $saleId,
                        $sale['invoice_number'],
                        $userId,
                        $prev
                    );
                }
            }
        }

        // Reverse payment impact: zero paid amounts; keep payment_history with a reversing note.
        $net = saleNetAmount($sale);
        if ((float)$sale['paid_amount'] > 0) {
            $pdo->prepare("
                INSERT INTO payment_history (sale_id, customer_id, amount, payment_method, reference_number, received_by, notes)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([
                $saleId,
                $sale['customer_id'] ?: null,
                -1 * (float)$sale['paid_amount'],
                $sale['payment_method'],
                'VOID-' . $sale['invoice_number'],
                $userId,
                'Void reversal: ' . $reason,
            ]);
        }

        $pdo->prepare("
            UPDATE sales SET
                status = 'voided',
                voided_at = CURRENT_TIMESTAMP,
                voided_by = ?,
                void_reason = ?,
                paid_amount = 0,
                remaining_balance = 0,
                payment_status = 'voided'
            WHERE id = ?
        ")->execute([$userId, $reason, $saleId]);

        if (!empty($sale['customer_id']) && function_exists('refreshCustomerCredit')) {
            refreshCustomerCredit($pdo, (int)$sale['customer_id']);
        }

        if (function_exists('auditLog')) {
            auditLog($pdo, 'sale_void', 'sale', $saleId,
                "Voided {$sale['invoice_number']} (net {$net}): {$reason}");
        }

        $pdo->commit();
        return ['ok' => true, 'message' => 'Sale voided. Inventory restored and marked VOID.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Correct a sale line quantity (e.g. 20 → 15). Restores/deducts batch stock and recalculates totals.
 */
function correctSaleItemQty(PDO $pdo, int $saleItemId, int $newQty, string $reason, ?int $userId = null): array {
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'A correction reason is required.'];
    }
    if ($newQty < 0) {
        return ['ok' => false, 'error' => 'Quantity cannot be negative.'];
    }

    $st = $pdo->prepare("
        SELECT si.*, s.status AS sale_status, s.invoice_number, s.id AS sid,
               s.discount AS sale_discount, s.tax AS sale_tax, s.payment_method, s.customer_id, s.paid_amount
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE si.id = ?
    ");
    $st->execute([$saleItemId]);
    $item = $st->fetch();
    if (!$item) {
        return ['ok' => false, 'error' => 'Sale item not found.'];
    }
    if (($item['sale_status'] ?? 'active') === 'voided') {
        return ['ok' => false, 'error' => 'Cannot correct a voided sale.'];
    }

    $oldQty = (int)$item['quantity'];
    if ($newQty === $oldQty) {
        return ['ok' => false, 'error' => 'Quantity is unchanged.'];
    }
    $delta = $oldQty - $newQty; // positive => return to stock

    $pdo->beginTransaction();
    try {
        if ($item['batch_id'] && $delta !== 0) {
            $bq = $pdo->prepare("SELECT quantity FROM batches WHERE id = ?");
            $bq->execute([(int)$item['batch_id']]);
            $prev = (int)$bq->fetchColumn();
            if ($delta < 0 && $prev < abs($delta)) {
                throw new RuntimeException('Not enough stock in batch to increase sale quantity.');
            }
            $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id = ?")
                ->execute([$delta, (int)$item['batch_id']]);
            recordStockMovement(
                $pdo,
                (int)$item['medicine_id'],
                (int)$item['batch_id'],
                'sale_correction',
                $delta,
                $reason,
                'sale',
                (int)$item['sid'],
                $item['invoice_number'],
                $userId,
                $prev
            );
        }

        $unit = (float)$item['unit_price'];
        $newSub = round($unit * $newQty, 2);
        if ($newQty === 0) {
            $pdo->prepare("DELETE FROM sale_items WHERE id = ?")->execute([$saleItemId]);
        } else {
            // Scale line discount/tax proportionally
            $scale = $oldQty > 0 ? ($newQty / $oldQty) : 0;
            $newDisc = round((float)($item['discount'] ?? 0) * $scale, 2);
            $newTax = round((float)($item['tax'] ?? 0) * $scale, 2);
            $pdo->prepare("UPDATE sale_items SET quantity=?, subtotal=?, discount=?, tax=? WHERE id=?")
                ->execute([$newQty, $newSub, $newDisc, $newTax, $saleItemId]);
        }

        // Recalculate sale totals from remaining lines
        $lines = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
        $lines->execute([(int)$item['sid']]);
        $all = $lines->fetchAll();
        $subtotal = array_sum(array_map(fn($l) => (float)$l['subtotal'], $all));
        $lineDisc = array_sum(array_map(fn($l) => (float)($l['discount'] ?? 0), $all));
        $lineTax = array_sum(array_map(fn($l) => (float)($l['tax'] ?? 0), $all));
        // Prefer summed line discount/tax; fall back to keeping invoice-level if lines had none
        $discount = $lineDisc > 0 ? $lineDisc : min((float)$item['sale_discount'], $subtotal);
        $tax = $lineTax > 0 ? $lineTax : 0;
        $net = round($subtotal - $discount + $tax, 2);
        $paid = min((float)$item['paid_amount'], $net);
        $remaining = max(0, $net - $paid);
        $status = computePaymentStatus($net, $paid, $item['payment_method']);

        $pdo->prepare("
            UPDATE sales SET total_amount=?, discount=?, tax=?, paid_amount=?, remaining_balance=?, payment_status=?
            WHERE id=?
        ")->execute([$subtotal, $discount, $tax, $paid, $remaining, $status, (int)$item['sid']]);

        if (!empty($item['customer_id']) && function_exists('refreshCustomerCredit')) {
            refreshCustomerCredit($pdo, (int)$item['customer_id']);
        }

        if (function_exists('auditLog')) {
            auditLog($pdo, 'sale_correct', 'sale', (int)$item['sid'],
                "Corrected item #{$saleItemId} qty {$oldQty}→{$newQty} on {$item['invoice_number']}: {$reason}");
        }

        $pdo->commit();
        return ['ok' => true, 'message' => "Sale corrected: quantity {$oldQty} → {$newQty}."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function processSaleReturn(
    PDO $pdo,
    int $saleId,
    int $saleItemId,
    int $qty,
    string $reasonCode,
    bool $resalable,
    string $notes = '',
    ?int $userId = null
): array {
    $reasons = saleReturnReasons();
    if (!isset($reasons[$reasonCode])) {
        return ['ok' => false, 'error' => 'Invalid return reason.'];
    }
    if ($qty <= 0) {
        return ['ok' => false, 'error' => 'Return quantity must be positive.'];
    }

    $st = $pdo->prepare("
        SELECT si.*, s.status AS sale_status, s.invoice_number, s.customer_id, s.payment_method, s.paid_amount
        FROM sale_items si JOIN sales s ON s.id = si.sale_id
        WHERE si.id = ? AND si.sale_id = ?
    ");
    $st->execute([$saleItemId, $saleId]);
    $item = $st->fetch();
    if (!$item) {
        return ['ok' => false, 'error' => 'Sale item not found.'];
    }
    if (($item['sale_status'] ?? 'active') === 'voided') {
        return ['ok' => false, 'error' => 'Cannot return items from a voided sale.'];
    }

    $already = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM sale_returns WHERE sale_item_id = ?");
    $already->execute([$saleItemId]);
    $returned = (int)$already->fetchColumn();
    $available = (int)$item['quantity'] - $returned;
    if ($qty > $available) {
        return ['ok' => false, 'error' => "Only {$available} unit(s) available to return."];
    }

    $unit = (float)$item['unit_price'];
    $refund = round($unit * $qty, 2);
    $reasonLabel = $reasons[$reasonCode] . ($notes !== '' ? (': ' . $notes) : '');

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO sale_returns (sale_id, medicine_id, batch_id, quantity, amount, reason, reason_code, resalable, created_by, refund_amount, sale_item_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $saleId, (int)$item['medicine_id'], $item['batch_id'] ?: null, $qty, $refund,
            $reasonLabel, $reasonCode, $resalable ? 1 : 0, $userId, $refund, $saleItemId,
        ]);

        if ($resalable && $item['batch_id']) {
            $bq = $pdo->prepare("SELECT quantity FROM batches WHERE id = ?");
            $bq->execute([(int)$item['batch_id']]);
            $prev = (int)$bq->fetchColumn();
            $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id = ?")
                ->execute([$qty, (int)$item['batch_id']]);
            recordStockMovement(
                $pdo, (int)$item['medicine_id'], (int)$item['batch_id'],
                'sale_return', $qty, $reasonLabel, 'sale', $saleId,
                $item['invoice_number'], $userId, $prev
            );
        } else {
            // Damaged/unfit — do not add to sellable stock; ledger as write-off of returned units
            $pdo->prepare("
                INSERT INTO stock_movements (
                    medicine_id, batch_id, movement_type, quantity_in, quantity_out,
                    previous_qty, new_qty, balance_after, reason,
                    reference_type, reference_id, reference, user_id
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                (int)$item['medicine_id'],
                $item['batch_id'] ? (int)$item['batch_id'] : null,
                'sale_return_unsellable',
                0, $qty, null, null, null,
                $reasonLabel . ' (not restocked)',
                'sale', $saleId, $item['invoice_number'], $userId,
            ]);
        }

        // Refund payment record
        if ($refund > 0) {
            $pdo->prepare("
                INSERT INTO payment_history (sale_id, customer_id, amount, payment_method, reference_number, received_by, notes)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([
                $saleId, $item['customer_id'] ?: null, -1 * $refund, $item['payment_method'],
                'RET-' . $item['invoice_number'], $userId, 'Return refund: ' . $reasonLabel,
            ]);
            $newPaid = max(0, (float)$item['paid_amount'] - $refund);
            $pdo->prepare("UPDATE sales SET paid_amount = ? WHERE id = ?")
                ->execute([$newPaid, $saleId]);
        }

        if (!empty($item['customer_id']) && function_exists('refreshCustomerCredit')) {
            refreshCustomerCredit($pdo, (int)$item['customer_id']);
        }

        if (function_exists('auditLog')) {
            auditLog($pdo, 'sale_return', 'sale', $saleId,
                "Return {$qty} of item #{$saleItemId} on {$item['invoice_number']}: {$reasonLabel}");
        }

        $pdo->commit();
        return ['ok' => true, 'message' => 'Return recorded successfully.' . ($resalable ? ' Stock restored.' : ' Stock not restocked (unfit).')];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function expiredStockAction(
    PDO $pdo,
    int $batchId,
    string $action,
    int $qty,
    string $notes = '',
    ?int $userId = null
): array {
    $map = [
        'return_supplier' => 'returned_supplier',
        'damaged'         => 'damaged',
        'disposed'        => 'disposed',
        'adjust'          => 'other',
    ];
    if (!isset($map[$action])) {
        return ['ok' => false, 'error' => 'Invalid expired-stock action.'];
    }
    if ($qty <= 0) {
        return ['ok' => false, 'error' => 'Quantity must be positive.'];
    }
    // These actions remove from sellable stock
    return adjustBatchStock($pdo, $batchId, -1 * $qty, $map[$action], $notes, $userId);
}
