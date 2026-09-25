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
	<script src="/assets/js/sortable.js"></script>
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
		window.NEXTSOUND_UPLOAD = <?= json_encode([
			'csrfToken' => csrf_token(),
			'maxBytes' => uploadLimitBytes(),
			'playlistsEnabled' => PLAYLISTS_ENABLED,
		]) ?>;
	</script>
	<script src="/assets/js/bulk-upload.js"></script>

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