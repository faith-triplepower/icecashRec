<?php
// ============================================================
// admin/agent_detail.php
// Per-agent detail page with stats, recent runs, escalations.
// ============================================================
$page_title = 'Agent Details';
$active_nav = 'agents';
require_once '../layouts/layout_header.php';
require_role(['Manager','Reconciler','Admin']); // Uploaders don't need agent detail

$db       = get_db();
$agent_id = (int)($_GET['id'] ?? 0);

if ($agent_id <= 0) {
    header('Location: agents.php?error=' . urlencode('Invalid agent id'));
    exit;
}

// ── Agent header ────────────────────────────────────────────
$a_stmt = $db->prepare("SELECT * FROM agents WHERE id=?");
$a_stmt->bind_param('i', $agent_id);
$a_stmt->execute();
$agent = $a_stmt->get_result()->fetch_assoc();
$a_stmt->close();

if (!$agent) {
    header('Location: agents.php?error=' . urlencode('Agent not found'));
    exit;
}

// ── Summary stats ───────────────────────────────────────────
$stats = $db->query("
    SELECT
      COUNT(*)                                                  AS total_sales,
      COALESCE(SUM(CASE WHEN currency='ZWG' THEN amount END),0) AS total_zwg,
      COALESCE(SUM(CASE WHEN currency='USD' THEN amount END),0) AS total_usd,
      MAX(txn_date)                                             AS last_sale
    FROM sales WHERE agent_id = $agent_id
")->fetch_assoc();

$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');
$mtd = $db->query("
    SELECT
      COUNT(*) cnt,
      COALESCE(SUM(CASE WHEN currency='ZWG' THEN amount END),0) mtd_zwg,
      COALESCE(SUM(CASE WHEN currency='USD' THEN amount END),0) mtd_usd
    FROM sales
    WHERE agent_id = $agent_id
      AND txn_date BETWEEN '$month_start' AND '$month_end'
")->fetch_assoc();

$terminal_count = (int)$db->query("SELECT COUNT(*) c FROM pos_terminals WHERE agent_id = $agent_id")->fetch_assoc()['c'];

$recon_summary = $db->query("
    SELECT COUNT(DISTINCT vr.run_id) recon_count,
           COALESCE(SUM(vr.variance_zwg),0) lifetime_var_zwg,
           COALESCE(SUM(vr.variance_usd),0) lifetime_var_usd,
           MAX(r.completed_at) last_completed
    FROM variance_results vr
    JOIN reconciliation_runs r ON r.id = vr.run_id
    WHERE vr.agent_id = $agent_id
")->fetch_assoc();

// ── Recent reconciliation runs for this agent ──────────────
$recent_runs = $db->query("
    SELECT r.id, r.period_label, r.product, r.date_from, r.date_to,
           r.run_status, r.started_at, r.completed_at,
           vr.sales_zwg, vr.sales_usd, vr.receipts_zwg, vr.receipts_usd,
           vr.variance_zwg, vr.variance_usd, vr.recon_status, vr.variance_cat
    FROM variance_results vr
    JOIN reconciliation_runs r ON r.id = vr.run_id
    WHERE vr.agent_id = $agent_id
    ORDER BY r.started_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// ── Recent sales ───────────────────────────────────────────
$recent_sales = $db->query("
    SELECT s.*, (SELECT r.id FROM receipts r WHERE r.matched_sale_id = s.id LIMIT 1) AS matched_receipt_id
    FROM sales s
    WHERE s.agent_id = $agent_id
    ORDER BY s.txn_date DESC, s.id DESC
    LIMIT 15
")->fetch_all(MYSQLI_ASSOC);

// ── Terminals for this agent ───────────────────────────────
$terminals = $db->query("
    SELECT * FROM pos_terminals WHERE agent_id = $agent_id ORDER BY terminal_id
")->fetch_all(MYSQLI_ASSOC);

// ── Open escalations for this agent ────────────────────────
$open_escalations = $db->query("
    SELECT id, priority, action_detail, status, created_at, variance_zwg, variance_usd
    FROM escalations
    WHERE agent_id = $agent_id AND status IN ('pending','reviewed')
    ORDER BY created_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1><?= htmlspecialchars($agent['agent_name']) ?></h1>
      <p>
        <span class="mono" style="color:var(--accent2)"><?= htmlspecialchars($agent['agent_code']) ?></span>
        · <?= htmlspecialchars($agent['agent_type']) ?>
        · <?= htmlspecialchars($agent['region']) ?>
        · <?= htmlspecialchars($agent['currency']) ?>
        · <span class="badge <?= $agent['is_active'] ? 'active' : 'inactive' ?>"><?= $agent['is_active'] ? 'ACTIVE' : 'INACTIVE' ?></span>
      </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="../modules/reconciliation.php?agent_id=<?= $agent_id ?>" class="btn btn-primary"><i class="fa fa-refresh"></i> Run Reconciliation</a>
      <a href="agents.php" class="btn btn-ghost">← Back to Agents</a>
    </div>
  </div>
</div>

<!-- Stats -->
<div class="stat-grid">
  <div class="stat-card blue">
    <div class="stat-label">Total Sales (all time)</div>
    <div class="stat-value"><?= fmt_compact($stats['total_sales']) ?></div>
    <div class="stat-sub">Last sale: <?= $stats['last_sale'] ?: '—' ?></div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">This Month (ZWG)</div>
    <div class="stat-value"><span class="stat-currency">ZWG</span><?= fmt_compact($mtd['mtd_zwg']) ?></div>
    <div class="stat-sub"><?= (int)$mtd['cnt'] ?> transactions</div>
  </div>
  <div class="stat-card <?= $recon_summary['lifetime_var_zwg'] < 0 ? 'red' : 'warn' ?>">
    <div class="stat-label">Lifetime Variance (ZWG)</div>
    <div class="stat-value"><span class="stat-currency">ZWG</span><?= fmt_compact($recon_summary['lifetime_var_zwg']) ?></div>
    <div class="stat-sub"><?= (int)$recon_summary['recon_count'] ?> reconciliation runs</div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">POS Terminals</div>
    <div class="stat-value"><?= $terminal_count ?></div>
    <div class="stat-sub">Linked to this agent</div>
  </div>
</div>

<?php if (!empty($open_escalations)): ?>
<div class="panel" style="border-left:4px solid #d49a00">
  <div class="panel-header"><span class="panel-title">⚑ Open Escalations (<?= count($open_escalations) ?>)</span></div>
  <table class="data-table">
    <thead><tr><th>#</th><th>Priority</th><th>Status</th><th>Detail</th><th>Var ZWG</th><th>Var USD</th><th>Created</th></tr></thead>
    <tbody>
      <?php foreach ($open_escalations as $e): ?>
      <tr>
        <td class="mono">#<?= $e['id'] ?></td>
        <td><span class="badge <?= $e['priority'] ?>"><?= strtoupper($e['priority']) ?></span></td>
        <td><span class="badge <?= $e['status'] ?>"><?= ucfirst($e['status']) ?></span></td>
        <td style="font-size:12px"><?= htmlspecialchars($e['action_detail']) ?></td>
        <td class="mono"><?= number_format($e['variance_zwg'], 2) ?></td>
        <td class="mono dim"><?= number_format($e['variance_usd'], 2) ?></td>
        <td class="dim" style="font-size:11px"><?= $e['created_at'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Recent Reconciliation Runs -->
<div class="panel">
  <div class="panel-header"><span class="panel-title">Recent Reconciliation Runs</span></div>
  <table class="data-table">
    <thead>
      <tr>
        <th>Run #</th><th>Period</th><th>Product</th>
        <th>Sales ZWG</th><th>Receipts ZWG</th><th>Var ZWG</th>
        <th>Category</th><th>Status</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recent_runs as $r): ?>
      <tr>
        <td class="mono">#<?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['period_label']) ?></td>
        <td class="dim"><?= htmlspecialchars($r['product']) ?></td>
        <td class="mono"><?= number_format($r['sales_zwg']) ?></td>
        <td class="mono"><?= number_format($r['receipts_zwg']) ?></td>
        <td class="<?= $r['variance_zwg'] < 0 ? 'variance-neg' : ($r['variance_zwg'] == 0 ? 'variance-pos' : 'dim') ?>"><?= number_format($r['variance_zwg']) ?></td>
        <td style="font-size:11px;color:#888"><?= htmlspecialchars($r['variance_cat'] ?? '—') ?></td>
        <td><span class="badge <?= $r['recon_status'] ?>"><?= ucfirst($r['recon_status']) ?></span></td>
        <td><a href="../modules/variance.php?run_id=<?= $r['id'] ?>" class="btn btn-ghost btn-sm">View</a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($recent_runs)): ?>
      <tr><td colspan="9" class="dim" style="text-align:center;padding:20px">No reconciliation runs include this agent yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="two-col" style="align-items:start;gap:16px">
  <!-- Recent Sales -->
  <div class="panel">
    <div class="panel-header"><span class="panel-title">Recent Sales (<?= count($recent_sales) ?>)</span></div>
    <table class="data-table">
      <thead><tr><th>Policy #</th><th>Date</th><th>Method</th><th>Amount</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($recent_sales as $s): ?>
        <tr>
          <td class="mono" style="color:var(--accent2)"><?= htmlspecialchars($s['policy_number']) ?></td>
          <td class="mono dim"><?= $s['txn_date'] ?></td>
          <td style="font-size:11px"><?= htmlspecialchars($s['payment_method']) ?></td>
          <td class="mono"><?= htmlspecialchars($s['currency']) ?> <?= number_format($s['amount']) ?></td>
          <td>
            <?php if ($s['matched_receipt_id']): ?>
              <span class="badge matched">Matched</span>
            <?php else: ?>
              <span class="badge pending">Unmatched</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent_sales)): ?>
        <tr><td colspan="5" class="dim" style="text-align:center;padding:20px">No sales recorded for this agent.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Terminals -->
  <div class="panel">
    <div class="panel-header"><span class="panel-title">POS Terminals (<?= count($terminals) ?>)</span></div>
    <table class="data-table">
      <thead><tr><th>Terminal ID</th><th>Bank</th><th>Location</th><th>Currency</th><th>Last Txn</th></tr></thead>
      <tbody>
        <?php foreach ($terminals as $t): ?>
        <tr>
          <td class="mono" style="color:var(--accent2)"><?= htmlspecialchars($t['terminal_id']) ?></td>
          <td style="font-size:11px"><?= htmlspecialchars($t['bank_name']) ?></td>
          <td class="dim" style="font-size:11px"><?= htmlspecialchars($t['location']) ?></td>
          <td class="mono dim" style="font-size:11px"><?= htmlspecialchars($t['currency']) ?></td>
          <td class="mono dim" style="font-size:11px"><?= $t['last_txn_at'] ? date('Y-m-d', strtotime($t['last_txn_at'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($terminals)): ?>
        <tr><td colspan="5" class="dim" style="text-align:center;padding:20px">No POS terminals linked.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once '../layouts/layout_footer.php'; ?>
