<?php
// ============================================================
// admin/statement_detail.php
// Formal printable reconciliation statement (per agent per period).
// ============================================================
$page_title = 'Statement';
$active_nav = 'statements';
require_once '../layouts/layout_header.php';
require_role(['Manager','Reconciler','Admin']);

$db   = get_db();
$user = current_user();
$uid  = (int)$user['id'];
// Permission matrix:
//   Manager/Admin → can edit any draft, finalize, cancel
//   Reconciler    → can edit own drafts only, cannot finalize/cancel
$is_mgr      = in_array($user['role'], array('Manager', 'Admin'));
$can_edit     = false; // resolved after loading the statement
$can_finalize = $is_mgr;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: statements.php?error=' . urlencode('Invalid statement id'));
    exit;
}

$success = htmlspecialchars($_GET['success'] ?? '');
$error   = htmlspecialchars($_GET['error']   ?? '');

// Load statement + related data
$stmt = $db->prepare("
    SELECT s.*, a.agent_name, a.agent_code, a.agent_type, a.region, a.currency AS agent_currency,
           r.period_label, r.product, r.run_status,
           gb.full_name AS generated_by_name, gb.role AS generated_by_role,
           rv.full_name AS reviewed_by_name
    FROM statements s
    JOIN agents a ON s.agent_id = a.id
    LEFT JOIN reconciliation_runs r ON s.run_id = r.id
    JOIN users gb ON s.generated_by = gb.id
    LEFT JOIN users rv ON s.reviewed_by = rv.id
    WHERE s.id = ?
");
$stmt->bind_param('i', $id);
$stmt->execute();
$s = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$s) {
    header('Location: statements.php?error=' . urlencode('Statement not found'));
    exit;
}

// Resolve edit permission now that we have the statement row.
// Manager/Admin can edit any. Reconciler can edit only drafts they generated.
$can_edit = $is_mgr
    || ($user['role'] === 'Reconciler' && $s['status'] === 'draft' && (int)$s['generated_by'] === $uid);

