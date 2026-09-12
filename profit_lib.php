<?php
/**
 * Profit & Loss reporting engine for report_profit.php.
 * Reuses report_lib filters/COGS joins; does not duplicate Investment & Growth.
 */
require_once __DIR__ . '/report_lib.php';

/** Same COGS expression used across existing reports (batch purchase price, then sale snapshot). */
function profitCogsExpr(string $si = 'si', string $b = 'b'): string {
    return "($si.quantity * COALESCE($b.purchase_price, $si.cost_price, 0))";
}

function profitDatePresets(): array {
    return [
        'today'         => 'Today',
        'yesterday'     => 'Yesterday',
        'last7'         => 'Last 7 Days',
        'last30'        => 'Last 30 Days',
        'this_week'     => 'This Week',
        'this_month'    => 'This Month',
        'last_month'    => 'Previous Month',
        'this_year'     => 'This Year',
        'previous_year' => 'Previous Year',
        'custom'        => 'Custom Date Range',
    ];
}

function profitExpenseFrequencies(): array {
    return [
        'one_time'  => 'One-time',
        'daily'     => 'Daily',
        'weekly'    => 'Weekly',
        'monthly'   => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly'    => 'Yearly',
    ];
}

function profitExpenseStatuses(): array {
    return [
        'paid'    => 'Paid',
        'pending' => 'Pending',
        'overdue' => 'Overdue',
    ];
}

function profitExpenseCategories(PDO $pdo): array {
    $rows = $pdo->query("SELECT id, name FROM expense_categories ORDER BY sort_order, name COLLATE NOCASE")->fetchAll();
    if ($rows) return $rows;
    // Fallback if table empty / migration pending
    $defaults = ['Rent', 'Salary/Wages', 'Electricity', 'Water', 'Internet', 'Transportation',
        'Delivery', 'Bank Fees', 'Cleaning', 'Maintenance', 'Tax/License', 'Administration', 'Other'];
    return array_map(fn($n, $i) => ['id' => $i + 1, 'name' => $n], $defaults, array_keys($defaults));
}

function profitEnsureOverdueStatuses(PDO $pdo): void {
    try {
        $pdo->exec("UPDATE operating_expenses
            SET status = 'overdue'
            WHERE LOWER(COALESCE(status,'pending')) = 'pending'
              AND due_date IS NOT NULL
              AND due_date < date('now')");
    } catch (PDOException $e) { /* ignore */ }
}

/**
 * Line-level revenue + COGS for a period (non-voided). Aggregates by medicine_id
 * so batch/expiry splits never double-count product totals incorrectly.
 */
function profitSalesTotals(PDO $pdo, string $from, string $to, array $filters): array {
    $ctx = reportItemFilterContext($filters, $from, $to);
    $cogs = profitCogsExpr();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(si.subtotal), 0) AS revenue,
               COALESCE(SUM($cogs), 0) AS cogs,
               COALESCE(SUM(si.quantity), 0) AS units,
               COUNT(DISTINCT s.id) AS transactions
        FROM sale_items si
        {$ctx['joins']}
        WHERE {$ctx['where']}
    ");
    $stmt->execute($ctx['params']);
    $r = $stmt->fetch() ?: [];
    return [
        'revenue'      => (float)($r['revenue'] ?? 0),
        'cogs'         => (float)($r['cogs'] ?? 0),
        'units'        => (float)($r['units'] ?? 0),
        'transactions' => (int)($r['transactions'] ?? 0),
    ];
}

/** Returns (revenue amount + estimated COGS) in the period, respecting filters. */
function profitReturnsTotals(PDO $pdo, string $from, string $to, array $filters): array {
    $where  = ["date(sr.created_at, '+3 hours') BETWEEN ? AND ?", "COALESCE(s.status, 'active') != 'voided'"];
    $params = [$from, $to];
    $joins  = ['JOIN sales s ON s.id = sr.sale_id', 'LEFT JOIN batches b ON b.id = sr.batch_id'];

    if ($filters['product']) {
        $where[] = 'sr.medicine_id = ?';
        $params[] = (int)$filters['product'];
    }
    if (!empty($filters['category']) || ($filters['type'] ?? '') !== '') {
        $joins[] = 'JOIN medicines mr ON mr.id = sr.medicine_id';
        reportApplyTypeCategory($filters, 'mr', $where, $params);
    }
    if ($filters['supplier']) {
        $where[] = 'b.supplier_id = ?';
        $params[] = (int)$filters['supplier'];
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
        $params[] = (int)$filters['cashier'];
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
        $params[] = (int)$filters['branch'];
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(sr.amount), 0) AS amount,
               COALESCE(SUM(sr.quantity * COALESCE(b.purchase_price, 0)), 0) AS cogs,
               COALESCE(SUM(sr.quantity), 0) AS units
        FROM sale_returns sr
        " . implode("\n", array_unique($joins)) . "
        WHERE " . implode(' AND ', $where) . "
    ");
    $stmt->execute($params);
    $r = $stmt->fetch() ?: [];
    return [
        'amount' => (float)($r['amount'] ?? 0),
        'cogs'   => (float)($r['cogs'] ?? 0),
        'units'  => (float)($r['units'] ?? 0),
    ];
}

