<?php
// ============================================================
// admin/audit.php
// Immutable audit log viewer with filters + CSV export.
// ============================================================
$page_title = 'Audit Log';
$active_nav = 'audit';
require_once '../layouts/layout_header.php';
require_login();

$db   = get_db();
$user = current_user();
$role = $user['role'];
$uid  = (int)$user['id'];

// Manager + Admin see everyone's logs. Everyone else sees only their own.
$can_see_all = in_array($role, array('Manager','Admin'));

// ── Filters ───────────────────────────────────────────────────
$filter_action = isset($_GET['action_type']) ? $_GET['action_type'] : '';
$filter_user   = (int)($_GET['user_id']      ?? 0);
$filter_from   = $_GET['date_from']          ?? date('Y-m-01');
$filter_to     = $_GET['date_to']            ?? date('Y-m-d');
$filter_result = isset($_GET['result'])      ? $_GET['result']      : '';
$filter_q      = trim($_GET['q']             ?? '');
$mine_only     = isset($_GET['mine'])        ? 1 : 0;
$page          = max(1, (int)($_GET['page']  ?? 1));
$per_page      = 10;
$offset        = ($page - 1) * $per_page;

// Normalize dates to prevent SQL injection via date fields
$filter_from = date('Y-m-d', strtotime($filter_from));
$filter_to   = date('Y-m-d', strtotime($filter_to));

// ── Build WHERE clause with prepared parameters ──────────────
$where_parts = array("al.created_at BETWEEN ? AND ?");
$params      = array($filter_from . ' 00:00:00', $filter_to . ' 23:59:59');
$types       = 'ss';

// Non-privileged users always see only their own records.
// Privileged users see their own when "mine_only" is toggled.
if (!$can_see_all || $mine_only) {
    $where_parts[] = "al.user_id = ?";
    $params[]      = $uid;
    $types        .= 'i';
}
if ($filter_action) {
    $where_parts[] = "al.action_type = ?";
    $params[]      = $filter_action;
    $types        .= 's';
}
if ($can_see_all && !$mine_only && $filter_user > 0) {
    $where_parts[] = "al.user_id = ?";
    $params[]      = $filter_user;
    $types        .= 'i';
}
if ($filter_result && in_array($filter_result, array('success','failed'))) {
    $where_parts[] = "al.result = ?";
    $params[]      = $filter_result;
    $types        .= 's';
}
if ($filter_q !== '') {
    $where_parts[] = "(al.detail LIKE ? OR al.ip_address LIKE ?)";
    $like          = '%' . $filter_q . '%';
    $params[]      = $like;
    $params[]      = $like;
    $types        .= 'ss';
}

$where_sql = implode(' AND ', $where_parts);

// Legacy ?export=csv links now redirect to the printable PDF report.
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $can_see_all) {
    header('Location: ' . BASE_URL . '/process/process_export.php?type=audit');
    exit;
}

// ── Stat cards (respect the same filters) ────────────────────
$stats_sql = "SELECT
    COUNT(*) total,
    SUM(CASE WHEN result='success' THEN 1 ELSE 0 END) success_cnt,
    SUM(CASE WHEN result='failed'  THEN 1 ELSE 0 END) failed_cnt,
    COUNT(DISTINCT user_id) unique_users
    FROM audit_log al
    WHERE $where_sql";
$s_stmt = $db->prepare($stats_sql);
$s_stmt->bind_param($types, ...$params);
$s_stmt->execute();
$stats = $s_stmt->get_result()->fetch_assoc();
$s_stmt->close();

$total_events = (int)$stats['total'];
$success_rate = $total_events > 0 ? round(($stats['success_cnt'] / $total_events) * 100) : 100;

// Top action type (same filters)
$top_action_sql = "SELECT action_type, COUNT(*) c FROM audit_log al WHERE $where_sql GROUP BY action_type ORDER BY c DESC LIMIT 1";
$ta_stmt = $db->prepare($top_action_sql);
$ta_stmt->bind_param($types, ...$params);
$ta_stmt->execute();
$top_action = $ta_stmt->get_result()->fetch_assoc();
$ta_stmt->close();

