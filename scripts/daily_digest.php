<?php
// ============================================================
// scripts/daily_digest.php — Daily notification digest
// Run via Task Scheduler or cron at 8:00 AM:
//   php c:\xampp\htdocs\icecashRec\scripts\daily_digest.php
//
// Collects yesterday's events and sends ONE summary email per
// Manager (not a flood of individual emails).
// ============================================================

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/notifications.php';

$db = get_db();

$yesterday = date('Y-m-d', strtotime('-1 day'));
$today     = date('Y-m-d');

// Gather yesterday's stats
$runs = (int)$db->query("SELECT COUNT(*) c FROM reconciliation_runs WHERE DATE(started_at) = '$yesterday'")->fetch_assoc()['c'];
$uploads = (int)$db->query("SELECT COUNT(*) c FROM upload_history WHERE DATE(created_at) = '$yesterday'")->fetch_assoc()['c'];
$records_imported = (int)$db->query("SELECT COALESCE(SUM(record_count),0) c FROM upload_history WHERE DATE(created_at) = '$yesterday'")->fetch_assoc()['c'];
$escalations_opened = (int)$db->query("SELECT COUNT(*) c FROM escalations WHERE DATE(created_at) = '$yesterday'")->fetch_assoc()['c'];
$escalations_resolved = (int)$db->query("SELECT COUNT(*) c FROM escalations WHERE status='resolved' AND DATE(reviewed_at) = '$yesterday'")->fetch_assoc()['c'];
$statements_issued = (int)$db->query("SELECT COUNT(*) c FROM statements WHERE DATE(generated_at) = '$yesterday'")->fetch_assoc()['c'];

// Variance threshold breaches
$settings = array();
foreach ($db->query("SELECT setting_key, setting_value FROM system_settings")->fetch_all(MYSQLI_ASSOC) as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}
$tol_zwg = (float)($settings['auto_escalate_threshold_zwg'] ?? 10000);
$high_variances = (int)$db->query("
    SELECT COUNT(*) c FROM variance_results vr
    JOIN reconciliation_runs r ON vr.run_id = r.id
    WHERE DATE(r.started_at) = '$yesterday' AND ABS(vr.variance_zwg) > $tol_zwg
")->fetch_assoc()['c'];

// Failed uploads
$failed_uploads = (int)$db->query("SELECT COUNT(*) c FROM upload_history WHERE DATE(created_at) = '$yesterday' AND upload_status IN ('failed','rejected')")->fetch_assoc()['c'];

// Skip if nothing happened
if ($runs + $uploads + $escalations_opened + $statements_issued === 0) {
    echo "No activity yesterday ($yesterday). Skipping digest.\n";
    exit;
}

// Build the digest body
$body = "Daily Reconciliation Digest — $yesterday\n"
      . str_repeat('=', 50) . "\n\n";

$body .= "UPLOADS\n";
$body .= "  Files uploaded:     $uploads\n";
$body .= "  Records imported:   " . number_format($records_imported) . "\n";
if ($failed_uploads > 0) {
    $body .= "  ** Failed uploads:  $failed_uploads **\n";
}
$body .= "\n";

$body .= "RECONCILIATION\n";
$body .= "  Runs completed:     $runs\n";
if ($high_variances > 0) {
    $body .= "  ** Variances over ZWG " . number_format($tol_zwg) . ": $high_variances agents **\n";
}
$body .= "\n";

$body .= "ESCALATIONS\n";
$body .= "  New:                $escalations_opened\n";
$body .= "  Resolved:           $escalations_resolved\n";
$body .= "\n";

$body .= "STATEMENTS\n";
$body .= "  Issued:             $statements_issued\n";
$body .= "\n";

$body .= "---\n";
$body .= "Review details: " . BASE_URL . "/modules/dashboard.php\n";

$subject = "IcecashRec Digest — $yesterday: $uploads uploads, $runs runs, $escalations_opened escalations";

// Send to all active Managers
enqueue_email_to_role($db, 'Manager', $subject, $body, 'generic', null);

echo "Digest enqueued for $yesterday.\n";
echo "  $uploads uploads, $runs runs, $escalations_opened escalations, $statements_issued statements\n";
