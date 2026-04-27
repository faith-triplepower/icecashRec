<?php
// ============================================================
// core/ingestion.php — Data Ingestion Engine
// Parses CSV, XLS, XLSX (including mislabeled HTML/XML), and
// PDF files into normalized row arrays. Handles messy real-world
// headers from Icecash, banks (CBZ, Stanbic, NMB, FBC, NBS),
// EcoCash, and brokers via flexible alias maps + fuzzy matching.
//
// Key components:
//   Column alias maps  — sales_column_map(), receipts_column_map()
//   pick()             — flexible column value lookup with fuzzy fallback
//   Row readers        — read_csv_rows(), read_xls_rows(), read_xlsx_rows()
//   Smart header scan  — finds the real header row in bank statements
//   Agent resolution   — auto-creates agents from unknown names
//   Inserters          — insert_sales_rows(), insert_receipts_rows()
//   Direction detect   — separate Credit/Debit columns + Cr/Dr flags
//
// Part of IceCashRec — Zimnat General Insurance
// ============================================================

// ════════════════════════════════════════════════════════════
// COLUMN ALIAS MAPS
// Every bank and system calls their columns something different.
// "Amount" in CBZ might be "Premium" in Icecash or "Total basic"
// in a broker schedule. These maps let us recognize all of them.
// To support a new file format, just add its header names here.
// ════════════════════════════════════════════════════════════

// What column names do we expect in SALES files?
if (!function_exists('sales_column_map')) {
    function sales_column_map() {
        return array(
            'policy_number'  => array('policy_number','policy_no','policy','ref','reference','certificate_no','cert_no'),
            'txn_date'       => array('txn_date','date','transaction_date','sale_date','issue_date','txn date','trans date'),
            'agent'          => array('agent_name','agent','channel','agent_code','broker','source','branch','office','outlet','location'),
            'product'        => array('product','product_type','product_name','class','cover_type'),
            'payment_method' => array('payment_method','method','payment_type','payment','channel_type','pay_method','mode_of_payment'),
            'amount'         => array('amount','total','sale_amount','premium','gross_premium','net_premium','total_amount','value','premium_collected','premium_amount','total_basic','basic','gvp','gross','commission'),
            'currency'       => array('currency','ccy','curr'),
        );
    }
}

// What column names do we expect in RECEIPT files?
// Note: credit and debit are split into separate alias groups
// so we can handle banks that have two columns (Credit Amount / Debit Amount)
// separately from those that have one Amount column + a Cr/Dr flag.
if (!function_exists('receipts_column_map')) {
    function receipts_column_map() {
        return array(
            'reference_no'  => array('reference_no','reference_number','ref','reference','transaction_ref','receipt_no','txn_ref','receipt_reference','narrative','description'),
            'txn_date'      => array('txn_date','date','transaction_date','value_date','posting_date','trans_date','posted','booking_date'),
            'terminal_id'   => array('terminal_id','terminal','pos_terminal','tid','terminal_no','pos_id'),
            'channel'       => array('channel','payment_type','payment_channel','transaction_type'),
            'source_name'   => array('source_name','bank','institution','agent','from','counterparty','sender','description','narrative','office','branch','outlet','broker'),
            // Credit-specific aliases FIRST so "Credit Amount" wins over "Debit Amount"
            'credit_amount' => array('credit_amount','credit','credits','credited','cr_amount','cr'),
            'debit_amount'  => array('debit_amount','debit','debits','dr_amount','dr'),
            'amount'        => array('amount','total','transaction_amount','net_amount','value','total_basic','basic','gvp','gross','premium','commission'),
            // Cr/Dr direction flag column (CBZ, EcoCash, etc.)
            'direction'     => array('cr/dr','dr/cr','crdr','cr_dr','dr_cr','type','transaction_type'),
            'currency'      => array('currency','ccy','curr'),
        );
    }
}

// This is the heart of the flexible matching.
// Given a row like {'credit_amount': '500', 'debit_amount': '', ...}
// and a list of aliases like ['credit_amount', 'credit', 'cr'],
// it finds the first alias that matches a column AND has a non-empty value.
//
// Pass 1: exact column name match (fast, most common case)
// Pass 2: fuzzy match — strips everything except letters/numbers and
//          checks if the alias appears inside the column name.
//          This is how "10%_commission" matches the alias "commission".
if (!function_exists('pick')) {
    function pick(array $row, array $aliases) {
        // Try exact matches first — fastest path
        foreach ($aliases as $a) {
            if (isset($row[$a]) && trim($row[$a]) !== '') return $row[$a];
        }
        // Build normalized key map once — used for both case-insensitive exact
        // match and substring fuzzy match below. Skips empty values.
        $norm_keys = array();
        foreach ($row as $k => $v) {
            if (trim((string)$v) === '') continue;
            $nk = preg_replace('/[^a-z0-9]+/', '', strtolower((string)$k));
            if ($nk !== '') $norm_keys[$nk] = $k;
        }
        // Case-insensitive EXACT match for short aliases (covers "VRN", "ID",
        // "REF", etc. where columns are uppercase in the source file).
        foreach ($aliases as $a) {
            $na = preg_replace('/[^a-z0-9]+/', '', strtolower((string)$a));
            if ($na === '') continue;
            if (isset($norm_keys[$na]) && trim((string)$row[$norm_keys[$na]]) !== '') {
                return $row[$norm_keys[$na]];
            }
        }
        // Fuzzy substring: only for aliases 4+ chars (else "id" would match "debit_id")
        foreach ($aliases as $a) {
            $na = preg_replace('/[^a-z0-9]+/', '', strtolower((string)$a));
            if (strlen($na) < 4) continue;
            foreach ($norm_keys as $nk => $orig) {
                if (strpos($nk, $na) !== false && trim((string)$row[$orig]) !== '') {
                    return $row[$orig];
                }
            }
        }
        return '';
    }
}

// ════════════════════════════════════════════════════════════
// CSV ROW READER
// Smart header detection: bank statements often have 2–10 preamble
// rows (account number, opening balance, etc.) before the real
// column headers. We scan up to 20 lines for one that contains at
// least 2 known header keywords, treat that as the header row, and
// read data rows after it.
//
// Also handles:
//   - duplicate column names (second occurrence gets _2 suffix)
//   - BOM at start of file
// ════════════════════════════════════════════════════════════
if (!function_exists('read_csv_rows')) {
    function read_csv_rows($filepath) {
        $handle = fopen($filepath, 'r');
        if (!$handle) throw new Exception('Cannot open file');

        // Strip UTF-8 BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $keywords = array(
            'date','amount','currency','reference','description','policy',
            'agent','terminal','channel','method','credit','debit','balance',
            'transaction','narrative','receipt','ref','type','product',
            'office','branch','outlet','broker','commission','levy','stamp',
            'gross','net','basic','gvp','admin','total','premium',
        );

        // Buffer the first 25 rows and score each for header-likeness.
        // The real header is the row with the MOST keyword hits (tie-break
        // by cell count) — not just the first row with ≥2 hits. Prevents
        // bank preamble rows like "Opening Balance ... Account Number"
        // from being mistaken for the transaction header further down.
        $scan_buffer = array();
        for ($scan = 0; $scan < 25; $scan++) {
            $row = fgetcsv($handle);
            if ($row === false) break;
            $scan_buffer[] = $row;
        }

        $headers = null;
        $header_idx = -1;
        $best_hits = 0;
        $best_cells = 0;
        foreach ($scan_buffer as $idx => $row) {
            $non_empty = array_filter($row, function($v) { return trim($v) !== ''; });
            if (empty($non_empty)) continue;
            $cell_count = count($non_empty);
            $hits = 0;
            foreach ($row as $cell) {
                $c = strtolower(trim($cell));
                if ($c === '') continue;
                $clean = preg_replace('/[^a-z]+/', '', $c);
                foreach ($keywords as $kw) {
                    if ($clean === $kw || strpos($clean, $kw) !== false) {
                        $hits++;
                        break;
                    }
                }
            }
            if ($hits < 2) continue;
            if ($hits > $best_hits || ($hits === $best_hits && $cell_count > $best_cells)) {
                $best_hits = $hits;
                $best_cells = $cell_count;
                $headers = $row;
                $header_idx = $idx;
            }
        }

        if ($headers === null) {
            // No header detected — fall back to the first non-empty row
            // in the buffer so we don't silently fail on files that use
            // unusual header names.
            foreach ($scan_buffer as $idx => $row) {
                if (!empty(array_filter($row, function($v) { return trim($v) !== ''; }))) {
                    $headers = $row;
                    $header_idx = $idx;
                    break;
                }
            }
            if ($headers === null) {
                fclose($handle);
                throw new Exception('Empty file or no recognisable header row');
            }
        }

        // Rows in the scan buffer AFTER the chosen header are real data
        // and must be replayed before we continue streaming from the file.
        $replay_rows = array_slice($scan_buffer, $header_idx + 1);

        // Normalize headers: lowercase, trim, and dedupe duplicates
        $norm_headers = array();
        $seen = array();
        foreach ($headers as $h) {
            $key = strtolower(trim($h));
            $key = preg_replace('/\s+/', '_', $key);
            if (isset($seen[$key])) {
                $seen[$key]++;
                $key .= '_' . $seen[$key];
            } else {
                $seen[$key] = 1;
            }
            $norm_headers[] = $key;
        }

        $rows = array();
        // Replay buffered rows that were scanned for header detection but
        // sit AFTER the chosen header row (they're real data), then stream
        // the remainder of the file.
        $replay_idx = 0;
        while (true) {
            if ($replay_idx < count($replay_rows)) {
                $row = $replay_rows[$replay_idx++];
            } else {
                $row = fgetcsv($handle);
                if ($row === false) break;
            }
            $non_empty = array_filter($row, function($v) { return trim($v) !== ''; });
            if (empty($non_empty)) continue;

            $assoc = array();
            foreach ($norm_headers as $i => $h) {
                $assoc[$h] = isset($row[$i]) ? trim($row[$i]) : '';
            }

            // Direction detection (Cr/Dr) is now handled in insert_receipts_rows
            // so both credits AND debits are preserved for float reconciliation.
            $rows[] = $assoc;
        }
        fclose($handle);
        return $rows;
    }
}

