<?php
// ============================================================
// core/notifications.php — Email Notification Queue
// Enqueues emails to notification_queue table for async delivery.
// Respects per-user notification preferences (opt-out by category).
// Drained by process/email_queue_runner.php via PHP mail().
// Part of IceCashRec — Zimnat General Insurance
// ============================================================

/**
 * Enqueue a notification for a specific user.
 *
 * Respects user_preferences: if the user has turned off the
 * relevant notification category, the row is written with
 * status='skipped' so there's still an audit trail.
 *
 * @param mysqli $db
 * @param int    $user_id     Target user. Email address is resolved
 *                            from users.email (or user_preferences.notif_email if set).
 * @param string $subject
 * @param string $body        Plain text or simple HTML
 * @param string $category    One of: variance, unmatched, escalation,
 *                            upload, recon, welcome, generic
 * @param string $pref_key    Optional user_preferences key to check.
 *                            If set and the user has it turned off,
 *                            the email is enqueued as 'skipped'.
 */
// Queue up an email for one specific user.
// The email doesn't send immediately — it sits in notification_queue
// until the email runner drains it (manually or via cron).
// If the user has opted out of this notification category, we still
// write the row but mark it 'skipped' so there's an audit trail.
function enqueue_email($db, $user_id, $subject, $body, $category, $pref_key = null) {
    $user_id = (int)$user_id;

    // Can't send to nobody
    if ($user_id <= 0) return false;

    // Figure out where to send it — users can set a custom notification
    // email in their preferences, otherwise we use their account email
    $u_stmt = $db->prepare("
        SELECT u.email, u.full_name,
               (SELECT pref_val FROM user_preferences WHERE user_id=u.id AND pref_key='notif_email' LIMIT 1) AS notif_email
        FROM users u WHERE u.id = ? AND u.is_active=1
    ");
    $u_stmt->bind_param('i', $user_id);
    $u_stmt->execute();
    $u = $u_stmt->get_result()->fetch_assoc();
    $u_stmt->close();

    // User doesn't exist or is disabled? Don't send anything
    if (!$u) return false;

    // Prefer the custom notification email, fall back to account email
    $recipient = !empty($u['notif_email']) ? $u['notif_email'] : $u['email'];
    if (!$recipient) return false;

    // Has the user turned off this type of notification?
    $status = 'pending';
    if ($pref_key) {
        $p_stmt = $db->prepare("SELECT pref_val FROM user_preferences WHERE user_id=? AND pref_key=?");
        $p_stmt->bind_param('is', $user_id, $pref_key);
        $p_stmt->execute();
        $p = $p_stmt->get_result()->fetch_assoc();
        $p_stmt->close();

        // '0' means they explicitly turned it off — we still record it, just don't send
        if ($p && $p['pref_val'] === '0') {
            $status = 'skipped';
        }
    }

    // Keep the subject line reasonable
    $subject = substr($subject, 0, 200);

    // Add a friendly "Hi [name]" if the body doesn't already have a greeting
    if (strpos($body, 'Hi ') !== 0 && strpos($body, 'Hello') !== 0) {
        $body = 'Hi ' . $u['full_name'] . ",\n\n" . $body;
    }

    // Drop it in the queue — the email runner will pick it up later
    $ins = $db->prepare("
        INSERT INTO notification_queue (user_id, recipient, subject, body, category, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ins->bind_param('isssss', $user_id, $recipient, $subject, $body, $category, $status);
    $ok = $ins->execute();
    $ins->close();
    return $ok;
}

// Send the same email to every active user with a given role.
// Useful for "notify all Managers" scenarios like new escalations.
function enqueue_email_to_role($db, $role, $subject, $body, $category, $pref_key = null) {
    // Find all active users with this role
    $stmt = $db->prepare("SELECT id FROM users WHERE role=? AND is_active=1");
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Queue an email for each one (each gets their own preference check)
    foreach ($rows as $r) {
        enqueue_email($db, $r['id'], $subject, $body, $category, $pref_key);
    }
}
