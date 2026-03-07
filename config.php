<?php
session_start();

// Define Constants - in some cases set from the docker env variables
define('DB_PATH', __DIR__ . '/data/nextsound.db');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD')); 
define('SITE_TITLE', getenv('SITE_TITLE') ?: 'NextSound'); // if defined as an env var in docker, it will insert that into the db; if not, default to NextSound.

// database connection / creation (if it doesnt exist)
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON;');

    $check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='projects'");
    if (!$check->fetch()) {
        $db->exec("
            CREATE TABLE projects (
			id INTEGER PRIMARY KEY AUTOINCREMENT, 
			title TEXT NOT NULL, 
			artistname TEXT NOT NULL,
			slug TEXT UNIQUE NOT NULL, 
			notes TEXT,
			is_public INTEGER DEFAULT 0, 
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP
			);

			CREATE TABLE versions (
				id INTEGER PRIMARY KEY AUTOINCREMENT, 
				project_id INTEGER, 
				filename TEXT NOT NULL, 
				version_number INTEGER NOT NULL, 
				changelog TEXT,
				is_active INTEGER DEFAULT 1, 
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP, 
				allow_download INTEGER DEFAULT 0,
				FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
			);
			
			CREATE TABLE comments (
				id INTEGER PRIMARY KEY AUTOINCREMENT, 
				version_id INTEGER, 
				timestamp REAL NOT NULL, 
				author_name TEXT, 
				author_token TEXT, 
				text TEXT NOT NULL, 
				status TEXT DEFAULT 'pending',
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP, 
				FOREIGN KEY(version_id) REFERENCES versions(id) ON DELETE CASCADE
			);
			
			CREATE TABLE site_settings (
				setting_key TEXT PRIMARY KEY,
				setting_value TEXT
			);

			-- Some site setting defaults
			INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES ('comments_enabled', '1');
			INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES ('primary_color', '#3498db');
			INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES ('site_title', '" . SITE_TITLE ."');
			
        ");
    }
} catch (Exception $e) {
    // We log the error but don't 'die' yet, or we'll break the session
    error_log("DB Error: " . $e->getMessage());
}

// Fetch all site settings into an array
$stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// helper functions
function isAdmin() { return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true; }
function generateSlug($l = 8) { return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyz', 5)), 0, $l); }

?>