<?php
// ============================================================
// admin/db_admin.php — Test-data management tools.
//
// Only the two explicitly whitelisted scripts below are exposed.
// The full schema, migrations, and any other SQL files are NOT
// listed, NOT previewable, and NOT runnable from here — they
// must be applied directly by a DBA with server access.
//
// Security:
//   - require_role('Admin')   — Managers cannot reach this page
//   - Explicit file whitelist — no directory scanning at all
//   - CSRF token on every run
//   - Typed confirmation required for destructive scripts
//   - Every run is recorded in the audit log
// ============================================================

$page_title = 'System Tools';
$active_nav = 'admin';
require_once '../layouts/layout_header.php';
require_role(['Admin']);

$db  = get_db();
$uid = (int)current_user()['id'];

// ── Explicit whitelist — ONLY these two files may be run ────
// To add a new script, add its filename here AND verify it is
// safe to run from the browser before deploying.
$ALLOWED_FILES = [
    'reset_test_data.sql'  => 'Full system wipe: removes agents, terminals, all transactions, reconciliation runs, preferences, and audit log. Keeps users, banks, and system settings only.',
    'update_passwords.sql' => 'Resets test user passwords to the defaults defined in the script. Safe to run after a fresh schema install.',
];

$SQL_DIR = realpath(__DIR__ . '/../sql');
if (!$SQL_DIR || !is_dir($SQL_DIR)) {
    die('SQL directory not found.');
}

// ── Run a script ────────────────────────────────────────────
$last_action = null;
$last_error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_sql') {
    csrf_verify();

    $req_name = trim(basename($_POST['filename'] ?? ''));
    $confirm  = trim($_POST['confirm_filename'] ?? '');

    if (!array_key_exists($req_name, $ALLOWED_FILES)) {
        $last_error = 'That script is not available from this page.';
    } else {
        $full = $SQL_DIR . DIRECTORY_SEPARATOR . $req_name;
        if (!is_file($full)) {
            $last_error = "Script file not found on disk: " . htmlspecialchars($req_name);
        } elseif ($confirm !== $req_name) {
            $last_error = "Name did not match — type exactly: <strong style='font-family:monospace'>" . htmlspecialchars($req_name) . "</strong> (you typed: <em>" . htmlspecialchars($confirm) . "</em>)";
        } else {
            $sql  = file_get_contents($full);
            $hash = substr(hash('sha256', $sql), 0, 12);

            if ($db->multi_query($sql)) {
                do {
                    if ($res = $db->store_result()) $res->free();
                    if (!$db->more_results()) break;
                } while ($db->next_result());
            }

            if ($db->errno) {
                $last_error = 'MySQL error: ' . htmlspecialchars($db->error);
                audit_log_entry($uid, 'DATA_EDIT', "Ran '$req_name' (hash $hash) — FAILED: " . substr($db->error, 0, 150), 'failed');
            } else {
                $last_action = "Successfully ran '$req_name' (sha256 …$hash).";
                audit_log_entry($uid, 'DATA_EDIT', "Ran '$req_name' (hash $hash) — OK");
            }
        }
    }
}
?>

<div class="page-header">
  <h1>System Tools</h1>
  <p>Test-data management scripts for Admin use. Schema migrations and production database files are not accessible here — contact a DBA for those.</p>
</div>

<?php if ($last_error): ?>
<div class="alert alert-danger">⚠ <?= $last_error ?></div>
<?php endif; ?>
<?php if ($last_action): ?>
<div class="alert alert-success">✓ <?= htmlspecialchars($last_action) ?></div>
<?php endif; ?>

<div class="panel">
  <div class="panel-header"><span class="panel-title">Available Scripts</span></div>
  <table class="data-table">
    <thead>
      <tr><th>Script</th><th>Description</th><th style="width:100px">Action</th></tr>
    </thead>
    <tbody>
      <?php foreach ($ALLOWED_FILES as $name => $desc): ?>
      <?php $exists = is_file($SQL_DIR . DIRECTORY_SEPARATOR . $name); ?>
      <tr>
        <td class="mono" style="font-weight:600;white-space:nowrap"><?= htmlspecialchars($name) ?></td>
        <td style="font-size:12px;color:#555"><?= htmlspecialchars($desc) ?></td>
        <td>
          <?php if ($exists): ?>
          <button type="button" class="btn btn-primary btn-sm"
                  style="background:#c0392b;border-color:#c0392b;font-weight:700"
                  onclick="openConfirm('<?= htmlspecialchars($name, ENT_QUOTES) ?>')">
            <i class="fa-solid fa-rotate-left"></i> Run
          </button>
          <?php else: ?>
          <span class="dim" style="font-size:11px">not on disk</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div style="margin-top:12px;padding:12px 16px;background:#fff8e1;border-left:4px solid #f0a500;border-radius:3px;font-size:12px;color:#6b4c00">
  <strong>Note:</strong> Running these scripts affects live data immediately and cannot be undone. Every run is recorded in the <a href="audit.php">Audit Log</a>.