// ── Total count + rows ───────────────────────────────────────
$cnt_stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM audit_log al WHERE $where_sql");
$cnt_stmt->bind_param($types, ...$params);
$cnt_stmt->execute();
$total = (int)$cnt_stmt->get_result()->fetch_assoc()['cnt'];
$cnt_stmt->close();
$total_pages = max(1, (int)ceil($total / $per_page));

$page_params = $params;
$page_params[] = $per_page;
$page_params[] = $offset;
$page_types    = $types . 'ii';

$log_sql = "SELECT al.id, al.action_type, al.detail, al.ip_address, al.result,
                   al.created_at, u.full_name, u.role AS user_role
            FROM audit_log al JOIN users u ON al.user_id = u.id
            WHERE $where_sql
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?";
$log_stmt = $db->prepare($log_sql);
$log_stmt->bind_param($page_types, ...$page_params);
$log_stmt->execute();
$logs = $log_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$log_stmt->close();

// Users list for filter dropdown (Manager/Admin only)
$all_users = $can_see_all
    ? $db->query("SELECT id, full_name FROM users ORDER BY full_name")->fetch_all(MYSQLI_ASSOC)
    : array();

// Preserve filters in links
function audit_link($extra = array()) {
    $params = array_merge($_GET, $extra);
    unset($params['export']);
    return '?' . http_build_query($params);
}

$role_class = array(
    'Manager'    => 'reconciled',
    'Admin'      => 'variance',
    'Reconciler' => 'pending',
    'Uploader'   => 'matched',
);
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
      <h1>Audit Log</h1>
      <p><?= $can_see_all && !$mine_only
          ? 'Immutable record of all system actions, uploads, and changes.'
          : 'Your activity log — all actions you have performed.' ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($can_see_all): ?>
        <?php if ($mine_only): ?>
          <a href="<?= audit_link(array('mine'=>null)) ?>" class="btn btn-ghost">Show all users</a>
        <?php else: ?>
          <a href="<?= audit_link(array('mine'=>1,'page'=>1)) ?>" class="btn btn-ghost">My Activity Only</a>
        <?php endif; ?>
        <a href="../process/process_export.php?type=audit" target="_blank" class="btn btn-primary" style="font-weight:700"><i class="fa-solid fa-print"></i>&nbsp; Print / PDF</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Stat cards -->
<div class="stat-grid">
  <div class="stat-card blue">
    <div class="stat-label">Total Events</div>
    <div class="stat-value"><?= fmt_compact($total_events) ?></div>
    <div class="stat-sub"><?= $filter_from ?> → <?= $filter_to ?></div>
  </div>
  <div class="stat-card <?= $success_rate >= 95 ? 'green' : ($success_rate >= 80 ? 'warn' : 'red') ?>">
    <div class="stat-label">Success Rate</div>
    <div class="stat-value"><?= $success_rate ?>%</div>
    <div class="stat-sub"><?= number_format($stats['success_cnt']) ?> ok · <?= number_format($stats['failed_cnt']) ?> failed</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Top Action</div>
    <div class="stat-value" style="font-size:16px"><?= $top_action ? htmlspecialchars($top_action['action_type']) : '—' ?></div>
    <div class="stat-sub"><?= $top_action ? number_format($top_action['c']) . ' events' : '' ?></div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label"><?= $can_see_all && !$mine_only ? 'Active Users' : 'Your Actions' ?></div>
    <div class="stat-value"><?= $can_see_all && !$mine_only ? (int)$stats['unique_users'] : number_format($total_events) ?></div>
    <div class="stat-sub"><?= $can_see_all && !$mine_only ? 'Distinct users in range' : 'In selected range' ?></div>
  </div>
</div>

