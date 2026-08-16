<?php
// Google API Configuration
// Load credentials from environment variables or an untracked local file.
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID'));
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET'));
}

if (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET)) {
    die('Google API credentials are not configured. Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in environment or create config.local.php.');
}

define('GOOGLE_REDIRECT_URI', (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/google_callback');
?>
