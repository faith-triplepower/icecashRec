<?php
// ============================================================
// utilities/uploaded_file_detail.php
// View file contents and manage upload
// ============================================================
$page_title = 'File Details';
$active_nav = 'upload';
require_once '../layouts/layout_header.php';

require_role(['Manager','Reconciler','Uploader','Admin']);

$db   = get_db();
$user = current_user();
$role = $user['role'];
$upload_id = (int)($_GET['id'] ?? 0);

// Reconcilers get a read-only view + flag-back ability. Delete is
// reserved for Manager/Admin so source data can't be lost in a tab.
$can_delete = in_array($role, array('Manager','Admin','Uploader'));
$can_flag   = in_array($role, array('Reconciler','Manager','Admin'));
$can_upload = in_array($role, array('Uploader','Manager','Admin'));

if (!$upload_id) {
    header('Location: uploaded_files_list.php?error=Invalid+file+ID');
    exit;
}

// Get upload details
$upload = $db->query("
    SELECT uh.id, uh.filename, uh.file_type, uh.report_type, uh.source_name, 
           uh.period_from, uh.period_to, uh.upload_status, uh.record_count,
           uh.validation_msg, uh.flag_status, uh.flag_reason, uh.flag_note,
           uh.flagged_at, uh.uploaded_by, uh.created_at,
           u.full_name, u.email AS uploader_email,
           fb.full_name AS flagged_by_name
    FROM upload_history uh
    JOIN users u ON uh.uploaded_by = u.id
    LEFT JOIN users fb ON uh.flagged_by = fb.id
    WHERE uh.id = $upload_id
")->fetch_assoc();

if (!$upload) {
    header('Location: uploaded_files_list.php?error=File+not+found');
    exit;
}

// Uploaders can only view their own files
if ($user['role'] === 'Uploader' && (int)$upload['uploaded_by'] !== (int)$user['id']) {
    header('Location: uploaded_files_list.php?error=' . urlencode('You can only view your own uploads'));
    exit;
}

// Get associated records
if ($upload['file_type'] === 'Sales') {
    $records = $db->query("
        SELECT s.*, a.agent_name
        FROM sales s
        LEFT JOIN agents a ON s.agent_id = a.id
        WHERE s.upload_id = $upload_id
        ORDER BY s.id DESC LIMIT 100
    ")->fetch_all(MYSQLI_ASSOC);
    $total_records = $db->query("SELECT COUNT(*) as cnt FROM sales WHERE upload_id = $upload_id")->fetch_assoc()['cnt'];
} else {
    $records = $db->query("
        SELECT r.*
        FROM receipts r
        WHERE r.upload_id = $upload_id
        ORDER BY r.id DESC LIMIT 100
    ")->fetch_all(MYSQLI_ASSOC);
    $total_records = $db->query("SELECT COUNT(*) as cnt FROM receipts WHERE upload_id = $upload_id")->fetch_assoc()['cnt'];
}
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:12px">
    <div>
      <h1><?= htmlspecialchars($upload['filename']) ?></h1>
      <p><?= htmlspecialchars($upload['file_type']) ?> • <?= htmlspecialchars($upload['report_type']) ?> • <?= number_format($total_records) ?> records</p>
    </div>
    <div style="display:flex;gap:8px">
      <a href="uploaded_files_list.php" class="btn btn-ghost" style="font-weight:700"><i class="fa-solid fa-arrow-left"></i> BACK</a>
      <?php if ($can_flag && $upload['flag_status'] !== 'flagged'): ?>
      <a href="#" onclick="openFlagModal(); return false;" class="btn btn-ghost" style="font-weight:700;color:#8a5a00"><i class="fa-solid fa-flag"></i> SEND BACK TO UPLOADER</a>
      <?php endif; ?>
      <?php if ($can_delete): ?>
      <a href="#" onclick="deleteFile(); return false;" class="btn btn-ghost" style="font-weight:700;color:#c0392b"><i class="fa-solid fa-trash"></i> DELETE FILE</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($upload['flag_status'] === 'flagged'): ?>
<div class="alert" style="background:#fff4d6;border-left:4px solid #c0392b;color:#5a0000;margin-bottom:16px">
  <strong><i class="fa-solid fa-flag"></i> File flagged for the uploader</strong>
  &middot; Reason: <strong><?= htmlspecialchars($upload['flag_reason']) ?></strong>
  &middot; By: <?= htmlspecialchars($upload['flagged_by_name'] ?? '—') ?>
  &middot; <?= date('M d H:i', strtotime($upload['flagged_at'])) ?>
  <?php if (!empty($upload['flag_note'])): ?>
  <div style="margin-top:6px;font-size:12px"><em><?= nl2br(htmlspecialchars($upload['flag_note'])) ?></em></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- File Info -->
<div class="panel" style="margin-bottom:20px">
  <div class="panel-header"><span class="panel-title">File Information</span></div>
  <div class="panel-body">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Original Filename</label>
        <input type="text" class="form-input" value="<?= htmlspecialchars($upload['filename']) ?>" readonly>
      </div>
      <div class="form-group">
        <label class="form-label">File Type</label>
        <input type="text" class="form-input" value="<?= htmlspecialchars($upload['file_type']) ?>" readonly>
      </div>
      <div class="form-group">
        <label class="form-label">Report Type</label>
        <input type="text" class="form-input" value="<?= htmlspecialchars($upload['report_type']) ?>" readonly>
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Source</label>
        <input type="text" class="form-input" value="<?= htmlspecialchars($upload['source_name']) ?>" readonly>
      </div>
      <div class="form-group">
        <label class="form-label">Uploaded By</label>
        <input type="text" class="form-input" value="<?= htmlspecialchars($upload['full_name']) ?>" readonly>
      </div>
      <div class="form-group">
        <label class="form-label">Upload Date</label>
        <input type="text" class="form-input" value="<?= date('Y-m-d H:i:s', strtotime($upload['created_at'])) ?>" readonly>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Period From</label>
        <?php if ($can_upload): ?>
        <input type="date" id="period_from" class="form-input" value="<?= htmlspecialchars($upload['period_from'] ?? '') ?>">
        <?php else: ?>
        <input type="text" class="form-input" value="<?= htmlspecialchars($upload['period_from'] ?? '') ?>" readonly>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Period To</label>
        <?php if ($can_upload): ?>
        <input type="date" id="period_to" class="form-input" value="<?= htmlspecialchars($upload['period_to'] ?? '') ?>">
        <?php else: ?>
        <input type="text" class="form-input" value="<?= htmlspecialchars($upload['period_to'] ?? '') ?>" readonly>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Records Imported</label>
        <input type="text" class="form-input" value="<?= number_format($upload['record_count'] ?? 0) ?>" readonly>
      </div>
    </div>
    <?php if ($can_upload): ?>
    <div style="margin-top:4px">
      <button type="button" id="save-period-btn" class="btn btn-primary btn-sm">
        <i class="fa-solid fa-floppy-disk"></i> Save Period
      </button>
      <span id="save-period-msg" style="margin-left:10px;font-size:12px;display:none"></span>
    </div>
    <?php endif; ?>

    <div style="background:#f9f9f9;border-left:3px solid <?= $upload['upload_status']==='ok'?'#00a950':($upload['upload_status']==='warning'?'#f39c12':'#c0392b') ?>;padding:12px;border-radius:3px;margin-top:16px">
      <strong>Status:</strong> 
      <span class="badge <?= $upload['upload_status']==='ok'?'reconciled':($upload['upload_status']==='warning'?'pending':'variance') ?>">
        <?= ucfirst($upload['upload_status']) ?>
      </span>
      <div style="margin-top:8px;font-size:12px;color:#666">
        <?= htmlspecialchars($upload['validation_msg']) ?>
      </div>
    </div>
  </div>
</div>

<!-- File Contents Preview -->
<div class="panel">
  <div class="panel-header"><span class="panel-title">Data Preview (First 100 records)</span></div>
  <div class="table-responsive">
    <table class="data-table" style="font-size:12px">
      <thead>
        <tr>
          <?php if ($upload['file_type'] === 'Sales'): ?>
            <th>Agent</th><th>Terminal</th><th>Policy</th><th>Date</th><th>Amount</th><th>Currency</th><th>Method</th><th>Reference</th>
          <?php else: ?>
            <th>Reference</th><th>Terminal</th><th>Date</th><th>Amount</th><th>Currency</th><th>Channel</th><th>Source</th><th>Status</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
          <?php if ($upload['file_type'] === 'Sales'): ?>
            <td><?= htmlspecialchars($r['agent_name'] ?? '-') ?></td>
            <td class="mono"><?= htmlspecialchars($r['terminal_id'] ?? '-') ?></td>
            <td class="mono"><?= htmlspecialchars($r['policy_number'] ?? '-') ?></td>
            <td class="mono"><?= htmlspecialchars($r['txn_date'] ?? '-') ?></td>
            <td class="mono" style="font-weight:600;color:#00a950"><?= number_format($r['amount'] ?? 0, 2) ?></td>
            <td class="mono"><?= htmlspecialchars($r['currency'] ?? 'ZWG') ?></td>
            <td><?= htmlspecialchars($r['payment_method'] ?? '-') ?></td>
            <td class="mono"><?= htmlspecialchars($r['reference_no'] ?? '-') ?></td>
          <?php else: ?>
            <td class="mono"><?= htmlspecialchars($r['reference_no'] ?? '-') ?></td>
            <td class="mono"><?= htmlspecialchars($r['terminal_id'] ?? '-') ?></td>
            <td class="mono"><?= htmlspecialchars($r['txn_date'] ?? '-') ?></td>
            <td class="mono" style="font-weight:600;color:#0066cc"><?= number_format($r['amount'] ?? 0, 2) ?></td>
            <td class="mono"><?= htmlspecialchars($r['currency'] ?? 'ZWG') ?></td>
            <td><?= htmlspecialchars($r['channel'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['source_name'] ?? '-') ?></td>
            <td><span class="badge <?= $r['match_status']==='matched'?'success':($r['match_status']==='pending'?'pending':'variance') ?>">
              <?= ucfirst($r['match_status']) ?></span></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($records)): ?>
        <tr><td colspan="8" class="dim" style="text-align:center;padding:20px">No records found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total_records > 100): ?>
  <div style="padding:12px;background:#f9f9f9;border-top:1px solid #e0e0e0;font-size:12px;color:#666">
    Showing 100 of <?= number_format($total_records) ?> records. <a href="#" onclick="alert('Download full dataset from reconciliation module'); return false;">Download full dataset</a>
  </div>
  <?php endif; ?>
</div>

<div style="margin-top:20px;display:flex;gap:8px">
  <a href="uploaded_files_list.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to Files</a>
  <?php if ($can_upload): ?>
  <a href="upload.php" class="btn btn-primary"><i class="fa fa-upload"></i> Upload Another File</a>
  <?php endif; ?>
</div>

<!-- ══ FLAG / SEND-BACK MODAL ══ -->
<?php if ($can_flag): ?>
<div id="flag-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:480px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600"><i class="fa-solid fa-flag" style="color:#8a5a00"></i>&nbsp; Send File Back to Uploader</span>
      <button onclick="document.getElementById('flag-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/flag_upload.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="upload_id" value="<?= $upload_id ?>">
      <p style="margin:0 0 12px">
        Send <strong><?= htmlspecialchars($upload['filename']) ?></strong> back to
        <strong><?= htmlspecialchars($upload['full_name']) ?></strong> for fixing.
        They'll see a FLAGGED badge on the file and receive an email notification.
      </p>
      <div class="form-group">
        <label class="form-label">Reason</label>
        <select name="reason" class="form-select" required>
          <option value="">-- Select --</option>
          <option value="wrong_type">Wrong file type (uploaded as Sales/Receipts incorrectly)</option>
          <option value="missing_columns">Missing required columns</option>
          <option value="bad_dates">Date column corrupted or unreadable</option>
          <option value="duplicate">Duplicate of a previous upload</option>
          <option value="wrong_period">Wrong reporting period</option>
          <option value="data_quality">Data quality issues (typos, blanks, garbage rows)</option>
          <option value="incomplete">File looks incomplete / truncated</option>
          <option value="other">Other (explain in note)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Note to Uploader (required)</label>
        <textarea name="note" class="form-input" style="height:90px;resize:vertical" required minlength="10" placeholder="Be specific — what's wrong, which rows or columns to check, what you need them to do."></textarea>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('flag-modal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary" style="background:#8a5a00;border-color:#8a5a00"><i class="fa-solid fa-flag"></i> Send Back</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
<?php if ($can_upload): ?>
document.getElementById('save-period-btn').addEventListener('click', function () {
    var btn = this;
    var msg = document.getElementById('save-period-msg');
    var from = document.getElementById('period_from').value;
    var to   = document.getElementById('period_to').value;

    btn.disabled = true;
    btn.textContent = 'Saving…';
    msg.style.display = 'none';

    var body = new URLSearchParams();
    body.append('upload_id',   '<?= $upload_id ?>');
    body.append('period_from', from);
    body.append('period_to',   to);
    body.append('_csrf',       '<?= csrf_token() ?>');

    fetch('../process/process_upload_period.php', {
        method: 'POST', body: body, credentials: 'same-origin'
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Period';
        msg.style.display = 'inline';
        if (data.ok) {
            msg.style.color = '#00a950';
            msg.textContent = 'Saved.';
        } else {
            msg.style.color = '#c0392b';
            msg.textContent = data.error || 'Save failed.';
        }
        setTimeout(function () { msg.style.display = 'none'; }, 3000);
    })
    .catch(function () {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Period';
        msg.style.display = 'inline';
        msg.style.color = '#c0392b';
        msg.textContent = 'Network error.';
    });
});
<?php endif; ?>
<?php if ($can_flag): ?>
function openFlagModal() {
  document.getElementById('flag-modal').style.display = 'flex';
}
document.getElementById('flag-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
<?php endif; ?>
<?php if ($can_delete): ?>
function deleteFile() {
  if (confirm('Are you sure you want to delete this file?\n\nThis will remove all associated sales/receipts records from the system.')) {
    fetch('../process/delete_upload.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'upload_id=<?= $upload_id ?>&_csrf=<?= csrf_token() ?>'
    })
    .then(r => r.text())
    .then(msg => {
      alert(msg);
      location.href = 'uploaded_files_list.php';
    });
  }
}
<?php endif; ?>
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
