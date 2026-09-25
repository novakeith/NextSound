<?php
// player.php - shared player markup for the track page (index.php) and playlist page (playlist.php).
// The content is filled in by assets/js/player.js from the track data the page provides.
//
// Expects these variables from the including page:
//   $playerTracks  - array of tracks from buildTrack()
//   $playerContext - ['type' => 'track'|'playlist', 'slug' => share slug,
//                     'title' / 'description' => playlist title & description (playlists only)]
$isPlaylist = $playerContext['type'] === 'playlist';
$showComments = commentsVisible($settings);
?>
			<div class="player-card">
				<h1 id="trackTitle" style="margin-top: 0; margin-bottom: 5px;"></h1>
				<div id="trackArtist" class="track-artist"></div>

				<div class="project-notes" id="trackNotesBox" hidden>
					<span id="trackNotes" class="multiline"></span>
					<span style="font-size:0.7rem; display: block;"><a class="dl-link" id="downloadLink" hidden><i>Download</i></a></span>
				</div>

				<div class="changelog-box">
					<span class="version-badge" id="versionBadge"></span>
					<span style="color: #fff;" id="changelogText"></span>
				</div>

				<div id="waveform"></div>

				<div class="controls">
					<?php if ($isPlaylist): ?><button class="btn btn-alt" id="prevBtn" title="Previous track">⏮</button><?php endif; ?>
					<button class="btn" id="playPause">Play / Pause</button>
					<?php if ($isPlaylist): ?><button class="btn btn-alt" id="nextBtn" title="Next track">⏭</button><?php endif; ?>
					<span id="currentTime">0:00</span> / <span id="duration">0:00</span>
				</div>
			</div>

			<?php if ($isPlaylist): ?>
				<!-- Playlist info + tracklist (the player above always shows the current track) --!>
				<div class="comment-section">
					<div class="playlist-label">Playlist</div>
					<h2 class="playlist-title"><?= h($playerContext['title']) ?></h2>
					<?php if (!empty($playerContext['description'])): ?>
						<div class="project-notes multiline"><?= h($playerContext['description']) ?></div>
					<?php endif; ?>
					<ol id="tracklist" class="tracklist"></ol>
				</div>
			<?php endif; ?>

			<?php
				// Allow comment form & comment display if enabled in site settings;
				// Admins are always allowed to view comments + the form.
				if ($showComments):
			?>

			<!-- Comment Form --!>
			<div class="player-card" style="margin-top: 20px;">
                <h4 style="margin-top: 0;">Leave feedback<?php if ($isPlaylist): ?> on <span id="commentTrack" style="color: var(--primary);"></span><?php endif; ?> at <span id="commentTime" style="color: var(--primary);">0:00</span></h4>
                <form id="commentForm" style="display: flex; gap: 10px;">
                    <input type="text" id="authorInput" placeholder="Your Name" style="width: 25%;">
                    <input type="text" id="textInput" placeholder="Feedback... (click to lock time)" style="flex-grow: 1;" required>
                    <button type="submit" class="btn">Post</button>
                </form>
            </div>

			<!-- Comment display --!>
			<div class="comment-section">
				<h3>Comments</h3>
				<div id="commentList"></div>
			</div>

				<?php if ($settings['comments_enabled'] == '0' && isAdmin()): ?>
					<div class="comment-section">
						<p style="text-align:center; color:#666; font-size: 0.8rem;">Comments are currently disabled (& invisible) for non-admin users. <br />
						As an admin, you can still view historical comments or leave them for yourself.</p>
					</div>
				<?php endif; ?>

			<?php else: ?>
				<div class="comment-section">
					<p style="text-align:center; color:#666;">Comments are currently closed for this site.</p>
				</div>
			<?php endif; ?>

			<script>
				window.NEXTSOUND_PLAYER = <?= json_encode([
					'tracks' => $playerTracks,
					'context' => $playerContext,
					'isAdmin' => isAdmin(),
					'csrfToken' => isAdmin() ? csrf_token() : '',
					'primaryColor' => $settings['primary_color'] ?? '#3498db',
				], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
			</script>
			<script src="<?= asset('/assets/js/player.js') ?>"></script>
