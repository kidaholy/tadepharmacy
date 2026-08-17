<?php
/**
 * Seed demo data for testing reports, sales, purchases, credit, and inventory.
 * CLI:  php seed_demo.php [--force]
 * Web:  Settings → Load Demo Data
 *
 * The dataset spans the last 12 months so "This Month", "Last 30 Days",
 * "This Year" and custom range reports all show data immediately:
 *   - 4 suppliers, 3 demo cashiers, 8 customers
 *   - ~26 products across Medication / Cosmetic / Medical Equipment /
 *     Medical Supply / Supplement / Personal Care
 *   - purchase orders over 12 months (linked to real batches, with payments —
 *     some full, some partial, some unpaid/overdue) plus a couple of
 *     purchase returns
 *   - ~160 sales across 12 months (cash, Telebirr, CBE, Abyssinia, credit)
 *     with credit collections and sale returns, expenses every month,
 *     and deterministic inventory scenarios (expired, near-expiry, low
 *     stock, overstock, out of stock)
 *
 * Each month is processed chronologically: that month's purchases (stock in)
 * are created before that month's sales (stock out), so batch history,
 * purchase history and sales history stay consistent.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sales_lib.php';
require_once __DIR__ . '/customers_lib.php';

function seedDemoData(PDO $pdo, bool $force = false): array {
    if (getSetting('demo_data_loaded', '0') === '1' && !$force) {
        return [
            'ok'      => false,
            'message' => 'Demo data already loaded. Use --force or check "Replace" to seed again.',
        ];
    }

    $adminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$adminId) {
        $adminId = (int)$pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    }

    $pdo->beginTransaction();
    try {
        if ($force) {
            demoClearTransactional($pdo);
        }

        $stats = [
            'suppliers' => demoSeedSuppliers($pdo),
            'users'     => demoSeedUsers($pdo),
            'customers' => demoSeedCustomers($pdo),
            'medicines' => demoSeedMedicines($pdo),
            'purchases' => ['purchases' => 0, 'batches' => 0, 'payments' => 0, 'returns' => 0],
            'sales'     => 0,
            'returns'   => 0,
            'expenses'  => 0,
        ];

        // Interleave purchases and sales month by month (oldest first).
        $events  = demoPurchaseEvents();
        $suppliers = $pdo->query("SELECT id FROM suppliers ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $salesCtx = demoSalesContext($pdo, $adminId);
        $purchaseSeq = 0;

        for ($month = 12; $month >= 0; $month--) {
            foreach (($events[$month] ?? []) as $order) {
                $purchaseSeq++;
                $d = demoCreatePurchaseOrder($pdo, $order, $purchaseSeq, $suppliers, $month);
                foreach ($d as $k => $v) $stats['purchases'][$k] += $v;
            }
            $stats['sales'] += demoSeedMonthSales($pdo, $month, $salesCtx, $adminId);
        }

        // A few sales today for the dashboard.
        $stats['sales'] += demoSeedTodaySales($pdo, $salesCtx, $adminId);
        $stats['returns'] = demoSeedSaleReturns($pdo);
        $stats['expenses'] = demoSeedExpenses($pdo, $adminId);
        demoSeedCurrentStock($pdo);

        foreach ($pdo->query("SELECT id FROM customers")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            refreshCustomerCredit($pdo, (int)$cid);
        }

        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('demo_data_loaded', ?)")
            ->execute(['1']);

        $pdo->commit();

        $msg = sprintf(
            'Demo data loaded: %d suppliers, %d users, %d customers, %d medicines, %d purchases, %d batches, %d sales, %d sale returns, %d expenses.',
            $stats['suppliers'], $stats['users'], $stats['customers'], $stats['medicines'],
            $stats['purchases']['purchases'], $stats['purchases']['batches'],
            $stats['sales'], $stats['returns'], $stats['expenses']
        );

        return ['ok' => true, 'message' => $msg, 'stats' => $stats];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Demo seed failed: ' . $e->getMessage()];
    }
}

function demoCustomerPhones(): array {
    return ['0911000001', '0911000002', '0911000003', '0911000004', '0911000005',
            '0911000006', '0911000007', '0911000008'];
}

function demoSupplierNames(): array {
    return ['Addis Pharma Distributors', 'Ethio Med Supply', 'Horizon Healthcare Imports', 'Kaleb Pharma Wholesale'];
}

function demoUserNames(): array {
    return ['demo_cashier1', 'demo_cashier2', 'demo_cashier3'];
}

/** Category names the demo may create (cleaned up on --clear). */
function demoCreatedCategoryNames(): array {
    return ['Medical Supply', 'Personal Care'];
}

function clearDemoData(PDO $pdo): array {
    $pdo->beginTransaction();
    try {
        $stats = demoClearTransactional($pdo);
        $pdo->commit();
        $msg = sprintf(
            'Demo data removed: %d sales, %d purchases, %d expenses, %d batches, %d medicines, %d customers, %d suppliers, %d users.',
            $stats['sales'], $stats['purchases'], $stats['expenses'], $stats['batches'],
            $stats['medicines'], $stats['customers'], $stats['suppliers'], $stats['users']
        );
        return ['ok' => true, 'message' => $msg, 'stats' => $stats];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Failed to remove demo data: ' . $e->getMessage()];
    }
}

