<?php
require_once 'db.php';
require_once 'auth.php';
require_once 'config.php';
requireLogin();

$auth_url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/drive.file',
    'access_type' => 'offline',
    'prompt' => 'consent'
]);

header('Location: ' . $auth_url);
exit();
?>
