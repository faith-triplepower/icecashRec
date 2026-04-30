<?php
// ============================================================
// modules/dashboard.php
// Single dashboard with role-aware sections. The hero CTA and
// the main content area adapt to the user's role:
//   Uploader   → upload stats + recent own uploads
//   Reconciler → available datasets + last run + variances
//   Manager    → full oversight (KPIs, agents, charts, activity)
//   Admin      → same as Manager
// Sidebar visibility is handled in layouts/layout_header.php.
// ============================================================

$page_title = 'Dashboard';
$active_nav = 'dashboard';
require_once '../layouts/layout_header.php';

$db   = get_db();
$role = $user['role'];
$uid  = (int)$user['id'];

// Pick the dashboard period from the data itself, not the wall clock.
// Reason: users load historical files (e.g. March data on April 15)
// and "current calendar month" would show an empty dashboard.
//
// Strategy:
//   1. Prefer the most recent month that has BOTH sales AND receipts,
//      because reconciliation only makes sense when both sides exist.
//   2. If no month has both, fall back to the most recent month with
//      either, so the dashboard still shows something useful.
//   3. If the database is completely empty, fall back to the current
//      calendar month (fresh-install case).
//
// `?period=YYYY-MM` lets the user override via URL — handy for the
// cases where they want to look at an older month explicitly.
$override = isset($_GET['period']) && preg_match('/^\d{4}-\d{2}$/', $_GET['period']) ? $_GET['period'] : null;