// ════════════════════════════════════════════════════════════
// PDF ROW READER
// Uses pdftotext (from Poppler / XPDF / Git for Windows) to extract
// text preserving layout, then heuristically parses each line that
// contains both a date token and an amount token. Best-effort for
// common finance PDF layouts (Icecash, bank statements).
//
// Looks for pdftotext at:
//   1. system_settings.pdftotext_path (admin-configurable)
//   2. common default paths
//   3. `pdftotext` on PATH
// ════════════════════════════════════════════════════════════
if (!function_exists('find_pdftotext')) {
    function find_pdftotext() {
        global $_INGESTION_PDFTOTEXT;
        if (isset($_INGESTION_PDFTOTEXT)) return $_INGESTION_PDFTOTEXT;

        $candidates = array();

        // Admin-configured path from system_settings
        if (function_exists('get_db')) {
            $db = get_db();
            $row = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='pdftotext_path'")->fetch_assoc();
            if ($row && !empty($row['setting_value'])) $candidates[] = $row['setting_value'];
        }

        // Common defaults on Windows / XAMPP
        $candidates[] = 'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe';
        $candidates[] = 'C:\\Program Files (x86)\\Git\\mingw64\\bin\\pdftotext.exe';
        $candidates[] = 'C:\\xampp\\apps\\pdftotext.exe';
        $candidates[] = 'pdftotext.exe';
        $candidates[] = 'pdftotext';

        foreach ($candidates as $c) {
            if (is_executable($c)) { $_INGESTION_PDFTOTEXT = $c; return $c; }
            // Also try it on PATH by running a version check
            $out = @shell_exec(escapeshellarg($c) . ' -v 2>&1');
            if ($out && stripos($out, 'pdftotext') !== false) {
                $_INGESTION_PDFTOTEXT = $c;
                return $c;
            }
        }
        $_INGESTION_PDFTOTEXT = false;
        return false;
    }
}

// Run pdftotext and return raw text. Shared by both PDF parsing strategies.
if (!function_exists('_ingestion_pdftotext_run')) {
    function _ingestion_pdftotext_run($filepath) {
        $bin = find_pdftotext();
        if (!$bin) {
            throw new Exception('pdftotext not available — install Poppler or Git for Windows, or set pdftotext_path in system settings');
        }
        $cmd = escapeshellarg($bin) . ' -layout -nopgbrk ' . escapeshellarg($filepath) . ' -';
        $text = @shell_exec($cmd . ' 2>&1');
        if (!$text || stripos($text, 'error') === 0) {
            throw new Exception('pdftotext failed: ' . substr(trim($text ?: 'no output'), 0, 200));
        }
        return $text;
    }
}

// ════════════════════════════════════════════════════════════
// PDF ROW READER — multi-strategy
// Runs three parsers in parallel:
//   - multi-line  : strict "S.No. + date" anchored format (Ecocash,
//                   Stanbic, etc.). Highest precision when it works.
//   - universal   : permissive — any line with a date+amount is a
//                   transaction candidate, with date-less follow-up
//                   lines treated as continuation narration. Handles
//                   most retail bank statements (Ecobank, CBZ, FBC,
//                   Stanbic non-Icecash format, etc.).
//   - simple      : original single-line parser, used as a safety net.
//
// Whichever returns the most rows wins. Image-only PDFs (scanned
// statements) are detected up front and rejected with a clear message
// since pdftotext can't extract anything useful from them.
// ════════════════════════════════════════════════════════════
if (!function_exists('read_pdf_rows')) {
    function read_pdf_rows($filepath, $file_type) {
        $text = _ingestion_pdftotext_run($filepath);

        // Image-based / scanned PDF detection. pdftotext returns close to
        // nothing (form-feeds, page numbers) for these — no point running
        // the row parsers, just tell the user so they can convert it.
        $stripped = trim(preg_replace('/[\s\f]+/', ' ', $text));
        if (strlen($stripped) < 80) {
            throw new Exception('PDF appears to be scanned / image-based — pdftotext extracted no usable text. OCR the PDF first (e.g. with Adobe Acrobat or tesseract) and re-upload.');
        }

        // Run every strategy and take the best. None of them throw
        // mid-run — they each return whatever they could find.
        $candidates = array(
            'columnar'  => _parse_pdf_columnar_balance($text, $file_type),
            'multiline' => _parse_pdf_multiline($text, $file_type),
            'universal' => _parse_pdf_universal($text, $file_type),
            'simple'    => _parse_pdf_simple($text, $file_type),
        );
        $best = array(); $best_name = '';
        foreach ($candidates as $name => $rows) {
            if (count($rows) > count($best)) { $best = $rows; $best_name = $name; }
        }
        if (!empty($best)) {
            // Drop a breadcrumb in the PHP error log so admins can tell
            // which strategy did the work (helps diagnose drift on new
            // bank formats over time).
            error_log("PDF parse: $filepath — used $best_name strategy, " . count($best) . " rows");
            return $best;
        }

        // All strategies failed — dump pdftotext output so the operator
        // can share it for debugging. Try project /uploads first; fall
        // back to the system temp dir if Apache can't write there
        // (common on Windows when the web user lacks project-folder
        // write perms).
        $dump_path  = null;
        $candidates = array(
            dirname(__DIR__) . '/uploads',
            dirname(__DIR__) . '/exports',
            sys_get_temp_dir(),
        );
        $stem = 'pdf_parse_failed_' . date('Ymd_His') . '_' . basename($filepath) . '.txt';
        foreach ($candidates as $dir) {
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            if (!is_dir($dir) || !is_writable($dir)) continue;
            $try = $dir . DIRECTORY_SEPARATOR . $stem;
            if (@file_put_contents($try, $text) !== false) { $dump_path = $try; break; }
        }
        if ($dump_path) {
            error_log('PDF parse failed — dump written to ' . $dump_path);
            $hint = ' Diagnostic text saved to ' . $dump_path . '.';
        } else {
            error_log('PDF parse failed — could NOT write dump (no writable directory found)');
            $hint = ' (Could not write diagnostic dump; check folder permissions.)';
        }
        throw new Exception('PDF parsed but no data rows detected. Verify the PDF contains a tabular statement with dates and amounts.' . $hint);
    }
}

// ════════════════════════════════════════════════════════════
// COLUMNAR PDF PARSER
//
// For multi-column statements where pdftotext extracts each column
// as its own block, NOT in transaction order. Ecobank Zimbabwe is
// the canonical example: each page contains 8 transactions and the
// raw text comes out as
//
//     [posting dates × 8]
//     [account header info]
//     [narrations × 8 — variable line count, separated by R-prefix]
//     [value dates × 8]
//     [financial summary]
//     [S.No. × 8]
//     [running balances × 8]
//     [amount values — split into debit-then-credit, NOT row order]
//
// Pairing amounts to rows by position breaks because debits and
// credits live in separate columns. Running balances, by contrast,
// are ALWAYS in row order — so we compute each transaction's
// amount and direction from balance_n − balance_{n-1} and ignore
// the listed amounts entirely. Seeded by Opening Book Balance
// (extracted from the first page), this delta approach reconstructs
// every transaction exactly.
// ════════════════════════════════════════════════════════════
if (!function_exists('_parse_pdf_columnar_balance')) {
    function _parse_pdf_columnar_balance($text, $file_type) {
        // Cheap guards — only run this strategy when the layout signals
        // are present. Other PDFs would just produce noise.
        if (stripos($text, 'Running Balance') === false) return array();
        if (stripos($text, 'S.No') === false && stripos($text, 'S No') === false) return array();

        // Seed the running-balance chain from the statement header.
        // Direct label-to-value regex first — works on layouts where
        // pdftotext keeps the label on the same logical line as the value.
        $opening = null;
        if (preg_match('/Opening\s+Book\s+Balance\s*:?\s*(?:[A-Z]{2,4}\s*)?([\d,]+\.\d{2})/i', $text, $m)) {
            $opening = (float)str_replace(',', '', $m[1]);
        } elseif (preg_match('/Opening\s+(?:Available\s+)?Balance\s*:?\s*(?:[A-Z]{2,4}\s*)?([\d,]+\.\d{2})/i', $text, $m)) {
            $opening = (float)str_replace(',', '', $m[1]);
        }
        // Fallback for the Ecobank-style column-block layout, where the
        // labels and values sit in separate blocks: collect every
        // currency-prefixed value in the summary area and pick the one
        // that, when subtracted from the first running balance, equals
        // an entry in the amount block. That's the opening balance by
        // construction.
        if ($opening === null) {
            $opening = _columnar_solve_opening($text);
        }

        $default_currency = 'ZWG';
        if (preg_match('/Account\s+Currency\s*:?\s*(USD|ZWG)/i', $text, $m)) {
            $default_currency = strtoupper($m[1]);
        }

        // Split into pages — pdftotext emits a form-feed between pages
        // by default, and the "Page X of Y" footer is also reliable.
        $pages = preg_split('/\f/', $text);
        if (count($pages) === 1) {
            $pages = preg_split('/(?=\bPage\s+\d+\s+of\s+\d+\b)/', $text);
        }

        $rows = array();
        $prev_balance = $opening;

        foreach ($pages as $page) {
            $sno_block      = _columnar_extract_sno_block($page);
            if (count($sno_block) < 1) continue;
            $balance_block  = _columnar_extract_balance_block($page);
            if (count($balance_block) < count($sno_block)) continue;

            $count = count($sno_block);
            $balances   = array_slice($balance_block, 0, $count);
            $narrations = _columnar_extract_narrations($page, $count);
            $dates      = _columnar_extract_dates($page, $count);

            for ($i = 0; $i < $count; $i++) {
                $bal = (float)$balances[$i];
                $is_credit = true;
                $amount    = 0.0;

                if ($prev_balance !== null) {
                    $delta = $bal - $prev_balance;
                    $amount = abs($delta);
                    $is_credit = $delta > 0;
                }
                $prev_balance = $bal;

                // amount=0 = drop from receipts, mirrors existing parsers
                $amount_val = $is_credit ? $amount : 0;
                $date       = isset($dates[$i])      ? $dates[$i]      : '';
                $narration  = isset($narrations[$i]) ? $narrations[$i] : '';
                $ref        = _columnar_extract_ref($narration);
                $nar_short  = substr(trim(preg_replace('/\s+/', ' ', $narration)), 0, 200);

                if ($file_type === 'Sales') {
                    $rows[] = array(
                        'policy_number'  => $ref,
                        'txn_date'       => $date,
                        'agent'          => $nar_short,
                        'product'        => '',
                        'payment_method' => '',
                        'amount'         => $amount_val,
                        'currency'       => $default_currency,
                    );
                } else {
                    $rows[] = array(
                        'reference_no' => $ref,
                        'txn_date'     => $date,
                        'terminal_id'  => '',
                        'channel'      => '',
                        'source_name'  => $nar_short,
                        'amount'       => $amount_val,
                        'currency'     => $default_currency,
                    );
                }
            }
        }

        return $rows;
    }
}

// Find the S.No. block: a run of consecutive integer-only lines
// (the longest such run on the page is the S.No. column).
if (!function_exists('_columnar_extract_sno_block')) {
    function _columnar_extract_sno_block($page) {
        $lines = preg_split('/\r?\n/', $page);
        $best = array(); $cur = array();
        foreach ($lines as $raw) {
            $t = trim($raw);
            if (preg_match('/^\d{1,4}$/', $t)) {
                $cur[] = (int)$t;
            } else {
                if (count($cur) > count($best)) $best = $cur;
                $cur = array();
            }
        }
        if (count($cur) > count($best)) $best = $cur;
        // Sanity: must be at least 1 entry. Most pages have 7–9.
        return (count($best) >= 1 && count($best) <= 60) ? $best : array();
    }
}

