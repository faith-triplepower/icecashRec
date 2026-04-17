<?php
// ============================================================
// process/process_export.php
// Streams printable-PDF HTML reports for variance, unmatched
// receipts, sales, receipts, audit log. Every report opens in
// a new browser tab and auto-triggers window.print() — users
// pick "Save as PDF" in the native print dialog. Zero-dependency:
// no TCPDF/FPDF/composer required.
//
// URL format: ?type=<variance|unmatched|sales|receipts|audit>[&…]
// ============================================================

require_once '../core/auth.php';
require_once '../core/db.php';
require_role(['Manager', 'Reconciler', 'Admin']);

$db   = get_db();
$user = current_user();
$uid  = (int)$user['id'];
$type = $_GET['type'] ?? '';

switch ($type) {

    // ── Variance results for a reconciliation run ────────────
    case 'variance':
    case 'variance_pdf':   // legacy alias — keep working for any bookmarks
        $run_id = (int)($_GET['run_id'] ?? 0);
        if (!$run_id) redirect_error('Missing run_id parameter.');

        $run_stmt = $db->prepare("
            SELECT r.*, u.full_name AS run_by_name
            FROM reconciliation_runs r
            JOIN users u ON r.run_by = u.id
            WHERE r.id = ? LIMIT 1
        ");
        $run_stmt->bind_param('i', $run_id);
        $run_stmt->execute();
        $run = $run_stmt->get_result()->fetch_assoc();
        $run_stmt->close();
        if (!$run) redirect_error('Reconciliation run not found.');

        $results = $db->query("
            SELECT v.*, a.agent_code, a.agent_name
            FROM variance_results v
            JOIN agents a ON v.agent_id = a.id
            WHERE v.run_id = $run_id
            ORDER BY v.recon_status DESC, ABS(v.variance_zwg) + ABS(v.variance_usd) DESC
        ")->fetch_all(MYSQLI_ASSOC);

        $totals = $db->query("
            SELECT
              COALESCE(SUM(sales_zwg),0)    AS ts_zwg,
              COALESCE(SUM(sales_usd),0)    AS ts_usd,
              COALESCE(SUM(receipts_zwg),0) AS tr_zwg,
              COALESCE(SUM(receipts_usd),0) AS tr_usd,
              COALESCE(SUM(variance_zwg),0) AS tv_zwg,
              COALESCE(SUM(variance_usd),0) AS tv_usd,
              COUNT(*) AS agent_cnt,
              SUM(CASE WHEN recon_status='reconciled' THEN 1 ELSE 0 END) AS reconciled_cnt,
              SUM(CASE WHEN recon_status='variance'   THEN 1 ELSE 0 END) AS variance_cnt
            FROM variance_results WHERE run_id = $run_id
        ")->fetch_assoc();

        audit_log($uid, 'REPORT_EXPORT', "Printed variance report for run #$run_id ({$run['period_label']})");

        $match_rate = $totals['agent_cnt'] > 0
            ? round(($totals['reconciled_cnt'] / $totals['agent_cnt']) * 100, 1)
            : 0;
        $tv_zwg_class = $totals['tv_zwg'] < 0 ? 'red' : ($totals['tv_zwg'] == 0 ? 'green' : 'warn');

        $table_rows = array();
        foreach ($results as $r) {
            $var_zwg_class = $r['variance_zwg'] < 0 ? 'neg' : ($r['variance_zwg'] > 0 ? 'pos' : '');
            $var_usd_class = $r['variance_usd'] < 0 ? 'neg' : ($r['variance_usd'] > 0 ? 'pos' : '');
            $badge  = '<span class="status-badge status-' . $r['recon_status'] . '">' . htmlspecialchars(ucfirst($r['recon_status'])) . '</span>';
            $table_rows[] = array(
                '_row_class' => 'row-' . $r['recon_status'],
                htmlspecialchars($r['agent_code']),
                htmlspecialchars($r['agent_name']),
                array('num'=>number_format($r['sales_zwg'], 2)),
                array('num'=>number_format($r['sales_usd'], 2)),
                array('num'=>number_format($r['receipts_zwg'], 2)),
                array('num'=>number_format($r['receipts_usd'], 2)),
                array('num'=>number_format($r['variance_zwg'], 2), 'class'=>$var_zwg_class),
                array('num'=>number_format($r['variance_usd'], 2), 'class'=>$var_usd_class),
                $badge,
            );
        }
        $t_zwg_class = $totals['tv_zwg'] < 0 ? 'neg' : 'pos';
        $t_usd_class = $totals['tv_usd'] < 0 ? 'neg' : 'pos';

        render_printable_report(array(
            'title'   => 'Variance Reconciliation Report',
            'filename_hint' => 'variance_run_' . $run_id,
            'meta' => array(
                'Run ID'     => '#' . $run_id,
                'Period'     => $run['period_label'],
                'Product'    => $run['product'],
                'Run Status' => ucfirst($run['run_status']),
                'Date From'  => $run['date_from'],
                'Date To'    => $run['date_to'],
                'Run By'     => $run['run_by_name'],
                'Run At'     => date('d M Y H:i', strtotime($run['started_at'])),
            ),
            'kpis' => array(
                array('label'=>'Match Rate',        'value'=>$match_rate . '%',                                    'tone'=>'green'),
                array('label'=>'Agents Reconciled', 'value'=>(int)$totals['reconciled_cnt'] . ' / ' . (int)$totals['agent_cnt'], 'tone'=>'blue'),
                array('label'=>'Total Variance ZWG','value'=>number_format($totals['tv_zwg'], 2),                  'tone'=>$tv_zwg_class),
                array('label'=>'Total Variance USD','value'=>number_format($totals['tv_usd'], 2),                  'tone'=>'warn'),
            ),
            'section_title' => 'Per-Agent Reconciliation Statement',
            'columns' => array(
                array('label'=>'Agent Code'),
                array('label'=>'Agent Name'),
                array('label'=>'Sales ZWG',   'num'=>true),
                array('label'=>'Sales USD',   'num'=>true),
                array('label'=>'Receipts ZWG','num'=>true),
                array('label'=>'Receipts USD','num'=>true),
                array('label'=>'Var ZWG',     'num'=>true),
                array('label'=>'Var USD',     'num'=>true),
                array('label'=>'Status'),
            ),
            'rows' => $table_rows,
            'empty_msg' => 'No agent results were produced for this run.',
            'totals_row' => array(
                array('span'=>2, 'text'=>'TOTALS'),
                array('num'=>number_format($totals['ts_zwg'], 2)),
                array('num'=>number_format($totals['ts_usd'], 2)),
                array('num'=>number_format($totals['tr_zwg'], 2)),
                array('num'=>number_format($totals['tr_usd'], 2)),
                array('num'=>number_format($totals['tv_zwg'], 2), 'class'=>$t_zwg_class),
                array('num'=>number_format($totals['tv_usd'], 2), 'class'=>$t_usd_class),
                array('text'=>''),
            ),
            'printed_by' => $user['name'],
        ));
        exit;

    // ── Unmatched receipts + unmatched sales ─────────────────
    case 'unmatched':
        list($date_from, $date_to) = resolve_report_range($db, 'receipts');

        // Unmatched credit receipts
        $rec_stmt = $db->prepare("
            SELECT reference_no, txn_date, terminal_id, channel, source_name,
                   amount, currency, match_status,
                   DATEDIFF(CURDATE(), txn_date) AS days_old
            FROM receipts
            WHERE direction='credit'
              AND match_status IN ('pending','variance')
              AND txn_date BETWEEN ? AND ?
            ORDER BY txn_date ASC
        ");
        $rec_stmt->bind_param('ss', $date_from, $date_to);
        $rec_stmt->execute();
        $unm_receipts = $rec_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rec_stmt->close();

        // Unmatched sales (no receipt linked)
        $sal_stmt = $db->prepare("
            SELECT s.policy_number, s.txn_date, s.payment_method, s.amount, s.currency,
                   s.terminal_id, a.agent_name,
                   DATEDIFF(CURDATE(), s.txn_date) AS days_old
            FROM sales s
            JOIN agents a ON s.agent_id = a.id
            LEFT JOIN receipts r ON r.matched_sale_id = s.id
            WHERE r.id IS NULL
              AND s.txn_date BETWEEN ? AND ?
            ORDER BY s.txn_date ASC
        ");
        $sal_stmt->bind_param('ss', $date_from, $date_to);
        $sal_stmt->execute();
        $unm_sales = $sal_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sal_stmt->close();

        audit_log($uid, 'REPORT_EXPORT', 'Printed unmatched report ('
            . count($unm_receipts) . ' receipts, ' . count($unm_sales) . ' sales)');

        // Combine into one flat list with a Type column so the report
        // reads like a single workbench view of everything unmatched.
        $table_rows = array();
        foreach ($unm_receipts as $r) {
            $table_rows[] = array(
                '<span class="status-badge status-variance">RECEIPT</span>',
                htmlspecialchars($r['reference_no']),
                htmlspecialchars($r['txn_date']),
                htmlspecialchars($r['channel']),
                htmlspecialchars($r['source_name']),
                array('num'=>$r['currency'] . ' ' . number_format($r['amount'], 2)),
                array('num'=>(int)$r['days_old'] . 'd'),
                '<span class="status-badge status-' . $r['match_status'] . '">' . strtoupper($r['match_status']) . '</span>',
            );
        }
        foreach ($unm_sales as $s) {
            $table_rows[] = array(
                '<span class="status-badge status-pending">SALE</span>',
                htmlspecialchars($s['policy_number']),
                htmlspecialchars($s['txn_date']),
                htmlspecialchars($s['payment_method']),
                htmlspecialchars($s['agent_name']),
                array('num'=>$s['currency'] . ' ' . number_format($s['amount'], 2)),
                array('num'=>(int)$s['days_old'] . 'd'),
                '<span class="status-badge status-pending">UNMATCHED</span>',
            );
        }

        render_printable_report(array(
            'title'   => 'Unmatched Transactions Report',
            'filename_hint' => 'unmatched_' . $date_from . '_to_' . $date_to,
            'meta' => array(
                'Period From'      => $date_from,
                'Period To'        => $date_to,
                'Unmatched Receipts' => (string)count($unm_receipts),
                'Unmatched Sales'    => (string)count($unm_sales),
            ),
            'kpis' => array(
                array('label'=>'Unmatched Receipts', 'value'=>(string)count($unm_receipts), 'tone'=>'warn'),
                array('label'=>'Unmatched Sales',    'value'=>(string)count($unm_sales),    'tone'=>'warn'),
                array('label'=>'Combined Total',     'value'=>(string)(count($unm_receipts) + count($unm_sales)), 'tone'=>'red'),
                array('label'=>'Date Range Days',    'value'=>(string)(1 + (strtotime($date_to) - strtotime($date_from)) / 86400), 'tone'=>'blue'),
            ),
            'section_title' => 'Transactions requiring manual review',
            'columns' => array(
                array('label'=>'Type'),
                array('label'=>'Reference / Policy'),
                array('label'=>'Date'),
                array('label'=>'Channel / Method'),
                array('label'=>'Source / Agent'),
                array('label'=>'Amount', 'num'=>true),
                array('label'=>'Age',    'num'=>true),
                array('label'=>'Status'),
            ),
            'rows' => $table_rows,
            'empty_msg' => 'Nothing unmatched in this date range — everything reconciled.',
            'printed_by' => $user['name'],
        ));
        exit;

    // ── All sales for a period ───────────────────────────────
    case 'sales':
        list($date_from, $date_to) = resolve_report_range($db, 'sales');

        $q = $db->prepare("
            SELECT s.policy_number, s.txn_date, a.agent_name, s.product,
                   s.payment_method, s.amount, s.currency, s.currency_flag
            FROM sales s
            JOIN agents a ON s.agent_id = a.id
            WHERE s.txn_date BETWEEN ? AND ?
            ORDER BY s.txn_date DESC
        ");
        $q->bind_param('ss', $date_from, $date_to);
        $q->execute();
        $rows_raw = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        audit_log($uid, 'REPORT_EXPORT', 'Printed sales report (' . count($rows_raw) . ' records)');

        $zwg_total = 0.0; $usd_total = 0.0; $flagged_cnt = 0;
        $table_rows = array();
        foreach ($rows_raw as $r) {
            if ($r['currency'] === 'USD') $usd_total += (float)$r['amount'];
            else                          $zwg_total += (float)$r['amount'];
            if ($r['currency_flag']) $flagged_cnt++;
            $table_rows[] = array(
                htmlspecialchars($r['policy_number']),
                htmlspecialchars($r['txn_date']),
                htmlspecialchars($r['agent_name']),
                htmlspecialchars($r['product']),
                htmlspecialchars($r['payment_method']),
                array('num'=>number_format($r['amount'], 2)),
                htmlspecialchars($r['currency']),
                $r['currency_flag'] ? '<span class="status-badge status-variance">YES</span>' : '—',
            );
        }

        render_printable_report(array(
            'title'   => 'Sales Data Report',
            'filename_hint' => 'sales_' . $date_from . '_to_' . $date_to,
            'meta' => array(
                'Period From' => $date_from,
                'Period To'   => $date_to,
                'Total Rows'  => (string)count($rows_raw),
            ),
            'kpis' => array(
                array('label'=>'Policies', 'value'=>(string)count($rows_raw),     'tone'=>'blue'),
                array('label'=>'ZWG Total','value'=>number_format($zwg_total, 2), 'tone'=>'green'),
                array('label'=>'USD Total','value'=>number_format($usd_total, 2), 'tone'=>'blue'),
                array('label'=>'FX Flagged','value'=>(string)$flagged_cnt,        'tone'=>'warn'),
            ),
            'section_title' => 'Sales Transactions',
            'columns' => array(
                array('label'=>'Policy Number'),
                array('label'=>'Date'),
                array('label'=>'Agent'),
                array('label'=>'Product'),
                array('label'=>'Payment Method'),
                array('label'=>'Amount', 'num'=>true),
                array('label'=>'Currency'),
                array('label'=>'FX Flag'),
            ),
            'rows' => $table_rows,
            'empty_msg' => 'No sales records in this date range.',
            'printed_by' => $user['name'],
        ));
        exit;

    // ── Receipts for a period ────────────────────────────────
    case 'receipts':
        list($date_from, $date_to) = resolve_report_range($db, 'receipts');
        $dir_param = $_GET['direction'] ?? 'credit';
        $dir_where = '';
        if ($dir_param === 'credit' || $dir_param === 'debit') {
            $dir_where = " AND direction = '" . $dir_param . "'";
        }

        $q = $db->prepare("SELECT * FROM receipts WHERE txn_date BETWEEN ? AND ?" . $dir_where . " ORDER BY txn_date DESC");
        $q->bind_param('ss', $date_from, $date_to);
        $q->execute();
        $rows_raw = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        audit_log($uid, 'REPORT_EXPORT', 'Printed receipts report (' . count($rows_raw) . ' records, direction=' . $dir_param . ')');

        $zwg_total = 0.0; $usd_total = 0.0; $matched_cnt = 0;
        $table_rows = array();
        foreach ($rows_raw as $r) {
            if ($r['currency'] === 'USD') $usd_total += (float)$r['amount'];
            else                          $zwg_total += (float)$r['amount'];
            if ($r['match_status'] === 'matched') $matched_cnt++;
            $dir_display = '<span class="status-badge status-' . ($r['direction'] === 'debit' ? 'variance' : 'reconciled') . '">' . strtoupper($r['direction']) . '</span>';
            $table_rows[] = array(
                htmlspecialchars($r['reference_no']),
                htmlspecialchars($r['txn_date']),
                htmlspecialchars($r['terminal_id'] ?? ''),
                htmlspecialchars($r['channel']),
                htmlspecialchars($r['source_name']),
                array('num'=>number_format($r['amount'], 2)),
                htmlspecialchars($r['currency']),
                $dir_display,
                htmlspecialchars($r['matched_policy'] ?? '—'),
                '<span class="status-badge status-' . $r['match_status'] . '">' . strtoupper($r['match_status']) . '</span>',
            );
        }

        render_printable_report(array(
            'title'   => 'Receipts Data Report',
            'filename_hint' => 'receipts_' . $dir_param . '_' . $date_from . '_to_' . $date_to,
            'meta' => array(
                'Period From' => $date_from,
                'Period To'   => $date_to,
                'Direction'   => ucfirst($dir_param),
                'Total Rows'  => (string)count($rows_raw),
            ),
            'kpis' => array(
                array('label'=>'Total Rows', 'value'=>(string)count($rows_raw),     'tone'=>'blue'),
                array('label'=>'Matched',    'value'=>(string)$matched_cnt,         'tone'=>'green'),
                array('label'=>'ZWG Total',  'value'=>number_format($zwg_total, 2), 'tone'=>'green'),
                array('label'=>'USD Total',  'value'=>number_format($usd_total, 2), 'tone'=>'blue'),
            ),
            'section_title' => 'Receipt Transactions',
            'columns' => array(
                array('label'=>'Reference'),
                array('label'=>'Date'),
                array('label'=>'Terminal'),
                array('label'=>'Channel'),
                array('label'=>'Source'),
                array('label'=>'Amount', 'num'=>true),
                array('label'=>'Currency'),
                array('label'=>'Direction'),
                array('label'=>'Matched Policy'),
                array('label'=>'Status'),
            ),
            'rows' => $table_rows,
            'empty_msg' => 'No receipt records in this date range.',
            'printed_by' => $user['name'],
        ));
        exit;

    // ── Audit log ────────────────────────────────────────────
    case 'audit':
        require_role(['Manager','Admin']);

        $rows_raw = $db->query("
            SELECT al.id, u.full_name, al.action_type, al.detail, al.ip_address, al.result, al.created_at
            FROM audit_log al
            JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT 2000
        ")->fetch_all(MYSQLI_ASSOC);

        audit_log($uid, 'REPORT_EXPORT', 'Printed audit log (' . count($rows_raw) . ' entries)');

        $action_counts = array();
        foreach ($rows_raw as $r) {
            $action_counts[$r['action_type']] = ($action_counts[$r['action_type']] ?? 0) + 1;
        }
        arsort($action_counts);
        $top_actions = array_slice($action_counts, 0, 3, true);
        $top_actions_str = '';
        foreach ($top_actions as $act => $cnt) {
            if ($top_actions_str !== '') $top_actions_str .= ', ';
            $top_actions_str .= $act . ' (' . $cnt . ')';
        }

        $table_rows = array();
        foreach ($rows_raw as $r) {
            $table_rows[] = array(
                (string)$r['id'],
                date('d M Y H:i', strtotime($r['created_at'])),
                htmlspecialchars($r['full_name']),
                htmlspecialchars($r['action_type']),
                htmlspecialchars($r['detail']),
                htmlspecialchars($r['ip_address']),
                '<span class="status-badge status-' . ($r['result'] === 'success' ? 'reconciled' : 'variance') . '">' . strtoupper($r['result']) . '</span>',
            );
        }

        render_printable_report(array(
            'title'   => 'System Audit Log',
            'filename_hint' => 'audit_log_' . date('Ymd_His'),
            'meta' => array(
                'Entries'     => (string)count($rows_raw),
                'Limit'       => '2000 most recent',
                'Printed At'  => date('d M Y H:i'),
            ),
            'kpis' => array(
                array('label'=>'Total Entries','value'=>(string)count($rows_raw),         'tone'=>'blue'),
                array('label'=>'Unique Users', 'value'=>(string)count(array_unique(array_column($rows_raw, 'full_name'))), 'tone'=>'green'),
                array('label'=>'Action Types', 'value'=>(string)count($action_counts),    'tone'=>'warn'),
                array('label'=>'Top Action',   'value'=>$top_actions_str ?: '—',          'tone'=>'blue'),
            ),
            'section_title' => 'Audit Entries (newest first)',
            'columns' => array(
                array('label'=>'ID'),
                array('label'=>'Timestamp'),
                array('label'=>'User'),
                array('label'=>'Action'),
                array('label'=>'Detail'),
                array('label'=>'IP'),
                array('label'=>'Result'),
            ),
            'rows' => $table_rows,
            'empty_msg' => 'No audit entries recorded yet.',
            'printed_by' => $user['name'],
        ));
        exit;

    default:
        redirect_error('Unknown report type.');
}

// ── Helpers ──────────────────────────────────────────────────

function redirect_error(string $msg): void
{
    header('Location: ' . BASE_URL . '/modules/variance.php?error=' . urlencode($msg));
    exit;
}

/**
 * Pick date_from/date_to for a report. Explicit ?date_from / ?date_to
 * query params win; otherwise we auto-select the latest month that
 * actually has data in the named table (same pattern as the dashboard
 * and data pages) so historical uploads don't produce empty reports.
 */
function resolve_report_range($db, string $table): array
{
    if (isset($_GET['date_from']) || isset($_GET['date_to'])) {
        return array(
            $_GET['date_from'] ?? date('Y-m-01'),
            $_GET['date_to']   ?? date('Y-m-d'),
        );
    }
    $t = $table === 'sales' ? 'sales' : 'receipts';
    $row = $db->query("SELECT DATE_FORMAT(MAX(txn_date), '%Y-%m-01') AS ms, LAST_DAY(MAX(txn_date)) AS me FROM $t")->fetch_assoc();
    if ($row && $row['ms']) {
        return array($row['ms'], $row['me']);
    }
    return array(date('Y-m-01'), date('Y-m-t'));
}

/**
 * Generic printable-report renderer. Streams a self-contained HTML
 * document styled for A4 print and auto-triggers window.print() on
 * load. Accountants pick "Save as PDF" in the browser's native
 * print dialog — no server-side PDF library needed.
 *
 * Config keys:
 *   title          — page heading shown in brand header
 *   filename_hint  — used in the document <title> only; filename is
 *                    chosen by the user in the browser Save dialog
 *   meta           — associative array of label => value shown in
 *                    the metadata grid under the header
 *   kpis           — array of [label,value,tone] cards. tone =
 *                    green|blue|warn|red
 *   section_title  — heading above the data table
 *   columns        — array of [label, num?] column definitions
 *   rows           — array of rows. Each row is an array of cells.
 *                    A cell can be a plain string, or an array with
 *                    {text?, num?, class?, span?}. String cells are
 *                    inserted verbatim (caller is responsible for
 *                    escaping). An optional `_row_class` key on the
 *                    row adds a CSS class to the <tr>.
 *   empty_msg      — text shown when rows is empty
 *   totals_row     — optional totals row (same cell format as rows)
 *   printed_by     — name of the user who hit Print
 */
function render_printable_report(array $cfg): void
{
    header('Content-Type: text/html; charset=utf-8');

    $title = htmlspecialchars($cfg['title'] ?? 'Report');
    $printed_by = htmlspecialchars($cfg['printed_by'] ?? '—');
    $printed_at = date('d M Y H:i');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<title>' . $title . ' — ' . htmlspecialchars($cfg['filename_hint'] ?? date('Ymd_His')) . '</title>';
    echo '<style>
        * { box-sizing: border-box; }
        body { font-family: "Helvetica", "Arial", sans-serif; color: #222; margin: 0; padding: 24px 32px; font-size: 11pt; }
        .doc-header { border-bottom: 3px solid #00a950; padding-bottom: 12px; margin-bottom: 16px; display: flex; align-items: center; gap: 16px; }
        .doc-header img { height: 48px; }
        .doc-title { font-size: 18pt; font-weight: 700; color: #00a950; margin: 0; }
        .doc-sub { font-size: 10pt; color: #666; margin-top: 2px; }
        .meta-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 18px; font-size: 9.5pt; }
        .meta-grid .lbl { color: #888; text-transform: uppercase; font-size: 8pt; letter-spacing: 0.5px; }
        .meta-grid .val { font-weight: 600; color: #222; }
        .kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
        .kpi { border: 1px solid #ddd; border-radius: 4px; padding: 10px 12px; background: #fafafa; }
        .kpi .lbl { font-size: 8pt; text-transform: uppercase; color: #888; letter-spacing: 0.5px; }
        .kpi .val { font-size: 14pt; font-weight: 700; color: #222; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .kpi.green { border-left: 4px solid #00a950; }
        .kpi.red   { border-left: 4px solid #c0392b; }
        .kpi.warn  { border-left: 4px solid #d49a00; }
        .kpi.blue  { border-left: 4px solid #0066cc; }
        h2 { font-size: 12pt; color: #00a950; margin: 18px 0 8px; border-bottom: 1px solid #e0e0e0; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 9pt; }
        th { background: #f0f4f1; color: #444; font-weight: 700; text-align: left; padding: 8px 10px; border-bottom: 2px solid #00a950; }
        th.num, td.num { text-align: right; font-family: "Courier New", monospace; }
        td { padding: 7px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
        tr.row-variance td { background: #fff8f3; }
        tr.totals { background: #eaf7ef; font-weight: 700; border-top: 2px solid #00a950; }
        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 2px; font-size: 8pt; font-weight: 700; }
        .status-reconciled, .status-matched { background: #d4edda; color: #155724; }
        .status-variance                    { background: #f4c3c3; color: #8a0000; }
        .status-pending                     { background: #fff3cd; color: #856404; }
        .neg { color: #c0392b; }
        .pos { color: #00a950; }
        .footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #e0e0e0; font-size: 8.5pt; color: #888; display: flex; justify-content: space-between; }
        .print-btn { position: fixed; top: 12px; right: 12px; padding: 8px 16px; background: #00a950; color: #fff; border: none; border-radius: 3px; font-size: 11pt; cursor: pointer; font-weight: 600; }

        @media print {
            body { padding: 0; }
            .print-btn { display: none; }
            .kpi, th, tr.totals, tr.row-variance td, .status-badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            @page { size: A4 landscape; margin: 10mm 10mm; }
        }
    </style></head><body>';

    echo '<button class="print-btn" onclick="window.print()">&#x1F5B6;&nbsp; Print / Save as PDF</button>';

    echo '<div class="doc-header">';
    echo '  <img src="' . BASE_URL . '/assets/img/zimnat logo.png" alt="Zimnat">';
    echo '  <div>';
    echo '    <h1 class="doc-title">' . $title . '</h1>';
    echo '    <div class="doc-sub">Zimnat General Insurance &middot; Finance Reconciliation System</div>';
    echo '  </div>';
    echo '</div>';

    if (!empty($cfg['meta'])) {
        echo '<div class="meta-grid">';
        foreach ($cfg['meta'] as $label => $value) {
            echo '<div><div class="lbl">' . htmlspecialchars($label) . '</div><div class="val">' . htmlspecialchars((string)$value) . '</div></div>';
        }
        echo '</div>';
    }

    if (!empty($cfg['kpis'])) {
        echo '<div class="kpi-row">';
        foreach ($cfg['kpis'] as $k) {
            $tone = $k['tone'] ?? 'blue';
            echo '<div class="kpi ' . htmlspecialchars($tone) . '">';
            echo '<div class="lbl">' . htmlspecialchars($k['label']) . '</div>';
            echo '<div class="val" title="' . htmlspecialchars((string)$k['value']) . '">' . htmlspecialchars((string)$k['value']) . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    if (!empty($cfg['section_title'])) {
        echo '<h2>' . htmlspecialchars($cfg['section_title']) . '</h2>';
    }
    echo '<table><thead><tr>';
    $col_count = count($cfg['columns']);
    foreach ($cfg['columns'] as $c) {
        $cls = !empty($c['num']) ? ' class="num"' : '';
        echo '<th' . $cls . '>' . htmlspecialchars($c['label']) . '</th>';
    }
    echo '</tr></thead><tbody>';

    if (empty($cfg['rows'])) {
        echo '<tr><td colspan="' . $col_count . '" style="text-align:center;padding:20px;color:#888;font-style:italic">'
           . htmlspecialchars($cfg['empty_msg'] ?? 'No rows.') . '</td></tr>';
    } else {
        foreach ($cfg['rows'] as $row) {
            $row_class = '';
            if (isset($row['_row_class'])) {
                $row_class = ' class="' . htmlspecialchars($row['_row_class']) . '"';
                unset($row['_row_class']);
            }
            echo '<tr' . $row_class . '>';
            foreach ($row as $cell) {
                render_cell($cell);
            }
            echo '</tr>';
        }
        if (!empty($cfg['totals_row'])) {
            echo '<tr class="totals">';
            foreach ($cfg['totals_row'] as $cell) {
                render_cell($cell);
            }
            echo '</tr>';
        }
    }
    echo '</tbody></table>';

    echo '<div class="footer">';
    echo '  <div>Printed by <strong>' . $printed_by . '</strong> on ' . $printed_at . '</div>';
    echo '  <div>IceCashRec &middot; Confidential — for finance review only</div>';
    echo '</div>';

    echo '<script>window.addEventListener("load", function(){ setTimeout(function(){ window.print(); }, 400); });</script>';
    echo '</body></html>';
}

/**
 * Render one table cell. See render_printable_report() for the
 * accepted cell formats.
 */
function render_cell($cell): void
{
    if (is_array($cell)) {
        $cls_parts = array();
        if (!empty($cell['num']))   $cls_parts[] = 'num';
        if (!empty($cell['class'])) $cls_parts[] = $cell['class'];
        $cls  = $cls_parts ? ' class="' . implode(' ', $cls_parts) . '"' : '';
        $span = !empty($cell['span']) ? ' colspan="' . (int)$cell['span'] . '"' : '';
        $text = $cell['text'] ?? ($cell['num'] ?? '');
        echo '<td' . $cls . $span . '>' . $text . '</td>';
    } else {
        // Plain string: already HTML, caller escaped it.
        echo '<td>' . $cell . '</td>';
    }
}
