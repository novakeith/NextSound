<?php
require_once('../config.php');
require_once('../assets/func.php');

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
<h1>Site Settings</h1>

<div class="card">
    
    <form method="POST">
		<label>Site Title</label>
        <input type="text" name="set[site_title]" value="<?= $settings['site_title'] ?? '' ?>" placeholder="<?= $settings['site_title'] ?>">
		
		<label>Site URL</label>
        <input type="text" name="set[site_url]" value="<?= $settings['site_url'] ?? '' ?>" placeholder="E.G. https://nextsound.mysite.com <- No Trailing Slash">
	
        <label>Primary Theme Color</label>
        <input type="color" name="set[primary_color]" style="width: 50px; height: 50px;" value="<?= $settings['primary_color'] ?>">

        <label>Allow Comments (Viewing & Posting) from Visitors?</label>
        <select name="set[comments_enabled]" style="padding: 0.6rem; margin: 1rem 0;">
            <option value="1" <?= $settings['comments_enabled'] == '1' ? 'selected' : '' ?>>Enabled</option>
            <option value="0" <?= $settings['comments_enabled'] == '0' ? 'selected' : '' ?>>Disabled</option>
        </select>

        <label>Webhook URL (e.g. Discord)</label>
        <input type="text" name="set[webhook_url]" value="<?= $settings['webhook_url'] ?? '' ?>" placeholder="https://discord.com/api/webhooks/...">
		
        <br /><button type="submit" class="btn">Save Configuration</button>
    </form>
</div>

<?php
// check for DB schema update; if so, display button to run update.
if (!isset($settings['db_schema'])){ $schema = 1; } else { $schema = $settings['db_schema']; }

if (checkDB_UpdateAvailable($schema, 2))
{
?>
	<div class="card">
	<div>
		<span>Database update is available. This will <strong>not</strong> remove any data. Click <a href='api.php?action=db_update'>here</a> to update.</span>
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