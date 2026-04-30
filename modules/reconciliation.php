<?php
// ============================================================
// modules/reconciliation.php 
// ============================================================
$page_title = 'Reconciliation';
$active_nav = 'reconciliation';
require_once '../layouts/layout_header.php';
require_role(['Manager','Reconciler','Admin']); // Uploaders cannot reconcile

$db      = get_db();
$success = htmlspecialchars($_GET['success'] ?? '');
$error   = htmlspecialchars($_GET['error']   ?? '');

$agents = $db->query("SELECT id, agent_name FROM agents WHERE is_active=1 ORDER BY agent_name")->fetch_all(MYSQLI_ASSOC);
$prefill_agent_id = (int)($_GET['agent_id'] ?? 0);
$runs   = $db->query("SELECT r.*, u.full_name FROM reconciliation_runs r
    JOIN users u ON r.run_by = u.id
    ORDER BY r.started_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

// Load settings defaults
$settings_rows = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetch_all(MYSQLI_ASSOC);
$settings      = array_column($settings_rows, 'setting_value', 'setting_key');

// Default the period to the most recent month that has BOTH sales
// and receipt data (so reconciliation actually has something to do).
// Falls back to the current calendar month for a fresh install.
$period_row = $db->query("
    SELECT DATE_FORMAT(s.txn_date, '%Y-%m-01') AS ms, LAST_DAY(s.txn_date) AS me
    FROM sales s
    WHERE EXISTS (
        SELECT 1 FROM receipts r
        WHERE DATE_FORMAT(r.txn_date, '%Y-%m') = DATE_FORMAT(s.txn_date, '%Y-%m')
    )
    ORDER BY s.txn_date DESC
    LIMIT 1
")->fetch_assoc();
$default_from = $period_row['ms'] ?? date('Y-m-01');
$default_to   = $period_row['me'] ?? date('Y-m-t');

// Uploads available for scoped reconciliation — only healthy files
// with actual rows and not currently flagged by another reconciler.
// Limited to recent 50 so the modal stays manageable.
$available_uploads = $db->query("
    SELECT uh.id, uh.filename, uh.file_type, uh.record_count, uh.upload_status,
           uh.period_from, uh.period_to, uh.created_at, u.full_name AS uploader_name
    FROM upload_history uh
    JOIN users u ON uh.uploaded_by = u.id
    WHERE uh.upload_status IN ('ok','warning')
      AND (uh.flag_status IS NULL OR uh.flag_status <> 'flagged')
      AND uh.record_count > 0
    ORDER BY uh.created_at DESC
    LIMIT 50
")->fetch_all(MYSQLI_ASSOC);

$sales_uploads    = array_values(array_filter($available_uploads, function($u) { return $u['file_type'] === 'Sales'; }));
$receipts_uploads = array_values(array_filter($available_uploads, function($u) { return $u['file_type'] === 'Receipts'; }));
?>

<div class="page-header">
  <h1>Reconciliation Engine</h1>
  <p>Pick a period and click RUN to match already-ingested sales against receipts. Source files are loaded by the Uploader role via the <a href="<?= BASE_URL ?>/utilities/uploaded_files_list.php">Uploaded Files library</a>.</p>
</div>

<?php if ($success): ?><div class="alert alert-success">✓ <?= $success ?></div><?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <span>⚠ <?= $error ?></span>
    <?php $blocking_run_id = (int)($_GET['blocking_run_id'] ?? 0); if ($blocking_run_id > 0): ?>
    <form method="POST" action="../process/process_reconciliation.php" style="margin:0" onsubmit="return confirm('Cancel run #<?= $blocking_run_id ?>? It will be marked failed so you can start a new one.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel_run">
      <input type="hidden" name="run_id" value="<?= $blocking_run_id ?>">
      <button type="submit" class="btn btn-primary btn-sm" style="background:#c0392b;border-color:#c0392b;font-weight:700">
        <i class="fa-solid fa-xmark"></i> Cancel run #<?= $blocking_run_id ?>
      </button>
    </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- Tabs Navigation -->
<div class="tab-bar" style="margin-bottom:20px">
  <div class="tab-item active" onclick="switchTab(this,'tab-run')"><i class="fa-solid fa-play"></i> Run Reconciliation</div>
  <div class="tab-item" onclick="switchTab(this,'tab-results')"><i class="fa-solid fa-chart-column"></i> View Results</div>
  <div class="tab-item" onclick="switchTab(this,'tab-history')"><i class="fa-solid fa-clock-rotate-left"></i> History</div>
</div>

<!-- TAB 1: Run Reconciliation (uses already-ingested data) -->
<div id="tab-run" class="tab-content" style="display:block">
  <div class="panel">
    <div class="panel-header">
      <span class="panel-title">Reconciliation Parameters</span>
      <span class="dim" style="float:right;font-size:11px;margin-top:4px">
        Running against: <strong><?= date('F Y', strtotime($default_from)) ?></strong>
        (latest period with both sales &amp; receipts)
      </span>
    </div>
    <div class="panel-body">
      <form method="POST" action="../process/process_reconciliation.php" id="params-form">
      <?= csrf_field() ?>
        <input type="hidden" name="action" value="run_with_db">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Product</label>
            <select name="product" class="form-select">
              <option value="All Products">All Products</option>
              <option value="Zinara">Zinara (Vehicle Licensing)</option>
              <option value="PPA">PPA (Passenger Protection Insurance)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Agent</label>
            <select name="agent_id" class="form-select">
              <option value="0">All Agents</option>
              <?php foreach ($agents as $ag): ?>
              <option value="<?= $ag['id'] ?>" <?= $prefill_agent_id === (int)$ag['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ag['agent_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Period Type</label>
            <select name="period_type" class="form-select">
              <option value="Monthly">Monthly</option>
              <option value="Daily">Daily</option>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">From Date</label>
            <input type="date" name="date_from" class="form-input" value="<?= htmlspecialchars($default_from) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">To Date</label>
            <input type="date" name="date_to" class="form-input" value="<?= htmlspecialchars($default_to) ?>" required>
          </div>
        </div>

        <div style="background:#f9f9f9;border:1px solid #e0e0e0;border-radius:4px;padding:14px;margin-top:16px">
          <div style="font-size:11px;font-weight:600;margin-bottom:10px;color:#888;letter-spacing:0.5px">MATCHING OPTIONS</div>
          <label style="display:flex;align-items:center;gap:10px;margin-bottom:8px;cursor:pointer;font-size:12px">
            <input type="checkbox" name="match_terminal" checked style="accent-color:var(--accent);width:14px;height:14px">
            Match by Terminal ID + Date + Amount
          </label>
          <label style="display:flex;align-items:center;gap:10px;margin-bottom:8px;cursor:pointer;font-size:12px">
            <input type="checkbox" name="match_reference" style="accent-color:var(--accent);width:14px;height:14px">
            Match by Reference Number
          </label>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:12px">
            <input type="checkbox" name="flag_currency" checked style="accent-color:var(--accent);width:14px;height:14px">
            Flag Currency Mismatches (ZWG vs USD)
          </label>
        </div>

        <!-- Selected-files summary (updated by JS when the modal closes) -->
        <div id="selected-files-box" style="display:none;background:#eaf7ef;border:1px solid #00a950;border-radius:4px;padding:10px 12px;margin-top:16px;font-size:12px">
          <strong><i class="fa-solid fa-check-square-o"></i> Scoped to selected files:</strong>
          <span id="selected-files-summary" style="margin-left:6px"></span>
          <a href="#" onclick="clearSelectedFiles(); return false;" style="margin-left:10px;color:#c0392b">clear</a>
        </div>

        <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
          <button type="submit" class="btn btn-primary" style="font-size:13px;padding:10px 22px;font-weight:700">
            <i class="fa-solid fa-play"></i> RUN RECONCILIATION
          </button>
          <button type="button" class="btn btn-ghost" onclick="openFileSelectModal()" style="font-size:12px;font-weight:700">
            <i class="fa-solid fa-check-square-o"></i> SELECT FILES <span id="file-count-badge" style="background:#00a950;color:#fff;border-radius:10px;padding:1px 8px;margin-left:4px;font-size:10px;display:none">0</span>
          </button>
          <button type="reset" class="btn btn-ghost" style="font-size:12px;font-weight:700">
            <i class="fa fa-refresh"></i> RESET
          </button>
          <a href="<?= BASE_URL ?>/utilities/uploaded_files_list.php" class="btn btn-ghost" style="font-size:12px;font-weight:700;margin-left:auto">
            <i class="fa-solid fa-folder-open-o"></i> SOURCE FILES
          </a>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- TAB 2: View Current Results -->
<div id="tab-results" class="tab-content" style="display:none">
<div class="panel">
  <div class="panel-header"><span class="panel-title">Latest Reconciliation Results</span></div>
  <div class="panel-body">
    <?php
    // Get the most recent completed run
    $latest_stmt = $db->prepare("
      SELECT r.id, r.run_status FROM reconciliation_runs r
      WHERE r.run_status = 'complete' OR r.run_status = 'failed'
      ORDER BY r.started_at DESC LIMIT 1
    ");
    $latest_stmt->execute();
    $latest_run = $latest_stmt->get_result()->fetch_assoc();
    $latest_stmt->close();

    if ($latest_run) {
      // Get variance results for latest run
      $res_stmt = $db->prepare("
        SELECT 
          SUM(CASE WHEN recon_status = 'reconciled' THEN 1 ELSE 0 END) as reconciled_count,
          SUM(CASE WHEN recon_status = 'variance' THEN 1 ELSE 0 END) as variance_count,
          SUM(variance_zwg) as total_variance_zwg,
          SUM(variance_usd) as total_variance_usd,
          COUNT(*) as total_agents
        FROM variance_results
        WHERE run_id = ?
      ");
      $res_stmt->bind_param('i', $latest_run['id']);
      $res_stmt->execute();
      $stats = $res_stmt->get_result()->fetch_assoc();
      $res_stmt->close();

      $match_rate = $stats['total_agents'] > 0 ? round(($stats['reconciled_count'] / $stats['total_agents']) * 100) : 0;
    ?>
    <div class="stat-grid">
      <div class="stat-card green">
        <div class="stat-value"><?= $stats['reconciled_count'] ?? 0 ?></div>
        <div class="stat-sub">Reconciled Agents</div>
      </div>
      <div class="stat-card warn">
        <div class="stat-value"><?= $stats['variance_count'] ?? 0 ?></div>
        <div class="stat-sub">With Variances</div>
      </div>
      <div class="stat-card red">
        <div class="stat-value" title="ZWG <?= number_format($stats['total_variance_zwg'] ?? 0, 2) ?>"><?= fmt_compact($stats['total_variance_zwg'] ?? 0) ?></div>
        <div class="stat-sub">Total Variance ZWG</div>
      </div>
      <div class="stat-card blue">
        <div class="stat-value"><?= $match_rate ?>%</div>
        <div class="stat-sub">Match Rate</div>
      </div>
    </div>
    <div style="margin-top:16px;padding:12px;background:#f9f9f9;border-radius:3px;border-left:3px solid #0066cc">
      <a href="reconciliation_results.php?run_id=<?= $latest_run['id'] ?>" style="color:#0066cc;text-decoration:none;font-weight:600">
        <i class="fa-solid fa-arrow-up-right-from-square"></i> View full results and per-agent details →
      </a>
    </div>
    <?php } else { ?>
    <div style="text-align:center;padding:40px;color:#888">
      <i class="fa fa-info-circle" style="font-size:24px;margin-bottom:10px"></i>
      <p>No reconciliation results yet. Pick a period above and click RUN to generate results.</p>
    </div>
    <?php } ?>
  </div>
</div>
</div>

<!-- TAB 3: Historical Runs -->
<div id="tab-history" class="tab-content" style="display:none">
<div class="panel">
  <div class="panel-header"><span class="panel-title">Reconciliation Runs History</span></div>
  <table class="data-table">
    <thead><tr><th>Period</th><th>Product</th><th>Started</th><th>By</th><th>Status</th><th>Matched</th><th>Unmatched</th><th>Action</th></tr></thead>
    <tbody>
      <?php foreach ($runs as $r): ?>
      <tr>
        <td class="mono" style="font-size:11px"><?= htmlspecialchars($r['period_label']) ?></td>
        <td class="dim"><?= htmlspecialchars($r['product']) ?></td>
        <td class="mono dim" style="font-size:11px"><?= date('Y-m-d H:i', strtotime($r['started_at'])) ?></td>
        <td><?= htmlspecialchars($r['full_name']) ?></td>
        <td><span class="badge <?= $r['run_status']==='complete'?'complete':($r['run_status']==='failed'?'failed':'running') ?>">
          <?= $r['run_status']==='complete'?'COMPLETE':($r['run_status']==='failed'?'FAILED':'RUNNING') ?></span></td>
        <td class="mono" style="font-weight:600;color:#00a950">
          <?php if ($r['matched_count'] !== null): ?>
            <?= (int)$r['matched_count'] ?>
            <?php if ($r['match_rate'] !== null): ?>
            <span style="font-size:10px;color:#888;font-weight:400">(<?= number_format($r['match_rate'], 0) ?>%)</span>
            <?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="mono" style="font-weight:600;color:#c0392b">
          <?= $r['unmatched_receipts'] !== null ? (int)$r['unmatched_receipts'] : '—' ?>
        </td>
        <td>
          <a href="reconciliation_results.php?run_id=<?= $r['id'] ?>" class="btn btn-ghost btn-sm" style="font-weight:700"><i class="fa-solid fa-eye"></i> VIEW DETAILS</a>
          <?php if ($r['run_status'] === 'running'): ?>
          <form method="POST" action="../process/process_reconciliation.php" style="display:inline" onsubmit="return confirm('Cancel run #<?= (int)$r['id'] ?>? This marks it as failed so a new run can start.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_run">
            <input type="hidden" name="run_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="color:#c0392b;font-weight:700"><i class="fa-solid fa-xmark"></i> CANCEL</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($runs)): ?>
      <tr><td colspan="8" class="dim" style="text-align:center;padding:20px">No runs yet. Run your first reconciliation from the tab above.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</div>

<!-- ══ SELECT FILES MODAL ══ -->
<div id="file-select-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:880px;max-width:95vw;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.18)">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600"><i class="fa-solid fa-check-square-o"></i>&nbsp; Select Files for Reconciliation</span>
      <button onclick="closeFileSelectModal()" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <div style="padding:16px 20px;background:#f9f9f9;border-bottom:1px solid #eee;font-size:12px;color:#555">
      Pick one or more Sales files and one or more Receipts files to reconcile. Leave everything unchecked to run against all files in the selected date range.
    </div>
    <div style="padding:20px;overflow-y:auto;flex:1;display:grid;grid-template-columns:1fr 1fr;gap:20px">

      <!-- Sales column -->
      <div>
        <div style="font-size:12px;font-weight:700;color:#00a950;margin-bottom:8px;display:flex;justify-content:space-between">
          <span><i class="fa-solid fa-chart-column"></i>&nbsp; Sales Files (<?= count($sales_uploads) ?>)</span>
          <a href="#" onclick="toggleAll('sales', true); return false;" style="font-size:11px;font-weight:400">select all</a>
        </div>
        <?php if (empty($sales_uploads)): ?>
        <div class="dim" style="font-size:12px;padding:14px;border:1px dashed #ddd;border-radius:3px;text-align:center">No sales files uploaded yet.</div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:6px;max-height:420px;overflow-y:auto">
          <?php foreach ($sales_uploads as $u): ?>
          <label class="file-select-row" style="display:flex;gap:10px;padding:10px 12px;border:1px solid #e0e0e0;border-radius:4px;cursor:pointer;transition:all 0.15s">
            <input type="checkbox" class="fs-sales-cb" value="<?= (int)$u['id'] ?>"
                   data-filename="<?= htmlspecialchars($u['filename']) ?>"
                   style="margin-top:2px;accent-color:#00a950;width:14px;height:14px">
            <div style="flex:1;min-width:0">
              <div style="font-size:12px;font-weight:600;color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($u['filename']) ?></div>
              <div style="font-size:10px;color:#888;margin-top:2px">
                <?= fmt_compact($u['record_count']) ?> rows &middot;
                <?= date('M d', strtotime($u['created_at'])) ?> &middot;
                <?= htmlspecialchars($u['uploader_name']) ?>
                <?php if ($u['upload_status'] === 'warning'): ?>
                &middot; <span style="color:#8a5a00">warn</span>
                <?php endif; ?>
              </div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Receipts column -->
      <div>
        <div style="font-size:12px;font-weight:700;color:#0066cc;margin-bottom:8px;display:flex;justify-content:space-between">
          <span><i class="fa-solid fa-file-lines-o"></i>&nbsp; Receipts Files (<?= count($receipts_uploads) ?>)</span>
          <a href="#" onclick="toggleAll('receipts', true); return false;" style="font-size:11px;font-weight:400">select all</a>
        </div>
        <?php if (empty($receipts_uploads)): ?>
        <div class="dim" style="font-size:12px;padding:14px;border:1px dashed #ddd;border-radius:3px;text-align:center">No receipts files uploaded yet.</div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:6px;max-height:420px;overflow-y:auto">
          <?php foreach ($receipts_uploads as $u): ?>
          <label class="file-select-row" style="display:flex;gap:10px;padding:10px 12px;border:1px solid #e0e0e0;border-radius:4px;cursor:pointer;transition:all 0.15s">
            <input type="checkbox" class="fs-receipts-cb" value="<?= (int)$u['id'] ?>"
                   data-filename="<?= htmlspecialchars($u['filename']) ?>"
                   style="margin-top:2px;accent-color:#0066cc;width:14px;height:14px">
            <div style="flex:1;min-width:0">
              <div style="font-size:12px;font-weight:600;color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($u['filename']) ?></div>
              <div style="font-size:10px;color:#888;margin-top:2px">
                <?= fmt_compact($u['record_count']) ?> rows &middot;
                <?= date('M d', strtotime($u['created_at'])) ?> &middot;
                <?= htmlspecialchars($u['uploader_name']) ?>
                <?php if ($u['upload_status'] === 'warning'): ?>
                &middot; <span style="color:#8a5a00">warn</span>
                <?php endif; ?>
              </div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>
    <div style="padding:14px 20px;border-top:1px solid #e0e0e0;display:flex;gap:8px;justify-content:flex-end;background:#fafafa">
      <a href="#" onclick="toggleAll('sales', false); toggleAll('receipts', false); return false;" style="align-self:center;font-size:11px;margin-right:auto">clear all</a>
      <button type="button" class="btn btn-ghost" onclick="closeFileSelectModal()">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="applyFileSelection()">Apply Selection</button>
    </div>
  </div>
</div>

<style>
.file-select-row:hover { border-color:#00a950; background:#f5fbf7 }
.file-select-row input:checked + div { color:#00a950 }
</style>

<script>
function switchTab(el, tabId) {
  document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
  document.querySelectorAll('.tab-item').forEach(t => t.classList.remove('active'));
  document.getElementById(tabId).style.display = 'block';
  el.classList.add('active');
}

// ── File selection modal ────────────────────────────────────
function openFileSelectModal() {
  document.getElementById('file-select-modal').style.display = 'flex';
}
function closeFileSelectModal() {
  document.getElementById('file-select-modal').style.display = 'none';
}
function toggleAll(kind, state) {
  document.querySelectorAll('.fs-' + kind + '-cb').forEach(cb => cb.checked = state);
}

function applyFileSelection() {
  const form = document.getElementById('params-form');
  // Remove any previously-injected hidden inputs
  form.querySelectorAll('input[data-fs-hidden="1"]').forEach(n => n.remove());

  const salesChecked    = Array.from(document.querySelectorAll('.fs-sales-cb:checked'));
  const receiptsChecked = Array.from(document.querySelectorAll('.fs-receipts-cb:checked'));

  // Inject hidden inputs so the selection posts with the form
  salesChecked.forEach(cb => {
    const h = document.createElement('input');
    h.type = 'hidden'; h.name = 'sales_upload_ids[]'; h.value = cb.value;
    h.setAttribute('data-fs-hidden', '1');
    form.appendChild(h);
  });
  receiptsChecked.forEach(cb => {
    const h = document.createElement('input');
    h.type = 'hidden'; h.name = 'receipts_upload_ids[]'; h.value = cb.value;
    h.setAttribute('data-fs-hidden', '1');
    form.appendChild(h);
  });

  // Update the count badge on the button
  const total = salesChecked.length + receiptsChecked.length;
  const badge = document.getElementById('file-count-badge');
  badge.textContent = total;
  badge.style.display = total > 0 ? 'inline-block' : 'none';

  // Show the summary strip above the Run button
  const box = document.getElementById('selected-files-box');
  const sum = document.getElementById('selected-files-summary');
  if (total > 0) {
    const salesNames    = salesChecked.map(cb => cb.dataset.filename);
    const receiptsNames = receiptsChecked.map(cb => cb.dataset.filename);
    const parts = [];
    if (salesNames.length)    parts.push(salesNames.length    + ' sales (' + salesNames.slice(0, 2).join(', ') + (salesNames.length > 2 ? '…' : '') + ')');
    if (receiptsNames.length) parts.push(receiptsNames.length + ' receipts (' + receiptsNames.slice(0, 2).join(', ') + (receiptsNames.length > 2 ? '…' : '') + ')');
    sum.textContent = parts.join('  ·  ');
    box.style.display = 'block';
  } else {
    box.style.display = 'none';
  }

  closeFileSelectModal();
}

function clearSelectedFiles() {
  toggleAll('sales', false);
  toggleAll('receipts', false);
  applyFileSelection();
}

// Close modal on backdrop click
document.getElementById('file-select-modal').addEventListener('click', function(e) {
  if (e.target === this) closeFileSelectModal();
});
</script>


<?php require_once '../layouts/layout_footer.php'; ?>
