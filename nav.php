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
                <a href="/admin/" class="navlink">Dashboard</a> | 
				<a href="/admin/settings.php" class="navlink">Settings</a> | 
				<a href="/admin/login.php?logout=true" class="navlink">Logout</a>
            <?php else: ?>
                <a href="/admin/login.php" class="navlink">Login</a>
            <?php endif; ?>
        </div>
    </div>