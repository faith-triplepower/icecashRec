<?php
// ============================================================
// process/process_export_csv.php — CSV export for all tables
// Respects current filters via GET params.
// ============================================================
require_once '../core/auth.php';
require_login();

$db = get_db();
$user = current_user();
$type = isset($_GET['type']) ? $_GET['type'] : '';

function csv_start($filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    return $out;
}

switch ($type) {
    case 'sales':
        $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
        $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
        $out = csv_start("sales_{$date_from}_to_{$date_to}.csv");
        fputcsv($out, array('Policy #','Reference','Date','Agent','Product','Method','Amount','Currency','Source'));
        $rows = $db->query("SELECT s.policy_number, s.reference_no, s.txn_date, a.agent_name, s.product, s.payment_method, s.amount, s.currency, s.source_system FROM sales s JOIN agents a ON s.agent_id=a.id WHERE s.txn_date BETWEEN '".$db->real_escape_string($date_from)."' AND '".$db->real_escape_string($date_to)."' ORDER BY s.txn_date DESC");
        while ($r = $rows->fetch_assoc()) fputcsv($out, array_values($r));
        break;

    case 'receipts':
        $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
        $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
        $direction = isset($_GET['direction']) ? $_GET['direction'] : 'credit';
        $dir_where = in_array($direction, array('credit','debit')) ? "AND direction='".$db->real_escape_string($direction)."'" : '';
        $out = csv_start("receipts_{$date_from}_to_{$date_to}.csv");
        fputcsv($out, array('Reference','Date','Terminal','Channel','Source','Amount','Currency','Direction','Status'));
        $rows = $db->query("SELECT reference_no, txn_date, terminal_id, channel, source_name, amount, currency, direction, match_status FROM receipts WHERE txn_date BETWEEN '".$db->real_escape_string($date_from)."' AND '".$db->real_escape_string($date_to)."' $dir_where ORDER BY txn_date DESC");
        while ($r = $rows->fetch_assoc()) fputcsv($out, array_values($r));
        break;

    case 'variance':
        $run_id = (int)(isset($_GET['run_id']) ? $_GET['run_id'] : 0);
        if (!$run_id) die('run_id required');
        $run = $db->query("SELECT period_label FROM reconciliation_runs WHERE id=$run_id")->fetch_assoc();
        $label = $run ? preg_replace('/[^a-zA-Z0-9]/', '_', $run['period_label']) : $run_id;
        $out = csv_start("variance_run_{$run_id}_{$label}.csv");
        fputcsv($out, array('Agent','Sales ZWG','Sales USD','Receipts ZWG','Receipts USD','Variance ZWG','Variance USD','Category','Status'));
        $rows = $db->query("SELECT a.agent_name, vr.sales_zwg, vr.sales_usd, vr.receipts_zwg, vr.receipts_usd, vr.variance_zwg, vr.variance_usd, vr.variance_cat, vr.recon_status FROM variance_results vr JOIN agents a ON vr.agent_id=a.id WHERE vr.run_id=$run_id ORDER BY a.agent_name");
        while ($r = $rows->fetch_assoc()) fputcsv($out, array_values($r));
        break;

    case 'unmatched':
        $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
        $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
        $out = csv_start("unmatched_{$date_from}_to_{$date_to}.csv");
        fputcsv($out, array('Reference','Date','Terminal','Channel','Source','Amount','Currency','Direction','Status'));
        $rows = $db->query("SELECT reference_no, txn_date, terminal_id, channel, source_name, amount, currency, direction, match_status FROM receipts WHERE match_status IN ('pending','variance') AND direction='credit' AND txn_date BETWEEN '".$db->real_escape_string($date_from)."' AND '".$db->real_escape_string($date_to)."' ORDER BY txn_date DESC");
        while ($r = $rows->fetch_assoc()) fputcsv($out, array_values($r));
        break;

    case 'escalations':
        $out = csv_start("escalations_" . date('Y-m-d') . ".csv");
        fputcsv($out, array('ID','Priority','Type','Agent','Detail','Status','Assigned To','Submitted By','Created'));
        $rows = $db->query("SELECT e.id, e.priority, e.action_type, a.agent_name, e.action_detail, e.status, u2.full_name as assigned_name, u.full_name as submitted_by, e.created_at FROM escalations e JOIN users u ON e.user_id=u.id LEFT JOIN users u2 ON e.assigned_to=u2.id LEFT JOIN agents a ON e.agent_id=a.id ORDER BY e.created_at DESC");
        while ($r = $rows->fetch_assoc()) fputcsv($out, array_values($r));
        break;

    case 'statements':
        $from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-01');
        $to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-t');
        $out = csv_start("statements_{$from}_to_{$to}.csv");
        fputcsv($out, array('Statement #','Agent','Period From','Period To','Sales ZWG','Sales USD','Receipts ZWG','Receipts USD','Variance ZWG','Variance USD','Category','Status','Issued By','Date'));
        $rows = $db->query("SELECT s.statement_no, a.agent_name, s.period_from, s.period_to, s.sales_zwg, s.sales_usd, s.receipts_zwg, s.receipts_usd, s.variance_zwg, s.variance_usd, s.variance_cat, s.status, u.full_name, s.generated_at FROM statements s JOIN agents a ON s.agent_id=a.id JOIN users u ON s.generated_by=u.id WHERE s.period_from >= '".$db->real_escape_string($from)."' AND s.period_to <= '".$db->real_escape_string($to)."' ORDER BY s.generated_at DESC");
        while ($r = $rows->fetch_assoc()) fputcsv($out, array_values($r));
        break;

    default:
        die('Unknown export type');
}
fclose($out);
audit_log((int)$user['id'], 'REPORT_EXPORT', "CSV export: $type");
