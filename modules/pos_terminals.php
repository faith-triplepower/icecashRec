<?php
// ============================================================
// modules/pos_terminals.php
// POS terminals master data with pagination, inactivity tab, region summary.
// ============================================================
$page_title = 'POS Terminals';
$active_nav = 'pos_terminals';
require_once '../layouts/layout_header.php';
require_role(['Manager','Reconciler','Admin']); // Uploaders don't need terminal master data

$db      = get_db();
$user    = current_user();
$success = htmlspecialchars($_GET['success'] ?? '');
$error   = htmlspecialchars($_GET['error']   ?? '');

// Writes are Manager/Admin only
$can_modify = in_array($user['role'], ['Manager','Admin']);

// Pagination
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$pg_offset = ($page - 1) * $per_page;
$total_count = (int)$db->query("SELECT COUNT(*) c FROM pos_terminals")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total_count / $per_page));

// Inactivity threshold (days) — could move to system_settings later
$inactive_days = 7;

// KPIs
$kpi = $db->query("SELECT
    COUNT(*) AS total,
    SUM(is_active) AS active_total,
    SUM(CASE WHEN last_txn_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) AND is_active=1 THEN 1 ELSE 0 END) AS active_today,
    COUNT(DISTINCT bank_id) AS banks
  FROM pos_terminals")->fetch_assoc();

$no_activity = (int)($kpi['active_total'] ?? 0) - (int)($kpi['active_today'] ?? 0);