function profitExpensesTotal(PDO $pdo, string $from, string $to): float {
    return reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(amount), 0) FROM operating_expenses
        WHERE expense_date BETWEEN ? AND ?
    ", [$from, $to]);
}

/**
 * Core P&L numbers for a period.
 * Revenue/COGS are line-level (aligned), net of returns. OpEx from operating_expenses.
 */
function profitPeriodCore(PDO $pdo, string $from, string $to, array $filters): array {
    $sales   = profitSalesTotals($pdo, $from, $to, $filters);
    $returns = profitReturnsTotals($pdo, $from, $to, $filters);
    $revenue = max(0, $sales['revenue'] - $returns['amount']);
    $cogs    = max(0, $sales['cogs'] - $returns['cogs']);
    $gross   = $revenue - $cogs;
    // OpEx is pharmacy-wide (not product-filtered) — only when no product-dimension filter.
    // When product/type/category/supplier filters are active, OpEx is excluded from Net
    // so filtered product profit is Gross Profit (product contribution), not distorted by full rent.
    $itemFiltered = reportNeedsItemJoin($filters);
    $expenses = $itemFiltered ? 0.0 : profitExpensesTotal($pdo, $from, $to);
    $net = $gross - $expenses;
    $grossMargin = $revenue > 0 ? ($gross / $revenue) * 100 : 0.0;
    $netMargin   = $revenue > 0 ? ($net / $revenue) * 100 : 0.0;
    $perThousand = $revenue > 0 ? ($net / $revenue) * 1000 : 0.0;

    return [
        'revenue'            => $revenue,
        'cogs'               => $cogs,
        'gross_profit'       => $gross,
        'expenses'           => $expenses,
        'expenses_full'      => profitExpensesTotal($pdo, $from, $to),
        'net_profit'         => $net,
        'gross_margin'       => $grossMargin,
        'net_margin'         => $netMargin,
        'profit_per_1000'    => $perThousand,
        'units'              => max(0, $sales['units'] - $returns['units']),
        'transactions'       => $sales['transactions'],
        'returns_amount'     => $returns['amount'],
        'returns_cogs'       => $returns['cogs'],
        'item_filtered'      => $itemFiltered,
    ];
}

function profitKpis(PDO $pdo, array $dates, array $filters): array {
    $cur  = profitPeriodCore($pdo, $dates['from'], $dates['to'], $filters);
    $prev = profitPeriodCore($pdo, $dates['prevFrom'], $dates['prevTo'], $filters);

    $map = [
        'revenue'          => [$cur['revenue'], $prev['revenue'], true],
        'cogs'             => [$cur['cogs'], $prev['cogs'], false],
        'gross_profit'     => [$cur['gross_profit'], $prev['gross_profit'], true],
        'expenses'         => [$cur['expenses_full'], $prev['expenses_full'], false],
        'net_profit'       => [$cur['net_profit'], $prev['net_profit'], true],
        'gross_margin'     => [$cur['gross_margin'], $prev['gross_margin'], true],
        'net_margin'       => [$cur['net_margin'], $prev['net_margin'], true],
        'profit_per_1000'  => [$cur['profit_per_1000'], $prev['profit_per_1000'], true],
    ];
    $out = ['core' => $cur, 'prev_core' => $prev];
    foreach ($map as $key => [$c, $p, $good]) {
        $out[$key] = reportTrendMeta((float)$c, (float)$p, $good);
    }
    // When item filters are on, KPI OpEx card still shows full OpEx for the period for transparency,
    // but Net Profit uses gross only (see period core). Recompute net KPI from core.
    if ($cur['item_filtered']) {
        $out['net_profit'] = reportTrendMeta($cur['gross_profit'], $prev['gross_profit'], true);
        $out['net_margin'] = reportTrendMeta(
            $cur['revenue'] > 0 ? ($cur['gross_profit'] / $cur['revenue']) * 100 : 0,
            $prev['revenue'] > 0 ? ($prev['gross_profit'] / $prev['revenue']) * 100 : 0,
            true
        );
        $out['profit_per_1000'] = reportTrendMeta(
            $cur['revenue'] > 0 ? ($cur['gross_profit'] / $cur['revenue']) * 1000 : 0,
            $prev['revenue'] > 0 ? ($prev['gross_profit'] / $prev['revenue']) * 1000 : 0,
            true
        );
    }
    return $out;
}

