<?php
require_once 'db.php';
$pdo = getDB();
try {
    $pdo->exec("ALTER TABLE medicines ADD COLUMN strength TEXT");
    echo "Added strength.\n";
} catch(Exception $e) {
    echo $e->getMessage() . "\n";
}
try {
    $pdo->exec("ALTER TABLE medicines ADD COLUMN dosage_form TEXT");
    echo "Added dosage_form.\n";
} catch(Exception $e) {
    echo $e->getMessage() . "\n";
}
echo "Done\n";
