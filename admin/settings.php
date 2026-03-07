<?php
require_once('../config.php');
if (!isAdmin()) { header("Location: login.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST['set'] as $key => $value) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
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
	<link rel="stylesheet" href="/assets/style/style.css">
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
</head>

<body>

	<!-- Navigation Bar --!>
    <?php include('../nav.php') ?>

<div class="main-content">
<div class="settings-card">
    <h2>Site Settings</h2>
    <form method="POST">
		<label>Site Title</label>
        <input type="text" style="width: 150px;" name="set[site_title]" value="<?= $settings['site_title'] ?? '' ?>" placeholder="<?= $settings['site_title'] ?>">
	
        <label>Primary Theme Color</label>
        <input type="color" name="set[primary_color]" style="width: 50px; height: 50px;" value="<?= $settings['primary_color'] ?>">

        <label>Allow Comments (Viewing & Posting) from Visitors?</label>
        <select name="set[comments_enabled]" style="padding: 0.6rem; margin: 1rem 0;">
            <option value="1" <?= $settings['comments_enabled'] == '1' ? 'selected' : '' ?>>Enabled</option>
            <option value="0" <?= $settings['comments_enabled'] == '0' ? 'selected' : '' ?>>Disabled</option>
        </select>

        <label>Discord Webhook URL</label>
        <input type="text" name="set[webhook_url]" value="<?= $settings['webhook_url'] ?? '' ?>" placeholder="https://discord.com/api/webhooks/...">
		
        <br /><button type="submit" class="btn">Save Configuration</button>
    </form>
</div>
</div>
	<!-- Footer --!>
    <?php include('../footer.php') ?>	
</body>
</html>