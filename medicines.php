<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/sales_lib.php';

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$msg    = '';
$error  = '';

$CSV_HEADERS = ['Category', 'Dispensing Unit', 'Product Name', 'Generic / Active Ingredient'];

// ── CSV Export (template or full list) ───────────────────────
if ($action === 'export_template' || $action === 'export') {
    $isTemplate = ($action === 'export_template');
    $filename   = $isTemplate
        ? 'medicine_list_template.csv'
        : 'medicine_list_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens accents correctly
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $CSV_HEADERS);

    if ($isTemplate) {
        // Example rows showing the expected format
        fputcsv($out, ['Antibiotics & Antimicrobials', 'strip', 'Acetazolamide 250mg', 'Acetazolamide']);
        fputcsv($out, ['', 'pk', 'Amoklavin 1gm', 'Amoxicillin + Clavulanic Acid']);
        fputcsv($out, ['Cosmetics', 'tube', 'Nivea Cream 50ml', '']);
        fputcsv($out, ['Equipment', 'pcs', 'Digital Thermometer', '']);
    } else {
        $rows = $pdo->query("
            SELECT c.name AS category, m.unit, m.name, m.generic_name
            FROM medicines m
            LEFT JOIN categories c ON c.id = m.category_id
            ORDER BY c.name COLLATE NOCASE, m.name COLLATE NOCASE
        ")->fetchAll();
        $prevCat = null;
        foreach ($rows as $r) {
            $cat = $r['category'] ?? '';
            // Match sheet style: repeat category only when it changes
            $catOut = ($cat !== $prevCat) ? $cat : '';
            $prevCat = $cat;
            fputcsv($out, [$catOut, $r['unit'], $r['name'], $r['generic_name'] ?? '']);
        }
    }
    fclose($out);
    exit;
}

