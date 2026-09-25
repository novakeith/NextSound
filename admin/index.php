<?php
require_once('../config.php');
require_once('../assets/func.php');
if (!isAdmin()) { header("Location: login.php"); exit; }

$playlistCountSql = PLAYLISTS_ENABLED ? "(SELECT COUNT(DISTINCT playlist_id) FROM playlist_items pi WHERE pi.project_id = projects.id)" : "0";
$projects = $db->query("SELECT *, $playlistCountSql AS playlist_count FROM projects ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// making a track private that's in public playlists bounces back here to confirm
$confirmPrivate = null;
if (isset($_GET['confirm_private']) && PLAYLISTS_ENABLED) {
	foreach ($projects as $p) { if ($p['id'] == $_GET['confirm_private']) $confirmPrivate = $p; }
	$confirmPlaylists = $confirmPrivate ? publicPlaylistsContaining($db, $confirmPrivate['id']) : [];
}

// playlists for the bulk "add to playlist" picker
$allPlaylists = PLAYLISTS_ENABLED ? $db->query("SELECT id, title, is_public FROM playlists ORDER BY title COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC) : [];

// bulk-adding private tracks to a public playlist bounces back here to confirm
$bulkConfirm = null;
if (isset($_GET['confirm_bulk_add']) && PLAYLISTS_ENABLED) {
	$projectsById = array_column($projects, null, 'id');
	foreach ($allPlaylists as $pl) { if ($pl['id'] == $_GET['confirm_bulk_add']) $bulkConfirm = $pl; }
	$bulkIds = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), fn($id) => isset($projectsById[$id])));
	$bulkPrivate = array_values(array_filter($bulkIds, fn($id) => !$projectsById[$id]['is_public']));
	if (!$bulkIds) $bulkConfirm = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard: <?= h($settings['site_title']) ?></title>
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
	<link rel="stylesheet" href="<?= asset('/assets/style/style.css') ?>">
	<script src="<?= asset('/assets/js/sortable.js') ?>"></script>
</head>