// Find running-balance block: the longest run of consecutive lines
// that look like "12,345.67" (or "1.23") with NO currency prefix.
// Currency-prefixed amounts (ZWG 1,234.56) belong to the debit/credit
// column block which we explicitly want to skip.
if (!function_exists('_columnar_extract_balance_block')) {
    function _columnar_extract_balance_block($page) {
        $lines = preg_split('/\r?\n/', $page);
        $best = array(); $cur = array();
        foreach ($lines as $raw) {
            $t = trim($raw);
            if ($t === '') {
                if (count($cur) > count($best)) $best = $cur;
                $cur = array();
                continue;
            }
            // Accept "1,234.56", "1234.56", "1.23". Reject anything with letters.
            if (preg_match('/^[\d]{1,3}(?:,\d{3})*\.\d{2}$|^\d+\.\d{2}$/', $t)) {
                $cur[] = (float)str_replace(',', '', $t);
            } else {
                if (count($cur) > count($best)) $best = $cur;
                $cur = array();
            }
        }
        if (count($cur) > count($best)) $best = $cur;
        return $best;
    }
}

// Find $count posting dates per page. Dates appear in two contiguous
// runs on each page (posting dates + value dates); both have the same
// transactions in the same order, so either works. We take the first
// run with at least $count entries.
if (!function_exists('_columnar_extract_dates')) {
    function _columnar_extract_dates($page, $count) {
        $lines = preg_split('/\r?\n/', $page);
        $runs  = array();
        $cur   = array();
        foreach ($lines as $raw) {
            $t = trim($raw);
            if (preg_match('/^(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})$/', $t, $m)) {
                $cur[] = $m[1];
            } else {
                if (!empty($cur)) $runs[] = $cur;
                $cur = array();
            }
        }
        if (!empty($cur)) $runs[] = $cur;

        // Prefer a run of exactly $count; otherwise the first run with >= $count.
        foreach ($runs as $r) if (count($r) === $count) return $r;
        foreach ($runs as $r) if (count($r) >= $count)  return array_slice($r, 0, $count);
        return array_fill(0, $count, '');
    }
}

// Split the narration block into $count parts. Each transaction's
// narration starts with an R-prefix reference (R30xxx, R02xxx, R05xxx,
// R300i…, etc.) at the very beginning of a line. Lines between two
// such markers belong to the earlier transaction.
if (!function_exists('_columnar_extract_narrations')) {
    function _columnar_extract_narrations($page, $count) {
        $lines = preg_split('/\r?\n/', $page);

        // Locate the narration zone — between "Posting Date Narration"
        // header and the next pure-date block (the value dates).
        $start = -1; $end = count($lines);
        foreach ($lines as $i => $raw) {
            $t = trim($raw);
            if ($start < 0 && stripos($t, 'Posting Date') !== false && stripos($t, 'Narration') !== false) {
                $start = $i + 1;
                continue;
            }
            if ($start >= 0 && preg_match('/^\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}$/', $t)) {
                $end = $i;
                break;
            }
        }
        if ($start < 0) return array_fill(0, $count, '');

        $narrations = array();
        $current    = '';
        for ($i = $start; $i < $end; $i++) {
            $line = trim($lines[$i]);
            if ($line === '') continue;
            // Start of a new transaction reference: R<digits><letters>...
            if (preg_match('/^R\d+[A-Za-z0-9]+\d+/i', $line) || preg_match('/^R\d+RTT[FI]\d+/i', $line)) {
                if ($current !== '') $narrations[] = $current;
                $current = $line;
            } else {
                $current .= ($current === '' ? '' : ' ') . $line;
            }
        }
        if ($current !== '') $narrations[] = $current;

        // Pad/trim to $count
        while (count($narrations) < $count) $narrations[] = '';
        return array_slice($narrations, 0, $count);
    }
}

// Solve for the opening balance when the financial-summary labels are
// in a different text block than their values (Ecobank's column layout).
//
// Strategy: collect every currency-prefixed decimal in the area before
// the first running-balance block, plus the first running balance and
// every entry in the amount block. The opening balance O satisfies
//   first_balance - O = some_amount_in_block
// because O + first_amount = first_balance by definition. The candidate
// from the summary that satisfies this equation IS the opening balance.
if (!function_exists('_columnar_solve_opening')) {
    function _columnar_solve_opening($text) {
        // Take only the first page's worth of text for this — opening
        // balance is page-1-only and later pages chain off it.
        $first_page = preg_split('/\f/', $text, 2)[0];
        if (!$first_page) $first_page = $text;

        // First running-balance block on the first page.
        $balances = _columnar_extract_balance_block($first_page);
        if (empty($balances)) return null;
        $first_bal = (float)$balances[0];

        // All currency-prefixed amounts on the first page (these include
        // both the financial-summary values and the per-row amount block).
        if (!preg_match_all('/(?:ZWG|USD|Z\$|\$)\s*([\d,]+\.\d{2})/i', $first_page, $m)) return null;
        $vals = array();
        foreach ($m[1] as $v) $vals[] = (float)str_replace(',', '', $v);

        // Build a hash-set of value strings for O(1) membership tests.
        $vset = array();
        foreach ($vals as $v) $vset[(string)round($v, 2)] = true;

        // For each candidate O, check whether (first_bal - O) is in the
        // amount set. The first match is the opening balance.
        foreach ($vals as $cand) {
            $derived = round($first_bal - $cand, 2);
            if ($derived <= 0) continue;
            if (isset($vset[(string)$derived])) return $cand;
        }
        return null;
    }
}

// Pull the canonical reference token out of a narration. Preference:
//   1. R30xxx / R02xxx / R05xxx (Ecobank's transaction reference)
//   2. RRN nnnnnnnnn
//   3. Long numeric ID (10+ digits — the cross-bank reference)
if (!function_exists('_columnar_extract_ref')) {
    function _columnar_extract_ref($narration) {
        // Ecobank reference shapes seen so far:
        //   R30uctp260580003   (R + 2-digit code + lowercase + digits)
        //   R30RTTF260630226   (R + 2-digit + uppercase RTTF + digits)
        //   R300i9m260630082   (R + 3-digit + alphanumeric + digits)
        //   R30ZEXA260700045   / R30INTL260840862  / R05PAMCZWGL00001
        // One permissive pattern catches all of them.
        if (preg_match('/\b(R\d{1,3}[A-Za-z][A-Za-z0-9]{2,}\d{4,})\b/', $narration, $m)) {
            return $m[1];
        }
        if (preg_match('/RRN\s*(\d{6,})/i', $narration, $m)) {
            return 'RRN' . $m[1];
        }
        if (preg_match('/\b(\d{10,})\b/', $narration, $m)) {
            return $m[1];
        }
        return '';
    }
}

// ════════════════════════════════════════════════════════════
// UNIVERSAL PDF PARSER
//
// The other two parsers expect a specific column layout. This one
// just walks lines looking for a date plus at least one decimal
// amount, treating date-less lines that follow as continuation
// narration for the previous transaction. That covers the vast
// majority of bank statement layouts without per-bank tuning.
//
// Heuristics:
//   - date can appear anywhere on the line, in any common format
//   - if multiple decimal amounts are on the same line, the LAST
//     one is treated as the running balance and the LARGEST of
//     the remainder is the transaction amount (typical layout:
//     "<date> <desc> <amount> <balance>")
//   - currency-prefixed amounts (ZWG/USD/Z$/$) win over plain
//     numbers when both are present
//   - lines containing TOTAL, BALANCE, OPENING, CLOSING, BROUGHT
//     FORWARD, etc. are skipped — they look like transactions but
//     aren't
//   - credit vs debit is inferred from the running-balance delta
//     when balances are present; otherwise defaults to credit and
//     the existing direction-resolver elsewhere handles it
// ════════════════════════════════════════════════════════════
if (!function_exists('_parse_pdf_universal')) {
    function _parse_pdf_universal($text, $file_type) {
        $lines = preg_split('/\r?\n/', $text);
        $rows  = array();

        $date_re = '#\b(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}|\d{1,2}\s+[A-Za-z]{3,9}\s+\d{2,4}|[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{2,4})\b#';
        // Currency-prefixed amount (ZWG / USD / Z$ / $)
        $ccy_amt_re = '#(ZWG|USD|Z\$|\$)\s*([\d,]+\.\d{2})#i';
        // Plain decimal amount — requires at least 2 decimal places to
        // avoid catching dates like "31.03" or page numbers.
        $amt_re = '#(?<![\w.])([\d]{1,3}(?:,\d{3})+\.\d{2}|\d+\.\d{2})(?![\w])#';
        // Reference: long-ish alphanumeric or 8+ digit ID
        $ref_re = '#\b([A-Z]{2,}[A-Z0-9\-/]{2,}\d+|RRN\s*\d{6,}|\d{8,})\b#i';

        // Substring keywords on a line that disqualify it as a transaction
        // even if it has date+amount (e.g. "Opening Balance 2024-03-01 1,234.56").
        $skip_substrings = array(
            'TOTAL DEPOSIT','TOTAL WITHDRAWAL','TOTAL CREDITS','TOTAL DEBITS',
            'OPENING BALANCE','CLOSING BALANCE','BROUGHT FORWARD','CARRIED FORWARD',
            'STATEMENT PERIOD','STATEMENT OF ACCOUNT','APPLIED FILTERS',
            'PAGE ', 'CONTINUED', '---END', 'PLEASE EXAMINE', 'THIS IS UNAUDITED',
            'COMPANY NAME','COMPANY ADDRESS','ACCOUNT CURRENCY','ACCOUNT NAME',
            'FINANCIAL INSTITUTION', 'COLUMN NAME',
        );

        $current      = null;  // in-progress txn
        $prev_balance = null;

        foreach ($lines as $raw) {
            $line = rtrim($raw);
            if (trim($line) === '') continue;

            $upper = strtoupper($line);
            $skip = false;
            foreach ($skip_substrings as $kw) {
                if (strpos($upper, $kw) !== false) { $skip = true; break; }
            }
            if ($skip) continue;

            $has_date = (bool)preg_match($date_re, $line, $dm);

            // Pull all candidate amounts off the line.
            $ccy_amounts  = array();
            if (preg_match_all($ccy_amt_re, $line, $cm)) {
                foreach ($cm[2] as $i => $val) {
                    $ccy_amounts[] = array(
                        'ccy' => strtoupper($cm[1][$i]) === 'Z$' ? 'ZWG' : (strtoupper($cm[1][$i]) === '$' ? 'USD' : strtoupper($cm[1][$i])),
                        'val' => (float)str_replace(',', '', $val),
                    );
                }
            }
            $plain_amounts = array();
            if (preg_match_all($amt_re, $line, $am)) {
                foreach ($am[1] as $val) {
                    $f = (float)str_replace(',', '', $val);
                    if ($f > 0) $plain_amounts[] = $f;
                }
            }

            $is_anchor = $has_date && (count($ccy_amounts) > 0 || count($plain_amounts) > 0);

            if ($is_anchor) {
                // Flush any open txn before starting the next.
                if ($current) {
                    $rows[] = _finalize_universal_txn($current, $prev_balance, $file_type);
                    if ($current['balance'] !== null) $prev_balance = $current['balance'];
                }

                // Pick transaction amount + currency.
                $amount = 0; $currency = '';
                if (!empty($ccy_amounts)) {
                    $amount   = $ccy_amounts[0]['val'];
                    $currency = $ccy_amounts[0]['ccy'];
                } elseif (count($plain_amounts) >= 2) {
                    // Last is balance; pick the largest of the rest as the amount.
                    $without_last = array_slice($plain_amounts, 0, -1);
                    $amount = max($without_last);
                } else {
                    $amount = $plain_amounts[0];
                }
                if ($currency === '') $currency = 'ZWG';

                $balance = !empty($plain_amounts) ? end($plain_amounts) : null;

                // Strip the structured tokens and use the remainder as narration.
                $clean = preg_replace($date_re, ' ', $line);
                $clean = preg_replace($ccy_amt_re, ' ', $clean);
                $clean = preg_replace($amt_re, ' ', $clean);
                $ref = '';
                if (preg_match($ref_re, $clean, $rm)) $ref = $rm[1];

                $current = array(
                    'date'      => $dm[1],
                    'amount'    => $amount,
                    'currency'  => $currency,
                    'balance'   => $balance,
                    'ref'       => $ref,
                    'narration' => trim(preg_replace('/\s+/', ' ', $clean)),
                );
                continue;
            }

            // Continuation line: add to the current txn's narration.
            if ($current) {
                $clean = trim(preg_replace('/\s+/', ' ', $line));
                if (empty($current['ref']) && preg_match($ref_re, $clean, $rm)) {
                    $current['ref'] = $rm[1];
                }
                if (strlen($current['narration']) < 400) {
                    $current['narration'] .= ' ' . $clean;
                }
            }
        }

        if ($current) {
            $rows[] = _finalize_universal_txn($current, $prev_balance, $file_type);
        }

        return $rows;
    }
}

