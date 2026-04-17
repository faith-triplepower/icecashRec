<?php
// ============================================================
// admin/terminal_detail.php
// POS terminal detail with assignment history + recent receipts.
// ============================================================
$page_title = 'Terminal Details';
$active_nav = 'pos_terminals';
require_once '../layouts/layout_header.php';
require_role(['Manager','Reconciler','Admin']); // Uploaders don't need terminal detail

$db = get_db();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: ../modules/pos_terminals.php?error=' . urlencode('Invalid terminal id'));
    exit;
}

$t_stmt = $db->prepare("
    SELECT pt.*, a.agent_name, a.agent_code, a.region, b.bank_code
    FROM pos_terminals pt
    JOIN agents a ON pt.agent_id = a.id
    LEFT JOIN banks b ON pt.bank_id = b.id
    WHERE pt.id=?
");
$t_stmt->bind_param('i', $id);
$t_stmt->execute();
$t = $t_stmt->get_result()->fetch_assoc();
$t_stmt->close();

if (!$t) {
    header('Location: ../modules/pos_terminals.php?error=' . urlencode('Terminal not found'));
    exit;
}

$tid_str = $t['terminal_id'];

// Recent receipts on this terminal — credits only. A terminal is by
// definition a POS payment device, so all rows from it are inflows;
// any debit row here would be a data-quality anomaly.
$recent_receipts = $db->query("
    SELECT * FROM receipts
    WHERE terminal_id = '" . $db->real_escape_string($tid_str) . "'
      AND direction='credit'
    ORDER BY txn_date DESC, id DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Receipt stats — also credits only for consistency with the list above
$stats = $db->query("
    SELECT
      COUNT(*) total,
      SUM(CASE WHEN match_status='matched' THEN 1 ELSE 0 END) matched,
      SUM(CASE WHEN match_status='pending' THEN 1 ELSE 0 END) pending,
      SUM(CASE WHEN match_status='variance' THEN 1 ELSE 0 END) variance,
      COALESCE(SUM(CASE WHEN currency='ZWG' THEN amount END),0) total_zwg,
      COALESCE(SUM(CASE WHEN currency='USD' THEN amount END),0) total_usd
    FROM receipts
    WHERE terminal_id = '" . $db->real_escape_string($tid_str) . "'
      AND direction='credit'
")->fetch_assoc();

// Month-to-date count — auto-detect the latest month with data for
// this terminal so historical uploads are visible on first load.
$latest_month = $db->query("
    SELECT DATE_FORMAT(MAX(txn_date), '%Y-%m-01') AS ms
    FROM receipts
    WHERE terminal_id = '" . $db->real_escape_string($tid_str) . "' AND direction='credit'
")->fetch_assoc();
$mtd_start = ($latest_month && $latest_month['ms']) ? $latest_month['ms'] : date('Y-m-01');
$mtd_count = (int)$db->query("
    SELECT COUNT(*) c FROM receipts
    WHERE terminal_id = '" . $db->real_escape_string($tid_str) . "'
      AND direction='credit'
      AND txn_date >= '" . $mtd_start . "'
")->fetch_assoc()['c'];

// Assignment history
$history = $db->query("
    SELECT ta.*, a.agent_name, u.full_name AS changed_by_name
    FROM terminal_assignments ta
    JOIN agents a ON ta.agent_id = a.id
    LEFT JOIN users u ON ta.changed_by = u.id
    WHERE ta.terminal_id = $id
    ORDER BY ta.valid_from DESC, ta.id DESC
")->fetch_all(MYSQLI_ASSOC);

// Days idle
$days_idle = null;
if ($t['last_txn_at']) {
    $days_idle = (int)floor((time() - strtotime($t['last_txn_at'])) / 86400);
}
?>

<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1><?= htmlspecialchars($t['terminal_id']) ?></h1>
      <p>
        <?= htmlspecialchars($t['merchant_name']) ?>
        · <?= htmlspecialchars($t['bank_name']) ?>
        · <?= htmlspecialchars($t['location']) ?>
        · <span class="badge <?= $t['is_active'] ? 'active' : 'inactive' ?>"><?= $t['is_active'] ? 'ACTIVE' : 'INACTIVE' ?></span>
      </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="../modules/reconciliation.php?agent_id=<?= (int)$t['agent_id'] ?>" class="btn btn-primary"><i class="fa fa-refresh"></i> Reconcile</a>
      <a href="agent_detail.php?id=<?= (int)$t['agent_id'] ?>" class="btn btn-ghost">Agent: <?= htmlspecialchars($t['agent_name']) ?></a>
      <a href="../modules/pos_terminals.php" class="btn btn-ghost">← Back</a>
    </div>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card blue">
    <div class="stat-label">Total Receipts</div>
    <div class="stat-value"><?= fmt_compact($stats['total']) ?></div>
    <div class="stat-sub"><?= $mtd_count ?> this month</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Matched</div>
    <div class="stat-value"><?= fmt_compact($stats['matched']) ?></div>
    <div class="stat-sub"><?= $stats['total'] > 0 ? round($stats['matched'] / $stats['total'] * 100) : 0 ?>% match rate</div>
  </div>
  <div class="stat-card warn">
    <div class="stat-label">Pending / Variance</div>
    <div class="stat-value"><?= (int)$stats['pending'] + (int)$stats['variance'] ?></div>
    <div class="stat-sub"><?= (int)$stats['pending'] ?> pending · <?= (int)$stats['variance'] ?> variance</div>
  </div>
  <div class="stat-card <?= $days_idle !== null && $days_idle > 7 ? 'red' : 'blue' ?>">
    <div class="stat-label">Days Idle</div>
    <div class="stat-value"><?= $days_idle === null ? '—' : $days_idle ?></div>
    <div class="stat-sub">Last txn: <?= $t['last_txn_at'] ?: 'never' ?></div>
  </div>
</div>

<!-- Assignment history -->
<div class="panel">
  <div class="panel-header">
    <span class="panel-title">Assignment History</span>
    <span class="dim" style="font-size:11px;margin-left:10px">Which agent owned this terminal over time — past reconciliations attribute transactions to the correct owner</span>
  </div>
  <table class="data-table">
    <thead><tr><th>Agent</th><th>Valid From</th><th>Valid To</th><th>Changed By</th><th>Reason</th></tr></thead>
    <tbody>
      <?php foreach ($history as $h): ?>
      <tr>
        <td style="font-weight:500"><a href="agent_detail.php?id=<?= (int)$h['agent_id'] ?>"><?= htmlspecialchars($h['agent_name']) ?></a></td>
        <td class="mono"><?= $h['valid_from'] ?></td>
        <td class="mono <?= $h['valid_to'] ? 'dim' : '' ?>"><?= $h['valid_to'] ?: '— (current)' ?></td>
        <td class="dim"><?= htmlspecialchars($h['changed_by_name'] ?? '—') ?></td>
        <td class="dim" style="font-size:11px"><?= htmlspecialchars($h['reason'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($history)): ?>
      <tr><td colspan="5" class="dim" style="text-align:center;padding:20px">No assignment history recorded.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Recent receipts -->
<div class="panel">
  <div class="panel-header"><span class="panel-title">Recent Receipts (last 20)</span></div>
  <table class="data-table">
    <thead><tr><th>Ref #</th><th>Date</th><th>Channel</th><th>Amount</th><th>Currency</th><th>Match</th></tr></thead>
    <tbody>
      <?php foreach ($recent_receipts as $r): ?>
      <tr>
        <td class="mono" style="color:var(--accent2)"><?= htmlspecialchars($r['reference_no']) ?></td>
        <td class="mono dim"><?= $r['txn_date'] ?></td>
        <td><?= htmlspecialchars($r['channel']) ?></td>
        <td class="mono"><?= number_format($r['amount'], 2) ?></td>
        <td><span class="badge <?= $r['currency']==='USD'?'ccy-usd':'ccy-zwg' ?>"><?= $r['currency'] ?></span></td>
        <td><span class="badge <?= $r['match_status']==='matched'?'reconciled':($r['match_status']==='variance'?'variance':'pending') ?>"><?= ucfirst($r['match_status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($recent_receipts)): ?>
      <tr><td colspan="6" class="dim" style="text-align:center;padding:20px">No receipts recorded for this terminal yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require_once '../layouts/layout_footer.php'; ?>
