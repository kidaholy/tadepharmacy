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

function telegramRequest(string $method, string $url, array $postFields = []): array {
    $body = telegramHttp($method, $url, $postFields);
    if ($body === false) {
        return ['ok' => false, 'error' => 'Could not reach Telegram. PHP HTTPS is off; Windows curl.exe also failed. Restart the PHP server after enabling extension=curl and extension=openssl in php.ini.'];
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Telegram returned an invalid response.'];
    }
    return $json;
}

function telegramHttp(string $method, string $url, array $postFields = []): string|false {
    $payload = $postFields ? json_encode($postFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $isPost = strtoupper($method) === 'POST';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        if ($isPost) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $body = curl_exec($ch);
        curl_close($ch);
        if ($body !== false) {
            return $body;
        }
    }

    $curlBin = telegramCurlBinary();
    if ($curlBin) {
        $tmp = null;
        $cmd = [escapeshellarg($curlBin), '-sS', '--max-time', '12'];
        if ($isPost && $payload !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'tg');
            file_put_contents($tmp, $payload);
            $cmd[] = '-H';
            $cmd[] = escapeshellarg('Content-Type: application/json');
            $cmd[] = '--data-binary';
            $cmd[] = escapeshellarg('@' . $tmp);
        }
        $cmd[] = escapeshellarg($url);
        $out = [];
        $code = 0;
        exec(implode(' ', $cmd) . ' 2>NUL', $out, $code);
        if ($tmp && is_file($tmp)) {
            @unlink($tmp);
        }
        if ($code === 0) {
            return implode("\n", $out);
        }
    }

    $opts = [
        'http' => [
            'method'        => strtoupper($method),
            'timeout'       => 12,
            'ignore_errors' => true,
            'header'        => $isPost ? "Content-Type: application/json\r\n" : '',
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ];
    if ($isPost && $payload !== null) {
        $opts['http']['content'] = $payload;
    }
    $body = @file_get_contents($url, false, stream_context_create($opts));
    return $body === false ? false : $body;
}

function telegramCurlBinary(): ?string {
    $candidates = [
        'C:\\Windows\\System32\\curl.exe',
        'curl.exe',
        'curl',
    ];
    foreach ($candidates as $bin) {
        if ($bin === 'curl' || $bin === 'curl.exe' || is_file($bin)) {
            $out = [];
            $code = 0;
            exec(escapeshellarg($bin) . ' --version 2>NUL', $out, $code);
            if ($code === 0) {
                return $bin;
            }
        }
    }
    return null;
}

function sendTelegram(string $message): array {
    $token  = trim(getSetting('telegram_bot_token', ''));
    $chatId = preg_replace('/\s+/', '', trim(getSetting('telegram_chat_id', '')));
    if ($token === '' || $chatId === '') {
        return ['ok' => false, 'error' => 'Telegram bot token or chat ID is missing.'];
    }
    if (!preg_match('/^-?\d+$/', $chatId) && !str_starts_with($chatId, '@')) {
        return ['ok' => false, 'error' => 'Chat ID must be a number (example: 123456789). Usernames like @name only work for public channels.'];
    }

    $payload = [
        'chat_id'    => preg_match('/^-?\d+$/', $chatId) ? (int) $chatId : $chatId,
        'text'       => $message,
        'parse_mode' => 'HTML',
    ];
    $json = telegramRequest('POST', "https://api.telegram.org/bot{$token}/sendMessage", $payload);
    if (!empty($json['ok'])) {
        return ['ok' => true];
    }

    $desc = $json['description'] ?? $json['error'] ?? 'Telegram rejected the request.';
    if (stripos($desc, 'chat not found') !== false) {
        return ['ok' => false, 'error' => 'Chat not found. Open your bot in Telegram, tap Start, send hello, then click Find chat ID below. Do not use @BotFather or a username — use the numeric ID.'];
    }
    return ['ok' => false, 'error' => $desc];
}