if (!function_exists('_finalize_universal_txn')) {
    function _finalize_universal_txn($txn, $prev_balance, $file_type) {
        $amount = (float)$txn['amount'];
        $is_credit = true;

        // Balance delta says debit if balance dropped by ~the amount.
        if ($prev_balance !== null && $txn['balance'] !== null && $amount > 0.01) {
            $delta = (float)$txn['balance'] - (float)$prev_balance;
            if (abs($delta + $amount) < 0.05 && abs($delta - $amount) >= 0.05) {
                $is_credit = false;
            }
        }

        $currency = $txn['currency'] ?: 'ZWG';
        if ($currency !== 'ZWG' && $currency !== 'USD') $currency = 'ZWG';
        $amount_val = $is_credit ? $amount : 0;
        $nar = substr(trim($txn['narration']), 0, 200);

        if ($file_type === 'Sales') {
            return array(
                'policy_number'  => $txn['ref'],
                'txn_date'       => $txn['date'],
                'agent'          => $nar,
                'product'        => '',
                'payment_method' => '',
                'amount'         => $amount_val,
                'currency'       => $currency,
            );
        }
        return array(
            'reference_no' => $txn['ref'],
            'txn_date'     => $txn['date'],
            'terminal_id'  => '',
            'channel'      => '',
            'source_name'  => $nar,
            'amount'       => $amount_val,
            'currency'     => $currency,
        );
    }
}

// ── Multi-line statement parser ─────────────────────────────
// Looks for transaction "anchors" — lines starting with an integer
// (S.No.) followed by a date. Subsequent non-anchor lines are
// treated as continuation narration for the previous anchor.
//
// Extracts S.No., posting date, ZWG amount, running balance.
// Credit vs debit is determined by comparing the running balance
// to the previous transaction's balance: delta > 0 → credit.
if (!function_exists('_parse_pdf_multiline')) {
    function _parse_pdf_multiline($text, $file_type) {
        $lines = preg_split('/\r?\n/', $text);
        $rows = array();

        // Anchor: starts with S.No., then a date (DD/MM/YYYY or DD-MM-YYYY)
        $anchor_re = '#^\s*(\d{1,4})\s+(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})\b#';
        // Amount with ZWG/USD prefix
        $ccy_amt_re  = '#(ZWG|USD)\s+([\d,]+\.\d{2})#';
        // Plain number (running balance candidate)
        $num_re      = '#\b([\d,]+\.\d{2})\b#';
        // Reference token (e.g. R30uctp260580003, R30RTTF260630226, RRN 000323538487)
        $ref_re      = '#\b(R\d{2}[A-Za-z]{3,}\d+|RRN\s*\d{6,}|\d{9,})\b#';

        // Noise patterns to ignore (repeating page headers / metadata)
        $noise_patterns = array(
            '#^Statement of Account#i',
            '#^Applied Filters#i',
            '#^Company Name\b#i',
            '#^Company Address\b#i',
            '#^Account\b#i',
            '#^Account Name\b#i',
            '#^Account Currency\b#i',
            '#^Total Withdrawals\b#i',
            '#^Total Deposit\b#i',
            '#^Opening\b#i',
            '#^Closing\b#i',
            '#^Financial Institution\b#i',
            '#^Statement Period\b#i',
            '#^Column Name\b#i',
            '#^S\.No\.?\s+Posting\b#i',
            '#^S\.No\.?\s+Value\b#i',
            '#^Posting Date\b#i',
            '#^Page \d+\s*of\s*\d+#i',
            '#^This is unaudited\b#i',
            '#^---End of Report---#i',
            '#^Please examine\b#i',
            '#BETWEEN\s+\d{2}/\d{2}/\d{4}#i',
        );

        $current = null; // in-progress transaction
        $prev_balance = null;

        foreach ($lines as $raw) {
            $line = rtrim($raw);
            if ($line === '' || trim($line) === '') continue;

            // Skip noise lines
            foreach ($noise_patterns as $p) {
                if (preg_match($p, trim($line))) continue 2;
            }

            // Anchor line?
            if (preg_match($anchor_re, $line, $am)) {
                $pdate = $am[2];

                // Flush the previous transaction
                if ($current) {
                    $rows[] = _finalize_multiline_txn($current, $prev_balance, $file_type);
                    if ($current['balance'] !== null) $prev_balance = $current['balance'];
                }

                // Extract all ZWG amounts and plain numbers from the anchor line
                $ccy_amounts = array();
                if (preg_match_all($ccy_amt_re, $line, $m2)) {
                    foreach ($m2[2] as $i => $val) {
                        $ccy_amounts[] = array('ccy' => $m2[1][$i], 'val' => (float)str_replace(',', '', $val));
                    }
                }
                // The last plain number (no ZWG prefix) is the running balance
                $all_nums = array();
                if (preg_match_all($num_re, $line, $m3)) {
                    foreach ($m3[1] as $val) {
                        $all_nums[] = (float)str_replace(',', '', $val);
                    }
                }
                // Remove the ones that are the ZWG amounts (they're counted twice)
                $balance = null;
                if (!empty($all_nums)) {
                    $ccy_vals = array_map(function($a){return $a['val'];}, $ccy_amounts);
                    $balance_candidates = array_values(array_filter($all_nums, function($n) use ($ccy_vals) {
                        return !in_array($n, $ccy_vals);
                    }));
                    if (!empty($balance_candidates)) {
                        $balance = end($balance_candidates);
                    } else {
                        // Fall back to the largest number
                        $balance = max($all_nums);
                    }
                }

                // Extract reference from the line (strip date, amounts)
                $clean = $line;
                $clean = preg_replace($anchor_re, '', $clean, 1);
                $clean = preg_replace('#\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}#', '', $clean);
                $clean = preg_replace($ccy_amt_re, '', $clean);
                $clean = preg_replace('#[\d,]+\.\d{2}#', '', $clean);
                $ref = '';
                if (preg_match($ref_re, $clean, $rm)) $ref = $rm[1];

                $current = array(
                    'date'        => $pdate,
                    'ccy_amounts' => $ccy_amounts,
                    'balance'     => $balance,
                    'ref'         => $ref,
                    'narration'   => trim(preg_replace('/\s+/', ' ', $clean)),
                );
                continue;
            }

            // Continuation line: append to the current transaction's narration
            if ($current) {
                $clean = trim(preg_replace('/\s+/', ' ', $line));
                // Capture any RRN or reference on this line
                if (empty($current['ref']) && preg_match($ref_re, $clean, $rm)) {
                    $current['ref'] = $rm[1];
                }
                $current['narration'] .= ' ' . $clean;
            }
        }

        // Flush the last transaction
        if ($current) {
            $rows[] = _finalize_multiline_txn($current, $prev_balance, $file_type);
        }

        return $rows;
    }
}

// Convert a parsed multi-line transaction to the standard row format.
// Determines credit/debit via running balance delta; defaults to credit
// (treating the ZWG amount as the inflow) if no previous balance is known.
if (!function_exists('_finalize_multiline_txn')) {
    function _finalize_multiline_txn($txn, $prev_balance, $file_type) {
        $amount = 0;
        $is_credit = true;

        if (!empty($txn['ccy_amounts'])) {
            $amount = $txn['ccy_amounts'][0]['val'];
        }

        // Determine credit vs debit from balance delta
        if ($prev_balance !== null && $txn['balance'] !== null && $amount > 0.01) {
            $delta = $txn['balance'] - $prev_balance;
            // Small rounding tolerance
            if (abs($delta + $amount) < 0.02 && abs($delta - $amount) >= 0.02) {
                // Balance went DOWN by amount → it was a debit
                $is_credit = false;
            }
        }

        $currency = !empty($txn['ccy_amounts']) ? $txn['ccy_amounts'][0]['ccy'] : 'ZWG';
        if ($currency !== 'ZWG' && $currency !== 'USD') $currency = 'ZWG';

        $date = $txn['date'];
        $ref  = $txn['ref'];
        $nar  = substr($txn['narration'], 0, 200);

        // Returning a row flagged as debit — caller will treat amount=0 as
        // rejection (so debits get dropped from receipts naturally).
        $amount_val = $is_credit ? $amount : 0;

        if ($file_type === 'Sales') {
            return array(
                'policy_number'  => $ref,
                'txn_date'       => $date,
                'agent'          => $nar,
                'product'        => '',
                'payment_method' => '',
                'amount'         => $amount_val,
                'currency'       => $currency,
            );
        }
        return array(
            'reference_no' => $ref,
            'txn_date'     => $date,
            'terminal_id'  => '',
            'channel'      => '',
            'source_name'  => $nar,
            'amount'       => $amount_val,
            'currency'     => $currency,
        );
    }
}

