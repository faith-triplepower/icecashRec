<?php
// ============================================================
// admin/admin_panel.php
// Admin Panel — system overview for Admin role only.
// ============================================================
$page_title = 'Admin Panel';
$active_nav = 'admin_panel';
require_once '../layouts/layout_header.php';
require_role('Admin');   // Only Admin can access this page

$db  = get_db();
$uid = (int)$user['id'];

// ── System stats ──────────────────────────────────────────────
$stats = array();

$tables = array('users','agents','pos_terminals','sales','receipts',
                'reconciliation_runs','variance_results','upload_history','audit_log');
foreach ($tables as $t) {
    $row = $db->query("SELECT COUNT(*) AS cnt FROM `$t`")->fetch_assoc();
    $stats[$t] = (int)$row['cnt'];
}

// ── User breakdown by role ────────────────────────────────────
$role_counts = $db->query(
    "SELECT role, COUNT(*) AS cnt, SUM(is_active) AS active FROM users GROUP BY role ORDER BY FIELD(role,'Admin','Manager','Reconciler','Uploader')"
)->fetch_all(MYSQLI_ASSOC);

// ── Recent failed logins ──────────────────────────────────────
$failed_logins = $db->query(
    "SELECT al.detail, al.ip_address, al.created_at, u.full_name
     FROM audit_log al JOIN users u ON al.user_id = u.id
     WHERE al.action_type = 'LOGIN' AND al.result = 'failed'
     ORDER BY al.created_at DESC LIMIT 10"
)->fetch_all(MYSQLI_ASSOC);

// ── Upload history summary ────────────────────────────────────
$upload_summary = $db->query(
    "SELECT upload_status, COUNT(*) AS cnt FROM upload_history GROUP BY upload_status"
)->fetch_all(MYSQLI_ASSOC);
$upload_by_status = array();
foreach ($upload_summary as $r) $upload_by_status[$r['upload_status']] = (int)$r['cnt'];

// ── Reconciliation run summary ────────────────────────────────
$run_summary = $db->query(
    "SELECT run_status, COUNT(*) AS cnt FROM reconciliation_runs GROUP BY run_status"
)->fetch_all(MYSQLI_ASSOC);
$runs_by_status = array();
foreach ($run_summary as $r) $runs_by_status[$r['run_status']] = (int)$r['cnt'];

// ── All users list for management ────────────────────────────
$all_users = $db->query(
    "SELECT id, username, full_name, email, role, initials, is_active, last_login, created_at
     FROM users ORDER BY FIELD(role,'Admin','Manager','Reconciler','Uploader'), full_name"
)->fetch_all(MYSQLI_ASSOC);

// ── System settings ───────────────────────────────────────────
$settings_rows = $db->query("SELECT setting_key, setting_value, updated_at FROM system_settings ORDER BY setting_key")->fetch_all(MYSQLI_ASSOC);

// ── DB size (informational) ───────────────────────────────────
$db_size_row = $db->query(
    "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
     FROM information_schema.tables WHERE table_schema = DATABASE()"
)->fetch_assoc();
$db_size = $db_size_row ? $db_size_row['size_mb'] : '?';
?>

<div class="page-header" style="border-bottom:2px solid #b91c3c">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
        <div>
            <h1 style="color:#b91c3c"><i class="fa fa-shield"></i> System Administration Panel</h1>
            <p>Full system oversight — restricted to Admin role only.</p>
        </div>
        <span style="background:rgba(185,28,60,0.1);border:1px solid rgba(185,28,60,0.3);color:#b91c3c;font-size:10px;font-family:monospace;padding:4px 10px;border-radius:3px;font-weight:700">ADMIN ACCESS</span>
    </div>
</div>

