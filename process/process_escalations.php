<?php
// ============================================================
// process/process_escalations.php — Escalation Actions
// Manager-only queue: assign, edit, review, resolve, dismiss.
// Each action appends timestamped notes and sends email
// notifications to affected parties.
// Part of IceCashRec — Zimnat General Insurance
// ============================================================

require_once '../core/auth.php';
require_once '../core/notifications.php';
require_role(['Manager']);
csrf_verify();

$db     = get_db();
$user   = current_user();
$uid    = (int)$user['id'];
$action = $_POST['action'] ?? '';

function redirect_back($type, $msg) {
    header("Location: " . BASE_URL . "/admin/escalations.php?" . $type . "=" . urlencode($msg));
    exit;
}

// Append a dated line to the review_note field so history is preserved
function append_note($db, $id, $prefix, $text, $user_name) {
    $stamp = date('Y-m-d H:i');
    $entry = "[$stamp · $user_name · $prefix] " . $text;
    $upd = $db->prepare("
        UPDATE escalations
        SET review_note = CONCAT(COALESCE(review_note,''), CASE WHEN review_note IS NOT NULL AND review_note<>'' THEN '\n' ELSE '' END, ?)
        WHERE id = ?
    ");
    $upd->bind_param('si', $entry, $id);
    $upd->execute();
    $upd->close();
}

// Verify the escalation exists and return its row
function load_escalation($db, $id) {
    $stmt = $db->prepare("SELECT * FROM escalations WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

switch ($action) {

    // ── ASSIGN to a different user ──────────────────────────
    case 'assign':
        $id          = (int)($_POST['escalation_id'] ?? 0);
        $assigned_to = (int)($_POST['assigned_to']   ?? 0);
        $note        = trim($_POST['assign_note']    ?? '');
        if (!$id || !$assigned_to) redirect_back('error', 'Escalation and assignee required.');

        // Verify assignee is a Manager or Admin
        $u = $db->prepare("SELECT id, full_name, role FROM users WHERE id=? AND is_active=1 AND role='Manager'");
        $u->bind_param('i', $assigned_to);
        $u->execute();
        $assignee = $u->get_result()->fetch_assoc();
        $u->close();
        if (!$assignee) redirect_back('error', 'Assignee must be an active Manager.');

        $esc = load_escalation($db, $id);
        if (!$esc) redirect_back('error', 'Escalation not found.');

        $upd = $db->prepare("UPDATE escalations SET assigned_to=? WHERE id=?");
        $upd->bind_param('ii', $assigned_to, $id);
        $upd->execute();
        $upd->close();

        $reason = 'Assigned to ' . $assignee['full_name'] . ($note ? ': ' . $note : '');
        append_note($db, $id, 'ASSIGN', $reason, $user['name']);

        // Notify the new assignee
        $subject = "Escalation #$id assigned to you — priority " . strtoupper($esc['priority']);
        $body    = "You have been assigned escalation #$id by {$user['name']}.\n\n"
                 . "Priority: " . strtoupper($esc['priority']) . "\n"
                 . "Type: " . $esc['action_type'] . "\n"
                 . "Detail: " . $esc['action_detail'] . "\n"
                 . ($note ? "\nHandover note: $note\n" : "")
                 . "\nReview it here: " . BASE_URL . "/admin/escalations.php";
        enqueue_email($db, $assigned_to, $subject, $body, 'escalation', 'notif_escalation_assigned');

        audit_log($uid, 'DATA_EDIT', "Assigned escalation #$id to {$assignee['full_name']}");
        redirect_back('success', "Escalation #$id assigned to {$assignee['full_name']}.");

    // ── EDIT priority / detail / action_type ────────────────
    case 'edit':
        $id       = (int)($_POST['escalation_id'] ?? 0);
        $priority = $_POST['priority'] ?? '';
        $type     = $_POST['action_type'] ?? '';
        $detail   = trim($_POST['action_detail'] ?? '');
        if (!$id) redirect_back('error', 'Invalid escalation.');
        if (!in_array($priority, array('low','medium','high','critical'))) {
            redirect_back('error', 'Invalid priority.');
        }
        if (!in_array($type, array('variance','unmatched','currency_mismatch','manual'))) {
            redirect_back('error', 'Invalid action type.');
        }
        if (strlen($detail) < 5) redirect_back('error', 'Detail must be at least 5 characters.');

        $esc = load_escalation($db, $id);
        if (!$esc) redirect_back('error', 'Escalation not found.');

        $detail = substr($detail, 0, 500);
        $upd = $db->prepare("UPDATE escalations SET priority=?, action_type=?, action_detail=? WHERE id=?");
        $upd->bind_param('sssi', $priority, $type, $detail, $id);
        $upd->execute();
        $upd->close();

        $changes = array();
        if ($esc['priority']      !== $priority) $changes[] = "priority {$esc['priority']}→$priority";
        if ($esc['action_type']   !== $type)     $changes[] = "type {$esc['action_type']}→$type";
        if ($esc['action_detail'] !== $detail)   $changes[] = "detail updated";
        if (!empty($changes)) {
            append_note($db, $id, 'EDIT', implode(', ', $changes), $user['name']);
        }

        audit_log($uid, 'DATA_EDIT', "Edited escalation #$id: " . implode(', ', $changes));
        redirect_back('success', "Escalation #$id updated.");

    // ── REVIEW (add note, mark as reviewed) ─────────────────
    case 'review':
        $id   = (int)($_POST['escalation_id'] ?? 0);
        $note = trim($_POST['review_note']    ?? '');
        if (!$id) redirect_back('error', 'Invalid escalation.');
        if (strlen($note) < 5) redirect_back('error', 'Review note required (min 5 characters).');

        append_note($db, $id, 'REVIEW', $note, $user['name']);
        $upd = $db->prepare("UPDATE escalations SET status='reviewed', reviewed_by=?, reviewed_at=NOW() WHERE id=?");
        $upd->bind_param('ii', $uid, $id);
        $upd->execute();
        $upd->close();

        audit_log($uid, 'DATA_EDIT', "Reviewed escalation #$id");
        redirect_back('success', "Escalation #$id marked as reviewed.");

    // ── RESOLVE ─────────────────────────────────────────────
    case 'resolve':
        $id   = (int)($_POST['escalation_id'] ?? 0);
        $note = trim($_POST['resolution_note'] ?? '');
        if (!$id) redirect_back('error', 'Invalid escalation.');
        if (strlen($note) < 5) redirect_back('error', 'Resolution note required (min 5 characters).');

        $esc = load_escalation($db, $id);
        append_note($db, $id, 'RESOLVE', $note, $user['name']);
        $upd = $db->prepare("UPDATE escalations SET status='resolved', reviewed_by=?, reviewed_at=NOW() WHERE id=?");
        $upd->bind_param('ii', $uid, $id);
        $upd->execute();
        $upd->close();

        // Notify the original submitter
        if ($esc && $esc['user_id'] != $uid) {
            $subject = "Your escalation #$id has been resolved";
            $body    = "Your escalation has been resolved by {$user['name']}.\n\n"
                     . "Original: " . $esc['action_detail'] . "\n\n"
                     . "Resolution: $note\n\n"
                     . "View: " . BASE_URL . "/admin/escalations.php?filter=resolved";
            enqueue_email($db, $esc['user_id'], $subject, $body, 'escalation', null);
        }

        audit_log($uid, 'DATA_EDIT', "Resolved escalation #$id");
        redirect_back('success', "Escalation #$id resolved.");

    // ── DISMISS ─────────────────────────────────────────────
    case 'dismiss':
        $id     = (int)($_POST['escalation_id'] ?? 0);
        $reason = trim($_POST['dismiss_reason'] ?? '');
        if (!$id) redirect_back('error', 'Invalid escalation.');
        if (strlen($reason) < 5) redirect_back('error', 'Dismissal reason required (min 5 characters).');

        $esc = load_escalation($db, $id);
        append_note($db, $id, 'DISMISS', $reason, $user['name']);
        $upd = $db->prepare("UPDATE escalations SET status='dismissed', reviewed_by=?, reviewed_at=NOW() WHERE id=?");
        $upd->bind_param('ii', $uid, $id);
        $upd->execute();
        $upd->close();

        // Notify the submitter
        if ($esc && $esc['user_id'] != $uid) {
            $subject = "Your escalation #$id has been dismissed";
            $body    = "Your escalation has been dismissed by {$user['name']}.\n\n"
                     . "Original: " . $esc['action_detail'] . "\n\n"
                     . "Reason: $reason\n\n"
                     . "View: " . BASE_URL . "/admin/escalations.php?filter=dismissed";
            enqueue_email($db, $esc['user_id'], $subject, $body, 'escalation', null);
        }

        audit_log($uid, 'DATA_EDIT', "Dismissed escalation #$id");
        redirect_back('success', "Escalation #$id dismissed.");

    default:
        redirect_back('error', 'Unknown action.');
}
