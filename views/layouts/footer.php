        </div>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" id="confirmModalLabel"><i class="fas fa-exclamation-triangle text-warning me-2"></i>Confirm Action</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-2" id="confirmModalBody">Are you sure?</div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmModalBtn"><i class="fas fa-check me-1"></i>Yes, Proceed</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BASE_URL = '<?= BASE_URL ?>';
const CSRF_TOKEN = '<?= csrf_token() ?>';

const _confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
let _confirmResolve = null;

function confirmAction(message, title) {
    return new Promise(resolve => {
        document.getElementById('confirmModalBody').textContent = message || 'Are you sure?';
        document.getElementById('confirmModalLabel').innerHTML =
            '<i class="fas fa-exclamation-triangle text-warning me-2"></i>' + (title || 'Confirm Action');
        _confirmResolve = resolve;
        _confirmModal.show();
    });
}

document.getElementById('confirmModalBtn').addEventListener('click', () => {
    _confirmModal.hide();
    if (_confirmResolve) { _confirmResolve(true); _confirmResolve = null; }
});
document.getElementById('confirmModal').addEventListener('hidden.bs.modal', () => {
    if (_confirmResolve) { _confirmResolve(false); _confirmResolve = null; }
});

function confirmForm(form, message, title) {
    confirmAction(message, title).then(ok => { if (ok) form.submit(); });
    return false;
}

function confirmClick(callback, message, title) {
    confirmAction(message, title).then(ok => { if (ok) callback(); });
    return false;
}

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('show');
    document.getElementById('sidebarOverlay').classList.toggle('show');
});

document.getElementById('sidebarOverlay')?.addEventListener('click', () => {
    document.getElementById('sidebar').classList.remove('show');
    document.getElementById('sidebarOverlay').classList.remove('show');
});

function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const icons = { success: 'fa-check-circle', error: 'fa-times-circle', info: 'fa-info-circle' };
    const toast = document.createElement('div');
    toast.className = 'toast-custom ' + type;
    toast.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '"></i><span>' + message + '</span>';
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateX(100%)'; setTimeout(() => toast.remove(), 300); }, 4000);
}

<?php if ($msg = flash('success')): ?>
showToast(<?= json_encode($msg) ?>, 'success');
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
showToast(<?= json_encode($msg) ?>, 'error');
<?php endif; ?>

(function() {
    const POLL_INTERVAL = 10000;
    let lastCount = -1;

    function updateNotifBadge(count) {
        const badge = document.getElementById('notifBadge');
        const forumBadges = document.querySelectorAll('.forum-notif-badge');
        if (!badge) return;

        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = '';
            forumBadges.forEach(b => { b.textContent = count > 99 ? '99+' : count; b.style.display = ''; });
        } else {
            badge.style.display = 'none';
            forumBadges.forEach(b => b.style.display = 'none');
        }

        if (lastCount >= 0 && count > lastCount) {
            const btn = document.getElementById('notifBellBtn');
            if (btn) { btn.classList.add('notif-bell-ring'); setTimeout(() => btn.classList.remove('notif-bell-ring'), 1500); }
        }
        lastCount = count;
    }

    function fetchNotifCount() {
        fetch(BASE_URL + '/forum-api.php?action=notification_count')
            .then(r => r.json())
            .then(d => updateNotifBadge(d.count || 0))
            .catch(() => {});
    }

    function loadNotifications() {
        fetch(BASE_URL + '/forum-api.php?action=notifications')
            .then(r => r.json())
            .then(d => {
                const list = document.getElementById('notifList');
                const empty = document.getElementById('notifEmpty');
                if (!list) return;

                const notifs = d.notifications || [];
                if (notifs.length === 0) {
                    list.innerHTML = '';
                    list.appendChild(empty || createEmptyNotif());
                    return;
                }

                const typeIcons = { like: 'fa-heart text-danger', reply: 'fa-reply text-primary', mention: 'fa-at text-info', pin: 'fa-thumbtack text-warning', lock: 'fa-lock text-secondary', join_approved: 'fa-check-circle text-success', join_declined: 'fa-times-circle text-danger', join_request: 'fa-user-plus text-info', ticket_reply: 'fa-ticket-alt text-primary', ticket_update: 'fa-ticket-alt text-warning', ticket_closed: 'fa-ticket-alt text-secondary', new_activity: 'fa-clipboard-list text-primary', student_submission: 'fa-file-upload text-info', submission_graded: 'fa-check-double text-success' };
                let html = '';
                notifs.forEach(n => {
                    const icon = typeIcons[n.type] || 'fa-bell text-primary';
                    const unreadClass = n.is_read ? '' : 'notif-unread';
                    let link = '#';
                    if (n.source === 'system' && n.link) {
                        link = n.link;
                    } else if (n.thread_id) {
                        link = BASE_URL + '/forum-thread.php?id=' + n.thread_id;
                    }
                    const markSource = n.source || 'forum';
                    html += '<a href="' + link + '" class="dropdown-item notif-item ' + unreadClass + '" onclick="markOneRead(' + n.id + ', \'' + markSource + '\')" style="white-space:normal;padding:10px 16px;border-bottom:1px solid var(--gray-100);">';
                    html += '<div class="d-flex gap-2 align-items-start">';
                    html += '<div class="flex-shrink-0 mt-1"><i class="fas ' + icon + '" style="font-size:0.85rem;"></i></div>';
                    html += '<div class="flex-grow-1">';
                    html += '<div style="font-size:0.82rem;line-height:1.4;">' + escHtml(n.message) + '</div>';
                    html += '<div class="text-muted" style="font-size:0.7rem;margin-top:2px;"><i class="far fa-clock me-1"></i>' + n.time_ago + '</div>';
                    html += '</div></div></a>';
                });
                list.innerHTML = html;
            })
            .catch(() => {});
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    window.markAllRead = function() {
        fetch(BASE_URL + '/forum-api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=mark_read&notification_id=all&csrf_token=' + CSRF_TOKEN
        }).then(() => { fetchNotifCount(); loadNotifications(); });
    };

    window.markOneRead = function(id, source) {
        fetch(BASE_URL + '/forum-api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=mark_read&notification_id=' + id + '&source=' + (source || 'forum') + '&csrf_token=' + CSRF_TOKEN
        }).then(() => fetchNotifCount());
    };

    const bellBtn = document.getElementById('notifBellBtn');
    if (bellBtn) {
        bellBtn.addEventListener('click', loadNotifications);
    }

    fetchNotifCount();
    setInterval(fetchNotifCount, POLL_INTERVAL);
})();
</script>
</body>
</html>
