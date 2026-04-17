<?php
// ============================================================
// process/process_upload.php — File Upload Handler
// Accepts multi-file uploads (CSV, XLS, XLSX, PDF), validates
// MIME types, deduplicates by SHA-256 hash, parses via the
// ingestion engine, and inserts rows into sales/receipts.
// Auto-detects POS terminals from uploaded data.
// Part of IceCashRec — Zimnat General Insurance
// ============================================================

// Pull in authentication, email helpers, and the file parsing engine
require_once '../core/auth.php';
require_once '../core/notifications.php';
require_once '../core/ingestion.php';

// Only Managers, Uploaders, and Admins can upload files
require_role(['Manager','Uploader','Admin']);

// PHP has a sneaky behavior: if the total upload size exceeds post_max_size,
// it silently empties BOTH $_POST and $_FILES. That means our CSRF token
// disappears too, and we'd get a confusing "invalid token" error.
// Catch this early and show a helpful message instead.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    $max = ini_get('post_max_size');
    header('Location: ' . BASE_URL . '/utilities/upload.php?error=' . urlencode("Upload too large — total size exceeds the $max server limit. Upload fewer files at once."));
    exit;
}

// Make sure this is a legitimate form submission (not a CSRF attack)
csrf_verify();

// Who's uploading?
$db   = get_db();
$user = current_user();
$uid  = (int)$user['id'];

// What kind of file are they uploading? (Sales or Receipts)
$file_type   = isset($_POST['file_type'])   ? $_POST['file_type']   : '';
$report_type = isset($_POST['report_type']) ? $_POST['report_type'] : '';
$source_name = isset($_POST['source_name']) ? $_POST['source_name'] : '';
$period_from = !empty($_POST['period_from']) ? $_POST['period_from'] : null;
$period_to   = !empty($_POST['period_to'])   ? $_POST['period_to']   : null;

// ── Validate inputs ──────────────────────────────────────────
if (!in_array($file_type, ['Sales', 'Receipts'])) {
    redirect_back('error', 'Invalid file type selected.');
}

if (empty($_FILES['upload_file'])) {
    redirect_back('error', 'No file was selected.');
}

// ── Normalize to an array of per-file descriptors ───────────
// PHP delivers $_FILES['upload_file'] two different ways:
//   single file:  ['name' => 'a.csv', 'tmp_name' => '...', ...]
//   multiple:     ['name' => ['a.csv','b.csv'], 'tmp_name' => [...], ...]
// We always reshape to: [ ['name'=>..,'tmp_name'=>..,..], ... ]
$raw = $_FILES['upload_file'];
$files = array();
if (is_array($raw['name'])) {
    $n = count($raw['name']);
    for ($i = 0; $i < $n; $i++) {
        if ($raw['error'][$i] === UPLOAD_ERR_NO_FILE) continue; // skip empty slots
        $files[] = array(
            'name'     => $raw['name'][$i],
            'type'     => isset($raw['type'][$i]) ? $raw['type'][$i] : '',
            'tmp_name' => $raw['tmp_name'][$i],
            'error'    => $raw['error'][$i],
            'size'     => $raw['size'][$i],
        );
    }
} else {
    if ($raw['error'] !== UPLOAD_ERR_NO_FILE) $files[] = $raw;
}

if (empty($files)) {
    redirect_back('error', 'No file was selected.');
}

// ── Per-file result accumulator ─────────────────────────────
$results = array(
    'ok' => 0, 'warning' => 0, 'failed' => 0, 'rejected' => 0,
    'total_records' => 0, 'messages' => array(),
);

