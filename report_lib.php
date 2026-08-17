<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sales_lib.php';

function reportDatePresets(): array {
    return [
        'today'      => 'Today',
        'yesterday'  => 'Yesterday',
        'last7'      => 'Last 7 Days',
        'last30'     => 'Last 30 Days',
        'last90'     => 'Last 90 Days',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'this_year'  => 'This Year',
        'custom'     => 'Custom Range',
    ];
}

function reportPaymentMethods(): array {
    return array_merge(posPaymentMethods(), [
        'card'  => 'Card',
        'mpesa' => 'M-Pesa',
    ]);
}

/** Business-local calendar date for UTC-stored timestamps (Africa/Addis_Ababa = UTC+3). */
function reportLocalDateExpr(string $alias = 's', string $col = 'created_at'): string {
    return "date($alias.$col, '+3 hours')";
}

function reportFilteredSalesSubquery(array $filters, string $from, string $to, string $select = 's.id, (s.total_amount - s.discount) AS net'): array {
    $ctx = reportBuildSalesContext($filters);
    $extra = $ctx['where'] ? ' AND ' . implode(' AND ', $ctx['where']) : '';
    $day = reportLocalDateExpr('s');
    $sql = "
        SELECT DISTINCT $select
        FROM sales s {$ctx['joins']}
        WHERE $day BETWEEN ? AND ? $extra
    ";
    return ['sql' => $sql, 'params' => array_merge([$from, $to], $ctx['params'])];
}

function reportParseDateRange(array $input): array {
    $preset = $input['preset'] ?? '';
    $today  = date('Y-m-d');

    if (!$preset && !empty($input['from']) && !empty($input['to'])) {
        $preset = 'custom';
    }
    if (!$preset) {
        $preset = 'last30';
    }

    switch ($preset) {
        case 'today':
            $from = $to = $today;
            break;
        case 'this_week':
            // Monday of the current business week through today.
            $dow = (int)date('N'); // 1 = Mon .. 7 = Sun
            $from = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
            $to   = $today;
            break;
        case 'yesterday':
            $from = $to = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'last7':
            $from = date('Y-m-d', strtotime('-6 days'));
            $to   = $today;
            break;
        case 'last30':
            $from = date('Y-m-d', strtotime('-29 days'));
            $to   = $today;
            break;
        case 'last90':
            $from = date('Y-m-d', strtotime('-89 days'));
            $to   = $today;
            break;
        case 'this_month':
            $from = date('Y-m-01');
            $to   = $today;
            break;
        case 'last_month':
            $from = date('Y-m-01', strtotime('first day of last month'));
            $to   = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'this_year':
            $from = date('Y-01-01');
            $to   = $today;
            break;
        case 'custom':
        default:
            $from = $input['from'] ?? date('Y-m-01');
            $to   = $input['to']   ?? $today;
            $preset = 'custom';
            break;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;
    if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }

    $days   = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
    $prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));

    $label = date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

    return compact('from', 'to', 'preset', 'prevFrom', 'prevTo', 'days', 'label');
}

function reportParseFilters(array $input): array {
    return [
        'product'         => (int)($input['product'] ?? 0),
        'category'        => (int)($input['category'] ?? 0),
        'supplier'        => (int)($input['supplier'] ?? 0),
        'customer'        => trim($input['customer'] ?? ''),
        'cashier'         => (int)($input['cashier'] ?? 0),
        'payment_method'  => trim($input['payment_method'] ?? ''),
        'sales_type'      => trim($input['sales_type'] ?? ''),
        'branch'          => (int)($input['branch'] ?? 0),
    ];
}

function reportNeedsItemJoin(array $filters): bool {
    return $filters['product'] > 0 || $filters['category'] > 0 || $filters['supplier'] > 0;
}