// ── Simple one-line-per-transaction parser (original strategy) ─
if (!function_exists('_parse_pdf_simple')) {
    function _parse_pdf_simple($text, $file_type) {
        $lines = preg_split('/\r?\n/', $text);
        $rows = array();

        $date_re = '#\b(\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}|\d{1,2}\s+[A-Za-z]{3,9}\s+\d{2,4})\b#';
        $amt_re  = '#\b(?:ZWG|USD|Z\$|\$)?\s*([\d,]+\.\d{2})\b#';
        $ref_re  = '#\b([A-Z]{2,}[\-/]?\d{3,}|\d{8,})\b#';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match($date_re, $line)) continue;
            if (!preg_match($amt_re, $line, $am)) continue;

            $date = null;
            if (preg_match($date_re, $line, $dm)) $date = $dm[1];
            $amount = isset($am[1]) ? str_replace(',', '', $am[1]) : '';

            $currency = '';
            if (preg_match('/\b(ZWG|USD)\b/', $line, $cm)) $currency = $cm[1];
            elseif (strpos($line, '$') !== false) $currency = 'USD';

            $ref = '';
            if (preg_match($ref_re, $line, $rm)) $ref = $rm[1];

            $narrative = preg_replace($date_re, '', $line);
            $narrative = preg_replace('/[\d,]+\.\d{2}/', '', $narrative);
            $narrative = preg_replace('/\b(ZWG|USD)\b/', '', $narrative);
            $narrative = trim(preg_replace('/\s+/', ' ', $narrative));

            if ($file_type === 'Sales') {
                $rows[] = array(
                    'policy_number'  => $ref,
                    'txn_date'       => $date,
                    'agent'          => $narrative,
                    'product'        => '',
                    'payment_method' => '',
                    'amount'         => $amount,
                    'currency'       => $currency,
                );
            } else {
                $rows[] = array(
                    'reference_no' => $ref,
                    'txn_date'     => $date,
                    'terminal_id'  => '',
                    'channel'      => '',
                    'source_name'  => $narrative,
                    'amount'       => $amount,
                    'currency'     => $currency,
                );
            }
        }
        return $rows;
    }
}

// ════════════════════════════════════════════════════════════
// NORMALIZERS
// ════════════════════════════════════════════════════════════
if (!function_exists('normalize_date')) {
    function normalize_date($raw) {
        if (empty($raw)) return null;
        $raw = trim($raw);

        // ISO YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;

        // d/m/Y or d-m-Y (4-digit year)
        if (preg_match('#^(\d{1,2})[/\-\.](\d{1,2})[/\-\.](\d{4})$#', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        // d/m/y or d-m-y (2-digit year) — e.g. 14/03/26
        if (preg_match('#^(\d{1,2})[/\-\.](\d{1,2})[/\-\.](\d{2})$#', $raw, $m)) {
            $year = (int)$m[3];
            $year += $year < 70 ? 2000 : 1900;
            return sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
        }

        // d-MMM-yy / d-MMM-yyyy / d MMM yy / d MMM yyyy (e.g. 31-Mar-26)
        if (preg_match('#^(\d{1,2})[\s\-/]([A-Za-z]{3,9})[\s\-/](\d{2,4})$#', $raw, $m)) {
            $months = array(
                'jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
                'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12,
                'january'=>1,'february'=>2,'march'=>3,'april'=>4,'june'=>6,
                'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12,
            );
            $mon = $months[strtolower(substr($m[2], 0, 3))] ?? null;
            if ($mon) {
                $year = (int)$m[3];
                if ($year < 100) $year += $year < 70 ? 2000 : 1900;
                return sprintf('%04d-%02d-%02d', $year, $mon, (int)$m[1]);
            }
        }

        // Fallback: strtotime handles a lot of formats
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}

if (!function_exists('normalize_product')) {
    function normalize_product($raw) {
        $lower = strtolower(trim($raw));
        if (strpos($lower, 'ppa') !== false || strpos($lower, 'passenger') !== false) return 'PPA';
        return 'Zinara';
    }
}

if (!function_exists('normalize_payment_method')) {
    function normalize_payment_method($raw) {
        $lower = strtolower(trim($raw));
        if (strpos($lower, 'ecocash') !== false || strpos($lower, 'eco cash') !== false) return 'EcoCash';
        if (strpos($lower, 'zimswitch') !== false) return 'Zimswitch';
        if (strpos($lower, 'broker') !== false || strpos($lower, 'transfer') !== false || strpos($lower, 'rtgs') !== false) return 'Broker';
        if (strpos($lower, 'ipos') !== false || strpos($lower, 'i-pos') !== false || strpos($lower, 'icecash') !== false) return 'iPOS';
        if (strpos($lower, 'pos') !== false || strpos($lower, 'bank') !== false || strpos($lower, 'card') !== false) return 'Bank POS';
        return 'iPOS';
    }
}

if (!function_exists('normalize_channel')) {
    function normalize_channel($raw, $source_name) {
        $lower = strtolower(trim($raw) . ' ' . strtolower($source_name));
        if (strpos($lower, 'ecocash') !== false || strpos($lower, 'eco cash') !== false || strpos($lower, 'cassava') !== false) return 'EcoCash';
        if (strpos($lower, 'zimswitch') !== false) return 'Zimswitch';
        if (strpos($lower, 'broker') !== false || strpos($lower, 'transfer') !== false || strpos($lower, 'agent') !== false) return 'Broker';
        if (strpos($lower, 'ipos') !== false || strpos($lower, 'icecash') !== false) return 'iPOS';
        return 'Bank POS';
    }
}

if (!function_exists('normalize_source_system')) {
    function normalize_source_system($source) {
        $lower = strtolower($source);
        if (strpos($lower, 'bordeaux') !== false) return 'Bordeaux';
        if (strpos($lower, 'zinara')   !== false) return 'Zinara';
        return 'Icecash';
    }
}

// ════════════════════════════════════════════════════════════
// AGENT RESOLUTION
// ════════════════════════════════════════════════════════════
if (!function_exists('build_agent_map')) {
    function build_agent_map($db) {
        $map  = array();
        $rows = $db->query("SELECT id, agent_code, agent_name FROM agents WHERE is_active=1")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            $map[strtolower(trim($r['agent_name']))] = (int)$r['id'];
            $map[strtolower(trim($r['agent_code']))] = (int)$r['id'];
        }
        return $map;
    }
}

if (!function_exists('resolve_agent')) {
    function resolve_agent($db, array &$agent_map, $agent_raw, $fallback_source) {
        if (empty($agent_raw)) $agent_raw = $fallback_source;
        $key = strtolower(trim($agent_raw));
        if (isset($agent_map[$key])) return $agent_map[$key];

        foreach ($agent_map as $name => $id) {
            if (strlen($name) >= 4 && (strpos($key, $name) !== false || strpos($name, $key) !== false)) {
                return $id;
            }
        }

        $code = 'AGT-' . strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $agent_raw), 0, 6));
        $existing = $db->query("SELECT id FROM agents WHERE agent_code = '" . $db->real_escape_string($code) . "' LIMIT 1")->fetch_assoc();
        if ($existing) $code .= rand(10, 99);

        $name_clean = substr($agent_raw, 0, 100);
        $stmt = $db->prepare("INSERT INTO agents (agent_code, agent_name, agent_type, region, currency) VALUES (?, ?, 'Broker', 'Unknown', 'ZWG')");
        $stmt->bind_param('ss', $code, $name_clean);
        $stmt->execute();
        $new_id = (int)$stmt->insert_id;
        $stmt->close();

        $agent_map[$key] = $new_id;
        return $new_id;
    }
}

