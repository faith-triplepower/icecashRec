<?php
// ============================================================
// process/delete_upload.php
// Delete uploaded file and associated records
// ============================================================
require_once '../core/auth.php';
require_login();
// Manager/Admin can delete any upload. Uploaders can delete only their own.
// Reconcilers can flag a file via process/flag_upload.php instead.
require_role(array('Manager','Admin','Uploader'));
csrf_verify();

$db = get_db();
$user = current_user();
$upload_id = (int)($_POST['upload_id'] ?? 0);

if (!$upload_id) {
    die('Error: Invalid upload ID');
}

// Get upload details
$upload = $db->query("SELECT * FROM upload_history WHERE id = $upload_id")->fetch_assoc();
if (!$upload) {
    die('Error: Upload not found');
}

// Uploaders can only delete files they uploaded themselves
if ($user['role'] === 'Uploader' && (int)$upload['uploaded_by'] !== (int)$user['id']) {
    die('Error: You can only delete your own uploads');
}

try {
    $db->begin_transaction();
    
    // Count associated records
    $sales_count = $db->query("SELECT COUNT(*) as cnt FROM sales WHERE upload_id = $upload_id")->fetch_assoc()['cnt'];
    $receipts_count = $db->query("SELECT COUNT(*) as cnt FROM receipts WHERE upload_id = $upload_id")->fetch_assoc()['cnt'];
    
    // Delete associated records
    $db->query("DELETE FROM sales WHERE upload_id = $upload_id");
    $db->query("DELETE FROM receipts WHERE upload_id = $upload_id");
    
    // Delete upload history record
    $db->query("DELETE FROM upload_history WHERE id = $upload_id");
    
    $db->commit();
    
    // Log action
    $uid = (int)$user['id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $detail = "Deleted upload: {$upload['filename']} ($sales_count sales, $receipts_count receipts removed)";
    
    $stmt = $db->prepare(
        "INSERT INTO audit_log (user_id, action_type, detail, ip_address, result, created_at) 
         VALUES (?, 'DELETE_UPLOAD', ?, ?, 'success', NOW())"
    );
    $stmt->bind_param('iss', $uid, $detail, $ip);
    $stmt->execute();
    $stmt->close();
    
    die('✓ File deleted successfully! ' . $sales_count . ' sales records and ' . $receipts_count . ' receipts records removed.');
    
} catch (Exception $e) {
    $db->rollback();
    die('✗ Error deleting file: ' . $e->getMessage());
}
