<?php
// ============================================================
// process/process_terminals.php
// Handles: register/edit/toggle/bulk-import terminals, add banks.
// WRITE actions: Manager / Admin only.
// ============================================================

require_once '../core/auth.php';
require_role(['Manager','Admin']);
csrf_verify();

$db     = get_db();
$action = $_POST['action'] ?? '';
$user   = current_user();
$uid    = (int)$user['id'];

function redirect_back($type, $msg) {
    header("Location: " . BASE_URL . "/modules/pos_terminals.php?" . $type . "=" . urlencode($msg));
    exit;
}

// ── Shared: record an assignment change ────────────────────
function log_assignment($db, $terminal_db_id, $agent_id, $uid, $reason) {
    // Close any currently-open assignment for this terminal
    $close = $db->prepare("UPDATE terminal_assignments SET valid_to=CURDATE() WHERE terminal_id=? AND valid_to IS NULL");
    $close->bind_param('i', $terminal_db_id);
    $close->execute();
    $close->close();

    // Open a new assignment
    $ins = $db->prepare(
        "INSERT INTO terminal_assignments (terminal_id, agent_id, valid_from, changed_by, reason)
         VALUES (?, ?, CURDATE(), ?, ?)"
    );
    $ins->bind_param('iiis', $terminal_db_id, $agent_id, $uid, $reason);
    $ins->execute();
    $ins->close();
}

