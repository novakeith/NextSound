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