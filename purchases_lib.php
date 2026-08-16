<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sales_lib.php';

function supplierPaymentTerms(): array {
    return [
        'cash'      => 'Cash',
        'immediate' => 'Due immediately',
        '7'         => '7 days',
        '15'        => '15 days',
        '30'        => '30 days',
        '45'        => '45 days',
        '60'        => '60 days',
        'custom'    => 'Custom',
    ];
}

function supplierPaymentMethods(): array {
    return [
        'cash'     => 'Cash',
        'bank'     => 'Bank Transfer',
        'telebirr' => 'Telebirr',
        'chapa'    => 'Chapa',
        'other'    => 'Other',
    ];
}

function purchaseReturnReasons(): array {
    return [
        'damaged'       => 'Damaged',
        'expired'       => 'Expired',
        'wrong_product' => 'Wrong product',
        'wrong_qty'     => 'Wrong quantity',
        'supplier'      => 'Supplier issue',
        'recall'        => 'Recall',
        'other'         => 'Other',
    ];
}

function creditDaysFromTerms(string $terms): ?int {
    if ($terms === 'cash' || $terms === 'immediate') return 0;
    if ($terms === 'custom') return null;
    if (ctype_digit($terms)) return (int) $terms;
    return 30;
}

function dueDateFromTerms(string $purchaseDate, string $terms, ?string $customDue = null): ?string {
    if ($customDue) return $customDue;
    $days = creditDaysFromTerms($terms);
    if ($days === null) return $customDue;
    return date('Y-m-d', strtotime($purchaseDate . ' +' . $days . ' days'));
}

function purchaseLineAmounts(float $qty, float $price, float $discountPct, float $taxPct): array {
    $gross = round($qty * $price, 4);
    $discount = round($gross * max(0, $discountPct) / 100, 4);
    $net = $gross - $discount;
    $tax = round($net * max(0, $taxPct) / 100, 4);
    return [
        'gross'    => $gross,
        'discount' => $discount,
        'tax'      => $tax,
        'total'    => round($net + $tax, 2),
    ];
}

function purchaseOutstanding(array $p): float {
    return max(0, round(
        (float)($p['grand_total'] ?? $p['total_amount'] ?? 0)
        - (float)($p['total_paid'] ?? 0)
        - (float)($p['total_returned'] ?? 0),
        2
    ));
}

function recalcPurchasePaymentStatus(PDO $pdo, int $purchaseId): void {
    $p = $pdo->prepare("SELECT * FROM purchases WHERE id=?");
    $p->execute([$purchaseId]);
    $row = $p->fetch();
    if (!$row || ($row['status'] ?? '') === 'cancelled') return;

    $due = purchaseOutstanding($row);
    $paid = (float)$row['total_paid'];
    if ($due <= 0.009) {
        $status = 'paid';
        $due = 0;
    } elseif ($paid > 0.009) {
        $status = 'partial';
    } else {
        $status = 'unpaid';
    }
    $pdo->prepare("UPDATE purchases SET payment_status=?, total_due=?, updated_at=datetime('now') WHERE id=?")
        ->execute([$status, $due, $purchaseId]);
}

function purchaseDisplayStatus(array $p): array {
    $wf = $p['status'] ?? 'received';
    if ($wf === 'cancelled') return ['cancelled', 'Cancelled', 'badge-gray'];
    if ($wf === 'draft') return ['draft', 'Draft', 'badge-gray'];
    if ($wf === 'pending_approval') return ['pending', 'Pending Approval', 'badge-orange'];

    $due = purchaseOutstanding($p);
    $pay = $p['payment_status'] ?? 'unpaid';
    $dueDate = $p['due_date'] ?? null;
    $today = businessToday();

    if ($due <= 0.009) return ['paid', 'Paid', 'badge-green'];
    if ($dueDate && $dueDate < $today) {
        $days = max(1, (int)((strtotime($today) - strtotime($dueDate)) / 86400));
        return ['overdue', 'Overdue by ' . $days . ' day' . ($days === 1 ? '' : 's'), 'badge-red'];
    }
    if ($dueDate && $dueDate === $today) return ['due_today', 'Due Today', 'badge-red'];
    if ($dueDate && $dueDate <= date('Y-m-d', strtotime('+7 days'))) return ['due_soon', 'Due Soon', 'badge-orange'];
    if ($pay === 'partial') return ['partial', 'Partially Paid', 'badge-orange'];
    return ['unpaid', 'Credit', 'badge-blue'];
}

function auditLog(PDO $pdo, string $action, string $entity, ?int $entityId, string $details = ''): void {
    $uid = (function_exists('currentUser') && currentUser()) ? (currentUser()['id'] ?? null) : null;
    $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)")
        ->execute([$uid, $action, $entity, $entityId, $details]);
}

