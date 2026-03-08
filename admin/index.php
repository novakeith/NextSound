<?php
require_once('../config.php');
if (!isAdmin()) { header("Location: login.php"); exit; }

$projects = $db->query("SELECT * FROM projects ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard: <?= $settings['site_title'] ?></title>
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
	<link rel="stylesheet" href="/assets/style/style.css">
</head>

<body>
	<!-- Navigation Bar --!>
    <?php include('../nav.php') ?>
	
	<div class="main-content">
    <h1>Admin Panel</h1>

    <div class="card">
        <h3>Create New Project</h3>
        <form action="api.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="new_project">
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
							
							<form action="api.php" method="POST" style="margin:0;" onsubmit="return confirm('Erase this project?');">
								<input type="hidden" name="action" value="delete_project">
								<input type="hidden" name="project_id" value="<?= $p['id'] ?>">
								<button type="submit" class="btn btn-sm btn-danger">🗑️ Delete</button>
							</form>
							
							<div style="margin-left: 20px;">
								<form action="api.php" method="POST" style="display:inline;">
									<input type="hidden" name="action" value="toggle_privacy">
									<input type="hidden" name="project_id" value="<?= $p['id'] ?>">
									<input type="hidden" name="current_status" value="<?= $p['is_public'] ?>">
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
		<?
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