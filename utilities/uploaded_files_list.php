<?php
// ============================================================
// utilities/uploaded_files_list.php
// List and manage all uploaded files
// ============================================================
$page_title = 'Uploaded Files Library';
$active_nav = 'upload';
require_once '../layouts/layout_header.php';

require_role(['Manager','Reconciler','Uploader','Admin']);

$db   = get_db();
$user = current_user();
$uid  = (int)$user['id'];
$role = $user['role'];

// Reconcilers get a read-only view: they can browse and open files,
// but cannot upload new ones, delete existing ones, or edit metadata.
// They can flag a file to send it back to the uploader for fixing.
$can_upload = in_array($role, array('Uploader','Manager','Admin'));
$can_delete = in_array($role, array('Manager','Admin','Uploader'));
$can_flag   = in_array($role, array('Reconciler','Manager','Admin'));

// Only Manager/Admin can cascade-cancel statements as part of a delete.
// Uploaders can delete files that DON'T feed any active statement, but
// if statements are blocking, the modal will show a "ask a Manager"
// notice instead of letting them through.
$can_force_delete = in_array($role, array('Manager','Admin'));

// Uploaders see only their own uploads. Everyone else sees all.
$scope_where = '';
$scope_params = array();
$scope_types  = '';
if ($role === 'Uploader') {
    $scope_where = ' WHERE uh.uploaded_by = ?';
    $scope_params[] = $uid;
    $scope_types    = 'i';
}

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$pg_offset = ($page - 1) * $per_page;

$sql = "
    SELECT uh.*, u.full_name,
           fb.full_name AS flagged_by_name,
           (SELECT COUNT(*) FROM sales WHERE upload_id = uh.id) as sales_count,
           (SELECT COUNT(*) FROM receipts WHERE upload_id = uh.id) as receipts_count
    FROM upload_history uh
    JOIN users u ON uh.uploaded_by = u.id
    LEFT JOIN users fb ON uh.flagged_by = fb.id
    $scope_where
    ORDER BY uh.created_at DESC
    LIMIT $per_page OFFSET $pg_offset
";
$stmt = $db->prepare($sql);
if ($scope_types) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$cnt_sql = "SELECT COUNT(*) c FROM upload_history uh $scope_where";
$cnt_s = $db->prepare($cnt_sql);
if ($scope_types) $cnt_s->bind_param($scope_types, ...$scope_params);
$cnt_s->execute();
$total_rows = (int)$cnt_s->get_result()->fetch_assoc()['c'];
$cnt_s->close();
$total_pages = max(1, ceil($total_rows / $per_page));

// Scoped stats: same WHERE as above
$stats_sql = "
    SELECT
        COUNT(*) as total_files,
        SUM(CASE WHEN upload_status='ok' THEN 1 ELSE 0 END) as ok_files,
        SUM(CASE WHEN upload_status='warning' THEN 1 ELSE 0 END) as warning_files,
        SUM(CASE WHEN upload_status='failed' THEN 1 ELSE 0 END) as failed_files,
        SUM(record_count) as total_records
    FROM upload_history uh
    $scope_where
";
$s_stmt = $db->prepare($stats_sql);
if ($scope_types) $s_stmt->bind_param($scope_types, ...$scope_params);
$s_stmt->execute();
$stats = $s_stmt->get_result()->fetch_assoc();
$s_stmt->close();
?>

<div class="page-header">
  <h1>Uploaded Files Library</h1>
  <p>View, manage, and analyze all uploaded data files.</p>
</div>

<!-- Stats Row -->
<div class="stat-grid">
  <div class="stat-card blue">
    <div class="stat-value"><?= $stats['total_files'] ?></div>
    <div class="stat-sub">Total Files</div>
  </div>
  <div class="stat-card green">
    <div class="stat-value"><?= $stats['ok_files'] ?></div>
    <div class="stat-sub">Successful</div>
  </div>
  <div class="stat-card warn">
    <div class="stat-value"><?= $stats['warning_files'] ?></div>
    <div class="stat-sub">With Warnings</div>
  </div>
  <div class="stat-card">
    <div class="stat-value" title="<?= number_format($stats['total_records'] ?? 0) ?>"><?= fmt_compact($stats['total_records'] ?? 0) ?></div>
    <div class="stat-sub">Total Records</div>
  </div>
