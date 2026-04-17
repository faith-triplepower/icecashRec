<?php
// ============================================================
// process/process_reconciliation.php — Reconciliation Engine
// 5-tier matching engine: exact ref, amount+date+channel,
// fuzzy amount, split payments (N→1), batch settlements (1→N).
// Also handles data quality checks, variance calculation,
// currency mismatch flagging, and auto-escalation.
// Part of IceCashRec — Zimnat General Insurance
// ============================================================
// process/process_reconciliation.php
// Matching engine + analysis endpoints for sales vs receipts.
// Entry points (POST action=):
//   run_with_db    — run engine on existing DB data (default)
//   data_quality   — pre-run data quality report (JSON)
//   manual_match   — manually link/unlink a receipt to a sale (JSON)
// ============================================================

require_once '../core/auth.php';
require_once '../core/db.php';
require_once '../core/notifications.php';
require_once '../core/ingestion.php';
require_role(['Manager','Reconciler','Admin']); // Uploaders cannot reconcile
csrf_verify();

$db   = get_db();
$user = current_user();
$uid  = (int)$user['id'];

// ════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════

function redirect_back($type, $msg) {
    header("Location: " . BASE_URL . "/modules/reconciliation.php?" . $type . "=" . urlencode($msg));
    exit;
}

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Progress now writes to reconciliation_runs, not audit_log.
// This replaces the previous per-tick audit_log spam.
function set_progress($db, $run_id, $pct, $msg) {
    $pct = max(0, min(100, (int)$pct));
    $msg = substr($msg, 0, 200);
    $stmt = $db->prepare("UPDATE reconciliation_runs SET progress_pct=?, progress_msg=? WHERE id=?");
    $stmt->bind_param('isi', $pct, $msg, $run_id);
    $stmt->execute();
    $stmt->close();
}

