<?php
require_once('../config.php');

if (!isAdmin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';

// --- Upload New Project ---
if ($action === 'new_project') {
    $title = $_POST['title'] ?: 'Untitled Project'; // defaults the title if one isnt specified
    $slug = generateSlug(); // shareable link slug - unique ID for this song visible to users
    $file = $_FILES['audio_file']; // audio file
	$notes = $_POST['notes'] ?? ''; // proj description
	$changelog = $_POST['changelog'] ?? ''; // capture 'change log' data
	$artistname = $_POST['artistname'] ?? ''; //grab artist name

	$downloads = $_POST['downloads'] ?? '0'; // 0=no, 1=yes for downloads enabled
	if ($downloads !== "1") { $downloads == 0; }

    if ($file['error'] === UPLOAD_ERR_OK) {
        $db->beginTransaction();
        try {

            // Create Project
            $stmt = $db->prepare("INSERT INTO projects (title, artistname, slug, notes) VALUES (?, ?, ?, ?)");
			$stmt->execute([$title, $artistname, $slug, $notes]);
            $projectId = $db->lastInsertId();

            // Create Project Folder
            $projectFolder = UPLOAD_DIR . $projectId . '/';
            mkdir($projectFolder, 0775, true);

            // Move File
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = $title . "_v1_" . time() . "." . $ext;
            move_uploaded_file($file['tmp_name'], $projectFolder . $filename);

            // Log Version 1
            $stmt = $db->prepare("INSERT INTO versions (project_id, filename, version_number, changelog, allow_download) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$projectId, $filename, 1, $changelog, $downloads]);

            $db->commit();
            header("Location: index.php?success=ProjectCreated");
        } catch (Exception $e) {
            $db->rollBack();
            die("Upload Failed: " . $e->getMessage());
        }
    }
	else { 
	echo "File error code: " . $file['error'] . " - "; 
	die('Critical Error'); }
}

// --- Add New Version to Existing Project ---
if ($action === 'new_version') {
    $projectId = (int)$_POST['project_id'];
    $file = $_FILES['audio_file'];

    // Get current version count to increment
    $stmt = $db->prepare("SELECT COUNT(*) FROM versions WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $nextVersion = $stmt->fetchColumn() + 1;

    // Set all other versions to inactive
    $db->prepare("UPDATE versions SET is_active = 0 WHERE project_id = ?")->execute([$projectId]);
	
	// capture 'change log' data
	$changelog = $_POST['changelog'] ?? '';
	
	$downloads = $_POST['downloads'] ?? '0'; // 0=no, 1=yes for downloads enabled
	if ($downloads !== "1") { $downloads == 0; }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = "v" . $nextVersion . "_" . time() . "." . $ext;
    move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $projectId . '/' . $filename);

	$stmt = $db->prepare("INSERT INTO versions (project_id, filename, version_number, changelog, allow_download, is_active) VALUES (?, ?, ?, ?, ?, 1)");
	$stmt->execute([$projectId, $filename, $nextVersion, $changelog, $downloads]);


    header("Location: index.php?success=VersionAdded");
}

// --- Toggle Privacy of a Project---
if ($action === 'toggle_privacy') {
    $projectId = (int)$_POST['project_id'];
    $currentStatus = (int)$_POST['current_status'];
    $newStatus = $currentStatus === 1 ? 0 : 1;

    $stmt = $db->prepare("UPDATE projects SET is_public = ? WHERE id = ?");
    $stmt->execute([$newStatus, $projectId]);
    header("Location: index.php");
}

// --- Update Project & Versions ---
if ($action === 'update_project') {
    $projectId = (int)$_POST['project_id'];
    $title = $_POST['title'];
    $notes = $_POST['notes'];
	$artistname = $_POST['artistname'];

    $db->beginTransaction();
    try {
        // Update main project info
        $stmt = $db->prepare("UPDATE projects SET title = ?, notes = ?, artistname = ? WHERE id = ?");
        $stmt->execute([$title, $notes, $artistname, $projectId]);

        // Update version changelogs (if any)
        if (isset($_POST['versions'])) {
            foreach ($_POST['versions'] as $versionId => $changelog) {
                $stmt = $db->prepare("UPDATE versions SET changelog = ? WHERE id = ?");
                $stmt->execute([$changelog, (int)$versionId]);
            }
        }

        $db->commit();
        header("Location: edit.php?id=$projectId&success=1");
    } catch (Exception $e) {
        $db->rollBack();
        die("Update Failed: " . $e->getMessage());
    }
}

// --- Delete Project & Files ---
if ($action === 'delete_project') {
    $projectId = (int)$_POST['project_id'];

    // 1. Get project info to find the folder
    $projectFolder = UPLOAD_DIR . $projectId;

    $db->beginTransaction();
    try {
        // 2. Remove from DB (Cascade will handle versions and comments)
        $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);

        // 3. Delete physical files
        if (is_dir($projectFolder)) {
            $files = glob($projectFolder . '/*'); 
            foreach($files as $file){ if(is_file($file)) unlink($file); }
            rmdir($projectFolder);
        }

        $db->commit();
        header("Location: index.php?success=Deleted");
    } catch (Exception $e) {
        $db->rollBack();
        die("Delete Failed: " . $e->getMessage());
    }
}

// --- Delete specific version of a track inside a project ---
if ($action === 'delete_version') {
    $versionId = (int)$_POST['version_id'];
    $projectId = (int)$_POST['project_id'];

    // 1. Get the filename to unlink the physical file
    $stmt = $db->prepare("SELECT filename FROM versions WHERE id = ?");
    $stmt->execute([$versionId]);
    $version = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($version) {
        $filePath = UPLOAD_DIR . $projectId . '/' . $version['filename'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // 2. Delete from DB
        $stmt = $db->prepare("DELETE FROM versions WHERE id = ?");
        $stmt->execute([$versionId]);
    }

    header("Location: edit.php?id=$projectId&success=VersionDeleted");
}

// --- Admins can mark a status as Resolved/Declined to indicate whether or not its worthwhile feedback.
if ($action === 'set_comment_status') {
    $commentId = (int)$_POST['comment_id'];
    $status = $_POST['status'];

    $stmt = $db->prepare("UPDATE comments SET status = ? WHERE id = ?");
    $result = $stmt->execute([$status, $commentId]);

    // If it's an AJAX request, just send a 200 OK and exit
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' || isset($_POST['action'])) {
        http_response_code(200);
        echo "Success";
        exit;
    }

    // Fallback for non-JS users
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// --- Admins can also straight up delete a comment, for example if something is inappropriate
if ($action === 'delete_comment') {
    // Security: Double check admin status again here!
    if (!isAdmin()) exit("Unauthorized");

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
    $status = (int)$_POST['status'];

    $stmt = $db->prepare("UPDATE versions SET allow_download = ? WHERE id = ?");
    $stmt->execute([$status, $versionId]);

    http_response_code(200);
    echo "Status Updated";
    exit;
}

