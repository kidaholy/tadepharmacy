<?php
/** External pharmacy list AJAX endpoint. */
require_once __DIR__ . '/db.php';
$pdo = getDB();

$rows = $pdo->query("SELECT id, name, contact_person, phone, address, status FROM external_pharmacies ORDER BY name")->fetchAll();
echo json_encode($rows);
