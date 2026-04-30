<?php
// ============================================================
// utilities/upload.php
// Main upload form (multi-file) for Sales and Receipts data.
// ============================================================
$page_title = 'Upload Files';
$active_nav = 'upload';
require_once '../layouts/layout_header.php';
require_role(['Manager','Uploader','Admin']); // Reconcilers cannot upload

$db      = get_db();
$success = htmlspecialchars($_GET['success'] ?? '');
$error   = htmlspecialchars($_GET['error']   ?? '');

// Load this week's upload history (last 7 days, max 5 files)
$history = $db->query(
    "SELECT uh.*, u.full_name
     FROM upload_history uh
     JOIN users u ON uh.uploaded_by = u.id
     WHERE uh.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     ORDER BY uh.created_at DESC LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);

// Load agents for source dropdown
$agents = $db->query("SELECT id, agent_name FROM agents WHERE is_active=1 ORDER BY agent_name")->fetch_all(MYSQLI_ASSOC);

// Load distinct report types and sources previously used, scoped by file_type.
// These power the datalist suggestions on the combo inputs below — the user
// can pick an existing value or type a brand-new one.
function load_distinct($db, $col, $file_type) {
    $stmt = $db->prepare("SELECT DISTINCT $col FROM upload_history WHERE file_type = ? AND $col IS NOT NULL AND $col <> '' ORDER BY $col");
    $stmt->bind_param('s', $file_type);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $out = array();
    foreach ($rows as $r) $out[] = $r[$col];
    return $out;
}
$sales_report_types     = load_distinct($db, 'report_type', 'Sales');
$sales_sources          = load_distinct($db, 'source_name', 'Sales');
$receipts_report_types  = load_distinct($db, 'report_type', 'Receipts');
$receipts_sources       = load_distinct($db, 'source_name', 'Receipts');
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:12px">
    <div>
      <h1>Upload Source Files</h1>
      <p>Upload reports from Icecash, Banks, Brokers, and EcoCash for automated processing.</p>
    </div>
    <a href="uploaded_files_list.php" class="btn btn-ghost" style="font-weight:700;white-space:nowrap"><i class="fa-solid fa-folder-open"></i> VIEW ALL FILES</a>
  </div>
</div>

<?php
// `white-space:pre-line` lets the headline + per-file detail land on
// separate lines inside the alert. The flash message puts \n between them;
// htmlspecialchars preserves that without needing to inject HTML.
// `align-items:flex-start` stops the icon from vertically centring on a
// multi-line message.
$alert_style = 'white-space:pre-line;align-items:flex-start';
?>
<?php if ($success): ?><div class="alert alert-success" style="<?= $alert_style ?>">✓ <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"  style="<?= $alert_style ?>">⚠ <?= $error ?></div><?php endif; ?>