function profitVarianceExplain(array $cur, array $prev): array {
    $dRev = $cur['revenue'] - $prev['revenue'];
    $dCogs = $cur['cogs'] - $prev['cogs'];
    $dExp = ($cur['item_filtered'] ? 0 : $cur['expenses_full']) - ($prev['item_filtered'] ? 0 : $prev['expenses_full']);
    // Net impact: +revenue, -cogs increase, -expense increase
    $netImpact = $dRev - $dCogs - $dExp;
    $netDelta = $cur['net_profit'] - $prev['net_profit'];

    $contributors = [
        ['label' => 'Revenue', 'delta' => $dRev, 'note' => $dRev >= 0 ? 'Higher sales' : 'Lower sales'],
        ['label' => 'COGS', 'delta' => -$dCogs, 'raw' => $dCogs, 'note' => $dCogs <= 0 ? 'Lower product cost' : 'Higher product cost'],
        ['label' => 'Operating Expenses', 'delta' => -$dExp, 'raw' => $dExp, 'note' => $dExp <= 0 ? 'Lower expenses' : 'Higher expenses'],
    ];
    usort($contributors, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));

    $hasPrev = ($prev['revenue'] + $prev['cogs'] + $prev['expenses_full'] + abs($prev['net_profit'])) > 0.009
        || $prev['transactions'] > 0;

    return [
        'has_previous'   => $hasPrev,
        'net_delta'      => $netDelta,
        'net_impact'     => $netImpact,
        'revenue_delta'  => $dRev,
        'cogs_delta'     => $dCogs,
        'expenses_delta' => $dExp,
        'contributors'   => $contributors,
        'positive'       => array_values(array_filter($contributors, fn($c) => $c['delta'] > 0.009)),
        'negative'       => array_values(array_filter($contributors, fn($c) => $c['delta'] < -0.009)),
    ];
}

function profitTrend(PDO $pdo, array $dates, array $filters, string $view = 'daily'): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $ctx  = reportItemFilterContext($filters, $from, $to);
    $cogs = profitCogsExpr();
    $day  = reportLocalDateExpr('s');

    $labelExpr = match ($view) {
        'weekly'  => "strftime('%Y-W%W', datetime(COALESCE(s.sale_at, s.created_at), '+3 hours'))",
        'monthly' => "strftime('%Y-%m', datetime(COALESCE(s.sale_at, s.created_at), '+3 hours'))",
        'yearly'  => "strftime('%Y', datetime(COALESCE(s.sale_at, s.created_at), '+3 hours'))",
        default   => $day,
    };

    $sql = "
        SELECT $labelExpr AS label,
               COALESCE(SUM(si.subtotal), 0) AS revenue,
               COALESCE(SUM($cogs), 0) AS cogs
        FROM sale_items si
        {$ctx['joins']}
        WHERE {$ctx['where']}
        GROUP BY label ORDER BY label ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ctx['params']);
    $salesRows = $stmt->fetchAll();

    // OpEx by bucket (only when not item-filtered)
    $expByLabel = [];
    if (!reportNeedsItemJoin($filters)) {
        $expLabel = match ($view) {
            'weekly'  => "strftime('%Y-W%W', expense_date)",
            'monthly' => "strftime('%Y-%m', expense_date)",
            'yearly'  => "strftime('%Y', expense_date)",
            default   => 'expense_date',
        };
        $eStmt = $pdo->prepare("
            SELECT $expLabel AS label, COALESCE(SUM(amount), 0) AS expenses
            FROM operating_expenses
            WHERE expense_date BETWEEN ? AND ?
            GROUP BY label
        ");
        $eStmt->execute([$from, $to]);
        foreach ($eStmt->fetchAll() as $e) {
            $expByLabel[$e['label']] = (float)$e['expenses'];
        }
    }

    $out = [];
    $seen = [];
    foreach ($salesRows as $r) {
        $label = $r['label'];
        $seen[$label] = true;
        $rev = (float)$r['revenue'];
        $cg  = (float)$r['cogs'];
        $gp  = $rev - $cg;
        $ex  = $expByLabel[$label] ?? 0.0;
        $out[] = [
            'label'     => $label,
            'revenue'   => $rev,
            'cogs'      => $cg,
            'gross'     => $gp,
            'expenses'  => $ex,
            'net'       => $gp - $ex,
        ];
    }
    foreach ($expByLabel as $label => $ex) {
        if (!empty($seen[$label])) continue;
        $out[] = [
            'label' => $label, 'revenue' => 0, 'cogs' => 0, 'gross' => 0,
            'expenses' => $ex, 'net' => -$ex,
        ];
    }
    usort($out, fn($a, $b) => strcmp((string)$a['label'], (string)$b['label']));
    return $out;
}

