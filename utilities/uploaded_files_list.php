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
            <a href="#" onclick="deleteFile(<?= $f['id'] ?>, '<?= htmlspecialchars($f['filename']) ?>'); return false;" class="btn btn-ghost btn-sm" title="Delete" style="color:#c0392b"><i class="fa-solid fa-trash"></i></a>
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

<script>
function deleteFile(id, filename) {
  if (confirm('Are you sure you want to delete "' + filename + '"?\n\nThis will also remove all associated sales/receipts records.')) {
    fetch('../process/delete_upload.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'upload_id=' + id + '&_csrf=<?= csrf_token() ?>'
    })
    .then(r => r.text())
    .then(msg => {
      alert(msg);
      location.reload();
    });
  }
}
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