// ── Handle POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $generic     = trim($_POST['generic_name'] ?? '');
        $strength    = trim($_POST['strength'] ?? '');
        $dosage_form = trim($_POST['dosage_form'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
        $unit        = trim($_POST['unit'] ?? 'pcs');
        $description = trim($_POST['description'] ?? '');
        $reorder     = (int)($_POST['reorder_level'] ?? 10);
        $productType = trim($_POST['product_type'] ?? 'medicine');
        if (!isset(productTypes()[$productType])) {
            $catName = null;
            if ($category_id) {
                $cstmt = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
                $cstmt->execute([$category_id]);
                $catName = $cstmt->fetchColumn() ?: null;
            }
            $productType = productTypeFromCategory($catName);
        }

        if (!$name) { $error = 'Product name is required.'; }
        else {
            if ($id) {
                $pdo->prepare("UPDATE medicines SET name=?,generic_name=?,strength=?,dosage_form=?,category_id=?,unit=?,description=?,reorder_level=?,product_type=? WHERE id=?")
                    ->execute([$name,$generic,$strength,$dosage_form,$category_id,$unit,$description,$reorder,$productType,$id]);
                flashSet('success', 'Product updated successfully.');
            } else {
                $pdo->prepare("INSERT INTO medicines (name,generic_name,strength,dosage_form,category_id,unit,description,reorder_level,product_type) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$name,$generic,$strength,$dosage_form,$category_id,$unit,$description,$reorder,$productType]);
                flashSet('success', 'Product added successfully.');
            }
            header('Location: medicines.php?type=' . urlencode($productType));
            exit;
        }
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM medicines WHERE id=?")->execute([$id]);
            flashSet('success', 'Product deleted.');
        } catch (PDOException $e) {
            flashSet('error', 'Cannot delete product: It is linked to existing inventory batches, sales, or purchases.');
        }
        header('Location: medicines.php?type=' . urlencode(trim($_POST['product_type'] ?? 'medicine')));
        exit;
    }

    if ($act === 'import_csv') {
        $action = 'list';
        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $error = 'Please choose a CSV file to import.';
        } else {
            $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if (!$fh) {
                $error = 'Could not read the uploaded file.';
            } else {
                // Strip UTF-8 BOM if present
                $first = fgets($fh);
                if ($first === false) {
                    $error = 'CSV file is empty.';
                    fclose($fh);
                } else {
                    if (strncmp($first, "\xEF\xBB\xBF", 3) === 0) {
                        $first = substr($first, 3);
                    }
                    $header = str_getcsv($first);
                    $header = array_map(fn($h) => strtolower(trim($h)), $header);

                    $map = [
                        'category'                       => 'category',
                        'dispensing unit'                => 'unit',
                        'despansing unit'                => 'unit',
                        'product name'                   => 'name',
                        'generic / active ingredient'    => 'generic',
                        'generic'                        => 'generic',
                        'generic name'                   => 'generic',
                        'active ingredient'              => 'generic',
                    ];
                    $col = [];
                    foreach ($header as $i => $h) {
                        if (isset($map[$h])) $col[$map[$h]] = $i;
                    }

                    if (!isset($col['name']) || !isset($col['unit'])) {
                        $error = 'Invalid CSV template. Required columns: Category, Dispensing Unit, Product Name, Generic / Active Ingredient';
                        fclose($fh);
                    } else {
                        // Load existing categories
                        $catIds = [];
                        foreach ($pdo->query('SELECT id, name FROM categories') as $c) {
                            $catIds[strtolower($c['name'])] = (int)$c['id'];
                        }
                        $insCat = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
                        $insMed = $pdo->prepare(
                            'INSERT INTO medicines (name, generic_name, category_id, unit, reorder_level, product_type) VALUES (?,?,?,?,10,?)'
                        );
                        $findMed = $pdo->prepare(
                            'SELECT id FROM medicines WHERE LOWER(name)=LOWER(?) AND LOWER(COALESCE(unit,\'\'))=LOWER(?) LIMIT 1'
                        );
                        $updMed = $pdo->prepare('UPDATE medicines SET generic_name=?, category_id=?, product_type=? WHERE id=?');

                        $added = 0;
                        $skipped = 0;
                        $updated = 0;
                        $mode = $_POST['import_mode'] ?? 'add'; // add | replace_all
                        $currentCat = '';

                        $pdo->beginTransaction();
                        try {
                            if ($mode === 'replace_all') {
                                $linked = (int)$pdo->query('SELECT COUNT(*) FROM batches')->fetchColumn()
                                    + (int)$pdo->query('SELECT COUNT(*) FROM sale_items')->fetchColumn()
                                    + (int)$pdo->query('SELECT COUNT(*) FROM purchase_items')->fetchColumn();
                                if ($linked > 0) {
                                    throw new RuntimeException('Cannot replace all medicines while inventory, sales, or purchases are linked. Use “Add new only” instead.');
                                }
                                $pdo->exec('DELETE FROM medicines');
                            }

                            while (($row = fgetcsv($fh)) !== false) {
                                if ($row === [null] || $row === false) continue;
                                if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;

                                $cat     = isset($col['category']) ? trim((string)($row[$col['category']] ?? '')) : '';
                                $unit    = trim((string)($row[$col['unit']] ?? ''));
                                $name    = trim(preg_replace('/\s+/', ' ', (string)($row[$col['name']] ?? '')));
                                $generic = isset($col['generic']) ? trim(preg_replace('/\s+/', ' ', (string)($row[$col['generic']] ?? ''))) : '';

                                if ($cat !== '') $currentCat = $cat;
                                if ($name === '' || $unit === '') { $skipped++; continue; }

                                $categoryId = null;
                                if ($currentCat !== '') {
                                    $key = strtolower($currentCat);
                                    if (!isset($catIds[$key])) {
                                        $insCat->execute([$currentCat]);
                                        $catIds[$key] = (int)$pdo->lastInsertId();
                                    }
                                    $categoryId = $catIds[$key];
                                }

                                $productType = productTypeFromCategory($currentCat);
                                $findMed->execute([$name, $unit]);
                                $existingId = $findMed->fetchColumn();
                                if ($existingId && $mode !== 'replace_all') {
                                    $updMed->execute([$generic !== '' ? $generic : null, $categoryId, $productType, $existingId]);
                                    $updated++;
                                } else {
                                    $insMed->execute([$name, $generic !== '' ? $generic : null, $categoryId, strtolower($unit), $productType]);
                                    $added++;
                                }
                            }

                            $pdo->commit();
                            $parts = [];
                            if ($added)   $parts[] = "$added added";
                            if ($updated) $parts[] = "$updated updated";
                            if ($skipped) $parts[] = "$skipped skipped";
                            flashSet('success', 'Import complete: ' . (implode(', ', $parts) ?: 'no rows imported') . '.');
                            header('Location: medicines.php');
                            exit;
                        } catch (Throwable $e) {
                            $pdo->rollBack();
                            $error = 'Import failed: ' . $e->getMessage();
                        }
                        fclose($fh);
                    }
                }
            }
        }
    }
}