// Terminals (paginated)
$terminals = $db->query("SELECT pt.*, a.agent_name
    FROM pos_terminals pt
    JOIN agents a ON pt.agent_id = a.id
    ORDER BY pt.is_active DESC, pt.terminal_id LIMIT $per_page OFFSET $pg_offset")->fetch_all(MYSQLI_ASSOC);

// Inactive terminals — active but no transactions for N days
$inactive = $db->query("SELECT pt.*, a.agent_name,
    DATEDIFF(NOW(), pt.last_txn_at) AS days_idle
    FROM pos_terminals pt
    JOIN agents a ON pt.agent_id = a.id
    WHERE pt.is_active=1
      AND (pt.last_txn_at IS NULL OR pt.last_txn_at < DATE_SUB(NOW(), INTERVAL $inactive_days DAY))
    ORDER BY pt.last_txn_at ASC")->fetch_all(MYSQLI_ASSOC);

// Region summary
$by_region = $db->query("SELECT a.region, COUNT(*) cnt,
    SUM(pt.is_active) active_cnt
    FROM pos_terminals pt
    JOIN agents a ON pt.agent_id = a.id
    GROUP BY a.region
    ORDER BY cnt DESC")->fetch_all(MYSQLI_ASSOC);

$agents = $db->query("SELECT id, agent_name FROM agents WHERE is_active=1 ORDER BY agent_name")->fetch_all(MYSQLI_ASSOC);
$banks  = $db->query("SELECT id, bank_name FROM banks WHERE is_active=1 ORDER BY bank_name")->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1>POS Terminals</h1>
      <p>Master list of all registered Point-of-Sale terminals and their assigned agents.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($can_modify): ?>
      <button class="btn btn-primary" onclick="document.getElementById('add-modal').style.display='flex'">+ Register Terminal</button>
      <button class="btn btn-ghost" onclick="document.getElementById('bulk-modal').style.display='flex'">⬆ Bulk Import</button>
      <button class="btn btn-ghost" onclick="document.getElementById('bank-modal').style.display='flex'">+ Add Bank</button>
      <?php else: ?>
      <div style="background:#f0f0f0;padding:8px 12px;border-radius:4px;font-size:12px;color:#666">
        <i class="fa fa-info-circle"></i> You can view terminals only. Contact your manager to add or modify.
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success">✓ <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">⚠ <?= $error ?></div><?php endif; ?>

<div class="stat-grid">
  <div class="stat-card green"><div class="stat-label">Total Terminals</div><div class="stat-value"><?= (int)$kpi['total'] ?></div><div class="stat-sub">Across all agents</div></div>
  <div class="stat-card blue"><div class="stat-label">Active (48h)</div><div class="stat-value"><?= (int)$kpi['active_today'] ?></div><div class="stat-sub">Transactions received</div></div>
  <div class="stat-card warn"><div class="stat-label">Idle ≥ <?= $inactive_days ?>d</div><div class="stat-value"><?= count($inactive) ?></div><div class="stat-sub">Need follow-up</div></div>
  <div class="stat-card green"><div class="stat-label">Banks Connected</div><div class="stat-value"><?= (int)$kpi['banks'] ?></div></div>
</div>

<div class="tab-bar">
  <div class="tab-item active" onclick="switchTab(this,'t-terminals')">Terminals (<?= $total_count ?>)</div>
  <div class="tab-item" onclick="switchTab(this,'t-inactive')">Inactivity (<?= count($inactive) ?>)</div>
  <div class="tab-item" onclick="switchTab(this,'t-regions')">By Region</div>
</div>

<!-- TERMINALS TAB -->
<div id="t-terminals">
  <div style="display:flex;gap:10px;margin:10px 0 16px;flex-wrap:wrap">
    <input type="text" class="form-input" placeholder="Search terminal ID or agent..." style="max-width:280px" oninput="filterTable(this.value,'terms-table')">
    <select class="form-select" style="width:auto" onchange="filterCol(this.value,'terms-table',3)">
      <option value="">All Banks</option>
      <?php foreach ($banks as $b): ?>
      <option><?= htmlspecialchars($b['bank_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" style="width:auto" onchange="filterCol(this.value,'terms-table',7)">
      <option value="">All Status</option>
      <option>Active</option><option>Inactive</option>
    </select>
  </div>

  <div class="panel">
    <table class="data-table" id="terms-table">
      <thead><tr><th>Terminal ID</th><th>Merchant</th><th>Agent</th><th>Bank</th><th>Location</th><th>Currency</th><th>Last Txn</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($terminals as $t): ?>
      <tr>
        <td class="mono" style="color:var(--accent2)"><?= htmlspecialchars($t['terminal_id']) ?></td>
        <td style="font-weight:500;font-size:12px"><?= htmlspecialchars($t['merchant_name']) ?></td>
        <td class="dim"><?= htmlspecialchars($t['agent_name']) ?></td>
        <td class="dim"><?= htmlspecialchars($t['bank_name']) ?></td>
        <td class="dim"><?= htmlspecialchars($t['location']) ?></td>
        <?php
          $ccy_class = $t['currency'] === 'USD' ? 'ccy-usd'
                     : ($t['currency'] === 'ZWG/USD' ? 'ccy-dual' : 'ccy-zwg');
        ?>
        <td><span class="badge <?= $ccy_class ?>"><?= $t['currency'] ?></span></td>
        <td class="mono dim" style="font-size:11px"><?= $t['last_txn_at'] ? date('Y-m-d H:i', strtotime($t['last_txn_at'])) : '—' ?></td>
        <td><span class="badge <?= $t['is_active'] ? 'active' : 'inactive' ?>"><?= $t['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td>
          <div style="display:flex;gap:5px;flex-wrap:wrap">
            <a class="btn btn-ghost btn-sm" href="../admin/terminal_detail.php?id=<?= $t['id'] ?>" title="View details"><i class="fa-solid fa-eye"></i> Details</a>
            <a class="btn btn-ghost btn-sm" href="reconciliation.php?agent_id=<?= $t['agent_id'] ?>" title="Reconcile this terminal's agent"><i class="fa fa-refresh"></i> Reconcile</a>
            <?php if ($can_modify): ?>
            <button class="btn btn-ghost btn-sm"
              onclick="openEdit(<?= $t['id'] ?>,'<?= addslashes($t['merchant_name']) ?>',<?= $t['agent_id'] ?>,<?= (int)$t['bank_id'] ?>,'<?= addslashes($t['location']) ?>','<?= $t['currency'] ?>')">Edit</button>
            <form method="POST" action="../process/process_terminals.php" style="display:inline">
      <?= csrf_field() ?>
              <input type="hidden" name="action"          value="toggle_terminal">
              <input type="hidden" name="terminal_db_id"  value="<?= $t['id'] ?>">
              <input type="hidden" name="is_active"       value="<?= $t['is_active'] ? 0 : 1 ?>">
              <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);border-color:rgba(219,6,48,0.25)"
                onclick="return confirm('<?= $t['is_active']?'Deactivate':'Activate' ?> this terminal?')">
                <?= $t['is_active'] ? '×' : '✓' ?>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($terminals)): ?>
      <tr><td colspan="9" class="dim" style="text-align:center;padding:20px">No terminals found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php if ($total_pages > 1): ?>
    <div style="padding:12px 16px;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#666">
      <span>Page <?= $page ?> of <?= $total_pages ?> — <?= $total_count ?> terminals</span>
      <div style="display:flex;gap:4px">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page-1 ?>">← Prev</a><?php endif; ?>
        <?php if ($page < $total_pages): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page+1 ?>">Next →</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- INACTIVITY TAB -->
<div id="t-inactive" style="display:none">
  <div class="panel">
    <div class="panel-header">
      <span class="panel-title">Terminals idle ≥ <?= $inactive_days ?> days</span>
      <span class="dim" style="font-size:11px;margin-left:10px">Follow up with merchants — possible device failure or disuse</span>
    </div>
    <table class="data-table">
      <thead><tr><th>Terminal ID</th><th>Merchant</th><th>Agent</th><th>Bank</th><th>Last Txn</th><th>Days Idle</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($inactive as $t): ?>
      <tr>
        <td class="mono" style="color:var(--accent2)"><?= htmlspecialchars($t['terminal_id']) ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($t['merchant_name']) ?></td>
        <td class="dim"><?= htmlspecialchars($t['agent_name']) ?></td>
        <td class="dim"><?= htmlspecialchars($t['bank_name']) ?></td>
        <td class="mono dim" style="font-size:11px"><?= $t['last_txn_at'] ?: '— (never)' ?></td>
        <td class="mono" style="color:<?= ($t['days_idle']??9999) > 30 ? '#c0392b' : '#d49a00' ?>;font-weight:600"><?= $t['days_idle'] ?? '—' ?></td>
        <td><a class="btn btn-ghost btn-sm" href="../admin/terminal_detail.php?id=<?= $t['id'] ?>">Details</a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($inactive)): ?>
      <tr><td colspan="7" class="dim" style="text-align:center;padding:20px">✓ All active terminals have recent transactions.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- REGIONS TAB -->
<div id="t-regions" style="display:none">
  <div class="panel">
    <div class="panel-header"><span class="panel-title">Terminals by Region</span></div>
    <table class="data-table">
      <thead><tr><th>Region</th><th>Total</th><th>Active</th><th>Inactive</th></tr></thead>
      <tbody>
      <?php foreach ($by_region as $rg): ?>
      <tr>
        <td style="font-weight:500"><?= htmlspecialchars($rg['region']) ?></td>
        <td class="mono"><?= (int)$rg['cnt'] ?></td>
        <td class="mono" style="color:#00a950"><?= (int)$rg['active_cnt'] ?></td>
        <td class="mono dim"><?= (int)$rg['cnt'] - (int)$rg['active_cnt'] ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($can_modify): ?>
<!-- Add Terminal Modal -->
<div id="add-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18)">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Register Terminal</span>
      <button onclick="document.getElementById('add-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_terminals.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_terminal">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Terminal ID</label><input type="text" name="terminal_id" class="form-input" placeholder="e.g. CBZ-POS-0099" required></div>
        <div class="form-group"><label class="form-label">Merchant Name</label><input type="text" name="merchant_name" class="form-input" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Assigned Agent</label>
          <select name="agent_id" class="form-select" required>
            <?php foreach ($agents as $ag): ?>
            <option value="<?= $ag['id'] ?>"><?= htmlspecialchars($ag['agent_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Bank</label>
          <select name="bank_id" class="form-select" required>
            <?php foreach ($banks as $b): ?>
            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['bank_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" class="form-input" required></div>
        <div class="form-group"><label class="form-label">Currency</label>
          <select name="currency" class="form-select"><option>ZWG</option><option>USD</option><option>ZWG/USD</option></select>
        </div>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Register</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('add-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Terminal Modal -->
<div id="edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18)">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Edit Terminal</span>
      <button onclick="document.getElementById('edit-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_terminals.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action"          value="edit_terminal">
      <input type="hidden" name="terminal_db_id"  id="edit_term_id">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Merchant Name</label><input type="text" name="merchant_name" id="edit_merchant" class="form-input" required></div>
        <div class="form-group"><label class="form-label">Assigned Agent</label>
          <select name="agent_id" id="edit_agent" class="form-select" required>
            <?php foreach ($agents as $ag): ?>
            <option value="<?= $ag['id'] ?>"><?= htmlspecialchars($ag['agent_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Bank</label>
          <select name="bank_id" id="edit_bank" class="form-select" required>
            <?php foreach ($banks as $b): ?>
            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['bank_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" id="edit_location" class="form-input" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Currency</label>
          <select name="currency" id="edit_currency" class="form-select"><option>ZWG</option><option>USD</option><option>ZWG/USD</option></select>
        </div>
        <div class="form-group"><label class="form-label">Reason for change</label><input type="text" name="reason" class="form-input" placeholder="e.g. Reassigned to new branch"></div>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('edit-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Bank Modal -->
<div id="bank-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:420px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,0.18)">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Add Bank</span>
      <button onclick="document.getElementById('bank-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_terminals.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_bank">
      <div class="form-group"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-input" placeholder="e.g. NMB Bank" required></div>
      <div class="form-group"><label class="form-label">Bank Code (optional)</label><input type="text" name="bank_code" class="form-input" placeholder="e.g. NMB"></div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Add Bank</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('bank-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Bulk Import Modal -->
<div id="bulk-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:520px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,0.18)">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Bulk Import Terminals</span>
      <button onclick="document.getElementById('bulk-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_terminals.php" enctype="multipart/form-data" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_import">
      <p style="font-size:12px;color:#666;margin-top:0">CSV must have columns: <code>terminal_id, merchant_name, agent_code, bank_name, location, currency</code>. Unknown banks will be auto-created. Duplicates are skipped.</p>
      <div class="form-group"><label class="form-label">CSV File</label><input type="file" name="csv_file" accept=".csv" class="form-input" required></div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Import</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('bulk-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function switchTab(el, id) {
  document.querySelectorAll('.tab-item').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  ['t-terminals','t-inactive','t-regions'].forEach(t => {
    const n = document.getElementById(t);
    if (n) n.style.display = t===id ? 'block' : 'none';
  });
}
function filterTable(q, tableId) {
  document.querySelectorAll('#'+tableId+' tbody tr').forEach(r => {
    r.style.display = r.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
  });
}
function filterCol(val, tableId, colIdx) {
  document.querySelectorAll('#'+tableId+' tbody tr').forEach(r => {
    const cell = r.cells[colIdx];
    r.style.display = (!val || (cell && cell.textContent.includes(val))) ? '' : 'none';
  });
}
function openEdit(id, merchant, agentId, bankId, location, currency) {
  document.getElementById('edit_term_id').value  = id;
  document.getElementById('edit_merchant').value = merchant;
  document.getElementById('edit_agent').value    = agentId;
  document.getElementById('edit_bank').value     = bankId;
  document.getElementById('edit_location').value = location;
  document.getElementById('edit_currency').value = currency;
  document.getElementById('edit-modal').style.display = 'flex';
}
['add-modal','edit-modal','bank-modal','bulk-modal'].forEach(id => {
  const m = document.getElementById(id);
  if (m) m.addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
  });
});
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
