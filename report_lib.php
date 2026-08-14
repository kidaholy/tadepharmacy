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

function reportFilteredSalesSubquery(array $filters, string $from, string $to, string $select = 's.id, (s.total_amount - s.discount) AS net'): array {
    $ctx = reportBuildSalesContext($filters);
    $extra = $ctx['where'] ? ' AND ' . implode(' AND ', $ctx['where']) : '';
    $sql = "
        SELECT DISTINCT $select
        FROM sales s {$ctx['joins']}
        WHERE date(s.created_at) BETWEEN ? AND ? $extra
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
    $joins  = [];
    $where  = [];
    $params = [];

    if ($filters['customer']) {
        $where[]  = "$saleAlias.customer_name LIKE ?";
        $params[] = '%' . $filters['customer'] . '%';
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
        'joins'  => implode("\n", array_unique($joins)),
        'where'  => $where,
        'params' => $params,
    ];
}

function reportDateClause(string $from, string $to, string $col = 'created_at', string $alias = 's'): array {
    return [
        "date($alias.$col) BETWEEN ? AND ?",
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
        'categories' => $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(),
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

    $itemWhere = ['date(s.created_at) BETWEEN ? AND ?'];
    $itemParams = [$from, $to];
    if ($filters['product'])   { $itemWhere[] = 'si.medicine_id = ?';   $itemParams[] = $filters['product']; }
    if ($filters['category'])  { $itemWhere[] = 'mf.category_id = ?';   $itemParams[] = $filters['category']; }
    if ($filters['supplier'])  { $itemWhere[] = 'bf.supplier_id = ?';    $itemParams[] = $filters['supplier']; }
    if ($filters['customer'])  { $itemWhere[] = 's.customer_name LIKE ?'; $itemParams[] = '%' . $filters['customer'] . '%'; }
    if ($filters['cashier'])   { $itemWhere[] = 's.user_id = ?';         $itemParams[] = $filters['cashier']; }
    if ($filters['payment_method']) { $itemWhere[] = 's.payment_method = ?'; $itemParams[] = $filters['payment_method']; }
    if ($filters['sales_type'] === 'credit') {
        $itemWhere[] = "(s.sale_type = 'credit' OR s.payment_method = 'credit')";
    } elseif ($filters['sales_type']) {
        $itemWhere[] = 's.sale_type = ?';
        $itemParams[] = $filters['sales_type'];
    }
    $itemJoin = 'JOIN sale_items si ON si.sale_id = s.id JOIN batches b ON b.id = si.batch_id';
    if ($filters['category']) $itemJoin .= ' JOIN medicines mf ON mf.id = si.medicine_id';
    $itemSqlWhere = implode(' AND ', $itemWhere);
    $prevItemParams = array_merge([$pf, $pt], array_slice($itemParams, 2));

    $cogsCur = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(si.quantity * b.purchase_price), 0)
        FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN batches b ON b.id = si.batch_id
        " . ($filters['category'] ? ' JOIN medicines mf ON mf.id = si.medicine_id' : '') . "
        WHERE $itemSqlWhere
    ", $itemParams);
    $cogsPrev = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(si.quantity * b.purchase_price), 0)
        FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN batches b ON b.id = si.batch_id
        " . ($filters['category'] ? ' JOIN medicines mf ON mf.id = si.medicine_id' : '') . "
        WHERE " . str_replace('date(s.created_at) BETWEEN ? AND ?', 'date(s.created_at) BETWEEN ? AND ?', $itemSqlWhere) . "
    ", $prevItemParams);

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
          AND date(s.created_at) BETWEEN ? AND ?
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
    $sub = reportFilteredSalesSubquery($filters, $dates['from'], $dates['to'], 's.id, date(s.created_at) AS day, (s.total_amount - s.discount) AS net');
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
    $where = ['date(s.created_at) BETWEEN ? AND ?'];
    $params = [$dates['from'], $dates['to']];
    if ($filters['payment_method']) { $where[] = 's.payment_method = ?'; $params[] = $filters['payment_method']; }
    if ($filters['cashier']) { $where[] = 's.user_id = ?'; $params[] = $filters['cashier']; }
    $stmt = $pdo->prepare("
        SELECT COALESCE(c.name, 'Uncategorized') AS category,
               SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        JOIN medicines m ON m.id = si.medicine_id
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY c.id ORDER BY revenue DESC LIMIT 12
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function reportTopProducts(PDO $pdo, array $dates, array $filters, string $sort = 'qty', int $limit = 25, int $offset = 0): array {
    $where = ['date(s.created_at) BETWEEN ? AND ?'];
    $params = [$dates['from'], $dates['to']];
    if ($filters['category']) { $where[] = 'm.category_id = ?'; $params[] = $filters['category']; }
    if ($filters['product'])  { $where[] = 'm.id = ?';          $params[] = $filters['product']; }
    if ($filters['payment_method']) { $where[] = 's.payment_method = ?'; $params[] = $filters['payment_method']; }

    $orderMap = [
        'qty'    => 'qty_sold DESC',
        'rev'    => 'revenue DESC',
        'profit' => 'gross_profit DESC',
        'net'    => 'net_profit DESC',
    ];
    $order = $orderMap[$sort] ?? 'qty_sold DESC';

    $sql = "
        SELECT m.id, m.name, m.generic_name, m.barcode, m.sku,
               COALESCE(c.name, 'Uncategorized') AS category,
               SUM(si.quantity) AS qty_sold,
               SUM(si.subtotal) AS revenue,
               SUM(si.quantity * b.purchase_price) AS purchase_cost,
               SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS gross_profit,
               SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS net_profit,
               COALESCE(st.stock, 0) AS current_stock
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        JOIN medicines m ON m.id = si.medicine_id
        JOIN batches b ON b.id = si.batch_id
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN (SELECT medicine_id, SUM(quantity) AS stock FROM batches GROUP BY medicine_id) st ON st.medicine_id = m.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY m.id ORDER BY $order LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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

    $perf = $pdo->prepare("
        SELECT SUM(si.quantity) AS qty_sold, SUM(si.subtotal) AS revenue,
               SUM(si.quantity * b.purchase_price) AS purchase_cost,
               COUNT(DISTINCT s.id) AS num_sales,
               COUNT(DISTINCT s.customer_name) AS num_customers,
               AVG(si.unit_price) AS avg_sell_price
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        JOIN batches b ON b.id = si.batch_id
        WHERE si.medicine_id = ? AND date(s.created_at) BETWEEN ? AND ?
    ");
    $perf->execute([$medId, $from, $to]);
    $perf = $perf->fetch();

    $trend = $pdo->prepare("
        SELECT date(s.created_at) AS day, SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue,
               SUM(si.subtotal) - SUM(si.quantity * b.purchase_price) AS profit
        FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN batches b ON b.id = si.batch_id
        WHERE si.medicine_id = ? AND date(s.created_at) BETWEEN ? AND ?
        GROUP BY day ORDER BY day ASC
    ");
    $trend->execute([$medId, $from, $to]);
    $trend = $trend->fetchAll();

    $batches = $pdo->prepare("
        SELECT batch_number, expiry_date, quantity, purchase_price, selling_price
        FROM batches WHERE medicine_id = ? ORDER BY expiry_date ASC
    ");
    $batches->execute([$medId]);
    $batches = $batches->fetchAll();

    $paySplit = $pdo->prepare("
        SELECT s.payment_method, SUM(si.subtotal) AS amount, SUM(si.quantity) AS qty
        FROM sale_items si JOIN sales s ON s.id = si.sale_id
        WHERE si.medicine_id = ? AND date(s.created_at) BETWEEN ? AND ?
        GROUP BY s.payment_method
    ");
    $paySplit->execute([$medId, $from, $to]);
    $paySplit = $paySplit->fetchAll();

    $topCust = $pdo->prepare("
        SELECT s.customer_name, SUM(si.quantity) AS qty, SUM(si.subtotal) AS spent
        FROM sale_items si JOIN sales s ON s.id = si.sale_id
        WHERE si.medicine_id = ? AND date(s.created_at) BETWEEN ? AND ?
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

    $gross = (float)$perf['revenue'] - (float)$perf['purchase_cost'];
    $margin = (float)$perf['revenue'] > 0 ? ($gross / (float)$perf['revenue']) * 100 : 0;
    $months = max(1, (int)((strtotime($to) - strtotime($from)) / 86400 / 30) + 1);

    return [
        'medicine'  => $med,
        'perf'      => $perf,
        'gross'     => $gross,
        'margin'    => $margin,
        'avg_monthly' => (float)$perf['qty_sold'] / $months,
        'trend'     => $trend,
        'batches'   => $batches,
        'payments'  => $paySplit,
        'top_customers' => $topCust->fetchAll(),
        'main_supplier' => $supplier->fetch(),
        'expired_stock' => $expired,
        'near_expiry_stock' => $nearExpiry,
    ];
}

function reportSearchProducts(PDO $pdo, string $q, int $limit = 20): array {
    if (strlen(trim($q)) < 1) return [];
    $like = '%' . trim($q) . '%';
    $stmt = $pdo->prepare("
        SELECT id, name, generic_name, barcode, sku
        FROM medicines
        WHERE name LIKE ? OR generic_name LIKE ? OR barcode LIKE ? OR sku LIKE ?
        ORDER BY name LIMIT $limit
    ");
    $stmt->execute([$like, $like, $like, $like]);
    return $stmt->fetchAll();
}

function reportInsights(PDO $pdo, array $dates, array $filters): array {
    $insights = [];
    $from = $dates['from'];
    $to   = $dates['to'];

    $best = $pdo->prepare("
        SELECT m.name, SUM(si.quantity) AS qty
        FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN medicines m ON m.id = si.medicine_id
        WHERE date(s.created_at) BETWEEN ? AND ?
        GROUP BY m.id ORDER BY qty DESC LIMIT 1
    ");
    $best->execute([$from, $to]);
    if ($row = $best->fetch()) {
        $insights[] = ['type' => 'success', 'icon' => 'trophy', 'text' => "Best seller: <strong>{$row['name']}</strong> with " . number_format($row['qty']) . " units sold."];
    }

    $slow = $pdo->prepare("
        SELECT m.name FROM medicines m
        JOIN batches b ON b.medicine_id = m.id AND b.quantity > 0
        WHERE m.id NOT IN (
            SELECT DISTINCT si.medicine_id FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE date(s.created_at) BETWEEN ? AND ?
        ) GROUP BY m.id LIMIT 1
    ");
    $slow->execute([$from, $to]);
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
        SELECT m.name, SUM(si.subtotal - si.quantity * bt.purchase_price) AS profit
        FROM sale_items si JOIN sales s ON s.id = si.sale_id
        JOIN medicines m ON m.id = si.medicine_id JOIN batches bt ON bt.id = si.batch_id
        WHERE date(s.created_at) BETWEEN ? AND ?
        GROUP BY m.id ORDER BY profit DESC LIMIT 1
    ");
    $topProfit->execute([$from, $to]);
    if ($row = $topProfit->fetch()) {
        $insights[] = ['type' => 'success', 'icon' => 'trending-up', 'text' => "Highest profit product: <strong>{$row['name']}</strong> (" . getSetting('currency','ETB') . ' ' . number_format($row['profit'], 0) . ")."];
    }

    $topCat = $pdo->prepare("
        SELECT COALESCE(c.name,'Uncategorized') AS cat, SUM(si.subtotal) AS rev
        FROM sale_items si JOIN sales s ON s.id = si.sale_id
        JOIN medicines m ON m.id = si.medicine_id LEFT JOIN categories c ON c.id = m.category_id
        WHERE date(s.created_at) BETWEEN ? AND ?
        GROUP BY c.id ORDER BY rev DESC LIMIT 1
    ");
    $topCat->execute([$from, $to]);
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
    ];
}
