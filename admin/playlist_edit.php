<?php
require_once('../config.php');
require_once('../assets/func.php');
if (!isAdmin()) { header("Location: login.php"); exit; }
if (!PLAYLISTS_ENABLED) { header("Location: playlists.php"); exit; }

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM playlists WHERE id = ?");
$stmt->execute([$id]);
$pl = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pl) die("Playlist not found.");

// every project + its versions (for the item version pickers and the "add track" form)
$projects = $db->query("SELECT id, title, artistname, is_public FROM projects ORDER BY title COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
$projectsById = array_column($projects, null, 'id');
$versionsByProject = [];
foreach ($db->query("SELECT id, project_id, version_number, changelog, is_active FROM versions ORDER BY version_number DESC") as $v) {
	$versionsByProject[$v['project_id']][] = $v;
}
// the version a project plays when an item follows it: the active one, else the newest
function currentVersionOf($versions) {
	foreach ($versions as $v) { if ($v['is_active']) return $v; }
	return $versions[0] ?? null;
}
function versionLabel($v) {
	$label = 'v' . $v['version_number'];
	if (!empty($v['changelog'])) $label .= ' - ' . mb_strimwidth($v['changelog'], 0, 40, '…');
	return $label;
}

$stmt = $db->prepare("SELECT * FROM playlist_items WHERE playlist_id = ? ORDER BY position, id");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$privateTracks = playlistPrivateTracks($db, $id);

// confirmation requests bounced back from api.php
$confirm = $_GET['confirm'] ?? '';
$confirmProject = $projectsById[(int)($_GET['project_id'] ?? 0)] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= h($settings['site_title']) ?> | Playlist: <?= h($pl['title']) ?></title>
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
	<link rel="stylesheet" href="/assets/style/style.css">
	<script src="/assets/js/sortable.js"></script>
</head>
<body>
	<!-- Navigation Bar --!>
    <?php include('../nav.php') ?>

	<div class="main-content">
    <h1>Edit Playlist</h1>
		<?php if (isset($_GET['success'])): ?>
			<div class='version-badge'><div>Changes saved</div></div>
		<?php endif; ?>

	<?php if ($confirm === 'add' && $confirmProject): ?>
		<!-- Warning: adding a private track to a public playlist --!>
		<div class="card warning-card">
			<strong>"<?= h($confirmProject['title']) ?>" is private, but this playlist is public.</strong>
			<p>If you add it, it will be playable by anyone who opens this playlist from the home page. It still won't be listed on the home page by itself.</p>
			<form action="api.php" method="POST" class="button-group">
				<input type="hidden" name="action" value="add_playlist_item">
				<?= csrf_field() ?>
				<input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
				<input type="hidden" name="project_id" value="<?= $confirmProject['id'] ?>">
				<input type="hidden" name="version_id" value="<?= h($_GET['version_id'] ?? '') ?>">
				<button type="submit" name="resolve" value="anyway" class="btn btn-sm">Add anyway</button>
				<button type="submit" name="resolve" value="make_track_public" class="btn btn-sm btn-alt">Make track public &amp; add</button>
				<button type="submit" name="resolve" value="make_playlist_private" class="btn btn-sm btn-alt">Make playlist private &amp; add</button>
				<a href="playlist_edit.php?id=<?= $pl['id'] ?>" class="btn btn-sm btn-alt">Cancel</a>
			</form>
		</div>
	<?php endif; ?>

	<?php if ($confirm === 'public' && !$pl['is_public']): ?>
		<!-- Warning: making a playlist with private tracks public --!>
		<div class="card warning-card">
			<strong>This playlist contains <?= count($privateTracks) ?> private track<?= count($privateTracks) == 1 ? '' : 's' ?>:</strong>
			<?= h(implode(', ', array_column($privateTracks, 'title'))) ?>
			<p>If you make it public, those tracks will be playable by anyone who opens this playlist from the home page. They still won't be listed on the home page by themselves.</p>
			<form action="api.php" method="POST" class="button-group">
				<input type="hidden" name="action" value="toggle_playlist_privacy">
				<?= csrf_field() ?>
				<input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
				<input type="hidden" name="return" value="<?= ($_GET['return'] ?? '') === 'edit' ? 'edit' : 'list' ?>">
				<button type="submit" name="resolve" value="anyway" class="btn btn-sm">Make public anyway</button>
				<button type="submit" name="resolve" value="make_tracks_public" class="btn btn-sm btn-alt">Make those tracks public too</button>
				<a href="<?= ($_GET['return'] ?? '') === 'edit' ? 'playlist_edit.php?id=' . $pl['id'] : 'playlists.php' ?>" class="btn btn-sm btn-alt">Cancel</a>
			</form>
		</div>
	<?php endif; ?>

	<div class="card">
		<form action="api.php" method="POST">
			<input type="hidden" name="action" value="update_playlist">
			<?= csrf_field() ?>
			<input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">

			<div class="form-group">
				<label>Playlist Title</label>
				<input type="text" name="title" value="<?= h($pl['title']) ?>" required>
			</div>
			<div class="form-group">
				<label>Description</label>
				<textarea name="description" class="prj-notes"><?= h($pl['description']) ?></textarea>
			</div>
			<div class='button-group'>
				<button type="submit" class="btn btn-sm" style="width:150px;">Save Changes</button>
				<a href="playlists.php" class="btn btn-sm btn-alt" style="width:75px;">Back</a>
			</div>
		</form>

		<div style="margin-top: 20px; display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
			<form action="api.php" method="POST" style="margin:0;">
				<input type="hidden" name="action" value="toggle_playlist_privacy">
				<?= csrf_field() ?>
				<input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
				<input type="hidden" name="return" value="edit">
				Visibility: <button type="submit" class="btn btn-sm btn-alt"><?= $pl['is_public'] ? 'Public' : 'Private' ?></button>
			</form>
			<span>Share link: <a class="sharelink" href="/playlist/<?= h($pl['slug']) ?>">/playlist/<?= h($pl['slug']) ?></a></span>
		</div>

		<?php if ($privateTracks): ?>
			<div class="notice-inline" style="margin-top: 15px;">
				Contains <?= count($privateTracks) ?> unlisted track<?= count($privateTracks) == 1 ? '' : 's' ?> (<?= h(implode(', ', array_column($privateTracks, 'title'))) ?>).
				<?= $pl['is_public']
					? 'Because this playlist is public, anyone who opens it from the home page can play them.'
					: 'Anyone with this playlist\'s link can play them.' ?>
			</div>
		<?php endif; ?>
	</div>

	<hr class='hr'>

	<h3 id="tracks">Tracks</h3>
	<?php if (empty($items)): ?>
		<div class="card"><span>No tracks yet - add some below.</span></div>
	<?php endif; ?>

	<div id="playlistItems">
	<?php foreach ($items as $i => $item):
		$project = $projectsById[$item['project_id']];
		$versions = $versionsByProject[$item['project_id']] ?? [];
		$current = currentVersionOf($versions);
	?>
		<div class="version-row playlist-item-row" data-item-id="<?= $item['id'] ?>">
			<span class="drag-handle" title="Drag to reorder">⠿</span>
			<div style="flex-grow: 1;">
				<strong><span class="item-number"><?= $i + 1 ?></span>. <?= h($project['title']) ?></strong>
				<small>(by <?= h($project['artistname']) ?>)</small>
				<?php if (!$project['is_public']): ?><span class="private-badge">Private</span><?php endif; ?>
			</div>

			<form action="api.php" method="POST" style="margin:0;">
				<input type="hidden" name="action" value="set_playlist_item_version">
				<?= csrf_field() ?>
				<input type="hidden" name="item_id" value="<?= $item['id'] ?>">
				<select name="version_id" onchange="this.form.submit()" style="padding: 0.4rem;" title="Which version plays">
					<option value="">Latest<?= $current ? ' (currently v' . $current['version_number'] . ')' : '' ?></option>
					<?php foreach ($versions as $v): ?>
						<option value="<?= $v['id'] ?>" <?= $item['version_id'] == $v['id'] ? 'selected' : '' ?>>📌 <?= h(versionLabel($v)) ?></option>
					<?php endforeach; ?>
				</select>
			</form>

			<form action="api.php" method="POST" style="margin:0; display: flex; gap: 4px;">
				<input type="hidden" name="action" value="move_playlist_item">
				<?= csrf_field() ?>
				<input type="hidden" name="item_id" value="<?= $item['id'] ?>">
				<button type="submit" name="dir" value="up" class="btn btn-sm btn-alt move-up" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>>▲</button>
				<button type="submit" name="dir" value="down" class="btn btn-sm btn-alt move-down" title="Move down" <?= $i === count($items) - 1 ? 'disabled' : '' ?>>▼</button>
			</form>

			<form action="api.php" method="POST" style="margin:0;">
				<input type="hidden" name="action" value="remove_playlist_item">
				<?= csrf_field() ?>
				<input type="hidden" name="item_id" value="<?= $item['id'] ?>">
				<button type="submit" class="btn btn-sm btn-danger" title="Remove from playlist">✕</button>
			</form>
		</div>
	<?php endforeach; ?>
	</div>
	<?php if (count($items) > 1): ?>
		<p style="color: #777; font-size: 0.8rem;">Drag ⠿ to reorder - changes save automatically.</p>
	<?php endif; ?>

	<!-- Add a track --!>
	<div class="card" style="margin-top: 1rem;">
		<?php if (empty($projects)): ?>
			<span>You don't have any tracks yet. <a class="navlink" href="index.php">Upload one</a> first.</span>
		<?php else: ?>
		<form action="api.php" method="POST">
			<input type="hidden" name="action" value="add_playlist_item">
			<?= csrf_field() ?>
			<input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
			<label><small>Add Track:</small></label>
			<div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 0.5rem;">
				<select name="project_id" id="addProject">
					<?php foreach ($projects as $p): ?>
						<option value="<?= $p['id'] ?>"><?= h($p['title']) ?> - <?= h($p['artistname']) ?><?= $p['is_public'] ? '' : ' (private)' ?></option>
					<?php endforeach; ?>
				</select>
				<select name="version_id" id="addVersion"></select>
				<button type="submit" class="btn btn-sm">Add</button>
			</div>
			<p style="color: #777; font-size: 0.8rem;">"Latest" follows the project, so new mixes show up automatically. Pin a version (📌) to keep it fixed - handy for before/after playlists.</p>
		</form>
		<?php endif; ?>
	</div>

	<script>
		// fill the version picker for whichever project is selected in "Add Track"
		const versionsByProject = <?= json_encode(array_map(fn($vs) => array_map(fn($v) => ['id' => (int)$v['id'], 'label' => versionLabel($v)], $vs), $versionsByProject), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
		const projectSelect = document.getElementById('addProject');
		const versionSelect = document.getElementById('addVersion');
		function fillVersions() {
			const options = [new Option('Latest', '')];
			for (const v of versionsByProject[projectSelect.value] || []) options.push(new Option('📌 ' + v.label, v.id));
			versionSelect.replaceChildren(...options);
		}
		if (projectSelect) { projectSelect.onchange = fillVersions; fillVersions(); }

		// drag-and-drop reordering: saves the new order right away
		const itemList = document.getElementById('playlistItems');
		makeSortable(itemList, {
			item: '.playlist-item-row',
			handle: '.drag-handle',
			onEnd: () => {
				const rows = [...itemList.querySelectorAll('.playlist-item-row')];
				// renumber and fix which ▲/▼ buttons are disabled, without a reload
				rows.forEach((row, i) => {
					row.querySelector('.item-number').textContent = i + 1;
					row.querySelector('.move-up').disabled = i === 0;
					row.querySelector('.move-down').disabled = i === rows.length - 1;
				});

				const body = new URLSearchParams({ action: 'reorder_playlist', ajax: '1', playlist_id: <?= (int)$pl['id'] ?>, csrf_token: <?= json_encode(csrf_token()) ?> });
				rows.forEach((row) => body.append('item_ids[]', row.dataset.itemId));
				fetch('api.php', { method: 'POST', body })
					.then((res) => res.json().then((data) => { if (!res.ok) throw new Error(data.message); }))
					.catch((err) => {
						alert((err.message || 'Could not save the new order.') + ' The page will reload.');
						location.reload();
					});
			},
		});
	</script>
	</div>

	<!-- Footer --!>
    <?php include('../footer.php') ?>
</body>
</html>