<div class="two-col">
  <!-- Sales Upload -->
  <div class="panel">
    <div class="panel-header"><span class="panel-title">📊 Sales Data Upload</span></div>
    <div class="panel-body">
      <form method="POST" action="../process/process_upload.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
        <input type="hidden" name="file_type" value="Sales">
        <div class="form-group">
          <label class="form-label">Report Type</label>
          <input type="text" name="report_type" class="form-input" list="sales-report-types" placeholder="Type a new report type or pick an existing one" required>
          <datalist id="sales-report-types">
            <?php foreach ($sales_report_types as $rt): ?>
              <option value="<?= htmlspecialchars($rt) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="form-group">
          <label class="form-label">Source</label>
          <input type="text" name="source_name" class="form-input" list="sales-sources" placeholder="Type a new source or pick an existing one" required>
          <datalist id="sales-sources">
            <?php foreach ($sales_sources as $src): ?>
              <option value="<?= htmlspecialchars($src) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Period From</label>
            <input type="date" name="period_from" class="form-input" value="<?= date('Y-m-01') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Period To</label>
            <input type="date" name="period_to" class="form-input" value="<?= date('Y-m-d') ?>">
          </div>
        </div>
        <div class="upload-zone" onclick="document.getElementById('sales-file').click()" id="sales-zone">
          <div class="upload-icon">⬆</div>
          <div class="upload-text">Drop files here or <strong>click to browse</strong></div>
          <div class="upload-formats">.xlsx &nbsp;·&nbsp; .xls &nbsp;·&nbsp; .csv &nbsp;·&nbsp; .pdf &nbsp;·&nbsp; <strong>multiple files supported</strong></div>
        </div>
        <input type="file" id="sales-file" name="upload_file[]" style="display:none" multiple
               accept=".xlsx,.xls,.csv,.pdf" onchange="showFiles(this,'sales-info')">
        <div id="sales-info" style="margin-top:10px;font-size:12px;color:#666"></div>
        <div style="margin-top:16px;display:flex;gap:8px">
          <button type="submit" class="btn btn-primary">↑ Upload &amp; Process</button>
          <button type="button" class="btn btn-ghost" onclick="clearFiles('sales')">Clear</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Receipts Upload -->
  <div class="panel">
    <div class="panel-header"><span class="panel-title">🏦 Receipts Data Upload</span></div>
    <div class="panel-body">
      <form method="POST" action="../process/process_upload.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
        <input type="hidden" name="file_type" value="Receipts">
        <div class="form-group">
          <label class="form-label">Source Type</label>
          <input type="text" name="report_type" class="form-input" list="rec-report-types" placeholder="Type a new source type or pick an existing one" required>
          <datalist id="rec-report-types">
            <?php foreach ($receipts_report_types as $rt): ?>
              <option value="<?= htmlspecialchars($rt) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="form-group">
          <label class="form-label">Bank / Institution</label>
          <input type="text" name="source_name" class="form-input" list="rec-sources" placeholder="Type a new bank or pick an existing one" required>
          <datalist id="rec-sources">
            <?php foreach ($receipts_sources as $src): ?>
              <option value="<?= htmlspecialchars($src) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Period From</label>
            <input type="date" name="period_from" class="form-input" value="<?= date('Y-m-01') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Period To</label>
            <input type="date" name="period_to" class="form-input" value="<?= date('Y-m-d') ?>">
          </div>
        </div>
        <div class="upload-zone" onclick="document.getElementById('rec-file').click()" id="rec-zone">
          <div class="upload-icon">⬆</div>
          <div class="upload-text">Drop files here or <strong>click to browse</strong></div>
          <div class="upload-formats">.xlsx &nbsp;·&nbsp; .xls &nbsp;·&nbsp; .csv &nbsp;·&nbsp; .pdf &nbsp;·&nbsp; <strong>multiple files supported</strong></div>
        </div>
        <input type="file" id="rec-file" name="upload_file[]" style="display:none" multiple
               accept=".xlsx,.xls,.csv,.pdf" onchange="showFiles(this,'rec-info')">
        <div id="rec-info" style="margin-top:10px;font-size:12px;color:#666"></div>
        <div style="margin-top:16px;display:flex;gap:8px">
          <button type="submit" class="btn btn-primary">↑ Upload &amp; Process</button>
          <button type="button" class="btn btn-ghost" onclick="clearFiles('rec')">Clear</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Upload History -->
