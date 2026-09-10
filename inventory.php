<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';
require_once __DIR__ . '/purchases_lib.php';
require_once __DIR__ . '/inventory_lib.php';
require_once __DIR__ . '/report_lib.php';

$pdo = getDB();
$userId = (int)(currentUser()['id'] ?? 0);
$currency = getSetting('currency', 'ETB');
$pharmacyName = getSetting('pharmacy_name', 'TADE PHARMACY');

$canEdit = can('inventory.edit') || can('inventory.manage');
$canDelete = can('inventory.delete') || can('inventory.manage');
$canAdjust = can('inventory.adjust') || can('inventory.manage');
$canChangeBuy = can('inventory.change_buy') || can('inventory.manage');
$canChangeSell = can('inventory.change_sell') || can('inventory.manage');
$canExport = can('reports.export') || can('reports.view') || can('inventory.view');

/** Build a query string from current inventory filters, with optional overrides. */
function invQs(array $base, array $overrides = []): string {
    $q = array_merge($base, $overrides);
    $out = [];
    foreach ($q as $k => $v) {
        if ($v === null || $v === '' || $v === false) continue;
        if ($k === 'filter' && $v === 'all') continue;
        if ($k === 'page' && (int)$v <= 1) continue;
        if ($k === 'per_page' && (int)$v === 25) continue;
        if ($k === 'within' && ($q['filter'] ?? 'all') !== 'expiring') continue;
        if (in_array($k, ['from_date', 'to_date'], true) && ($q['within'] ?? '') !== 'custom') continue;
        $out[$k] = $v;
    }
    return http_build_query($out);
}

function invRedirect(string $msg, string $type = 'success'): void {
    flashSet($type, $msg);
    $qs = $_POST['return_qs'] ?? '';
    header('Location: inventory.php' . ($qs ? '?' . ltrim($qs, '?') : ''));
    exit;
}

// ── POST actions (PRG) ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'update_batch') {
        if (!$canEdit) {
            invRedirect('You do not have permission to edit inventory.', 'error');
        }
        $bid = (int)($_POST['batch_id'] ?? 0);
        $medicineId = (int)($_POST['medicine_id'] ?? 0);
        $batchNumber = trim($_POST['batch_number'] ?? '');
        $quantity = (int)($_POST['quantity'] ?? 0);
        $expiryDate = trim($_POST['expiry_date'] ?? '');
        $manufactureDate = trim($_POST['manufacture_date'] ?? '') ?: null;
        $variant = trim($_POST['variant'] ?? '');
        $modelNumber = trim($_POST['model_number'] ?? '');
        $serialNumber = trim($_POST['serial_number'] ?? '');
        $warrantyPeriod = trim($_POST['warranty_period'] ?? '');
        $warrantyExpiry = trim($_POST['warranty_expiry'] ?? '') ?: null;
        $supplierId = (int)($_POST['supplier_id'] ?? 0) ?: null;
        $batchNotes = trim($_POST['notes'] ?? '');

        $medName = trim($_POST['med_name'] ?? '');
        $genericName = trim($_POST['generic_name'] ?? '');
        $brandName = trim($_POST['brand_name'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
        $unit = trim($_POST['unit'] ?? '') ?: 'pcs';
        $reorderLevel = (int)($_POST['reorder_level'] ?? 0);
        // Single notes field updates batch notes; keep product notes in sync when provided.
        $medNotes = trim($_POST['med_notes'] ?? '');
        if ($medNotes === '') {
            $medNotes = $batchNotes;
        }

        $typeStmt = $pdo->prepare("SELECT COALESCE(product_type, 'medicine') FROM medicines WHERE id=?");
        $typeStmt->execute([$medicineId]);
        $productType = $typeStmt->fetchColumn() ?: 'medicine';
        $expiryRequired = productRequiresExpiry($productType);
        $expiryDate = normalizeExpiryDate($expiryDate, $expiryRequired);

        $cur = $pdo->prepare("SELECT * FROM batches WHERE id=? AND medicine_id=?");
        $cur->execute([$bid, $medicineId]);
        $oldBatch = $cur->fetch();
        if (!$oldBatch) {
            invRedirect('Batch not found.', 'error');
        }

        $purchasePrice = $canChangeBuy
            ? (float)($_POST['purchase_price'] ?? $oldBatch['purchase_price'])
            : (float)$oldBatch['purchase_price'];
        $sellingPrice = $canChangeSell
            ? (float)($_POST['selling_price'] ?? $oldBatch['selling_price'])
            : (float)$oldBatch['selling_price'];

        if (!$medicineId || !$batchNumber || ($expiryRequired && !$expiryDate)) {
            invRedirect(
                $expiryRequired
                    ? 'Product, batch number, and expiry date are required.'
                    : 'Product and batch number are required.',
                'error'
            );
        }
        if ($quantity < 0 || $purchasePrice < 0 || $sellingPrice < 0) {
            invRedirect('Quantity and prices cannot be negative.', 'error');
        }
        if ($medName === '') {
            invRedirect('Product name is required.', 'error');
        }

        $dup = $pdo->prepare("SELECT id FROM batches WHERE medicine_id=? AND batch_number=? AND id!=?");
        $dup->execute([$medicineId, $batchNumber, $bid]);
        if ($dup->fetch()) {
            invRedirect('Another batch with this number already exists for this product.', 'error');
        }

        $oldQty = (int)$oldBatch['quantity'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE medicines
                SET name=?, generic_name=?, brand_name=?, category_id=?, unit=?, reorder_level=?, notes=?
                WHERE id=?
            ")->execute([
                $medName, $genericName, $brandName !== '' ? $brandName : $medName,
                $categoryId, $unit, $reorderLevel, $medNotes !== '' ? $medNotes : null, $medicineId,
            ]);

            $pdo->prepare("
                UPDATE batches
                SET batch_number=?, quantity=?, purchase_price=?, selling_price=?,
                    expiry_date=?, manufacture_date=?, supplier_id=?,
                    variant=?, model_number=?, serial_number=?, warranty_period=?, warranty_expiry=?, notes=?
                WHERE id=?
            ")->execute([
                $batchNumber, $quantity, $purchasePrice, $sellingPrice,
                $expiryDate, $manufactureDate, $supplierId,
                $variant, $modelNumber, $serialNumber, $warrantyPeriod, $warrantyExpiry,
                $batchNotes !== '' ? $batchNotes : null, $bid,
            ]);

            if ($quantity !== $oldQty && $canAdjust) {
                recordStockMovement(
                    $pdo, $medicineId, $bid, 'adjustment',
                    $quantity - $oldQty, 'Manual edit',
                    'batch_edit', $bid, $batchNumber, $userId, $oldQty
                );
            }

            if (function_exists('auditLog')) {
                auditLog($pdo, 'batch_edit', 'batch', $bid,
                    "Batch {$batchNumber}: qty {$oldQty}→{$quantity}, buy {$purchasePrice}, sell {$sellingPrice}");
            }
            $pdo->commit();
            invRedirect('Batch updated successfully.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            invRedirect('Update failed: ' . $e->getMessage(), 'error');
        }
    }

    if ($act === 'delete_batch') {
        if (!$canDelete) {
            invRedirect('You do not have permission to delete batches.', 'error');
        }
        $delBid = (int)($_POST['batch_id'] ?? 0);
        $result = deleteOrDeactivateBatch($pdo, $delBid, $userId);
        invRedirect($result['ok'] ? $result['message'] : $result['error'], $result['ok'] ? 'success' : 'error');
    }

    if ($act === 'adjust_stock') {
        if (!$canAdjust) {
            invRedirect('You do not have permission to adjust stock.', 'error');
        }
        $adjBid = (int)($_POST['batch_id'] ?? 0);
        $adjQty = (int)($_POST['adjustment_qty'] ?? 0);
        $reasonKey = trim($_POST['reason'] ?? '');
        $adjNotes = trim($_POST['notes'] ?? '');
        $result = adjustBatchStock($pdo, $adjBid, $adjQty, $reasonKey, $adjNotes, $userId);
        invRedirect($result['ok'] ? $result['message'] : $result['error'], $result['ok'] ? 'success' : 'error');
    }

    if ($act === 'expired_action') {
        if (!$canAdjust) {
            invRedirect('You do not have permission to process expired stock.', 'error');
        }
        $exBid = (int)($_POST['batch_id'] ?? 0);
        $exAction = trim($_POST['expired_action'] ?? '');
        $exQty = (int)($_POST['quantity'] ?? 0);
        $exNotes = trim($_POST['notes'] ?? '');
        $result = expiredStockAction($pdo, $exBid, $exAction, $exQty, $exNotes, $userId);
        invRedirect($result['ok'] ? $result['message'] : $result['error'], $result['ok'] ? 'success' : 'error');
    }

    invRedirect('Unknown action.', 'error');
}

