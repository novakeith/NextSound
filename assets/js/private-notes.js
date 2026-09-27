// private per-version notes (admin only) - follows whatever track the player loads
(function () {
	const panel = document.getElementById('privateNotes');
	if (!panel) return;
	const cfg = window.NEXTSOUND_PLAYER;
	const $ = (id) => document.getElementById(id);
	const OPEN_KEY = 'nextsound.privateNotesOpen';
	const TABS = 4; // this version + 3 others, the rest go under "Older"

	let track = null;
	let selected = null;
	let olderOpen = false;
	let pending = null;
	let saveTimer = null;

	function el(tag, props = {}, ...children) {
		const node = Object.assign(document.createElement(tag), props);
		node.append(...children);
		return node;
	}

	// open/closed is remembered per browser
	function setOpen(open, remember = true) {
		panel.classList.toggle('open', open);
		$('pnToggle').setAttribute('aria-expanded', open);
		if (remember) try { localStorage.setItem(OPEN_KEY, open ? '1' : '0'); } catch (e) {}
	}
	let startOpen = false;
	try { startOpen = localStorage.getItem(OPEN_KEY) === '1'; } catch (e) {}
	setOpen(startOpen, false);
	$('pnToggle').addEventListener('click', () => setOpen(!panel.classList.contains('open')));

	const hasNotes = (v) => v.notes.trim() !== '';
	const current = () => track.privateNotes.find((v) => v.versionId === track.versionId);

	function renderHint() {
		const n = track.privateNotes.filter(hasNotes).length;
		$('pnHint').textContent = n === 0 ? 'only you can see these' : `· notes on ${n} version${n === 1 ? '' : 's'}`;
	}

	function render() {
		const cur = current();
		renderHint();

		// the playing version first, then the newest of the rest
		const others = track.privateNotes.filter((v) => v !== cur);
		const shown = [cur, ...others.slice(0, TABS - 1)];
		const older = others.slice(TABS - 1);

		const tab = (v) => {
			const b = el('button', { type: 'button', className: 'pn-tab' + (v === selected ? ' on' : '') },
				v === cur ? `v${v.versionNumber} · this version` : `v${v.versionNumber}`);
			if (v !== cur && hasNotes(v)) b.append(' ', el('span', { className: 'dot' }, '•'));
			b.onclick = () => { selected = v; olderOpen = false; render(); };
			return b;
		};
		const tabs = shown.map(tab);

		if (older.length) {
			const picked = older.includes(selected);
			const btn = el('button', { type: 'button', className: 'pn-tab' + (picked ? ' on' : '') }, picked ? `v${selected.versionNumber} ▾` : 'Older ▾');
			btn.onclick = (e) => { e.stopPropagation(); olderOpen = !olderOpen; render(); };
			tabs.push(btn);
			if (olderOpen) {
				tabs.push(el('div', { className: 'pn-older-menu' }, ...older.map((v) => {
					const item = el('button', { type: 'button' }, `v${v.versionNumber}`);
					if (hasNotes(v)) item.append(' ', el('span', { className: 'dot' }, '•'));
					item.onclick = () => { selected = v; olderOpen = false; render(); };
					return item;
				})));
			}
		}
		$('pnTabs').replaceChildren(...tabs);

		if (selected === cur) {
			const box = el('textarea', {
				value: cur.notes,
				placeholder: 'Notes for this version - mix ideas, plugin settings, what to fix next... Only you can see these.',
			});
			box.addEventListener('input', () => { cur.notes = box.value; renderHint(); queueSave(cur); });
			box.addEventListener('blur', flush);
			$('pnBody').replaceChildren(box);
		} else {
			$('pnBody').replaceChildren(
				el('div', { className: 'pn-tab-label' }, `V${selected.versionNumber} Notes - Read-Only (Current version is: V${cur.versionNumber})`),
				el('div', { className: 'pnotes-old' + (hasNotes(selected) ? '' : ' empty') }, hasNotes(selected) ? selected.notes : 'No notes for this version.'));
		}
	}

	function status(text) { $('pnSaved').textContent = text; }

	function queueSave(version) {
		pending = version;
		status('Saving…');
		clearTimeout(saveTimer);
		saveTimer = setTimeout(flush, 700);
	}

	function flush() {
		clearTimeout(saveTimer);
		if (!pending) return;
		const v = pending;
		pending = null;
		fetch('/admin/api.php', {
			method: 'POST',
			keepalive: true,
			body: new URLSearchParams({ action: 'save_admin_notes', ajax: '1', csrf_token: cfg.csrfToken, version_id: v.versionId, notes: v.notes }),
		})
			.then((res) => res.json().then((data) => { if (!res.ok) throw new Error(data.message); }))
			.then(() => { if (!pending) status('✓ Saved'); })
			.catch(() => {
				if (!pending) pending = v;
				status("Couldn't save - retrying…");
				saveTimer = setTimeout(flush, 5000);
			});
	}

	document.addEventListener('nextsound:track', (e) => {
		flush(); // don't lose notes typed just before the playlist moved on
		track = e.detail;
		if (!track.privateNotes) { panel.hidden = true; return; }
		panel.hidden = false;
		selected = current();
		olderOpen = false;
		status('');
		render();
	});
	document.addEventListener('click', () => { if (olderOpen) { olderOpen = false; render(); } });
	window.addEventListener('pagehide', flush);
})();
