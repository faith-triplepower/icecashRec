<?php
// ============================================================
// process/statement_lookup.php
// Returns the sales, receipts, and uploads that feed a given
// statement. Used by:
//   1. statement_detail.php — "What's in this statement?"
//   2. uploaded_files_list.php — to show the user *exactly*
//      which statements are blocking a delete.
//
// A sale/receipt belongs to a statement when:
//   - same agent_id
//   - txn_date BETWEEN statement.period_from AND period_to
// ============================================================

require_once '../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(array('ok' => false, 'message' => 'Not logged in'));
    exit;
}

$db = get_db();
$statement_id = (int)($_GET['statement_id'] ?? 0);
if ($statement_id <= 0) {
    echo json_encode(array('ok' => false, 'message' => 'Invalid statement_id'));
    exit;
}

// 1. Load the statement period + agent
$stmt = $db->prepare("
    SELECT st.id, st.statement_no, st.agent_id, st.period_from, st.period_to,
           a.agent_name
    FROM statements st
    JOIN agents a ON a.id = st.agent_id
    WHERE st.id = ?
");
$stmt->bind_param('i', $statement_id);
$stmt->execute();
$s = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$s) {
    echo json_encode(array('ok' => false, 'message' => 'Statement not found'));
    exit;
}

// 2. Sales feeding this statement (with their upload origin)
$sales_stmt = $db->prepare("
    SELECT s.id, s.policy_number, s.txn_date, s.amount, s.currency,
           s.payment_method, s.paid_status,
           s.upload_id, u.filename AS upload_filename
    FROM sales s
    LEFT JOIN upload_history u ON u.id = s.upload_id
    WHERE s.agent_id = ?
      AND s.txn_date BETWEEN ? AND ?
    ORDER BY s.txn_date, s.id
");
$sales_stmt->bind_param('iss', $s['agent_id'], $s['period_from'], $s['period_to']);
$sales_stmt->execute();
$sales = $sales_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sales_stmt->close();

// 3. Receipts feeding this statement (only matched ones count for variance)
$rec_stmt = $db->prepare("
    SELECT r.id, r.reference_no, r.txn_date, r.amount, r.currency, r.channel,
           r.match_status, r.matched_sale_id, r.matched_policy,
           r.upload_id, u.filename AS upload_filename
    FROM receipts r
    LEFT JOIN upload_history u ON u.id = r.upload_id
    LEFT JOIN sales s2 ON s2.id = r.matched_sale_id
    WHERE r.txn_date BETWEEN ? AND ?
      AND (
            (r.matched_sale_id IS NOT NULL AND s2.agent_id = ?)
         OR (r.matched_sale_id IS NULL AND r.txn_date BETWEEN ? AND ?)
      )
    ORDER BY r.txn_date, r.id
");
$rec_stmt->bind_param('ssiss',
    $s['period_from'], $s['period_to'],
    $s['agent_id'],
    $s['period_from'], $s['period_to']
);
$rec_stmt->execute();
$receipts = $rec_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rec_stmt->close();

// 4. Aggregate: which uploads contributed?
$upload_ids = array();
foreach ($sales as $row)    if ($row['upload_id']) $upload_ids[$row['upload_id']] = true;
foreach ($receipts as $row) if ($row['upload_id']) $upload_ids[$row['upload_id']] = true;

$uploads = array();
if (!empty($upload_ids)) {
    $ids_csv = implode(',', array_map('intval', array_keys($upload_ids)));
    $uploads = $db->query("
        SELECT id, filename, file_type, uploaded_at
        FROM upload_history
        WHERE id IN ($ids_csv)
        ORDER BY uploaded_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
}

echo json_encode(array(
    'ok'        => true,
    'statement' => $s,
    'sales'     => $sales,
    'receipts'  => $receipts,
    'uploads'   => $uploads,
    'counts'    => array(
        'sales'    => count($sales),
        'receipts' => count($receipts),
        'uploads'  => count($uploads),
    ),
));