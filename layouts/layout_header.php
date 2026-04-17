<?php
// ============================================================
// layouts/layout_header.php — Shared Page Header
// Included at the top of every protected page. Renders the
// top navbar (search, notifications, user dropdown), the
// role-gated sidebar navigation, and loads all CSS/JS assets.
// Part of IceCashRec — Zimnat General Insurance
// ============================================================
require_once '../core/auth.php';
require_once '../core/db.php';

// Buffer all output until the end of the request. This lets pages
// call require_role() AFTER including this header without triggering
// "headers already sent" when the authorization redirect fires.
// layout_footer.php flushes the buffer at the end.
if (!ob_get_level()) ob_start();

require_login();
$user = current_user();
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8" />
    <title>Zimnat | <?= htmlspecialchars($page_title ?? 'Page') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <!-- Bootstrap 3 via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Font Awesome 6 Free -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet" />
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:400,600,700" rel="stylesheet" />
    <!-- App Styles -->
    <link rel="stylesheet" href="/icecashRec/assets/css/app.css" />
</head>
<body>
<div id="wrapper">

    <!-- ══ TOP NAVBAR ══ -->
    <nav class="navbar navbar-default navbar-cls-top" role="navigation" style="margin-bottom:0">
        <div class="navbar-header">
            <button type="button" class="navbar-toggle" id="sidebar-toggle">
                <span class="sr-only">Toggle navigation</span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
            </button>
            <a class="navbar-brand" href="/icecashRec/modules/dashboard.php">
                Icecash<span>Rec</span>
            </a>
        </div>
        <div class="topbar-right">
            <!-- Notification Bell -->
            <div class="notification-dropdown">
                <button class="notification-bell" id="notif-bell" type="button">
                    <i class="fa-solid fa-bell"></i>
                    <span class="notif-count hidden" id="notif-count">0</span>
                </button>
                <div class="notification-panel" id="notif-panel">
                    <div class="notif-header" style="display:flex;justify-content:space-between;align-items:center">
                        <span>Notifications</span>
                        <a href="#" id="notif-mark-read" style="font-size:11px;color:#00a950;font-weight:600;text-decoration:none">Mark all read</a>
                    </div>
                    <div class="notif-list" id="notif-list">
                        <p class="dim" style="padding:14px;font-size:12px;text-align:center">Loading…</p>
                    </div>
                </div>
            </div>
            
            <!-- Global Search -->
            <div style="position:relative" id="global-search-wrap">
                <button onclick="var el=document.getElementById('global-search');var w=document.getElementById('search-input-wrap');if(w.style.display==='none'){w.style.display='block';el.focus()}else{w.style.display='none';el.value='';document.getElementById('search-results').style.display='none'}"
                        style="background:none;border:none;color:#555;font-size:16px;cursor:pointer;padding:5px 10px;transition:color 0.2s"
                        onmouseover="this.style.color='#00a950'" onmouseout="this.style.color='#555'" title="Search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
                <div id="search-input-wrap" style="display:none;position:absolute;top:100%;right:0;margin-top:6px;z-index:9999">
                    <input type="text" id="global-search" placeholder="Search policies, receipts, agents..."
                           style="width:320px;padding:8px 14px;border:1px solid #ddd;border-radius:6px;background:#fff;color:#333;font-size:12px;outline:none;box-shadow:0 4px 12px rgba(0,0,0,0.12)"
                           onfocus="this.style.borderColor='#00a950'"
                           onblur="var s=this;setTimeout(function(){if(!s.value){document.getElementById('search-input-wrap').style.display='none'}document.getElementById('search-results').style.display='none';s.style.borderColor='#ddd'},200)"
                           oninput="globalSearch(this.value)" autocomplete="off">
                    <div id="search-results" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 6px 6px;box-shadow:0 4px 12px rgba(0,0,0,0.15);max-height:400px;overflow-y:auto"></div>
                </div>
            </div>

            <div style="position:relative" id="user-menu-wrap">
                <button onclick="document.getElementById('user-dropdown').classList.toggle('show')"
                        style="background:none;border:1px solid #ddd;border-radius:4px;padding:6px 12px;cursor:pointer;display:flex;align-items:center;gap:8px;color:#333;font-size:13px">
                    <span style="width:28px;height:28px;border-radius:50%;background:#00a950;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700"><?= htmlspecialchars($user['initials'] ?? strtoupper(substr($user['name'],0,2))) ?></span>
                    <span><?= htmlspecialchars($user['name']) ?></span>
                    <i class="fa-solid fa-chevron-down" style="font-size:10px;color:#999"></i>
                </button>
                <div id="user-dropdown" style="display:none;position:absolute;top:100%;right:0;margin-top:6px;background:#fff;border:1px solid #ddd;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.12);min-width:180px;z-index:9999;overflow:hidden">
                    <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0">
                        <div style="font-weight:600;color:#333;font-size:13px"><?= htmlspecialchars($user['name']) ?></div>
                        <div style="font-size:11px;color:#888"><?= htmlspecialchars($user['role']) ?> &middot; <?= htmlspecialchars($user['username']) ?></div>
                    </div>
                    <a href="/icecashRec/admin/settings.php" style="display:flex;align-items:center;gap:10px;padding:10px 16px;color:#333;text-decoration:none;font-size:12px;transition:background 0.1s" onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
                        <i class="fa-solid fa-gear" style="width:16px;text-align:center;color:#888"></i> Settings
                    </a>
                    <a href="/icecashRec/pages/setup_2fa.php" style="display:flex;align-items:center;gap:10px;padding:10px 16px;color:#333;text-decoration:none;font-size:12px;transition:background 0.1s" onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
                        <i class="fa fa-shield" style="width:16px;text-align:center;color:#888"></i> Two-Factor Auth
                    </a>
                    <a href="/icecashRec/pages/change_password.php" style="display:flex;align-items:center;gap:10px;padding:10px 16px;color:#333;text-decoration:none;font-size:12px;transition:background 0.1s" onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
                        <i class="fa-solid fa-key" style="width:16px;text-align:center;color:#888"></i> Change Password
                    </a>
                    <div style="border-top:1px solid #f0f0f0">
                        <a href="/icecashRec/pages/logout.php" style="display:flex;align-items:center;gap:10px;padding:10px 16px;color:#c0392b;text-decoration:none;font-size:12px;font-weight:600;transition:background 0.1s" onmouseover="this.style.background='#fdf4f4'" onmouseout="this.style.background='#fff'">
                            <i class="fa-solid fa-right-from-bracket" style="width:16px;text-align:center"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
            <script>
            document.addEventListener('click', function(e) {
                var wrap = document.getElementById('user-menu-wrap');
                var dd = document.getElementById('user-dropdown');
                if (wrap && dd && !wrap.contains(e.target)) dd.classList.remove('show');
            });
            </script>
            <style>#user-dropdown.show { display:block !important; }</style>
        </div>
    </nav>
    <!-- /.TOP NAVBAR -->

    <!-- Sidebar overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <!-- ══ SIDEBAR ══ -->
    <nav class="navbar-default navbar-side" id="sidebar" role="navigation">
        <div class="sidebar-collapse">
        <ul class="nav" id="main-menu">

            <!-- Logo -->
            <li class="text-center">
                <img src="/icecashRec/assets/img/zimnat logo.png" class="user-image img-responsive" />
            </li>


            <?php
            $role    = $user['role'];
            $is_uploader   = $role === 'Uploader';
            $is_reconciler = $role === 'Reconciler';
            $is_manager    = $role === 'Manager';
            $is_admin      = $role === 'Admin';
            $can_reconcile = $is_reconciler || $is_manager || $is_admin;
            $can_upload    = $is_uploader   || $is_manager || $is_admin;
            ?>

            <!-- Overview -->
            <li class="sidebar-section-label">Overview</li>
            <li class="<?= ($active_nav==='dashboard') ? 'active' : '' ?>">
                <a href="/icecashRec/modules/dashboard.php">
                    <i class="fa-solid fa-gauge-high fa-fw"></i> Dashboard
                </a>
            </li>
            <?php if ($can_reconcile): ?>
            <li class="<?= ($active_nav==='reconciliation') ? 'active' : '' ?>">
                <a href="/icecashRec/modules/reconciliation.php">
                    <i class="fa-solid fa-arrows-rotate fa-fw"></i> Reconciliation
                </a>
            </li>
            <?php endif; ?>

            <!-- Data Ingestion — label adapts to role -->
            <li class="sidebar-section-label">
                <?= $is_uploader ? 'My Work' : 'Source Data' ?>
            </li>
            <?php if ($can_upload): ?>
            <li class="<?= ($active_nav==='upload') ? 'active' : '' ?>">
                <a href="/icecashRec/utilities/upload.php">
                    <i class="fa-solid fa-cloud-arrow-up fa-fw"></i> Upload Files
                </a>
            </li>
            <?php endif; ?>
            <li class="<?= ($active_nav==='upload' && !$can_upload) ? 'active' : '' ?>">
                <a href="/icecashRec/utilities/uploaded_files_list.php">
                    <i class="fa-regular fa-folder-open fa-fw"></i> <?= $is_uploader ? 'My Uploads' : 'Uploaded Files' ?>
                </a>
            </li>
            <!-- Sales/Receipts data pages — Uploaders see only their own
                 imports (scoped by upload_id in the page itself); everyone
                 else sees the full dataset. -->
            <li class="<?= ($active_nav==='sales') ? 'active' : '' ?>">
                <a href="/icecashRec/modules/sales.php">
                    <i class="fa-solid fa-chart-column fa-fw"></i> Sales Data
                </a>
            </li>
            <li class="<?= ($active_nav==='receipts') ? 'active' : '' ?>">
                <a href="/icecashRec/modules/receipts.php">
                    <i class="fa-regular fa-file-lines fa-fw"></i> Receipts Data
                </a>
            </li>

            <!-- Analysis / Oversight — hidden for Uploaders -->
            <?php if ($can_reconcile): ?>
            <li class="sidebar-section-label"><?= $is_reconciler ? 'Reconciliation Tools' : 'Oversight' ?></li>
            <li class="<?= ($active_nav==='variance') ? 'active' : '' ?>">
                <a href="/icecashRec/modules/variance.php">
                    <i class="fa-solid fa-triangle-exclamation fa-fw"></i> Variance Report
                </a>
            </li>
            <li class="<?= ($active_nav==='statements') ? 'active' : '' ?>">
                <a href="/icecashRec/admin/statements.php">
                    <i class="fa-regular fa-file-lines fa-fw"></i> Statements
                </a>
            </li>
            <li class="<?= ($active_nav==='agents') ? 'active' : '' ?>">
                <a href="/icecashRec/admin/agents.php">
                    <i class="fa-solid fa-people-group fa-fw"></i> Agents / Channels
                </a>
            </li>
            <li class="<?= ($active_nav==='pos_terminals') ? 'active' : '' ?>">
                <a href="/icecashRec/modules/pos_terminals.php">
                    <i class="fa-solid fa-cash-register fa-fw"></i> POS Terminals
                </a>
            </li>
            <li class="<?= ($active_nav==='unmatched') ? 'active' : '' ?>">
                <a href="/icecashRec/admin/unmatched.php">
                    <i class="fa-solid fa-circle-question fa-fw"></i> Unmatched Transactions
                </a>
            </li>
            <?php endif; ?>

            <!-- System -->
            <li class="sidebar-section-label">System</li>
            <li class="<?= ($active_nav==='audit') ? 'active' : '' ?>">
                <a href="/icecashRec/admin/audit.php">
                    <i class="fa-solid fa-clock-rotate-left fa-fw"></i> Audit Log
                </a>
            </li>
            <?php if ($user['role'] === 'Manager'): ?>
            <li class="<?= ($active_nav==='escalations') ? 'active' : '' ?>">
                <a href="/icecashRec/admin/escalations.php">
                    <i class="fa-solid fa-fire fa-fw"></i> Escalations
                </a>
            </li>
            <?php endif; ?>
            <?php if ($user['role'] === 'Manager' || $user['role'] === 'Admin'): ?>
            <li class="<?= ($active_nav==='outbox') ? 'active' : '' ?>">
                <a href="/icecashRec/admin/outbox.php">
                    <i class="fa-regular fa-envelope fa-fw"></i> Notification Outbox
                </a>
            </li>
            <li class="<?= ($active_nav==='users') ? 'active' : '' ?>">
                <a href="/icecashRec/admin/users.php">
                    <i class="fa-solid fa-user-gear fa-fw"></i> User Management
                </a>
            </li>
            <?php endif; ?>
            <li class="<?= ($active_nav==='settings') ? 'active' : '' ?>">
                <a href="/icecashRec/admin/settings.php">
                    <i class="fa-solid fa-sliders fa-fw"></i> Settings
                </a>
            </li>

            <?php if ($user['role'] === 'Admin'): ?>
            <li class="sidebar-section-label" style="margin-top:4px;color:rgba(255,220,100,0.7)">Administration</li>
            <li class="<?= ($active_nav==='admin_panel') ? 'active' : '' ?>">
                <a href="/icecashRec/admin/admin_panel.php" style="color:rgba(255,220,100,0.9) !important">
                    <i class="fa-solid fa-shield-halved fa-fw"></i> Admin Panel
                </a>
            </li>
            <?php endif; ?>

        </ul>


        </div>
    </nav>
    <!-- /.SIDEBAR -->

    <div id="page-wrapper">
        <div id="page-inner">
