<?php
// ============================================================
// modules/matched_receipts.php
// Lists all matched receipt ↔ sale pairs for a reconciliation run.
// ============================================================
$page_title = 'Matched Pairs';
$active_nav = 'reconciliation';
require_once '../layouts/layout_header.php';
require_role(['Manager', 'Reconciler', 'Admin']);

$db     = get_db();
$run_id = (int)($_GET['run_id'] ?? 0);

if (!$run_id) {
    header('Location: reconciliation.php?error=Invalid+run+ID');
    exit;
}

// Load the run so we know the date range and period label
$rs = $db->prepare("SELECT id, period_label, product, date_from, date_to, matched_count, total_receipts, match_rate FROM reconciliation_runs WHERE id = ?");
$rs->bind_param('i', $run_id);
$rs->execute();
$run = $rs->get_result()->fetch_assoc();
$rs->close();

if (!$run) {
    header('Location: reconciliation.php?error=Run+not+found');
    exit;
}

// Optional filters from query string
$filter_agent     = (int)($_GET['agent_id']   ?? 0);
$filter_channel   = trim($_GET['channel']     ?? '');
$filter_confidence = trim($_GET['confidence'] ?? '');
$filter_status    = trim($_GET['status']      ?? '');

// Build the matched-pairs query dynamically based on active filters
$where_parts = [
    "r.txn_date BETWEEN ? AND ?",
    "r.match_status IN ('matched','variance')",
    "r.matched_sale_id IS NOT NULL",
];
$bind_types = 'ss';
$bind_vals  = [$run['date_from'], $run['date_to']];

if ($filter_agent) {
    $where_parts[] = 's.agent_id = ?';
    $bind_types   .= 'i';
    $bind_vals[]   = $filter_agent;
}
if ($filter_channel !== '') {
    $where_parts[] = 'r.channel = ?';
    $bind_types   .= 's';
    $bind_vals[]   = $filter_channel;
}
if ($filter_confidence !== '') {
    $where_parts[] = 'r.match_confidence = ?';
    $bind_types   .= 's';
    $bind_vals[]   = $filter_confidence;
}
if ($filter_status !== '') {
    $where_parts[] = 'r.match_status = ?';
    $bind_types   .= 's';
    $bind_vals[]   = $filter_status;
}

$where_sql = implode(' AND ', $where_parts);
$sql = "
    SELECT
        r.id               AS receipt_id,
        r.reference_no     AS receipt_ref,
        r.txn_date         AS receipt_date,
        r.channel,
        r.source_name,
        r.amount           AS receipt_amount,
        r.currency         AS receipt_currency,
        r.match_status,
        r.match_confidence,
        r.matched_policy,
        s.id               AS sale_id,
        s.policy_number,
        s.txn_date         AS sale_date,
        s.amount           AS sale_amount,
        s.currency         AS sale_currency,
        s.product,
        s.payment_method,
        a.id               AS agent_id,
        a.agent_name,
        a.agent_code
    FROM receipts r
    INNER JOIN sales s ON r.matched_sale_id = s.id
    LEFT JOIN agents a ON s.agent_id = a.id
    WHERE $where_sql
    ORDER BY r.txn_date DESC, a.agent_name, r.reference_no
";

$pstmt = $db->prepare($sql);
$pstmt->bind_param($bind_types, ...$bind_vals);
$pstmt->execute();
$pairs = $pstmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pstmt->close();

// Dropdown data for filters
$agents   = $db->query("SELECT id, agent_name FROM agents WHERE is_active=1 ORDER BY agent_name")->fetch_all(MYSQLI_ASSOC);
$channels = ['Bank POS','iPOS','EcoCash','Zimswitch','Broker'];

