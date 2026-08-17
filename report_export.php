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
        $headers = ['Product', 'Category', 'Units Sold', 'Revenue', 'Purchase Cost', 'Gross Profit', 'Profit %', 'Current Stock'];
        foreach (reportTopProducts($pdo, $dates, $filters, $_GET['sort'] ?? 'qty', 5000) as $p) {
            $rows[] = [$p['name'], productTypeLabel($p['product_type'] ?? '') . ' · ' . $p['category'], $p['qty_sold'], $p['revenue'], $p['purchase_cost'], $p['gross_profit'], number_format((float)$p['profit_margin'], 1) . '%', $p['current_stock']];
        }
        $filename = 'best-selling-products-' . $dates['from'] . '-' . $dates['to'];
        break;

    case 'product_detail':
        $medId = (int)($_GET['med'] ?? 0);
        if ($medId) {
            $detail = reportProductDetail($pdo, $medId, $dates);
            if ($detail) {
                $m = $detail['medicine'];
                $p = $detail['perf'];
                $headers = ['Metric', 'Value'];
                $rows = [
                    ['Product Name', $m['name']],
                    ['Generic Name', $m['generic_name'] ?? ''],
                    ['Category', productTypeLabel($m['product_type'] ?? '') . ' · ' . $m['category_name']],
                    ['Barcode', $m['barcode'] ?? ''],
                    ['SKU', $m['sku'] ?? ''],
                    ['Current Stock', $m['current_stock']],
                    ['Selling Price', $m['avg_sell_price']],
                    ['Purchase Cost', $m['avg_buy_price']],
                    ['Inventory Value', $m['inventory_value']],
                    ['Units Sold', $p['qty_sold'] ?? 0],
                    ['Revenue', $p['revenue'] ?? 0],
                    ['Purchase Cost (Sales)', $p['purchase_cost'] ?? 0],
                    ['Gross Profit', $detail['gross']],
                    ['Profit Margin', number_format($detail['margin'], 1) . '%'],
                    ['Transactions', $p['num_sales'] ?? 0],
                    ['Avg Units/Day', number_format($detail['avg_daily'], 1)],
                    ['Avg Monthly Sales', number_format($detail['avg_monthly'], 1)],
                    ['Batch Count', $detail['batch_count']],
                    ['Expired Stock', $detail['expired_stock']],
                    ['Near Expiry Stock', $detail['near_expiry_stock']],
                ];
                $rows[] = ['', ''];
                $rows[] = ['Batch Performance', ''];
                $rows[] = ['Batch Number', 'Expiry', 'Qty Purchased', 'Qty Sold', 'Remaining', 'Sales Value'];
                foreach ($detail['batch_performance'] as $b) {
                    $rows[] = [$b['batch_number'], $b['expiry_date'], $b['qty_purchased'], $b['qty_sold'], $b['remaining'], $b['sales_value']];
                }
            }
        }
        $filename = 'product-detail-' . ($medId ?: 'unknown') . '-' . $dates['from'] . '-' . $dates['to'];
        break;

    case 'compare':
        $cmpRaw = $_GET['compare'] ?? '';
        $compareIds = is_array($cmpRaw)
            ? array_map('intval', $cmpRaw)
            : array_map('intval', explode(',', (string)$cmpRaw));
        $compareIds = array_slice(array_values(array_unique(array_filter($compareIds))), 0, 5);
        $headers = ['Product', 'Units Sold', 'Revenue', 'Cost', 'Profit', 'Profit Margin', 'Current Stock'];
        foreach ($compareIds as $cid) {
            $d = reportProductDetail($pdo, $cid, $dates);
            if ($d) {
                $m = $d['medicine'];
                $p = $d['perf'];
                $rows[] = [$m['name'], $p['qty_sold'] ?? 0, $p['revenue'] ?? 0, $p['purchase_cost'] ?? 0, $d['gross'], number_format($d['margin'], 1) . '%', $m['current_stock']];
            }
        }
        $filename = 'product-comparison-' . $dates['from'] . '-' . $dates['to'];
        break;
    case 'sales':
        $daily = reportDailyRevenue($pdo, $dates, $filters);
        $headers = ['Date', 'Orders', 'Revenue'];
        foreach ($daily as $d) $rows[] = [$d['day'], $d['orders'], $d['revenue']];
        $filename = 'sales-report-' . $dates['from'] . '-' . $dates['to'];
        break;
    case 'sales_report':
        $summary = reportSalesSummary($pdo, $dates, $filters);
        $headers = ['Metric', 'Value'];
        $rows = [
            ['Total Revenue', $summary['revenue']],
            ['Total Transactions', $summary['transactions']],
            ['Total Units Sold', $summary['units']],
            ['Average Sale Value', $summary['avg_sale']],
            ['Total Discount', $summary['discount']],
            ['Total Tax', $summary['tax']],
            ['Total Returns', $summary['returns']],
            ['Net Sales', $summary['net']],
        ];
        $rows[] = [];
        $rows[] = ['Date', 'Invoice', 'Customer', 'Items', 'Units', 'Total', 'Discount', 'Tax', 'Payment Method', 'Cashier', 'Status'];
        foreach (reportSalesHistory($pdo, $dates, $filters, 5000) as $r) {
            $rows[] = [
                date('Y-m-d H:i', strtotime($r['created_at'])),
                $r['invoice_number'],
                $r['customer_name'],
                (int)$r['items'],
                (int)$r['units'],
                round((float)$r['total_amount'] - (float)$r['discount'] + (float)$r['tax'], 2),
                (float)$r['discount'],
                (float)$r['tax'],
                reportPaymentMethods()[$r['payment_method']] ?? $r['payment_method'],
                $r['cashier'],
                $r['payment_status'] ? ucfirst($r['payment_status']) : '',
            ];
        }
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
    case 'inventory':
        $invF = reportInventoryFilters($_GET);
        $invSummary = reportInventorySummary($pdo, $dates, $invF);
        $headers = ['Metric', 'Value'];
        $rows = [
            ['Inventory Cost Value', $invSummary['cost']],
            ['Inventory Retail Value', $invSummary['retail']],
            ['Expected Gross Profit', $invSummary['profit']],
            ['Total Units in Stock', $invSummary['units']],
            ['Total Products', $invSummary['products']],
            ['Low Stock Items', $invSummary['low']],
            ['Out of Stock Items', $invSummary['out']],
            ['Near Expiry Items (30d)', $invSummary['near']],
            ['Expired Items', $invSummary['expired']],
        ];
        $rows[] = ['', ''];
        $rows[] = ['Stock Status', ''];
        $rows[] = ['Product', 'Generic Name', 'Category', 'Stock', 'Reorder Level', 'Units Purchased', 'Units Sold', 'Units Returned', 'Last Purchase', 'Last Sale', 'Cost Value', 'Retail Value', 'Expected Profit', 'Profit Margin %', 'Stock Status'];
        foreach (reportInventoryProducts($pdo, $dates, $invF) as $p) {
            $st = inventoryStockStatus($p);
            $retail = (float)$p['retail_value'];
            $cost = (float)$p['cost_value'];
            $rows[] = [
                $p['name'], $p['generic_name'], productTypeLabel($p['product_type'] ?? '') . ' · ' . $p['category'],
                $p['stock'], $p['reorder_level'], $p['units_purchased'], $p['qty_sold'], $p['units_returned'],
                $p['last_purchase'] ? substr($p['last_purchase'], 0, 10) : '', $p['last_sale'] ?: '',
                round($cost, 2), round($retail, 2), round($retail - $cost, 2),
                $retail > 0 ? round(($retail - $cost) / $retail * 100, 1) : 0,
                inventoryStockStatusLabel($st),
            ];
        }
        $rows[] = ['', ''];
        $rows[] = ['Expiry Management', ''];
        $rows[] = ['Product', 'Batch', 'Qty', 'Expiry Date', 'Days Remaining', 'Cost Value', 'Retail Value', 'Status'];
        foreach (reportInventoryExpiryRows($pdo, $dates, $invF) as $r) {
            $rows[] = [
                $r['name'], $r['batch_number'], $r['quantity'],
                $r['expiry_date'] && $r['expiry_date'] < '9000-01-01' ? $r['expiry_date'] : '',
                $r['status']['days'] !== null ? $r['status']['days'] : '',
                round((float)$r['cost_value'], 2), round((float)$r['retail_value'], 2), $r['status']['label'],
            ];
        }
        $filename = 'inventory-report-' . $dates['from'] . '-' . $dates['to'];
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