// Process each file independently
foreach ($files as $file) {
    $orig_name = basename($file['name']);
    $summary   = process_one_upload($db, $file, $file_type, $report_type, $source_name, $period_from, $period_to, $uid);

    $results[$summary['status']]++;
    $results['total_records'] += $summary['record_count'];
    $results['messages'][]     = "'$orig_name' — " . $summary['validation'];

    // Per-file audit entry
    audit_log($uid, 'FILE_UPLOAD',
        "Uploaded: $orig_name ($file_type / $report_type) — " . $summary['validation']);

    // Notify the uploader on failures (respects notif_failed_upload pref)
    if ($summary['status'] === 'failed' || $summary['status'] === 'rejected') {
        enqueue_email($db, $uid,
            "Upload " . strtoupper($summary['status']) . ": $orig_name",
            "Your upload of '$orig_name' ($file_type) failed processing.\n\n"
          . "Reason: " . $summary['validation'] . "\n\n"
          . "Review: " . BASE_URL . "/utilities/uploaded_files_list.php",
            'upload',
            'notif_failed_upload'
        );
    }
}

// ── Build aggregate message + redirect ──────────────────────
$total_files = count($files);
$headline = $total_files . ' file' . ($total_files > 1 ? 's' : '') . ' processed: '
          . $results['ok'] . ' ok, ' . $results['warning'] . ' warning, '
          . $results['failed'] . ' failed, ' . $results['rejected'] . ' rejected. '
          . number_format($results['total_records']) . ' records imported.';

// Include first few per-file messages for context
$detail = implode(' | ', array_slice($results['messages'], 0, 3));
if (count($results['messages']) > 3) $detail .= ' | +' . (count($results['messages']) - 3) . ' more';

$flash_type = ($results['failed'] + $results['rejected'] > 0) ? 'error'
            : ($results['warning'] > 0 ? 'success' : 'success');
redirect_back($flash_type, $headline . ' ' . $detail);

