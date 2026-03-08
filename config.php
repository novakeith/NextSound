<?php
session_start();

// Define Constants - in some cases set from the docker env variables
define('DB_PATH', __DIR__ . '/data/');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD')); 
define('SITE_TITLE', getenv('SITE_TITLE') ?: 'NextSound'); // if defined as an env var in docker, it will insert that into the db; if not, default to NextSound.
define('SITE_URL', getenv('SITE_URL') ?: '127.0.0.1'); // important for linking purposes

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
} catch (Exception $e) {
    // Log any errors w/ database connection
    error_log("DB Error: " . $e->getMessage());
}

// Fetch all site settings into an array
$stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ################
// helper functions
function isAdmin() { return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true; }
function generateSlug($l = 8) { return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyz', 5)), 0, $l); }

// webhook functionality
function sendwebhookNotification($url, $message) {
    if (!$url) return; // Do nothing if no webhook is set

    $data = json_encode(['content' => $message]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

?>