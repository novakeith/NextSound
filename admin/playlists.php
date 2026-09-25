<?php
require_once('../config.php');
require_once('../assets/func.php');
if (!isAdmin()) { header("Location: login.php"); exit; }

$playlists = [];
if (PLAYLISTS_ENABLED) {
	$playlists = $db->query("SELECT pl.*,
								(SELECT COUNT(*) FROM playlist_items pi WHERE pi.playlist_id = pl.id) AS track_count,
								(SELECT COUNT(DISTINCT pi.project_id) FROM playlist_items pi JOIN projects p ON p.id = pi.project_id
								 WHERE pi.playlist_id = pl.id AND p.is_public = 0) AS private_count
							 FROM playlists pl ORDER BY pl.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Playlists: <?= h($settings['site_title']) ?></title>
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
	<link rel="stylesheet" href="<?= asset('/assets/style/style.css') ?>">
</head>

<body>
	<!-- Navigation Bar --!>
    <?php include('../nav.php') ?>

	<div class="main-content">
    <h1>Playlists</h1>

	<?php if (!PLAYLISTS_ENABLED): ?>
		<div class="card">
			<span>Playlists need a quick database update. Head to <a class="navlink" href="settings.php">Settings</a> and run it - no data will be removed.</span>
		</div>
	<?php else: ?>

    <div class="card">
        <h3>Create New Playlist</h3>
        <form action="api.php" method="POST">
            <input type="hidden" name="action" value="new_playlist">
            <?= csrf_field() ?>
            <input type="text" name="title" placeholder="Playlist Title" required>
			<p><textarea name="description" class="prj-notes" placeholder="What's this collection about? (optional)"></textarea></p>
            <p><button type="submit" class="btn">Create Playlist</button></p>
        </form>
    </div>

    <hr class='hr'>

    <?php foreach($playlists as $pl): ?>
        <div class="card">
			<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
				<div>
					<strong><a class="sharelink" href="/playlist/<?= h($pl['slug']) ?>"><?= h($pl['title']) ?></a></strong>
					<small>(<?= (int)$pl['track_count'] ?> track<?= $pl['track_count'] == 1 ? '' : 's' ?>)</small>
					<span class="copy-link" data-path="/playlist/<?= h($pl['slug']) ?>" title="Copy link to clipboard">🔗</span>
					<?php if ($pl['private_count'] > 0): ?>
						<div class="notice-inline"><?= (int)$pl['private_count'] ?> unlisted track<?= $pl['private_count'] == 1 ? '' : 's' ?><?= $pl['is_public'] ? ' - playable by anyone who finds this playlist on the home page' : '' ?></div>
					<?php endif; ?>
				</div>

				<div style="display: flex; gap: 10px; align-items: center;">
					<a href="playlist_edit.php?id=<?= $pl['id'] ?>" class="btn btn-sm btn-alt">✏️ Edit</a>

					<form action="api.php" method="POST" style="margin:0;" onsubmit="return confirm('Delete this playlist? The tracks themselves are not affected.');">
						<input type="hidden" name="action" value="delete_playlist">
						<?= csrf_field() ?>
						<input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
						<button type="submit" class="btn btn-sm btn-danger">🗑️ Delete</button>
					</form>

					<form action="api.php" method="POST" style="margin:0 0 0 20px;">
						<input type="hidden" name="action" value="toggle_playlist_privacy">
						<?= csrf_field() ?>
						<input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
						<input type="hidden" name="return" value="list">
						Visibility: <button type="submit" class="btn btn-sm btn-alt"><?= $pl['is_public'] ? 'Public' : 'Private' ?></button>
					</form>
				</div>
			</div>
        </div>
    <?php endforeach; ?>

	<?php if (empty($playlists)): ?>
		<div class="card">
			<span>Once you create some playlists, they will appear here. Playlists can mix any of your tracks, and can include the same song more than once (pinned to different versions) for before/after comparisons.</span>
		</div>
	<?php endif; ?>

	<?php endif; ?>

	<script>
		// copy a playlist's share link
		document.querySelectorAll('.copy-link').forEach(icon => {
			icon.onclick = () => {
				navigator.clipboard.writeText(window.location.origin + icon.dataset.path).then(() => {
					icon.textContent = '✅';
					setTimeout(() => icon.textContent = '🔗', 2000);
				}).catch(err => console.error('Failed to copy: ', err));
			};
		});
	</script>
	</div>

	<!-- Footer --!>
    <?php include('../footer.php') ?>
</body>
</html>
