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
    case 'purchases':
        require_once __DIR__ . '/purchases_lib.php';
        $tab = $_GET['tab'] ?? 'purchases';
        $from = $dates['from'];
        $to = $dates['to'];
        $supplierId = (int)($filters['supplier'] ?? 0);
        if ($tab === 'payments') {
            $headers = ['Date', 'Supplier', 'Invoice', 'Amount', 'Method', 'Reference'];
            $sql = "SELECT pp.payment_date, s.name, p.purchase_number, pp.amount, pp.payment_method, pp.reference_number
                    FROM purchase_payments pp JOIN purchases p ON p.id=pp.purchase_id LEFT JOIN suppliers s ON s.id=p.supplier_id
                    WHERE pp.payment_date BETWEEN ? AND ?" . ($supplierId ? " AND p.supplier_id=$supplierId" : '');
            $st = $pdo->prepare($sql);
            $st->execute([$from, $to]);
            foreach ($st as $r) $rows[] = [$r['payment_date'], $r['name'], $r['purchase_number'], $r['amount'], $r['payment_method'], $r['reference_number']];
            $filename = 'supplier-payments-' . $from . '-' . $to;
        } elseif ($tab === 'overdue') {
            $headers = ['Supplier', 'Invoice', 'Original', 'Paid', 'Due', 'Due Date', 'Days Overdue'];
            $st = $pdo->query("SELECT p.*, s.name AS supplier_name FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id
                WHERE p.status='received' AND p.due_date < date('now')
                  AND (COALESCE(p.grand_total,p.total_amount)-COALESCE(p.total_paid,0)-COALESCE(p.total_returned,0)) > 0.009");
            foreach ($st as $p) {
                $due = purchaseOutstanding($p);
                $days = (int)((strtotime(date('Y-m-d')) - strtotime($p['due_date'])) / 86400);
                $rows[] = [$p['supplier_name'], $p['purchase_number'], $p['grand_total'] ?? $p['total_amount'], $p['total_paid'], $due, $p['due_date'], $days];
            }
            $filename = 'overdue-payables-' . $from . '-' . $to;
        } elseif ($tab === 'returns') {
            $headers = ['Supplier', 'Purchase', 'Return', 'Amount', 'Reason', 'Date'];
            $sql = "SELECT r.*, p.purchase_number, s.name AS supplier_name FROM purchase_returns r
                    LEFT JOIN purchases p ON p.id=r.purchase_id LEFT JOIN suppliers s ON s.id=r.supplier_id
                    WHERE r.return_date BETWEEN ? AND ?" . ($supplierId ? " AND r.supplier_id=$supplierId" : '');
            $st = $pdo->prepare($sql);
            $st->execute([$from, $to]);
            foreach ($st as $r) $rows[] = [$r['supplier_name'], $r['purchase_number'], $r['return_number'], $r['total_amount'], $r['reason'], $r['return_date']];
            $filename = 'purchase-returns-' . $from . '-' . $to;
        } else {
            $headers = ['Invoice', 'Supplier', 'Date', 'Total', 'Paid', 'Due', 'Status'];
            $sql = "SELECT p.*, s.name AS supplier_name FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id
                    WHERE date(COALESCE(p.purchase_date,p.created_at)) BETWEEN ? AND ?" . ($supplierId ? " AND p.supplier_id=$supplierId" : '');
            $st = $pdo->prepare($sql);
            $st->execute([$from, $to]);
            foreach ($st as $p) {
                $d = purchaseDisplayStatus($p);
                $rows[] = [$p['purchase_number'], $p['supplier_name'], $p['purchase_date'], $p['grand_total'] ?? $p['total_amount'], $p['total_paid'], purchaseOutstanding($p), $d[1]];
            }
            $filename = 'purchases-' . $from . '-' . $to;
        }
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