function profitByCategory(PDO $pdo, array $dates, array $filters, string $sort = 'revenue'): array {
    $ctx = reportItemFilterContext($filters, $dates['from'], $dates['to']);
    $cogs = profitCogsExpr();
    $stmt = $pdo->prepare("
        SELECT COALESCE(c.name, 'Uncategorized') AS category,
               COALESCE(m.product_type, c.product_type, '') AS mtype,
               COALESCE(SUM(si.quantity), 0) AS units,
               COALESCE(SUM(si.subtotal), 0) AS revenue,
               COALESCE(SUM($cogs), 0) AS cogs
        FROM sale_items si
        {$ctx['joins']}
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE {$ctx['where']}
        GROUP BY COALESCE(c.id, 0), category, mtype
    ");
    $stmt->execute($ctx['params']);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rev = (float)$r['revenue'];
        $cg  = (float)$r['cogs'];
        $gp  = $rev - $cg;
        $rows[] = [
            'category'     => $r['category'],
            'mtype'        => $r['mtype'],
            'type'         => reportCategoryType($r['mtype'] ?? null, null, $r['category']),
            'units'        => (float)$r['units'],
            'revenue'      => $rev,
            'cogs'         => $cg,
            'gross_profit' => $gp,
            'gross_margin' => $rev > 0 ? ($gp / $rev) * 100 : 0.0,
            'net_profit'   => $gp, // category net ≈ gross (OpEx not allocated)
        ];
    }
    $cmp = match ($sort) {
        'gross'       => fn($a, $b) => $b['gross_profit'] <=> $a['gross_profit'],
        'margin_high' => fn($a, $b) => $b['gross_margin'] <=> $a['gross_margin'],
        'margin_low'  => fn($a, $b) => $a['gross_margin'] <=> $b['gross_margin'],
        'units'       => fn($a, $b) => $b['units'] <=> $a['units'],
        default       => fn($a, $b) => $b['revenue'] <=> $a['revenue'],
    };
    usort($rows, $cmp);
    return $rows;
}

function profitProducts(PDO $pdo, array $dates, array $filters, string $sort = 'profit', int $limit = 25, ?float $maxMargin = null): array {
    $ctx = reportItemFilterContext($filters, $dates['from'], $dates['to']);
    $cogs = profitCogsExpr();
    $orderMap = [
        'profit'   => 'gross_profit DESC',
        'revenue'  => 'revenue DESC',
        'margin'   => 'margin_pct DESC',
        'units'    => 'units DESC',
        'per_unit' => 'profit_per_unit DESC',
    ];
    $order = $orderMap[$sort] ?? 'gross_profit DESC';
    $having = $maxMargin !== null
        ? 'HAVING CASE WHEN SUM(si.subtotal) > 0 THEN (SUM(si.subtotal) - SUM(' . $cogs . ')) / SUM(si.subtotal) * 100 ELSE 0 END < ' . (float)$maxMargin
        : '';

    $sql = "
        SELECT m.id, m.name, m.generic_name,
               COALESCE(c.name, 'Uncategorized') AS category,
               COALESCE(m.product_type, c.product_type, 'medicine') AS product_type,
               SUM(si.quantity) AS units,
               SUM(si.subtotal) AS revenue,
               SUM($cogs) AS cogs,
               SUM(si.subtotal) - SUM($cogs) AS gross_profit,
               CASE WHEN SUM(si.subtotal) > 0
                    THEN (SUM(si.subtotal) - SUM($cogs)) / SUM(si.subtotal) * 100 ELSE 0 END AS margin_pct,
               CASE WHEN SUM(si.quantity) > 0 THEN SUM(si.subtotal) / SUM(si.quantity) ELSE 0 END AS avg_sell,
               CASE WHEN SUM(si.quantity) > 0 THEN SUM($cogs) / SUM(si.quantity) ELSE 0 END AS avg_cost,
               CASE WHEN SUM(si.quantity) > 0 THEN (SUM(si.subtotal) - SUM($cogs)) / SUM(si.quantity) ELSE 0 END AS profit_per_unit,
               COUNT(DISTINCT s.id) AS transactions
        FROM sale_items si
        {$ctx['joins']}
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE {$ctx['where']}
        GROUP BY m.id
        $having
        ORDER BY $order
        LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($ctx['params']);
    return $stmt->fetchAll();
}

