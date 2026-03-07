<?php
require_once('config.php');

// 1. Validate input
$versionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($versionId <= 0) {
    die("Invalid file request.");
}

// 2. Query the database to check if download is allowed
$stmt = $db->prepare("SELECT filename, project_id FROM versions WHERE id = ? AND allow_download = 1");
$stmt->execute([$versionId]);
$version = $stmt->fetch(PDO::FETCH_ASSOC);

// 3. If file exists and is allowed, serve it
if ($version) {
    $filePath = UPLOAD_DIR . $version['project_id'] . '/' . $version['filename'];
//die("Looking for file at: " . $filePath);
    if (file_exists($filePath)) {
        // Clear any previous output to ensure a clean download
        if (ob_get_level()) ob_end_clean();

        // Set headers for secure file transfer
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
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
    die("Downloads are not enabled for this version or access is denied.");
}
?>