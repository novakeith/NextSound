    <!-- Override primary color w/ one that admin sets in control panel --!>
	<style>
    :root {
        --primary: <?= h($settings['primary_color'] ?? '#3498db') ?>;
    }
	</style>
	
	<div class="navbar">
        <a href="/" style="color: #fff; text-decoration: none; font-weight: bold;"><?= h($settings['site_title'] ?? '') ?></a>
        <div>
            <?php if (isAdmin()): ?>
				<?php if (NOTIFICATIONS_ENABLED): [$unreadCount, $unreadItems] = adminNotifications($db); ?>
				<!-- Notifications: new comments waiting for review --!>
				<div class="notif" id="notif" data-csrf="<?= csrf_token() ?>">
					<button type="button" class="notif-bell" id="notifBell" aria-haspopup="true" aria-expanded="false" title="Comments to review">
						🔔<span class="notif-badge" id="notifBadge" <?= $unreadCount ? '' : 'hidden' ?>><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
					</button>
					<div class="notif-panel" id="notifPanel" hidden>
						<div class="notif-head">
							<strong>Comments to review</strong>
							<button type="button" class="notif-markall" id="notifMarkAll" <?= $unreadCount ? '' : 'hidden' ?>>Mark all as read</button>
						</div>
						<div id="notifList">
							<?php foreach ($unreadItems as $n): ?>
								<div class="notif-item<?= $n['is_approved'] ? '' : ' notif-held' ?>" data-id="<?= $n['id'] ?>">
									<a class="notif-link" href="/share/<?= h($n['slug']) ?>?vid=<?= $n['version_id'] ?>#comment-<?= $n['id'] ?>">
										<span><strong><?= h($n['author_name'] ?: 'Anonymous') ?></strong> on <strong><?= h($n['title']) ?></strong></span>
										<span class="notif-meta">v<?= $n['version_number'] ?> · at <?= sprintf('%d:%02d', floor($n['timestamp'] / 60), (int)$n['timestamp'] % 60) ?> · <?= timeAgo($n['created_at']) ?></span>
										<span class="notif-text"><?= h(mb_strimwidth($n['text'], 0, 110, '…')) ?></span>
									</a>
									<?php if (!$n['is_approved']): ?>
										<!-- held comment: stays in the queue until approved (shown to everyone) or denied (deleted) --!>
										<div class="notif-actions">
											<span class="notif-held-tag">⏳ Awaiting approval</span>
											<button type="button" class="btn btn-sm notif-approve">Approve</button>
											<button type="button" class="btn btn-sm btn-danger notif-deny">Deny</button>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
							<p class="notif-empty" id="notifEmpty" <?= $unreadItems ? 'hidden' : '' ?>>You're all caught up.</p>
						</div>
						<?php if ($unreadCount > count($unreadItems)): ?>
							<p class="notif-more" id="notifMore">+ <?= $unreadCount - count($unreadItems) ?> older - "Mark all as read" clears them too.</p>
						<?php endif; ?>
					</div>
				</div>
				<script src="<?= asset('/assets/js/notifications.js') ?>"></script>
				<?php endif; ?>
                <a href="/admin/" class="navlink">Dashboard</a> | 
				<a href="/admin/playlists.php" class="navlink">Playlists</a> | 
				<a href="/admin/settings.php" class="navlink">Settings</a> | 
				<a href="/admin/login.php?logout=true" class="navlink">Logout</a>
            <?php else: ?>
                <a href="/admin/login.php" class="navlink">Login</a>
            <?php endif; ?>
        </div>
    </div>