<div class="panel">
  <div class="panel-header">
    <span class="panel-title">This Week's Uploads (Last 7 Days)</span>
  </div>
  <table class="data-table">
    <thead>
      <tr><th>Filename</th><th>Type</th><th>Report / Source</th><th>Records</th><th>Uploaded By</th><th>Time</th><th>Validation</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($history as $h): ?>
    <tr>
      <td class="mono" style="font-size:11px"><?= htmlspecialchars($h['filename']) ?></td>
      <td><span class="badge <?= $h['file_type']==='Sales'?'matched':'reconciled' ?>"><?= $h['file_type'] ?></span></td>
      <td class="dim" style="font-size:12px"><?= htmlspecialchars($h['report_type']) ?> / <?= htmlspecialchars($h['source_name']) ?></td>
      <td class="mono"><?= $h['record_count'] !== null ? number_format($h['record_count']) : '—' ?></td>
      <td><?= htmlspecialchars($h['full_name']) ?></td>
      <td class="mono dim" style="font-size:11px"><?= date('H:i', strtotime($h['created_at'])) ?></td>
      <td style="font-size:11px;color:<?= $h['upload_status']==='ok'?'var(--accent)':($h['upload_status']==='warning'?'var(--warn)':'var(--danger)') ?>">
        <?= htmlspecialchars($h['validation_msg']) ?>
      </td>
      <td><span class="badge <?= $h['upload_status']==='ok'?'reconciled':($h['upload_status']==='warning'?'pending':'variance') ?>">
        <?= ucfirst($h['upload_status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($history)): ?>
    <tr><td colspan="8" class="dim" style="text-align:center;padding:20px">No uploads this week. Upload a file above to get started.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
// File accumulator — users can browse/drop multiple times and files stack up
var fileStore = { sales: [], rec: [] };

function addFiles(type, newFiles) {
  var existing = fileStore[type].map(function(f) { return f.name + '|' + f.size; });
  for (var i = 0; i < newFiles.length; i++) {
    var key = newFiles[i].name + '|' + newFiles[i].size;
    if (existing.indexOf(key) === -1) {
      fileStore[type].push(newFiles[i]);
      existing.push(key);
    }
  }
  renderFileList(type);
}

function removeFile(type, idx) {
  fileStore[type].splice(idx, 1);
  renderFileList(type);
}

function clearFiles(type) {
  fileStore[type] = [];
  var inp = document.getElementById(type === 'sales' ? 'sales-file' : 'rec-file');
  if (inp) inp.value = '';
  renderFileList(type);
}

function renderFileList(type) {
  var box = document.getElementById(type === 'sales' ? 'sales-info' : 'rec-info');
  var files = fileStore[type];
  if (!files.length) { box.innerHTML = ''; return; }
  var totalBytes = 0;
  var rows = files.map(function(f, i) {
    totalBytes += f.size;
    var sz = f.size > 1048576 ? (f.size/1048576).toFixed(1)+' MB' : (f.size/1024).toFixed(1)+' KB';
    return '<div style="padding:3px 0;display:flex;align-items:center;gap:8px">'
      + '<strong>' + f.name + '</strong> <span style="color:#888">' + sz + '</span>'
      + ' <a href="#" onclick="removeFile(\'' + type + '\',' + i + ');return false" style="color:#c0392b;font-size:11px;text-decoration:none">remove</a>'
      + '</div>';
  });
  var totalStr = totalBytes > 1048576 ? (totalBytes/1048576).toFixed(1)+' MB' : (totalBytes/1024).toFixed(1)+' KB';
  var warnings = '';
  files.forEach(function(f) {
    if (f.size > 50*1048576) warnings += '<div style="color:#c0392b;font-weight:600;margin-top:4px">⚠ ' + f.name + ' exceeds 50MB limit</div>';
  });
  if (totalBytes > 100*1048576) warnings += '<div style="color:#c0392b;font-weight:600;margin-top:4px">⚠ Total size exceeds 100MB limit</div>';
  box.innerHTML = rows.join('') +
    '<div style="margin-top:6px;padding-top:6px;border-top:1px solid #eee;color:#00a950;font-weight:600">' +
    files.length + ' file' + (files.length>1?'s':'') + ' selected · ' + totalStr + ' total</div>' + warnings;
}

// Native file input change → accumulate
function showFiles(input, infoId) {
  var type = infoId === 'sales-info' ? 'sales' : 'rec';
  addFiles(type, Array.from(input.files || []));
  input.value = ''; // reset so same file can be re-added after remove
}

// Intercept form submit — inject accumulated files via FormData
document.querySelectorAll('form[action*="process_upload"]').forEach(function(form, idx) {
  var type = idx === 0 ? 'sales' : 'rec';
  form.addEventListener('submit', function(e) {
    if (fileStore[type].length === 0) return; // no JS files, let native handle
    e.preventDefault();
    var fd = new FormData(this);
    fd.delete('upload_file[]');
    fileStore[type].forEach(function(f) { fd.append('upload_file[]', f); });
    var btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Uploading ' + fileStore[type].length + ' files...'; }
    fetch(this.action, { method: 'POST', body: fd })
      .then(function(r) { window.location.href = r.url; })
      .catch(function(err) { alert('Upload failed: ' + err); if (btn) { btn.disabled = false; btn.textContent = '↑ Upload & Process'; } });
  });
});

// Drag and drop — accumulate files
['sales-zone','rec-zone'].forEach(function(id) {
  var z = document.getElementById(id);
  if (!z) return;
  var type = id === 'sales-zone' ? 'sales' : 'rec';
  z.addEventListener('dragover', function(e) { e.preventDefault(); z.style.borderColor='var(--accent)'; });
  z.addEventListener('dragleave', function() { z.style.borderColor=''; });
  z.addEventListener('drop', function(e) {
    e.preventDefault(); z.style.borderColor='';
    addFiles(type, Array.from(e.dataTransfer.files));
  });
});
</script>

<?php require_once '../layouts/layout_footer.php'; ?>