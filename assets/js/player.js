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

		renderInfo(track);
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

	wavesurfer.on('ready', () => { $('duration').textContent = formatTime(wavesurfer.getDuration()); });
	wavesurfer.on('timeupdate', (t) => { $('currentTime').textContent = formatTime(t); });
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

	function renderInfo(track) {
		$('trackTitle').textContent = track.title;
		$('trackArtist').textContent = track.artist;

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
			const meta = `v${track.versionNumber}${track.pinned ? ' · pinned' : ''}`;
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

		if (cfg.isAdmin && c.id) node.append(renderAdminControls(c, node));

		if (c.status !== 'pending') {
			node.append(el('span', { className: 'status-badge ' + c.status }, c.status === 'accepted' ? ' ✅ Resolved' : ' ❌ Declined'));
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

		const triage = (status, label) => {
			const btn = el('button', { className: 'triage-btn' }, label);
			btn.onclick = () => {
				statusMsg.textContent = 'Updating...';
				adminPost({ action: 'set_comment_status', comment_id: c.id, status })
					.then((res) => {
						if (!res.ok) throw new Error(res.status);
						c.status = status;
						statusMsg.textContent = status === 'accepted' ? 'Marked as Resolved ✅ ' : 'Marked as Declined ❌ ';
						node.style.opacity = status === 'rejected' ? '0.5' : '1';
					})
					.catch(() => { statusMsg.textContent = 'Error!'; });
			};
			return btn;
		};

		const del = el('button', { className: 'delete-comment-btn', style: 'background: #442222; border: 1px solid #663333;' }, '🗑️');
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
			});
		};

		return el('div', { className: 'admin-controls' }, triage('accepted', '👍'), triage('rejected', '👎'), statusMsg, del);
	}

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
			formData.append(isPlaylist ? 'playlist_slug' : 'project_slug', cfg.context.slug);

			const response = await fetch('/action.php', { method: 'POST', body: formData });
			const result = await response.json().catch(() => ({}));

			if (!response.ok) {
				alert(result.message || 'Could not post comment.');
				return;
			}

			textInput.value = '';
			const comment = { id: result.id, timestamp: target.time, author, text, status: 'pending' };
			track.comments.unshift(comment);
			if (target.index === current) {
				// Add to top of list, removing the "No comments yet" message if it's there
				const list = $('commentList');
				if (track.comments.length === 1) list.replaceChildren();
				list.prepend(renderComment(comment));
			}
			lock = null;
			showLock();
		};
	}

	loadTrack(0, false);
})();