// KPI stats — always from the full run date range, independent of user filters
// so the cards reflect the whole run even when filters narrow the table.
$kpi_stmt = $db->prepare("
    SELECT
        COUNT(*)                                  AS total_matched,
        SUM(r.match_status = 'variance')          AS variance_count,
        SUM(r.match_confidence = 'high')          AS high_conf,
        COALESCE(SUM(r.amount), 0)                AS receipt_total
    FROM receipts r
    WHERE r.txn_date BETWEEN ? AND ?
      AND r.match_status IN ('matched','variance')
      AND r.matched_sale_id IS NOT NULL
");
$kpi_stmt->bind_param('ss', $run['date_from'], $run['date_to']);
$kpi_stmt->execute();
$kpi = $kpi_stmt->get_result()->fetch_assoc();
$kpi_stmt->close();

$total_matched  = (int)$kpi['total_matched'];
$variance_count = (int)$kpi['variance_count'];
$high_conf      = (int)$kpi['high_conf'];
$receipt_total  = (float)$kpi['receipt_total'];
$high_conf_pct  = $total_matched > 0 ? round($high_conf / $total_matched * 100) : 0;
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:12px">
    <div>
      <h1>Matched Receipt–Sale Pairs</h1>
      <p><?= htmlspecialchars($run['period_label']) ?> &bull; <?= htmlspecialchars($run['product']) ?></p>
    </div>
    <div style="display:flex;gap:8px">
      <a href="../process/process_export_csv.php?type=matched_pairs&run_id=<?= $run_id ?>" class="btn btn-ghost" style="font-size:12px;font-weight:700">
        <i class="fa-solid fa-download"></i> Export CSV
      </a>
      <a href="reconciliation_results.php?run_id=<?= $run_id ?>" class="btn btn-ghost">
        <i class="fa-solid fa-arrow-left"></i> Back to Results
      </a>
    </div>
  </div>
</div>

<!-- KPI strip -->
<div class="stat-grid" style="margin-bottom:20px">
  <div class="stat-card green">
    <div class="stat-value"><?= $total_matched ?></div>
    <div class="stat-sub">Matched Pairs</div>
  </div>
  <div class="stat-card warn">
    <div class="stat-value"><?= $variance_count ?></div>
    <div class="stat-sub">Amount Variances</div>
  </div>
  <div class="stat-card blue">
    <div class="stat-value"><?= $high_conf_pct ?>% <span style="font-size:12px;color:#888"><?= $high_conf ?> / <?= $total_matched ?></span></div>
    <div class="stat-sub">High Confidence</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= number_format($receipt_total, 2) ?></div>
    <div class="stat-sub">Total Matched ZWG</div>
  </div>
</div>

<!-- Filter bar -->
<div class="panel" style="margin-bottom:16px">
  <div class="panel-body" style="padding:12px 16px">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="run_id" value="<?= $run_id ?>">

      <div style="flex:1;min-width:160px">
        <label style="font-size:11px;font-weight:600;color:#888;display:block;margin-bottom:4px">AGENT</label>
        <select name="agent_id" class="form-select" style="font-size:12px">
          <option value="0">All Agents</option>
          <?php foreach ($agents as $ag): ?>
          <option value="<?= $ag['id'] ?>" <?= $filter_agent === (int)$ag['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ag['agent_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="flex:1;min-width:140px">
        <label style="font-size:11px;font-weight:600;color:#888;display:block;margin-bottom:4px">CHANNEL</label>
        <select name="channel" class="form-select" style="font-size:12px">
          <option value="">All Channels</option>
          <?php foreach ($channels as $ch): ?>
          <option value="<?= htmlspecialchars($ch) ?>" <?= $filter_channel === $ch ? 'selected' : '' ?>><?= htmlspecialchars($ch) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="flex:1;min-width:140px">
        <label style="font-size:11px;font-weight:600;color:#888;display:block;margin-bottom:4px">CONFIDENCE</label>
        <select name="confidence" class="form-select" style="font-size:12px">
          <option value="">Any Confidence</option>
          <option value="high"   <?= $filter_confidence === 'high'   ? 'selected' : '' ?>>High</option>
          <option value="medium" <?= $filter_confidence === 'medium' ? 'selected' : '' ?>>Medium</option>
          <option value="low"    <?= $filter_confidence === 'low'    ? 'selected' : '' ?>>Low</option>
          <option value="manual" <?= $filter_confidence === 'manual' ? 'selected' : '' ?>>Manual</option>
        </select>
      </div>

      <div style="flex:1;min-width:130px">
        <label style="font-size:11px;font-weight:600;color:#888;display:block;margin-bottom:4px">STATUS</label>
        <select name="status" class="form-select" style="font-size:12px">
          <option value="">All Statuses</option>
          <option value="matched"  <?= $filter_status === 'matched'  ? 'selected' : '' ?>>Matched</option>
          <option value="variance" <?= $filter_status === 'variance' ? 'selected' : '' ?>>Variance</option>
        </select>
      </div>

      <div>
        <button type="submit" class="btn btn-primary btn-sm" style="font-size:12px;font-weight:700">Filter</button>
        <a href="matched_receipts.php?run_id=<?= $run_id ?>" class="btn btn-ghost btn-sm" style="font-size:12px;margin-left:4px">Clear</a>
      </div>

      <div style="flex:1;min-width:200px;margin-left:auto">
        <label style="font-size:11px;font-weight:600;color:#888;display:block;margin-bottom:4px">SEARCH</label>
        <input type="text" id="pair-search" placeholder="Reference, policy, agent…" class="form-input" style="font-size:12px;width:100%">
      </div>
    </form>
  </div>
</div>

<!-- Pairs table -->
<div class="panel">
  <div class="panel-header">
    <span class="panel-title">Matched Pairs</span>
    <span class="dim" style="float:right;font-size:11px;margin-top:4px" id="visible-count"><?= $total_matched ?> shown</span>
  </div>
  <div class="table-responsive">
    <table class="data-table" id="pairs-table">
      <thead>
        <tr>
          <th style="min-width:130px">Receipt Ref</th>
          <th>Date</th>
          <th>Channel</th>
          <th style="text-align:right">Receipt Amt</th>
          <th style="min-width:130px">Policy No.</th>
          <th>Agent</th>
          <th style="text-align:right">Sale Amt</th>
          <th>Confidence</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pairs)): ?>
        <tr><td colspan="9" class="dim" style="text-align:center;padding:30px">No matched pairs found for this run period.</td></tr>
        <?php else: ?>
        <?php foreach ($pairs as $p): ?>
        <?php
            $conf_class = match($p['match_confidence']) {
                'high'   => 'success',
                'medium' => 'pending',
                'low'    => 'failed',
                'manual' => 'complete',
                default  => 'pending',
            };
            $status_class = $p['match_status'] === 'matched' ? 'success' : 'warn';
            $diff = $p['receipt_amount'] - $p['sale_amount'];
        ?>
        <tr class="pair-row"
            data-ref="<?= strtolower(htmlspecialchars($p['receipt_ref'])) ?>"
            data-policy="<?= strtolower(htmlspecialchars($p['policy_number'] ?? '')) ?>"
            data-agent="<?= strtolower(htmlspecialchars($p['agent_name'] ?? '')) ?>">
          <td class="mono" style="font-size:11px;font-weight:600"><?= htmlspecialchars($p['receipt_ref']) ?></td>
          <td class="mono dim" style="font-size:11px"><?= htmlspecialchars($p['receipt_date']) ?></td>
          <td><?= htmlspecialchars($p['channel']) ?></td>
          <td style="text-align:right;font-weight:600;color:#0066cc">
            <?= htmlspecialchars($p['receipt_currency']) ?> <?= number_format($p['receipt_amount'], 2) ?>
          </td>
          <td class="mono" style="font-size:11px;font-weight:600"><?= htmlspecialchars($p['policy_number'] ?? '—') ?></td>
          <td style="font-size:11px"><?= htmlspecialchars($p['agent_name'] ?? '—') ?></td>
          <td style="text-align:right;font-weight:600;color:#00a950">
            <?= htmlspecialchars($p['sale_currency']) ?> <?= number_format($p['sale_amount'], 2) ?>
            <?php if (abs($diff) > 0.01): ?>
            <div style="font-size:10px;color:<?= $diff > 0 ? '#c0392b' : '#0066cc' ?>;font-weight:400">
              <?= $diff > 0 ? '+' : '' ?><?= number_format($diff, 2) ?>
            </div>
            <?php endif; ?>
          </td>
          <td><span class="badge <?= $conf_class ?>"><?= strtoupper($p['match_confidence'] ?? '?') ?></span></td>
          <td><span class="badge <?= $status_class ?>"><?= strtoupper($p['match_status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Navigation -->
<div style="margin-top:24px;display:flex;gap:8px">
  <a href="reconciliation_results.php?run_id=<?= $run_id ?>" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to Results</a>
</div>

<script>
// Live search
document.getElementById('pair-search').addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    let shown = 0;
    document.querySelectorAll('.pair-row').forEach(function(row) {
        const hit = !q
            || row.dataset.ref.includes(q)
            || row.dataset.policy.includes(q)
            || row.dataset.agent.includes(q);
        row.style.display = hit ? '' : 'none';
        if (hit) shown++;
    });
    document.getElementById('visible-count').textContent = shown + ' shown';
});
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
