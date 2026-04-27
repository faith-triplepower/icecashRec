<?php
// ============================================================
// admin/db_admin.php — Admin-only database migration runner.
//
// Lists every .sql file in /sql/ and lets an Admin preview / run
// them with one click. Built for environments where the developer
// can push to git but doesn't have direct DB credentials.
//
// Security:
//   - require_role('Admin')                — Managers cannot reach this
//   - filename whitelist (basename only, must end .sql, must exist)
//   - destructive files (matching DESTRUCTIVE_PATTERNS) need an
//     extra typed confirmation
//   - CSRF token on every run
//   - audit_log entry per run with file hash + result
// ============================================================

$page_title = 'Database Admin';
$active_nav = 'admin';
require_once '../layouts/layout_header.php';
require_role(array('Admin'));

$db   = get_db();
$user = current_user();
$uid  = (int)$user['id'];

$SQL_DIR = realpath(__DIR__ . '/../sql');
if (!$SQL_DIR || !is_dir($SQL_DIR)) {
    die('SQL directory not found.');
}

// Files matching these substrings are flagged DESTRUCTIVE in the UI
// (require typing the filename to confirm). Adjust as new files appear.
$DESTRUCTIVE_PATTERNS = array('reset_', 'wipe_', 'drop_', 'truncate_', 'icecash_db.sql');

function is_destructive($name, $patterns) {
    foreach ($patterns as $p) {
        if (stripos($name, $p) !== false) return true;
    }
    return false;
}

function log_admin_action($db, $uid, $detail, $result = 'success') {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $stmt = $db->prepare(
        "INSERT INTO audit_log (user_id, action_type, detail, ip_address, result, created_at)
         VALUES (?, 'DATA_EDIT', ?, ?, ?, NOW())"
    );
    $stmt->bind_param('isss', $uid, $detail, $ip, $result);
    $stmt->execute();
    $stmt->close();
}

// ── Run a SQL file ──────────────────────────────────────────
$last_action = null;
$last_output = null;
$last_error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_sql') {
    csrf_verify();

    $req_name = basename($_POST['filename'] ?? '');
    $confirm  = trim($_POST['confirm_filename'] ?? '');

    // Whitelist: must be a real .sql file in /sql/
    if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $req_name)) {
        $last_error = 'Invalid filename.';
    } else {
        $full = $SQL_DIR . DIRECTORY_SEPARATOR . $req_name;
        if (!is_file($full)) {
            $last_error = "File not found: $req_name";
        } else {
            // Destructive files require typing the exact filename to confirm.
            if (is_destructive($req_name, $DESTRUCTIVE_PATTERNS) && $confirm !== $req_name) {
                $last_error = "This file is flagged as destructive. To confirm, type the filename exactly: $req_name";
            } else {
                $sql = file_get_contents($full);
                $hash = substr(hash('sha256', $sql), 0, 12);

                // Run the file as a multi-statement query so semicolon-
                // separated statements all execute. Capture every result
                // in order.
                $output = array();
                if ($db->multi_query($sql)) {
                    do {
                        if ($res = $db->store_result()) {
                            $rows = $res->fetch_all(MYSQLI_ASSOC);
                            $output[] = array(
                                'kind' => 'rows',
                                'cols' => empty($rows) ? array() : array_keys($rows[0]),
                                'rows' => $rows,
                            );
                            $res->free();
                        } else {
                            $output[] = array(
                                'kind'    => 'affected',
                                'rows'    => $db->affected_rows,
                                'info'    => $db->info ?: '',
                            );
                        }
                        if (!$db->more_results()) break;
                    } while ($db->next_result());
                }
                if ($db->errno) {
                    $last_error = 'MySQL error: ' . $db->error;
                    log_admin_action($db, $uid, "Ran SQL file '$req_name' (hash $hash) — FAILED: " . substr($db->error, 0, 150), 'failed');
                } else {
                    $last_action = "Ran '$req_name' (hash $hash)";
                    $last_output = $output;
                    log_admin_action($db, $uid, "Ran SQL file '$req_name' (hash $hash) — OK");
                }
            }
        }
    }
}

// ── List all .sql files ─────────────────────────────────────
$files = array();
foreach (scandir($SQL_DIR) as $f) {
    if (!preg_match('/\.sql$/i', $f)) continue;
    $full = $SQL_DIR . DIRECTORY_SEPARATOR . $f;
    if (!is_file($full)) continue;
    $files[] = array(
        'name'         => $f,
        'size'         => filesize($full),
        'mtime'        => filemtime($full),
        'destructive'  => is_destructive($f, $DESTRUCTIVE_PATTERNS),
    );
}
usort($files, function($a, $b) { return $b['mtime'] - $a['mtime']; });

// ── Preview support ─────────────────────────────────────────
$preview_name = isset($_GET['preview']) ? basename($_GET['preview']) : '';
$preview_content = '';
if ($preview_name && preg_match('/^[A-Za-z0-9._-]+\.sql$/', $preview_name)) {
    $p = $SQL_DIR . DIRECTORY_SEPARATOR . $preview_name;
    if (is_file($p)) $preview_content = file_get_contents($p);
}
?>