// ── Data ────────────────────────────────────────────────────
$flash = flashGet();
if ($flash) {
    if ($flash['type'] === 'success') $msg = $flash['message'];
    else $error = $flash['message'];
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM medicines WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    $edit = $stmt->fetch();
}

// Search / list with pagination
$search     = trim($_GET['q'] ?? '');
$catFilter  = (int)($_GET['cat'] ?? 0);
$typeFilter = trim($_GET['type'] ?? 'medicine');
if (!isset(productTypes()[$typeFilter])) {
    $typeFilter = 'medicine';
}
if ($action === 'edit' && $edit) {
    $typeFilter = $edit['product_type'] ?? $typeFilter;
    if (!isset(productTypes()[$typeFilter])) {
        $typeFilter = 'medicine';
    }
}
$typeMeta = [
    'medicine'  => ['title' => 'Medicine', 'plural' => 'Medicines', 'add' => 'Add Medicine', 'icon' => 'pill'],
    'cosmetic'  => ['title' => 'Cosmetics', 'plural' => 'Cosmetics', 'add' => 'Add Cosmetic', 'icon' => 'sparkles'],
    'equipment' => ['title' => 'Equipment', 'plural' => 'Equipment', 'add' => 'Add Equipment', 'icon' => 'stethoscope'],
];
$pageMeta = $typeMeta[$typeFilter];
$typeCounts = ['medicine' => 0, 'cosmetic' => 0, 'equipment' => 0];
foreach ($pdo->query("SELECT COALESCE(product_type,'medicine') AS t, COUNT(*) AS c FROM medicines GROUP BY t") as $row) {
    if (isset($typeCounts[$row['t']])) {
        $typeCounts[$row['t']] = (int)$row['c'];
    }
}
$defaultCatId = 0;
foreach ($categories as $c) {
    if ($typeFilter === 'cosmetic' && strcasecmp($c['name'], 'Cosmetics') === 0) {
        $defaultCatId = (int) $c['id'];
    }
    if ($typeFilter === 'equipment' && strcasecmp($c['name'], 'Equipment') === 0) {
        $defaultCatId = (int) $c['id'];
    }
}
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 50;

