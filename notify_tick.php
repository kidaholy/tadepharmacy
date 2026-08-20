<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/notifications_lib.php';

try {
    runScheduledNotifications(getDB());
} catch (Throwable $e) {
    // Background tick must never 500.
}

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
