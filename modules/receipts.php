<?php
// ============================================================
// modules/receipts.php 
// ============================================================
$page_title = 'Receipts Data';
$active_nav = 'receipts';
require_once '../layouts/layout_header.php';
require_role(['Manager','Reconciler','Admin','Uploader']);

$db   = get_db();
$user = current_user();
// Uploaders get a privacy-scoped view: only the rows that came from
// files THEY uploaded. Everyone else sees the full dataset.
$is_uploader_only = ($user['role'] === 'Uploader');
$uploader_scope_sql = '';
if ($is_uploader_only) {
    $uploader_scope_sql = " AND upload_id IN (SELECT id FROM upload_history WHERE uploaded_by = " . (int)$user['id'] . ")";
}

// Default date range = the txn_date span of the most recently uploaded
// Receipts file. Matches user intent: after uploading, you land on the
// data you just uploaded — not on whichever stray future-dated row
// happens to be MAX(txn_date) in the table.
if (!isset($_GET['date_from']) && !isset($_GET['date_to'])) {
    $uploader_filter = $is_uploader_only
        ? " AND uh.uploaded_by = " . (int)$user['id']
        : '';
    $latest_sql = "
        SELECT DATE_FORMAT(MIN(r.txn_date), '%Y-%m-01') AS ms,
               LAST_DAY(MAX(r.txn_date))                AS me
        FROM receipts r
        WHERE r.upload_id = (
            SELECT uh.id
            FROM upload_history uh
            WHERE uh.file_type = 'Receipts' AND uh.upload_status IN ('ok','warning')
                  $uploader_filter
            ORDER BY uh.id DESC LIMIT 1
        )";
    $latest = $db->query($latest_sql)->fetch_assoc();
    if ($latest && $latest['ms']) {
        $f_from = $latest['ms'];
        $f_to   = $latest['me'];
    } else {
        $f_from = date('Y-m-01');
        $f_to   = date('Y-m-d');
    }
} else {
    $f_from = $_GET['date_from'] ?? date('Y-m-01');
    $f_to   = $_GET['date_to']   ?? date('Y-m-d');
}

$f_source    = $_GET['source']       ?? '';
$f_status    = $_GET['match_status'] ?? '';
$f_direction = $_GET['direction']    ?? 'credit';   // credit | debit | all
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 10;
$offset      = ($page - 1) * $per_page;

$where = ["txn_date BETWEEN '" . $db->real_escape_string($f_from) . "' AND '" . $db->real_escape_string($f_to) . "'"];
if ($f_direction === 'credit' || $f_direction === 'debit') {
    $where[] = "direction = '$f_direction'";
}
if ($f_source) $where[] = "channel = '" . $db->real_escape_string($f_source) . "'";
if ($f_status) $where[] = "match_status = '" . $db->real_escape_string($f_status) . "'";
$w = implode(' AND ', $where);
// Append the uploader scope OUTSIDE of the implode so it's always the
// last condition regardless of which optional filters the user picks.
if ($uploader_scope_sql) $w .= $uploader_scope_sql;

