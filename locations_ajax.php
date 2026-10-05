<?php
/** Location list AJAX endpoint. */
require_once __DIR__ . '/db.php';
$pdo = getDB();

$rows = $pdo->query("SELECT id, name, code, address, status FROM locations ORDER BY name")->fetchAll();
echo json_encode($rows);