// ════════════════════════════════════════════════════════════
// UNIFIED INSERTERS
// ════════════════════════════════════════════════════════════
if (!function_exists('insert_sales_rows')) {
    // ════════════════════════════════════════════════════════════
    // FIXED insert_sales_rows — v2.0
    // Changes from original:
    //   1. abs() on amounts (IceCash statements have negative debits)
    //   2. Adds reference_no, terminal_id, txn_code to INSERT
    //   3. Detects IceCash statement format (Code column) and:
    //      - Extracts VRN from Details field as policy reference
    //      - Maps transaction code → product (ZBC/ZLC→Zinara, IPC→PPA)
    //      - Filters out fees/reversals (PZA, REV, TRA, EFT, etc.)
    //      - Uses IceCash txn ID as unique policy_number
    //   4. Fixes bind_param type from 'i' to 'd' for amount
    // ════════════════════════════════════════════════════════════
    function insert_sales_rows($db, array $rows, $upload_id, $source_name, $period_from) {
        $aliases   = sales_column_map();
        $agent_map = build_agent_map($db);

        // Detect IceCash statement format: rows have 'code' and 'details' keys
        // but no 'policy_number' or 'product' key.
        $is_icecash_statement = false;
        if (!empty($rows)) {
            $first = $rows[0];
            $keys  = array_map('strtolower', array_map('trim', array_keys($first)));
            $has_code    = in_array('code', $keys);
            $has_details = in_array('details', $keys);
            $has_balance = in_array('balance', $keys);
            $has_policy  = false;
            foreach (array('policy_number','policy_no','policy','certificate_no') as $p) {
                if (in_array($p, $keys)) { $has_policy = true; break; }
            }
            $is_icecash_statement = ($has_code && $has_details && $has_balance && !$has_policy);
        }

        // IceCash transaction codes that are actual sales (not fees/transfers)
        $sale_codes = array(
            'ZBC' => 'Zinara',   // Zinara Basic Cover
            'ZLC' => 'Zinara',   // Zinara License Cover
            'ZLA' => 'Zinara',   // Zinara License Additional
            'IPC' => 'PPA',      // Insurance Passenger Cover
            'IAA' => 'Zinara',   // Insurance Auto
            'IAL' => 'Zinara',   // Insurance Alternative
            'IAX' => 'Zinara',   // Insurance Extended
            'IGL' => 'Zinara',   // Insurance Glass
            'IGC' => 'Zinara',   // Insurance General Cover
            'IIC' => 'Zinara',   // Insurance Comprehensive
            'IBC' => 'Zinara',   // Insurance Basic Cover
            'MIB' => 'Zinara',   // Motor Insurance Bond
        );

        $stmt = $db->prepare(
            "INSERT IGNORE INTO sales
             (policy_number, reference_no, txn_date, agent_id, terminal_id,
              product, payment_method, amount, currency,
              source_system, txn_code, upload_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );

        $imported = 0; $duplicates = 0; $errors = array();
        $skipped_fees = 0;
        $row_num = 1;

        foreach ($rows as $row) {
            $row_num++;

            // ── IceCash statement format handling ────────────────
            if ($is_icecash_statement) {
                $txn_id  = isset($row['id'])           ? trim($row['id'])           : '';
                $details = isset($row['details'])      ? trim($row['details'])      : '';
                $code    = isset($row['code'])         ? strtoupper(trim($row['code'])) : '';
                $raw_date= isset($row['date'])         ? trim($row['date'])         : '';
                $raw_amt = isset($row['amount'])       ? $row['amount']             : '0';
                $location= isset($row['location'])     ? trim($row['location'])     : '';
                $account = isset($row['account_name']) ? trim($row['account_name']) : '';
                // Also try 'account name' with space (normalized header)
                if (empty($account) && isset($row['account name'])) $account = trim($row['account name']);

                // Skip non-transaction rows (Opening Balance, Closing, Totals, repeated headers)
                if (empty($txn_id) || $txn_id === 'ID' || stripos($txn_id, 'Opening') !== false
                    || stripos($txn_id, 'Closing') !== false || stripos($txn_id, 'Total') !== false) {
                    continue;
                }

                // Filter: only process sale codes, skip fees/reversals
                if (!isset($sale_codes[$code])) {
                    $skipped_fees++;
                    continue;
                }

                // Amount: take absolute value (debits are negative on IceCash)
                $amount_val = abs((float) preg_replace('/[^0-9.\-]/', '', $raw_amt));
                if ($amount_val <= 0) continue;

                // Date
                $txn_date = normalize_date($raw_date);
                if (!$txn_date && $period_from) $txn_date = $period_from;
                if (!$txn_date) continue;

                // Policy: use IceCash transaction ID (guaranteed unique)
                $txn_id_clean = preg_replace('/[^0-9]/', '', $txn_id);
                $policy = 'IC-' . $txn_id_clean;

                // VRN extraction for reference tracking
                $ref_no = null;
                if (preg_match('/VRN\s+([A-Z0-9]+)/i', $details, $m)) {
                    $ref_no = strtoupper($m[1]);
                } elseif (preg_match('/policy\s+([A-Z0-9\-]+)/i', $details, $m)) {
                    $ref_no = strtoupper($m[1]);
                }

                // Product from code
                $product_norm = $sale_codes[$code];

                // Payment method from context
                $method_norm = 'iPOS';
                $lower_det = strtolower($details);
                if (strpos($lower_det, 'ecocash') !== false) $method_norm = 'EcoCash';
                if (strpos($lower_det, 'zimswitch') !== false) $method_norm = 'Zimswitch';

                // Agent
                $agent_raw = !empty($location) ? $location : $account;
                $agent_id  = resolve_agent($db, $agent_map, $agent_raw, $source_name);

                $currency   = 'ZWG';
                $source_sys = 'Icecash';
                $term_id    = null;
                $upload_id_v = $upload_id ?: null;

                $stmt->bind_param('sssisssdsssi',
                    $policy, $ref_no, $txn_date, $agent_id, $term_id,
                    $product_norm, $method_norm, $amount_val, $currency,
                    $source_sys, $code, $upload_id_v
                );

            } else {
                // ── Standard CSV/Excel format ────────────────────
                $policy    = pick($row, $aliases['policy_number']);
                $raw_date  = pick($row, $aliases['txn_date']);
                $agent_raw = pick($row, $aliases['agent']);
                $product   = pick($row, $aliases['product']);
                $method    = pick($row, $aliases['payment_method']);
                $amount    = pick($row, $aliases['amount']);
                $currency  = strtoupper(pick($row, $aliases['currency']));

                // Try to pick reference_no and terminal_id if columns exist
                $ref_no  = pick($row, array('reference_no','payment_reference','external_ref','ecocash_ref','receipt_ref','txn_ref','transaction_reference','rrn'));
                $term_id = pick($row, array('terminal_id','terminal','pos_terminal','tid','terminal_no','pos_id'));
                $code_val = pick($row, array('code','txn_code','transaction_code'));

                // ── CAPTURE EVERY POSSIBLE IDENTIFIER into reference_no ──
                // The matcher does substring search across reference_no when scoring,
                // so packing multiple identifiers here multiplies our chance of a match
                // against arbitrary bank narrations. We try a wide net of column name
                // variants because every sales export file is different.
                $vrn          = pick($row, array('vrn','vehicle_reg','vehicle_registration','reg_no','registration','plate','plate_no','number_plate','vehicle_no'));
                $cust_phone   = pick($row, array('customer_phone','phone','msisdn','mobile','cell','contact','phone_number','customer_phone_number'));
                $cust_id      = pick($row, array('customer_id','cust_id','client_id','account','account_no','customer_number'));
                $sale_id_ext  = pick($row, array('id','sale_id','doc_no','document_no','trans_id','transaction_id','invoice_no','receipt_number'));
                $location_val = pick($row, array('location','branch','office','station','site'));
                $owner_name   = pick($row, array('owner_name','customer_name','client_name','insured_name'));

                // Build a compact identifier blob — VRN first (most likely to match
                // bank narratives), then phone, sale id, customer id, location.
                $id_parts = array();
                foreach (array($ref_no, $vrn, $sale_id_ext, $cust_phone, $cust_id, $location_val, $owner_name) as $val) {
                    $v = trim((string)$val);
                    if ($v !== '' && $v !== '0') $id_parts[] = $v;
                }
                if (!empty($id_parts)) {
                    $combined = substr(implode(' ', $id_parts), 0, 95);
                    $ref_no = $combined;
                }

                if (empty($ref_no))  $ref_no  = null;
                if (empty($term_id)) $term_id = null;
                if (empty($code_val)) $code_val = null;

                if (empty($policy)) {
                    $policy = 'AUTO-' . ($upload_id ?: 'X') . '-' . $row_num;
                }

                $txn_date = normalize_date($raw_date);
                if (!$txn_date && $period_from) $txn_date = $period_from;
                if (!$txn_date) { continue; }

                // FIXED: take absolute value so negative debits become positive
                $amount_val = abs((float) preg_replace('/[^0-9.\-]/', '', $amount));
                if ($amount_val <= 0) { continue; }

                if (!in_array($currency, array('ZWG', 'USD'))) $currency = 'ZWG';

                $product_norm = normalize_product($product);
                $method_norm  = normalize_payment_method($method);
                $agent_id     = resolve_agent($db, $agent_map, $agent_raw, $source_name);
                $source_sys   = normalize_source_system($source_name);
                $upload_id_v  = $upload_id ?: null;

                // FIXED: bind_param with 'd' for amount (was 'i' which truncated decimals)
                // FIXED: includes reference_no, terminal_id, txn_code
                $stmt->bind_param('sssisssdsssi',
                    $policy, $ref_no, $txn_date, $agent_id, $term_id,
                    $product_norm, $method_norm, $amount_val, $currency,
                    $source_sys, $code_val, $upload_id_v
                );
            }

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new Exception("Row $row_num DB error: $err");
            }
            if ($stmt->affected_rows > 0) $imported++;
            else                          $duplicates++;
        }

        $stmt->close();

        // IceCash fee/transfer skips are informational, not errors —
        // returned as a 4th element so the caller can show them as a note
        // without inflating the error count or flipping the status to warning.
        $notes = array();
        if ($is_icecash_statement && $skipped_fees > 0) {
            $notes[] = "$skipped_fees IceCash fee/transfer rows filtered";
        }

        return array($imported, $errors, $duplicates, $notes);
    }

}

if (!function_exists('insert_receipts_rows')) {
    function insert_receipts_rows($db, array $rows, $upload_id, $source_name, $period_from) {
        $aliases = receipts_column_map();

        // Credits (inflows) enter the matching queue; debits (outflows)
        // are stored with match_status='excluded' + exclude_reason='debit_outflow'
        // so they never show up in the unmatched-credits queue but remain
        // visible for full-balance reconciliation on the variance report.
        $stmt = $db->prepare(
            "INSERT IGNORE INTO receipts
             (reference_no, txn_date, terminal_id, channel, source_name, amount, currency,
              direction, match_status, exclude_reason, upload_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );

        $imported = 0; $duplicates = 0; $errors = array();
        $row_num = 1;

        foreach ($rows as $row) {
            $row_num++;
            $ref         = pick($row, $aliases['reference_no']);
            $raw_date    = pick($row, $aliases['txn_date']);
            $terminal    = pick($row, $aliases['terminal_id']);
            $channel_raw = pick($row, $aliases['channel']);
            $src         = pick($row, $aliases['source_name']);
            $currency    = strtoupper(pick($row, $aliases['currency']));

            if (empty($ref))      $ref = 'AUTO-' . ($upload_id ?: 'X') . '-' . $row_num;
            if (empty($terminal)) $terminal = null;
            if (empty($src))      $src = $source_name;

            // Skip noise rows that banks put in their statements
            // (opening/closing balances, totals, page headers, etc.)
            $ref_lower = strtolower($ref);
            if (strpos($ref_lower, 'opening') !== false || strpos($ref_lower, 'closing') !== false
                || strpos($ref_lower, 'balance') !== false || strpos($ref_lower, 'total') !== false
                || strpos($ref_lower, 'brought forward') !== false || strpos($ref_lower, 'carried forward') !== false) {
                continue;
            }

            // Extract terminal ID from narration text. Banks put it in
            // different places: CBZ puts "TID:40091364" in the description
            // column (which we store as source_name); others stamp it in
            // the reference. Search both so we populate terminal_id either way.
            if (empty($terminal)) {
                $haystack = (string)$ref . ' ' . (string)$src;
                if (preg_match('/TID[:\s]*(\d{6,})/i', $haystack, $tid_m)) {
                    $terminal = $tid_m[1];
                }
            }

            $txn_date = normalize_date($raw_date);
            if (!$txn_date && $period_from) $txn_date = $period_from;
            if (!$txn_date) { continue; }

            // ── Smart amount + direction detection ──────────────
            // Strategy: try separate Credit/Debit columns first (Stanbic,
            // NBS, NMB, First Capital). Fall back to single Amount column
            // (CBZ, EcoCash) with Cr/Dr flag detection.
            $credit_raw = pick($row, $aliases['credit_amount']);
            $debit_raw  = pick($row, $aliases['debit_amount']);
            $amount_raw = pick($row, $aliases['amount']);
            $dir_flag   = strtoupper(trim(pick($row, $aliases['direction'])));

            $credit_val = (float) preg_replace('/[^0-9.\-]/', '', $credit_raw);
            $debit_val  = (float) preg_replace('/[^0-9.\-]/', '', $debit_raw);
            $amount_single = (float) preg_replace('/[^0-9.\-]/', '', $amount_raw);

            $amount_val = 0;
            $direction  = 'credit';

            if ($credit_val > 0 && $debit_val <= 0) {
                // Separate columns: credit has value → it's a receipt
                $amount_val = $credit_val;
                $direction  = 'credit';
            } elseif ($debit_val > 0 && $credit_val <= 0) {
                // Separate columns: debit has value → it's an outflow
                $amount_val = $debit_val;
                $direction  = 'debit';
            } elseif ($amount_single != 0) {
                // Single amount column — use Cr/Dr flag if present
                $amount_val = abs($amount_single);
                if (in_array($dir_flag, array('DR','DDT','DEBIT','D'))) {
                    $direction = 'debit';
                } elseif ($amount_single < 0) {
                    $direction = 'debit';
                } else {
                    $direction = 'credit';
                }
            }

            if ($amount_val == 0.0) { continue; }

            if ($direction === 'debit') {
                $match_status  = 'excluded';
                $exclude_reason = 'debit_outflow';
            } else {
                $match_status  = 'pending';
                $exclude_reason = null;

                // Exclude non-customer receipts so they don't inflate the
                // unmatched queue. RTGS/RTTF/OMNI are intercompany transfers,
                // not customer payments; funds-sweeps and tax/admin entries
                // are bank internals. They should never try to match a sale.
                $narr = strtoupper((string)$ref . ' ' . (string)$src);
                if (strpos($narr, 'RTGS') !== false
                    || strpos($narr, 'RTTF') !== false
                    || strpos($narr, 'RTTI') !== false
                    || strpos($narr, 'OMNI') !== false
                    || strpos($narr, 'FUNDS SWEEP') !== false
                    || strpos($narr, 'TAX FREE') !== false
                    || strpos($narr, 'MAINTENANCE FEE') !== false
                    || strpos($narr, 'INTER-ACCOUNT TRANSFER') !== false
                    || strpos($narr, 'ESB_INTERNAL') !== false) {
                    $match_status  = 'excluded';
                    $exclude_reason = 'non_customer_transfer';
                }
                // Filter test/placeholder data (amount ≤ 1 with 'test' source)
                elseif ($amount_val <= 1.0 && strpos(strtolower((string)$src), 'test') !== false) {
                    $match_status  = 'excluded';
                    $exclude_reason = 'test_placeholder';
                }
            }

            if (!in_array($currency, array('ZWG', 'USD'))) $currency = 'ZWG';

            $channel_norm = normalize_channel($channel_raw, $source_name);
            $upload_id_v  = $upload_id ?: null;

            $stmt->bind_param('sssssdssssi',
                $ref, $txn_date, $terminal, $channel_norm,
                $src, $amount_val, $currency,
                $direction, $match_status, $exclude_reason,
                $upload_id_v
            );

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new Exception("Row $row_num DB error: $err");
            }
            if ($stmt->affected_rows > 0) $imported++;
            else                          $duplicates++;
        }

        $stmt->close();
        return array($imported, $errors, $duplicates);
    }
}

