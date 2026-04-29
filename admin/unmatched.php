<?php
// ============================================================
// admin/unmatched.php
// Unmatched transactions workbench with smart matching + exclude + escalate.
// ============================================================
$page_title = 'Unmatched Transactions';
$active_nav = 'unmatched';
require_once '../layouts/layout_header.php';
require_role(['Manager','Reconciler','Admin']);

$db   = get_db();
$user = current_user();

// ── Filters ──────────────────────────────────────────────
// Default date range auto-detects the latest month that actually has
// unmatched/variance receipt data so historical uploads don't produce
// an empty workbench on first load. Explicit URL params still override.
if (!isset($_GET['date_from']) && !isset($_GET['date_to'])) {
    $row = $db->query("
        SELECT DATE_FORMAT(MAX(txn_date), '%Y-%m-01') AS ms, LAST_DAY(MAX(txn_date)) AS me
        FROM receipts
        WHERE direction='credit' AND match_status IN ('pending','variance')
    ")->fetch_assoc();
    if ($row && $row['ms']) {
        $date_from = $row['ms'];
        $date_to   = $row['me'];
    } else {
        // No pending/variance receipts at all — fall back to latest
        // credit month so the debits/excluded tabs still have a range.
        $row2 = $db->query("SELECT DATE_FORMAT(MAX(txn_date), '%Y-%m-01') AS ms, LAST_DAY(MAX(txn_date)) AS me FROM receipts")->fetch_assoc();
        $date_from = $row2 && $row2['ms'] ? $row2['ms'] : date('Y-m-01');
        $date_to   = $row2 && $row2['me'] ? $row2['me'] : date('Y-m-t');
    }
} else {
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to   = $_GET['date_to']   ?? date('Y-m-t');
}
// Defensive: a malformed ?date_from=foobar would make strtotime() return
// false, and date(_, false) deprecates on PHP 8.1+. Fall back to month
// boundaries when the input is unparseable.
$df_ts = strtotime($date_from);
$dt_ts = strtotime($date_to);
$date_from = $df_ts ? date('Y-m-d', $df_ts) : date('Y-m-01');
$date_to   = $dt_ts ? date('Y-m-d', $dt_ts) : date('Y-m-t');
// Whitelist enum-like GET params before they reach SQL/branching logic
// — even one query that drops the escape would otherwise turn these into
// an injection vector. Default to a known-safe value when invalid.
$raw_tab     = $_GET['tab']     ?? '';
$tab         = in_array($raw_tab,     ['receipts','sales','excluded','debits','currency_review'], true) ? $raw_tab     : 'receipts';

$raw_status  = $_GET['status']  ?? '';
$status      = in_array($raw_status,  ['all','pending','variance'], true)                               ? $raw_status  : 'all';

$raw_age     = $_GET['age']     ?? '';
$age         = in_array($raw_age,     ['all','fresh','aging','stale'], true)                            ? $raw_age     : 'all';

$raw_channel = $_GET['channel'] ?? '';
$channel     = in_array($raw_channel, ['','Bank POS','iPOS','EcoCash','Zimswitch','Broker'], true)      ? $raw_channel : '';
$q         = trim($_GET['q'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 10;
$offset    = ($page - 1) * $per_page;

// Direct-link target: ?receipt_id=<id> (used by escalations "View context").
// Resolve the receipt once, widen the date window to include its txn_date,
// force the tab to match its match_status, and drop all noise filters so
// the single row is guaranteed to render.
$focus_receipt_id = (int)($_GET['receipt_id'] ?? 0);
$focus_receipt    = null;
if ($focus_receipt_id > 0) {
    $f_stmt = $db->prepare("SELECT id, txn_date, match_status, direction FROM receipts WHERE id = ?");
    $f_stmt->bind_param('i', $focus_receipt_id);
    $f_stmt->execute();
    $focus_receipt = $f_stmt->get_result()->fetch_assoc();
    $f_stmt->close();
    if ($focus_receipt) {
        if ($focus_receipt['txn_date'] < $date_from) $date_from = $focus_receipt['txn_date'];
        if ($focus_receipt['txn_date'] > $date_to)   $date_to   = $focus_receipt['txn_date'];
        if ($focus_receipt['direction'] === 'debit')        $tab = 'debits';
        elseif ($focus_receipt['match_status'] === 'excluded') $tab = 'excluded';
        else                                                $tab = 'receipts';
        // Clear secondary filters that could still hide the row
        $status = 'all'; $age = 'all'; $channel = ''; $q = ''; $page = 1;
    }
}

$success = htmlspecialchars($_GET['success'] ?? '');
$error   = htmlspecialchars($_GET['error']   ?? '');

function age_bucket_sql() {
    return "CASE
        WHEN DATEDIFF(CURDATE(), txn_date) <= 7  THEN 'fresh'
        WHEN DATEDIFF(CURDATE(), txn_date) <= 30 THEN 'aging'
        ELSE 'stale'
    END";
}

// ── Build receipts query ────────────────────────────────
$r_where  = "r.txn_date BETWEEN ? AND ?";
$r_params = array($date_from, $date_to);
$r_types  = 'ss';

if ($tab === 'debits') {
    // Float outflows — always direction='debit' (which are auto-excluded
    // at ingestion time). They don't participate in matching; this tab
    // is purely for review and float-balance reconciliation.
    $r_where .= " AND r.direction='debit'";
} elseif ($tab === 'excluded') {
    // "Excluded" now means manually-excluded credits only. Debits live
    // in their own tab so the two aren't visually conflated.
    $r_where .= " AND r.direction='credit' AND r.match_status='excluded'";
} else {
    $r_where .= " AND r.direction='credit' AND r.match_status IN ('pending','variance')";
    if ($status !== 'all' && in_array($status, array('pending','variance'))) {
        $r_where .= " AND r.match_status=?";
        $r_params[] = $status;
        $r_types   .= 's';
    }
}
if ($age === 'fresh')  $r_where .= " AND DATEDIFF(CURDATE(), r.txn_date) <= 7";
if ($age === 'aging')  $r_where .= " AND DATEDIFF(CURDATE(), r.txn_date) BETWEEN 8 AND 30";
if ($age === 'stale')  $r_where .= " AND DATEDIFF(CURDATE(), r.txn_date) > 30";
if ($channel) {
    $r_where .= " AND r.channel=?";
    $r_params[] = $channel;
    $r_types   .= 's';
}
if ($q !== '') {
    $r_where .= " AND (r.reference_no LIKE ? OR r.source_name LIKE ? OR CAST(r.amount AS CHAR) LIKE ?)";
    $like = '%' . $q . '%';
    $r_params[] = $like; $r_params[] = $like; $r_params[] = $like;
    $r_types   .= 'sss';
}
if ($focus_receipt) {
    $r_where .= " AND r.id = ?";
    $r_params[] = $focus_receipt['id'];
    $r_types   .= 'i';
}

// Counts (for tabs) — all scoped to direction='credit' except the debits tab
$cnt_pending = (int)$db->query("SELECT COUNT(*) c FROM receipts r WHERE r.direction='credit' AND r.match_status='pending' AND r.txn_date BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['c'];
$cnt_variance = (int)$db->query("SELECT COUNT(*) c FROM receipts r WHERE r.direction='credit' AND r.match_status='variance' AND r.txn_date BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['c'];
$cnt_excluded = (int)$db->query("SELECT COUNT(*) c FROM receipts r WHERE r.direction='credit' AND r.match_status='excluded' AND r.txn_date BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['c'];
$debit_stats  = $db->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) total FROM receipts r WHERE r.direction='debit' AND r.txn_date BETWEEN '$date_from' AND '$date_to'")->fetch_assoc();
$cnt_debits   = (int)$debit_stats['c'];
$sum_debits   = (float)$debit_stats['total'];
$cnt_unmatched_sales = (int)$db->query("
    SELECT COUNT(*) c FROM sales s
    WHERE s.txn_date BETWEEN '$date_from' AND '$date_to'
      AND s.paid_status IN ('unpaid','partial')
")->fetch_assoc()['c'];
// Distinct sales sitting in currency_review for this period.
$cnt_currency_review = (int)$db->query("
    SELECT COUNT(DISTINCT s.id) c
    FROM sales s
    JOIN receipts r ON r.matched_sale_id = s.id AND r.match_status = 'currency_review'
    WHERE s.txn_date BETWEEN '$date_from' AND '$date_to'
")->fetch_assoc()['c'];

// Aging breakdown for pending+variance only
$aging = $db->query("
    SELECT " . age_bucket_sql() . " AS bucket, COUNT(*) c, COALESCE(SUM(amount),0) total
    FROM receipts r
    WHERE r.direction='credit'
      AND r.match_status IN ('pending','variance')
      AND r.txn_date BETWEEN '$date_from' AND '$date_to'
    GROUP BY bucket
")->fetch_all(MYSQLI_ASSOC);
$aging_map = array('fresh'=>array('c'=>0,'total'=>0),'aging'=>array('c'=>0,'total'=>0),'stale'=>array('c'=>0,'total'=>0));
foreach ($aging as $a) {
    if (isset($aging_map[$a['bucket']])) $aging_map[$a['bucket']] = array('c'=>(int)$a['c'],'total'=>(float)$a['total']);
}

// Channels for filter dropdown
$channels = array('Bank POS','iPOS','EcoCash','Zimswitch','Broker');

// ── Fetch the visible tab ──────────────────────────────
$rows = array(); $total_rows = 0;
$cr_groups = array(); // currency_review tab: per-sale group with attached receipts

if ($tab === 'currency_review') {
    // Pull every sale in the period that has at least one currency_review
    // receipt, plus the receipts themselves so the UI can show the
    // breakdown the reconciler needs to approve or reject.
    $cnt_stmt = $db->prepare("
        SELECT COUNT(DISTINCT s.id) c
        FROM sales s
        JOIN receipts r ON r.matched_sale_id = s.id AND r.match_status = 'currency_review'
        WHERE s.txn_date BETWEEN ? AND ?
    ");
    $cnt_stmt->bind_param('ss', $date_from, $date_to);
    $cnt_stmt->execute();
    $total_rows = (int)$cnt_stmt->get_result()->fetch_assoc()['c'];
    $cnt_stmt->close();

    $sale_stmt = $db->prepare("
        SELECT DISTINCT s.id, s.policy_number, s.txn_date, s.amount, s.currency,
               s.payment_method, s.paid_status, a.agent_name
        FROM sales s
        JOIN agents a ON s.agent_id = a.id
        JOIN receipts r ON r.matched_sale_id = s.id AND r.match_status = 'currency_review'
        WHERE s.txn_date BETWEEN ? AND ?
        ORDER BY s.txn_date ASC, s.id ASC
        LIMIT ? OFFSET ?
    ");
    $sale_stmt->bind_param('ssii', $date_from, $date_to, $per_page, $offset);
    $sale_stmt->execute();
    $sale_rows = $sale_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sale_stmt->close();

    if (!empty($sale_rows)) {
        $sale_ids = array_map(function($r){ return (int)$r['id']; }, $sale_rows);
        $in_clause = implode(',', $sale_ids); // ints only — safe
        $rec_rows = $db->query("
            SELECT id, matched_sale_id, reference_no, txn_date, channel,
                   source_name, amount, currency
            FROM receipts
            WHERE matched_sale_id IN ($in_clause)
              AND match_status = 'currency_review'
            ORDER BY txn_date, id
        ")->fetch_all(MYSQLI_ASSOC);
        $by_sale = array();
        foreach ($rec_rows as $rr) $by_sale[(int)$rr['matched_sale_id']][] = $rr;
        foreach ($sale_rows as $sr) {
            $sid = (int)$sr['id'];
            $attached = isset($by_sale[$sid]) ? $by_sale[$sid] : array();
            $sum_zwg = 0; $sum_usd = 0;
            foreach ($attached as $a) {
                if ($a['currency'] === 'ZWG') $sum_zwg += (float)$a['amount'];
                elseif ($a['currency'] === 'USD') $sum_usd += (float)$a['amount'];
            }
            $cr_groups[] = array(
                'sale'     => $sr,
                'receipts' => $attached,
                'sum_zwg'  => $sum_zwg,
                'sum_usd'  => $sum_usd,
            );
        }
    }
} elseif ($tab === 'sales') {
    // Unmatched sales = nothing attached OR partially paid (sum < amount).
    // Currency_review sales have their own tab so we exclude them here.
    $s_where  = "s.txn_date BETWEEN ? AND ? AND s.paid_status IN ('unpaid','partial')";
    $s_params = array($date_from, $date_to);
    $s_types  = 'ss';
    if ($age === 'fresh')  $s_where .= " AND DATEDIFF(CURDATE(), s.txn_date) <= 7";
    if ($age === 'aging')  $s_where .= " AND DATEDIFF(CURDATE(), s.txn_date) BETWEEN 8 AND 30";
    if ($age === 'stale')  $s_where .= " AND DATEDIFF(CURDATE(), s.txn_date) > 30";
    if ($q !== '') {
        $s_where .= " AND (s.policy_number LIKE ? OR a.agent_name LIKE ? OR CAST(s.amount AS CHAR) LIKE ?)";
        $like = '%' . $q . '%';
        $s_params[] = $like; $s_params[] = $like; $s_params[] = $like;
        $s_types   .= 'sss';
    }
    $c_stmt = $db->prepare("SELECT COUNT(*) c FROM sales s JOIN agents a ON s.agent_id=a.id WHERE $s_where");
    $c_stmt->bind_param($s_types, ...$s_params);
    $c_stmt->execute();
    $total_rows = (int)$c_stmt->get_result()->fetch_assoc()['c'];
    $c_stmt->close();

    $s_params[] = $per_page; $s_params[] = $offset; $s_types .= 'ii';
    // Pull received-so-far per sale so partial rows can show progress
    // ("3 of 5 receipts in, $80 of $100"). Subquery scoped to allocated
    // statuses only.
    $stmt = $db->prepare("
        SELECT s.id, s.policy_number, s.txn_date, s.payment_method, s.amount, s.currency,
               s.terminal_id, s.paid_status, a.agent_name, a.id agent_id,
               DATEDIFF(CURDATE(), s.txn_date) days_old,
               (SELECT COALESCE(SUM(amount),0) FROM receipts r
                  WHERE r.matched_sale_id = s.id
                    AND r.match_status IN ('matched','partial','variance','currency_review')) AS received,
               (SELECT COUNT(*) FROM receipts r
                  WHERE r.matched_sale_id = s.id
                    AND r.match_status IN ('matched','partial','variance','currency_review')) AS rec_count
        FROM sales s
        JOIN agents a ON s.agent_id = a.id
        WHERE $s_where
        ORDER BY s.txn_date ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param($s_types, ...$s_params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    // Receipts tab (pending+variance OR excluded)
    $c_stmt = $db->prepare("SELECT COUNT(*) c FROM receipts r WHERE $r_where");
    $c_stmt->bind_param($r_types, ...$r_params);
    $c_stmt->execute();
    $total_rows = (int)$c_stmt->get_result()->fetch_assoc()['c'];
    $c_stmt->close();

    $r_params[] = $per_page; $r_params[] = $offset; $r_types .= 'ii';
    $stmt = $db->prepare("
        SELECT r.*, DATEDIFF(CURDATE(), r.txn_date) days_old,
               " . age_bucket_sql() . " AS bucket,
               eu.full_name excluded_by_name
        FROM receipts r
        LEFT JOIN users eu ON r.excluded_by = eu.id
        WHERE $r_where
        ORDER BY r.txn_date ASC, r.id ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param($r_types, ...$r_params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$total_pages = max(1, (int)ceil($total_rows / $per_page));

// Helper: preserve filter state in links
function link_with($extra) {
    $params = array_merge($_GET, $extra);
    unset($params['success'], $params['error']);
    return '?' . http_build_query($params);
}
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
      <h1>Unmatched Transactions</h1>
      <p>Review, manually match, exclude, or escalate receipts and sales that didn't auto-reconcile.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="../process/process_export_csv.php?type=unmatched&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="btn btn-ghost" style="font-weight:600"><i class="fa-solid fa-download"></i> CSV</a>
      <a class="btn btn-primary" target="_blank" href="../process/process_export.php?type=unmatched&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" style="font-weight:700"><i class="fa-solid fa-print"></i>&nbsp; Print / PDF</a>
    </div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success">✓ <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">⚠ <?= $error ?></div><?php endif; ?>

<?php if ($focus_receipt_id > 0 && !$focus_receipt): ?>
<div class="alert alert-danger">⚠ Receipt #<?= $focus_receipt_id ?> no longer exists — it may have been deleted or re-matched.</div>
<?php elseif ($focus_receipt): ?>
<div class="alert" style="background:#fff8e1;border-left:4px solid #d49a00;color:#5a4500;margin-bottom:16px">
  <strong><i class="fa-solid fa-crosshairs"></i> Showing receipt #<?= (int)$focus_receipt['id'] ?></strong> from an escalation link.
  <a href="unmatched.php" style="margin-left:12px;color:#007a3d;text-decoration:underline">← Clear focus and show all unmatched</a>
</div>
<?php endif; ?>

<!-- Filter bar -->
<form method="GET" class="panel" style="padding:12px 16px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
  <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
  <div>
    <label style="font-size:11px;color:#666;display:block">From</label>
    <input type="date" name="date_from" value="<?= $date_from ?>" class="form-input" style="padding:6px 8px">
  </div>
  <div>
    <label style="font-size:11px;color:#666;display:block">To</label>
    <input type="date" name="date_to" value="<?= $date_to ?>" class="form-input" style="padding:6px 8px">
  </div>
  <div>
    <label style="font-size:11px;color:#666;display:block">Aging</label>
    <select name="age" class="form-select" style="padding:6px 8px">
      <option value="all">All ages</option>
      <option value="fresh" <?= $age==='fresh'?'selected':'' ?>>Fresh (0–7d)</option>
      <option value="aging" <?= $age==='aging'?'selected':'' ?>>Aging (8–30d)</option>
      <option value="stale" <?= $age==='stale'?'selected':'' ?>>Stale (30+d)</option>
    </select>
  </div>
  <?php if ($tab !== 'sales' && $tab !== 'excluded' && $tab !== 'currency_review'): ?>
  <div>
    <label style="font-size:11px;color:#666;display:block">Status</label>
    <select name="status" class="form-select" style="padding:6px 8px">
      <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
      <option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option>
      <option value="variance" <?= $status==='variance'?'selected':'' ?>>Variance</option>
    </select>
  </div>
  <div>
    <label style="font-size:11px;color:#666;display:block">Channel</label>
    <select name="channel" class="form-select" style="padding:6px 8px">
      <option value="">All</option>
      <?php foreach ($channels as $c): ?>
      <option value="<?= $c ?>" <?= $channel===$c?'selected':'' ?>><?= $c ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div style="flex:1;min-width:180px">
    <label style="font-size:11px;color:#666;display:block">Search (ref / source / amount)</label>
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-input" style="padding:6px 8px" placeholder="e.g. 1500 or ABX-003">
  </div>
  <button type="submit" class="btn btn-primary">Apply</button>
  <a href="unmatched.php?tab=<?= $tab ?>" class="btn btn-ghost">Reset</a>
</form>

<!-- Outflow summary (only on debits tab) -->
<?php if ($tab === 'debits'): ?>
<div class="stat-grid">
  <div class="stat-card" style="border-left:4px solid #8a5a00">
    <div class="stat-label">Debit Rows</div>
    <div class="stat-value"><?= $cnt_debits ?></div>
    <div class="stat-sub">In selected period</div>
  </div>
  <div class="stat-card" style="border-left:4px solid #8a5a00">
    <div class="stat-label">Total Outflow</div>
    <div class="stat-value"><?= fmt_compact($sum_debits) ?></div>
    <div class="stat-sub">Fees &middot; refunds &middot; payouts</div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">Purpose</div>
    <div class="stat-sub" style="font-size:11px;line-height:1.5;margin-top:8px">
      Outflows don't match against sales — they're money leaving the float account (regulator fees, licence payments, reversals). Use this list for full balance reconciliation, not customer receipt matching.
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Aging summary cards (visible on receipts tabs only) -->
<?php if ($tab !== 'sales' && $tab !== 'excluded' && $tab !== 'debits' && $tab !== 'currency_review'): ?>
<div class="stat-grid">
  <div class="stat-card green">
    <div class="stat-label">Fresh (0–7 days)</div>
    <div class="stat-value"><?= $aging_map['fresh']['c'] ?></div>
    <div class="stat-sub">Total <?= number_format($aging_map['fresh']['total']) ?></div>
  </div>
  <div class="stat-card warn">
    <div class="stat-label">Aging (8–30 days)</div>
    <div class="stat-value"><?= $aging_map['aging']['c'] ?></div>
    <div class="stat-sub">Total <?= number_format($aging_map['aging']['total']) ?></div>
  </div>
  <div class="stat-card red">
    <div class="stat-label">Stale (30+ days)</div>
    <div class="stat-value"><?= $aging_map['stale']['c'] ?></div>
    <div class="stat-sub">Total <?= number_format($aging_map['stale']['total']) ?></div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">Bulk Auto-Match</div>
    <form method="POST" action="../process/process_unmatched.php" style="display:flex;gap:4px;margin-top:8px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_accept">
      <input type="hidden" name="date_from" value="<?= $date_from ?>">
      <input type="hidden" name="date_to" value="<?= $date_to ?>">
      <input type="number" name="threshold" value="85" min="50" max="100" style="width:60px;padding:4px" class="form-input">
      <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Auto-accept all suggestions at or above this score?')">Run</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="tab-bar" style="margin-top:16px">
  <a class="tab-item <?= $tab==='receipts'?'active':'' ?>" href="<?= link_with(array('tab'=>'receipts','page'=>1)) ?>">Unmatched Receipts (<?= $cnt_pending + $cnt_variance ?>)</a>
  <a class="tab-item <?= $tab==='sales'?'active':'' ?>"    href="<?= link_with(array('tab'=>'sales','page'=>1)) ?>">Unmatched Sales (<?= $cnt_unmatched_sales ?>)</a>
  <a class="tab-item <?= $tab==='currency_review'?'active':'' ?>" href="<?= link_with(array('tab'=>'currency_review','page'=>1)) ?>">Currency Review (<?= $cnt_currency_review ?>)</a>
  <a class="tab-item <?= $tab==='excluded'?'active':'' ?>" href="<?= link_with(array('tab'=>'excluded','page'=>1)) ?>">Excluded (<?= $cnt_excluded ?>)</a>
  <a class="tab-item <?= $tab==='debits'?'active':'' ?>"   href="<?= link_with(array('tab'=>'debits','page'=>1)) ?>">Debits / Outflows (<?= $cnt_debits ?>)</a>
</div>

<!-- Table -->
<div class="panel">
<?php if ($tab === 'currency_review'): ?>
  <div style="padding:14px 18px;background:#fff8e1;border-bottom:1px solid #f0e0a0;font-size:12px;color:#5a4500">
    <strong>Currency review queue.</strong> Each card below is one sale with receipts attached in a different currency. Approve to count them as covered (uses the sale's currency for variance), or reject to send the receipts back to the unmatched pool.
  </div>
  <?php if (empty($cr_groups)): ?>
    <div class="dim" style="text-align:center;padding:24px">✓ No currency-review items in this period.</div>
  <?php else: foreach ($cr_groups as $g):
        $sale = $g['sale']; $sid = (int)$sale['id'];
  ?>
    <div style="padding:14px 18px;border-bottom:1px solid #eee">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div>
          <div class="mono" style="font-weight:600;color:var(--accent2)"><?= htmlspecialchars($sale['policy_number']) ?></div>
          <div class="dim" style="font-size:11px;margin-top:2px">
            <?= htmlspecialchars($sale['agent_name']) ?> &middot; <?= htmlspecialchars($sale['payment_method']) ?> &middot; <?= $sale['txn_date'] ?>
          </div>
        </div>
        <div style="text-align:right">
          <div class="mono" style="font-weight:600">Sale: <?= htmlspecialchars($sale['currency']) ?> <?= number_format($sale['amount'], 2) ?></div>
          <div class="mono dim" style="font-size:11px">Received: ZWG <?= number_format($g['sum_zwg'], 2) ?> &middot; USD <?= number_format($g['sum_usd'], 2) ?></div>
        </div>
      </div>
      <table class="data-table" style="margin-top:10px">
        <thead><tr><th>Reference</th><th>Date</th><th>Channel</th><th>Source</th><th>Amount</th></tr></thead>
        <tbody>
        <?php foreach ($g['receipts'] as $rr): ?>
          <tr>
            <td class="mono" style="font-size:11px"><?= htmlspecialchars($rr['reference_no']) ?></td>
            <td class="mono dim" style="font-size:11px"><?= $rr['txn_date'] ?></td>
            <td class="dim"><?= htmlspecialchars($rr['channel']) ?></td>
            <td class="dim" style="font-size:11px"><?= htmlspecialchars($rr['source_name']) ?></td>
            <td class="mono <?= $rr['currency']!==$sale['currency']?'':'dim' ?>" style="font-weight:<?= $rr['currency']!==$sale['currency']?'600':'400' ?>">
              <?= htmlspecialchars($rr['currency']) ?> <?= number_format($rr['amount'], 2) ?>
              <?php if ($rr['currency']!==$sale['currency']): ?>
                <span class="badge variance" style="margin-left:6px">FX</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="POST" action="../process/process_unmatched.php" style="display:flex;gap:8px;margin-top:10px;align-items:center">
        <?= csrf_field() ?>
        <input type="hidden" name="sale_id" value="<?= $sid ?>">
        <input type="text" name="note" placeholder="Optional note (FX rate, source...)" class="form-input" style="flex:1;padding:6px 8px;font-size:12px" maxlength="500">
        <button type="submit" name="action" value="currency_review_approve" class="btn btn-primary btn-sm" onclick="return confirm('Approve this allocation? Receipts will count toward sale coverage.')"><i class="fa-solid fa-check"></i> Approve</button>
        <button type="submit" name="action" value="currency_review_reject" class="btn btn-ghost btn-sm" onclick="return confirm('Reject? Receipts will be detached and sent back to pending.')"><i class="fa-solid fa-xmark"></i> Reject</button>
      </form>
    </div>
  <?php endforeach; endif; ?>
<?php elseif ($tab === 'sales'): ?>
  <table class="data-table">
    <thead><tr><th>Policy #</th><th>Date</th><th>Method</th><th>Agent</th><th>Amount</th><th>Coverage</th><th>Age</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <?php
        $bucket = $r['days_old'] <= 7 ? 'fresh' : ($r['days_old'] <= 30 ? 'aging' : 'stale');
        $bucket_class = $bucket === 'fresh' ? 'reconciled' : ($bucket === 'aging' ? 'pending' : 'variance');
        $is_partial = $r['paid_status'] === 'partial';
        $rec_count  = (int)($r['rec_count'] ?? 0);
        $received   = (float)($r['received'] ?? 0);
        $pct = ($r['amount'] > 0) ? min(100, round(($received / $r['amount']) * 100)) : 0;
      ?>
      <tr>
        <td class="mono" style="color:var(--accent2)"><?= htmlspecialchars($r['policy_number']) ?></td>
        <td class="mono dim"><?= $r['txn_date'] ?></td>
        <td><?= htmlspecialchars($r['payment_method']) ?></td>
        <td><?= htmlspecialchars($r['agent_name']) ?></td>
        <td class="mono"><?= htmlspecialchars($r['currency']) ?> <?= number_format($r['amount'], 2) ?></td>
        <td>
          <?php if ($is_partial): ?>
            <span class="badge variance">PARTIAL</span>
            <div class="dim" style="font-size:10px;margin-top:2px"><?= $rec_count ?>/10 receipts &middot; <?= number_format($received, 2) ?> (<?= $pct ?>%)</div>
          <?php else: ?>
            <span class="badge pending">UNPAID</span>
            <div class="dim" style="font-size:10px;margin-top:2px">No receipts attached</div>
          <?php endif; ?>
        </td>
        <td><span class="badge <?= $bucket_class ?>"><?= $r['days_old'] ?>d</span></td>
        <td>
          <a class="btn btn-ghost btn-sm" href="agent_detail.php?id=<?= (int)$r['agent_id'] ?>">Agent</a>
          <a class="btn btn-ghost btn-sm" href="../modules/reconciliation.php?agent_id=<?= (int)$r['agent_id'] ?>">Reconcile</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
      <tr><td colspan="8" class="dim" style="text-align:center;padding:20px">✓ No unmatched sales in this range.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
<?php else: ?>
  <!-- Bulk action bar (hidden until selections made) -->
  <?php if ($tab === 'receipts'): ?>
  <div id="bulk-bar" style="display:none;background:#007a3d;color:#fff;padding:10px 16px;border-radius:4px 4px 0 0;display:none;align-items:center;gap:12px;font-size:13px">
    <strong><span id="bulk-count">0</span> selected</strong>
    <button class="btn btn-sm" style="background:#fff;color:#007a3d;font-weight:600" onclick="bulkExclude()"><i class="fa-solid fa-ban"></i> Bulk Exclude</button>
    <button class="btn btn-sm" style="background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,0.4)" onclick="clearBulkSelection()">Clear</button>
  </div>
  <?php endif; ?>
  <table class="data-table" id="unmatched-table">
    <thead>
      <tr>
        <?php if ($tab === 'receipts'): ?><th style="width:30px"><input type="checkbox" id="select-all" onchange="toggleSelectAll(this)"></th><?php endif; ?>
        <th>Reference</th><th>Date</th><th>Channel</th><th>Source</th><th>Amount</th>
        <th>Age</th><th>Status</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <?php
        $bucket = $r['bucket'];
        $bucket_class = $bucket === 'fresh' ? 'reconciled' : ($bucket === 'aging' ? 'pending' : 'variance');
      ?>
      <tr>
        <?php if ($tab === 'receipts'): ?><td><input type="checkbox" class="bulk-cb" value="<?= (int)$r['id'] ?>" onchange="updateBulkBar()"></td><?php endif; ?>
        <td class="mono" style="color:var(--accent2);font-size:11px"><?= htmlspecialchars($r['reference_no']) ?></td>
        <td class="mono dim" style="font-size:11px"><?= $r['txn_date'] ?></td>
        <td class="dim"><?= htmlspecialchars($r['channel']) ?></td>
        <td class="dim" style="font-size:11px"><?= htmlspecialchars($r['source_name']) ?></td>
        <td class="mono" style="font-weight:500"><?= htmlspecialchars($r['currency']) ?> <?= number_format($r['amount'], 2) ?></td>
        <td><span class="badge <?= $bucket_class ?>"><?= (int)$r['days_old'] ?>d</span></td>
        <td>
          <?php if ($tab === 'debits'): ?>
            <span class="badge" style="background:#f4e3c3;color:#8a5a00">DEBIT</span>
            <div class="dim" style="font-size:10px;margin-top:2px">Float outflow</div>
          <?php elseif ($tab === 'excluded'): ?>
            <span class="badge variance"><?= htmlspecialchars($r['exclude_reason']) ?></span>
            <div class="dim" style="font-size:10px;margin-top:2px" title="<?= htmlspecialchars($r['exclude_note']) ?>">by <?= htmlspecialchars($r['excluded_by_name'] ?? '—') ?></div>
          <?php else: ?>
            <span class="badge <?= $r['match_status']==='pending'?'pending':'variance' ?>"><?= strtoupper($r['match_status']) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($tab === 'debits'): ?>
            <span class="dim" style="font-size:11px">—</span>
          <?php elseif ($tab !== 'excluded'): ?>
          <button class="btn btn-primary btn-sm" onclick="openMatchModal(<?= $r['id'] ?>, '<?= addslashes($r['reference_no']) ?>', <?= $r['amount'] ?>, '<?= $r['currency'] ?>', '<?= addslashes($r['txn_date']) ?>')"><i class="fa-solid fa-wand-magic-sparkles"></i> Match</button>
          <button class="btn btn-ghost btn-sm" onclick="openExcludeModal(<?= $r['id'] ?>, '<?= addslashes($r['reference_no']) ?>')">Exclude</button>
          <button class="btn btn-ghost btn-sm" onclick="openEscalateModal(<?= $r['id'] ?>, '<?= addslashes($r['reference_no']) ?>')">Escalate</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
      <tr><td colspan="8" class="dim" style="text-align:center;padding:20px">✓ Nothing to review in this tab.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
<?php endif; ?>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <div style="padding:12px 16px;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#666">
    <span>Page <?= $page ?> of <?= $total_pages ?> — <?= $total_rows ?> total</span>
    <div style="display:flex;gap:4px">
      <?php if ($page > 1): ?>
      <a class="btn btn-ghost btn-sm" href="<?= link_with(array('page'=>$page-1)) ?>">← Prev</a>
      <?php endif; ?>
      <?php if ($page < $total_pages): ?>
      <a class="btn btn-ghost btn-sm" href="<?= link_with(array('page'=>$page+1)) ?>">Next →</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ══ SMART MATCH MODAL ══ -->
<div id="match-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:720px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18)">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Match Receipt to Sale</span>
      <button onclick="document.getElementById('match-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <div style="padding:20px">
      <div style="background:#f5f5f5;padding:14px;border-radius:4px;margin-bottom:16px">
        <div style="font-size:11px;color:#888;font-weight:600;margin-bottom:4px">RECEIPT</div>
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
          <div>
            <strong id="match_ref" style="font-family:monospace"></strong>
            <div style="font-size:11px;color:#666;margin-top:2px">
              <i class="fa-regular fa-calendar"></i>
              Paid on <span id="match_date" style="font-weight:600;color:#333"></span>
            </div>
          </div>
          <div><strong id="match_amt" style="font-family:monospace"></strong></div>
        </div>
      </div>

      <div id="suggestions" style="margin-bottom:16px">
        <em class="dim">Loading smart suggestions…</em>
      </div>

      <form method="POST" action="../process/process_unmatched.php" id="match-form">
      <?= csrf_field() ?>
        <input type="hidden" name="action" value="manual_match">
        <input type="hidden" name="receipt_id" id="match_receipt_id">
        <input type="hidden" name="sales_id"   id="match_sales_id">
        <div class="form-group">
          <label class="form-label">Match Reason</label>
          <select name="reason" class="form-select" required>
            <option value="">-- Select --</option>
            <option>Amount &amp; Date Match</option>
            <option>Reference Number Verified</option>
            <option>Agent Confirmation</option>
            <option>Manual Verification</option>
            <option>Bank Processing Delay</option>
            <option>Other</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Comments (optional)</label>
          <textarea name="comments" class="form-input" style="height:60px;resize:vertical"></textarea>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary" id="match-submit-btn" disabled><i class="fa-solid fa-check"></i> Confirm Match</button>
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('match-modal').style.display='none'">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ EXCLUDE MODAL ══ -->
<div id="exclude-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:460px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Exclude Receipt</span>
      <button onclick="document.getElementById('exclude-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_unmatched.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="exclude">
      <input type="hidden" name="receipt_id" id="exclude_receipt_id">
      <p>Mark <strong id="exclude_ref"></strong> as excluded. It will stop showing on the main list.</p>
      <div class="form-group">
        <label class="form-label">Reason</label>
        <select name="exclude_reason" class="form-select" required>
          <option value="duplicate">Duplicate of another receipt</option>
          <option value="refund">Refund / reversal</option>
          <option value="bank_error">Bank error / wrong routing</option>
          <option value="write_off">Write-off (unrecoverable)</option>
          <option value="other">Other (explain below)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Note (required)</label>
        <textarea name="exclude_note" class="form-input" style="height:80px" required minlength="5"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Exclude</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('exclude-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ ESCALATE MODAL ══ -->
<div id="escalate-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:460px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Escalate to Manager</span>
      <button onclick="document.getElementById('escalate-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_unmatched.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="escalate">
      <input type="hidden" name="receipt_id" id="escalate_receipt_id">
      <p>Escalate <strong id="escalate_ref"></strong> for manager review.</p>
      <div class="form-group">
        <label class="form-label">Priority</label>
        <select name="priority" class="form-select">
          <option value="low">Low</option>
          <option value="medium" selected>Medium</option>
          <option value="high">High</option>
          <option value="critical">Critical</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Note (required)</label>
        <textarea name="note" class="form-input" style="height:80px" required minlength="5" placeholder="What have you checked? What do you suspect?"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Escalate</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('escalate-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<style>
.suggestion-row { display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border:1px solid #e0e0e0; border-radius:4px; margin-bottom:6px; cursor:pointer; transition:all 0.15s }
.suggestion-row:hover { border-color:var(--green,#00a950); background:#f5fbf7 }
.suggestion-row.selected { border-color:var(--green-dark,#007a3d); background:#eaf7ef; border-width:2px }
.conf-high { color:#00a950; font-weight:700 }
.conf-med  { color:#d49a00; font-weight:700 }
.conf-low  { color:#c0392b; font-weight:700 }
.reason-tag { display:inline-block; background:#f0f4f1; color:#555; padding:1px 6px; border-radius:2px; font-size:10px; margin-right:3px }
</style>

<script>
function openMatchModal(receiptId, ref, amount, currency, txnDate) {
  document.getElementById('match_receipt_id').value = receiptId;
  document.getElementById('match_ref').textContent = ref;
  document.getElementById('match_amt').textContent = currency + ' ' + parseFloat(amount).toLocaleString(undefined, {minimumFractionDigits:2});
  document.getElementById('match_date').textContent = formatReceiptDate(txnDate);
  document.getElementById('match_sales_id').value = '';
  document.getElementById('match-submit-btn').disabled = true;
  document.getElementById('suggestions').innerHTML = '<em class="dim">Loading smart suggestions…</em>';
  document.getElementById('match-modal').style.display = 'flex';

  fetch('../process/process_unmatched.php?action=suggest&receipt_id=' + receiptId)
    .then(r => r.json())
    .then(function (data) { renderSuggestions(data, txnDate); })
    .catch(e => { document.getElementById('suggestions').innerHTML = '<div style="color:#c0392b">Failed: ' + e + '</div>'; });
}

// Formats "2026-03-15" → "Sun, 15 Mar 2026" so the user sees both
// the day-of-week (helps spot weekend-vs-weekday mistakes) and the
// human month name. Falls back to the raw string if parsing fails.
function formatReceiptDate(d) {
  if (!d) return '—';
  var dt = new Date(d + 'T00:00:00');
  if (isNaN(dt.getTime())) return d;
  return dt.toLocaleDateString('en-GB', {
    weekday: 'short', day: '2-digit', month: 'short', year: 'numeric'
  });
}

// Returns the day-gap between the receipt date and a candidate sale date.
// Used to colour-code suggestions: same-day matches are strongest.
function dayDiff(a, b) {
  if (!a || !b) return null;
  var da = new Date(a + 'T00:00:00');
  var db = new Date(b + 'T00:00:00');
  if (isNaN(da.getTime()) || isNaN(db.getTime())) return null;
  return Math.round((db - da) / (1000 * 60 * 60 * 24));
}

function renderSuggestions(data, receiptDate) {
  const box = document.getElementById('suggestions');
  if (!data.candidates || data.candidates.length === 0) {
    box.innerHTML = '<div class="dim">No candidate sales found within ±7 days and ±5% amount. Try manual entry via another tool.</div>';
    return;
  }
  let html = '<div style="font-size:11px;color:#666;font-weight:600;margin-bottom:6px">TOP SUGGESTIONS (click to select)</div>';
  data.candidates.forEach((c, i) => {
    const score = c.confidence_score;
    const cls = score >= 80 ? 'conf-high' : (score >= 50 ? 'conf-med' : 'conf-low');
    const reasons = (c.match_reasons || []).map(r => '<span class="reason-tag">' + r + '</span>').join('');

    // Day-gap badge: shows how far off the sale date is from the
    // receipt date. Same-day = green, ±1-2 days = amber, more = red.
    var gap = dayDiff(receiptDate, c.txn_date);
    var gapHtml = '';
    if (gap !== null) {
      var gapTxt;
      if (gap === 0)         gapTxt = 'same day';
      else if (gap === 1)    gapTxt = '+1 day';
      else if (gap === -1)   gapTxt = '-1 day';
      else if (gap > 0)      gapTxt = '+' + gap + ' days';
      else                   gapTxt = gap + ' days';
      var gapColor = (gap === 0) ? '#1e7e34'
                   : (Math.abs(gap) <= 2) ? '#d49a00'
                   : '#c0392b';
      gapHtml = '<span style="display:inline-block;background:#fff;border:1px solid '
              + gapColor + ';color:' + gapColor
              + ';padding:1px 6px;border-radius:2px;font-size:10px;font-weight:600;margin-left:6px">'
              + gapTxt + '</span>';
    }

    html += '<div class="suggestion-row" data-id="' + c.id + '" onclick="selectSuggestion(this,' + c.id + ')">'
         +  '  <div style="flex:1">'
         +  '    <div style="font-family:monospace;font-weight:600">' + c.policy_number + '</div>'
         +  '    <div style="font-size:11px;color:#666">' + c.agent_name + ' · ' + c.payment_method + ' · ' + c.txn_date + gapHtml + '</div>'
         +  '    <div style="margin-top:4px">' + reasons + '</div>'
         +  '  </div>'
         +  '  <div style="text-align:right">'
         +  '    <div style="font-family:monospace;font-weight:600">' + c.currency + ' ' + parseFloat(c.amount).toLocaleString(undefined, {minimumFractionDigits:2}) + '</div>'
         +  '    <div class="' + cls + '">Score ' + score + '</div>'
         +  '  </div>'
         +  '</div>';
  });
  box.innerHTML = html;
}

function selectSuggestion(el, saleId) {
  document.querySelectorAll('.suggestion-row').forEach(r => r.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('match_sales_id').value = saleId;
  document.getElementById('match-submit-btn').disabled = false;
}

function openExcludeModal(receiptId, ref) {
  document.getElementById('exclude_receipt_id').value = receiptId;
  document.getElementById('exclude_ref').textContent = ref;
  document.getElementById('exclude-modal').style.display = 'flex';
}
function openEscalateModal(receiptId, ref) {
  document.getElementById('escalate_receipt_id').value = receiptId;
  document.getElementById('escalate_ref').textContent = ref;
  document.getElementById('escalate-modal').style.display = 'flex';
}
['match-modal','exclude-modal','escalate-modal'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
  });
});

// ── Bulk actions ──────────────────────────────
function toggleSelectAll(el) {
  document.querySelectorAll('.bulk-cb').forEach(function(cb) { cb.checked = el.checked; });
  updateBulkBar();
}
function updateBulkBar() {
  var checked = document.querySelectorAll('.bulk-cb:checked');
  var bar = document.getElementById('bulk-bar');
  if (!bar) return;
  document.getElementById('bulk-count').textContent = checked.length;
  bar.style.display = checked.length > 0 ? 'flex' : 'none';
}
function clearBulkSelection() {
  document.querySelectorAll('.bulk-cb').forEach(function(cb) { cb.checked = false; });
  var sa = document.getElementById('select-all');
  if (sa) sa.checked = false;
  updateBulkBar();
}
function bulkExclude() {
  var ids = [];
  document.querySelectorAll('.bulk-cb:checked').forEach(function(cb) { ids.push(cb.value); });
  if (ids.length === 0) return;
  var reason = prompt('Exclude reason for ' + ids.length + ' receipts (write_off / duplicate / bank_error / refund):', 'write_off');
  if (!reason) return;
  var note = prompt('Brief note (min 5 chars):', 'Bulk excluded');
  if (!note || note.length < 5) { alert('Note must be at least 5 characters.'); return; }
  // Submit via a hidden form
  var form = document.createElement('form');
  form.method = 'POST';
  form.action = '../process/process_unmatched.php';
  form.innerHTML = '<?= csrf_field() ?>'
    + '<input name="action" value="bulk_exclude">'
    + '<input name="receipt_ids" value="' + ids.join(',') + '">'
    + '<input name="exclude_reason" value="' + reason + '">'
    + '<input name="exclude_note" value="' + note + '">'
    + '<input name="date_from" value="<?= $date_from ?>">'
    + '<input name="date_to" value="<?= $date_to ?>">';
  document.body.appendChild(form);
  form.submit();
}
</script>

<?php require_once '../layouts/layout_footer.php'; ?>