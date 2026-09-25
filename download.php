<?php
require_once('config.php');
require_once('assets/tracks.php');

// 1. Validate input
$versionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$projectSlug = (string)($_GET['s'] ?? '');   // from a track page
$playlistSlug = (string)($_GET['p'] ?? '');  // from a playlist page

if ($versionId <= 0) {
    die("Invalid file request.");
}

// 2. Query the database to check if download is allowed.
// A matching track or playlist slug is required too - otherwise anyone could walk through ?id=1,2,3... and grab private tracks.
$version = false;
if (resolveVersionAccess($db, $versionId, $projectSlug, $playlistSlug)) {
    $stmt = $db->prepare("SELECT filename, origfilename, project_id FROM versions WHERE id = ? AND allow_download = 1");
    $stmt->execute([$versionId]);
    $version = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 3. If file exists and is allowed, serve it
if ($version) {
    $filePath = UPLOAD_DIR . $version['project_id'] . '/' . basename($version['filename']);
    if (is_file($filePath)) {
        // Clear any previous output to ensure a clean download
        if (ob_get_level()) ob_end_clean();

        // hand the file over with its original name (stripped of anything that could break the header)
        $downloadName = preg_replace('/[^\w\-. ()]/u', '_', $version['origfilename'] ?: basename($filePath));

        // Set headers for secure file transfer
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        // Read and output the file
        readfile($filePath);
        exit;
    } else {
        die("File not found on server.");
    }
} else {
    // 4. Deny access if not found or not allowed
    http_response_code(404);
    die("Downloads are not enabled for this version or access is denied.");
}
?>