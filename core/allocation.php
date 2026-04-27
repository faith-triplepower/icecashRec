<?php
// ============================================================
// core/allocation.php — Split-payment allocation helpers
//
// Owns the rules:
//   - up to 10 receipts per sale
//   - calendar-month window
//   - same-currency only counts toward 'paid'; mixed-currency
//     allocations sit in 'currency_review' until a reconciler approves
//   - sum >= sale.amount means the sale is covered (overpayment OK)
//
// Anything that adds, removes, or moves a receipt-to-sale link
// MUST end by calling refresh_paid_status() so sales.paid_status
// stays in sync. The dashboards read paid_status directly.
// ============================================================

if (!defined('SPLIT_MAX_RECEIPTS_PER_SALE')) {
    define('SPLIT_MAX_RECEIPTS_PER_SALE', 10);
}

// Recompute and store sales.paid_status from current allocations.
// Pass null to recompute every sale (used by the migration backfill
// and end-of-run housekeeping); pass an int to recompute one.
function refresh_paid_status($db, $sale_id = null) {
    if ($sale_id !== null) {
        $sale_id = (int)$sale_id;
        if ($sale_id <= 0) return;

        $s = $db->prepare("SELECT amount, currency FROM sales WHERE id=?");
        $s->bind_param('i', $sale_id);
        $s->execute();
        $sale = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$sale) return;

        $r = $db->prepare(
            "SELECT COALESCE(SUM(amount),0) total,
                    SUM(currency <> ?) mixed,
                    COUNT(*) cnt
               FROM receipts
              WHERE matched_sale_id = ?
                AND match_status IN ('matched','variance','partial','currency_review')"
        );
        $r->bind_param('si', $sale['currency'], $sale_id);
        $r->execute();
        $row = $r->get_result()->fetch_assoc();
        $r->close();

        $total = (float)$row['total'];
        $mixed = (int)$row['mixed'];
        $cnt   = (int)$row['cnt'];

        if ($cnt === 0) {
            $status = 'unpaid';
        } elseif ($mixed > 0) {
            $status = 'currency_review';
        } elseif ($total <  (float)$sale['amount']) {
            $status = 'partial';
        } elseif ($total == (float)$sale['amount']) {
            $status = 'paid';
        } else {
            $status = 'overpaid';
        }

        $u = $db->prepare("UPDATE sales SET paid_status=? WHERE id=?");
        $u->bind_param('si', $status, $sale_id);
        $u->execute();
        $u->close();
        return;
    }

    // Bulk recompute — runs the same logic in pure SQL so the engine
    // doesn't have to round-trip per sale at the end of a big run.
    $db->query("
        UPDATE sales s
        LEFT JOIN (
            SELECT r.matched_sale_id sid,
                   SUM(r.amount) total_recv,
                   SUM(r.currency <> sl.currency) mixed_cnt,
                   COUNT(*) cnt
              FROM receipts r
              JOIN sales sl ON sl.id = r.matched_sale_id
             WHERE r.match_status IN ('matched','variance','partial','currency_review')
             GROUP BY r.matched_sale_id
        ) agg ON agg.sid = s.id
        SET s.paid_status = CASE
            WHEN agg.cnt IS NULL OR agg.cnt = 0 THEN 'unpaid'
            WHEN agg.mixed_cnt > 0              THEN 'currency_review'
            WHEN agg.total_recv <  s.amount     THEN 'partial'
            WHEN agg.total_recv =  s.amount     THEN 'paid'
            ELSE 'overpaid'
        END
    ");
}

// How many receipts are currently attached to this sale?
// Used by allocation paths to enforce SPLIT_MAX_RECEIPTS_PER_SALE.
function receipts_attached_to_sale($db, $sale_id) {
    $sale_id = (int)$sale_id;
    if ($sale_id <= 0) return 0;
    $s = $db->prepare(
        "SELECT COUNT(*) c FROM receipts
          WHERE matched_sale_id = ?
            AND match_status IN ('matched','variance','partial','currency_review')"
    );
    $s->bind_param('i', $sale_id);
    $s->execute();
    $c = (int)$s->get_result()->fetch_assoc()['c'];
    $s->close();
    return $c;
}

// Calendar-month boundaries for a YYYY-MM-DD date.
// Used to cap the candidate-receipt window for split matching.
function calendar_month_bounds($ymd) {
    $ts = strtotime($ymd);
    if (!$ts) return null;
    return array(
        'from' => date('Y-m-01', $ts),
        'to'   => date('Y-m-t',  $ts),
    );
}

// Find a subset of $candidates (each: ['id'=>..,'amt'=>..]) that
// sums to >= $target using at most $max_size receipts. Prefers the
// minimum overshoot. Returns array of selected items, or null.
//
// DFS with strong pruning: walks from largest to smallest, prunes
// branches that can no longer reach the target, and stops as soon
// as it finds an exact (zero-overshoot) hit. In practice candidate
// pools are small (5–20) because we filter by month + signal first.
function find_split_subset($candidates, $target, $max_size = SPLIT_MAX_RECEIPTS_PER_SALE) {
    if ($target <= 0 || empty($candidates)) return null;

    usort($candidates, function($a, $b) { return $b['amt'] <=> $a['amt']; });
    $n = count($candidates);

    // Suffix-sum cache for the "can we still reach target?" prune.
    $suffix = array_fill(0, $n + 1, 0.0);
    for ($i = $n - 1; $i >= 0; $i--) {
        $suffix[$i] = $suffix[$i + 1] + (float)$candidates[$i]['amt'];
    }

    $best        = null;
    $best_over   = PHP_FLOAT_MAX;

    // Hard cap on DFS work — pathological pools shouldn't be allowed
    // to dominate a recon run. 20K node visits is plenty for N <= 25.
    $budget = 20000;

    $dfs = function($idx, $picked, $sum) use (&$dfs, $candidates, $target, $max_size, $n, $suffix, &$best, &$best_over, &$budget) {
        if ($budget-- <= 0) return;
        if ($sum >= $target) {
            $over = $sum - $target;
            if ($over < $best_over) { $best = $picked; $best_over = $over; }
            return;
        }
        if (count($picked) >= $max_size) return;
        if ($idx >= $n) return;
        if ($sum + $suffix[$idx] < $target) return; // can't reach

        // Take it
        $picked2 = $picked;
        $picked2[] = $candidates[$idx];
        $dfs($idx + 1, $picked2, $sum + (float)$candidates[$idx]['amt']);
        if ($best_over === 0.0) return; // perfect hit, stop

        // Skip it
        $dfs($idx + 1, $picked, $sum);
    };

    $dfs(0, array(), 0.0);
    return $best;
}