function reportBuildSalesContext(array $filters, string $saleAlias = 's'): array {
    $joins  = ["LEFT JOIN customers cust ON cust.id = $saleAlias.customer_id"];
    $where  = [];
    $params = [];

    if ($filters['customer']) {
        $where[]  = "(COALESCE(NULLIF(TRIM($saleAlias.customer_name), ''), cust.full_name) LIKE ? OR cust.phone LIKE ?)";
        $like = '%' . $filters['customer'] . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($filters['cashier']) {
        $where[]  = "$saleAlias.user_id = ?";
        $params[] = $filters['cashier'];
    }
    if ($filters['payment_method']) {
        $where[]  = "$saleAlias.payment_method = ?";
        $params[] = $filters['payment_method'];
    }
    if ($filters['sales_type']) {
        if ($filters['sales_type'] === 'credit') {
            $where[] = "($saleAlias.sale_type = 'credit' OR $saleAlias.payment_method = 'credit')";
        } elseif ($filters['sales_type'] === 'cash') {
            $where[] = "($saleAlias.sale_type = 'cash' OR ($saleAlias.payment_method IS NOT NULL AND $saleAlias.payment_method != 'credit' AND COALESCE($saleAlias.sale_type, 'cash') != 'credit'))";
        } else {
            $where[]  = "$saleAlias.sale_type = ?";
            $params[] = $filters['sales_type'];
        }
    }
    if ($filters['branch']) {
        $where[]  = "$saleAlias.branch_id = ?";
        $params[] = $filters['branch'];
    }

    if (reportNeedsItemJoin($filters)) {
        $joins[] = "JOIN sale_items si ON si.sale_id = $saleAlias.id";
        if ($filters['product']) {
            $where[]  = 'si.medicine_id = ?';
            $params[] = $filters['product'];
        }
        if ($filters['category']) {
            $joins[]  = 'JOIN medicines mf ON mf.id = si.medicine_id';
            $where[]  = 'mf.category_id = ?';
            $params[] = $filters['category'];
        }
        if ($filters['supplier']) {
            $joins[]  = 'JOIN batches bf ON bf.id = si.batch_id';
            $where[]  = 'bf.supplier_id = ?';
            $params[] = $filters['supplier'];
        }
    }

    return [
        'joins'  => $joins ? ("\n" . implode("\n", array_unique($joins))) : '',
        'where'  => $where,
        'params' => $params,
    ];
}

/** Shared WHERE/JOIN pieces for sale_items-based reports (products, category, COGS). */
function reportItemFilterContext(array $filters, string $from, string $to): array {
    $day = reportLocalDateExpr('s');
    $where = ["$day BETWEEN ? AND ?"];
    $params = [$from, $to];
    $joins = [
        'JOIN sales s ON s.id = si.sale_id',
        'JOIN batches b ON b.id = si.batch_id',
        'JOIN medicines m ON m.id = si.medicine_id',
    ];

    if ($filters['product']) {
        $where[] = 'si.medicine_id = ?';
        $params[] = $filters['product'];
    }
    if ($filters['category']) {
        $where[] = 'm.category_id = ?';
        $params[] = $filters['category'];
    }
    if ($filters['supplier']) {
        $where[] = 'b.supplier_id = ?';
        $params[] = $filters['supplier'];
    }
    if ($filters['customer']) {
        $joins[] = 'LEFT JOIN customers cust ON cust.id = s.customer_id';
        $where[] = "(COALESCE(NULLIF(TRIM(s.customer_name), ''), cust.full_name) LIKE ? OR cust.phone LIKE ?)";
        $like = '%' . $filters['customer'] . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($filters['cashier']) {
        $where[] = 's.user_id = ?';
        $params[] = $filters['cashier'];
    }
    if ($filters['payment_method']) {
        $where[] = 's.payment_method = ?';
        $params[] = $filters['payment_method'];
    }
    if ($filters['sales_type'] === 'credit') {
        $where[] = "(s.sale_type = 'credit' OR s.payment_method = 'credit')";
    } elseif ($filters['sales_type'] === 'cash') {
        $where[] = "(s.sale_type = 'cash' OR (s.payment_method IS NOT NULL AND s.payment_method != 'credit' AND COALESCE(s.sale_type, 'cash') != 'credit'))";
    } elseif ($filters['sales_type']) {
        $where[] = 's.sale_type = ?';
        $params[] = $filters['sales_type'];
    }
    if ($filters['branch']) {
        $where[] = 's.branch_id = ?';
        $params[] = $filters['branch'];
    }

    return [
        'joins'  => implode("\n", array_unique($joins)),
        'where'  => implode(' AND ', $where),
        'params' => $params,
    ];
}

function reportDateClause(string $from, string $to, string $col = 'created_at', string $alias = 's'): array {
    return [
        reportLocalDateExpr($alias, $col) . ' BETWEEN ? AND ?',
        [$from, $to],
    ];
}

function reportPctChange(float $current, float $previous): ?float {
    if ($previous == 0) {
        return $current > 0 ? 100.0 : ($current < 0 ? -100.0 : 0.0);
    }
    return (($current - $previous) / abs($previous)) * 100;
}

function reportTrendMeta(float $current, float $previous, bool $higherIsGood = true): array {
    $change = reportPctChange($current, $previous);
    $up     = $current >= $previous;
    $good   = $higherIsGood ? $up : !$up;
    return [
        'current'  => $current,
        'previous' => $previous,
        'change'   => $change,
        'up'       => $up,
        'good'     => $good,
        'dir'      => $up ? 'up' : 'down',
    ];
}

function reportFilterOptions(PDO $pdo): array {
    return [
        'products'   => $pdo->query("SELECT id, name, generic_name, barcode, sku FROM medicines ORDER BY name")->fetchAll(),
        // Categories ordered by product type (Medicine / Cosmetics / Equipment) so the
        // filter dropdown can group them instead of mixing e.g. Antibiotics with Haircare.
        'categories' => $pdo->query("SELECT id, name, COALESCE(product_type, 'medicine') AS product_type FROM categories ORDER BY product_type, name COLLATE NOCASE")->fetchAll(),
        'suppliers'  => $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll(),
        'cashiers'   => $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll(),
        'customers'  => $pdo->query("SELECT id, full_name AS name, phone FROM customers ORDER BY full_name LIMIT 200")->fetchAll(),
    ];
}

function reportQueryParams(array $dates, array $filters, array $extra = []): array {
    return array_filter(array_merge([
        'preset'          => $dates['preset'],
        'from'            => $dates['from'],
        'to'              => $dates['to'],
        'product'         => $filters['product'] ?: null,
        'category'        => $filters['category'] ?: null,
        'supplier'        => $filters['supplier'] ?: null,
        'customer'        => $filters['customer'] ?: null,
        'cashier'         => $filters['cashier'] ?: null,
        'payment_method'  => $filters['payment_method'] ?: null,
        'sales_type'      => $filters['sales_type'] ?: null,
        'branch'          => $filters['branch'] ?: null,
    ], $extra), fn($v) => $v !== null && $v !== '');
}

function reportQueryString(array $dates, array $filters, array $extra = []): string {
    return http_build_query(reportQueryParams($dates, $filters, $extra));
}

function reportSaleNetExpr(string $alias = 's'): string {
    return "($alias.total_amount - $alias.discount)";
}

function reportFetchScalar(PDO $pdo, string $sql, array $params = []): float {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

function reportOverviewKpis(PDO $pdo, array $dates, array $filters): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $pf   = $dates['prevFrom'];
    $pt   = $dates['prevTo'];
    $net  = reportSaleNetExpr('s');

    $curSub  = reportFilteredSalesSubquery($filters, $from, $to);
    $prevSub = reportFilteredSalesSubquery($filters, $pf, $pt);

    $revenueCur = reportFetchScalar($pdo, "SELECT COALESCE(SUM(net), 0) FROM ({$curSub['sql']}) t", $curSub['params']);
    $revenuePrev = reportFetchScalar($pdo, "SELECT COALESCE(SUM(net), 0) FROM ({$prevSub['sql']}) t", $prevSub['params']);
    $ordersCur = (int)reportFetchScalar($pdo, "SELECT COUNT(*) FROM ({$curSub['sql']}) t", $curSub['params']);
    $ordersPrev = (int)reportFetchScalar($pdo, "SELECT COUNT(*) FROM ({$prevSub['sql']}) t", $prevSub['params']);

    $itemCtx = reportItemFilterContext($filters, $from, $to);
    $prevItemCtx = reportItemFilterContext($filters, $pf, $pt);

    $cogsCur = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(si.quantity * b.purchase_price), 0)
        FROM sale_items si
        {$itemCtx['joins']}
        WHERE {$itemCtx['where']}
    ", $itemCtx['params']);
    $cogsPrev = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(si.quantity * b.purchase_price), 0)
        FROM sale_items si
        {$prevItemCtx['joins']}
        WHERE {$prevItemCtx['where']}
    ", $prevItemCtx['params']);

    $grossCur  = $revenueCur - $cogsCur;
    $grossPrev = $revenuePrev - $cogsPrev;

    $expCur = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(amount), 0) FROM operating_expenses
        WHERE expense_date BETWEEN ? AND ?
    ", [$from, $to]);
    $expPrev = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(amount), 0) FROM operating_expenses
        WHERE expense_date BETWEEN ? AND ?
    ", [$pf, $pt]);

    $netCur  = $grossCur - $expCur;
    $netPrev = $grossPrev - $expPrev;

    $purchaseCur = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE date(created_at) BETWEEN ? AND ?
    ", [$from, $to]);

    $customersCur = (int)reportFetchScalar($pdo, "
        SELECT COUNT(DISTINCT s.customer_name) FROM sales s
        WHERE s.id IN (SELECT id FROM ({$curSub['sql']}) t)
          AND s.customer_name IS NOT NULL AND TRIM(s.customer_name) != ''
    ", $curSub['params']);

    $inv = $pdo->query("
        SELECT COALESCE(SUM(quantity * purchase_price), 0) AS cost_value,
               COALESCE(SUM(quantity * selling_price), 0) AS retail_value
        FROM batches WHERE quantity > 0
    ")->fetch();

    $lowStock = (int)$pdo->query("
        SELECT COUNT(*) FROM (
          SELECT m.id FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id
          GROUP BY m.id HAVING COALESCE(SUM(b.quantity), 0) > 0 AND COALESCE(SUM(b.quantity), 0) <= m.reorder_level
        )
    ")->fetchColumn();

    $outStock = (int)$pdo->query("
        SELECT COUNT(*) FROM (
          SELECT m.id FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id
          GROUP BY m.id HAVING COALESCE(SUM(b.quantity), 0) = 0
        )
    ")->fetchColumn();

    $expiring = (int)$pdo->query("
        SELECT COUNT(DISTINCT medicine_id) FROM batches
        WHERE expiry_date BETWEEN date('now') AND date('now', '+30 days') AND quantity > 0
          AND expiry_date < '9000-01-01'
    ")->fetchColumn();

    $returned = (int)reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(quantity), 0) FROM sale_returns WHERE date(created_at) BETWEEN ? AND ?
    ", [$from, $to]);

    $creditOutstanding = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM($net - s.paid_amount), 0) FROM sales s
        WHERE (s.sale_type = 'credit' OR s.payment_method = 'credit')
          AND ($net - s.paid_amount) > 0.009
    ");
    $creditOverdue = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM($net - s.paid_amount), 0) FROM sales s
        WHERE (s.sale_type = 'credit' OR s.payment_method = 'credit')
          AND ($net - s.paid_amount) > 0.009
          AND s.credit_due_date IS NOT NULL AND s.credit_due_date < date('now')
    ");
    $creditCollected = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(s.paid_amount), 0) FROM sales s
        WHERE (s.sale_type = 'credit' OR s.payment_method = 'credit')
          AND " . reportLocalDateExpr('s') . " BETWEEN ? AND ?
    ", [$from, $to]);

    $creditCollectedToday = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(ph.amount), 0)
        FROM payment_history ph
        JOIN sales s ON s.id = ph.sale_id
        WHERE (s.sale_type = 'credit' OR s.payment_method = 'credit')
          AND date(ph.payment_date) = date('now')
    ");

    $avgOrder = $ordersCur > 0 ? $revenueCur / $ordersCur : 0;
    $avgPrev  = $ordersPrev > 0 ? $revenuePrev / $ordersPrev : 0;
    $marginCur = $revenueCur > 0 ? ($netCur / $revenueCur) * 100 : 0;
    $marginPrev = $revenuePrev > 0 ? ($netPrev / $revenuePrev) * 100 : 0;

    return [
        'revenue'            => reportTrendMeta($revenueCur, $revenuePrev),
        'gross_profit'       => reportTrendMeta($grossCur, $grossPrev),
        'net_profit'         => reportTrendMeta($netCur, $netPrev),
        'profit_margin'      => reportTrendMeta($marginCur, $marginPrev),
        'inventory_value'    => ['current' => (float)$inv['cost_value'], 'previous' => (float)$inv['cost_value'], 'change' => 0, 'up' => true, 'good' => true, 'dir' => 'up'],
        'inventory_retail'   => (float)$inv['retail_value'],
        'total_orders'       => reportTrendMeta($ordersCur, $ordersPrev),
        'customers_served'   => reportTrendMeta($customersCur, (float)$customersCur, false),
        'outstanding_credit' => ['current' => $creditOutstanding, 'previous' => $creditOutstanding, 'change' => 0, 'up' => false, 'good' => true, 'dir' => 'down'],
        'credit_collected'   => reportTrendMeta($creditCollected, 0),
        'credit_collected_today' => ['current' => $creditCollectedToday, 'previous' => 0, 'change' => 0, 'up' => true, 'good' => true, 'dir' => 'up'],
        'overdue_credit'     => ['current' => $creditOverdue, 'previous' => $creditOverdue, 'change' => 0, 'up' => false, 'good' => true, 'dir' => 'down'],
        'low_stock'          => ['current' => $lowStock, 'previous' => $lowStock, 'change' => 0, 'up' => false, 'good' => true, 'dir' => 'down'],
        'out_of_stock'       => ['current' => $outStock, 'previous' => $outStock, 'change' => 0, 'up' => false, 'good' => true, 'dir' => 'down'],
        'expiring_products'  => ['current' => $expiring, 'previous' => $expiring, 'change' => 0, 'up' => false, 'good' => true, 'dir' => 'down'],
        'returned_products'  => reportTrendMeta($returned, 0, false),
        'purchase_cost'      => reportTrendMeta($purchaseCur, 0),
        'avg_order_value'    => reportTrendMeta($avgOrder, $avgPrev),
        'cogs'               => reportTrendMeta($cogsCur, $cogsPrev, false),
        'expenses'           => reportTrendMeta($expCur, $expPrev, false),
    ];
}

