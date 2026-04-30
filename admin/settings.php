<?php
// ============================================================
// admin/settings.php
// System + organization + user notification settings page.
// ============================================================
$page_title = 'Settings';
$active_nav = 'settings';
require_once '../layouts/layout_header.php';
require_login();

$db      = get_db();
$user    = current_user();
$role    = $user['role'];
$uid     = (int)$user['id'];
$success = htmlspecialchars($_GET['success'] ?? '');
$error   = htmlspecialchars($_GET['error']   ?? '');

// Manager + Admin can tweak reconciliation defaults (operational).
// Only Admin can edit organization settings and view system info.
$can_recon_defaults = in_array($role, array('Manager','Admin'));
$can_admin          = $role === 'Admin';

// Load system settings
$settings_rows = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetch_all(MYSQLI_ASSOC);
$settings      = array_column($settings_rows, 'setting_value', 'setting_key');

// Load user preferences
$prefs = array();
$p_stmt = $db->prepare("SELECT pref_key, pref_val FROM user_preferences WHERE user_id=?");
$p_stmt->bind_param('i', $uid);
$p_stmt->execute();
foreach ($p_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $prefs[$r['pref_key']] = $r['pref_val'];
}
$p_stmt->close();

// Helper: default check state for a notification pref
function pref_checked($prefs, $key, $default = false) {
    $val = isset($prefs[$key]) ? $prefs[$key] : ($default ? '1' : '0');
    return $val === '1' ? 'checked' : '';
}

$notif_email = $prefs['notif_email'] ?? $user['email'];
$theme       = $prefs['theme']       ?? 'default';
$timezone    = $prefs['timezone']    ?? 'Africa/Harare';
?>

<div class="page-header">
  <h1>Settings</h1>
  <p><?= $role === 'Admin'
      ? 'System configuration, organization info, notification preferences, and your account.'
      : ($role === 'Manager'
          ? 'Reconciliation defaults, notification preferences, and your account.'
          : 'Your notification preferences and account settings.') ?></p>
</div>

<?php if ($success): ?><div class="alert alert-success">✓ <?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">⚠ <?= $error ?></div><?php endif; ?>