// ════════════════════════════════════════════════════════════
// HIGH-LEVEL: load rows from any supported file
// Returns assoc rows ready for insert_sales_rows / insert_receipts_rows
// ════════════════════════════════════════════════════════════
if (!function_exists('load_rows_from_file')) {
    function load_rows_from_file($filepath, $file_type, $ext_hint = null) {
        // Callers that stream from PHP's tmp upload buffer pass $ext_hint
        // because $filepath is something like "C:\xampp\tmp\phpXXXX.tmp".
        $ext = $ext_hint !== null
             ? strtolower($ext_hint)
             : strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return read_csv_rows($filepath);
        }
        if ($ext === 'xls') {
            // Pure-PHP BIFF8 reader — no external dependencies.
            // Handles OLE2 compound documents and the common cell
            // record types. Falls back to LibreOffice conversion
            // if the XlsReader can't parse the file for some reason.
            return read_xls_rows($filepath);
        }
        if ($ext === 'xlsx') {
            // Native xlsx reader that produces the same raw grid as
            // read_xls_rows(), then passes it through the shared
            // smart-header + alias pipeline. This avoids FileParser's
            // hardcoded English column names and its missing support
            // for <inlineStr> cells.
            return read_xlsx_rows($filepath);
        }
        if ($ext === 'pdf') {
            return read_pdf_rows($filepath, $file_type);
        }
        throw new Exception("Unsupported file extension: $ext");
    }
}

// ════════════════════════════════════════════════════════════
// XLS ROW READER — pure-PHP BIFF8 parser
// Uses utilities/XlsReader.php to parse binary .xls files
// (Excel 97-2003 format). Reshapes the raw grid into assoc
// arrays keyed by the lowercased header row, matching the
// output of read_csv_rows so downstream code is format-agnostic.
//
// If the XlsReader throws (corrupt/unsupported file), we fall
// back to LibreOffice-headless conversion if available.
// ════════════════════════════════════════════════════════════
if (!function_exists('read_xls_rows')) {
    function read_xls_rows($filepath) {
        if (!class_exists('XlsReader')) {
            require_once __DIR__ . '/../utilities/XlsReader.php';
        }
        try {
            $reader = new XlsReader($filepath);
            $raw = $reader->getFirstSheetRows();
        } catch (Exception $e) {
            // Pure-PHP parser failed — try LibreOffice auto-conversion
            // then run the converted .xlsx through our native reader.
            $converted = _convert_xls_to_xlsx($filepath);
            if ($converted && function_exists('read_xlsx_rows')) {
                try {
                    return read_xlsx_rows($converted);
                } catch (Exception $e2) {
                    // fall through to the throw below
                }
            }
            throw new Exception('Unable to parse .xls file: ' . $e->getMessage());
        }

        return _grid_to_assoc_rows($raw);
    }
}

