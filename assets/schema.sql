-- schema.sql
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
		origfilename TEXT NOT NULL,
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
	INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES ('site_title', 'Nextsound');
	INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES ('site_url', '127.0.0.1:8027');