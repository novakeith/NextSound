<?php
// tracks.php - shared helpers for loading tracks/playlists and checking who can reach a version.
// Used by the public pages (index.php, playlist.php), action.php, download.php and the admin pages.

// The version a project plays by default: the active one, or the newest if nothing is marked active.
function getCurrentVersion($db, $projectId) {
	$stmt = $db->prepare("SELECT * FROM versions WHERE project_id = ? ORDER BY is_active DESC, version_number DESC LIMIT 1");
	$stmt->execute([$projectId]);
	return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getVersionComments($db, $versionId) {
	$stmt = $db->prepare("SELECT id, timestamp, author_name, text, status FROM comments WHERE version_id = ? ORDER BY timestamp ASC");
	$stmt->execute([$versionId]);
	return array_map(fn($c) => [
		'id' => (int)$c['id'],
		'timestamp' => (float)$c['timestamp'],
		'author' => $c['author_name'] ?: 'Anonymous',
		'text' => $c['text'],
		'status' => $c['status'],
	], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Everything the player needs for one track, as a plain array (it gets json_encoded into the page).
// $access is the query string that proves the listener may reach this version - either the project's
// share slug ('s=...') or the playlist's slug ('p=...'), so playlist pages never reveal a track's own share link.
function buildTrack($db, $project, $version, $access, $withComments, $pinned = false) {
	return [
		'versionId' => (int)$version['id'],
		'versionNumber' => (int)$version['version_number'],
		'pinned' => $pinned,
		'title' => $project['title'],
		'artist' => $project['artistname'],
		'notes' => $project['notes'] ?? '',
		'changelog' => $version['changelog'] ?? '',
		'audioUrl' => '/uploads/' . (int)$project['id'] . '/' . rawurlencode($version['filename']),
		'downloadUrl' => $version['allow_download'] ? '/download.php?id=' . (int)$version['id'] . '&' . $access : null,
		'comments' => $withComments ? getVersionComments($db, $version['id']) : [],
	];
}

function getPlaylistBySlug($db, $slug) {
	$stmt = $db->prepare("SELECT * FROM playlists WHERE slug = ?");
	$stmt->execute([$slug]);
	return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Playlist items in order, each with its project row and the version it resolves to.
// Items whose project currently has no versions are skipped.
function getPlaylistEntries($db, $playlistId) {
	$stmt = $db->prepare("SELECT pi.id AS item_id, pi.version_id AS pinned_version_id, p.*
						  FROM playlist_items pi JOIN projects p ON p.id = pi.project_id
						  WHERE pi.playlist_id = ? ORDER BY pi.position, pi.id");
	$stmt->execute([$playlistId]);

	$versionStmt = $db->prepare("SELECT * FROM versions WHERE id = ?");
	$entries = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
		if ($row['pinned_version_id']) {
			$versionStmt->execute([$row['pinned_version_id']]);
			$version = $versionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
		} else {
			$version = getCurrentVersion($db, $row['id']);
		}
		if (!$version) continue;
		$entries[] = ['item_id' => (int)$row['item_id'], 'pinned' => (bool)$row['pinned_version_id'], 'project' => $row, 'version' => $version];
	}
	return $entries;
}

// Can a listener holding this share link reach this version? Returns [project title, public page path] or null.
// Via a project link: the version must belong to that project.
// Via a playlist link: the version must be pinned in that playlist, or belong to a project the playlist follows.
function resolveVersionAccess($db, $versionId, $projectSlug, $playlistSlug) {
	if ($projectSlug !== '') {
		$stmt = $db->prepare("SELECT p.title FROM versions v JOIN projects p ON p.id = v.project_id WHERE v.id = ? AND p.slug = ?");
		$stmt->execute([$versionId, $projectSlug]);
		$title = $stmt->fetchColumn();
		return $title === false ? null : ['title' => $title, 'path' => '/share/' . $projectSlug];
	}
	if ($playlistSlug !== '' && PLAYLISTS_ENABLED) {
		$stmt = $db->prepare("SELECT p.title FROM versions v
							  JOIN projects p ON p.id = v.project_id
							  JOIN playlist_items pi ON pi.project_id = v.project_id
							  JOIN playlists pl ON pl.id = pi.playlist_id
							  WHERE v.id = ? AND pl.slug = ? AND (pi.version_id = v.id OR pi.version_id IS NULL)
							  LIMIT 1");
		$stmt->execute([$versionId, $playlistSlug]);
		$title = $stmt->fetchColumn();
		return $title === false ? null : ['title' => $title, 'path' => '/playlist/' . $playlistSlug];
	}
	return null;
}

// Comments are visible to everyone when enabled, and always to the admin.
function commentsVisible($settings) {
	return ($settings['comments_enabled'] ?? '1') === '1' || isAdmin();
}
?>