function findTelegramChats(): array {
    $token = trim(getSetting('telegram_bot_token', ''));
    if ($token === '') {
        return ['ok' => false, 'error' => 'Save your Telegram bot token first.'];
    }

    $json = telegramRequest('GET', "https://api.telegram.org/bot{$token}/getUpdates");
    if (empty($json['ok'])) {
        return ['ok' => false, 'error' => $json['description'] ?? $json['error'] ?? 'Could not read Telegram chats.'];
    }

    $chats = [];
    foreach ($json['result'] ?? [] as $update) {
        $chat = $update['message']['chat']
            ?? $update['my_chat_member']['chat']
            ?? $update['channel_post']['chat']
            ?? null;
        if (!$chat || !isset($chat['id'])) {
            continue;
        }
        $id = (string) $chat['id'];
        $name = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
        if ($name === '') {
            $name = $chat['title'] ?? ($chat['username'] ?? 'Chat');
        }
        $chats[$id] = [
            'id'   => $id,
            'name' => trim($name),
            'type' => $chat['type'] ?? 'private',
        ];
    }

    return ['ok' => true, 'chats' => array_values($chats)];
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

function tgEsc(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function dailyReportTimes(): array {
    $t1 = trim(getSetting('telegram_report_time_1', '09:00'));
    $t2 = trim(getSetting('telegram_report_time_2', '18:00'));
    if (!preg_match('/^\d{2}:\d{2}$/', $t1)) $t1 = '09:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $t2)) $t2 = '18:00';
    return ['morning' => $t1, 'evening' => $t2];
}

function buildDailyReportMessage(PDO $pdo, string $slot): string {
    $name = getSetting('pharmacy_name', 'TADE PHARMACY');
    $hour = (int) date('G');
    if ($slot === 'manual') {
        $slot = $hour < 15 ? 'morning' : 'evening';
    }
    $label = $slot === 'evening' ? 'Evening Daily Report' : 'Morning Daily Report';
    $when = date('D, M j, Y g:i A');
    $today = date('Y-m-d');
    $in30 = date('Y-m-d', strtotime('+30 days'));

    $salesStmt = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE date(created_at) = ?");
    $salesStmt->execute([$today]);
    $sales = (int) $salesStmt->fetchColumn();

    $revStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount - discount), 0) FROM sales WHERE date(created_at) = ?");
    $revStmt->execute([$today]);
    $revenue = (float) $revStmt->fetchColumn();

    $itemsStmt = $pdo->prepare("
        SELECT COALESCE(SUM(si.quantity), 0)
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE date(s.created_at) = ?
    ");
    $itemsStmt->execute([$today]);
    $itemsSold = (int) $itemsStmt->fetchColumn();

    $payStmt = $pdo->prepare("
        SELECT payment_method, COUNT(*) AS cnt, COALESCE(SUM(total_amount - discount), 0) AS rev
        FROM sales WHERE date(created_at) = ?
        GROUP BY payment_method ORDER BY rev DESC
    ");
    $payStmt->execute([$today]);
    $payments = $payStmt->fetchAll();

    $outstanding = (float) $pdo->query("
        SELECT COALESCE(SUM(remaining_balance), 0) FROM sales
        WHERE remaining_balance > 0.009 AND (payment_method = 'credit' OR sale_type = 'credit')
    ")->fetchColumn();
    $overdueStmt = $pdo->prepare("
        SELECT COALESCE(SUM(remaining_balance), 0) FROM sales
        WHERE remaining_balance > 0.009 AND (payment_method = 'credit' OR sale_type = 'credit')
          AND COALESCE(due_date, credit_due_date) < ?
    ");
    $overdueStmt->execute([$today]);
    $overdue = (float) $overdueStmt->fetchColumn();

    $collectedStmt = $pdo->prepare("
        SELECT COALESCE(SUM(ph.amount), 0) FROM payment_history ph
        JOIN sales s ON s.id = ph.sale_id
        WHERE date(ph.payment_date) = ?
          AND (s.payment_method = 'credit' OR s.sale_type = 'credit')
    ");
    $collectedStmt->execute([$today]);
    $collected = (float) $collectedStmt->fetchColumn();

    $lowStock = (int) $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT m.id FROM medicines m
            LEFT JOIN batches b ON b.medicine_id = m.id
            GROUP BY m.id
            HAVING COALESCE(SUM(b.quantity), 0) <= m.reorder_level
        ) AS low_meds
    ")->fetchColumn();
    $expStmt = $pdo->prepare("SELECT COUNT(*) FROM batches WHERE expiry_date BETWEEN ? AND ? AND quantity > 0");
    $expStmt->execute([$today, $in30]);
    $expiring = (int) $expStmt->fetchColumn();
    $expiredStmt = $pdo->prepare("SELECT COUNT(*) FROM batches WHERE expiry_date < ? AND quantity > 0");
    $expiredStmt->execute([$today]);
    $expired = (int) $expiredStmt->fetchColumn();

    $topStmt = $pdo->prepare("
        SELECT m.name, SUM(si.quantity) AS qty, SUM(si.subtotal) AS rev
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        JOIN medicines m ON m.id = si.medicine_id
        WHERE date(s.created_at) = ?
        GROUP BY m.id
        ORDER BY qty DESC
        LIMIT 5
    ");
    $topStmt->execute([$today]);
    $top = $topStmt->fetchAll();

    $payLabels = [
        'cash' => 'Cash', 'credit' => 'Credit', 'cbe' => 'CBE', 'telebirr' => 'Telebirr',
        'abyssinia' => 'Abyssinia', 'awash' => 'Awash', 'card' => 'Card',
    ];

    $msg = '<b>' . tgEsc($label) . "</b>\n"
         . tgEsc($name) . "\n"
         . tgEsc($when) . "\n\n"
         . "<b>Sales today</b>\n"
         . 'Invoices: ' . number_format($sales) . "\n"
         . 'Items sold: ' . number_format($itemsSold) . "\n"
         . 'Revenue: ' . tgEsc(currency($revenue)) . "\n";

    if ($payments) {
        foreach ($payments as $p) {
            $key = strtolower((string) $p['payment_method']);
            $method = $payLabels[$key] ?? ucwords(str_replace('_', ' ', $key));
            $msg .= tgEsc($method) . ': ' . (int) $p['cnt'] . ' · ' . tgEsc(currency((float) $p['rev'])) . "\n";
        }
    }

    $msg .= "\n<b>Credit</b>\n"
          . 'Outstanding: ' . tgEsc(currency($outstanding)) . "\n"
          . 'Overdue: ' . tgEsc(currency($overdue)) . "\n"
          . 'Collected today: ' . tgEsc(currency($collected)) . "\n\n"
          . "<b>Inventory</b>\n"
          . 'Low stock: ' . number_format($lowStock) . "\n"
          . 'Expiring (30 days): ' . number_format($expiring) . "\n"
          . 'Expired on shelf: ' . number_format($expired) . "\n";

    if ($top) {
        $msg .= "\n<b>Top products today</b>\n";
        $i = 1;
        foreach ($top as $row) {
            $msg .= $i . '. ' . tgEsc($row['name']) . ' — ' . number_format((int) $row['qty']) . ' sold · ' . tgEsc(currency((float) $row['rev'])) . "\n";
            $i++;
        }
    }

    return trim($msg);
}

function sendDailyReportNow(PDO $pdo, string $slot = 'manual'): array {
    if (!notifyEnabled('telegram')) {
        return ['ok' => false, 'error' => 'Enable Telegram first, then save settings.'];
    }
    return sendNotification(buildDailyReportMessage($pdo, $slot));
}

function maybeSendDailyReports(PDO $pdo): void {
    if (!notifyEnabled('telegram') || getSetting('telegram_daily_report', '1') !== '1') {
        return;
    }

    $times = dailyReportTimes();
    $now = date('H:i');
    $today = date('Y-m-d');
    $state = json_decode(getSetting('telegram_report_sent', '{}'), true);
    if (!is_array($state) || ($state['date'] ?? '') !== $today) {
        $state = ['date' => $today, 'slots' => []];
    }
    $sent = $state['slots'] ?? [];
    $due = [];
    foreach ($times as $slot => $time) {
        if ($now >= $time && !in_array($slot, $sent, true)) {
            $due[] = $slot;
        }
    }
    if (!$due) {
        return;
    }

    $slot = $due[count($due) - 1];
    $result = sendNotification(buildDailyReportMessage($pdo, $slot));
    if (empty($result['telegram']['ok'])) {
        return;
    }

    $state['slots'] = array_values(array_unique(array_merge($sent, $due)));
    getDB()->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_report_sent', ?)")
        ->execute([json_encode($state)]);
    clearSettingsCache();
}

function runScheduledNotifications(PDO $pdo): void {
    if (getSetting('last_credit_alert_date', '') !== date('Y-m-d')) {
        runDailyCreditAlerts($pdo);
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('last_credit_alert_date', ?)")->execute([date('Y-m-d')]);
        clearSettingsCache();
    }
    maybeSendDailyReports($pdo);
}