<!-- PAGE CONTENT STARTS HERE -->

<script>
// Live clock
(function tick() {
    var el = document.getElementById('topbar-clock');
    if (el) {
        var n = new Date();
        el.textContent = n.toLocaleDateString('en-GB', {weekday:'short', day:'2-digit', month:'short'})
            + ' · ' + n.toLocaleTimeString('en-GB', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
    }
    setTimeout(tick, 1000);
})();

// Mobile sidebar toggle
var sidebar = document.getElementById('sidebar');
var overlay = document.getElementById('sidebar-overlay');
var toggle  = document.getElementById('sidebar-toggle');

function openSidebar()  { sidebar.classList.add('open');    overlay.classList.add('show'); }
function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }

toggle.addEventListener('click', function () {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
});
overlay.addEventListener('click', closeSidebar);

// ── Notification bell ────────────────────────────────────────
(function () {
    var bell  = document.getElementById('notif-bell');
    var panel = document.getElementById('notif-panel');
    var list  = document.getElementById('notif-list');
    var count = document.getElementById('notif-count');
    var markReadBtn = document.getElementById('notif-mark-read');
    if (!bell || !panel) return;

    var lastUnreadCount = 0;
    var pollTimer = null;

    function esc(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function timeAgo(ts) {
        if (!ts) return '';
        var d = new Date(ts.replace(' ', 'T'));
        var diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 60)    return diff + 's ago';
        if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function wiggle() {
        bell.classList.add('wiggle');
        setTimeout(function () { bell.classList.remove('wiggle'); }, 1200);
    }

    function render(data) {
        var items = data.items || [];
        if (items.length === 0) {
            list.innerHTML = '<p class="dim" style="padding:14px;font-size:12px;text-align:center">No notifications</p>';
        } else {
            var html = '';
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                html += '<div class="notif-item-wrap' + (it.unread ? ' unread' : '') + '" style="display:flex;align-items:stretch;border-bottom:1px solid #F0F1F2">'
                     + '  <a href="' + esc(it.link) + '" class="notif-item" data-key="' + esc(it.key) + '" style="flex:1;text-decoration:none;border-bottom:none">'
                     + '    <div style="font-weight:600;color:#333333;margin-bottom:3px">' + esc(it.title) + '</div>'
                     + '    <div style="font-size:11px;color:#666;line-height:1.4">' + esc(it.desc) + '</div>'
                     + '    <div style="font-size:10px;color:#999;margin-top:4px">' + timeAgo(it.created_at) + '</div>'
                     + '  </a>'
                     + '  <button class="notif-dismiss" data-key="' + esc(it.key) + '" title="Dismiss" '
                     + '    style="background:none;border:none;color:#bbb;cursor:pointer;font-size:16px;padding:0 12px">×</button>'
                     + '</div>';
            }
            list.innerHTML = html;

            // Wire click-to-read: mark individual item as read when clicked
            list.querySelectorAll('.notif-item').forEach(function (link) {
                link.addEventListener('click', function () {
                    var key = link.getAttribute('data-key');
                    if (!key) return;
                    var wrap = link.closest('.notif-item-wrap');
                    if (wrap) wrap.classList.remove('unread');
                    var body = new URLSearchParams();
                    body.append('action', 'mark_one_read');
                    body.append('key', key);
                    fetch('/icecashRec/process/process_notifications.php', {
                        method: 'POST', body: body, credentials: 'same-origin'
                    });
                    // Update badge count immediately
                    var current = parseInt(count.textContent) || 0;
                    if (current > 0) {
                        current--;
                        count.textContent = current;
                        if (current === 0) count.classList.add('hidden');
                    }
                });
            });

            // Wire dismiss buttons
            list.querySelectorAll('.notif-dismiss').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var key = btn.getAttribute('data-key');
                    var wrap = btn.closest('.notif-item-wrap');
                    wrap.style.opacity = '0.3';
                    var body = new URLSearchParams();
                    body.append('action', 'dismiss');
                    body.append('key', key);
                    fetch('/icecashRec/process/process_notifications.php', {
                        method: 'POST', body: body, credentials: 'same-origin'
                    }).then(function () { loadNotifications(); });
                });
            });
        }
        var n = data.unread_count || 0;
        if (n > lastUnreadCount && lastUnreadCount > 0) {
            // New items arrived since last poll — draw attention
            wiggle();
        }
        lastUnreadCount = n;
        count.textContent = n > 99 ? '99+' : n;
        if (n > 0) count.classList.remove('hidden');
        else       count.classList.add('hidden');
    }

    function loadNotifications() {
        fetch('/icecashRec/process/process_notifications.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () {
                list.innerHTML = '<p class="dim" style="padding:14px;font-size:12px;text-align:center;color:#c0392b">Failed to load</p>';
            });
    }

    bell.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.classList.toggle('show');
        if (panel.classList.contains('show')) loadNotifications();
    });

    document.addEventListener('click', function (e) {
        if (panel.classList.contains('show') && !panel.contains(e.target) && e.target !== bell) {
            panel.classList.remove('show');
        }
    });

    markReadBtn.addEventListener('click', function (e) {
        e.preventDefault();
        // Mark each visible unread item individually
        var unreadItems = list.querySelectorAll('.notif-item-wrap.unread .notif-item');
        unreadItems.forEach(function (link) {
            var key = link.getAttribute('data-key');
            if (!key) return;
            var body = new URLSearchParams();
            body.append('action', 'mark_one_read');
            body.append('key', key);
            fetch('/icecashRec/process/process_notifications.php', {
                method: 'POST', body: body, credentials: 'same-origin'
            });
        });
        // Clear all unread styling immediately
        list.querySelectorAll('.unread').forEach(function (el) { el.classList.remove('unread'); });
        count.textContent = '0';
        count.classList.add('hidden');
    });

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(loadNotifications, 30000);
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    // Focus-aware polling: poll every 30s while visible, stop when hidden,
    // refetch immediately when the tab regains focus.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
        } else {
            loadNotifications();
            startPolling();
        }
    });
    window.addEventListener('focus', loadNotifications);

    loadNotifications();
    startPolling();
})();
</script>

<style>
@keyframes bellWiggle {
  0%,100% { transform: rotate(0deg); }
  15%     { transform: rotate(-15deg); }
  30%     { transform: rotate(12deg); }
  45%     { transform: rotate(-10deg); }
  60%     { transform: rotate(8deg); }
  75%     { transform: rotate(-5deg); }
}
.notification-bell.wiggle { animation: bellWiggle 1.2s ease-in-out; }
.notif-item-wrap:hover { background:#F8FAFB }
.notif-item-wrap.unread { background:#F0F8F5; border-left:3px solid #00a950 }
.notif-dismiss:hover { color:#c0392b !important }
</style>