$where  = [];
$params = [];
if ($search) { $where[] = "(m.name LIKE ? OR m.generic_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catFilter) { $where[] = "m.category_id = ?"; $params[] = $catFilter; }
if ($typeFilter) { $where[] = "COALESCE(m.product_type, 'medicine') = ?"; $params[] = $typeFilter; }
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM medicines m" . $whereSql);
$countStmt->execute($params);
$totalMeds = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalMeds / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT m.*, c.name as cat_name,
        (SELECT COALESCE(SUM(b.quantity),0) FROM batches b WHERE b.medicine_id = m.id) as stock
        FROM medicines m
        LEFT JOIN categories c ON c.id = m.category_id
        $whereSql
        ORDER BY c.name COLLATE NOCASE, m.name COLLATE NOCASE
        LIMIT $perPage OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$medicines = $stmt->fetchAll();

$pagerBase = 'medicines.php?' . http_build_query(array_filter(['q' => $search ?: null, 'cat' => $catFilter ?: null, 'type' => $typeFilter ?: null]));

renderHead($pageMeta['plural']);
renderSidebar();
?>
<div id="sidebarOverlay" class="overlay-bg" onclick="toggleSidebar()"></div>
<div class="main-content">
<?php renderTopbar($pageMeta['plural'], 'Three catalogue pages: medicines, cosmetics, and equipment'); ?>
<div class="page-body">

<?php if ($msg):  ?><div class="alert alert-success auto-hide"><i data-lucide="check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i data-lucide="x-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="admin-tabs catalogue-tabs">
  <?php foreach ($typeMeta as $key => $meta): ?>
  <a href="medicines.php?type=<?= $key ?>" class="admin-tab<?= $typeFilter === $key ? ' active' : '' ?>">
    <i data-lucide="<?= $meta['icon'] ?>"></i>
    <span><?= htmlspecialchars($meta['title']) ?></span>
    <span class="badge <?= $typeFilter === $key ? 'badge-blue' : 'badge-gray' ?>"><?= number_format($typeCounts[$key] ?? 0) ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($typeFilter === 'cosmetic'): ?>
<p style="font-size:13px;color:var(--text-300);margin:-8px 0 16px;">Cosmetics can include an expiry date when you purchase or stock them, but it is optional. Medicines always require an expiry date.</p>
<?php elseif ($typeFilter === 'equipment'): ?>
<p style="font-size:13px;color:var(--text-300);margin:-8px 0 16px;">Equipment expiry date is optional when purchasing or adding stock.</p>
<?php elseif ($typeFilter === 'medicine'): ?>
<p style="font-size:13px;color:var(--text-300);margin:-8px 0 16px;">Medicines require an expiry date on every purchase batch / stock entry.</p>
<?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<!-- ── FORM ─────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <span class="card-title"><?= $action === 'edit' ? 'Edit ' . $pageMeta['title'] : $pageMeta['add'] ?></span>
    <a href="medicines.php?type=<?= urlencode($typeFilter) ?>" class="btn btn-ghost btn-sm"><i data-lucide="arrow-left"></i> Back</a>
  </div>
  <form method="POST">
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <input type="hidden" name="product_type" value="<?= htmlspecialchars($typeFilter) ?>">
    <div class="form-row">
      <div class="form-group">
        <label>Category</label>
        <select name="category_id">
          <option value="">— Select Category —</option>
          <?php foreach ($categories as $c):
            $selectedId = (int)($edit['category_id'] ?? $defaultCatId);
          ?>
          <option value="<?= $c['id'] ?>" <?= $selectedId === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Dispensing Unit</label>
        <select name="unit">
          <?php foreach (productUnits() as $u): ?>
          <option value="<?= $u ?>" <?= (($edit['unit'] ?? 'strip') === $u) ? 'selected' : '' ?>><?= $u ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Product Name *</label>
        <input type="text" name="name" value="<?= htmlspecialchars($edit['name'] ?? '') ?>" required placeholder="<?= $typeFilter === 'medicine' ? 'e.g. Amoklavin 1gm' : ($typeFilter === 'cosmetic' ? 'e.g. Nivea Cream 50ml' : 'e.g. Digital Thermometer') ?>">
      </div>
      <div class="form-group">
        <label><?= $typeFilter === 'medicine' ? 'Generic / Active Ingredient' : 'Brand / Description' ?></label>
        <input type="text" name="generic_name" value="<?= htmlspecialchars($edit['generic_name'] ?? '') ?>" placeholder="<?= $typeFilter === 'medicine' ? 'e.g. Amoxicillin + Clavulanic Acid' : 'Optional' ?>">
      </div>
      <?php if ($typeFilter === 'medicine'): ?>
      <div class="form-group">
        <label>Strength</label>
        <input type="text" name="strength" value="<?= htmlspecialchars($edit['strength'] ?? '') ?>" placeholder="e.g. 500mg, 10mg/ml">
      </div>
      <div class="form-group">
        <label>Dosage Form</label>
        <input type="text" name="dosage_form" value="<?= htmlspecialchars($edit['dosage_form'] ?? '') ?>" placeholder="e.g. Tablet, Syrup, Injection">
      </div>
      <?php else: ?>
      <input type="hidden" name="strength" value="<?= htmlspecialchars($edit['strength'] ?? '') ?>">
      <input type="hidden" name="dosage_form" value="<?= htmlspecialchars($edit['dosage_form'] ?? '') ?>">
      <?php endif; ?>
      <div class="form-group">
        <label>Reorder Level</label>
        <input type="number" name="reorder_level" value="<?= $edit['reorder_level'] ?? 10 ?>" min="0">
      </div>
    </div>
    <div class="form-group">
      <label>Description</label>
      <textarea name="description" placeholder="Optional notes..."><?= htmlspecialchars($edit['description'] ?? '') ?></textarea>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> <?= $action === 'edit' ? 'Update' : $pageMeta['add'] ?></button>
      <a href="medicines.php?type=<?= urlencode($typeFilter) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>

<?php else: ?>
<!-- ── LIST ─────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header" style="flex-wrap:wrap;gap:10px;">
    <span class="card-title"><?= htmlspecialchars($pageMeta['plural']) ?> (<?= number_format($totalMeds) ?>)</span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <a href="medicines.php?action=export_template" class="btn btn-ghost btn-sm"><i data-lucide="file-down"></i> CSV Template</a>
      <a href="medicines.php?action=export" class="btn btn-ghost btn-sm"><i data-lucide="download"></i> Export CSV</a>
      <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('importPanel').style.display = document.getElementById('importPanel').style.display === 'none' ? 'block' : 'none'">
        <i data-lucide="upload"></i> Import CSV
      </button>
      <a href="medicines.php?action=add&type=<?= urlencode($typeFilter) ?>" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> <?= htmlspecialchars($pageMeta['add']) ?></a>
    </div>
  </div>

  <div id="importPanel" style="display:<?= ($error && ($_POST['act'] ?? '') === 'import_csv') ? 'block' : 'none' ?>;margin-bottom:16px;padding:16px;border:1px solid var(--border);border-radius:10px;background:var(--bg-soft, transparent);">
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
      <input type="hidden" name="act" value="import_csv">
      <div class="form-group" style="margin:0;flex:1;min-width:220px;">
        <label>CSV file (use the template format)</label>
        <input type="file" name="csv_file" accept=".csv,text/csv" required>
      </div>
      <div class="form-group" style="margin:0;min-width:180px;">
        <label>Import mode</label>
        <select name="import_mode">
          <option value="add">Add / update (keep existing)</option>
          <option value="replace_all">Replace all (empty catalogue only)</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary"><i data-lucide="upload"></i> Import</button>
    </form>
    <p style="margin:10px 0 0;color:var(--text-300);font-size:13px;">
      Columns: <strong>Category</strong>, <strong>Dispensing Unit</strong>, <strong>Product Name</strong>, <strong>Generic / Active Ingredient</strong>.
      Category may be left blank on following rows to reuse the last category.
    </p>
  </div>

  <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
    <form method="GET" style="display:flex;gap:10px;flex:1;min-width:260px;">
      <input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>">
      <div class="search-bar" style="flex:1;">
        <i data-lucide="search"></i>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search <?= strtolower($pageMeta['plural']) ?>...">
      </div>
      <select name="cat" style="width:180px;">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $catFilter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-ghost">Filter</button>
      <?php if ($search || $catFilter): ?><a href="medicines.php?type=<?= urlencode($typeFilter) ?>" class="btn btn-ghost">Clear</a><?php endif; ?>
    </form>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Category</th>
          <th>Dispensing Unit</th>
          <th>Product Name</th>
          <th><?= $typeFilter === 'medicine' ? 'Generic / Active Ingredient' : 'Brand / Description' ?></th>
          <th>Stock</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($medicines)): ?>
        <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-300);">No <?= htmlspecialchars(strtolower($pageMeta['plural'])) ?> found</td></tr>
      <?php else: ?>
      <?php
        $prevCat = null;
        foreach ($medicines as $m):
          $stockClass = $m['stock'] == 0 ? 'badge-red' : ($m['stock'] <= $m['reorder_level'] ? 'badge-orange' : 'badge-green');
          $showCat = ($m['cat_name'] !== $prevCat);
          $prevCat = $m['cat_name'];
      ?>
        <tr>
          <td><?php if ($showCat): ?><span class="badge badge-gray"><?= htmlspecialchars($m['cat_name'] ?? 'Uncategorized') ?></span><?php endif; ?></td>
          <td><?= htmlspecialchars($m['unit']) ?></td>
          <td style="font-weight:600;color:var(--text-100);"><?= htmlspecialchars($m['name']) ?></td>
          <td style="color:var(--text-300);"><?= htmlspecialchars($m['generic_name'] ?: '—') ?></td>
          <td><span class="badge <?= $stockClass ?>"><?= number_format($m['stock']) ?></span></td>
          <td>
            <div class="row-actions">
              <a href="medicines.php?action=edit&type=<?= urlencode($typeFilter) ?>&id=<?= $m['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
              <a href="inventory.php?med=<?= $m['id'] ?>" class="btn btn-ghost btn-sm">Stock</a>
              <form method="POST" onsubmit="return confirmDelete(this)">
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <input type="hidden" name="product_type" value="<?= htmlspecialchars($typeFilter) ?>">
                <button type="submit" class="btn btn-danger btn-sm">Del</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php renderPagination($page, $totalPages, $pagerBase); ?>
</div>
<?php endif; ?>

</div></div>
<?php renderFooter(); ?>
