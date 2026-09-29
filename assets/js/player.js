// player.js - the audio player for both single tracks and playlists.
// A single track page is just a playlist of one. The page provides window.NEXTSOUND_PLAYER:
//   tracks    - [{versionId, versionNumber, pinned, title, artist, notes, changelog, audioUrl, downloadUrl, comments[]}]
//   context   - {type: 'track'|'playlist', slug, title}
//   isAdmin, csrfToken, primaryColor
(function () {
	const cfg = window.NEXTSOUND_PLAYER;
	const tracks = cfg.tracks;
	const isPlaylist = cfg.context.type === 'playlist';
	const $ = (id) => document.getElementById(id);

	let current = 0;     // index of the loaded track
	let loadToken = 0;   // guards against a slow load finishing after the listener already skipped ahead

	// play counting: a play counts once the listener has actually heard this many seconds of a track
	const PLAY_THRESHOLD = 5;
	let listened = 0;        // seconds heard of the current track (seeking doesn't count)
	let lastTime = 0;
	let playRecorded = false;

	const wavesurfer = WaveSurfer.create({
		container: '#waveform',
		waveColor: '#555',
		progressColor: cfg.primaryColor,
		cursorColor: '#fff',
		barWidth: 2,
		height: 128,
	});

	function formatTime(s) {
		s = Number(s) || 0;
		const min = Math.floor(s / 60);
		const sec = Math.floor(s % 60);
		return `${min}:${sec < 10 ? '0' : ''}${sec}`;
	}

	function el(tag, props = {}, ...children) {
		const node = Object.assign(document.createElement(tag), props);
		node.append(...children);
		return node;
	}

	// ---------- loading tracks ----------

	function loadTrack(index, autoplay) {
		current = index;
		const track = tracks[index];
		const token = ++loadToken;

		listened = 0;
		lastTime = 0;
		playRecorded = false;
		markersReady = false;
		renderMarkers();
		renderInfo(track);
		document.dispatchEvent(new CustomEvent('nextsound:track', { detail: track }));
		renderComments(track);
		renderTracklist();
		resetCommentLock();
		$('currentTime').textContent = '0:00';
		$('duration').textContent = '0:00';

		wavesurfer.once('ready', () => {
			if (token === loadToken && autoplay) wavesurfer.play().catch(() => {});
		});
		wavesurfer.load(track.audioUrl).catch(() => {}); // rejects when a newer load replaces this one

		updateMediaSession(track);
		if (isPlaylist) document.title = `${track.title} · ${cfg.context.title}`;
	}

	function playNext() { if (current < tracks.length - 1) loadTrack(current + 1, true); }
	function playPrev() {
		// like most players: restart the track unless we're right at its start
		if (wavesurfer.getCurrentTime() > 3 || current === 0) { wavesurfer.setTime(0); return; }
		loadTrack(current - 1, true);
	}

	wavesurfer.on('ready', () => {
		$('duration').textContent = formatTime(wavesurfer.getDuration());
		markersReady = true;
		renderMarkers();
	});
	wavesurfer.on('timeupdate', (t) => {
		$('currentTime').textContent = formatTime(t);
		trackListening(t);
	});

	// Add up real listening time: small forward steps while playing count, jumps (seeking) don't.
	// Very short tracks count once they're almost finished.
	function trackListening(t) {
		const step = t - lastTime;
		lastTime = t;
		if (playRecorded || !wavesurfer.isPlaying() || step <= 0 || step > 1.5) return;
		listened += step;
		const needed = Math.min(PLAY_THRESHOLD, wavesurfer.getDuration() * 0.9 || PLAY_THRESHOLD);
		if (listened >= needed) recordPlay(tracks[current]);
	}

	function recordPlay(track) {
		playRecorded = true;
		const body = new URLSearchParams({ action: 'record_play', version_id: track.versionId });
		body.append(isPlaylist ? 'playlist_slug' : 'project_slug', cfg.context.slug);
		fetch('/action.php', { method: 'POST', body })
			.then((res) => res.json())
			.then((data) => {
				// only present when counts are public; refresh the number on screen
				if (data.plays === undefined || track.plays === undefined) return;
				tracks.forEach((t) => { if (t.title === track.title && t.plays !== undefined) t.plays = data.plays; });
				if (tracks[current] === track) renderPlays(track);
				renderTracklist();
			})
			.catch(() => {}); // a missed count isn't worth bothering the listener about
	}
	wavesurfer.on('finish', playNext); // playlists roll straight into the next track

	$('playPause').onclick = () => wavesurfer.playPause();
	if ($('nextBtn')) $('nextBtn').onclick = playNext;
	if ($('prevBtn')) $('prevBtn').onclick = playPrev;

	function seekTo(seconds) {
		wavesurfer.setTime(seconds);
		wavesurfer.play().catch(() => {});
	}

	// lock-screen / media key controls on phones and desktops
	function updateMediaSession(track) {
		if (!('mediaSession' in navigator)) return;
		navigator.mediaSession.metadata = new MediaMetadata({ title: track.title, artist: track.artist, album: isPlaylist ? cfg.context.title : '' });
		navigator.mediaSession.setActionHandler('play', () => wavesurfer.play());
		navigator.mediaSession.setActionHandler('pause', () => wavesurfer.pause());
		navigator.mediaSession.setActionHandler('nexttrack', isPlaylist ? playNext : null);
		navigator.mediaSession.setActionHandler('previoustrack', isPlaylist ? playPrev : null);
	}

	// ---------- track info ----------

	function formatPlays(n) {
		return `${n.toLocaleString()} play${n === 1 ? '' : 's'}`;
	}

	function renderPlays(track) {
		const el = $('trackPlays');
		el.hidden = track.plays === undefined;
		if (track.plays !== undefined) el.textContent = ' · ▶ ' + formatPlays(track.plays);
	}

	function renderInfo(track) {
		$('trackTitle').textContent = track.title;
		$('trackArtist').textContent = track.artist;
		renderPlays(track);

		$('trackNotes').textContent = track.notes;
		const dl = $('downloadLink');
		dl.hidden = !track.downloadUrl;
		if (track.downloadUrl) dl.href = track.downloadUrl;
		$('trackNotesBox').hidden = !track.notes && !track.downloadUrl;

		$('versionBadge').textContent = `Version ${track.versionNumber}${track.pinned ? ' (pinned)' : ''} Notes:`;
		$('changelogText').textContent = track.changelog || 'No change notes for this mix.';
	}

	function renderTracklist() {
		const list = $('tracklist');
		if (!list) return;
		list.replaceChildren(...tracks.map((track, i) => {
			const meta = `v${track.versionNumber}${track.pinned ? ' · pinned' : ''}${track.plays !== undefined ? ' · ' + formatPlays(track.plays) : ''}`;
			const item = el('li', { className: 'tracklist-item' + (i === current ? ' active' : '') },
				el('span', { className: 'tracklist-title' }, track.title),
				el('span', { className: 'tracklist-artist' }, track.artist),
				el('span', { className: 'tracklist-meta' }, meta));
			item.onclick = () => loadTrack(i, true);
			return item;
		}));
	}

	// ---------- comments ----------

	function renderComments(track) {
		const list = $('commentList');
		if (!list) return; // comments hidden on this site
		if (track.comments.length === 0) {
			list.replaceChildren(el('p', { style: 'color: #777;' }, 'No comments yet. Be the first to ruin the mix.'));
			return;
		}
		list.replaceChildren(...track.comments.map(renderComment));
	}

	function renderComment(c) {
		const time = el('span', { className: 'timestamp' }, formatTime(c.timestamp));
		time.onclick = () => seekTo(c.timestamp);

		const node = el('div', { className: 'comment', id: 'comment-container-' + c.id },
			time, el('strong', {}, c.author + ':'), ' ', c.text);
		if (c.status === 'rejected') node.style.opacity = '0.5';
		// only the admin ever receives held comments
		if (c.held) {
			node.classList.add('comment-held');
			node.append(el('span', { className: 'held-tag' }, ' ⏳ Awaiting approval - only you can see this'));
		}

		if (cfg.isAdmin && c.id) node.append(renderAdminControls(c, node));

		if (c.status !== 'pending') {
			// shown to everyone, so commenters can see what happened to their feedback
			node.append(c.status === 'accepted'
				? el('span', { className: 'status-badge accepted', title: 'The artist is incorporating this feedback into the next version' }, ' ✅ Resolved')
				: el('span', { className: 'status-badge rejected', title: "The artist doesn't plan to act on this feedback" }, ' ❌ Declined'));
		}
		return node;
	}

	function adminPost(params) {
		return fetch('/admin/api.php', {
			method: 'POST',
			body: new URLSearchParams({ ...params, csrf_token: cfg.csrfToken }),
		});
	}

	function renderAdminControls(c, node) {
		const statusMsg = el('span', { className: 'status-badge' });

		// 👍 = incorporated into the next version, 👎 = won't be implemented.
		// The chosen one looks pressed; clicking it again clears the decision back to undecided.
		const TRIAGE_HELP = {
			accepted: 'Incorporated - this feedback is going into the next version',
			rejected: "Not planned - this feedback won't be implemented",
		};
		const triage = (status, label) => {
			const active = c.status === status;
			const btn = el('button', {
				className: 'triage-btn' + (active ? ' active ' + status : ''),
				title: active ? TRIAGE_HELP[status] + ' (click again to clear)' : 'Mark as: ' + TRIAGE_HELP[status],
			}, label);
			btn.setAttribute('aria-pressed', active);
			btn.onclick = () => {
				const newStatus = active ? 'pending' : status;
				statusMsg.textContent = 'Updating...';
				adminPost({ action: 'set_comment_status', comment_id: c.id, status: newStatus })
					.then((res) => {
						if (!res.ok) throw new Error(res.status);
						c.status = newStatus;
						node.replaceWith(renderComment(c)); // redraw with the new badge and pressed button
					})
					.catch(() => { statusMsg.textContent = 'Error!'; });
			};
			return btn;
		};

		const del = el('button', { className: 'delete-comment-btn', title: 'Delete this comment', style: 'background: #442222; border: 1px solid #663333;' }, '🗑️');
		del.onclick = () => {
			if (!confirm('Are you sure you want to delete this comment?')) return;
			adminPost({ action: 'delete_comment', comment_id: c.id }).then((res) => {
				if (!res.ok) return;
				const track = tracks.find((t) => t.comments.includes(c));
				if (track) track.comments.splice(track.comments.indexOf(c), 1);
				// Smoothly hide and remove the element
				node.style.transition = 'opacity 0.3s, transform 0.3s';
				node.style.opacity = '0';
				node.style.transform = 'translateX(20px)';
				setTimeout(() => node.remove(), 300);
				renderMarkers();
			});
		};

		const controls = [triage('accepted', '👍'), triage('rejected', '👎'), statusMsg, del];
		if (c.held) {
			// approving makes it visible to everyone; denying = the 🗑️ delete button
			const approve = el('button', { className: 'btn btn-sm approve-btn', title: 'Approve - make this comment visible to everyone' }, 'Approve');
			approve.onclick = () => {
				approve.disabled = true;
				adminPost({ action: 'approve_comment', comment_id: c.id, ajax: '1' })
					.then((res) => { if (!res.ok) throw new Error(res.status); markApproved(c.id); })
					.catch(() => { approve.disabled = false; statusMsg.textContent = 'Error!'; });
			};
			del.title = 'Deny - delete this comment';
			controls.unshift(approve);
		}
		return el('div', { className: 'admin-controls' }, ...controls);
	}

	// A held comment was approved (here or from the 🔔 menu): update it in place
	function markApproved(id) {
		tracks.forEach((t) => t.comments.forEach((c) => { if (c.id === id) c.held = false; }));
		const node = document.getElementById('comment-container-' + id);
		if (node) {
			node.classList.remove('comment-held');
			node.querySelectorAll('.held-tag, .approve-btn').forEach((x) => x.remove());
		}
		renderMarkers();
	}
	document.addEventListener('nextsound:comment-approved', (e) => markApproved(e.detail));
	document.addEventListener('nextsound:comment-deleted', (e) => {
		tracks.forEach((t) => { t.comments = t.comments.filter((c) => c.id !== e.detail); });
		const node = document.getElementById('comment-container-' + e.detail);
		if (node) node.remove();
		renderMarkers();
	});

	// ---------- comment markers along the top of the waveform ----------
	// Comments closer together on screen than MARKER_GAP px (measured from the first comment
	// of a group) share one numbered dot, so dots never overlap however long the track or narrow the screen.

	const MARKER_GAP = 16;
	let markersReady = false; // the current track's duration is known
	let tip = null;           // open tooltip: { group, pinned, box, dot }
	let lastPointer = 'mouse';

	function markerGroups(comments, duration, width) {
		const gap = (MARKER_GAP / width) * duration;
		const groups = [];
		for (const c of [...comments].sort((a, b) => a.timestamp - b.timestamp)) {
			const group = groups[groups.length - 1];
			if (group && c.timestamp - group[0].timestamp < gap) group.push(c);
			else groups.push([c]);
		}
		return groups;
	}

	function renderMarkers() {
		const box = $('markers');
		if (!box) return;
		hideTip();
		box.replaceChildren();
		const duration = wavesurfer.getDuration();
		const comments = tracks[current].comments.filter((c) => c.id);
		if (!markersReady || !duration || !comments.length) return;

		markerGroups(comments, duration, box.clientWidth || 800).forEach((group) => {
			// placed at the group's first comment, so dots are always at least MARKER_GAP apart
			const at = Math.min(group[0].timestamp, duration);
			const many = group.length > 1;
			const dot = el('button', {
				type: 'button',
				className: 'marker' + (many ? ' cluster' : '') + (!many && group[0].held ? ' held' : '') + (many && group.some((c) => c.held) ? ' has-held' : ''),
			}, many ? String(group.length) : '');
			dot.style.left = (at / duration * 100) + '%';
			dot.setAttribute('aria-label', many ? `${group.length} comments around ${formatTime(group[0].timestamp)}` : `Comment at ${formatTime(group[0].timestamp)} by ${group[0].author}`);

			dot.addEventListener('pointerdown', (e) => { lastPointer = e.pointerType; });
			dot.addEventListener('mouseenter', () => showTip(dot, group, at / duration, false));
			dot.addEventListener('mouseleave', () => { if (tip && !tip.pinned) hideTip(); });
			dot.addEventListener('focus', () => showTip(dot, group, at / duration, false));
			dot.addEventListener('blur', () => { if (tip && !tip.pinned) hideTip(); });
			dot.addEventListener('click', (e) => {
				e.stopPropagation();
				const alreadyOpen = tip && tip.group === group && tip.pinned;
				// single dot: jump (on touch, the first tap only shows the comment); group: open its list
				if (!many && (lastPointer !== 'touch' || alreadyOpen)) { jumpToComment(group[0]); return; }
				showTip(dot, group, at / duration, true);
			});
			box.append(dot);
		});
	}

	function showTip(dot, group, fraction, pinned) {
		hideTip();
		dot.classList.add('active');
		const rows = group.map((c) => {
			const row = el('span', { className: 'row' },
				el('span', { className: 't' }, formatTime(c.timestamp)), el('span', { className: 'who' }, c.author), ': ', c.text);
			if (c.held) row.append(el('span', { className: 'held-note' }, '⏳ awaiting approval'));
			row.addEventListener('click', (e) => { e.stopPropagation(); jumpToComment(c); });
			return row;
		});
		const hint = group.length > 1 ? 'click a comment to jump there' : (lastPointer === 'touch' ? 'tap again to jump there' : 'click to jump there');
		const box = el('div', { className: 'marker-tip' }, ...rows, el('span', { className: 'hint' }, hint));
		box.style.left = Math.min(Math.max(fraction * 100, 12), 88) + '%';
		$('markers').append(box);
		tip = { group, pinned, box, dot };
	}

	function hideTip() {
		if (!tip) return;
		tip.box.remove();
		tip.dot.classList.remove('active');
		tip = null;
	}
	document.addEventListener('click', hideTip);

	function jumpToComment(c) {
		hideTip();
		seekTo(c.timestamp);
		flashComment(c.id);
	}

	let resizeTimer;
	window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(renderMarkers, 150); });

	// ---------- comment form ----------
	// Clicking into the box locks both the time AND the track, so a comment typed while
	// the playlist rolls into the next song still lands on the song it was about.

	const form = $('commentForm');
	const textInput = $('textInput');
	let lock = null; // {index, time}

	function showLock() {
		const index = lock ? lock.index : current;
		$('commentTime').textContent = formatTime(lock ? lock.time : 0);
		if ($('commentTrack')) $('commentTrack').textContent = tracks[index].title;
	}

	function resetCommentLock() {
		if (!form) return;
		// keep a half-written comment attached to the track it was started on
		if (lock && textInput.value.trim() !== '') { showLock(); return; }
		lock = null;
		showLock();
	}

	function showNotice(message) {
		const notice = $('commentNotice');
		notice.textContent = message;
		notice.hidden = false;
		clearTimeout(showNotice.timer);
		showNotice.timer = setTimeout(() => { notice.hidden = true; }, 8000);
	}

	if (form) {
		textInput.onfocus = () => {
			lock = { index: current, time: wavesurfer.getCurrentTime() };
			showLock();
		};

		form.onsubmit = async (e) => {
			e.preventDefault();
			const target = lock || { index: current, time: wavesurfer.getCurrentTime() };
			const track = tracks[target.index];
			const author = $('authorInput').value.trim() || 'Anonymous';
			const text = textInput.value.trim();
			if (!text) return;

			const formData = new FormData();
			formData.append('action', 'add_comment');
			formData.append('version_id', track.versionId);
			formData.append('timestamp', target.time);
			formData.append('author', author);
			formData.append('text', text);
			formData.append('website', $('websiteInput').value); // honeypot - always empty for real people
			formData.append(isPlaylist ? 'playlist_slug' : 'project_slug', cfg.context.slug);

			const response = await fetch('/action.php', { method: 'POST', body: formData });
			const result = await response.json().catch(() => ({}));

			if (!response.ok) {
				alert(result.message || 'Could not post comment.');
				return;
			}

			textInput.value = '';

			// held for approval: tell the poster, and don't show it (it isn't public yet)
			if (result.held) {
				showNotice('Thanks! Your comment will appear once it has been approved.');
				lock = null;
				showLock();
				return;
			}

			const comment = { id: result.id, timestamp: target.time, author, text, status: 'pending' };
			track.comments.unshift(comment);
			if (target.index === current) {
				// Add to top of list, removing the "No comments yet" message if it's there
				const list = $('commentList');
				if (track.comments.length === 1) list.replaceChildren();
				list.prepend(renderComment(comment));
				renderMarkers();
			}
			lock = null;
			showLock();
		};
	}

	// scroll to a comment in the list and flash it
	function flashComment(id) {
		const comment = document.getElementById('comment-container-' + id);
		if (!comment) return;
		comment.scrollIntoView({ behavior: 'smooth', block: 'center' });
		comment.classList.remove('comment-highlight');
		void comment.offsetWidth; // restart the animation if it's the same comment again
		comment.classList.add('comment-highlight');
	}

	// Arriving from the admin's notifications menu (#comment-123)
	function highlightLinkedComment() {
		const match = location.hash.match(/^#comment-(\d+)$/);
		if (match) flashComment(match[1]);
	}
	window.addEventListener('hashchange', highlightLinkedComment);

	loadTrack(0, false);
	highlightLinkedComment();
})();
