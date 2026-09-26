<?php
require_once('config.php');
require_once('assets/tracks.php');

// A playlist link plays every track in it, whatever each track's public/private status.
// (Public/private only controls what's listed on the home page.)
$playlist = null;
$entries = [];
if (PLAYLISTS_ENABLED) {
	$playlist = getPlaylistBySlug($db, param($_GET, 'slug'));
	if ($playlist) $entries = getPlaylistEntries($db, $playlist['id']);
}

if ($playlist && $entries) {
	$withComments = commentsVisible($settings);
	$withPlays = showPlayCounts($settings);
	$access = 'p=' . rawurlencode($playlist['slug']);
	$playerTracks = array_map(fn($e) => buildTrack($db, $e['project'], $e['version'], $access, $withComments, $e['pinned'], $withPlays), $entries);
	$playerContext = ['type' => 'playlist', 'slug' => $playlist['slug'], 'title' => $playlist['title'], 'description' => $playlist['description'] ?? ''];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($playlist ? $playlist['title'] : $settings['site_title']) ?></title>
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
		<?php if ($playlist && $entries): ?>
			<?php include('assets/player.php'); ?>

		<?php elseif ($playlist): ?>
			<div class="player-card" style="text-align: center;">
				<h2><?= h($playlist['title']) ?></h2>
				<p>This playlist is empty right now.</p>
				<a href="/" style="color: var(--primary);">Go Home</a>
			</div>

		<?php else: ?>
			<div class="player-card" style="text-align: center;">
				<h2>404</h2>
				<p>This playlist has returned to the ether.</p>
				<a href="/" style="color: var(--primary);">Go Home</a>
			</div>
		<?php endif; ?>
    </div>
</div>

	<!-- Footer --!>
    <?php include('footer.php') ?>
</body>
</html>
