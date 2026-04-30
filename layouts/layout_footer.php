<!-- ============================================================
     layouts/layout_footer.php — Shared Page Footer
     Closes the page wrapper, loads jQuery + Bootstrap JS,
     renders the live clock, sidebar toggle, and global search.
     Part of IceCashRec — Zimnat General Insurance
     ============================================================ -->
<!-- PAGE CONTENT ENDS HERE -->

        <!-- Page footer with clock -->
        <div style="margin-top:30px;padding:14px 20px;border-top:1px solid #e0e0e0;display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#888">
            <span>Zimnat General Insurance &middot; IcecashRec v1.0</span>
            <span>
                <span class="status-pill live" style="font-size:10px;padding:2px 8px;margin-right:6px">● LIVE</span>
                <span id="topbar-clock" style="letter-spacing:1px;color:#555">--:--</span>
            </span>
        </div>

        </div><!-- /#page-inner -->
    </div><!-- /#page-wrapper -->

</div><!-- /#wrapper -->

<!-- jQuery + Bootstrap JS via CDN -->
<script src="https://code.jquery.com/jquery-1.12.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>

<script>
// ── Live Clock (Update only on minute change) ──
function updateClock() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const newTime = `${hours}:${minutes}`;
    
    const clockElement = document.getElementById('topbar-clock');
    if (clockElement && clockElement.textContent !== newTime) {
        clockElement.textContent = newTime;
    }
    
    // Calculate milliseconds until next minute
    const secondsUntilNextMinute = 60 - now.getSeconds();
    const msUntilNextMinute = (secondsUntilNextMinute * 1000) + 100;
    
    // Schedule next update exactly when minute changes
    setTimeout(updateClock, msUntilNextMinute);
}

// Initial update
updateClock();

// ── Sidebar Toggle (Mobile) ──
document.getElementById('sidebar-toggle')?.addEventListener('click', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    sidebar.style.left = sidebar.style.left === '0px' ? '-260px' : '0px';
    overlay.classList.toggle('show');
});

document.getElementById('sidebar-overlay')?.addEventListener('click', function() {
    document.getElementById('sidebar').style.left = '-260px';
    this.classList.remove('show');
});

// Global search
var _gsTimer = null;
function globalSearch(q) {
    clearTimeout(_gsTimer);
    var box = document.getElementById('search-results');
    if (!box) return;
    if (q.length < 3) { box.style.display = 'none'; return; }
    _gsTimer = setTimeout(function() {
        fetch('<?= BASE_URL ?>/process/process_search.php?q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.results || data.results.length === 0) {
                    box.innerHTML = '<div style="padding:16px;text-align:center;color:#888;font-size:12px">No results for "' + q.replace(/</g,'&lt;') + '"</div>';
                    box.style.display = 'block';
                    return;
                }
                var html = '';
                data.results.forEach(function(r) {
                    html += '<a href="' + r.url + '" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f0f0f0;text-decoration:none;color:#333;font-size:12px"'
                         + ' onmouseover="this.style.background=\'#f5fbf7\'" onmouseout="this.style.background=\'#fff\'">'
                         + '<i class="fa ' + r.icon + '" style="color:#00a950;width:16px;text-align:center"></i>'
                         + '<div style="flex:1;overflow:hidden">'
                         + '<div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + r.title + '</div>'
                         + '<div style="color:#888;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + r.sub + '</div>'
                         + '</div>'
                         + '<span style="background:#f0f0f0;padding:2px 6px;border-radius:3px;font-size:10px;color:#666;white-space:nowrap">' + r.type + '</span>'
                         + '</a>';
                });
                box.innerHTML = html;
                box.style.display = 'block';
            })
            .catch(function() { box.style.display = 'none'; });
    }, 300);
}
</script>

</body>
</html>