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

// The bulk uploader and drag-and-drop reordering call in the background and want JSON back instead of a redirect
$wantsJson = ($_POST['ajax'] ?? '') === '1';
function jsonOut($code, $data) {
	http_response_code($code);
	header('Content-Type: application/json');
	echo json_encode($data);
	exit;
}

// Validates an uploaded audio file and returns the extension to save it with; throws with a message if it's no good.
function validateAudioUpload($file) {
	if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
		throw new Exception("Upload failed (file error code: " . ($file['error'] ?? 'none') . "). The file may be larger than the server's upload limit.");
	}
	// checking against allowed MIME types, detected from the file contents (not the filename)
	$finfo = new finfo(FILEINFO_MIME_TYPE);
	$mime = $finfo->file($file['tmp_name']);
	if (!isset(ALLOWED_AUDIO_TYPES[$mime])) {
		throw new Exception('Uploaded file type is not allowed (' . $mime . ')');
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

    try {
		$ext = validateAudioUpload($file);
        $db->beginTransaction();

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

        // the bulk uploader sends one combined message for its whole batch instead (see notify_upload_batch)
        if (($_POST['batch'] ?? '') !== '1') {
            notifyWebhook($settings, 'track', "New track uploaded: '$title'" . ($artistname !== '' ? " by $artistname" : '') . ", at URL " . siteLink($settings, "/share/$slug"));
        }

        if ($wantsJson) jsonOut(200, ['status' => 'success', 'id' => (int)$projectId, 'slug' => $slug]);
        header("Location: index.php?success=ProjectCreated");
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($wantsJson) jsonOut(400, ['status' => 'error', 'message' => $e->getMessage()]);
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

    try {
		$ext = validateAudioUpload($file);
        $db->beginTransaction();

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
        if ($db->inTransaction()) $db->rollBack();
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

// Deletes a project: its db rows (versions, comments and playlist entries cascade) and then its files.
// Throws if the db delete fails; files are only removed once the db change has stuck.
function deleteProject($db, $projectId) {
    $projectId = (int)$projectId;
    if ($projectId <= 0) return;

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    $projectFolder = UPLOAD_DIR . $projectId;
    if (is_dir($projectFolder)) {
        foreach (glob($projectFolder . '/*') as $file) { if (is_file($file)) unlink($file); }
        rmdir($projectFolder);
    }
}

// project_ids[] from a bulk form, as ints, in the order they were sent (= the order shown on the dashboard)
function postedProjectIds() {
    $ids = is_array($_POST['project_ids'] ?? null) ? $_POST['project_ids'] : [];
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

// --- Delete Project & Files ---
if ($action === 'delete_project') {
    try {
        deleteProject($db, $_POST['project_id'] ?? 0);
    } catch (Exception $e) {
        die("Delete Failed: " . h($e->getMessage()));
    }
    header("Location: index.php?success=Deleted");
    exit;
}

// --- One webhook message for a whole bulk upload, sent by the uploader when its batch finishes ---
if ($action === 'notify_upload_batch') {
    $ids = postedProjectIds();
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT title, artistname, slug FROM projects WHERE id IN ($in)");
        $stmt->execute($ids);
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($tracks) === 1) {
            $t = $tracks[0];
            notifyWebhook($settings, 'track', "New track uploaded: '{$t['title']}'" . ($t['artistname'] !== '' ? " by {$t['artistname']}" : '') . ", at URL " . siteLink($settings, "/share/{$t['slug']}"));
        } elseif ($tracks) {
            $lines = array_map(fn($t) => "- {$t['title']}: " . siteLink($settings, "/share/{$t['slug']}"), $tracks);
            notifyWebhook($settings, 'track', count($tracks) . " new tracks uploaded:\n" . implode("\n", $lines));
        }
    }
    jsonOut(200, ['status' => 'success']);
}

// --- Bulk delete projects (dashboard checkboxes) ---
if ($action === 'bulk_delete_projects') {
    $ids = postedProjectIds();
    if (!$ids) die("No tracks were selected.");
    try {
        foreach ($ids as $projectId) deleteProject($db, $projectId);
    } catch (Exception $e) {
        die("Delete Failed: " . h($e->getMessage()));
    }
    header("Location: index.php?success=Deleted");
    exit;
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
// Optionally pre-filled with project_ids[] (in order) - the bulk uploader uses this to turn an upload batch into a playlist.
if ($action === 'new_playlist') {
    $title = trim($_POST['title'] ?? '') ?: 'Untitled Playlist';
    $description = $_POST['description'] ?? '';
    $projectIds = array_map('intval', is_array($_POST['project_ids'] ?? null) ? $_POST['project_ids'] : []);

    $db->beginTransaction();
    $slug = generateSlug();
    $stmt = $db->prepare("INSERT INTO playlists (title, slug, description) VALUES (?, ?, ?)");
    $stmt->execute([$title, $slug, $description]);
    $playlistId = (int)$db->lastInsertId();

    // new playlists start private, so no public/private warnings apply here
    $stmt = $db->prepare("INSERT INTO playlist_items (playlist_id, project_id, position) SELECT ?, id, ? FROM projects WHERE id = ?");
    foreach ($projectIds as $i => $projectId) {
        $stmt->execute([$playlistId, $i + 1, $projectId]);
    }
    $db->commit();

    notifyWebhook($settings, 'playlist', "New playlist created: '$title', at URL " . siteLink($settings, "/playlist/$slug"));

    if ($wantsJson) jsonOut(200, ['status' => 'success', 'id' => $playlistId, 'slug' => $slug]);
    header("Location: playlist_edit.php?id=" . $playlistId);
    exit;
}

// --- Reorder a whole playlist at once (drag and drop) ---
// item_ids[] must be exactly this playlist's items, in their new order.
if ($action === 'reorder_playlist') {
    $playlistId = (int)$_POST['playlist_id'];
    $newOrder = array_map('intval', is_array($_POST['item_ids'] ?? null) ? $_POST['item_ids'] : []);

    $stmt = $db->prepare("SELECT id FROM playlist_items WHERE playlist_id = ?");
    $stmt->execute([$playlistId]);
    $current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    // someone else may have changed the playlist in another tab - don't guess, ask for a reload
    $a = $current; $b = $newOrder; sort($a); sort($b);
    if ($a !== $b) jsonOut(409, ['status' => 'error', 'message' => 'This playlist changed since the page loaded. Reload and try again.']);

    $db->beginTransaction();
    $stmt = $db->prepare("UPDATE playlist_items SET position = ? WHERE id = ?");
    foreach ($newOrder as $i => $itemId) {
        $stmt->execute([$i + 1, $itemId]);
    }
    $db->commit();
    jsonOut(200, ['status' => 'success']);
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

// --- Bulk add projects to a playlist (dashboard checkboxes) ---
// playlist_id: an existing playlist's id, or 'new' (then new_playlist_title names it)
// resolve: '' (ask first if needed) | 'anyway' | 'make_tracks_public' | 'make_playlist_private'
if ($action === 'bulk_add_to_playlist') {
    $ids = postedProjectIds();
    $target = (string)($_POST['playlist_id'] ?? '');
    $resolve = $_POST['resolve'] ?? '';
    if (!$ids) die("No tracks were selected.");

    // keep only projects that exist, in the order they were sent
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, is_public FROM projects WHERE id IN ($in)");
    $stmt->execute($ids);
    $publicById = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'is_public', 'id');
    $ids = array_values(array_filter($ids, fn($id) => isset($publicById[$id])));
    if (!$ids) die("None of the selected tracks exist any more.");

    $db->beginTransaction();
    if ($target === 'new') {
        // brand-new playlists start private, so no public/private warning applies
        $title = trim($_POST['new_playlist_title'] ?? '') ?: 'Untitled Playlist';
        $newSlug = generateSlug();
        $stmt = $db->prepare("INSERT INTO playlists (title, slug) VALUES (?, ?)");
        $stmt->execute([$title, $newSlug]);
        $playlistId = (int)$db->lastInsertId();
    } else {
        $playlistId = (int)$target;
        $stmt = $db->prepare("SELECT is_public FROM playlists WHERE id = ?");
        $stmt->execute([$playlistId]);
        $playlistPublic = $stmt->fetchColumn();
        if ($playlistPublic === false) { $db->rollBack(); die("Playlist not found."); }

        // private tracks going into a public playlist: check with the admin first (same choices as adding one track)
        $privateIds = array_values(array_filter($ids, fn($id) => !$publicById[$id]));
        if ($playlistPublic && $privateIds && $resolve === '') {
            $db->rollBack();
            header("Location: index.php?confirm_bulk_add=$playlistId&ids=" . implode(',', $ids));
            exit;
        }
        if ($privateIds && $resolve === 'make_tracks_public') {
            $in = implode(',', array_fill(0, count($privateIds), '?'));
            $db->prepare("UPDATE projects SET is_public = 1 WHERE id IN ($in)")->execute($privateIds);
        } elseif ($resolve === 'make_playlist_private') {
            $db->prepare("UPDATE playlists SET is_public = 0 WHERE id = ?")->execute([$playlistId]);
        }
    }

    // append to the end of the playlist, in dashboard order
    $stmt = $db->prepare("INSERT INTO playlist_items (playlist_id, project_id, position)
                          VALUES (?, ?, (SELECT COALESCE(MAX(position), 0) + 1 FROM playlist_items WHERE playlist_id = ?))");
    foreach ($ids as $projectId) $stmt->execute([$playlistId, $projectId, $playlistId]);
    $db->commit();

    if ($target === 'new') {
        notifyWebhook($settings, 'playlist', "New playlist created: '$title', at URL " . siteLink($settings, "/playlist/$newSlug"));
    }

    header("Location: playlist_edit.php?id=$playlistId&success=TracksAdded#tracks");
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