</div>

<!-- Confirmation modal -->
<div id="confirm-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:5px;width:480px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,0.2)">
    <div style="padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center">
      <span style="font-weight:700;color:#c0392b"><i class="fa-solid fa-triangle-exclamation"></i>&nbsp; Confirm Script Run</span>
      <button onclick="closeConfirm()" style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888;line-height:1">×</button>
    </div>
    <form method="POST" style="padding:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="run_sql">
      <input type="hidden" name="filename" id="modal-filename">
      <p style="font-size:13px;margin:0 0 4px;color:#555">About to run:</p>
      <p style="font-family:monospace;font-size:14px;font-weight:700;color:#c0392b;margin:0 0 14px;word-break:break-all" id="modal-name"></p>
      <p style="font-size:12px;color:#888;margin:0 0 14px">This will modify data immediately and cannot be undone.</p>
      <label style="display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:6px">
        Type the script name exactly to confirm:
      </label>
      <div style="display:flex;gap:6px">
        <input type="text" name="confirm_filename" id="modal-confirm-input"
               class="form-input" placeholder="type filename here" autocomplete="off" spellcheck="false" style="flex:1">
        <button type="button" onclick="fillConfirm()"
                style="background:#f0f0f0;border:1px solid #ccc;border-radius:3px;padding:0 14px;font-size:12px;cursor:pointer;white-space:nowrap;color:#333">
          Fill
        </button>
      </div>
      <p id="modal-match-hint" style="font-size:11px;margin:6px 0 0;min-height:16px"></p>
      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="submit" id="modal-submit-btn" class="btn btn-primary" disabled
                style="background:#aaa;border-color:#aaa;font-weight:700;cursor:not-allowed">
          <i class="fa-solid fa-rotate-left"></i> Run Now
        </button>
        <button type="button" class="btn btn-ghost" onclick="closeConfirm()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openConfirm(name) {
    document.getElementById('modal-filename').value = name;
    document.getElementById('modal-name').textContent = name;
    document.getElementById('modal-confirm-input').value = '';
    document.getElementById('modal-confirm-input').style.borderColor = '';
    document.getElementById('modal-match-hint').textContent = '';
    var btn = document.getElementById('modal-submit-btn');
    btn.disabled = true;
    btn.style.background   = '#aaa';
    btn.style.borderColor  = '#aaa';
    btn.style.cursor       = 'not-allowed';
    document.getElementById('confirm-modal').style.display = 'flex';
    setTimeout(function(){ document.getElementById('modal-confirm-input').focus(); }, 60);
}

function fillConfirm() {
    var name  = document.getElementById('modal-filename').value;
    var input = document.getElementById('modal-confirm-input');
    input.value = name;
    input.dispatchEvent(new Event('input'));
    input.focus();
}

function closeConfirm() {
    document.getElementById('confirm-modal').style.display = 'none';
}

document.getElementById('modal-confirm-input').addEventListener('input', function() {
    var expected = document.getElementById('modal-filename').value;
    var match    = this.value === expected && expected !== '';
    var btn      = document.getElementById('modal-submit-btn');
    var hint     = document.getElementById('modal-match-hint');
    this.style.borderColor = this.value === '' ? '' : (match ? '#00a950' : '#c0392b');
    btn.disabled          = !match;
    btn.style.background  = match ? '#c0392b' : '#aaa';
    btn.style.borderColor = match ? '#c0392b' : '#aaa';
    btn.style.cursor      = match ? 'pointer' : 'not-allowed';
    hint.textContent      = this.value === '' ? '' : (match ? '✓ Ready to run.' : '✗ Does not match yet.');
    hint.style.color      = match ? '#00a950' : '#c0392b';
});

document.getElementById('confirm-modal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirm();
});
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