<!-- ── System KPIs ── -->
<div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:20px">
    <div class="stat-card" style="border-top-color:#b91c3c">
        <div class="stat-label">Total Users</div>
        <div class="stat-value"><?= $stats['users'] ?></div>
        <div class="stat-sub"><?= array_sum(array_column($role_counts,'active')) ?> active</div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Sales Records</div>
        <div class="stat-value"><?= fmt_compact($stats['sales']) ?></div>
        <div class="stat-sub">In database</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Receipt Records</div>
        <div class="stat-value"><?= fmt_compact($stats['receipts']) ?></div>
        <div class="stat-sub">In database</div>
    </div>
    <div class="stat-card warn">
        <div class="stat-label">Recon Runs</div>
        <div class="stat-value"><?= $stats['reconciliation_runs'] ?></div>
        <div class="stat-sub"><?= $runs_by_status['complete'] ?? 0 ?> completed</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Audit Log Entries</div>
        <div class="stat-value"><?= fmt_compact($stats['audit_log']) ?></div>
        <div class="stat-sub">All time</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">DB Size</div>
        <div class="stat-value"><?= $db_size ?> <span style="font-size:14px">MB</span></div>
        <div class="stat-sub">MySQL on disk</div>
    </div>
</div>

<div class="two-col" style="align-items:start">

