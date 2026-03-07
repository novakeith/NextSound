    <!-- Override primary color w/ one that admin sets in control panel --!>
	<style>
    :root {
        --primary: <?= $settings['primary_color'] ?>;
    }
	</style>
	
	<div class="navbar">
        <a href="/" style="color: #fff; text-decoration: none; font-weight: bold;"><?=  $settings['site_title'] ?></a>
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