</div>

<!-- Files Table -->
<div class="panel">
  <div class="panel-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="panel-title"><?= $role === 'Uploader' ? 'My Uploaded Files' : 'All Uploaded Files' ?></span>
    <?php if ($can_upload): ?>
    <a href="upload.php" class="btn btn-primary" style="font-weight:700;font-size:12px"><i class="fa fa-upload"></i> NEW UPLOAD</a>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Filename</th>
          <th>Type</th>
          <th>Report</th>
          <th style="text-align:right">Records</th>
          <th>Uploaded By</th>
          <th>Date</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($files as $f): ?>
        <tr>
          <td style="font-weight:500;font-size:12px"><?= htmlspecialchars($f['filename']) ?></td>
          <td><span class="badge"><?= htmlspecialchars($f['file_type']) ?></span></td>
          <td class="dim" style="font-size:11px"><?= htmlspecialchars($f['report_type']) ?></td>
          <td class="mono" style="font-weight:600;text-align:right" title="<?= $f['record_count'] !== null ? number_format($f['record_count']) . ' records' : '' ?>"><?= fmt_compact($f['record_count']) ?></td>
          <td><?= htmlspecialchars($f['full_name']) ?></td>
          <td class="mono dim" style="font-size:11px"><?= date('M d, Y H:i', strtotime($f['created_at'])) ?></td>
          <td>
            <span class="badge <?= $f['upload_status']==='ok'?'reconciled':($f['upload_status']==='warning'?'pending':'variance') ?>">
              <?= ucfirst($f['upload_status']) ?>
            </span>
            <?php if ($f['flag_status'] === 'flagged'): ?>
              <div class="badge variance" style="background:#f4c3c3;color:#8a0000;margin-top:3px" title="Flagged by <?= htmlspecialchars($f['flagged_by_name'] ?? '—') ?> — <?= htmlspecialchars($f['flag_note'] ?? '') ?>">
                <i class="fa-solid fa-flag"></i> FLAGGED
              </div>
            <?php elseif ($f['flag_status'] === 'resolved'): ?>
              <div class="badge reconciled" style="margin-top:3px">FIXED</div>
            <?php endif; ?>
          </td>
          <td style="display:flex;gap:6px;font-size:11px">
            <a href="uploaded_file_detail.php?id=<?= $f['id'] ?>" class="btn btn-ghost btn-sm" title="View"><i class="fa-solid fa-eye"></i></a>
            <?php if ($can_delete): ?>
            <a href="#" onclick="deleteFile(<?= $f['id'] ?>, '<?= htmlspecialchars($f['filename'], ENT_QUOTES) ?>'); return false;" class="btn btn-ghost btn-sm" title="Delete" style="color:#c0392b"><i class="fa-solid fa-trash"></i></a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($files)): ?>
        <tr><td colspan="8" class="dim" style="text-align:center;padding:20px">No uploaded files yet. <a href="upload.php">Upload your first file</a>.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php if ($total_pages > 1): ?>
    <div style="padding:12px 16px;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#666">
      <span>Page <?= $page ?> of <?= $total_pages ?> — <?= $total_rows ?> files</span>
      <div style="display:flex;gap:4px">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page-1 ?>">← Prev</a><?php endif; ?>
        <?php if ($page < $total_pages): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page+1 ?>">Next →</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================
     Delete confirmation modal
     Hidden by default. Two-phase flow:
       1. Click trash → modal opens, calls delete_upload.php?mode=preview
          to find out how many sales/receipts AND statements would
          be affected.
       2. User reviews, optionally expands the statement list, enters
          a reason, and confirms → calls delete_upload.php?mode=force
          which cascade-cancels the statements then deletes the upload.
     ============================================================ -->