<div class="two-col" style="align-items:start;gap:16px">

  <!-- LEFT column -->
  <div>
    <?php if ($can_recon_defaults): ?>
    <!-- Reconciliation Defaults -->
    <div class="panel">
      <div class="panel-header"><span class="panel-title">Reconciliation Defaults</span></div>
      <div class="panel-body">
        <form method="POST" action="../process/process_settings.php">
      <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_defaults">
          <div class="form-group">
            <label class="form-label">Default Period Type</label>
            <select name="default_period_type" class="form-select">
              <option <?= ($settings['default_period_type']??'')==='Monthly'?'selected':'' ?>>Monthly</option>
              <option <?= ($settings['default_period_type']??'')==='Daily'?'selected':'' ?>>Daily</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Date Tolerance (days)</label>
            <input type="number" name="date_tolerance" class="form-input"
              value="<?= (int)($settings['date_tolerance_days'] ?? 1) ?>" min="0" max="5">
            <div style="font-size:11px;color:#888;margin-top:5px">Allow ±N days when matching by date</div>
          </div>
          <div class="form-group">
            <label class="form-label">Amount Tolerance (ZWG)</label>
            <input type="number" name="amount_tolerance" class="form-input"
              value="<?= (int)($settings['amount_tolerance_zwg'] ?? 0) ?>" min="0">
            <div style="font-size:11px;color:#888;margin-top:5px">Allow small rounding differences</div>
          </div>
          <div class="form-group">
            <label class="form-label">Auto-flag Currency Mismatches</label>
            <select name="auto_flag_fx" class="form-select">
              <option value="yes" <?= ($settings['auto_flag_fx_mismatch']??'')==='yes'?'selected':'' ?>>Yes — Flag automatically</option>
              <option value="no"  <?= ($settings['auto_flag_fx_mismatch']??'')==='no' ?'selected':'' ?>>No — Log only</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">Save Defaults</button>
        </form>
      </div>
    </div>
    <?php endif; // /can_recon_defaults ?>

    <?php if ($can_admin): ?>
    <!-- Organization Settings (Admin only) -->
    <div class="panel">
      <div class="panel-header"><span class="panel-title">Organization Settings</span></div>
      <div class="panel-body">
        <form method="POST" action="../process/process_settings.php">
      <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_org">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Organization Name</label>
              <input type="text" name="org_name" class="form-input"
                value="<?= htmlspecialchars($settings['org_name'] ?? 'Zimnat General Insurance') ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">System Version</label>
              <input type="text" name="system_version" class="form-input"
                value="<?= htmlspecialchars($settings['system_version'] ?? 'v1.0') ?>">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Session Timeout (hours)</label>
              <input type="number" name="session_timeout" class="form-input" min="1" max="24"
                value="<?= (int)($settings['session_timeout_hours'] ?? 8) ?>">
              <div style="font-size:11px;color:#888;margin-top:5px">Idle users are logged out after this many hours</div>
            </div>
            <div class="form-group">
              <label class="form-label">Password Minimum Length</label>
              <input type="number" name="password_min_length" class="form-input" min="6" max="32"
                value="<?= (int)($settings['password_min_length'] ?? 8) ?>">
              <div style="font-size:11px;color:#888;margin-top:5px">Applied on next password change</div>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Auto-Escalate Threshold (ZWG)</label>
              <input type="number" name="auto_escalate_zwg" class="form-input" min="0"
                value="<?= (int)($settings['auto_escalate_threshold_zwg'] ?? 10000) ?>">
              <div style="font-size:11px;color:#888;margin-top:5px">Variances above this amount auto-escalate</div>
            </div>
            <div class="form-group">
              <label class="form-label">Auto-Escalate Threshold (USD)</label>
              <input type="number" name="auto_escalate_usd" class="form-input" min="0"
                value="<?= (int)($settings['auto_escalate_threshold_usd'] ?? 500) ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Audit Log Retention (days)</label>
            <input type="number" name="audit_retention_days" class="form-input" min="30"
              value="<?= (int)($settings['audit_retention_days'] ?? 3650) ?>">
            <div style="font-size:11px;color:#888;margin-top:5px">Policy setting — entries are not auto-deleted but the policy is shown on the audit page</div>
          </div>
          <div class="form-group">
            <label class="form-label">PDF Extractor Path</label>
            <input type="text" name="pdftotext_path" class="form-input"
              value="<?= htmlspecialchars($settings['pdftotext_path'] ?? '') ?>" placeholder="e.g. C:/Program Files/Git/mingw64/bin/pdftotext.exe">
            <div style="font-size:11px;color:#888;margin-top:5px">Path to the <code>pdftotext</code> binary (Poppler/XPDF). Used to parse PDF uploads. Leave blank to auto-detect.</div>
          </div>
          <button type="submit" class="btn btn-primary">Save Organization Settings</button>
        </form>
      </div>
    </div>
    <?php endif; // /can_admin (org settings) ?>

    <!-- Two-Factor Authentication -->
    <div class="panel">
      <div class="panel-header"><span class="panel-title">Two-Factor Authentication</span></div>
      <div class="panel-body" style="padding:16px 20px">
        <?php
        require_once '../core/totp.php';
        $totp_on = totp_is_enabled($db, $uid);
        $totp_required = totp_is_required_for_role($role);
        ?>
        <?php if ($totp_on): ?>
        <div style="display:flex;align-items:center;gap:12px">
          <i class="fa fa-shield" style="font-size:24px;color:#00a950"></i>
          <div>
            <div style="font-weight:600;color:#00a950">2FA is enabled</div>
            <div style="font-size:11px;color:#888">Your account is protected with TOTP authenticator.</div>
          </div>
          <a href="../pages/setup_2fa.php" class="btn btn-ghost btn-sm" style="margin-left:auto">Manage</a>
        </div>
        <?php else: ?>
        <div style="display:flex;align-items:center;gap:12px">
          <i class="fa fa-unlock-alt" style="font-size:24px;color:#888"></i>
          <div>
            <div style="font-weight:600;color:#555">2FA is not enabled</div>
            <div style="font-size:11px;color:<?= $totp_required ? '#c0392b' : '#888' ?>">
              <?= $totp_required ? 'Required for ' . $role . ' accounts.' : 'Optional but recommended.' ?>
            </div>
          </div>
          <a href="../pages/setup_2fa.php" class="btn btn-primary btn-sm" style="margin-left:auto"><i class="fa fa-shield"></i> Enable 2FA</a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Notification Preferences (everyone) -->
    <div class="panel">
      <div class="panel-header"><span class="panel-title">Notification Preferences</span></div>
      <div class="panel-body">
        <form method="POST" action="../process/process_settings.php">
      <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_prefs">
          <div style="font-size:11px;color:#888;font-weight:600;margin-bottom:8px">ALERTS</div>
          <label style="display:flex;align-items:center;gap:10px;margin-bottom:10px;cursor:pointer;font-size:12.5px">
            <input type="checkbox" name="notif_variance" <?= pref_checked($prefs, 'notif_variance', true) ?> style="accent-color:var(--accent);width:15px;height:15px">
            Variance detected on a reconciliation run
          </label>
          <label style="display:flex;align-items:center;gap:10px;margin-bottom:10px;cursor:pointer;font-size:12.5px">
            <input type="checkbox" name="notif_unmatched" <?= pref_checked($prefs, 'notif_unmatched', true) ?> style="accent-color:var(--accent);width:15px;height:15px">
            Unmatched items exceed threshold
          </label>
          <label style="display:flex;align-items:center;gap:10px;margin-bottom:10px;cursor:pointer;font-size:12.5px">
            <input type="checkbox" name="notif_failed_upload" <?= pref_checked($prefs, 'notif_failed_upload', true) ?> style="accent-color:var(--accent);width:15px;height:15px">
            Failed file upload
          </label>
          <?php if ($can_admin): ?>
          <label style="display:flex;align-items:center;gap:10px;margin-bottom:10px;cursor:pointer;font-size:12.5px">
            <input type="checkbox" name="notif_escalation_assigned" <?= pref_checked($prefs, 'notif_escalation_assigned', true) ?> style="accent-color:var(--accent);width:15px;height:15px">
            Escalation assigned to me
          </label>
          <?php endif; ?>

          <div style="font-size:11px;color:#888;font-weight:600;margin:14px 0 8px">DIGESTS</div>
          <label style="display:flex;align-items:center;gap:10px;margin-bottom:10px;cursor:pointer;font-size:12.5px">
            <input type="checkbox" name="notif_daily_summary" <?= pref_checked($prefs, 'notif_daily_summary', false) ?> style="accent-color:var(--accent);width:15px;height:15px">
            Daily reconciliation summary
          </label>
          <?php if ($can_admin): ?>
          <label style="display:flex;align-items:center;gap:10px;margin-bottom:10px;cursor:pointer;font-size:12.5px">
            <input type="checkbox" name="notif_weekly_audit" <?= pref_checked($prefs, 'notif_weekly_audit', false) ?> style="accent-color:var(--accent);width:15px;height:15px">
            Weekly audit summary
          </label>
          <?php endif; ?>

          <div class="form-group" style="margin-top:14px">
            <label class="form-label">Notification Email</label>
            <input type="email" name="notif_email" class="form-input" value="<?= htmlspecialchars($notif_email) ?>">
            <div style="font-size:11px;color:#888;margin-top:5px">Defaults to your account email</div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Theme</label>
              <select name="theme" id="theme-select" class="form-select">
                <option value="default" <?= $theme==='default'?'selected':'' ?>>Default (Zimnat green)</option>
                <option value="light"   <?= $theme==='light'  ?'selected':'' ?>>Light</option>
                <option value="dark"    <?= $theme==='dark'   ?'selected':'' ?>>Dark</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Timezone</label>
              <select name="timezone" class="form-select">
                <?php foreach (array('Africa/Harare','Africa/Johannesburg','UTC','Europe/London') as $tz): ?>
                <option value="<?= $tz ?>" <?= $timezone===$tz?'selected':'' ?>><?= $tz ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-primary">Save Preferences</button>
        </form>
      </div>
    </div>
  </div>

  <!-- RIGHT column -->
  <div>
    <!-- Account Settings -->
    <div class="panel">
      <div class="panel-header"><span class="panel-title">Account Settings</span></div>
      <div class="panel-body">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;padding:14px;background:#f8f8f8;border:1px solid #e0e0e0;border-radius:6px">
          <div style="width:42px;height:42px;background:rgba(26,86,219,0.1);border:1px solid rgba(26,86,219,0.25);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:monospace;font-size:14px;font-weight:700;color:var(--accent)">
            <?= htmlspecialchars($user['initials']) ?>
          </div>
          <div>
            <div style="font-weight:600"><?= htmlspecialchars($user['name']) ?></div>
            <div style="font-size:11px;color:#888"><?= htmlspecialchars($user['email']) ?></div>
            <div style="font-size:10px;margin-top:3px;color:var(--accent)"><?= htmlspecialchars($user['role']) ?></div>
          </div>
        </div>

        <form method="POST" action="../process/process_settings.php">
      <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_profile">
          <div class="form-group"><label class="form-label">Display Name</label>
            <input type="text" name="full_name" class="form-input" value="<?= htmlspecialchars($user['name']) ?>" required></div>
          <div class="form-group"><label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($user['email']) ?>" required></div>
          <button type="submit" class="btn btn-primary" style="margin-bottom:10px">Update Profile</button>
        </form>

        <hr style="border:none;border-top:1px solid #eee;margin:16px 0">

        <div style="font-size:12px;font-weight:600;margin-bottom:12px">Change Password</div>
        <form method="POST" action="../process/process_settings.php">
      <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="form-group"><label class="form-label">Current Password</label>
            <input type="password" name="current_password" class="form-input" placeholder="••••••••" required></div>
          <div class="form-group"><label class="form-label">New Password</label>
            <input type="password" name="new_password" class="form-input" placeholder="••••••••" required minlength="<?= (int)($settings['password_min_length'] ?? 8) ?>">
            <div style="font-size:11px;color:#888;margin-top:5px">
              Minimum <?= (int)($settings['password_min_length'] ?? 8) ?> characters, must include at least one letter and one number.
            </div>
          </div>
          <div class="form-group"><label class="form-label">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-input" placeholder="••••••••" required></div>
          <button type="submit" class="btn btn-ghost">Update Password</button>
        </form>

        <hr style="border:none;border-top:1px solid #eee;margin:16px 0">

        <form method="POST" action="../process/process_settings.php" onsubmit="return confirm('Sign out of all other sessions? You will stay signed in here.')">
      <?= csrf_field() ?>
          <input type="hidden" name="action" value="signout_all">
          <button type="submit" class="btn btn-ghost" style="color:var(--danger);border-color:rgba(219,6,48,0.25)">
            <i class="fa fa-sign-out"></i> Sign Out All Other Sessions
          </button>
          <div style="font-size:11px;color:#888;margin-top:6px">Useful if you logged in from a shared computer and forgot to log out.</div>
        </form>
      </div>
    </div>

    <?php if ($can_admin): ?>
    <!-- System Info (read-only snapshot) -->
    <div class="panel">
      <div class="panel-header"><span class="panel-title">System Information</span></div>
      <div class="panel-body">
        <?php
        $info = array(
            array('System Version',       $settings['system_version']     ?? 'v1.0'),
            array('Organisation',         $settings['org_name']           ?? 'Zimnat General Insurance'),
            array('Database',             'icecash_recon (MySQL)'),
            array('PHP Version',          phpversion()),
            array('Server Timezone',      date_default_timezone_get()),
            array('Session Timeout',      ($settings['session_timeout_hours'] ?? '8') . ' hours'),
            array('Audit Retention',      ($settings['audit_retention_days'] ?? '3650') . ' days policy'),
            array('Auto-Escalate (ZWG)',  'ZWG ' . number_format((int)($settings['auto_escalate_threshold_zwg'] ?? 10000))),
            array('Auto-Escalate (USD)',  'USD ' . number_format((int)($settings['auto_escalate_threshold_usd'] ?? 500))),
        );
        foreach ($info as $i): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eee;font-size:12px">
          <span style="color:#888"><?= htmlspecialchars($i[0]) ?></span>
          <span style="font-family:monospace;font-size:11px"><?= htmlspecialchars($i[1]) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
// Live theme preview — update body class immediately when dropdown changes
// so the user sees the effect before hitting Save.
document.getElementById('theme-select').addEventListener('change', function () {
    document.body.className = 'theme-' + this.value;
});
</script>

<?php require_once '../layouts/layout_footer.php'; ?>
