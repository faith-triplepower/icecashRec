<?php
// ============================================================
// admin/statements.php
// Statement index with filters and bulk-issue-from-run.
// ============================================================
$page_title = 'Reconciliation Statements';
$active_nav = 'statements';
require_once '../layouts/layout_header.php';
require_role(['Manager','Reconciler','Admin']); // Reconcilers can view, only Manager/Admin can issue/finalize

$db   = get_db();
$user = current_user();
$uid  = (int)$user['id'];

$success = htmlspecialchars($_GET['success'] ?? '');
$error   = htmlspecialchars($_GET['error']   ?? '');

// Filters — Managers default to 'draft' view (statements awaiting sign-off).
// Reconcilers see all (they track their own issued drafts).
$period_from = $_GET['from']   ?? date('Y-m-01');
$period_to   = $_GET['to']     ?? date('Y-m-t');
$default_status = ($user['role'] === 'Manager' && !isset($_GET['status'])) ? 'draft' : '';
$filter_status = $_GET['status'] ?? $default_status;
$filter_run    = (int)($_GET['run_id'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;
$period_from = date('Y-m-d', strtotime($period_from));
$period_to   = date('Y-m-d', strtotime($period_to));

// Build WHERE clause
$where = array("s.period_from >= ? AND s.period_to <= ?");
$params = array($period_from, $period_to);
$types  = 'ss';

if ($filter_status && in_array($filter_status, array('draft','final','reviewed','cancelled'))) {
    $where[] = "s.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}
if ($filter_run > 0) {
    $where[] = "s.run_id = ?";
    $params[] = $filter_run;
    $types   .= 'i';
}

$where_sql = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT s.*, a.agent_name, a.agent_code, u.full_name AS generated_by_name
    FROM statements s
    JOIN agents a ON s.agent_id = a.id
    JOIN users u  ON s.generated_by = u.id
    WHERE $where_sql
    ORDER BY s.generated_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$statements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Aggregate totals for stat cards
$totals = array('cnt'=>0,'draft'=>0,'final'=>0,'reviewed'=>0,'cancelled'=>0,'variance_zwg'=>0,'variance_usd'=>0);
foreach ($statements as $s) {
    $totals['cnt']++;
    $totals[$s['status']] = ($totals[$s['status']] ?? 0) + 1;
    $totals['variance_zwg'] += (float)$s['variance_zwg'];
    $totals['variance_usd'] += (float)$s['variance_usd'];
}

// Recent runs for the "Bulk Issue" dropdown
$recent_runs = $db->query("
    SELECT r.id, r.period_label, r.product, r.date_from, r.date_to,
           (SELECT COUNT(*) FROM variance_results vr WHERE vr.run_id = r.id) variance_count,
           (SELECT COUNT(*) FROM statements st WHERE st.run_id = r.id AND st.status<>'cancelled') issued_count
    FROM reconciliation_runs r
    WHERE r.run_status = 'complete'
    ORDER BY r.started_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

function stmt_link($extra = array()) {
    $p = array_merge($_GET, $extra);
    unset($p['success'], $p['error']);
    return '?' . http_build_query($p);
}
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
      <h1>Reconciliation Statements</h1>
      <p>Formal per-agent statements — snapshots of reconciliation results, issued for review and sign-off.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="../process/process_export_csv.php?type=statements&from=<?= $period_from ?>&to=<?= $period_to ?>" class="btn btn-ghost" style="font-weight:600"><i class="fa-solid fa-download"></i> CSV</a>
      <?php if (in_array($user['role'], array('Manager','Reconciler','Admin'))): ?>
      <button class="btn btn-primary" onclick="document.getElementById('bulk-modal').style.display='flex'">+ Bulk Issue from Run</button>
      <?php else: ?>
      <span class="dim" style="font-size:11px;padding:6px 10px;background:#f0f0f0;border-radius:3px">View-only access</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success">✓ <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">⚠ <?= $error ?></div><?php endif; ?>

<?php if ($user['role'] === 'Manager' && ($totals['draft'] ?? 0) > 0): ?>
<div class="alert" style="background:#fff8e1;border-left:4px solid #d49a00;color:#5a4500;margin-bottom:16px">
  <strong><i class="fa-solid fa-inbox"></i> <?= (int)$totals['draft'] ?> draft statement<?= $totals['draft'] > 1 ? 's' : '' ?> awaiting your review.</strong>
  Review each statement, then finalize to lock it for audit.
  <?php if ($filter_status !== 'draft'): ?>
  <a href="<?= stmt_link(array('status'=>'draft')) ?>" style="margin-left:8px;color:#007a3d;font-weight:600;text-decoration:underline">Show drafts only</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div class="stat-grid">
  <div class="stat-card blue">
    <div class="stat-label">Total Statements</div>
    <div class="stat-value"><?= (int)$totals['cnt'] ?></div>
    <div class="stat-sub">In selected range</div>
  </div>
  <div class="stat-card warn">
    <div class="stat-label">Draft</div>
    <div class="stat-value"><?= (int)($totals['draft'] ?? 0) ?></div>
    <div class="stat-sub">Not yet finalized</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Final / Reviewed</div>
    <div class="stat-value"><?= (int)(($totals['final']??0) + ($totals['reviewed']??0)) ?></div>
    <div class="stat-sub"><?= (int)($totals['reviewed']??0) ?> reviewed</div>
  </div>
  <div class="stat-card <?= $totals['variance_zwg'] < 0 ? 'red' : 'blue' ?>">
    <div class="stat-label">Total Variance</div>
    <div class="stat-value" style="font-size:18px">ZWG <?= fmt_compact($totals["variance_zwg"]) ?></div>
    <div class="stat-sub">USD <?= number_format($totals['variance_usd']) ?></div>
  </div>
</div>

<!-- Filters -->
<form method="GET" class="panel" style="padding:12px 16px;margin:16px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
  <div>
    <label class="form-label" style="font-size:11px">From</label>
    <input type="date" name="from" class="form-input" value="<?= $period_from ?>">
  </div>
  <div>
    <label class="form-label" style="font-size:11px">To</label>
    <input type="date" name="to" class="form-input" value="<?= $period_to ?>">
  </div>
  <div>
    <label class="form-label" style="font-size:11px">Status</label>
    <select name="status" class="form-select">
      <option value="">All</option>
      <option value="draft"     <?= $filter_status==='draft'?'selected':'' ?>>Draft</option>
      <option value="final"     <?= $filter_status==='final'?'selected':'' ?>>Final</option>
      <option value="reviewed"  <?= $filter_status==='reviewed'?'selected':'' ?>>Reviewed</option>
      <option value="cancelled" <?= $filter_status==='cancelled'?'selected':'' ?>>Cancelled</option>
    </select>
  </div>
  <div>
    <label class="form-label" style="font-size:11px">Run ID</label>
    <input type="number" name="run_id" class="form-input" style="width:100px" value="<?= $filter_run ?: '' ?>">
  </div>
  <button type="submit" class="btn btn-primary">Apply</button>
  <a class="btn btn-ghost" href="statements.php">Reset</a>
</form>

<!-- Statements Table -->
<div class="panel">
  <table class="data-table">
    <thead>
      <tr>
        <th>Statement #</th>
        <th>Period</th>
        <th>Agent</th>
        <th style="text-align:right">Sales</th>
        <th style="text-align:right">Receipts</th>
        <th style="text-align:right">Variance</th>
        <th>Category</th>
        <th>Status</th>
        <th>Issued By</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($statements as $s): ?>
    <?php
      $status_cls = array('draft'=>'pending','final'=>'reconciled','reviewed'=>'active','cancelled'=>'inactive');
    ?>
    <tr>
      <td class="mono" style="color:var(--accent2);font-weight:600"><?= htmlspecialchars($s['statement_no']) ?></td>
      <td class="mono dim" style="font-size:11px"><?= $s['period_from'] ?> → <?= $s['period_to'] ?></td>
      <td>
        <div style="font-weight:500"><?= htmlspecialchars($s['agent_name']) ?></div>
        <div class="mono dim" style="font-size:10px"><?= htmlspecialchars($s['agent_code']) ?></div>
      </td>
      <td class="mono" style="text-align:right">
        ZWG <?= number_format($s['sales_zwg']) ?><br>
        <span class="dim">USD <?= number_format($s['sales_usd']) ?></span>
      </td>
      <td class="mono" style="text-align:right">
        ZWG <?= number_format($s['receipts_zwg']) ?><br>
        <span class="dim">USD <?= number_format($s['receipts_usd']) ?></span>
      </td>
      <td class="mono <?= $s['variance_zwg'] < 0 ? 'variance-neg' : ($s['variance_zwg'] == 0 ? 'variance-pos' : 'dim') ?>" style="text-align:right;font-weight:600">
        ZWG <?= number_format($s['variance_zwg']) ?><br>
        <span class="dim">USD <?= number_format($s['variance_usd']) ?></span>
      </td>
      <td style="font-size:11px;color:#888"><?= htmlspecialchars($s['variance_cat'] ?? '—') ?></td>
      <td><span class="badge <?= $status_cls[$s['status']] ?? 'pending' ?>"><?= strtoupper($s['status']) ?></span></td>
      <td class="dim" style="font-size:11px"><?= htmlspecialchars($s['generated_by_name']) ?><br><?= date('Y-m-d', strtotime($s['generated_at'])) ?></td>
      <td style="white-space:nowrap">
        <a href="statement_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-primary btn-sm" title="View details"><i class="fa-solid fa-eye"></i></a>
        <a href="statement_detail.php?id=<?= (int)$s['id'] ?>" target="_blank" onclick="setTimeout(function(){window.open('','_blank').print()},500)" class="btn btn-ghost btn-sm" title="Print"><i class="fa-solid fa-print"></i></a>
        <?php if ($user['role'] === 'Manager' && $s['status'] === 'draft' && (int)$s['generated_by'] !== $uid): ?>
        <form method="POST" action="../process/process_statements.php" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="finalize">
          <input type="hidden" name="statement_id" value="<?= (int)$s['id'] ?>">
          <button type="submit" class="btn btn-ghost btn-sm" style="color:#00a950" title="Finalize" onclick="return confirm('Finalize statement <?= htmlspecialchars($s['statement_no']) ?>?')"><i class="fa-solid fa-check"></i></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($statements)): ?>
    <tr><td colspan="10" class="dim" style="text-align:center;padding:20px">No statements in this range. Issue one from the bulk button above, or from a specific reconciliation run.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <?php
  $cnt_stmt = $db->prepare("SELECT COUNT(*) c FROM statements s WHERE $where_sql");
  $cnt_stmt->bind_param($types, ...$params);
  $cnt_stmt->execute();
  $total_rows = (int)$cnt_stmt->get_result()->fetch_assoc()['c'];
  $cnt_stmt->close();
  $total_pages = max(1, ceil($total_rows / $per_page));
  if ($total_pages > 1): ?>
  <div style="padding:12px 16px;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#666">
    <span>Page <?= $page ?> of <?= $total_pages ?> — <?= $total_rows ?> total</span>
    <div style="display:flex;gap:4px">
      <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= stmt_link(array('page'=>$page-1)) ?>">← Prev</a><?php endif; ?>
      <?php if ($page < $total_pages): ?><a class="btn btn-ghost btn-sm" href="<?= stmt_link(array('page'=>$page+1)) ?>">Next →</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Bulk Issue Modal -->
<div id="bulk-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:560px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Bulk Issue Statements from Run</span>
      <button onclick="document.getElementById('bulk-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_statements.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_issue">
      <p style="font-size:12px;color:#666;margin-top:0">Issues one draft statement per agent in the selected run. Skips agents that already have a non-cancelled statement for that run.</p>
      <div class="form-group">
        <label class="form-label">Reconciliation Run</label>
        <select name="run_id" class="form-select" required>
          <option value="">-- Pick a completed run --</option>
          <?php foreach ($recent_runs as $r): ?>
          <option value="<?= $r['id'] ?>">
            #<?= $r['id'] ?> — <?= htmlspecialchars($r['period_label']) ?> (<?= htmlspecialchars($r['product']) ?>) — <?= (int)$r['variance_count'] ?> agents, <?= (int)$r['issued_count'] ?> already issued
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Issue Statements</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('bulk-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('bulk-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
