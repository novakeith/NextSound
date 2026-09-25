<?php
// this file is essentially the non-admin API file - what a non-logged in user can do, it will flow through here. (Eventually)
require_once('config.php');
require_once('assets/tracks.php');

$action = param($_POST, 'action');

// lets turn away nosy nancy's
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$action){ http_response_code(400); die('Unauthorized'); }

function jsonResponse($code, $data) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Handle Comments on a project
if ($action === 'add_comment') {
    // comments can be switched off site-wide; admins can still leave notes for themselves
    if (($settings['comments_enabled'] ?? '1') !== '1' && !isAdmin()) {
        jsonResponse(403, ['status' => 'error', 'message' => 'Comments are disabled.']);
    }

    $version_id = (int)($_POST['version_id'] ?? 0);
    $projectSlug = param($_POST, 'project_slug');
    $playlistSlug = param($_POST, 'playlist_slug');
    $timestamp = max(0, (float)($_POST['timestamp'] ?? 0));
    $author = mb_substr(trim(param($_POST, 'author')), 0, 100) ?: 'Anonymous';
    $text = mb_substr(trim(param($_POST, 'text')), 0, 5000);

    if ($text === '') {
        jsonResponse(400, ['status' => 'error', 'message' => 'Comment is empty.']);
    }

    // Look the project up from the db rather than trusting what the browser sent.
    // Requiring the track's or playlist's slug too means you can only comment on tracks you have a link for.
    $access = resolveVersionAccess($db, $version_id, $projectSlug, $playlistSlug);
    if (!$access) {
        jsonResponse(404, ['status' => 'error', 'message' => 'Track not found.']);
    }

    // Give the commenter a 30-day tracking cookie
	// eventually this will mean they wont have to retype their name,
	// and I can give them controls to edit/delete their comments. But not yet.
	// commenting this out for now.
    //$author_token = $_COOKIE['nextsound_guest'] ?? bin2hex(random_bytes(16));
    //setcookie('nextsound_guest', $author_token, time() + (60 * 60 * 24 * 30), "/");
    $author_token = null;

    $stmt = $db->prepare("INSERT INTO comments (version_id, timestamp, author_name, author_token, text) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$version_id, $timestamp, $author, $author_token, $text]);
    $commentId = $db->lastInsertId();

	// send webhook msg
	notifyWebhook($settings, 'comment', "New comment left on project '" . $access['title'] . "', at URL " . siteLink($settings, $access['path']));

    // Respond with success so the frontend knows to show it
    jsonResponse(200, ['status' => 'success', 'id' => (int)$commentId]);
}

jsonResponse(400, ['status' => 'error', 'message' => 'Unknown action.']);
