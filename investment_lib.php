<?php
require_once __DIR__ . '/report_lib.php';
require_once __DIR__ . '/purchases_lib.php';

/**
 * ─── INVESTMENT & GROWTH ANALYSIS (read-only layer) ─────────────────────────
 *
 * Everything here only READS existing data (sales, batches, medicines,
 * purchases) and turns it into investment recommendations. Nothing in this
 * file mutates stock, prices, sales, payments, credit or suppliers.
 *
 * The only write anywhere in the module is creating a purchase DRAFT via the
 * existing createPurchase(... 'draft') — done explicitly from investment.php
 * after the user selects products, never automatically.
 *
 * The scoring model is transparent and configurable via invDefaultConfig().
 */

/** Tunable knobs for the recommendation engine (overridable per request). */
function invDefaultConfig(): array {
    return [
        'coverage_days'     => 45,  // target stock coverage after investing (days)
        'safety_days'       => 7,   // extra safety-stock days on top of coverage
        'max_stock_days'    => 120, // never recommend buying beyond this many days of stock
        'min_margin_pct'    => 12,  // below this gross margin, investment scores drop
        'expiry_risk_days'  => 90,  // stock expiring within this window is risky
        'score_min_sales'   => 3,   // units in period required to count as "selling"
        'dead_stock_days'   => 60,  // no sale in this many days => slow / dead stock
    ];
}

/** Merge user/GET config over the defaults, clamping to sane ranges. */
function invConfig(array $input = []): array {
    $cfg = invDefaultConfig();
    foreach ($cfg as $k => $def) {
        if (!isset($input[$k]) || !is_numeric($input[$k]) || (float)$input[$k] < 0) {
            continue;
        }
        $val = (int)(float)$input[$k];
        if ($k === 'min_margin_pct') {
            $cfg[$k] = max(0, min(100, $val));
        } elseif ($k === 'safety_days') {
            $cfg[$k] = max(0, min(365, $val));
        } else {
            $cfg[$k] = max(1, min(3650, $val));
        }
    }
    return $cfg;
}

/** Preserve analysis knobs (+ optional extras) across GET links/forms. */
function invConfigQueryParams(array $cfg, array $extra = []): array {
    $out = $extra;
    foreach (['coverage_days', 'safety_days', 'max_stock_days', 'expiry_risk_days', 'min_margin_pct', 'dead_stock_days'] as $k) {
        if (isset($cfg[$k])) {
            $out[$k] = $cfg[$k];
        }
    }
    return $out;
}

