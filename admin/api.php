<?php
require_once('../config.php');
require_once('../assets/func.php');

if (!isAdmin()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// every admin action changes data, so they must all be POSTs carrying the CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}
// when an upload exceeds post_max_size, PHP silently drops the whole request body (token included)
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    die('Upload failed: the file is larger than the server allows (post_max_size = ' . h(ini_get('post_max_size')) . ').');
}
verify_csrf();

$action = $_POST['action'] ?? '';

// Validates an uploaded audio file and returns the extension to save it with; dies with a message if it's no good.
function validateAudioUpload($file) {
	if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
		die("Upload failed (file error code: " . ($file['error'] ?? 'none') . "). The file may be larger than the server's upload limit.");
	}
	// checking against allowed MIME types, detected from the file contents (not the filename)
	$finfo = new finfo(FILEINFO_MIME_TYPE);
	$mime = $finfo->file($file['tmp_name']);
	if (!isset(ALLOWED_AUDIO_TYPES[$mime])) {
		die('Uploaded file type is not allowed (' . h($mime) . ')');
	}
	return ALLOWED_AUDIO_TYPES[$mime];
}

// --- Upload New Project ---
if ($action === 'new_project') {
    $title = trim($_POST['title'] ?? '') ?: 'Untitled Project'; // defaults the title if one isnt specified
    $slug = generateSlug(); // shareable link slug - unique ID for this song visible to users
    $file = $_FILES['audio_file'] ?? null; // audio file
	$notes = $_POST['notes'] ?? ''; // proj description
	$changelog = $_POST['changelog'] ?? ''; // capture 'change log' data
	$artistname = $_POST['artistname'] ?? ''; //grab artist name
	$downloads = (($_POST['downloads'] ?? '0') === '1') ? 1 : 0; // 0=no, 1=yes for downloads enabled

	$ext = validateAudioUpload($file);

    $db->beginTransaction();
    try {
        // Create Project
        $stmt = $db->prepare("INSERT INTO projects (title, artistname, slug, notes) VALUES (?, ?, ?, ?)");
		$stmt->execute([$title, $artistname, $slug, $notes]);
        $projectId = $db->lastInsertId();

        // Create Project Folder
        $projectFolder = UPLOAD_DIR . $projectId . '/';
        if (!is_dir($projectFolder) && !mkdir($projectFolder, 0775, true)) {
            throw new Exception("Could not create upload folder");
        }

        // Move File - named with its own random token, so it's URL-safe, not guessable, and doesn't reveal the share link
        $filename = generateSlug(16) . "_v1." . $ext;
        if (!move_uploaded_file($file['tmp_name'], $projectFolder . $filename)) {
            throw new Exception("Could not save uploaded file");
        }

        // Log Version 1
        $stmt = $db->prepare("INSERT INTO versions (project_id, filename, origfilename, version_number, changelog, allow_download) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$projectId, $filename, $file['name'], 1, $changelog, $downloads]);

        $db->commit();
        header("Location: index.php?success=ProjectCreated");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        die("Upload Failed: " . h($e->getMessage()));
    }
}

// --- Add New Version to Existing Project ---
if ($action === 'new_version') {
    $projectId = (int)$_POST['project_id'];
    $file = $_FILES['audio_file'] ?? null;
	$changelog = $_POST['changelog'] ?? ''; // capture 'change log' data
	$downloads = (($_POST['downloads'] ?? '0') === '1') ? 1 : 0; // 0=no, 1=yes for downloads enabled

	$stmt = $db->prepare("SELECT 1 FROM projects WHERE id = ?");
	$stmt->execute([$projectId]);
	if (!$stmt->fetchColumn()) die("Project not found.");

	$ext = validateAudioUpload($file);

    $db->beginTransaction();
    try {
        // Next version number = highest existing + 1 (COUNT would repeat numbers after a version is deleted)
        $stmt = $db->prepare("SELECT COALESCE(MAX(version_number), 0) FROM versions WHERE project_id = ?");
        $stmt->execute([$projectId]);
        $nextVersion = $stmt->fetchColumn() + 1;

        $projectFolder = UPLOAD_DIR . $projectId . '/';
        if (!is_dir($projectFolder) && !mkdir($projectFolder, 0775, true)) {
            throw new Exception("Could not create upload folder");
        }

        $filename = generateSlug(16) . "_v" . $nextVersion . "." . $ext;
        if (!move_uploaded_file($file['tmp_name'], $projectFolder . $filename)) {
            throw new Exception("Could not save uploaded file");
        }

        // Set all other versions to inactive - only once the new file is safely in place
        $db->prepare("UPDATE versions SET is_active = 0 WHERE project_id = ?")->execute([$projectId]);

		$stmt = $db->prepare("INSERT INTO versions (project_id, filename, origfilename, version_number, changelog, allow_download, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
		$stmt->execute([$projectId, $filename, $file['name'], $nextVersion, $changelog, $downloads]);

        $db->commit();
        header("Location: edit.php?id=" . $projectId . "&success=VersionAdded");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        die("Upload Failed: " . h($e->getMessage()));
    }
}

// --- Toggle Privacy of a Project---
if ($action === 'toggle_privacy') {
    $projectId = (int)$_POST['project_id'];
    $confirmed = ($_POST['resolve'] ?? '') === 'anyway';

    $stmt = $db->prepare("SELECT is_public FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $isPublic = $stmt->fetchColumn();
    if ($isPublic === false) die("Project not found.");

    // Going private while in public playlists: it stays playable there, so check the admin knows that first
    if ($isPublic && !$confirmed && PLAYLISTS_ENABLED && publicPlaylistsContaining($db, $projectId)) {
        header("Location: index.php?confirm_private=" . $projectId);
        exit;
    }

    $stmt = $db->prepare("UPDATE projects SET is_public = ? WHERE id = ?");
    $stmt->execute([$isPublic ? 0 : 1, $projectId]);
    header("Location: index.php");
    exit;
}

// --- Update Project & Versions ---
if ($action === 'update_project') {
    $projectId = (int)$_POST['project_id'];
    $title = $_POST['title'] ?? '';
    $notes = $_POST['notes'] ?? '';
	$artistname = $_POST['artistname'] ?? '';

    $db->beginTransaction();
    try {
        // Update main project info
        $stmt = $db->prepare("UPDATE projects SET title = ?, notes = ?, artistname = ? WHERE id = ?");
        $stmt->execute([$title, $notes, $artistname, $projectId]);

        // Update version changelogs (if any) - only versions that belong to this project
        if (isset($_POST['versions']) && is_array($_POST['versions'])) {
            $stmt = $db->prepare("UPDATE versions SET changelog = ? WHERE id = ? AND project_id = ?");
            foreach ($_POST['versions'] as $versionId => $changelog) {
                $stmt->execute([$changelog, (int)$versionId, $projectId]);
            }
        }

        $db->commit();
        header("Location: edit.php?id=$projectId&success=1");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        die("Update Failed: " . h($e->getMessage()));
    }
}

// --- Delete Project & Files ---
if ($action === 'delete_project') {
    $projectId = (int)$_POST['project_id'];

    // Get project info to find the folder
    $projectFolder = UPLOAD_DIR . $projectId;

    $db->beginTransaction();
    try {
        // Remove from DB (Cascade will handle versions and comments)
        $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);

        // Delete files
        if ($projectId > 0 && is_dir($projectFolder)) {
            $files = glob($projectFolder . '/*');
            foreach($files as $file){ if(is_file($file)) unlink($file); }
            rmdir($projectFolder);
        }

        $db->commit();
        header("Location: index.php?success=Deleted");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        die("Delete Failed: " . h($e->getMessage()));
    }
}

// --- Delete specific version of a track inside a project ---
if ($action === 'delete_version') {
    $versionId = (int)$_POST['version_id'];

    // Get the filename to unlink the physical file (and the project it belongs to, from the db rather than the form)
    $stmt = $db->prepare("SELECT filename, project_id, is_active FROM versions WHERE id = ?");
    $stmt->execute([$versionId]);
    $version = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$version) die("Version not found.");
    $projectId = (int)$version['project_id'];

    $db->beginTransaction();
    try {
        // Delete from DB
        $stmt = $db->prepare("DELETE FROM versions WHERE id = ?");
        $stmt->execute([$versionId]);

        // If we just deleted the active version, promote the newest remaining one,
        // otherwise the share page has nothing to play and 404s.
        if ($version['is_active']) {
            $stmt = $db->prepare("UPDATE versions SET is_active = 1 WHERE id = (SELECT id FROM versions WHERE project_id = ? ORDER BY version_number DESC LIMIT 1)");
            $stmt->execute([$projectId]);
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        die("Delete Failed: " . h($e->getMessage()));
    }

    // only remove the file once the db change has stuck
    $filePath = UPLOAD_DIR . $projectId . '/' . basename($version['filename']);
    if (is_file($filePath)) {
        unlink($filePath);
    }

    header("Location: edit.php?id=$projectId&success=VersionDeleted");
    exit;
}

// --- Admins can mark a status as Resolved/Declined to indicate whether or not its worthwhile feedback.
if ($action === 'set_comment_status') {
    $commentId = (int)$_POST['comment_id'];
    $status = $_POST['status'] ?? '';

    if (!in_array($status, ['pending', 'accepted', 'rejected'], true)) {
        http_response_code(400);
        die("Invalid status");
    }

    $stmt = $db->prepare("UPDATE comments SET status = ? WHERE id = ?");
    $stmt->execute([$status, $commentId]);

    http_response_code(200);
    echo "Success";
    exit;
}

// --- Admins can also straight up delete a comment, for example if something is inappropriate
if ($action === 'delete_comment') {
    $commentId = (int)$_POST['comment_id'];

    $stmt = $db->prepare("DELETE FROM comments WHERE id = ?");
    $stmt->execute([$commentId]);

    // Return success for the JS fetch
    http_response_code(200);
    echo "Deleted";
    exit;
}

// --- toggling downloads on the admin edit page... Per version!
if ($action === 'toggle_download') {
    $versionId = (int)$_POST['version_id'];
    $status = ((int)$_POST['status'] === 1) ? 1 : 0;

    $stmt = $db->prepare("UPDATE versions SET allow_download = ? WHERE id = ?");
    $stmt->execute([$status, $versionId]);

    http_response_code(200);
    echo "Status Updated";
    exit;
}

// --- admin initiated database update:
if ($action === 'db_update') {
	$schema = $settings['db_schema'] ?? 1;
	try {
		runDBmigration($schema, $db);
	} catch (Exception $e) {
        die("Migration Failed: " . h($e->getMessage()));
    }

	header("Location: settings.php?success=DBUpdated");
	exit;
}

// ==========================================================
// Playlists
// ==========================================================
if (str_contains($action, 'playlist') && !PLAYLISTS_ENABLED) {
    die("Playlists need a database update first - run it from the Settings page.");
}

// --- Create a playlist ---
if ($action === 'new_playlist') {
    $title = trim($_POST['title'] ?? '') ?: 'Untitled Playlist';
    $description = $_POST['description'] ?? '';

    $stmt = $db->prepare("INSERT INTO playlists (title, slug, description) VALUES (?, ?, ?)");
    $stmt->execute([$title, generateSlug(), $description]);
    header("Location: playlist_edit.php?id=" . $db->lastInsertId());
    exit;
}

// --- Edit playlist title/description ---
if ($action === 'update_playlist') {
    $playlistId = (int)$_POST['playlist_id'];
    $title = trim($_POST['title'] ?? '') ?: 'Untitled Playlist';

    $stmt = $db->prepare("UPDATE playlists SET title = ?, description = ? WHERE id = ?");
    $stmt->execute([$title, $_POST['description'] ?? '', $playlistId]);
    header("Location: playlist_edit.php?id=$playlistId&success=Saved");
    exit;
}

// --- Delete a playlist (the tracks themselves are untouched) ---
if ($action === 'delete_playlist') {
    $stmt = $db->prepare("DELETE FROM playlists WHERE id = ?");
    $stmt->execute([(int)$_POST['playlist_id']]);
    header("Location: playlists.php?success=Deleted");
    exit;
}

// --- Toggle Privacy of a Playlist ---
// resolve: '' (ask first if needed) | 'anyway' | 'make_tracks_public'
if ($action === 'toggle_playlist_privacy') {
    $playlistId = (int)$_POST['playlist_id'];
    $resolve = $_POST['resolve'] ?? '';
    $return = ($_POST['return'] ?? '') === 'edit' ? "playlist_edit.php?id=$playlistId" : "playlists.php";

    $stmt = $db->prepare("SELECT is_public FROM playlists WHERE id = ?");
    $stmt->execute([$playlistId]);
    $isPublic = $stmt->fetchColumn();
    if ($isPublic === false) die("Playlist not found.");

    // Going public with private tracks inside: check the admin knows they'll be playable from the home page
    if (!$isPublic && $resolve === '' && playlistPrivateTracks($db, $playlistId)) {
        header("Location: playlist_edit.php?id=$playlistId&confirm=public&return=" . (($_POST['return'] ?? '') === 'edit' ? 'edit' : 'list'));
        exit;
    }

    $db->beginTransaction();
    if (!$isPublic && $resolve === 'make_tracks_public') {
        $stmt = $db->prepare("UPDATE projects SET is_public = 1 WHERE id IN (SELECT project_id FROM playlist_items WHERE playlist_id = ?)");
        $stmt->execute([$playlistId]);
    }
    $stmt = $db->prepare("UPDATE playlists SET is_public = ? WHERE id = ?");
    $stmt->execute([$isPublic ? 0 : 1, $playlistId]);
    $db->commit();

    header("Location: $return");
    exit;
}

// --- Add a track to a playlist ---
// version_id: '' = follow the project's current version, or a version id to pin
// resolve: '' (ask first if needed) | 'anyway' | 'make_track_public' | 'make_playlist_private'
if ($action === 'add_playlist_item') {
    $playlistId = (int)$_POST['playlist_id'];
    $projectId = (int)$_POST['project_id'];
    $versionId = ($_POST['version_id'] ?? '') === '' ? null : (int)$_POST['version_id'];
    $resolve = $_POST['resolve'] ?? '';

    $stmt = $db->prepare("SELECT is_public FROM playlists WHERE id = ?");
    $stmt->execute([$playlistId]);
    $playlistPublic = $stmt->fetchColumn();
    $stmt = $db->prepare("SELECT is_public FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $projectPublic = $stmt->fetchColumn();
    if ($playlistPublic === false || $projectPublic === false) die("Playlist or project not found.");

    if ($versionId !== null) {
        $stmt = $db->prepare("SELECT 1 FROM versions WHERE id = ? AND project_id = ?");
        $stmt->execute([$versionId, $projectId]);
        if (!$stmt->fetchColumn()) die("That version doesn't belong to this project.");
    }

    // A private track going into a public playlist: check with the admin first
    if ($playlistPublic && !$projectPublic && $resolve === '') {
        header("Location: playlist_edit.php?id=$playlistId&confirm=add&project_id=$projectId&version_id=" . ($versionId ?? ''));
        exit;
    }

    $db->beginTransaction();
    if ($resolve === 'make_track_public') {
        $db->prepare("UPDATE projects SET is_public = 1 WHERE id = ?")->execute([$projectId]);
    } elseif ($resolve === 'make_playlist_private') {
        $db->prepare("UPDATE playlists SET is_public = 0 WHERE id = ?")->execute([$playlistId]);
    }
    $stmt = $db->prepare("INSERT INTO playlist_items (playlist_id, project_id, version_id, position)
                          VALUES (?, ?, ?, (SELECT COALESCE(MAX(position), 0) + 1 FROM playlist_items WHERE playlist_id = ?))");
    $stmt->execute([$playlistId, $projectId, $versionId, $playlistId]);
    $db->commit();

    header("Location: playlist_edit.php?id=$playlistId#tracks");
    exit;
}

// the remaining actions work on one playlist item
if (in_array($action, ['remove_playlist_item', 'move_playlist_item', 'set_playlist_item_version'], true)) {
    $stmt = $db->prepare("SELECT * FROM playlist_items WHERE id = ?");
    $stmt->execute([(int)$_POST['item_id']]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) die("Playlist item not found.");
    $back = "Location: playlist_edit.php?id=" . $item['playlist_id'] . "#tracks";

    // --- Remove a track from a playlist ---
    if ($action === 'remove_playlist_item') {
        $db->prepare("DELETE FROM playlist_items WHERE id = ?")->execute([$item['id']]);
    }

    // --- Move a track up/down by swapping places with its neighbour ---
    if ($action === 'move_playlist_item') {
        $up = ($_POST['dir'] ?? '') === 'up';
        $stmt = $db->prepare("SELECT id, position FROM playlist_items WHERE playlist_id = ? AND position " . ($up ? '<' : '>') . " ?
                              ORDER BY position " . ($up ? 'DESC' : 'ASC') . " LIMIT 1");
        $stmt->execute([$item['playlist_id'], $item['position']]);
        $neighbour = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($neighbour) {
            $db->beginTransaction();
            $swap = $db->prepare("UPDATE playlist_items SET position = ? WHERE id = ?");
            $swap->execute([$neighbour['position'], $item['id']]);
            $swap->execute([$item['position'], $neighbour['id']]);
            $db->commit();
        }
    }

    // --- Pin an item to a version, or set it back to following the latest ('') ---
    if ($action === 'set_playlist_item_version') {
        $versionId = ($_POST['version_id'] ?? '') === '' ? null : (int)$_POST['version_id'];
        if ($versionId !== null) {
            $stmt = $db->prepare("SELECT 1 FROM versions WHERE id = ? AND project_id = ?");
            $stmt->execute([$versionId, $item['project_id']]);
            if (!$stmt->fetchColumn()) die("That version doesn't belong to this project.");
        }
        $db->prepare("UPDATE playlist_items SET version_id = ? WHERE id = ?")->execute([$versionId, $item['id']]);
    }

    header($back);
    exit;
}

http_response_code(400);
echo "Unknown action";
