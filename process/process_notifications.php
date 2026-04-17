<?php
// ============================================================
// process/process_notifications.php
// Live notification feed for the topbar bell. Computes the user's
// current notifications from existing tables (escalations,
// upload_history, reconciliation_runs, statements) based on role
// and the user's last-read timestamp. Nothing is persisted as
// individual notification rows — we compute on demand.
//
// Actions (JSON):
//   list       — GET: { unread_count, items: [...] }
//   mark_read  — POST: bumps user_preferences.notif_last_read to now
// ============================================================

require_once '../core/auth.php';
require_login();
header('Content-Type: application/json');

$db   = get_db();
$user = current_user();
$uid  = (int)$user['id'];
$role = $user['role'];
$action = $_REQUEST['action'] ?? 'list';

function out($data) { echo json_encode($data); exit; }

function get_last_read($db, $uid) {
    $stmt = $db->prepare("SELECT pref_val FROM user_preferences WHERE user_id=? AND pref_key='notif_last_read'");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['pref_val'] : '1970-01-01 00:00:00';
}

function set_last_read($db, $uid) {
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("
        INSERT INTO user_preferences (user_id, pref_key, pref_val)
        VALUES (?, 'notif_last_read', ?)
        ON DUPLICATE KEY UPDATE pref_val = VALUES(pref_val)
    ");
    $stmt->bind_param('is', $uid, $now);
    $stmt->execute();
    $stmt->close();
}

// ── MARK READ ───────────────────────────────────────────────
if ($action === 'mark_read') {
    set_last_read($db, $uid);
    out(array('success' => true));
}

// ── MARK ONE READ (per-item read tracking) ───────────────────
if ($action === 'mark_one_read') {
    $key = isset($_POST['key']) ? substr($_POST['key'], 0, 80) : '';
    if (!$key) out(array('error' => 'key required'), 400);

    $g = $db->prepare("SELECT pref_val FROM user_preferences WHERE user_id=? AND pref_key='notif_read'");
    $g->bind_param('i', $uid);
    $g->execute();
    $row = $g->get_result()->fetch_assoc();
    $g->close();

    $read_list = $row ? (json_decode($row['pref_val'], true) ?: array()) : array();
    if (!in_array($key, $read_list)) $read_list[] = $key;
    if (count($read_list) > 200) $read_list = array_slice($read_list, -200);
    $json = json_encode($read_list);

    $s = $db->prepare("
        INSERT INTO user_preferences (user_id, pref_key, pref_val)
        VALUES (?, 'notif_read', ?)
        ON DUPLICATE KEY UPDATE pref_val = VALUES(pref_val)
    ");
    $s->bind_param('is', $uid, $json);
    $s->execute();
    $s->close();

    out(array('success' => true));
}

// ── DISMISS one item (store a key in user_preferences) ─────
// Format of dismissed set: JSON array of "type:id" strings
// stored under pref_key='notif_dismissed'. Kept small by capping at 100.
if ($action === 'dismiss') {
    $key = isset($_POST['key']) ? substr($_POST['key'], 0, 80) : '';
    if (!$key) out(array('error' => 'key required'), 400);

    $g = $db->prepare("SELECT pref_val FROM user_preferences WHERE user_id=? AND pref_key='notif_dismissed'");
    $g->bind_param('i', $uid);
    $g->execute();
    $row = $g->get_result()->fetch_assoc();
    $g->close();

    $list = $row ? (json_decode($row['pref_val'], true) ?: array()) : array();
    if (!in_array($key, $list)) $list[] = $key;
    if (count($list) > 100) $list = array_slice($list, -100);
    $json = json_encode($list);

    $s = $db->prepare("
        INSERT INTO user_preferences (user_id, pref_key, pref_val)
        VALUES (?, 'notif_dismissed', ?)
        ON DUPLICATE KEY UPDATE pref_val = VALUES(pref_val)
    ");
    $s->bind_param('is', $uid, $json);
    $s->execute();
    $s->close();

    out(array('success' => true));
}

// Load dismissed set for filtering in the list action
function get_dismissed($db, $uid) {
    $g = $db->prepare("SELECT pref_val FROM user_preferences WHERE user_id=? AND pref_key='notif_dismissed'");
    $g->bind_param('i', $uid);
    $g->execute();
    $row = $g->get_result()->fetch_assoc();
    $g->close();
    return $row ? (json_decode($row['pref_val'], true) ?: array()) : array();
}

$dismissed_set = get_dismissed($db, $uid);

// Load per-item read set
function get_read_set($db, $uid) {
    $g = $db->prepare("SELECT pref_val FROM user_preferences WHERE user_id=? AND pref_key='notif_read'");
    $g->bind_param('i', $uid);
    $g->execute();
    $row = $g->get_result()->fetch_assoc();
    $g->close();
    return $row ? (json_decode($row['pref_val'], true) ?: array()) : array();
}
$read_set = get_read_set($db, $uid);

// ── LIST ────────────────────────────────────────────────────
$last_read = get_last_read($db, $uid);
$items = array();

// Manager only: pending escalations assigned to me or unassigned.
// Admins are intentionally excluded — escalations are not their workflow.
if ($role === 'Manager') {
    $stmt = $db->prepare("
        SELECT e.id, e.priority, e.action_detail, e.created_at, e.assigned_to,
               a.agent_name
        FROM escalations e
        LEFT JOIN agents a ON e.agent_id = a.id
        WHERE e.status = 'pending'
          AND (e.assigned_to = ? OR e.assigned_to IS NULL)
        ORDER BY FIELD(e.priority,'critical','high','medium','low'), e.created_at DESC
        LIMIT 10
    ");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $e) {
        $icon = $e['priority'] === 'critical' || $e['priority'] === 'high' ? '⚠' : '⚑';
        $prefix = $e['assigned_to'] ? 'Assigned to you' : 'Unassigned';
        $title = "$icon $prefix — " . strtoupper($e['priority']) . ' escalation';
        $desc  = $e['agent_name'] ? "Agent: " . $e['agent_name'] : '';
        $desc .= $desc ? ' — ' : '';
        $desc .= substr($e['action_detail'], 0, 100);
        $items[] = array(
            'key'       => 'escalation:' . $e['id'],
            'type'      => 'escalation',
            'title'     => $title,
            'desc'      => $desc,
            'link'      => '/icecashRec/admin/escalations.php',
            'created_at'=> $e['created_at'],
            'unread'    => $e['created_at'] > $last_read,
        );
    }
    $stmt->close();

    // Failed reconciliation runs in last 48 hours
    $stmt = $db->prepare("
        SELECT id, period_label, progress_msg, started_at
        FROM reconciliation_runs
        WHERE run_status = 'failed' AND started_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
        ORDER BY started_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = array(
            'key'       => 'recon_failed:' . $r['id'],
            'type'      => 'recon_failed',
            'title'     => '✕ Reconciliation run #' . $r['id'] . ' failed',
            'desc'      => ($r['period_label'] ?? '') . ' — ' . substr($r['progress_msg'] ?? 'Unknown error', 0, 100),
            'link'      => '/icecashRec/modules/reconciliation.php',
            'created_at'=> $r['started_at'],
            'unread'    => $r['started_at'] > $last_read,
        );
    }
    $stmt->close();
}

// Reconciler: escalations I raised that got resolved/dismissed recently
if (in_array($role, array('Reconciler','Manager','Admin'))) {
    $stmt = $db->prepare("
        SELECT e.id, e.status, e.reviewed_at, e.action_detail,
               rv.full_name AS reviewed_by_name
        FROM escalations e
        LEFT JOIN users rv ON e.reviewed_by = rv.id
        WHERE e.user_id = ?
          AND e.status IN ('resolved','dismissed','reviewed')
          AND e.reviewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY e.reviewed_at DESC
        LIMIT 5
    ");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $e) {
        $icon = $e['status'] === 'resolved' ? '✓' : ($e['status'] === 'dismissed' ? '×' : '👁');
        $items[] = array(
            'key'       => 'escalation_closed:' . $e['id'],
            'type'      => 'escalation_closed',
            'title'     => $icon . ' Your escalation #' . $e['id'] . ' ' . $e['status'],
            'desc'      => 'By ' . ($e['reviewed_by_name'] ?? 'manager') . ' — ' . substr($e['action_detail'], 0, 80),
            'link'      => '/icecashRec/admin/escalations.php?filter=' . $e['status'],
            'created_at'=> $e['reviewed_at'],
            'unread'    => $e['reviewed_at'] > $last_read,
        );
    }
    $stmt->close();
}

// Everyone: my own failed uploads in last 48 hours
$stmt = $db->prepare("
    SELECT id, filename, validation_msg, upload_status, created_at
    FROM upload_history
    WHERE uploaded_by = ?
      AND upload_status IN ('failed','warning')
      AND created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->bind_param('i', $uid);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $u) {
    $icon = $u['upload_status'] === 'failed' ? '✕' : '⚠';
    $items[] = array(
        'key'       => 'upload:' . $u['id'],
        'type'      => 'upload',
        'title'     => $icon . ' Upload ' . strtoupper($u['upload_status']) . ': ' . $u['filename'],
        'desc'      => substr($u['validation_msg'], 0, 120),
        'link'      => '/icecashRec/utilities/uploaded_files_list.php',
        'created_at'=> $u['created_at'],
        'unread'    => $u['created_at'] > $last_read,
    );
}
$stmt->close();

// Manager/Admin: new draft statements in last 24 hours
if (in_array($role, array('Manager','Admin'))) {
    $stmt = $db->prepare("
        SELECT s.id, s.statement_no, s.generated_at, a.agent_name, s.variance_zwg
        FROM statements s
        JOIN agents a ON s.agent_id = a.id
        WHERE s.status = 'draft'
          AND s.generated_by <> ?
          AND s.generated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY s.generated_at DESC
        LIMIT 5
    ");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
        $items[] = array(
            'key'       => 'statement:' . $s['id'],
            'type'      => 'statement',
            'title'     => '📄 New draft statement ' . $s['statement_no'],
            'desc'      => $s['agent_name'] . ' · variance ZWG ' . number_format($s['variance_zwg'], 0),
            'link'      => '/icecashRec/admin/statement_detail.php?id=' . $s['id'],
            'created_at'=> $s['generated_at'],
            'unread'    => $s['generated_at'] > $last_read,
        );
    }
    $stmt->close();
}

// Filter out per-user dismissed items
$items = array_values(array_filter($items, function($it) use ($dismissed_set) {
    return !in_array($it['key'], $dismissed_set);
}));

// Sort all items by created_at desc, cap at 15
usort($items, function($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
$items = array_slice($items, 0, 15);

// Per-item read tracking: an item is unread only if its key is NOT in the read set.
// This replaces the old timestamp-based approach so only individually clicked
// notifications lose their unread status.
$unread_count = 0;
foreach ($items as &$it) {
    $it['unread'] = !in_array($it['key'], $read_set);
    if ($it['unread']) $unread_count++;
}
unset($it);

out(array(
    'unread_count' => $unread_count,
    'items'        => $items,
    'last_read'    => $last_read,
));
