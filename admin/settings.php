<?php
require_once('../config.php');
require_once('../assets/func.php');

if (!isAdmin()) { header("Location: login.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // only settings this form actually edits (db_schema etc. can't be overwritten from here)
    $editable = ['site_title', 'site_url', 'primary_color', 'comments_enabled', 'show_play_counts', 'webhook_url',
                 'webhook_on_track', 'webhook_on_comment', 'webhook_on_playlist'];
    $stmt = $db->prepare("INSERT OR REPLACE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($_POST['set'] ?? [] as $key => $value) {
        if (!in_array($key, $editable, true)) continue;
        $value = trim((string)$value);

        // primary_color is printed into a <style> tag on every page, so it must be a plain hex color
        if ($key === 'primary_color' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) continue;
        if (($key === 'comments_enabled' || $key === 'show_play_counts' || str_starts_with($key, 'webhook_on_')) && !in_array($value, ['0', '1'], true)) continue;
        if ($key === 'site_url') $value = rtrim($value, '/');

        $stmt->execute([$key, $value]);
    }
    header("Location: settings.php?success=1");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings: <?= htmlspecialchars($settings['site_title']) ?></title>
	<link rel="stylesheet" href="<?= asset('/assets/style/style.css') ?>">
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
</head>

<body>

	<!-- Navigation Bar --!>
    <?php include('../nav.php') ?>

<div class="main-content">
<h1>Site Settings</h1>

<div class="card">
    
    <form method="POST">
		<?= csrf_field() ?>
		<label>Site Title</label>
        <input type="text" name="set[site_title]" value="<?= h($settings['site_title'] ?? '') ?>" placeholder="<?= h($settings['site_title'] ?? '') ?>">
		
		<label>Site URL</label>
        <input type="text" name="set[site_url]" value="<?= h($settings['site_url'] ?? '') ?>" placeholder="E.G. https://nextsound.mysite.com <- No Trailing Slash">
	
        <label>Primary Theme Color</label>
        <input type="color" name="set[primary_color]" style="width: 50px; height: 50px;" value="<?= h($settings['primary_color'] ?? '#3498db') ?>">

        <label>Allow Comments (Viewing & Posting) from Visitors?</label>
        <select name="set[comments_enabled]" style="padding: 0.6rem; margin: 1rem 0;">
            <option value="1" <?= $settings['comments_enabled'] == '1' ? 'selected' : '' ?>>Enabled</option>
            <option value="0" <?= $settings['comments_enabled'] == '0' ? 'selected' : '' ?>>Disabled</option>
        </select>

        <?php if (PLAY_COUNTS_ENABLED): ?>
        <label class="webhook-event" style="margin-bottom: 1rem;">
            <input type="hidden" name="set[show_play_counts]" value="0">
            <input type="checkbox" class="checkbox" name="set[show_play_counts]" value="1" <?= ($settings['show_play_counts'] ?? '0') === '1' ? 'checked' : '' ?>>
            Show play counts publicly
        </label>
        <p class="settings-hint" style="margin-top: -0.5rem;">You always see play counts on the dashboard. A play counts after about 5 seconds of listening; your own plays while logged in don't count.</p>
        <?php endif; ?>

        <label>Webhook URL (e.g. Discord)</label>
        <input type="text" name="set[webhook_url]" value="<?= h($settings['webhook_url'] ?? '') ?>" placeholder="https://discord.com/api/webhooks/...">

        <label>Send a webhook message when:</label>
        <div class="webhook-events">
            <?php foreach (['track' => 'A new track is uploaded', 'comment' => 'Someone leaves a comment', 'playlist' => 'A new playlist is created'] as $event => $label): ?>
                <label class="webhook-event">
                    <!-- unchecked boxes aren't submitted, so the hidden 0 is what gets saved when it's off -->
                    <input type="hidden" name="set[webhook_on_<?= $event ?>]" value="0">
                    <input type="checkbox" class="checkbox" name="set[webhook_on_<?= $event ?>]" value="1" <?= ($settings['webhook_on_' . $event] ?? WEBHOOK_EVENTS[$event]) === '1' ? 'checked' : '' ?>>
                    <?= $label ?>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="settings-hint">Messages include share links, even for private tracks and playlists, so only point this at a channel you trust. Bulk uploads send a single combined message.</p>
		
        <br /><button type="submit" class="btn">Save Configuration</button>
    </form>
</div>

<?php
// check for DB schema update; if so, display button to run update.
$schema = $settings['db_schema'] ?? 1;

if (checkDB_UpdateAvailable($schema, DB_SCHEMA_VERSION))
{
?>
	<div class="card">
	<div>
		<form action="api.php" method="POST" style="margin:0;">
			<input type="hidden" name="action" value="db_update">
			<?= csrf_field() ?>
			<span>Database update is available. This will <strong>not</strong> remove any data. Click <button type="submit" class="btn btn-sm">here</button> to update.</span>
		</form>
	</div>
	</div>
<?php
}

if (isset($_GET['success']) && $_GET['success'] == "DBUpdated")
{
?>
	<div class="card">
	<div>
		<span>Database updated successfully.</span>
	</div>
	</div>
<?php 
}
?>


</div>
	<!-- Footer --!>
    <?php include('../footer.php') ?>	

</body>
</html>