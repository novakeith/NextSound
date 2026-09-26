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

    // Honeypot: the comment form has a hidden "website" field that people never see or fill in.
    // Bots that fill in every field get a fake success, so they don't learn to skip it.
    if (param($_POST, 'website') !== '') {
        jsonResponse(200, ['status' => 'success', 'id' => 0]);
    }

    // At most 5 comments a minute / 30 an hour from one visitor (the admin is exempt)
    if (!isAdmin() && rateLimited($db, 'comment', [60 => 5, 3600 => 30])) {
        jsonResponse(429, ['status' => 'error', 'message' => "You're commenting too fast - wait a minute and try again."]);
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

// Count a play: the player calls this once a listener has heard about 5 seconds of a track.
// Rudimentary on purpose - the same visitor replaying the same version within 30 minutes counts once,
// one visitor can add at most 200 plays an hour, and the admin's own listening isn't counted.
if ($action === 'record_play') {
    if (!PLAY_COUNTS_ENABLED) jsonResponse(200, ['status' => 'ignored']);

    $version_id = (int)param($_POST, 'version_id');
    if (!resolveVersionAccess($db, $version_id, param($_POST, 'project_slug'), param($_POST, 'playlist_slug'))) {
        jsonResponse(404, ['status' => 'error', 'message' => 'Track not found.']);
    }

    // forget plays older than 30 minutes, so the session doesn't grow forever
    $recent = array_filter($_SESSION['plays'] ?? [], fn($t) => $t > time() - 1800);
    $counted = false;
    if (!isAdmin() && !isset($recent[$version_id]) && !rateLimited($db, 'play', [3600 => 200])) {
        $db->prepare("UPDATE versions SET play_count = play_count + 1 WHERE id = ?")->execute([$version_id]);
        $recent[$version_id] = time();
        $counted = true;
    }
    $_SESSION['plays'] = $recent;

    $response = ['status' => 'success', 'counted' => $counted];
    if (showPlayCounts($settings)) {
        $stmt = $db->prepare("SELECT project_id FROM versions WHERE id = ?");
        $stmt->execute([$version_id]);
        $response['plays'] = projectPlayCount($db, $stmt->fetchColumn());
    }
    jsonResponse(200, $response);
}

jsonResponse(400, ['status' => 'error', 'message' => 'Unknown action.']);
