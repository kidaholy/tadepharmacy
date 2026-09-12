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
        } elseif ($tab === 'credit') {
            $headers = ['Supplier', 'Total Purchased', 'Total Paid', 'Outstanding'];
            $st = $pdo->query("
                SELECT s.name,
                       COALESCE(SUM(CASE WHEN p.status='received' THEN COALESCE(p.grand_total,p.total_amount) ELSE 0 END),0) AS purchased,
                       COALESCE(SUM(CASE WHEN p.status!='cancelled' THEN COALESCE(p.total_paid,0) ELSE 0 END),0) AS paid,
                       COALESCE(SUM(CASE WHEN p.status='received' THEN COALESCE(p.grand_total,p.total_amount)-COALESCE(p.total_paid,0)-COALESCE(p.total_returned,0) ELSE 0 END),0) AS outstanding
                FROM suppliers s
                LEFT JOIN purchases p ON p.supplier_id = s.id
                GROUP BY s.id
                HAVING purchased > 0 OR outstanding > 0
                ORDER BY outstanding DESC, s.name
            ");
            foreach ($st as $r) $rows[] = [$r['name'], $r['purchased'], $r['paid'], $r['outstanding']];
            $filename = 'supplier-payables-' . $from . '-' . $to;
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
    case 'investment':
        require_once __DIR__ . '/investment_lib.php';
        $cfg = invConfig($_GET);
        $inv = invAnalyze($pdo, $dates, $filters, $cfg);
        $headers = ['Product', 'Category', 'Units Sold', 'Sales Revenue', 'Gross Profit', 'Margin %', 'Current Stock', 'Avg Daily Sales', 'Days of Stock', 'Sales Growth %', 'Investment Score', 'Recommendation', 'Recommended Qty', 'Est. Investment', 'Est. Revenue', 'Est. Profit', 'Est. ROI %'];
        $ranked = $inv['rows'];
        usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
        foreach ($ranked as $m) {
            $rp = $m['purchase'];
            $headersRow = [
                $m['name'],
                $m['category'],
                $m['units_sold'],
                round($m['revenue'], 2),
                round($m['gross_profit'], 2),
                round($m['margin_pct'], 1),
                $m['stock'],
                round($m['avg_daily'], 2),
                $m['coverage'] >= 9999 ? '' : round($m['coverage'], 0),
                round($m['growth_pct'], 1),
                $m['score'],
                $m['rec'][2] ?? $m['rec'][0],
                $rp['recommended_qty'],
                $rp['est_cost'],
                $rp['est_revenue'],
                $rp['est_profit'],
                $rp['est_roi'],
            ];
            $rows[] = $headersRow;
        }
        $filename = 'investment-growth-' . $dates['from'] . '-' . $dates['to'];
        break;

    case 'profit':
        require_once __DIR__ . '/profit_lib.php';
        profitEnsureOverdueStatuses($pdo);
        $bundle = profitExportBundle($pdo, $dates, $filters, [
            'cat_sort' => $_GET['cat_sort'] ?? 'revenue',
            'prod_sort' => $_GET['prod_sort'] ?? 'profit',
            'prod_limit' => (int)($_GET['prod_limit'] ?? 50),
            'margin_threshold' => (float)($_GET['margin_threshold'] ?? 25),
            'exp_filters' => profitParseExpenseFilters($_GET),
        ]);
        $core = $bundle['core'];
        $pharmacy = getSetting('pharmacy_name', 'TADE PHARMACY');
        $chips = reportActiveFilterChips($dates, $filters, reportFilterOptions($pdo));

        if ($format === 'pdf' || $format === 'print') {
            $sections = [
                'Summary' => [
                    ['headers' => ['Metric', 'Value'], 'rows' => [
                        ['Report Period', $dates['label']],
                        ['Filters', implode(' · ', $chips)],
                        ['Revenue', number_format($core['revenue'], 2)],
                        ['COGS', number_format($core['cogs'], 2)],
                        ['Gross Profit', number_format($core['gross_profit'], 2)],
                        ['Operating Expenses', number_format($core['expenses_full'], 2)],
                        ['Net Profit', number_format($core['net_profit'], 2)],
                        ['Gross Margin %', number_format($core['gross_margin'], 1) . '%'],
                        ['Net Margin %', number_format($core['net_margin'], 1) . '%'],
                        ['Profit per ETB 1,000', number_format($core['profit_per_1000'], 2)],
                    ]],
                ],
                'Category Profitability' => [
                    ['headers' => ['Category', 'Units', 'Revenue', 'COGS', 'Gross Profit', 'Margin %'], 'rows' => array_map(fn($c) => [
                        $c['category'], $c['units'], round($c['revenue'], 2), round($c['cogs'], 2),
                        round($c['gross_profit'], 2), number_format($c['gross_margin'], 1) . '%',
                    ], $bundle['categories'])],
                ],
                'Most Profitable Products' => [
                    ['headers' => ['Product', 'Units', 'Revenue', 'COGS', 'Gross Profit', 'Margin %', 'Profit/Unit', 'Txns'], 'rows' => array_map(fn($p) => [
                        $p['name'], $p['units'], round($p['revenue'], 2), round($p['cogs'], 2),
                        round($p['gross_profit'], 2), number_format((float)$p['margin_pct'], 1) . '%',
                        round((float)$p['profit_per_unit'], 2), $p['transactions'],
                    ], $bundle['products'])],
                ],
                'Low-Margin Products' => [
                    ['headers' => ['Product', 'Revenue', 'Gross Profit', 'Margin %'], 'rows' => array_map(fn($p) => [
                        $p['name'], round($p['revenue'], 2), round($p['gross_profit'], 2),
                        number_format((float)$p['margin_pct'], 1) . '%',
                    ], $bundle['low'])],
                ],
                'Expense Breakdown' => [
                    ['headers' => ['Category', 'Amount', '% of OpEx'], 'rows' => array_map(fn($e) => [
                        $e['category'], round((float)$e['total'], 2), number_format((float)$e['pct'], 1) . '%',
                    ], $bundle['expenses']['breakdown'])],
                ],
                'Profit by Payment Method' => [
                    ['headers' => ['Method', 'Txns', 'Revenue', 'COGS', 'Gross Profit'], 'rows' => array_map(fn($p) => [
                        $p['label'], $p['transactions'], round($p['revenue'], 2), round($p['cogs'], 2), round($p['gross_profit'], 2),
                    ], array_filter($bundle['payments'], fn($p) => $p['transactions'] > 0 || $p['revenue'] > 0))],
                ],
                'Profit by Cashier' => [
                    ['headers' => ['Cashier', 'Txns', 'Revenue', 'Gross Profit', 'Margin %'], 'rows' => array_map(fn($c) => [
                        $c['cashier'], $c['transactions'], round($c['revenue'], 2), round($c['gross_profit'], 2),
                        number_format($c['margin_pct'], 1) . '%',
                    ], $bundle['cashiers'])],
                ],
                'Profit by Sales Type' => [
                    ['headers' => ['Type', 'Txns', 'Revenue', 'COGS', 'Gross Profit', 'Margin %'], 'rows' => array_map(fn($s) => [
                        $s['label'], $s['transactions'], round($s['revenue'], 2), round($s['cogs'], 2),
                        round($s['gross_profit'], 2), number_format($s['margin_pct'], 1) . '%',
                    ], $bundle['salesTypes'])],
                ],
                'Profit by Customer' => [
                    ['headers' => ['Customer', 'Txns', 'Revenue', 'Gross Profit', 'Margin %'], 'rows' => array_map(fn($c) => [
                        $c['customer'], $c['transactions'], round($c['revenue'], 2), round($c['gross_profit'], 2),
                        number_format($c['margin_pct'], 1) . '%',
                    ], $bundle['customers'])],
                ],
                'Profit Comparison' => [
                    ['headers' => ['Metric', 'Current', 'Previous', 'Delta'], 'rows' => [
                        ['Revenue', round($core['revenue'], 2), round($bundle['kpis']['prev_core']['revenue'], 2), round($core['revenue'] - $bundle['kpis']['prev_core']['revenue'], 2)],
                        ['COGS', round($core['cogs'], 2), round($bundle['kpis']['prev_core']['cogs'], 2), round($core['cogs'] - $bundle['kpis']['prev_core']['cogs'], 2)],
                        ['Gross Profit', round($core['gross_profit'], 2), round($bundle['kpis']['prev_core']['gross_profit'], 2), round($core['gross_profit'] - $bundle['kpis']['prev_core']['gross_profit'], 2)],
                        ['Operating Expenses', round($core['expenses_full'], 2), round($bundle['kpis']['prev_core']['expenses_full'], 2), round($core['expenses_full'] - $bundle['kpis']['prev_core']['expenses_full'], 2)],
                        ['Net Profit', round($core['net_profit'], 2), round($bundle['kpis']['prev_core']['net_profit'], 2), round($core['net_profit'] - $bundle['kpis']['prev_core']['net_profit'], 2)],
                    ]],
                ],
            ];
            ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pharmacy) ?> — Profit &amp; Loss</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; padding: 24px; }
  h1 { font-size: 18px; margin-bottom: 4px; text-transform: uppercase; }
  h2 { font-size: 14px; margin: 18px 0 6px; border-bottom: 1px solid #333; padding-bottom: 4px; }
  .meta { color: #444; margin-bottom: 12px; line-height: 1.5; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; margin-bottom: 10px; }
  th, td { border: 1px solid #333; padding: 5px 7px; text-align: left; font-size: 11px; }
  th { background: #eee; text-transform: uppercase; font-size: 10px; }
  .note { margin-top: 16px; font-size: 11px; color: #555; }
  @media print { body { padding: 10px; } .no-print { display: none; } }
</style>
</head>
<body>
  <h1>Profit &amp; Loss Report</h1>
  <div class="meta">
    <div><strong><?= htmlspecialchars($pharmacy) ?></strong></div>
    <div>Period: <?= htmlspecialchars($dates['label']) ?></div>
    <div>Generated: <?= date('Y-m-d H:i') ?></div>
    <div>Filters: <?= htmlspecialchars(implode(' · ', $chips)) ?></div>
  </div>
  <?php if ($format === 'pdf'): ?>
  <p class="no-print note">Use your browser’s <strong>Print → Save as PDF</strong> to download this report.</p>
  <?php endif; ?>
  <?php foreach ($sections as $title => $blocks): ?>
    <h2><?= htmlspecialchars($title) ?></h2>
    <?php foreach ($blocks as $block): ?>
      <table>
        <thead><tr><?php foreach ($block['headers'] as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php if (empty($block['rows'])): ?>
          <tr><td colspan="<?= count($block['headers']) ?>" style="text-align:center;">No data</td></tr>
        <?php else: foreach ($block['rows'] as $row): ?>
          <tr><?php foreach ($row as $cell): ?><td><?= htmlspecialchars((string)$cell) ?></td><?php endforeach; ?></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <script>window.onload = function () { window.print(); };</script>
</body>
</html>
            <?php
            exit;
        }

        // Excel: multi-section HTML workbook-style table
        $headers = ['Section', 'Col1', 'Col2', 'Col3', 'Col4', 'Col5', 'Col6', 'Col7', 'Col8'];
        $rows = [];
        $rows[] = ['PROFIT & LOSS SUMMARY', $pharmacy, $dates['label'], '', '', '', '', '', ''];
        $rows[] = ['Filters', implode(' | ', $chips), '', '', '', '', '', '', ''];
        $rows[] = ['Metric', 'Value', '', '', '', '', '', '', ''];
        foreach ([
            ['Revenue', $core['revenue']],
            ['COGS', $core['cogs']],
            ['Gross Profit', $core['gross_profit']],
            ['Operating Expenses', $core['expenses_full']],
            ['Net Profit', $core['net_profit']],
            ['Gross Margin %', round($core['gross_margin'], 1)],
            ['Net Margin %', round($core['net_margin'], 1)],
            ['Profit per ETB 1,000', round($core['profit_per_1000'], 2)],
        ] as $r) $rows[] = [$r[0], $r[1], '', '', '', '', '', '', ''];

        $rows[] = ['', '', '', '', '', '', '', '', ''];
        $rows[] = ['CATEGORY PROFITABILITY', 'Units', 'Revenue', 'COGS', 'Gross Profit', 'Margin %', '', '', ''];
        foreach ($bundle['categories'] as $c) {
            $rows[] = [$c['category'], $c['units'], round($c['revenue'], 2), round($c['cogs'], 2), round($c['gross_profit'], 2), round($c['gross_margin'], 1), '', '', ''];
        }

        $rows[] = ['', '', '', '', '', '', '', '', ''];
        $rows[] = ['MOST PROFITABLE PRODUCTS', 'Units', 'Revenue', 'COGS', 'Gross Profit', 'Margin %', 'Avg Sell', 'Profit/Unit', 'Txns'];
        foreach ($bundle['products'] as $p) {
            $rows[] = [$p['name'], $p['units'], round($p['revenue'], 2), round($p['cogs'], 2), round($p['gross_profit'], 2), round((float)$p['margin_pct'], 1), round((float)$p['avg_sell'], 2), round((float)$p['profit_per_unit'], 2), $p['transactions']];
        }

        $rows[] = ['', '', '', '', '', '', '', '', ''];
        $rows[] = ['LOW-MARGIN PRODUCTS', 'Revenue', 'Gross Profit', 'Margin %', '', '', '', '', ''];
        foreach ($bundle['low'] as $p) {
            $rows[] = [$p['name'], round($p['revenue'], 2), round($p['gross_profit'], 2), round((float)$p['margin_pct'], 1), '', '', '', '', ''];
        }

        $rows[] = ['', '', '', '', '', '', '', '', ''];
        $rows[] = ['EXPENSE BREAKDOWN', 'Amount', '% of OpEx', 'Paid', 'Pending', 'Overdue', '', '', ''];
        $rows[] = ['TOTAL', round($bundle['expenses']['total'], 2), '100', round($bundle['expenses']['paid'], 2), round($bundle['expenses']['pending'], 2), round($bundle['expenses']['overdue'], 2), '', '', ''];
        foreach ($bundle['expenses']['breakdown'] as $e) {
            $rows[] = [$e['category'], round((float)$e['total'], 2), round((float)$e['pct'], 1), '', '', '', '', '', ''];
        }

        $rows[] = ['', '', '', '', '', '', '', '', ''];
        $rows[] = ['PAYMENT METHOD', 'Txns', 'Revenue', 'COGS', 'Gross Profit', '', '', '', ''];
        foreach ($bundle['payments'] as $p) {
            if ($p['transactions'] <= 0 && $p['revenue'] <= 0) continue;
            $rows[] = [$p['label'], $p['transactions'], round($p['revenue'], 2), round($p['cogs'], 2), round($p['gross_profit'], 2), '', '', '', ''];
        }

        $rows[] = ['', '', '', '', '', '', '', '', ''];
        $rows[] = ['CASHIER', 'Txns', 'Revenue', 'Gross Profit', 'Margin %', '', '', '', ''];
        foreach ($bundle['cashiers'] as $c) {
            $rows[] = [$c['cashier'], $c['transactions'], round($c['revenue'], 2), round($c['gross_profit'], 2), round($c['margin_pct'], 1), '', '', '', ''];
        }

        $rows[] = ['', '', '', '', '', '', '', '', ''];
        $rows[] = ['SALES TYPE', 'Txns', 'Revenue', 'COGS', 'Gross Profit', 'Margin %', '', '', ''];
        foreach ($bundle['salesTypes'] as $s) {
            $rows[] = [$s['label'], $s['transactions'], round($s['revenue'], 2), round($s['cogs'], 2), round($s['gross_profit'], 2), round($s['margin_pct'], 1), '', '', ''];
        }

        $rows[] = ['', '', '', '', '', '', '', '', ''];
        $rows[] = ['CUSTOMER', 'Txns', 'Revenue', 'Gross Profit', 'Margin %', '', '', '', ''];
        foreach ($bundle['customers'] as $c) {
            $rows[] = [$c['customer'], $c['transactions'], round($c['revenue'], 2), round($c['gross_profit'], 2), round($c['margin_pct'], 1), '', '', '', ''];
        }

        $rows[] = ['', '', '', '', '', '', '', '', ''];
        $v = $bundle['variance'];
        $rows[] = ['PROFIT COMPARISON', 'Delta', '', '', '', '', '', '', ''];
        $rows[] = ['Net Profit change', round($v['net_delta'], 2), '', '', '', '', '', '', ''];
        $rows[] = ['Revenue delta', round($v['revenue_delta'], 2), '', '', '', '', '', '', ''];
        $rows[] = ['COGS delta', round($v['cogs_delta'], 2), '', '', '', '', '', '', ''];
        $rows[] = ['OpEx delta', round($v['expenses_delta'], 2), '', '', '', '', '', '', ''];

        $filename = 'profit-loss-' . $dates['from'] . '-' . $dates['to'];
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