switch ($action) {

    // ── ADD TERMINAL ────────────────────────────────────────
    case 'add_terminal':
        $terminal_id   = strtoupper(trim($_POST['terminal_id']   ?? ''));
        $merchant_name = trim($_POST['merchant_name'] ?? '');
        $agent_id      = (int)($_POST['agent_id']     ?? 0);
        $bank_id       = (int)($_POST['bank_id']      ?? 0);
        $location      = trim($_POST['location']      ?? '');
        $currency      = $_POST['currency']           ?? 'ZWG';

        if (!$terminal_id || !$merchant_name || !$agent_id || !$bank_id || !$location) {
            redirect_back('error', 'All fields are required.');
        }

        // Resolve bank name for denormalized storage
        $b_stmt = $db->prepare("SELECT bank_name FROM banks WHERE id=? AND is_active=1");
        $b_stmt->bind_param('i', $bank_id);
        $b_stmt->execute();
        $bank = $b_stmt->get_result()->fetch_assoc();
        $b_stmt->close();
        if (!$bank) redirect_back('error', 'Invalid bank selected.');

        $stmt = $db->prepare(
            "INSERT INTO pos_terminals (terminal_id, merchant_name, agent_id, bank_id, bank_name, location, currency)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssiisss', $terminal_id, $merchant_name, $agent_id, $bank_id, $bank['bank_name'], $location, $currency);
        if (!$stmt->execute()) {
            $errno = $db->errno;
            $stmt->close();
            if ($errno === 1062) redirect_back('error', 'Terminal ID already exists.');
            redirect_back('error', 'Failed to register terminal.');
        }
        $new_id = (int)$stmt->insert_id;
        $stmt->close();

        log_assignment($db, $new_id, $agent_id, $uid, 'Initial registration');

        audit_log($uid, 'DATA_EDIT', "Registered terminal: $terminal_id");
        redirect_back('success', "Terminal '$terminal_id' registered.");

    // ── EDIT TERMINAL ───────────────────────────────────────
    case 'edit_terminal':
        $id            = (int)($_POST['terminal_db_id'] ?? 0);
        $merchant_name = trim($_POST['merchant_name']   ?? '');
        $agent_id      = (int)($_POST['agent_id']       ?? 0);
        $bank_id       = (int)($_POST['bank_id']        ?? 0);
        $location      = trim($_POST['location']        ?? '');
        $currency      = $_POST['currency']             ?? 'ZWG';
        $reason        = trim($_POST['reason']          ?? 'Edit');

        if (!$id || !$merchant_name || !$agent_id || !$bank_id) {
            redirect_back('error', 'Invalid data.');
        }

        // Get current state to detect agent reassignment
        $cur_stmt = $db->prepare("SELECT agent_id FROM pos_terminals WHERE id=?");
        $cur_stmt->bind_param('i', $id);
        $cur_stmt->execute();
        $cur = $cur_stmt->get_result()->fetch_assoc();
        $cur_stmt->close();
        if (!$cur) redirect_back('error', 'Terminal not found.');

        $b_stmt = $db->prepare("SELECT bank_name FROM banks WHERE id=?");
        $b_stmt->bind_param('i', $bank_id);
        $b_stmt->execute();
        $bank = $b_stmt->get_result()->fetch_assoc();
        $b_stmt->close();
        if (!$bank) redirect_back('error', 'Invalid bank.');

        $stmt = $db->prepare(
            "UPDATE pos_terminals
             SET merchant_name=?, agent_id=?, bank_id=?, bank_name=?, location=?, currency=?
             WHERE id=?"
        );
        $stmt->bind_param('siisssi', $merchant_name, $agent_id, $bank_id, $bank['bank_name'], $location, $currency, $id);
        $stmt->execute();
        $stmt->close();

        // Record assignment change if the agent actually changed
        if ((int)$cur['agent_id'] !== $agent_id) {
            log_assignment($db, $id, $agent_id, $uid, $reason);
        }

        audit_log($uid, 'DATA_EDIT', "Edited terminal ID $id");
        redirect_back('success', 'Terminal updated.');

    // ── TOGGLE (deactivate with guardrail) ──────────────────
    case 'toggle_terminal':
        $id     = (int)($_POST['terminal_db_id'] ?? 0);
        $active = (int)($_POST['is_active']      ?? 0);
        if (!$id) redirect_back('error', 'Invalid terminal.');

        // Guardrail: don't deactivate if there are unmatched receipts in the last 30 days
        if ($active === 0) {
            $t_stmt = $db->prepare("SELECT terminal_id FROM pos_terminals WHERE id=?");
            $t_stmt->bind_param('i', $id);
            $t_stmt->execute();
            $term = $t_stmt->get_result()->fetch_assoc();
            $t_stmt->close();
            if (!$term) redirect_back('error', 'Terminal not found.');

            $u_stmt = $db->prepare(
                "SELECT COUNT(*) c FROM receipts
                 WHERE terminal_id=? AND match_status='pending'
                   AND txn_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
            );
            $u_stmt->bind_param('s', $term['terminal_id']);
            $u_stmt->execute();
            $cnt = (int)$u_stmt->get_result()->fetch_assoc()['c'];
            $u_stmt->close();

            if ($cnt > 0 && empty($_POST['force'])) {
                redirect_back('error',
                    "Cannot deactivate: $cnt unmatched receipts in the last 30 days on this terminal. Resolve them first.");
            }
        }

        $stmt = $db->prepare("UPDATE pos_terminals SET is_active=? WHERE id=?");
        $stmt->bind_param('ii', $active, $id);
        $stmt->execute();
        $stmt->close();

        $label = $active ? 'Activated' : 'Deactivated';
        audit_log($uid, 'DATA_EDIT', "$label terminal ID $id");
        redirect_back('success', "Terminal $label.");

    // ── ADD BANK ────────────────────────────────────────────
    case 'add_bank':
        $bank_name = trim($_POST['bank_name'] ?? '');
        $bank_code = trim($_POST['bank_code'] ?? '') ?: null;
        if (!$bank_name) redirect_back('error', 'Bank name required.');

        $stmt = $db->prepare("INSERT INTO banks (bank_name, bank_code) VALUES (?, ?)");
        $stmt->bind_param('ss', $bank_name, $bank_code);
        if (!$stmt->execute()) {
            $errno = $db->errno;
            $stmt->close();
            if ($errno === 1062) redirect_back('error', 'Bank already exists.');
            redirect_back('error', 'Failed to add bank.');
        }
        $stmt->close();
        audit_log($uid, 'DATA_EDIT', "Added bank: $bank_name");
        redirect_back('success', "Bank '$bank_name' added.");

    // ── BULK IMPORT (CSV) ───────────────────────────────────
    case 'bulk_import':
        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            redirect_back('error', 'No CSV file uploaded.');
        }
        $fp = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fp) redirect_back('error', 'Cannot read CSV.');

        // Expect header: terminal_id, merchant_name, agent_code, bank_name, location, currency
        $headers = fgetcsv($fp);
        if (!$headers) { fclose($fp); redirect_back('error', 'Empty CSV.'); }
        $headers = array_map(function($h){ return strtolower(trim($h)); }, $headers);
        $idx = array_flip($headers);
        $needed = array('terminal_id','merchant_name','agent_code','bank_name','location','currency');
        foreach ($needed as $n) {
            if (!isset($idx[$n])) { fclose($fp); redirect_back('error', "Missing column: $n"); }
        }

        // Build lookup maps
        $agents_map = array();
        $a_res = $db->query("SELECT id, UPPER(agent_code) ac FROM agents");
        while ($a = $a_res->fetch_assoc()) $agents_map[$a['ac']] = (int)$a['id'];

        $banks_map = array();
        $b_res = $db->query("SELECT id, UPPER(bank_name) bn FROM banks");
        while ($b = $b_res->fetch_assoc()) $banks_map[$b['bn']] = (int)$b['id'];

        $ins = $db->prepare(
            "INSERT IGNORE INTO pos_terminals (terminal_id, merchant_name, agent_id, bank_id, bank_name, location, currency)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $imported = 0; $skipped = 0; $errors = array();
        $row_num = 1;
        $db->begin_transaction();
        try {
            while (($row = fgetcsv($fp)) !== false) {
                $row_num++;
                if (count(array_filter($row, function($v){ return trim($v)!==''; })) === 0) continue;

                $tid     = strtoupper(trim($row[$idx['terminal_id']]));
                $merch   = trim($row[$idx['merchant_name']]);
                $ac      = strtoupper(trim($row[$idx['agent_code']]));
                $bn      = trim($row[$idx['bank_name']]);
                $loc     = trim($row[$idx['location']]);
                $cur     = trim($row[$idx['currency']]) ?: 'ZWG';

                if (!$tid || !$merch || !$ac || !$bn || !$loc) {
                    $errors[] = "Row $row_num: missing required fields";
                    $skipped++;
                    continue;
                }
                if (!isset($agents_map[$ac])) {
                    $errors[] = "Row $row_num: unknown agent code '$ac'";
                    $skipped++;
                    continue;
                }

                // Auto-create bank if unknown
                $bn_key = strtoupper($bn);
                if (!isset($banks_map[$bn_key])) {
                    $bi = $db->prepare("INSERT INTO banks (bank_name) VALUES (?)");
                    $bi->bind_param('s', $bn);
                    $bi->execute();
                    $banks_map[$bn_key] = (int)$bi->insert_id;
                    $bi->close();
                }
                $bank_id = $banks_map[$bn_key];
                $agent_id = $agents_map[$ac];

                $ins->bind_param('ssiisss', $tid, $merch, $agent_id, $bank_id, $bn, $loc, $cur);
                if ($ins->execute() && $ins->affected_rows > 0) {
                    $imported++;
                    $new_id = (int)$ins->insert_id;
                    log_assignment($db, $new_id, $agent_id, $uid, 'Bulk import');
                } else {
                    $skipped++;
                }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            $ins->close();
            fclose($fp);
            redirect_back('error', 'Import failed: ' . $e->getMessage());
        }
        $ins->close();
        fclose($fp);

        audit_log($uid, 'DATA_EDIT', "Bulk imported $imported terminals, $skipped skipped");
        $msg = "Imported $imported terminals. $skipped skipped.";
        if (!empty($errors)) $msg .= ' First errors: ' . implode('; ', array_slice($errors, 0, 3));
        redirect_back('success', $msg);

    default:
        redirect_back('error', 'Unknown action.');
}