/** Per-product analysis rows for the period: sales, stock, expiry risk, purchase prices. */
function invProductRows(PDO $pdo, array $dates, array $filters, array $cfg): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $days = max(1, (int)$dates['days']);
    $ctx  = reportItemFilterContext($filters, $from, $to);

    $stmt = $pdo->prepare("
        SELECT m.id, m.name, m.generic_name, m.unit, m.reorder_level,
               COALESCE(m.product_type, c.product_type, 'medicine') AS product_type,
               COALESCE(c.name, 'Uncategorized') AS category,
               SUM(si.quantity) AS units_sold,
               SUM(si.subtotal) AS revenue,
               SUM(si.quantity * COALESCE(b.purchase_price, si.cost_price, 0)) AS cogs,
               SUM(si.subtotal) - SUM(si.quantity * COALESCE(b.purchase_price, si.cost_price, 0)) AS gross_profit
        FROM sale_items si
        {$ctx['joins']}
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE {$ctx['where']}
        GROUP BY m.id
    ");
    $stmt->execute($ctx['params']);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rows[(int)$r['id']] = [
            'id'            => (int)$r['id'],
            'name'          => $r['name'],
            'generic_name'  => $r['generic_name'] ?? '',
            'unit'          => $r['unit'] ?? 'pcs',
            'reorder_level' => (int)$r['reorder_level'],
            'product_type'  => $r['product_type'],
            'category'      => $r['category'],
            'units_sold'    => (int)$r['units_sold'],
            'revenue'       => (float)$r['revenue'],
            'cogs'          => (float)$r['cogs'],
            'gross_profit'  => (float)$r['gross_profit'],
        ];
    }

    // All active products so never-sold products also get recommendations.
    $prodWhere = [];
    $prodParams = [];
    if (!empty($filters['type'])) {
        $prodWhere[] = "COALESCE(m.product_type, 'medicine') = ?";
        $prodParams[] = $filters['type'];
    }
    if (!empty($filters['category'])) {
        $prodWhere[] = 'm.category_id = ?';
        $prodParams[] = (int)$filters['category'];
    }
    if (!empty($filters['product'])) {
        $prodWhere[] = 'm.id = ?';
        $prodParams[] = (int)$filters['product'];
    }
    if (!empty($filters['supplier'])) {
        $prodWhere[] = '(EXISTS (
                SELECT 1 FROM purchase_items pi0
                JOIN purchases p0 ON p0.id = pi0.purchase_id
                WHERE pi0.medicine_id = m.id AND p0.supplier_id = ? AND p0.status != \'cancelled\'
            ) OR EXISTS (
                SELECT 1 FROM batches b0 WHERE b0.medicine_id = m.id AND b0.supplier_id = ?
            ))';
        $prodParams[] = (int)$filters['supplier'];
        $prodParams[] = (int)$filters['supplier'];
    }
    $prodExtra = $prodWhere ? ('WHERE ' . implode(' AND ', $prodWhere)) : '';
    $st = $pdo->prepare("
        SELECT m.id,
               COALESCE(st.stock, 0) AS stock,
               COALESCE(st.stock_value, 0) AS stock_value,
               COALESCE(st.avg_buy, 0) AS avg_buy,
               COALESCE(st.avg_sell, 0) AS avg_sell,
               COALESCE(st.expiring_qty, 0) AS expiring_qty,
               st.next_expiry,
               COALESCE(pend.pending_qty, 0) AS pending_qty,
               COALESCE(sl.last_sale, '') AS last_sale,
               pr.avg_price, pr.low_price,
               (SELECT pi2.purchase_price FROM purchase_items pi2 JOIN purchases p2 ON p2.id = pi2.purchase_id
                 WHERE pi2.medicine_id = m.id AND p2.status != 'cancelled'
                 ORDER BY p2.purchase_date DESC, pi2.id DESC LIMIT 1) AS last_price,
               (SELECT p3.supplier_id FROM purchase_items pi3 JOIN purchases p3 ON p3.id = pi3.purchase_id
                 WHERE pi3.medicine_id = m.id AND p3.status != 'cancelled'
                 ORDER BY p3.purchase_date DESC, pi3.id DESC LIMIT 1) AS supplier_id,
               (SELECT s2.name FROM purchase_items pi4 JOIN purchases p4 ON p4.id = pi4.purchase_id
                 LEFT JOIN suppliers s2 ON s2.id = p4.supplier_id
                 WHERE pi4.medicine_id = m.id AND p4.status != 'cancelled'
                 ORDER BY p4.purchase_date DESC, pi4.id DESC LIMIT 1) AS supplier_name,
               COALESCE(m.reorder_level, 0) AS reorder_level,
               COALESCE(m.product_type, 'medicine') AS product_type,
               COALESCE(c.name, 'Uncategorized') AS category,
               m.name, m.generic_name, m.unit
        FROM medicines m
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN (
            SELECT medicine_id,
                   SUM(quantity) AS stock,
                   SUM(quantity * purchase_price) AS stock_value,
                   AVG(purchase_price) AS avg_buy,
                   AVG(selling_price) AS avg_sell,
                   SUM(CASE WHEN expiry_date < date('now', '+{$cfg['expiry_risk_days']} days') AND expiry_date < '9000-01-01' THEN quantity ELSE 0 END) AS expiring_qty,
                   MIN(CASE WHEN quantity > 0 AND expiry_date < '9000-01-01' THEN expiry_date END) AS next_expiry
            FROM batches GROUP BY medicine_id
        ) st ON st.medicine_id = m.id
        LEFT JOIN (
            SELECT medicine_id, SUM(quantity) AS pending_qty
            FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id
            WHERE p.status IN ('draft', 'pending_approval')
            GROUP BY medicine_id
        ) pend ON pend.medicine_id = m.id
        LEFT JOIN (
            SELECT medicine_id, MAX(s.created_at) AS last_sale
            FROM sale_items si JOIN sales s ON s.id = si.sale_id
            GROUP BY medicine_id
        ) sl ON sl.medicine_id = m.id
        LEFT JOIN (
            SELECT pi.medicine_id,
                   AVG(pi.purchase_price) AS avg_price,
                   MIN(pi.purchase_price) AS low_price
            FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id
            WHERE p.status != 'cancelled'
            GROUP BY pi.medicine_id
        ) pr ON pr.medicine_id = m.id
        {$prodExtra}
        ORDER BY m.name COLLATE NOCASE
    ");
    $st->execute($prodParams);

    $all = [];
    foreach ($st->fetchAll() as $r) {
        $id = (int)$r['id'];
        $sold = $rows[$id] ?? null;
        $all[$id] = [
            'id'             => $id,
            'name'           => $r['name'],
            'generic_name'   => $r['generic_name'] ?? '',
            'unit'           => $r['unit'] ?? 'pcs',
            'product_type'   => $r['product_type'],
            'category'       => $r['category'],
            'reorder_level'  => (int)$r['reorder_level'],
            'units_sold'     => $sold ? $sold['units_sold'] : 0,
            'revenue'        => $sold ? $sold['revenue'] : 0.0,
            'cogs'           => $sold ? $sold['cogs'] : 0.0,
            'gross_profit'   => $sold ? $sold['gross_profit'] : 0.0,
            'stock'          => (int)$r['stock'],
            'stock_value'    => (float)$r['stock_value'],
            'avg_sell'       => (float)$r['avg_sell'],
            'expiring_qty'   => (int)$r['expiring_qty'],
            'next_expiry'    => $r['next_expiry'] ?: null,
            'pending_qty'    => (int)$r['pending_qty'],
            'last_sale'      => $r['last_sale'] ?: null,
            'last_price'     => $r['last_price'] !== null ? (float)$r['last_price'] : null,
            'avg_price'      => $r['avg_price'] !== null ? (float)$r['avg_price'] : null,
            'low_price'      => $r['low_price'] !== null ? (float)$r['low_price'] : null,
            'supplier_id'    => $r['supplier_id'] !== null ? (int)$r['supplier_id'] : null,
            'supplier_name'  => $r['supplier_name'] ?? null,
        ];
    }
    return $all;
}

