<?php
// func.php - random misc functions i'll need to call
// will be included on a page as needed.

function checkDB_UpdateAvailable($schema, $targetschema){
	if (!isset($schema))
	{
		return TRUE;
	}

	return (int)$schema < (int)$targetschema;
}

// true if $table already has $column - lets migrations be safely re-run
function columnExists($db, $table, $column){
	foreach ($db->query("PRAGMA table_info($table)") as $col) {
		if ($col['name'] === $column) return TRUE;
	}
	return FALSE;
}

// The largest single file PHP will accept, in bytes: the smaller of upload_max_filesize and post_max_size (0 = no limit)
function uploadLimitBytes(){
	$toBytes = function($value){
		$value = trim((string)$value);
		$bytes = (float)$value;
		switch (strtolower(substr($value, -1))) {
			// deliberate fall-through: G = 1024 M, M = 1024 K, K = 1024 bytes
			case 'g': $bytes *= 1024;
			case 'm': $bytes *= 1024;
			case 'k': $bytes *= 1024;
		}
		return (int)$bytes;
	};
	$limits = array_filter([$toBytes(ini_get('upload_max_filesize')), $toBytes(ini_get('post_max_size'))]);
	return $limits ? min($limits) : 0;
}

// ---- playlist helpers (admin side) ----

// Distinct private (unlisted) projects in a playlist: [[id, title], ...]
function playlistPrivateTracks($db, $playlistId){
	$stmt = $db->prepare("SELECT DISTINCT p.id, p.title FROM playlist_items pi JOIN projects p ON p.id = pi.project_id
						  WHERE pi.playlist_id = ? AND p.is_public = 0 ORDER BY p.title");
	$stmt->execute([$playlistId]);
	return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Public playlists that include a project: [[id, title], ...]
function publicPlaylistsContaining($db, $projectId){
	$stmt = $db->prepare("SELECT DISTINCT pl.id, pl.title FROM playlist_items pi JOIN playlists pl ON pl.id = pi.playlist_id
						  WHERE pi.project_id = ? AND pl.is_public = 1 ORDER BY pl.title");
	$stmt->execute([$projectId]);
	return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function runDBmigration($schema, $db){
	$schema = (int)$schema;

	// all-or-nothing: if any step fails, the db is left exactly as it was
	$db->beginTransaction();
	try {
		// Migrate from v1 to v2:
		if ($schema < 2)
		{
			// add 'original filename' attribute to versions
			// (fresh installs made before this fix already have the column, so check first)
			if (!columnExists($db, 'versions', 'origfilename')) {
				$db->exec("ALTER TABLE versions ADD COLUMN origfilename TEXT NOT NULL DEFAULT ''");
			}
			// since users will be updating from v1, they wont have original filenames in the database; this will give them something at least
			$db->exec("UPDATE versions SET origfilename = filename WHERE origfilename = ''");
		}

		// Migrate from v2 to v3: playlists
		if ($schema < 3)
		{
			$db->exec("CREATE TABLE IF NOT EXISTS playlists (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				title TEXT NOT NULL,
				slug TEXT UNIQUE NOT NULL,
				description TEXT,
				is_public INTEGER DEFAULT 0,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP
			)");
			$db->exec("CREATE TABLE IF NOT EXISTS playlist_items (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				playlist_id INTEGER NOT NULL,
				project_id INTEGER NOT NULL,
				version_id INTEGER,
				position INTEGER NOT NULL,
				FOREIGN KEY(playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
				FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
				FOREIGN KEY(version_id) REFERENCES versions(id) ON DELETE CASCADE
			)");
			$db->exec("CREATE INDEX IF NOT EXISTS idx_playlist_items_playlist ON playlist_items(playlist_id, position)");
		}

		// Migrate from v3 to v4: play counts (per version; a song's total is the sum of its versions)
		if ($schema < 4)
		{
			if (!columnExists($db, 'versions', 'play_count')) {
				$db->exec("ALTER TABLE versions ADD COLUMN play_count INTEGER NOT NULL DEFAULT 0");
			}
		}

		// record the new schema version - do this last in case previous statements fail
		$stmt = $db->prepare("INSERT OR REPLACE INTO site_settings (setting_key, setting_value) VALUES ('db_schema', ?)");
		$stmt->execute([(string)DB_SCHEMA_VERSION]);

		$db->commit();
	} catch (Exception $e) {
		$db->rollBack();
		throw $e;
	}
}
?>