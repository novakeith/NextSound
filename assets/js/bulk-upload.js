// bulk-upload.js - the "Upload Tracks" drop zone on the admin dashboard.
// Pick or drop one or more files, fill in each track's details, then Upload.
// Files are sent one at a time (each as a normal new-project upload) so every file gets its own
// progress bar, one failure doesn't sink the batch, and no single request hits the server's size limit.
(function () {
	const cfg = window.NEXTSOUND_UPLOAD;
	const $ = (id) => document.getElementById(id);
	const AUDIO_EXTENSIONS = ['mp3', 'wav', 'ogg', 'flac'];

	const dropZone = $('dropZone');
	const fileInput = $('fileInput');
	const panel = $('uploadPanel');
	const rowList = $('uploadRows');
	const uploadBtn = $('uploadBtn');
	const clearBtn = $('clearBtn');
	const summary = $('uploadSummary');
	const makePlaylist = $('makePlaylist');       // missing until the playlist db update has been run
	const playlistTitle = $('playlistTitle');

	const rows = new Map(); // row element -> {file, status: 'pending'|'uploading'|'done'|'error', projectId, slug}
	let busy = false;
	let playlistCreated = false;
	let nextId = 0;

	// ---------- helpers ----------

	function el(tag, props = {}, ...children) {
		const node = Object.assign(document.createElement(tag), props);
		node.append(...children);
		return node;
	}

	function formatSize(bytes) {
		return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
	}

	// "03_my_song_final.wav" -> "my song final";  "01 - Intro.flac" -> "Intro"
	function titleFromFilename(name) {
		const title = name
			.replace(/\.[^.]+$/, '')                  // extension
			.replace(/^\d{1,3}[\s._-]+(-\s*)?/, '')   // leading track number
			.replace(/_+/g, ' ')
			.replace(/\s+/g, ' ')
			.trim();
		return title || name;
	}

	function orderedRows() {
		return [...rowList.children].filter((node) => rows.has(node));
	}

	function showSummary(content, isError = false) {
		summary.hidden = false;
		summary.classList.toggle('error', isError);
		summary.replaceChildren(...[].concat(content));
	}

	// ---------- adding files ----------

	function addFiles(fileList) {
		if (busy) return;
		const skipped = [];
		const files = [...fileList].sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }));

		for (const file of files) {
			const ext = file.name.split('.').pop().toLowerCase();
			if (!AUDIO_EXTENSIONS.includes(ext) && !file.type.startsWith('audio/')) {
				skipped.push(`${file.name} (not a supported audio file)`);
			} else if (cfg.maxBytes && file.size > cfg.maxBytes) {
				skipped.push(`${file.name} (${formatSize(file.size)} - the limit is ${formatSize(cfg.maxBytes)})`);
			} else if ([...rows.values()].some((r) => r.file.name === file.name && r.file.size === file.size && r.status !== 'done')) {
				skipped.push(`${file.name} (already in the list)`);
			} else {
				addRow(file);
			}
		}

		panel.hidden = rows.size === 0;
		$('reorderHint').hidden = rows.size < 2;
		updateButton();
		if (skipped.length) showSummary(['Skipped: ', skipped.join(', ')], true);
		else summary.hidden = true;
	}

	function addRow(file) {
		const id = 'up' + nextId++;
		// new rows inherit the artist from the "apply to all" box, or else the row above
		const lastRow = orderedRows().pop();
		const artist = $('applyArtist').value.trim() || (lastRow ? lastRow.querySelector('.f-artist').value : '');

		const title = el('input', { type: 'text', className: 'f-title', placeholder: 'Title *', value: titleFromFilename(file.name) });
		const artistInput = el('input', { type: 'text', className: 'f-artist', placeholder: 'Artist *', value: artist });
		const notes = el('textarea', { className: 'f-notes', placeholder: 'Backstory (optional)', rows: 2 });
		const changelog = el('input', { type: 'text', className: 'f-changelog', placeholder: 'Notes for this version (optional)' });
		const downloads = el('input', { type: 'checkbox', className: 'f-downloads checkbox', id: id + '-dl' });
		const remove = el('button', { type: 'button', className: 'btn btn-sm btn-danger remove-row', title: 'Remove from list' }, '✕');

		// header line: drag handle, file name + size, remove button - then the fields underneath
		const row = el('div', { className: 'upload-row' },
			el('div', { className: 'upload-head' },
				el('span', { className: 'drag-handle', title: 'Drag to reorder' }, '⠿'),
				el('div', { className: 'upload-file' }, file.name, el('small', {}, ' · ' + formatSize(file.size))),
				remove),
			el('div', { className: 'upload-grid' },
				title, artistInput, notes, changelog,
				el('label', { className: 'upload-dl', htmlFor: id + '-dl' }, downloads, ' Allow downloads')),
			el('div', { className: 'upload-progress' }, el('div', { className: 'upload-progress-bar' })),
			el('div', { className: 'upload-status' }));

		remove.onclick = () => {
			if (busy) return;
			rows.delete(row);
			row.remove();
			panel.hidden = rows.size === 0;
			$('reorderHint').hidden = rows.size < 2;
			updateButton();
		};

		row.addEventListener('input', (e) => e.target.classList.remove('invalid'));
		rows.set(row, { file, status: 'pending', projectId: null, slug: null });
		rowList.append(row);
	}

	// ---------- per-row state ----------

	function setProgress(row, fraction) {
		row.querySelector('.upload-progress').classList.add('active');
		row.querySelector('.upload-progress-bar').style.width = Math.round(fraction * 100) + '%';
	}

	function setStatus(row, status, message, link) {
		rows.get(row).status = status;
		row.classList.remove('is-uploading', 'is-done', 'is-error');
		if (status !== 'pending') row.classList.add('is-' + status);
		const statusEl = row.querySelector('.upload-status');
		statusEl.replaceChildren(message || '');
		if (link) statusEl.append(' ', el('a', { href: link, className: 'sharelink', target: '_blank' }, 'open'));

		// uploaded rows can't be edited or removed any more (they're real projects now) but can still be reordered
		const locked = status === 'uploading' || status === 'done';
		row.querySelectorAll('input, textarea').forEach((input) => { input.disabled = locked; });
		row.querySelector('.remove-row').hidden = status === 'done';
	}

	function describeError(xhr, data) {
		if (data && data.message) return data.message;
		switch (xhr.status) {
			case 0: return 'Network error - check your connection and retry.';
			case 401: return 'You were logged out. Log in again in another tab, then retry.';
			case 403: return 'Security token expired. Reload the page and try again.';
			case 413: return 'Too large for the server or your reverse proxy (413).';
			case 504: return 'The upload timed out (504) - your reverse proxy may need a longer timeout.';
			default: return `Server error (${xhr.status}).`;
		}
	}

	// ---------- uploading ----------

	function uploadRow(row) {
		return new Promise((resolve) => {
			const r = rows.get(row);
			const form = new FormData();
			form.append('action', 'new_project');
			form.append('ajax', '1');
			form.append('csrf_token', cfg.csrfToken);
			form.append('title', row.querySelector('.f-title').value.trim());
			form.append('artistname', row.querySelector('.f-artist').value.trim());
			form.append('notes', row.querySelector('.f-notes').value);
			form.append('changelog', row.querySelector('.f-changelog').value);
			form.append('downloads', row.querySelector('.f-downloads').checked ? '1' : '0');
			form.append('audio_file', r.file);

			setStatus(row, 'uploading', 'Uploading…');
			setProgress(row, 0);

			// XMLHttpRequest rather than fetch, because fetch can't report upload progress
			const xhr = new XMLHttpRequest();
			xhr.open('POST', 'api.php');
			xhr.upload.onprogress = (e) => { if (e.lengthComputable) setProgress(row, e.loaded / e.total); };
			xhr.upload.onload = () => { setProgress(row, 1); setStatus(row, 'uploading', 'Processing…'); };
			xhr.onload = () => {
				let data = null;
				try { data = JSON.parse(xhr.responseText); } catch (e) { /* not JSON - e.g. a proxy error page */ }
				if (xhr.status === 200 && data && data.status === 'success') {
					r.projectId = data.id;
					r.slug = data.slug;
					setStatus(row, 'done', '✓ Uploaded', '/share/' + data.slug);
				} else {
					setStatus(row, 'error', '✕ ' + describeError(xhr, data));
				}
				resolve();
			};
			xhr.onerror = () => { setStatus(row, 'error', '✕ ' + describeError(xhr, null)); resolve(); };
			xhr.send(form);
		});
	}

	function validate(pending) {
		let ok = true;
		for (const row of pending) {
			for (const field of row.querySelectorAll('.f-title, .f-artist')) {
				const empty = field.value.trim() === '';
				field.classList.toggle('invalid', empty);
				if (empty) ok = false;
			}
		}
		if (makePlaylist && makePlaylist.checked && !playlistCreated) {
			const empty = playlistTitle.value.trim() === '';
			playlistTitle.classList.toggle('invalid', empty);
			if (empty) ok = false;
		}
		if (!ok) showSummary('Fill in the highlighted fields first.', true);
		return ok;
	}

	async function createPlaylist() {
		const body = new URLSearchParams({ action: 'new_playlist', ajax: '1', csrf_token: cfg.csrfToken, title: playlistTitle.value.trim() });
		orderedRows().forEach((row) => body.append('project_ids[]', rows.get(row).projectId));
		try {
			const res = await fetch('api.php', { method: 'POST', body });
			const data = await res.json();
			if (!res.ok || data.status !== 'success') throw new Error(data.message);
			playlistCreated = true;
			return data;
		} catch (e) {
			return null;
		}
	}

	async function run() {
		if (busy) return;
		const pending = orderedRows().filter((row) => rows.get(row).status !== 'done');
		if (!validate(pending)) return;

		busy = true;
		summary.hidden = true;
		setControlsDisabled(true);

		for (let i = 0; i < pending.length; i++) {
			uploadBtn.textContent = `Uploading ${i + 1} of ${pending.length}…`;
			await uploadRow(pending[i]);
		}

		busy = false;
		setControlsDisabled(false);

		const all = orderedRows();
		const failed = all.filter((row) => rows.get(row).status === 'error').length;
		const done = all.length - failed;

		if (failed) {
			updateButton();
			showSummary(`${done} uploaded, ${failed} failed. Fix or remove the failed ${failed === 1 ? 'one' : 'ones'}, then retry.` +
				(makePlaylist && makePlaylist.checked ? ' The playlist will be created once everything is uploaded.' : ''), true);
			return;
		}

		const parts = [`All ${done} track${done === 1 ? '' : 's'} uploaded (as private - change visibility below). `];
		if (makePlaylist && makePlaylist.checked && !playlistCreated) {
			const playlist = await createPlaylist();
			if (!playlist) {
				updateButton();
				showSummary('Tracks uploaded, but the playlist could not be created. Click the button to try again.', true);
				return;
			}
			parts.push('Playlist created: ', el('a', { href: 'playlist_edit.php?id=' + playlist.id, className: 'sharelink' }, playlistTitle.value.trim()), '. ');
		}
		parts.push(el('a', { href: 'index.php', className: 'sharelink' }, 'Refresh the list'));
		showSummary(parts);
		uploadBtn.hidden = true;
	}

	function setControlsDisabled(disabled) {
		uploadBtn.disabled = disabled;
		clearBtn.disabled = disabled;
		dropZone.classList.toggle('disabled', disabled);
		document.querySelectorAll('.bulk-apply input, .bulk-apply button, .bulk-playlist input').forEach((c) => { c.disabled = disabled; });
	}

	function updateButton() {
		const all = [...rows.values()];
		const failed = all.filter((r) => r.status === 'error').length;
		const todo = all.filter((r) => r.status !== 'done').length;
		uploadBtn.hidden = false;
		if (todo === 0 && makePlaylist && makePlaylist.checked && !playlistCreated) uploadBtn.textContent = 'Create playlist';
		else if (failed) uploadBtn.textContent = `Retry (${todo})`;
		else uploadBtn.textContent = todo === 1 ? 'Upload 1 track' : `Upload ${todo} tracks`;
	}

	// ---------- wiring ----------

	dropZone.onclick = () => { if (!busy) fileInput.click(); };
	dropZone.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropZone.click(); } };
	fileInput.onchange = () => { addFiles(fileInput.files); fileInput.value = ''; };

	dropZone.addEventListener('dragover', (e) => { e.preventDefault(); if (!busy) dropZone.classList.add('dragover'); });
	dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
	dropZone.addEventListener('drop', (e) => {
		e.preventDefault();
		dropZone.classList.remove('dragover');
		addFiles(e.dataTransfer.files);
	});
	// a file dropped anywhere else on the page would make the browser navigate away to play it
	window.addEventListener('dragover', (e) => e.preventDefault());
	window.addEventListener('drop', (e) => e.preventDefault());

	$('applyArtistBtn').onclick = () => {
		const artist = $('applyArtist').value.trim();
		orderedRows().filter((row) => rows.get(row).status !== 'done')
			.forEach((row) => { const f = row.querySelector('.f-artist'); f.value = artist; f.classList.remove('invalid'); });
	};
	const applyDownloads = (on) => orderedRows().filter((row) => rows.get(row).status !== 'done')
		.forEach((row) => { row.querySelector('.f-downloads').checked = on; });
	$('applyDownloadsOn').onclick = () => applyDownloads(true);
	$('applyDownloadsOff').onclick = () => applyDownloads(false);

	if (makePlaylist) {
		playlistTitle.addEventListener('input', () => playlistTitle.classList.remove('invalid'));
		makePlaylist.onchange = () => {
			playlistTitle.hidden = !makePlaylist.checked;
			if (makePlaylist.checked) playlistTitle.focus();
			updateButton();
		};
	}

	uploadBtn.onclick = run;
	clearBtn.onclick = () => {
		if (busy) return;
		// anything already uploaded is a real project now - reload so it shows in the list below
		if ([...rows.values()].some((r) => r.status === 'done')) { location.reload(); return; }
		rows.clear();
		rowList.replaceChildren();
		panel.hidden = true;
		summary.hidden = true;
	};

	window.addEventListener('beforeunload', (e) => { if (busy) { e.preventDefault(); e.returnValue = ''; } });

	makeSortable(rowList, { item: '.upload-row', handle: '.drag-handle' });
})();
