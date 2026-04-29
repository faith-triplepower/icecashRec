<?php
// ============================================================
// process/delete_upload.php
// Delete an uploaded file and its associated sales/receipts.
//
// Two modes:
//   mode=preview  → returns JSON describing what *would* be deleted
//                   (sales count, receipts count, list of impacted
//                   statements). UI uses this to show a warning.
//   mode=force    → actually deletes. Cancels any blocking statements
//                   first (with audit trail), then removes the data.
//   (no mode)     → legacy behaviour: refuse if any active statement
//                   exists. Kept for any callers we haven't updated.
//
// Always returns JSON.
// ============================================================

require_once '../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

function jsend($ok, $message, $extra = array()) {
    echo json_encode(array_merge(
        array('ok' => (bool)$ok, 'message' => $message),
        $extra
    ));
    exit;
}

// ── 1. Auth ──────────────────────────────────────────────────
if (!is_logged_in()) {
    jsend(false, 'Your session has expired. Please refresh the page and log in again.');
}
$user = current_user();
if (!$user) {
    jsend(false, 'Your session has expired. Please refresh the page and log in again.');
}

// ── 2. Role check ────────────────────────────────────────────
$allowed_roles = array('Manager', 'Admin', 'Uploader');
if (!in_array($user['role'], $allowed_roles, true)) {
    jsend(false, 'You do not have permission to delete uploaded files.');
}

// ── 3. CSRF ──────────────────────────────────────────────────
$posted_token = isset($_POST['_csrf']) ? $_POST['_csrf'] : '';
if (!hash_equals(csrf_token(), $posted_token)) {
    jsend(false, 'Security token expired. Please refresh the page and try again.');
}

// ── 4. Validate input ────────────────────────────────────────
$db        = get_db();
$upload_id = (int)($_POST['upload_id'] ?? 0);
$mode      = $_POST['mode'] ?? '';   // 'preview' | 'force' | ''

if ($upload_id <= 0) {
    jsend(false, 'Invalid upload ID.');
}

// ── 5. Fetch the upload row ──────────────────────────────────
$stmt = $db->prepare('SELECT * FROM upload_history WHERE id = ?');
$stmt->bind_param('i', $upload_id);
$stmt->execute();
$upload = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$upload) {
    jsend(false, 'Upload not found. It may have already been deleted.');
}

// ── 6. Uploaders can only delete files they uploaded ─────────
if ($user['role'] === 'Uploader' && (int)$upload['uploaded_by'] !== (int)$user['id']) {
    jsend(false, 'You can only delete files you uploaded yourself.');
}

// ── 7. Force-delete is restricted to Manager/Admin ───────────
// An Uploader can preview but cannot cascade-cancel statements.
// Statements are financial documents — only Manager/Admin can wipe them.
if ($mode === 'force' && !in_array($user['role'], array('Manager','Admin'), true)) {
    jsend(false, 'Only Managers or Admins can force-delete an upload that has active statements. Ask a Manager to do this for you.');
}

// ── 8. Helper: fetch row counts + impacted statements ────────
function gather_impact($db, $upload_id) {
    $sales_cnt = (int)$db->query(
        "SELECT COUNT(*) c FROM sales WHERE upload_id = $upload_id"
    )->fetch_assoc()['c'];
    $rec_cnt = (int)$db->query(
        "SELECT COUNT(*) c FROM receipts WHERE upload_id = $upload_id"
    )->fetch_assoc()['c'];

    // A statement is impacted if any of its underlying sales OR
    // matched receipts come from this upload. We use UNION on the
    // distinct statement_id so each statement is counted once.
    $impact_sql = "
        SELECT DISTINCT st.id, st.statement_no, st.status,
                        a.agent_name,
                        st.period_from, st.period_to,
                        st.variance_zwg, st.variance_usd,
                        st.generated_by
        FROM statements st
        JOIN agents a ON a.id = st.agent_id
        WHERE st.status IN ('draft','final','reviewed')
          AND (
                st.id IN (
                    SELECT st2.id FROM statements st2
                    JOIN sales s
                      ON s.agent_id = st2.agent_id
                     AND s.txn_date BETWEEN st2.period_from AND st2.period_to
                    WHERE s.upload_id = $upload_id
                )
             OR st.id IN (
                    SELECT st3.id FROM statements st3
                    JOIN receipts r ON r.upload_id = $upload_id
                    JOIN sales s2 ON s2.id = r.matched_sale_id
                    WHERE s2.agent_id = st3.agent_id
                      AND r.txn_date BETWEEN st3.period_from AND st3.period_to
                )
          )
        ORDER BY st.statement_no
    ";
    $impacted = $db->query($impact_sql)->fetch_all(MYSQLI_ASSOC);

    return array(
        'sales_count'    => $sales_cnt,
        'receipts_count' => $rec_cnt,
        'statements'     => $impacted,
    );
}