<?php if ($stats['failed_cnt'] > 0): ?>
<div class="alert alert-danger" style="margin-top:12px">
  ⚠ <?= number_format($stats['failed_cnt']) ?> failed action<?= $stats['failed_cnt']==1?'':'s' ?> in this range.
  <a href="<?= audit_link(array('result'=>'failed','page'=>1)) ?>" style="color:inherit;text-decoration:underline">Show only failed</a>.
</div>
<?php endif; ?>

<!-- Filter bar -->
<form method="GET" action="" class="panel" style="padding:14px 18px;margin-top:16px;margin-bottom:16px">
  <?php if ($mine_only): ?><input type="hidden" name="mine" value="1"><?php endif; ?>
  <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div style="flex:1;min-width:150px">
      <label class="form-label" style="font-size:11px">Action Type</label>
      <select name="action_type" class="form-select">
        <option value="">All Actions</option>
        <?php foreach (array('LOGIN','LOGOUT','FILE_UPLOAD','RECON_RUN','DATA_EDIT','REPORT_EXPORT','USER_MGMT') as $at): ?>
        <option value="<?= $at ?>" <?= $filter_action===$at?'selected':'' ?>><?= $at ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($can_see_all && !$mine_only): ?>
    <div style="flex:1;min-width:150px">
      <label class="form-label" style="font-size:11px">User</label>
      <select name="user_id" class="form-select">
        <option value="0">All Users</option>
        <?php foreach ($all_users as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= $filter_user === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div style="min-width:130px">
      <label class="form-label" style="font-size:11px">From</label>
      <input type="date" name="date_from" class="form-input" value="<?= $filter_from ?>">
    </div>
    <div style="min-width:130px">
      <label class="form-label" style="font-size:11px">To</label>
      <input type="date" name="date_to" class="form-input" value="<?= $filter_to ?>">
    </div>
    <div style="min-width:120px">
      <label class="form-label" style="font-size:11px">Result</label>
      <select name="result" class="form-select">
        <option value="">All</option>
        <option value="success" <?= $filter_result==='success'?'selected':'' ?>>Success</option>
        <option value="failed"  <?= $filter_result==='failed'?'selected':'' ?>>Failed</option>
      </select>
    </div>
    <div style="flex:1;min-width:220px">
      <label class="form-label" style="font-size:11px">Search (detail / IP)</label>
      <input type="text" name="q" value="<?= htmlspecialchars($filter_q) ?>" class="form-input" placeholder="e.g. receipt 1234, or 192.168">
    </div>
    <button type="submit" class="btn btn-primary">Apply</button>
    <a href="audit.php<?= $mine_only ? '?mine=1' : '' ?>" class="btn btn-ghost">Reset</a>
  </div>
</form>

<div class="panel">
  <table class="data-table" id="audit-table">
    <thead>
      <tr>
        <th style="width:22px"></th>
        <th>Timestamp</th>
        <?php if ($can_see_all && !$mine_only): ?><th>User</th><th>Role</th><?php endif; ?>
        <th>Action</th>
        <th>Detail</th>
        <th>Result</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $log): ?>
    <tr class="audit-row" style="cursor:pointer;<?= $log['result']==='failed' ? 'border-left:3px solid #c0392b' : '' ?>" onclick="toggleAuditDetail(this)">
      <td class="expand-toggle" style="text-align:center;color:#888">▸</td>
      <td class="mono" style="font-size:11px;color:#666;white-space:nowrap"><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></td>
      <?php if ($can_see_all && !$mine_only): ?>
      <td style="font-weight:500;font-size:12px"><?= htmlspecialchars($log['full_name']) ?></td>
      <td><span class="badge <?= $role_class[$log['user_role']] ?? 'matched' ?>"><?= htmlspecialchars($log['user_role']) ?></span></td>
      <?php endif; ?>
      <td><span style="font-family:monospace;font-size:10px;color:var(--accent2);background:rgba(0,102,204,0.1);padding:2px 7px;border-radius:3px"><?= htmlspecialchars($log['action_type']) ?></span></td>
      <td style="font-size:11.5px;color:#666;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($log['detail']) ?></td>
      <td><span class="badge <?= $log['result']==='success'?'success':'failed' ?>"><?= strtoupper($log['result']) ?></span></td>
    </tr>
    <tr class="audit-detail-row" style="display:none">
      <td colspan="<?= $can_see_all && !$mine_only ? 7 : 5 ?>" style="background:#fafbfc;padding:0">
        <div style="padding:16px 24px">
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px 20px;font-size:12px">
            <div><span class="dim">Event ID:</span> <strong class="mono">#<?= $log['id'] ?></strong></div>
            <div><span class="dim">Timestamp:</span> <strong class="mono"><?= $log['created_at'] ?></strong></div>
            <?php if ($can_see_all && !$mine_only): ?>
            <div><span class="dim">User:</span> <strong><?= htmlspecialchars($log['full_name']) ?></strong> (<?= htmlspecialchars($log['user_role']) ?>)</div>
            <?php endif; ?>
            <div><span class="dim">Action:</span> <strong><?= htmlspecialchars($log['action_type']) ?></strong></div>
            <div><span class="dim">IP Address:</span> <strong class="mono"><?= htmlspecialchars($log['ip_address']) ?: '—' ?></strong></div>
            <div><span class="dim">Result:</span> <strong style="color:<?= $log['result']==='success'?'#00a950':'#c0392b' ?>"><?= strtoupper($log['result']) ?></strong></div>
          </div>
          <div style="margin-top:12px;padding:10px 12px;background:#fff;border:1px solid #e5e5e5;border-radius:4px;font-size:12px;color:#333;white-space:pre-wrap"><?= htmlspecialchars($log['detail']) ?></div>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($logs)): ?>
    <tr><td colspan="<?= $can_see_all && !$mine_only ? 7 : 5 ?>" class="dim" style="text-align:center;padding:20px">No log entries match the current filters.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <div style="padding:14px 18px;border-top:1px solid #e0e0e0;display:flex;align-items:center;gap:12px">
    <span style="font-size:11px;color:#888">
      Showing <?= $total > 0 ? min($offset+1, $total) : 0 ?>–<?= min($offset+$per_page, $total) ?> of <?= number_format($total) ?> entries
    </span>
    <div style="margin-left:auto;display:flex;gap:6px">
      <?php if ($page > 1): ?>
      <a href="<?= audit_link(array('page'=>$page-1)) ?>" class="btn btn-ghost btn-sm">← Prev</a>
      <?php endif; ?>
      <?php for ($p = max(1, $page-2); $p <= min($total_pages, $page+2); $p++): ?>
      <a href="<?= audit_link(array('page'=>$p)) ?>" class="btn btn-ghost btn-sm" <?= $p===$page?'style="background:rgba(26,86,219,0.1);color:var(--accent);font-weight:700"':'' ?>><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($page < $total_pages): ?>
      <a href="<?= audit_link(array('page'=>$page+1)) ?>" class="btn btn-ghost btn-sm">Next →</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<p class="dim" style="font-size:11px;margin-top:10px;text-align:center">
  Audit entries are immutable and cannot be edited or deleted. Retention: indefinite.
</p>

<style>
.audit-row:hover { background:#f5fbf7 }
.audit-row.expanded { background:#eaf7ef }
.audit-row.expanded .expand-toggle { display:inline-block }
</style>

<script>
function toggleAuditDetail(row) {
  const next = row.nextElementSibling;
  if (!next || !next.classList.contains('audit-detail-row')) return;
  const isOpen = next.style.display !== 'none';
  next.style.display = isOpen ? 'none' : '';
  row.classList.toggle('expanded', !isOpen);
  row.querySelector('.expand-toggle').textContent = isOpen ? '▸' : '▾';
}
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