function reportDailyRevenue(PDO $pdo, array $dates, array $filters): array {
    $day = reportLocalDateExpr('s');
    $sub = reportFilteredSalesSubquery($filters, $dates['from'], $dates['to'], "s.id, $day AS day, (s.total_amount - s.discount) AS net");
    $stmt = $pdo->prepare("
        SELECT day, SUM(net) AS revenue, COUNT(*) AS orders
        FROM ({$sub['sql']}) t GROUP BY day ORDER BY day ASC
    ");
    $stmt->execute($sub['params']);
    return $stmt->fetchAll();
}

function reportPaymentBreakdown(PDO $pdo, array $dates, array $filters): array {
    $sub = reportFilteredSalesSubquery($filters, $dates['from'], $dates['to'], 's.id, s.payment_method, (s.total_amount - s.discount) AS net');
    $stmt = $pdo->prepare("
        SELECT payment_method, COUNT(*) AS cnt, SUM(net) AS amount
        FROM ({$sub['sql']}) t GROUP BY payment_method ORDER BY amount DESC
    ");
    $stmt->execute($sub['params']);
    return $stmt->fetchAll();
}

function reportCategoryPerformance(PDO $pdo, array $dates, array $filters): array {
    $ctx = reportItemFilterContext($filters, $dates['from'], $dates['to']);
    $stmt = $pdo->prepare("
        SELECT COALESCE(c.name, 'Uncategorized') AS category,
               SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue
        FROM sale_items si
        {$ctx['joins']}
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE {$ctx['where']}
        GROUP BY c.id ORDER BY revenue DESC LIMIT 12
    ");
    $stmt->execute($ctx['params']);
    return $stmt->fetchAll();
}

function reportTopProducts(PDO $pdo, array $dates, array $filters, string $sort = 'qty', int $limit = 25, int $offset = 0): array {
    $ctx = reportItemFilterContext($filters, $dates['from'], $dates['to']);
    $orderMap = [
        'qty'    => 'qty_sold DESC',
        'rev'    => 'revenue DESC',
        'profit' => 'gross_profit DESC',
        'net'    => 'net_profit DESC',
        'margin' => 'profit_margin DESC',
    ];
    $order = $orderMap[$sort] ?? 'qty_sold DESC';

    $sql = "
        SELECT m.id, m.name, m.generic_name, m.barcode, m.sku,
               COALESCE(c.name, 'Uncategorized') AS category,
               COALESCE(m.product_type, c.product_type, 'medicine') AS product_type,
               SUM(si.quantity) AS qty_sold,
               SUM(si.subtotal) AS revenue,
               SUM(si.quantity * b.purchase_price) AS purchase_cost,
               SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS gross_profit,
               SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS net_profit,
               CASE WHEN SUM(si.subtotal) > 0
                    THEN (SUM(si.subtotal) - SUM(si.quantity * b.purchase_price)) / SUM(si.subtotal) * 100
                    ELSE 0 END AS profit_margin,
               COALESCE(st.stock, 0) AS current_stock
        FROM sale_items si
        {$ctx['joins']}
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN (SELECT medicine_id, SUM(quantity) AS stock FROM batches GROUP BY medicine_id) st ON st.medicine_id = m.id
        WHERE {$ctx['where']}
        GROUP BY m.id ORDER BY $order LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ctx['params']);
    return $stmt->fetchAll();
}

function reportProductDetail(PDO $pdo, int $medId, array $dates): ?array {
    $stmt = $pdo->prepare("
        SELECT m.*, COALESCE(c.name, 'Uncategorized') AS category_name,
               COALESCE(st.stock, 0) AS current_stock,
               COALESCE(st.avg_sell, 0) AS avg_sell_price,
               COALESCE(st.avg_buy, 0) AS avg_buy_price,
               COALESCE(st.inv_value, 0) AS inventory_value
        FROM medicines m
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN (
            SELECT medicine_id, SUM(quantity) AS stock,
                   AVG(selling_price) AS avg_sell, AVG(purchase_price) AS avg_buy,
                   SUM(quantity * purchase_price) AS inv_value
            FROM batches GROUP BY medicine_id
        ) st ON st.medicine_id = m.id
        WHERE m.id = ?
    ");
    $stmt->execute([$medId]);
    $med = $stmt->fetch();
    if (!$med) return null;

    $from = $dates['from'];
    $to   = $dates['to'];
    $day = reportLocalDateExpr('s');

    $perf = $pdo->prepare("
        SELECT SUM(si.quantity) AS qty_sold, SUM(si.subtotal) AS revenue,
               SUM(si.quantity * b.purchase_price) AS purchase_cost,
               COUNT(DISTINCT s.id) AS num_sales,
               COUNT(DISTINCT s.customer_name) AS num_customers,
               AVG(si.unit_price) AS avg_sell_price
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        JOIN batches b ON b.id = si.batch_id
        WHERE si.medicine_id = ? AND $day BETWEEN ? AND ?
    ");
    $perf->execute([$medId, $from, $to]);
    $perf = $perf->fetch();

    $trend = $pdo->prepare("
        SELECT $day AS day, SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue,
               SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS profit
        FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN batches b ON b.id = si.batch_id
        WHERE si.medicine_id = ? AND $day BETWEEN ? AND ?
        GROUP BY day ORDER BY day ASC
    ");
    $trend->execute([$medId, $from, $to]);
    $trend = $trend->fetchAll();

    // Weekly trend
    $trendWeekly = $pdo->prepare("
        SELECT strftime('%Y-W%W', $day) AS period,
               SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue,
               SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS profit
        FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN batches b ON b.id = si.batch_id
        WHERE si.medicine_id = ? AND $day BETWEEN ? AND ?
        GROUP BY period ORDER BY period ASC
    ");
    $trendWeekly->execute([$medId, $from, $to]);
    $trendWeekly = $trendWeekly->fetchAll();

    // Monthly trend
    $trendMonthly = $pdo->prepare("
        SELECT strftime('%Y-%m', $day) AS period,
               SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue,
               SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS profit
        FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN batches b ON b.id = si.batch_id
        WHERE si.medicine_id = ? AND $day BETWEEN ? AND ?
        GROUP BY period ORDER BY period ASC
    ");
    $trendMonthly->execute([$medId, $from, $to]);
    $trendMonthly = $trendMonthly->fetchAll();

    // Yearly trend
    $trendYearly = $pdo->prepare("
        SELECT strftime('%Y', $day) AS period,
               SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue,
               SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS profit
        FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN batches b ON b.id = si.batch_id
        WHERE si.medicine_id = ? AND $day BETWEEN ? AND ?
        GROUP BY period ORDER BY period ASC
    ");
    $trendYearly->execute([$medId, $from, $to]);
    $trendYearly = $trendYearly->fetchAll();

    $batches = $pdo->prepare("
        SELECT batch_number, expiry_date, quantity, purchase_price, selling_price
        FROM batches WHERE medicine_id = ? ORDER BY expiry_date ASC
    ");
    $batches->execute([$medId]);
    $batches = $batches->fetchAll();

    // Batch performance with sales data
    $batchPerf = $pdo->prepare("
        SELECT b.batch_number, b.expiry_date,
               COALESCE(b.quantity_received, b.quantity + COALESCE(sold.qty, 0)) AS qty_purchased,
               b.quantity AS remaining,
               COALESCE(sold.qty, 0) AS qty_sold,
               COALESCE(sold.sales_value, 0) AS sales_value,
               b.purchase_price, b.selling_price
        FROM batches b
        LEFT JOIN (
            SELECT batch_id, SUM(quantity) AS qty, SUM(subtotal) AS sales_value
            FROM sale_items GROUP BY batch_id
        ) sold ON sold.batch_id = b.id
        WHERE b.medicine_id = ?
        ORDER BY b.expiry_date ASC
    ");
    $batchPerf->execute([$medId]);
    $batchPerf = $batchPerf->fetchAll();

    $paySplit = $pdo->prepare("
        SELECT s.payment_method, SUM(si.subtotal) AS amount, SUM(si.quantity) AS qty
        FROM sale_items si JOIN sales s ON s.id = si.sale_id
        WHERE si.medicine_id = ? AND $day BETWEEN ? AND ?
        GROUP BY s.payment_method
    ");
    $paySplit->execute([$medId, $from, $to]);
    $paySplit = $paySplit->fetchAll();

    $topCust = $pdo->prepare("
        SELECT s.customer_name, SUM(si.quantity) AS qty, SUM(si.subtotal) AS spent
        FROM sale_items si JOIN sales s ON s.id = si.sale_id
        WHERE si.medicine_id = ? AND $day BETWEEN ? AND ?
          AND s.customer_name IS NOT NULL AND TRIM(s.customer_name) != ''
        GROUP BY s.customer_name ORDER BY spent DESC LIMIT 5
    ");
    $topCust->execute([$medId, $from, $to]);

    $supplier = $pdo->prepare("
        SELECT COALESCE(sup.name, 'Unknown') AS supplier_name, COUNT(*) AS purchase_count,
               AVG(b.purchase_price) AS avg_cost
        FROM batches b LEFT JOIN suppliers sup ON sup.id = b.supplier_id
        WHERE b.medicine_id = ? GROUP BY b.supplier_id ORDER BY purchase_count DESC LIMIT 1
    ");
    $supplier->execute([$medId]);

    $expStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM batches WHERE medicine_id=? AND expiry_date < date('now') AND quantity>0 AND expiry_date < '9000-01-01'");
    $expStmt->execute([$medId]);
    $expired = (int)$expStmt->fetchColumn();
    $nearStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM batches WHERE medicine_id=? AND expiry_date BETWEEN date('now') AND date('now','+30 days') AND quantity>0 AND expiry_date < '9000-01-01'");
    $nearStmt->execute([$medId]);
    $nearExpiry = (int)$nearStmt->fetchColumn();

    // Batch count
    $batchCountStmt = $pdo->prepare("SELECT COUNT(*) FROM batches WHERE medicine_id = ?");
    $batchCountStmt->execute([$medId]);
    $batchCount = (int)$batchCountStmt->fetchColumn();

    // Expiry summary
    $expirySummary = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN expiry_date < date('now') AND expiry_date < '9000-01-01' THEN quantity ELSE 0 END), 0) AS expired,
            COALESCE(SUM(CASE WHEN expiry_date BETWEEN date('now') AND date('now','+30 days') AND expiry_date < '9000-01-01' THEN quantity ELSE 0 END), 0) AS near_expiry,
            COALESCE(SUM(CASE WHEN expiry_date > date('now','+30 days') OR expiry_date >= '9000-01-01' THEN quantity ELSE 0 END), 0) AS good
        FROM batches WHERE medicine_id = ? AND quantity > 0
    ");
    $expirySummary->execute([$medId]);
    $expirySummary = $expirySummary->fetch();

    // Purchase history
    $purchasesHistory = $pdo->prepare("
        SELECT p.purchase_number, p.purchase_date, pi.quantity, pi.purchase_price, pi.selling_price,
               s.name AS supplier_name
        FROM purchase_items pi
        JOIN purchases p ON p.id = pi.purchase_id
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE pi.medicine_id = ?
        ORDER BY p.purchase_date DESC
        LIMIT 50
    ");
    $purchasesHistory->execute([$medId]);
    $purchasesHistory = $purchasesHistory->fetchAll();

    // Sales history
    $salesHistory = $pdo->prepare("
        SELECT s.invoice_number, $day AS sale_date,
               si.quantity, si.unit_price, si.subtotal, s.customer_name
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE si.medicine_id = ?
        ORDER BY s.created_at DESC
        LIMIT 50
    ");
    $salesHistory->execute([$medId]);
    $salesHistory = $salesHistory->fetchAll();

    // Returns history
    $returnsHistory = $pdo->prepare("
        SELECT sr.quantity, sr.amount, sr.reason, sr.created_at,
               s.invoice_number
        FROM sale_returns sr
        LEFT JOIN sales s ON s.id = sr.sale_id
        WHERE sr.medicine_id = ?
        ORDER BY sr.created_at DESC
        LIMIT 50
    ");
    $returnsHistory->execute([$medId]);
    $returnsHistory = $returnsHistory->fetchAll();

    // Stock changes (purchases +, sales -, returns +)
    $stockChanges = $pdo->prepare("
        SELECT 'Purchase' AS type, pi.quantity, p.purchase_date AS date, p.purchase_number AS reference
        FROM purchase_items pi
        JOIN purchases p ON p.id = pi.purchase_id
        WHERE pi.medicine_id = ?
        UNION ALL
        SELECT 'Sale' AS type, si.quantity, $day AS date, s.invoice_number AS reference
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE si.medicine_id = ?
        UNION ALL
        SELECT 'Return' AS type, sr.quantity, date(sr.created_at, '+3 hours') AS date, s.invoice_number AS reference
        FROM sale_returns sr
        LEFT JOIN sales s ON s.id = sr.sale_id
        WHERE sr.medicine_id = ?
        ORDER BY date DESC
        LIMIT 100
    ");
    $stockChanges->execute([$medId, $medId, $medId]);
    $stockChanges = $stockChanges->fetchAll();

    // Batch changes (when batches were created)
    $batchChanges = $pdo->prepare("
        SELECT b.batch_number, b.expiry_date,
               COALESCE(b.quantity_received, b.quantity) AS qty_purchased,
               b.created_at AS date,
               p.purchase_number AS reference,
               s.name AS supplier_name
        FROM batches b
        LEFT JOIN purchases p ON p.id = b.purchase_id
        LEFT JOIN suppliers s ON s.id = b.supplier_id
        WHERE b.medicine_id = ?
        ORDER BY b.created_at DESC
    ");
    $batchChanges->execute([$medId]);
    $batchChanges = $batchChanges->fetchAll();

    // Price history
    $priceHistory = $pdo->prepare("
        SELECT b.batch_number, b.purchase_price, b.selling_price, b.created_at,
               s.name AS supplier_name
        FROM batches b
        LEFT JOIN suppliers s ON s.id = b.supplier_id
        WHERE b.medicine_id = ?
        ORDER BY b.created_at ASC
    ");
    $priceHistory->execute([$medId]);
    $priceHistory = $priceHistory->fetchAll();

    $gross = (float)$perf['revenue'] - (float)$perf['purchase_cost'];
    $margin = (float)$perf['revenue'] > 0 ? ($gross / (float)$perf['revenue']) * 100 : 0;
    $days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
    $months = max(1, (int)($days / 30) + 1);
    $avgDaily = $days > 0 ? (float)$perf['qty_sold'] / $days : 0;

    return [
        'medicine'  => $med,
        'perf'      => $perf,
        'gross'     => $gross,
        'margin'    => $margin,
        'avg_monthly' => (float)$perf['qty_sold'] / $months,
        'avg_daily' => $avgDaily,
        'trend'     => $trend,
        'trend_weekly' => $trendWeekly,
        'trend_monthly' => $trendMonthly,
        'trend_yearly' => $trendYearly,
        'batches'   => $batches,
        'batch_performance' => $batchPerf,
        'payments'  => $paySplit,
        'top_customers' => $topCust->fetchAll(),
        'main_supplier' => $supplier->fetch(),
        'expired_stock' => $expired,
        'near_expiry_stock' => $nearExpiry,
        'batch_count' => $batchCount,
        'expiry_summary' => $expirySummary,
        'purchases_history' => $purchasesHistory,
        'sales_history' => $salesHistory,
        'returns_history' => $returnsHistory,
        'stock_changes' => $stockChanges,
        'batch_changes' => $batchChanges,
        'price_history' => $priceHistory,
    ];
}

function reportSearchProducts(PDO $pdo, string $q, int $limit = 20): array {
    if (strlen(trim($q)) < 1) return [];
    $like = '%' . trim($q) . '%';
    $stmt = $pdo->prepare("
        SELECT DISTINCT m.id, m.name, m.generic_name, m.barcode, m.sku,
               COALESCE(c.name, 'Uncategorized') AS category,
               COALESCE(m.product_type, c.product_type, 'medicine') AS product_type,
               COALESCE(st.stock, 0) AS current_stock
        FROM medicines m
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN batches b ON b.medicine_id = m.id
        LEFT JOIN (SELECT medicine_id, SUM(quantity) AS stock FROM batches GROUP BY medicine_id) st ON st.medicine_id = m.id
        WHERE m.name LIKE ? OR m.generic_name LIKE ? OR m.barcode LIKE ? OR m.sku LIKE ? OR b.batch_number LIKE ?
        ORDER BY m.name LIMIT $limit
    ");
    $stmt->execute([$like, $like, $like, $like, $like]);
    return $stmt->fetchAll();
}

function reportInsights(PDO $pdo, array $dates, array $filters): array {
    $insights = [];
    $from = $dates['from'];
    $to   = $dates['to'];
    $itemCtx = reportItemFilterContext($filters, $from, $to);

    $best = $pdo->prepare("
        SELECT m.name, SUM(si.quantity) AS qty
        FROM sale_items si
        {$itemCtx['joins']}
        WHERE {$itemCtx['where']}
        GROUP BY m.id ORDER BY qty DESC LIMIT 1
    ");
    $best->execute($itemCtx['params']);
    if ($row = $best->fetch()) {
        $insights[] = ['type' => 'success', 'icon' => 'trophy', 'text' => "Best seller: <strong>{$row['name']}</strong> with " . number_format($row['qty']) . " units sold."];
    }

    $slow = $pdo->prepare("
        SELECT m.name FROM medicines m
        JOIN batches b ON b.medicine_id = m.id AND b.quantity > 0
        WHERE m.id NOT IN (
            SELECT DISTINCT si.medicine_id FROM sale_items si
            {$itemCtx['joins']}
            WHERE {$itemCtx['where']}
        ) GROUP BY m.id LIMIT 1
    ");
    $slow->execute($itemCtx['params']);
    if ($row = $slow->fetch()) {
        $insights[] = ['type' => 'warning', 'icon' => 'snail', 'text' => "Slow mover alert: <strong>{$row['name']}</strong> has stock but no sales in this period."];
    }

    $runout = $pdo->query("
        SELECT m.name, COALESCE(SUM(b.quantity),0) AS stock
        FROM medicines m JOIN batches b ON b.medicine_id = m.id
        GROUP BY m.id HAVING stock > 0 AND stock <= 5
        ORDER BY stock ASC LIMIT 1
    ")->fetch();
    if ($runout) {
        $insights[] = ['type' => 'danger', 'icon' => 'alert-triangle', 'text' => "Low stock warning: <strong>{$runout['name']}</strong> may run out soon ({$runout['stock']} left)."];
    }

    $exp = $pdo->query("
        SELECT m.name, b.expiry_date, b.quantity
        FROM batches b JOIN medicines m ON m.id = b.medicine_id
        WHERE b.expiry_date BETWEEN date('now') AND date('now','+14 days') AND b.quantity > 0
          AND b.expiry_date < '9000-01-01'
        ORDER BY b.expiry_date ASC LIMIT 1
    ")->fetch();
    if ($exp) {
        $insights[] = ['type' => 'warning', 'icon' => 'calendar-clock', 'text' => "Expiring soon: <strong>{$exp['name']}</strong> ({$exp['quantity']} units) by " . date('M j', strtotime($exp['expiry_date'])) . "."];
    }

    $topProfit = $pdo->prepare("
        SELECT m.name, SUM(si.subtotal - si.quantity * b.purchase_price) AS profit
        FROM sale_items si
        {$itemCtx['joins']}
        WHERE {$itemCtx['where']}
        GROUP BY m.id ORDER BY profit DESC LIMIT 1
    ");
    $topProfit->execute($itemCtx['params']);
    if ($row = $topProfit->fetch()) {
        $insights[] = ['type' => 'success', 'icon' => 'trending-up', 'text' => "Highest profit product: <strong>{$row['name']}</strong> (" . getSetting('currency','ETB') . ' ' . number_format($row['profit'], 0) . ")."];
    }

    $topCat = $pdo->prepare("
        SELECT COALESCE(c.name,'Uncategorized') AS cat, SUM(si.subtotal) AS rev
        FROM sale_items si
        {$itemCtx['joins']}
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE {$itemCtx['where']}
        GROUP BY c.id ORDER BY rev DESC LIMIT 1
    ");
    $topCat->execute($itemCtx['params']);
    if ($row = $topCat->fetch()) {
        $insights[] = ['type' => 'info', 'icon' => 'layers', 'text' => "Top category: <strong>{$row['cat']}</strong> leading revenue this period."];
    }

    $kpis = reportOverviewKpis($pdo, $dates, $filters);
    $chg = $kpis['revenue']['change'] ?? 0;
    $dir = $chg >= 0 ? 'up' : 'down';
    $insights[] = ['type' => $chg >= 0 ? 'success' : 'warning', 'icon' => 'activity',
        'text' => "Sales trend is <strong>$dir " . number_format(abs($chg), 1) . "%</strong> vs the previous period."];

    return $insights;
}

function reportSlowMovingProducts(PDO $pdo, array $dates, array $filters, int $limit = 50): array {
    $day = reportLocalDateExpr('s');
    $from = $dates['from'];
    $to = $dates['to'];

    $extraWhere = '';
    $extraParams = [];
    if ($filters['category']) {
        $extraWhere .= ' AND m.category_id = ?';
        $extraParams[] = $filters['category'];
    }
    if ($filters['supplier']) {
        $extraWhere .= ' AND EXISTS (SELECT 1 FROM batches sb WHERE sb.medicine_id = m.id AND sb.supplier_id = ?)';
        $extraParams[] = $filters['supplier'];
    }

    $sql = "
        SELECT m.id, m.name, COALESCE(c.name, 'Uncategorized') AS category,
               COALESCE(m.product_type, c.product_type, 'medicine') AS product_type,
               COALESCE(stock.total, 0) AS current_stock,
               COALESCE(sold.qty, 0) AS units_sold,
               sold.last_sale_date,
               COALESCE(stock.total, 0) * COALESCE(stock.avg_cost, 0) AS stock_value
        FROM medicines m
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN (
            SELECT medicine_id, SUM(quantity) AS total, AVG(purchase_price) AS avg_cost
            FROM batches WHERE quantity > 0 GROUP BY medicine_id
        ) stock ON stock.medicine_id = m.id
        LEFT JOIN (
            SELECT si.medicine_id, SUM(si.quantity) AS qty, MAX($day) AS last_sale_date
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            WHERE $day BETWEEN ? AND ?
            GROUP BY si.medicine_id
        ) sold ON sold.medicine_id = m.id
        WHERE COALESCE(stock.total, 0) > 0 $extraWhere
        ORDER BY COALESCE(sold.qty, 0) ASC, stock.total DESC
        LIMIT $limit
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$from, $to], $extraParams));
    return $stmt->fetchAll();
}

function reportExportCsv(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

function reportExportExcel(string $filename, array $headers, array $rows): void {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo '<html><head><meta charset="UTF-8"></head><body><table border="1">';
    echo '<tr>';
    foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
    echo '</tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) echo '<td>' . htmlspecialchars((string)$cell) . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}

function reportNavItems(): array {
    return [
        ['page' => 'reports',           'label' => 'Overview',           'icon' => 'layout-dashboard'],
        ['page' => 'report_sales',      'label' => 'Sales',              'icon' => 'shopping-cart'],
        ['page' => 'report_products',   'label' => 'Products',           'icon' => 'pill'],
        ['page' => 'report_payments',   'label' => 'Payments',         'icon' => 'wallet'],
        ['page' => 'report_credit',     'label' => 'Credit',             'icon' => 'credit-card'],
        ['page' => 'report_inventory',  'label' => 'Inventory',          'icon' => 'boxes'],
        ['page' => 'report_profit',     'label' => 'Profit',             'icon' => 'trending-up'],
        ['page' => 'report_customers',  'label' => 'Customers',          'icon' => 'users'],
        ['page' => 'report_purchases',  'label' => 'Purchases',          'icon' => 'package-open'],
    ];
}

/* ─── SALES REPORT (report_sales.php) ────────────────────────────────────── */

/** Date presets offered on the Sales Report page (Today / This Week / This Month / Last 30 Days / This Year / Custom). */
function reportSalesDatePresets(): array {
    return [
        'today'      => 'Today',
        'this_week'  => 'This Week',
        'this_month' => 'This Month',
        'last30'     => 'Last 30 Days',
        'this_year'  => 'This Year',
        'custom'     => 'Custom Range',
    ];
}

/** Format an ISO 'YYYY-Www' weekly label as the Monday date of that week. */
function reportWeeklyLabel(string $yw): string {
    if (preg_match('/^(\d{4})-W(\d{2})$/', $yw, $m)) {
        $d = new DateTime();
        $d->setISODate((int)$m[1], (int)$m[2], 1);
        return $d->format('M j');
    }
    return $yw;
}

/**
 * Main summary KPIs for the Sales Report: revenue (gross), transactions,
 * units sold, average sale value, discount, tax, returns and net sales.
 * Net sales follows the app-wide convention: total_amount - discount.
 */
function reportSalesSummary(PDO $pdo, array $dates, array $filters): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $day  = reportLocalDateExpr('s');
    $ctx  = reportBuildSalesContext($filters);
    $extra = $ctx['where'] ? ' AND ' . implode(' AND ', $ctx['where']) : '';
    $params = array_merge([$from, $to], $ctx['params']);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS transactions,
               COALESCE(SUM(total_amount), 0) AS revenue,
               COALESCE(SUM(discount), 0) AS discount,
               COALESCE(SUM(tax), 0) AS tax,
               COALESCE(SUM(total_amount - discount), 0) AS net
        FROM (
            SELECT DISTINCT s.id, s.total_amount, s.discount, s.tax
            FROM sales s {$ctx['joins']}
            WHERE $day BETWEEN ? AND ? $extra
        ) t
    ");
    $stmt->execute($params);
    $s = $stmt->fetch();

    $itemCtx = reportItemFilterContext($filters, $from, $to);
    $units = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(si.quantity), 0)
        FROM sale_items si
        {$itemCtx['joins']}
        WHERE {$itemCtx['where']}
    ", $itemCtx['params']);

    $returns = reportSalesReturns($pdo, $dates, $filters);

    $transactions = (int)$s['transactions'];
    $net          = (float)$s['net'];
    return [
        'revenue'      => (float)$s['revenue'],
        'transactions' => $transactions,
        'units'        => $units,
        'avg_sale'     => $transactions > 0 ? $net / $transactions : 0.0,
        'discount'     => (float)$s['discount'],
        'tax'          => (float)$s['tax'],
        'returns'      => (float)$returns['amount'],
        'net'          => $net,
    ];
}

/** Sales revenue over time, grouped by day / ISO week / month / year (net = total_amount - discount). */
function reportSalesTrend(PDO $pdo, array $dates, array $filters, string $view = 'daily'): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $day  = reportLocalDateExpr('s');
    $ctx  = reportBuildSalesContext($filters);
    $extra = $ctx['where'] ? ' AND ' . implode(' AND ', $ctx['where']) : '';

    $labelExpr = match ($view) {
        'weekly'  => "strftime('%Y-W%W', datetime(s.created_at, '+3 hours'))",
        'monthly' => "strftime('%Y-%m', datetime(s.created_at, '+3 hours'))",
        'yearly'  => "strftime('%Y', datetime(s.created_at, '+3 hours'))",
        default   => $day,
    };

    $stmt = $pdo->prepare("
        SELECT label, COUNT(*) AS transactions, SUM(net) AS revenue
        FROM (
            SELECT DISTINCT s.id, $labelExpr AS label, (s.total_amount - s.discount) AS net
            FROM sales s {$ctx['joins']}
            WHERE $day BETWEEN ? AND ? $extra
        ) t
        GROUP BY label ORDER BY label ASC
    ");
    $stmt->execute(array_merge([$from, $to], $ctx['params']));
    return $stmt->fetchAll();
}

/** Per-day sales breakdown: transactions, units, revenue (gross), discount, tax, net sales. */
function reportSalesDaily(PDO $pdo, array $dates, array $filters): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $day  = reportLocalDateExpr('s');
    $ctx  = reportBuildSalesContext($filters);
    $extra = $ctx['where'] ? ' AND ' . implode(' AND ', $ctx['where']) : '';
    $params = array_merge([$from, $to], $ctx['params']);

    $stmt = $pdo->prepare("
        SELECT day, COUNT(*) AS transactions,
               SUM(total_amount) AS revenue,
               SUM(discount) AS discount,
               SUM(tax) AS tax,
               SUM(total_amount - discount) AS net
        FROM (
            SELECT DISTINCT s.id, $day AS day, s.total_amount, s.discount, s.tax
            FROM sales s {$ctx['joins']}
            WHERE $day BETWEEN ? AND ? $extra
        ) t
        GROUP BY day ORDER BY day DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $itemCtx = reportItemFilterContext($filters, $from, $to);
    $uStmt = $pdo->prepare("
        SELECT " . reportLocalDateExpr('s') . " AS day, SUM(si.quantity) AS units
        FROM sale_items si
        {$itemCtx['joins']}
        WHERE {$itemCtx['where']}
        GROUP BY day
    ");
    $uStmt->execute($itemCtx['params']);
    $unitsByDay = [];
    foreach ($uStmt->fetchAll() as $u) $unitsByDay[$u['day']] = (float)$u['units'];
    foreach ($rows as &$r) $r['units'] = $unitsByDay[$r['day']] ?? 0.0;
    unset($r);
    return $rows;
}

/** Payment report: Cash, CBE, Abyssinia, Telebirr and Credit with txns, amount and % of total. */
function reportSalesPayments(PDO $pdo, array $dates, array $filters): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $sub = reportFilteredSalesSubquery($filters, $from, $to, 's.id, s.payment_method, (s.total_amount - s.discount) AS net');
    $stmt = $pdo->prepare("
        SELECT payment_method, COUNT(*) AS transactions, SUM(net) AS amount
        FROM ({$sub['sql']}) t GROUP BY payment_method
    ");
    $stmt->execute($sub['params']);

    $byMethod = [];
    foreach ($stmt->fetchAll() as $r) $byMethod[$r['payment_method']] = $r;

    $out = [];
    foreach (posPaymentMethods() as $key => $label) {
        $row = $byMethod[$key] ?? null;
        $out[] = [
            'method'       => $key,
            'label'        => $label,
            'transactions' => (int)($row['transactions'] ?? 0),
            'amount'       => (float)($row['amount'] ?? 0),
        ];
    }
    $total = array_sum(array_column($out, 'amount'));
    foreach ($out as &$r) $r['pct'] = $total > 0 ? ($r['amount'] / $total) * 100 : 0.0;
    unset($r);
    return $out;
}

/** Customer sales: transactions, units, total spent and credit amount per customer. */
function reportSalesCustomers(PDO $pdo, array $dates, array $filters): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $day  = reportLocalDateExpr('s');
    $label = "COALESCE(NULLIF(TRIM(s.customer_name), ''), cust.full_name, 'Unknown')";
    $ctx  = reportBuildSalesContext($filters);
    $extra = $ctx['where'] ? ' AND ' . implode(' AND ', $ctx['where']) : '';

    $stmt = $pdo->prepare("
        SELECT label, COUNT(*) AS transactions,
               SUM(net) AS spent,
               COALESCE(SUM(CASE WHEN is_credit THEN net END), 0) AS credit_amount
        FROM (
            SELECT DISTINCT s.id, $label AS label,
                   (s.total_amount - s.discount) AS net,
                   CASE WHEN (s.sale_type = 'credit' OR s.payment_method = 'credit') THEN 1 ELSE 0 END AS is_credit
            FROM sales s {$ctx['joins']}
            WHERE $day BETWEEN ? AND ? $extra
        ) t
        GROUP BY label ORDER BY spent DESC
    ");
    $stmt->execute(array_merge([$from, $to], $ctx['params']));
    $rows = $stmt->fetchAll();

    $itemCtx = reportItemFilterContext($filters, $from, $to);
    $custJoin = str_contains($itemCtx['joins'], 'customers cust') ? '' : "\nLEFT JOIN customers cust ON cust.id = s.customer_id";
    $uStmt = $pdo->prepare("
        SELECT $label AS label, SUM(si.quantity) AS units
        FROM sale_items si
        {$itemCtx['joins']}$custJoin
        WHERE {$itemCtx['where']}
        GROUP BY label
    ");
    $uStmt->execute($itemCtx['params']);
    $unitsByLabel = [];
    foreach ($uStmt->fetchAll() as $u) $unitsByLabel[$u['label']] = (float)$u['units'];
    foreach ($rows as &$r) $r['units'] = $unitsByLabel[$r['label']] ?? 0.0;
    unset($r);
    return $rows;
}

/** Cashier sales: transactions, units sold, total sales, discounts and net sales per cashier. */
function reportSalesCashiers(PDO $pdo, array $dates, array $filters): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $day  = reportLocalDateExpr('s');
    $ctx  = reportBuildSalesContext($filters);
    $extra = $ctx['where'] ? ' AND ' . implode(' AND ', $ctx['where']) : '';
    $joins = $ctx['joins'] . "\nLEFT JOIN users u ON u.id = s.user_id";

    $stmt = $pdo->prepare("
        SELECT t.label,
               COUNT(*) AS transactions,
               SUM(t.total_amount) AS total_sales,
               SUM(t.discount) AS discount,
               SUM(t.total_amount - t.discount) AS net
        FROM (
            SELECT DISTINCT s.id, s.user_id, COALESCE(u.full_name, 'Unknown') AS label,
                   s.total_amount, s.discount
            FROM sales s $joins
            WHERE $day BETWEEN ? AND ? $extra
        ) t
        GROUP BY t.user_id ORDER BY net DESC
    ");
    $stmt->execute(array_merge([$from, $to], $ctx['params']));
    $rows = $stmt->fetchAll();

    $itemCtx = reportItemFilterContext($filters, $from, $to);
    $uStmt = $pdo->prepare("
        SELECT COALESCE(u.full_name, 'Unknown') AS label, SUM(si.quantity) AS units
        FROM sale_items si
        {$itemCtx['joins']}
        LEFT JOIN users u ON u.id = s.user_id
        WHERE {$itemCtx['where']}
        GROUP BY label
    ");
    $uStmt->execute($itemCtx['params']);
    $unitsByLabel = [];
    foreach ($uStmt->fetchAll() as $u) $unitsByLabel[$u['label']] = (float)$u['units'];
    foreach ($rows as &$r) $r['units'] = $unitsByLabel[$r['label']] ?? 0.0;
    unset($r);
    return $rows;
}

/**
 * Classify a product/category into a product-type bucket.
 * The explicit product_type wins; otherwise fall back to category-name
 * heuristics, then default to medicine.
 */
function reportCategoryType(?string $productType, ?string $catType, ?string $catName): string {
    $t = strtolower(trim((string)($productType ?? $catType ?? '')));
    if (in_array($t, ['medicine', 'cosmetic', 'equipment'], true)) return $t;
    $n = strtolower(trim((string)$catName));
    if ($n === '' || $n === 'uncategorized' || $n === 'other') return 'other';
    if (strpos($n, 'cosmetic') !== false) return 'cosmetic';
    if (strpos($n, 'equipment') !== false || strpos($n, 'device') !== false) return 'equipment';
    return 'medicine';
}

/**
 * Display label for a product type bucket.
 */
function productTypeLabel(?string $type): string {
    return ['medicine' => 'Medicine', 'cosmetic' => 'Cosmetics', 'equipment' => 'Equipment', 'other' => 'Other'][strtolower((string)$type)] ?? 'Medicine';
}

/**
 * Category sales detail: one row per category tagged with its product type
 * (Medicine / Cosmetics / Equipment / Other), with transactions, units,
 * revenue and profit (revenue - cost of goods sold).
 */
function reportSalesCategories(PDO $pdo, array $dates, array $filters): array {
    $itemCtx = reportItemFilterContext($filters, $dates['from'], $dates['to']);
    $stmt = $pdo->prepare("
        SELECT COALESCE(c.name, 'Uncategorized') AS category,
               COALESCE(m.product_type, c.product_type, '') AS mtype,
               COUNT(DISTINCT s.id) AS transactions,
               SUM(si.quantity) AS units,
               SUM(si.subtotal) AS revenue,
               SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS profit
        FROM sale_items si
        {$itemCtx['joins']}
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE {$itemCtx['where']}
        GROUP BY m.category_id
    ");
    $stmt->execute($itemCtx['params']);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $type = reportCategoryType($r['mtype'] ?? null, null, $r['category']);
        $rows[] = [
            'type'         => $type,
            'type_label'   => productTypeLabel($type),
            'category'     => $r['category'],
            'transactions' => (int)$r['transactions'],
            'units'        => (float)$r['units'],
            'revenue'      => (float)$r['revenue'],
            'profit'       => (float)$r['profit'],
        ];
    }
    // Order groups Medicine → Cosmetics → Equipment → Other, revenue desc within.
    $order = ['medicine' => 0, 'cosmetic' => 1, 'equipment' => 2, 'other' => 3];
    usort($rows, function ($a, $b) use ($order) {
        $o = ($order[$a['type']] ?? 9) <=> ($order[$b['type']] ?? 9);
        return $o !== 0 ? $o : ($b['revenue'] <=> $a['revenue']);
    });
    return $rows;
}

/** Returns summary: returned transactions, returned units and returned amount. */
function reportSalesReturns(PDO $pdo, array $dates, array $filters): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $where  = ["date(sr.created_at, '+3 hours') BETWEEN ? AND ?"];
    $params = [$from, $to];
    $joins  = [];

    if ($filters['category']) {
        $joins[] = 'JOIN medicines mr ON mr.id = sr.medicine_id';
        $where[] = 'mr.category_id = ?';
        $params[] = $filters['category'];
    }
    if ($filters['customer']) {
        $joins[] = 'LEFT JOIN customers cust ON cust.id = s.customer_id';
        $where[] = "(COALESCE(NULLIF(TRIM(s.customer_name), ''), cust.full_name) LIKE ? OR cust.phone LIKE ?)";
        $like = '%' . $filters['customer'] . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($filters['cashier']) {
        $where[] = 's.user_id = ?';
        $params[] = $filters['cashier'];
    }
    if ($filters['payment_method']) {
        $where[] = 's.payment_method = ?';
        $params[] = $filters['payment_method'];
    }
    if ($filters['sales_type'] === 'credit') {
        $where[] = "(s.sale_type = 'credit' OR s.payment_method = 'credit')";
    } elseif ($filters['sales_type'] === 'cash') {
        $where[] = "(s.sale_type = 'cash' OR (s.payment_method IS NOT NULL AND s.payment_method != 'credit' AND COALESCE(s.sale_type, 'cash') != 'credit'))";
    } elseif ($filters['sales_type']) {
        $where[] = 's.sale_type = ?';
        $params[] = $filters['sales_type'];
    }
    if ($filters['branch']) {
        $where[] = 's.branch_id = ?';
        $params[] = $filters['branch'];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sr.sale_id) AS transactions,
               COALESCE(SUM(sr.quantity), 0) AS units,
               COALESCE(SUM(sr.amount), 0) AS amount
        FROM sale_returns sr
        JOIN sales s ON s.id = sr.sale_id
        " . ($joins ? implode("\n", array_unique($joins)) : '') . "
        WHERE " . implode(' AND ', $where) . "
    ");
    $stmt->execute($params);
    $r = $stmt->fetch();
    return [
        'transactions' => (int)$r['transactions'],
        'units'        => (float)$r['units'],
        'amount'       => (float)$r['amount'],
    ];
}

/** Sales history rows (every sale matching the filters), newest first. No expiry/stock details. */
function reportSalesHistory(PDO $pdo, array $dates, array $filters, int $limit = 50, int $offset = 0): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $day  = reportLocalDateExpr('s');
    $ctx  = reportBuildSalesContext($filters);
    $extra = $ctx['where'] ? ' AND ' . implode(' AND ', $ctx['where']) : '';
    $joins = $ctx['joins'] . "\nLEFT JOIN users u ON u.id = s.user_id";
    $itemJoin = str_contains($ctx['joins'], 'JOIN sale_items si') ? '' : "\nLEFT JOIN sale_items si ON si.sale_id = s.id";

    $stmt = $pdo->prepare("
        SELECT s.id, s.invoice_number, s.created_at, s.customer_name, s.customer_id,
               s.total_amount, s.discount, s.tax, s.paid_amount, s.payment_method,
               s.payment_status, s.sale_type,
               COALESCE(u.full_name, 'Unknown') AS cashier,
               COUNT(si.id) AS items,
               COALESCE(SUM(si.quantity), 0) AS units
        FROM sales s
        $joins$itemJoin
        WHERE $day BETWEEN ? AND ? $extra
        GROUP BY s.id
        ORDER BY s.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute(array_merge([$from, $to], $ctx['params']));
    return $stmt->fetchAll();
}

function reportSalesHistoryCount(PDO $pdo, array $dates, array $filters): int {
    $from = $dates['from'];
    $to   = $dates['to'];
    $day  = reportLocalDateExpr('s');
    $ctx  = reportBuildSalesContext($filters);
    $extra = $ctx['where'] ? ' AND ' . implode(' AND ', $ctx['where']) : '';
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM (
            SELECT DISTINCT s.id FROM sales s {$ctx['joins']}
            WHERE $day BETWEEN ? AND ? $extra
        ) t
    ");
    $stmt->execute(array_merge([$from, $to], $ctx['params']));
    return (int)$stmt->fetchColumn();
}

/* ─── INVENTORY REPORT (report_inventory.php) ────────────────────────────── */

/** Top-level inventory category buckets shown throughout the Inventory Report. */
function reportInventoryBuckets(): array {
    return [
        'medication'        => 'Medication',
        'cosmetic'          => 'Cosmetic',
        'medical_equipment' => 'Medical Equipment',
        'medical_supply'    => 'Medical Supply',
        'supplement'        => 'Supplement',
        'personal_care'     => 'Personal Care',
        'other'             => 'Other',
    ];
}

function reportInventoryBucketLabel(?string $key): string {
    $b = reportInventoryBuckets();
    return $b[$key] ?? 'Other';
}

/**
 * SQL CASE that classifies a product (aliases m / c) into one of the inventory
 * buckets: Medication, Cosmetic, Medical Equipment, Medical Supply, Supplement,
 * Personal Care or Other.
 */
function reportInventoryBucketExpr(string $m = 'm', string $c = 'c'): string {
    return "CASE
        WHEN LOWER(COALESCE($m.product_type, $c.product_type, '')) = 'cosmetic' THEN 'cosmetic'
        WHEN LOWER(COALESCE($m.product_type, $c.product_type, '')) = 'equipment' THEN 'medical_equipment'
        WHEN LOWER(COALESCE($c.name, '')) LIKE '%cosmetic%' THEN 'cosmetic'
        WHEN LOWER(COALESCE($c.name, '')) LIKE '%equipment%' OR LOWER(COALESCE($c.name, '')) LIKE '%device%' THEN 'medical_equipment'
        WHEN LOWER(COALESCE($c.name, '')) LIKE '%supplement%' OR LOWER(COALESCE($c.name, '')) LIKE '%vitamin%' THEN 'supplement'
        WHEN LOWER(COALESCE($c.name, '')) LIKE '%personal care%' OR LOWER(COALESCE($c.name, '')) LIKE '%hygiene%' OR LOWER(COALESCE($c.name, '')) LIKE '%body care%' THEN 'personal_care'
        WHEN LOWER(COALESCE($c.name, '')) LIKE '%supply%' OR LOWER(COALESCE($c.name, '')) LIKE '%consumable%' OR LOWER(COALESCE($c.name, '')) LIKE '%disposable%' THEN 'medical_supply'
        WHEN LOWER(COALESCE($c.name, '')) = 'other' OR $m.category_id IS NULL THEN 'other'
        ELSE 'medication'
    END";
}

/** Parse the Inventory Report's own filter set (product, bucket category, supplier, brand, batch, stock, expiry). */
function reportInventoryFilters(array $input): array {
    $validStock = ['all', 'in', 'low', 'out', 'over', 'fast', 'slow', 'dead'];
    $validExpiry = ['all', 'expiring', 'expired', 'safe'];
    $stock = trim((string)($input['stock'] ?? 'all'));
    $expiry = trim((string)($input['expiry'] ?? 'all'));
    $cat = trim((string)($input['category'] ?? ''));
    return [
        'product'  => (int)($input['product'] ?? 0),
        'category' => isset(reportInventoryBuckets()[$cat]) ? $cat : '',
        'supplier' => (int)($input['supplier'] ?? 0),
        'brand'    => trim((string)($input['brand'] ?? '')),
        'batch'    => trim((string)($input['batch'] ?? '')),
        'stock'    => in_array($stock, $validStock, true) ? $stock : 'all',
        'expiry'   => in_array($expiry, $validExpiry, true) ? $expiry : 'all',
    ];
}

/** Build the URL query string for the Inventory Report's filters. */
function reportInventoryQueryString(array $dates, array $f, array $extra = []): string {
    $qs = array_filter([
        'preset'   => $dates['preset'],
        'from'     => $dates['from'],
        'to'       => $dates['to'],
        'product'  => $f['product'] ?: null,
        'category' => $f['category'] ?: null,
        'supplier' => $f['supplier'] ?: null,
        'brand'    => $f['brand'] ?: null,
        'batch'    => $f['batch'] ?: null,
        'stock'    => ($f['stock'] ?? 'all') !== 'all' ? $f['stock'] : null,
        'expiry'   => ($f['expiry'] ?? 'all') !== 'all' ? $f['expiry'] : null,
    ], fn($v) => $v !== null && $v !== '');
    return http_build_query(array_merge($qs, $extra));
}

/** Medicine-level WHERE for inventory filters (aliases m = medicines, c = categories). */
function reportInventoryMedWhere(array $f): array {
    $and = [];
    $params = [];
    if ($f['product'])      { $and[] = 'm.id = ?';                            $params[] = $f['product']; }
    if ($f['category'])     { $and[] = reportInventoryBucketExpr() . ' = ?';  $params[] = $f['category']; }
    if ($f['supplier'])     { $and[] = 'EXISTS (SELECT 1 FROM batches sb WHERE sb.medicine_id = m.id AND sb.supplier_id = ?)'; $params[] = $f['supplier']; }
    if ($f['brand'] !== '') { $and[] = 'm.name LIKE ?';                       $params[] = '%' . $f['brand'] . '%'; }
    if ($f['batch'] !== '') { $and[] = 'EXISTS (SELECT 1 FROM batches bb WHERE bb.medicine_id = m.id AND bb.batch_number LIKE ?)'; $params[] = '%' . $f['batch'] . '%'; }
    return ['sql' => $and ? (' AND ' . implode(' AND ', $and)) : '', 'params' => $params];
}

/** Batch-level WHERE for inventory expiry queries (aliases b = batches, m = medicines, c = categories). */
function reportInventoryBatchWhere(array $f): array {
    $and = [];
    $params = [];
    if ($f['product'])      { $and[] = 'b.medicine_id = ?';                   $params[] = $f['product']; }
    if ($f['category'])     { $and[] = reportInventoryBucketExpr() . ' = ?';  $params[] = $f['category']; }
    if ($f['supplier'])     { $and[] = 'b.supplier_id = ?';                   $params[] = $f['supplier']; }
    if ($f['brand'] !== '') { $and[] = 'm.name LIKE ?';                       $params[] = '%' . $f['brand'] . '%'; }
    if ($f['batch'] !== '') { $and[] = 'b.batch_number LIKE ?';               $params[] = '%' . $f['batch'] . '%'; }
    return ['sql' => $and ? (' AND ' . implode(' AND ', $and)) : '', 'params' => $params];
}

/** Stock status for a product row (out / low / in / over). */
function inventoryStockStatus(array $p): string {
    $stock = (float)($p['stock'] ?? 0);
    $reorder = max(1, (int)($p['reorder_level'] ?? 0));
    if ($stock <= 0)     return 'out';
    if ($stock <= $reorder) return 'low';
    if ($stock > $reorder * 3) return 'over';
    return 'in';
}

function inventoryStockStatusLabel(string $key): string {
    return ['in' => 'In Stock', 'low' => 'Low Stock', 'out' => 'Out of Stock', 'over' => 'Overstock'][$key] ?? ucfirst($key);
}

function inventoryStockStatusBadge(string $key): string {
    return ['in' => 'badge-green', 'low' => 'badge-orange', 'out' => 'badge-red', 'over' => 'badge-blue'][$key] ?? 'badge-gray';
}

/** Expiry status for a batch row, given its expiry date and days remaining. */
function reportInventoryExpiryStatus(?string $expiryDate, ?int $days): array {
    $expiry = (string)$expiryDate;
    if ($expiry === '' || $expiry >= '9000-01-01') {
        return ['key' => 'safe', 'label' => 'No Expiry', 'badge' => 'badge-gray', 'days' => null];
    }
    if ($days < 0) {
        return ['key' => 'expired', 'label' => 'EXPIRED', 'badge' => 'badge-red', 'days' => $days];
    }
    if ($days <= 30) {
        return ['key' => 'expiring', 'label' => $days === 0 ? 'Expiring today' : "$days days remaining", 'badge' => 'badge-orange', 'days' => $days];
    }
    if ($days <= 90) {
        return ['key' => 'expiring', 'label' => "$days days remaining", 'badge' => 'badge-blue', 'days' => $days];
    }
    return ['key' => 'safe', 'label' => 'Safe', 'badge' => 'badge-green', 'days' => $days];
}

/**
 * One row per product with current stock, values and movement, respecting the
 * Inventory Report's base filters (product / category bucket / supplier / brand / batch).
 */
function reportInventoryProducts(PDO $pdo, array $dates, array $f): array {
    $mw = reportInventoryMedWhere($f);
    $day = reportLocalDateExpr('s');
    $sql = "
        SELECT m.id, m.name, m.generic_name, m.unit, m.barcode, m.sku,
               COALESCE(m.reorder_level, 10) AS reorder_level,
               COALESCE(c.name, 'Uncategorized') AS category,
               " . reportInventoryBucketExpr() . " AS bucket,
               COALESCE(st.stock, 0) AS stock,
               COALESCE(st.avg_cost, 0) AS avg_cost,
               COALESCE(st.avg_sell, 0) AS avg_sell,
               COALESCE(st.stock * st.avg_cost, 0) AS cost_value,
               COALESCE(st.stock * st.avg_sell, 0) AS retail_value,
               COALESCE(sold.qty, 0) AS qty_sold,
               sold.last_sale,
               COALESCE(purch.qty, 0) AS units_purchased,
               purch.last_purchase,
               COALESCE(r.qty, 0) AS units_returned
        FROM medicines m
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN (
            SELECT medicine_id, SUM(quantity) AS stock,
                   AVG(purchase_price) AS avg_cost, AVG(selling_price) AS avg_sell
            FROM batches GROUP BY medicine_id
        ) st ON st.medicine_id = m.id
        LEFT JOIN (
            SELECT si.medicine_id, SUM(si.quantity) AS qty, MAX($day) AS last_sale
            FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE $day BETWEEN ? AND ?
            GROUP BY si.medicine_id
        ) sold ON sold.medicine_id = m.id
        LEFT JOIN (
            SELECT medicine_id, COALESCE(SUM(COALESCE(quantity_received, quantity)), 0) AS qty,
                   MAX(created_at) AS last_purchase
            FROM batches GROUP BY medicine_id
        ) purch ON purch.medicine_id = m.id
        LEFT JOIN (
            SELECT medicine_id, COALESCE(SUM(quantity), 0) AS qty
            FROM sale_returns GROUP BY medicine_id
        ) r ON r.medicine_id = m.id
        WHERE 1=1 {$mw['sql']}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$dates['from'], $dates['to']], $mw['params']));
    return $stmt->fetchAll();
}

/** One row per in-stock batch with expiry information (respects base filters). */
function reportInventoryExpiryRows(PDO $pdo, array $dates, array $f): array {
    $bw = reportInventoryBatchWhere($f);
    $stmt = $pdo->prepare("
        SELECT m.id AS medicine_id, m.name, m.unit,
               b.batch_number, b.quantity, b.expiry_date, b.manufacture_date,
               b.purchase_price, b.selling_price,
               COALESCE(sup.name, '—') AS supplier_name,
               CAST(julianday(b.expiry_date) - julianday('now') AS INTEGER) AS days_remaining,
               b.quantity * b.purchase_price AS cost_value,
               b.quantity * b.selling_price AS retail_value,
               " . reportInventoryBucketExpr() . " AS bucket
        FROM batches b
        JOIN medicines m ON m.id = b.medicine_id
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN suppliers sup ON sup.id = b.supplier_id
        WHERE b.quantity > 0 {$bw['sql']}
        ORDER BY CASE WHEN b.expiry_date >= '9000-01-01' OR b.expiry_date IS NULL THEN 1 ELSE 0 END, b.expiry_date ASC
    ");
    $stmt->execute($bw['params']);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $status = reportInventoryExpiryStatus($r['expiry_date'], $r['days_remaining'] !== null ? (int)$r['days_remaining'] : null);
        $r['status'] = $status;
        $rows[] = $r;
    }
    return $rows;
}

/**
 * Summary values for the Inventory Report: cost/retail/profit values, units,
 * product counts and stock/expiry attention counts (respects base filters).
 */
function reportInventorySummary(PDO $pdo, array $dates, array $f): array {
    $products = reportInventoryProducts($pdo, $dates, $f);
    $expiry = reportInventoryExpiryRows($pdo, $dates, $f);
    $cost = $retail = $units = 0.0;
    $low = $out = 0;
    foreach ($products as $p) {
        $cost += (float)$p['cost_value'];
        $retail += (float)$p['retail_value'];
        $units += (float)$p['stock'];
        $st = inventoryStockStatus($p);
        if ($st === 'low') $low++;
        elseif ($st === 'out') $out++;
    }
    $nearProducts = [];
    $expiredProducts = [];
    foreach ($expiry as $r) {
        if ($r['status']['key'] === 'expired') $expiredProducts[$r['medicine_id']] = 1;
        elseif ($r['status']['key'] === 'expiring' && $r['status']['days'] !== null && $r['status']['days'] <= 30) $nearProducts[$r['medicine_id']] = 1;
    }
    return [
        'cost'     => $cost,
        'retail'   => $retail,
        'profit'   => $retail - $cost,
        'units'    => $units,
        'products' => count($products),
        'low'      => $low,
        'out'      => $out,
        'near'     => count($nearProducts),
        'expired'  => count($expiredProducts),
    ];
}

/** Overview totals + distribution across the inventory category buckets. */
function reportInventoryOverview(PDO $pdo, array $dates, array $f): array {
    $products = reportInventoryProducts($pdo, $dates, $f);
    $dist = [];
    foreach (reportInventoryBuckets() as $key => $label) {
        $dist[$key] = ['key' => $key, 'label' => $label, 'products' => 0, 'units' => 0, 'cost' => 0, 'retail' => 0];
    }
    foreach ($products as $p) {
        $k = isset($dist[$p['bucket']]) ? $p['bucket'] : 'other';
        $dist[$k]['products']++;
        $dist[$k]['units'] += (float)$p['stock'];
        $dist[$k]['cost'] += (float)$p['cost_value'];
        $dist[$k]['retail'] += (float)$p['retail_value'];
    }
    $totals = ['products' => count($products), 'units' => 0, 'cost' => 0, 'retail' => 0];
    foreach ($dist as $d) {
        $totals['units'] += $d['units'];
        $totals['cost'] += $d['cost'];
        $totals['retail'] += $d['retail'];
    }
    $totals['profit'] = $totals['retail'] - $totals['cost'];
    $totals['avg_value'] = $totals['products'] > 0 ? $totals['cost'] / $totals['products'] : 0;
    return ['totals' => $totals, 'dist' => array_values($dist)];
}

/** Everything the Inventory Report shows for one selected product. */
function reportInventoryProductDetail(PDO $pdo, int $medId): ?array {
    $stmt = $pdo->prepare("
        SELECT m.*, COALESCE(c.name, 'Uncategorized') AS category_name,
               " . reportInventoryBucketExpr() . " AS bucket,
               COALESCE(st.stock, 0) AS stock,
               COALESCE(st.avg_cost, 0) AS avg_cost,
               COALESCE(st.avg_sell, 0) AS avg_sell,
               COALESCE(st.stock * st.avg_cost, 0) AS cost_value,
               COALESCE(st.stock * st.avg_sell, 0) AS retail_value
        FROM medicines m
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN (
            SELECT medicine_id, SUM(quantity) AS stock,
                   AVG(purchase_price) AS avg_cost, AVG(selling_price) AS avg_sell
            FROM batches GROUP BY medicine_id
        ) st ON st.medicine_id = m.id
        WHERE m.id = ?
    ");
    $stmt->execute([$medId]);
    $m = $stmt->fetch();
    if (!$m) return null;

    $perf = $pdo->prepare("
        SELECT
            (SELECT COALESCE(SUM(COALESCE(quantity_received, quantity)), 0) FROM batches WHERE medicine_id = ?) AS units_purchased,
            (SELECT COALESCE(SUM(quantity), 0) FROM sale_items WHERE medicine_id = ?) AS units_sold,
            (SELECT COALESCE(SUM(quantity), 0) FROM sale_returns WHERE medicine_id = ?) AS units_returned,
            (SELECT MAX(created_at) FROM batches WHERE medicine_id = ?) AS last_purchase,
            (SELECT MAX(s.created_at) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.medicine_id = ?) AS last_sale
    ");
    $perf->execute([$medId, $medId, $medId, $medId, $medId]);
    $perf = $perf->fetch();

    $sup = $pdo->prepare("
        SELECT COALESCE(sup.name, '—') AS supplier_name, COUNT(*) AS batch_count
        FROM batches b LEFT JOIN suppliers sup ON sup.id = b.supplier_id
        WHERE b.medicine_id = ? GROUP BY b.supplier_id ORDER BY batch_count DESC LIMIT 1
    ");
    $sup->execute([$medId]);
    $supplier = $sup->fetch() ?: ['supplier_name' => '—'];

    $batches = $pdo->prepare("
        SELECT b.batch_number, b.quantity, b.purchase_price, b.selling_price,
               b.manufacture_date, b.expiry_date, b.status,
               COALESCE(sup.name, '—') AS supplier_name,
               CAST(julianday(b.expiry_date) - julianday('now') AS INTEGER) AS days_remaining
        FROM batches b LEFT JOIN suppliers sup ON sup.id = b.supplier_id
        WHERE b.medicine_id = ? ORDER BY b.expiry_date ASC
    ");
    $batches->execute([$medId]);
    $batchRows = [];
    foreach ($batches->fetchAll() as $b) {
        $b['status'] = reportInventoryExpiryStatus($b['expiry_date'], $b['days_remaining'] !== null ? (int)$b['days_remaining'] : null);
        $batchRows[] = $b;
    }

    $retail = (float)$m['retail_value'];
    $cost = (float)$m['cost_value'];
    return [
        'medicine'       => $m,
        'perf'           => $perf,
        'supplier'       => $supplier,
        'batches'        => $batchRows,
        'batch_count'    => count($batchRows),
        'profit'         => $retail - $cost,
        'margin'         => $retail > 0 ? (($retail - $cost) / $retail) * 100 : 0,
    ];
}
