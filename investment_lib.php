<?php
require_once __DIR__ . '/report_lib.php';
require_once __DIR__ . '/purchases_lib.php';

/**
 * ─── INVESTMENT & GROWTH — DECISION ENGINE (read-only) ──────────────────────
 *
 * Everything in this file only READS existing ERP data (sales, sale_items,
 * batches, medicines, categories, purchases, suppliers) and turns it into
 * inventory-investment recommendations. Nothing here mutates stock, prices,
 * sales, payments, credit, suppliers or any existing transaction.
 *
 * The only write anywhere in the module is creating a purchase DRAFT via the
 * existing createPurchase(... 'draft') — triggered explicitly from
 * investment.php after the user selects products, never automatically.
 *
 * SINGLE SOURCE OF TRUTH
 * ──────────────────────
 * Sales/COGS come from the exact same tables + filter context the Sales,
 * Product and Profit reports use (reportItemFilterContext / reportLocalDateExpr),
 * so best-sellers here match best-sellers there. Stock and expiry come from
 * `batches` (the same source the Inventory report uses).
 *
 * HISTORY-AWARE DEMAND (the core fix)
 * ───────────────────────────────────
 * The selected reporting period is NOT the same thing as available ERP
 * history. With a young ERP, "24 units / 30 calendar days = 0.80/day" badly
 * understates demand and would mark a proven best-seller as overstocked.
 *
 * We therefore keep the calendar metric (labelled) and add:
 *   • active selling velocity  = units / days that actually sold
 *   • in-stock velocity        = units / estimated in-stock days
 *   • recent velocity          = last 7 / 14 / 30 days (only when supported)
 *   • forecast velocity        = weighted blend, spike-capped, plus a data
 *                                confidence rating (HIGH / MEDIUM / LOW)
 */

/* ═══════════════════════════════════════════════════════════════════════════
   CONFIGURATION
   ═══════════════════════════════════════════════════════════════════════════ */

/** Tunable knobs for the recommendation engine (overridable per request). */
function invDefaultConfig(): array {
    return [
        'coverage_days'    => 45, // target stock coverage after investing (target stock days)
        'safety_days'      => 7,  // extra safety-stock days on top of target coverage
        'max_stock_days'   => 120,// never let total coverage exceed this
        'horizon_days'     => 30, // forecast horizon for expected revenue/profit (next N days)
        'urgent_days'      => 7,  // at/below this coverage a proven seller is "buy urgently"
        'min_margin_pct'   => 12, // below this gross margin, investment priority drops
        'expiry_risk_days' => 90, // stock expiring within this window counts as risky
        'score_min_sales'  => 3,  // units in period required to count as "proven demand"
        'dead_stock_days'  => 60, // no sale in this many days => slow / dead stock
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
        } elseif ($k === 'urgent_days') {
            $cfg[$k] = max(1, min(60, $val));
        } else {
            $cfg[$k] = max(1, min(3650, $val));
        }
    }
    return $cfg;
}

/** Preserve analysis knobs (+ optional extras) across GET links/forms. */
function invConfigQueryParams(array $cfg, array $extra = []): array {
    $out = $extra;
    foreach (['coverage_days', 'safety_days', 'max_stock_days', 'horizon_days', 'urgent_days', 'expiry_risk_days', 'min_margin_pct', 'dead_stock_days'] as $k) {
        if (isset($cfg[$k])) {
            $out[$k] = $cfg[$k];
        }
    }
    return $out;
}

/* ═══════════════════════════════════════════════════════════════════════════
   SMALL DATE / MATH HELPERS
   ═══════════════════════════════════════════════════════════════════════════ */

function invDaysBetween(string $from, string $to): int {
    $a = strtotime($from . ' 00:00:00');
    $b = strtotime($to . ' 00:00:00');
    return (int)floor(($b - $a) / 86400);
}

function invClamp(float $v, float $lo, float $hi): float {
    return max($lo, min($hi, $v));
}

/** Formats a nullable percentage with sign, or N/A / a custom note. */
function invPct($v, int $dec = 0, string $fallback = 'N/A'): string {
    if ($v === null) return $fallback;
    return ($v >= 0 ? '+' : '') . number_format((float)$v, $dec) . '%';
}

/* ═══════════════════════════════════════════════════════════════════════════
   AVAILABLE HISTORY  (ERP history ≠ selected reporting period)
   ═══════════════════════════════════════════════════════════════════════════ */

/** Global available ERP sales history (non-voided sales) + first stock date. */
function invErpHistory(PDO $pdo): array {
    $row = $pdo->query("
        SELECT MIN(date(created_at, '+3 hours')) AS first_day,
               MAX(date(created_at, '+3 hours')) AS last_day,
               COUNT(*) AS sales_count,
               COUNT(DISTINCT date(created_at, '+3 hours')) AS trading_days
        FROM sales
        WHERE COALESCE(status, 'active') != 'voided'
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $first = $row['first_day'] ?? null;
    $last  = $row['last_day'] ?? null;
    $stockFirst = $pdo->query("SELECT MIN(date(created_at, '+3 hours')) FROM batches")->fetchColumn() ?: null;

    return [
        'first'       => $first ?: null,
        'last'        => $last ?: null,
        'days'        => ($first && $last) ? (invDaysBetween($first, $last) + 1) : 0,
        'trading_days'=> (int)($row['trading_days'] ?? 0),
        'sales_count' => (int)($row['sales_count'] ?? 0),
        'stock_first' => $stockFirst ?: null,
    ];
}

/**
 * Resolves the selected period against the ERP's real history.
 * The analysis window is the intersection of the selected period with the
 * dates the ERP actually has data for — never a pretend 30-day history.
 */
function invHistoryWindow(PDO $pdo, array $dates): array {
    $erp   = invErpHistory($pdo);
    $today = date('Y-m-d');

    $periodFrom = $dates['from'];
    $periodTo   = $dates['to'];
    $periodDays = max(1, (int)$dates['days']);

    $analysisFrom = $erp['first'] ? max($periodFrom, $erp['first']) : $periodFrom;
    $analysisTo   = min($periodTo, $today);
    $empty        = ($analysisTo < $analysisFrom);

    $analysisDays = $empty ? 0 : (invDaysBetween($analysisFrom, $analysisTo) + 1);

    // Did the equal-length previous period contain any ERP sales at all?
    $prevNow = reportLocalDateExpr('s');
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM sales s
        WHERE $prevNow BETWEEN ? AND ? AND COALESCE(s.status, 'active') != 'voided'
    ");
    $st->execute([$dates['prevFrom'], $dates['prevTo']]);
    $prevSales = (int)$st->fetchColumn();

    return [
        'erp_first'       => $erp['first'],
        'erp_last'        => $erp['last'],
        'erp_days'        => $erp['days'],
        'erp_trading_days'=> $erp['trading_days'],
        'stock_since'     => $erp['stock_first'],
        'period_from'     => $periodFrom,
        'period_to'       => $periodTo,
        'period_days'     => $periodDays,
        'analysis_from'   => $analysisFrom,
        'analysis_to'     => $analysisTo,
        'analysis_days'   => $analysisDays,
        'analysis_empty'  => $empty,
        'has_prev_sales'  => $prevSales > 0,
        'prev_sales_count'=> $prevSales,
        'full_period'     => !$empty && $analysisDays >= $periodDays,
    ];
}

/* ═══════════════════════════════════════════════════════════════════════════
   RAW DATA READERS (one query each — no per-product recomputation)
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Per-medicine × per-day sales series inside the selected period, using the
 * same joins/where as the Product/Profit reports. Returns:
 *   [medicineId => ['YYYY-MM-DD' => ['qty','revenue','cogs','txn'], ...]]
 */
function invDailySales(PDO $pdo, array $dates, array $filters): array {
    $ctx = reportItemFilterContext($filters, $dates['from'], $dates['to']);
    $day = reportLocalDateExpr('s');
    $sql = "
        SELECT si.medicine_id AS mid, $day AS day,
               SUM(si.quantity) AS qty,
               SUM(si.subtotal) AS revenue,
               SUM(si.quantity * COALESCE(b.purchase_price, si.cost_price, 0)) AS cogs,
               COUNT(DISTINCT s.id) AS txn
        FROM sale_items si
        {$ctx['joins']}
        WHERE {$ctx['where']}
        GROUP BY mid, day
    ";
    $st = $pdo->prepare($sql);
    $st->execute($ctx['params']);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mid = (int)$r['mid'];
        $out[$mid][$r['day']] = [
            'qty'     => (int)$r['qty'],
            'revenue' => (float)$r['revenue'],
            'cogs'    => (float)$r['cogs'],
            'txn'     => (int)$r['txn'],
        ];
    }
    return $out;
}

