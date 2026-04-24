<?php
// ============================================================
// modules/sales.php — Real DB version
// ============================================================
$page_title = 'Sales Data';
$active_nav = 'sales';
require_once '../layouts/layout_header.php';
require_role(['Manager','Reconciler','Admin','Uploader']);

$db   = get_db();
$user = current_user();
// Uploaders get a privacy-scoped view: only the rows that came from
// files THEY uploaded. Everyone else sees the full dataset.
$is_uploader_only = ($user['role'] === 'Uploader');
$uploader_scope_sql = '';
if ($is_uploader_only) {
    $uploader_scope_sql = " AND s.upload_id IN (SELECT id FROM upload_history WHERE uploaded_by = " . (int)$user['id'] . ")";
}

// Filters
// Default date range = the txn_date span of the most recently uploaded
// Sales file. This matches user intent: after uploading you land on
// what you just uploaded, not on whichever stray future-dated row
// happens to be MAX(txn_date) in the table.
if (!isset($_GET['date_from']) && !isset($_GET['date_to'])) {
    $uploader_filter = $is_uploader_only
        ? " AND uh.uploaded_by = " . (int)$user['id']
        : '';
    $latest_sql = "
        SELECT DATE_FORMAT(MIN(s.txn_date), '%Y-%m-01') AS ms,
               LAST_DAY(MAX(s.txn_date))                AS me
        FROM sales s
        WHERE s.upload_id = (
            SELECT uh.id
            FROM upload_history uh
            WHERE uh.file_type = 'Sales' AND uh.upload_status IN ('ok','warning')
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

$f_product  = $_GET['product']  ?? '';
$f_agent    = (int)($_GET['agent_id'] ?? 0);
$f_currency = $_GET['currency']  ?? '';

// Build WHERE
$where  = ["s.txn_date BETWEEN '" . $db->real_escape_string($f_from) . "' AND '" . $db->real_escape_string($f_to) . "'"];
if ($f_product)  $where[] = "s.product = '" . $db->real_escape_string($f_product) . "'";
if ($f_agent)    $where[] = "s.agent_id = $f_agent";
if ($f_currency) $where[] = "s.currency = '" . $db->real_escape_string($f_currency) . "'";
$w = implode(' AND ', $where);
// Scope Uploaders to their own rows
if ($uploader_scope_sql) $w .= $uploader_scope_sql;

// KPIs — counts by currency rather than raw sums. A running sum grows
// unboundedly as uploads accumulate; row counts by currency are more
// useful for verifying that a particular upload landed correctly.
$kpi = $db->query("SELECT
    SUM(CASE WHEN s.currency='ZWG' THEN 1 ELSE 0 END) AS zwg_cnt,
    SUM(CASE WHEN s.currency='USD' THEN 1 ELSE 0 END) AS usd_cnt,
    SUM(CASE WHEN s.currency_flag=1 THEN 1 ELSE 0 END) AS flagged,
    COUNT(*) AS total
  FROM sales s WHERE $w")->fetch_assoc();

// Detail records (paginated — 10 per page)
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;
$rows     = $db->query("SELECT s.*, a.agent_name FROM sales s
  JOIN agents a ON s.agent_id=a.id WHERE $w
  ORDER BY s.txn_date DESC, s.id DESC LIMIT $per_page OFFSET $offset")->fetch_all(MYSQLI_ASSOC);

// By Agent
$by_agent = $db->query("SELECT a.agent_name,
    GROUP_CONCAT(DISTINCT s.product ORDER BY s.product) AS products,
    COUNT(*) AS policies,
    COALESCE(SUM(CASE WHEN s.currency='ZWG' THEN s.amount ELSE 0 END),0) AS zwg,
    COALESCE(SUM(CASE WHEN s.currency='USD' THEN s.amount ELSE 0 END),0) AS usd
  FROM sales s JOIN agents a ON s.agent_id=a.id WHERE $w
  GROUP BY s.agent_id, a.agent_name ORDER BY zwg DESC")->fetch_all(MYSQLI_ASSOC);

// By Payment Method
$by_method = $db->query("SELECT payment_method,
    COUNT(*) AS txns,
    COALESCE(SUM(CASE WHEN currency='ZWG' THEN amount ELSE 0 END),0) AS zwg,
    COALESCE(SUM(CASE WHEN currency='USD' THEN amount ELSE 0 END),0) AS usd
  FROM sales s WHERE $w
  GROUP BY payment_method ORDER BY txns DESC")->fetch_all(MYSQLI_ASSOC);

// Agents for filter
$agents_list = $db->query("SELECT id, agent_name FROM agents WHERE is_active=1 ORDER BY agent_name")->fetch_all(MYSQLI_ASSOC);
$total_rows  = $db->query("SELECT COUNT(*) AS c FROM sales s WHERE $w")->fetch_assoc()['c'];
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
      <h1>Sales Data</h1>
      <p>Aggregated sales records from Icecash, Zinara, and PPA — <?= date('F Y', strtotime($f_from)) ?></p>
    </div>
    <a href="../process/process_export_csv.php?type=sales&date_from=<?= $f_from ?>&date_to=<?= $f_to ?>" class="btn btn-ghost" style="font-weight:600"><i class="fa-solid fa-download"></i> Export CSV</a>
  </div>
</div>

<!-- Filters -->
<form method="GET">
<div class="panel" style="margin-bottom:16px">
  <div class="panel-body" style="padding:14px 18px">
    <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
      <div class="form-group" style="margin:0;flex:1;min-width:140px">
        <label class="form-label">Product</label>
        <select name="product" class="form-select">
          <option value="">All Products</option>
          <option value="Zinara" <?= $f_product==='Zinara'?'selected':'' ?>>Zinara</option>
          <option value="PPA"    <?= $f_product==='PPA'?'selected':'' ?>>PPA</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:140px">
        <label class="form-label">Agent</label>
        <select name="agent_id" class="form-select">
          <option value="0">All Agents</option>
          <?php foreach ($agents_list as $ag): ?>
          <option value="<?= $ag['id'] ?>" <?= $f_agent===$ag['id']?'selected':'' ?>><?= htmlspecialchars($ag['agent_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:120px">
        <label class="form-label">From</label>
        <input type="date" name="date_from" class="form-input" value="<?= $f_from ?>">
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:120px">
        <label class="form-label">To</label>
        <input type="date" name="date_to" class="form-input" value="<?= $f_to ?>">
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:110px">
        <label class="form-label">Currency</label>
        <select name="currency" class="form-select">
          <option value="">All</option>
          <option value="ZWG" <?= $f_currency==='ZWG'?'selected':'' ?>>ZWG</option>
          <option value="USD" <?= $f_currency==='USD'?'selected':'' ?>>USD</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary" style="flex-shrink:0">Apply Filters</button>
      <a href="sales.php" class="btn btn-ghost">Reset</a>
    </div>
  </div>
</div>
</form>

<div class="stat-grid">
  <div class="stat-card green"><div class="stat-label">ZWG Policies</div><div class="stat-value"><?= fmt_compact($kpi['zwg_cnt']) ?></div><div class="stat-sub">ZWG-denominated rows</div></div>
  <div class="stat-card blue"><div class="stat-label">USD Policies</div><div class="stat-value"><?= fmt_compact($kpi['usd_cnt']) ?></div><div class="stat-sub">USD-denominated rows</div></div>
  <div class="stat-card warn"><div class="stat-label">ZWG → USD Flagged</div><div class="stat-value"><?= fmt_compact($kpi['flagged']) ?></div><div class="stat-sub">Currency mismatch policies</div></div>
  <div class="stat-card green"><div class="stat-label">Total Policies</div><div class="stat-value"><?= fmt_compact($kpi['total']) ?></div><div class="stat-sub">All products</div></div>
</div>

<!-- Tabs -->
<div style="display:flex;border-bottom:1px solid #e0e0e0;margin-bottom:16px">
  <div class="tab-btn active" onclick="switchTab(this,'tab-detail')" style="padding:10px 16px;cursor:pointer;font-size:12px;font-weight:500;color:var(--accent);border-bottom:2px solid var(--accent);margin-bottom:-1px">Sales Detail</div>
  <div class="tab-btn" onclick="switchTab(this,'tab-agent')"  style="padding:10px 16px;cursor:pointer;font-size:12px;color:#888;border-bottom:2px solid transparent;margin-bottom:-1px">By Agent</div>
  <div class="tab-btn" onclick="switchTab(this,'tab-method')" style="padding:10px 16px;cursor:pointer;font-size:12px;color:#888;border-bottom:2px solid transparent;margin-bottom:-1px">By Payment Method</div>
</div>

<!-- Detail Tab -->
<div id="tab-detail">
  <div class="panel">
    <div class="panel-header">
      <span class="panel-title">Sales Transactions</span>
      <span class="panel-subtitle"><?= number_format($total_rows) ?> records</span>
    </div>
    <table class="data-table">
      <thead><tr><th>Policy #</th><th>Date</th><th>Agent</th><th>Product</th><th>Method</th><th>Amount</th><th>Currency</th><th>Source</th><th>Flag</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $s): ?>
        <tr>
          <td class="mono" style="color:var(--accent2)"><?= htmlspecialchars($s['policy_number']) ?></td>
          <td class="mono dim"><?= $s['txn_date'] ?></td>
          <td><?= htmlspecialchars($s['agent_name']) ?></td>
          <td class="dim"><?= $s['product'] ?></td>
          <td><span class="badge matched"><?= $s['payment_method'] ?></span></td>
          <td class="mono"><?= number_format($s['amount']) ?></td>
          <td><span class="badge <?= $s['currency']==='USD'?'variance':'reconciled' ?>"><?= $s['currency'] ?></span></td>
          <td class="dim" style="font-size:11px"><?= $s['source_system'] ?></td>
          <td style="color:var(--warn);font-size:11px"><?= $s['currency_flag'] ? '⚠ USD paid' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr><td colspan="9" class="dim" style="text-align:center;padding:20px">No sales records found for this period. Upload a sales file first.</td></tr>
        <?php else: ?>
        <tr><td colspan="9" class="dim" style="text-align:center;padding:14px;font-size:11px">
          <?php
            // Preserve every current GET filter on Prev/Next links so paginating
            // doesn't silently drop the user's product/agent/currency picks.
            $total_pages = (int)ceil($total_rows / $per_page);
            $base_qs = $_GET;
          ?>
          Showing <?= count($rows) ?> of <?= number_format($total_rows) ?> records
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
</div>

<!-- By Agent Tab -->
<div id="tab-agent" style="display:none">
  <div class="panel">
    <div class="panel-header"><span class="panel-title">Sales by Agent</span></div>
    <table class="data-table">
      <thead><tr><th>Agent</th><th>Products</th><th>Policies</th><th>ZWG Sales</th><th>USD Sales</th></tr></thead>
      <tbody>
        <?php foreach ($by_agent as $b): ?>
        <tr>
          <td style="font-weight:500"><?= htmlspecialchars($b['agent_name']) ?></td>
          <td class="dim"><?= htmlspecialchars($b['products']) ?></td>
          <td class="mono"><?= number_format($b['policies']) ?></td>
          <td class="mono"><?= number_format($b['zwg']) ?></td>
          <td class="mono"><?= number_format($b['usd']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($by_agent)): ?>
        <tr><td colspan="5" class="dim" style="text-align:center;padding:20px">No data.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- By Method Tab -->
<div id="tab-method" style="display:none">
  <div class="panel">
    <div class="panel-header"><span class="panel-title">Sales by Payment Method</span></div>
    <table class="data-table">
      <thead><tr><th>Payment Method</th><th>Transactions</th><th>Total ZWG</th><th>Total USD</th><th>% of Total</th></tr></thead>
      <tbody>
        <?php
        $grand = array_sum(array_column($by_method,'txns')) ?: 1;
        foreach ($by_method as $m):
          $pct = round(($m['txns']/$grand)*100,1);
        ?>
        <tr>
          <td><span class="badge matched"><?= $m['payment_method'] ?></span></td>
          <td class="mono"><?= number_format($m['txns']) ?></td>
          <td class="mono"><?= number_format($m['zwg']) ?></td>
          <td class="mono"><?= number_format($m['usd']) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div class="progress-wrap" style="flex:1"><div class="progress-bar green" style="width:<?= $pct ?>%"></div></div>
              <span class="mono dim"><?= $pct ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function switchTab(el, targetId) {
  document.querySelectorAll('.tab-btn').forEach(t => { t.style.color='#888'; t.style.borderBottomColor='transparent'; });
  el.style.color='var(--accent)'; el.style.borderBottomColor='var(--accent)';
  ['tab-detail','tab-agent','tab-method'].forEach(id => {
    document.getElementById(id).style.display = id===targetId ? 'block' : 'none';
  });
}
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
