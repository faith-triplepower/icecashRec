<?php
// ============================================================
// process/process_statements.php — Statement Lifecycle
// Issue (single/bulk), finalize (maker-checker enforced),
// review, and cancel reconciliation statements.
// Notifies Managers when Reconcilers create drafts.
// Part of IceCashRec — Zimnat General Insurance
// ============================================================

require_once '../core/auth.php';
require_once '../core/notifications.php';
require_role(['Manager','Reconciler','Admin']);
csrf_verify();

$db     = get_db();
$user   = current_user();
$uid    = (int)$user['id'];
$role   = $user['role'];
$action = $_POST['action'] ?? '';

// Per-action role enforcement (matches the permission matrix).
// Reconcilers can generate/edit drafts but cannot finalize or cancel.
$manager_only_actions = array('finalize', 'review', 'cancel');
if (in_array($action, $manager_only_actions) && !in_array($role, array('Manager', 'Admin'))) {
    redirect_back('error', 'Only Managers can ' . $action . ' statements.');
}

function redirect_back($type, $msg, $params = array()) {
    $qs = array_merge($params, array($type => $msg));
    header("Location: " . BASE_URL . "/admin/statements.php?" . http_build_query($qs));
    exit;
}

// Generate the next statement number for a period like ST-2026-04-0001
function next_statement_no($db, $period_from) {
    $yymm = date('Y-m', strtotime($period_from));
    $prefix = "ST-$yymm-";
    $row = $db->query("
        SELECT MAX(CAST(SUBSTRING_INDEX(statement_no, '-', -1) AS UNSIGNED)) mx
        FROM statements
        WHERE statement_no LIKE '" . $db->real_escape_string($prefix) . "%'
    ")->fetch_assoc();
    $next = ((int)($row['mx'] ?? 0)) + 1;
    return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
}

switch ($action) {

    // ── ISSUE statement from an existing recon run + agent ──
    // Snapshots numbers from variance_results into a statements row.
    case 'issue':
        $run_id   = (int)($_POST['run_id']   ?? 0);
        $agent_id = (int)($_POST['agent_id'] ?? 0);
        $notes    = trim($_POST['notes']     ?? '');
        if (!$run_id || !$agent_id) redirect_back('error', 'run_id and agent_id required');

        // Pull variance + run context
        $stmt = $db->prepare("
            SELECT r.date_from, r.date_to, r.period_label,
                   vr.sales_zwg, vr.sales_usd, vr.receipts_zwg, vr.receipts_usd,
                   vr.variance_zwg, vr.variance_usd, vr.variance_cat
            FROM reconciliation_runs r
            LEFT JOIN variance_results vr ON vr.run_id = r.id AND vr.agent_id = ?
            WHERE r.id = ?
        ");
        $stmt->bind_param('ii', $agent_id, $run_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$data) redirect_back('error', 'Run or agent not found');

        // Guardrail: don't re-issue an identical statement for the same run+agent
        $chk = $db->prepare("SELECT id, statement_no FROM statements WHERE run_id=? AND agent_id=? AND status<>'cancelled' LIMIT 1");
        $chk->bind_param('ii', $run_id, $agent_id);
        $chk->execute();
        $dup = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($dup) {
            redirect_back('error', "Statement already issued for this run+agent: " . $dup['statement_no']);
        }

        $statement_no = next_statement_no($db, $data['date_from']);

        $ins = $db->prepare("
            INSERT INTO statements
              (statement_no, run_id, agent_id, period_from, period_to, status,
               sales_zwg, sales_usd, receipts_zwg, receipts_usd,
               variance_zwg, variance_usd, variance_cat, notes, generated_by)
            VALUES (?, ?, ?, ?, ?, 'draft',
                    ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $s_zwg = (float)($data['sales_zwg'] ?? 0);
        $s_usd = (float)($data['sales_usd'] ?? 0);
        $r_zwg = (float)($data['receipts_zwg'] ?? 0);
        $r_usd = (float)($data['receipts_usd'] ?? 0);
        $v_zwg = (float)($data['variance_zwg'] ?? 0);
        $v_usd = (float)($data['variance_usd'] ?? 0);
        $cat   = $data['variance_cat'];

        $ins->bind_param('siissddddddssi',
            $statement_no, $run_id, $agent_id, $data['date_from'], $data['date_to'],
            $s_zwg, $s_usd, $r_zwg, $r_usd, $v_zwg, $v_usd, $cat, $notes, $uid);
        $ins->execute();
        $new_id = (int)$ins->insert_id;
        $ins->close();

        audit_log($uid, 'DATA_EDIT', "Issued statement $statement_no (run $run_id / agent $agent_id)");

        // Notify Managers that a draft statement is ready for review
        if ($role !== 'Manager') {
            $agent_row = $db->query("SELECT agent_name FROM agents WHERE id=$agent_id")->fetch_assoc();
            $agent_label = $agent_row ? $agent_row['agent_name'] : "Agent #$agent_id";
            enqueue_email_to_role($db, 'Manager',
                "Draft statement $statement_no ready for review",
                "{$user['name']} issued a draft reconciliation statement.\n\n"
                . "Statement: $statement_no\n"
                . "Agent: $agent_label\n"
                . "Variance ZWG: " . number_format($v_zwg, 2) . "\n\n"
                . "Review and finalize: " . BASE_URL . "/admin/statement_detail.php?id=$new_id",
                'generic', null
            );
        }

        header("Location: " . BASE_URL . "/admin/statement_detail.php?id=$new_id&success=" . urlencode("Statement $statement_no issued as draft."));
        exit;

    // ── FINALIZE (mark draft as final) ──────────────────────
    // Fix 10: Maker-checker — the person who generated the draft cannot
    // be the same one who finalizes it. Enforces dual sign-off for
    // financial control.
    case 'finalize':
        $id = (int)($_POST['statement_id'] ?? 0);
        if (!$id) redirect_back('error', 'statement_id required');

        $chk = $db->prepare("SELECT generated_by, statement_no FROM statements WHERE id=? AND status='draft'");
        $chk->bind_param('i', $id);
        $chk->execute();
        $draft = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$draft) redirect_back('error', 'Statement not found or already finalized.');
        if ((int)$draft['generated_by'] === $uid) {
            redirect_back('error', 'Maker-checker: you cannot finalize a statement you generated. A different Manager must sign off.');
        }

        $upd = $db->prepare("UPDATE statements SET status='final' WHERE id=? AND status='draft'");
        $upd->bind_param('i', $id);
        $upd->execute();
        $upd->close();

        $row = $db->query("SELECT statement_no FROM statements WHERE id=$id")->fetch_assoc();
        audit_log($uid, 'DATA_EDIT', "Finalized statement #{$row['statement_no']}");
        header("Location: " . BASE_URL . "/admin/statement_detail.php?id=$id&success=" . urlencode('Statement finalized.'));
        exit;

    // ── MARK AS REVIEWED (by a different manager) ───────────
    case 'review':
        $id    = (int)($_POST['statement_id'] ?? 0);
        $notes = trim($_POST['review_notes'] ?? '');
        if (!$id) redirect_back('error', 'statement_id required');

        $upd = $db->prepare("
            UPDATE statements
            SET status='reviewed', reviewed_by=?, reviewed_at=NOW(),
                notes = CONCAT(COALESCE(notes,''), CASE WHEN notes IS NOT NULL AND notes<>'' THEN '\n---\n' ELSE '' END, ?)
            WHERE id=? AND status IN ('draft','final')
        ");
        $review_block = 'REVIEW (' . $user['name'] . '): ' . $notes;
        $upd->bind_param('isi', $uid, $review_block, $id);
        $upd->execute();
        $upd->close();

        $row = $db->query("SELECT statement_no FROM statements WHERE id=$id")->fetch_assoc();
        audit_log($uid, 'DATA_EDIT', "Reviewed statement #{$row['statement_no']}");
        header("Location: " . BASE_URL . "/admin/statement_detail.php?id=$id&success=" . urlencode('Statement reviewed.'));
        exit;

    // ── CANCEL ──────────────────────────────────────────────
    case 'cancel':
        $id     = (int)($_POST['statement_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if (!$id) redirect_back('error', 'statement_id required');
        if (strlen($reason) < 5) redirect_back('error', 'Cancellation reason required (min 5 chars)');

        $upd = $db->prepare("
            UPDATE statements
            SET status='cancelled',
                notes = CONCAT(COALESCE(notes,''), '\nCANCELLED: ', ?)
            WHERE id=?
        ");
        $upd->bind_param('si', $reason, $id);
        $upd->execute();
        $upd->close();

        $row = $db->query("SELECT statement_no FROM statements WHERE id=$id")->fetch_assoc();
        audit_log($uid, 'DATA_EDIT', "Cancelled statement #{$row['statement_no']}: $reason");
        redirect_back('success', "Statement cancelled.");

    // ── BULK ISSUE for all variant agents in a run ──────────
    case 'bulk_issue':
        $run_id = (int)($_POST['run_id'] ?? 0);
        if (!$run_id) redirect_back('error', 'run_id required');

        $agents = $db->query("
            SELECT vr.agent_id
            FROM variance_results vr
            WHERE vr.run_id = $run_id
              AND NOT EXISTS (
                SELECT 1 FROM statements s
                WHERE s.run_id = vr.run_id AND s.agent_id = vr.agent_id AND s.status <> 'cancelled'
              )
        ")->fetch_all(MYSQLI_ASSOC);

        $created = 0;
        foreach ($agents as $ag) {
            $_POST['action']   = 'issue';  // not used, just documenting intent
            $_POST['agent_id'] = $ag['agent_id'];
            $_POST['notes']    = 'Bulk issued from run #' . $run_id;
            // Inline re-run of the issue logic without the redirect:
            $stmt = $db->prepare("
                SELECT r.date_from, r.date_to,
                       vr.sales_zwg, vr.sales_usd, vr.receipts_zwg, vr.receipts_usd,
                       vr.variance_zwg, vr.variance_usd, vr.variance_cat
                FROM reconciliation_runs r
                LEFT JOIN variance_results vr ON vr.run_id = r.id AND vr.agent_id = ?
                WHERE r.id = ?
            ");
            $aid = (int)$ag['agent_id'];
            $stmt->bind_param('ii', $aid, $run_id);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$data) continue;

            $statement_no = next_statement_no($db, $data['date_from']);
            $notes = 'Bulk issued from run #' . $run_id;

            $ins = $db->prepare("
                INSERT INTO statements
                  (statement_no, run_id, agent_id, period_from, period_to, status,
                   sales_zwg, sales_usd, receipts_zwg, receipts_usd,
                   variance_zwg, variance_usd, variance_cat, notes, generated_by)
                VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $s_zwg = (float)($data['sales_zwg'] ?? 0);
            $s_usd = (float)($data['sales_usd'] ?? 0);
            $r_zwg = (float)($data['receipts_zwg'] ?? 0);
            $r_usd = (float)($data['receipts_usd'] ?? 0);
            $v_zwg = (float)($data['variance_zwg'] ?? 0);
            $v_usd = (float)($data['variance_usd'] ?? 0);
            $cat   = $data['variance_cat'];
            $ins->bind_param('siissddddddssi',
                $statement_no, $run_id, $aid, $data['date_from'], $data['date_to'],
                $s_zwg, $s_usd, $r_zwg, $r_usd, $v_zwg, $v_usd, $cat, $notes, $uid);
            $ins->execute();
            $ins->close();
            $created++;
        }

        audit_log($uid, 'DATA_EDIT', "Bulk-issued $created statements from run $run_id");

        // Notify Managers when a Reconciler bulk-issues drafts
        if ($role !== 'Manager' && $created > 0) {
            enqueue_email_to_role($db, 'Manager',
                "$created draft statements ready for review (Run #$run_id)",
                "{$user['name']} bulk-issued $created draft reconciliation statements from run #$run_id.\n\n"
                . "Review and finalize them: " . BASE_URL . "/admin/statements.php?status=draft&run_id=$run_id",
                'generic', null
            );
        }

        redirect_back('success', "$created statements issued from run #$run_id.");

    default:
        redirect_back('error', 'Unknown action.');
}
