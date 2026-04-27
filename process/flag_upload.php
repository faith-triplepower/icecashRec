<?php
// ============================================================
// process/flag_upload.php
// Reconciler/Manager flags an upload as having issues so the
// original uploader gets notified to fix it. Stores the reason
// and note on upload_history, audit-logs the action, and emails
// the uploader via the existing notifications queue.
// ============================================================
require_once '../core/auth.php';
require_once '../core/db.php';
require_once '../core/notifications.php';
require_login();
require_role(array('Reconciler','Manager','Admin'));
csrf_verify();

$db   = get_db();
$user = current_user();

$upload_id = (int)($_POST['upload_id'] ?? 0);
$reason    = trim($_POST['reason'] ?? '');
$note      = trim($_POST['note']   ?? '');

// ── Validate ──────────────────────────────────────────────
$allowed_reasons = array(
    'wrong_type','missing_columns','bad_dates','duplicate',
    'wrong_period','data_quality','incomplete','other'
);
if (!$upload_id || !in_array($reason, $allowed_reasons) || strlen($note) < 10) {
    header('Location: ../utilities/uploaded_files_list.php?error=' . urlencode('Invalid flag request — reason and note (≥10 chars) are required'));
    exit;
}

// ── Load the file + uploader ──────────────────────────────
$f_stmt = $db->prepare("
    SELECT uh.id, uh.filename, uh.file_type, uh.flag_status, uh.uploaded_by,
           u.full_name AS uploader_name, u.email AS uploader_email
    FROM upload_history uh
    JOIN users u ON uh.uploaded_by = u.id
    WHERE uh.id = ?
");
$f_stmt->bind_param('i', $upload_id);
$f_stmt->execute();
$file = $f_stmt->get_result()->fetch_assoc();
$f_stmt->close();

if (!$file) {
    header('Location: ../utilities/uploaded_files_list.php?error=' . urlencode('File not found'));
    exit;
}
if ($file['flag_status'] === 'flagged') {
    header('Location: ../utilities/uploaded_file_detail.php?id=' . $upload_id . '&error=' . urlencode('File is already flagged'));
    exit;
}

// ── Mark the upload as flagged ────────────────────────────
$uid = (int)$user['id'];
$upd = $db->prepare("
    UPDATE upload_history
    SET flag_status='flagged',
        flagged_by=?,
        flagged_at=NOW(),
        flag_reason=?,
        flag_note=?
    WHERE id=?
");
$upd->bind_param('issi', $uid, $reason, $note, $upload_id);
$upd->execute();
$upd->close();

// ── Audit log ─────────────────────────────────────────────
audit_log_entry($uid, 'FLAG_UPLOAD',
    "Flagged upload #{$upload_id} ({$file['filename']}) — {$reason}");

// ── Notify the uploader by email ──────────────────────────
$reason_labels = array(
    'wrong_type'       => 'Wrong file type',
    'missing_columns'  => 'Missing required columns',
    'bad_dates'        => 'Date column unreadable',
    'duplicate'        => 'Duplicate of a previous upload',
    'wrong_period'     => 'Wrong reporting period',
    'data_quality'     => 'Data quality issues',
    'incomplete'       => 'File incomplete or truncated',
    'other'            => 'Other',
);
$reason_label = $reason_labels[$reason] ?? $reason;

$subject = "Action needed: {$file['filename']} flagged for review";
$body    = "Hi {$file['uploader_name']},\n\n"
         . "{$user['name']} has flagged your upload \"{$file['filename']}\" "
         . "and asked for it to be re-checked.\n\n"
         . "Reason: {$reason_label}\n\n"
         . "Note from reviewer:\n"
         . "{$note}\n\n"
         . "Please open the file in IceCashRec, review the note, and re-upload "
         . "a corrected version when ready.\n\n"
         . "— IceCashRec";

enqueue_email($db, (int)$file['uploaded_by'], $subject, $body, 'upload', 'notif_upload_flagged');

// ── Redirect with success ────────────────────────────────
header('Location: ../utilities/uploaded_file_detail.php?id=' . $upload_id . '&success=' . urlencode('File flagged. The uploader has been notified.'));
exit;