// ── GET filters ────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'low', 'expiring', 'expired', 'out'], true)) {
    $filter = 'all';
}
$medId = (int)($_GET['med'] ?? 0);
$typeFilter = trim($_GET['type'] ?? '');
if ($typeFilter !== '' && !isset(productTypes()[$typeFilter])) {
    $typeFilter = '';
}
$catFilter = (int)($_GET['cat'] ?? 0);
$categoriesByType = categoriesByProductType($pdo);
$typeCategories = $typeFilter ? ($categoriesByType[$typeFilter] ?? []) : [];

$expiryWithin = expiryWithinParse(
    $_GET['within'] ?? '30',
    '30',
    $_GET['from_date'] ?? '',
    $_GET['to_date'] ?? ''
);
$expiryDays = (int)$expiryWithin['days'];
$expiryKey = $expiryWithin['key'];
$expiryFrom = $expiryWithin['from'] ?? '';
$expiryTo = $expiryWithin['to'] ?? '';
if ($filter !== 'expiring') {
    $expiryKey = '30';
    $expiryDays = 30;
    $expiryFrom = '';
    $expiryTo = '';
    $expiryWithin = expiryWithinParse('30');
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = inventoryParsePerPage($_GET['per_page'] ?? null);
$export = trim($_GET['export'] ?? '');
if ($export !== '' && !$canExport) {
    $export = '';
}

$suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll();
$allCategories = $pdo->query("
    SELECT id, name, COALESCE(product_type,'medicine') AS product_type
    FROM categories ORDER BY name COLLATE NOCASE
")->fetchAll();
$medicines = $pdo->query("
    SELECT id, name, unit, COALESCE(product_type,'medicine') AS product_type
    FROM medicines ORDER BY name
")->fetchAll();

$catName = '';
if ($catFilter) {
    foreach ($allCategories as $c) {
        if ((int)$c['id'] === $catFilter) {
            $catName = $c['name'];
            break;
        }
    }
}

$viewBatches = $medId > 0;
$activeSql = "COALESCE(b.status,'active')='active'";

$qsBase = [
    'filter' => $filter,
    'q' => $search,
    'type' => $typeFilter,
    'cat' => $catFilter ?: null,
    'within' => $filter === 'expiring' ? $expiryKey : null,
    'from_date' => ($filter === 'expiring' && $expiryKey === 'custom') ? $expiryFrom : null,
    'to_date' => ($filter === 'expiring' && $expiryKey === 'custom') ? $expiryTo : null,
    'med' => $medId ?: null,
    'per_page' => $perPage,
];
$returnQs = invQs($qsBase, ['page' => $page > 1 ? $page : null]);

$params = [];
$where = [$activeSql];

if ($search !== '') {
    $where[] = inventorySearchSql('m', 'b', 'c');
    $params = array_merge($params, inventorySearchParams($search));
}
if ($typeFilter) {
    $where[] = "COALESCE(m.product_type, 'medicine') = ?";
    $params[] = $typeFilter;
}
if ($catFilter) {
    $where[] = "m.category_id = ?";
    $params[] = $catFilter;
}

$batches = [];
$stockRows = [];
$focusMedName = '';
$totalRows = 0;
$totalPages = 1;

if ($viewBatches) {
    $where[] = "b.medicine_id = ?";
    $params[] = $medId;
    if ($filter === 'low') {
        $where[] = "b.quantity > 0 AND b.quantity <= m.reorder_level";
    }
    if ($filter === 'expired') {
        $where[] = "b.expiry_date < date('now') AND b.expiry_date < '9000-01-01'";
    }
    if ($filter === 'expiring') {
        $where[] = expiryWithinSql('b', $expiryDays, $expiryFrom, $expiryTo);
    }
    if ($filter === 'out') {
        $where[] = "b.quantity = 0";
    }

    $fromSql = "
        FROM batches b
        JOIN medicines m ON m.id = b.medicine_id
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN suppliers s ON s.id = b.supplier_id
        WHERE " . implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) " . $fromSql);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;

    $selectSql = "
        SELECT b.*, m.name AS med_name, m.generic_name,
               COALESCE(NULLIF(TRIM(m.brand_name), ''), m.name) AS brand_name,
               m.reorder_level, m.unit, m.category_id, m.notes AS med_notes,
               COALESCE(m.product_type, 'medicine') AS product_type,
               c.name AS cat_name, s.name AS supplier_name
        " . $fromSql . "
        ORDER BY b.expiry_date ASC, m.name COLLATE NOCASE
    ";

    if ($export !== '') {
        $stmt = $pdo->prepare($selectSql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll();
    } else {
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare($selectSql . " LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        $batches = $stmt->fetchAll();
    }

    $focusMed = $pdo->prepare("SELECT name FROM medicines WHERE id=?");
    $focusMed->execute([$medId]);
    $focusMedName = $focusMed->fetchColumn() ?: 'Product';
} else {
    $having = [];
    if ($filter === 'low') {
        $having[] = "COALESCE(SUM(b.quantity), 0) > 0 AND COALESCE(SUM(b.quantity), 0) <= m.reorder_level";
    }
    if ($filter === 'expired') {
        $having[] = "SUM(CASE WHEN b.expiry_date < date('now') AND b.expiry_date < '9000-01-01' AND b.quantity > 0 THEN 1 ELSE 0 END) > 0";
    }
    if ($filter === 'expiring') {
        $having[] = 'SUM(CASE WHEN ' . expiryWithinSql('b', $expiryDays, $expiryFrom, $expiryTo) . ' THEN 1 ELSE 0 END) > 0';
    }
    if ($filter === 'out') {
        $having[] = "COALESCE(SUM(b.quantity), 0) = 0";
    }

    $fromSql = "
        FROM medicines m
        JOIN batches b ON b.medicine_id = m.id AND {$activeSql}
        LEFT JOIN categories c ON c.id = m.category_id
    ";
    $prodWhere = [];
    $prodParams = [];
    if ($search !== '') {
        $prodWhere[] = inventorySearchSql('m', 'b', 'c');
        $prodParams = array_merge($prodParams, inventorySearchParams($search));
    }
    if ($typeFilter) {
        $prodWhere[] = "COALESCE(m.product_type, 'medicine') = ?";
        $prodParams[] = $typeFilter;
    }
    if ($catFilter) {
        $prodWhere[] = "m.category_id = ?";
        $prodParams[] = $catFilter;
    }
    $whereSql = $prodWhere ? (' WHERE ' . implode(' AND ', $prodWhere)) : '';
    $havingSql = $having ? (' HAVING ' . implode(' AND ', $having)) : '';

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM (
            SELECT m.id
            {$fromSql}
            {$whereSql}
            GROUP BY m.id
            {$havingSql}
        )
    ");
    $countStmt->execute($prodParams);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;

    $selectSql = "
        SELECT m.id AS medicine_id, m.name AS med_name, m.generic_name,
               COALESCE(NULLIF(TRIM(m.brand_name), ''), m.name) AS brand_name,
               m.reorder_level, m.unit,
               COALESCE(m.product_type, 'medicine') AS product_type, c.name AS cat_name,
               COALESCE(SUM(b.quantity), 0) AS stock,
               COUNT(b.id) AS batch_count,
               MIN(CASE WHEN b.quantity > 0 AND b.expiry_date < '9000-01-01' THEN b.expiry_date END) AS next_expiry
        {$fromSql}
        {$whereSql}
        GROUP BY m.id
        {$havingSql}
        ORDER BY m.name COLLATE NOCASE
    ";

    if ($export !== '') {
        $stmt = $pdo->prepare($selectSql);
        $stmt->execute($prodParams);
        $stockRows = $stmt->fetchAll();
    } else {
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare($selectSql . " LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($prodParams);
        $stockRows = $stmt->fetchAll();
    }
}

// ── Export (full filtered set, not paginated) ──────────────────────────────
if ($export !== '') {
    $reportTitle = inventoryReportTitle([
        'type' => $typeFilter,
        'cat_name' => $catName,
        'filter' => $filter,
        'within_label' => $filter === 'expiring' ? ($expiryWithin['label'] ?? '') : '',
        'search' => $search,
    ]);

    if ($viewBatches) {
        $headers = ['Product', 'Generic', 'Brand', 'Category', 'Batch #', 'Qty', 'Unit', 'Buy', 'Sell', 'Mfg', 'Expiry', 'Supplier', 'Status'];
        $rows = [];
        $totalQty = 0;
        foreach ($batches as $b) {
            $noExpiry = isNoExpiryDate($b['expiry_date'] ?? '');
            $daysLeft = expiryDaysRemaining($b['expiry_date'] ?? '');
            if ((int)$b['quantity'] === 0) {
                $statusLabel = 'Out';
            } elseif ($noExpiry) {
                $statusLabel = 'No expiry';
            } else {
                $days = (int)$daysLeft;
                if ($days < 0) $statusLabel = 'Expired';
                elseif ($days <= 7) $statusLabel = 'Critical';
                elseif ($days <= 30) $statusLabel = 'Expiring';
                else $statusLabel = 'Good';
            }
            $totalQty += (int)$b['quantity'];
            $rows[] = [
                $b['med_name'],
                $b['generic_name'] ?: '',
                $b['brand_name'] ?: '',
                $b['cat_name'] ?? '',
                $b['batch_number'],
                (int)$b['quantity'],
                $b['unit'],
                (float)$b['purchase_price'],
                (float)$b['selling_price'],
                $b['manufacture_date'] ?? '',
                formatExpiryDate($b['expiry_date'] ?? ''),
                $b['supplier_name'] ?? '',
                $statusLabel,
            ];
        }
        $totalsNote = 'Batches: ' . count($rows) . ' · Total qty: ' . number_format($totalQty);
    } else {
        $headers = ['Product', 'Generic', 'Brand', 'Category', 'Batches', 'Total Qty', 'Unit', 'Next expiry', 'Status'];
        $rows = [];
        $totalQty = 0;
        foreach ($stockRows as $r) {
            $noExpiry = isNoExpiryDate($r['next_expiry'] ?? '');
            $daysLeft = expiryDaysRemaining($r['next_expiry'] ?? '');
            if ((int)$r['stock'] === 0) {
                $statusLabel = 'Out';
            } elseif ($noExpiry) {
                $statusLabel = ((int)$r['stock'] <= (int)$r['reorder_level']) ? 'Low' : 'Good';
            } else {
                $days = (int)$daysLeft;
                if ($days < 0) $statusLabel = 'Expired';
                elseif ($days <= 7) $statusLabel = 'Critical';
                elseif ($days <= 30) $statusLabel = 'Expiring';
                elseif ((int)$r['stock'] <= (int)$r['reorder_level']) $statusLabel = 'Low';
                else $statusLabel = 'Good';
            }
            $totalQty += (int)$r['stock'];
            $rows[] = [
                $r['med_name'],
                $r['generic_name'] ?: '',
                $r['brand_name'] ?: '',
                $r['cat_name'] ?? '',
                (int)$r['batch_count'],
                (int)$r['stock'],
                $r['unit'],
                formatExpiryDate($r['next_expiry'] ?? ''),
                $statusLabel,
            ];
        }
        $totalsNote = 'Products: ' . count($rows) . ' · Total qty: ' . number_format($totalQty);
    }

    $slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower($reportTitle));
    $slug = trim($slug, '_') ?: 'inventory';

    if ($export === 'csv') {
        reportExportCsv($slug . '.csv', $headers, $rows);
    }
    if ($export === 'excel') {
        reportExportExcel($slug, $headers, $rows);
    }

    if ($export === 'pdf' || $export === 'print') {
        $filterBits = array_filter([
            $typeFilter ? ('Type: ' . ($typeFilter)) : null,
            $catName !== '' ? ('Category: ' . $catName) : null,
            $filter !== 'all' ? ('Filter: ' . $filter) : null,
            $filter === 'expiring' ? ('Within: ' . ($expiryWithin['label'] ?? '')) : null,
            $search !== '' ? ('Search: ' . $search) : null,
            $viewBatches ? ('Product: ' . $focusMedName) : null,
        ]);
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($reportTitle) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; padding: 24px; }
  h1 { font-size: 18px; margin-bottom: 4px; text-transform: uppercase; }
  .meta { color: #444; margin-bottom: 12px; line-height: 1.5; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  th, td { border: 1px solid #333; padding: 5px 7px; text-align: left; font-size: 11px; }
  th { background: #eee; text-transform: uppercase; font-size: 10px; }
  .totals { margin-top: 10px; font-weight: bold; }
  .note { margin-top: 16px; font-size: 11px; color: #555; }
  @media print { body { padding: 10px; } .no-print { display: none; } }
</style>
</head>
<body>
  <h1><?= htmlspecialchars($reportTitle) ?></h1>
  <div class="meta">
    <div><strong><?= htmlspecialchars($pharmacyName) ?></strong></div>
    <div>Report date: <?= date('Y-m-d H:i') ?></div>
    <?php if ($filterBits): ?><div><?= htmlspecialchars(implode(' · ', $filterBits)) ?></div><?php endif; ?>
  </div>
  <?php if ($export === 'pdf'): ?>
  <p class="no-print note">Use your browser’s <strong>Print → Save as PDF</strong> to download this report.</p>
  <?php endif; ?>
  <table>
    <thead><tr><?php foreach ($headers as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="<?= count($headers) ?>" style="text-align:center;">No rows</td></tr>
    <?php else: ?>
      <?php foreach ($rows as $row): ?>
      <tr><?php foreach ($row as $cell): ?><td><?= htmlspecialchars((string)$cell) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="totals"><?= htmlspecialchars($totalsNote) ?></div>
  <script>window.onload = function () { window.print(); };</script>
</body>
</html>
        <?php
        exit;
    }
}

// ── Counts (similar to current page — global active batches) ───────────────
$countScope = "COALESCE(status,'active')='active'";
$counts = [
    'all' => (int)$pdo->query("SELECT COUNT(DISTINCT medicine_id) FROM batches WHERE {$countScope}")->fetchColumn(),
    'low' => (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT m.id FROM medicines m
            JOIN batches b ON b.medicine_id = m.id AND COALESCE(b.status,'active')='active'
            GROUP BY m.id
            HAVING COALESCE(SUM(b.quantity), 0) > 0
               AND COALESCE(SUM(b.quantity), 0) <= m.reorder_level
        )
    ")->fetchColumn(),
    'expired' => (int)$pdo->query("
        SELECT COUNT(DISTINCT medicine_id) FROM batches
        WHERE {$countScope} AND expiry_date < date('now') AND expiry_date < '9000-01-01' AND quantity > 0
    ")->fetchColumn(),
    'expiring' => (int)$pdo->query(
        "SELECT COUNT(DISTINCT medicine_id) FROM batches WHERE COALESCE(status,'active')='active' AND "
        . expiryWithinSql('', $expiryDays, $expiryFrom, $expiryTo)
    )->fetchColumn(),
    'out' => (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT m.id FROM medicines m
            JOIN batches b ON b.medicine_id = m.id AND COALESCE(b.status,'active')='active'
            GROUP BY m.id
            HAVING COALESCE(SUM(b.quantity), 0) = 0
        )
    ")->fetchColumn(),
];

$expiryWindowCounts = [];
foreach (expiryWithinPresets() as $wKey => $wMeta) {
    if ($wKey === 'custom') {
        $expiryWindowCounts[$wKey] = 0;
        continue;
    }
    $expiryWindowCounts[$wKey] = (int)$pdo->query(
        "SELECT COUNT(DISTINCT medicine_id) FROM batches WHERE COALESCE(status,'active')='active' AND "
        . expiryWithinSql('', (int)$wMeta['days'])
    )->fetchColumn();
}

$typeCounts = ['all' => 0, 'medicine' => 0, 'cosmetic' => 0, 'equipment' => 0];
foreach ($pdo->query("
    SELECT COALESCE(m.product_type,'medicine') AS t, COUNT(DISTINCT m.id) AS c
    FROM medicines m
    JOIN batches b ON b.medicine_id = m.id AND COALESCE(b.status,'active')='active'
    GROUP BY t
") as $row) {
    if (isset($typeCounts[$row['t']])) {
        $typeCounts[$row['t']] = (int)$row['c'];
    }
    $typeCounts['all'] += (int)$row['c'];
}

$invExtra = array_filter([
    'filter' => $filter !== 'all' ? $filter : null,
    'q' => $search ?: null,
    'within' => ($filter === 'expiring' && $expiryKey !== '30') ? $expiryKey : null,
    'from_date' => ($filter === 'expiring' && $expiryKey === 'custom') ? $expiryFrom : null,
    'to_date' => ($filter === 'expiring' && $expiryKey === 'custom') ? $expiryTo : null,
    'per_page' => $perPage !== 25 ? $perPage : null,
]);

$pagerBase = 'inventory.php?' . invQs($qsBase);
$flash = flashGet();
$msg = ($flash && $flash['type'] === 'success') ? $flash['message'] : '';
$error = ($flash && $flash['type'] === 'error') ? $flash['message'] : '';

$adjReasons = stockAdjustmentReasons();

renderHead('Inventory');
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar('Inventory', 'Filter by Medicine, Cosmetics, or Equipment — then a detail like Skincare'); ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php renderTypeTabs('inventory.php', $typeFilter, $typeCounts, $invExtra, true); ?>
<?php if ($typeFilter): ?>
<?php renderCategoryChips('inventory.php', $typeFilter, $catFilter, $typeCategories, $invExtra); ?>
<?php endif; ?>

<!-- Filter tabs -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:<?= $filter === 'expiring' ? '12' : '20' ?>px;">
  <?php
  $tabs = [
    ['key'=>'all',      'label'=>'All Stock',       'cnt'=>$counts['all'],      'class'=>'badge-blue'],
    ['key'=>'low',      'label'=>'Low Stock',       'cnt'=>$counts['low'],      'class'=>'badge-orange'],
    ['key'=>'expiring', 'label'=>'Expiring Soon',   'cnt'=>$counts['expiring'], 'class'=>'badge-orange'],
    ['key'=>'expired',  'label'=>'Expired',         'cnt'=>$counts['expired'],  'class'=>'badge-red'],
    ['key'=>'out',      'label'=>'Out of Stock',    'cnt'=>$counts['out'],      'class'=>'badge-gray'],
  ];
  foreach ($tabs as $tab):
    $active = $filter === $tab['key'];
    $tabQs = invQs([
        'filter' => $tab['key'],
        'type' => $typeFilter,
        'cat' => $catFilter ?: null,
        'q' => $search,
        'within' => $tab['key'] === 'expiring' ? ($filter === 'expiring' ? $expiryKey : '30') : null,
        'from_date' => ($tab['key'] === 'expiring' && $filter === 'expiring' && $expiryKey === 'custom') ? $expiryFrom : null,
        'to_date' => ($tab['key'] === 'expiring' && $filter === 'expiring' && $expiryKey === 'custom') ? $expiryTo : null,
        'per_page' => $perPage !== 25 ? $perPage : null,
        'med' => $medId ?: null,
    ]);
  ?>
  <a href="inventory.php<?= $tabQs ? '?' . htmlspecialchars($tabQs) : '' ?>"
     class="btn <?= $active ? 'btn-primary' : 'btn-ghost' ?>" style="gap:8px;">
    <?= $tab['label'] ?>
    <span class="badge <?= $tab['class'] ?>"><?= $tab['cnt'] ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($filter === 'expiring'): ?>
<div class="card mb-20" style="padding:12px 16px;">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <span style="font-size:13px;font-weight:600;color:var(--text-200);">Expires within:</span>
    <?php foreach (expiryWithinPresets() as $wKey => $wMeta):
      $wActive = $expiryKey === $wKey;
      $wQs = invQs([
          'filter' => 'expiring',
          'within' => $wKey,
          'type' => $typeFilter,
          'cat' => $catFilter ?: null,
          'q' => $search,
          'med' => $medId ?: null,
          'per_page' => $perPage !== 25 ? $perPage : null,
          'from_date' => $wKey === 'custom' ? $expiryFrom : null,
          'to_date' => $wKey === 'custom' ? $expiryTo : null,
      ]);
    ?>
    <a href="inventory.php?<?= htmlspecialchars($wQs) ?>"
       class="btn btn-sm <?= $wActive ? 'btn-primary' : 'btn-ghost' ?>" style="gap:6px;">
      <?= htmlspecialchars($wMeta['label']) ?>
      <?php if ($wKey !== 'custom'): ?>
      <span class="badge <?= $wActive ? 'badge-blue' : 'badge-gray' ?>"><?= (int)($expiryWindowCounts[$wKey] ?? 0) ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <span style="font-size:12px;color:var(--text-300);margin-left:4px;">
      Showing products expiring <?= $expiryKey === 'custom'
        ? htmlspecialchars($expiryWithin['label'])
        : ($expiryDays === 0 ? 'today' : ('within ' . (int)$expiryDays . ' days') . ' (' . htmlspecialchars($expiryWithin['label']) . ')') ?>
    </span>
  </div>
  <?php if ($expiryKey === 'custom'): ?>
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;">
    <input type="hidden" name="filter" value="expiring">
    <input type="hidden" name="within" value="custom">
    <?php if ($typeFilter): ?><input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>"><?php endif; ?>
    <?php if ($catFilter): ?><input type="hidden" name="cat" value="<?= (int)$catFilter ?>"><?php endif; ?>
    <?php if ($search): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
    <?php if ($medId): ?><input type="hidden" name="med" value="<?= (int)$medId ?>"><?php endif; ?>
    <?php if ($perPage !== 25): ?><input type="hidden" name="per_page" value="<?= (int)$perPage ?>"><?php endif; ?>
    <div class="form-group" style="margin:0;">
      <label>From</label>
      <input type="date" name="from_date" value="<?= htmlspecialchars($expiryFrom) ?>" required>
    </div>
    <div class="form-group" style="margin:0;">
      <label>To</label>
      <input type="date" name="to_date" value="<?= htmlspecialchars($expiryTo) ?>" required>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <span class="card-title">
      <?php if ($viewBatches): ?>
        <?= htmlspecialchars($focusMedName) ?> — batches (<?= number_format($totalRows) ?>)
      <?php elseif ($filter === 'expiring'): ?>
        Expiring Soon — <?= htmlspecialchars($expiryWithin['label']) ?> (<?= number_format($totalRows) ?>)
      <?php else: ?>
        <?= htmlspecialchars($typeFilter ? (productTypeMeta()[$typeFilter]['plural'] ?? 'Stock') : 'Stock') ?> (<?= number_format($totalRows) ?>)
      <?php endif; ?>
    </span>
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
      <?php if ($filter === 'expiring'): ?>
        <input type="hidden" name="within" value="<?= htmlspecialchars($expiryKey) ?>">
        <?php if ($expiryKey === 'custom'): ?>
          <input type="hidden" name="from_date" value="<?= htmlspecialchars($expiryFrom) ?>">
          <input type="hidden" name="to_date" value="<?= htmlspecialchars($expiryTo) ?>">
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($typeFilter): ?><input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>"><?php endif; ?>
      <?php if ($catFilter): ?><input type="hidden" name="cat" value="<?= (int)$catFilter ?>"><?php endif; ?>
      <?php if ($viewBatches): ?>
      <input type="hidden" name="med" value="<?= $medId ?>">
      <a href="inventory.php?<?= htmlspecialchars(invQs(array_merge($qsBase, ['med' => null]))) ?>" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> All products</a>
      <a href="bin_card.php?id=<?= $medId ?>" class="btn btn-ghost btn-sm"><i data-lucide="file-text"></i> Bin Card</a>
      <a href="stock_history.php?id=<?= $medId ?>" class="btn btn-ghost btn-sm"><i data-lucide="history"></i> Stock History</a>
      <?php endif; ?>
      <div class="search-bar"><i data-lucide="search"></i><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search product, brand, batch..."></div>
      <label style="font-size:12px;color:var(--text-300);display:flex;align-items:center;gap:6px;">
        Per page
        <select name="per_page" onchange="this.form.submit()" style="min-width:70px;">
          <?php foreach (inventoryPerPageOptions() as $opt): ?>
          <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
      <?php if ($canExport): ?>
      <details style="position:relative;">
        <summary class="btn btn-ghost btn-sm" style="list-style:none;cursor:pointer;"><i data-lucide="download"></i> Export</summary>
        <div style="position:absolute;right:0;top:100%;margin-top:4px;background:var(--bg-700);border:1px solid var(--border);border-radius:8px;padding:6px;min-width:140px;z-index:20;display:flex;flex-direction:column;gap:2px;">
          <?php
          $exportBase = invQs($qsBase);
          foreach (['csv' => 'CSV', 'excel' => 'Excel', 'pdf' => 'PDF', 'print' => 'Print'] as $ek => $elabel):
            $eq = $exportBase . ($exportBase !== '' ? '&' : '') . 'export=' . $ek;
          ?>
          <a class="btn btn-ghost btn-sm" style="justify-content:flex-start;" href="inventory.php?<?= htmlspecialchars($eq) ?>" <?= in_array($ek, ['pdf', 'print'], true) ? 'target="_blank"' : '' ?>><?= $elabel ?></a>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
    </form>
  </div>

  <div class="table-wrap">
    <?php if ($viewBatches): ?>
    <table>
      <thead>
        <tr>
          <th>Product</th><th>Generic</th><th>Brand</th><th>Category</th><th>Batch #</th>
          <th>Qty</th><th>Unit</th><th>Buy</th><th>Sell</th>
          <th>Mfg</th><th>Expiry</th><th>Supplier</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($batches)): ?>
        <tr><td colspan="14" style="text-align:center;padding:40px;color:var(--text-300);">No batches match your filter</td></tr>
      <?php else: ?>
      <?php foreach ($batches as $b):
        $noExpiry = isNoExpiryDate($b['expiry_date'] ?? '');
        $daysLabel = expiryDaysLabel($b['expiry_date'] ?? '');
        $daysLeft = expiryDaysRemaining($b['expiry_date'] ?? '');
        $daysColor = $daysLeft === null ? '' : ($daysLeft < 0 ? 'var(--danger)' : ($daysLeft <= 30 ? 'var(--warning)' : 'var(--text-300)'));
        if ($noExpiry) {
            $statusClass = 'badge-green';
            $statusLabel = 'No expiry';
        } else {
            $days = (int)$daysLeft;
            if ($days < 0)        { $statusClass = 'badge-red';    $statusLabel = 'Expired'; }
            elseif ($days <= 7)   { $statusClass = 'badge-red';    $statusLabel = 'Critical'; }
            elseif ($days <= 30)  { $statusClass = 'badge-orange'; $statusLabel = 'Expiring'; }
            else                  { $statusClass = 'badge-green';  $statusLabel = 'Good'; }
        }
        if ((int)$b['quantity'] === 0) { $statusClass = 'badge-gray'; $statusLabel = 'Out'; }
        $qtyClass = (int)$b['quantity'] === 0 ? 'badge-gray' : ((int)$b['quantity'] <= (int)$b['reorder_level'] ? 'badge-orange' : 'badge-green');
        $showExpiredActs = !$noExpiry && $daysLeft !== null && (int)$daysLeft <= 30 && (int)$b['quantity'] > 0;
        $batchDetail = trim(implode(' · ', array_filter([
            $b['variant'] ?? '', $b['model_number'] ?? '', $b['serial_number'] ?? '',
        ], fn($v) => $v !== '' && $v !== null)));
      ?>
      <tr>
        <td style="font-weight:600;color:var(--text-100);"><?= htmlspecialchars($b['med_name']) ?></td>
        <td style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($b['generic_name'] ?: '—') ?></td>
        <td style="font-size:12px;"><?= htmlspecialchars($b['brand_name'] ?: '—') ?></td>
        <td><span class="badge badge-gray" style="font-size:11px;"><?= htmlspecialchars($b['cat_name'] ?? '—') ?></span></td>
        <td>
          <code style="background:var(--bg-600);padding:2px 7px;border-radius:4px;font-size:12px;"><?= htmlspecialchars($b['batch_number']) ?></code>
          <?= $batchDetail !== '' ? '<div style="font-size:11px;color:var(--text-300);margin-top:3px;">' . htmlspecialchars($batchDetail) . '</div>' : '' ?>
        </td>
        <td><span class="badge <?= $qtyClass ?>"><?= number_format((int)$b['quantity']) ?></span></td>
        <td><?= htmlspecialchars($b['unit']) ?></td>
        <td style="color:var(--text-300);"><?= currency($b['purchase_price']) ?></td>
        <td style="color:var(--accent2);font-weight:700;"><?= currency($b['selling_price']) ?></td>
        <td style="font-size:12px;"><?= !empty($b['manufacture_date']) ? htmlspecialchars($b['manufacture_date']) : '—' ?></td>
        <td style="font-size:12px;">
          <?= formatExpiryDate($b['expiry_date']) ?>
          <?php if ($daysLabel): ?><div style="margin-top:2px;font-size:11px;font-weight:600;color:<?= $daysColor ?>;"><?= htmlspecialchars($daysLabel) ?></div><?php endif; ?>
        </td>
        <td style="font-size:12px;"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></td>
        <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
        <td>
          <div class="row-actions">
            <?php if ($canEdit): ?>
            <button type="button" class="btn btn-ghost btn-sm"
              onclick='openEditModal(<?= htmlspecialchars(json_encode([
                'id'               => (int)$b['id'],
                'medicine_id'      => (int)$b['medicine_id'],
                'med_name'         => $b['med_name'],
                'generic_name'     => $b['generic_name'] ?? '',
                'brand_name'       => $b['brand_name'] ?? '',
                'category_id'      => (int)($b['category_id'] ?? 0),
                'unit'             => $b['unit'] ?? '',
                'reorder_level'    => (int)($b['reorder_level'] ?? 0),
                'batch_number'     => $b['batch_number'],
                'quantity'         => (int)$b['quantity'],
                'purchase_price'   => (float)$b['purchase_price'],
                'selling_price'    => (float)$b['selling_price'],
                'expiry_date'      => isNoExpiryDate($b['expiry_date'] ?? '') ? '' : ($b['expiry_date'] ?? ''),
                'manufacture_date' => $b['manufacture_date'] ?? '',
                'supplier_id'      => (int)($b['supplier_id'] ?? 0),
                'notes'            => $b['notes'] ?? '',
                'med_notes'        => $b['med_notes'] ?? '',
                'variant'          => $b['variant'] ?? '',
                'model_number'     => $b['model_number'] ?? '',
                'serial_number'    => $b['serial_number'] ?? '',
                'warranty_period'  => $b['warranty_period'] ?? '',
                'warranty_expiry'  => $b['warranty_expiry'] ?? '',
                'product_type'     => $b['product_type'] ?? 'medicine',
                'requires_expiry'  => productRequiresExpiry($b['product_type'] ?? 'medicine') ? 1 : 0,
              ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>Edit</button>
            <?php endif; ?>
            <?php if ($canAdjust): ?>
            <button type="button" class="btn btn-ghost btn-sm"
              onclick='openAdjustModal(<?= (int)$b['id'] ?>, <?= (int)$b['quantity'] ?>, <?= htmlspecialchars(json_encode($b['batch_number'] . ' — ' . $b['med_name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>Adjust</button>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <form method="POST" action="inventory.php" onsubmit="return confirmBatchDelete(this)" style="display:inline;">
              <input type="hidden" name="act" value="delete_batch">
              <input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
              <input type="hidden" name="return_qs" value="<?= htmlspecialchars($returnQs) ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
            <?php endif; ?>
            <?php if ($canAdjust && $showExpiredActs): ?>
            <button type="button" class="btn btn-ghost btn-sm"
              onclick='openExpiredModal(<?= (int)$b['id'] ?>, <?= (int)$b['quantity'] ?>, "return_supplier", <?= htmlspecialchars(json_encode($b['batch_number']), ENT_QUOTES) ?>)'>Return</button>
            <button type="button" class="btn btn-ghost btn-sm"
              onclick='openExpiredModal(<?= (int)$b['id'] ?>, <?= (int)$b['quantity'] ?>, "damaged", <?= htmlspecialchars(json_encode($b['batch_number']), ENT_QUOTES) ?>)'>Damaged</button>
            <button type="button" class="btn btn-ghost btn-sm"
              onclick='openExpiredModal(<?= (int)$b['id'] ?>, <?= (int)$b['quantity'] ?>, "disposed", <?= htmlspecialchars(json_encode($b['batch_number']), ENT_QUOTES) ?>)'>Disposed</button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Product name</th><th>Generic</th><th>Brand</th><th>Category</th>
          <th>Batches</th><th>Total Qty</th><th>Unit</th><th>Next expiry</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($stockRows)): ?>
        <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-300);">No products match your filter</td></tr>
      <?php else: ?>
      <?php foreach ($stockRows as $r):
        $noExpiry = isNoExpiryDate($r['next_expiry'] ?? '');
        $daysLabel = expiryDaysLabel($r['next_expiry'] ?? '');
        $daysLeft = expiryDaysRemaining($r['next_expiry'] ?? '');
        $daysColor = $daysLeft === null ? '' : ($daysLeft < 0 ? 'var(--danger)' : ($daysLeft <= 30 ? 'var(--warning)' : 'var(--text-300)'));
        if ((int)$r['stock'] === 0) {
            $statusClass = 'badge-gray';
            $statusLabel = 'Out';
        } elseif ($noExpiry) {
            $statusClass = ((int)$r['stock'] <= (int)$r['reorder_level']) ? 'badge-orange' : 'badge-green';
            $statusLabel = ((int)$r['stock'] <= (int)$r['reorder_level']) ? 'Low' : 'Good';
        } else {
            $days = (int)$daysLeft;
            if ($days < 0)        { $statusClass = 'badge-red';    $statusLabel = 'Expired'; }
            elseif ($days <= 7)   { $statusClass = 'badge-red';    $statusLabel = 'Critical'; }
            elseif ($days <= 30)  { $statusClass = 'badge-orange'; $statusLabel = 'Expiring'; }
            elseif ((int)$r['stock'] <= (int)$r['reorder_level']) { $statusClass = 'badge-orange'; $statusLabel = 'Low'; }
            else                  { $statusClass = 'badge-green';  $statusLabel = 'Good'; }
        }
        $qtyClass = (int)$r['stock'] === 0 ? 'badge-gray' : ((int)$r['stock'] <= (int)$r['reorder_level'] ? 'badge-orange' : 'badge-green');
        $batchLink = invQs(array_merge($qsBase, ['med' => (int)$r['medicine_id'], 'page' => null]));
      ?>
      <tr>
        <td style="font-weight:600;color:var(--text-100);"><?= htmlspecialchars($r['med_name']) ?></td>
        <td style="font-size:12px;color:var(--text-300);"><?= htmlspecialchars($r['generic_name'] ?: '—') ?></td>
        <td style="font-size:12px;"><?= htmlspecialchars($r['brand_name'] ?: '—') ?></td>
        <td><span class="badge badge-gray" style="font-size:11px;"><?= htmlspecialchars($r['cat_name'] ?? '—') ?></span></td>
        <td><span class="badge badge-blue"><?= (int)$r['batch_count'] ?></span></td>
        <td><span class="badge <?= $qtyClass ?>"><?= number_format((int)$r['stock']) ?></span></td>
        <td><?= htmlspecialchars($r['unit']) ?></td>
        <td style="font-size:12px;">
          <?= formatExpiryDate($r['next_expiry'] ?? '') ?>
          <?php if ($daysLabel): ?><div style="margin-top:2px;font-size:11px;font-weight:600;color:<?= $daysColor ?>;"><?= htmlspecialchars($daysLabel) ?></div><?php endif; ?>
        </td>
        <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
        <td>
          <div class="row-actions">
            <a href="inventory.php?<?= htmlspecialchars($batchLink) ?>" class="btn btn-ghost btn-sm">Batches</a>
            <a href="stock_history.php?id=<?= (int)$r['medicine_id'] ?>" class="btn btn-ghost btn-sm">Stock History</a>
            <a href="bin_card.php?id=<?= (int)$r['medicine_id'] ?>" class="btn btn-ghost btn-sm">Bin Card</a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php renderInventoryPagination($page, $totalPages, $pagerBase); ?>
</div>

<?php if ($canEdit): ?>
<!-- Edit Batch Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h2>Edit Batch</h2>
      <button type="button" class="modal-close" onclick="closeModal('editModal')"><i data-lucide="x"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST" action="inventory.php">
        <input type="hidden" name="act" value="update_batch">
        <input type="hidden" name="batch_id" id="editBatchId">
        <input type="hidden" name="medicine_id" id="editMedicineId">
        <input type="hidden" name="return_qs" value="<?= htmlspecialchars($returnQs) ?>">

        <div class="form-group">
          <label>Product name</label>
          <input type="text" name="med_name" id="editMedName" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Generic name</label>
            <input type="text" name="generic_name" id="editGenericName">
          </div>
          <div class="form-group">
            <label>Brand name</label>
            <input type="text" name="brand_name" id="editBrandName">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Category</label>
            <select name="category_id" id="editCategoryId">
              <option value="">— None —</option>
              <?php foreach ($allCategories as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['product_type']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Unit</label>
            <input type="text" name="unit" id="editUnit" required>
          </div>
          <div class="form-group">
            <label>Reorder level</label>
            <input type="number" name="reorder_level" id="editReorderLevel" min="0" step="1">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Batch Number</label>
            <input type="text" name="batch_number" id="editBatchNumber" required>
          </div>
          <div class="form-group">
            <label>Quantity</label>
            <input type="number" name="quantity" id="editQuantity" min="0" step="1" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Buy Price (<?= htmlspecialchars($currency) ?>)</label>
            <input type="number" name="purchase_price" id="editPurchasePrice" step="0.01" min="0" <?= $canChangeBuy ? 'required' : 'readonly' ?>>
          </div>
          <div class="form-group">
            <label>Sell Price (<?= htmlspecialchars($currency) ?>)</label>
            <input type="number" name="selling_price" id="editSellingPrice" step="0.01" min="0" <?= $canChangeSell ? 'required' : 'readonly' ?>>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Manufacture Date</label>
            <input type="date" name="manufacture_date" id="editManufactureDate">
          </div>
          <div class="form-group">
            <label>Expiry Date <span id="editExpiryHint" style="color:var(--text-300);font-weight:400;"></span></label>
            <input type="date" name="expiry_date" id="editExpiryDate">
          </div>
        </div>
        <div class="form-group">
          <label>Supplier</label>
          <select name="supplier_id" id="editSupplierId">
            <option value="">— None —</option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Variant</label>
            <input type="text" name="variant" id="editVariant">
          </div>
          <div class="form-group">
            <label>Model Number</label>
            <input type="text" name="model_number" id="editModelNumber">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Serial Number</label>
            <input type="text" name="serial_number" id="editSerialNumber">
          </div>
          <div class="form-group">
            <label>Warranty Period</label>
            <input type="text" name="warranty_period" id="editWarrantyPeriod">
          </div>
          <div class="form-group">
            <label>Warranty Expiry</label>
            <input type="date" name="warranty_expiry" id="editWarrantyExpiry">
          </div>
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" id="editNotes" rows="2"></textarea>
          <input type="hidden" name="med_notes" id="editMedNotes">
        </div>
        <div class="form-actions" style="padding-top:12px;margin-top:12px;">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canAdjust): ?>
<!-- Stock Adjustment Modal -->
<div class="modal-overlay" id="adjustModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <h2>Stock Adjustment</h2>
      <button type="button" class="modal-close" onclick="closeModal('adjustModal')"><i data-lucide="x"></i></button>
    </div>
    <div class="modal-body">
      <p id="adjustLabel" style="font-size:13px;color:var(--text-300);margin-bottom:12px;"></p>
      <form method="POST" action="inventory.php">
        <input type="hidden" name="act" value="adjust_stock">
        <input type="hidden" name="batch_id" id="adjustBatchId">
        <input type="hidden" name="return_qs" value="<?= htmlspecialchars($returnQs) ?>">
        <div class="form-group">
          <label>Adjustment qty <span style="color:var(--text-300);font-weight:400;">(+ in / − out)</span></label>
          <input type="number" name="adjustment_qty" id="adjustQty" step="1" required>
          <div style="font-size:12px;color:var(--text-300);margin-top:4px;">Current: <span id="adjustCurrentQty">0</span></div>
        </div>
        <div class="form-group">
          <label>Reason</label>
          <select name="reason" id="adjustReason" required>
            <?php foreach ($adjReasons as $rk => $rl): ?>
            <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" rows="2"></textarea>
        </div>
        <div class="form-actions" style="padding-top:12px;margin-top:12px;">
          <button type="submit" class="btn btn-primary">Apply Adjustment</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('adjustModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Expired Action Modal -->
<div class="modal-overlay" id="expiredModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <h2 id="expiredModalTitle">Expired Stock Action</h2>
      <button type="button" class="modal-close" onclick="closeModal('expiredModal')"><i data-lucide="x"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST" action="inventory.php">
        <input type="hidden" name="act" value="expired_action">
        <input type="hidden" name="batch_id" id="expiredBatchId">
        <input type="hidden" name="expired_action" id="expiredAction">
        <input type="hidden" name="return_qs" value="<?= htmlspecialchars($returnQs) ?>">
        <div class="form-group">
          <label>Quantity to remove</label>
          <input type="number" name="quantity" id="expiredQty" min="1" step="1" required>
          <div style="font-size:12px;color:var(--text-300);margin-top:4px;">Available: <span id="expiredMaxQty">0</span></div>
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" rows="2"></textarea>
        </div>
        <div class="form-actions" style="padding-top:12px;margin-top:12px;">
          <button type="submit" class="btn btn-primary">Confirm</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('expiredModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

</div></div>

<script>
function confirmBatchDelete(form) {
  if (confirm('Are you sure you want to delete this batch?')) {
    return true;
  }
  return false;
}

function syncEditExpiryRequired(requires) {
  const input = document.getElementById('editExpiryDate');
  const hint = document.getElementById('editExpiryHint');
  if (!input) return;
  input.required = !!requires;
  if (hint) hint.textContent = requires ? '*' : '(optional)';
}

function openEditModal(batch) {
  document.getElementById('editBatchId').value = batch.id;
  document.getElementById('editMedicineId').value = batch.medicine_id;
  document.getElementById('editMedName').value = batch.med_name || '';
  document.getElementById('editGenericName').value = batch.generic_name || '';
  document.getElementById('editBrandName').value = batch.brand_name || '';
  document.getElementById('editCategoryId').value = batch.category_id || '';
  document.getElementById('editUnit').value = batch.unit || '';
  document.getElementById('editReorderLevel').value = batch.reorder_level || 0;
  document.getElementById('editBatchNumber').value = batch.batch_number || '';
  document.getElementById('editQuantity').value = batch.quantity;
  document.getElementById('editPurchasePrice').value = batch.purchase_price;
  document.getElementById('editSellingPrice').value = batch.selling_price;
  document.getElementById('editExpiryDate').value = batch.expiry_date || '';
  document.getElementById('editManufactureDate').value = batch.manufacture_date || '';
  document.getElementById('editSupplierId').value = batch.supplier_id || '';
  document.getElementById('editNotes').value = batch.notes || '';
  document.getElementById('editMedNotes').value = batch.med_notes || batch.notes || '';
  document.getElementById('editVariant').value = batch.variant || '';
  document.getElementById('editModelNumber').value = batch.model_number || '';
  document.getElementById('editSerialNumber').value = batch.serial_number || '';
  document.getElementById('editWarrantyPeriod').value = batch.warranty_period || '';
  document.getElementById('editWarrantyExpiry').value = batch.warranty_expiry || '';
  syncEditExpiryRequired(!!batch.requires_expiry);
  openModal('editModal');
}

function openAdjustModal(batchId, currentQty, label) {
  document.getElementById('adjustBatchId').value = batchId;
  document.getElementById('adjustCurrentQty').textContent = currentQty;
  document.getElementById('adjustQty').value = '';
  document.getElementById('adjustLabel').textContent = label || '';
  openModal('adjustModal');
}

function openExpiredModal(batchId, maxQty, action, batchNumber) {
  const titles = {
    return_supplier: 'Return to Supplier',
    damaged: 'Mark Damaged',
    disposed: 'Dispose Stock'
  };
  document.getElementById('expiredBatchId').value = batchId;
  document.getElementById('expiredAction').value = action;
  document.getElementById('expiredQty').value = maxQty;
  document.getElementById('expiredQty').max = maxQty;
  document.getElementById('expiredMaxQty').textContent = maxQty;
  document.getElementById('expiredModalTitle').textContent = (titles[action] || 'Expired Action') + (batchNumber ? ' — ' + batchNumber : '');
  openModal('expiredModal');
}
</script>

<?php renderFooter(); ?>
