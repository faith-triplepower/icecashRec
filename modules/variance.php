<?php
// ============================================================
// modules/variance.php — Real DB version
// ============================================================
$page_title = 'Variance Report';
$active_nav = 'variance';
require_once '../layouts/layout_header.php';
require_role(['Manager','Reconciler']);

$db     = get_db();
$run_id = (int)($_GET['run_id'] ?? 0);
$success = htmlspecialchars($_GET['success'] ?? '');

// Latest run if none specified
if (!$run_id) {
    $latest = $db->query("SELECT id FROM reconciliation_runs ORDER BY started_at DESC LIMIT 1")->fetch_assoc();
    $run_id = $latest ? (int)$latest['id'] : 0;
}

// Run info
$run = $run_id ? $db->query("SELECT r.*, u.full_name FROM reconciliation_runs r
    JOIN users u ON r.run_by = u.id WHERE r.id = $run_id")->fetch_assoc() : null;

// Variance results
$stmt_results = $db->query("SELECT vr.*, a.agent_name FROM variance_results vr
    JOIN agents a ON vr.agent_id = a.id WHERE vr.run_id = $run_id
    ORDER BY vr.recon_status DESC, a.agent_name"
)->fetch_all(MYSQLI_ASSOC);

// Totals
$totals = $db->query("SELECT
    SUM(sales_zwg) AS ts_zwg, SUM(sales_usd) AS ts_usd,
    SUM(receipts_zwg) AS tr_zwg, SUM(receipts_usd) AS tr_usd,
    SUM(variance_zwg) AS tv_zwg, SUM(variance_usd) AS tv_usd
  FROM variance_results WHERE run_id = $run_id")->fetch_assoc();

// Unmatched receipts for the run period — credits only; debits belong
// in the Float Reconciliation section below, not the matching queue.
$unmatched = $run ? $db->query("SELECT * FROM receipts
    WHERE direction='credit' AND match_status='pending'
      AND txn_date BETWEEN '{$run['date_from']}' AND '{$run['date_to']}'
    LIMIT 50")->fetch_all(MYSQLI_ASSOC) : [];

// ── Float reconciliation: money in / money out on the bank float ──
// Credits are customer money in; debits are fees, refunds, regulator
// payments. Proper reconciliation needs both sides so the closing
// balance can be verified end-to-end.
$float_totals = $run ? $db->query("SELECT
      COALESCE(SUM(CASE WHEN direction='credit' AND currency='ZWG' THEN amount ELSE 0 END),0) AS credits_zwg,
      COALESCE(SUM(CASE WHEN direction='credit' AND currency='USD' THEN amount ELSE 0 END),0) AS credits_usd,
      COALESCE(SUM(CASE WHEN direction='debit'  AND currency='ZWG' THEN amount ELSE 0 END),0) AS debits_zwg,
      COALESCE(SUM(CASE WHEN direction='debit'  AND currency='USD' THEN amount ELSE 0 END),0) AS debits_usd,
      SUM(CASE WHEN direction='credit' THEN 1 ELSE 0 END) AS credit_cnt,
      SUM(CASE WHEN direction='debit'  THEN 1 ELSE 0 END) AS debit_cnt
    FROM receipts
    WHERE txn_date BETWEEN '{$run['date_from']}' AND '{$run['date_to']}'
  ")->fetch_assoc() : array(
    'credits_zwg'=>0,'credits_usd'=>0,'debits_zwg'=>0,'debits_usd'=>0,'credit_cnt'=>0,'debit_cnt'=>0
  );

// Per-channel breakdown of debits for the outflow review table
$float_debits_by_channel = $run ? $db->query("SELECT
      channel,
      COUNT(*) AS cnt,
      COALESCE(SUM(CASE WHEN currency='ZWG' THEN amount ELSE 0 END),0) AS zwg,
      COALESCE(SUM(CASE WHEN currency='USD' THEN amount ELSE 0 END),0) AS usd
    FROM receipts
    WHERE direction='debit' AND txn_date BETWEEN '{$run['date_from']}' AND '{$run['date_to']}'
    GROUP BY channel
    ORDER BY zwg DESC")->fetch_all(MYSQLI_ASSOC) : [];

// Top 20 largest individual debits for the review list
$float_top_debits = $run ? $db->query("SELECT reference_no, txn_date, channel, source_name, amount, currency
    FROM receipts
    WHERE direction='debit' AND txn_date BETWEEN '{$run['date_from']}' AND '{$run['date_to']}'
    ORDER BY amount DESC
    LIMIT 10")->fetch_all(MYSQLI_ASSOC) : [];

// Currency mismatches
$fx_mismatches = $run ? $db->query("SELECT s.*, a.agent_name FROM sales s
    JOIN agents a ON s.agent_id = a.id
    WHERE s.currency_flag = 1 AND s.txn_date BETWEEN '{$run['date_from']}' AND '{$run['date_to']}'"
)->fetch_all(MYSQLI_ASSOC) : [];

// Previous runs for the dropdown
$all_runs = $db->query("SELECT r.id, r.period_label, r.product, r.run_status, u.full_name
    FROM reconciliation_runs r JOIN users u ON r.run_by = u.id
    ORDER BY r.started_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

// ── Triage: split agents into "Ready to issue" vs "Needs attention" ──
// Load tolerance thresholds from system_settings
$tol_zwg = 5.0;  $tol_usd = 1.0;
$tol_row = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('amount_tolerance_zwg','amount_tolerance_usd')")->fetch_all(MYSQLI_ASSOC);
foreach ($tol_row as $tr) {
    if ($tr['setting_key'] === 'amount_tolerance_zwg') $tol_zwg = (float)$tr['setting_value'];
    if ($tr['setting_key'] === 'amount_tolerance_usd') $tol_usd = (float)$tr['setting_value'];
}

// Per-agent unmatched counts (sales without receipts, receipts without sales)
$agent_unmatched = array();
if ($run) {
    $um_sales = $db->query("
        SELECT s.agent_id, COUNT(*) cnt
        FROM sales s
        LEFT JOIN receipts rm ON rm.matched_sale_id = s.id
        WHERE s.txn_date BETWEEN '{$run['date_from']}' AND '{$run['date_to']}'
          AND rm.id IS NULL
        GROUP BY s.agent_id
    ")->fetch_all(MYSQLI_ASSOC);
    foreach ($um_sales as $u) $agent_unmatched[(int)$u['agent_id']]['sales'] = (int)$u['cnt'];

    $um_receipts = $db->query("
        SELECT sl.agent_id, COUNT(*) cnt
        FROM receipts r
        JOIN sales sl ON r.matched_sale_id = sl.id
        WHERE r.match_status = 'pending' AND r.direction = 'credit'
          AND r.txn_date BETWEEN '{$run['date_from']}' AND '{$run['date_to']}'
        GROUP BY sl.agent_id
    ")->fetch_all(MYSQLI_ASSOC);
    foreach ($um_receipts as $u) $agent_unmatched[(int)$u['agent_id']]['receipts'] = (int)$u['cnt'];
}

// Per-agent FX flag counts
$agent_fx = array();
if ($run) {
    $fx_q = $db->query("
        SELECT agent_id, COUNT(*) cnt FROM sales
        WHERE currency_flag = 1 AND txn_date BETWEEN '{$run['date_from']}' AND '{$run['date_to']}'
        GROUP BY agent_id
    ")->fetch_all(MYSQLI_ASSOC);
    foreach ($fx_q as $f) $agent_fx[(int)$f['agent_id']] = (int)$f['cnt'];
}

// Per-agent open escalation counts
$agent_esc = array();
if ($run) {
    $esc_q = $db->query("
        SELECT agent_id, COUNT(*) cnt FROM escalations
        WHERE run_id = $run_id AND status IN ('pending','reviewed')
        GROUP BY agent_id
    ")->fetch_all(MYSQLI_ASSOC);
    foreach ($esc_q as $eq) $agent_esc[(int)$eq['agent_id']] = (int)$eq['cnt'];
}

// Classify each agent
$ready_agents    = array();
$attention_agents = array();
foreach ($stmt_results as &$r) {
    $aid = (int)$r['agent_id'];
    $um_s = isset($agent_unmatched[$aid]['sales'])    ? $agent_unmatched[$aid]['sales']    : 0;
    $um_r = isset($agent_unmatched[$aid]['receipts'])  ? $agent_unmatched[$aid]['receipts']  : 0;
    $fx   = isset($agent_fx[$aid]) ? $agent_fx[$aid] : 0;
    $esc  = isset($agent_esc[$aid]) ? $agent_esc[$aid] : 0;
    $r['_um_sales']    = $um_s;
    $r['_um_receipts'] = $um_r;
    $r['_fx']          = $fx;
    $r['_esc']         = $esc;

    $within_tol = (abs($r['variance_zwg']) <= $tol_zwg && abs($r['variance_usd']) <= $tol_usd);
    $no_issues  = ($um_s == 0 && $um_r == 0 && $fx == 0 && $esc == 0);
    $match_pct  = ($r['sales_zwg'] + $r['sales_usd']) > 0
        ? round(($r['receipts_zwg'] + $r['receipts_usd']) / ($r['sales_zwg'] + $r['sales_usd']) * 100, 1)
        : 100;
    $r['_match_pct'] = $match_pct;

    // Determine reason for attention
    $reasons = array();
    if (!$within_tol)  $reasons[] = 'Variance over tolerance';
    if ($fx > 0)       $reasons[] = 'FX mismatch';
    if ($um_s + $um_r > 0) $reasons[] = 'Unmatched items';
    if ($esc > 0)      $reasons[] = 'Open escalation';
    if ($match_pct < 95 && empty($reasons)) $reasons[] = 'Low match rate';
    $r['_attention_reason'] = implode(' + ', $reasons);

    if ($within_tol && $no_issues && $match_pct >= 95) {
        $ready_agents[] = $r;
    } else {
        $attention_agents[] = $r;
    }
}
unset($r);
?>

<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1>Variance Report</h1>
      <p><?= $run ? htmlspecialchars($run['period_label'] . ' — ' . $run['product']) : 'No reconciliation run selected' ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <?php if (count($all_runs) > 1): ?>
      <select class="form-select" style="width:auto" onchange="location='variance.php?run_id='+this.value">
        <?php foreach ($all_runs as $ar): ?>
        <option value="<?= $ar['id'] ?>" <?= $ar['id']===$run_id?'selected':'' ?>>
          <?= htmlspecialchars($ar['period_label'] . ' / ' . $ar['product']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <?php if ($run_id): ?>
      <a href="../process/process_export_csv.php?type=variance&run_id=<?= $run_id ?>" class="btn btn-ghost" style="font-weight:600"><i class="fa-solid fa-download"></i> CSV</a>
      <a href="../process/process_export.php?type=variance&run_id=<?= $run_id ?>" target="_blank" class="btn btn-primary" style="font-weight:700"><i class="fa-solid fa-print"></i>&nbsp; Print / PDF</a>
      <?php endif; ?>
      <a href="reconciliation.php" class="btn btn-primary">⇄ New Run</a>
    </div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success">✓ <?= $success ?></div><?php endif; ?>

<?php if (!$run_id): ?>
<div class="alert alert-warn">⚠ No reconciliation runs found. Run a reconciliation first.</div>
<?php else: ?>

<div class="stat-grid">
  <div class="stat-card red">
    <div class="stat-label">Total Variance (ZWG)</div>
    <div class="stat-value" title="ZWG <?= number_format($totals['tv_zwg'] ?? 0, 2) ?>" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><span class="stat-currency">ZWG</span><?= fmt_compact($totals['tv_zwg'] ?? 0) ?></div>
    <div class="stat-sub"><span class="<?= ($totals['tv_zwg']??0) < 0 ? 'down' : 'up' ?>"><?= ($totals['tv_zwg']??0) < 0 ? 'Short collection' : 'Over collection' ?></span></div>
  </div>
  <div class="stat-card warn">
    <div class="stat-label">Unmatched Items</div>
    <div class="stat-value"><?= count($unmatched) ?></div>
    <div class="stat-sub">Require manual review</div>
  </div>
  <div class="stat-card warn">
    <div class="stat-label">Currency Mismatches</div>
    <div class="stat-value"><?= count($fx_mismatches) ?></div>
    <div class="stat-sub">ZWG issued → USD paid</div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">Agents with Variance</div>
    <?php $with_var = count(array_filter($stmt_results, function($r) { return $r['recon_status']==='variance'; })); ?>
    <div class="stat-value"><?= $with_var ?> <span style="font-size:13px;color:#888">/ <?= count($stmt_results) ?></span></div>
    <div class="stat-sub">Agents reconciled: <?= count(array_filter($stmt_results, function($r) { return $r['recon_status']==='reconciled'; })) ?></div>
  </div>
</div>

<div class="tab-bar">
  <div class="tab-item active" onclick="switchTab(this,'t-summary')">Summary Statement</div>
  <div class="tab-item" onclick="switchTab(this,'t-unmatched')">Unmatched (<?= count($unmatched) ?>)</div>
  <div class="tab-item" onclick="switchTab(this,'t-currency')">Currency Mismatches (<?= count($fx_mismatches) ?>)</div>
  <div class="tab-item" onclick="switchTab(this,'t-float')">Float Reconciliation (<?= (int)$float_totals['debit_cnt'] ?>)</div>
</div>

<!-- Summary with triage sub-tabs -->
<div id="t-summary">
  <div class="tab-bar" style="margin-bottom:0;border-bottom:none">
    <div class="tab-item active" onclick="switchSubTab(this,'st-ready')">Ready to issue (<?= count($ready_agents) ?>)</div>
    <div class="tab-item" onclick="switchSubTab(this,'st-attention')">Needs attention (<?= count($attention_agents) ?>)</div>
    <div class="tab-item" onclick="switchSubTab(this,'st-all')">All agents (<?= count($stmt_results) ?>)</div>
  </div>

  <!-- ── Ready to issue ── -->
  <div id="st-ready" class="sub-tab-panel">
    <div class="panel" style="border-top-left-radius:0">
      <div class="panel-header" style="background:#f0faf4;border-left:3px solid var(--green,#00a950)">
        <span class="panel-title" style="color:#00a950">Clean reconciliations — variance within tolerance.<?php if ($user['role'] !== 'Manager'): ?> Review and forward to Manager for issuing.<?php endif; ?></span>
      </div>
      <?php if (empty($ready_agents)): ?>
      <div style="text-align:center;padding:30px;color:#888">No agents are fully reconciled within tolerance yet.</div>
      <?php else: ?>
      <table class="data-table" id="ready-table">
        <thead><tr><th>Agent</th><th>Sales ZWG</th><th>Receipts ZWG</th><th>Variance</th><th>Match</th><th>Action</th></tr></thead>
        <tbody>
          <?php $ri = 0; foreach ($ready_agents as $r): $ri++; ?>
          <tr class="variance-row ready-row" style="cursor:pointer;<?= $ri > 20 ? 'display:none' : '' ?>" data-agent-id="<?= (int)$r['agent_id'] ?>" data-agent-name="<?= htmlspecialchars($r['agent_name']) ?>" onclick="toggleVarianceDetail(this)">
            <td style="font-weight:500"><?= htmlspecialchars($r['agent_name']) ?></td>
            <td class="mono"><?= number_format($r['sales_zwg'], 2) ?></td>
            <td class="mono"><?= number_format($r['receipts_zwg'], 2) ?></td>
            <td class="mono" style="color:#00a950"><?= number_format($r['variance_zwg'], 2) ?></td>
            <td><span class="badge reconciled" style="font-weight:700"><?= $r['_match_pct'] ?>%</span></td>
            <td onclick="event.stopPropagation()">
              <form method="POST" action="../process/process_statements.php" style="display:inline">
      <?= csrf_field() ?>
                <input type="hidden" name="action" value="issue">
                <input type="hidden" name="run_id" value="<?= $run_id ?>">
                <input type="hidden" name="agent_id" value="<?= (int)$r['agent_id'] ?>">
                <input type="hidden" name="notes" value="Clean reconciliation — auto-ready">
                <button type="submit" class="btn btn-ghost btn-sm" style="color:#00a950;font-weight:600">Draft statement</button>
              </form>
            </td>
          </tr>
          <tr class="variance-detail-row" style="display:none"><td colspan="6" style="padding:0;background:#fafbfc"><div class="variance-detail-body" style="padding:20px"><em class="dim">Loading…</em></div></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($ready_agents) > 20): ?>
      <div id="ready-showmore" style="text-align:center;padding:10px;border-top:1px solid #eee">
        <button class="btn btn-ghost" style="font-weight:600" onclick="document.querySelectorAll('.ready-row').forEach(function(r){r.style.display=''});this.parentNode.style.display='none'">Show all <?= count($ready_agents) ?> agents ▾</button>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Needs attention ── -->
  <div id="st-attention" class="sub-tab-panel" style="display:none">
    <div class="panel" style="border-top-left-radius:0">
      <div class="panel-header" style="background:#fdf4f0;border-left:3px solid #c0392b">
        <span class="panel-title" style="color:#8a0000">Variance over tolerance or unresolved items. Investigate before issuing.</span>
      </div>
      <?php if (empty($attention_agents)): ?>
      <div style="text-align:center;padding:30px;color:#888">All agents are within tolerance — nothing to investigate.</div>
      <?php else: ?>
      <table class="data-table" id="attention-table">
        <thead><tr><th>Agent</th><th>Variance</th><th>Unmatched</th><th>Reason</th><th>Action</th></tr></thead>
        <tbody>
          <?php $ai = 0; foreach ($attention_agents as $r): $ai++; ?>
          <tr class="variance-row attn-row" style="cursor:pointer;<?= $ai > 20 ? 'display:none' : '' ?>" data-agent-id="<?= (int)$r['agent_id'] ?>" data-agent-name="<?= htmlspecialchars($r['agent_name']) ?>" onclick="toggleVarianceDetail(this)">
            <td style="font-weight:500"><?= htmlspecialchars($r['agent_name']) ?></td>
            <td class="mono variance-neg" style="font-weight:600"><?= number_format($r['variance_zwg'], 2) ?></td>
            <td class="mono"><?= $r['_um_sales'] ?> / <?= $r['_um_receipts'] ?></td>
            <td style="font-size:11px;color:#8a0000"><?= htmlspecialchars($r['_attention_reason']) ?></td>
            <td onclick="event.stopPropagation()">
              <button class="btn btn-ghost btn-sm" onclick="toggleVarianceDetail(this.closest('tr'))">Investigate</button>
            </td>
          </tr>
          <tr class="variance-detail-row" style="display:none"><td colspan="5" style="padding:0;background:#fafbfc"><div class="variance-detail-body" style="padding:20px"><em class="dim">Loading…</em></div></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($attention_agents) > 20): ?>
      <div id="attn-showmore" style="text-align:center;padding:10px;border-top:1px solid #eee">
        <button class="btn btn-ghost" style="font-weight:600" onclick="document.querySelectorAll('.attn-row').forEach(function(r){r.style.display=''});this.parentNode.style.display='none'">Show all <?= count($attention_agents) ?> agents ▾</button>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── All agents (with search + show more) ── -->
  <div id="st-all" class="sub-tab-panel" style="display:none">
    <div class="panel" style="border-top-left-radius:0">
      <div class="panel-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div>
          <span class="panel-title">Reconciliation Statement — Per Agent</span>
          <span class="dim" style="font-size:11px;margin-left:10px">Click any row to drill into details and escalate</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="text" id="agent-search" class="form-input" style="padding:5px 10px;width:200px;font-size:12px" placeholder="Search agent..." oninput="filterAllAgents()">
          <span id="agent-count" class="dim" style="font-size:11px;white-space:nowrap"><?= count($stmt_results) ?> agents</span>
        </div>
      </div>
      <table class="data-table" id="all-agents-table" style="font-size:12px">
        <thead><tr><th style="width:22px"></th><th>Agent</th><th style="text-align:right">Sales</th><th style="text-align:right">Receipts</th><th style="text-align:right">Variance</th><th>Category</th><th>Status</th></tr></thead>
        <tbody>
          <?php $idx = 0; foreach ($stmt_results as $r): $idx++; ?>
          <tr class="variance-row agent-row" style="cursor:pointer;<?= $idx > 20 ? 'display:none' : '' ?>" data-agent-id="<?= (int)$r['agent_id'] ?>" data-agent-name="<?= htmlspecialchars($r['agent_name']) ?>" onclick="toggleVarianceDetail(this)">
            <td class="expand-toggle" style="text-align:center;color:#888">▸</td>
            <td style="font-weight:500;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['agent_name']) ?></td>
            <td class="mono" style="text-align:right"><?= number_format($r['sales_zwg']) ?><?php if($r['sales_usd'] > 0): ?><br><span class="dim" style="font-size:10px">$<?= number_format($r['sales_usd']) ?></span><?php endif; ?></td>
            <td class="mono" style="text-align:right"><?= number_format($r['receipts_zwg']) ?><?php if($r['receipts_usd'] > 0): ?><br><span class="dim" style="font-size:10px">$<?= number_format($r['receipts_usd']) ?></span><?php endif; ?></td>
            <td style="text-align:right;font-weight:600" class="<?= $r['variance_zwg'] < 0 ? 'variance-neg' : ($r['variance_zwg'] == 0 ? 'variance-pos' : 'dim') ?>"><?= number_format($r['variance_zwg']) ?><?php if($r['variance_usd'] != 0): ?><br><span class="dim" style="font-size:10px;font-weight:400">$<?= number_format($r['variance_usd']) ?></span><?php endif; ?></td>
            <td style="font-size:11px;color:#888"><?= htmlspecialchars($r['variance_cat'] ?? '—') ?></td>
            <td><span class="badge <?= $r['recon_status'] ?>"><?= ucfirst($r['recon_status']) ?></span></td>
          </tr>
          <tr class="variance-detail-row" style="display:none"><td colspan="7" style="padding:0;background:#fafbfc"><div class="variance-detail-body" style="padding:20px"><em class="dim">Loading…</em></div></td></tr>
          <?php endforeach; ?>
          <?php if ($totals): ?>
          <tr class="agent-total-row" style="background:#f5f8ff">
            <td></td>
            <td style="font-weight:700">TOTAL</td>
            <td class="mono" style="font-weight:600;text-align:right"><?= number_format($totals['ts_zwg']) ?><?php if($totals['ts_usd'] > 0): ?><br><span class="dim" style="font-size:10px">$<?= number_format($totals['ts_usd']) ?></span><?php endif; ?></td>
            <td class="mono" style="font-weight:600;text-align:right"><?= number_format($totals['tr_zwg']) ?><?php if($totals['tr_usd'] > 0): ?><br><span class="dim" style="font-size:10px">$<?= number_format($totals['tr_usd']) ?></span><?php endif; ?></td>
            <td class="<?= $totals['tv_zwg'] < 0 ? 'variance-neg' : 'variance-pos' ?>" style="font-weight:700;text-align:right"><?= number_format($totals['tv_zwg']) ?><?php if($totals['tv_usd'] != 0): ?><br><span class="dim" style="font-size:10px">$<?= number_format($totals['tv_usd']) ?></span><?php endif; ?></td>
            <td></td><td></td>
          </tr>
          <?php endif; ?>
          <?php if (empty($stmt_results)): ?>
          <tr><td colspan="7" class="dim" style="text-align:center;padding:20px">No results for this run.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <?php if (count($stmt_results) > 20): ?>
      <div id="show-all-bar" style="text-align:center;padding:12px;border-top:1px solid #eee">
        <button class="btn btn-ghost" onclick="showAllAgents()" id="show-all-btn" style="font-weight:600">
          Show all <?= count($stmt_results) ?> agents ▾
        </button>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Unmatched -->
<div id="t-unmatched" style="display:none">
  <?php if (!empty($unmatched)): ?>
  <div class="alert alert-warn">⚠ <?= count($unmatched) ?> transactions could not be automatically matched. Review and assign manually.</div>
  <?php endif; ?>
  <div class="panel">
    <div class="panel-header"><span class="panel-title">Unmatched Receipts</span></div>
    <table class="data-table paginated-table">
      <thead><tr><th>Ref #</th><th>Date</th><th>Channel</th><th>Amount</th><th>Currency</th><th>Source</th></tr></thead>
      <tbody>
        <?php foreach ($unmatched as $u): ?>
        <tr>
          <td class="mono" style="color:var(--accent2)"><?= htmlspecialchars($u['reference_no']) ?></td>
          <td class="mono dim"><?= $u['txn_date'] ?></td>
          <td><?= $u['channel'] ?></td>
          <td class="mono"><?= number_format($u['amount']) ?></td>
          <td><span class="badge <?= $u['currency']==='USD'?'variance':'matched' ?>"><?= $u['currency'] ?></span></td>
          <td class="dim"><?= htmlspecialchars($u['source_name']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($unmatched)): ?>
        <tr><td colspan="6" class="dim" style="text-align:center;padding:20px">✓ All receipts matched.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Currency Mismatches -->
<div id="t-currency" style="display:none">
  <?php if (!empty($fx_mismatches)): ?>
  <div class="alert alert-danger">⚠ <?= count($fx_mismatches) ?> policies issued in ZWG but payment received in USD.</div>
  <?php endif; ?>
  <div class="panel">
    <div class="panel-header"><span class="panel-title">ZWG Policies Paid in USD</span></div>
    <table class="data-table paginated-table">
      <thead><tr><th>Policy #</th><th>Agent</th><th>Product</th><th>Issue Currency</th><th>Amount (ZWG)</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($fx_mismatches as $f): ?>
        <tr>
          <td class="mono" style="color:var(--accent2)"><?= htmlspecialchars($f['policy_number']) ?></td>
          <td><?= htmlspecialchars($f['agent_name']) ?></td>
          <td class="dim"><?= $f['product'] ?></td>
          <td><span class="badge reconciled">ZWG</span> → <span class="badge variance">USD</span></td>
          <td class="mono"><?= number_format($f['amount']) ?></td>
          <td class="mono dim"><?= $f['txn_date'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($fx_mismatches)): ?>
        <tr><td colspan="6" class="dim" style="text-align:center;padding:20px">✓ No currency mismatches.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Float Reconciliation -->
<div id="t-float" style="display:none">
  <?php
    $net_zwg = (float)$float_totals['credits_zwg'] - (float)$float_totals['debits_zwg'];
    $net_usd = (float)$float_totals['credits_usd'] - (float)$float_totals['debits_usd'];
  ?>
  <div class="alert" style="background:#fff4d6;border-left:3px solid #8a5a00;color:#5a3a00">
    <strong>Float Reconciliation</strong> — money in vs money out on the bank/float account for this period.
    Credits are customer receipts (matched against sales above); debits are fees, refunds, and regulator payments
    that reduce the float balance but don't correspond to any sale. The net movement should tie to the bank statement's
    closing balance minus opening balance.
  </div>

  <div class="stat-grid">
    <div class="stat-card green">
      <div class="stat-label">Credits In — ZWG</div>
      <div class="stat-value"><?= number_format($float_totals['credits_zwg']) ?></div>
      <div class="stat-sub"><?= (int)$float_totals['credit_cnt'] ?> receipt rows</div>
    </div>
    <div class="stat-card green">
      <div class="stat-label">Credits In — USD</div>
      <div class="stat-value"><?= number_format($float_totals['credits_usd']) ?></div>
      <div class="stat-sub">Customer inflow</div>
    </div>
    <div class="stat-card" style="border-left:4px solid #8a5a00">
      <div class="stat-label">Debits Out — ZWG</div>
      <div class="stat-value"><?= number_format($float_totals['debits_zwg']) ?></div>
      <div class="stat-sub"><?= (int)$float_totals['debit_cnt'] ?> outflow rows</div>
    </div>
    <div class="stat-card" style="border-left:4px solid #8a5a00">
      <div class="stat-label">Debits Out — USD</div>
      <div class="stat-value"><?= number_format($float_totals['debits_usd']) ?></div>
      <div class="stat-sub">Fees · refunds · payouts</div>
    </div>
    <div class="stat-card <?= $net_zwg >= 0 ? 'blue' : 'red' ?>">
      <div class="stat-label">Net Movement — ZWG</div>
      <div class="stat-value"><?= number_format($net_zwg) ?></div>
      <div class="stat-sub"><?= $net_zwg >= 0 ? 'Float grew' : 'Float shrank' ?></div>
    </div>
    <div class="stat-card <?= $net_usd >= 0 ? 'blue' : 'red' ?>">
      <div class="stat-label">Net Movement — USD</div>
      <div class="stat-value"><?= number_format($net_usd) ?></div>
      <div class="stat-sub">Credits − Debits</div>
    </div>
  </div>

  <div class="panel" style="margin-top:16px">
    <div class="panel-header"><span class="panel-title">Outflows by Channel</span></div>
    <table class="data-table">
      <thead><tr><th>Channel</th><th style="text-align:right">Count</th><th style="text-align:right">ZWG</th><th style="text-align:right">USD</th></tr></thead>
      <tbody>
        <?php $tot_cnt=0; $tot_zwg=0; $tot_usd=0; foreach ($float_debits_by_channel as $d):
          $tot_cnt += (int)$d['cnt']; $tot_zwg += (float)$d['zwg']; $tot_usd += (float)$d['usd']; ?>
        <tr>
          <td><?= htmlspecialchars($d['channel']) ?></td>
          <td class="mono" style="text-align:right"><?= (int)$d['cnt'] ?></td>
          <td class="mono" style="text-align:right"><?= number_format($d['zwg'], 2) ?></td>
          <td class="mono" style="text-align:right"><?= number_format($d['usd'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($float_debits_by_channel)): ?>
        <tr><td colspan="4" class="dim" style="text-align:center;padding:20px">No outflows recorded for this period.</td></tr>
        <?php else: ?>
        <tr style="background:#f5f8ff">
          <td style="font-weight:700">TOTAL</td>
          <td class="mono" style="text-align:right;font-weight:700"><?= $tot_cnt ?></td>
          <td class="mono" style="text-align:right;font-weight:700"><?= number_format($tot_zwg, 2) ?></td>
          <td class="mono" style="text-align:right;font-weight:700"><?= number_format($tot_usd, 2) ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="panel" style="margin-top:16px">
    <div class="panel-header">
      <span class="panel-title">Top Outflow Transactions</span>
      <a href="../admin/unmatched.php?tab=debits&date_from=<?= $run['date_from'] ?>&date_to=<?= $run['date_to'] ?>" class="btn btn-ghost btn-sm" style="float:right;margin-top:-4px">View all &rarr;</a>
    </div>
    <table class="data-table paginated-table">
      <thead><tr><th>Reference</th><th>Date</th><th>Channel</th><th>Source</th><th style="text-align:right">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($float_top_debits as $d): ?>
        <tr>
          <td class="mono" style="color:var(--accent2);font-size:11px"><?= htmlspecialchars($d['reference_no']) ?></td>
          <td class="mono dim" style="font-size:11px"><?= $d['txn_date'] ?></td>
          <td><?= htmlspecialchars($d['channel']) ?></td>
          <td class="dim" style="font-size:11px"><?= htmlspecialchars($d['source_name']) ?></td>
          <td class="mono" style="text-align:right"><?= htmlspecialchars($d['currency']) ?> <?= number_format($d['amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($float_top_debits)): ?>
        <tr><td colspan="5" class="dim" style="text-align:center;padding:20px">No outflows to review.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Escalation Modal -->
<div id="escalate-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;width:520px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,0.3)">
    <div style="padding:16px 20px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center">
      <strong style="color:var(--green-dark,#007a3d)">Escalate to Manager</strong>
      <span style="cursor:pointer;font-size:22px;color:#888" onclick="closeEscalateModal()">&times;</span>
    </div>
    <div style="padding:20px">
      <p style="margin:0 0 12px;color:#555">Escalating variance for <strong id="esc-agent-name"></strong>. The escalation will be automatically assigned to an active Manager.</p>
      <label style="display:block;font-size:12px;color:#666;margin-bottom:4px">Priority</label>
      <select id="esc-priority" class="form-select" style="margin-bottom:12px">
        <option value="low">Low</option>
        <option value="medium" selected>Medium</option>
        <option value="high">High</option>
        <option value="critical">Critical</option>
      </select>
      <label style="display:block;font-size:12px;color:#666;margin-bottom:4px">Note (required)</label>
      <textarea id="esc-note" class="form-control" rows="4" style="width:100%;resize:vertical" placeholder="Explain why this needs manager review — what you've checked, what you suspect, suggested action"></textarea>
      <div id="esc-error" style="color:#c0392b;font-size:12px;margin-top:8px;display:none"></div>
    </div>
    <div style="padding:14px 20px;border-top:1px solid #eee;display:flex;justify-content:flex-end;gap:8px">
      <button class="btn btn-ghost" onclick="closeEscalateModal()">Cancel</button>
      <button id="esc-submit" class="btn btn-primary" onclick="submitEscalation()">Submit Escalation</button>
    </div>
  </div>
</div>

<style>
.variance-row:hover { background:#f5fbf7 }
.variance-row.expanded { background:#eaf7ef }
.variance-row.expanded .expand-toggle { transform:rotate(90deg); display:inline-block }
.detail-section { margin-bottom:18px }
.detail-section h4 { margin:0 0 8px; font-size:13px; color:var(--green-dark,#007a3d); text-transform:uppercase; letter-spacing:0.5px }
.detail-mini-table { width:100%; font-size:12px; border-collapse:collapse }
.detail-mini-table th { text-align:left; padding:6px 8px; background:#f0f4f1; color:#555; font-weight:600; border-bottom:1px solid #ddd }
.detail-mini-table td { padding:6px 8px; border-bottom:1px solid #eee; font-family:monospace }
.detail-mini-table tr:last-child td { border-bottom:none }
.detail-empty { color:#999; font-style:italic; padding:10px 0 }
.detail-actions { display:flex; gap:10px; justify-content:flex-end; padding-top:10px; border-top:1px solid #eee }
.confidence-badge { padding:2px 6px; border-radius:3px; font-size:10px; text-transform:uppercase }
.confidence-low { background:#ffe0e0; color:#b00 }
.confidence-medium { background:#fff4d6; color:#8a5a00 }
.escalation-banner { padding:10px 14px; border-radius:4px; background:#fff4d6; border-left:3px solid #d49a00; margin-bottom:12px; font-size:12px }
.escalation-banner.resolved { background:#e4f6ea; border-left-color:#00a950 }
</style>

<?php endif; // end if run_id ?>

<script>
const RUN_ID = <?= (int)$run_id ?>;
const DETAIL_URL = '../process/process_variance_detail.php';
const detailCache = {};

// Auto-paginate tables with class "paginated-table" — show 10 rows, add Show All button
document.querySelectorAll('.paginated-table').forEach(function(table) {
    var rows = table.querySelectorAll('tbody tr');
    if (rows.length <= 10) return;
    for (var i = 10; i < rows.length; i++) rows[i].style.display = 'none';
    var bar = document.createElement('div');
    bar.style.cssText = 'text-align:center;padding:10px;border-top:1px solid #eee';
    bar.innerHTML = '<button class="btn btn-ghost" style="font-weight:600" onclick="this.parentNode.previousElementSibling.querySelectorAll(\'tbody tr\').forEach(function(r){r.style.display=\'\'});this.parentNode.style.display=\'none\'">Show all ' + rows.length + ' rows ▾</button>';
    table.parentNode.insertBefore(bar, table.nextSibling);
});

function switchTab(el, id) {
  el.closest('.tab-bar').querySelectorAll('.tab-item').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  ['t-summary','t-unmatched','t-currency','t-float'].forEach(t => {
    const node = document.getElementById(t);
    if (node) node.style.display = t===id ? 'block' : 'none';
  });
}
function switchSubTab(el, id) {
  el.closest('.tab-bar').querySelectorAll('.tab-item').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.sub-tab-panel').forEach(p => p.style.display = 'none');
  document.getElementById(id).style.display = 'block';
}

var allAgentsShown = false;
function showAllAgents() {
  document.querySelectorAll('#all-agents-table .agent-row').forEach(function(r) { r.style.display = ''; });
  allAgentsShown = true;
  var bar = document.getElementById('show-all-bar');
  if (bar) bar.style.display = 'none';
}
function filterAllAgents() {
  var q = document.getElementById('agent-search').value.toLowerCase();
  var rows = document.querySelectorAll('#all-agents-table .agent-row');
  var visible = 0;
  var shown = 0;
  rows.forEach(function(r, i) {
    var name = (r.dataset.agentName || '').toLowerCase();
    var match = !q || name.indexOf(q) !== -1;
    // When searching, show all matches (ignore the 20 limit).
    // When not searching, respect the 20 limit unless "show all" was clicked.
    if (q) {
      r.style.display = match ? '' : 'none';
      // Also hide the detail row
      var det = r.nextElementSibling;
      if (det && det.classList.contains('variance-detail-row') && !match) det.style.display = 'none';
    } else {
      if (allAgentsShown) {
        r.style.display = '';
      } else {
        r.style.display = shown < 20 ? '' : 'none';
      }
    }
    if (match) visible++;
    if (r.style.display !== 'none') shown++;
  });
  document.getElementById('agent-count').textContent = (q ? visible + ' found' : visible + ' agents');
  var bar = document.getElementById('show-all-bar');
  if (bar) bar.style.display = (q || allAgentsShown) ? 'none' : '';
}

function toggleVarianceDetail(row) {
  const next = row.nextElementSibling;
  if (!next || !next.classList.contains('variance-detail-row')) return;
  const agentId = row.dataset.agentId;
  const isOpen = next.style.display !== 'none';

  var toggle = row.querySelector('.expand-toggle');
  if (isOpen) {
    next.style.display = 'none';
    row.classList.remove('expanded');
    if (toggle) toggle.textContent = '▸';
    return;
  }

  next.style.display = '';
  row.classList.add('expanded');
  if (toggle) toggle.textContent = '▾';

  const body = next.querySelector('.variance-detail-body');
  if (detailCache[agentId]) {
    renderDetail(body, detailCache[agentId], row.dataset.agentName, agentId);
    return;
  }
  body.innerHTML = '<em class="dim">Loading…</em>';
  fetch(DETAIL_URL + '?action=detail&run_id=' + RUN_ID + '&agent_id=' + agentId)
    .then(r => r.json())
    .then(data => {
      if (data.error) { body.innerHTML = '<div style="color:#c0392b">⚠ ' + data.error + '</div>'; return; }
      detailCache[agentId] = data;
      renderDetail(body, data, row.dataset.agentName, agentId);
    })
    .catch(err => { body.innerHTML = '<div style="color:#c0392b">⚠ Failed to load: ' + err + '</div>'; });
}

function fmt(n) {
  n = parseFloat(n || 0);
  return n.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
}
function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function renderDetail(container, data, agentName, agentId) {
  let html = '';

  // Existing escalation banner
  if (data.existing_escalation) {
    const e = data.existing_escalation;
    const cls = (e.status === 'resolved' || e.status === 'reviewed') ? 'escalation-banner resolved' : 'escalation-banner';
    html += '<div class="' + cls + '">'
         + '<strong>Escalation #' + e.id + '</strong> — status: <strong>' + esc(e.status) + '</strong>'
         + ' · priority: ' + esc(e.priority)
         + ' · created: ' + esc(e.created_at)
         + (e.review_note ? '<br><em>Manager note: ' + esc(e.review_note) + '</em>' : '')
         + '</div>';
  }

  // Per-channel breakdown
  html += '<div class="detail-section"><h4>Per-Channel Breakdown</h4>';
  if (data.channels && data.channels.length) {
    html += '<table class="detail-mini-table"><thead><tr>'
         + '<th>Channel</th><th style="text-align:right">Sales ZWG</th><th style="text-align:right">Receipts ZWG</th>'
         + '<th style="text-align:right">Var ZWG</th><th style="text-align:right">Var USD</th>'
         + '</tr></thead><tbody>';
    data.channels.forEach(c => {
      const vzwg = parseFloat(c.variance_zwg);
      const color = Math.abs(vzwg) > 0.01 ? (vzwg < 0 ? '#c0392b' : '#d49a00') : '#2c7a4b';
      html += '<tr>'
           + '<td>' + esc(c.channel) + '</td>'
           + '<td style="text-align:right">' + fmt(c.sales_zwg) + '</td>'
           + '<td style="text-align:right">' + fmt(c.receipts_zwg) + '</td>'
           + '<td style="text-align:right;color:' + color + ';font-weight:600">' + fmt(c.variance_zwg) + '</td>'
           + '<td style="text-align:right;color:' + color + '">' + fmt(c.variance_usd) + '</td>'
           + '</tr>';
    });
    html += '</tbody></table>';
  } else {
    html += '<div class="detail-empty">No channel breakdown available for this run.</div>';
  }
  html += '</div>';

  // Unmatched sales
  html += '<div class="detail-section"><h4>Unmatched Sales (' + (data.unmatched_sales || []).length + ')</h4>';
  if (data.unmatched_sales && data.unmatched_sales.length) {
    html += '<table class="detail-mini-table"><thead><tr><th>Policy #</th><th>Date</th><th>Method</th><th>Terminal</th><th style="text-align:right">Amount</th></tr></thead><tbody>';
    data.unmatched_sales.forEach(s => {
      html += '<tr><td>' + esc(s.policy_number) + '</td><td>' + esc(s.txn_date) + '</td><td>' + esc(s.payment_method) + '</td><td>' + esc(s.terminal_id || '—') + '</td><td style="text-align:right">' + esc(s.currency) + ' ' + fmt(s.amount) + '</td></tr>';
    });
    html += '</tbody></table>';
  } else {
    html += '<div class="detail-empty">All sales for this agent are matched.</div>';
  }
  html += '</div>';

  // Unmatched receipts (possible match candidates)
  html += '<div class="detail-section"><h4>Possible Unmatched Receipts (' + (data.unmatched_receipts || []).length + ')</h4>';
  if (data.unmatched_receipts && data.unmatched_receipts.length) {
    html += '<table class="detail-mini-table"><thead><tr><th>Ref #</th><th>Date</th><th>Channel</th><th>Source</th><th style="text-align:right">Amount</th></tr></thead><tbody>';
    data.unmatched_receipts.forEach(r => {
      html += '<tr><td>' + esc(r.reference_no) + '</td><td>' + esc(r.txn_date) + '</td><td>' + esc(r.channel) + '</td><td>' + esc(r.source_name) + '</td><td style="text-align:right">' + esc(r.currency) + ' ' + fmt(r.amount) + '</td></tr>';
    });
    html += '</tbody></table>';
  } else {
    html += '<div class="detail-empty">No unmatched receipts look like they belong to this agent.</div>';
  }
  html += '</div>';

  // Low-confidence matches
  if (data.low_conf_matches && data.low_conf_matches.length) {
    html += '<div class="detail-section"><h4>Low / Medium Confidence Matches — Review</h4>'
         + '<table class="detail-mini-table"><thead><tr><th>Confidence</th><th>Receipt Ref</th><th>Sale Policy</th><th>Date (R / S)</th><th style="text-align:right">Amount (R / S)</th></tr></thead><tbody>';
    data.low_conf_matches.forEach(m => {
      const badge = '<span class="confidence-badge confidence-' + m.match_confidence + '">' + m.match_confidence + '</span>';
      html += '<tr><td>' + badge + '</td><td>' + esc(m.reference_no) + '</td><td>' + esc(m.policy_number) + '</td>'
           + '<td>' + esc(m.txn_date) + ' / ' + esc(m.sale_date) + '</td>'
           + '<td style="text-align:right">' + fmt(m.amount) + ' / ' + fmt(m.sale_amount) + '</td></tr>';
    });
    html += '</tbody></table></div>';
  }

  // FX mismatches
  if (data.fx_mismatches && data.fx_mismatches.length) {
    html += '<div class="detail-section"><h4>Currency Flags (' + data.fx_mismatches.length + ')</h4>'
         + '<table class="detail-mini-table"><thead><tr><th>Policy #</th><th>Date</th><th>Sale Currency</th><th style="text-align:right">Amount</th></tr></thead><tbody>';
    data.fx_mismatches.forEach(f => {
      html += '<tr><td>' + esc(f.policy_number) + '</td><td>' + esc(f.txn_date) + '</td><td>' + esc(f.currency) + '</td><td style="text-align:right">' + fmt(f.amount) + '</td></tr>';
    });
    html += '</tbody></table></div>';
  }

  // Action buttons
  const hasOpenEsc = data.existing_escalation && data.existing_escalation.status === 'pending';
  html += '<div class="detail-actions">';
  if (hasOpenEsc) {
    html += '<span class="dim" style="align-self:center;font-size:12px">⚠ Already escalated — pending manager review</span>';
  } else {
    html += '<button class="btn btn-primary" onclick="openEscalateModal(' + agentId + ', \'' + esc(agentName).replace(/'/g, "\\'") + '\')">⚑ Escalate to Manager</button>';
  }
  html += '</div>';

  container.innerHTML = html;
}

// ── Escalation Modal ────────────────────────────────────────
let activeEscAgentId = null;

function openEscalateModal(agentId, agentName) {
  activeEscAgentId = agentId;
  document.getElementById('esc-agent-name').textContent = agentName;
  document.getElementById('esc-note').value = '';
  document.getElementById('esc-priority').value = 'medium';
  document.getElementById('esc-error').style.display = 'none';
  document.getElementById('escalate-modal').style.display = 'flex';
}
function closeEscalateModal() {
  document.getElementById('escalate-modal').style.display = 'none';
  activeEscAgentId = null;
}
function submitEscalation() {
  const note = document.getElementById('esc-note').value.trim();
  const priority = document.getElementById('esc-priority').value;
  const errEl = document.getElementById('esc-error');
  errEl.style.display = 'none';
  if (note.length < 5) {
    errEl.textContent = 'Please provide a note (at least 5 characters)';
    errEl.style.display = 'block';
    return;
  }
  const btn = document.getElementById('esc-submit');
  btn.disabled = true; btn.textContent = 'Submitting…';
  const body = new URLSearchParams();
  body.append('action', 'escalate');
  body.append('run_id', RUN_ID);
  body.append('agent_id', activeEscAgentId);
  body.append('priority', priority);
  body.append('note', note);
  fetch(DETAIL_URL, { method:'POST', body: body })
    .then(r => r.json())
    .then(d => {
      btn.disabled = false; btn.textContent = 'Submit Escalation';
      if (d.error) { errEl.textContent = d.error; errEl.style.display = 'block'; return; }
      closeEscalateModal();
      delete detailCache[activeEscAgentId]; // force reload
      alert('Escalation submitted. Assigned to: ' + (d.assigned_to || 'Unassigned'));
      // Re-open the expanded row to refresh its state
      const row = document.querySelector('.variance-row[data-agent-id="' + activeEscAgentId + '"]');
      if (row && row.classList.contains('expanded')) {
        toggleVarianceDetail(row); // close
        toggleVarianceDetail(row); // reopen, re-fetch
      }
    })
    .catch(err => {
      btn.disabled = false; btn.textContent = 'Submit Escalation';
      errEl.textContent = 'Request failed: ' + err; errEl.style.display = 'block';
    });
}
</script>

<?php require_once '../layouts/layout_footer.php'; ?>