/** Per-supplier purchase price history for one product (best available option). */
function invProductSuppliers(PDO $pdo, int $medId, int $limit = 5): array {
    $st = $pdo->prepare("
        SELECT p.supplier_id,
               COALESCE(s.name, 'Unknown') AS supplier_name,
               pi.purchase_price,
               p.purchase_date
        FROM purchase_items pi
        JOIN purchases p ON p.id = pi.purchase_id
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE pi.medicine_id = ? AND p.status != 'cancelled'
        ORDER BY p.purchase_date DESC, pi.id DESC
    ");
    $st->execute([$medId]);
    $agg = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = $r['supplier_id'] !== null ? (string)$r['supplier_id'] : 'none';
        if (!isset($agg[$key])) {
            $agg[$key] = [
                'supplier_name' => $r['supplier_name'],
                'last_price'    => (float)$r['purchase_price'],
                'avg_price'     => 0.0,
                'low_price'     => (float)$r['purchase_price'],
                'orders'        => 0,
                'sum_price'     => 0.0,
                'last_purchase' => $r['purchase_date'],
            ];
        }
        $agg[$key]['orders']++;
        $agg[$key]['sum_price'] += (float)$r['purchase_price'];
        $agg[$key]['low_price'] = min($agg[$key]['low_price'], (float)$r['purchase_price']);
    }
    $rows = [];
    foreach ($agg as $a) {
        $a['avg_price'] = $a['orders'] > 0 ? $a['sum_price'] / $a['orders'] : 0;
        unset($a['sum_price']);
        $rows[] = $a;
    }
    usort($rows, fn($a, $b) => ((float)$a['low_price']) <=> ((float)$b['low_price']));
    return array_slice($rows, 0, $limit);
}

/** Recommendation verdict from coverage math. Returns [key, emoji, label, cssBadge]. */
function invRecommendation(array $m, array $cfg): array {
    $avgDaily = (float)($m['avg_daily'] ?? 0);
    $coverage = $avgDaily > 0 ? ($m['stock'] / $avgDaily) : ($m['stock'] > 0 ? 9999.0 : 0.0);

    // Selling product with no (or almost no) usable stock left.
    if ($avgDaily > 0 && ($m['units_sold'] ?? 0) >= $cfg['score_min_sales'] && $coverage <= 2) {
        return ['urgent', '🔥', 'BUY URGENTLY', 'badge-red'];
    }

    if ($avgDaily <= 0.01 && $m['stock'] > 0) {
        if (($m['last_sale_days'] ?? null) !== null && $m['last_sale_days'] >= $cfg['dead_stock_days']) {
            return ['dont_buy', '🔴', "DON'T BUY", 'badge-red'];
        }
        return ['reduce', '🟠', 'REDUCE', 'badge-orange'];
    }
    if ($avgDaily <= 0.01) {
        return ['dont_buy', '🔴', "DON'T BUY", 'badge-red'];
    }
    $overstocked = $coverage >= $cfg['max_stock_days'];
    if ($overstocked) {
        return ['dont_buy', '🔴', "DON'T BUY", 'badge-red'];
    }
    if ($coverage < 14 && ($m['margin_pct'] ?? 0) >= $cfg['min_margin_pct']) {
        return ['invest', '🟢', 'INVEST MORE', 'badge-green'];
    }
    if ($coverage < 21) {
        return ['invest', '🟢', 'INVEST MORE', 'badge-green'];
    }
    if ($coverage <= $cfg['coverage_days'] + 15) {
        return ['maintain', '🟡', 'MAINTAIN', 'badge-blue'];
    }
    return ['reduce', '🟠', 'REDUCE', 'badge-orange'];
}

/** Transparent 0–100 investment score: demand, margin, velocity, urgency, expiry penalty. */
function invInvestmentScore(array $m, array $cfg): int {
    $avgDaily = (float)($m['avg_daily'] ?? 0);
    $coverage = $avgDaily > 0 ? ($m['stock'] / $avgDaily) : ($m['stock'] > 0 ? 9999.0 : 0.0);

    // Demand (0-30): units sold relative to the strongest seller in the set.
    $demand = 30.0 * min(1.0, ($m['max_units'] ?? 0) > 0 ? ($m['units_sold'] / $m['max_units']) : 0);

    // Margin (0-25)
    $marginPct = (float)($m['margin_pct'] ?? 0);
    $margin = 25.0 * min(1.0, $marginPct / 40.0);

    // Velocity & urgency (0-25): low coverage = urgent restock need.
    $urgency = 0.0;
    if ($avgDaily > 0) {
        if ($coverage <= 0)      $urgency = 25.0;
        elseif ($coverage <= 7)  $urgency = 22.0;
        elseif ($coverage <= 14) $urgency = 18.0;
        elseif ($coverage <= 30) $urgency = 12.0;
        elseif ($coverage <= 60) $urgency = 6.0;
        elseif ($coverage <= 90) $urgency = 2.0;
    }

    // Growth (0-10)
    $growth = (float)($m['growth_pct'] ?? 0);
    $growthPts = 10.0 * max(-1.0, min(1.0, $growth / 50.0));

    // Expiry risk penalty (0-15): stock expiring soon should not attract money.
    $expiryPenalty = 0.0;
    if ($m['stock'] > 0 && $m['expiring_qty'] > 0) {
        $expiryPenalty = 15.0 * min(1.0, $m['expiring_qty'] / max(1, $m['stock']));
    }

    $score = $demand + $margin + $urgency + $growthPts - $expiryPenalty;
    return (int)round(max(0, min(100, $score)));
}