function nextPurchaseNumber(PDO $pdo): string {
    $year = date('Y');
    $prefix = 'PUR-' . $year . '-';
    $stmt = $pdo->prepare("SELECT purchase_number FROM purchases WHERE purchase_number LIKE ? ORDER BY purchase_number DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)$stmt->fetchColumn();
    $n = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $n = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

function nextSupplierCode(PDO $pdo): string {
    $last = (int)$pdo->query("SELECT MAX(id) FROM suppliers")->fetchColumn();
    return sprintf('SUP-%06d', $last + 1);
}

function nextReturnNumber(PDO $pdo): string {
    $year = date('Y');
    $prefix = 'PRN-' . $year . '-';
    $stmt = $pdo->prepare("SELECT return_number FROM purchase_returns WHERE return_number LIKE ? ORDER BY return_number DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)$stmt->fetchColumn();
    $n = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $n = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

function saveSupplier(PDO $pdo, array $data, int $id = 0): int {
    $name = trim($data['name'] ?? '');
    if ($name === '') {
        throw new RuntimeException('Supplier name is required.');
    }
    $terms = trim($data['payment_terms'] ?? '30');
    if (!isset(supplierPaymentTerms()[$terms])) $terms = '30';
    $days = creditDaysFromTerms($terms);
    if ($days === null) $days = (int)($data['default_credit_days'] ?? 30);
    $fields = [
        'name'                => $name,
        'company_name'        => trim($data['company_name'] ?? ''),
        'contact_person'      => trim($data['contact_person'] ?? ''),
        'phone'               => trim($data['phone'] ?? ''),
        'email'               => trim($data['email'] ?? ''),
        'address'             => trim($data['address'] ?? ''),
        'tax_number'          => trim($data['tax_number'] ?? ''),
        'payment_terms'       => $terms,
        'default_credit_days' => $days ?? 0,
        'opening_balance'     => max(0, (float)($data['opening_balance'] ?? 0)),
        'status'              => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? $data['status'] : 'active',
        'notes'               => trim($data['notes'] ?? ''),
        'updated_at'          => date('Y-m-d H:i:s'),
    ];
    if ($id > 0) {
        $pdo->prepare("UPDATE suppliers SET name=?, company_name=?, contact_person=?, phone=?, email=?, address=?, tax_number=?, payment_terms=?, default_credit_days=?, opening_balance=?, status=?, notes=?, updated_at=? WHERE id=?")
            ->execute([...array_values($fields), $id]);
        auditLog($pdo, 'update', 'supplier', $id, $name);
        return $id;
    }
    $code = nextSupplierCode($pdo);
    $pdo->prepare("INSERT INTO suppliers (supplier_code, name, company_name, contact_person, phone, email, address, tax_number, payment_terms, default_credit_days, opening_balance, status, notes, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$code, ...array_values($fields)]);
    $newId = (int)$pdo->lastInsertId();
    auditLog($pdo, 'create', 'supplier', $newId, $name);
    return $newId;
}

function parsePurchaseItemsFromPost(PDO $pdo, array $post): array {
    $meds = $post['medicine_id'] ?? [];
    $codes = $post['product_code'] ?? [];
    $batches = $post['batch_number'] ?? [];
    $mfgs = $post['manufacturing_date'] ?? [];
    $exps = $post['expiry_date'] ?? [];
    $qtys = $post['quantity'] ?? [];
    $pprices = $post['purchase_price'] ?? [];
    $sprices = $post['selling_price'] ?? [];
    $discs = $post['line_discount'] ?? [];
    $taxes = $post['line_tax'] ?? [];
    $variants = $post['variant'] ?? [];
    $models = $post['model_number'] ?? [];
    $serials = $post['serial_number'] ?? [];
    $warrantyPeriods = $post['warranty_period'] ?? [];
    $warrantyExps = $post['warranty_expiry'] ?? [];
    $purchaseDate = trim($post['purchase_date'] ?? '') ?: businessToday();

    $ids = array_values(array_unique(array_filter(array_map('intval', $meds))));
    $typeMap = [];
    $nameMap = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, name, COALESCE(product_type,'medicine') FROM medicines WHERE id IN ($ph)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
            $typeMap[(int)$row[0]] = $row[2];
            $nameMap[(int)$row[0]] = $row[1];
        }
    }

    $items = [];
    $seenBatches = [];
    for ($i = 0; $i < count($meds); $i++) {
        $mid = (int)($meds[$i] ?? 0);
        $qty = (int)($qtys[$i] ?? 0);
        $batch = trim($batches[$i] ?? '');
        $variant = trim($variants[$i] ?? '');
        $model = trim($models[$i] ?? '');
        $serial = trim($serials[$i] ?? '');
        $lineNo = $i + 1;

        if ($mid <= 0 && $qty <= 0 && $batch === '') {
            continue;
        }
        if ($mid <= 0 || !isset($typeMap[$mid])) {
            throw new RuntimeException("Line {$lineNo}: select a product from the master list.");
        }
        $type = $typeMap[$mid];

        if ($type === 'equipment' && $batch === '') {
            // Equipment has no batch number: identify the batch by serial number or an auto-generated code.
            $batch = $serial !== '' ? $serial : ('EQ-' . strtoupper(bin2hex(random_bytes(3))));
        }
        if ($qty <= 0) {
            throw new RuntimeException("Line {$lineNo}: quantity must be greater than 0.");
        }
        if ($batch === '') {
            throw new RuntimeException("Line {$lineNo}: batch number is required.");
        }

        $needExp = productRequiresExpiry($type);
        $expiryRaw = trim($exps[$i] ?? '');
        $expiry = normalizeExpiryDate($expiryRaw, $needExp);
        if ($needExp && !$expiry) {
            throw new RuntimeException("Line {$lineNo}: expiry date is required for medicines.");
        }
        if ($expiryRaw !== '' && !isNoExpiryDate($expiry) && $expiry < $purchaseDate) {
            throw new RuntimeException("Line {$lineNo}: expiry date cannot be before the purchase date.");
        }

        $price = (float)($pprices[$i] ?? 0);
        $sell = (float)($sprices[$i] ?? 0);
        if ($qty > 0 && $price < 0) {
            throw new RuntimeException("Line {$lineNo}: purchase price is invalid.");
        }
        if ($sell < 0) {
            throw new RuntimeException("Line {$lineNo}: selling price is invalid.");
        }

        // Batch identity includes the cosmetic variant / equipment model+serial so
        // different variants or serialized units stay separate stock items.
        $batchKey = $mid . '|' . strtolower($batch) . '|' . strtolower($variant) . '|' . strtolower($model) . '|' . strtolower($serial);
        if (isset($seenBatches[$batchKey])) {
            $medLabel = $nameMap[$mid] ?? 'product';
            throw new RuntimeException("Duplicate batch \"{$batch}\" for {$medLabel} on this invoice. Combine quantities or use a different batch number.");
        }
        $seenBatches[$batchKey] = true;

        $disc = (float)($discs[$i] ?? 0);
        $tax = (float)($taxes[$i] ?? 0);
        $amt = purchaseLineAmounts($qty, $price, $disc, $tax);
        $items[] = [
            'medicine_id'         => $mid,
            'product_code'        => trim($codes[$i] ?? ''),
            'batch_number'        => $batch,
            'manufacturing_date'  => trim($mfgs[$i] ?? '') ?: null,
            'expiry_date'         => $expiry,
            'quantity'            => $qty,
            'free_quantity'       => 0,
            'purchase_price'      => $price,
            'selling_price'       => $sell,
            'discount'            => $disc,
            'tax'                 => $tax,
            'line_total'          => $amt['total'],
            'medicine_name'       => $nameMap[$mid] ?? '',
            'variant'             => $variant,
            'model_number'        => $model,
            'serial_number'       => $serial,
            'warranty_period'     => trim($warrantyPeriods[$i] ?? ''),
            'warranty_expiry'     => trim($warrantyExps[$i] ?? '') ?: null,
        ];
    }
    if (!$items) {
        throw new RuntimeException('Add at least one product with quantity.');
    }
    return $items;
}

function createPurchase(PDO $pdo, array $post, int $userId): int {
    $supplierId = (int)($post['supplier_id'] ?? 0);
    $supplierId = $supplierId > 0 ? $supplierId : null;
    $items = parsePurchaseItemsFromPost($pdo, $post);
    $purchaseDate = trim($post['purchase_date'] ?? '') ?: businessToday();
    $terms = trim($post['payment_terms'] ?? '30');
    if (!isset(supplierPaymentTerms()[$terms])) $terms = '30';
    $dueDate = dueDateFromTerms($purchaseDate, $terms, trim($post['due_date'] ?? '') ?: null);
    $headerDisc = max(0, (float)($post['header_discount'] ?? 0));
    $headerTax = max(0, (float)($post['header_tax'] ?? 0));
    $subtotal = array_sum(array_column($items, 'line_total'));
    $grand = round($subtotal - $headerDisc + $headerTax, 2);
    if ($grand < 0) $grand = 0;

    $payType = $post['payment_type'] ?? 'credit';
    $paidNow = max(0, (float)($post['amount_paid'] ?? 0));
    if ($payType === 'paid') {
        $paidNow = $grand;
    } elseif ($payType === 'credit') {
        $paidNow = 0;
    }
    if ($paidNow - $grand > 0.009) {
        throw new RuntimeException('Payment amount cannot exceed the purchase total.');
    }
    if (in_array($payType, ['credit', 'partial'], true) && $grand - $paidNow > 0.009 && !$dueDate) {
        throw new RuntimeException('A due date is required for credit and partial payments.');
    }

    $intent = $post['save_intent'] ?? 'receive';
    $requireApproval = getSetting('purchase_require_approval', '0') === '1';
    $canApprove = function_exists('can') && (can('purchases.approve') || can('purchases.manage'));
    if ($intent === 'draft') {
        $status = 'draft';
    } elseif ($requireApproval && !$canApprove) {
        $status = 'pending_approval';
    } else {
        $status = 'received';
    }

    $number = nextPurchaseNumber($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO purchases (
                purchase_number, reference, supplier_id, purchase_date, due_date, payment_terms,
                subtotal, discount, tax, grand_total, total_amount, total_paid, total_due, total_returned,
                payment_status, status, warehouse, created_by, notes, received_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $number,
            trim($post['reference'] ?? '') ?: $number,
            $supplierId,
            $purchaseDate,
            $dueDate,
            $terms,
            $subtotal,
            $headerDisc,
            $headerTax,
            $grand,
            $grand,
            0,
            $grand,
            0,
            'unpaid',
            $status,
            trim($post['warehouse'] ?? '') ?: 'Main Store',
            $userId ?: null,
            trim($post['notes'] ?? ''),
            $status === 'received' ? date('Y-m-d H:i:s') : null,
        ]);
        $purchaseId = (int)$pdo->lastInsertId();

        $insItem = $pdo->prepare("
            INSERT INTO purchase_items (
                purchase_id, medicine_id, batch_id, batch_number, manufacturing_date, expiry_date,
                quantity, free_quantity, purchase_price, selling_price, discount, tax, line_total,
                variant, model_number, serial_number, warranty_period, warranty_expiry
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        foreach ($items as $it) {
            $batchId = null;
            if ($status === 'received') {
                $batchId = receivePurchaseLine($pdo, $purchaseId, $supplierId, $it);
            }
            $insItem->execute([
                $purchaseId, $it['medicine_id'], $batchId, $it['batch_number'], $it['manufacturing_date'],
                $it['expiry_date'], $it['quantity'], 0, $it['purchase_price'],
                $it['selling_price'], $it['discount'], $it['tax'], $it['line_total'],
                $it['variant'] ?? null, $it['model_number'] ?? null, $it['serial_number'] ?? null,
                $it['warranty_period'] ?? null, $it['warranty_expiry'] ?? null,
            ]);
        }

        if ($paidNow > 0.009 && $status === 'received') {
            recordPurchasePayment($pdo, $purchaseId, $paidNow, $post['payment_method'] ?? 'cash', $purchaseDate, trim($post['payment_reference'] ?? ''), $userId, 'Initial payment', false);
        } else {
            recalcPurchasePaymentStatus($pdo, $purchaseId);
        }

        auditLog($pdo, 'create', 'purchase', $purchaseId, $number . ' ' . $status . ' ' . currency($grand));
        $pdo->commit();
        return $purchaseId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function receivePurchaseLine(PDO $pdo, int $purchaseId, ?int $supplierId, array $it): int {
    $addQty = (int)$it['quantity'];
    $find = $pdo->prepare("
        SELECT id, quantity, purchase_price, selling_price, expiry_date, manufacture_date
        FROM batches
        WHERE medicine_id=? AND batch_number=?
          AND COALESCE(variant,'')=? AND COALESCE(model_number,'')=? AND COALESCE(serial_number,'')=?
        LIMIT 1
    ");
    $find->execute([
        $it['medicine_id'], $it['batch_number'],
        (string)($it['variant'] ?? ''), (string)($it['model_number'] ?? ''), (string)($it['serial_number'] ?? ''),
    ]);
    $existing = $find->fetch();
    if ($existing) {
        $priceDiff = abs((float)$existing['purchase_price'] - (float)$it['purchase_price']) > 0.009
            || abs((float)$existing['selling_price'] - (float)$it['selling_price']) > 0.009;
        $expNew = (string)($it['expiry_date'] ?? '');
        $expOld = (string)($existing['expiry_date'] ?? '');
        $expDiff = $expNew !== '' && $expOld !== '' && $expNew !== $expOld
            && !(isNoExpiryDate($expNew) && isNoExpiryDate($expOld));
        if ($priceDiff || $expDiff) {
            $label = $it['medicine_name'] ?? $it['med_name'] ?? ('product #' . $it['medicine_id']);
            throw new RuntimeException(
                "Batch \"{$it['batch_number']}\" for {$label} already exists with different price or expiry. "
                . 'Use a different batch number so purchase history and batch pricing stay accurate.'
            );
        }
        // Same lot: add stock only — never overwrite historical purchase/selling prices.
        $pdo->prepare("
            UPDATE batches
            SET quantity = quantity + ?,
                supplier_id = COALESCE(supplier_id, ?),
                purchase_id = COALESCE(purchase_id, ?),
                quantity_received = COALESCE(quantity_received, 0) + ?,
                status = 'active'
            WHERE id = ?
        ")->execute([
            $addQty, $supplierId, $purchaseId, $addQty, $existing['id'],
        ]);
        return (int)$existing['id'];
    }
    $pdo->prepare("
        INSERT INTO batches (
            medicine_id, batch_number, quantity, purchase_price, selling_price, expiry_date,
            manufacture_date, supplier_id, purchase_id, quantity_received, free_quantity, status,
            variant, model_number, serial_number, warranty_period, warranty_expiry
        ) VALUES (?,?,?,?,?,?,?,?,?,?,0,'active',?,?,?,?,?)
    ")->execute([
        $it['medicine_id'], $it['batch_number'], $addQty, $it['purchase_price'], $it['selling_price'],
        $it['expiry_date'], $it['manufacturing_date'], $supplierId, $purchaseId, $addQty,
        $it['variant'] ?? null, $it['model_number'] ?? null, $it['serial_number'] ?? null,
        $it['warranty_period'] ?? null, $it['warranty_expiry'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function receivePurchase(PDO $pdo, int $purchaseId, int $userId): void {
    $p = fetchPurchase($pdo, $purchaseId);
    if (!$p) throw new RuntimeException('Purchase not found.');
    if (!in_array($p['status'], ['draft', 'pending_approval'], true)) {
        throw new RuntimeException('This purchase has already been received.');
    }
    $items = fetchPurchaseItems($pdo, $purchaseId);
    $pdo->beginTransaction();
    try {
        $updItem = $pdo->prepare("UPDATE purchase_items SET batch_id=? WHERE id=?");
        foreach ($items as $it) {
            $batchId = receivePurchaseLine($pdo, $purchaseId, $p['supplier_id'] !== null ? (int)$p['supplier_id'] : null, $it);
            $updItem->execute([$batchId, $it['id']]);
        }
        $pdo->prepare("UPDATE purchases SET status='received', received_at=datetime('now'), approved_by=?, approved_at=datetime('now'), updated_at=datetime('now') WHERE id=?")
            ->execute([$userId ?: null, $purchaseId]);
        auditLog($pdo, 'receive', 'purchase', $purchaseId, $p['purchase_number']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function recordPurchasePayment(PDO $pdo, int $purchaseId, float $amount, string $method, string $date, string $reference, int $userId, string $notes = '', bool $ownTransaction = true): void {
    $p = fetchPurchase($pdo, $purchaseId);
    if (!$p) throw new RuntimeException('Purchase not found.');
    if (($p['status'] ?? '') === 'cancelled') {
        throw new RuntimeException('Cannot pay a cancelled purchase.');
    }
    if (($p['status'] ?? '') !== 'received') {
        throw new RuntimeException('Receive the purchase before recording a payment.');
    }
    $outstanding = purchaseOutstanding($p);
    if ($outstanding <= 0.009) {
        throw new RuntimeException('This invoice is already fully paid.');
    }
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }
    if ($amount - $outstanding > 0.009) {
        throw new RuntimeException('Payment amount cannot exceed the outstanding balance.');
    }
    if (!isset(supplierPaymentMethods()[$method])) $method = 'cash';

    $run = function () use ($pdo, $p, $purchaseId, $amount, $method, $date, $reference, $userId, $notes) {
        $pdo->prepare("
            INSERT INTO purchase_payments (purchase_id, supplier_id, payment_date, amount, payment_method, reference_number, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $purchaseId, $p['supplier_id'], $date ?: businessToday(), round($amount, 2), $method,
            $reference ?: null, $notes ?: null, $userId ?: null,
        ]);
        $pdo->prepare("UPDATE purchases SET total_paid = COALESCE(total_paid,0) + ? WHERE id=?")->execute([round($amount, 2), $purchaseId]);
        recalcPurchasePaymentStatus($pdo, $purchaseId);
        auditLog($pdo, 'payment', 'purchase', $purchaseId, currency($amount) . ' via ' . $method);
    };

    if ($ownTransaction) {
        $pdo->beginTransaction();
        try {
            $run();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } else {
        $run();
    }
}

function cancelPurchase(PDO $pdo, int $purchaseId, int $userId): void {
    $p = fetchPurchase($pdo, $purchaseId);
    if (!$p) throw new RuntimeException('Purchase not found.');
    if (($p['status'] ?? '') === 'cancelled') {
        throw new RuntimeException('Purchase is already cancelled.');
    }
    if ((float)$p['total_paid'] > 0.009) {
        throw new RuntimeException('Cannot cancel a purchase with payments. Record a return or keep the invoice.');
    }
    $pdo->beginTransaction();
    try {
        if (($p['status'] ?? '') === 'received') {
            $items = fetchPurchaseItems($pdo, $purchaseId);
            foreach ($items as $it) {
                $qty = (int)$it['quantity'] - (int)($it['returned_quantity'] ?? 0);
                if ($qty > 0 && !empty($it['batch_id'])) {
                    $pdo->prepare("UPDATE batches SET quantity = MAX(0, quantity - ?) WHERE id=?")->execute([$qty, $it['batch_id']]);
                }
            }
        }
        $pdo->prepare("UPDATE purchases SET status='cancelled', total_due=0, updated_at=datetime('now') WHERE id=?")->execute([$purchaseId]);
        auditLog($pdo, 'cancel', 'purchase', $purchaseId, $p['purchase_number']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function createPurchaseReturn(PDO $pdo, int $purchaseId, array $post, int $userId): int {
    $p = fetchPurchase($pdo, $purchaseId);
    if (!$p) throw new RuntimeException('Purchase not found.');
    if (($p['status'] ?? '') !== 'received') {
        throw new RuntimeException('Only received purchases can be returned.');
    }
    $reason = trim($post['reason'] ?? 'other');
    if (!isset(purchaseReturnReasons()[$reason])) $reason = 'other';
    $qtys = $post['return_qty'] ?? [];
    $items = fetchPurchaseItems($pdo, $purchaseId);
    $lines = [];
    $total = 0;
    foreach ($items as $it) {
        $max = (int)$it['quantity'] - (int)($it['returned_quantity'] ?? 0);
        $qty = (int)($qtys[$it['id']] ?? 0);
        if ($qty <= 0) continue;
        if ($qty > $max) {
            throw new RuntimeException('Cannot return more than remaining quantity for a line.');
        }
        $unit = (float)$it['purchase_price'];
        $line = round($unit * $qty, 2);
        $lines[] = [
            'purchase_item_id' => (int)$it['id'],
            'medicine_id'      => (int)$it['medicine_id'],
            'batch_id'         => $it['batch_id'] ? (int)$it['batch_id'] : null,
            'quantity'         => $qty,
            'unit_price'       => $unit,
            'total'            => $line,
        ];
        $total += $line;
    }
    if (!$lines) {
        throw new RuntimeException('Select at least one quantity to return.');
    }

    $pdo->beginTransaction();
    try {
        $number = nextReturnNumber($pdo);
        $pdo->prepare("
            INSERT INTO purchase_returns (return_number, purchase_id, supplier_id, return_date, total_amount, reason, status, created_by, notes)
            VALUES (?,?,?,?,?,?, 'completed', ?, ?)
        ")->execute([
            $number, $purchaseId, $p['supplier_id'], trim($post['return_date'] ?? '') ?: businessToday(),
            $total, $reason, $userId ?: null, trim($post['notes'] ?? ''),
        ]);
        $returnId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO purchase_return_items (return_id, purchase_item_id, medicine_id, batch_id, quantity, unit_price, total) VALUES (?,?,?,?,?,?,?)");
        $updItem = $pdo->prepare("UPDATE purchase_items SET returned_quantity = COALESCE(returned_quantity,0) + ? WHERE id=?");
        $updBatch = $pdo->prepare("UPDATE batches SET quantity = MAX(0, quantity - ?) WHERE id=?");
        foreach ($lines as $ln) {
            $ins->execute([$returnId, $ln['purchase_item_id'], $ln['medicine_id'], $ln['batch_id'], $ln['quantity'], $ln['unit_price'], $ln['total']]);
            $updItem->execute([$ln['quantity'], $ln['purchase_item_id']]);
            if ($ln['batch_id']) {
                $updBatch->execute([$ln['quantity'], $ln['batch_id']]);
            }
        }
        $pdo->prepare("UPDATE purchases SET total_returned = COALESCE(total_returned,0) + ?, updated_at=datetime('now') WHERE id=?")
            ->execute([$total, $purchaseId]);
        recalcPurchasePaymentStatus($pdo, $purchaseId);
        auditLog($pdo, 'return', 'purchase', $purchaseId, $number . ' ' . currency($total));
        $pdo->commit();
        return $returnId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function fetchPurchase(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT p.*, s.name AS supplier_name, s.phone AS supplier_phone, s.email AS supplier_email,
               s.address AS supplier_address, s.company_name, s.tax_number AS supplier_tax,
               s.contact_person, u.full_name AS created_by_name
        FROM purchases p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        LEFT JOIN users u ON u.id = p.created_by
        WHERE p.id=?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetchPurchaseItems(PDO $pdo, int $purchaseId): array {
    $stmt = $pdo->prepare("
        SELECT pi.*, m.name AS med_name, m.generic_name, m.unit, m.sku,
               COALESCE(m.product_type,'medicine') AS product_type,
               c.name AS category_name
        FROM purchase_items pi
        JOIN medicines m ON m.id = pi.medicine_id
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE pi.purchase_id=?
        ORDER BY pi.id
    ");
    $stmt->execute([$purchaseId]);
    return $stmt->fetchAll();
}

function fetchPurchasePayments(PDO $pdo, int $purchaseId): array {
    $stmt = $pdo->prepare("
        SELECT pp.*, u.full_name AS created_by_name
        FROM purchase_payments pp
        LEFT JOIN users u ON u.id = pp.created_by
        WHERE pp.purchase_id=?
        ORDER BY pp.payment_date ASC, pp.id ASC
    ");
    $stmt->execute([$purchaseId]);
    return $stmt->fetchAll();
}

function fetchPurchaseReturns(PDO $pdo, int $purchaseId): array {
    $stmt = $pdo->prepare("SELECT * FROM purchase_returns WHERE purchase_id=? ORDER BY return_date DESC, id DESC");
    $stmt->execute([$purchaseId]);
    return $stmt->fetchAll();
}

function supplierTotals(PDO $pdo, int $supplierId): array {
    $st = $pdo->prepare("SELECT COALESCE(opening_balance,0) FROM suppliers WHERE id=?");
    $st->execute([$supplierId]);
    $opening = (float)$st->fetchColumn();

    $purch = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(grand_total, total_amount)),0) FROM purchases WHERE supplier_id=? AND status='received'");
    $purch->execute([$supplierId]);
    $totalPurchases = (float)$purch->fetchColumn();

    $paid = $pdo->prepare("SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payments pp JOIN purchases p ON p.id=pp.purchase_id WHERE p.supplier_id=? AND p.status!='cancelled'");
    $paid->execute([$supplierId]);
    $totalPaid = (float)$paid->fetchColumn();

    $ret = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchase_returns WHERE supplier_id=? AND status='completed'");
    $ret->execute([$supplierId]);
    $totalReturned = (float)$ret->fetchColumn();

    $outstanding = $opening + $totalPurchases - $totalPaid - $totalReturned;
    $over = $pdo->prepare("
        SELECT COALESCE(SUM(COALESCE(grand_total,total_amount) - COALESCE(total_paid,0) - COALESCE(total_returned,0)),0)
        FROM purchases
        WHERE supplier_id=? AND status='received'
          AND (COALESCE(grand_total,total_amount) - COALESCE(total_paid,0) - COALESCE(total_returned,0)) > 0.009
          AND due_date IS NOT NULL AND due_date < date('now')
    ");
    $over->execute([$supplierId]);
    $overdue = (float)$over->fetchColumn();

    return [
        'opening'        => $opening,
        'total_purchases'=> $totalPurchases,
        'total_paid'     => $totalPaid,
        'total_returned' => $totalReturned,
        'outstanding'    => $outstanding,
        'overdue'        => $overdue,
    ];
}

function supplierLedger(PDO $pdo, int $supplierId): array {
    $st = $pdo->prepare("SELECT opening_balance, created_at FROM suppliers WHERE id=?");
    $st->execute([$supplierId]);
    $sup = $st->fetch();
    $rows = [];
    $balance = (float)($sup['opening_balance'] ?? 0);
    if (abs($balance) > 0.009) {
        $rows[] = [
            'date' => substr((string)($sup['created_at'] ?? businessToday()), 0, 10),
            'description' => 'Opening balance',
            'debit' => $balance > 0 ? $balance : 0,
            'credit' => $balance < 0 ? abs($balance) : 0,
            'balance' => $balance,
        ];
    }
    $purchases = $pdo->prepare("SELECT purchase_number, purchase_date, created_at, COALESCE(grand_total,total_amount) AS amt FROM purchases WHERE supplier_id=? AND status='received' ORDER BY COALESCE(purchase_date, date(created_at)), id");
    $purchases->execute([$supplierId]);
    $payments = $pdo->prepare("SELECT pp.payment_date, pp.amount, pp.payment_method, p.purchase_number FROM purchase_payments pp JOIN purchases p ON p.id=pp.purchase_id WHERE p.supplier_id=? AND p.status!='cancelled' ORDER BY pp.payment_date, pp.id");
    $payments->execute([$supplierId]);
    $returns = $pdo->prepare("SELECT return_date, total_amount, return_number, purchase_id FROM purchase_returns WHERE supplier_id=? AND status='completed' ORDER BY return_date, id");
    $returns->execute([$supplierId]);

    $events = [];
    foreach ($purchases->fetchAll() as $r) {
        $events[] = ['date' => $r['purchase_date'] ?: substr($r['created_at'], 0, 10), 'sort' => 1, 'description' => 'Purchase ' . $r['purchase_number'], 'debit' => (float)$r['amt'], 'credit' => 0];
    }
    foreach ($payments->fetchAll() as $r) {
        $events[] = ['date' => $r['payment_date'], 'sort' => 2, 'description' => 'Payment ' . $r['purchase_number'] . ' (' . ($r['payment_method'] ?? '') . ')', 'debit' => 0, 'credit' => (float)$r['amount']];
    }
    foreach ($returns->fetchAll() as $r) {
        $events[] = ['date' => $r['return_date'], 'sort' => 3, 'description' => 'Return ' . $r['return_number'], 'debit' => 0, 'credit' => (float)$r['total_amount']];
    }
    usort($events, fn($a, $b) => strcmp($a['date'] . $a['sort'], $b['date'] . $b['sort']));
    foreach ($events as $e) {
        $balance += $e['debit'] - $e['credit'];
        $e['balance'] = $balance;
        $rows[] = $e;
    }
    return $rows;
}

function payableDashboard(PDO $pdo): array {
    $openSql = "status='received' AND (COALESCE(grand_total,total_amount) - COALESCE(total_paid,0) - COALESCE(total_returned,0)) > 0.009";
    $dueExpr = "COALESCE(grand_total,total_amount) - COALESCE(total_paid,0) - COALESCE(total_returned,0)";
    $total = (float)$pdo->query("SELECT COALESCE(SUM($dueExpr),0) FROM purchases WHERE $openSql")->fetchColumn();
    $overdue = (float)$pdo->query("SELECT COALESCE(SUM($dueExpr),0) FROM purchases WHERE $openSql AND due_date < date('now')")->fetchColumn();
    $dueToday = (float)$pdo->query("SELECT COALESCE(SUM($dueExpr),0) FROM purchases WHERE $openSql AND due_date = date('now')")->fetchColumn();
    $dueWeek = (float)$pdo->query("SELECT COALESCE(SUM($dueExpr),0) FROM purchases WHERE $openSql AND due_date BETWEEN date('now') AND date('now','+7 days')")->fetchColumn();
    $dueMonth = (float)$pdo->query("SELECT COALESCE(SUM($dueExpr),0) FROM purchases WHERE $openSql AND due_date BETWEEN date('now','start of month') AND date('now','start of month','+1 month','-1 day')")->fetchColumn();
    $paidCnt = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE status='received' AND payment_status='paid'")->fetchColumn();
    $creditCnt = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE $openSql AND COALESCE(total_paid,0) <= 0.009")->fetchColumn();
    $partialCnt = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE status='received' AND payment_status='partial'")->fetchColumn();
    $suppliers = (int)$pdo->query("SELECT COUNT(DISTINCT supplier_id) FROM purchases WHERE $openSql")->fetchColumn();
    return compact('total', 'overdue', 'dueToday', 'dueWeek', 'dueMonth', 'paidCnt', 'creditCnt', 'partialCnt', 'suppliers');
}

function listPurchases(PDO $pdo, array $filters, int $page, int $perPage): array {
    $where = [];
    $params = [];
    if (!empty($filters['q'])) {
        $where[] = "(p.purchase_number LIKE ? OR p.reference LIKE ? OR s.name LIKE ? OR EXISTS (
            SELECT 1 FROM purchase_items pi LEFT JOIN medicines m ON m.id=pi.medicine_id
            WHERE pi.purchase_id=p.id AND (pi.batch_number LIKE ? OR m.name LIKE ?)
        ))";
        $q = '%' . $filters['q'] . '%';
        array_push($params, $q, $q, $q, $q, $q);
    }
    if (!empty($filters['supplier'])) { $where[] = "p.supplier_id=?"; $params[] = (int)$filters['supplier']; }
    if (!empty($filters['from'])) { $where[] = "COALESCE(p.purchase_date, date(p.created_at)) >= ?"; $params[] = $filters['from']; }
    if (!empty($filters['to'])) { $where[] = "COALESCE(p.purchase_date, date(p.created_at)) <= ?"; $params[] = $filters['to']; }
    if (!empty($filters['status'])) {
        if ($filters['status'] === 'overdue') {
            $where[] = "p.status='received' AND p.due_date < date('now') AND (COALESCE(p.grand_total,p.total_amount)-COALESCE(p.total_paid,0)-COALESCE(p.total_returned,0)) > 0.009";
        } elseif ($filters['status'] === 'due_today') {
            $where[] = "p.status='received' AND p.due_date = date('now') AND (COALESCE(p.grand_total,p.total_amount)-COALESCE(p.total_paid,0)-COALESCE(p.total_returned,0)) > 0.009";
        } else {
            $where[] = "p.payment_status=?"; $params[] = $filters['status'];
        }
    }
    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $order = match ($filters['sort'] ?? 'newest') {
        'oldest' => 'COALESCE(p.purchase_date, p.created_at) ASC',
        'highest' => 'COALESCE(p.grand_total, p.total_amount) DESC',
        'lowest' => 'COALESCE(p.grand_total, p.total_amount) ASC',
        'due' => "CASE WHEN p.due_date IS NULL THEN 1 ELSE 0 END, p.due_date ASC",
        'overdue' => "CASE WHEN p.due_date < date('now') AND (COALESCE(p.grand_total,p.total_amount)-COALESCE(p.total_paid,0)-COALESCE(p.total_returned,0)) > 0.009 THEN 0 ELSE 1 END, p.due_date ASC",
        default => 'p.id DESC',
    };
    $count = $pdo->prepare("SELECT COUNT(*) FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $sql = "
        SELECT p.*, s.name AS supplier_name,
          (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id=p.id) AS items
        FROM purchases p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        $whereSql
        ORDER BY $order
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/**
 * Quick-create a master product (medicine / cosmetic / equipment) from the purchase
 * screen. Refuses to create a duplicate of an existing product name.
 */
function quickCreateProduct(PDO $pdo, array $post): array {
    $name = trim($post['name'] ?? '');
    $type = trim($post['product_type'] ?? 'medicine');
    if (!isset(productTypes()[$type])) $type = 'medicine';
    if ($name === '') {
        throw new RuntimeException('Product name is required.');
    }
    $unit = trim($post['unit'] ?? '');
    if ($unit === '') {
        $unit = 'pcs';
    }
    $sku = trim($post['sku'] ?? '');
    $barcode = trim($post['barcode'] ?? '');
    $categoryId = (int)($post['category_id'] ?? 0) ?: null;

    $dup = $pdo->prepare("SELECT id, name, COALESCE(product_type,'medicine') FROM medicines WHERE LOWER(name)=LOWER(?) LIMIT 1");
    $dup->execute([$name]);
    if ($dup->fetch()) {
        throw new RuntimeException("A product named \"{$name}\" already exists. Select it from the search results instead of creating a duplicate.");
    }
    if ($sku !== '') {
        $dupSku = $pdo->prepare("SELECT id FROM medicines WHERE LOWER(sku)=LOWER(?) LIMIT 1");
        $dupSku->execute([$sku]);
        if ($dupSku->fetch()) {
            throw new RuntimeException("SKU \"{$sku}\" is already used by another product. Enter a different SKU or leave it blank.");
        }
    }
    if ($barcode !== '') {
        $dupBar = $pdo->prepare("SELECT id FROM medicines WHERE LOWER(barcode)=LOWER(?) LIMIT 1");
        $dupBar->execute([$barcode]);
        if ($dupBar->fetch()) {
            throw new RuntimeException("Barcode \"{$barcode}\" is already used by another product. Enter a different barcode or leave it blank.");
        }
    }

    $pdo->prepare("INSERT INTO medicines (name, category_id, unit, sku, barcode, reorder_level, product_type, description) VALUES (?,?,?,?,?,10,?,?)")
        ->execute([$name, $categoryId, $unit, $sku !== '' ? $sku : null, $barcode !== '' ? $barcode : null, $type, 'Created from purchase screen']);
    $newId = (int)$pdo->lastInsertId();
    auditLog($pdo, 'create', 'medicine', $newId, $name);

    $st = $pdo->prepare("
        SELECT m.id, m.name, m.generic_name, m.unit, m.sku, m.barcode,
               COALESCE(m.product_type,'medicine') AS product_type,
               c.name AS category_name
        FROM medicines m
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE m.id=?
    ");
    $st->execute([$newId]);
    return $st->fetch();
}
