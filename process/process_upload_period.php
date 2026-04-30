<?php
// ============================================================
// process/process_upload_period.php
// Updates period_from / period_to on an existing upload record.
// Called via AJAX from uploaded_file_detail.php.
// ============================================================
require_once '../core/auth.php';
require_role(['Manager', 'Uploader', 'Admin']);
csrf_verify();

header('Content-Type: application/json');

$db        = get_db();
$user      = current_user();
$uid       = (int)$user['id'];
$upload_id = (int)($_POST['upload_id'] ?? 0);

if (!$upload_id) {
    echo json_encode(['ok' => false, 'error' => 'Invalid upload ID.']);
    exit;
}

// Load the record — verify it exists and the user is allowed to edit it
$stmt = $db->prepare("SELECT id, uploaded_by FROM upload_history WHERE id = ?");
$stmt->bind_param('i', $upload_id);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    echo json_encode(['ok' => false, 'error' => 'Upload not found.']);
    exit;
}

// Uploaders can only edit their own uploads; Manager/Admin can edit any
if ($user['role'] === 'Uploader' && (int)$record['uploaded_by'] !== $uid) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied.']);
    exit;
}

$period_from = trim($_POST['period_from'] ?? '');
$period_to   = trim($_POST['period_to']   ?? '');

// Store NULL when the field is left blank so the column stays clean
$from_val = $period_from !== '' ? $period_from : null;
$to_val   = $period_to   !== '' ? $period_to   : null;

$upd = $db->prepare("UPDATE upload_history SET period_from=?, period_to=? WHERE id=?");
$upd->bind_param('ssi', $from_val, $to_val, $upload_id);
$upd->execute();
$upd->close();

audit_log($uid, 'DATA_EDIT', "Updated period on upload #$upload_id: $period_from → $period_to");

echo json_encode(['ok' => true]);
