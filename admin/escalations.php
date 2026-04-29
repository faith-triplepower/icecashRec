<?php
// ============================================================
// admin/escalations.php
// Manager queue for reviewing, assigning, and resolving escalations.
// ============================================================
$page_title = 'Escalations';
$active_nav = 'escalations';
require_once '../layouts/layout_header.php';
require_role(['Manager']);

$db   = get_db();
$user = current_user();
$uid  = (int)$user['id'];

$success = htmlspecialchars($_GET['success'] ?? '');
$error   = htmlspecialchars($_GET['error']   ?? '');

// Filters — whitelist enum values defensively. $filter_assigned can also
// be a numeric user id, so allow integers in addition to the named modes.
$filter_status   = in_array($_GET['filter']   ?? '', array('pending','reviewed','resolved','dismissed','all'), true) ? $_GET['filter']   : 'pending';
$raw_priority    = $_GET['priority'] ?? '';
$filter_priority = in_array($raw_priority, ['','low','medium','high','critical'], true)
                    ? $raw_priority : '';

$raw_type        = $_GET['type'] ?? '';
$filter_type     = in_array($raw_type, ['','variance','unmatched','currency_mismatch','manual'], true)
                    ? $raw_type : '';
$raw_assigned    = $_GET['assigned'] ?? 'mine';
$filter_assigned = (in_array($raw_assigned, array('mine','all','unassigned'), true) || ctype_digit((string)$raw_assigned))
                    ? $raw_assigned : 'mine';
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

// Build WHERE clause
$where  = array();
$params = array();
$types  = '';

if ($filter_assigned === 'mine') {
    // Admins supervise everyone — "my queue" = the whole queue for them.
    // Managers see what's assigned to them plus anything unassigned.
    if ($user['role'] !== 'Admin') {
        $where[] = "(e.assigned_to = ? OR e.assigned_to IS NULL)";
        $params[] = $uid;
        $types   .= 'i';
    }
} elseif ($filter_assigned === 'unassigned') {
    $where[] = "e.assigned_to IS NULL";
} elseif ($filter_assigned === 'all') {
    // no filter
} elseif (is_numeric($filter_assigned)) {
    $where[] = "e.assigned_to = ?";
    $params[] = (int)$filter_assigned;
    $types   .= 'i';
}

