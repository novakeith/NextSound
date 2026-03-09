<?php 
// func.php - random misc functions i'll need to call
// will be included on a page as needed.

function checkDB_UpdateAvailable($schema, $targetschema){
	if (!isset($schema))
	{
		return TRUE;
	}
	
	if ($schema < $targetschema)
	{
		return TRUE;
	}
	else
	{
		// update not needed
		return FALSE;
	}
}

function runDBmigration($schema, $db){
	// Migrate from v1 to v2:
	if ($schema < 2)
	{
		// create a db schema version tracker
		$db->exec("INSERT OR REPLACE INTO site_settings (setting_key, setting_value) VALUES ('db_schema', '2')");
		
		// add 'original filename' attribute to versions 
		$db->exec("ALTER TABLE versions ADD COLUMN origfilename TEXT NOT NULL");
	}
}
?>