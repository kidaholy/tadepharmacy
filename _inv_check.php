<?php
require_once __DIR__ . '/auth.php';
$pdo = getDB();
$_SESSION = []; $_SESSION['user_id'] = 1; refreshUserSession(1);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/investment.php';
$cases = ['preset=last30', 'preset=last30&detail=3006', 'preset=last7', 'preset=last30&budget=100000', 'preset=last30&type=medicine', 'preset=previous_year'];
$bad = 0;
foreach ($cases as $q) {
    parse_str($q, $_GET);
    ob_start();
    require __DIR__ . '/investment.php';
    $html = ob_get_clean();
    $counts = [];
    foreach (['div', 'table', 'tr', 'td', 'th', 'form', 'select', 'tbody', 'thead', 'span', 'p'] as $tag) {
        $open = preg_match_all('/<' . $tag . '[\s>]/i', $html);
        $close = preg_match_all('/<\/' . $tag . '>/i', $html);
        $counts[$tag] = $open - $close;
    }
    $cells = $counts['td'] + $counts['th'];
    unset($counts['td'], $counts['th']);
    $unbalanced = array_filter($counts, fn($v) => $v !== 0);
    $ok = !$unbalanced && $cells === 0 && !str_contains($html, 'Undefined') && !str_contains($html, 'Fatal error');
    if (!$ok) $bad++;
    echo ($ok ? 'OK  ' : 'BAD ') . $q
        . ' | unbalanced=' . json_encode($unbalanced) . ' cells=' . $cells
        . ' | bytes=' . strlen($html) . "\n";
}
echo $bad === 0 ? "MARKUP STRUCTURE OK\n" : "MARKUP PROBLEMS: $bad case(s)\n";
