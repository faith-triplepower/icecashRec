<?php
// ============================================================
// process/process_search.php — Global search AJAX endpoint
// Returns JSON results across sales, receipts, agents, escalations, statements.
// ============================================================
require_once '../core/auth.php';
require_login();

header('Content-Type: application/json');
$db = get_db();
$q = trim(isset($_GET['q']) ? $_GET['q'] : '');
if (strlen($q) < 3) { echo json_encode(array('results' => array())); exit; }

$like = '%' . $db->real_escape_string($q) . '%';
$results = array();

// Sales
$rows = $db->query("SELECT id, policy_number, reference_no, txn_date, amount, currency FROM sales WHERE policy_number LIKE '$like' OR reference_no LIKE '$like' LIMIT 5");
if ($rows) {
    while ($r = $rows->fetch_assoc()) {
        $results[] = array(
            'type' => 'Sale', 'icon' => 'fa-shopping-cart',
            'title' => $r['policy_number'],
            'sub' => $r['currency'] . ' ' . number_format($r['amount'], 2) . ' · ' . $r['txn_date'],
            'url' => BASE_URL . '/modules/sales.php?date_from=' . substr($r['txn_date'], 0, 7) . '-01&date_to=' . $r['txn_date'],
        );
    }
}

// Receipts
$rows = $db->query("SELECT id, reference_no, source_name, txn_date, amount, currency FROM receipts WHERE reference_no LIKE '$like' OR source_name LIKE '$like' LIMIT 5");
if ($rows) {
    while ($r = $rows->fetch_assoc()) {
        $results[] = array(
            'type' => 'Receipt', 'icon' => 'fa-bank',
            'title' => $r['reference_no'],
            'sub' => $r['currency'] . ' ' . number_format($r['amount'], 2) . ' · ' . $r['source_name'] . ' · ' . $r['txn_date'],
            'url' => BASE_URL . '/admin/unmatched.php?receipt_id=' . $r['id'],
        );
    }
}

// Agents
$rows = $db->query("SELECT id, agent_name, agent_code, region FROM agents WHERE agent_name LIKE '$like' OR agent_code LIKE '$like' LIMIT 5");
if ($rows) {
    while ($r = $rows->fetch_assoc()) {
        $results[] = array(
            'type' => 'Agent', 'icon' => 'fa-building',
            'title' => $r['agent_name'],
            'sub' => $r['agent_code'] . ' · ' . $r['region'],
            'url' => BASE_URL . '/admin/agent_detail.php?id=' . $r['id'],
        );
    }
}

// Escalations
$rows = $db->query("SELECT id, action_type, priority, LEFT(action_detail, 80) AS detail FROM escalations WHERE action_detail LIKE '$like' LIMIT 5");
if ($rows) {
    while ($r = $rows->fetch_assoc()) {
        $results[] = array(
            'type' => 'Escalation', 'icon' => 'fa-exclamation-circle',
            'title' => '#' . $r['id'] . ' · ' . ucfirst($r['action_type']),
            'sub' => $r['detail'],
            'url' => BASE_URL . '/admin/escalations.php?filter=all',
        );
    }
}

// Statements
$rows = $db->query("SELECT s.id, s.statement_no, a.agent_name, s.status FROM statements s JOIN agents a ON s.agent_id=a.id WHERE s.statement_no LIKE '$like' OR a.agent_name LIKE '$like' LIMIT 5");
if ($rows) {
    while ($r = $rows->fetch_assoc()) {
        $results[] = array(
            'type' => 'Statement', 'icon' => 'fa-file-text',
            'title' => $r['statement_no'],
            'sub' => $r['agent_name'] . ' · ' . ucfirst($r['status']),
            'url' => BASE_URL . '/admin/statement_detail.php?id=' . $r['id'],
        );
    }
}

echo json_encode(array('results' => $results));
