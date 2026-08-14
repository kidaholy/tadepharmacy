<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/notifications_lib.php';

runScheduledNotifications(getDB());

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
