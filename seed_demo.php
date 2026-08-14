<?php
/**
 * Seed demo data for testing reports, sales, purchases, credit, and inventory.
 * CLI:  php seed_demo.php [--force]
 * Web:  Settings → Load Demo Data
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
            'suppliers'  => demoSeedSuppliers($pdo),
            'customers'  => demoSeedCustomers($pdo),
            'medicines'  => demoSeedMedicines($pdo),
            'batches'    => demoSeedBatches($pdo),
            'purchases'  => demoSeedPurchases($pdo),
            'sales'      => demoSeedSales($pdo, $adminId),
            'expenses'   => demoSeedExpenses($pdo, $adminId),
        ];

        foreach ($pdo->query("SELECT id FROM customers")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            refreshCustomerCredit($pdo, (int)$cid);
        }

        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('demo_data_loaded', ?)")
            ->execute(['1']);

        $pdo->commit();

        $msg = sprintf(
            'Demo data loaded: %d suppliers, %d customers, %d medicines, %d batches, %d purchases, %d sales, %d expenses.',
            $stats['suppliers'], $stats['customers'], $stats['medicines'], $stats['batches'],
            $stats['purchases'], $stats['sales'], $stats['expenses']
        );

        return ['ok' => true, 'message' => $msg, 'stats' => $stats];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Demo seed failed: ' . $e->getMessage()];
    }
}

function demoCustomerPhones(): array {
    return ['0911000001', '0911000002', '0911000003', '0911000004', '0911000005'];
}

function demoSupplierNames(): array {
    return ['Addis Pharma Distributors', 'Ethio Med Supply', 'Horizon Healthcare Imports'];
}

function clearDemoData(PDO $pdo): array {
    $pdo->beginTransaction();
    try {
        $stats = demoClearTransactional($pdo);
        $pdo->commit();
        $msg = sprintf(
            'Demo data removed: %d sales, %d purchases, %d expenses, %d batches, %d medicines, %d customers, %d suppliers.',
            $stats['sales'], $stats['purchases'], $stats['expenses'], $stats['batches'],
            $stats['medicines'], $stats['customers'], $stats['suppliers']
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
    ];

    $demoSaleIds = $pdo->query("SELECT id FROM sales WHERE invoice_number LIKE 'DEMO-INV-%'")->fetchAll(PDO::FETCH_COLUMN);
    $stats['sales'] = count($demoSaleIds);

    foreach ($demoSaleIds as $sid) {
        foreach ($pdo->query("SELECT * FROM sale_items WHERE sale_id=" . (int)$sid)->fetchAll() as $si) {
            $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id=?")
                ->execute([$si['quantity'], $si['batch_id']]);
        }
    }
    if ($demoSaleIds) {
        $in = implode(',', array_map('intval', $demoSaleIds));
        $pdo->exec("DELETE FROM sale_returns WHERE sale_id IN ($in)");
        $pdo->exec("DELETE FROM payment_history WHERE sale_id IN ($in)");
        $pdo->exec("DELETE FROM sale_items WHERE sale_id IN ($in)");
        $pdo->exec("DELETE FROM sales WHERE id IN ($in)");
    }

    $stats['purchases'] = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE reference LIKE 'DEMO-PUR-%'")->fetchColumn();
    $pdo->exec("DELETE FROM purchase_items WHERE purchase_id IN (SELECT id FROM purchases WHERE reference LIKE 'DEMO-PUR-%')");
    $pdo->exec("DELETE FROM purchases WHERE reference LIKE 'DEMO-PUR-%'");

    $stats['expenses'] = (int)$pdo->query("SELECT COUNT(*) FROM operating_expenses WHERE description LIKE 'Demo%'")->fetchColumn();
    $pdo->exec("DELETE FROM operating_expenses WHERE description LIKE 'Demo%'");

    $stats['batches'] = (int)$pdo->query("
        SELECT COUNT(*) FROM batches
        WHERE batch_number LIKE 'BATCH-DEMO-%'
           OR batch_number LIKE 'BATCH-EXP%'
           OR batch_number LIKE 'BATCH-LOW'
           OR batch_number LIKE 'DEMO-RCV-%'
    ")->fetchColumn();
    $pdo->exec("
        DELETE FROM batches
        WHERE batch_number LIKE 'BATCH-DEMO-%'
           OR batch_number LIKE 'BATCH-EXP%'
           OR batch_number LIKE 'BATCH-LOW'
           OR batch_number LIKE 'DEMO-RCV-%'
    ");

    $stats['medicines'] = (int)$pdo->query("SELECT COUNT(*) FROM medicines WHERE sku LIKE 'DEMO-%'")->fetchColumn();
    $pdo->exec("DELETE FROM medicines WHERE sku LIKE 'DEMO-%'");

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
    ];
    $n = 0;
    $ins = $pdo->prepare("INSERT OR IGNORE INTO suppliers (name, phone, email, address, contact_person) VALUES (?,?,?,?,?)");
    foreach ($rows as $r) {
        $ins->execute($r);
        if ($ins->rowCount()) $n++;
    }
    return $n ?: count($rows);
}

function demoSeedCustomers(PDO $pdo): int {
    $rows = [
        ['Abebe Kebede',     '0911000001', 50000],
        ['Tigist Haile',     '0911000002', 30000],
        ['Dr. Samuel Tadesse','0911000003', 80000],
        ['Meron Assefa',     '0911000004', 25000],
        ['Yonas Girma',      '0911000005', 40000],
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

function demoSeedMedicines(PDO $pdo): int {
    $catId = (int)$pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
    if (!$catId) {
        $pdo->exec("INSERT INTO categories (name) VALUES ('Demo Category')");
        $catId = (int)$pdo->lastInsertId();
    }

    $meds = [
        ['DEMO-001', 'Amoxicillin 500mg',       'Amoxicillin',        'Antibiotics',  'strip',  45,  85],
        ['DEMO-002', 'Paracetamol 500mg',       'Paracetamol',        'Pain Relief',  'strip',  12,  25],
        ['DEMO-003', 'Metformin 500mg',         'Metformin',          'Diabetes',     'strip',  55,  95],
        ['DEMO-004', 'Omeprazole 20mg',         'Omeprazole',         'GI Health',    'strip',  80,  140],
        ['DEMO-005', 'Cetirizine 10mg',         'Cetirizine',         'Allergy',      'strip',  25,  45],
        ['DEMO-006', 'Azithromycin 250mg',      'Azithromycin',       'Antibiotics',  'strip',  120, 195],
        ['DEMO-007', 'Ibuprofen 400mg',         'Ibuprofen',          'Pain Relief',  'strip',  30,  55],
        ['DEMO-008', 'Amlodipine 5mg',          'Amlodipine',         'Cardiovascular','strip', 40,  75],
        ['DEMO-009', 'Salbutamol Inhaler',      'Salbutamol',         'Respiratory',  'puff',   180, 280],
        ['DEMO-010', 'ORS Sachet',              'Oral Rehydration',   'GI Health',    'sachet', 8,   15],
        ['DEMO-011', 'Vitamin C 500mg',         'Ascorbic Acid',      'Supplements',  'strip',  35,  60],
        ['DEMO-012', 'Ciprofloxacin 500mg',     'Ciprofloxacin',      'Antibiotics',  'strip',  65, 110],
        ['DEMO-013', 'Losartan 50mg',           'Losartan',           'Cardiovascular','strip', 70,  120],
        ['DEMO-014', 'Hydrocortisone Cream 1%', 'Hydrocortisone',     'Dermatology',  'tube',   95,  160],
        ['DEMO-015', 'Insulin Glargine',        'Insulin Glargine',   'Diabetes',     'vial',   450, 650],
    ];

    $ins = $pdo->prepare("
        INSERT INTO medicines (name, generic_name, category_id, unit, reorder_level, barcode, sku)
        SELECT ?, ?, ?, ?, 10, ?, ?
        WHERE NOT EXISTS (SELECT 1 FROM medicines WHERE sku = ?)
    ");
    $added = 0;
    foreach ($meds as $m) {
        $ins->execute([$m[1], $m[2], $catId, $m[4], $m[0], $m[0], $m[0]]);
        $added += $ins->rowCount();
    }
    return $added + (int)$pdo->query("SELECT COUNT(*) FROM medicines WHERE sku LIKE 'DEMO-%'")->fetchColumn();
}

function demoSeedBatches(PDO $pdo): int {
    $supplierIds = $pdo->query("SELECT id FROM suppliers ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($supplierIds)) {
        demoSeedSuppliers($pdo);
        $supplierIds = $pdo->query("SELECT id FROM suppliers ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }

    $meds = $pdo->query("
        SELECT id, sku, name FROM medicines
        WHERE sku LIKE 'DEMO-%' OR id IN (SELECT medicine_id FROM batches)
        ORDER BY id LIMIT 20
    ")->fetchAll();

    if (empty($meds)) {
        $meds = $pdo->query("SELECT id, sku, name FROM medicines ORDER BY id LIMIT 15")->fetchAll();
    }

    $existing = (int)$pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn();
    if ($existing >= 10 && getSetting('demo_data_loaded', '0') !== '1') {
        return $existing;
    }

    $ins = $pdo->prepare("
        INSERT INTO batches (medicine_id, batch_number, quantity, purchase_price, selling_price, expiry_date, supplier_id)
        VALUES (?,?,?,?,?,?,?)
    ");

    $prices = [
        'DEMO-001' => [45, 85], 'DEMO-002' => [12, 25], 'DEMO-003' => [55, 95],
        'DEMO-004' => [80, 140], 'DEMO-005' => [25, 45], 'DEMO-006' => [120, 195],
        'DEMO-007' => [30, 55], 'DEMO-008' => [40, 75], 'DEMO-009' => [180, 280],
        'DEMO-010' => [8, 15], 'DEMO-011' => [35, 60], 'DEMO-012' => [65, 110],
        'DEMO-013' => [70, 120], 'DEMO-014' => [95, 160], 'DEMO-015' => [450, 650],
    ];

    $n = 0;
    $i = 0;
    foreach ($meds as $med) {
        $sku = $med['sku'] ?? '';
        $buy = $prices[$sku][0] ?? 50;
        $sell = $prices[$sku][1] ?? 90;
        $sup = $supplierIds[$i % count($supplierIds)];

        // Primary batch — good stock, expires in 6–18 months
        $ins->execute([
            $med['id'], 'BATCH-DEMO-' . str_pad((string)(++$i), 3, '0', STR_PAD_LEFT),
            rand(80, 200), $buy, $sell,
            date('Y-m-d', strtotime('+' . rand(180, 540) . ' days')),
            $sup,
        ]);
        $n++;

        // Second batch for some — FEFO testing
        if ($i % 2 === 0) {
            $ins->execute([
                $med['id'], 'BATCH-DEMO-' . str_pad((string)(++$i), 3, '0', STR_PAD_LEFT),
                rand(30, 80), $buy * 0.95, $sell,
                date('Y-m-d', strtotime('+' . rand(60, 150) . ' days')),
                $sup,
            ]);
            $n++;
        }
    }

    // Near expiry & expired batches for inventory reports
    if ($meds) {
        $m = $meds[0];
        $ins->execute([$m['id'], 'BATCH-EXP-SOON', 25, 40, 75, date('Y-m-d', strtotime('+14 days')), $supplierIds[0]]);
        $ins->execute([$m['id'], 'BATCH-EXPIRED', 10, 40, 75, date('Y-m-d', strtotime('-30 days')), $supplierIds[0]]);
        $n += 2;
    }

    // Low stock medicine
    if (isset($meds[1])) {
        $ins->execute([$meds[1]['id'], 'BATCH-LOW', 3, 12, 25, date('Y-m-d', strtotime('+300 days')), $supplierIds[1]]);
        $n++;
    }

    return $n;
}

function demoSeedPurchases(PDO $pdo): int {
    if ((int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE reference LIKE 'DEMO-PUR-%'")->fetchColumn() > 0) {
        return (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE reference LIKE 'DEMO-PUR-%'")->fetchColumn();
    }

    $suppliers = $pdo->query("SELECT id FROM suppliers ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $batches = $pdo->query("SELECT medicine_id, purchase_price, selling_price, expiry_date FROM batches LIMIT 8")->fetchAll();
    if (empty($batches)) return 0;

    $insP = $pdo->prepare("INSERT INTO purchases (reference, supplier_id, total_amount, notes, created_at) VALUES (?,?,?,?,?)");
    $insI = $pdo->prepare("INSERT INTO purchase_items (purchase_id,medicine_id,batch_number,quantity,purchase_price,selling_price,expiry_date) VALUES (?,?,?,?,?,?,?)");

    $n = 0;
    for ($d = 75; $d >= 0; $d -= 15) {
        $date = date('Y-m-d H:i:s', strtotime("-$d days") + rand(9, 17) * 3600);
        $sup = $suppliers[$n % count($suppliers)];
        $total = 0;
        $items = [];
        foreach (array_slice($batches, 0, 4) as $j => $b) {
            $qty = rand(20, 60);
            $line = $b['purchase_price'] * $qty;
            $total += $line;
            $items[] = [$b, $qty, 'DEMO-RCV-' . ($n + 1) . '-' . ($j + 1)];
        }
        $insP->execute(['DEMO-PUR-' . ($n + 1), $sup, $total, 'Demo stock receiving', $date]);
        $pid = (int)$pdo->lastInsertId();
        foreach ($items as [$b, $qty, $bn]) {
            $insI->execute([$pid, $b['medicine_id'], $bn, $qty, $b['purchase_price'], $b['selling_price'], $b['expiry_date']]);
        }
        $n++;
    }
    return $n;
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
    $net = $total - $discount;
    $method = $opts['payment_method'] ?? 'cash';
    $paid = array_key_exists('paid_amount', $opts) ? (float)$opts['paid_amount'] : ($method === 'credit' ? 0 : $net);
    if ($paid > $net) $paid = $net;

    $remaining = max(0, $net - $paid);
    $status = computePaymentStatus($net, $paid, $method);
    $saleType = ($method === 'credit') ? 'credit' : 'cash';
    $dueDate = $opts['due_date'] ?? null;

    static $invSeq = 0;
    $invoice = 'DEMO-INV-' . date('Ymd') . '-' . str_pad((string)(++$invSeq), 4, '0', STR_PAD_LEFT);

    $pdo->prepare("
        INSERT INTO sales (
            invoice_number, customer_id, customer_name, customer_phone,
            total_amount, discount, paid_amount, remaining_balance,
            payment_method, payment_status, payment_reference,
            notes, user_id, sale_type, due_date, credit_due_date, created_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $invoice,
        $opts['customer_id'] ?? null,
        $opts['customer_name'] ?? 'Walk-in Customer',
        $opts['customer_phone'] ?? '',
        $total, $discount, $paid, $remaining,
        $method, $status, $opts['payment_reference'] ?? null,
        $opts['notes'] ?? 'Demo sale',
        $adminId, $saleType,
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
            $opts['payment_reference'] ?? null, $adminId, 'Demo payment',
        ]);
    }

    return $saleId;
}

function demoSeedSales(PDO $pdo, int $adminId): int {
    if ((int)$pdo->query("SELECT COUNT(*) FROM sales WHERE invoice_number LIKE 'DEMO-INV-%'")->fetchColumn() > 0) {
        return (int)$pdo->query("SELECT COUNT(*) FROM sales WHERE invoice_number LIKE 'DEMO-INV-%'")->fetchColumn();
    }

    $medIds = $pdo->query("
        SELECT DISTINCT b.medicine_id
        FROM batches b
        WHERE b.quantity > 0 AND b.expiry_date >= date('now')
        ORDER BY b.medicine_id
        LIMIT 15
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (count($medIds) < 3) {
        $medIds = $pdo->query("SELECT id FROM medicines WHERE sku LIKE 'DEMO-%' ORDER BY id LIMIT 15")->fetchAll(PDO::FETCH_COLUMN);
    }
    if (count($medIds) < 3) return 0;

    $customers = $pdo->query("SELECT id, full_name, phone FROM customers ORDER BY id")->fetchAll();
    $methods = ['cash', 'telebirr', 'cbe', 'abyssinia', 'credit'];
    $refs = ['TBR123456', 'CBE987654', 'ABY554433', 'TBR778899', 'CBE112233'];

    $count = 0;

    // ~70 sales spread over last 90 days
    for ($day = 89; $day >= 0; $day--) {
        $salesToday = ($day % 7 === 0) ? rand(2, 4) : rand(0, 2);
        for ($s = 0; $s < $salesToday; $s++) {
            $method = $methods[array_rand($methods)];
            $cust = ($method === 'credit' || rand(0, 1))
                ? $customers[array_rand($customers)]
                : null;

            $items = [];
            $numItems = rand(1, 3);
            shuffle($medIds);
            for ($k = 0; $k < $numItems; $k++) {
                $items[] = ['medicine_id' => (int)$medIds[$k], 'qty' => rand(1, 4)];
            }

            $hour = rand(8, 20);
            $min  = rand(0, 59);
            $created = date('Y-m-d H:i:s', strtotime("-$day days") + $hour * 3600 + $min * 60);

            $opts = [
                'items'             => $items,
                'payment_method'    => $method,
                'discount'          => (rand(1, 10) === 1) ? rand(5, 30) : 0,
                'created_at'        => $created,
                'payment_reference' => in_array($method, ['telebirr', 'cbe', 'abyssinia'], true) ? $refs[array_rand($refs)] : null,
            ];

            if ($cust) {
                $opts['customer_id']    = (int)$cust['id'];
                $opts['customer_name']  = $cust['full_name'];
                $opts['customer_phone'] = $cust['phone'];
            }

            if ($method !== 'credit') {
                demoCreateSale($pdo, $opts, $adminId) && $count++;
                continue;
            }

            // Credit — skip most random credits; handle scenarios below
            if (rand(1, 4) !== 1) {
                $opts['payment_method'] = 'cash';
                demoCreateSale($pdo, $opts, $adminId) && $count++;
                continue;
            }

            $opts['due_date'] = date('Y-m-d', strtotime("-$day days +30 days"));
            $opts['paid_amount'] = 0;
            demoCreateSale($pdo, $opts, $adminId) && $count++;
        }
    }

    // Targeted credit scenarios
    if (count($customers) >= 4) {
        $scenarios = [
            // Overdue, unpaid
            ['cust' => 0, 'days_ago' => 45, 'due_offset' => -10, 'paid' => 0, 'items' => 2],
            // Overdue, partial
            ['cust' => 1, 'days_ago' => 30, 'due_offset' => -5,  'paid' => 0.3, 'items' => 2],
            // Due tomorrow
            ['cust' => 2, 'days_ago' => 29, 'due_offset' => 1,   'paid' => 0, 'items' => 1],
            // Partial, not overdue
            ['cust' => 3, 'days_ago' => 10, 'due_offset' => 20,  'paid' => 0.5, 'items' => 3],
        ];

        foreach ($scenarios as $sc) {
            $c = $customers[$sc['cust']];
            $items = [];
            for ($k = 0; $k < $sc['items']; $k++) {
                $items[] = ['medicine_id' => (int)$medIds[$k], 'qty' => rand(2, 5)];
            }
            $created = date('Y-m-d H:i:s', strtotime('-' . $sc['days_ago'] . ' days 14:30:00'));
            $due = date('Y-m-d', strtotime($created . ' +' . $sc['due_offset'] . ' days'));

            // Estimate net for partial pay ratio
            $saleId = demoCreateSale($pdo, [
                'items'          => $items,
                'payment_method' => 'credit',
                'customer_id'    => (int)$c['id'],
                'customer_name'  => $c['full_name'],
                'customer_phone' => $c['phone'],
                'created_at'     => $created,
                'due_date'       => $due,
                'paid_amount'    => 0,
                'notes'          => 'Demo credit scenario',
            ], $adminId);

            if ($saleId && $sc['paid'] > 0) {
                $sale = $pdo->query("SELECT * FROM sales WHERE id=$saleId")->fetch();
                $net = saleNetAmount($sale);
                $payAmt = round($net * $sc['paid'], 2);
                $pdo->prepare("UPDATE sales SET paid_amount=?, remaining_balance=?, payment_status=? WHERE id=?")
                    ->execute([$payAmt, $net - $payAmt, computePaymentStatus($net, $payAmt, 'credit'), $saleId]);
                $pdo->prepare("
                    INSERT INTO payment_history (sale_id, customer_id, amount, payment_method, payment_date, received_by, notes)
                    VALUES (?,?,?,?,?,?,?)
                ")->execute([$saleId, $c['id'], $payAmt, 'cash', date('Y-m-d H:i:s', strtotime($created . ' +3 days')), $adminId, 'Partial demo payment']);
                $count++;
            } elseif ($saleId) {
                $count++;
            }
        }

        // Credit collected today
        $c = $customers[4];
        $saleId = demoCreateSale($pdo, [
            'items'          => [['medicine_id' => (int)$medIds[0], 'qty' => 2]],
            'payment_method' => 'credit',
            'customer_id'    => (int)$c['id'],
            'customer_name'  => $c['full_name'],
            'customer_phone' => $c['phone'],
            'created_at'     => date('Y-m-d H:i:s', strtotime('-20 days')),
            'due_date'       => date('Y-m-d', strtotime('+10 days')),
            'paid_amount'    => 0,
        ], $adminId);
        if ($saleId) {
            $sale = $pdo->query("SELECT * FROM sales WHERE id=$saleId")->fetch();
            $net = saleNetAmount($sale);
            $pdo->prepare("UPDATE sales SET paid_amount=?, remaining_balance=?, payment_status=? WHERE id=?")
                ->execute([$net, 0, 'paid', $saleId]);
            $pdo->prepare("
                INSERT INTO payment_history (sale_id, customer_id, amount, payment_method, payment_date, received_by, notes)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$saleId, $c['id'], $net, 'telebirr', date('Y-m-d H:i:s'), $adminId, 'Paid in full today']);
            $count++;
        }
    }

    // Today's sales boost for dashboard
    for ($i = 0; $i < 5; $i++) {
        $items = [['medicine_id' => (int)$medIds[$i % count($medIds)], 'qty' => rand(1, 3)]];
        demoCreateSale($pdo, [
            'items'          => $items,
            'payment_method' => $methods[$i % 4],
            'created_at'     => date('Y-m-d H:i:s', strtotime('-' . rand(1, 8) . ' hours')),
            'payment_reference' => $refs[$i % count($refs)],
        ], $adminId) && $count++;
    }

    return $count;
}

function demoSeedExpenses(PDO $pdo, int $adminId): int {
    if ((int)$pdo->query("SELECT COUNT(*) FROM operating_expenses WHERE description LIKE 'Demo%'")->fetchColumn() > 0) {
        return (int)$pdo->query("SELECT COUNT(*) FROM operating_expenses WHERE description LIKE 'Demo%'")->fetchColumn();
    }

    $rows = [
        ['Rent', 'Demo — Monthly pharmacy rent', 15000],
        ['Utilities', 'Demo — Electricity & water', 2500],
        ['Salaries', 'Demo — Staff salaries', 22000],
        ['Supplies', 'Demo — Packaging & consumables', 1800],
        ['Marketing', 'Demo — Local advertising', 1200],
        ['Maintenance', 'Demo — Equipment service', 900],
    ];

    $ins = $pdo->prepare("INSERT INTO operating_expenses (category, description, amount, expense_date, created_by) VALUES (?,?,?,?,?)");
    $n = 0;
    foreach ($rows as $i => $r) {
        $daysAgo = 60 - ($i * 10);
        $ins->execute([$r[0], $r[1], $r[2], date('Y-m-d', strtotime("-$daysAgo days")), $adminId ?: null]);
        $n++;
    }
    return $n;
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