/**
 * Product metadata + stock + expiry + incoming + purchase prices, for every
 * product matching the filters (so never-sold products get recommendations too).
 * Sales figures are merged in from invDailySales() to avoid duplicating logic.
 */
function invProductMeta(PDO $pdo, array $dates, array $filters, array $cfg): array {
    $from = $dates['from'];
    $to   = $dates['to'];
    $riskDays = (int)$cfg['expiry_risk_days'];

    $prodWhere  = [];
    $prodParams = [];
    if (!empty($filters['type'])) {
        $prodWhere[]  = "COALESCE(m.product_type, c.product_type, 'medicine') = ?";
        $prodParams[] = $filters['type'];
    }
    if (!empty($filters['category'])) {
        $prodWhere[]  = 'm.category_id = ?';
        $prodParams[] = (int)$filters['category'];
    }
    if (!empty($filters['product'])) {
        $prodWhere[]  = 'm.id = ?';
        $prodParams[] = (int)$filters['product'];
    }
    if (!empty($filters['supplier'])) {
        // Product set restricted to products this supplier has supplied.
        $prodWhere[] = "(EXISTS (
                SELECT 1 FROM purchase_items pi0
                JOIN purchases p0 ON p0.id = pi0.purchase_id
                WHERE pi0.medicine_id = m.id AND p0.supplier_id = ? AND p0.status != 'cancelled'
            ) OR EXISTS (
                SELECT 1 FROM batches b0 WHERE b0.medicine_id = m.id AND b0.supplier_id = ?
            ))";
        $prodParams[] = (int)$filters['supplier'];
        $prodParams[] = (int)$filters['supplier'];
    }
    $prodExtra = $prodWhere ? ('WHERE ' . implode(' AND ', $prodWhere)) : '';

    // NOTE: the two ? in the SELECT list (received value within period) bind
    // before the WHERE placeholders, so params are built in that order.
    $sql = "
        SELECT m.id, m.name, m.generic_name, m.unit,
               COALESCE(m.reorder_level, 0) AS reorder_level,
               COALESCE(m.product_type, c.product_type, 'medicine') AS product_type,
               COALESCE(c.name, 'Uncategorized') AS category,
               COALESCE(st.stock, 0) AS stock,
               COALESCE(st.stock_value, 0) AS stock_value,
               COALESCE(st.avg_buy, 0) AS avg_buy,
               COALESCE(st.avg_sell, 0) AS avg_sell,
               COALESCE(st.expired_qty, 0) AS expired_qty,
               COALESCE(st.at_risk_qty, 0) AS at_risk_qty,
               st.next_expiry,
               COALESCE(st.stock_since, '') AS stock_since,
               COALESCE(pend.pending_qty, 0) AS pending_qty,
               COALESCE(sl.last_sale, '') AS last_sale,
               pr.avg_price, pr.low_price,
               recv.received_value,
               (SELECT pi2.purchase_price FROM purchase_items pi2 JOIN purchases p2 ON p2.id = pi2.purchase_id
                 WHERE pi2.medicine_id = m.id AND p2.status != 'cancelled'
                 ORDER BY p2.purchase_date DESC, pi2.id DESC LIMIT 1) AS last_price,
               (SELECT p3.supplier_id FROM purchase_items pi3 JOIN purchases p3 ON p3.id = pi3.purchase_id
                 WHERE pi3.medicine_id = m.id AND p3.status != 'cancelled'
                 ORDER BY p3.purchase_date DESC, pi3.id DESC LIMIT 1) AS supplier_id,
               (SELECT s2.name FROM purchase_items pi4 JOIN purchases p4 ON p4.id = pi4.purchase_id
                 LEFT JOIN suppliers s2 ON s2.id = p4.supplier_id
                 WHERE pi4.medicine_id = m.id AND p4.status != 'cancelled'
                 ORDER BY p4.purchase_date DESC, pi4.id DESC LIMIT 1) AS supplier_name
        FROM medicines m
        LEFT JOIN categories c ON c.id = m.category_id
        LEFT JOIN (
            SELECT medicine_id,
                   SUM(quantity) AS stock,
                   SUM(quantity * purchase_price) AS stock_value,
                   AVG(purchase_price) AS avg_buy,
                   AVG(selling_price) AS avg_sell,
                   MIN(date(created_at, '+3 hours')) AS stock_since,
                   SUM(CASE WHEN expiry_date < date('now') AND expiry_date < '9000-01-01' THEN quantity ELSE 0 END) AS expired_qty,
                   SUM(CASE WHEN expiry_date >= date('now') AND expiry_date < date('now', '+{$riskDays} days') AND expiry_date < '9000-01-01' THEN quantity ELSE 0 END) AS at_risk_qty,
                   MIN(CASE WHEN quantity > 0 AND expiry_date >= date('now') AND expiry_date < '9000-01-01' THEN expiry_date END) AS next_expiry
            FROM batches GROUP BY medicine_id
        ) st ON st.medicine_id = m.id
        LEFT JOIN (
            SELECT medicine_id, SUM(quantity) AS pending_qty
            FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id
            WHERE p.status IN ('draft', 'pending_approval', 'approved', 'ordered', 'partially_received', 'pending')
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
        LEFT JOIN (
            SELECT b2.medicine_id,
                   SUM(COALESCE(b2.quantity_received, b2.quantity) * b2.purchase_price) AS received_value
            FROM batches b2
            WHERE date(b2.created_at, '+3 hours') BETWEEN ? AND ?
            GROUP BY b2.medicine_id
        ) recv ON recv.medicine_id = m.id
        $prodExtra
        ORDER BY m.name COLLATE NOCASE
    ";
    $params = array_merge([$from, $to], $prodParams);
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = [
            'id'            => (int)$r['id'],
            'name'          => $r['name'],
            'generic_name'  => $r['generic_name'] ?? '',
            'unit'          => ($r['unit'] ?? '') !== '' ? $r['unit'] : 'pcs',
            'product_type'  => $r['product_type'],
            'category'      => $r['category'],
            'reorder_level' => (int)$r['reorder_level'],
            'stock'         => (int)$r['stock'],
            'stock_value'   => (float)$r['stock_value'],
            'avg_buy'       => (float)$r['avg_buy'],
            'avg_sell'      => (float)$r['avg_sell'],
            'expired_qty'   => (int)$r['expired_qty'],
            'at_risk_qty'   => (int)$r['at_risk_qty'],
            'next_expiry'   => $r['next_expiry'] ?: null,
            'stock_since'   => $r['stock_since'] ?: null,
            'pending_qty'   => (int)$r['pending_qty'],
            'last_sale'     => $r['last_sale'] ?: null,
            'last_price'    => $r['last_price'] !== null ? (float)$r['last_price'] : null,
            'avg_price'     => $r['avg_price'] !== null ? (float)$r['avg_price'] : null,
            'low_price'     => $r['low_price'] !== null ? (float)$r['low_price'] : null,
            'supplier_id'   => $r['supplier_id'] !== null ? (int)$r['supplier_id'] : null,
            'supplier_name' => $r['supplier_name'] ?? null,
            'received_value'=> $r['received_value'] !== null ? (float)$r['received_value'] : 0.0,
        ];
    }
    return $out;
}