<body>
	<!-- Navigation Bar --!>
    <?php include('../nav.php') ?>
	
	<div class="main-content">
    <h1>Admin Panel</h1>

	<?php if ($confirmPrivate && $confirmPlaylists): ?>
		<!-- Warning: making a track private that's in public playlists --!>
		<div class="card warning-card">
			<strong>"<?= h($confirmPrivate['title']) ?>" is in <?= count($confirmPlaylists) ?> public playlist<?= count($confirmPlaylists) == 1 ? '' : 's' ?>:</strong>
			<?= h(implode(', ', array_column($confirmPlaylists, 'title'))) ?>
			<p>Making it private removes it from the home page, but it will still be playable from <?= count($confirmPlaylists) == 1 ? 'that playlist' : 'those playlists' ?>.</p>
			<form action="api.php" method="POST" class="button-group">
				<input type="hidden" name="action" value="toggle_privacy">
				<?= csrf_field() ?>
				<input type="hidden" name="project_id" value="<?= $confirmPrivate['id'] ?>">
				<button type="submit" name="resolve" value="anyway" class="btn btn-sm">Make private anyway</button>
				<a href="index.php" class="btn btn-sm btn-alt">Cancel</a>
			</form>
		</div>
	<?php endif; ?>

	<?php if ($bulkConfirm): ?>
		<!-- Warning: bulk-adding private tracks to a public playlist --!>
		<div class="card warning-card">
			<strong><?= count($bulkPrivate) ?> of the <?= count($bulkIds) ?> selected track<?= count($bulkIds) == 1 ? '' : 's' ?> <?= count($bulkPrivate) == 1 ? 'is' : 'are' ?> private, but "<?= h($bulkConfirm['title']) ?>" is public:</strong>
			<?= h(implode(', ', array_map(fn($id) => $projectsById[$id]['title'], $bulkPrivate))) ?>
			<p>If you add them, they will be playable by anyone who opens that playlist from the home page. They still won't be listed on the home page by themselves.</p>
			<form action="api.php" method="POST" class="button-group">
				<input type="hidden" name="action" value="bulk_add_to_playlist">
				<?= csrf_field() ?>
				<input type="hidden" name="playlist_id" value="<?= $bulkConfirm['id'] ?>">
				<?php foreach ($bulkIds as $id): ?><input type="hidden" name="project_ids[]" value="<?= $id ?>"><?php endforeach; ?>
				<button type="submit" name="resolve" value="anyway" class="btn btn-sm">Add anyway</button>
				<button type="submit" name="resolve" value="make_tracks_public" class="btn btn-sm btn-alt">Make those tracks public &amp; add</button>
				<button type="submit" name="resolve" value="make_playlist_private" class="btn btn-sm btn-alt">Make playlist private &amp; add</button>
				<a href="index.php" class="btn btn-sm btn-alt">Cancel</a>
			</form>
		</div>
	<?php endif; ?>

    <!-- Upload tracks: one or many --!>
    <div class="card">
        <h3>Upload Tracks</h3>
        <div id="dropZone" class="drop-zone" tabindex="0" role="button">
            <strong>Drag &amp; drop audio files here, or click to choose</strong>
            <small>MP3, WAV, OGG or FLAC<?= uploadLimitBytes() ? ' - up to ' . round(uploadLimitBytes() / 1048576) . 'MB each' : '' ?>. Pick several at once to upload a whole batch.</small>
            <input type="file" id="fileInput" multiple accept="audio/*,.mp3,.wav,.ogg,.flac" hidden>
        </div>

        <div id="uploadPanel" hidden>
            <div class="bulk-apply">
                <span>Apply to all:</span>
                <input type="text" id="applyArtist" placeholder="Artist name">
                <button type="button" class="btn btn-sm btn-alt" id="applyArtistBtn">Set artist</button>
                <button type="button" class="btn btn-sm btn-alt" id="applyDownloadsOn">Downloads on</button>
                <button type="button" class="btn btn-sm btn-alt" id="applyDownloadsOff">Downloads off</button>
            </div>

            <div id="uploadRows"></div>
            <p id="reorderHint" style="color: #777; font-size: 0.8rem;" hidden>Drag ⠿ to change the order - it's the playlist order if you create one below.</p>

            <?php if (PLAYLISTS_ENABLED): ?>
            <div class="bulk-playlist">
                <label style="display: inline; color: inherit; font-weight: normal;">
                    <input type="checkbox" id="makePlaylist" class="checkbox"> Also create a playlist from these tracks, in this order
                </label>
                <input type="text" id="playlistTitle" placeholder="Playlist / album title" hidden>
            </div>
            <?php endif; ?>

            <div class="button-group">
                <button type="button" class="btn" id="uploadBtn">Upload</button>
                <button type="button" class="btn btn-alt" id="clearBtn">Clear list</button>
            </div>
            <div id="uploadSummary" class="upload-summary" hidden></div>
        </div>
    </div>

    <hr class='hr'>

	<?php if ($projects): ?>
	<!-- Bulk actions: the checkboxes on each row belong to this form via form="bulkForm" (forms can't be nested) --!>
	<form id="bulkForm" action="api.php" method="POST" class="bulk-bar">
		<?= csrf_field() ?>
		<label class="bulk-select-all"><input type="checkbox" id="selectAll" class="checkbox" title="Select all"> <span id="bulkCount">Select tracks for bulk actions</span></label>

		<div class="bulk-bar-actions">
			<?php if (PLAYLISTS_ENABLED): ?>
				<select name="playlist_id" id="bulkPlaylist">
					<option value="new">New playlist…</option>
					<?php foreach ($allPlaylists as $pl): ?>
						<option value="<?= $pl['id'] ?>"><?= h($pl['title']) ?><?= $pl['is_public'] ? ' (public)' : '' ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="new_playlist_title" id="bulkPlaylistTitle" placeholder="New playlist title">
				<button type="submit" name="action" value="bulk_add_to_playlist" class="btn btn-sm bulk-needs-selection" id="bulkAddBtn" disabled>Add to playlist</button>
			<?php endif; ?>
			<button type="submit" name="action" value="bulk_delete_projects" class="btn btn-sm btn-danger bulk-needs-selection" id="bulkDeleteBtn" disabled>🗑️ Delete</button>
		</div>
	</form>
	<?php endif; ?>

	<div id="projectList">
    <?php foreach($projects as $p): ?>
        <div class="card project-row">
			<input type="checkbox" class="checkbox bulk-check" name="project_ids[]" value="<?= $p['id'] ?>" form="bulkForm"
				   data-playlists="<?= (int)$p['playlist_count'] ?>" aria-label="Select <?= h($p['title']) ?>">

			<div class="project-info">
				<strong><a class="sharelink" href="../share/<?= h($p['slug']) ?>"><?= h($p['title']) ?></a></strong>
				<small>(by <?= h($p['artistname']) ?>)</small>
				<span id="copy-icon-<?= h($p['slug']) ?>" class="copy-link" onclick="copyShareLink('<?= h($p['slug']) ?>')" title="Copy link to clipboard">🔗</span>
			</div>

			<a href="edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-alt">✏️ Edit</a>

			<form action="api.php" method="POST" onsubmit="return confirm(<?= h(json_encode('Erase this project?' . ($p['playlist_count'] > 0 ? ' It will also be removed from ' . $p['playlist_count'] . ' playlist' . ($p['playlist_count'] == 1 ? '' : 's') . '.' : ''))) ?>);">
				<input type="hidden" name="action" value="delete_project">
				<?= csrf_field() ?>
				<input type="hidden" name="project_id" value="<?= $p['id'] ?>">
				<button type="submit" class="btn btn-sm btn-danger">🗑️ Delete</button>
			</form>

			<form action="api.php" method="POST" class="project-visibility">
				<input type="hidden" name="action" value="toggle_privacy">
				<?= csrf_field() ?>
				<input type="hidden" name="project_id" value="<?= $p['id'] ?>">
				<span>Visibility:</span>
				<button type="submit" class="btn btn-sm btn-alt visibility-btn"><?= $p['is_public'] ? 'Public' : 'Private' ?></button>
			</form>
        </div>
    <?php endforeach; ?>
	</div>

	<?php if (empty($projects)): ?>
		<div class="card">
			<span>Once you create some projects, they will appear here.
			You will have the option to edit any notes, download status, and visibility for each.</span>
		</div>
	<?php endif; ?>

	<script>
		window.NEXTSOUND_UPLOAD = <?= json_encode([
			'csrfToken' => csrf_token(),
			'maxBytes' => uploadLimitBytes(),
			'playlistsEnabled' => PLAYLISTS_ENABLED,
		]) ?>;
	</script>
	<script src="<?= asset('/assets/js/bulk-upload.js') ?>"></script>

	<script>
		// ---- bulk selection ----
		(function () {
			const form = document.getElementById('bulkForm');
			if (!form) return;
			const boxes = [...document.querySelectorAll('.bulk-check')];
			const selectAll = document.getElementById('selectAll');
			const count = document.getElementById('bulkCount');
			const playlistSelect = document.getElementById('bulkPlaylist');
			const playlistTitle = document.getElementById('bulkPlaylistTitle');
			let lastClicked = null;

			function refresh() {
				const n = boxes.filter((b) => b.checked).length;
				count.textContent = n ? `${n} selected` : 'Select tracks for bulk actions';
				selectAll.checked = n > 0 && n === boxes.length;
				selectAll.indeterminate = n > 0 && n < boxes.length;
				form.querySelectorAll('.bulk-needs-selection').forEach((btn) => { btn.disabled = n === 0; });
				boxes.forEach((b) => b.closest('.project-row').classList.toggle('selected', b.checked));
			}

			// shift-click selects everything between this box and the last one clicked
			boxes.forEach((box, i) => box.addEventListener('click', (e) => {
				if (e.shiftKey && lastClicked !== null) {
					const [from, to] = [Math.min(i, lastClicked), Math.max(i, lastClicked)];
					for (let j = from; j <= to; j++) boxes[j].checked = box.checked;
				}
				lastClicked = i;
				refresh();
			}));
			selectAll.addEventListener('change', () => { boxes.forEach((b) => { b.checked = selectAll.checked; }); refresh(); });

			if (playlistSelect) {
				const syncTitle = () => { playlistTitle.hidden = playlistSelect.value !== 'new'; };
				playlistSelect.addEventListener('change', syncTitle);
				syncTitle();
			}

			form.addEventListener('submit', (e) => {
				const selected = boxes.filter((b) => b.checked);
				const action = e.submitter ? e.submitter.value : '';
				if (action === 'bulk_delete_projects') {
					const inPlaylists = selected.filter((b) => +b.dataset.playlists > 0).length;
					let msg = `Delete ${selected.length} track${selected.length === 1 ? '' : 's'} and all of their versions, files and comments? This can't be undone.`;
					if (inPlaylists) msg += `\n\n${inPlaylists} of them ${inPlaylists === 1 ? 'is' : 'are'} in playlists and will be removed from them.`;
					if (!confirm(msg)) e.preventDefault();
				} else if (action === 'bulk_add_to_playlist' && playlistSelect.value === 'new' && !playlistTitle.value.trim()) {
					e.preventDefault();
					playlistTitle.classList.add('invalid');
					playlistTitle.focus();
				}
			});
			playlistTitle && playlistTitle.addEventListener('input', () => playlistTitle.classList.remove('invalid'));

			refresh();
		})();

		function copyShareLink(slug) {
		// Construct the full URL
		const url = window.location.origin + '/share/' + slug;
		
		// Copy to clipboard
		navigator.clipboard.writeText(url).then(() => {
			// Optional: Add a "Copied!" feedback effect
			const icon = document.getElementById('copy-icon-' + slug);
			const originalText = icon.innerText;
			icon.innerText = '✅'; 
			setTimeout(() => icon.innerText = originalText, 2000);
		}).catch(err => {
			console.error('Failed to copy: ', err);
		});
	}
	</script>

	</div>
	<!-- Footer --!>
    <?php include('../footer.php') ?>	

</body>
</html>