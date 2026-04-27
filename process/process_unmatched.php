<?php
// ============================================================
// process/process_unmatched.php — Unmatched Transactions
// Smart suggestion engine (composite confidence scoring),
// manual match, exclude, bulk accept, bulk exclude, and
// escalate actions for unmatched receipts and sales.
// Part of IceCashRec — Zimnat General Insurance
// ============================================================

require_once '../core/auth.php';
require_once '../core/notifications.php';
require_once '../core/allocation.php';
require_role(['Manager','Reconciler','Admin']);
csrf_verify();

$db   = get_db();
$user = current_user();
$uid  = (int)$user['id'];

function redirect_back($type, $msg) {
    $qs = array();
    foreach (array('date_from','date_to','tab','status','age') as $k) {
        if (!empty($_POST[$k])) $qs[$k] = $_POST[$k];
    }
    $qs[$type] = $msg;
    header("Location: " . BASE_URL . "/admin/unmatched.php?" . http_build_query($qs));
    exit;
}
function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ════════════════════════════════════════════════════════════
// SUGGESTION ENGINE
// Given a receipt, return up to 5 candidate sales ranked by a
// composite confidence score combining amount, date, channel,
// terminal, agent, and policy-token signals.
// ════════════════════════════════════════════════════════════
function suggest_candidates($db, $receipt_id) {
    $r_stmt = $db->prepare("SELECT * FROM receipts WHERE id=?");
    $r_stmt->bind_param('i', $receipt_id);
    $r_stmt->execute();
    $r = $r_stmt->get_result()->fetch_assoc();
    $r_stmt->close();
    if (!$r) return array();
    // Debits are outflows — they can't be matched against sales.
    if (($r['direction'] ?? 'credit') !== 'credit') return array();

    // Pull all sales within ±7 days that are either unmatched or
    // match-status-less (receipts.matched_sale_id references sales.id).
    $stmt = $db->prepare("
        SELECT s.id, s.policy_number, s.reference_no, s.txn_date, s.amount, s.currency,
               s.payment_method, s.terminal_id, s.agent_id, a.agent_name,
               (SELECT COUNT(*) FROM receipts r2 WHERE r2.matched_sale_id=s.id) matched_n
        FROM sales s
        JOIN agents a ON s.agent_id = a.id
        WHERE s.txn_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
          AND s.amount BETWEEN ? * 0.95 AND ? * 1.05
        ORDER BY ABS(DATEDIFF(s.txn_date, ?))
        LIMIT 200
    ");
    $stmt->bind_param('ssdds', $r['txn_date'], $r['txn_date'], $r['amount'], $r['amount'], $r['txn_date']);
    $stmt->execute();
    $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Terminal ownership at the receipt date
    $owner_id = null;
    if (!empty($r['terminal_id'])) {
        $o_stmt = $db->prepare("
            SELECT ta.agent_id
            FROM pos_terminals pt
            JOIN terminal_assignments ta ON ta.terminal_id = pt.id
            WHERE pt.terminal_id=?
              AND ? >= ta.valid_from
              AND (ta.valid_to IS NULL OR ? <= ta.valid_to)
            LIMIT 1
        ");
        $o_stmt->bind_param('sss', $r['terminal_id'], $r['txn_date'], $r['txn_date']);
        $o_stmt->execute();
        $o = $o_stmt->get_result()->fetch_assoc();
        $o_stmt->close();
        if ($o) $owner_id = (int)$o['agent_id'];
    }

    // Normalize reference tokens from the receipt
    $ref_tokens = array();
    foreach (preg_split('/[\s,;:\/\|]+/', strtoupper($r['reference_no'])) as $t) {
        $t = preg_replace('/[^A-Z0-9]/', '', $t);
        if (strlen($t) >= 4) $ref_tokens[] = $t;
    }

    $scored = array();
    foreach ($candidates as $s) {
        $score = 0;
        $reasons = array();

        // Amount match — 0 to 40 points, linear decay
        $diff = abs((float)$s['amount'] - (float)$r['amount']);
        $amt_rel = $r['amount'] > 0 ? $diff / (float)$r['amount'] : 0;
        if ($diff < 0.01) { $score += 40; $reasons[] = 'exact amount'; }
        elseif ($amt_rel < 0.005) { $score += 35; $reasons[] = 'amount ±0.5%'; }
        elseif ($amt_rel < 0.02)  { $score += 25; $reasons[] = 'amount ±2%'; }
        elseif ($amt_rel < 0.05)  { $score += 10; $reasons[] = 'amount ±5%'; }

        // Date proximity — 0 to 20
        $days = abs((strtotime($s['txn_date']) - strtotime($r['txn_date'])) / 86400);
        if ($days == 0)      { $score += 20; $reasons[] = 'same date'; }
        elseif ($days <= 1)  { $score += 15; $reasons[] = '±1 day'; }
        elseif ($days <= 3)  { $score += 8;  $reasons[] = '±3 days'; }

        // Channel match — 0 to 10
        if ($s['payment_method'] === $r['channel']) {
            $score += 10;
            $reasons[] = 'channel match';
        }

        // Currency match — 0 to 10
        if ($s['currency'] === $r['currency']) {
            $score += 10;
        } else {
            $score -= 5;
            $reasons[] = 'CURRENCY MISMATCH';
        }

        // Terminal match — 0 to 15
        if (!empty($r['terminal_id']) && !empty($s['terminal_id']) && $r['terminal_id'] === $s['terminal_id']) {
            $score += 15;
            $reasons[] = 'same terminal';
        }

        // Agent ownership via terminal assignment — 0 to 10
        if ($owner_id && (int)$s['agent_id'] === $owner_id) {
            $score += 10;
            $reasons[] = 'agent owns terminal';
        }

        // Reference/policy token match — 0 to 20
        $policy_clean = preg_replace('/[^A-Z0-9]/', '', strtoupper($s['policy_number']));
        foreach ($ref_tokens as $rt) {
            if (strlen($policy_clean) >= 4 && (strpos($rt, $policy_clean) !== false || strpos($policy_clean, $rt) !== false)) {
                $score += 20;
                $reasons[] = 'policy in reference';
                break;
            }
        }

        // Penalty if sale already matched
        if ((int)$s['matched_n'] > 0) {
            $score -= 20;
            $reasons[] = 'already matched (will reassign)';
        }

        $s['confidence_score'] = $score;
        $s['match_reasons']    = $reasons;
        $scored[] = $s;
    }

    usort($scored, function($a, $b) { return $b['confidence_score'] - $a['confidence_score']; });
    return array_slice($scored, 0, 5);
}

// NOTE: The CSV export function that used to live here has been
// replaced by a printable-PDF report at process/process_export.php
// (type=unmatched). Any legacy ?export=csv URL is redirected there so
// old bookmarks keep working.

// ════════════════════════════════════════════════════════════
// DISPATCH
// ════════════════════════════════════════════════════════════
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $qs = http_build_query(array_diff_key($_GET, array('export'=>1, 'action'=>1)));
    header('Location: ' . BASE_URL . '/process/process_export.php?type=unmatched' . ($qs ? '&' . $qs : ''));
    exit;
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

try {
    // ── AJAX: suggestions ───────────────────────────────────
    if ($action === 'suggest') {
        $rid = (int)($_REQUEST['receipt_id'] ?? 0);
        if ($rid <= 0) json_out(array('error' => 'receipt_id required'), 400);
        json_out(array('candidates' => suggest_candidates($db, $rid)));
    }

    // ── Manual match (fixed) ────────────────────────────────
    if ($action === 'manual_match') {
        $receipt_id = (int)($_POST['receipt_id'] ?? 0);
        $sales_id   = (int)($_POST['sales_id']   ?? 0);
        $reason     = trim($_POST['reason']      ?? '');
        $comments   = trim($_POST['comments']    ?? '');

        if (!$receipt_id || !$sales_id) redirect_back('error', 'Invalid transaction IDs.');

        $sale = null;
        $s_stmt = $db->prepare("SELECT id, policy_number, currency FROM sales WHERE id=?");
        $s_stmt->bind_param('i', $sales_id);
        $s_stmt->execute();
        $sale = $s_stmt->get_result()->fetch_assoc();
        $s_stmt->close();
        if (!$sale) redirect_back('error', 'Sale not found.');

        // Capture the previous sale link (for paid_status refresh) and
        // enforce the 10-receipt cap.
        $cur = $db->prepare("SELECT matched_sale_id, currency FROM receipts WHERE id=?");
        $cur->bind_param('i', $receipt_id);
        $cur->execute();
        $cur_row = $cur->get_result()->fetch_assoc();
        $cur->close();
        $prev_sale_id = $cur_row && $cur_row['matched_sale_id'] ? (int)$cur_row['matched_sale_id'] : null;
        if ($prev_sale_id !== $sales_id) {
            if (receipts_attached_to_sale($db, $sales_id) >= SPLIT_MAX_RECEIPTS_PER_SALE) {
                redirect_back('error', 'Sale already has the maximum of ' . SPLIT_MAX_RECEIPTS_PER_SALE . ' receipts allocated.');
            }
        }
        $new_status = ($cur_row && $cur_row['currency'] !== $sale['currency'])
                        ? 'currency_review' : 'matched';

        $db->begin_transaction();
        try {
            // Set matched_sale_id AND match_confidence='manual' so re-runs preserve it
            $upd = $db->prepare("
                UPDATE receipts
                SET matched_policy=?, matched_sale_id=?, match_status=?, match_confidence='manual'
                WHERE id=?
            ");
            $upd->bind_param('sisi', $sale['policy_number'], $sales_id, $new_status, $receipt_id);
            $upd->execute();
            $upd->close();

            // Log to manual_match_log (the correct table)
            $full_reason = $reason . ($comments ? ' — ' . $comments : '');
            $log = $db->prepare("
                INSERT INTO manual_match_log (receipt_id, sale_id, action, reason, user_id)
                VALUES (?, ?, 'match', ?, ?)
            ");
            $log->bind_param('iisi', $receipt_id, $sales_id, $full_reason, $uid);
            $log->execute();
            $log->close();

            if ($prev_sale_id && $prev_sale_id !== $sales_id) refresh_paid_status($db, $prev_sale_id);
            refresh_paid_status($db, $sales_id);

            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            redirect_back('error', 'Match failed: ' . $e->getMessage());
        }

        audit_log($uid, 'DATA_EDIT',
            "Manual match: receipt $receipt_id → sale $sales_id (policy {$sale['policy_number']}) — $reason");
        redirect_back('success', "Matched to policy {$sale['policy_number']}.");
    }

    // ── Exclude / write-off ─────────────────────────────────
    if ($action === 'exclude') {
        $receipt_id = (int)($_POST['receipt_id'] ?? 0);
        $reason     = trim($_POST['exclude_reason'] ?? '');
        $note       = trim($_POST['exclude_note']   ?? '');
        if (!$receipt_id) redirect_back('error', 'Invalid receipt.');
        if (!in_array($reason, array('duplicate','refund','bank_error','write_off','other'))) {
            redirect_back('error', 'Invalid exclusion reason.');
        }
        if (strlen($note) < 5) redirect_back('error', 'Exclusion note required (min 5 chars).');

        // Track the prior sale link so paid_status drops the excluded receipt's contribution.
        $cur = $db->prepare("SELECT matched_sale_id FROM receipts WHERE id=?");
        $cur->bind_param('i', $receipt_id);
        $cur->execute();
        $cur_row = $cur->get_result()->fetch_assoc();
        $cur->close();
        $prev_sale_id = $cur_row && $cur_row['matched_sale_id'] ? (int)$cur_row['matched_sale_id'] : null;

        $upd = $db->prepare("
            UPDATE receipts
            SET match_status='excluded', exclude_reason=?, exclude_note=?,
                excluded_by=?, excluded_at=NOW(), match_confidence='manual'
            WHERE id=?
        ");
        $upd->bind_param('ssii', $reason, $note, $uid, $receipt_id);
        $upd->execute();
        $upd->close();

        if ($prev_sale_id) refresh_paid_status($db, $prev_sale_id);

        audit_log($uid, 'DATA_EDIT', "Excluded receipt $receipt_id: $reason — $note");
        redirect_back('success', "Receipt excluded as '$reason'.");
    }

    // ── Bulk accept high-confidence suggestions ─────────────
    if ($action === 'bulk_accept') {
        $threshold = (int)($_POST['threshold'] ?? 80);
        $date_from = $_POST['date_from'] ?? date('Y-m-01');
        $date_to   = $_POST['date_to']   ?? date('Y-m-t');
        $date_from = date('Y-m-d', strtotime($date_from));
        $date_to   = date('Y-m-d', strtotime($date_to));

        $receipts = $db->query("
            SELECT id FROM receipts
            WHERE match_status='pending'
              AND direction='credit'
              AND txn_date BETWEEN '$date_from' AND '$date_to'
            LIMIT 500
        ")->fetch_all(MYSQLI_ASSOC);

        $accepted = 0; $skipped = 0;
        $db->begin_transaction();
        try {
            $upd = $db->prepare("
                UPDATE receipts
                SET matched_policy=?, matched_sale_id=?, match_status='matched', match_confidence='manual'
                WHERE id=?
            ");
            $log = $db->prepare("
                INSERT INTO manual_match_log (receipt_id, sale_id, action, reason, user_id)
                VALUES (?, ?, 'match', ?, ?)
            ");

            foreach ($receipts as $r) {
                $cands = suggest_candidates($db, (int)$r['id']);
                if (empty($cands) || $cands[0]['confidence_score'] < $threshold) { $skipped++; continue; }
                $top = $cands[0];
                $upd->bind_param('sii', $top['policy_number'], $top['id'], $r['id']);
                $upd->execute();
                $bulk_reason = "Bulk-accepted (score {$top['confidence_score']}): " . implode(', ', $top['match_reasons']);
                $bulk_reason = substr($bulk_reason, 0, 255);
                $log->bind_param('iisi', $r['id'], $top['id'], $bulk_reason, $uid);
                $log->execute();
                $accepted++;
            }
            $upd->close();
            $log->close();
            // Touched many sales — bulk recompute is cheaper than per-row.
            refresh_paid_status($db);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            redirect_back('error', 'Bulk accept failed: ' . $e->getMessage());
        }

        audit_log($uid, 'DATA_EDIT', "Bulk-accepted $accepted suggestions (threshold $threshold)");
        redirect_back('success', "Auto-matched $accepted receipts. $skipped had no high-confidence candidate.");
    }

    // ── Escalate a receipt ──────────────────────────────────
    if ($action === 'escalate') {
        $receipt_id = (int)($_POST['receipt_id'] ?? 0);
        $priority   = $_POST['priority'] ?? 'medium';
        $note       = trim($_POST['note'] ?? '');
        if (!$receipt_id || strlen($note) < 5) redirect_back('error', 'Receipt and note (5+ chars) required.');
        if (!in_array($priority, array('low','medium','high','critical'))) $priority = 'medium';

        $r = $db->query("SELECT reference_no, amount, currency, txn_date, channel FROM receipts WHERE id=$receipt_id")->fetch_assoc();
        if (!$r) redirect_back('error', 'Receipt not found.');

        // Auto-assign to least-loaded manager
        $mgr = $db->query("
            SELECT u.id FROM users u
            LEFT JOIN (SELECT assigned_to, COUNT(*) cnt FROM escalations WHERE status='pending' GROUP BY assigned_to) e ON e.assigned_to=u.id
            WHERE u.role='Manager' AND u.is_active=1
            ORDER BY COALESCE(e.cnt,0), u.id LIMIT 1
        ")->fetch_assoc();
        $assigned_to = $mgr ? (int)$mgr['id'] : null;

        $detail = "Unmatched receipt {$r['reference_no']} ({$r['currency']} " . number_format($r['amount'], 2)
                . ", {$r['channel']}, {$r['txn_date']}) — $note";
        $detail = substr($detail, 0, 500);

        $ins = $db->prepare("
            INSERT INTO escalations
              (user_id, assigned_to, action_type, action_detail, affected_entity, entity_id, priority, status)
            VALUES (?, ?, 'unmatched', ?, 'receipt', ?, ?, 'pending')
        ");
        $ins->bind_param('iisis', $uid, $assigned_to, $detail, $receipt_id, $priority);
        $ins->execute();
        $esc_id = $ins->insert_id;
        $ins->close();

        // Notify the assigned manager (or all managers if auto-assign failed).
        $subject = "New escalation #$esc_id — unmatched receipt, priority " . strtoupper($priority);
        $body    = "{$user['name']} escalated an unmatched receipt.\n\n"
                 . "Priority: " . strtoupper($priority) . "\n"
                 . "Detail: $detail\n\n"
                 . "Review it here: " . BASE_URL . "/admin/escalations.php";
        if ($assigned_to) {
            enqueue_email($db, $assigned_to, $subject, $body, 'escalation', 'notif_escalation_assigned');
        } else {
            enqueue_email_to_role($db, 'Manager', $subject, $body, 'escalation', 'notif_escalation_assigned');
        }

        audit_log($uid, 'DATA_EDIT', "Escalated unmatched receipt $receipt_id (priority $priority)");
        redirect_back('success', 'Receipt escalated to manager.');
    }

    // ── Bulk exclude ─────────────────────────────────────────
    if ($action === 'bulk_exclude') {
        $ids_raw = isset($_POST['receipt_ids']) ? $_POST['receipt_ids'] : '';
        $reason  = trim(isset($_POST['exclude_reason']) ? $_POST['exclude_reason'] : '');
        $note    = trim(isset($_POST['exclude_note']) ? $_POST['exclude_note'] : '');
        if (!$ids_raw) redirect_back('error', 'No receipts selected.');
        if (!in_array($reason, array('duplicate','refund','bank_error','write_off','other'))) $reason = 'write_off';
        if (strlen($note) < 5) redirect_back('error', 'Note required (min 5 chars).');

        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
        if (empty($ids)) redirect_back('error', 'No valid receipt IDs.');

        $upd = $db->prepare("UPDATE receipts SET match_status='excluded', exclude_reason=?, exclude_note=?, excluded_by=?, excluded_at=NOW(), match_confidence='manual' WHERE id=?");
        $count = 0;
        foreach ($ids as $rid) {
            $upd->bind_param('ssii', $reason, $note, $uid, $rid);
            $upd->execute();
            if ($upd->affected_rows > 0) $count++;
        }
        $upd->close();
        // Some of these receipts may have been allocated to sales — drop
        // their contribution from paid_status across the board.
        refresh_paid_status($db);

        audit_log($uid, 'DATA_EDIT', "Bulk excluded $count receipts: $reason — $note");
        redirect_back('success', "$count receipts excluded as '$reason'.");
    }

    // ── Currency-review approval (per sale) ─────────────────
    // The reconciler has eyeballed the cross-currency allocation and
    // decided it really does cover the sale (FX rate makes it work).
    // Flip every receipt attached to this sale from currency_review →
    // matched and let refresh_paid_status() promote the sale itself.
    if ($action === 'currency_review_approve') {
        $sale_id = (int)($_POST['sale_id'] ?? 0);
        $note    = substr(trim($_POST['note'] ?? ''), 0, 500);
        if ($sale_id <= 0) redirect_back('error', 'sale_id required.');

        $db->begin_transaction();
        try {
            $upd = $db->prepare(
                "UPDATE receipts
                    SET match_status = 'matched',
                        match_confidence = 'manual'
                  WHERE matched_sale_id = ?
                    AND match_status = 'currency_review'"
            );
            $upd->bind_param('i', $sale_id);
            $upd->execute();
            $touched = $upd->affected_rows;
            $upd->close();

            refresh_paid_status($db, $sale_id);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            redirect_back('error', 'Approve failed: ' . $e->getMessage());
        }

        audit_log($uid, 'DATA_EDIT',
            "Currency-review approved for sale $sale_id ($touched receipt(s))" . ($note ? " — $note" : ''));
        redirect_back('success', "Approved $touched receipt(s) for sale #$sale_id.");
    }

    // ── Currency-review rejection (per sale) ────────────────
    // Detach every currency_review receipt from this sale, sending
    // them back to 'pending' for re-matching with same-currency partners.
    if ($action === 'currency_review_reject') {
        $sale_id = (int)($_POST['sale_id'] ?? 0);
        $note    = substr(trim($_POST['note'] ?? ''), 0, 500);
        if ($sale_id <= 0) redirect_back('error', 'sale_id required.');

        $db->begin_transaction();
        try {
            $upd = $db->prepare(
                "UPDATE receipts
                    SET matched_policy = NULL,
                        matched_sale_id = NULL,
                        match_status = 'pending',
                        match_confidence = NULL
                  WHERE matched_sale_id = ?
                    AND match_status = 'currency_review'"
            );
            $upd->bind_param('i', $sale_id);
            $upd->execute();
            $touched = $upd->affected_rows;
            $upd->close();

            refresh_paid_status($db, $sale_id);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            redirect_back('error', 'Reject failed: ' . $e->getMessage());
        }

        audit_log($uid, 'DATA_EDIT',
            "Currency-review rejected for sale $sale_id ($touched receipt(s) detached)" . ($note ? " — $note" : ''));
        redirect_back('success', "Detached $touched receipt(s) from sale #$sale_id.");
    }

    redirect_back('error', 'Unknown action.');

} catch (Exception $e) {
    redirect_back('error', 'Error: ' . $e->getMessage());
}
