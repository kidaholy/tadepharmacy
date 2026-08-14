<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/report_lib.php';

$pdo = getDB();
$dates   = reportParseDateRange($_GET);
$filters = reportParseFilters($_GET);
$format  = $_GET['format'] ?? 'csv';
$report  = $_GET['report'] ?? 'overview';

$headers = [];
$rows    = [];

switch ($report) {
    case 'products':
        $headers = ['Product', 'Category', 'Qty Sold', 'Revenue', 'Purchase Cost', 'Gross Profit', 'Current Stock'];
        foreach (reportTopProducts($pdo, $dates, $filters, $_GET['sort'] ?? 'qty', 5000) as $p) {
            $rows[] = [$p['name'], $p['category'], $p['qty_sold'], $p['revenue'], $p['purchase_cost'], $p['gross_profit'], $p['current_stock']];
        }
        $filename = 'best-selling-products-' . $dates['from'] . '-' . $dates['to'];
        break;
    case 'sales':
        $daily = reportDailyRevenue($pdo, $dates, $filters);
        $headers = ['Date', 'Orders', 'Revenue'];
        foreach ($daily as $d) $rows[] = [$d['day'], $d['orders'], $d['revenue']];
        $filename = 'sales-report-' . $dates['from'] . '-' . $dates['to'];
        break;
    case 'payments':
        $headers = ['Payment Method', 'Transactions', 'Amount'];
        foreach (reportPaymentBreakdown($pdo, $dates, $filters) as $p) {
            $rows[] = [reportPaymentMethods()[$p['payment_method']] ?? $p['payment_method'], $p['cnt'], $p['amount']];
        }
        $filename = 'payment-report-' . $dates['from'] . '-' . $dates['to'];
        break;
    default:
        $kpis = reportOverviewKpis($pdo, $dates, $filters);
        $headers = ['Metric', 'Value'];
        $rows = [
            ['Total Revenue', $kpis['revenue']['current']],
            ['Gross Profit', $kpis['gross_profit']['current']],
            ['Net Profit', $kpis['net_profit']['current']],
            ['Profit Margin %', round($kpis['profit_margin']['current'], 2)],
            ['Total Orders', $kpis['total_orders']['current']],
            ['Inventory Value', $kpis['inventory_value']['current']],
            ['Outstanding Credit', $kpis['outstanding_credit']['current']],
            ['Low Stock Products', $kpis['low_stock']['current']],
            ['Out of Stock', $kpis['out_of_stock']['current']],
        ];
        $top = reportTopProducts($pdo, $dates, $filters, 'qty', 50);
        $rows[] = ['', ''];
        $rows[] = ['Top Products', ''];
        foreach ($top as $p) {
            $rows[] = [$p['name'], $p['qty_sold'] . ' units / ' . $p['revenue']];
        }
        $filename = 'overview-report-' . $dates['from'] . '-' . $dates['to'];
}

if ($format === 'excel') {
    reportExportExcel($filename, $headers, $rows);
}
reportExportCsv($filename . '.csv', $headers, $rows);