// Organization info from system_settings
$settings = array();
foreach ($db->query("SELECT setting_key, setting_value FROM system_settings")->fetch_all(MYSQLI_ASSOC) as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$org_name = $settings['org_name']       ?? 'Zimnat General Insurance';
$sys_ver  = $settings['system_version'] ?? 'v1.0';

// Per-channel breakdown for this agent in this period
$channels = array();
if ($s['run_id']) {
    $c_stmt = $db->prepare("
        SELECT channel, sales_zwg, sales_usd, receipts_zwg, receipts_usd, variance_zwg, variance_usd
        FROM variance_by_channel
        WHERE run_id = ? AND agent_id = ?
        ORDER BY ABS(variance_zwg) + ABS(variance_usd) DESC
    ");
    $c_stmt->bind_param('ii', $s['run_id'], $s['agent_id']);
    $c_stmt->execute();
    $channels = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $c_stmt->close();
}

// Unmatched sales for this agent in the period
$u_sales_stmt = $db->prepare("
    SELECT s.policy_number, s.txn_date, s.payment_method, s.amount, s.currency
    FROM sales s
    LEFT JOIN receipts r ON r.matched_sale_id = s.id
    WHERE s.agent_id = ? AND s.txn_date BETWEEN ? AND ? AND r.id IS NULL
    ORDER BY s.txn_date DESC
    LIMIT 30
");
$u_sales_stmt->bind_param('iss', $s['agent_id'], $s['period_from'], $s['period_to']);
$u_sales_stmt->execute();
$unmatched_sales = $u_sales_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$u_sales_stmt->close();

// Escalations linked to this run/agent
$escalations = array();
if ($s['run_id']) {
    $e_stmt = $db->prepare("
        SELECT id, priority, status, action_detail, created_at
        FROM escalations
        WHERE run_id = ? AND agent_id = ?
        ORDER BY created_at DESC
    ");
    $e_stmt->bind_param('ii', $s['run_id'], $s['agent_id']);
    $e_stmt->execute();
    $escalations = $e_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $e_stmt->close();
}

$status_cls = array('draft'=>'pending','final'=>'reconciled','reviewed'=>'active','cancelled'=>'inactive');
$var_zwg = (float)$s['variance_zwg'];
$var_usd = (float)$s['variance_usd'];
$is_reconciled = (abs($var_zwg) < 0.01 && abs($var_usd) < 0.01);
?>

<!-- Toolbar (hidden on print) -->
<div class="no-print" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
  <div>
    <a href="statements.php" class="btn btn-ghost">← Back to Statements</a>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button onclick="window.print()" class="btn btn-ghost"><i class="fa-solid fa-print"></i> Print / Save PDF</button>
    <?php if ($can_finalize && $s['status'] === 'draft'): ?>
    <form method="POST" action="../process/process_statements.php" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="finalize">
      <input type="hidden" name="statement_id" value="<?= $id ?>">
      <button type="submit" class="btn btn-primary" onclick="return confirm('Finalize this statement? It will be locked for review.')">✓ Finalize</button>
    </form>
    <?php endif; ?>
    <?php if ($can_finalize && in_array($s['status'], array('draft','final'))): ?>
    <button class="btn btn-ghost" onclick="document.getElementById('review-modal').style.display='flex'">Review</button>
    <button class="btn btn-ghost" style="color:var(--danger)" onclick="document.getElementById('cancel-modal').style.display='flex'">Cancel</button>
    <?php endif; ?>
    <?php if ($s['run_id']): ?>
    <a href="../modules/variance.php?run_id=<?= (int)$s['run_id'] ?>" class="btn btn-ghost">View Run</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success no-print">✓ <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger no-print">⚠ <?= $error ?></div><?php endif; ?>

<!-- ══ STATEMENT DOCUMENT ══ -->
<div class="statement-page">
  <!-- Header -->
  <div class="stmt-header">
    <div>
      <div class="stmt-org"><?= htmlspecialchars($org_name) ?></div>
      <div class="stmt-subtitle">Reconciliation Statement</div>
    </div>
    <div class="stmt-meta">
      <div><strong>Statement #:</strong> <span class="mono"><?= htmlspecialchars($s['statement_no']) ?></span></div>
      <div><strong>Period:</strong> <?= $s['period_from'] ?> → <?= $s['period_to'] ?></div>
      <div><strong>Issued:</strong> <?= date('Y-m-d H:i', strtotime($s['generated_at'])) ?></div>
      <div><strong>Status:</strong> <span class="badge <?= $status_cls[$s['status']] ?? 'pending' ?>"><?= strtoupper($s['status']) ?></span></div>
      <?php if ($s['run_id']): ?>
      <div><strong>Run:</strong> #<?= (int)$s['run_id'] ?> (<?= htmlspecialchars($s['period_label'] ?? '') ?>)</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Agent block -->
  <div class="stmt-section">
    <h3>Agent Details</h3>
    <table class="stmt-kv">
      <tr><th>Agent Code</th><td class="mono"><?= htmlspecialchars($s['agent_code']) ?></td>
          <th>Agent Type</th><td><?= htmlspecialchars($s['agent_type']) ?></td></tr>
      <tr><th>Agent Name</th><td><?= htmlspecialchars($s['agent_name']) ?></td>
          <th>Region</th><td><?= htmlspecialchars($s['region']) ?></td></tr>
      <tr><th>Registered Currency</th><td><?= htmlspecialchars($s['agent_currency']) ?></td>
          <th>Product</th><td><?= htmlspecialchars($s['product'] ?? '—') ?></td></tr>
    </table>
  </div>

  <!-- Summary totals -->
  <div class="stmt-section">
    <h3>Reconciliation Summary</h3>
    <table class="stmt-totals">
      <thead>
        <tr><th>Line Item</th><th class="right">ZWG</th><th class="right">USD</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Total Sales (period)</td>
          <td class="right mono"><?= number_format($s['sales_zwg'], 2) ?></td>
          <td class="right mono"><?= number_format($s['sales_usd'], 2) ?></td>
        </tr>
        <tr>
          <td>Total Receipts Matched</td>
          <td class="right mono"><?= number_format($s['receipts_zwg'], 2) ?></td>
          <td class="right mono"><?= number_format($s['receipts_usd'], 2) ?></td>
        </tr>
        <tr class="stmt-variance-row">
          <td><strong>Variance</strong></td>
          <td class="right mono <?= $var_zwg < -0.01 ? 'neg' : ($var_zwg > 0.01 ? 'over' : 'ok') ?>"><strong><?= number_format($var_zwg, 2) ?></strong></td>
          <td class="right mono <?= $var_usd < -0.01 ? 'neg' : ($var_usd > 0.01 ? 'over' : 'ok') ?>"><strong><?= number_format($var_usd, 2) ?></strong></td>
        </tr>
      </tbody>
    </table>
    <div class="stmt-conclusion">
      <?php if ($is_reconciled): ?>
      <span class="stmt-ok">✓ FULLY RECONCILED — no variance detected.</span>
      <?php else: ?>
      <span class="stmt-var">⚠ VARIANCE DETECTED — Category: <strong><?= htmlspecialchars($s['variance_cat'] ?? 'Uncategorized') ?></strong></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Per-channel breakdown -->
  <?php if (!empty($channels)): ?>
  <div class="stmt-section">
    <h3>Per-Channel Breakdown</h3>
    <table class="stmt-totals">
      <thead>
        <tr>
          <th>Channel</th>
          <th class="right">Sales ZWG</th>
          <th class="right">Receipts ZWG</th>
          <th class="right">Var ZWG</th>
          <th class="right">Var USD</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($channels as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['channel']) ?></td>
          <td class="right mono"><?= number_format($c['sales_zwg'], 2) ?></td>
          <td class="right mono"><?= number_format($c['receipts_zwg'], 2) ?></td>
          <td class="right mono <?= $c['variance_zwg'] < -0.01 ? 'neg' : ($c['variance_zwg'] > 0.01 ? 'over' : '') ?>"><?= number_format($c['variance_zwg'], 2) ?></td>
          <td class="right mono <?= $c['variance_usd'] < -0.01 ? 'neg' : ($c['variance_usd'] > 0.01 ? 'over' : '') ?>"><?= number_format($c['variance_usd'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Unmatched sales -->
  <?php if (!empty($unmatched_sales)): ?>
  <div class="stmt-section">
    <h3>Unmatched Sales (<?= count($unmatched_sales) ?>)</h3>
    <p class="stmt-note">The following policies were recorded as sales but have no matching receipt in the period.</p>
    <table class="stmt-totals">
      <thead><tr><th>Policy #</th><th>Date</th><th>Method</th><th class="right">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($unmatched_sales as $us): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($us['policy_number']) ?></td>
          <td class="mono"><?= $us['txn_date'] ?></td>
          <td><?= htmlspecialchars($us['payment_method']) ?></td>
          <td class="right mono"><?= $us['currency'] ?> <?= number_format($us['amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Escalations -->
  <?php if (!empty($escalations)): ?>
  <div class="stmt-section">
    <h3>Related Escalations (<?= count($escalations) ?>)</h3>
    <table class="stmt-totals">
      <thead><tr><th>Esc #</th><th>Priority</th><th>Status</th><th>Detail</th><th>Created</th></tr></thead>
      <tbody>
        <?php foreach ($escalations as $e): ?>
        <tr>
          <td class="mono">#<?= $e['id'] ?></td>
          <td><span class="badge <?= $e['priority'] ?>"><?= strtoupper($e['priority']) ?></span></td>
          <td><span class="badge <?= $e['status']==='resolved'||$e['status']==='reviewed'?'reconciled':'pending' ?>"><?= ucfirst($e['status']) ?></span></td>
          <td style="font-size:11px"><?= htmlspecialchars(substr($e['action_detail'], 0, 200)) ?></td>
          <td class="mono" style="font-size:11px"><?= $e['created_at'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Notes -->
  <?php if (!empty($s['notes'])): ?>
  <div class="stmt-section">
    <h3>Notes</h3>
    <div class="stmt-notes"><?= nl2br(htmlspecialchars($s['notes'])) ?></div>
  </div>
  <?php endif; ?>

  <!-- Sign-off -->
  <div class="stmt-section stmt-signoff">
    <h3>Sign-Off</h3>
    <div class="signoff-row">
      <div class="signoff-block">
        <div class="signoff-label">Prepared By</div>
        <div class="signoff-value">
          <strong><?= htmlspecialchars($s['generated_by_name']) ?></strong><br>
          <span class="dim"><?= htmlspecialchars($s['generated_by_role']) ?></span><br>
          <span class="dim"><?= date('Y-m-d H:i', strtotime($s['generated_at'])) ?></span>
        </div>
      </div>
      <div class="signoff-block">
        <div class="signoff-label">Reviewed By</div>
        <div class="signoff-value">
          <?php if ($s['reviewed_by_name']): ?>
            <strong><?= htmlspecialchars($s['reviewed_by_name']) ?></strong><br>
            <span class="dim"><?= date('Y-m-d H:i', strtotime($s['reviewed_at'])) ?></span>
          <?php else: ?>
            <div class="signoff-line"></div>
            <span class="dim">(signature)</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="signoff-block">
        <div class="signoff-label">Finance Approval</div>
        <div class="signoff-value">
          <div class="signoff-line"></div>
          <span class="dim">(signature + date)</span>
        </div>
      </div>
    </div>
  </div>

  <div class="stmt-footer">
    <?= htmlspecialchars($org_name) ?> · System <?= htmlspecialchars($sys_ver) ?> · Statement generated <?= date('Y-m-d H:i:s') ?> · Page generated for <?= htmlspecialchars($user['name']) ?>
  </div>
</div>

<!-- Review Modal -->
<?php if ($can_edit): ?>
<div id="review-modal" class="no-print" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:460px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Review Statement</span>
      <button onclick="document.getElementById('review-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_statements.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="review">
      <input type="hidden" name="statement_id" value="<?= $id ?>">
      <div class="form-group">
        <label class="form-label">Review Notes</label>
        <textarea name="review_notes" class="form-input" style="height:100px" required placeholder="What did you verify? Any concerns or corrections?"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Mark as Reviewed</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('review-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div id="cancel-modal" class="no-print" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:460px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Cancel Statement</span>
      <button onclick="document.getElementById('cancel-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_statements.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="statement_id" value="<?= $id ?>">
      <p>Cancelling will mark this statement as void. It remains visible for audit but is excluded from totals.</p>
      <div class="form-group">
        <label class="form-label">Reason (required)</label>
        <textarea name="reason" class="form-input" style="height:80px" required minlength="5"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary" style="background:var(--danger)">Cancel Statement</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('cancel-modal').style.display='none'">Back</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<style>
.statement-page {
  background: #fff;
  border: 1px solid #d0d0d0;
  border-radius: 4px;
  padding: 40px 50px;
  max-width: 1000px;
  margin: 0 auto;
  font-family: Georgia, 'Times New Roman', serif;
  color: #222;
}
.stmt-header {
  display:flex; justify-content:space-between; align-items:flex-start;
  border-bottom:3px double #007a3d; padding-bottom:20px; margin-bottom:24px;
}
.stmt-org { font-size:22px; font-weight:800; color:#007a3d; letter-spacing:0.5px }
.stmt-subtitle { font-size:14px; color:#666; letter-spacing:3px; text-transform:uppercase; margin-top:4px }
.stmt-meta { font-size:11px; text-align:right; line-height:1.7 }
.stmt-meta strong { color:#007a3d }
.stmt-section { margin-bottom:24px }
.stmt-section h3 { font-size:13px; font-weight:700; color:#007a3d; text-transform:uppercase; letter-spacing:1px; border-bottom:1px solid #007a3d; padding-bottom:4px; margin-bottom:12px }
.stmt-kv { width:100%; border-collapse:collapse; font-size:12px }
.stmt-kv th { text-align:left; padding:6px 12px 6px 0; color:#666; font-weight:600; width:22% }
.stmt-kv td { padding:6px 20px 6px 0; width:28% }
.stmt-totals { width:100%; border-collapse:collapse; font-size:12px }
.stmt-totals th { background:#f0f4f1; padding:8px 10px; text-align:left; font-weight:700; color:#007a3d; border-bottom:2px solid #007a3d }
.stmt-totals td { padding:8px 10px; border-bottom:1px solid #eee; font-family:Georgia, serif }
.stmt-totals .right { text-align:right }
.stmt-totals .mono { font-family:'Courier New', monospace }
.stmt-totals .neg { color:#c0392b }
.stmt-totals .over { color:#d49a00 }
.stmt-totals .ok { color:#00a950 }
.stmt-variance-row { background:#fafbfc }
.stmt-variance-row td { border-top:2px solid #007a3d }
.stmt-conclusion { margin-top:10px; padding:10px; border:1px solid #ddd; border-radius:4px; font-size:12px }
.stmt-ok { color:#00a950; font-weight:700 }
.stmt-var { color:#c0392b; font-weight:700 }
.stmt-note { font-size:11px; color:#666; font-style:italic; margin:4px 0 8px }
.stmt-notes { padding:10px 12px; background:#fafbfc; border:1px solid #e5e5e5; font-size:12px; white-space:pre-wrap }
.stmt-signoff { margin-top:40px }
.signoff-row { display:flex; justify-content:space-between; gap:30px; margin-top:20px }
.signoff-block { flex:1; text-align:center }
.signoff-label { font-size:10px; text-transform:uppercase; color:#666; letter-spacing:1px; margin-bottom:30px }
.signoff-value { font-size:12px; min-height:60px }
.signoff-line { border-bottom:1px solid #333; width:80%; margin:0 auto 6px }
.stmt-footer { border-top:1px solid #ddd; padding-top:12px; margin-top:30px; font-size:10px; color:#999; text-align:center }

@media print {
  body { background:#fff !important }
  .no-print, .sidebar, .topbar, .page-header, header, footer, nav { display:none !important }
  .statement-page { border:none !important; padding:0 !important; max-width:100% !important; box-shadow:none !important }
  @page { margin: 15mm }
}
</style>

<?php require_once '../layouts/layout_footer.php'; ?>
