<?php
// ============================================================
// process/email_queue_runner.php
// Drains pending notification_queue rows by sending them via
// PHP's mail(). Can be called:
//   - From the admin/outbox.php "Run Now" button
//   - From a cron job / scheduled task
//   - From the CLI: php process/email_queue_runner.php
//
// If mail() fails (SMTP not configured), the row is marked
// failed and retried on the next run up to 3 attempts.
// ============================================================

require_once __DIR__ . '/../core/db.php';

$db = get_db();

// CLI vs web — allow both. For web, require Manager/Admin.
$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    require_once __DIR__ . '/../core/auth.php';
    require_role(['Manager','Admin']);
    header('Content-Type: application/json');
}

$max_attempts = 3;
$batch_size = 50;

$rows = $db->query("
    SELECT * FROM notification_queue
    WHERE status = 'pending' AND attempt_count < $max_attempts
    ORDER BY created_at ASC
    LIMIT $batch_size
")->fetch_all(MYSQLI_ASSOC);

$sent = 0; $failed = 0;

// Get org name for From header
$org_row = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='org_name'")->fetch_assoc();
$org_name = $org_row ? $org_row['setting_value'] : 'IceCashRec';
$from_addr = 'no-reply@' . preg_replace('/[^a-z0-9.-]/', '', strtolower(str_replace(' ', '', $org_name))) . '.local';

foreach ($rows as $row) {
    $headers  = "From: $org_name <$from_addr>\r\n";
    $headers .= "Reply-To: $from_addr\r\n";
    $headers .= "X-Mailer: IceCashRec/1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    // Attempt send — mail() returns false if SMTP isn't configured
    // or the system's sendmail path is wrong.
    $ok = @mail($row['recipient'], $row['subject'], $row['body'], $headers);

    $new_attempt = $row['attempt_count'] + 1;
    if ($ok) {
        $upd = $db->prepare("UPDATE notification_queue SET status='sent', sent_at=NOW(), attempt_count=?, error=NULL WHERE id=?");
        $upd->bind_param('ii', $new_attempt, $row['id']);
        $upd->execute();
        $upd->close();
        $sent++;
    } else {
        $error = error_get_last();
        $err_msg = $error && isset($error['message']) ? substr($error['message'], 0, 500) : 'mail() returned false — SMTP not configured';
        $new_status = $new_attempt >= $max_attempts ? 'failed' : 'pending';
        $upd = $db->prepare("UPDATE notification_queue SET status=?, attempt_count=?, error=? WHERE id=?");
        $upd->bind_param('sisi', $new_status, $new_attempt, $err_msg, $row['id']);
        $upd->execute();
        $upd->close();
        $failed++;
    }
}

$result = array(
    'batch_size'   => count($rows),
    'sent'         => $sent,
    'failed'       => $failed,
    'message'      => "Processed " . count($rows) . " messages: $sent sent, $failed failed.",
);

if ($is_cli) {
    echo $result['message'] . "\n";
} else {
    echo json_encode($result);
}
