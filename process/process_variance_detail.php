<?php
// ============================================================
// process/process_variance_detail.php
// AJAX endpoints for the variance drill-down + escalation flow.
// Actions:
//   detail     — GET per-agent breakdown for a run (JSON)
//   escalate   — POST create an escalation assigned to a Manager
// ============================================================

require_once '../core/auth.php';
require_once '../core/notifications.php';
require_role(['Manager','Reconciler']);
csrf_verify();

header('Content-Type: application/json');

$db   = get_db();
$user = current_user();
$uid  = (int)$user['id'];

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

try {
    // ── DETAIL ENDPOINT ──────────────────────────────────────
    if ($action === 'detail') {
        $run_id   = (int)(isset($_GET['run_id'])   ? $_GET['run_id']   : 0);
        $agent_id = (int)(isset($_GET['agent_id']) ? $_GET['agent_id'] : 0);
        if ($run_id <= 0 || $agent_id <= 0) respond(array('error'=>'run_id and agent_id required'), 400);

        // Period covered by this run
        $run_stmt = $db->prepare("SELECT date_from, date_to, period_label, product FROM reconciliation_runs WHERE id=?");
        $run_stmt->bind_param('i', $run_id);
        $run_stmt->execute();
        $run = $run_stmt->get_result()->fetch_assoc();
        $run_stmt->close();
        if (!$run) respond(array('error'=>'Run not found'), 404);

        // Agent header
        $a_stmt = $db->prepare("SELECT id, agent_name, agent_code, agent_type, region FROM agents WHERE id=?");
        $a_stmt->bind_param('i', $agent_id);
        $a_stmt->execute();
        $agent = $a_stmt->get_result()->fetch_assoc();
        $a_stmt->close();
        if (!$agent) respond(array('error'=>'Agent not found'), 404);

        // Per-agent variance summary
        $v_stmt = $db->prepare("SELECT * FROM variance_results WHERE run_id=? AND agent_id=?");
        $v_stmt->bind_param('ii', $run_id, $agent_id);
        $v_stmt->execute();
        $summary = $v_stmt->get_result()->fetch_assoc();
        $v_stmt->close();

        // Per-channel breakdown
        $c_stmt = $db->prepare("SELECT * FROM variance_by_channel WHERE run_id=? AND agent_id=? ORDER BY ABS(variance_zwg)+ABS(variance_usd) DESC");
        $c_stmt->bind_param('ii', $run_id, $agent_id);
        $c_stmt->execute();
        $channels = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $c_stmt->close();

        // Unmatched sales for this agent in the period (no matching receipt)
        $us_stmt = $db->prepare("
            SELECT s.id, s.policy_number, s.txn_date, s.amount, s.currency,
                   s.payment_method, s.terminal_id
            FROM sales s
            LEFT JOIN receipts r ON r.matched_sale_id = s.id
            WHERE s.agent_id=? AND s.txn_date BETWEEN ? AND ? AND r.id IS NULL
            ORDER BY s.txn_date DESC, ABS(s.amount) DESC
            LIMIT 50
        ");
        $us_stmt->bind_param('iss', $agent_id, $run['date_from'], $run['date_to']);
        $us_stmt->execute();
        $unmatched_sales = $us_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $us_stmt->close();

        // Unmatched receipts likely belonging to this agent. Two signals:
        //   1. terminal_id resolves (via terminal_assignments) to this agent
        //      on the receipt date — authoritative ownership at the time.
        //   2. source_name mentions the agent name or code — weaker hint.
        // Signal 1 catches reassigned-terminal cases correctly; signal 2
        // catches receipts that don't carry a terminal_id (EcoCash, broker).
        $name_like = '%' . $agent['agent_name'] . '%';
        $code_like = '%' . $agent['agent_code'] . '%';
        $ur_stmt = $db->prepare("
            SELECT DISTINCT r.id, r.reference_no, r.txn_date, r.amount, r.currency, r.channel, r.source_name, r.terminal_id
            FROM receipts r
            LEFT JOIN pos_terminals pt ON pt.terminal_id = r.terminal_id
            LEFT JOIN terminal_assignments ta
                   ON ta.terminal_id = pt.id
                  AND r.txn_date >= ta.valid_from
                  AND (ta.valid_to IS NULL OR r.txn_date <= ta.valid_to)
            WHERE r.match_status='pending'
              AND r.txn_date BETWEEN ? AND ?
              AND (
                   ta.agent_id = ?
                OR r.source_name LIKE ?
                OR r.source_name LIKE ?
              )
            ORDER BY r.txn_date DESC
            LIMIT 50
        ");
        $ur_stmt->bind_param('ssiss', $run['date_from'], $run['date_to'], $agent_id, $name_like, $code_like);
        $ur_stmt->execute();
        $unmatched_receipts = $ur_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ur_stmt->close();

        // Low-confidence matches for this agent — reviewers should double-check
        $lc_stmt = $db->prepare("
            SELECT r.id, r.reference_no, r.txn_date, r.amount, r.currency, r.channel,
                   r.match_confidence, s.policy_number, s.amount AS sale_amount, s.txn_date AS sale_date
            FROM receipts r
            INNER JOIN sales s ON r.matched_sale_id = s.id
            WHERE s.agent_id=? AND r.txn_date BETWEEN ? AND ?
              AND r.match_confidence IN ('low','medium')
            ORDER BY r.match_confidence ASC
            LIMIT 50
        ");
        $lc_stmt->bind_param('iss', $agent_id, $run['date_from'], $run['date_to']);
        $lc_stmt->execute();
        $low_conf_matches = $lc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lc_stmt->close();

        // Currency mismatches for this agent
        $fx_stmt = $db->prepare("
            SELECT s.id, s.policy_number, s.txn_date, s.amount, s.currency, s.payment_method
            FROM sales s
            WHERE s.agent_id=? AND s.currency_flag=1 AND s.txn_date BETWEEN ? AND ?
            LIMIT 50
        ");
        $fx_stmt->bind_param('iss', $agent_id, $run['date_from'], $run['date_to']);
        $fx_stmt->execute();
        $fx_mismatches = $fx_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fx_stmt->close();

        // Existing escalation for this agent+run, if any
        $esc_stmt = $db->prepare("
            SELECT id, status, priority, action_detail, created_at, reviewed_at,
                   review_note, assigned_to
            FROM escalations
            WHERE run_id=? AND agent_id=?
            ORDER BY created_at DESC LIMIT 1
        ");
        $esc_stmt->bind_param('ii', $run_id, $agent_id);
        $esc_stmt->execute();
        $existing_escalation = $esc_stmt->get_result()->fetch_assoc();
        $esc_stmt->close();

        respond(array(
            'run'                 => $run,
            'agent'               => $agent,
            'summary'             => $summary,
            'channels'            => $channels,
            'unmatched_sales'     => $unmatched_sales,
            'unmatched_receipts'  => $unmatched_receipts,
            'low_conf_matches'    => $low_conf_matches,
            'fx_mismatches'       => $fx_mismatches,
            'existing_escalation' => $existing_escalation,
        ));
    }

    // ── ESCALATE ENDPOINT ────────────────────────────────────
    if ($action === 'escalate') {
        $run_id   = (int)(isset($_POST['run_id'])   ? $_POST['run_id']   : 0);
        $agent_id = (int)(isset($_POST['agent_id']) ? $_POST['agent_id'] : 0);
        $priority = isset($_POST['priority']) ? $_POST['priority'] : 'medium';
        $note     = isset($_POST['note']) ? trim($_POST['note']) : '';

        if ($run_id <= 0 || $agent_id <= 0) respond(array('error'=>'run_id and agent_id required'), 400);
        if (!in_array($priority, array('low','medium','high','critical'))) $priority = 'medium';
        if (strlen($note) < 5) respond(array('error'=>'Please provide a note (at least 5 characters)'), 400);

        // Pull variance numbers to embed on the escalation
        $v_stmt = $db->prepare("SELECT variance_zwg, variance_usd, variance_cat FROM variance_results WHERE run_id=? AND agent_id=?");
        $v_stmt->bind_param('ii', $run_id, $agent_id);
        $v_stmt->execute();
        $v = $v_stmt->get_result()->fetch_assoc();
        $v_stmt->close();

        $a_stmt = $db->prepare("SELECT agent_name FROM agents WHERE id=?");
        $a_stmt->bind_param('i', $agent_id);
        $a_stmt->execute();
        $agent_name = $a_stmt->get_result()->fetch_assoc()['agent_name'];
        $a_stmt->close();

        // Auto-assign to the least-loaded active Manager.
        // "Least loaded" = fewest pending escalations currently assigned.
        $mgr = $db->query("
            SELECT u.id, u.full_name, COALESCE(e.cnt, 0) AS load_cnt
            FROM users u
            LEFT JOIN (SELECT assigned_to, COUNT(*) cnt FROM escalations WHERE status='pending' GROUP BY assigned_to) e
                   ON e.assigned_to = u.id
            WHERE u.role='Manager' AND u.is_active=1
            ORDER BY load_cnt ASC, u.id ASC
            LIMIT 1
        ")->fetch_assoc();
        $assigned_to = $mgr ? (int)$mgr['id'] : null;

        $detail = "Variance on $agent_name"
                . ($v && $v['variance_cat'] ? " ({$v['variance_cat']})" : '')
                . ": ZWG " . number_format((float)($v['variance_zwg'] ?? 0), 2)
                . ", USD " . number_format((float)($v['variance_usd'] ?? 0), 2)
                . " — " . $note;
        $detail = substr($detail, 0, 500);

        $var_zwg = isset($v['variance_zwg']) ? (float)$v['variance_zwg'] : 0;
        $var_usd = isset($v['variance_usd']) ? (float)$v['variance_usd'] : 0;

        $ins = $db->prepare("
            INSERT INTO escalations
              (run_id, agent_id, user_id, assigned_to, action_type, action_detail,
               affected_entity, entity_id, variance_zwg, variance_usd, priority, status)
            VALUES (?, ?, ?, ?, 'variance', ?, 'agent', ?, ?, ?, ?, 'pending')
        ");
        $ins->bind_param('iiiisidds',
            $run_id, $agent_id, $uid, $assigned_to, $detail, $agent_id,
            $var_zwg, $var_usd, $priority);
        $ins->execute();
        $esc_id = $ins->insert_id;
        $ins->close();

        // Notify the assigned manager (or all managers if auto-assign failed).
        $subject = "New escalation #$esc_id — variance on $agent_name, priority " . strtoupper($priority);
        $body    = "{$user['name']} escalated a variance.\n\n"
                 . "Priority: " . strtoupper($priority) . "\n"
                 . "Detail: $detail\n\n"
                 . "Review it here: " . BASE_URL . "/admin/escalations.php";
        if ($assigned_to) {
            enqueue_email($db, $assigned_to, $subject, $body, 'escalation', 'notif_escalation_assigned');
        } else {
            enqueue_email_to_role($db, 'Manager', $subject, $body, 'escalation', 'notif_escalation_assigned');
        }

        audit_log($uid, 'DATA_EDIT',
            "Escalated variance for agent $agent_name (run $run_id) → escalation #$esc_id, priority=$priority");

        respond(array(
            'success'       => true,
            'escalation_id' => $esc_id,
            'assigned_to'   => $mgr ? $mgr['full_name'] : 'Unassigned',
        ));
    }

    respond(array('error'=>'Unknown action'), 400);

} catch (Exception $e) {
    respond(array('error'=>$e->getMessage()), 500);
}
