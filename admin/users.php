<?php
// ============================================================
// admin/users.php
// User management: search, filters, clear action buttons, permission matrix.
// ============================================================
$page_title = 'User Management';
$active_nav = 'users';
require_once '../layouts/layout_header.php';
require_role(['Manager','Admin']);

$db = get_db();

// Filters
$q             = trim($_GET['q']      ?? '');
$filter_role   = $_GET['role']   ?? '';
$filter_status = $_GET['status'] ?? '';

$where = array('1=1');
$params = array();
$types  = '';
if ($q !== '') {
    $where[] = "(full_name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($filter_role && in_array($filter_role, array('Admin','Manager','Reconciler','Uploader'))) {
    $where[] = "role = ?";
    $params[] = $filter_role;
    $types   .= 's';
}
if ($filter_status === 'active')   $where[] = "is_active = 1";
if ($filter_status === 'disabled') $where[] = "is_active = 0";

$where_sql = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT id, username, full_name, email, role, initials, is_active,
           last_login, last_login_ip, created_at
    FROM users
    WHERE $where_sql
    ORDER BY FIELD(role,'Admin','Manager','Reconciler','Uploader'), full_name
");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$users_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Counts
$counts = array();
$counts['total']  = (int)$db->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
$counts['active'] = (int)$db->query("SELECT COUNT(*) c FROM users WHERE is_active=1")->fetch_assoc()['c'];
$counts['online'] = (int)$db->query("SELECT COUNT(*) c FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetch_assoc()['c'];
$counts['admins'] = (int)$db->query("SELECT COUNT(*) c FROM users WHERE role='Admin' AND is_active=1")->fetch_assoc()['c'];

// Role permission matrix (columns: Uploader | Reconciler | Manager | Admin)
$perms = array(
    array('Upload source files',       true,  false, true,  true),
    array('View own uploads',          true,  true,  true,  true),
    array('View all uploads',          false, true,  true,  true),
    array('View Sales Data',           false, true,  true,  true),
    array('View Receipts Data',        false, true,  true,  true),
    array('Run Reconciliation',        false, true,  true,  true),
    array('Match / exclude unmatched', false, true,  true,  true),
    array('View Variance Reports',     false, true,  true,  true),
    array('View Statements',           false, true,  true,  true),
    array('Issue / finalize Statements', false, false, true,  true),
    array('View Agents / Terminals',   false, true,  true,  true),
    array('Edit Agents / Terminals',   false, false, true,  true),
    array('Create Escalations',        false, true,  true,  true),
    array('Review / resolve Escalations', false, false, true,  true),
    array('View Audit Log (own)',      true,  true,  true,  true),
    array('View All Audit Logs',       false, false, true,  true),
    array('Manage Users',              false, false, true,  true),
    array('Create Admin Users',        false, false, false, true),
    array('Organization Settings',     false, false, false, true),
    array('System Admin Panel',        false, false, false, true),
);

$success = htmlspecialchars($_GET['success'] ?? '');
$error   = htmlspecialchars($_GET['error']   ?? '');

// Password min length for client-side hints
$pw_row = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='password_min_length'")->fetch_assoc();
$pw_min = $pw_row ? (int)$pw_row['setting_value'] : 8;

$role_cls = array(
    'Admin'      => 'variance',
    'Manager'    => 'reconciled',
    'Reconciler' => 'pending',
    'Uploader'   => 'matched',
);
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
      <h1>User Management</h1>
      <p>Manage user accounts and role-based access control.</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('user-modal').style.display='flex'"><i class="fa-solid fa-plus"></i> Add User</button>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success">✓ <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">⚠ <?= $error ?></div><?php endif; ?>
<div class="alert alert-warn" style="font-size:12px">
  ⚠ Restricted to <strong>Manager</strong> and <strong>Admin</strong>. All changes are audit-logged.
  <?php if ($user['role'] === 'Admin'): ?> &nbsp;|&nbsp; <strong style="color:#b91c3c">Admin:</strong> you can create and manage Admin accounts.<?php endif; ?>
</div>

<!-- Stat cards -->
<div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
  <div class="stat-card blue"><div class="stat-label">Total Users</div><div class="stat-value"><?= $counts['total'] ?></div></div>
  <div class="stat-card green"><div class="stat-label">Active</div><div class="stat-value"><?= $counts['active'] ?></div><div class="stat-sub"><?= $counts['total'] - $counts['active'] ?> disabled</div></div>
  <div class="stat-card green"><div class="stat-label">Online (1h)</div><div class="stat-value"><?= $counts['online'] ?></div><div class="stat-sub">Recently active</div></div>
  <div class="stat-card <?= $counts['admins'] > 0 ? 'red' : 'warn' ?>"><div class="stat-label">Admins</div><div class="stat-value"><?= $counts['admins'] ?></div><div class="stat-sub">Privileged accounts</div></div>
</div>

<!-- Filter bar -->
<form method="GET" class="panel" style="padding:12px 16px;margin:16px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
  <div style="flex:1;min-width:180px">
    <label class="form-label" style="font-size:11px">Search</label>
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-input" placeholder="name, username, email">
  </div>
  <div>
    <label class="form-label" style="font-size:11px">Role</label>
    <select name="role" class="form-select">
      <option value="">All</option>
      <option value="Admin"      <?= $filter_role==='Admin'?'selected':'' ?>>Admin</option>
      <option value="Manager"    <?= $filter_role==='Manager'?'selected':'' ?>>Manager</option>
      <option value="Reconciler" <?= $filter_role==='Reconciler'?'selected':'' ?>>Reconciler</option>
      <option value="Uploader"   <?= $filter_role==='Uploader'?'selected':'' ?>>Uploader</option>
    </select>
  </div>
  <div>
    <label class="form-label" style="font-size:11px">Status</label>
    <select name="status" class="form-select">
      <option value="">All</option>
      <option value="active"   <?= $filter_status==='active'?'selected':'' ?>>Active</option>
      <option value="disabled" <?= $filter_status==='disabled'?'selected':'' ?>>Disabled</option>
    </select>
  </div>
  <button type="submit" class="btn btn-primary">Apply</button>
  <a href="users.php" class="btn btn-ghost">Reset</a>
</form>

<style>
.user-actions { display:inline-flex; gap:2px; align-items:center; white-space:nowrap }
.user-actions form { margin:0 }
.ua-btn {
  display:inline-flex; align-items:center; justify-content:center;
  width:28px; height:28px; padding:0; border:1px solid #e0e0e0;
  background:#fff; color:#555; border-radius:4px; cursor:pointer;
  font-size:12px; line-height:1; transition:all .12s;
}
.ua-btn:hover { background:#f5fbf7; border-color:var(--green,#00a950); color:var(--green-dark,#007a3d) }
.ua-btn:focus { outline:none; box-shadow:0 0 0 2px rgba(0,169,80,0.2) }
.ua-btn.ua-warn:hover   { background:#fff8e1; border-color:#d49a00; color:#7a5500 }
.ua-btn.ua-danger:hover { background:#fdecea; border-color:#db0630; color:#b91c3c }
.ua-btn.ua-ok:hover     { background:#eaf7ef; border-color:#00a950; color:#00a950 }
</style>

<!-- Users Table -->
<div class="panel">
  <table class="data-table">
    <thead>
      <tr>
        <th>Username</th><th>Full Name</th><th>Email</th><th>Role</th>
        <th>Last Login</th><th>IP</th><th>Status</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users_list as $u):
      $can_edit = !($u['role'] === 'Admin' && $user['role'] !== 'Admin');
      $is_online = $u['last_login'] && strtotime($u['last_login']) > time() - 3600;
      $last_login_str = $u['last_login'] ? date('Y-m-d H:i', strtotime($u['last_login'])) : 'Never';
    ?>
    <tr>
      <td class="mono" style="color:var(--accent2)">
        <?php if ($is_online): ?><span title="Online (within 1 hour)" style="display:inline-block;width:8px;height:8px;background:#00a950;border-radius:50%;margin-right:4px"></span><?php endif; ?>
        <?= htmlspecialchars($u['username']) ?>
      </td>
      <td style="font-weight:500"><?= htmlspecialchars($u['full_name']) ?></td>
      <td class="dim" style="font-size:11.5px"><?= htmlspecialchars($u['email']) ?></td>
      <td><span class="badge <?= $role_cls[$u['role']] ?? 'matched' ?>" style="font-weight:700"><?= htmlspecialchars($u['role']) ?></span></td>
      <td class="mono dim" style="font-size:11px"><?= $last_login_str ?></td>
      <td class="mono dim" style="font-size:11px"><?= htmlspecialchars($u['last_login_ip'] ?? '—') ?></td>
      <td><span class="badge <?= $u['is_active'] ? 'active' : 'inactive' ?>"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
      <td>
        <div class="user-actions">
          <a class="ua-btn" href="../admin/audit.php?user_id=<?= (int)$u['id'] ?>&mine=&date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>" title="View activity log"><i class="fa-solid fa-clock-rotate-left"></i></a>
          <?php if ($can_edit): ?>
          <button class="ua-btn" title="Edit name, email, role"
            onclick="openEdit(<?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>', '<?= addslashes($u['email']) ?>', '<?= $u['role'] ?>')"><i class="fa-solid fa-pen"></i></button>
          <button class="ua-btn" title="Reset password"
            onclick="openReset(<?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>')"><i class="fa fa-key"></i></button>
          <?php if ($u['id'] !== (int)$user['id']): ?>
          <form method="POST" action="../process/process_users.php" style="display:inline"
                onsubmit="return confirm('Force logout <?= addslashes($u['full_name']) ?>? They will be kicked on their next request.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="force_logout">
            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
            <button type="submit" class="ua-btn ua-warn" title="Force logout"><i class="fa fa-sign-out"></i></button>
          </form>
          <form method="POST" action="../process/process_users.php" style="display:inline"
                onsubmit="return confirm('<?= $u['is_active'] ? 'Disable' : 'Enable' ?> account for <?= addslashes($u['full_name']) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action"    value="toggle_user">
            <input type="hidden" name="user_id"   value="<?= $u['id'] ?>">
            <input type="hidden" name="is_active" value="<?= $u['is_active'] ? 0 : 1 ?>">
            <button type="submit" class="ua-btn <?= $u['is_active'] ? 'ua-danger' : 'ua-ok' ?>"
              title="<?= $u['is_active'] ? 'Disable this account' : 'Re-enable this account' ?>">
              <i class="fa fa-<?= $u['is_active'] ? 'ban' : 'check' ?>"></i>
            </button>
          </form>
          <?php if ($user['role'] === 'Admin'): ?>
          <form method="POST" action="../process/process_users.php" style="display:inline"
                onsubmit="return confirm('PERMANENTLY DELETE <?= addslashes($u['full_name']) ?>? This cannot be undone.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
            <button type="submit" class="ua-btn ua-danger" title="Delete user permanently"><i class="fa-solid fa-trash"></i></button>
          </form>
          <?php endif; ?>
          <?php endif; ?>
          <?php else: ?>
          <span class="dim" style="font-size:11px;padding:3px 6px"><i class="fa fa-lock"></i> Admin only</span>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($users_list)): ?>
    <tr><td colspan="8" class="dim" style="text-align:center;padding:20px">No users match the current filters.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Role Permissions -->
<div class="panel">
  <div class="panel-header"><span class="panel-title">Role Permissions</span></div>
  <table class="data-table">
    <thead>
      <tr>
        <th>Permission</th>
        <th style="text-align:center">Uploader</th>
        <th style="text-align:center">Reconciler</th>
        <th style="text-align:center">Manager</th>
        <th style="text-align:center;color:#b91c3c">Admin</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($perms as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p[0]) ?></td>
        <?php for ($i = 1; $i <= 4; $i++): ?>
        <td style="text-align:center;font-size:16px;color:<?= $p[$i] ? ($i===4?'#b91c3c':'var(--green)') : '#ccc' ?>">
          <?= $p[$i] ? '✓' : '—' ?>
        </td>
        <?php endfor; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── ADD USER MODAL ── -->
<div id="user-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:460px;max-width:95vw;max-height:90vh;overflow-y:auto">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Add New User</span>
      <button onclick="document.getElementById('user-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_users.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_user">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Full Name</label><input type="text" name="full_name" class="form-input" required></div>
        <div class="form-group"><label class="form-label">Username</label><input type="text" name="username" class="form-input" required></div>
      </div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-input" required></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Role</label>
          <select name="role" class="form-select">
            <option>Uploader</option><option>Reconciler</option><option>Manager</option>
            <?php if ($user['role'] === 'Admin'): ?><option style="color:#b91c3c;font-weight:600">Admin</option><?php endif; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Temporary Password</label>
          <input type="password" name="temp_password" class="form-input" required minlength="<?= $pw_min ?>">
          <div style="font-size:11px;color:#888;margin-top:5px">Min <?= $pw_min ?> chars, letter + number</div>
        </div>
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:16px;cursor:pointer">
        <input type="checkbox" name="send_welcome" checked>
        Queue a welcome email to this user
      </label>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create User</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('user-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── EDIT USER MODAL ── -->
<div id="edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:440px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Edit User</span>
      <button onclick="document.getElementById('edit-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_users.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action"  value="edit_user">
      <input type="hidden" name="user_id" id="edit_user_id">
      <div class="form-group"><label class="form-label">Full Name</label><input type="text" name="full_name" id="edit_full_name" class="form-input" required></div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="edit_email" class="form-input" required></div>
      <div class="form-group"><label class="form-label">Role</label>
        <select name="role" id="edit_role" class="form-select">
          <option>Uploader</option><option>Reconciler</option><option>Manager</option>
          <?php if ($user['role'] === 'Admin'): ?><option style="color:#b91c3c;font-weight:600">Admin</option><?php endif; ?>
        </select>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('edit-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── RESET PASSWORD MODAL ── -->
<div id="reset-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:400px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Reset Password</span>
      <button onclick="document.getElementById('reset-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_users.php" style="padding:20px" onsubmit="return confirm('Reset password for ' + document.getElementById('reset_name').textContent + '?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action"  value="reset_password">
      <input type="hidden" name="user_id" id="reset_user_id">
      <p style="font-size:12px;color:#666;margin-top:0">Resetting password for <strong id="reset_name"></strong></p>
      <div class="form-group">
        <label class="form-label">New Password</label>
        <input type="password" name="new_password" class="form-input" required minlength="<?= $pw_min ?>">
        <div style="font-size:11px;color:#888;margin-top:5px">Min <?= $pw_min ?> chars, must include a letter and a number.</div>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary"><i class="fa fa-key"></i> Reset Password</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('reset-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(id, name, email, role) {
  document.getElementById('edit_user_id').value   = id;
  document.getElementById('edit_full_name').value = name;
  document.getElementById('edit_email').value     = email;
  document.getElementById('edit_role').value      = role;
  document.getElementById('edit-modal').style.display = 'flex';
}
function openReset(id, name) {
  document.getElementById('reset_user_id').value = id;
  document.getElementById('reset_name').textContent = name;
  document.getElementById('reset-modal').style.display = 'flex';
}
['user-modal','edit-modal','reset-modal'].forEach(function(id){
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
  });
});
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