if ($filter_status !== 'all') {
    $where[] = "e.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}
if ($filter_priority && in_array($filter_priority, array('low','medium','high','critical'))) {
    $where[] = "e.priority = ?";
    $params[] = $filter_priority;
    $types   .= 's';
}
if ($filter_type && in_array($filter_type, array('variance','unmatched','currency_mismatch','manual'))) {
    $where[] = "e.action_type = ?";
    $params[] = $filter_type;
    $types   .= 's';
}

$where_sql = empty($where) ? '1=1' : implode(' AND ', $where);

// Fetch
$sql = "
    SELECT e.id, e.run_id, e.agent_id, e.action_type, e.action_detail,
           e.affected_entity, e.entity_id, e.status, e.priority,
           e.variance_zwg, e.variance_usd, e.created_at, e.review_note,
           e.reviewed_at, e.assigned_to,
           u.full_name  AS submitted_by,
           rm.full_name AS reviewed_by_name,
           as_u.full_name AS assigned_name,
           a.agent_name
    FROM escalations e
    JOIN users u       ON e.user_id = u.id
    LEFT JOIN users rm ON e.reviewed_by = rm.id
    LEFT JOIN users as_u ON e.assigned_to = as_u.id
    LEFT JOIN agents a ON e.agent_id = a.id
    WHERE $where_sql
    ORDER BY FIELD(e.priority,'critical','high','medium','low'), e.created_at DESC
    LIMIT $per_page OFFSET $offset
";
$stmt = $db->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$escalations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Status counts (respect assignment filter only)
$counts = array();
$count_where = '1=1';
$count_params = array();
$count_types = '';
if ($filter_assigned === 'mine') {
    if ($user['role'] !== 'Admin') {
        $count_where .= " AND (assigned_to = ? OR assigned_to IS NULL)";
        $count_params[] = $uid;
        $count_types   .= 'i';
    }
} elseif ($filter_assigned === 'unassigned') {
    $count_where .= " AND assigned_to IS NULL";
} elseif (is_numeric($filter_assigned)) {
    $count_where .= " AND assigned_to = ?";
    $count_params[] = (int)$filter_assigned;
    $count_types   .= 'i';
}
foreach (array('pending','reviewed','resolved','dismissed') as $s) {
    $c_stmt = $db->prepare("SELECT COUNT(*) c FROM escalations WHERE $count_where AND status = ?");
    $c_params = $count_params;
    $c_params[] = $s;
    $c_types = $count_types . 's';
    $c_stmt->bind_param($c_types, ...$c_params);
    $c_stmt->execute();
    $counts[$s] = (int)$c_stmt->get_result()->fetch_assoc()['c'];
    $c_stmt->close();
}

// Managers for assignment dropdown — Admins are excluded from the
// escalation flow entirely; escalations are a Manager-only workspace.
$managers = $db->query("SELECT id, full_name, role FROM users WHERE role='Manager' AND is_active=1 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Helper: link for the affected entity
function entity_link($e) {
    if (empty($e['affected_entity']) || empty($e['entity_id'])) return null;
    switch ($e['affected_entity']) {
        case 'agent':   return '../admin/agent_detail.php?id=' . (int)$e['entity_id'];
        case 'receipt': return '../admin/unmatched.php?receipt_id=' . (int)$e['entity_id'];
        case 'sale':    return '../modules/variance.php?run_id=' . (int)$e['run_id'];
        default:        return null;
    }
}

function link_with($extra = array()) {
    $p = array_merge($_GET, $extra);
    unset($p['success'], $p['error']);
    return '?' . http_build_query($p);
}
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
      <h1>Escalations &amp; Reviews</h1>
      <p>Review, assign, resolve, or dismiss escalations raised by reconcilers and the system.</p>
    </div>
    <a href="../process/process_export_csv.php?type=escalations" class="btn btn-ghost" style="font-weight:600"><i class="fa-solid fa-download"></i> Export CSV</a>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success">✓ <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">⚠ <?= $error ?></div><?php endif; ?>

<!-- Status pills -->
<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
  <a href="<?= link_with(array('filter'=>'pending')) ?>"   class="btn <?= $filter_status==='pending'?'btn-primary':'btn-ghost' ?>">⏳ Pending (<?= $counts['pending'] ?>)</a>
  <a href="<?= link_with(array('filter'=>'reviewed')) ?>"  class="btn <?= $filter_status==='reviewed'?'btn-primary':'btn-ghost' ?>">✓ Reviewed (<?= $counts['reviewed'] ?>)</a>
  <a href="<?= link_with(array('filter'=>'resolved')) ?>"  class="btn <?= $filter_status==='resolved'?'btn-primary':'btn-ghost' ?>">✓ Resolved (<?= $counts['resolved'] ?>)</a>
  <a href="<?= link_with(array('filter'=>'dismissed')) ?>" class="btn <?= $filter_status==='dismissed'?'btn-primary':'btn-ghost' ?>">× Dismissed (<?= $counts['dismissed'] ?>)</a>
  <a href="<?= link_with(array('filter'=>'all')) ?>"       class="btn <?= $filter_status==='all'?'btn-primary':'btn-ghost' ?>">📋 All (<?= array_sum($counts) ?>)</a>
</div>

<!-- Secondary filters -->
<form method="GET" class="panel" style="padding:10px 14px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
  <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_status) ?>">
  <div>
    <label class="form-label" style="font-size:11px">Assigned</label>
    <select name="assigned" class="form-select" style="width:auto">
      <option value="mine"       <?= $filter_assigned==='mine'?'selected':'' ?>>My queue (+ unassigned)</option>
      <option value="unassigned" <?= $filter_assigned==='unassigned'?'selected':'' ?>>Unassigned only</option>
      <option value="all"        <?= $filter_assigned==='all'?'selected':'' ?>>All managers</option>
      <?php foreach ($managers as $m): ?>
      <option value="<?= (int)$m['id'] ?>" <?= $filter_assigned == (string)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label" style="font-size:11px">Priority</label>
    <select name="priority" class="form-select" style="width:auto">
      <option value="">All</option>
      <option value="critical" <?= $filter_priority==='critical'?'selected':'' ?>>Critical</option>
      <option value="high"     <?= $filter_priority==='high'?'selected':'' ?>>High</option>
      <option value="medium"   <?= $filter_priority==='medium'?'selected':'' ?>>Medium</option>
      <option value="low"      <?= $filter_priority==='low'?'selected':'' ?>>Low</option>
    </select>
  </div>
  <div>
    <label class="form-label" style="font-size:11px">Type</label>
    <select name="type" class="form-select" style="width:auto">
      <option value="">All</option>
      <option value="variance"          <?= $filter_type==='variance'?'selected':'' ?>>Variance</option>
      <option value="unmatched"         <?= $filter_type==='unmatched'?'selected':'' ?>>Unmatched</option>
      <option value="currency_mismatch" <?= $filter_type==='currency_mismatch'?'selected':'' ?>>Currency Mismatch</option>
      <option value="manual"            <?= $filter_type==='manual'?'selected':'' ?>>Manual</option>
    </select>
  </div>
  <button type="submit" class="btn btn-primary">Apply</button>
  <a href="escalations.php?filter=<?= htmlspecialchars($filter_status) ?>" class="btn btn-ghost">Reset</a>
</form>

<div class="panel">
  <table class="data-table">
    <thead>
      <tr>
        <th style="width:22px"></th>
        <th>#</th>
        <th>Priority</th>
        <th>Type</th>
        <th>Agent</th>
        <th>Detail</th>
        <th>Variance</th>
        <th>Assigned</th>
        <th>Submitted</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($escalations as $e): ?>
      <?php
        $prio_class = array(
            'critical' => 'variance',
            'high'     => 'variance',
            'medium'   => 'pending',
            'low'      => 'matched',
        );
        $status_class = array(
            'pending'   => 'pending',
            'reviewed'  => 'active',
            'resolved'  => 'reconciled',
            'dismissed' => 'inactive',
        );
        $entity_url = entity_link($e);
      ?>
      <tr class="esc-row" style="cursor:pointer" onclick="toggleEscDetail(this)">
        <td class="expand-toggle" style="text-align:center;color:#888">▸</td>
        <td class="mono">#<?= (int)$e['id'] ?></td>
        <td><span class="badge <?= $prio_class[$e['priority']] ?? 'matched' ?>" style="<?= $e['priority']==='critical'?'font-weight:800':'' ?>"><?= strtoupper($e['priority']) ?></span></td>
        <td style="font-size:11px"><?= htmlspecialchars(str_replace('_',' ',$e['action_type'])) ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($e['agent_name'] ?? '—') ?></td>
        <td style="font-size:11px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($e['action_detail']) ?></td>
        <td class="mono" style="font-size:11px">
          <?php if ($e['variance_zwg'] || $e['variance_usd']): ?>
          ZWG <?= number_format($e['variance_zwg'] ?? 0, 0) ?><br>
          <span class="dim">USD <?= number_format($e['variance_usd'] ?? 0, 0) ?></span>
          <?php else: ?>
          —
          <?php endif; ?>
        </td>
        <td style="font-size:11px">
          <?php if ($e['assigned_name']): ?>
            <?= htmlspecialchars($e['assigned_name']) ?>
          <?php else: ?>
            <span class="dim">Unassigned</span>
          <?php endif; ?>
        </td>
        <td class="mono dim" style="font-size:11px"><?= date('M d, H:i', strtotime($e['created_at'])) ?><br><?= htmlspecialchars($e['submitted_by']) ?></td>
        <td><span class="badge <?= $status_class[$e['status']] ?? 'pending' ?>"><?= strtoupper($e['status']) ?></span></td>
        <td onclick="event.stopPropagation()">
          <div style="display:flex;gap:4px;flex-wrap:wrap">
            <?php if ($e['status'] === 'pending'): ?>
              <button class="btn btn-primary btn-sm" onclick="openReview(<?= $e['id'] ?>)">Review</button>
              <button class="btn btn-ghost btn-sm" style="color:#888" onclick="openDismiss(<?= $e['id'] ?>)">Dismiss</button>
            <?php elseif ($e['status'] === 'reviewed'): ?>
              <button class="btn btn-primary btn-sm" style="background:#00a950;border-color:#00a950" onclick="openResolve(<?= $e['id'] ?>)">Resolve</button>
              <button class="btn btn-ghost btn-sm" style="color:#888" onclick="openDismiss(<?= $e['id'] ?>)">Dismiss</button>
            <?php else: ?>
              <span class="dim" style="font-size:11px">—</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <tr class="esc-detail-row" style="display:none">
        <td colspan="11" style="background:#fafbfc;padding:0">
          <div style="padding:16px 24px">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 20px;font-size:12px;margin-bottom:12px">
              <div><span class="dim">Escalation:</span> <strong>#<?= (int)$e['id'] ?></strong></div>
              <div><span class="dim">Submitted by:</span> <strong><?= htmlspecialchars($e['submitted_by']) ?></strong></div>
              <div><span class="dim">Run ID:</span> <strong><?= $e['run_id'] ? '#'.(int)$e['run_id'] : '—' ?></strong></div>
              <div><span class="dim">Agent:</span> <strong><?= htmlspecialchars($e['agent_name'] ?? '—') ?></strong></div>
              <div><span class="dim">Entity:</span> <strong><?= htmlspecialchars($e['affected_entity'] ?? '—') ?> #<?= htmlspecialchars($e['entity_id'] ?? '—') ?></strong></div>
              <div><span class="dim">Reviewed by:</span> <strong><?= htmlspecialchars($e['reviewed_by_name'] ?? '—') ?></strong></div>
            </div>
            <div style="font-size:12px;margin-bottom:10px">
              <strong class="dim">Full detail:</strong>
              <div style="padding:8px 12px;background:#fff;border:1px solid #eee;border-radius:4px;margin-top:4px"><?= htmlspecialchars($e['action_detail']) ?></div>
            </div>
            <?php if ($e['review_note']): ?>
            <div style="font-size:12px">
              <strong class="dim">Notes &amp; history:</strong>
              <div style="padding:8px 12px;background:#fff;border:1px solid #eee;border-radius:4px;margin-top:4px;white-space:pre-wrap;font-family:monospace;font-size:11px"><?= htmlspecialchars($e['review_note']) ?></div>
            </div>
            <?php endif; ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid #eee" onclick="event.stopPropagation()">
              <button class="btn btn-ghost btn-sm" onclick="openAssign(<?= $e['id'] ?>)"><i class="fa-solid fa-user"></i> Reassign</button>
              <?php if ($e['status'] !== 'resolved' && $e['status'] !== 'dismissed'): ?>
              <button class="btn btn-ghost btn-sm" onclick="openEdit(<?= $e['id'] ?>,'<?= $e['priority'] ?>','<?= $e['action_type'] ?>','<?= htmlspecialchars(addslashes($e['action_detail']), ENT_QUOTES) ?>')"><i class="fa-solid fa-pen"></i> Edit</button>
              <?php endif; ?>
              <?php if ($entity_url): ?>
              <a class="btn btn-ghost btn-sm" href="<?= $entity_url ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i> View context</a>
              <?php endif; ?>
            </div>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($escalations)): ?>
      <tr><td colspan="11" class="dim" style="text-align:center;padding:20px">No escalations match the current filters.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <?php
  $total_stmt = $db->prepare("SELECT COUNT(*) c FROM escalations e WHERE $where_sql");
  if ($types) $total_stmt->bind_param($types, ...$params);
  $total_stmt->execute();
  $total_rows = (int)$total_stmt->get_result()->fetch_assoc()['c'];
  $total_stmt->close();
  $total_pages = max(1, ceil($total_rows / $per_page));
  if ($total_pages > 1): ?>
  <div style="padding:12px 16px;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#666">
    <span>Page <?= $page ?> of <?= $total_pages ?> — <?= $total_rows ?> total</span>
    <div style="display:flex;gap:4px">
      <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= link_with(array('page'=>$page-1)) ?>">← Prev</a><?php endif; ?>
      <?php if ($page < $total_pages): ?><a class="btn btn-ghost btn-sm" href="<?= link_with(array('page'=>$page+1)) ?>">Next →</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ── ASSIGN MODAL ── -->
