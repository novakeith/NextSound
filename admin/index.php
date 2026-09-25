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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard: <?= h($settings['site_title']) ?></title>
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
	<link rel="stylesheet" href="/assets/style/style.css">
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

    <div class="card">
        <h3>Create New Project</h3>
        <form action="api.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="new_project">
            <?= csrf_field() ?>
            <input type="text" name="title" placeholder="Project Title" required><br />
			<input type="text" name="artistname" placeholder="Artist Name" required>
            <p><input class="btn btn-alt" type="file" name="audio_file" accept="audio/*" required></p>
			<p><textarea name="notes" class="prj-notes" placeholder="Project backstory..."></textarea></p>
			<p><input type="text" name="changelog" placeholder="Specific notes for this initial release?"></p>
			<p><input name="downloads" value="1" type="checkbox" class="download-toggle checkbox"><label style='display: inline;'>Allow Downloads?</label></p>
            <p><button type="submit" class="btn">Start Project</button></p>
        </form>
    </div>

    <hr class='hr'>

    <?php foreach($projects as $p): ?>
        <div class="card">
            <div class="flex">
                <div>
					<div style="display: flex; justify-content: space-between; align-items: center;">
						<div>
							<strong>
								<a class="sharelink" href='../share/<?= $p['slug'] ?>'>
								<?= htmlspecialchars($p['title']) ?></a>
							</strong>
								
								<small>(by <?= htmlspecialchars($p['artistname']) ?>)</small>
							
						<span id="copy-icon-<?= $p['slug'] ?>" 
							  onclick="copyShareLink('<?= $p['slug'] ?>')" 
							  style="cursor: pointer; font-size: 0.6em; margin-left: 10px; vertical-align: middle;" 
							  title="Copy link to clipboard">
							  🔗
						</span>
						</div>
						
						<div style="display: flex; gap: 10px; align-items: center; margin-left: 20px;">
							<a href="edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-alt">✏️ Edit</a>
							
							<form action="api.php" method="POST" style="margin:0;" onsubmit="return confirm(<?= h(json_encode('Erase this project?' . ($p['playlist_count'] > 0 ? ' It will also be removed from ' . $p['playlist_count'] . ' playlist' . ($p['playlist_count'] == 1 ? '' : 's') . '.' : ''))) ?>);">
								<input type="hidden" name="action" value="delete_project">
								<?= csrf_field() ?>
								<input type="hidden" name="project_id" value="<?= $p['id'] ?>">
								<button type="submit" class="btn btn-sm btn-danger">🗑️ Delete</button>
							</form>
							
							<div style="margin-left: 20px;">
								<form action="api.php" method="POST" style="display:inline;">
									<input type="hidden" name="action" value="toggle_privacy">
									<?= csrf_field() ?>
									<input type="hidden" name="project_id" value="<?= $p['id'] ?>">
									Project Visiblity: <button type="submit" class="btn btn-sm btn-alt">
										<?= $p['is_public'] ? 'Public' : 'Private' ?>
									</button>
								</form>
							</div>
							
						</div>
					</div>
                </div>
		
            </div>
            
            <!--<div style="margin-top: 1rem;">
                <form action="api.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="new_version">
                    <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
					<label><small>Upload New Version:</small></label>
                    <p><input type="file" name="audio_file" accept="audio/*" required></p>
					<p><input type="text" name="changelog" placeholder="What changed in this mix?"></p>
					<p><input name="downloads" value="1" type="checkbox" class="download-toggle checkbox"><label style='display: inline;'>Allow Downloads?</label></p>
                    <p><button type="submit" class="btn btn-alt">Upload New Version</button></p>
                </form>
            </div> --!>
        </div> 
    <?php endforeach; 
	
	if (empty($projects)) {
		?>
		<div class="card">
		<div class="flex">
			<span>Once you create some projects, they will appear here. 
			You will have the option to edit any notes, download status, and visibility for each.</span>
		</div>
		</div>
		<?php
		}
	
	?>

	<script>
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