/** Short data-driven "why" lines for one product recommendation. */
function invWhyLines(array $m, array $cfg): array {
    $lines = [];
    $unit = $m['unit'] !== '' ? $m['unit'] : 'units';
    $avgDaily = (float)($m['avg_daily'] ?? 0);
    $coverage = $avgDaily > 0 ? $m['stock'] / $avgDaily : ($m['stock'] > 0 ? 9999.0 : 0.0);

    if (($m['units_sold'] ?? 0) >= $cfg['score_min_sales']) {
        $lines[] = 'Sold ' . number_format($m['units_sold']) . " {$unit} in the period (" . number_format($avgDaily, 1) . "/day)";
    } else {
        $lines[] = 'Almost no sales in the selected period';
    }
    if (($m['margin_pct'] ?? 0) > 0) {
        $lines[] = 'Gross margin ' . number_format($m['margin_pct'], 1) . '%';
    }
    if ($avgDaily > 0) {
        if ($m['stock'] <= 0) {
            $lines[] = 'Out of stock — every sale day is a missed day';
        } else {
            $lines[] = 'Current stock covers ~' . number_format($coverage, 0) . ' days of demand';
        }
    }
    if (($m['reorder_level'] ?? 0) > 0 && $m['stock'] <= $m['reorder_level'] && $m['stock'] > 0) {
        $lines[] = 'At/below reorder level (' . number_format($m['reorder_level']) . ')';
    }
    if (($m['growth_pct'] ?? 0) > 10) {
        $lines[] = 'Sales up ' . number_format($m['growth_pct'], 0) . '% vs previous period';
    } elseif (($m['growth_pct'] ?? 0) < -10) {
        $lines[] = 'Sales down ' . number_format(abs($m['growth_pct']), 0) . '% vs previous period';
    }
    if (($m['pending_qty'] ?? 0) > 0) {
        $lines[] = number_format($m['pending_qty']) . " {$unit} already in draft purchases";
    }
    if (($m['expiring_qty'] ?? 0) > 0 && $m['stock'] > 0) {
        $lines[] = number_format($m['expiring_qty']) . " {$unit} expire within {$cfg['expiry_risk_days']} days — do not overstock";
    }
    if (($m['last_price'] ?? null) !== null) {
        $lines[] = 'Last purchase ' . currency((float)$m['last_price']) . '/' . $unit . ($m['supplier_name'] ? ' from ' . $m['supplier_name'] : '');
    }
    return $lines;
}

/** Recommended purchase quantity + projected economics for one product. */
function invRecommendedPurchase(array $m, array $cfg): array {
    $avgDaily = (float)($m['avg_daily'] ?? 0);
    $usable = max(0, (int)$m['stock'] - (int)($m['expiring_qty'] ?? 0));
    $pending = max(0, (int)($m['pending_qty'] ?? 0));
    $reorder = (int)($m['reorder_level'] ?? 0);

    $target = (int)ceil($avgDaily * ($cfg['coverage_days'] + $cfg['safety_days']));
    // Never propose buying below the reorder floor when stock is already under it.
    $floor = ($reorder > 0 && $m['stock'] <= $reorder) ? ($reorder - max(0, (int)$m['stock'])) : 0;
    $recommended = max(0, $target - $usable - $pending, $floor);

    // Never recommend pushing total coverage beyond max_stock_days.
    if ($avgDaily > 0 && $recommended > 0) {
        $maxUnits = (int)floor($avgDaily * $cfg['max_stock_days']) - $usable - $pending;
        $recommended = max(0, min($recommended, $maxUnits));
    }
    // Round to a sane pack multiple when quantities get large.
    if ($recommended >= 50) {
        $recommended = (int)(round($recommended / 5) * 5);
    }

    $unitCost = (float)($m['last_price'] ?? $m['avg_price'] ?? $m['avg_buy'] ?? 0);
    $unitPrice = (float)$m['avg_sell'];
    if ($unitCost <= 0 && $unitPrice > 0) {
        $unitCost = $unitPrice * 0.75; // fall back to an assumed 25% margin
    }
    $cost = $recommended * $unitCost;
    $revenue = $recommended * $unitPrice;
    $profit = $recommended * max(0, $unitPrice - $unitCost);
    $roi = $cost > 0 ? ($profit / $cost) * 100 : 0;

    return [
        'recommended_qty'  => $recommended,
        'unit_cost'        => $unitCost,
        'unit_price'       => $unitPrice,
        'est_cost'         => round($cost, 2),
        'est_revenue'      => round($revenue, 2),
        'est_profit'       => round(max(0, $profit), 2),
        'est_roi'          => round($roi, 1),
        'target_units'     => $target,
        'usable_stock'     => $usable,
        'pending_qty'      => $pending,
    ];
}