function audit_log_entry($user_id, $action, $details, $result = 'success') {
    global $db;
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $stmt = $db->prepare(
        "INSERT INTO audit_log (user_id, action_type, detail, ip_address, result, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param('issss', $user_id, $action, $details, $ip, $result);
    $stmt->execute();
    $stmt->close();
}

function normalize_ymd($d) {
    $ts = strtotime($d);
    return $ts ? date('Y-m-d', $ts) : null;
}

function validate_params($params) {
    $errors = array();
    if (empty($params['date_from']) || !strtotime($params['date_from'])) $errors[] = 'Invalid start date';
    if (empty($params['date_to'])   || !strtotime($params['date_to']))   $errors[] = 'Invalid end date';
    if (!empty($params['date_from']) && !empty($params['date_to'])) {
        if (strtotime($params['date_to']) < strtotime($params['date_from'])) {
            $errors[] = 'End date must be after start date';
        }
        $days_diff = (strtotime($params['date_to']) - strtotime($params['date_from'])) / 86400;
        if ($days_diff > 365) $errors[] = 'Date range cannot exceed 1 year';
    }
    return $errors;
}

// ════════════════════════════════════════════════════════════
// PRE-RUN DATA QUALITY
// Returns counts + a list of potential issues the user should
// fix before running the engine. Called via AJAX from the UI.
// ════════════════════════════════════════════════════════════
function data_quality_report($db, $date_from, $date_to, $agent_filter) {
    $where_sales = "txn_date BETWEEN ? AND ?";
    // Pre-flight receipt count is credits only — debits are outflows
    // that don't participate in matching, so counting them here would
    // inflate the "ready to reconcile" signal.
    $where_rec   = "txn_date BETWEEN ? AND ? AND direction='credit'";
    $params_s = array($date_from, $date_to);
    $types_s  = 'ss';

    if ($agent_filter > 0) {
        $where_sales .= " AND agent_id = ?";
        $params_s[]  = $agent_filter;
        $types_s    .= 'i';
    }

    $stmt = $db->prepare("SELECT COUNT(*) c FROM sales WHERE $where_sales");
    $stmt->bind_param($types_s, ...$params_s);
    $stmt->execute();
    $sales_count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $db->prepare("SELECT COUNT(*) c FROM receipts WHERE $where_rec");
    $stmt->bind_param('ss', $date_from, $date_to);
    $stmt->execute();
    $receipts_count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $issues = array();

    $zero_sales = $db->query("SELECT COUNT(*) c FROM sales WHERE txn_date BETWEEN '$date_from' AND '$date_to' AND amount <= 0")->fetch_assoc()['c'];
    if ($zero_sales > 0) $issues[] = "$zero_sales sales records have zero or negative amounts";

    // Pre-flight counts apply to credits only — debits (float outflows)
    // are excluded from matching and carry their own amounts/terminals
    // which would just create false-positive warnings here.
    $zero_rec = $db->query("SELECT COUNT(*) c FROM receipts WHERE direction='credit' AND txn_date BETWEEN '$date_from' AND '$date_to' AND amount <= 0")->fetch_assoc()['c'];
    if ($zero_rec > 0) $issues[] = "$zero_rec receipts have zero or negative amounts";

    $no_terminal = $db->query("SELECT COUNT(*) c FROM receipts WHERE direction='credit' AND txn_date BETWEEN '$date_from' AND '$date_to' AND channel='Bank POS' AND (terminal_id IS NULL OR terminal_id='')")->fetch_assoc()['c'];
    if ($no_terminal > 0) $issues[] = "$no_terminal Bank POS receipts missing terminal_id — terminal match will be skipped for these";

    $dup_policies = $db->query("SELECT COUNT(*) c FROM (SELECT policy_number FROM sales WHERE txn_date BETWEEN '$date_from' AND '$date_to' GROUP BY policy_number HAVING COUNT(*) > 1) x")->fetch_assoc()['c'];
    if ($dup_policies > 0) $issues[] = "$dup_policies policy numbers appear on multiple sales rows";

    $pending_prior = $db->query("SELECT COUNT(*) c FROM receipts WHERE direction='credit' AND txn_date < '$date_from' AND match_status='pending'")->fetch_assoc()['c'];
    if ($pending_prior > 0) $issues[] = "$pending_prior receipts from prior periods are still unmatched — consider widening date range";

    return array(
        'sales_count'    => $sales_count,
        'receipts_count' => $receipts_count,
        'issues'         => $issues,
        'can_run'        => ($sales_count > 0 && $receipts_count > 0),
    );
}

// ════════════════════════════════════════════════════════════
// MANUAL MATCH / UNMATCH
// ════════════════════════════════════════════════════════════
function manual_match($db, $uid, $receipt_id, $sale_id, $action, $reason, $run_id) {
    $receipt_id = (int)$receipt_id;
    $sale_id    = (int)$sale_id;

    if ($action === 'match') {
        $stmt = $db->prepare("SELECT policy_number, amount, currency FROM sales WHERE id=?");
        $stmt->bind_param('i', $sale_id);
        $stmt->execute();
        $sale = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$sale) throw new Exception("Sale $sale_id not found");

        $upd = $db->prepare(
            "UPDATE receipts
             SET matched_policy=?, matched_sale_id=?, match_status='matched', match_confidence='manual'
             WHERE id=?"
        );
        $upd->bind_param('sii', $sale['policy_number'], $sale_id, $receipt_id);
        $upd->execute();
        $upd->close();
    } elseif ($action === 'unmatch') {
        $upd = $db->prepare(
            "UPDATE receipts
             SET matched_policy=NULL, matched_sale_id=NULL, match_status='pending', match_confidence=NULL
             WHERE id=?"
        );
        $upd->bind_param('i', $receipt_id);
        $upd->execute();
        $upd->close();
        $sale_id = null;
    } else {
        throw new Exception("Invalid action");
    }

    $log = $db->prepare(
        "INSERT INTO manual_match_log (run_id, receipt_id, sale_id, action, reason, user_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $run_id_val = $run_id ? (int)$run_id : null;
    $log->bind_param('iiissi', $run_id_val, $receipt_id, $sale_id, $action, $reason, $uid);
    $log->execute();
    $log->close();
}

// ════════════════════════════════════════════════════════════
// MATCHING ENGINE — tiered with confidence
//
// Tier 1 (high):   reference_no contains policy_number (or vice
//                  versa), same date ±1. Most reliable signal.
// Tier 2 (medium): exact amount + date + channel match,
//                  with terminal check for Bank POS.
// Tier 3 (low):    amount within 0.5% + date ±1 day. Flagged
//                  for review but auto-matched.
// Tier 4 (split):  N receipts sum to one sale, same date ±1 day
//                  and same channel. Walks small combinations only.
// ════════════════════════════════════════════════════════════
function run_matching_engine($db, $run_id, $date_from, $date_to, $product, $agent_filter, $opts, $sales_upload_ids = array(), $receipts_upload_ids = array()) {
    // Sanitize upload-id filters (defence in depth — handlers should
    // have done this already, but we still run bound queries below).
    $sales_upload_ids    = array_values(array_filter(array_map('intval', $sales_upload_ids)));
    $receipts_upload_ids = array_values(array_filter(array_map('intval', $receipts_upload_ids)));
    $sales_in_clause     = !empty($sales_upload_ids)    ? ' AND s.upload_id IN ('    . implode(',', $sales_upload_ids)    . ')' : '';
    $receipts_in_clause  = !empty($receipts_upload_ids) ? ' AND upload_id IN ('      . implode(',', $receipts_upload_ids) . ')' : '';

    // Reset prior matches for the period so re-running is deterministic.
    // Credits only — debits are `match_status='excluded'` by design at
    // ingestion time, and resetting them to 'pending' would drop them
    // into the matching queue and corrupt the unmatched tabs.
    // When a specific set of receipt files is selected, only reset
    // rows from those files so other uploads keep their matches.
    $reset_sql = "UPDATE receipts SET match_status='pending', matched_policy=NULL,
                         matched_sale_id=NULL, match_confidence=NULL
                  WHERE txn_date BETWEEN ? AND ?
                    AND direction='credit'
                    AND (match_confidence IS NULL OR match_confidence <> 'manual')"
               . $receipts_in_clause;
    $reset = $db->prepare($reset_sql);
    $reset->bind_param('ss', $date_from, $date_to);
    $reset->execute();
    $reset->close();

    set_progress($db, $run_id, 5, 'Loading sales');

    // ── Load sales ──────────────────────────────────────────
    $sw = "s.txn_date BETWEEN ? AND ?";
    $sp = array($date_from, $date_to);
    $st = 'ss';
    if ($product !== 'All Products') {
        $sw .= " AND s.product = ?"; $sp[] = $product; $st .= 's';
    }
    if ($agent_filter > 0) {
        $sw .= " AND s.agent_id = ?"; $sp[] = $agent_filter; $st .= 'i';
    }
    $sw .= $sales_in_clause; // safe: ints only, already sanitized above
    $stmt = $db->prepare(
        "SELECT s.id, s.policy_number, s.reference_no, s.txn_date, s.agent_id,
                s.terminal_id, s.payment_method, s.amount, s.currency
         FROM sales s WHERE $sw"
    );
    $stmt->bind_param($st, ...$sp);
    $stmt->execute();
    $sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    set_progress($db, $run_id, 15, 'Loading receipts');

    // ── Load receipts (include a buffer day for date tolerance) ──
    $buffer_from = date('Y-m-d', strtotime($date_from . ' -1 day'));
    $buffer_to   = date('Y-m-d', strtotime($date_to   . ' +1 day'));
    // direction='credit' is belt-and-braces — debits are imported with
    // match_status='excluded' so they wouldn't enter this query anyway,
    // but the explicit filter makes intent obvious and survives any
    // future status refactor.
    $stmt = $db->prepare(
        "SELECT id, reference_no, txn_date, terminal_id, channel,
                source_name, amount, currency, match_status
         FROM receipts
         WHERE txn_date BETWEEN ? AND ?
           AND match_status = 'pending'
           AND direction = 'credit'"
        . $receipts_in_clause
    );
    $stmt->bind_param('ss', $buffer_from, $buffer_to);
    $stmt->execute();
    $receipts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ── Terminal registry for currency consistency check ────
    $terminal_reg = array(); // terminal_id string → array(currency, current_agent_id)
    $treg_res = $db->query("SELECT terminal_id, currency, agent_id FROM pos_terminals");
    while ($row = $treg_res->fetch_assoc()) {
        $terminal_reg[$row['terminal_id']] = $row;
    }

    $total_sales    = count($sales);
    $total_receipts = count($receipts);
    $matched_count  = 0;
    $fx_flagged     = 0;
    $matched_sale_ids = array();  // Track which sales are already claimed across tiers

    // Channel normalization: iPOS, Bank POS, and POS Terminal are all
    // the same money flow — just labeled differently by Icecash vs banks.
    // This function maps them to a common key so they can match.
    $norm_channel = function($ch) {
        $ch = strtolower(trim($ch));
        if (strpos($ch, 'ipos') !== false || strpos($ch, 'bank pos') !== false
            || strpos($ch, 'pos') !== false || strpos($ch, 'card') !== false) return 'POS';
        if (strpos($ch, 'ecocash') !== false || strpos($ch, 'eco') !== false) return 'EcoCash';
        if (strpos($ch, 'zimswitch') !== false) return 'Zimswitch';
        if (strpos($ch, 'broker') !== false || strpos($ch, 'transfer') !== false) return 'Broker';
        return 'POS'; // default — most transactions flow through POS
    };

    // Build indexes once — we normalize channels so iPOS sales match Bank POS receipts
    $by_id = array();                  // receipt_id → receipt (mutable status)
    $by_key = array();                 // "amount_normChannel_date" → [ids]
    $by_date_channel = array();        // "date_normChannel" → [ids]
    $by_date = array();                // "date" → [ids] (for batch matching)
    foreach ($receipts as $r) {
        $by_id[$r['id']] = $r;
        $nch = $norm_channel($r['channel']);
        $k = $r['amount'] . '_' . $nch . '_' . $r['txn_date'];
        $by_key[$k][] = $r['id'];
        $dc = $r['txn_date'] . '_' . $nch;
        $by_date_channel[$dc][] = $r['id'];
        $by_date[$r['txn_date']][] = $r['id'];
    }

    $upd = $db->prepare(
        "UPDATE receipts
         SET matched_policy=?, matched_sale_id=?, match_status=?, match_confidence=?
         WHERE id=?"
    );
    $flag_sale = $db->prepare("UPDATE sales SET currency_flag=1 WHERE id=?");

    $claim = function($receipt_id, $sale, $confidence, $status) use (&$by_id, $upd, $flag_sale, $opts, &$fx_flagged, $terminal_reg) {
        $r = $by_id[$receipt_id];
        // Sale vs receipt currency mismatch
        if ($opts['flag_fx'] && $sale['currency'] !== $r['currency']) {
            $status = 'variance';
            $fx_flagged++;
            $flag_sale->bind_param('i', $sale['id']);
            $flag_sale->execute();
        }
        // Terminal vs receipt currency mismatch (Bank POS only)
        // If the receipt came through a terminal registered as ZWG but was
        // settled in USD (or vice versa), that's a physical-device red flag.
        if ($opts['flag_fx'] && !empty($r['terminal_id']) && isset($terminal_reg[$r['terminal_id']])) {
            $t_cur = $terminal_reg[$r['terminal_id']]['currency'];
            if ($t_cur !== 'ZWG/USD' && $t_cur !== $r['currency']) {
                $status = 'variance';
                $fx_flagged++;
            }
        }
        $upd->bind_param('sissi', $sale['policy_number'], $sale['id'], $status, $confidence, $receipt_id);
        $upd->execute();
        $by_id[$receipt_id]['match_status'] = 'matched';
    };

    // ── Tier 1: reference-number matching ───────────────────
    set_progress($db, $run_id, 25, 'Tier 1: reference matching');

    // Build a lookup of receipts by normalized reference token
    $ref_lookup = array(); // normalized token → [receipt_ids]
    foreach ($receipts as $r) {
        $tokens = preg_split('/[\s,;:\/\|]+/', strtoupper(trim($r['reference_no'])));
        foreach ($tokens as $t) {
            $t = preg_replace('/[^A-Z0-9]/', '', $t);
            if (strlen($t) >= 4) $ref_lookup[$t][] = $r['id'];
        }
    }

    foreach ($sales as $sale) {
        if (isset($matched_sale_ids[$sale['id']])) continue;
        if ($sale['amount'] <= 0) continue;
        $policy = preg_replace('/[^A-Z0-9]/', '', strtoupper($sale['policy_number']));
        if (strlen($policy) < 4) continue;

        $candidates = isset($ref_lookup[$policy]) ? $ref_lookup[$policy] : array();

        // Also try the sale's own reference_no if present
        if (!empty($sale['reference_no'])) {
            $sr = preg_replace('/[^A-Z0-9]/', '', strtoupper($sale['reference_no']));
            if (strlen($sr) >= 4 && isset($ref_lookup[$sr])) {
                $candidates = array_merge($candidates, $ref_lookup[$sr]);
            }
        }

        foreach ($candidates as $rid) {
            if ($by_id[$rid]['match_status'] !== 'pending') continue;
            $r = $by_id[$rid];
            // Reference match must still be roughly contemporaneous
            $days = abs(strtotime($r['txn_date']) - strtotime($sale['txn_date'])) / 86400;
            if ($days > 2) continue;
            $claim($rid, $sale, 'high', 'matched');
            $matched_count++;
            $matched_sale_ids[$sale['id']] = true;
            break;
        }
    }

    // ── Tier 2: exact amount + date + channel ───────────────
    set_progress($db, $run_id, 45, 'Tier 2: exact amount match');

    foreach ($sales as $sale) {
        if (isset($matched_sale_ids[$sale['id']])) continue;
        if ($sale['amount'] <= 0) continue;
        // Use normalized channel so iPOS sales match Bank POS receipts
        $k = $sale['amount'] . '_' . $norm_channel($sale['payment_method']) . '_' . $sale['txn_date'];
        if (!isset($by_key[$k])) continue;

        foreach ($by_key[$k] as $rid) {
            if ($by_id[$rid]['match_status'] !== 'pending') continue;
            $r = $by_id[$rid];
            // Terminal check for Bank POS
            if ($opts['terminal'] && $sale['payment_method'] === 'Bank POS') {
                if (!empty($sale['terminal_id']) && !empty($r['terminal_id'])) {
                    if ($r['terminal_id'] !== $sale['terminal_id']) continue;
                }
            }
            if ($opts['ecocash'] && $sale['payment_method'] === 'EcoCash') {
                if ($r['channel'] !== 'EcoCash') continue;
            }
            $claim($rid, $sale, 'medium', 'matched');
            $matched_count++;
            $matched_sale_ids[$sale['id']] = true;
            break;
        }
    }

    // ── Tier 3: fuzzy amount (±0.5%) + date (±1 day) ────────
    set_progress($db, $run_id, 60, 'Tier 3: fuzzy matching');

    foreach ($sales as $sale) {
        if (isset($matched_sale_ids[$sale['id']])) continue;
        if ($sale['amount'] <= 0) continue;
        $sale_amt = (float)$sale['amount'];
        $tol = max(0.50, $sale_amt * 0.005); // 0.5% or 50 cents, whichever is larger

        $dates = array(
            $sale['txn_date'],
            date('Y-m-d', strtotime($sale['txn_date'] . ' -1 day')),
            date('Y-m-d', strtotime($sale['txn_date'] . ' +1 day')),
        );
        $best_rid = null; $best_diff = PHP_INT_MAX;
        foreach ($dates as $d) {
            $dc = $d . '_' . $norm_channel($sale['payment_method']);
            if (!isset($by_date_channel[$dc])) continue;
            foreach ($by_date_channel[$dc] as $rid) {
                if ($by_id[$rid]['match_status'] !== 'pending') continue;
                $diff = abs((float)$by_id[$rid]['amount'] - $sale_amt);
                if ($diff <= $tol && $diff < $best_diff) {
                    $best_diff = $diff;
                    $best_rid  = $rid;
                }
            }
        }
        if ($best_rid !== null) {
            $claim($best_rid, $sale, 'low', 'matched');
            $matched_count++;
            $matched_sale_ids[$sale['id']] = true;
        }
    }

    // ── Tier 4: split payments (many receipts → one sale) ──
    // Walks small combinations on the same date+channel whose sum
    // matches the sale. Caps at 4 parts and 8 candidates per sale
    // to keep complexity bounded.
    set_progress($db, $run_id, 75, 'Tier 4: split payment pass');

    foreach ($sales as $sale) {
        if (isset($matched_sale_ids[$sale['id']])) continue;
        if ($sale['amount'] <= 0) continue;
        $sale_amt = (float)$sale['amount'];

        $dc = $sale['txn_date'] . '_' . $norm_channel($sale['payment_method']);
        if (!isset($by_date_channel[$dc])) continue;

        $pool = array();
        foreach ($by_date_channel[$dc] as $rid) {
            if ($by_id[$rid]['match_status'] !== 'pending') continue;
            $pool[] = array('id' => $rid, 'amt' => (float)$by_id[$rid]['amount']);
            if (count($pool) >= 8) break;
        }
        if (count($pool) < 2) continue;

        $found = null;
        // Brute force pairs, triples, quads
        $n = count($pool);
        for ($i = 0; $i < $n && !$found; $i++) {
            for ($j = $i + 1; $j < $n && !$found; $j++) {
                if (abs($pool[$i]['amt'] + $pool[$j]['amt'] - $sale_amt) < 0.01) {
                    $found = array($pool[$i]['id'], $pool[$j]['id']);
                    break;
                }
                for ($k = $j + 1; $k < $n && !$found; $k++) {
                    if (abs($pool[$i]['amt'] + $pool[$j]['amt'] + $pool[$k]['amt'] - $sale_amt) < 0.01) {
                        $found = array($pool[$i]['id'], $pool[$j]['id'], $pool[$k]['id']);
                        break;
                    }
                }
            }
        }
        if ($found) {
            foreach ($found as $rid) {
                $claim($rid, $sale, 'medium', 'matched');
                $matched_count++;
            }
            $matched_sale_ids[$sale['id']] = true;
        }
    }

    // ── Tier 5: Reverse batch matching (1 receipt → many sales) ──
    // Bank settlements aggregate many individual IceCash sales into
    // one deposit. For each unmatched receipt, we greedily sum
    // unmatched sales on the same date (±1 day) until we hit the
    // receipt amount. If we reach it within tolerance, claim them all.
    set_progress($db, $run_id, 82, 'Tier 5: batch settlement matching');

    $amt_tol = max(5.0, 0.001 * 100); // ZWG 5 tolerance

    // Sort unmatched receipts by amount descending (largest batches first)
    $unmatched_rids = array();
    foreach ($by_id as $rid => $r) {
        if ($r['match_status'] === 'pending') $unmatched_rids[] = $rid;
    }
    usort($unmatched_rids, function($a, $b) use ($by_id) {
        return $by_id[$b]['amount'] - $by_id[$a]['amount'];
    });

    foreach ($unmatched_rids as $rid) {
        if ($by_id[$rid]['match_status'] !== 'pending') continue;
        $r_amt  = (float)$by_id[$rid]['amount'];
        $r_date = $by_id[$rid]['txn_date'];
        if ($r_amt < 100) continue; // too small to be a batch

        // Collect unmatched sales on the same date (±1 day), sorted by amount desc
        $pool = array();
        foreach ($sales as $sale) {
            if (isset($matched_sale_ids[$sale['id']])) continue;
            if ($sale['amount'] <= 0) continue;
            $day_diff = abs((strtotime($sale['txn_date']) - strtotime($r_date)) / 86400);
            if ($day_diff > 1) continue;
            $pool[] = array('id' => $sale['id'], 'amt' => (float)$sale['amount']);
        }
        if (count($pool) < 2) continue;
        usort($pool, function($a, $b) { return $b['amt'] - $a['amt']; });

        // Greedy sum: add sales until we hit the receipt amount
        $running = 0;
        $batch_ids = array();
        foreach ($pool as $s) {
            if ($running + $s['amt'] > $r_amt + $amt_tol) continue; // skip if would overshoot
            $running += $s['amt'];
            $batch_ids[] = $s['id'];
            if (abs($running - $r_amt) <= $amt_tol) break; // we hit the target
        }

        // Did we match?
        if (abs($running - $r_amt) <= $amt_tol && count($batch_ids) >= 2) {
            foreach ($batch_ids as $sid) {
                $matched_sale_ids[$sid] = true;
            }
            $claim($rid, array('id' => $batch_ids[0], 'policy_number' => 'BATCH-' . $r_date . '-' . count($batch_ids)), 'medium', 'matched');
            $matched_count += count($batch_ids);
        }
    }

    $upd->close();
    $flag_sale->close();

    return array(
        'total_sales'    => $total_sales,
        'total_receipts' => $total_receipts,
        'matched_count'  => $matched_count,
        'fx_flagged'     => $fx_flagged,
    );
}

// ════════════════════════════════════════════════════════════
// VARIANCE CALCULATION
// Writes per-agent rows to variance_results AND per-channel rows
// to variance_by_channel for the drill-down views.
// ════════════════════════════════════════════════════════════
function calculate_variances($db, $run_id, $date_from, $date_to, $agent_filter) {
    set_progress($db, $run_id, 85, 'Calculating variances');

    $where_agent = $agent_filter > 0 ? " AND s.agent_id = $agent_filter " : "";

    // Per-agent totals
    $agents = $db->query("
        SELECT DISTINCT s.agent_id, a.agent_name
        FROM sales s JOIN agents a ON s.agent_id = a.id
        WHERE s.txn_date BETWEEN '$date_from' AND '$date_to' $where_agent
    ")->fetch_all(MYSQLI_ASSOC);

    $var_stmt = $db->prepare(
        "INSERT INTO variance_results
         (run_id, agent_id, sales_zwg, sales_usd, receipts_zwg, receipts_usd,
          variance_zwg, variance_usd, variance_cat, recon_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $chan_stmt = $db->prepare(
        "INSERT INTO variance_by_channel
         (run_id, agent_id, channel, sales_zwg, sales_usd, receipts_zwg, receipts_usd, variance_zwg, variance_usd)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $s_sum = $db->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN currency='ZWG' THEN amount ELSE 0 END),0) zwg,
          COALESCE(SUM(CASE WHEN currency='USD' THEN amount ELSE 0 END),0) usd
        FROM sales WHERE agent_id=? AND txn_date BETWEEN ? AND ?
    ");
    $r_sum = $db->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN r.currency='ZWG' THEN r.amount ELSE 0 END),0) zwg,
          COALESCE(SUM(CASE WHEN r.currency='USD' THEN r.amount ELSE 0 END),0) usd
        FROM receipts r
        INNER JOIN sales sl ON r.matched_sale_id = sl.id
        WHERE sl.agent_id=? AND r.txn_date BETWEEN ? AND ?
          AND r.match_status IN ('matched','variance')
    ");

    $s_chan = $db->prepare("
        SELECT payment_method ch,
          COALESCE(SUM(CASE WHEN currency='ZWG' THEN amount ELSE 0 END),0) zwg,
          COALESCE(SUM(CASE WHEN currency='USD' THEN amount ELSE 0 END),0) usd
        FROM sales WHERE agent_id=? AND txn_date BETWEEN ? AND ?
        GROUP BY payment_method
    ");
    $r_chan = $db->prepare("
        SELECT r.channel ch,
          COALESCE(SUM(CASE WHEN r.currency='ZWG' THEN r.amount ELSE 0 END),0) zwg,
          COALESCE(SUM(CASE WHEN r.currency='USD' THEN r.amount ELSE 0 END),0) usd
        FROM receipts r
        INNER JOIN sales sl ON r.matched_sale_id = sl.id
        WHERE sl.agent_id=? AND r.txn_date BETWEEN ? AND ?
          AND r.match_status IN ('matched','variance')
        GROUP BY r.channel
    ");

    $total_variance_zwg = 0;
    $total_variance_usd = 0;

    foreach ($agents as $ag) {
        $aid = (int)$ag['agent_id'];

        $s_sum->bind_param('iss', $aid, $date_from, $date_to);
        $s_sum->execute();
        $s = $s_sum->get_result()->fetch_assoc();

        $r_sum->bind_param('iss', $aid, $date_from, $date_to);
        $r_sum->execute();
        $r = $r_sum->get_result()->fetch_assoc();

        $s_zwg = (float)$s['zwg']; $s_usd = (float)$s['usd'];
        $r_zwg = (float)$r['zwg']; $r_usd = (float)$r['usd'];
        $v_zwg = $r_zwg - $s_zwg;
        $v_usd = $r_usd - $s_usd;

        $status = 'reconciled'; $cat = null;
        if (abs($v_zwg) > 0.01 || abs($v_usd) > 0.01) {
            $status = 'variance';
            $cat = ($v_zwg < -0.01 || $v_usd < -0.01) ? 'Short Collection' : 'Over Collection';
        }

        $var_stmt->bind_param('iiddddddss',
            $run_id, $aid, $s_zwg, $s_usd, $r_zwg, $r_usd, $v_zwg, $v_usd, $cat, $status);
        $var_stmt->execute();

        $total_variance_zwg += $v_zwg;
        $total_variance_usd += $v_usd;

        // Per-channel breakdown
        $s_chan->bind_param('iss', $aid, $date_from, $date_to);
        $s_chan->execute();
        $sales_by_ch = array();
        foreach ($s_chan->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $sales_by_ch[$row['ch']] = $row;
        }

        $r_chan->bind_param('iss', $aid, $date_from, $date_to);
        $r_chan->execute();
        $rec_by_ch = array();
        foreach ($r_chan->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $rec_by_ch[$row['ch']] = $row;
        }

        $channels = array_unique(array_merge(array_keys($sales_by_ch), array_keys($rec_by_ch)));
        foreach ($channels as $ch) {
            if (!in_array($ch, array('Bank POS','iPOS','EcoCash','Zimswitch','Broker'))) continue;
            $cs_zwg = isset($sales_by_ch[$ch]) ? (float)$sales_by_ch[$ch]['zwg'] : 0;
            $cs_usd = isset($sales_by_ch[$ch]) ? (float)$sales_by_ch[$ch]['usd'] : 0;
            $cr_zwg = isset($rec_by_ch[$ch])   ? (float)$rec_by_ch[$ch]['zwg']   : 0;
            $cr_usd = isset($rec_by_ch[$ch])   ? (float)$rec_by_ch[$ch]['usd']   : 0;
            $cv_zwg = $cr_zwg - $cs_zwg;
            $cv_usd = $cr_usd - $cs_usd;
            $chan_stmt->bind_param('iisdddddd',
                $run_id, $aid, $ch, $cs_zwg, $cs_usd, $cr_zwg, $cr_usd, $cv_zwg, $cv_usd);
            $chan_stmt->execute();
        }
    }

    $var_stmt->close();
    $chan_stmt->close();
    $s_sum->close();
    $r_sum->close();
    $s_chan->close();
    $r_chan->close();

    return array(
        'total_variance_zwg' => $total_variance_zwg,
        'total_variance_usd' => $total_variance_usd,
    );
}

// ════════════════════════════════════════════════════════════
// UNMATCHED AGING — counts receipts & sales still unmatched,
// bucketed by how old they are. Written onto reconciliation_runs.
// ════════════════════════════════════════════════════════════
function count_unmatched($db, $date_from, $date_to) {
    $unm_sales = $db->query("
        SELECT COUNT(*) c FROM sales s
        LEFT JOIN receipts r ON r.matched_sale_id = s.id
        WHERE s.txn_date BETWEEN '$date_from' AND '$date_to'
          AND r.id IS NULL
    ")->fetch_assoc()['c'];

    $unm_rec = $db->query("
        SELECT COUNT(*) c FROM receipts
        WHERE txn_date BETWEEN '$date_from' AND '$date_to'
          AND match_status='pending'
    ")->fetch_assoc()['c'];

    return array('sales' => (int)$unm_sales, 'receipts' => (int)$unm_rec);
}

// ════════════════════════════════════════════════════════════
// CSV EXPORT
// ════════════════════════════════════════════════════════════
function generate_export($run_id, $db) {
    $filename = "reconciliation_run_{$run_id}_" . date('Ymd_His') . ".csv";
    $filepath = EXPORT_DIR . '/' . $filename;
    if (!is_dir(EXPORT_DIR)) mkdir(EXPORT_DIR, 0755, true);
    $fp = fopen($filepath, 'w');
    fputcsv($fp, array('Agent','Sales ZWG','Sales USD','Receipts ZWG','Receipts USD','Variance ZWG','Variance USD','Category','Status'));
    $q = $db->prepare("
        SELECT a.agent_name, v.sales_zwg, v.sales_usd, v.receipts_zwg, v.receipts_usd,
               v.variance_zwg, v.variance_usd, v.variance_cat, v.recon_status
        FROM variance_results v JOIN agents a ON v.agent_id = a.id
        WHERE v.run_id = ? ORDER BY ABS(v.variance_zwg)+ABS(v.variance_usd) DESC
    ");
    $q->bind_param('i', $run_id);
    $q->execute();
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        fputcsv($fp, array(
            $row['agent_name'],
            number_format($row['sales_zwg'], 2),
            number_format($row['sales_usd'], 2),
            number_format($row['receipts_zwg'], 2),
            number_format($row['receipts_usd'], 2),
            number_format($row['variance_zwg'], 2),
            number_format($row['variance_usd'], 2),
            $row['variance_cat'] ?: '-',
            $row['recon_status'],
        ));
    }
    $q->close();
    fclose($fp);
    return BASE_URL . '/exports/' . $filename;
}

// ════════════════════════════════════════════════════════════
// MAIN DISPATCH
// ════════════════════════════════════════════════════════════
try {
    $action = isset($_POST['action']) ? $_POST['action'] : 'run_with_db';

    // ── DATA QUALITY REPORT (AJAX) ───────────────────────────
    if ($action === 'data_quality') {
        $date_from = normalize_ymd(isset($_POST['date_from']) ? $_POST['date_from'] : '');
        $date_to   = normalize_ymd(isset($_POST['date_to'])   ? $_POST['date_to']   : '');
        $agent     = (int)(isset($_POST['agent_id']) ? $_POST['agent_id'] : 0);
        if (!$date_from || !$date_to) json_response(array('error'=>'Invalid date range'), 400);
        json_response(data_quality_report($db, $date_from, $date_to, $agent));
    }

    // ── MANUAL MATCH (AJAX) ──────────────────────────────────
    if ($action === 'manual_match') {
        $receipt_id = (int)(isset($_POST['receipt_id']) ? $_POST['receipt_id'] : 0);
        $sale_id    = (int)(isset($_POST['sale_id']) ? $_POST['sale_id'] : 0);
        $sub_action = isset($_POST['match_action']) ? $_POST['match_action'] : 'match';
        $reason     = isset($_POST['reason']) ? substr($_POST['reason'], 0, 255) : '';
        $run_id     = (int)(isset($_POST['run_id']) ? $_POST['run_id'] : 0);

        if ($receipt_id <= 0) json_response(array('error'=>'receipt_id required'), 400);
        if ($sub_action === 'match' && $sale_id <= 0) {
            json_response(array('error'=>'sale_id required for match'), 400);
        }
        $db->begin_transaction();
        try {
            manual_match($db, $uid, $receipt_id, $sale_id, $sub_action, $reason, $run_id);
            $db->commit();
            audit_log_entry($uid, 'DATA_EDIT',
                "Manual $sub_action: receipt $receipt_id" . ($sale_id ? " → sale $sale_id" : "") . " — $reason");
            json_response(array('success'=>true));
        } catch (Exception $e) {
            $db->rollback();
            json_response(array('error'=>$e->getMessage()), 500);
        }
    }

    // NOTE: the old `upload_files` action that accepted raw file
    // uploads on the reconciliation page has been removed. Uploaders
    // now ingest files through /utilities/upload.php, and the recon
    // engine runs against already-ingested data via run_with_db.

    // ── DEFAULT: run against existing DB data ────────────────
    $start_time = microtime(true);

    $product      = isset($_POST['product'])      ? $_POST['product']      : 'All Products';
    $period_type  = isset($_POST['period_type'])  ? $_POST['period_type']  : 'Monthly';
    $agent_filter = (int)(isset($_POST['agent_id']) ? $_POST['agent_id'] : 0);
    $date_from    = isset($_POST['date_from'])    ? $_POST['date_from']    : '';
    $date_to      = isset($_POST['date_to'])      ? $_POST['date_to']      : '';

    // Optional scope: reconcile only rows from these specific upload
    // files. Empty arrays = no scoping (all files in the date range).
    $sales_upload_ids    = isset($_POST['sales_upload_ids'])    && is_array($_POST['sales_upload_ids'])    ? array_map('intval', $_POST['sales_upload_ids'])    : array();
    $receipts_upload_ids = isset($_POST['receipts_upload_ids']) && is_array($_POST['receipts_upload_ids']) ? array_map('intval', $_POST['receipts_upload_ids']) : array();

    $opts = array(
        'terminal' => isset($_POST['opt_terminal']),
        'ecocash'  => isset($_POST['opt_ecocash']),
        'flag_fx'  => isset($_POST['opt_flag_fx']),
        'bordeaux' => isset($_POST['opt_bordeaux']),
        'date_tol' => isset($_POST['opt_date_tol']),
    );
    $export_results = isset($_POST['export_results']) ? 1 : 0;

    $validation_errors = validate_params(array('date_from'=>$date_from,'date_to'=>$date_to));
    if (!empty($validation_errors)) redirect_back('error', implode('. ', $validation_errors));

    // Fix 9: Concurrency lock — reject if another run overlaps this period
    // and is still in 'running' status.
    $lock_stmt = $db->prepare("
        SELECT r.id, u.full_name FROM reconciliation_runs r
        JOIN users u ON r.run_by = u.id
        WHERE r.run_status = 'running'
          AND r.date_from <= ? AND r.date_to >= ?
        LIMIT 1
    ");
    $lock_stmt->bind_param('ss', $date_to, $date_from);
    $lock_stmt->execute();
    $blocking = $lock_stmt->get_result()->fetch_assoc();
    $lock_stmt->close();
    if ($blocking) {
        redirect_back('error', "Run #{$blocking['id']} is already in progress by {$blocking['full_name']}. Wait for it to complete or ask them to cancel.");
    }

    $period_label = date('F Y', strtotime($date_from));
    $agent_id_val = $agent_filter ?: null;

    $stmt = $db->prepare(
        "INSERT INTO reconciliation_runs
         (period_label, product, agent_id, date_from, date_to, period_type,
          opt_terminal, opt_ecocash, opt_flag_fx, opt_bordeaux, opt_date_tol,
          run_status, run_by, started_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'running', ?, NOW())"
    );
    $opt_t  = $opts['terminal'] ? 1 : 0;
    $opt_e  = $opts['ecocash']  ? 1 : 0;
    $opt_f  = $opts['flag_fx']  ? 1 : 0;
    $opt_b  = $opts['bordeaux'] ? 1 : 0;
    $opt_dt = $opts['date_tol'] ? 1 : 0;
    $stmt->bind_param('ssisssiiiiii',
        $period_label, $product, $agent_id_val, $date_from, $date_to, $period_type,
        $opt_t, $opt_e, $opt_f, $opt_b, $opt_dt, $uid);
    $stmt->execute();
    $run_id = $stmt->insert_id;
    $stmt->close();

    audit_log_entry($uid, 'RECON_RUN', "Run #$run_id started: $period_label / $product");

    // Fix 12: Idempotent re-runs — mark any prior complete runs on the
    // same period as 'superseded' and delete their variance rows so the
    // new run's results don't stack on top of stale data.
    $prior = $db->prepare("
        SELECT id FROM reconciliation_runs
        WHERE id <> ? AND run_status = 'complete'
          AND date_from = ? AND date_to = ?
          AND (product = ? OR ? = 'All Products')
    ");
    $prior->bind_param('issss', $run_id, $date_from, $date_to, $product, $product);
    $prior->execute();
    $prior_runs = $prior->get_result()->fetch_all(MYSQLI_ASSOC);
    $prior->close();
    foreach ($prior_runs as $pr) {
        $pid = (int)$pr['id'];
        $db->query("DELETE FROM variance_by_channel WHERE run_id = $pid");
        $db->query("DELETE FROM variance_results WHERE run_id = $pid");
        $db->query("UPDATE reconciliation_runs SET run_status = 'superseded' WHERE id = $pid");
    }

    $db->begin_transaction();

    $result = run_matching_engine($db, $run_id, $date_from, $date_to, $product, $agent_filter, $opts, $sales_upload_ids, $receipts_upload_ids);
    $variance = calculate_variances($db, $run_id, $date_from, $date_to, $agent_filter);
    $unmatched = count_unmatched($db, $date_from, $date_to);

    // Auto-detect any POS terminals in the run period that aren't in pos_terminals yet.
    // Catches terminals from files uploaded before the auto-creation code existed.
    $new_terms = $db->query("
        SELECT s.terminal_id, s.agent_id, a.agent_name, MAX(s.txn_date) last_txn, COUNT(*) cnt
        FROM sales s
        JOIN agents a ON s.agent_id = a.id
        WHERE s.terminal_id IS NOT NULL AND s.terminal_id <> ''
          AND s.txn_date BETWEEN '$date_from' AND '$date_to'
          AND NOT EXISTS (SELECT 1 FROM pos_terminals pt WHERE pt.terminal_id = s.terminal_id)
        GROUP BY s.terminal_id, s.agent_id
        ORDER BY cnt DESC
    ")->fetch_all(MYSQLI_ASSOC);
    $seen_tids = array();
    foreach ($new_terms as $nt) {
        if (isset($seen_tids[$nt['terminal_id']])) continue;
        $seen_tids[$nt['terminal_id']] = true;
        $tid = $nt['terminal_id'];
        $bank = 'Unknown';
        if (stripos($tid, 'CBZ') === 0)  $bank = 'CBZ Bank';
        elseif (stripos($tid, 'STAN') === 0) $bank = 'Stanbic Zimbabwe';
        elseif (stripos($tid, 'FBC') === 0)  $bank = 'FBC Bank';
        elseif (stripos($tid, 'ZB') === 0)   $bank = 'ZB Bank';
        elseif (stripos($tid, 'STW') === 0)  $bank = 'Steward Bank';
        elseif (stripos($tid, 'NMB') === 0)  $bank = 'NMB Bank';
        $ins_pt = $db->prepare("INSERT IGNORE INTO pos_terminals (terminal_id, merchant_name, agent_id, bank_name, location, currency, last_txn_at) VALUES (?, ?, ?, ?, ?, 'ZWG', ?)");
        $ins_pt->bind_param('ssisss', $tid, $nt['agent_name'], $nt['agent_id'], $bank, $nt['agent_name'], $nt['last_txn']);
        $ins_pt->execute();
        $ins_pt->close();
    }

    set_progress($db, $run_id, 95, 'Finalizing');

    $match_rate = $result['total_sales'] > 0 ? round(($result['matched_count'] / $result['total_sales']) * 100, 2) : 0;

    $upd = $db->prepare("
        UPDATE reconciliation_runs
        SET run_status='complete', completed_at=NOW(),
            progress_pct=100, progress_msg='Complete',
            total_sales=?, total_receipts=?, matched_count=?, match_rate=?,
            fx_flagged=?, unmatched_sales=?, unmatched_receipts=?,
            total_variance_zwg=?, total_variance_usd=?
        WHERE id=?
    ");
    $upd->bind_param('iiidiiiddi',
        $result['total_sales'], $result['total_receipts'], $result['matched_count'], $match_rate,
        $result['fx_flagged'], $unmatched['sales'], $unmatched['receipts'],
        $variance['total_variance_zwg'], $variance['total_variance_usd'], $run_id);
    $upd->execute();
    $upd->close();

    $export_url = '';
    if ($export_results) $export_url = generate_export($run_id, $db);

    $db->commit();

    $exec_time = round(microtime(true) - $start_time, 2);
    $summary = "{$result['matched_count']}/{$result['total_sales']} matched ($match_rate%), "
             . "{$result['fx_flagged']} FX flags, {$unmatched['receipts']} unmatched receipts";
    audit_log_entry($uid, 'RECON_RUN', "Run #$run_id complete: $summary in {$exec_time}s");

    $msg = "Reconciliation complete. $summary.";
    if ($export_url) {
        $msg .= " <a href='$export_url' class='btn btn-sm btn-success' target='_blank'><i class='fas fa-download'></i> Download Report</a>";
    }
    header("Location: " . BASE_URL . "/modules/variance.php?run_id=$run_id&success=" . urlencode($msg));
    exit;

} catch (Exception $e) {
    try { $db->rollback(); } catch (Exception $er) {}
    if (isset($run_id)) {
        $f = $db->prepare("UPDATE reconciliation_runs SET run_status='failed', completed_at=NOW(), progress_msg=? WHERE id=?");
        $err_msg = substr($e->getMessage(), 0, 200);
        $f->bind_param('si', $err_msg, $run_id);
        $f->execute();
        $f->close();
    }
    audit_log_entry($uid, 'RECON_RUN',
        "Reconciliation failed: " . $e->getMessage() . " in " . basename($e->getFile()) . ":" . $e->getLine(),
        'failed');
    error_log("Reconciliation Error: " . $e->getMessage());

    // Notify the user who kicked off the run
    if (isset($run_id)) {
        enqueue_email($db, $uid,
            "Reconciliation run #$run_id failed",
            "Your reconciliation run #$run_id has failed and been rolled back.\n\n"
          . "Error: " . substr($e->getMessage(), 0, 300) . "\n\n"
          . "Details: " . BASE_URL . "/modules/reconciliation.php",
            'recon',
            null
        );
    }

    redirect_back('error', 'Reconciliation failed: ' . $e->getMessage());
}