<?php
require_once('../config.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    // I know this isn't great for now but I'll change this in the future.
    if ($password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        header("Location: index.php");
        exit;
    } else {
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
    <title><?= $settings['site_title'] ?> Login</title>
	<link rel="stylesheet" href="/assets/style/style.css">
	<link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
</head>
<body>
	<!-- Navigation Bar --!>
    <?php include('../nav.php') ?>
	
<div class="main-content">
	<div class="login-container">
		<div class="login-card">
			<h2><?= $settings['site_title'] ?></h2>
			<p style="color: #888; font-size: 0.8rem;">Enter Admin Password</p>
			
			<?php if ($error): ?>
				<div class="error"><?= $error ?></div>
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