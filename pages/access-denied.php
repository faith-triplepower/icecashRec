<?php
// ============================================================
// pages/access-denied.php
// Shown when require_role() rejects a user.
// ============================================================
// 403 - Access Denied
$page_title = 'Access Denied';
require_once '../layouts/layout_header.php';
?>

<div class="page-header" style="text-align:center;padding:40px 20px">
  <div style="font-size:48px;margin-bottom:20px">🔒</div>
  <h1>Access Denied</h1>
  <p style="margin-bottom:20px;color:var(--danger)">You don't have permission to access this page. Your role doesn't allow this action.</p>
  
  <div style="background:var(--card);border:1px solid var(--border);border-radius:6px;padding:16px;margin:20px 0;text-align:left;max-width:500px;margin-left:auto;margin-right:auto">
    <div style="font-weight:600;margin-bottom:12px">Your Current Role: <span style="color:var(--accent)"><?= htmlspecialchars($user['role'] ?? 'Unknown') ?></span></div>
    <div style="font-size:12px;color:var(--text-dim)">
      If you believe you should have access, contact your administrator.
    </div>
  </div>
  
  <a href="/icecashRec/modules/dashboard.php" class="btn btn-primary">← Back to Dashboard</a>
</div>

<?php require_once '../layouts/layout_footer.php'; ?>