function profitProductDetail(PDO $pdo, int $medId, array $dates, array $filters): ?array {
    if ($medId <= 0) return null;
    $f = $filters;
    $f['product'] = $medId;
    $rows = profitProducts($pdo, $dates, $f, 'profit', 1);
    if (!$rows) {
        $med = $pdo->prepare("SELECT id, name, generic_name FROM medicines WHERE id = ?");
        $med->execute([$medId]);
        $m = $med->fetch();
        if (!$m) return null;
        return [
            'id' => (int)$m['id'], 'name' => $m['name'], 'generic_name' => $m['generic_name'],
            'units' => 0, 'revenue' => 0, 'cogs' => 0, 'gross_profit' => 0, 'margin_pct' => 0,
            'avg_sell' => 0, 'avg_cost' => 0, 'profit_per_unit' => 0, 'transactions' => 0,
        ];
    }
    return $rows[0];
}

function profitByPayment(PDO $pdo, array $dates, array $filters): array {
    $ctx = reportItemFilterContext($filters, $dates['from'], $dates['to']);
    $cogs = profitCogsExpr();
    $stmt = $pdo->prepare("
        SELECT COALESCE(s.payment_method, 'other') AS method,
               COUNT(DISTINCT s.id) AS transactions,
               COALESCE(SUM(si.subtotal), 0) AS revenue,
               COALESCE(SUM($cogs), 0) AS cogs
        FROM sale_items si
        {$ctx['joins']}
        WHERE {$ctx['where']}
        GROUP BY method
    ");
    $stmt->execute($ctx['params']);
    $by = [];
    foreach ($stmt->fetchAll() as $r) {
        $by[$r['method']] = $r;
    }
    $methods = reportPaymentMethods();
    $out = [];
    foreach ($methods as $key => $label) {
        $r = $by[$key] ?? null;
        $rev = (float)($r['revenue'] ?? 0);
        $cg  = (float)($r['cogs'] ?? 0);
        $out[] = [
            'method' => $key, 'label' => $label,
            'transactions' => (int)($r['transactions'] ?? 0),
            'revenue' => $rev, 'cogs' => $cg, 'gross_profit' => $rev - $cg,
        ];
        unset($by[$key]);
    }
    foreach ($by as $key => $r) {
        $rev = (float)$r['revenue'];
        $cg  = (float)$r['cogs'];
        $out[] = [
            'method' => $key, 'label' => ucfirst((string)$key),
            'transactions' => (int)$r['transactions'],
            'revenue' => $rev, 'cogs' => $cg, 'gross_profit' => $rev - $cg,
        ];
    }
    return $out;
}

function profitByCashier(PDO $pdo, array $dates, array $filters, string $sort = 'gross'): array {
    $ctx = reportItemFilterContext($filters, $dates['from'], $dates['to']);
    $cogs = profitCogsExpr();
    $stmt = $pdo->prepare("
        SELECT COALESCE(u.full_name, 'Unknown') AS cashier,
               s.user_id,
               COUNT(DISTINCT s.id) AS transactions,
               COALESCE(SUM(si.subtotal), 0) AS revenue,
               COALESCE(SUM($cogs), 0) AS cogs
        FROM sale_items si
        {$ctx['joins']}
        LEFT JOIN users u ON u.id = s.user_id
        WHERE {$ctx['where']}
        GROUP BY s.user_id
    ");
    $stmt->execute($ctx['params']);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rev = (float)$r['revenue'];
        $cg  = (float)$r['cogs'];
        $gp  = $rev - $cg;
        $rows[] = [
            'cashier' => $r['cashier'],
            'transactions' => (int)$r['transactions'],
            'revenue' => $rev,
            'gross_profit' => $gp,
            'margin_pct' => $rev > 0 ? ($gp / $rev) * 100 : 0.0,
        ];
    }
    $cmp = match ($sort) {
        'revenue' => fn($a, $b) => $b['revenue'] <=> $a['revenue'],
        'margin'  => fn($a, $b) => $b['margin_pct'] <=> $a['margin_pct'],
        'txns'    => fn($a, $b) => $b['transactions'] <=> $a['transactions'],
        default   => fn($a, $b) => $b['gross_profit'] <=> $a['gross_profit'],
    };
    usort($rows, $cmp);
    return $rows;
}

function profitBySalesType(PDO $pdo, array $dates, array $filters): array {
    $ctx = reportItemFilterContext($filters, $dates['from'], $dates['to']);
    $cogs = profitCogsExpr();
    $stmt = $pdo->prepare("
        SELECT CASE
                 WHEN s.sale_type = 'credit' OR s.payment_method = 'credit' THEN 'credit'
                 ELSE COALESCE(NULLIF(TRIM(s.sale_type), ''), 'cash')
               END AS sales_type,
               COUNT(DISTINCT s.id) AS transactions,
               COALESCE(SUM(si.subtotal), 0) AS revenue,
               COALESCE(SUM($cogs), 0) AS cogs
        FROM sale_items si
        {$ctx['joins']}
        WHERE {$ctx['where']}
        GROUP BY sales_type
    ");
    $stmt->execute($ctx['params']);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rev = (float)$r['revenue'];
        $cg  = (float)$r['cogs'];
        $gp  = $rev - $cg;
        $rows[] = [
            'sales_type' => $r['sales_type'],
            'label' => ucfirst((string)$r['sales_type']) . ' Sale',
            'transactions' => (int)$r['transactions'],
            'revenue' => $rev,
            'cogs' => $cg,
            'gross_profit' => $gp,
            'margin_pct' => $rev > 0 ? ($gp / $rev) * 100 : 0.0,
        ];
    }
    usort($rows, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    return $rows;
}

function profitByCustomer(PDO $pdo, array $dates, array $filters, int $limit = 50): array {
    $ctx = reportItemFilterContext($filters, $dates['from'], $dates['to']);
    $cogs = profitCogsExpr();
    $custJoin = str_contains($ctx['joins'], 'customers cust') ? '' : "\nLEFT JOIN customers cust ON cust.id = s.customer_id";
    $label = "COALESCE(NULLIF(TRIM(s.customer_name), ''), cust.full_name)";
    $stmt = $pdo->prepare("
        SELECT $label AS customer,
               COUNT(DISTINCT s.id) AS transactions,
               COALESCE(SUM(si.subtotal), 0) AS revenue,
               COALESCE(SUM($cogs), 0) AS cogs
        FROM sale_items si
        {$ctx['joins']}$custJoin
        WHERE {$ctx['where']}
          AND $label IS NOT NULL AND TRIM($label) != ''
        GROUP BY customer
        ORDER BY revenue DESC
        LIMIT " . (int)$limit
    );
    $stmt->execute($ctx['params']);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rev = (float)$r['revenue'];
        $cg  = (float)$r['cogs'];
        $gp  = $rev - $cg;
        $rows[] = [
            'customer' => $r['customer'],
            'transactions' => (int)$r['transactions'],
            'revenue' => $rev,
            'gross_profit' => $gp,
            'margin_pct' => $rev > 0 ? ($gp / $rev) * 100 : 0.0,
        ];
    }
    return $rows;
}

function profitParseExpenseFilters(array $input): array {
    return [
        'category'       => trim((string)($input['exp_category'] ?? '')),
        'status'         => trim((string)($input['exp_status'] ?? '')),
        'payment_method' => trim((string)($input['exp_payment'] ?? '')),
    ];
}

function profitExpenseSummary(PDO $pdo, string $from, string $to, array $expFilters = []): array {
    $expFilters = array_merge([
        'category' => '',
        'status' => '',
        'payment_method' => '',
    ], $expFilters);
    $where = ['expense_date BETWEEN ? AND ?'];
    $params = [$from, $to];
    if ($expFilters['category'] !== '') {
        $where[] = 'category = ?';
        $params[] = $expFilters['category'];
    }
    if ($expFilters['status'] !== '') {
        $where[] = 'LOWER(COALESCE(status,\'paid\')) = ?';
        $params[] = strtolower($expFilters['status']);
    }
    if ($expFilters['payment_method'] !== '') {
        $where[] = 'payment_method = ?';
        $params[] = $expFilters['payment_method'];
    }
    $w = implode(' AND ', $where);

    $totals = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0) AS total,
               COALESCE(SUM(CASE WHEN LOWER(COALESCE(status,'paid'))='paid' THEN amount ELSE 0 END),0) AS paid,
               COALESCE(SUM(CASE WHEN LOWER(COALESCE(status,'paid'))='pending' THEN amount ELSE 0 END),0) AS pending,
               COALESCE(SUM(CASE WHEN LOWER(COALESCE(status,'paid'))='overdue' THEN amount ELSE 0 END),0) AS overdue,
               COUNT(*) AS cnt
        FROM operating_expenses WHERE $w
    ");
    $totals->execute($params);
    $t = $totals->fetch() ?: [];

    $byCat = $pdo->prepare("
        SELECT category, SUM(amount) AS total, COUNT(*) AS cnt
        FROM operating_expenses WHERE $w
        GROUP BY category ORDER BY total DESC
    ");
    $byCat->execute($params);
    $breakdown = $byCat->fetchAll();
    $sum = (float)($t['total'] ?? 0);
    foreach ($breakdown as &$b) {
        $b['pct'] = $sum > 0 ? ((float)$b['total'] / $sum) * 100 : 0.0;
    }
    unset($b);

    $list = $pdo->prepare("
        SELECT id, COALESCE(NULLIF(TRIM(expense_name),''), description, category) AS expense_name,
               category, amount, frequency, due_date, payment_date, payment_method,
               COALESCE(status,'paid') AS status, notes, expense_date, series_id, description
        FROM operating_expenses WHERE $w
        ORDER BY expense_date DESC, id DESC
        LIMIT 500
    ");
    $list->execute($params);

    return [
        'total'     => (float)($t['total'] ?? 0),
        'paid'      => (float)($t['paid'] ?? 0),
        'pending'   => (float)($t['pending'] ?? 0),
        'overdue'   => (float)($t['overdue'] ?? 0),
        'count'     => (int)($t['cnt'] ?? 0),
        'breakdown' => $breakdown,
        'rows'      => $list->fetchAll(),
    ];
}

function profitNextOccurrenceDates(string $start, string $frequency, string $until): array {
    $dates = [];
    $cur = strtotime($start);
    $end = strtotime($until);
    if ($cur === false || $end === false || $cur > $end) return $dates;

    $max = 400; // safety
    $i = 0;
    while ($cur <= $end && $i < $max) {
        $dates[] = date('Y-m-d', $cur);
        $i++;
        $cur = match ($frequency) {
            'daily'     => strtotime('+1 day', $cur),
            'weekly'    => strtotime('+1 week', $cur),
            'monthly'   => strtotime('+1 month', $cur),
            'quarterly' => strtotime('+3 months', $cur),
            'yearly'    => strtotime('+1 year', $cur),
            default     => $end + 1, // one_time → stop after first
        };
    }
    return $dates;
}

function profitAddExpenseCategory(PDO $pdo, string $name): string {
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('Category name is required.');
    $pdo->prepare("INSERT OR IGNORE INTO expense_categories (name, sort_order) VALUES (?, 999)")->execute([$name]);
    return $name;
}

/**
 * Create expense row(s). Recurring expenses generate dated occurrences without duplicate series dates.
 */
function profitSaveExpense(PDO $pdo, array $data, int $userId): int {
    $name = trim((string)($data['expense_name'] ?? ''));
    $category = trim((string)($data['category'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    $frequency = trim((string)($data['frequency'] ?? 'one_time'));
    if (!isset(profitExpenseFrequencies()[$frequency])) $frequency = 'one_time';
    $due = trim((string)($data['due_date'] ?? ''));
    $payDate = trim((string)($data['payment_date'] ?? ''));
    $payMethod = trim((string)($data['payment_method'] ?? ''));
    $status = strtolower(trim((string)($data['status'] ?? 'paid')));
    if (!isset(profitExpenseStatuses()[$status])) $status = 'paid';
    $notes = trim((string)($data['notes'] ?? ''));
    $start = trim((string)($data['expense_date'] ?? $due));
    if ($name === '' || $category === '' || $amount <= 0) {
        throw new InvalidArgumentException('Expense name, category and a positive amount are required.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $start = date('Y-m-d');
    }
    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = $start;
    if ($due === '') $due = $start;
    if ($payDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) $payDate = '';
    if ($status === 'paid' && $payDate === '') $payDate = $start;

    // Ensure category exists (expandable)
    profitAddExpenseCategory($pdo, $category);

    $ins = $pdo->prepare("
        INSERT INTO operating_expenses
            (category, description, amount, expense_date, created_by,
             expense_name, frequency, due_date, payment_date, payment_method, status, notes, series_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    if ($frequency === 'one_time') {
        $ins->execute([
            $category, $notes !== '' ? $notes : $name, $amount, $start, $userId ?: null,
            $name, $frequency, $due, $payDate !== '' ? $payDate : null,
            $payMethod !== '' ? $payMethod : null, $status, $notes !== '' ? $notes : null, null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    $seriesId = bin2hex(random_bytes(8));
    // Materialize occurrences from start through +24 months (or due start horizon)
    $until = date('Y-m-d', strtotime($start . ' +24 months'));
    $occ = profitNextOccurrenceDates($start, $frequency, $until);
    $created = 0;
    $firstId = 0;

    // Avoid duplicates: skip dates already present for same series name+category+amount+freq
    $exists = $pdo->prepare("
        SELECT 1 FROM operating_expenses
        WHERE category = ? AND amount = ? AND frequency = ?
          AND expense_date = ?
          AND COALESCE(expense_name, description, '') = ?
        LIMIT 1
    ");

    foreach ($occ as $d) {
        $exists->execute([$category, $amount, $frequency, $d, $name]);
        if ($exists->fetchColumn()) continue;

        $occStatus = $status;
        if ($status === 'pending' && $d < date('Y-m-d')) $occStatus = 'overdue';
        // Future occurrences default to pending unless user marked all paid
        if ($d > date('Y-m-d') && $status === 'paid') {
            $occStatus = 'pending';
        }
        $occPay = ($occStatus === 'paid') ? ($payDate !== '' ? $payDate : $d) : null;
        $ins->execute([
            $category, $notes !== '' ? $notes : $name, $amount, $d, $userId ?: null,
            $name, $frequency, $d, $occPay,
            $payMethod !== '' ? $payMethod : null, $occStatus, $notes !== '' ? $notes : null, $seriesId,
        ]);
        if (!$firstId) $firstId = (int)$pdo->lastInsertId();
        $created++;
    }
    if ($created === 0) {
        throw new InvalidArgumentException('Those recurring expense dates already exist — duplicates were skipped.');
    }
    return $firstId;
}

function profitUpdateExpense(PDO $pdo, int $id, array $data): void {
    $row = $pdo->prepare("SELECT * FROM operating_expenses WHERE id = ?");
    $row->execute([$id]);
    $cur = $row->fetch();
    if (!$cur) throw new InvalidArgumentException('Expense not found.');

    $name = trim((string)($data['expense_name'] ?? $cur['expense_name'] ?? $cur['description']));
    $category = trim((string)($data['category'] ?? $cur['category']));
    $amount = (float)($data['amount'] ?? $cur['amount']);
    $frequency = trim((string)($data['frequency'] ?? $cur['frequency'] ?? 'one_time'));
    $due = trim((string)($data['due_date'] ?? $cur['due_date'] ?? $cur['expense_date']));
    $payDate = trim((string)($data['payment_date'] ?? $cur['payment_date'] ?? ''));
    $payMethod = trim((string)($data['payment_method'] ?? $cur['payment_method'] ?? ''));
    $status = strtolower(trim((string)($data['status'] ?? $cur['status'] ?? 'paid')));
    $notes = trim((string)($data['notes'] ?? $cur['notes'] ?? ''));
    $expenseDate = trim((string)($data['expense_date'] ?? $cur['expense_date']));

    if (!isset(profitExpenseStatuses()[$status])) $status = 'paid';
    if ($status === 'paid' && $payDate === '') $payDate = $expenseDate;
    if ($category !== '') profitAddExpenseCategory($pdo, $category);

    $pdo->prepare("
        UPDATE operating_expenses SET
            expense_name = ?, category = ?, description = ?, amount = ?, expense_date = ?,
            frequency = ?, due_date = ?, payment_date = ?, payment_method = ?, status = ?, notes = ?
        WHERE id = ?
    ")->execute([
        $name, $category, $notes !== '' ? $notes : $name, $amount, $expenseDate,
        $frequency, $due !== '' ? $due : $expenseDate,
        $payDate !== '' ? $payDate : null,
        $payMethod !== '' ? $payMethod : null,
        $status, $notes !== '' ? $notes : null, $id,
    ]);
}

function profitDeleteExpense(PDO $pdo, int $id, bool $wholeSeries = false): void {
    if ($wholeSeries) {
        $row = $pdo->prepare("SELECT series_id FROM operating_expenses WHERE id = ?");
        $row->execute([$id]);
        $sid = $row->fetchColumn();
        if ($sid) {
            $pdo->prepare("DELETE FROM operating_expenses WHERE series_id = ?")->execute([$sid]);
            return;
        }
    }
    $pdo->prepare("DELETE FROM operating_expenses WHERE id = ?")->execute([$id]);
}

/** Build multi-section rows for Excel/PDF export of the full Profit report. */
function profitExportBundle(PDO $pdo, array $dates, array $filters, array $opts = []): array {
    $kpis = profitKpis($pdo, $dates, $filters);
    $core = $kpis['core'];
    $variance = profitVarianceExplain($kpis['core'], $kpis['prev_core']);
    $catSort = $opts['cat_sort'] ?? 'revenue';
    $prodSort = $opts['prod_sort'] ?? 'profit';
    $prodLimit = (int)($opts['prod_limit'] ?? 50);
    $marginMax = (float)($opts['margin_threshold'] ?? 25);
    $expFilters = $opts['exp_filters'] ?? ['category' => '', 'status' => '', 'payment_method' => ''];

    $categories = profitByCategory($pdo, $dates, $filters, $catSort);
    $products = profitProducts($pdo, $dates, $filters, $prodSort, $prodLimit);
    $low = profitProducts($pdo, $dates, $filters, 'margin', 100, $marginMax);
    $expenses = profitExpenseSummary($pdo, $dates['from'], $dates['to'], $expFilters);
    $payments = profitByPayment($pdo, $dates, $filters);
    $cashiers = profitByCashier($pdo, $dates, $filters);
    $salesTypes = profitBySalesType($pdo, $dates, $filters);
    $customers = profitByCustomer($pdo, $dates, $filters, 100);

    return compact('kpis', 'core', 'variance', 'categories', 'products', 'low', 'expenses', 'payments', 'cashiers', 'salesTypes', 'customers');
}
