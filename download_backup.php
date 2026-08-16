<?php
require_once 'db.php';
require_once 'auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$file = $_GET['file'] ?? '';

if (empty($file)) {
    die("File not specified.");
}

// Security: Prevent directory traversal and ensure the file belongs to the user
// File name format is backup_userid_date.csv
if (!preg_match('/^backup_' . $user_id . '_[\d-]+\.csv$/', $file)) {
    die("Access denied or invalid file format.");
}

$filepath = __DIR__ . '/backups/' . $file;

if (file_exists($filepath)) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit();
} else {
    die("File not found.");
}
?>