/** Aggregate products into category-level investment rows. */
function invCategoryRows(array $rows, array $cfg): array {
    $cats = [];
    foreach ($rows as $m) {
        $key = $m['category'] ?: 'Uncategorized';
        if (!isset($cats[$key])) {
            $cats[$key] = [
                'category' => $key, 'product_type' => $m['product_type'],
                'sales' => 0.0, 'profit' => 0.0, 'stock_value' => 0.0, 'units_sold' => 0,
                'prev_units' => 0, 'invest' => 0.0, 'rec_count' => 0, 'products' => 0,
            ];
        }
        $c = &$cats[$key];
        $c['products']++;
        $c['sales'] += $m['revenue'];
        $c['profit'] += $m['gross_profit'];
        $c['stock_value'] += $m['stock_value'];
        $c['units_sold'] += $m['units_sold'];
        $c['prev_units'] += (int)($m['prev_units'] ?? 0);
        $rp = $m['purchase'] ?? invRecommendedPurchase($m, $cfg);
        $recKey = $m['rec'][0] ?? '';
        if ($rp['recommended_qty'] > 0 && in_array($recKey, ['invest', 'urgent', 'maintain'], true)) {
            $c['invest'] += $rp['est_cost'];
        }
        if ($rp['recommended_qty'] > 0 && in_array($recKey, ['invest', 'urgent'], true)) {
            $c['rec_count']++;
        }
    }

    $out = [];
    $maxSales = max(1.0, max(array_column($cats, 'sales') ?: [1.0]));
    foreach ($cats as $c) {
        $c['margin_pct'] = $c['sales'] > 0 ? ($c['profit'] / $c['sales']) * 100 : 0;
        // Turnover approximation: COGS sold / average inventory value at cost.
        $c['turnover'] = $c['stock_value'] > 0 ? ($c['sales'] - $c['profit']) / $c['stock_value'] : ($c['sales'] > 0 ? 99.0 : 0.0);
        $c['growth_pct'] = $c['prev_units'] > 0
            ? (($c['units_sold'] - $c['prev_units']) / $c['prev_units']) * 100
            : ($c['units_sold'] > 0 ? 100.0 : 0.0);
        $c['score'] = (int)round(max(0, min(100,
            35 * ($c['sales'] / $maxSales)
            + 20 * min(1, max(0, $c['margin_pct']) / 40)
            + 15 * min(1.5, $c['turnover']) / 1.5
            + 15 * min(1, $c['rec_count'] / max(1, $c['products']))
            + 15 * max(-1, min(1, $c['growth_pct'] / 50))
        )));
        $c['invest'] = round($c['invest'], 2);
        $out[] = $c;
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return $out;
}

/** Products selling well but running out of stock → estimated missed sales. */
function invStockoutRows(array $rows, array $cfg): array {
    $out = [];
    foreach ($rows as $m) {
        $avgDaily = (float)$m['avg_daily'];
        if ($avgDaily <= 0 || $m['units_sold'] < $cfg['score_min_sales']) continue;
        $coverage = $m['stock'] / $avgDaily;
        if ($coverage >= $cfg['coverage_days']) continue;

        $risk = $coverage <= 3 ? 'Critical' : ($coverage <= 7 ? 'High' : ($coverage <= 14 ? 'Medium' : 'Low'));
        $riskBadge = $coverage <= 3 ? 'badge-red' : ($coverage <= 7 ? 'badge-red' : ($coverage <= 14 ? 'badge-orange' : 'badge-blue'));
        $window = min($cfg['coverage_days'], 60); // estimate horizon
        $missedUnits = (int)ceil(max(0, $window - $coverage) * $avgDaily);
        $unitPrice = (float)$m['avg_sell'];
        $unitCost = (float)($m['avg_price'] ?? $m['avg_buy'] ?? 0);
        $out[] = [
            'row' => $m,
            'stockout_risk' => $risk,
            'risk_badge' => $riskBadge,
            'days_remaining' => (int)floor($coverage),
            'missed_units' => $missedUnits,
            'missed_revenue' => round($missedUnits * $unitPrice, 2),
            'missed_profit' => round($missedUnits * max(0, $unitPrice - $unitCost), 2),
        ];
    }
    usort($out, fn($a, $b) => $b['missed_profit'] <=> $a['missed_profit']);
    return $out;
}

/** Slow / dead stock: money tied up in inventory that is not moving. */
function invSlowStockRows(array $rows, array $cfg): array {
    $out = [];
    foreach ($rows as $m) {
        if ($m['stock'] <= 0) continue;
        $lastDays = $m['last_sale_days'];
        $isDead = $m['avg_daily'] <= 0.01;
        $isSlow = !$isDead && ($m['last_sale_days'] === null || $m['last_sale_days'] >= $cfg['dead_stock_days'] || $m['units_sold'] < $cfg['score_min_sales']);
        if (!$isDead && !$isSlow) continue;

        $rec = $isDead ? ($m['last_sale_days'] !== null && $m['last_sale_days'] >= $cfg['dead_stock_days'] * 2 ? 'CLEARANCE REVIEW' : 'STOP REORDERING')
                       : ($m['stock_value'] > 0 && $m['avg_daily'] > 0 && ($m['stock'] / $m['avg_daily']) > $cfg['max_stock_days'] ? 'REDUCE PURCHASE' : 'MAINTAIN');
        $recBadge = $rec === 'CLEARANCE REVIEW' ? 'badge-red' : ($rec === 'STOP REORDERING' ? 'badge-red' : ($rec === 'REDUCE PURCHASE' ? 'badge-orange' : 'badge-blue'));
        $out[] = [
            'row' => $m,
            'since_label' => $m['last_sale'] ? date('M j, Y', strtotime($m['last_sale'])) : 'Never',
            'days_since' => $m['last_sale_days'],
            'recommendation' => $rec,
            'rec_badge' => $recBadge,
        ];
    }
    usort($out, fn($a, $b) => ($b['row']['stock_value'] ?? 0) <=> ($a['row']['stock_value'] ?? 0));
    return $out;
}

/** Expiry-aware risk: high stock + low expected sales before expiry → excess. */
function invExpiryRiskRows(array $rows, array $cfg): array {
    $out = [];
    foreach ($rows as $m) {
        if ($m['stock'] <= 0 || !$m['next_expiry']) continue;
        $daysLeft = expiryDaysRemaining($m['next_expiry']);
        if ($daysLeft === null || $daysLeft < 0) {
            $daysLeft = $daysLeft ?? -1;
        }
        if ($daysLeft < 0) {
            // Already expired stock is handled by Slow/Dead stock & reports; skip here.
            continue;
        }
        if ($daysLeft === null || $daysLeft > $cfg['expiry_risk_days'] * 2) continue;

        $avgDaily = (float)$m['avg_daily'];
        $expected = $avgDaily * $daysLeft;
        $excess = max(0, $m['stock'] - $expected);
        if ($daysLeft < 0) continue;
        if ($excess < max(1, $m['stock'] * 0.2)) continue; // only meaningful excess

        $rec = $avgDaily <= 0.01 ? 'CLEARANCE REVIEW'
            : ($daysLeft <= 30 ? 'PROMOTE / DISCOUNT REVIEW'
            : ($excess > $expected ? 'STOP REORDERING' : 'MONITOR'));
        $out[] = [
            'row' => $m,
            'expiry_date' => $m['next_expiry'],
            'days_until' => $daysLeft,
            'avg_sales' => $avgDaily,
            'expected_sales' => (int)round($expected),
            'excess_stock' => (int)round($excess),
            'recommendation' => $rec,
        ];
    }
    usort($out, fn($a, $b) => $a['days_until'] <=> $b['days_until']);
    return $out;
}

/** Build the full analysis payload used by investment.php. */
function invAnalyze(PDO $pdo, array $dates, array $filters, array $cfg): array {
    $rows = invProductRows($pdo, $dates, $filters, $cfg);
    $days = max(1, (int)$dates['days']);
    $prevMap = invPreviousPeriodUnits($pdo, $dates, $filters);

    $maxUnits = 0;
    foreach ($rows as &$m) {
        $m['avg_daily'] = $m['units_sold'] / $days;
        $m['margin_pct'] = $m['revenue'] > 0 ? ($m['gross_profit'] / $m['revenue']) * 100 : 0;
        $m['last_sale_days'] = $m['last_sale'] ? (int)floor((time() - strtotime($m['last_sale'])) / 86400) : null;
        $m['prev_units'] = $prevMap[$m['id']] ?? 0;
    }
    unset($m);
    foreach ($rows as $m) {
        $maxUnits = max($maxUnits, $m['units_sold']);
    }
    foreach ($rows as &$m) {
        $m['max_units'] = $maxUnits;
        $prevUnits = $m['prev_units'];
        $m['growth_pct'] = $prevUnits > 0 ? (($m['units_sold'] - $prevUnits) / $prevUnits) * 100 : ($m['units_sold'] > 0 ? 100.0 : 0.0);
        $m['coverage'] = $m['avg_daily'] > 0 ? $m['stock'] / $m['avg_daily'] : ($m['stock'] > 0 ? 9999.0 : 0.0);
        $m['turnover'] = $m['stock_value'] > 0 ? ($m['cogs'] > 0 ? $m['cogs'] : $m['revenue'] - $m['gross_profit']) / $m['stock_value'] : 0;
        $m['score'] = invInvestmentScore($m, $cfg);
        $m['rec'] = invRecommendation($m, $cfg);
        $m['why'] = invWhyLines($m, $cfg);
        $m['purchase'] = invRecommendedPurchase($m, $cfg);
    }
    unset($m);

    // ── Top summary ──
    $totalStockValue = array_sum(array_column($rows, 'stock_value'));
    $totalMissedProfit = 0.0;
    $totalMissedRevenue = 0.0;
    foreach (invStockoutRows($rows, $cfg) as $s) {
        $totalMissedRevenue += $s['missed_revenue'];
        $totalMissedProfit += $s['missed_profit'];
    }
    $recommended = array_values(array_filter($rows, fn($m) => in_array($m['rec'][0], ['invest', 'urgent'], true) && $m['purchase']['recommended_qty'] > 0));
    usort($recommended, fn($a, $b) => $b['score'] <=> $a['score']);
    $totalInvest = array_sum(array_column(array_map(fn($m) => $m['purchase'], $recommended), 'est_cost'));
    $totalProfit = array_sum(array_column(array_map(fn($m) => $m['purchase'], $recommended), 'est_profit'));

    return [
        'rows'        => $rows,
        'recommended' => $recommended,
        'summary'     => [
            'stock_value'      => round($totalStockValue, 2),
            'rec_count'        => count($recommended),
            'invest'           => round($totalInvest, 2),
            'revenue'          => round($totalInvest + $totalProfit, 2),
            'profit'           => round($totalProfit, 2),
            'roi'              => $totalInvest > 0 ? round($totalProfit / $totalInvest * 100, 1) : 0.0,
            'missed_revenue'   => round($totalMissedRevenue, 2),
            'missed_profit'    => round($totalMissedProfit, 2),
        ],
        'categories'  => invCategoryRows($rows, $cfg),
        'stockouts'   => invStockoutRows($rows, $cfg),
        'slow'        => invSlowStockRows($rows, $cfg),
        'expiry_risk' => invExpiryRiskRows($rows, $cfg),
        'config'      => $cfg,
    ];
}

/** Units sold per medicine in the equal-length period before the selected one (single grouped query). */
function invPreviousPeriodUnits(PDO $pdo, array $dates, array $filters): array {
    $from = $dates['prevFrom'];
    $to   = $dates['prevTo'];
    $day  = reportLocalDateExpr('s');
    $sql = "
        SELECT si.medicine_id, SUM(si.quantity) AS units
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        LEFT JOIN batches b ON b.id = si.batch_id
        WHERE {$day} BETWEEN ? AND ?
    ";
    $params = [$from, $to];
    if (!empty($filters['supplier'])) {
        $sql .= ' AND b.supplier_id = ?';
        $params[] = (int)$filters['supplier'];
    }
    $sql .= ' GROUP BY si.medicine_id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(int)$r['medicine_id']] = (int)$r['units'];
    }
    return $map;
}

