<?php
require_once('../config.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    if (!ADMIN_PASSWORD) {
        // never allow an empty password to work if the env var is missing
        $error = 'No ADMIN_PASSWORD is configured on the server. Set it in your .env file and restart the container.';
    } elseif (is_string($password) && hash_equals((string)ADMIN_PASSWORD, $password)) {
        session_regenerate_id(true); // fresh session id on login, so a pre-login id can't be reused
        $_SESSION['admin_logged_in'] = true;
        header("Location: index.php");
        exit;
    } else {
        sleep(1); // slow down password guessing
        $error = 'Invalid Password. ';
    }
}

// Logout logic
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= h($settings['site_title']) ?> Login</title>
	<link rel="stylesheet" href="/assets/style/style.css">
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
</head>
<body>
	<!-- Navigation Bar --!>
    <?php include('../nav.php') ?>
	
<div class="main-content">
	<div class="login-container">
		<div class="login-card">
			<h2><?= h($settings['site_title']) ?></h2>
			<p style="color: #888; font-size: 0.8rem;">Enter Admin Password</p>
			
			<?php if ($error): ?>
				<div class="error"><?= h($error) ?></div>
			<?php endif; ?>

			<form method="POST">
				<input type="password" name="password" placeholder="••••••••" required autofocus>
				<button type="submit" class="btn">Login</button>
			</form>
		</div>
	</div>
</div>
	
	<!-- Footer --!>
    <?php include('../footer.php') ?>	
</body>
</html>