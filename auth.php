<?php
session_start();

// Security: Session Timeout
$timeout_duration = 1800; // 30 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login?msg=session_expired");
    exit();
}
$_SESSION['last_activity'] = time();

// Security: CSRF Protection Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login');
        exit;
    }
    
    // Part 25: PIN Lock Check
    if (isset($_SESSION['has_pin']) && $_SESSION['has_pin'] === true) {
        if (!isset($_SESSION['app_unlocked']) || $_SESSION['app_unlocked'] !== true) {
            // Exceptions for lock.php itself and setting/unsetting PIN
            $currentFile = basename($_SERVER['PHP_SELF']);
            if ($currentFile !== 'lock.php' && $currentFile !== 'logout.php') {
                header('Location: lock');
                exit;
            }
        }
    }
}

// Security: CSRF Verification Guard
function checkCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            die("Security Error: Invalid CSRF token. Please refresh the page and try again.");
        }
    }
}
?>