/** Units sold per medicine in the equal-length period before the selected one. */
function invPreviousPeriodUnits(PDO $pdo, array $dates, array $filters): array {
    $day = reportLocalDateExpr('s');
    $sql = "
        SELECT si.medicine_id, SUM(si.quantity) AS units
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        LEFT JOIN batches b ON b.id = si.batch_id
        WHERE $day BETWEEN ? AND ?
          AND COALESCE(s.status, 'active') != 'voided'
    ";
    $params = [$dates['prevFrom'], $dates['prevTo']];
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

/* ═══════════════════════════════════════════════════════════════════════════
   DEMAND MODEL — velocities, forecast, confidence
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Computes every demand metric for one product row (in place).
 * Sets: calendar_avg, active_velocity, in_stock_velocity, recent7/14/30,
 *       forecast, confidence, history_days, selling_days, stockout/coverage.
 */
function invApplyDemand(array &$m, array $series, array $hist, array $cfg, array $prevMap): void {
    $periodDays   = max(1, (int)$hist['period_days']);
    $analysisDays = max(1, (int)$hist['analysis_days']);
    $analysisTo   = $hist['analysis_to'];

    $units = 0.0; $revenue = 0.0; $cogs = 0.0; $txn = 0;
    $sellingDays = 0;
    $firstDay = null; $lastDay = null;
    foreach ($series as $day => $v) {
        $units   += $v['qty'];
        $revenue += $v['revenue'];
        $cogs    += $v['cogs'];
        $txn     += $v['txn'];
        if ($v['qty'] > 0) {
            $sellingDays++;
            if ($firstDay === null || $day < $firstDay) $firstDay = $day;
            if ($lastDay === null || $day > $lastDay)    $lastDay = $day;
        }
    }
    $units = (int)round($units);

    $stock   = (int)$m['stock'];
    $expired = (int)$m['expired_qty'];
    $usable  = max(0, $stock - $expired);

    // ── Calendar metric (kept, clearly labelled) ──────────────────────────
    $calendarAvg = $units / $periodDays;

    // ── Active selling velocity: units / days that actually sold ───────────
    $activeVelocity = $sellingDays > 0 ? $units / $sellingDays : 0.0;

    // ── In-stock days: days the product could actually be sold ─────────────
    $inStockFrom = $hist['analysis_from'];
    if (!empty($m['stock_since'])) {
        $inStockFrom = max($inStockFrom, (string)$m['stock_since']);
    }
    $inStockDays = max(1, invDaysBetween($inStockFrom, $analysisTo) + 1);
    $inStockDays = min($inStockDays, max(1, $analysisDays));

    // Out-of-stock estimate: currently empty but it did sell → it ran out after
    // its last sale, so the days since are likely lost selling days.
    $outOfStockDays = 0;
    $oosUnknown = false;
    if ($usable <= 0 && $lastDay !== null) {
        $outOfStockDays = max(0, invDaysBetween($lastDay, $analysisTo));
        if ($outOfStockDays > 0) {
            $inStockDays = max(1, $inStockDays - $outOfStockDays);
        }
    } elseif ($usable <= 0 && $units <= 0) {
        $oosUnknown = true;
    }
    $inStockVelocity = $units / max(1, $inStockDays);

    // ── Recent velocity (only windows the available history supports) ─────
    $sumLast = function (int $n) use ($series, $analysisTo): float {
        $start = date('Y-m-d', strtotime($analysisTo . ' -' . ($n - 1) . ' days'));
        $sum = 0.0;
        foreach ($series as $day => $v) {
            if ($day >= $start) $sum += $v['qty'];
        }
        return $sum;
    };
    $recent7  = $analysisDays >= 7  ? $sumLast(7)  / min(7,  $analysisDays) : null;
    $recent14 = $analysisDays >= 14 ? $sumLast(14) / min(14, $analysisDays) : null;
    $recent30 = $analysisDays >= 30 ? $sumLast(30) / min(30, $analysisDays) : null;

    // ── Recent trend vs the earlier part of the same window ───────────────
    $recentTrend = null;
    if ($analysisDays >= 10) {
        $earlierDays = $analysisDays - 7;
        $earlierUnits = $units - $sumLast(7);
        $earlierAvg = $earlierDays > 0 ? $earlierUnits / $earlierDays : 0.0;
        if ($earlierAvg > 0.0001) {
            $recentTrend = ($sumLast(7) / 7 - $earlierAvg) / $earlierAvg * 100;
        }
    }

    // ── Forecast / planning velocity: weighted blend, spike-capped ────────
    $comps = [];
    if ($recent7  !== null) $comps[] = [$recent7,  0.35];
    if ($recent14 !== null) $comps[] = [$recent14, 0.20];
    if ($recent30 !== null) $comps[] = [$recent30, 0.15];
    if ($units > 0)         $comps[] = [$inStockVelocity, 0.30];
    $comps[] = [$activeVelocity, 0.20];
    $comps[] = [$calendarAvg,    0.15];

    $wsum = 0.0; $blend = 0.0;
    foreach ($comps as [$v, $w]) { $blend += $v * $w; $wsum += $w; }
    $forecast = $wsum > 0 ? $blend / $wsum : 0.0;

    // Cap abnormal spikes: never more than 1.5× the strongest simple metric.
    $cap = max($inStockVelocity, $activeVelocity, $calendarAvg) * 1.5;
    $forecast = max(0.0, min($forecast, $cap));

    // Evidence shrinkage: a velocity built on very few selling days is only
    // trusted in proportion to the evidence behind it, so one busy day cannot
    // define the plan. As history grows the forecast converges on the blend.
    $proven = $units >= (int)$cfg['score_min_sales'];
    $evidence = 0.0;
    if ($proven) {
        $evidence = min(1.0, $sellingDays / 5) * min(1.0, $analysisDays / 14);
        $evidence = max(0.25, $evidence);
        $forecast = $calendarAvg + ($forecast - $calendarAvg) * $evidence;
    } else {
        // Very few sales → do not extrapolate beyond the observed average.
        $forecast = min($forecast, $calendarAvg);
    }
    $forecast = max(0.0, min($forecast, $cap));

    // ── Confidence: how much reliable evidence backs this forecast? ───────
    $historyDays = 0;
    if ($firstDay !== null) {
        $historyDays = invDaysBetween($firstDay, $analysisTo) + 1;
    }
    if ($units <= 0) {
        $confidence = 'none';
    } elseif (!$proven) {
        $confidence = 'low';
    } elseif ($analysisDays >= 21 && $historyDays >= 14 && $sellingDays >= 8) {
        $confidence = 'high';
    } elseif ($analysisDays >= 7 && $historyDays >= 7 && $sellingDays >= 3) {
        $confidence = 'medium';
    } else {
        $confidence = 'low';
    }

    // ── Days of stock / coverage (N/A when demand history is insufficient) ─
    $coverage = null;
    if ($forecast > 0.0001) {
        $coverage = $usable / $forecast;
    }

    // ── Growth vs previous period (never fake +100%) ───────────────────────
    $prevUnits = $prevMap[$m['id']] ?? 0;
    $growth = null;
    if ($prevUnits > 0) {
        $growth = (($units - $prevUnits) / $prevUnits) * 100;
    }
    $growthLabel = $prevUnits > 0
        ? invPct($growth, 0)
        : (!empty($hist['has_prev_sales']) ? 'NEW / NO COMPARABLE HISTORY' : 'INSUFFICIENT HISTORY');

    // ── Turnover = COGS / average inventory cost (or N/A) ─────────────────
    $endingValue = (float)$m['stock_value'];
    $receivedValue = (float)$m['received_value'];
    $beginningValue = $endingValue + $cogs - $receivedValue;
    $avgInventory = ($beginningValue + $endingValue) / 2;
    $turnover = null;
    $turnoverAvailable = ($beginningValue >= 0) && ($avgInventory > 0.01);
    if ($turnoverAvailable) {
        $turnover = $periodDays > 0 ? $cogs / $avgInventory : null;
    }

    $m['units_sold']        = $units;
    $m['transactions']      = $txn;
    $m['revenue']           = round($revenue, 2);
    $m['cogs']              = round($cogs, 2);
    $m['gross_profit']      = round($revenue - $cogs, 2);
    $m['margin_pct']        = $revenue > 0 ? ($revenue - $cogs) / $revenue * 100 : 0.0;
    $m['markup_pct']        = $cogs > 0 ? ($revenue - $cogs) / $cogs * 100 : null;
    $m['calendar_avg']      = $calendarAvg;
    $m['active_velocity']   = $activeVelocity;
    $m['in_stock_velocity'] = $inStockVelocity;
    $m['recent7']           = $recent7;
    $m['recent14']          = $recent14;
    $m['recent30']          = $recent30;
    $m['recent_trend']      = $recentTrend;
    $m['forecast']          = $forecast;
    $m['evidence']          = $proven ? $evidence : 0.0;
    $m['confidence']        = $confidence;
    $m['confidence_factor'] = ['high' => 1.0, 'medium' => 0.85, 'low' => 0.6, 'none' => 0.0][$confidence];
    $m['confidence_label']  = ['high' => 'HIGH', 'medium' => 'MEDIUM', 'low' => 'LOW', 'none' => 'NO DATA'][$confidence];
    $m['history_days']      = $historyDays;
    $m['selling_days']      = $sellingDays;
    $m['analysis_days']     = $analysisDays;
    $m['period_days']       = $periodDays;
    $m['in_stock_days']     = $inStockDays;
    $m['out_of_stock_days'] = $outOfStockDays;
    $m['oos_unknown']       = $oosUnknown;
    $m['usable_stock']      = $usable;
    $m['expired_qty']       = $expired;
    $m['at_risk_qty']       = (int)$m['at_risk_qty'];
    $m['coverage']          = $coverage;
    $m['prev_units']        = $prevUnits;
    $m['growth_pct']        = $growth;
    $m['growth_label']      = $growthLabel;
    $m['turnover']          = $turnover;
    $m['turnover_available']= $turnoverAvailable;
    $m['beginning_value']   = round($beginningValue, 2);
    $m['avg_inventory']     = round($avgInventory, 2);
    $m['last_sale_days']    = !empty($m['last_sale']) ? max(0, invDaysBetween(date('Y-m-d', strtotime($m['last_sale'])), date('Y-m-d'))) : null;
    $m['proven']            = $proven;
}

/* ═══════════════════════════════════════════════════════════════════════════
   RECOMMENDED PURCHASE QUANTITY + CONSERVATIVE ECONOMICS
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Target demand stock + safety stock − usable stock − confirmed incoming,
 * floored at 0, capped by max stock days and by expiry risk. Never invents
 * demand for products without evidence.
 */
function invRecommendedPurchase(array $m, array $cfg): array {
    $forecast = (float)$m['forecast'];
    $usable   = (int)$m['usable_stock'];
    $incoming = max(0, (int)$m['pending_qty']);
    $reorder  = (int)$m['reorder_level'];
    $proven   = (bool)$m['proven'];

    $horizon     = (int)$cfg['horizon_days'];
    // With limited evidence we buy less: the coverage target is scaled by data
    // confidence (HIGH 100%, MEDIUM 85%, LOW 60%), plus full safety stock.
    $confFactor  = (float)($m['confidence_factor'] ?? 0.0);
    $targetCover = (int)floor((int)$cfg['coverage_days'] * $confFactor) + (int)$cfg['safety_days'];
    $safetyUnits = (int)ceil($forecast * (int)$cfg['safety_days']);
    $targetUnits = (int)ceil($forecast * $targetCover);

    $recommended = 0;
    $expiryBlocked = false;

    if ($proven && $forecast > 0) {
        $recommended = max(0, $targetUnits - $usable - $incoming);
        // Never let total coverage exceed the max-stock-days ceiling.
        $maxUnits = (int)floor($forecast * (int)$cfg['max_stock_days']) - $usable - $incoming;
        $recommended = min($recommended, max(0, $maxUnits));
        // Respect the reorder floor for products sitting at/below it, but count
        // confirmed incoming stock towards that floor so we never double-order.
        if ($reorder > 0) {
            $recommended = max($recommended, max(0, $reorder - $usable - $incoming));
        }
        // Expiry-aware: don't add stock that cannot sell before it expires.
        $daysLeft = $m['next_expiry'] ? expiryDaysRemaining($m['next_expiry']) : null;
        if ($daysLeft !== null && $daysLeft >= 0 && $daysLeft <= (int)$cfg['expiry_risk_days']) {
            $expectedBeforeExpiry = (int)floor($forecast * $daysLeft);
            $headroom = max(0, $expectedBeforeExpiry - $usable - $incoming);
            if ($recommended > $headroom) {
                $recommended = $headroom;
                $expiryBlocked = ($headroom === 0);
            }
        }
        if ($recommended >= 50) {
            $recommended = (int)(round($recommended / 5) * 5);
        }
    }

    // Unit cost: last purchase price, else average, else warehouse average,
    // else a conservative 25% margin assumption off the selling price.
    $unitCost = (float)($m['last_price'] ?? $m['avg_price'] ?? $m['avg_buy'] ?? 0);
    if ($unitCost <= 0 && (float)$m['avg_sell'] > 0) {
        $unitCost = (float)$m['avg_sell'] * 0.75;
    }
    $unitCostSource = ($m['last_price'] !== null) ? 'last purchase'
        : (($m['avg_price'] !== null) ? 'average purchase' : ((float)$m['avg_buy'] > 0 ? 'inventory average' : 'estimated'));
    $unitPrice = (float)$m['avg_sell'];
    $gpUnit = max(0.0, $unitPrice - $unitCost);

    $requiredInvestment = $recommended * $unitCost;

    // Expected units attributable to this purchase inside the horizon:
    // the share of forecast demand that the NEW stock supplies, allocated in
    // proportion to how much of the post-purchase stock it represents. This
    // avoids assuming the whole purchase sells at once while also not crediting
    // the purchase with revenue that existing stock would have earned anyway.
    $demandInHorizon = $forecast * $horizon;
    $stockAfter = $usable + $incoming + $recommended;
    $fulfilled = min($demandInHorizon, (float)$stockAfter);
    $expectedUnits = ($recommended > 0 && $stockAfter > 0)
        ? (int)round($fulfilled * ($recommended / $stockAfter))
        : 0;

    $estRevenue = $expectedUnits * $unitPrice;
    $estProfit  = $expectedUnits * $gpUnit;
    $roi = ($requiredInvestment > 0 && $recommended > 0)
        ? round($estProfit / $requiredInvestment * 100, 1)
        : null; // N/A — never infinity

    // Full sell-through economics for context (how the whole purchase pays off).
    $fullProfit = $recommended * $gpUnit;
    $roiFull = $requiredInvestment > 0 ? round($fullProfit / $requiredInvestment * 100, 1) : null;

    return [
        'recommended_qty'    => $recommended,
        'target_units'       => $targetUnits,
        'safety_units'       => $safetyUnits,
        'target_coverage'    => $targetCover,
        'usable_stock'       => $usable,
        'incoming_qty'       => $incoming,
        'unit_cost'          => $unitCost,
        'unit_cost_source'   => $unitCostSource,
        'unit_price'         => $unitPrice,
        'gp_per_unit'        => $gpUnit,
        'est_cost'           => round($requiredInvestment, 2),
        'required_investment'=> round($requiredInvestment, 2),
        'expected_units'     => $expectedUnits,
        'est_revenue'        => round($estRevenue, 2),
        'est_profit'         => round($estProfit, 2),
        'est_roi'            => $roi,
        'roi_full'           => $roiFull,
        'full_profit'        => round($fullProfit, 2),
        'horizon_days'       => $horizon,
        'expiry_blocked'     => $expiryBlocked,
        'coverage_after'     => $forecast > 0 ? (($usable + $incoming + $recommended) / $forecast) : null,
    ];
}

/* ═══════════════════════════════════════════════════════════════════════════
   SCORING
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Transparent 0–100 investment score across many normalised factors, with a
 * breakdown of the biggest positive and negative contributors. No single
 * factor can dominate (largest weight is 14 of 100).
 */
function invScoreDetail(array $m, array $cfg, array $ctx): array {
    $forecast  = (float)$m['forecast'];
    $coverage  = $m['coverage'];
    $usable    = (int)$m['usable_stock'];
    $stock     = (int)$m['stock'];
    $margin    = (float)$m['margin_pct'];
    $incoming  = (int)$m['pending_qty'];
    $targetCover = (int)$cfg['coverage_days'] + (int)$cfg['safety_days'];
    $confRank = ['high' => 1.0, 'medium' => 0.6, 'low' => 0.25, 'none' => 0.0][$m['confidence']] ?? 0.0;

    $f = [];
    // Positive factors (weights total 100).
    $f[] = ['key' => 'demand',    'label' => 'Demand strength',      'max' => 14, 'value' => min(1.0, $m['units_sold'] / max(1, $ctx['max_units']))];
    $f[] = ['key' => 'velocity',  'label' => 'Sales velocity',       'max' => 10, 'value' => $ctx['max_forecast'] > 0 ? min(1.0, $forecast / $ctx['max_forecast']) : 0.0];
    $f[] = ['key' => 'trend',     'label' => 'Recent sales trend',   'max' => 10, 'value' => $m['recent_trend'] === null ? 0.5 : invClamp(((float)$m['recent_trend'] + 50) / 100, 0, 1)];
    $f[] = ['key' => 'profit',    'label' => 'Gross profit contribution', 'max' => 8, 'value' => min(1.0, (float)$m['gross_profit'] / max(1.0, $ctx['max_gross']))];
    $f[] = ['key' => 'margin',    'label' => 'Gross margin',         'max' => 10, 'value' => invClamp($margin / max(1.0, (float)$cfg['min_margin_pct'] * 2.5), 0, 1)];
    $f[] = ['key' => 'stock_need','label' => 'Stock below target',   'max' => 12, 'value' => ($forecast > 0 && $coverage !== null) ? invClamp(($targetCover - $coverage) / $targetCover, 0, 1) : 0.0];
    $f[] = ['key' => 'stockout',  'label' => 'Stockout risk',        'max' => 8,  'value' => $forecast <= 0 ? 0.0
        : ($usable <= 0 ? 1.0
        : ($coverage <= (int)$cfg['urgent_days'] ? 1.0
        : ($coverage <= (int)$cfg['urgent_days'] * 2 ? 0.6
        : ($coverage <= (int)$cfg['coverage_days'] ? 0.3 : 0.0))))];
    $f[] = ['key' => 'turnover',  'label' => 'Inventory turnover',   'max' => 8,  'value' => $m['turnover'] === null ? 0.5 : invClamp((float)$m['turnover'] / $ctx['target_turnover'], 0, 1)];
    $f[] = ['key' => 'confidence','label' => 'Data confidence',      'max' => 10, 'value' => $confRank];

    // Penalties.
    $pen = [];
    // Expiry risk: excess stock that cannot sell before expiry.
    $expiryRisk = 0.0;
    if ($stock > 0 && $forecast > 0 && $m['next_expiry']) {
        $daysLeft = expiryDaysRemaining($m['next_expiry']);
        if ($daysLeft !== null && $daysLeft >= 0) {
            $expected = $forecast * $daysLeft;
            $excess = max(0.0, $usable - $expected);
            $expiryRisk = invClamp($excess / max(1, $stock), 0, 1);
        }
    }
    $pen[] = ['key' => 'expiry_risk', 'label' => 'Expiry risk', 'max' => 20, 'value' => $expiryRisk];
    // Overstock risk.
    $overstock = 0.0;
    if ($forecast > 0 && $coverage !== null && $coverage > (int)$cfg['max_stock_days']) {
        $overstock = invClamp(($coverage - (int)$cfg['max_stock_days']) / max(1, (int)$cfg['max_stock_days']), 0, 1);
    } elseif ($forecast <= 0 && $stock > 0) {
        $overstock = invClamp($stock / max(1, max(1, (int)$m['reorder_level']) * 5), 0, 1);
    }
    $pen[] = ['key' => 'overstock', 'label' => 'Overstock risk', 'max' => 25, 'value' => $overstock];
    // Incoming stock already ordered → do not duplicate.
    $incomingRisk = ($incoming > 0 && $forecast > 0)
        ? invClamp($incoming / max(1.0, $forecast * (int)$cfg['coverage_days']), 0, 1)
        : 0.0;
    $pen[] = ['key' => 'incoming', 'label' => 'Incoming stock ordered', 'max' => 10, 'value' => $incomingRisk];
    // Dead stock: no sale for a long time.
    $deadRisk = 0.0;
    if ($forecast <= 0.0001 && $stock > 0) {
        $daysSince = $m['last_sale_days'];
        $deadRisk = $daysSince === null ? 1.0 : invClamp($daysSince / max(1, (int)$cfg['dead_stock_days']), 0, 1);
    }
    $pen[] = ['key' => 'dead_risk', 'label' => 'Dead stock risk', 'max' => 15, 'value' => $deadRisk];

    $points = 0.0;
    $factors = [];
    foreach ($f as $row) {
        $pts = $row['value'] * $row['max'];
        $points += $pts;
        $factors[] = ['label' => $row['label'], 'points' => $pts, 'max' => $row['max'], 'dir' => 'up'];
    }
    foreach ($pen as $row) {
        $pts = $row['value'] * $row['max'];
        $points -= $pts;
        if ($pts >= 0.01) {
            $factors[] = ['label' => $row['label'], 'points' => -$pts, 'max' => $row['max'], 'dir' => 'down'];
        }
    }
    $score = (int)round(invClamp($points, 0, 100));

    usort($factors, fn($a, $b) => abs($b['points']) <=> abs($a['points']));

    return ['score' => $score, 'factors' => $factors];
}

/* ═══════════════════════════════════════════════════════════════════════════
   RECOMMENDATION RULES  (spec §10 — zero stock + strong demand = BUY URGENTLY)
   ═══════════════════════════════════════════════════════════════════════════ */

function invRecommendation(array $m, array $cfg, array $purchase): array {
    $forecast = (float)$m['forecast'];
    $usable   = (int)$m['usable_stock'];
    $stock    = (int)$m['stock'];
    $coverage = $m['coverage'];
    $margin   = (float)$m['margin_pct'];
    $proven   = (bool)$m['proven'];
    $marginOk = $margin >= (float)$cfg['min_margin_pct'];
    $urgent   = (int)$cfg['urgent_days'];
    $covDays  = (int)$cfg['coverage_days'];
    $maxDays  = (int)$cfg['max_stock_days'];

    // Expiry blocks additional buying when stock cannot sell before expiry.
    $expiryBlocked = !empty($purchase['expiry_blocked']);
    $expiryRiskNote = '';
    if ($m['next_expiry']) {
        $dl = expiryDaysRemaining($m['next_expiry']);
        if ($dl !== null && $dl >= 0 && $dl <= (int)$cfg['expiry_risk_days'] && $forecast > 0) {
            $excess = max(0.0, $usable - $forecast * $dl);
            if ($excess >= max(1, $stock * 0.2)) {
                $expiryRiskNote = 'High expiry risk — excess stock cannot sell before expiry';
            }
        }
    }

    $mk = function (string $key, string $emoji, string $label, string $badge, string $why) {
        return ['key' => $key, 'emoji' => $emoji, 'label' => $label, 'badge' => $badge, 'why' => $why];
    };

    // 1. No demand evidence → never recommend additional investment.
    if ($forecast <= 0.0001 || !$proven) {
        if ($stock <= 0 && $m['units_sold'] <= 0) {
            return $mk('dont_buy', '🔴', "DON'T BUY", 'badge-red',
                'No sales and no stock — insufficient evidence to justify investment');
        }
        if ($stock > 0 && $forecast <= 0.0001) {
            $daysSince = $m['last_sale_days'];
            if ($daysSince === null) {
                return $mk('dont_buy', '🔴', "DON'T BUY", 'badge-red',
                    'Never sold and still holding stock — do not buy more');
            }
            if ($daysSince >= (int)$cfg['dead_stock_days']) {
                return $mk('dont_buy', '🔴', "DON'T BUY", 'badge-red',
                    'No sales for ' . $daysSince . ' days — dead stock, review for clearance');
            }
            return $mk('reduce', '🟠', 'REDUCE', 'badge-orange',
                'Stock held but no recent demand — buy less next time');
        }
        return $mk('dont_buy', '🔴', "DON'T BUY", 'badge-red',
            'Too little sales history (' . $m['units_sold'] . ' units) to justify investment');
    }

    // 2. Expiry-blocked or overstocked.
    if ($expiryBlocked || $expiryRiskNote !== '') {
        return $mk('reduce', '🟠', 'REDUCE', 'badge-orange', $expiryRiskNote !== '' ? $expiryRiskNote
            : 'Stock already exceeds what can sell before expiry — do not add more');
    }
    if ($coverage !== null && $coverage > $maxDays) {
        return $mk('reduce', '🟠', 'REDUCE', 'badge-orange',
            'Coverage ~' . number_format($coverage) . ' days — far above the ' . $maxDays . '-day maximum');
    }

    // 3. Proven demand with insufficient stock → urgent. "Proven" here means
    //    either solid confidence, or enough units that the evidence is clear.
    $strongEnough = in_array($m['confidence'], ['high', 'medium'], true)
        || (int)$m['units_sold'] >= max(6, (int)$cfg['score_min_sales'] * 2);
    if ($marginOk && $strongEnough && ($usable <= 0 || ($coverage !== null && $coverage <= $urgent))) {
        $why = $usable <= 0
            ? 'Out of stock with proven demand of ' . number_format($forecast, 2) . '/day — sales are being missed'
            : 'Only ~' . number_format((float)$coverage) . ' days of stock left at ' . number_format($forecast, 2) . '/day demand';
        return $mk('urgent', '🔥', 'BUY URGENTLY', 'badge-red', $why);
    }

    // 4. Additional inventory has attractive potential.
    if ($purchase['recommended_qty'] > 0 && $marginOk && $coverage !== null && $coverage < $covDays) {
        return $mk('invest', '🟢', 'INVEST MORE', 'badge-green',
            'Coverage ~' . number_format($coverage) . ' days is below the ' . $covDays . '-day target and margin is ' . number_format($margin, 1) . '%');
    }

    // 5. Healthy demand and adequate cover.
    if ($coverage !== null && $coverage <= $covDays * 1.4) {
        if (!$marginOk) {
            return $mk('maintain', '🟡', 'MAINTAIN', 'badge-blue',
                'Demand is healthy but margin ' . number_format($margin, 1) . '% is below the ' . (int)$cfg['min_margin_pct'] . '% threshold');
        }
        return $mk('maintain', '🟡', 'MAINTAIN', 'badge-blue',
            'Stock covers ~' . number_format($coverage) . ' days — within the target range');
    }

    // 6. Overstocked / fading.
    if ($coverage !== null && $coverage > $covDays * 1.4) {
        $why = !$marginOk ? 'Overstocked and margin is below the threshold'
            : ($m['recent_trend'] !== null && $m['recent_trend'] < -15
                ? 'Overstocked with demand down ' . number_format(abs((float)$m['recent_trend']), 0) . '% recently'
                : 'Coverage ~' . number_format($coverage) . ' days — more stock than demand needs');
        return $mk('reduce', '🟠', 'REDUCE', 'badge-orange', $why);
    }

    return $mk('maintain', '🟡', 'MAINTAIN', 'badge-blue', 'Demand and stock are broadly in balance');
}

/** Short data-driven "why" lines for one product recommendation. */
function invWhyLines(array $m, array $cfg): array {
    $lines = [];
    $unit  = $m['unit'] !== '' ? $m['unit'] : 'units';

    if ($m['units_sold'] > 0) {
        $lines[] = 'Sold ' . number_format($m['units_sold']) . " {$unit} in " . $m['selling_days']
            . ' selling day' . ($m['selling_days'] === 1 ? '' : 's') . ' ('
            . number_format((float)$m['active_velocity'], 2) . '/selling day)';
    } else {
        $lines[] = 'No sales in the available history for this period';
    }
    $lines[] = 'Forecast demand ' . number_format((float)$m['forecast'], 2) . " {$unit}/day"
        . ' · confidence ' . $m['confidence_label'];
    $lines[] = 'Calendar average ' . number_format((float)$m['calendar_avg'], 2) . " {$unit}/day over "
        . $m['period_days'] . ' days in the selected period';
    if ($m['analysis_days'] < $m['period_days']) {
        $lines[] = 'Only ' . $m['analysis_days'] . ' of ' . $m['period_days'] . ' selected days have ERP sales history';
    }
    if ($m['margin_pct'] > 0) {
        $lines[] = 'Gross margin ' . number_format((float)$m['margin_pct'], 1) . '%';
    }
    if ($m['coverage'] !== null) {
        if ($m['usable_stock'] <= 0) {
            $lines[] = 'Out of stock — every demand day is a missed sale';
        } else {
            $lines[] = 'Current usable stock covers ~' . number_format((float)$m['coverage'], 0) . ' days of forecast demand';
        }
    } else {
        $lines[] = 'Days of stock N/A — not enough demand history';
    }
    if ($m['out_of_stock_days'] > 0) {
        $lines[] = 'Est. ' . $m['out_of_stock_days'] . ' out-of-stock day(s) after its last sale';
    }
    if ($m['reorder_level'] > 0 && $m['stock'] <= $m['reorder_level'] && $m['stock'] > 0) {
        $lines[] = 'At/below reorder level (' . number_format($m['reorder_level']) . ')';
    }
    if ($m['growth_pct'] !== null) {
        $lines[] = 'Sales ' . ((float)$m['growth_pct'] >= 0 ? 'up ' : 'down ')
            . number_format(abs((float)$m['growth_pct']), 0) . '% vs previous period';
    } else {
        $lines[] = 'Growth vs previous period: ' . $m['growth_label'];
    }
    if ($m['recent_trend'] !== null) {
        $lines[] = 'Last 7 days trending ' . ((float)$m['recent_trend'] >= 0 ? 'up ' : 'down ')
            . number_format(abs((float)$m['recent_trend']), 0) . '% vs the earlier part of the window';
    }
    if ($m['pending_qty'] > 0) {
        $lines[] = number_format($m['pending_qty']) . " {$unit} already incoming in draft/ordered purchases";
    }
    if ($m['at_risk_qty'] > 0 && $m['stock'] > 0) {
        $lines[] = number_format($m['at_risk_qty']) . " {$unit} expire within {$cfg['expiry_risk_days']} days — do not overstock";
    }
    if ($m['expired_qty'] > 0) {
        $lines[] = number_format($m['expired_qty']) . " {$unit} already expired and excluded from usable stock";
    }
    if ($m['last_price'] !== null) {
        $lines[] = 'Last purchase ' . currency((float)$m['last_price']) . '/' . $unit
            . ($m['supplier_name'] ? ' from ' . $m['supplier_name'] : '');
    }
    return $lines;
}

/* ═══════════════════════════════════════════════════════════════════════════
   SECTION BUILDERS
   ═══════════════════════════════════════════════════════════════════════════ */

/** Aggregate products into category-level investment rows (sums reconcile). */
function invCategoryRows(array $rows, array $cfg): array {
    $cats = [];
    foreach ($rows as $m) {
        $key = $m['category'] ?: 'Uncategorized';
        if (!isset($cats[$key])) {
            $cats[$key] = [
                'category' => $key, 'product_type' => $m['product_type'],
                'sales' => 0.0, 'profit' => 0.0, 'stock_value' => 0.0, 'units_sold' => 0,
                'prev_units' => 0, 'invest' => 0.0, 'rec_count' => 0, 'products' => 0,
                'cogs' => 0.0, 'beginning' => 0.0, 'avg_inventory' => 0.0,
                'expected_units' => 0, 'expected_revenue' => 0.0, 'expected_profit' => 0.0,
                'score_sum' => 0.0, 'top_score' => 0,
            ];
        }
        $c = &$cats[$key];
        $c['products']++;
        $c['sales']      += $m['revenue'];
        $c['profit']     += $m['gross_profit'];
        $c['cogs']       += $m['cogs'];
        $c['stock_value']+= $m['stock_value'];
        $c['beginning']  += $m['beginning_value'];
        $c['avg_inventory'] += $m['avg_inventory'];
        $c['units_sold'] += $m['units_sold'];
        $c['prev_units'] += (int)($m['prev_units'] ?? 0);
        $rp = $m['purchase'];
        if ($rp['recommended_qty'] > 0 && in_array($m['rec']['key'], ['invest', 'urgent', 'maintain'], true)) {
            $c['invest'] += $rp['est_cost'];
            $c['expected_units']   += $rp['expected_units'];
            $c['expected_revenue'] += $rp['est_revenue'];
            $c['expected_profit']  += $rp['est_profit'];
        }
        if ($rp['recommended_qty'] > 0 && in_array($m['rec']['key'], ['invest', 'urgent'], true)) {
            $c['rec_count']++;
        }
        $c['score_sum'] += $m['score'];
        $c['top_score'] = max($c['top_score'], $m['score']);
    }
    unset($c); // break the reference before reusing $c below

    $out = [];
    $maxSales = max(1.0, max(array_column($cats, 'sales') ?: [1.0]));
    foreach ($cats as $c) {
        $c['margin_pct'] = $c['sales'] > 0 ? ($c['profit'] / $c['sales']) * 100 : 0;
        // Cost-based turnover: COGS / average inventory cost (N/A if unknown).
        $c['turnover'] = ($c['avg_inventory'] > 0.01 && $c['beginning'] >= 0) ? $c['cogs'] / $c['avg_inventory'] : null;
        $c['growth_pct'] = $c['prev_units'] > 0 ? (($c['units_sold'] - $c['prev_units']) / $c['prev_units']) * 100 : null;
        $c['roi'] = $c['invest'] > 0 ? round($c['expected_profit'] / $c['invest'] * 100, 1) : null;
        $c['score'] = (int)round(max(0, min(100,
            30 * ($c['sales'] / $maxSales)
            + 20 * min(1, max(0, $c['margin_pct']) / 40)
            + 15 * ($c['turnover'] === null ? 0.5 : min(1.2, $c['turnover']) / 1.2)
            + 15 * min(1, $c['rec_count'] / max(1, $c['products'] * 0.25))
            + 10 * ($c['growth_pct'] === null ? 0.5 : max(-1, min(1, $c['growth_pct'] / 50)))
            + 10 * ($c['score_sum'] / max(1, $c['products'])) / 100
        )));
        $c['invest'] = round($c['invest'], 2);
        $c['expected_revenue'] = round($c['expected_revenue'], 2);
        $c['expected_profit'] = round($c['expected_profit'], 2);
        $out[] = $c;
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return $out;
}

/**
 * Products selling well but short of the target window → estimated missed
 * sales. Labels everything as an ESTIMATE and never treats out-of-stock days
 * as zero demand.
 */
function invStockoutRows(array $rows, array $cfg): array {
    $out = [];
    // Align the missed-sales window with the page's forecast horizon so the
    // estimate is consistent with the rest of the page (and never alarmist).
    $window = max(1, min((int)$cfg['coverage_days'], (int)$cfg['horizon_days']));
    foreach ($rows as $m) {
        $forecast = (float)$m['forecast'];
        if ($forecast <= 0.0001 || !$m['proven']) continue;

        $usable = (int)$m['usable_stock'];
        $incoming = (int)$m['pending_qty'];
        $coverage = $usable / $forecast;
        if ($coverage >= $window) continue;

        $risk = $coverage <= 3 ? 'Critical' : ($coverage <= 7 ? 'High' : ($coverage <= 14 ? 'Medium' : 'Low'));
        $riskBadge = $coverage <= 7 ? 'badge-red' : ($coverage <= 14 ? 'badge-orange' : 'badge-blue');

        // Future shortfall inside the window, adjusted for incoming stock.
        $coveredDays = ($usable + $incoming) / $forecast;
        $missedUnits = (int)ceil(max(0.0, $window - $coveredDays) * $forecast);
        $unitPrice = (float)$m['avg_sell'];
        $unitCost = (float)($m['last_price'] ?? $m['avg_price'] ?? $m['avg_buy'] ?? 0);
        $missedRevenue = $missedUnits * $unitPrice;
        $missedProfit = $missedUnits * max(0.0, $unitPrice - $unitCost);

        $out[] = [
            'row'             => $m,
            'forecast'        => $forecast,
            'stockout_risk'   => $risk,
            'risk_badge'      => $riskBadge,
            'days_remaining'  => (int)floor($coverage),
            'out_of_stock_days' => (int)$m['out_of_stock_days'],
            'window_days'     => $window,
            'missed_units'    => $missedUnits,
            'missed_revenue'  => round($missedRevenue, 2),
            'missed_profit'   => round($missedProfit, 2),
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
        $isDead = (float)$m['forecast'] <= 0.0001;
        $isSlow = !$isDead && ($lastDays === null || $lastDays >= (int)$cfg['dead_stock_days'] || $m['units_sold'] < (int)$cfg['score_min_sales']);
        if (!$isDead && !$isSlow) continue;

        if ($isDead) {
            $rec = ($lastDays !== null && $lastDays >= (int)$cfg['dead_stock_days'] * 2) ? 'CLEARANCE REVIEW' : 'STOP REORDERING';
        } else {
            $cov = $m['coverage'];
            $rec = ($cov !== null && $cov > (int)$cfg['max_stock_days']) ? 'REDUCE PURCHASE' : 'MAINTAIN';
        }
        $recBadge = in_array($rec, ['CLEARANCE REVIEW', 'STOP REORDERING'], true) ? 'badge-red'
            : ($rec === 'REDUCE PURCHASE' ? 'badge-orange' : 'badge-blue');

        $out[] = [
            'row'            => $m,
            'since_label'    => $m['last_sale'] ? date('M j, Y', strtotime($m['last_sale'])) : 'Never',
            'days_since'     => $lastDays,
            'turnover'       => $m['turnover'],
            'recommendation' => $rec,
            'rec_badge'      => $recBadge,
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
        if ($daysLeft === null || $daysLeft < 0) continue; // already expired handled elsewhere
        if ($daysLeft > (int)$cfg['expiry_risk_days'] * 2) continue;

        $forecast = (float)$m['forecast'];
        $usable = max(0, (int)$m['usable_stock']);
        $expected = $forecast * $daysLeft;
        $excess = max(0.0, $usable - $expected);
        if ($excess < max(1, $m['stock'] * 0.2)) continue;

        $rec = $forecast <= 0.0001 ? 'CLEARANCE REVIEW'
            : ($daysLeft <= 30 ? 'PROMOTE / DISCOUNT REVIEW'
            : ($excess > $expected ? 'STOP REORDERING' : 'MONITOR'));
        $recBadge = in_array($rec, ['CLEARANCE REVIEW', 'STOP REORDERING'], true) ? 'badge-red'
            : ($rec === 'PROMOTE / DISCOUNT REVIEW' ? 'badge-orange' : 'badge-blue');

        $out[] = [
            'row'             => $m,
            'expiry_date'     => $m['next_expiry'],
            'days_until'      => $daysLeft,
            'forecast'        => $forecast,
            'avg_sales'       => $forecast,
            'expected_sales'  => (int)round($expected),
            'stock'           => (int)$m['stock'],
            'excess_stock'    => (int)round($excess),
            'value_at_risk'   => round($excess * (float)($m['last_price'] ?? $m['avg_price'] ?? $m['avg_buy'] ?? 0), 2),
            'recommendation'  => $rec,
            'rec_badge'       => $recBadge,
            'blocks_buy'      => true,
        ];
    }
    usort($out, fn($a, $b) => $a['days_until'] <=> $b['days_until']);
    return $out;
}

/* ═══════════════════════════════════════════════════════════════════════════
   MAIN ANALYSIS  (everything computed once, then reused)
   ═══════════════════════════════════════════════════════════════════════════ */

/** Builds the full analysis payload used by investment.php. */
function invAnalyze(PDO $pdo, array $dates, array $filters, array $cfg): array {
    $hist     = invHistoryWindow($pdo, $dates);
    $daily    = invDailySales($pdo, $dates, $filters);
    $rows     = invProductMeta($pdo, $dates, $filters, $cfg);
    $prevMap  = $hist['has_prev_sales'] ? invPreviousPeriodUnits($pdo, $dates, $filters) : [];

    // Pass 1 — demand model per product.
    foreach ($rows as &$m) {
        invApplyDemand($m, $daily[$m['id']] ?? [], $hist, $cfg, $prevMap);
    }
    unset($m);

    // Normalisation context for the score.
    $ctx = [
        'max_units'       => 1,
        'max_forecast'    => 0.0001,
        'max_gross'       => 1.0,
        'target_turnover' => max(0.4, (float)$hist['period_days'] / max(1, (int)$cfg['coverage_days'])),
    ];
    foreach ($rows as $m) {
        $ctx['max_units'] = max($ctx['max_units'], (int)$m['units_sold']);
        $ctx['max_forecast'] = max($ctx['max_forecast'], (float)$m['forecast']);
        $ctx['max_gross'] = max($ctx['max_gross'], (float)$m['gross_profit']);
    }

    // Pass 2 — economics, score, recommendation, explanation.
    foreach ($rows as &$m) {
        $m['purchase'] = invRecommendedPurchase($m, $cfg);
        $sd = invScoreDetail($m, $cfg, $ctx);
        $m['score'] = $sd['score'];
        $m['score_factors'] = $sd['factors'];
        $m['rec'] = invRecommendation($m, $cfg, $m['purchase']);
        $m['why'] = invWhyLines($m, $cfg);
    }
    unset($m);

    // ── Derivations (single pass each — no duplicate section recomputation) ──
    $stockouts = invStockoutRows($rows, $cfg);
    $slow      = invSlowStockRows($rows, $cfg);
    $expiry    = invExpiryRiskRows($rows, $cfg);
    $categories = invCategoryRows($rows, $cfg);

    $recommended = array_values(array_filter($rows, fn($m) => in_array($m['rec']['key'], ['invest', 'urgent'], true) && $m['purchase']['recommended_qty'] > 0));
    usort($recommended, fn($a, $b) => $b['score'] <=> $a['score']);

    $invest = 0.0; $revenue = 0.0; $profit = 0.0; $fullProfit = 0.0;
    foreach ($recommended as $m) {
        $invest    += $m['purchase']['required_investment'];
        $revenue   += $m['purchase']['est_revenue'];
        $profit    += $m['purchase']['est_profit'];
        $fullProfit+= $m['purchase']['full_profit'];
    }
    $missedRevenue = 0.0; $missedProfit = 0.0;
    foreach ($stockouts as $s) {
        $missedRevenue += $s['missed_revenue'];
        $missedProfit  += $s['missed_profit'];
    }

    $summary = [
        'stock_value'     => round(array_sum(array_column($rows, 'stock_value')), 2),
        'rec_count'       => count($recommended),
        'invest'          => round($invest, 2),
        'revenue'         => round($revenue, 2),
        'profit'          => round($profit, 2),
        'roi'             => $invest > 0 ? round($profit / $invest * 100, 1) : null,
        'roi_full'        => $invest > 0 ? round($fullProfit / $invest * 100, 1) : null,
        'missed_revenue'  => round($missedRevenue, 2),
        'missed_profit'   => round($missedProfit, 2),
        'products_total'  => count($rows),
        'products_selling'=> count(array_filter($rows, fn($m) => $m['units_sold'] > 0)),
        'products_no_data'=> count(array_filter($rows, fn($m) => $m['confidence'] === 'none')),
    ];

    $data = [
        'rows'        => $rows,
        'recommended' => $recommended,
        'summary'     => $summary,
        'categories'  => $categories,
        'stockouts'   => $stockouts,
        'slow'        => $slow,
        'expiry_risk' => $expiry,
        'history'     => $hist,
        'config'      => $cfg,
        'context'     => $ctx,
    ];
    $data['reconcile'] = invReconcile($pdo, $data, $dates, $filters);
    return $data;
}

/**
 * Internal validation (spec §29): product totals vs the same item-level source,
 * category sums vs product sums, investment sum vs recommended sum, and the
 * expected revenue/profit derived from the same quantities. Mismatches are
 * surfaced instead of silently displayed.
 */
function invReconcile(PDO $pdo, array $data, array $dates, array $filters): array {
    $rows = $data['rows'];
    $checks = [];

    // 1. Product rows vs a direct item-level query with the same filter context.
    $ctx = reportItemFilterContext($filters, $dates['from'], $dates['to']);
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(si.quantity), 0) AS units,
               COALESCE(SUM(si.subtotal), 0) AS revenue
        FROM sale_items si {$ctx['joins']}
        WHERE {$ctx['where']}
    ");
    $st->execute($ctx['params']);
    $direct = $st->fetch(PDO::FETCH_ASSOC) ?: ['units' => 0, 'revenue' => 0];

    $rowRevenue = array_sum(array_column($rows, 'revenue'));
    $rowUnits = array_sum(array_column($rows, 'units_sold'));
    $checks[] = [
        'label' => 'Product rows vs sales records (item-level)',
        'detail' => 'Revenue ' . currency((float)$rowRevenue) . ' vs ' . currency((float)$direct['revenue'])
            . ' · Units ' . number_format($rowUnits) . ' vs ' . number_format((int)$direct['units']),
        'ok' => abs((float)$rowRevenue - (float)$direct['revenue']) < 0.05 && (int)$rowUnits === (int)$direct['units'],
    ];

    // 2. Category totals must equal the sum of their products.
    $catSales = round(array_sum(array_column($data['categories'], 'sales')), 2);
    $catProfit = round(array_sum(array_column($data['categories'], 'profit')), 2);
    $checks[] = [
        'label' => 'Category totals vs product totals',
        'detail' => 'Sales ' . currency($catSales) . ' vs ' . currency($rowRevenue)
            . ' · Profit ' . currency($catProfit) . ' vs ' . currency(array_sum(array_column($rows, 'gross_profit'))),
        'ok' => abs($catSales - $rowRevenue) < 0.05 && abs($catProfit - array_sum(array_column($rows, 'gross_profit'))) < 0.05,
    ];

    // 3. Recommended investment total must equal the sum of recommended lines.
    $recSum = 0.0; $recRev = 0.0; $recProfit = 0.0;
    foreach ($data['recommended'] as $m) {
        $recSum    += $m['purchase']['required_investment'];
        $recRev    += $m['purchase']['est_revenue'];
        $recProfit += $m['purchase']['est_profit'];
    }
    $checks[] = [
        'label' => 'Recommended investment vs sum of product lines',
        'detail' => 'Summary ' . currency((float)$data['summary']['invest']) . ' vs lines ' . currency(round($recSum, 2)),
        'ok' => abs((float)$data['summary']['invest'] - round($recSum, 2)) < 0.05
            && abs((float)$data['summary']['revenue'] - round($recRev, 2)) < 0.05
            && abs((float)$data['summary']['profit'] - round($recProfit, 2)) < 0.05,
    ];

    // 4. Cross-check against the Sales/Overview report revenue for the period.
    $ref = reportFetchScalar($pdo, "
        SELECT COALESCE(SUM(s.total_amount - s.discount), 0)
        FROM sales s
        WHERE " . reportLocalDateExpr('s') . " BETWEEN ? AND ? AND COALESCE(s.status, 'active') != 'voided'
    ", [$dates['from'], $dates['to']]);
    $checks[] = [
        'label' => 'Reference: Sales report net revenue for the period',
        'detail' => currency((float)$ref) . ' net (after header discounts) — item-level gross above may differ by header discounts only',
        'ok' => true,
        'info' => true,
    ];

    return [
        'checks' => $checks,
        'ok' => !in_array(false, array_column(array_filter($checks, fn($c) => empty($c['info'])), 'ok'), true),
    ];
}

/** Detail payload for the "Product Investment Details" panel. */
function invProductDetail(array $rows, PDO $pdo, int $medId): ?array {
    foreach ($rows as $m) {
        if ($m['id'] === $medId) {
            $m['suppliers'] = invProductSuppliers($pdo, $medId);
            return $m;
        }
    }
    return null;
}

/* ═══════════════════════════════════════════════════════════════════════════
   INVESTMENT SIMULATOR
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Greedy budget allocation: BUY URGENTLY first, then highest score / ROI.
 * Respects margin, expiry and incoming-stock constraints, and does NOT force
 * the whole budget to be spent.
 */
function invSimulate(array $recommended, float $budget, array $cfg = []): array {
    $cfg = $cfg ?: invDefaultConfig();
    $minMargin = (float)$cfg['min_margin_pct'];

    $pool = array_values(array_filter($recommended, function ($m) use ($minMargin) {
        if ($m['purchase']['est_cost'] <= 0 || $m['purchase']['recommended_qty'] <= 0) return false;
        if (!empty($m['purchase']['expiry_blocked'])) return false;
        return (float)$m['margin_pct'] >= $minMargin || $m['rec']['key'] === 'urgent';
    }));

    $prio = ['urgent' => 0, 'invest' => 1, 'maintain' => 2];
    usort($pool, function ($a, $b) use ($prio) {
        return [
            $prio[$a['rec']['key']] ?? 3, -$a['score'],
            -($a['purchase']['roi_full'] ?? 0),
        ] <=> [
            $prio[$b['rec']['key']] ?? 3, -$b['score'],
            -($b['purchase']['roi_full'] ?? 0),
        ];
    });

    $plan = [];
    $remaining = $budget;
    foreach ($pool as $m) {
        if ($remaining <= 0) break;
        $rp = $m['purchase'];
        $cost = (float)$rp['est_cost'];
        if ($cost <= $remaining) {
            $plan[] = ['row' => $m, 'qty' => $rp['recommended_qty'], 'cost' => $cost,
                       'revenue' => $rp['est_revenue'], 'profit' => $rp['est_profit'],
                       'roi' => $rp['est_roi'], 'partial' => false];
            $remaining -= $cost;
        } else {
            $unitCost = $cost / max(1, $rp['recommended_qty']);
            $qty = (int)floor($remaining / $unitCost);
            if ($qty >= 1) {
                $demandHorizon = (float)$rp['est_revenue'] / max(0.01, (float)$rp['unit_price']);
                $expectedUnits = min($qty, (int)floor($demandHorizon));
                $profit = $expectedUnits * (float)$rp['gp_per_unit'];
                $plan[] = [
                    'row' => $m, 'qty' => $qty, 'cost' => round($qty * $unitCost, 2),
                    'revenue' => round($expectedUnits * $rp['unit_price'], 2),
                    'profit' => round($profit, 2),
                    'roi' => ($qty * $unitCost) > 0 ? round($profit / ($qty * $unitCost) * 100, 1) : null,
                    'partial' => true,
                ];
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
    $totalRecommended = array_sum(array_map(fn($m) => $m['purchase']['est_cost'], $pool));

    return [
        'plan'      => $plan,
        'cost'      => round($cost, 2),
        'revenue'   => round($revenue, 2),
        'profit'    => round($profit, 2),
        'roi'       => $cost > 0 ? round($profit / $cost * 100, 1) : null,
        'unspent'   => round(max(0, $budget - $cost), 2),
        'budget'    => round($budget, 2),
        'opportunity' => round($totalRecommended, 2),
        'candidates'  => count($pool),
        'cats'        => $cats,
    ];
}

/* ═══════════════════════════════════════════════════════════════════════════
   THE ONLY WRITE PATH — purchase DRAFT via existing createPurchase()
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Turns selected products into a purchase DRAFT using the existing
 * createPurchase() with save_intent=draft. It never receives or confirms the
 * purchase — stock is untouched until somebody with permission receives it
 * from the normal Purchases screen.
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
        $batch = $type === 'medicine' ? nextInternalBatchNumber($pdo, 'INV') : '';
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
