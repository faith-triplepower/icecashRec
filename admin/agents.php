<?php
// ============================================================
// admin/agents.php
// Agent master data list with search, pagination, add/edit.
// ============================================================
$page_title = 'Agents & Channels';
$active_nav = 'agents';
require_once '../layouts/layout_header.php';
require_role(['Manager','Reconciler','Admin']); // Uploaders don't need agent master data

$db      = get_db();
$success = htmlspecialchars($_GET['success'] ?? '');
$error   = htmlspecialchars($_GET['error']   ?? '');
$user    = current_user();

// Check if user can modify agents (Manager/Admin only)
$can_modify = in_array($user['role'], ['Admin', 'Manager']);

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$pg_offset = ($page - 1) * $per_page;
$total_count = (int)$db->query("SELECT COUNT(*) c FROM agents")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total_count / $per_page));

$agents = $db->query(
    "SELECT a.*,
     (SELECT COUNT(*) FROM pos_terminals pt WHERE pt.agent_id = a.id) AS terminal_count,
     (SELECT MAX(r.date_from) FROM reconciliation_runs r
      JOIN variance_results vr ON vr.run_id = r.id
      WHERE vr.agent_id = a.id) AS last_recon
     FROM agents a ORDER BY a.agent_code LIMIT $per_page OFFSET $pg_offset"
)->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1>Agents &amp; Channels</h1>
      <p>Master data management — Agents, Brokers, iPOS, and EcoCash channels.</p>
    </div>
    <?php if ($can_modify): ?>
    <button class="btn btn-primary" onclick="document.getElementById('add-modal').style.display='flex'">+ Add Agent</button>
    <?php else: ?>
    <div style="background:#f0f0f0;padding:8px 12px;border-radius:4px;font-size:12px;color:#666">
      <i class="fa fa-info-circle"></i> You can view agents only. Contact your manager to add or modify agents.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success">✓ <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">⚠ <?= $error ?></div><?php endif; ?>

<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
  <input type="text" class="form-input" placeholder="Search agents..." style="max-width:260px" oninput="filterTable(this.value)">
  <select class="form-select" style="width:auto" onchange="filterType(this.value)">
    <option value="">All Types</option>
    <option>Broker</option><option>iPOS</option><option>POS Terminal</option><option>EcoCash</option>
  </select>
  <select class="form-select" style="width:auto" onchange="filterRegion(this.value)">
    <option value="">All Regions</option>
    <option>Harare</option><option>Bulawayo</option><option>Manicaland</option>
    <option>Midlands</option><option>Masvingo</option><option>Mashonaland</option>
  </select>
</div>

<div class="panel">
  <table class="data-table" id="agents-table">
    <thead>
      <tr><th>Agent ID</th><th>Agent Name</th><th>Type</th><th>Region</th>
          <th>Terminals</th><th>Currency</th><th>Last Recon</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($agents as $a): ?>
    <tr>
      <td class="mono" style="color:var(--accent2)"><?= htmlspecialchars($a['agent_code']) ?></td>
      <td style="font-weight:500"><?= htmlspecialchars($a['agent_name']) ?></td>
      <td><span class="badge matched"><?= htmlspecialchars($a['agent_type']) ?></span></td>
      <td class="dim"><?= htmlspecialchars($a['region']) ?></td>
      <td class="mono" style="text-align:center"><?= (int)$a['terminal_count'] ?></td>
      <td class="mono dim" style="font-size:11px"><?= $a['currency'] ?></td>
      <td class="mono dim" style="font-size:11px"><?= $a['last_recon'] ? date('Y-m-d', strtotime($a['last_recon'])) : '—' ?></td>
      <td><span class="badge <?= $a['is_active'] ? 'active' : 'inactive' ?>"><?= $a['is_active'] ? 'ACTIVE' : 'INACTIVE' ?></span></td>
      <td>
        <div style="display:flex;gap:5px;flex-wrap:wrap">
          <a class="btn btn-ghost btn-sm" href="agent_detail.php?id=<?= (int)$a['id'] ?>" title="View agent details"><i class="fa-solid fa-eye"></i> Details</a>
          <a class="btn btn-ghost btn-sm" href="../modules/reconciliation.php?agent_id=<?= (int)$a['id'] ?>" title="Run reconciliation for this agent"><i class="fa fa-refresh"></i> Reconcile</a>
          <?php if ($can_modify): ?>
          <button class="btn btn-ghost btn-sm"
            onclick="openEdit(<?= $a['id'] ?>,'<?= addslashes($a['agent_name']) ?>','<?= $a['agent_type'] ?>','<?= $a['region'] ?>','<?= $a['currency'] ?>')">Edit</button>
          <form method="POST" action="../process/process_agents.php" style="display:inline">
      <?= csrf_field() ?>
            <input type="hidden" name="action"    value="toggle_agent">
            <input type="hidden" name="agent_id"  value="<?= $a['id'] ?>">
            <input type="hidden" name="is_active" value="<?= $a['is_active'] ? 0 : 1 ?>">
            <button type="submit" class="btn btn-ghost btn-sm"
              style="color:var(--danger);border-color:rgba(219,6,48,0.25)"
              onclick="return confirm('<?= $a['is_active'] ? 'Deactivate' : 'Activate' ?> this agent?')">
              <?= $a['is_active'] ? '×' : '✓' ?>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($agents)): ?>
    <tr><td colspan="9" class="dim" style="text-align:center;padding:20px">No agents found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <?php if ($total_pages > 1): ?>
  <div style="padding:12px 16px;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#666">
    <span>Page <?= $page ?> of <?= $total_pages ?> — <?= $total_count ?> agents</span>
    <div style="display:flex;gap:4px">
      <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page-1 ?>">← Prev</a><?php endif; ?>
      <?php if ($page < $total_pages): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page+1 ?>">Next →</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Add Agent Modal -->
