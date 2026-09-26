// notifications.js - the admin's 🔔 menu of comments to review in the top bar.
// Clicking a notification marks it read and opens the comment; "Mark all as read" clears the unread ones.
// Comments held for approval stay in the menu until they're approved or denied (deleted) right here.
(function () {
	const root = document.getElementById('notif');
	if (!root) return;
	const $ = (id) => document.getElementById(id);
	const bell = $('notifBell');
	const panel = $('notifPanel');
	const badge = $('notifBadge');
	const list = $('notifList');
	const markAll = $('notifMarkAll');

	function setOpen(open) {
		panel.hidden = !open;
		bell.setAttribute('aria-expanded', open);
	}
	bell.addEventListener('click', (e) => { e.stopPropagation(); setOpen(panel.hidden); });
	document.addEventListener('click', (e) => { if (!root.contains(e.target)) setOpen(false); });
	document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setOpen(false); });

	function setCount(n) {
		badge.hidden = n === 0;
		badge.textContent = n > 99 ? '99+' : n;
		markAll.hidden = n === 0;
		if (n === 0) {
			$('notifEmpty').hidden = false;
			if ($('notifMore')) $('notifMore').remove();
		}
	}

	function post(params) {
		// keepalive lets the request finish even if the page is navigating away
		return fetch('/admin/api.php', {
			method: 'POST',
			keepalive: true,
			body: new URLSearchParams({ ...params, ajax: '1', csrf_token: root.dataset.csrf }),
		}).then((res) => res.json());
	}

	// Held comments stay listed (they still need a decision); others leave the menu once read
	function markRead(item) {
		if (!item.classList.contains('notif-held')) item.remove();
		return post({ action: 'mark_notification_read', comment_id: item.dataset.id })
			.then((data) => setCount(data.unread))
			.catch(() => {});
	}

	// Approve / Deny a held comment without leaving the page
	list.addEventListener('click', (e) => {
		const button = e.target.closest('.notif-approve, .notif-deny');
		if (!button) return;
		const item = button.closest('.notif-item');
		const approve = button.classList.contains('notif-approve');
		if (!approve && !confirm('Deny this comment? It will be deleted.')) return;
		item.querySelectorAll('button').forEach((b) => { b.disabled = true; });
		post({ action: approve ? 'approve_comment' : 'delete_comment', comment_id: item.dataset.id })
			.then((data) => {
				item.remove();
				setCount(data.unread);
				if (approve) document.dispatchEvent(new CustomEvent('nextsound:comment-approved', { detail: +item.dataset.id }));
				else document.dispatchEvent(new CustomEvent('nextsound:comment-deleted', { detail: +item.dataset.id }));
			})
			.catch(() => {
				item.querySelectorAll('button').forEach((b) => { b.disabled = false; });
				alert('That didn\'t work - try reloading the page.');
			});
	});

	// click-through: mark read first, then go to the comment
	list.addEventListener('click', (e) => {
		const link = e.target.closest('.notif-link');
		if (!link) return;
		const item = link.closest('.notif-item');
		if (e.ctrlKey || e.metaKey || e.shiftKey) { markRead(item); return; } // opening in a new tab/window
		e.preventDefault();
		markRead(item).finally(() => { location.href = link.href; });
	});
	// middle-click opens a new tab without a 'click' event
	list.addEventListener('auxclick', (e) => {
		const link = e.target.closest('.notif-link');
		if (link && e.button === 1) markRead(link.closest('.notif-item'));
	});

	markAll.addEventListener('click', () => {
		markAll.disabled = true;
		post({ action: 'mark_all_notifications_read' })
			.then((data) => {
				list.querySelectorAll('.notif-item:not(.notif-held)').forEach((item) => item.remove());
				setCount(data.unread);
			})
			.catch(() => alert('Could not mark the notifications as read. Try reloading the page.'))
			.finally(() => { markAll.disabled = false; });
	});
})();
