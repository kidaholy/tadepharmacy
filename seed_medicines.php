<?php
/**
 * Seed medicines catalogue from TSV list.
 * Format: Category | Dispensing unit | Product Name | Generic / Active Ingredient
 */
require_once __DIR__ . '/db.php';

$pdo = getDB();

$tsv = file_get_contents(__DIR__ . '/data/medicines_catalogue.tsv');
if ($tsv === false) {
    fwrite(STDERR, "Missing data/medicines_catalogue.tsv\n");
    exit(1);
}
$lines = preg_split('/\r\n|\r|\n/', $tsv);

$categories = [];
$medicines = [];
$currentCat = '';

foreach ($lines as $i => $line) {
    $line = rtrim($line, "\r\n");
    if ($line === '' || $i === 0) continue; // skip header / blanks

    $parts = explode("\t", $line);
    // Pad to 4 columns
    while (count($parts) < 4) $parts[] = '';

    $cat     = trim($parts[0]);
    $unit    = trim($parts[1]);
    $name    = trim(preg_replace('/\s+/', ' ', $parts[2]));
    $generic = trim(preg_replace('/\s+/', ' ', $parts[3]));

    // Known dispensing units that must never become categories
    $knownUnits = ['strip','pk','bottle','sachet','ampoule','tube','suppository','effervescent','puff','pis','tin','vial'];
    if ($cat !== '' && in_array(strtolower($cat), $knownUnits, true)) {
        // Misaligned row: shift right
        $generic = $name;
        $name    = $unit;
        $unit    = $cat;
        $cat     = '';
    }

    if ($cat !== '') {
        $currentCat = $cat;
        $categories[$currentCat] = true;
    }
    if ($name === '' || $unit === '') continue;

    $medicines[] = [
        'category' => $currentCat,
        'unit'     => strtolower($unit),
        'name'     => $name,
        'generic'  => $generic,
    ];
}

echo "Parsed " . count($medicines) . " medicines across " . count($categories) . " categories\n";

$pdo->beginTransaction();
try {
    // Clear dependent rows that block medicine delete
    $pdo->exec('DELETE FROM purchase_items');
    $pdo->exec('DELETE FROM sale_items');
    $pdo->exec('DELETE FROM batches');
    $pdo->exec('DELETE FROM medicines');
    $pdo->exec('DELETE FROM categories');

    $catIds = [];
    $insCat = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
    foreach (array_keys($categories) as $catName) {
        $insCat->execute([$catName]);
        $catIds[$catName] = (int)$pdo->lastInsertId();
        echo "  Category: $catName (#{$catIds[$catName]})\n";
    }

    $insMed = $pdo->prepare(
        'INSERT INTO medicines (name, generic_name, category_id, unit, reorder_level) VALUES (?,?,?,?,10)'
    );
    foreach ($medicines as $m) {
        $insMed->execute([
            $m['name'],
            $m['generic'] !== '' ? $m['generic'] : null,
            $catIds[$m['category']] ?? null,
            $m['unit'],
        ]);
    }

    $pdo->commit();
    echo "\nSeeded " . $pdo->query('SELECT COUNT(*) FROM medicines')->fetchColumn() . " medicines successfully.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
