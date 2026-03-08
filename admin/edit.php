<?php
require_once('../config.php');
if (!isAdmin()) { header("Location: login.php"); exit; }

$id = (int)($_GET['id'] ?? 0);
$project = $db->prepare("SELECT * FROM projects WHERE id = ?");
$project->execute([$id]);
$p = $project->fetch(PDO::FETCH_ASSOC);

if (!$p) die("Project not found.");

$versions = $db->prepare("SELECT * FROM versions WHERE project_id = ? ORDER BY version_number DESC");
$versions->execute([$id]);
$vs = $versions->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($settings['site_title']) ?> | Edit: <?= htmlspecialchars($p['title']) ?></title>
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
	<link rel="stylesheet" href="/assets/style/style.css">
    <!--<style>
        body { font-family: sans-serif; background: #121212; color: #eee; padding: 2rem; }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; margin-bottom: 0.5rem; color: #888; font-weight: bold; }

    </style>--!>
</head>
<body>
	<!-- Navigation Bar --!>
    <?php include('../nav.php') ?>
	
	<div class="main-content">
    <h1>Edit Project</h1>

	<div class="card">
    <form action="api.php" method="POST">
        <input type="hidden" name="action" value="update_project">
        <input type="hidden" name="project_id" value="<?= $p['id'] ?>">

        <div class="form-group">
            <label>Project Title</label>
            <input type="text" name="title" value="<?= htmlspecialchars($p['title']) ?>" required>
        </div>
		
		<div class="form-group">
            <label>Artist Name</label>
            <input type="text" name="artistname" value="<?= htmlspecialchars($p['artistname']) ?>" required>
        </div>

        <div class="form-group">
            <label>Project Notes</label>
            <textarea name="notes" class="prj-notes"><?= htmlspecialchars($p['notes']) ?></textarea>
        </div>

        <h3>Version Changelogs</h3>
        <?php foreach($vs as $v): ?>
			<div class="version-row" style="display: flex; justify-content: space-between; align-items: center;">
				<div style="flex-grow: 1;">
					<label>Version <?= $v['version_number'] ?> (<?= $v['filename'] ?>)</label>
					<input type="text" name="versions[<?= $v['id'] ?>]" value="<?= htmlspecialchars($v['changelog']) ?>" style="width:50%;" placeholder="Describe what changed...">
				</div>
				<div style="padding: 1px;">
					<button type="button" class="btn btn-sm download-toggle-btn" data-version-id="<?= $v['id'] ?>" data-current="<?= $v['allow_download'] ?>">
						<?= $v['allow_download'] ? '🔓 Downloads On' : '🔒 Downloads Off' ?>
					</button>
				</div>

				<div style="padding: 1px;">
					<button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $v['id'] ?>)">
						🗑️ Delete
					</button>

				</div>
			</div>
		<?php endforeach; ?>
		
		<div class='button-group'>
			<button type="submit" class="btn btn-sm" style="width:150px;">Save Changes</button>
			<a href="/admin/index.php" class="btn btn-sm" style="width:75px;">Cancel</a>
		</div>
    </form>
	</div>
	
	<form id="delete-helper-form" action="api.php" method="POST" style="display:none;">
		<input type="hidden" name="action" value="delete_version">
		<input type="hidden" name="version_id" id="delete-version-id">
		<input type="hidden" name="project_id" value="<?= $p['id'] ?>">
	</form>
	
	<script>
			document.querySelectorAll('.download-toggle-btn').forEach(btn => {
			btn.addEventListener('click', function() {
				const versionId = this.dataset.versionId;
				const currentStatus = parseInt(this.dataset.current);
				const newStatus = currentStatus === 1 ? 0 : 1;

				// Visual feedback immediately
				this.innerText = "...";

				fetch('api.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: `action=toggle_download&version_id=${versionId}&status=${newStatus}`
				})
				.then(res => {
					if (res.ok) {
						this.dataset.current = newStatus;
						this.innerText = newStatus === 1 ? '🔓 Downloads On' : '🔒 Downloads Off';
						this.classList.toggle('btn-success', newStatus === 1);
						this.classList.toggle('btn-alt', newStatus === 0);
					}
				});
			});
		});
		
		function confirmDelete(versionId) {
			if (confirm('Delete this version and its file?')) {
				document.getElementById('delete-version-id').value = versionId;
				document.getElementById('delete-helper-form').submit();
			}
		};
	</script>
	
	</div>
	<!-- Footer --!>
    <?php include('../footer.php') ?>	
</body>
</html>