// ── 9. PREVIEW mode ──────────────────────────────────────────
if ($mode === 'preview') {
    $impact = gather_impact($db, $upload_id);

    $can_force = in_array($user['role'], array('Manager','Admin'), true);
    $msg_parts = array();
    $msg_parts[] = $impact['sales_count']    . ' sale(s)';
    $msg_parts[] = $impact['receipts_count'] . ' receipt(s)';
    if (!empty($impact['statements'])) {
        $msg_parts[] = count($impact['statements']) . ' statement(s) will be cancelled';
    }

    jsend(true, 'Preview ready', array(
        'preview'        => true,
        'filename'       => $upload['filename'],
        'sales_count'    => $impact['sales_count'],
        'receipts_count' => $impact['receipts_count'],
        'statements'     => $impact['statements'],
        'has_blockers'   => !empty($impact['statements']),
        'can_force'      => $can_force,
        'summary'        => 'You are about to delete: ' . implode(', ', $msg_parts) . '.',
    ));
}

// ── 10. Determine path: clean delete vs force ────────────────
$impact      = gather_impact($db, $upload_id);
$has_blockers = !empty($impact['statements']);

if ($has_blockers && $mode !== 'force') {
    // Legacy / no-mode caller — keep the old behaviour: refuse and
    // tell them what's blocking. The new UI never lands here because
    // it always uses preview → force.
    $lines = array();
    foreach ($impact['statements'] as $b) {
        $lines[] = $b['statement_no'] . ' (' . strtoupper($b['status']) . ', ' . $b['agent_name'] . ')';
    }
    jsend(false,
        'This upload feeds ' . count($impact['statements']) . ' active statement(s). ' .
        'Re-submit with mode=force to cancel them and proceed. Statements: ' .
        implode(', ', $lines)
    );
}

// ── 11. Perform the delete in a transaction ──────────────────
try {
    $db->begin_transaction();

    $sales_cnt     = $impact['sales_count'];
    $rec_cnt       = $impact['receipts_count'];
    $cancelled_no  = array();   // for the audit detail
    $reason        = trim($_POST['reason'] ?? '');

    // 11a. If force, cancel every blocking statement first.
    if ($mode === 'force' && $has_blockers) {
        if (strlen($reason) < 5) {
            $db->rollback();
            jsend(false, 'A reason (min 5 characters) is required when cancelling statements.');
        }

        $cancel_note = 'Auto-cancelled by ' . $user['name'] .
                       ' on delete of upload "' . $upload['filename'] . '" (#' . $upload_id . '). ' .
                       'Reason: ' . $reason;

        $upd = $db->prepare("
            UPDATE statements
               SET status = 'cancelled',
                   notes  = CONCAT(COALESCE(notes,''), '\nCANCELLED: ', ?)
             WHERE id = ?
               AND status IN ('draft','final','reviewed')
        ");
        foreach ($impact['statements'] as $s) {
            $sid = (int)$s['id'];
            $upd->bind_param('si', $cancel_note, $sid);
            $upd->execute();
            $cancelled_no[] = $s['statement_no'];

            // One audit row per statement, so each cancellation
            // is independently traceable in the audit log.
            audit_log(
                (int)$user['id'],
                'DATA_EDIT',
                "Cancelled statement #{$s['statement_no']} (cascade from upload delete #$upload_id): $reason"
            );
        }
        $upd->close();
    }

    // 11b. Unlink any matched receipts that point at sales we're
    //      deleting, so we never leave dangling matched_sale_id refs.
    $db->query("
        UPDATE receipts
           SET matched_sale_id  = NULL,
               matched_policy   = NULL,
               match_status     = 'pending',
               match_confidence = NULL
         WHERE matched_sale_id IN (
             SELECT id FROM (SELECT id FROM sales WHERE upload_id = $upload_id) AS s
         )
    ");

    // 11c. Delete the data
    $db->query("DELETE FROM sales          WHERE upload_id = $upload_id");
    $db->query("DELETE FROM receipts       WHERE upload_id = $upload_id");
    $db->query("DELETE FROM upload_history WHERE id        = $upload_id");

    // 11d. Audit the delete itself
    $detail = "Deleted upload: {$upload['filename']} ({$sales_cnt} sales, {$rec_cnt} receipts)";
    if (!empty($cancelled_no)) {
        $detail .= '. Cascade-cancelled statements: ' . implode(', ', $cancelled_no);
    }
    audit_log((int)$user['id'], 'DATA_EDIT', $detail);

    $db->commit();

    // Build a friendly success message
    $msg = "Deleted {$upload['filename']} — removed {$sales_cnt} sales and {$rec_cnt} receipts";
    if (!empty($cancelled_no)) {
        $msg .= ' and cancelled ' . count($cancelled_no) . ' statement(s)';
    }
    $msg .= '.';

    jsend(true, $msg, array(
        'cancelled_statements' => $cancelled_no,
    ));

} catch (Exception $e) {
    $db->rollback();
    jsend(false, 'Delete failed: ' . $e->getMessage());
}