<!-- LEFT -->
<div>

    <!-- User Breakdown -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Users by Role</span>
            <a href="/icecashRec/admin/users.php" class="btn btn-ghost btn-sm" style="margin-left:auto">Manage Users &rarr;</a>
        </div>
        <table class="data-table">
            <thead><tr><th>Role</th><th>Total</th><th>Active</th><th>Inactive</th></tr></thead>
            <tbody>
            <?php
            $role_colors = array(
                'Admin'      => '#b91c3c',
                'Manager'    => '#856404',
                'Reconciler' => '#007a3d',
                'Uploader'   => '#0066cc',
            );
            foreach ($role_counts as $rc):
                $col = isset($role_colors[$rc['role']]) ? $role_colors[$rc['role']] : '#888';
            ?>
            <tr>
                <td><span style="font-size:10px;font-family:monospace;padding:2px 8px;border-radius:3px;background:<?= $col ?>20;color:<?= $col ?>;font-weight:600"><?= $rc['role'] ?></span></td>
                <td class="mono"><?= $rc['cnt'] ?></td>
                <td class="mono" style="color:var(--green)"><?= $rc['active'] ?></td>
                <td class="mono" style="color:#aaa"><?= $rc['cnt'] - $rc['active'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- All Users Quick View -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">All User Accounts</span></div>
        <table class="data-table">
            <thead><tr><th>User</th><th>Role</th><th>Last Login</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($all_users as $u):
                $col = isset($role_colors[$u['role']]) ? $role_colors[$u['role']] : '#888';
            ?>
            <tr>
                <td>
                    <div style="font-weight:500;font-size:12px"><?= htmlspecialchars($u['full_name']) ?></div>
                    <div class="mono dim" style="font-size:10px"><?= htmlspecialchars($u['username']) ?></div>
                </td>
                <td><span style="font-size:10px;font-family:monospace;padding:2px 7px;border-radius:3px;background:<?= $col ?>20;color:<?= $col ?>"><?= $u['role'] ?></span></td>
                <td class="mono dim" style="font-size:11px"><?= $u['last_login'] ? date('Y-m-d H:i', strtotime($u['last_login'])) : 'Never' ?></td>
                <td><span class="badge <?= $u['is_active'] ? 'reconciled' : 'inactive' ?>"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Data Volume by Table -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">Database Table Counts</span></div>
        <table class="data-table">
            <thead><tr><th>Table</th><th>Rows</th></tr></thead>
            <tbody>
            <?php foreach ($stats as $table => $count): ?>
            <tr>
                <td class="mono" style="font-size:12px"><?= $table ?></td>
                <td class="mono"><?= number_format($count) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

<!-- RIGHT -->
<div>

    <!-- Reconciliation & Upload Health -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">Reconciliation Run Health</span></div>
        <div class="panel-body">
            <?php
            $run_colors = array('complete'=>'green','running'=>'warn','failed'=>'red');
            $run_total  = array_sum($runs_by_status);
            if ($run_total === 0): ?>
            <p class="dim" style="font-size:12px">No reconciliation runs yet.</p>
            <?php else:
                foreach ($run_colors as $st => $color):
                    $cnt  = isset($runs_by_status[$st]) ? $runs_by_status[$st] : 0;
                    $pct  = $run_total > 0 ? round($cnt / $run_total * 100) : 0;
            ?>
            <div style="margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="font-size:12px;font-weight:500"><?= ucfirst($st) ?></span>
                    <span class="mono" style="font-size:11px"><?= $cnt ?> (<?= $pct ?>%)</span>
                </div>
                <div class="progress-wrap"><div class="progress-bar <?= $color ?>" style="width:<?= $pct ?>%"></div></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Upload Health -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">Upload Health</span></div>
        <div class="panel-body">
            <?php
            $status_colors = array('ok'=>'green','warning'=>'warn','failed'=>'red','processing'=>'gray');
            $upload_total  = array_sum($upload_by_status);
            if ($upload_total === 0): ?>
            <p class="dim" style="font-size:12px">No uploads yet.</p>
            <?php else:
                foreach ($status_colors as $st => $color):
                    $cnt = isset($upload_by_status[$st]) ? $upload_by_status[$st] : 0;
                    $pct = $upload_total > 0 ? round($cnt / $upload_total * 100) : 0;
            ?>
            <div style="margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="font-size:12px;font-weight:500"><?= ucfirst($st) ?></span>
                    <span class="mono" style="font-size:11px"><?= $cnt ?> (<?= $pct ?>%)</span>
                </div>
                <div class="progress-wrap"><div class="progress-bar <?= $color ?>" style="width:<?= $pct ?>%"></div></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Failed Login Attempts -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">Recent Failed Logins</span></div>
        <?php if (empty($failed_logins)): ?>
        <div class="panel-body"><p class="dim" style="font-size:12px">No failed logins recorded.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>User</th><th>IP Address</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($failed_logins as $fl): ?>
            <tr>
                <td style="font-size:12px"><?= htmlspecialchars($fl['full_name']) ?></td>
                <td class="mono" style="font-size:11px"><?= htmlspecialchars($fl['ip_address']) ?></td>
                <td class="mono dim" style="font-size:11px"><?= date('Y-m-d H:i', strtotime($fl['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- System Settings (read-only overview) -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">System Settings</span>
            <a href="/icecashRec/admin/settings.php" class="btn btn-ghost btn-sm" style="margin-left:auto">Edit &rarr;</a>
        </div>
        <table class="data-table">
            <thead><tr><th>Key</th><th>Value</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($settings_rows as $s): ?>
            <tr>
                <td class="mono" style="font-size:11px"><?= htmlspecialchars($s['setting_key']) ?></td>
                <td style="font-size:12px"><?= htmlspecialchars($s['setting_value']) ?></td>
                <td class="mono dim" style="font-size:11px"><?= date('Y-m-d', strtotime($s['updated_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Quick Admin Actions -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">Quick Actions</span></div>
        <div class="panel-body" style="display:flex;flex-direction:column;gap:8px">
            <a href="/icecashRec/admin/users.php"      class="btn btn-ghost"><i class="fa-solid fa-users"></i> Manage All Users</a>
            <a href="/icecashRec/admin/audit.php"       class="btn btn-ghost"><i class="fa-solid fa-clock-rotate-left"></i> View Full Audit Log</a>
            <a href="/icecashRec/admin/agents.php"      class="btn btn-ghost"><i class="fa-solid fa-building"></i> Manage Agents</a>
            <a href="/icecashRec/admin/settings.php"    class="btn btn-ghost"><i class="fa fa-cogs"></i> System Settings</a>
            <a href="/icecashRec/process/process_export.php?type=audit" class="btn btn-ghost" target="_blank">
                <i class="fa-solid fa-print"></i> Print Audit Log (PDF)
            </a>
        </div>
    </div>

</div>
</div>

<?php require_once '../layouts/layout_footer.php'; ?>