<?php if ($can_modify): ?>
<div id="add-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border:1px solid #e0e0e0;border-radius:5px;width:480px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18)">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Add New Agent</span>
      <button onclick="document.getElementById('add-modal').style.display='none'"
        style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_agents.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_agent">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Agent Name</label><input type="text" name="agent_name" class="form-input" placeholder="e.g. Harare Central" required></div>
        <div class="form-group"><label class="form-label">Type</label>
          <select name="agent_type" class="form-select">
            <option>Broker</option><option>iPOS</option><option>POS Terminal</option><option>EcoCash</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Region</label>
          <select name="region" class="form-select">
            <option>Harare</option><option>Bulawayo</option><option>Manicaland</option>
            <option>Midlands</option><option>Masvingo</option><option>Mashonaland</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Currency</label>
          <select name="currency" class="form-select">
            <option>ZWG</option><option>USD</option><option>ZWG/USD</option>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Save Agent</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('add-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Agent Modal -->
<div id="edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border:1px solid #e0e0e0;border-radius:5px;width:480px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,0.18)">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Edit Agent</span>
      <button onclick="document.getElementById('edit-modal').style.display='none'"
        style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_agents.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action"   value="edit_agent">
      <input type="hidden" name="agent_id" id="edit_agent_id">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Agent Name</label><input type="text" name="agent_name" id="edit_agent_name" class="form-input" required></div>
        <div class="form-group"><label class="form-label">Type</label>
          <select name="agent_type" id="edit_agent_type" class="form-select">
            <option>Broker</option><option>iPOS</option><option>POS Terminal</option><option>EcoCash</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Region</label>
          <select name="region" id="edit_region" class="form-select">
            <option>Harare</option><option>Bulawayo</option><option>Manicaland</option>
            <option>Midlands</option><option>Masvingo</option><option>Mashonaland</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Currency</label>
          <select name="currency" id="edit_currency" class="form-select">
            <option>ZWG</option><option>USD</option><option>ZWG/USD</option>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('edit-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function filterTable(q) {
  document.querySelectorAll('#agents-table tbody tr').forEach(r => {
    r.style.display = r.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
  });
}
function filterType(t) {
  document.querySelectorAll('#agents-table tbody tr').forEach(r => {
    r.style.display = (!t || r.textContent.includes(t)) ? '' : 'none';
  });
}
function filterRegion(reg) {
  document.querySelectorAll('#agents-table tbody tr').forEach(r => {
    r.style.display = (!reg || r.textContent.includes(reg)) ? '' : 'none';
  });
}
function openEdit(id, name, type, region, currency) {
  document.getElementById('edit_agent_id').value   = id;
  document.getElementById('edit_agent_name').value = name;
  document.getElementById('edit_agent_type').value = type;
  document.getElementById('edit_region').value     = region;
  document.getElementById('edit_currency').value   = currency;
  document.getElementById('edit-modal').style.display = 'flex';
}
['add-modal','edit-modal'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
  });
});
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
