<?php
// ============================================================
// process/process_agents.php
// Handles: add agent, edit agent, toggle active status
// Called from: admin/agents.php
// ============================================================

require_once '../core/auth.php';
require_role(['Manager','Admin']); // Only Manager/Admin can write agent data
csrf_verify();

$db     = get_db();
$action = $_POST['action'] ?? '';
$user   = current_user();

// ── Check if user has permission to modify agents ──────────────
function can_modify_agents(): bool {
    $user = current_user();
    return in_array($user['role'], ['Admin', 'Manager']);
}

// ── Create escalation for non-admin actions ────────────────────
function create_escalation(string $action_type, string $action_detail, string $entity, int $entity_id): void {
    $user = current_user();
    if ($user['role'] === 'Admin') return; // Admin actions don't need escalation
    
    $db = get_db();
    // Get all managers
    $managers = $db->query("SELECT id FROM users WHERE role='Manager' AND is_active=1 LIMIT 1");
    $manager = $managers->fetch_assoc();
    $assigned_to = $manager['id'] ?? NULL;
    
    $stmt = $db->prepare(
        "INSERT INTO escalations (user_id, action_type, action_detail, affected_entity, entity_id, assigned_to)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('isssii', $user['id'], $action_type, $action_detail, $entity, $entity_id, $assigned_to);
    $stmt->execute();
    $stmt->close();
}

switch ($action) {

    // ── Add agent ─────────────────────────────────────────────
    case 'add_agent':
        if (!can_modify_agents()) {
            redirect_back('error', 'You do not have permission to add agents. Only Admin and Managers can manage agents.');
        }
        
        $agent_name = trim($_POST['agent_name'] ?? '');
        $agent_type = $_POST['agent_type']       ?? '';
        $region     = trim($_POST['region']      ?? '');
        $currency   = $_POST['currency']         ?? 'ZWG';

        $valid_types     = ['iPOS','POS Terminal','Broker','EcoCash'];
        $valid_currencies = ['ZWG','USD','ZWG/USD'];

        if (!$agent_name || !in_array($agent_type, $valid_types) || !$region
            || !in_array($currency, $valid_currencies)) {
            redirect_back('error', 'All fields are required.');
        }

        // Auto-generate agent code: AGT-NNN
        $row  = $db->query("SELECT MAX(id) AS max_id FROM agents")->fetch_assoc();
        $next = ($row['max_id'] ?? 0) + 1;
        $code = 'AGT-' . str_pad($next, 3, '0', STR_PAD_LEFT);

        $stmt = $db->prepare(
            "INSERT INTO agents (agent_code, agent_name, agent_type, region, currency)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('sssss', $code, $agent_name, $agent_type, $region, $currency);
        if ($stmt->execute()) {
            $agent_id = $stmt->insert_id;
            $stmt->close();
            
            $detail = "Added agent: $agent_name ($code)";
            audit_log($user['id'], 'DATA_EDIT', $detail);
            
            // Escalate if not admin
            if ($user['role'] !== 'Admin') {
                create_escalation('AGENT_ADD', $detail, 'Agent', $agent_id);
            }
            
            redirect_back('success', "Agent '$agent_name' added successfully.");
        }
        $stmt->close();
        redirect_back('error', 'Failed to add agent.');

    // ── Edit agent ────────────────────────────────────────────
    case 'edit_agent':
        if (!can_modify_agents()) {
            redirect_back('error', 'You do not have permission to edit agents. Only Admin and Managers can manage agents.');
        }
        
        $id         = (int)($_POST['agent_id']   ?? 0);
        $agent_name = trim($_POST['agent_name']  ?? '');
        $agent_type = $_POST['agent_type']       ?? '';
        $region     = trim($_POST['region']      ?? '');
        $currency   = $_POST['currency']         ?? 'ZWG';

        if (!$id || !$agent_name) {
            redirect_back('error', 'Invalid data.');
        }

        $stmt = $db->prepare(
            "UPDATE agents SET agent_name=?, agent_type=?, region=?, currency=? WHERE id=?"
        );
        $stmt->bind_param('ssssi', $agent_name, $agent_type, $region, $currency, $id);
        $stmt->execute();
        $stmt->close();

        $detail = "Edited agent ID $id: $agent_name";
        audit_log($user['id'], 'DATA_EDIT', $detail);
        
        // Escalate if not admin
        if ($user['role'] !== 'Admin') {
            create_escalation('AGENT_EDIT', $detail, 'Agent', $id);
        }
        
        redirect_back('success', 'Agent updated successfully.');

    // ── Toggle active ─────────────────────────────────────────
    case 'toggle_agent':
        if (!can_modify_agents()) {
            redirect_back('error', 'You do not have permission to modify agents. Only Admin and Managers can manage agents.');
        }
        
        $id     = (int)($_POST['agent_id']  ?? 0);
        $active = (int)($_POST['is_active'] ?? 0);

        if (!$id) redirect_back('error', 'Invalid agent.');

        $stmt = $db->prepare("UPDATE agents SET is_active=? WHERE id=?");
        $stmt->bind_param('ii', $active, $id);
        $stmt->execute();
        $stmt->close();

        $label = $active ? 'Activated' : 'Deactivated';
        $detail = "$label agent ID $id";
        audit_log($user['id'], 'DATA_EDIT', $detail);
        
        // Escalate if not admin
        if ($user['role'] !== 'Admin') {
            create_escalation('AGENT_TOGGLE', $detail, 'Agent', $id);
        }
        
        redirect_back('success', "Agent $label.");

    default:
        redirect_back('error', 'Unknown action.');
}

function redirect_back(string $type, string $msg): void {
    header("Location: " . BASE_URL . "/admin/agents.php?" . $type . "=" . urlencode($msg));
    exit;
}
?>