<div id="delete-modal" class="del-modal" style="display:none">
  <div class="del-modal-backdrop" onclick="closeDeleteModal()"></div>
  <div class="del-modal-card" role="dialog" aria-modal="true" aria-labelledby="del-title">
    <div class="del-modal-header">
      <h3 id="del-title" style="margin:0">Delete upload</h3>
      <button type="button" class="del-modal-x" onclick="closeDeleteModal()" aria-label="Close">×</button>
    </div>

    <div class="del-modal-body">
      <div id="del-loading" class="dim">Checking impact…</div>

      <div id="del-content" style="display:none">
        <p>You are about to delete <strong id="del-filename"></strong>.</p>

        <div class="del-counts">
          <div><span class="del-num" id="del-sales">0</span> <span class="dim">sales</span></div>
          <div><span class="del-num" id="del-receipts">0</span> <span class="dim">receipts</span></div>
          <div id="del-stmts-wrap" style="display:none">
            <span class="del-num del-warn" id="del-stmts">0</span>
            <span class="dim">statement(s) will be cancelled</span>
          </div>
        </div>

        <!-- Warning banner shown only when statements are impacted AND user can force -->
        <div id="del-warning" class="del-banner" style="display:none">
          <strong>⚠ This will also cancel <span id="del-stmts-2">0</span> active statement(s).</strong>
          Cancelled statements are kept in the system for audit, but they will no
          longer be valid for the affected agent(s).
          <div style="margin-top:8px">
            <button type="button" class="btn btn-ghost btn-sm" onclick="toggleStmtList()">
              <i class="fa-solid fa-list"></i> <span id="del-toggle-label">View statements</span>
            </button>
          </div>
          <ul id="del-stmt-list" class="del-stmt-list" style="display:none"></ul>

          <label class="del-reason">
            <span>Reason for cancelling these statements
              <span class="dim">(min 5 chars, will be saved to the audit log)</span>:</span>
            <textarea id="del-reason" rows="2" placeholder="e.g. Wrong file uploaded — replacing with corrected version"></textarea>
          </label>
        </div>

        <!-- Permission denial banner if Uploader hits a blocked file -->
        <div id="del-noperm" class="del-banner del-banner-block" style="display:none">
          <strong>Cannot delete.</strong>
          This upload is feeding active statements, and only a Manager can cascade-cancel them.
          Please ask a Manager to delete this file for you.
        </div>
      </div>
    </div>

    <div class="del-modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeDeleteModal()">Cancel</button>
      <button type="button" id="del-confirm-btn" class="btn btn-danger" onclick="confirmDelete()" disabled>
        Delete
      </button>
    </div>
  </div>
</div>

<style>
.del-modal { position:fixed; top:0; left:0; right:0; bottom:0; width:100vw; height:100vh; z-index:100000;
             display:flex; align-items:center; justify-content:center; }
.del-modal-backdrop { position:fixed; top:0; left:0; right:0; bottom:0; width:100vw; height:100vh; background:rgba(0,0,0,0.55); }
.del-modal-card { position:relative; background:#fff; border-radius:8px; width:520px; max-width:92vw; max-height:88vh;
                  display:flex; flex-direction:column; box-shadow:0 10px 40px rgba(0,0,0,0.25); }
