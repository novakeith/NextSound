<?php
// Harden the session cookie before starting the session:
// httponly = JS can't read it; samesite=Lax = other sites can't POST with it; secure when served over HTTPS.
session_set_cookie_params([
	'httponly' => true,
	'samesite' => 'Lax',
	'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
]);
session_start();

// Define Constants - in some cases set from the docker env variables
define('DB_PATH', __DIR__ . '/data/');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD'));
define('SITE_TITLE', getenv('SITE_TITLE') ?: 'NextSound'); // if defined as an env var in docker, it will insert that into the db; if not, default to NextSound.
define('SITE_URL', getenv('SITE_URL') ?: '127.0.0.1'); // important for linking purposes
// allowed upload types (as detected from the file contents) => extension the file is saved with
define('ALLOWED_AUDIO_TYPES', [
	'audio/mpeg' => 'mp3',
	'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav', 'audio/vnd.wave' => 'wav',
	'audio/ogg' => 'ogg',
	'audio/flac' => 'flac', 'audio/x-flac' => 'flac',
]);
define('DB_SCHEMA_VERSION', 2); // bump this when adding a migration to assets/func.php

// database connection / creation (if it doesnt exist)
try {
    $db = new PDO('sqlite:' . DB_PATH . 'nextsound.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON;');

    $check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='projects'");
    if (!$check->fetch()) {
		$sql = file_get_contents(__DIR__ . '/assets/schema.sql');
        $db->exec($sql);

		// load in defaults from .env or -e when running docker
		$stmt = $db->prepare("INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES
							('site_title', ?),
							('site_url', ?)
							");
		$stmt->execute([SITE_TITLE, SITE_URL]);
    }

	// Fetch all site settings into an array
	$stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
	$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    // Log any errors w/ database connection; nothing else on the page can work without the db, so stop here.
    error_log("DB Error: " . $e->getMessage());
    http_response_code(500);
    die("Database error. Check the server logs.");
}

// ################
// helper functions
function isAdmin() { return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true; }

// random slug from a cryptographically secure source (str_shuffle is predictable)
function generateSlug($l = 8) {
	$chars = '0123456789abcdefghijklmnopqrstuvwxyz';
	$slug = '';
	for ($i = 0; $i < $l; $i++) { $slug .= $chars[random_int(0, strlen($chars) - 1)]; }
	return $slug;
}

// shorthand for escaping anything printed into HTML
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// CSRF protection: every admin form/fetch sends this token, and api.php rejects requests without it.
// Stops another website from submitting forms (like 'delete project') using your logged-in session.
function csrf_token() {
	if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
	return $_SESSION['csrf_token'];
}
function csrf_field() { return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">'; }
function verify_csrf() {
	$sent = $_POST['csrf_token'] ?? '';
	if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
		http_response_code(403);
		die('Invalid or missing security token. Reload the page and try again.');
	}
}

// webhook functionality
function sendwebhookNotification($url, $message) {
    if (!$url) return; // Do nothing if no webhook is set

    $data = json_encode(['content' => $message]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // don't let a slow webhook hang the commenter's request
    curl_exec($ch);
    curl_close($ch);
}

?>