// ════════════════════════════════════════════════════════════
// Process a single uploaded file. Isolated so it can be called
// in a loop for multi-file uploads. Returns:
//   ['status' => ok|warning|failed|rejected, 'record_count' => int,
//    'validation' => string]
// ════════════════════════════════════════════════════════════
function process_one_upload($db, $file, $file_type, $report_type, $source_name, $period_from, $period_to, $uid) {
    $orig_name = basename($file['name']);
    $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = array(
            UPLOAD_ERR_INI_SIZE   => 'exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE  => 'exceeds form size limit',
            UPLOAD_ERR_PARTIAL    => 'partial upload',
            UPLOAD_ERR_NO_FILE    => 'no file',
            UPLOAD_ERR_NO_TMP_DIR => 'missing tmp folder',
            UPLOAD_ERR_CANT_WRITE => 'failed to write',
        );
        $msg = isset($upload_errors[$file['error']]) ? $upload_errors[$file['error']] : 'upload error';
        return array('status' => 'failed', 'record_count' => 0, 'validation' => $msg);
    }

    if (!in_array($ext, array('csv', 'xlsx', 'xls', 'pdf'))) {
        return array('status' => 'rejected', 'record_count' => 0, 'validation' => 'unsupported extension');
    }
    if ($file['size'] > 20 * 1024 * 1024) {
        return array('status' => 'rejected', 'record_count' => 0, 'validation' => 'exceeds 20 MB limit');
    }

    // Fix 5: MIME validation — verify actual content, not just extension.
    // Blocks renamed PHP/JS/HTML files from being processed.
    $safe_mimes = array(
        'text/plain', 'text/csv', 'text/comma-separated-values',
        'application/csv', 'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip', 'application/octet-stream',
        'application/pdf', 'application/xml', 'text/xml', 'text/html',
        'message/rfc822', // MHTML exports
    );
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected_mime = $finfo->file($file['tmp_name']);
    if ($detected_mime && !in_array($detected_mime, $safe_mimes)) {
        return array('status' => 'rejected', 'record_count' => 0,
            'validation' => "blocked: detected MIME '$detected_mime' is not an allowed data format");
    }

    // SHA-256 dedup
    $file_hash = hash_file('sha256', $file['tmp_name']);
    $dup_stmt  = $db->prepare(
        "SELECT id, filename FROM upload_history
         WHERE file_hash = ? AND upload_status IN ('ok','warning') LIMIT 1"
    );
    $dup_stmt->bind_param('s', $file_hash);
    $dup_stmt->execute();
    $dup = $dup_stmt->get_result()->fetch_assoc();
    $dup_stmt->close();
    if ($dup) {
        return array(
            'status'       => 'rejected',
            'record_count' => 0,
            'validation'   => "duplicate of '" . $dup['filename'] . "' (upload #" . $dup['id'] . ")",
        );
    }

    // Parse straight from PHP's tmp upload buffer — we never copy the
    // file into the project. Once this request ends PHP auto-deletes
    // the tmp file. Only the parsed sales/receipts rows persist, plus
    // the upload_history audit row. file_path stays NULL.
    $src_path = $file['tmp_name'];

    // Log as processing
    $file_path_null = null;
    $stmt = $db->prepare(
        "INSERT INTO upload_history
         (filename, file_type, report_type, source_name, period_from, period_to,
          uploaded_by, validation_msg, upload_status, file_path, file_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'Processing...', 'processing', ?, ?)"
    );
    $stmt->bind_param('ssssssiss',
        $orig_name, $file_type, $report_type, $source_name,
        $period_from, $period_to, $uid, $file_path_null, $file_hash
    );
    $stmt->execute();
    $upload_id = (int)$stmt->insert_id;
    $stmt->close();

    // Parse + insert in a transaction
    $record_count = 0;
    $validation   = '';
    $status       = 'ok';

    $db->begin_transaction();
    try {
        $rows = load_rows_from_file($src_path, $file_type, $ext);

        if ($file_type === 'Sales') {
            $result = insert_sales_rows($db, $rows, $upload_id, $source_name, $period_from);
        } else {
            $result = insert_receipts_rows($db, $rows, $upload_id, $source_name, $period_from);
        }
        $record_count = $result[0];
        $errors       = $result[1];
        $duplicates   = $result[2];
        $notes        = isset($result[3]) ? $result[3] : array();

        $db->commit();

        $parts = array($record_count . ' imported');
        if ($duplicates > 0) $parts[] = $duplicates . ' dup';
        if (!empty($errors)) $parts[] = count($errors) . ' errors';
        $validation = implode(', ', $parts);
        if (!empty($errors)) {
            // Show the first few specific errors so users can diagnose
            $validation .= '. First: ' . implode(' | ', array_slice($errors, 0, 3));
        }
        if (!empty($notes)) {
            $validation .= ' (' . implode(', ', $notes) . ')';
        }
        $status = empty($errors) ? 'ok' : 'warning';
        if ($record_count === 0 && !empty($errors)) $status = 'failed';
    } catch (Exception $e) {
        $db->rollback();
        $status       = 'failed';
        $validation   = 'rolled back: ' . substr($e->getMessage(), 0, 100);
        $record_count = 0;
    }

    // Auto-detect POS terminals from uploaded data. Creates new
    // pos_terminals rows for any terminal_id not yet in the table,
    // derives the bank from the ID prefix, and links to the most
    // common agent. Also refreshes last_txn_at for existing terminals.
    if ($status !== 'failed') {
        // Which table to scan depends on file type
        $tbl = ($file_type === 'Sales') ? 'sales' : 'receipts';
        $date_col = 'txn_date';

        // Find terminal_ids in this upload that don't exist in pos_terminals yet
        $new_terms = $db->query("
            SELECT t.terminal_id, MAX(t.$date_col) AS last_txn
            FROM $tbl t
            WHERE t.upload_id = $upload_id
              AND t.terminal_id IS NOT NULL AND t.terminal_id <> ''
              AND NOT EXISTS (SELECT 1 FROM pos_terminals pt WHERE pt.terminal_id = t.terminal_id)
            GROUP BY t.terminal_id
        ")->fetch_all(MYSQLI_ASSOC);

        if (!empty($new_terms)) {
            $ins_pt = $db->prepare("
                INSERT IGNORE INTO pos_terminals (terminal_id, merchant_name, agent_id, bank_name, location, currency, last_txn_at)
                VALUES (?, ?, ?, ?, ?, 'ZWG', ?)
            ");
            foreach ($new_terms as $nt) {
                $tid = $nt['terminal_id'];
                // Derive bank from prefix
                $bank = 'Unknown';
                if (stripos($tid, 'CBZ') === 0)  $bank = 'CBZ Bank';
                elseif (stripos($tid, 'STAN') === 0) $bank = 'Stanbic Zimbabwe';
                elseif (stripos($tid, 'FBC') === 0)  $bank = 'FBC Bank';
                elseif (stripos($tid, 'ZB') === 0)   $bank = 'ZB Bank';
                elseif (stripos($tid, 'STW') === 0)  $bank = 'Steward Bank';
                elseif (stripos($tid, 'NMB') === 0)  $bank = 'NMB Bank';
                elseif (stripos($tid, 'NBS') === 0)  $bank = 'NBS Bank';

                // Find the most common agent_id for this terminal in the uploaded data
                $agent_id = 0;
                if ($file_type === 'Sales') {
                    $a_row = $db->query("SELECT agent_id, COUNT(*) c FROM sales WHERE upload_id=$upload_id AND terminal_id='" . $db->real_escape_string($tid) . "' GROUP BY agent_id ORDER BY c DESC LIMIT 1")->fetch_assoc();
                    if ($a_row) $agent_id = (int)$a_row['agent_id'];
                }
                // For receipts, try to find agent via matched sales
                if ($agent_id === 0) {
                    $a_row = $db->query("SELECT s.agent_id, COUNT(*) c FROM receipts r JOIN sales s ON r.matched_sale_id=s.id WHERE r.terminal_id='" . $db->real_escape_string($tid) . "' GROUP BY s.agent_id ORDER BY c DESC LIMIT 1")->fetch_assoc();
                    if ($a_row) $agent_id = (int)$a_row['agent_id'];
                }
                // Fallback: create a placeholder agent if none found
                if ($agent_id === 0) {
                    $code = 'TERM-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $tid), 0, 10));
                    $db->query("INSERT IGNORE INTO agents (agent_code, agent_name, agent_type, region, currency) VALUES ('" . $db->real_escape_string($code) . "', 'Terminal $tid', 'POS', '$bank', 'ZWG')");
                    $agent_id = (int)$db->insert_id;
                    if ($agent_id === 0) {
                        $agent_id = (int)$db->query("SELECT id FROM agents WHERE agent_code='" . $db->real_escape_string($code) . "' LIMIT 1")->fetch_assoc()['id'];
                    }
                }

                $merchant = 'Terminal ' . $tid;
                // Try to use agent name as merchant
                $ag_name = $db->query("SELECT agent_name FROM agents WHERE id=$agent_id")->fetch_assoc();
                if ($ag_name) $merchant = $ag_name['agent_name'];

                $ins_pt->bind_param('ssisss', $tid, $merchant, $agent_id, $bank, $merchant, $nt['last_txn']);
                $ins_pt->execute();
            }
            $ins_pt->close();
        }

        // Update last_txn_at for existing terminals
        $db->query("
            UPDATE pos_terminals pt
            JOIN (
              SELECT terminal_id, MAX($date_col) md
              FROM $tbl
              WHERE upload_id = $upload_id AND terminal_id IS NOT NULL AND terminal_id <> ''
              GROUP BY terminal_id
            ) r ON r.terminal_id = pt.terminal_id
            SET pt.last_txn_at = GREATEST(COALESCE(pt.last_txn_at, '1970-01-01'), r.md)
        ");
    }

    // Finalize upload_history row
    $validation_trimmed = substr($validation, 0, 255);
    $upd = $db->prepare(
        "UPDATE upload_history SET record_count=?, validation_msg=?, upload_status=? WHERE id=?"
    );
    $upd->bind_param('issi', $record_count, $validation_trimmed, $status, $upload_id);
    $upd->execute();
    $upd->close();

    return array(
        'status'       => $status,
        'record_count' => $record_count,
        'validation'   => $validation,
    );
}

// All data-ingestion helpers (row readers, column maps, normalizers,
// agent resolution, insert_sales_rows, insert_receipts_rows) live in
// core/ingestion.php and are included at the top of this file.

function redirect_back($type, $msg)
{
    header('Location: ' . BASE_URL . '/utilities/upload.php?' . $type . '=' . urlencode($msg));
    exit;
}