<div class="page-header">
  <h1>Database Admin</h1>
  <p>Run database migrations and maintenance scripts. <strong>Admin only.</strong> Every run is recorded in the audit log.</p>
</div>

<?php if ($last_error): ?>
<div class="alert alert-danger">⚠ <?= htmlspecialchars($last_error) ?></div>
<?php endif; ?>

<?php if ($last_action): ?>
<div class="alert alert-success">✓ <?= htmlspecialchars($last_action) ?></div>
  <?php if ($last_output): ?>
  <div class="panel" style="margin-bottom:16px">
    <div class="panel-header"><span class="panel-title">Output</span></div>
    <div style="padding:12px 16px;font-family:monospace;font-size:12px">
    <?php foreach ($last_output as $i => $o): ?>
      <?php if ($o['kind'] === 'rows'): ?>
        <div style="margin-bottom:8px"><strong>Result set <?= $i + 1 ?>:</strong> <?= count($o['rows']) ?> rows</div>
        <?php if (!empty($o['rows'])): ?>
        <table class="data-table" style="font-size:11px">
          <thead><tr><?php foreach ($o['cols'] as $c): ?><th><?= htmlspecialchars($c) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
            <?php foreach ($o['rows'] as $row): ?>
            <tr><?php foreach ($o['cols'] as $c): ?><td><?= htmlspecialchars((string)($row[$c] ?? '')) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      <?php else: ?>
        <div>Statement <?= $i + 1 ?>: affected <?= (int)$o['rows'] ?> row(s)<?= $o['info'] ? ' — ' . htmlspecialchars($o['info']) : '' ?></div>
      <?php endif; ?>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>

<div class="panel" style="margin-bottom:20px">
  <div class="panel-header"><span class="panel-title">SQL Files in <code>/sql/</code></span></div>
  <table class="data-table">
    <thead><tr><th>File</th><th>Size</th><th>Modified</th><th>Type</th><th style="width:280px">Actions</th></tr></thead>
    <tbody>
      <?php foreach ($files as $f): ?>
      <tr>
        <td class="mono" style="font-weight:600"><?= htmlspecialchars($f['name']) ?></td>
        <td class="mono dim"><?= number_format($f['size']) ?> B</td>
        <td class="mono dim" style="font-size:11px"><?= date('Y-m-d H:i', $f['mtime']) ?></td>
        <td>
          <?php if ($f['destructive']): ?>
          <span class="badge variance" style="font-weight:700">DESTRUCTIVE</span>
          <?php else: ?>
          <span class="badge reconciled">migration</span>
          <?php endif; ?>
        </td>
        <td>
          <a class="btn btn-ghost btn-sm" href="?preview=<?= urlencode($f['name']) ?>"><i class="fa-solid fa-eye"></i> Preview</a>
          <button type="button" class="btn btn-primary btn-sm"
            style="<?= $f['destructive'] ? 'background:#c0392b;border-color:#c0392b;' : '' ?>"
            onclick="openRunModal('<?= htmlspecialchars($f['name'], ENT_QUOTES) ?>', <?= $f['destructive'] ? 'true' : 'false' ?>)">
            <i class="fa-solid fa-play"></i> Run
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($files)): ?>
      <tr><td colspan="5" class="dim" style="text-align:center;padding:20px">No .sql files found in /sql/.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($preview_name && $preview_content): ?>
<div class="panel" style="margin-bottom:20px">
  <div class="panel-header"><span class="panel-title">Preview: <code><?= htmlspecialchars($preview_name) ?></code></span></div>
  <pre style="margin:0;padding:14px 18px;font-size:12px;background:#fafafa;border-top:1px solid #eee;overflow:auto;max-height:480px;white-space:pre-wrap"><?= htmlspecialchars($preview_content) ?></pre>
</div>
<?php endif; ?>

<!-- Run modal -->
<div id="run-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:520px;max-width:95vw">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:600">Run SQL Script</span>
      <button onclick="document.getElementById('run-modal').style.display='none'" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <form method="POST" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="run_sql">
      <input type="hidden" name="filename" id="run_filename">
      <p>About to run <strong id="run_filename_label" style="font-family:monospace"></strong>.</p>
      <div id="destructive_warning" style="display:none;background:#fff5f5;border-left:4px solid #c0392b;padding:10px 12px;margin:10px 0;font-size:12px;color:#7a2e25">
        <strong>This file is flagged DESTRUCTIVE.</strong> It will modify or delete data permanently. Type the filename below to confirm.
        <input type="text" name="confirm_filename" class="form-input" placeholder="type the filename to confirm" style="margin-top:8px">
      </div>
      <div style="display:flex;gap:8px;margin-top:14px">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-play"></i> Run Now</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('run-modal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openRunModal(name, destructive) {
  document.getElementById('run_filename').value = name;
  document.getElementById('run_filename_label').textContent = name;
  document.getElementById('destructive_warning').style.display = destructive ? 'block' : 'none';
  document.getElementById('run-modal').style.display = 'flex';
}
document.getElementById('run-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