<div id="assign-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:460px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Assign Escalation</span>
      <button onclick="document.getElementById('assign-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_escalations.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="assign">
      <input type="hidden" name="escalation_id" id="assign_esc_id">
      <div class="form-group">
        <label class="form-label">Assign to</label>
        <select name="assigned_to" class="form-select" required>
          <option value="">-- Pick a manager or admin --</option>
          <?php foreach ($managers as $m): ?>
          <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['full_name']) ?> (<?= $m['role'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Handover note (optional)</label>
        <textarea name="assign_note" class="form-input" style="height:70px" placeholder="Context for the new assignee"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Assign</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('assign-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── EDIT MODAL ── -->
<div id="edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:520px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Edit Escalation</span>
      <button onclick="document.getElementById('edit-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_escalations.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="escalation_id" id="edit_esc_id">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Priority</label>
          <select name="priority" id="edit_priority" class="form-select">
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
            <option value="critical">Critical</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Type</label>
          <select name="action_type" id="edit_type" class="form-select">
            <option value="variance">Variance</option>
            <option value="unmatched">Unmatched</option>
            <option value="currency_mismatch">Currency Mismatch</option>
            <option value="manual">Manual</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Detail</label>
        <textarea name="action_detail" id="edit_detail" class="form-input" style="height:100px" required minlength="5"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('edit-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── REVIEW MODAL ── -->
<div id="review-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:460px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Review Escalation</span>
      <button onclick="document.getElementById('review-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_escalations.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="review">
      <input type="hidden" name="escalation_id" id="review_esc_id">
      <div class="form-group">
        <label class="form-label">Review Note</label>
        <textarea name="review_note" class="form-input" style="height:100px" required minlength="5" placeholder="What did you verify? Any concerns or action items?"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Mark as Reviewed</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('review-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── RESOLVE MODAL ── -->
<div id="resolve-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:460px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Resolve Escalation</span>
      <button onclick="document.getElementById('resolve-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_escalations.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="resolve">
      <input type="hidden" name="escalation_id" id="resolve_esc_id">
      <div class="form-group">
        <label class="form-label">Resolution Note</label>
        <textarea name="resolution_note" class="form-input" style="height:100px" required minlength="5" placeholder="How was this resolved? What action was taken?"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Mark as Resolved</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('resolve-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── DISMISS MODAL ── -->
<div id="dismiss-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:460px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Dismiss Escalation</span>
      <button onclick="document.getElementById('dismiss-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" action="../process/process_escalations.php" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="dismiss">
      <input type="hidden" name="escalation_id" id="dismiss_esc_id">
      <div class="form-group">
        <label class="form-label">Dismissal Reason</label>
        <textarea name="dismiss_reason" class="form-input" style="height:100px" required minlength="5" placeholder="Why is this being dismissed? (not a real issue, duplicate, etc.)"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Dismiss</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('dismiss-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<style>
.esc-row:hover { background:#f5fbf7 }
.esc-row.expanded { background:#eaf7ef }
.esc-row.expanded .expand-toggle { display:inline-block }
</style>

<script>
function toggleEscDetail(row) {
  const next = row.nextElementSibling;
  if (!next || !next.classList.contains('esc-detail-row')) return;
  const isOpen = next.style.display !== 'none';
  next.style.display = isOpen ? 'none' : '';
  row.classList.toggle('expanded', !isOpen);
  row.querySelector('.expand-toggle').textContent = isOpen ? '▸' : '▾';
}

function openAssign(id) {
  document.getElementById('assign_esc_id').value = id;
  document.getElementById('assign-modal').style.display = 'flex';
}
function openEdit(id, priority, type, detail) {
  document.getElementById('edit_esc_id').value = id;
  document.getElementById('edit_priority').value = priority;
  document.getElementById('edit_type').value = type;
  document.getElementById('edit_detail').value = detail;
  document.getElementById('edit-modal').style.display = 'flex';
}
function openReview(id) {
  document.getElementById('review_esc_id').value = id;
  document.getElementById('review-modal').style.display = 'flex';
}
function openResolve(id) {
  document.getElementById('resolve_esc_id').value = id;
  document.getElementById('resolve-modal').style.display = 'flex';
}
function openDismiss(id) {
  document.getElementById('dismiss_esc_id').value = id;
  document.getElementById('dismiss-modal').style.display = 'flex';
}

['assign-modal','edit-modal','review-modal','resolve-modal','dismiss-modal'].forEach(id => {
  const m = document.getElementById(id);
  if (m) m.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
});
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
