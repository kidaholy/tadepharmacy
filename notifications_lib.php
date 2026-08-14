<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sales_lib.php';

function notifyEnabled(string $channel): bool {
    return getSetting("{$channel}_enabled", '0') === '1';
}

function sendNotification(string $message): array {
    $results = [];
    if (notifyEnabled('telegram')) {
        $results['telegram'] = sendTelegram($message);
    }
    return $results;
}

function sendTelegram(string $message): array {
    $token  = trim(getSetting('telegram_bot_token', ''));
    $chatId = trim(getSetting('telegram_chat_id', ''));
    if ($token === '' || $chatId === '') {
        return ['ok' => false, 'error' => 'Telegram bot token or chat ID is missing.'];
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = http_build_query([
        'chat_id'    => $chatId,
        'text'       => $message,
        'parse_mode' => 'HTML',
    ]);

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => $payload,
        'timeout'       => 8,
        'ignore_errors' => true,
    ]]);
    $result = @file_get_contents($url, false, $ctx);
    if ($result === false) {
        return ['ok' => false, 'error' => 'Could not reach Telegram. Check internet access and that PHP allow_url_fopen is On.'];
    }

    $json = json_decode($result, true);
    if (!empty($json['ok'])) {
        return ['ok' => true];
    }
    return ['ok' => false, 'error' => $json['description'] ?? 'Telegram rejected the request.'];
}

function notifyNewCreditSale(array $sale, array $customer): void {
    $net = (float)$sale['total_amount'] - (float)$sale['discount'];
    $due = $sale['due_date'] ?? $sale['credit_due_date'] ?? '—';
    $msg = "<b>New Credit Sale</b>\n"
         . "Customer: {$customer['full_name']} ({$customer['phone']})\n"
         . "Invoice: {$sale['invoice_number']}\n"
         . "Total: " . number_format($net, 2) . " ETB\n"
         . "Due: {$due}";
    sendNotification($msg);
}

function notifyCreditDueTomorrow(array $sales): void {
    foreach ($sales as $s) {
        $msg = "<b>Credit Due Tomorrow</b>\n"
             . "Customer: {$s['customer_name']}\n"
             . "Invoice: {$s['invoice_number']}\n"
             . "Balance: " . number_format((float)$s['remaining_balance'], 2) . " ETB";
        sendNotification($msg);
    }
}

function notifyCreditOverdue(array $sale): void {
    $msg = "<b>Credit Overdue</b>\n"
         . "Customer: {$sale['customer_name']}\n"
         . "Invoice: {$sale['invoice_number']}\n"
         . "Balance: " . number_format((float)$sale['remaining_balance'], 2) . " ETB";
    sendNotification($msg);
}

function notifyLowStock(string $medicineName, int $stock): void {
    sendNotification("<b>Low Stock</b>\n{$medicineName}: {$stock} units remaining");
}

function notifyOutOfStock(string $medicineName): void {
    sendNotification("<b>Out of Stock</b>\n{$medicineName} has no available stock");
}

function runDailyCreditAlerts(PDO $pdo): void {
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $stmt = $pdo->prepare("
        SELECT * FROM sales
        WHERE remaining_balance > 0.009
          AND COALESCE(due_date, credit_due_date) = ?
    ");
    $stmt->execute([$tomorrow]);
    $dueTomorrow = $stmt->fetchAll();
    if ($dueTomorrow) notifyCreditDueTomorrow($dueTomorrow);

    $overdue = $pdo->query("
        SELECT * FROM sales
        WHERE remaining_balance > 0.009
          AND COALESCE(due_date, credit_due_date) < date('now')
        LIMIT 20
    ")->fetchAll();
    foreach ($overdue as $s) notifyCreditOverdue($s);
}

function checkStockAlerts(PDO $pdo, int $medicineId): void {
    $med = $pdo->prepare("SELECT name, reorder_level FROM medicines WHERE id = ?");
    $med->execute([$medicineId]);
    $m = $med->fetch();
    if (!$m) return;

    $stock = medicineAvailableStock($pdo, $medicineId);
    if ($stock === 0) {
        notifyOutOfStock($m['name']);
    } elseif ($stock <= (int)$m['reorder_level']) {
        notifyLowStock($m['name'], $stock);
    }
}
