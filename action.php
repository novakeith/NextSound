<?php
// this file is essentially the non-admin API file - what a non-logged in user can do, it will flow through here. (Eventually)
require_once('config.php');

$action = $_POST['action'] ?? '';

// lets turn away nosy nancy's
if (!$action){ die('Unauthorized'); }

// Handle Comments on a project 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
    $version_id = (int)$_POST['version_id'];
    $timestamp = (float)$_POST['timestamp'];
    $author = trim($_POST['author']) ?: 'Anonymous';
    $text = trim($_POST['text']);
	$title = $_POST['project_title'];
	$slug = $_POST['project_slug'];
    
    // Give the commenter a 30-day tracking cookie
	// eventually this will mean they wont have to retype their name, 
	// and I can give them controls to edit/delete their comments. But not yet.
	// commenting this out for now.
    //$author_token = $_COOKIE['nextsound_guest'] ?? bin2hex(random_bytes(16));
    //setcookie('nextsound_guest', $author_token, time() + (60 * 60 * 24 * 30), "/"); 

    if (!empty($text)) {
        $stmt = $db->prepare("INSERT INTO comments (version_id, timestamp, author_name, author_token, text) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$version_id, $timestamp, $author, $author_token, $text]);
		
		// send webhook msg
		sendwebhookNotification($settings['webhook_url'], "New comment left on project '" . $title . "', at URL " . $settings['site_url'] . "/share/" . $slug);
				
    }
    
    // Respond with success so the frontend knows to reload
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
    exit;
}
?>