.del-modal-header { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid #eee; }
.del-modal-x { background:none; border:none; font-size:24px; line-height:1; cursor:pointer; color:#666; }
.del-modal-body { padding:16px 18px; overflow-y:auto; flex:1; font-size:13px; }
.del-modal-footer { padding:12px 18px; border-top:1px solid #eee; display:flex; justify-content:flex-end; gap:8px; }
.del-counts { display:flex; gap:18px; flex-wrap:wrap; padding:10px 12px; background:#f7f7f9; border-radius:6px; margin:10px 0; align-items:center; }
.del-num { font-weight:700; font-size:18px; color:#333; }
.del-warn { color:#c0392b; }
.del-banner { background:#fff7e6; border:1px solid #f4c97a; border-radius:6px; padding:10px 12px; margin-top:10px; font-size:12px; }
.del-banner-block { background:#fdecea; border-color:#e6b3ad; }
.del-stmt-list { margin:8px 0 0 0; padding:8px 12px; background:#fff; border:1px solid #eee; border-radius:4px;
                 max-height:180px; overflow-y:auto; font-size:12px; list-style:none; }
.del-stmt-list li { padding:4px 0; border-bottom:1px dotted #eee; }
.del-stmt-list li:last-child { border-bottom:none; }
.del-stmt-list .pill { display:inline-block; padding:1px 6px; border-radius:3px; font-size:10px; font-weight:600; margin-right:6px; }
.del-stmt-list .pill-draft    { background:#e8eaf6; color:#3949ab; }
.del-stmt-list .pill-final    { background:#fff3e0; color:#e65100; }
.del-stmt-list .pill-reviewed { background:#e8f5e9; color:#2e7d32; }
.del-reason { display:block; margin-top:10px; }
.del-reason span { display:block; margin-bottom:4px; font-size:12px; }
.del-reason textarea { width:100%; padding:6px 8px; border:1px solid #ccc; border-radius:4px; font-family:inherit; font-size:12px; box-sizing:border-box; }
.btn-danger { background:#c0392b; color:#fff; border:none; }
.btn-danger:hover:not(:disabled) { background:#a93226; }
.btn-danger:disabled { background:#ddd; color:#888; cursor:not-allowed; }
</style>

<script>
// ─── State ────────────────────────────────────────────────────
var _del_state = {
  uploadId: null,
  filename: '',
  hasBlockers: false,
  canForce: false,
  csrf: '<?= csrf_token() ?>'
};

// ─── Move the modal to <body> on first load ───────────────────
// The page renders inside #page-inner which is a constrained
// content column next to the sidebar. A position:fixed modal
// nested inside that column has its backdrop clipped by the
// parent's stacking context, which is why the sidebar/header
// stayed visible and the page underneath looked broken.
// Reparenting to body puts it at the top of the stack.
(function () {
  var modal = document.getElementById('delete-modal');
  if (modal && modal.parentNode !== document.body) {
    document.body.appendChild(modal);
  }
})();

// Triggered by the trash icon in the file list
function deleteFile(id, filename) {
  _del_state.uploadId = id;
  _del_state.filename = filename;

  // Reset modal to its initial state
  document.getElementById('del-filename').textContent = filename;
  document.getElementById('del-loading').style.display = 'block';
  document.getElementById('del-loading').textContent = 'Checking impact…';
  document.getElementById('del-content').style.display = 'none';
  document.getElementById('del-warning').style.display = 'none';
  document.getElementById('del-noperm').style.display  = 'none';
  document.getElementById('del-stmts-wrap').style.display = 'none';
  document.getElementById('del-stmt-list').style.display = 'none';
  document.getElementById('del-toggle-label').textContent = 'View statements';
  document.getElementById('del-reason').value = '';
  document.getElementById('del-confirm-btn').disabled = true;
  document.getElementById('del-confirm-btn').textContent = 'Delete';
  document.getElementById('delete-modal').style.display = 'flex';

  // Step 1: ask the server what would happen
  var body = 'upload_id=' + encodeURIComponent(id) +
             '&mode=preview' +
             '&_csrf=' + encodeURIComponent(_del_state.csrf);

  fetch('../process/delete_upload.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    credentials: 'same-origin',
    body: body
  })
  .then(function (r) {
    return r.text().then(function (t) {
      try { return JSON.parse(t); }
      catch (e) {
        return { ok: false, message: 'Your session may have expired. Please refresh.' };
      }
    });
  })
  .then(function (res) {
    document.getElementById('del-loading').style.display = 'none';
    document.getElementById('del-content').style.display = 'block';

    if (!res.ok) {
      // Hard error — show in the warning slot and disable confirm
      document.getElementById('del-warning').style.display = 'block';
      document.getElementById('del-warning').textContent = res.message;
      document.getElementById('del-confirm-btn').disabled = true;
      return;
    }

    document.getElementById('del-sales').textContent    = res.sales_count;
    document.getElementById('del-receipts').textContent = res.receipts_count;

    _del_state.hasBlockers = !!res.has_blockers;
    _del_state.canForce    = !!res.can_force;

    if (res.has_blockers) {
      document.getElementById('del-stmts-wrap').style.display = 'inline';
      document.getElementById('del-stmts').textContent   = res.statements.length;
      document.getElementById('del-stmts-2').textContent = res.statements.length;
      renderStmtList(res.statements);

      if (_del_state.canForce) {
        document.getElementById('del-warning').style.display = 'block';
        document.getElementById('del-noperm').style.display  = 'none';
        document.getElementById('del-confirm-btn').textContent = 'Cancel statements & delete';
        document.getElementById('del-confirm-btn').disabled = false;
      } else {
        // Uploader hitting a blocked file
        document.getElementById('del-warning').style.display = 'none';
        document.getElementById('del-noperm').style.display  = 'block';
        document.getElementById('del-confirm-btn').disabled  = true;
      }
    } else {
      // No statements impacted — straightforward delete
      document.getElementById('del-warning').style.display = 'none';
      document.getElementById('del-noperm').style.display  = 'none';
      document.getElementById('del-confirm-btn').textContent = 'Delete';
      document.getElementById('del-confirm-btn').disabled = false;
    }
  })
  .catch(function (err) {
    document.getElementById('del-loading').textContent = 'Network error: ' + (err && err.message || 'unknown');
  });
}

function renderStmtList(stmts) {
  var ul = document.getElementById('del-stmt-list');
  ul.innerHTML = stmts.map(function (s) {
    var pill = 'pill-' + s.status;
    return '<li>' +
      '<span class="pill ' + pill + '">' + s.status.toUpperCase() + '</span>' +
      '<strong>' + esc(s.statement_no) + '</strong> — ' +
      esc(s.agent_name) + ' &nbsp;' +
      '<span class="dim">[' + esc(s.period_from) + ' to ' + esc(s.period_to) + ']</span>' +
      '</li>';
  }).join('');
}

function toggleStmtList() {
  var ul    = document.getElementById('del-stmt-list');
  var label = document.getElementById('del-toggle-label');
  if (ul.style.display === 'none') {
    ul.style.display = 'block';
    label.textContent = 'Hide statements';
  } else {
    ul.style.display = 'none';
    label.textContent = 'View statements';
  }
}

function closeDeleteModal() {
  document.getElementById('delete-modal').style.display = 'none';
}

function confirmDelete() {
  var btn = document.getElementById('del-confirm-btn');
  btn.disabled = true;
  btn.textContent = 'Deleting…';

  var reason = document.getElementById('del-reason').value || '';

  if (_del_state.hasBlockers && reason.trim().length < 5) {
    alert('Please enter a reason (min 5 characters) for cancelling the statements.');
    btn.disabled = false;
    btn.textContent = 'Cancel statements & delete';
    return;
  }

  // Belt-and-braces native confirm so the user can't bulldoze through by accident
  var confirmMsg = 'Permanently delete "' + _del_state.filename + '"?';
  if (_del_state.hasBlockers) {
    confirmMsg += '\n\nThis will also cancel ' + document.getElementById('del-stmts').textContent +
                  ' active statement(s). This cannot be undone.';
  }
  if (!confirm(confirmMsg)) {
    btn.disabled = false;
    btn.textContent = _del_state.hasBlockers ? 'Cancel statements & delete' : 'Delete';
    return;
  }

  var body = 'upload_id=' + encodeURIComponent(_del_state.uploadId) +
             '&mode=' + (_del_state.hasBlockers ? 'force' : '') +
             '&reason=' + encodeURIComponent(reason) +
             '&_csrf=' + encodeURIComponent(_del_state.csrf);

  fetch('../process/delete_upload.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    credentials: 'same-origin',
    body: body
  })
  .then(function (r) {
    return r.text().then(function (t) {
      try { return JSON.parse(t); }
      catch (e) { return { ok: false, message: 'Session expired. Refresh and try again.' }; }
    });
  })
  .then(function (res) {
    alert(res.message);
    if (res.ok) {
      closeDeleteModal();
      location.reload();
    } else {
      btn.disabled = false;
      btn.textContent = _del_state.hasBlockers ? 'Cancel statements & delete' : 'Delete';
    }
  })
  .catch(function (err) {
    alert('Could not reach the server: ' + (err && err.message || 'unknown error'));
    btn.disabled = false;
    btn.textContent = _del_state.hasBlockers ? 'Cancel statements & delete' : 'Delete';
  });
}

function esc(v) {
  if (v === null || v === undefined) return '';
  return String(v).replace(/[&<>"']/g, function (c) {
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
  });
}

// Esc key closes the modal
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && document.getElementById('delete-modal').style.display === 'flex') {
    closeDeleteModal();
  }
});
</script>

<?php require_once '../layouts/layout_footer.php'; ?>