// Counts by currency rather than raw sums: the sum of amounts grows
// unboundedly as more uploads accumulate, which isn't actionable on a
// data-quality page. Counts tell the user "how many rows landed by
// currency" which is what matters for verifying an upload.
$kpi = $db->query("SELECT
    SUM(CASE WHEN currency='ZWG' THEN 1 ELSE 0 END) AS zwg_cnt,
    SUM(CASE WHEN currency='USD' THEN 1 ELSE 0 END) AS usd_cnt,
    SUM(CASE WHEN match_status='pending' THEN 1 ELSE 0 END) AS unmatched,
    COUNT(*) AS total,
    SUM(CASE WHEN match_status='matched' THEN 1 ELSE 0 END) AS matched
  FROM receipts WHERE $w")->fetch_assoc();

$match_rate = $kpi['total'] > 0 ? round(($kpi['matched']/$kpi['total'])*100,1) : 0;

$rows  = $db->query("SELECT * FROM receipts WHERE $w ORDER BY txn_date DESC LIMIT $per_page OFFSET $offset")->fetch_all(MYSQLI_ASSOC);
$total = $db->query("SELECT COUNT(*) AS c FROM receipts WHERE $w")->fetch_assoc()['c'];
$total_pages = max(1, (int)ceil($total / $per_page));
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div style="flex:1">
  <h1>Receipts Data</h1>
  <p>
    <?php if ($f_direction === 'debit'): ?>
      Float outflows (fees, refunds, payouts)
    <?php elseif ($f_direction === 'all'): ?>
      All receipts — credits and debits combined
    <?php else: ?>
      Incoming payment records
    <?php endif; ?>
    &mdash; <?= date('j M Y', strtotime($f_from)) ?> to <?= date('j M Y', strtotime($f_to)) ?>
  </p>
    </div>
    <a href="../process/process_export_csv.php?type=receipts&date_from=<?= $f_from ?>&date_to=<?= $f_to ?>&direction=<?= $f_direction ?>" class="btn btn-ghost" style="font-weight:600"><i class="fa-solid fa-download"></i> Export CSV</a>
  </div>
</div>

<form method="GET">
<div class="panel" style="margin-bottom:16px">
  <div class="panel-body" style="padding:14px 18px">
    <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
      <div class="form-group" style="margin:0;flex:1;min-width:120px">
        <label class="form-label">Direction</label>
        <select name="direction" class="form-select">
          <option value="credit" <?= $f_direction==='credit'?'selected':'' ?>>Credits (inflow)</option>
          <option value="debit"  <?= $f_direction==='debit'?'selected':'' ?>>Debits (outflow)</option>
          <option value="all"    <?= $f_direction==='all'?'selected':'' ?>>All</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:140px">
        <label class="form-label">Source</label>
        <select name="source" class="form-select">
          <option value="">All Sources</option>
          <?php foreach (['Bank POS','iPOS','EcoCash','Zimswitch','Broker'] as $ch): ?>
          <option value="<?= $ch ?>" <?= $f_source===$ch?'selected':'' ?>><?= $ch ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:130px">
        <label class="form-label">Match Status</label>
        <select name="match_status" class="form-select">
          <option value="">All</option>
          <option value="matched"  <?= $f_status==='matched'?'selected':'' ?>>Matched</option>
          <option value="pending"  <?= $f_status==='pending'?'selected':'' ?>>Unmatched</option>
          <option value="variance" <?= $f_status==='variance'?'selected':'' ?>>Variance</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:120px">
        <label class="form-label">From</label><input type="date" name="date_from" class="form-input" value="<?= $f_from ?>">
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:120px">
        <label class="form-label">To</label><input type="date" name="date_to" class="form-input" value="<?= $f_to ?>">
      </div>
      <button type="submit" class="btn btn-primary">Filter</button>
      <a href="receipts.php" class="btn btn-ghost">Reset</a>
    </div>
  </div>
</div>
</form>

<div class="stat-grid">
  <div class="stat-card green">
    <div class="stat-label">ZWG Receipts</div>
    <div class="stat-value"><?= fmt_compact($kpi['zwg_cnt']) ?></div>
    <div class="stat-sub">ZWG-denominated rows</div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">USD Receipts</div>
    <div class="stat-value"><?= fmt_compact($kpi['usd_cnt']) ?></div>
    <div class="stat-sub">USD-denominated rows</div>
  </div>
  <div class="stat-card warn">
    <div class="stat-label">Unmatched</div>
    <div class="stat-value"><?= fmt_compact($kpi['unmatched']) ?></div>
    <div class="stat-sub">Need manual review</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Match Rate</div>
    <div class="stat-value"><?= $match_rate ?><span style="font-size:14px">%</span></div>
    <div class="stat-sub"><?= fmt_compact($kpi['matched']) ?> of <?= fmt_compact($kpi['total']) ?> matched</div>
  </div>
</div>

<div class="panel">
  <div class="panel-header">
    <span class="panel-title">Receipt Transactions</span>
    <span class="panel-subtitle"><?= number_format($total) ?> records</span>
  </div>
  <table class="data-table">
    <thead><tr><th>Ref / ID</th><th>Date</th><th>Terminal / Channel</th><th>Source</th><th>Amount</th><th>Currency</th><th>Matched Policy</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono" style="color:var(--accent2);font-size:11px"><?= htmlspecialchars($r['reference_no']) ?></td>
        <td class="mono dim"><?= $r['txn_date'] ?></td>
        <td style="font-size:11.5px"><?= htmlspecialchars($r['terminal_id'] ?: $r['channel']) ?></td>
        <td><span class="badge matched"><?= $r['channel'] ?></span></td>
        <td class="mono"><?= number_format($r['amount']) ?></td>
        <td><span class="badge <?= $r['currency']==='USD'?'variance':'reconciled' ?>"><?= $r['currency'] ?></span></td>
        <td class="mono" style="color:<?= $r['matched_policy']?'var(--accent2)':'#aaa' ?>"><?= $r['matched_policy'] ?: '—' ?></td>
        <td><span class="badge <?= $r['match_status'] ?>"><?= ucfirst($r['match_status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
      <tr><td colspan="8" class="dim" style="text-align:center;padding:20px">No receipt records found. Upload receipt files first.</td></tr>
      <?php else: ?>
      <tr><td colspan="8" class="dim" style="text-align:center;padding:14px;font-size:11px">
        <?php $base_qs = $_GET; ?>
        Showing <?= count($rows) ?> of <?= number_format($total) ?> records
        <?php if ($total_pages > 1): ?>
        &nbsp;·&nbsp; Page <?= $page ?> of <?= $total_pages ?>
        <?php if ($page > 1): ?>
          &nbsp;·&nbsp; <a href="?<?= http_build_query(array_merge($base_qs, array('page'=>$page-1))) ?>">← Prev</a>
        <?php endif; ?>
        <?php if ($page < $total_pages): ?>
          &nbsp; <a href="?<?= http_build_query(array_merge($base_qs, array('page'=>$page+1))) ?>">Next →</a>
        <?php endif; ?>
        <?php endif; ?>
      </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require_once '../layouts/layout_footer.php'; ?>
