<?php
// ============================================================
// admin/outbox.php
// Notification email queue viewer + manual queue runner.
// ============================================================
$page_title = 'Notification Outbox';
$active_nav = 'outbox';
require_once '../layouts/layout_header.php';
require_role(['Manager','Admin']);

$db = get_db();
$success = htmlspecialchars($_GET['success'] ?? '');
$error   = htmlspecialchars($_GET['error']   ?? '');

$filter = $_GET['status'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;
$where = '';
$params = array();
$types  = '';
if (in_array($filter, array('pending','sent','failed','skipped'))) {
    $where = 'WHERE nq.status = ?';
    $params[] = $filter;
    $types    = 's';
}

$stmt = $db->prepare("
    SELECT nq.*, u.full_name AS user_name
    FROM notification_queue nq
    LEFT JOIN users u ON nq.user_id = u.id
    $where
    ORDER BY nq.created_at DESC
    LIMIT $per_page OFFSET $offset
");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$queue = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$cnt_q = $db->prepare("SELECT COUNT(*) c FROM notification_queue nq $where");
if ($types) $cnt_q->bind_param($types, ...$params);
$cnt_q->execute();
$total_rows = (int)$cnt_q->get_result()->fetch_assoc()['c'];
$cnt_q->close();
$total_pages = max(1, ceil($total_rows / $per_page));

// Counts
$counts = array();
foreach (array('pending','sent','failed','skipped') as $s) {
    $row = $db->query("SELECT COUNT(*) c FROM notification_queue WHERE status='$s'")->fetch_assoc();
    $counts[$s] = (int)$row['c'];
}
?>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
      <h1>Notification Outbox</h1>
      <p>Queue of outbound notification emails. Runs as drafts until SMTP is configured.</p>
    </div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" onclick="runQueue()"><i class="fa-solid fa-play"></i> Run Queue Now</button>
    </div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success">✓ <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">⚠ <?= $error ?></div><?php endif; ?>

<div class="alert alert-warn" style="font-size:12px">
  <strong>ℹ SMTP not configured?</strong> Messages stay <em>pending</em> or turn <em>failed</em> after 3 attempts. Treat this as an in-app inbox until mail delivery is wired. Once SMTP is set, click "Run Queue Now" to send.
</div>

<div class="stat-grid">
  <div class="stat-card warn"><div class="stat-label">Pending</div><div class="stat-value"><?= $counts['pending'] ?></div><div class="stat-sub">Waiting to send</div></div>
  <div class="stat-card green"><div class="stat-label">Sent</div><div class="stat-value"><?= $counts['sent'] ?></div><div class="stat-sub">Successfully delivered</div></div>
  <div class="stat-card red"><div class="stat-label">Failed</div><div class="stat-value"><?= $counts['failed'] ?></div><div class="stat-sub">Hit max retries</div></div>
  <div class="stat-card blue"><div class="stat-label">Skipped</div><div class="stat-value"><?= $counts['skipped'] ?></div><div class="stat-sub">User opted out</div></div>
</div>

<div style="display:flex;gap:8px;margin:16px 0;flex-wrap:wrap">
  <a href="?"              class="btn <?= !$filter?'btn-primary':'btn-ghost' ?>">All</a>
  <a href="?status=pending" class="btn <?= $filter==='pending'?'btn-primary':'btn-ghost' ?>">Pending (<?= $counts['pending'] ?>)</a>
  <a href="?status=sent"    class="btn <?= $filter==='sent'?'btn-primary':'btn-ghost' ?>">Sent (<?= $counts['sent'] ?>)</a>
  <a href="?status=failed"  class="btn <?= $filter==='failed'?'btn-primary':'btn-ghost' ?>">Failed (<?= $counts['failed'] ?>)</a>
  <a href="?status=skipped" class="btn <?= $filter==='skipped'?'btn-primary':'btn-ghost' ?>">Skipped (<?= $counts['skipped'] ?>)</a>
</div>

<div class="panel">
  <table class="data-table">
    <thead>
      <tr><th style="width:22px"></th><th>#</th><th>Category</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Attempts</th><th>Created</th></tr>
    </thead>
    <tbody>
      <?php foreach ($queue as $q): ?>
      <?php $cls = array('pending'=>'pending','sent'=>'reconciled','failed'=>'variance','skipped'=>'matched'); ?>
      <tr class="q-row" style="cursor:pointer" onclick="toggleQ(this)">
        <td class="expand-toggle" style="text-align:center;color:#888">▸</td>
        <td class="mono">#<?= (int)$q['id'] ?></td>
        <td style="font-size:11px"><?= htmlspecialchars($q['category']) ?></td>
        <td style="font-size:11px"><?= htmlspecialchars($q['recipient']) ?><br><span class="dim"><?= htmlspecialchars($q['user_name'] ?? '—') ?></span></td>
        <td style="font-size:12px;max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($q['subject']) ?></td>
        <td><span class="badge <?= $cls[$q['status']] ?? 'pending' ?>"><?= strtoupper($q['status']) ?></span></td>
        <td class="mono" style="font-size:11px"><?= (int)$q['attempt_count'] ?>/3</td>
        <td class="dim mono" style="font-size:11px"><?= date('M d H:i', strtotime($q['created_at'])) ?></td>
      </tr>
      <tr class="q-detail-row" style="display:none">
        <td colspan="8" style="background:#fafbfc;padding:0">
          <div style="padding:14px 24px">
            <div style="font-size:11px;color:#666;margin-bottom:6px"><strong>Subject:</strong> <?= htmlspecialchars($q['subject']) ?></div>
            <div style="padding:10px 12px;background:#fff;border:1px solid #e5e5e5;border-radius:4px;font-family:monospace;font-size:11px;white-space:pre-wrap"><?= htmlspecialchars($q['body']) ?></div>
            <?php if ($q['error']): ?>
            <div style="margin-top:8px;padding:8px 12px;background:#fde8ea;border-left:3px solid #c0392b;font-size:11px;color:#7a1a1a">
              <strong>Last error:</strong> <?= htmlspecialchars($q['error']) ?>
            </div>
            <?php endif; ?>
            <?php if ($q['sent_at']): ?>
            <div class="dim" style="font-size:11px;margin-top:6px">Sent at: <?= $q['sent_at'] ?></div>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($queue)): ?>
      <tr><td colspan="8" class="dim" style="text-align:center;padding:20px">Queue is empty.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <?php if ($total_pages > 1): ?>
  <div style="padding:12px 16px;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#666">
    <span>Page <?= $page ?> of <?= $total_pages ?> — <?= $total_rows ?> total</span>
    <div style="display:flex;gap:4px">
      <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="?status=<?= urlencode($filter) ?>&page=<?= $page-1 ?>">← Prev</a><?php endif; ?>
      <?php if ($page < $total_pages): ?><a class="btn btn-ghost btn-sm" href="?status=<?= urlencode($filter) ?>&page=<?= $page+1 ?>">Next →</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function toggleQ(row) {
  const next = row.nextElementSibling;
  if (!next || !next.classList.contains('q-detail-row')) return;
  const isOpen = next.style.display !== 'none';
  next.style.display = isOpen ? 'none' : '';
  row.querySelector('.expand-toggle').textContent = isOpen ? '▸' : '▾';
}

function runQueue() {
  fetch('../process/email_queue_runner.php', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => {
      alert(d.message || 'Queue processed.');
      location.reload();
    })
    .catch(e => alert('Failed: ' + e));
}
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