// ════════════════════════════════════════════════════════════
// XLSX ROW READER — native Office Open XML reader
// Parses .xlsx (zip archive of XML) using PHP's built-in
// ZipArchive + SimpleXMLElement. Supports:
//   - shared strings table (xl/sharedStrings.xml)
//   - <c t="s"> shared-string cells
//   - <c t="inlineStr"> inline-string cells (<is><t>...</t></is>)
//   - <c t="str"> formula-result strings
//   - <c t="b"> boolean cells
//   - numeric cells (default type)
//   - sparse/gappy rows (r="D5" style references)
// Produces the same raw grid as read_xls_rows(), which is then
// passed through the shared smart-header + alias pipeline.
// ════════════════════════════════════════════════════════════
if (!function_exists('read_xlsx_rows')) {
    function read_xlsx_rows($filepath) {
        if (!extension_loaded('zip')) {
            throw new Exception('PHP zip extension is not enabled — cannot read .xlsx');
        }

        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            // Banks frequently mislabel files: an .xlsx that's actually an
            // old BIFF8 .xls, an HTML/MHTML export, or SpreadsheetML XML.
            // Sniff the magic bytes and route to the correct reader.
            $rerouted = _read_mislabeled_xlsx($filepath);
            if ($rerouted !== null) return $rerouted;
            throw new Exception('Cannot open .xlsx file (not a valid zip archive)');
        }

        // Discover the first worksheet by reading the workbook + rels,
        // falling back to xl/worksheets/sheet1.xml for well-behaved files.
        $sheet_path = 'xl/worksheets/sheet1.xml';
        $wb_xml = $zip->getFromName('xl/workbook.xml');
        $rels_xml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb_xml !== false && $rels_xml !== false) {
            try {
                $wb = new SimpleXMLElement($wb_xml);
                $wb_ns = $wb->getDocNamespaces(true);
                // r:id attribute lives in the relationships namespace
                $r_ns = isset($wb_ns['r']) ? $wb_ns['r'] : 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
                if (isset($wb->sheets->sheet[0])) {
                    $first_sheet = $wb->sheets->sheet[0];
                    $rid = (string)$first_sheet->attributes($r_ns)->id;
                    if ($rid !== '') {
                        $rels = new SimpleXMLElement($rels_xml);
                        foreach ($rels->Relationship as $rel) {
                            if ((string)$rel['Id'] === $rid) {
                                $target = (string)$rel['Target'];
                                // Target is relative to xl/ (e.g. "worksheets/sheet1.xml")
                                $sheet_path = (strpos($target, '/') === 0)
                                    ? ltrim($target, '/')
                                    : 'xl/' . $target;
                                break;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Fall through to the default sheet1.xml path
            }
        }

        $sheet_xml = $zip->getFromName($sheet_path);
        if ($sheet_xml === false) {
            $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        }
        if ($sheet_xml === false) {
            $zip->close();
            throw new Exception('Cannot find worksheet XML inside .xlsx');
        }

        // Shared strings table (optional — inlineStr files skip it)
        $sst = array();
        $sst_xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sst_xml !== false) {
            $sst = _xlsx_parse_shared_strings($sst_xml);
        }
        $zip->close();

        $raw = _xlsx_parse_sheet_grid($sheet_xml, $sst);
        if (empty($raw)) return array();
        return _grid_to_assoc_rows($raw);
    }
}

// Salvage a file that has an .xlsx extension but isn't a real OOXML zip.
// Returns associative rows on success, or null if we can't recognize it
// (caller should then throw the original "not a valid zip archive" error).
if (!function_exists('_read_mislabeled_xlsx')) {
    function _read_mislabeled_xlsx($filepath) {
        $fh = @fopen($filepath, 'rb');
        if (!$fh) return null;
        $head = fread($fh, 512);
        fclose($fh);
        if ($head === false || $head === '') return null;

        // OLE2 compound document → real binary .xls (BIFF)
        if (substr($head, 0, 4) === "\xD0\xCF\x11\xE0") {
            try {
                return read_xls_rows($filepath);
            } catch (Exception $e) {
                return null;
            }
        }

        // HTML / MHTML / SpreadsheetML XML — banks commonly export HTML
        // tables disguised as .xlsx. Parse them natively using DOMDocument
        // (no LibreOffice dependency). Strip BOM + leading whitespace.
        $probe = ltrim($head, "\xEF\xBB\xBF \t\r\n");
        $is_textish = (
            stripos($probe, '<?xml')         === 0 ||
            stripos($probe, '<html')         === 0 ||
            stripos($probe, '<!doctype')     === 0 ||
            stripos($probe, 'mime-version:') === 0 ||
            stripos($probe, '<workbook')     === 0 ||
            stripos($probe, '<table')        === 0
        );
        if (!$is_textish) return null;

        $raw = @file_get_contents($filepath);
        if (!$raw) return null;

        // MHTML: extract the HTML part after the MIME boundary
        if (stripos($probe, 'mime-version:') === 0) {
            $html_start = stripos($raw, '<html');
            if ($html_start === false) $html_start = stripos($raw, '<table');
            if ($html_start === false) return null;
            $raw = substr($raw, $html_start);
        }

        // SpreadsheetML XML: extract <Table> → <Row> → <Cell> → <Data>
        if (stripos($probe, '<?xml') === 0 && stripos($raw, '<Workbook') !== false) {
            return _parse_spreadsheetml($raw);
        }

        // HTML table: extract <table> → <tr> → <td>/<th>
        return _parse_html_table($raw);
    }
}

// Parse an HTML string containing <table> rows into a raw grid,
// then pass through _grid_to_assoc_rows for header detection + alias matching.
if (!function_exists('_parse_html_table')) {
    function _parse_html_table($html) {
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_use_internal_errors($prev);

        $tables = $dom->getElementsByTagName('table');
        if ($tables->length === 0) return null;

        // Pick the largest table (most rows) — bank exports often wrap
        // metadata in a small header table above the main data table.
        $best = null;
        $best_rows = 0;
        for ($t = 0; $t < $tables->length; $t++) {
            $tbl = $tables->item($t);
            $rc = $tbl->getElementsByTagName('tr')->length;
            if ($rc > $best_rows) { $best = $tbl; $best_rows = $rc; }
        }
        if (!$best) return null;

        $raw = array();
        $trs = $best->getElementsByTagName('tr');
        for ($i = 0; $i < $trs->length; $i++) {
            $row = array();
            $cells = $trs->item($i)->childNodes;
            for ($j = 0; $j < $cells->length; $j++) {
                $cell = $cells->item($j);
                if ($cell->nodeName === 'td' || $cell->nodeName === 'th') {
                    $row[] = trim($cell->textContent);
                }
            }
            if (!empty($row)) $raw[] = $row;
        }
        if (empty($raw)) return null;
        return _grid_to_assoc_rows($raw);
    }
}

// Parse Microsoft SpreadsheetML 2003 XML into a raw grid.
if (!function_exists('_parse_spreadsheetml')) {
    function _parse_spreadsheetml($xml_string) {
        $prev = libxml_use_internal_errors(true);
        try {
            $xml = new SimpleXMLElement($xml_string);
        } catch (Exception $e) {
            libxml_use_internal_errors($prev);
            return null;
        }
        libxml_use_internal_errors($prev);

        $ns = $xml->getNamespaces(true);
        $ssns = isset($ns['ss']) ? $ns['ss'] : (isset($ns['']) ? $ns[''] : '');
        if ($ssns) $xml->registerXPathNamespace('ss', $ssns);

        $sheets = $ssns
            ? $xml->xpath('//ss:Worksheet/ss:Table')
            : $xml->xpath('//Worksheet/Table');
        if (empty($sheets)) return null;

        // Pick the sheet with the most rows
        $best = null;
        $best_count = 0;
        foreach ($sheets as $sheet) {
            $rows = $ssns ? $sheet->xpath('ss:Row') : $sheet->xpath('Row');
            $c = count($rows);
            if ($c > $best_count) { $best = $rows; $best_count = $c; }
        }
        if (!$best) return null;

        $raw = array();
        foreach ($best as $row) {
            $cells = $ssns ? $row->xpath('ss:Cell') : $row->xpath('Cell');
            $grid_row = array();
            foreach ($cells as $cell) {
                $data = $ssns ? $cell->xpath('ss:Data') : $cell->xpath('Data');
                $grid_row[] = !empty($data) ? trim((string)$data[0]) : '';
            }
            if (!empty(array_filter($grid_row, function($v){ return $v !== ''; }))) {
                $raw[] = $grid_row;
            }
        }
        if (empty($raw)) return null;
        return _grid_to_assoc_rows($raw);
    }
}

if (!function_exists('_xlsx_parse_shared_strings')) {
    function _xlsx_parse_shared_strings($xml_content) {
        $strings = array();
        try {
            $xml = new SimpleXMLElement($xml_content);
        } catch (Exception $e) {
            return $strings;
        }
        foreach ($xml->si as $si) {
            // A shared string is either a single <t> element, or a series
            // of <r><t>...</t></r> rich-text runs to be concatenated.
            $txt = '';
            if (isset($si->t)) $txt .= (string)$si->t;
            if (isset($si->r)) {
                foreach ($si->r as $r) {
                    if (isset($r->t)) $txt .= (string)$r->t;
                }
            }
            $strings[] = $txt;
        }
        return $strings;
    }
}

if (!function_exists('_xlsx_parse_sheet_grid')) {
    function _xlsx_parse_sheet_grid($xml_content, array $sst) {
        try {
            $xml = new SimpleXMLElement($xml_content);
        } catch (Exception $e) {
            throw new Exception('Malformed worksheet XML: ' . $e->getMessage());
        }
        if (!isset($xml->sheetData)) return array();

        $grid = array();   // [row_idx] => array of column values
        $max_col = 0;

        foreach ($xml->sheetData->row as $row_el) {
            $r_attr = (int)$row_el['r'];
            if ($r_attr <= 0) continue;
            $row_idx = $r_attr - 1;   // 0-based
            $row_cells = array();

            foreach ($row_el->c as $c) {
                $ref = (string)$c['r'];
                $col_idx = _xlsx_ref_to_col($ref);
                if ($col_idx < 0) continue;

                $t = (string)$c['t'];
                $value = '';

                if ($t === 's') {
                    $idx = (int)(string)$c->v;
                    $value = isset($sst[$idx]) ? $sst[$idx] : '';
                } elseif ($t === 'inlineStr') {
                    // <c t="inlineStr"><is><t>...</t></is></c>
                    // Also supports rich-text runs inside <is>.
                    if (isset($c->is)) {
                        if (isset($c->is->t)) $value .= (string)$c->is->t;
                        if (isset($c->is->r)) {
                            foreach ($c->is->r as $rr) {
                                if (isset($rr->t)) $value .= (string)$rr->t;
                            }
                        }
                    }
                } elseif ($t === 'str') {
                    // Formula result, inline string
                    $value = (string)$c->v;
                } elseif ($t === 'b') {
                    $value = ((string)$c->v === '1') ? 'TRUE' : 'FALSE';
                } elseif ($t === 'e') {
                    $value = (string)$c->v;  // error code
                } else {
                    // Default: number (or nothing). Format consistently
                    // with read_xls_rows so "2.2653073E7" becomes
                    // "22653073" for integer-valued floats.
                    $v = (string)$c->v;
                    if ($v !== '' && is_numeric($v)) {
                        $f = (float)$v;
                        if (is_finite($f) && (float)(int)$f === $f && abs($f) < 1e15) {
                            $value = (string)(int)$f;
                        } else {
                            $value = rtrim(rtrim(sprintf('%.6f', $f), '0'), '.');
                        }
                    } else {
                        $value = $v;
                    }
                }

                $row_cells[$col_idx] = $value;
                if ($col_idx > $max_col) $max_col = $col_idx;
            }

            $grid[$row_idx] = $row_cells;
        }

        if (empty($grid)) return array();

        // Densify into an array of arrays with a consistent column count.
        $max_row = max(array_keys($grid));
        $out = array();
        for ($r = 0; $r <= $max_row; $r++) {
            $row = array();
            for ($c = 0; $c <= $max_col; $c++) {
                $row[] = isset($grid[$r][$c]) ? $grid[$r][$c] : '';
            }
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('_xlsx_ref_to_col')) {
    function _xlsx_ref_to_col($ref) {
        // "AB12" → 27 (0-based column for AB)
        if ($ref === '') return -1;
        $letters = '';
        for ($i = 0, $len = strlen($ref); $i < $len; $i++) {
            $ch = $ref[$i];
            if ($ch >= 'A' && $ch <= 'Z') $letters .= $ch;
            elseif ($ch >= 'a' && $ch <= 'z') $letters .= strtoupper($ch);
            else break;
        }
        if ($letters === '') return -1;
        $idx = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $idx = $idx * 26 + (ord($letters[$i]) - 64);
        }
        return $idx - 1;
    }
}

// ════════════════════════════════════════════════════════════
// Shared: raw grid → assoc rows
// Smart header detection, normalization, Cr/Dr filter. Used by
// both read_xls_rows() and read_xlsx_rows() so .xls and .xlsx
// go through the exact same downstream pipeline as CSV.
// ════════════════════════════════════════════════════════════
if (!function_exists('_grid_to_assoc_rows')) {
    function _grid_to_assoc_rows(array $raw) {
        if (empty($raw)) return array();

        $keywords = array(
            'date','amount','currency','reference','description','policy',
            'agent','terminal','channel','method','credit','debit','balance',
            'transaction','narrative','receipt','ref','type','product','account',
            'location','details','code','id','total','fees','value','premium',
            'customer','issue',
            // broker schedule / commission report headers
            'office','branch','outlet','broker','commission','levy','stamp',
            'gross','net','basic','gvp','admin',
        );

        // Score every candidate row in the scan window and pick the one
        // with the highest keyword hit count (tie-break by non-empty cell
        // count). This prevents bank statements with preamble rows like
        // "Opening Balance ... Account Number" from being mistaken for the
        // real transaction header "Date | Description | Reference | ..."
        // that typically sits 5–10 rows lower.
        $header_idx = -1;
        $header = null;
        $best_hits = 0;
        $best_cells = 0;
        $scan_limit = min(25, count($raw));
        for ($scan = 0; $scan < $scan_limit; $scan++) {
            $row = $raw[$scan];
            $non_empty = array_filter($row, function($v){ return trim($v) !== ''; });
            if (empty($non_empty)) continue;
            $cell_count = count($non_empty);
            $hits = 0;
            foreach ($row as $cell) {
                $clean = preg_replace('/[^a-z]+/', '', strtolower(trim($cell)));
                if ($clean === '') continue;
                foreach ($keywords as $kw) {
                    if ($clean === $kw || strpos($clean, $kw) !== false) { $hits++; break; }
                }
            }
            if ($hits < 2) continue;
            if ($hits > $best_hits || ($hits === $best_hits && $cell_count > $best_cells)) {
                $best_hits = $hits;
                $best_cells = $cell_count;
                $header = $row;
                $header_idx = $scan;
            }
        }
        if ($header === null) {
            foreach ($raw as $idx => $row) {
                if (!empty(array_filter($row, function($v){ return trim($v) !== ''; }))) {
                    $header = $row; $header_idx = $idx; break;
                }
            }
        }
        if ($header === null) return array();

        // Normalize headers: lowercase, underscores, dedupe
        $seen = array();
        $norm = array();
        foreach ($header as $h) {
            $key = strtolower(trim($h));
            $key = preg_replace('/\s+/', '_', $key);
            if ($key === '') $key = 'col';
            if (isset($seen[$key])) { $seen[$key]++; $key .= '_' . $seen[$key]; }
            else $seen[$key] = 1;
            $norm[] = $key;
        }

        $rows = array();
        $total = count($raw);
        for ($i = $header_idx + 1; $i < $total; $i++) {
            $src = $raw[$i];
            if (empty(array_filter($src, function($v){ return trim($v) !== ''; }))) continue;
            $assoc = array();
            foreach ($norm as $j => $key) {
                $assoc[$key] = isset($src[$j]) ? trim($src[$j]) : '';
            }
            $rows[] = $assoc;
        }
        return $rows;
    }
}

// ════════════════════════════════════════════════════════════
// XLS → XLSX conversion via LibreOffice headless mode.
// Returns the path to the converted file, or false if no
// converter is available. Graceful fallback so the system
// works with or without LibreOffice installed.
// ════════════════════════════════════════════════════════════
if (!function_exists('_find_soffice')) {
    function _find_soffice() {
        global $_INGESTION_SOFFICE;
        if (isset($_INGESTION_SOFFICE)) return $_INGESTION_SOFFICE;

        $candidates = array();

        // Admin-configured path (future-proofing — not exposed in UI yet)
        if (function_exists('get_db')) {
            $db = get_db();
            $row = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='soffice_path'")->fetch_assoc();
            if ($row && !empty($row['setting_value'])) $candidates[] = $row['setting_value'];
        }

        // Common LibreOffice install paths on Windows
        $candidates[] = 'C:\\Program Files\\LibreOffice\\program\\soffice.exe';
        $candidates[] = 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe';
        // Unix paths
        $candidates[] = '/usr/bin/soffice';
        $candidates[] = '/usr/bin/libreoffice';
        $candidates[] = 'soffice';
        $candidates[] = 'libreoffice';

        foreach ($candidates as $c) {
            if (is_executable($c)) { $_INGESTION_SOFFICE = $c; return $c; }
            $out = @shell_exec(escapeshellarg($c) . ' --version 2>&1');
            if ($out && stripos($out, 'libreoffice') !== false) {
                $_INGESTION_SOFFICE = $c;
                return $c;
            }
        }
        $_INGESTION_SOFFICE = false;
        return false;
    }
}

if (!function_exists('_convert_xls_to_xlsx')) {
    function _convert_xls_to_xlsx($xls_path) {
        $soffice = _find_soffice();
        if (!$soffice) return false;

        $outdir = dirname($xls_path);
        $cmd = escapeshellarg($soffice)
             . ' --headless --convert-to xlsx --outdir '
             . escapeshellarg($outdir) . ' '
             . escapeshellarg($xls_path)
             . ' 2>&1';
        @shell_exec($cmd);

        // LibreOffice writes {basename}.xlsx in the output directory
        $basename = pathinfo($xls_path, PATHINFO_FILENAME);
        $converted = $outdir . DIRECTORY_SEPARATOR . $basename . '.xlsx';
        return file_exists($converted) ? $converted : false;
    }
}
