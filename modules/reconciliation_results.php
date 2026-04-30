<?php
// ============================================================
// modules/reconciliation_results.php
// Display detailed reconciliation results and comparisons
// ============================================================
$page_title = 'Reconciliation Results';
$active_nav = 'reconciliation';
require_once '../layouts/layout_header.php';

$db = get_db();
$run_id = (int)($_GET['run_id'] ?? 0);

if (!$run_id) {
    header('Location: reconciliation.php?error=Invalid+run+ID');
    exit;
}

// Get reconciliation run details
$run_stmt = $db->prepare("
    SELECT r.*, u.full_name
    FROM reconciliation_runs r
    JOIN users u ON r.run_by = u.id
    WHERE r.id = ?
");
$run_stmt->bind_param('i', $run_id);
$run_stmt->execute();
$run = $run_stmt->get_result()->fetch_assoc();
$run_stmt->close();

if (!$run) {
    header('Location: reconciliation.php?error=Run+not+found');
    exit;
}

// Get variance results for this run
$results_stmt = $db->prepare("
    SELECT vr.*, a.agent_name, a.agent_code
    FROM variance_results vr
    JOIN agents a ON vr.agent_id = a.id
    WHERE vr.run_id = ?
    ORDER BY a.agent_name
");
$results_stmt->bind_param('i', $run_id);
$results_stmt->execute();
$results = $results_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$results_stmt->close();

// Calculate statistics
$total_agents = count($results);
$reconciled = count(array_filter($results, function($r) { return $r['recon_status'] === 'reconciled'; }));
$variance = count(array_filter($results, function($r) { return $r['recon_status'] === 'variance'; }));
$pending = count(array_filter($results, function($r) { return $r['recon_status'] === 'pending'; }));

$total_sales_zwg = array_sum(array_column($results, 'sales_zwg'));
$total_sales_usd = array_sum(array_column($results, 'sales_usd'));
$total_receipts_zwg = array_sum(array_column($results, 'receipts_zwg'));
$total_receipts_usd = array_sum(array_column($results, 'receipts_usd'));
$total_variance_zwg = array_sum(array_column($results, 'variance_zwg'));
$total_variance_usd = array_sum(array_column($results, 'variance_usd'));

$reconciliation_rate = $total_agents > 0 ? round(($reconciled / $total_agents) * 100) : 0;
// Transaction-level match rate from the run itself (more informative than agent-level)
$txn_match_rate = ($run['match_rate'] !== null) ? number_format($run['match_rate'], 1) : $reconciliation_rate;
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:12px">
    <div>
      <h1>Reconciliation Results</h1>
      <p><?= htmlspecialchars($run['period_label']) ?> • <?= htmlspecialchars($run['product']) ?> • Run by <?= htmlspecialchars($run['full_name']) ?></p>
    </div>
    <div style="display:flex;gap:8px">
      <a href="../process/process_export.php?type=variance&run_id=<?= (int)$run['id'] ?>" target="_blank" class="btn btn-primary" style="font-weight:700"><i class="fa-solid fa-print"></i>&nbsp; Print / PDF</a>
    </div>
  </div>
</div>

<!-- KPI Stats Row -->
<div class="stat-grid">
  <div class="stat-card green">
    <div class="stat-value"><?= $reconciled ?> <span style="font-size:12px;color:#888">/ <?= $total_agents ?></span></div>
    <div class="stat-sub">Reconciled Agents</div>
  </div>
  <div class="stat-card warn">
    <div class="stat-value"><?= $variance ?></div>
    <div class="stat-sub">With Variances</div>
  </div>
  <div class="stat-card blue" style="cursor:pointer" onclick="window.location='matched_receipts.php?run_id=<?= $run_id ?>'" title="View matched pairs">
    <div class="stat-value">
      <?= $run['matched_count'] ?? '—' ?> <span style="font-size:12px;color:#888">/ <?= $run['total_receipts'] ?? '?' ?></span>
      <?php if ($run['match_rate'] !== null): ?>
      <span style="font-size:13px;font-weight:600;color:#0066cc;margin-left:6px"><?= number_format($run['match_rate'], 1) ?>%</span>
      <?php endif; ?>
    </div>
    <div class="stat-sub">Receipts Matched <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:9px;margin-left:4px"></i></div>
  </div>
  <div class="stat-card red">
    <div class="stat-value" title="ZWG <?= number_format($total_variance_zwg, 2) ?>"><?= fmt_compact($total_variance_zwg) ?></div>
    <div class="stat-sub">Total Variance ZWG</div>
  </div>
</div>

<!-- Summary Statement -->
<div class="panel">
  <div class="panel-header"><span class="panel-title">Reconciliation Summary Statement</span></div>
  <div class="panel-body">
    <table style="width:100%;border-collapse:collapse">
      <tr style="border-bottom:2px solid #e0e0e0">
        <th style="text-align:left;padding:12px;font-weight:700;color:#333333">Category</th>
        <th style="text-align:right;padding:12px;font-weight:700;color:#333333">Sales ZWG</th>
        <th style="text-align:right;padding:12px;font-weight:700;color:#333333">Sales USD</th>
        <th style="text-align:right;padding:12px;font-weight:700;color:#333333">Receipts ZWG</th>
        <th style="text-align:right;padding:12px;font-weight:700;color:#333333">Receipts USD</th>
        <th style="text-align:right;padding:12px;font-weight:700;color:#c0392b">Variance ZWG</th>
        <th style="text-align:right;padding:12px;font-weight:700;color:#c0392b">Variance USD</th>
      </tr>
      <tr style="background:#f9f9f9;border-bottom:1px solid #e0e0e0">
        <td style="padding:12px;font-weight:600">TOTAL</td>
        <td style="text-align:right;padding:12px;font-weight:600;color:#00a950"><?= number_format($total_sales_zwg, 2) ?></td>
        <td style="text-align:right;padding:12px;font-weight:600;color:#00a950">$<?= number_format($total_sales_usd, 2) ?></td>
        <td style="text-align:right;padding:12px;font-weight:600;color:#0066cc"><?= number_format($total_receipts_zwg, 2) ?></td>
        <td style="text-align:right;padding:12px;font-weight:600;color:#0066cc">$<?= number_format($total_receipts_usd, 2) ?></td>
        <td style="text-align:right;padding:12px;font-weight:600;color:#c0392b"><?= number_format($total_variance_zwg, 2) ?></td>
        <td style="text-align:right;padding:12px;font-weight:600;color:#c0392b">$<?= number_format($total_variance_usd, 2) ?></td>
      </tr>
    </table>
  </div>
</div>

<!-- Agent Breakdown -->
<div class="panel" style="margin-top:20px">
  <div class="panel-header"><span class="panel-title">Per-Agent Reconciliation Details</span></div>
  <div class="panel-body">
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Agent</th>
            <th style="text-align:right">Sales ZWG</th>
            <th style="text-align:right">Receipts ZWG</th>
            <th style="text-align:right">Variance ZWG</th>
            <th style="text-align:right">Sales USD</th>
            <th style="text-align:right">Receipts USD</th>
            <th style="text-align:right">Variance USD</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $r): ?>
          <tr>
            <td style="font-weight:500"><?= htmlspecialchars($r['agent_name']) ?> <span class="mono dim"><?= htmlspecialchars($r['agent_code']) ?></span></td>
            <td style="text-align:right;font-weight:600;color:<?= $r['sales_zwg'] > 0 ? '#00a950' : '#888' ?>"><?= number_format($r['sales_zwg'], 2) ?></td>
            <td style="text-align:right;font-weight:600;color:<?= $r['receipts_zwg'] > 0 ? '#0066cc' : '#888' ?>"><?= number_format($r['receipts_zwg'], 2) ?></td>
            <td style="text-align:right;font-weight:600;color:<?= $r['variance_zwg'] != 0 ? '#c0392b' : '#00a950' ?>"><?= number_format($r['variance_zwg'], 2) ?></td>
            <td style="text-align:right;font-weight:600;color:<?= $r['sales_usd'] > 0 ? '#00a950' : '#888' ?>">$<?= number_format($r['sales_usd'], 2) ?></td>
            <td style="text-align:right;font-weight:600;color:<?= $r['receipts_usd'] > 0 ? '#0066cc' : '#888' ?>">$<?= number_format($r['receipts_usd'], 2) ?></td>
            <td style="text-align:right;font-weight:600;color:<?= $r['variance_usd'] != 0 ? '#c0392b' : '#00a950' ?>">$<?= number_format($r['variance_usd'], 2) ?></td>
            <td><span class="badge <?= 
              $r['recon_status'] === 'reconciled' ? 'success' : 
              ($r['recon_status'] === 'variance' ? 'failed' : 'pending') 
            ?>">
              <?= strtoupper($r['recon_status']) ?>
            </span></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($results)): ?>
          <tr><td colspan="8" class="dim" style="text-align:center;padding:20px">No reconciliation data available.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Navigation -->
<div style="margin-top:24px;display:flex;gap:8px;flex-wrap:wrap">
  <a href="reconciliation.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to Reconciliation</a>
  <a href="variance.php?run_id=<?= $run_id ?>" class="btn btn-ghost"><i class="fa-solid fa-chart-column"></i> View Variance Report</a>
  <a href="matched_receipts.php?run_id=<?= $run_id ?>" class="btn btn-ghost" style="font-weight:700;color:#0066cc;border-color:#0066cc">
    <i class="fa-solid fa-link"></i> View Matched Pairs
    <?php if (!empty($run['matched_count'])): ?>
    <span style="background:#0066cc;color:#fff;border-radius:10px;padding:1px 8px;margin-left:6px;font-size:10px"><?= (int)$run['matched_count'] ?></span>
    <?php endif; ?>
  </a>
</div>

<?php require_once '../layouts/layout_footer.php'; ?>