if ($override) {
    $month_start = $override . '-01';
    $month_end   = date('Y-m-t', strtotime($month_start));
} else {
    // Months that have both sales and receipts, newest first
    $both = $db->query("
        SELECT DATE_FORMAT(s.txn_date, '%Y-%m-01') AS m
        FROM sales s
        WHERE EXISTS (
            SELECT 1 FROM receipts r
            WHERE DATE_FORMAT(r.txn_date, '%Y-%m') = DATE_FORMAT(s.txn_date, '%Y-%m')
        )
        ORDER BY s.txn_date DESC
        LIMIT 1
    ")->fetch_assoc();

    if ($both) {
        $month_start = $both['m'];
    } else {
        $either = $db->query("
            SELECT DATE_FORMAT(d, '%Y-%m-01') AS m
            FROM (
                SELECT MAX(txn_date) AS d FROM sales
                UNION ALL
                SELECT MAX(txn_date) AS d FROM receipts
            ) x
            WHERE d IS NOT NULL
            ORDER BY d DESC
            LIMIT 1
        ")->fetch_assoc();
        $month_start = $either ? $either['m'] : date('Y-m-01');
    }
    $month_end = date('Y-m-t', strtotime($month_start));
}
$period_label = date('F Y', strtotime($month_start));

// Available months for the period dropdown — union of months that
// have either sales or receipts, newest first, capped at 24 months.
$available_periods = $db->query("
    SELECT DISTINCT DATE_FORMAT(d, '%Y-%m') AS ym
    FROM (
        SELECT txn_date AS d FROM sales
        UNION ALL
        SELECT txn_date AS d FROM receipts
    ) u
    ORDER BY ym DESC
    LIMIT 24
")->fetch_all(MYSQLI_ASSOC);

// ── Greeting (everyone) ──────────────────────────────────────
$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

// ── Role flags for conditional sections ────────────────────
$is_uploader   = $role === 'Uploader';
$is_reconciler = $role === 'Reconciler';
$is_manager    = $role === 'Manager' || $role === 'Admin';

// ══════════════════════════════════════════════════════════
// UPLOADER DATA — only queried if needed
// ══════════════════════════════════════════════════════════
if ($is_uploader) {
    // Use the current calendar month for upload counts — the card says
    // "this month" meaning when files were submitted, not the transaction
    // period inside the data (which drives $month_start/$month_end for
    // the reconciliation view and would exclude April uploads of March data).
    $u_kpi_stmt = $db->prepare("
        SELECT
            COUNT(*) AS total_files,
            SUM(CASE WHEN upload_status='ok'      THEN 1 ELSE 0 END) AS ok_files,
            SUM(CASE WHEN upload_status='warning' THEN 1 ELSE 0 END) AS warn_files,
            SUM(CASE WHEN upload_status='failed'  THEN 1 ELSE 0 END) AS failed_files,
            COALESCE(SUM(record_count),0)        AS total_records
        FROM upload_history
        WHERE uploaded_by = ? AND created_at BETWEEN ? AND ?
    ");
    $mstart = date('Y-m-01') . ' 00:00:00';
    $mend   = date('Y-m-t')  . ' 23:59:59';
    $u_kpi_stmt->bind_param('iss', $uid, $mstart, $mend);
    $u_kpi_stmt->execute();
    $u_kpi = $u_kpi_stmt->get_result()->fetch_assoc();
    $u_kpi_stmt->close();

    // Include flag_status so the dashboard can show FLAGGED badges and
    // surface the attention-required banner. Joined on users to display
    // the flagger's name inline next to the flagged file.
    $u_recent_stmt = $db->prepare("
        SELECT uh.id, uh.filename, uh.file_type, uh.record_count, uh.upload_status, uh.created_at,
               uh.flag_status, uh.flag_reason, uh.flag_note, uh.flagged_at,
               fb.full_name AS flagged_by_name
        FROM upload_history uh
        LEFT JOIN users fb ON uh.flagged_by = fb.id
        WHERE uh.uploaded_by = ?
        ORDER BY uh.created_at DESC
        LIMIT 8
    ");
    $u_recent_stmt->bind_param('i', $uid);
    $u_recent_stmt->execute();
    $u_recent = $u_recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $u_recent_stmt->close();

    // Count of currently-flagged files for this uploader — drives
    // the "needs your attention" banner at the top of the dashboard.
    $u_flagged_count_stmt = $db->prepare("
        SELECT COUNT(*) c FROM upload_history
        WHERE uploaded_by = ? AND flag_status='flagged'
    ");
    $u_flagged_count_stmt->bind_param('i', $uid);
    $u_flagged_count_stmt->execute();
    $u_flagged_count = (int)$u_flagged_count_stmt->get_result()->fetch_assoc()['c'];
    $u_flagged_count_stmt->close();

    $u_success_rate = $u_kpi['total_files'] > 0
        ? round(($u_kpi['ok_files'] / $u_kpi['total_files']) * 100)
        : 100;
}

// ══════════════════════════════════════════════════════════
// RECONCILER DATA
// ══════════════════════════════════════════════════════════
if ($is_reconciler) {
    // Credit/debit split: receipts counts only count credits (inflows)
    // so Unmatched/Variance cards reflect money the matching engine
    // actually cares about. Float outflows get their own card below.
    $r_kpi_stmt = $db->prepare("
        SELECT
          (SELECT COUNT(*) FROM sales    WHERE txn_date BETWEEN ? AND ?) AS sales_cnt,
          (SELECT COUNT(*) FROM receipts WHERE txn_date BETWEEN ? AND ? AND direction='credit') AS rec_cnt,
          (SELECT COUNT(*) FROM receipts WHERE txn_date BETWEEN ? AND ? AND direction='credit' AND match_status='pending')  AS pending_cnt,
          (SELECT COUNT(*) FROM receipts WHERE txn_date BETWEEN ? AND ? AND direction='credit' AND match_status='variance') AS variance_cnt,
          (SELECT COUNT(*) FROM receipts WHERE txn_date BETWEEN ? AND ? AND direction='debit')  AS debit_cnt,
          (SELECT COALESCE(SUM(amount),0) FROM receipts WHERE txn_date BETWEEN ? AND ? AND direction='debit') AS debit_total
    ");
    $r_kpi_stmt->bind_param('ssssssssssss',
        $month_start,$month_end,$month_start,$month_end,
        $month_start,$month_end,$month_start,$month_end,
        $month_start,$month_end,$month_start,$month_end);
    $r_kpi_stmt->execute();
    $r_kpi = $r_kpi_stmt->get_result()->fetch_assoc();
    $r_kpi_stmt->close();

    $last_run = $db->query("
        SELECT r.*, u.full_name
        FROM reconciliation_runs r
        JOIN users u ON r.run_by = u.id
        ORDER BY r.started_at DESC LIMIT 1
    ")->fetch_assoc();

    // "Ready to reconcile" = healthy uploads with data that haven't
    // been flagged back to the uploader for fixing. Flagged files are
    // pending corrections and shouldn't show up as ready input.
    $r_ready_stmt = $db->prepare("
        SELECT uh.id, uh.filename, uh.file_type, uh.record_count,
               uh.created_at, u.full_name AS uploader_name
        FROM upload_history uh
        JOIN users u ON uh.uploaded_by = u.id
        WHERE uh.upload_status IN ('ok','warning')
          AND uh.record_count > 0
          AND (uh.flag_status IS NULL OR uh.flag_status <> 'flagged')
        ORDER BY uh.created_at DESC
        LIMIT 6
    ");
    $r_ready_stmt->execute();
    $r_ready = $r_ready_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $r_ready_stmt->close();
}

// ══════════════════════════════════════════════════════════
// MANAGER / ADMIN DATA (full oversight)
// ══════════════════════════════════════════════════════════
if ($is_manager) {
    $m_kpi_stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN currency='ZWG' THEN amount ELSE 0 END),0) AS total_zwg,
            COALESCE(SUM(CASE WHEN currency='USD' THEN amount ELSE 0 END),0) AS total_usd,
            COUNT(*) AS total_policies
        FROM sales WHERE txn_date BETWEEN ? AND ?
    ");
    $m_kpi_stmt->bind_param('ss', $month_start, $month_end);
    $m_kpi_stmt->execute();
    $m_kpi = $m_kpi_stmt->get_result()->fetch_assoc();
    $m_kpi_stmt->close();

    $m_unmatched = (int)$db->query("SELECT COUNT(*) c FROM receipts WHERE match_status='pending' AND direction='credit' AND txn_date BETWEEN '$month_start' AND '$month_end'")->fetch_assoc()['c'];
    $m_fx        = (int)$db->query("SELECT COUNT(*) c FROM sales WHERE currency_flag=1 AND txn_date BETWEEN '$month_start' AND '$month_end'")->fetch_assoc()['c'];
    $m_escalations_open = (int)$db->query("SELECT COUNT(*) c FROM escalations WHERE status IN ('pending','reviewed')")->fetch_assoc()['c'];
    $m_outflows  = $db->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) total FROM receipts WHERE direction='debit' AND txn_date BETWEEN '$month_start' AND '$month_end'")->fetch_assoc();

    $m_agents_stmt = $db->prepare("
        SELECT a.agent_name,
            COALESCE(SUM(CASE WHEN s.currency='ZWG' THEN s.amount ELSE 0 END),0) AS sales_zwg,
            COALESCE(SUM(CASE WHEN r.currency='ZWG' THEN r.amount ELSE 0 END),0) AS rec_zwg,
            COALESCE(SUM(CASE WHEN r.currency='ZWG' THEN r.amount ELSE 0 END),0)
              - COALESCE(SUM(CASE WHEN s.currency='ZWG' THEN s.amount ELSE 0 END),0) AS variance_zwg,
            CASE
              WHEN COUNT(s.id) = 0 THEN 'pending'
              WHEN ABS(
                COALESCE(SUM(CASE WHEN r.currency='ZWG' THEN r.amount ELSE 0 END),0)
                - COALESCE(SUM(CASE WHEN s.currency='ZWG' THEN s.amount ELSE 0 END),0)
              ) < 1 THEN 'reconciled'
              ELSE 'variance'
            END AS recon_status
        FROM agents a
        LEFT JOIN sales s    ON s.agent_id = a.id AND s.txn_date BETWEEN ? AND ?
        LEFT JOIN receipts r ON r.matched_sale_id = s.id
        WHERE a.is_active = 1
        GROUP BY a.id, a.agent_name
        ORDER BY a.agent_name
        LIMIT 8
    ");
    $m_agents_stmt->bind_param('ss', $month_start, $month_end);
    $m_agents_stmt->execute();
    $m_agents = $m_agents_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $m_agents_stmt->close();

    $m_activity = $db->query("
        SELECT al.detail, al.action_type, al.created_at, u.full_name
        FROM audit_log al JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC LIMIT 6
    ")->fetch_all(MYSQLI_ASSOC);
}

// Status class map used across sections
$status_class = array(
    'ok'         => 'active',
    'warning'    => 'pending',
    'failed'     => 'inactive',
    'processing' => 'pending',
    'complete'   => 'reconciled',
    'running'    => 'pending',
);
?>

<div class="row">
  <div class="col-md-12">
    <h2><?= $greeting ?>, <?= htmlspecialchars($user['name']) ?>!</h2>
    <p style="color:#888;font-size:13px;padding-top:0">
      <?php if ($is_uploader): ?>
        Your upload workspace &mdash; <?= date('l, d F Y') ?>
      <?php else: ?>
        <?= $is_reconciler ? 'Reconciliation workspace' : 'Daily overview' ?>
        &mdash; showing data for
        <?php if (!empty($available_periods)): ?>
        <select onchange="location.href='dashboard.php?period='+this.value" style="font-size:12px;padding:2px 6px;border:1px solid #ccc;border-radius:3px;font-weight:600">
          <?php foreach ($available_periods as $p):
            $sel = $p['ym'] === date('Y-m', strtotime($month_start)) ? 'selected' : '';
            $lbl = date('F Y', strtotime($p['ym'] . '-01')); ?>
          <option value="<?= $p['ym'] ?>" <?= $sel ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <strong><?= htmlspecialchars($period_label) ?></strong>
        <?php endif; ?>
        <?php if (!isset($_GET['period'])): ?>
        <span class="dim" style="font-size:11px">(auto-selected latest period with data)</span>
        <?php endif; ?>
      <?php endif; ?>
    </p>
  </div>
</div>
<hr />

<!-- ─────── HERO CTA (role-specific) ─────── -->
<?php if ($is_uploader): ?>
<div class="panel" style="background:linear-gradient(135deg,#00a950,#007a3d);color:#fff;margin-bottom:16px">
  <div style="padding:20px 24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <div style="font-size:17px;font-weight:700">Upload new source files</div>
      <div style="font-size:12px;opacity:0.85;margin-top:4px">CSV, Excel, or PDF — multiple files at once</div>
    </div>
    <a href="<?= BASE_URL ?>/utilities/upload.php" class="btn btn-primary" style="background:#fff;color:#00a950;font-weight:700;padding:10px 22px">
      <i class="fa fa-upload"></i>&nbsp; Upload Files
    </a>
  </div>
</div>
<?php elseif ($is_reconciler): ?>
<div class="panel" style="background:linear-gradient(135deg,#00a950,#007a3d);color:#fff;margin-bottom:16px">
  <div style="padding:20px 24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <div style="font-size:17px;font-weight:700">Run a reconciliation</div>
      <div style="font-size:12px;opacity:0.85;margin-top:4px">
        <?= fmt_compact($r_kpi['sales_cnt']) ?> sales &middot; <?= fmt_compact($r_kpi['rec_cnt']) ?> receipts in <?= htmlspecialchars($period_label) ?>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/modules/reconciliation.php" class="btn btn-primary" style="background:#fff;color:#00a950;font-weight:700;padding:10px 22px">
      <i class="fa fa-refresh"></i>&nbsp; Run Reconciliation
    </a>
  </div>
</div>
<?php endif; ?>

<!-- ─────── KPI CARDS (role-specific) ─────── -->
<?php if ($is_uploader): ?>
<div class="stat-grid">
  <div class="stat-card blue">
    <div class="stat-label">My Uploads (this month)</div>
    <div class="stat-value"><?= (int)$u_kpi['total_files'] ?></div>
    <div class="stat-sub">Files submitted</div>
  </div>
  <div class="stat-card <?= $u_success_rate >= 90 ? 'green' : ($u_success_rate >= 70 ? 'warn' : 'red') ?>">
    <div class="stat-label">Success Rate</div>
    <div class="stat-value"><?= $u_success_rate ?>%</div>
    <div class="stat-sub"><?= (int)$u_kpi['ok_files'] ?> ok &middot; <?= (int)$u_kpi['warn_files'] ?> warn &middot; <?= (int)$u_kpi['failed_files'] ?> failed</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Records Imported</div>
    <div class="stat-value"><?= fmt_compact($u_kpi['total_records']) ?></div>
    <div class="stat-sub">Rows added to the system</div>
  </div>
</div>

<?php elseif ($is_reconciler): ?>
<div class="stat-grid">
  <div class="stat-card blue">
    <div class="stat-label">Sales in Period</div>
    <div class="stat-value" title="<?= number_format($r_kpi['sales_cnt']) ?>"><?= fmt_compact($r_kpi['sales_cnt']) ?></div>
    <div class="stat-sub">Dataset A</div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">Receipts in Period</div>
    <div class="stat-value" title="<?= number_format($r_kpi['rec_cnt']) ?>"><?= fmt_compact($r_kpi['rec_cnt']) ?></div>
    <div class="stat-sub">Dataset B</div>
  </div>
  <div class="stat-card warn">
    <div class="stat-label">Unmatched</div>
    <div class="stat-value" title="<?= number_format($r_kpi['pending_cnt']) ?>"><?= fmt_compact($r_kpi['pending_cnt']) ?></div>
    <div class="stat-sub"><a href="<?= BASE_URL ?>/admin/unmatched.php" style="color:inherit">Review queue &rarr;</a></div>
  </div>
  <div class="stat-card red">
    <div class="stat-label">Variances</div>
    <div class="stat-value" title="<?= number_format($r_kpi['variance_cnt']) ?>"><?= fmt_compact($r_kpi['variance_cnt']) ?></div>
    <div class="stat-sub"><a href="<?= BASE_URL ?>/modules/variance.php" style="color:inherit">Variance report &rarr;</a></div>
  </div>
  <div class="stat-card" style="border-left:4px solid #8a5a00">
    <div class="stat-label">Float Outflows</div>
    <div class="stat-value" title="<?= number_format($r_kpi['debit_total'] ?? 0, 2) ?>"><?= fmt_compact($r_kpi['debit_total'] ?? 0) ?></div>
    <div class="stat-sub"><?= fmt_compact($r_kpi['debit_cnt'] ?? 0) ?> debits &middot; <a href="<?= BASE_URL ?>/admin/unmatched.php?tab=debits" style="color:inherit">Review &rarr;</a></div>
  </div>
</div>

<?php else: // manager / admin ?>
<div class="stat-grid">
  <div class="stat-card green">
    <div class="stat-label">Sales ZWG</div>
    <div class="stat-value" title="<?= number_format($m_kpi['total_zwg'], 2) ?>"><?= fmt_compact($m_kpi['total_zwg']) ?></div>
    <div class="stat-sub"><?= fmt_compact($m_kpi['total_policies']) ?> policies</div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">Sales USD</div>
    <div class="stat-value" title="<?= number_format($m_kpi['total_usd'], 2) ?>"><?= fmt_compact($m_kpi['total_usd']) ?></div>
    <div class="stat-sub">USD policies</div>
  </div>
  <div class="stat-card warn">
    <div class="stat-label">Unmatched</div>
    <div class="stat-value"><?= fmt_compact($m_unmatched) ?></div>
    <div class="stat-sub">Need review</div>
  </div>
  <?php if ($role === 'Manager'): ?>
  <div class="stat-card red">
    <div class="stat-label">Escalations</div>
    <div class="stat-value"><?= fmt_compact($m_escalations_open) ?></div>
    <div class="stat-sub"><a href="<?= BASE_URL ?>/admin/escalations.php" style="color:inherit">Open queue &rarr;</a></div>
  </div>
  <?php endif; ?>
  <div class="stat-card" style="border-left:4px solid #8a5a00">
    <div class="stat-label">Float Outflows</div>
    <div class="stat-value" title="<?= number_format($m_outflows['total'] ?? 0, 2) ?>"><?= fmt_compact($m_outflows['total'] ?? 0) ?></div>
    <div class="stat-sub"><?= fmt_compact($m_outflows['c'] ?? 0) ?> debits &middot; <a href="<?= BASE_URL ?>/admin/unmatched.php?tab=debits" style="color:inherit">Review &rarr;</a></div>
  </div>
</div>
<?php endif; ?>

<!-- ─────── MAIN CONTENT (role-specific) ─────── -->
<?php if ($is_uploader): ?>
<?php if ($u_flagged_count > 0): ?>
<div class="alert" style="background:#fff4d6;border-left:4px solid #c0392b;color:#5a0000;margin-top:16px;padding:12px 16px;border-radius:3px">
  <strong><i class="fa-solid fa-flag"></i>&nbsp; <?= $u_flagged_count ?> file<?= $u_flagged_count === 1 ? '' : 's' ?> need<?= $u_flagged_count === 1 ? 's' : '' ?> your attention</strong>
  &middot; A reconciler has flagged <?= $u_flagged_count === 1 ? 'a file' : 'files' ?> with issues. Review the note<?= $u_flagged_count === 1 ? '' : 's' ?> and re-upload a corrected version.
  <a href="<?= BASE_URL ?>/utilities/uploaded_files_list.php" style="color:#5a0000;font-weight:700;margin-left:6px">Review flagged files &rarr;</a>
</div>
<?php endif; ?>
<div class="panel" style="margin-top:16px">
  <div class="panel-header">
    <span class="panel-title">Your Recent Uploads</span>
    <a href="<?= BASE_URL ?>/utilities/uploaded_files_list.php" class="btn btn-ghost btn-sm" style="float:right;margin-top:-4px">View All &rarr;</a>
  </div>
  <table class="data-table">
    <thead><tr><th>Filename</th><th>Type</th><th style="text-align:right">Records</th><th>Status</th><th>Uploaded</th></tr></thead>
    <tbody>
      <?php foreach ($u_recent as $f): ?>
      <tr<?= $f['flag_status'] === 'flagged' ? ' style="background:#fff4d6"' : '' ?>>
        <td class="mono" style="font-size:11px;color:var(--accent2)">
          <a href="<?= BASE_URL ?>/utilities/uploaded_file_detail.php?id=<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['filename']) ?></a>
        </td>
        <td><span class="badge <?= $f['file_type']==='Sales'?'ccy-zwg':'ccy-usd' ?>"><?= $f['file_type'] ?></span></td>
        <td class="mono" style="text-align:right" title="<?= $f['record_count'] !== null ? number_format($f['record_count']) . ' records' : '' ?>"><?= fmt_compact($f['record_count']) ?></td>
        <td>
          <span class="badge <?= $status_class[$f['upload_status']] ?? 'pending' ?>"><?= strtoupper($f['upload_status']) ?></span>
          <?php if ($f['flag_status'] === 'flagged'): ?>
          <div class="badge variance" style="background:#f4c3c3;color:#8a0000;margin-top:3px" title="Flagged by <?= htmlspecialchars($f['flagged_by_name'] ?? '—') ?>: <?= htmlspecialchars($f['flag_note'] ?? '') ?>">
            <i class="fa-solid fa-flag"></i> FLAGGED
          </div>
          <?php elseif ($f['flag_status'] === 'resolved'): ?>
          <div class="badge reconciled" style="margin-top:3px">FIXED</div>
          <?php endif; ?>
        </td>
        <td class="mono dim" style="font-size:11px"><?= date('M d, H:i', strtotime($f['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($u_recent)): ?>
      <tr><td colspan="5" class="dim" style="text-align:center;padding:20px">No uploads yet. <a href="<?= BASE_URL ?>/utilities/upload.php"><strong>Upload your first file &rarr;</strong></a></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($is_reconciler): ?>
<?php if ($last_run): ?>
<div class="panel" style="margin-top:16px">
  <div class="panel-header">
    <span class="panel-title">Last Reconciliation Run</span>
    <a href="<?= BASE_URL ?>/modules/variance.php?run_id=<?= (int)$last_run['id'] ?>" class="btn btn-ghost btn-sm" style="float:right;margin-top:-4px">Open &rarr;</a>
  </div>
  <div style="padding:16px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:14px;font-size:12px">
    <div><span class="dim">Run #</span><br><strong class="mono">#<?= (int)$last_run['id'] ?></strong></div>
    <div><span class="dim">Period</span><br><strong><?= htmlspecialchars($last_run['period_label']) ?></strong></div>
    <div><span class="dim">Run By</span><br><strong><?= htmlspecialchars($last_run['full_name']) ?></strong></div>
    <div><span class="dim">Status</span><br><span class="badge <?= $status_class[$last_run['run_status']] ?? 'pending' ?>"><?= strtoupper($last_run['run_status']) ?></span></div>
    <div><span class="dim">Match Rate</span><br><strong class="mono"><?= $last_run['match_rate'] !== null ? number_format($last_run['match_rate'], 1) . '%' : '—' ?></strong></div>
    <div><span class="dim">Matched / Total</span><br><strong class="mono"><?= (int)$last_run['matched_count'] ?> / <?= (int)$last_run['total_sales'] ?></strong></div>
  </div>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:16px">
  <div class="panel-header">
    <span class="panel-title">Datasets Ready to Reconcile</span>
    <a href="<?= BASE_URL ?>/utilities/uploaded_files_list.php" class="btn btn-ghost btn-sm" style="float:right;margin-top:-4px">All &rarr;</a>
  </div>
  <table class="data-table">
    <thead><tr><th>Filename</th><th>Type</th><th>Uploader</th><th>Records</th><th>Date</th></tr></thead>
    <tbody>
      <?php foreach ($r_ready as $d): ?>
      <tr>
        <td class="mono" style="font-size:11px;color:var(--accent2)">
          <a href="<?= BASE_URL ?>/utilities/uploaded_file_detail.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['filename']) ?></a>
        </td>
        <td><span class="badge <?= $d['file_type']==='Sales'?'ccy-zwg':'ccy-usd' ?>"><?= $d['file_type'] ?></span></td>
        <td class="dim" style="font-size:11px"><?= htmlspecialchars($d['uploader_name']) ?></td>
        <td class="mono"><?= number_format($d['record_count']) ?></td>
        <td class="mono dim" style="font-size:11px"><?= date('M d', strtotime($d['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($r_ready)): ?>
      <tr><td colspan="5" class="dim" style="text-align:center;padding:20px">No datasets available. Ask an Uploader to ingest source files.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php else: // manager / admin ?>

<!-- ── Dashboard Charts (Manager/Admin) ── -->
<?php
// Match rate trend over last 12 months
$chart_months = array();
$chart_match_rates = array();
$chart_variances = array();
$chart_runs = $db->query("
    SELECT DATE_FORMAT(r.date_from, '%Y-%m') AS ym,
           MAX(r.match_rate) AS rate,
           MAX(r.total_variance_zwg) AS var_zwg
    FROM reconciliation_runs r
    WHERE r.run_status IN ('complete','superseded')
      AND r.date_from >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym ORDER BY ym
")->fetch_all(MYSQLI_ASSOC);
foreach ($chart_runs as $cr) {
    $chart_months[] = date('M Y', strtotime($cr['ym'] . '-01'));
    $chart_match_rates[] = round((float)$cr['rate'], 1);
    $chart_variances[] = round(abs((float)$cr['var_zwg']));
}

// Sales by payment method (pie chart)
$chart_method_labels = array();
$chart_method_values = array();
$method_data = $db->query("
    SELECT payment_method, COUNT(*) AS cnt
    FROM sales
    WHERE txn_date BETWEEN '$month_start' AND '$month_end'
    GROUP BY payment_method ORDER BY cnt DESC
")->fetch_all(MYSQLI_ASSOC);
foreach ($method_data as $md) {
    $chart_method_labels[] = $md['payment_method'];
    $chart_method_values[] = (int)$md['cnt'];
}

// Top 5 agents by variance (current period)
$chart_agent_names = array();
$chart_agent_var = array();
$top_agents = $db->query("
    SELECT a.agent_name, ABS(vr.variance_zwg) AS abs_var
    FROM variance_results vr
    JOIN agents a ON vr.agent_id = a.id
    WHERE vr.run_id = (SELECT id FROM reconciliation_runs WHERE run_status='complete' ORDER BY id DESC LIMIT 1)
    ORDER BY abs_var DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
foreach ($top_agents as $ta) {
    $chart_agent_names[] = strlen($ta['agent_name']) > 20 ? substr($ta['agent_name'], 0, 18) . '..' : $ta['agent_name'];
    $chart_agent_var[] = round((float)$ta['abs_var']);
}
?>
<?php if (!empty($chart_months) || !empty($chart_method_labels) || !empty($chart_agent_names)): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;margin-top:16px">
  <?php if (!empty($chart_months)): ?>
  <div class="panel">
    <div class="panel-header"><span class="panel-title">Match Rate Trend</span></div>
    <div class="panel-body" style="padding:12px"><canvas id="chart-match-rate" height="180"></canvas></div>
  </div>
  <?php endif; ?>
  <?php if (!empty($chart_agent_names)): ?>
  <div class="panel">
    <div class="panel-header"><span class="panel-title">Top 5 Agents by Variance (ZWG)</span></div>
    <div class="panel-body" style="padding:12px"><canvas id="chart-agents" height="180"></canvas></div>
  </div>
  <?php endif; ?>
  <?php if (!empty($chart_method_labels)): ?>
  <div class="panel">
    <div class="panel-header"><span class="panel-title">Sales by Payment Method</span></div>
    <div class="panel-body" style="padding:12px;display:flex;justify-content:center"><canvas id="chart-methods" height="180" style="max-width:300px"></canvas></div>
  </div>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
var chartColors = { green: '#00a950', red: '#c0392b', blue: '#0066cc', amber: '#d49a00', gray: '#888' };
<?php if (!empty($chart_months)): ?>
new Chart(document.getElementById('chart-match-rate'), {
  type: 'line',
  data: {
    labels: <?= json_encode($chart_months) ?>,
    datasets: [{
      label: 'Match Rate %',
      data: <?= json_encode($chart_match_rates) ?>,
      borderColor: chartColors.green, backgroundColor: 'rgba(0,112,60,0.08)',
      tension: 0.3, fill: true, pointRadius: 4
    }]
  },
  options: { responsive:true, scales:{ y:{ beginAtZero:true, max:100, ticks:{ callback:function(v){return v+'%'} } } }, plugins:{ legend:{display:false} } }
});
<?php endif; ?>
<?php if (!empty($chart_agent_names)): ?>
new Chart(document.getElementById('chart-agents'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($chart_agent_names) ?>,
    datasets: [{
      label: 'Variance ZWG',
      data: <?= json_encode($chart_agent_var) ?>,
      backgroundColor: [chartColors.red, chartColors.amber, chartColors.blue, chartColors.green, chartColors.gray]
    }]
  },
  options: { indexAxis:'y', responsive:true, plugins:{ legend:{display:false} }, scales:{ x:{ ticks:{ callback:function(v){return v>=1000?(v/1000)+'K':v} } } } }
});
<?php endif; ?>
<?php if (!empty($chart_method_labels)): ?>
new Chart(document.getElementById('chart-methods'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($chart_method_labels) ?>,
    datasets: [{
      data: <?= json_encode($chart_method_values) ?>,
      backgroundColor: ['#00a950', '#0066cc', '#d49a00', '#c0392b', '#888', '#6F4E37'],
      borderWidth: 2, borderColor: '#fff'
    }]
  },
  options: { responsive:true, plugins:{ legend:{ position:'right', labels:{ font:{size:11}, padding:8 } } } }
});
<?php endif; ?>
</script>
<?php endif; ?>

<div class="panel" style="margin-top:16px">
  <div class="panel-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="panel-title">Agent Reconciliation Status</span>
    <div style="display:flex;gap:8px;align-items:center">
      <input type="text" id="dash-agent-search" placeholder="Search agent..." oninput="dashFilterAgents()" style="padding:4px 10px;border:1px solid #ddd;border-radius:4px;font-size:11px;width:160px">
      <a href="<?= BASE_URL ?>/admin/agents.php" class="btn btn-ghost btn-sm">View all &rarr;</a>
    </div>
  </div>
  <table class="data-table" id="dash-agent-table">
    <thead><tr><th>Agent</th><th>Sales ZWG</th><th>Receipts ZWG</th><th>Variance</th><th>Status</th></tr></thead>
    <tbody>
      <?php $dash_idx = 0; foreach ($m_agents as $a): $dash_idx++; ?>
      <tr class="dash-agent-row" style="<?= $dash_idx > 10 ? 'display:none' : '' ?>">
        <td><?= htmlspecialchars($a['agent_name']) ?></td>
        <td class="mono"><?= number_format($a['sales_zwg']) ?></td>
        <td class="mono"><?= $a['rec_zwg'] > 0 ? number_format($a['rec_zwg']) : '—' ?></td>
        <td class="mono <?= $a['variance_zwg'] < 0 ? 'variance-neg' : ($a['variance_zwg'] == 0 ? 'variance-pos' : 'dim') ?>">
          <?= $a['rec_zwg'] > 0 ? number_format($a['variance_zwg']) : '—' ?>
        </td>
        <td><span class="badge <?= $status_class[$a['recon_status']] ?? 'pending' ?>"><?= ucfirst($a['recon_status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($m_agents)): ?>
      <tr><td colspan="5" class="dim" style="text-align:center;padding:20px">No agent data for this month yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <?php if (count($m_agents) > 10): ?>
  <div id="dash-agent-showmore" style="text-align:center;padding:10px;border-top:1px solid #eee">
    <button class="btn btn-ghost" onclick="dashShowAllAgents()" style="font-weight:600">Show all <?= count($m_agents) ?> agents ▾</button>
  </div>
  <?php endif; ?>
</div>
<script>
var dashAllShown = false;
function dashShowAllAgents() {
  document.querySelectorAll('.dash-agent-row').forEach(function(r) { r.style.display = ''; });
  dashAllShown = true;
  var bar = document.getElementById('dash-agent-showmore');
  if (bar) bar.style.display = 'none';
}
function dashFilterAgents() {
  var q = document.getElementById('dash-agent-search').value.toLowerCase();
  var rows = document.querySelectorAll('.dash-agent-row');
  var shown = 0;
  rows.forEach(function(r, i) {
    var name = r.cells[0].textContent.toLowerCase();
    var match = !q || name.indexOf(q) !== -1;
    if (q) {
      r.style.display = match ? '' : 'none';
    } else {
      r.style.display = (dashAllShown || shown < 10) ? '' : 'none';
    }
    if (r.style.display !== 'none') shown++;
  });
  var bar = document.getElementById('dash-agent-showmore');
  if (bar) bar.style.display = (q || dashAllShown) ? 'none' : '';
}
</script>

<?php endif; ?>

<?php require_once '../layouts/layout_footer.php'; ?>
