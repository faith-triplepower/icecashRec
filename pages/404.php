<?php
// ============================================================
// pages/404.php
// Not-found page for unknown routes.
// ============================================================
// 404 - Page Not Found
$page_title = 'Page Not Found';
require_once '../layouts/layout_header.php';
?>

<div class="page-header" style="text-align:center;padding:40px 20px">
  <div style="font-size:48px;margin-bottom:20px">404</div>
  <h1>Page Not Found</h1>
  <p style="margin-bottom:20px">The page you're looking for doesn't exist or has been moved.</p>
  <a href="<?= BASE_URL ?>/modules/dashboard.php" class="btn btn-primary">← Back to Dashboard</a>
</div>

<?php require_once '../layouts/layout_footer.php'; ?>