/**
 * Detail payload for the "Product Investment Details" modal.
 * Accepts the precomputed rows from invAnalyze() so nothing is recomputed.
 */
function invProductDetail(array $rows, PDO $pdo, int $medId): ?array {
    foreach ($rows as $m) {
        if ($m['id'] === $medId) {
            $m['suppliers'] = invProductSuppliers($pdo, $medId);
            return $m;
        }
    }
    return null;
}

/** Greedy budget allocation for the simulator: best score-per-cost first. */
function invSimulate(array $recommended, float $budget): array {
    $pool = array_values(array_filter($recommended, fn($m) => $m['purchase']['est_cost'] > 0 && $m['purchase']['recommended_qty'] > 0));
    // Spend on the highest investment score first, then the best ROI.
    usort($pool, fn($a, $b) => [$b['score'], $b['purchase']['est_roi']] <=> [$a['score'], $a['purchase']['est_roi']]);
    $plan = [];
    $remaining = $budget;
    foreach ($pool as $m) {
        if ($remaining <= 0) break;
        $cost = $m['purchase']['est_cost'];
        if ($cost <= $remaining) {
            $plan[] = ['row' => $m, 'qty' => $m['purchase']['recommended_qty'], 'cost' => $cost,
                       'revenue' => $m['purchase']['est_revenue'], 'profit' => $m['purchase']['est_profit']];
            $remaining -= $cost;
        } else {
            // Partial fill: buy what the remaining budget allows (at least 1 unit).
            $unitCost = $m['purchase']['est_cost'] / max(1, $m['purchase']['recommended_qty']);
            $qty = (int)floor($remaining / $unitCost);
            if ($qty >= 1) {
                $plan[] = ['row' => $m, 'qty' => $qty, 'cost' => round($qty * $unitCost, 2),
                           'revenue' => round($qty * $m['purchase']['unit_price'], 2),
                           'profit' => round($qty * ($m['purchase']['unit_price'] - $unitCost), 2)];
                $remaining -= $qty * $unitCost;
            }
        }
    }
    $cost = array_sum(array_column($plan, 'cost'));
    $revenue = array_sum(array_column($plan, 'revenue'));
    $profit = array_sum(array_column($plan, 'profit'));
    $cats = [];
    foreach ($plan as $p) {
        $cats[$p['row']['category']] = ($cats[$p['row']['category']] ?? 0) + $p['cost'];
    }
    arsort($cats);
    return [
        'plan'     => $plan,
        'cost'     => round($cost, 2),
        'revenue'  => round($revenue, 2),
        'profit'   => round($profit, 2),
        'roi'      => $cost > 0 ? round($profit / $cost * 100, 1) : 0.0,
        'unspent'  => round(max(0, $budget - $cost), 2),
        'cats'     => $cats,
    ];
}

