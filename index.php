<?php
require_once('config.php');
require_once('assets/tracks.php');

$slug = param($_GET, 'slug') ?: null;
$project = null;
$activeVersion = null;

// Route to project using a version ID (vid) if specified
$vid = isset($_GET['vid']) ? (int)$_GET['vid'] : null;

if ($slug) {
    $stmt = $db->prepare("SELECT * FROM projects WHERE slug = ?");
    $stmt->execute([$slug]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($project) {
        if ($vid) {
            // Load specific version
            $stmt = $db->prepare("SELECT * FROM versions WHERE id = ? AND project_id = ?");
            $stmt->execute([$vid, $project['id']]);
            $activeVersion = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Load default active version
            $activeVersion = getCurrentVersion($db, $project['id']);
        }
    }
}

// Not directly linked? display all public playlists & projects.
if (!$project && !$slug) {
    $stmt = $db->query("SELECT * FROM projects WHERE is_public = 1 ORDER BY created_at DESC");
    $publicProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $publicPlaylists = [];
    if (PLAYLISTS_ENABLED) {
        $stmt = $db->query("SELECT pl.*, (SELECT COUNT(*) FROM playlist_items pi WHERE pi.playlist_id = pl.id) AS track_count
                            FROM playlists pl WHERE pl.is_public = 1 ORDER BY pl.created_at DESC");
        $publicPlaylists = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch ALL versions for a project
$allVersions = [];
if ($project) {
    $stmt = $db->prepare("SELECT * FROM versions WHERE project_id = ? ORDER BY version_number DESC");
    $stmt->execute([$project['id']]);
    $allVersions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($project ? $project['title'] : $settings['site_title']) ?></title>
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
	<link rel="stylesheet" href="<?= asset('/assets/style/style.css') ?>">
    <!-- WaveSurfer 7.12.12, served from this site (pinned) rather than a CDN; license in assets/js/vendor/ -->
    <script src="<?= asset('/assets/js/vendor/wavesurfer-7.12.12.min.js') ?>"></script>

</head>
<body>

	<!-- Navigation Bar --!>
    <?php include('nav.php') ?>

<div class="main-content">
    <div class="container">
        <?php if ($project && $activeVersion): ?>
			<?php
				// a single track is just a playlist of one
				$playerTracks = [buildTrack($db, $project, $activeVersion, 's=' . rawurlencode($project['slug']), commentsVisible($settings), false, showPlayCounts($settings))];
				$playerContext = ['type' => 'track', 'slug' => $project['slug']];
				include('assets/player.php');
			?>

			<!-- Version selection --!>
			<?php if (count($allVersions) > 1): ?>
				<div class="comment-section">
					<h3>Version History</h3>
					<div class="version-list">
						<?php foreach ($allVersions as $v): ?>
							<div class="version-row <?= ($v['id'] == $activeVersion['id']) ? 'active' : '' ?>">
								<span><strong>Version <?= $v['version_number'] ?></strong></span>
								<small><?= date('M j, Y', strtotime($v['created_at'])) ?></small>

								<?php if ($v['id'] != $activeVersion['id']): ?>
									<a href="?slug=<?= h($project['slug']) ?>&amp;vid=<?= $v['id'] ?>" class="btn-sm">Switch</a>
								<?php else: ?>
									<span class="active-badge">Currently Playing</span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

        <?php elseif (!$slug): ?>
			<?php if (!empty($publicPlaylists)): ?>
				<h2>Playlists</h2>
				<?php foreach ($publicPlaylists as $pl): ?>
					<div class="player-card" style="padding: 20px; margin-bottom: 10px;">
						<a href="/playlist/<?= h($pl['slug']) ?>" style="color: var(--primary); text-decoration: none; font-size: 1.2rem; font-weight: bold;">
							<?= h($pl['title']) ?>
						</a>
						<small style="color: #777; margin-left: 10px;"><?= (int)$pl['track_count'] ?> track<?= $pl['track_count'] == 1 ? '' : 's' ?></small>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

            <h2>Public Tracks</h2>
            <?php if (empty($publicProjects)): ?>
                <p style="color: #777;">No public tracks right now.</p>
            <?php endif; ?>

            <?php foreach ($publicProjects as $p): ?>
                <div class="player-card" style="padding: 20px; margin-bottom: 10px;">
                    <a href="/share/<?= h($p['slug']) ?>" style="color: var(--primary); text-decoration: none; font-size: 1.2rem; font-weight: bold;">
                        <?= h($p['title']) ?>
                    </a>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="player-card" style="text-align: center;">
                <h2>404</h2>
                <p>This track has returned to the ether.</p>
                <a href="/" style="color: var(--primary);">Go Home</a>
            </div>
        <?php endif; ?>
    </div>
</div>

	<!-- Footer --!>
    <?php include('footer.php') ?>
</body>
</html>