function demoClearTransactional(PDO $pdo): array {
    $stats = [
        'sales'      => 0,
        'purchases'  => 0,
        'expenses'   => 0,
        'batches'    => 0,
        'medicines'  => 0,
        'customers'  => 0,
        'suppliers'  => 0,
        'users'      => 0,
    ];

    $demoSaleIds = $pdo->query("SELECT id FROM sales WHERE invoice_number LIKE 'DEMO-INV-%'")->fetchAll(PDO::FETCH_COLUMN);
    $stats['sales'] = count($demoSaleIds);
    if ($demoSaleIds) {
        $in = implode(',', array_map('intval', $demoSaleIds));
        $pdo->exec("DELETE FROM sale_returns WHERE sale_id IN ($in)");
        $pdo->exec("DELETE FROM payment_history WHERE sale_id IN ($in)");
        $pdo->exec("DELETE FROM sale_items WHERE sale_id IN ($in)");
        $pdo->exec("DELETE FROM sales WHERE id IN ($in)");
    }

    $demoPurchaseIds = $pdo->query("SELECT id FROM purchases WHERE reference LIKE 'DEMO-PUR-%'")->fetchAll(PDO::FETCH_COLUMN);
    $stats['purchases'] = count($demoPurchaseIds);
    if ($demoPurchaseIds) {
        $pin = implode(',', array_map('intval', $demoPurchaseIds));
        $pdo->exec("DELETE FROM purchase_returns WHERE purchase_id IN ($pin)");
        $pdo->exec("DELETE FROM purchase_payments WHERE purchase_id IN ($pin)");
        $pdo->exec("DELETE FROM purchase_items WHERE purchase_id IN ($pin)");
        $pdo->exec("DELETE FROM purchases WHERE id IN ($pin)");
    }

    $stats['expenses'] = (int)$pdo->query("SELECT COUNT(*) FROM operating_expenses WHERE description LIKE 'Demo%'")->fetchColumn();
    $pdo->exec("DELETE FROM operating_expenses WHERE description LIKE 'Demo%'");

    $stats['batches'] = (int)$pdo->query("
        SELECT COUNT(*) FROM batches
        WHERE batch_number LIKE 'DEMO-B-%'
           OR batch_number LIKE 'DEMO-EXP-%'
           OR batch_number LIKE 'DEMO-CUR-%'
    ")->fetchColumn();
    $pdo->exec("
        DELETE FROM batches
        WHERE batch_number LIKE 'DEMO-B-%'
           OR batch_number LIKE 'DEMO-EXP-%'
           OR batch_number LIKE 'DEMO-CUR-%'
    ");

    $stats['medicines'] = (int)$pdo->query("SELECT COUNT(*) FROM medicines WHERE sku LIKE 'DEMO-%'")->fetchColumn();
    $pdo->exec("DELETE FROM medicines WHERE sku LIKE 'DEMO-%'");

    // Demo-created categories (only when no products reference them anymore).
    $catNames = demoCreatedCategoryNames();
    $cn = implode(',', array_fill(0, count($catNames), '?'));
    $pdo->prepare("DELETE FROM categories WHERE name IN ($cn) AND NOT EXISTS (SELECT 1 FROM medicines m WHERE m.category_id = categories.id)")->execute($catNames);

    $phones = demoCustomerPhones();
    $ph = implode(',', array_fill(0, count($phones), '?'));
    $custStmt = $pdo->prepare("SELECT id FROM customers WHERE phone IN ($ph)");
    $custStmt->execute($phones);
    $demoCustomerIds = $custStmt->fetchAll(PDO::FETCH_COLUMN);
    $stats['customers'] = count($demoCustomerIds);
    if ($demoCustomerIds) {
        $cin = implode(',', array_map('intval', $demoCustomerIds));
        $pdo->exec("DELETE FROM payment_history WHERE customer_id IN ($cin)");
        $pdo->exec("DELETE FROM customers WHERE id IN ($cin)");
    }

    $users = demoUserNames();
    $un = implode(',', array_fill(0, count($users), '?'));
    $userStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username IN ($un)");
    $userStmt->execute($users);
    $stats['users'] = (int)$userStmt->fetchColumn();
    $pdo->prepare("DELETE FROM users WHERE username IN ($un)")->execute($users);

    $suppliers = demoSupplierNames();
    $sph = implode(',', array_fill(0, count($suppliers), '?'));
    $supCount = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE name IN ($sph)");
    $supCount->execute($suppliers);
    $stats['suppliers'] = (int)$supCount->fetchColumn();
    $pdo->prepare("DELETE FROM suppliers WHERE name IN ($sph)")->execute($suppliers);

    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('demo_data_loaded', ?)")->execute(['0']);

    return $stats;
}

function demoSeedSuppliers(PDO $pdo): int {
    $rows = [
        ['Addis Pharma Distributors', '+251911100001', 'info@addispharma.et', 'Addis Ababa', 'Solomon T.'],
        ['Ethio Med Supply',          '+251911100002', 'sales@ethiomed.et', 'Bole, Addis Ababa', 'Hanna M.'],
        ['Horizon Healthcare Imports', '+251911100003', 'contact@horizonhc.et', 'Megenagna', 'Dawit A.'],
        ['Kaleb Pharma Wholesale',     '+251911100004', 'orders@kalebpharma.et', 'Kazanchis', 'Kaleb F.'],
    ];
    $n = 0;
    $ins = $pdo->prepare("INSERT OR IGNORE INTO suppliers (name, phone, email, address, contact_person) VALUES (?,?,?,?,?)");
    foreach ($rows as $r) {
        $ins->execute($r);
        if ($ins->rowCount()) $n++;
    }
    return $n ?: count($rows);
}

function demoSeedUsers(PDO $pdo): int {
    $rows = [
        ['demo_cashier1', 'Sara Getachew'],
        ['demo_cashier2', 'Mekdes Alemu'],
        ['demo_cashier3', 'Daniel Worku'],
    ];
    $n = 0;
    $ins = $pdo->prepare("INSERT OR IGNORE INTO users (username, password_hash, full_name, role) VALUES (?,?,?,?)");
    foreach ($rows as $r) {
        $ins->execute([$r[0], password_hash('demo123', PASSWORD_DEFAULT), $r[1], 'cashier']);
        $n += $ins->rowCount();
    }
    return $n ?: count($rows);
}

function demoSeedCustomers(PDO $pdo): int {
    $rows = [
        ['Abebe Kebede',       '0911000001', 50000],
        ['Tigist Haile',       '0911000002', 30000],
        ['Dr. Samuel Tadesse', '0911000003', 80000],
        ['Meron Assefa',       '0911000004', 25000],
        ['Yonas Girma',        '0911000005', 40000],
        ['Hana Bekele',        '0911000006', 20000],
        ['Dawit Tesfaye',      '0911000007', 60000],
        ['Selamawit Worku',    '0911000008', 35000],
    ];
    $n = 0;
    foreach ($rows as $r) {
        try {
            registerCustomer($pdo, $r[0], $r[1], (float)$r[2]);
            $n++;
        } catch (Throwable $e) {
            // already exists
        }
    }
    return $n ?: count($rows);
}

/**
 * Demo catalogue: [sku, name, generic, category, unit, buy, sell, reorder, product_type, weight].
 * weight = relative sales demand; 0 = never sold (expiry / slow-moving scenarios).
 */
function demoProductCatalogue(): array {
    return [
        ['DEMO-001', 'Amoxicillin 500mg',        'Amoxicillin',           'Antibiotics & Antimicrobials',    'strip',  45,  85,  30, 'medicine',  4],
        ['DEMO-002', 'Paracetamol 500mg',        'Paracetamol',           'Pain Relief & Anti-Inflammatories','strip', 12,  25,  50, 'medicine',  4],
        ['DEMO-003', 'Metformin 500mg',          'Metformin',             'Diabetes & Endocrine Care',       'strip',  55,  95,  40, 'medicine',  3],
        ['DEMO-004', 'Omeprazole 20mg',          'Omeprazole',            'Gastrointestinal Health',         'strip',  80,  140, 25, 'medicine',  3],
        ['DEMO-005', 'Cetirizine 10mg',          'Cetirizine',            'Respiratory & Allergy',           'strip',  25,  45,  30, 'medicine',  3],
        ['DEMO-006', 'Amlodipine 5mg',           'Amlodipine',            'Cardiovascular & Blood Health',   'strip',  40,  75,  35, 'medicine',  3],
        ['DEMO-007', 'Ibuprofen 400mg',          'Ibuprofen',             'Pain Relief & Anti-Inflammatories','strip', 30,  55,  40, 'medicine',  3],
        ['DEMO-008', 'Salbutamol Inhaler',       'Salbutamol',            'Respiratory & Allergy',           'puff',   180, 280, 15, 'medicine',  2],
        ['DEMO-009', 'Azithromycin 250mg',       'Azithromycin',          'Antibiotics & Antimicrobials',    'strip',  120, 195, 20, 'medicine',  2],
        ['DEMO-010', 'Losartan 50mg',            'Losartan',              'Cardiovascular & Blood Health',   'strip',  70,  120, 30, 'medicine',  2],
        ['DEMO-011', 'ORS Sachet',               'Oral Rehydration Salts', 'Gastrointestinal Health',        'sachet', 8,   15,  60, 'medicine',  3],
        ['DEMO-012', 'Vitamin C 500mg',          'Ascorbic Acid',         'Vitamins & Supplements',          'strip',  35,  60,  25, 'medicine',  3],
        ['DEMO-013', 'Multivitamin Tablets',     'Multivitamin',          'Vitamins & Supplements',          'strip',  50,  90,  20, 'medicine',  0],
        ['DEMO-014', 'Omega-3 Fish Oil',         'Fish Oil',              'Vitamins & Supplements',          'bottle', 250, 420, 10, 'medicine',  0],
        ['DEMO-015', 'Hydrocortisone Cream 1%',  'Hydrocortisone',        'Dermatology & Skin Care',         'tube',   95,  160, 10, 'medicine',  0],
        ['DEMO-016', 'Nivea Soft Cream 100ml',   'Nivea Soft',            'Skincare',                        'tube',   90,  150, 12, 'cosmetic',  1],
        ['DEMO-017', 'Sunscreen SPF 50',         'Sunscreen',             'Sunscreen',                       'tube',   110, 190, 10, 'cosmetic',  0],
        ['DEMO-018', 'Head & Shoulders 200ml',   'Anti-Dandruff Shampoo', 'Haircare',                        'bottle', 160, 260, 8,  'cosmetic',  1],
        ['DEMO-019', 'Matte Lipstick',           'Lipstick',              'Makeup',                          'pcs',    140, 230, 8,  'cosmetic',  1],
        ['DEMO-020', 'Digital Thermometer',      'Thermometer',           'Medical Equipment',               'pcs',    120, 220, 6,  'equipment', 1],
        ['DEMO-021', 'Blood Pressure Monitor',   'BP Monitor',            'Diagnostic Equipment',            'pcs',    850, 1400, 4, 'equipment', 1],
        ['DEMO-022', 'Glucometer Kit',           'Glucose Meter',         'Diagnostic Equipment',            'pcs',    600, 1050, 5, 'equipment', 1],
        ['DEMO-023', 'Disposable Syringes 5ml',  'Syringe',               'Medical Supply',                  'pcs',    3,   6,   100,'medicine',  2],
        ['DEMO-024', 'Disposable Gloves',        'Gloves',                'Medical Supply',                  'pcs',    4,   8,   80, 'medicine',  2],
        ['DEMO-025', 'Body Care Lotion 250ml',   'Moisturizing Lotion',   'Personal Care',                   'bottle', 85,  145, 10, 'medicine',  1],
        ['DEMO-026', 'Hygiene Hand Wash 500ml',  'Hand Wash',             'Personal Care',                   'bottle', 60,  105, 12, 'medicine',  1],
    ];
}

/** sku → buy price (fallback when no batch price exists yet). */
function demoBuyPrice(string $sku): float {
    foreach (demoProductCatalogue() as $m) {
        if ($m[0] === $sku) return (float)$m[5];
    }
    return 50;
}

/** sku → expiry months after purchase (used for expiry scenarios). */
function demoExpiryOverrides(): array {
    return [
        'DEMO-015' => 8,   // purchased ~9 months ago → expired now
        'DEMO-017' => 8.5, // → ~15 days from now
        'DEMO-013' => 9.5, // → ~75 days from now
    ];
}

function demoEnsureCategories(PDO $pdo): array {
    $needed = ['Other'];
    foreach (demoProductCatalogue() as $m) $needed[] = $m[3];
    $needed = array_values(array_unique($needed));
    $map = [];
    $ins = $pdo->prepare("INSERT OR IGNORE INTO categories (name, product_type) VALUES (?,?)");
    $get = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
    foreach ($needed as $name) {
        $ins->execute([$name, 'medicine']);
        $get->execute([$name]);
        $map[$name] = (int)$get->fetchColumn();
    }
    return $map;
}

function demoSeedMedicines(PDO $pdo): int {
    $cats = demoEnsureCategories($pdo);
    $ins = $pdo->prepare("
        INSERT INTO medicines (name, generic_name, category_id, unit, reorder_level, barcode, sku, product_type)
        SELECT ?, ?, ?, ?, ?, ?, ?, ?
        WHERE NOT EXISTS (SELECT 1 FROM medicines WHERE sku = ?)
    ");
    $added = 0;
    foreach (demoProductCatalogue() as $m) {
        [$sku, $name, $generic, $cat, $unit, $buy, $sell, $reorder, $ptype, $weight] = $m;
        $ins->execute([$name, $generic, $cats[$cat], $unit, $reorder, $sku, $sku, $ptype, $sku]);
        $added += $ins->rowCount();
    }
    return (int)$pdo->query("SELECT COUNT(*) FROM medicines WHERE sku LIKE 'DEMO-%'")->fetchColumn();
}

/**
 * Purchase timeline: for each product, split its total yearly purchase quantity
 * into 2–4 purchase events spread over the last 12 months. Returns
 * ['month' => [ ['sku'=>, 'qty'=>], ... ], ...] for months 12 → 0.
 */
function demoPurchaseEvents(): array {
    $events = [];
    foreach (demoProductCatalogue() as $m) {
        [$sku, , , , , , , , , $weight] = $m;

        $total = match ($weight) {
            4 => 210 + mt_rand(-20, 30),
            3 => 135 + mt_rand(-15, 25),
            2 => 75 + mt_rand(-10, 15),
            1 => 45 + mt_rand(-5, 10),
            default => 30 + mt_rand(0, 8),
        };
        $firstMonth = match ($weight) {
            4 => 12,
            3 => 10 + mt_rand(0, 2),
            2 => 8 + mt_rand(0, 3),
            1 => 6 + mt_rand(0, 4),
            default => 9 + mt_rand(0, 2),
        };
        $eventsCount = match ($weight) {
            4 => 4,
            3 => 3,
            2 => mt_rand(2, 3),
            1 => 2,
            default => 1,
        };

        // Fixed first purchase for the expiry-scenario products.
        if ($sku === 'DEMO-015') { $firstMonth = 9; $eventsCount = 1; }
        if ($sku === 'DEMO-017') { $firstMonth = 8; $eventsCount = 1; }
        if ($sku === 'DEMO-013') { $firstMonth = 7; $eventsCount = 1; }

        $months = [];
        if ($eventsCount === 1) {
            $months = [$firstMonth];
        } else {
            for ($i = 0; $i < $eventsCount; $i++) {
                $months[] = (int)round($firstMonth - ($firstMonth * $i) / max(1, $eventsCount - 1));
            }
            $months = array_values(array_unique($months));
        }

        $perEvent = $total / count($months);
        foreach ($months as $month) {
            $qty = (int)max(5, round($perEvent * (0.85 + mt_rand(0, 30) / 100)));
            $events[$month][] = ['sku' => $sku, 'qty' => $qty];
        }
    }

    // Bundle up to 2 events of the same month into one purchase order.
    $orders = [];
    foreach ($events as $month => $evs) {
        for ($i = 0; $i < count($evs); $i += 2) {
            $orders[$month][] = array_slice($evs, $i, 2);
        }
    }
    return $orders;
}

/**
 * Creates one purchase order with linked batches, a payment (full/partial/none)
 * and occasionally a purchase return. Returns per-order stats.
 */
function demoCreatePurchaseOrder(PDO $pdo, array $items, int $seq, array $suppliers, int $month): array {
    $stats = ['purchases' => 0, 'batches' => 0, 'payments' => 0, 'returns' => 0];

    $medIds = [];
    $medStmt = $pdo->prepare("SELECT id FROM medicines WHERE sku = ?");
    foreach ($items as $ev) {
        $medStmt->execute([$ev['sku']]);
        $id = (int)$medStmt->fetchColumn();
        if (!$id) continue;
        $medIds[$ev['sku']] = ['id' => $id, 'qty' => (int)$ev['qty']];
    }
    if (empty($medIds)) return $stats;

    // Timestamp the order within its month so purchases precede that month's sales.
    // Clamp current-month orders so they never land in the future.
    $date = date('Y-m-d H:i:s', strtotime("-$month months") + mt_rand(1, 27) * 86400 + mt_rand(9, 16) * 3600);
    if ($month === 0 && strtotime($date) > time()) {
        $date = date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 10) . ' hours'));
    }

    $sup = $suppliers[($seq - 1) % count($suppliers)];
    $subtotal = 0;
    $lineItems = [];
    foreach ($medIds as $sku => $mi) {
        $buy = demoBuyPrice($sku);
        $lineItems[] = ['id' => $mi['id'], 'qty' => $mi['qty'], 'buy' => $buy];
        $subtotal += $buy * $mi['qty'];
    }
    $discount = (mt_rand(1, 8) === 1) ? round($subtotal * 0.02, 2) : 0;
    $grand = round($subtotal - $discount, 2);
    $dueDate = date('Y-m-d', strtotime($date . ' +30 days'));

    $scenario = mt_rand(1, 100);
    $paidAmt = 0.0;
    $payDate = null;
    if ($scenario <= 40) {
        $paidAmt = $grand;
        $payDate = date('Y-m-d', strtotime($date . ' +' . mt_rand(0, 12) . ' days'));
    } elseif ($scenario <= 75) {
        $paidAmt = round($grand * (0.4 + mt_rand(0, 40) / 100), 2);
        $payDate = date('Y-m-d', strtotime($date . ' +' . mt_rand(5, 20) . ' days'));
    }

    $paymentStatus = $paidAmt >= $grand - 0.009 ? 'paid' : ($paidAmt > 0 ? 'partial' : 'unpaid');

    $pdo->prepare("
        INSERT INTO purchases (
            reference, supplier_id, purchase_number, purchase_date, due_date,
            subtotal, discount, tax, grand_total, total_paid, total_due, total_returned,
            payment_status, status, notes, created_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        'DEMO-PUR-' . $seq, $sup, 'DEMO-PUR-' . $seq,
        date('Y-m-d', strtotime($date)), $dueDate,
        $subtotal, $discount, 0, $grand, $paidAmt, $grand - $paidAmt, 0,
        $paymentStatus, 'received', 'Demo stock receiving', $date,
    ]);
    $pid = (int)$pdo->lastInsertId();
    $stats['purchases']++;

    $expiryOv = demoExpiryOverrides();
    $insI = $pdo->prepare("
        INSERT INTO purchase_items (
            purchase_id, medicine_id, batch_number, quantity, purchase_price,
            selling_price, expiry_date, manufacturing_date
        ) VALUES (?,?,?,?,?,?,?,?)
    ");
    $insB = $pdo->prepare("
        INSERT INTO batches (
            medicine_id, batch_number, quantity, purchase_price, selling_price,
            expiry_date, manufacture_date, supplier_id, created_at, purchase_id,
            quantity_received, status
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    foreach ($lineItems as $idx => $item) {
        $sku = array_keys($medIds)[$idx];
        $expiryMonths = $expiryOv[$sku] ?? (10 + mt_rand(0, 10));
        $expiry = date('Y-m-d', strtotime($date . ' +' . round($expiryMonths) . ' months'));
        $manuf = date('Y-m-d', strtotime($date . ' -1 month'));
        $batchNo = 'DEMO-B-' . $seq . '-' . ($idx + 1);

        $insI->execute([$pid, $item['id'], $batchNo, $item['qty'], $item['buy'], $item['buy'] * 1.8, $expiry, $manuf]);
        $insB->execute([
            $item['id'], $batchNo, $item['qty'], $item['buy'], $item['buy'] * 1.8,
            $expiry, $manuf, $sup, $date, $pid, $item['qty'], 'received',
        ]);
        $stats['batches']++;
    }

    if ($paidAmt > 0) {
        $pdo->prepare("
            INSERT INTO purchase_payments (
                purchase_id, supplier_id, payment_date, amount, payment_method,
                reference_number, notes, created_by, created_at
            ) VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([
            $pid, $sup, $payDate, $paidAmt,
            ['cash', 'bank', 'telebirr', 'bank', 'cash'][($seq - 1) % 5],
            'DEMO-PAY-' . $seq, 'Demo supplier payment', null, $payDate . ' 10:00:00',
        ]);
        $stats['payments']++;
    }

    // Occasional purchase return (damaged/expired stock sent back to supplier).
    if ($seq % 14 === 0 && $lineItems) {
        $item = $lineItems[mt_rand(0, count($lineItems) - 1)];
        $retQty = max(1, (int)round($item['qty'] * 0.1));
        $retAmt = round($item['buy'] * $retQty, 2);
        $retDate = date('Y-m-d', strtotime($date . ' +' . mt_rand(2, 10) . ' days'));
        $pdo->prepare("
            INSERT INTO purchase_returns (
                return_number, purchase_id, supplier_id, return_date, total_amount,
                reason, status, notes, created_by, created_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            'DEMO-RET-' . $seq, $pid, $sup, $retDate, $retAmt,
            (mt_rand(0, 1) ? 'damaged' : 'expired'), 'returned', 'Demo return to supplier', null, $retDate,
        ]);
        $pdo->prepare("UPDATE purchases SET total_returned = total_returned + ? WHERE id=?")->execute([$retAmt, $pid]);
        $pdo->prepare("UPDATE batches SET quantity = quantity - ? WHERE purchase_id = ? AND medicine_id = ?")
            ->execute([$retQty, $pid, $item['id']]);
        $stats['returns']++;
    }

    return $stats;
}

function demoPickBatch(PDO $pdo, int $medicineId, int $qty): ?array {
    $stmt = $pdo->prepare("
        SELECT id, batch_number, quantity, selling_price, purchase_price
        FROM batches
        WHERE medicine_id = ? AND quantity >= ? AND expiry_date >= date('now')
        ORDER BY expiry_date ASC LIMIT 1
    ");
    $stmt->execute([$medicineId, $qty]);
    return $stmt->fetch() ?: null;
}

function demoCreateSale(PDO $pdo, array $opts, int $adminId): ?int {
    $items = $opts['items'] ?? [];
    if (empty($items)) return null;

    $lineItems = [];
    $total = 0;
    foreach ($items as $it) {
        $batch = demoPickBatch($pdo, (int)$it['medicine_id'], (int)$it['qty']);
        if (!$batch) continue;
        $sub = (float)$batch['selling_price'] * (int)$it['qty'];
        $total += $sub;
        $lineItems[] = [
            'medicine_id' => (int)$it['medicine_id'],
            'batch_id'    => (int)$batch['id'],
            'qty'         => (int)$it['qty'],
            'price'       => (float)$batch['selling_price'],
            'subtotal'    => $sub,
        ];
    }
    if (empty($lineItems)) return null;

    $discount = (float)($opts['discount'] ?? 0);
    if ($discount > $total) $discount = $total;
    $tax = (float)($opts['tax'] ?? 0);
    $payable = ($total - $discount) + $tax;
    $method = $opts['payment_method'] ?? 'cash';
    $paid = array_key_exists('paid_amount', $opts) ? (float)$opts['paid_amount'] : ($method === 'credit' ? 0 : $payable);
    if ($paid > $payable) $paid = $payable;

    $remaining = max(0, $payable - $paid);
    $status = computePaymentStatus($payable, $paid, $method);
    $saleType = ($method === 'credit') ? 'credit' : 'cash';
    $dueDate = $opts['due_date'] ?? null;

    static $invSeq = 0;
    $invoice = 'DEMO-INV-' . date('Ymd') . '-' . str_pad((string)(++$invSeq), 4, '0', STR_PAD_LEFT);

    $pdo->prepare("
        INSERT INTO sales (
            invoice_number, customer_id, customer_name, customer_phone,
            total_amount, discount, tax, paid_amount, remaining_balance,
            payment_method, payment_status, payment_reference,
            notes, user_id, sale_type, due_date, credit_due_date, created_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $invoice,
        $opts['customer_id'] ?? null,
        $opts['customer_name'] ?? 'Walk-in Customer',
        $opts['customer_phone'] ?? '',
        $total, $discount, $tax, $paid, $remaining,
        $method, $status, $opts['payment_reference'] ?? null,
        $opts['notes'] ?? 'Demo sale',
        $opts['user_id'] ?? $adminId, $saleType,
        $dueDate, $dueDate,
        $opts['created_at'] ?? date('Y-m-d H:i:s'),
    ]);
    $saleId = (int)$pdo->lastInsertId();

    foreach ($lineItems as $li) {
        $pdo->prepare("INSERT INTO sale_items (sale_id,medicine_id,batch_id,quantity,unit_price,subtotal) VALUES (?,?,?,?,?,?)")
            ->execute([$saleId, $li['medicine_id'], $li['batch_id'], $li['qty'], $li['price'], $li['subtotal']]);
        $pdo->prepare("UPDATE batches SET quantity = quantity - ? WHERE id=?")
            ->execute([$li['qty'], $li['batch_id']]);
    }

    if ($paid > 0) {
        $pdo->prepare("
            INSERT INTO payment_history (sale_id, customer_id, amount, payment_method, payment_date, reference_number, received_by, notes)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $saleId, $opts['customer_id'] ?? null, $paid, $method,
            $opts['created_at'] ?? date('Y-m-d H:i:s'),
            $opts['payment_reference'] ?? null, $opts['user_id'] ?? $adminId, 'Demo payment',
        ]);
    }

    return $saleId;
}

/** Shared lookup sets used while generating sales. */
function demoSalesContext(PDO $pdo, int $adminId): array {
    $meds = $pdo->query("SELECT id, sku FROM medicines WHERE sku LIKE 'DEMO-%' ORDER BY sku")->fetchAll();
    $catalogue = [];
    foreach (demoProductCatalogue() as $m) $catalogue[$m[0]] = $m;

    $weighted = [];
    foreach ($meds as $m) {
        $w = $catalogue[$m['sku']][9] ?? 1;
        if ($w <= 0) continue;
        $weighted[] = ['id' => (int)$m['id'], 'weight' => $w];
    }

    return [
        'customers' => $pdo->query("SELECT id, full_name, phone FROM customers ORDER BY id")->fetchAll(),
        'cashiers'  => $pdo->query("SELECT id FROM users WHERE username LIKE 'demo_cashier%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
        'methods'   => ['cash', 'cash', 'cash', 'cash', 'telebirr', 'telebirr', 'cbe', 'cbe', 'abyssinia', 'credit', 'credit'],
        'refs'      => ['TBR123456', 'CBE987654', 'ABY554433', 'TBR778899', 'CBE112233', 'TBR334455'],
        'weighted'  => $weighted,
        'admin'     => $adminId,
    ];
}

/** Generates the sales for one month. Returns the number of sales created. */
function demoSeedMonthSales(PDO $pdo, int $month, array $ctx, int $adminId): int {
    $weighted = $ctx['weighted'];
    $customers = $ctx['customers'];
    $cashiers = $ctx['cashiers'];
    $methods = $ctx['methods'];
    $refs = $ctx['refs'];
    if (count($weighted) < 3) return 0;

    $salesThisMonth = ($month === 0) ? mt_rand(14, 18) : max(5, (int)round(5 + (12 - $month) * 1.3));
    $count = 0;

    for ($s = 0; $s < $salesThisMonth; $s++) {
        $method = $methods[mt_rand(0, count($methods) - 1)];
        $isCredit = $method === 'credit';

        $cust = null;
        if ($isCredit || mt_rand(0, 100) < 45) {
            $cust = $customers[mt_rand(0, count($customers) - 1)];
        }

        $numItems = mt_rand(1, 3);
        $items = [];
        $picks = $weighted;
        for ($k = 0; $k < $numItems && $picks; $k++) {
            $totalW = array_sum(array_column($picks, 'weight'));
            $r = mt_rand(1, max(1, (int)$totalW));
            $acc = 0;
            $chosen = null;
            foreach ($picks as $i => $p) {
                $acc += $p['weight'];
                if ($r <= $acc) { $chosen = $p; unset($picks[$i]); break; }
            }
            if ($chosen) $items[] = ['medicine_id' => $chosen['id'], 'qty' => mt_rand(1, 4)];
        }
        if (empty($items)) continue;

        $day = mt_rand(0, 27);
        $created = date('Y-m-d H:i:s', strtotime("-$month months -$day days") + mt_rand(8, 20) * 3600 + mt_rand(0, 59) * 60);
        $cashierId = ($cashiers && mt_rand(0, 100) < 70) ? $cashiers[mt_rand(0, count($cashiers) - 1)] : $adminId;

        $opts = [
            'items'             => $items,
            'payment_method'    => $method,
            'discount'          => (mt_rand(1, 10) === 1) ? mt_rand(5, 15) : 0,
            'tax'               => (mt_rand(1, 7) === 1) ? mt_rand(2, 6) : 0,
            'created_at'        => $created,
            'user_id'           => (int)$cashierId,
            'payment_reference' => in_array($method, ['telebirr', 'cbe', 'abyssinia'], true) ? $refs[mt_rand(0, count($refs) - 1)] : null,
        ];
        if ($cust) {
            $opts['customer_id']    = (int)$cust['id'];
            $opts['customer_name']  = $cust['full_name'];
            $opts['customer_phone'] = $cust['phone'];
        }

        if (!$isCredit) {
            demoCreateSale($pdo, $opts, $adminId) && $count++;
            continue;
        }

        // Credit sale, 30-day term; ~55% get collected later.
        $opts['due_date'] = date('Y-m-d', strtotime($created . ' +30 days'));
        $opts['paid_amount'] = 0;
        $saleId = demoCreateSale($pdo, $opts, $adminId);
        if (!$saleId) continue;
        $count++;

        if (mt_rand(1, 100) <= 55) {
            $sale = $pdo->query("SELECT * FROM sales WHERE id=$saleId")->fetch();
            $net = saleNetAmount($sale);
            $collectedPct = (mt_rand(0, 1) === 0) ? 1.0 : (0.5 + mt_rand(0, 30) / 100);
            $payAmt = round($net * $collectedPct, 2);
            $payDate = date('Y-m-d H:i:s', strtotime($created . ' +' . mt_rand(10, 28) . ' days') + mt_rand(9, 17) * 3600);
            $pdo->prepare("UPDATE sales SET paid_amount=?, remaining_balance=?, payment_status=? WHERE id=?")
                ->execute([$payAmt, $net - $payAmt, computePaymentStatus($net, $payAmt, 'credit'), $saleId]);
            $pdo->prepare("
                INSERT INTO payment_history (sale_id, customer_id, amount, payment_method, payment_date, reference_number, received_by, notes)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([$saleId, $sale['customer_id'], $payAmt, 'telebirr', $payDate, $refs[mt_rand(0, count($refs) - 1)], $adminId, 'Credit collection']);
        }
    }

    return $count;
}

/** A handful of sales today for the dashboard. */
function demoSeedTodaySales(PDO $pdo, array $ctx, int $adminId): int {
    $weighted = $ctx['weighted'];
    $methods = $ctx['methods'];
    $refs = $ctx['refs'];
    $cashiers = $ctx['cashiers'];
    if (empty($weighted)) return 0;

    $count = 0;
    for ($i = 0; $i < 6; $i++) {
        $pick = $weighted[mt_rand(0, count($weighted) - 1)];
        $method = $methods[mt_rand(0, 8)];
        demoCreateSale($pdo, [
            'items'             => [['medicine_id' => (int)$pick['id'], 'qty' => mt_rand(1, 3)]],
            'payment_method'    => $method,
            'created_at'        => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 10) . ' hours')),
            'user_id'           => $cashiers ? (int)$cashiers[mt_rand(0, count($cashiers) - 1)] : $adminId,
            'payment_reference' => in_array($method, ['telebirr', 'cbe', 'abyssinia'], true) ? $refs[mt_rand(0, count($refs) - 1)] : null,
        ], $adminId) && $count++;
    }
    return $count;
}

/** Sale returns across the last 12 months — restores stock, feeds the Returns report. */
function demoSeedSaleReturns(PDO $pdo): int {
    $saleIds = $pdo->query("
        SELECT s.id FROM sales s
        WHERE s.invoice_number LIKE 'DEMO-INV-%'
        ORDER BY RANDOM() LIMIT 12
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($saleIds)) return 0;

    $reasons = ['expired', 'damaged', 'wrong_product', 'customer_return'];
    $n = 0;
    $ins = $pdo->prepare("
        INSERT INTO sale_returns (sale_id, medicine_id, batch_id, quantity, amount, reason, created_at)
        VALUES (?,?,?,?,?,?,?)
    ");
    $getSale = $pdo->prepare("SELECT created_at FROM sales WHERE id=?");
    $getItem = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ? ORDER BY RANDOM() LIMIT 1");

    foreach ($saleIds as $sid) {
        $getItem->execute([(int)$sid]);
        $it = $getItem->fetch();
        if (!$it) continue;

        $getSale->execute([(int)$sid]);
        $saleDate = (string)$getSale->fetchColumn();

        $qty = mt_rand(1, 2);
        $retDate = date('Y-m-d H:i:s', strtotime(substr($saleDate, 0, 10) . ' +' . mt_rand(1, 5) . ' days') + mt_rand(9, 17) * 3600);

        $ins->execute([
            (int)$sid, (int)$it['medicine_id'], (int)$it['batch_id'], $qty,
            round((float)$it['unit_price'] * $qty, 2),
            $reasons[mt_rand(0, count($reasons) - 1)],
            $retDate,
        ]);
        $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id=?")->execute([$qty, (int)$it['batch_id']]);
        $n++;
    }
    return $n;
}

/** Monthly operating expenses for the last 12 months. */
function demoSeedExpenses(PDO $pdo, int $adminId): int {
    $rows = [
        ['Rent', 'Demo — Monthly pharmacy rent', 15000],
        ['Salaries', 'Demo — Staff salaries', 22000],
        ['Utilities', 'Demo — Electricity & water', 2500 + mt_rand(0, 800)],
        ['Supplies', 'Demo — Packaging & consumables', 1200 + mt_rand(0, 800)],
        ['Marketing', 'Demo — Local advertising', mt_rand(0, 1500)],
        ['Maintenance', 'Demo — Equipment service', mt_rand(0, 1000)],
    ];
    $ins = $pdo->prepare("INSERT INTO operating_expenses (category, description, amount, expense_date, created_by) VALUES (?,?,?,?,?)");
    $n = 0;
    for ($month = 12; $month >= 0; $month--) {
        foreach ($rows as $r) {
            $ins->execute([
                $r[0], $r[1], $r[2],
                date('Y-m-d', strtotime("-$month months") + mt_rand(1, 26) * 86400),
                $adminId ?: null,
            ]);
            $n++;
        }
    }
    return $n;
}

/**
 * Deterministic end-of-demo inventory scenarios so every stock-status and
 * expiry tab shows meaningful content:
 *  - Paracetamol → Low Stock, Amlodipine → Overstock, Azithromycin → Out of Stock,
 *  - Metformin → healthy In Stock, plus dedicated expired / near-expiry batches.
 */
function demoSeedCurrentStock(PDO $pdo): void {
    $setTotal = function (string $name, float $target) use ($pdo): void {
        $med = $pdo->prepare("SELECT id, COALESCE(reorder_level,10) reorder FROM medicines WHERE name = ? AND sku LIKE 'DEMO-%'");
        $med->execute([$name]);
        $m = $med->fetch();
        if (!$m) return;

        $batches = $pdo->prepare("SELECT id, quantity FROM batches WHERE medicine_id = ? ORDER BY created_at DESC");
        $batches->execute([(int)$m['id']]);
        $rows = $batches->fetchAll();
        $current = array_sum(array_column($rows, 'quantity'));
        $delta = $target - $current;
        if (abs($delta) < 0.01) return;

        $upd = $pdo->prepare("UPDATE batches SET quantity = ? WHERE id = ?");
        if ($delta > 0) {
            if ($rows) {
                $upd->execute([$rows[0]['quantity'] + $delta, $rows[0]['id']]);
            } else {
                $pdo->prepare("
                    INSERT INTO batches (medicine_id, batch_number, quantity, purchase_price, selling_price, expiry_date, created_at)
                    VALUES (?,?,?,?,?,?,datetime('now'))
                ")->execute([(int)$m['id'], 'DEMO-CUR-' . (int)$m['id'], $delta, 1, 2, date('Y-m-d', strtotime('+300 days'))]);
            }
        } else {
            $remaining = -$delta;
            foreach ($rows as $r) {
                if ($remaining <= 0) break;
                $take = min((float)$r['quantity'], $remaining);
                $upd->execute([$r['quantity'] - $take, $r['id']]);
                $remaining -= $take;
            }
        }
    };

    $setTotal('Paracetamol 500mg', 15);   // low (reorder 50)
    $setTotal('Amlodipine 5mg', 160);     // overstock (reorder 35)
    $setTotal('Azithromycin 250mg', 0);   // out of stock
    $setTotal('Metformin 500mg', 48);     // healthy in stock (reorder 40)

    // Dedicated expiry batches (unsold, qty > 0) for the expiry report.
    $cats = demoEnsureCategories($pdo);
    $insMed = $pdo->prepare("
        INSERT INTO medicines (name, generic_name, category_id, unit, reorder_level, barcode, sku, product_type)
        SELECT ?, ?, ?, ?, 10, ?, ?, ?
        WHERE NOT EXISTS (SELECT 1 FROM medicines WHERE sku = ?)
    ");
    $insBatch = $pdo->prepare("
        INSERT INTO batches (medicine_id, batch_number, quantity, purchase_price, selling_price, expiry_date, created_at)
        VALUES (?,?,?,?,?,?,datetime('now'))
    ");

    $expiryMeds = [
        // [sku, name, generic, category, unit, buy, sell, [[batch, qty, expiry_offset_days], ...]]
        ['DEMO-EXP1', 'Saline Drops 10ml', 'Saline Solution', 'Other', 'bottle', 20, 40, [
            ['DEMO-EXP-01', 8, -25],
        ]],
        ['DEMO-EXP2', 'Cough Syrup 100ml', 'Dextromethorphan Syrup', 'Respiratory & Allergy', 'bottle', 60, 110, [
            ['DEMO-EXP-02', 12, 18],
            ['DEMO-EXP-03', 6, 70],
        ]],
        ['DEMO-EXP3', 'Antibiotic Ointment', 'Mupirocin Ointment', 'Dermatology & Skin Care', 'tube', 55, 95, [
            ['DEMO-EXP-04', 15, 240],
        ]],
    ];
    foreach ($expiryMeds as $em) {
        [$sku, $name, $generic, $cat, $unit, $buy, $sell, $batches] = $em;
        $insMed->execute([$name, $generic, $cats[$cat], $unit, $sku, $sku, 'medicine', $sku]);
        $medId = (int)$pdo->query("SELECT id FROM medicines WHERE sku = '$sku'")->fetchColumn();
        foreach ($batches as $b) {
            $insBatch->execute([$medId, $b[0], $b[1], $buy, $sell, date('Y-m-d', strtotime($b[2] . ' days'))]);
        }
    }
}

// ── CLI ──────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $pdo = getDB();
    if (in_array('--clear', $argv ?? [], true)) {
        $result = clearDemoData($pdo);
    } else {
        $force = in_array('--force', $argv ?? [], true);
        $result = seedDemoData($pdo, $force);
    }
    echo ($result['ok'] ? "OK: " : "ERROR: ") . $result['message'] . "\n";
    exit($result['ok'] ? 0 : 1);
}
