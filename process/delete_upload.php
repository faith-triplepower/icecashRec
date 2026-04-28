<?php
// ============================================================
// process/delete_upload.php
// Delete an uploaded file and its associated sales/receipts.
//
// IMPORTANT: This endpoint is called via fetch() from the
// uploaded files page. It MUST always return JSON, never HTML.
// That is why it does its own auth checks instead of using
// require_login() / require_role(), which redirect to login
// pages on failure (and the browser would render that HTML
// inside the alert() popup).
// ============================================================

require_once '../core/auth.php';

// All responses are JSON
header('Content-Type: application/json; charset=utf-8');

function jsend($ok, $message) {
    echo json_encode(array('ok' => (bool)$ok, 'message' => $message));
    exit;
}

// ── 1. Auth: must be logged in ────────────────────────────────
if (!is_logged_in()) {
    jsend(false, 'Your session has expired. Please refresh the page and log in again.');
}

$user = current_user();
if (!$user) {
    jsend(false, 'Your session has expired. Please refresh the page and log in again.');
}

// ── 2. Auth: role must be allowed to delete ──────────────────
$allowed_roles = array('Manager', 'Admin', 'Uploader');
if (!in_array($user['role'], $allowed_roles, true)) {
    jsend(false, 'You do not have permission to delete uploaded files.');
}

// ── 3. CSRF check (do it ourselves so we get JSON, not text) ──
$posted_token = isset($_POST['_csrf']) ? $_POST['_csrf'] : '';
if (!hash_equals(csrf_token(), $posted_token)) {
    jsend(false, 'Security token expired. Please refresh the page and try again.');
}

// ── 4. Validate input ────────────────────────────────────────
$db = get_db();
$upload_id = (int)(isset($_POST['upload_id']) ? $_POST['upload_id'] : 0);
if ($upload_id <= 0) {
    jsend(false, 'Invalid upload ID.');
}

// ── 5. Fetch the upload row (prepared statement) ─────────────
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

// ── 7. Block deletion if any rows are referenced by an active
//      statement, so finalised reconciliations cannot lose
//      their underlying data.
$used_stmt = $db->prepare("
    SELECT COUNT(*) AS c
    FROM statements st
    JOIN sales s ON s.agent_id = st.agent_id
    WHERE s.upload_id = ?
      AND st.status IN ('draft','final','reviewed')
");
$used_stmt->bind_param('i', $upload_id);
$used_stmt->execute();
$used_cnt = (int)$used_stmt->get_result()->fetch_assoc()['c'];
$used_stmt->close();
if ($used_cnt > 0) {
    jsend(false, "This upload is referenced by {$used_cnt} active statement(s). Cancel those statements first.");
}

// ── 8. Delete inside a transaction ───────────────────────────
try {
    $db->begin_transaction();

    // Count what we are about to remove (for the audit detail)
    $sales_cnt = (int)$db->query(
        "SELECT COUNT(*) c FROM sales WHERE upload_id = {$upload_id}"
    )->fetch_assoc()['c'];
    $rec_cnt = (int)$db->query(
        "SELECT COUNT(*) c FROM receipts WHERE upload_id = {$upload_id}"
    )->fetch_assoc()['c'];

    // Unlink any matched receipts that point at sales we are deleting,
    // so we never leave dangling matched_sale_id references behind.
    $db->query("
        UPDATE receipts
           SET matched_sale_id = NULL,
               matched_policy  = NULL,
               match_status    = 'pending',
               match_confidence = NULL
         WHERE matched_sale_id IN (
             SELECT id FROM (SELECT id FROM sales WHERE upload_id = {$upload_id}) AS s
         )
    ");

    $db->query("DELETE FROM sales          WHERE upload_id = {$upload_id}");
    $db->query("DELETE FROM receipts       WHERE upload_id = {$upload_id}");
    $db->query("DELETE FROM upload_history WHERE id        = {$upload_id}");

    // Audit log INSIDE the transaction. Use 'DATA_EDIT' because
    // it is already a valid value in the audit_log.action_type
    // ENUM. Do NOT use 'DELETE_UPLOAD' until the schema has been
    // migrated to allow that value.
    $detail = "Deleted upload: {$upload['filename']} ({$sales_cnt} sales, {$rec_cnt} receipts)";
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $uid = (int)$user['id'];
    $a = $db->prepare("
        INSERT INTO audit_log (user_id, action_type, detail, ip_address, result, created_at)
        VALUES (?, 'DATA_EDIT', ?, ?, 'success', NOW())
    ");
    $a->bind_param('iss', $uid, $detail, $ip);
    $a->execute();
    $a->close();

    $db->commit();

    jsend(true, "Deleted {$upload['filename']} — removed {$sales_cnt} sales and {$rec_cnt} receipts.");
} catch (Exception $e) {
    $db->rollback();
    jsend(false, 'Delete failed: ' . $e->getMessage());
}