/**
 * The ONLY write path in this module: turn selected products into a purchase
 * DRAFT using the existing createPurchase() with save_intent=draft.
 * It never receives or confirms the purchase — stock is untouched until
 * somebody with permission receives it from the normal Purchases screen.
 *
 * Batch numbers are auto-generated with the existing nextInternalBatchNumber()
 * helper (same pattern the purchase form uses for cosmetics/equipment) and the
 * "no expiry" sentinel is used so the draft validates; the notes field tells
 * the buyer to review batch/expiry before receiving.
 */
function invCreatePurchaseDraft(PDO $pdo, array $post, int $userId): int {
    if (!function_exists('can') || !can('purchases.manage')) {
        throw new RuntimeException('You do not have permission to create purchase drafts.');
    }
    $selected = $post['inv_sel'] ?? [];
    $items = [];
    foreach (($post['inv_qty'] ?? []) as $medId => $qty) {
        $medId = (int)$medId;
        $qty = (int)$qty;
        if ($medId <= 0 || $qty <= 0) continue;
        // Only include rows the user explicitly checked.
        if (!isset($selected[$medId]) && !isset($selected[(string)$medId])) continue;
        $items[$medId] = $qty;
    }
    if (!$items) {
        throw new RuntimeException('Select at least one product with a quantity.');
    }
    $ids = array_keys($items);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name, COALESCE(product_type,'medicine') AS product_type FROM medicines WHERE id IN ($ph)");
    $st->execute($ids);
    $meds = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $meds[(int)$r['id']] = $r;
    }

    // Last purchase price / current avg selling price per product (cheap lookups).
    $meta = [];
    $mk = $pdo->prepare("
        SELECT COALESCE(m.product_type,'medicine') AS product_type,
               (SELECT pi2.purchase_price FROM purchase_items pi2 JOIN purchases p2 ON p2.id = pi2.purchase_id
                 WHERE pi2.medicine_id = m.id AND p2.status != 'cancelled'
                 ORDER BY p2.purchase_date DESC, pi2.id DESC LIMIT 1) AS last_price,
               (SELECT AVG(b.selling_price) FROM batches b WHERE b.medicine_id = m.id) AS avg_sell
        FROM medicines m WHERE m.id = ?
    ");
    foreach ($ids as $mid) {
        $mk->execute([$mid]);
        $meta[$mid] = $mk->fetch(PDO::FETCH_ASSOC) ?: ['product_type' => 'medicine', 'last_price' => null, 'avg_sell' => null];
    }

    // Build the exact $_POST shape createPurchase()/parsePurchaseItemsFromPost() expects.
    $payload = [
        'supplier_id'      => (int)($post['supplier_id'] ?? 0) ?: '',
        'purchase_date'    => businessToday(),
        'payment_terms'    => '30',
        'due_date'         => '',
        'reference'        => 'Investment plan ' . date('M j, Y'),
        'warehouse'        => 'Main Store',
        'notes'            => 'Created from Investment & Growth recommendations on ' . date('M j, Y H:i') . ' — DRAFT ONLY. Review batch numbers, expiry dates and selling prices before receiving.',
        'payment_type'     => 'credit',
        'amount_paid'      => '0',
        'payment_method'   => 'cash',
        'payment_reference'=> '',
        'save_intent'      => 'draft',
        'header_discount'  => '0',
        'header_tax'       => '0',
    ];
    foreach ($items as $medId => $qty) {
        $type = $meta[$medId]['product_type'] ?? 'medicine';
        $sell = (float)($meta[$medId]['avg_sell'] ?? 0);
        $price = (float)($meta[$medId]['last_price'] ?? 0);
        // Batch reference: auto-generated for cosmetics/equipment by the existing
        // purchase logic; medicines need one up front, so generate an internal one.
        $batch = $type === 'medicine' ? nextInternalBatchNumber($pdo, 'INV') : '';
        // Expiry: medicines require one even for drafts — use the existing
        // "no expiry" sentinel and flag it in the notes for review.
        $expiry = $type === 'medicine' ? noExpiryDate() : '';
        $payload['medicine_id'][]        = $medId;
        $payload['batch_number'][]       = $batch;
        $payload['manufacturing_date'][] = '';
        $payload['expiry_date'][]        = $expiry;
        $payload['quantity'][]           = $qty;
        $payload['purchase_price'][]     = $price > 0 ? $price : 0;
        $payload['selling_price'][]      = $sell > 0 ? $sell : '';
        $payload['variant'][]            = '';
        $payload['model_number'][]       = '';
        $payload['serial_number'][]      = '';
    }

    return createPurchase($pdo, $payload, $userId);
}
