<?php
require_once('config.php');

$slug = $_GET['slug'] ?? null;
$project = null;
$activeVersion = null;
$comments = [];

// Route to project using a version ID (vid) if specified
$vid = $_GET['vid'] ?? null;

if ($slug) {
    $stmt = $db->prepare("SELECT * FROM projects WHERE slug = ?");
    $stmt->execute([$slug]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($project) {
        if ($vid) {
            // Load specific version
            $stmt = $db->prepare("SELECT * FROM versions WHERE id = ? AND project_id = ?");
            $stmt->execute([$vid, $project['id']]);
        } else {
            // Load default active version
            $stmt = $db->prepare("SELECT * FROM versions WHERE project_id = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$project['id']]);
        }
        $activeVersion = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activeVersion) {
            // Fetch comments for this version
            $stmt = $db->prepare("SELECT * FROM comments WHERE version_id = ? ORDER BY timestamp ASC");
            $stmt->execute([$activeVersion['id']]);
            $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Not directly linked? display all public projects.
if (!$project && !$slug) {
    $stmt = $db->query("SELECT * FROM projects WHERE is_public = 1 ORDER BY created_at DESC");
    $publicProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch ALL versions for a project
$allVersions = [];
if ($project) {
    $stmt = $db->prepare("SELECT * FROM versions WHERE project_id = ? ORDER BY version_number DESC");
    $stmt->execute([$project['id']]);
    $allVersions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle Comments on a project 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
    $version_id = (int)$_POST['version_id'];
    $timestamp = (float)$_POST['timestamp'];
    $author = trim($_POST['author']) ?: 'Anonymous';
    $text = trim($_POST['text']);
    
    // Give the commenter a 30-day tracking cookie
	// eventually this will mean they wont have to retype their name, 
	// and I can give them controls to edit/delete their comments. But not yet.
	// commenting this out for now.
    //$author_token = $_COOKIE['nextsound_guest'] ?? bin2hex(random_bytes(16));
    //setcookie('nextsound_guest', $author_token, time() + (60 * 60 * 24 * 30), "/"); 

    if (!empty($text)) {
        $stmt = $db->prepare("INSERT INTO comments (version_id, timestamp, author_name, author_token, text) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$version_id, $timestamp, $author, $author_token, $text]);
    }
    
    // Respond with success so the frontend knows to reload
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
    exit;
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $project ? htmlspecialchars($project['title']) : $settings['site_title'] ?></title>
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
	<link rel="stylesheet" href="/assets/style/style.css">
    <script src="https://unpkg.com/wavesurfer.js@7"></script>

</head>
<body>

	<!-- Navigation Bar --!>
    <?php include('nav.php') ?>

<div class="main-content">
    <div class="container">
        <?php if ($project && $activeVersion): ?>
            <div class="player-card">
				<h1 style="margin-top: 0; margin-bottom: 5px;"><?= htmlspecialchars($project['title']) ?></h1>
				
				<?php if (!empty($project['notes'])): ?>
					<div class="project-notes">
						<?= nl2br(htmlspecialchars($project['notes'])) ?>
						<span style="font-size:0.7rem; display: block;"><?php if ($activeVersion['allow_download']): ?><a class="dl-link" href="/download.php?id=<?= $activeVersion['id'] ?>"><i>Download</i></a> <?php endif; ?></span>
					</div>
				<?php endif; ?>
				
				<div class="changelog-box">
					<span class="version-badge">Version <?= $activeVersion['version_number'] ?> Notes:</span>
					<span style="color: #fff;">
						<?= !empty($activeVersion['changelog']) ? htmlspecialchars($activeVersion['changelog']) : "No change notes for this mix." ?>
					</span>
				</div>

				<div id="waveform"></div>
				
				<div class="controls">
					<button class="btn" id="playPause">Play / Pause</button>
					<span id="currentTime">0:00</span> / <span id="duration">0:00</span>
				</div>
			</div>
			
			<?php 
				// Allow comment form & comment display if enabled in site settings;
				// Admins are always allowed to view comments + the form.
				if (($settings['comments_enabled'] == '1') || ($settings['comments_enabled'] == '0' && isAdmin())): 
			?>
			
			<!-- Comment Form --!>
			<div class="player-card">
                <h4 style="margin-top: 0;">Leave feedback at <span id="commentTime" style="color: var(--primary);">0:00</span></h4>
                <form id="commentForm" style="display: flex; gap: 10px;">
                    <input type="hidden" id="timestampInput" name="timestamp" value="0">
                    <input type="text" id="authorInput" placeholder="Your Name" style="width: 25%;">
                    <input type="text" id="textInput" placeholder="Feedback... (click to lock time)" style="flex-grow: 1;" required>
                    <button type="submit" class="btn">Post</button>
                </form>
            </div>

			<!-- Comment display --!>
				<div class="comment-section">
					<h3>Comments</h3>
					<div id="commentList">
						<?php if (empty($comments)): ?>
							<p style="color: #777;">No comments yet. Be the first to ruin the mix.</p>
						<?php endif; ?>

						<?php foreach ($comments as $c): ?>
							<div class="comment" id="comment-container-<?= $c['id']?>">
								<span class="timestamp" onclick="seekTo(<?= $c['timestamp'] ?>)">
									<?= sprintf('%d:%02d', floor($c['timestamp'] / 60), (int)floor($c['timestamp']) % 60) ?>
								</span>
								<strong><?= htmlspecialchars($c['author_name'] ?: 'Anonymous') ?>:</strong>
								<?= htmlspecialchars($c['text']) ?>

								<?php if (isAdmin()): ?>
									<div class="admin-controls">
										<button class="triage-btn" data-id="<?= $c['id'] ?>" data-status="accepted">👍</button>
										<button class="triage-btn" data-id="<?= $c['id'] ?>" data-status="rejected">👎</button>									
										<span id="status-msg-<?= $c['id'] ?>" class="status-badge"></span>
										<button class="delete-comment-btn" data-id="<?= $c['id'] ?>" style="background: #442222; border: 1px solid #663333;">🗑️</button>
									</div>
								<?php endif; ?>
								
								<?php if ($c['status'] !== 'pending'): ?>
									<span class="status-badge <?= $c['status'] ?>">
										<?= $c['status'] === 'accepted' ? '✅ Resolved' : '❌ Declined' ?>
									</span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				
				<?php if (isAdmin()): ?>
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
									<a href="?slug=<?= $project['slug'] ?>&vid=<?= $v['id'] ?>" class="btn-sm">Switch</a>
								<?php else: ?>
									<span class="active-badge">Currently Playing</span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

        <?php elseif (!$slug): ?>
            <h2>Public Tracks</h2>
            <?php if (empty($publicProjects)): ?>
                <p style="color: #777;">No public tracks right now.</p>
            <?php endif; ?>
            
            <?php foreach ($publicProjects as $p): ?>
                <div class="player-card" style="padding: 20px;">
                    <a href="/share/<?= $p['slug'] ?>" style="color: var(--primary); text-decoration: none; font-size: 1.2rem; font-weight: bold;">
                        <?= htmlspecialchars($p['title']) ?>
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

    <script>
        <?php if ($activeVersion): ?>
        // Initialize WaveSurfer
        const wavesurfer = WaveSurfer.create({
            container: '#waveform',
            waveColor: '#555',
            progressColor: '#3498db',
            cursorColor: '#fff',
            barWidth: 2,
            height: 128,
            url: '/uploads/<?= $project['id'] ?>/<?= $activeVersion['filename'] ?>'
        });

        // Controls
        const playBtn = document.getElementById('playPause');
        playBtn.onclick = () => wavesurfer.playPause();

        // Update Time
        wavesurfer.on('audioprocess', () => {
            document.getElementById('currentTime').innerText = formatTime(wavesurfer.getCurrentTime());
        });

        wavesurfer.on('ready', () => {
            document.getElementById('duration').innerText = formatTime(wavesurfer.getDuration());
        });

        function formatTime(s) {
            const min = Math.floor(s / 60);
            const sec = Math.floor(s % 60);
            return `${min}:${sec < 10 ? '0' : ''}${sec}`;
        }

        function seekTo(seconds) {
            wavesurfer.setTime(seconds);
            wavesurfer.play();
        }

        // Comment Form Logic
        const textInput = document.getElementById('textInput');
        const commentTimeDisplay = document.getElementById('commentTime');
        const timestampInput = document.getElementById('timestampInput');

        // Lock in time when clicking the input
        textInput.onfocus = () => {
            const now = wavesurfer.getCurrentTime();
            timestampInput.value = now;
            commentTimeDisplay.innerText = formatTime(now);
        };

        // Submit form
        document.getElementById('commentForm').onsubmit = async (e) => {
			e.preventDefault();
			
			const timestamp = timestampInput.value;
			const author = document.getElementById('authorInput').value || 'Anonymous';
			const text = textInput.value;

			const formData = new FormData();
			formData.append('action', 'add_comment');
			formData.append('version_id', '<?= $activeVersion['id'] ?>');
			formData.append('timestamp', timestamp);
			formData.append('author', author);
			formData.append('text', text);

			const response = await fetch(window.location.href, { 
				method: 'POST',
				body: formData
			});

			if (response.ok) {
				// Clear the input
				textInput.value = '';

				// Manually build and prepend the comment to the list
				const list = document.getElementById('commentList');
				const newComment = document.createElement('div');
				newComment.className = 'comment';
				
				// Format the time for the UI
				const displayTime = formatTime(timestamp);
				
				newComment.innerHTML = `
					<span class="timestamp" onclick="seekTo(${timestamp})">${displayTime}</span>
					<strong>${author}:</strong> ${text}
				`;
				
				// Add to top of list (or use appendChild for bottom)
				list.prepend(newComment);
				
				// Remove the "No comments yet" message if it exists
				if(list.querySelector('p')) list.querySelector('p').remove();
			}
		};
	
		document.querySelectorAll('.triage-btn').forEach(button => {
			button.addEventListener('click', function() {
				const commentId = this.dataset.id;
				const newStatus = this.dataset.status;
				const statusMsg = document.getElementById('status-msg-' + commentId);

				statusMsg.innerText = "Updating...";

				// Send the request to api.php in the background
				fetch('/admin/api.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: `action=set_comment_status&comment_id=${commentId}&status=${newStatus}`
				})
				.then(response => {
					if (response.ok) {
						statusMsg.innerText = (newStatus === 'accepted') ? "Marked as Resolved ✅ " : "Marked as Declined ❌ ";
						// Optional: Dim the comment if rejected
						if(newStatus === 'rejected') {
							this.closest('.comment').style.opacity = '0.5';
						} else {
							this.closest('.comment').style.opacity = '1';
						}
					}
				})
				.catch(error => {
					console.error('Error:', error);
					statusMsg.innerText = "Error!";
				});
			});
		});
		
		document.querySelectorAll('.delete-comment-btn').forEach(button => {
			button.addEventListener('click', function() {
				const commentId = this.dataset.id;
				
				if (!confirm("Are you sure you want to delete this comment?")) return;

				fetch('/admin/api.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: `action=delete_comment&comment_id=${commentId}`
				})
				.then(response => {
					if (response.ok) {
						// Smoothly hide and remove the element
						const container = document.getElementById('comment-container-' + commentId);
						container.style.transition = "opacity 0.3s, transform 0.3s";
						container.style.opacity = "0";
						container.style.transform = "translateX(20px)";
						setTimeout(() => container.remove(), 300);
					}
				});
			});
		});
        <?php endif; ?>
    </script>
	
	<!-- Footer --!>
    <?php include('footer.php') ?>	
</body>
</html>