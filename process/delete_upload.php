<?php
// ============================================================
// process/delete_upload.php
//
// Delete an uploaded file and its associated rows.
//
// Permissions:
//   - Uploader can delete ONLY their own uploads
//   - Manager/Admin can delete anyone's upload
//
// Safety guarantees:
//   - Refuses to delete an upload referenced by a draft / final /
//     reviewed statement — the statement must be cancelled first.
//   - Unlinks any matched receipts pointing at sales we're about to
//     delete (so we don't orphan receipts.matched_sale_id values).
//   - Audit log INSERT runs INSIDE the transaction; if the audit row
//     fails to write, the entire delete is rolled back. Destruction
//     without a record is a worse outcome than a failed delete.
//   - Always redirects (never die()), so refreshing the result page
//     does not replay the action.
// ============================================================

require_once '../core/auth.php';
require_role(array('Manager','Admin','Uploader'));
csrf_verify();

$db        = get_db();
$user      = current_user();
$uid       = (int)$user['id'];
$upload_id = (int)($_POST['upload_id'] ?? 0);

function back($type, $msg) {
    header('Location: ' . BASE_URL . '/utilities/uploaded_files_list.php?'
        . $type . '=' . urlencode($msg));
    exit;
}

if (!$upload_id) back('error', 'Invalid upload ID');

// Load the upload row
$stmt = $db->prepare('SELECT * FROM upload_history WHERE id = ?');
$stmt->bind_param('i', $upload_id);
$stmt->execute();
$upload = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$upload) back('error', 'Upload not found');

// Uploader role: own files only.
if ($user['role'] === 'Uploader' && (int)$upload['uploaded_by'] !== $uid) {
    back('error', 'You can only delete your own uploads');
}

// Block deletion if any sales row from this upload feeds an active
// statement. Cancelled statements are fine (their numbers no longer
// matter); draft / final / reviewed are not.
$used_stmt = $db->prepare("
    SELECT COUNT(*) c
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
    back('error', "This upload feeds {$used_cnt} active statement(s). Cancel them before deleting.");
}

try {
    $db->begin_transaction();

    // Count first so the audit row records exact impact
    $cnt_s = $db->prepare('SELECT COUNT(*) c FROM sales WHERE upload_id = ?');
    $cnt_s->bind_param('i', $upload_id);
    $cnt_s->execute();
    $sales_cnt = (int)$cnt_s->get_result()->fetch_assoc()['c'];
    $cnt_s->close();

    $cnt_r = $db->prepare('SELECT COUNT(*) c FROM receipts WHERE upload_id = ?');
    $cnt_r->bind_param('i', $upload_id);
    $cnt_r->execute();
    $rec_cnt = (int)$cnt_r->get_result()->fetch_assoc()['c'];
    $cnt_r->close();

    // Detach matched receipts that point at sales we're about to drop
    // — otherwise they'd be left with a dangling matched_sale_id.
    $u1 = $db->prepare("
        UPDATE receipts
           SET matched_sale_id = NULL,
               matched_policy  = NULL,
               match_status    = 'pending',
               match_confidence = NULL
         WHERE matched_sale_id IN (SELECT id FROM sales WHERE upload_id = ?)
    ");
    $u1->bind_param('i', $upload_id);
    $u1->execute();
    $u1->close();

    $d1 = $db->prepare('DELETE FROM sales WHERE upload_id = ?');
    $d1->bind_param('i', $upload_id);
    $d1->execute();
    $d1->close();

    $d2 = $db->prepare('DELETE FROM receipts WHERE upload_id = ?');
    $d2->bind_param('i', $upload_id);
    $d2->execute();
    $d2->close();

    $d3 = $db->prepare('DELETE FROM upload_history WHERE id = ?');
    $d3->bind_param('i', $upload_id);
    $d3->execute();
    $d3->close();

    // Audit row INSIDE the transaction. If this fails, the whole
    // deletion rolls back — we'd rather refuse the operation than
    // destroy data without a record of who did it.
    audit_log_entry($uid, 'DELETE_UPLOAD',
        "Deleted upload: {$upload['filename']} ({$sales_cnt} sales, {$rec_cnt} receipts)");

    $db->commit();
    back('success', "Deleted {$upload['filename']} — {$sales_cnt} sales and {$rec_cnt} receipts removed.");

} catch (Exception $e) {
    $db->rollback();
    error_log('delete_upload failed: ' . $e->getMessage());
    back('error', 'Delete failed: ' . $e->getMessage());
}
