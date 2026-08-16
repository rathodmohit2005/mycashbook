<?php
require_once 'db.php';
require_once 'auth.php';
require_once 'config.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$code = $_GET['code'] ?? '';

if (empty($code)) {
    die("Authorization failed.");
}

// Exchange code for tokens
$ch = curl_init("https://oauth2.googleapis.com/token");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'code' => $code,
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'grant_type' => 'authorization_code'
]));

$response = curl_exec($ch);
$data = json_decode($response, true);
curl_close($ch);

if (isset($data['access_token'])) {
    $access_token = $data['access_token'];
    $refresh_token = $data['refresh_token'] ?? null;

    // Store in database
    // Check if entry exists in backup_settings
    $stmt = $conn->prepare("SELECT id FROM backup_settings WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE backup_settings SET google_access_token = ?, google_refresh_token = ? WHERE user_id = ?");
        $stmt->bind_param("ssi", $access_token, $refresh_token, $user_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO backup_settings (google_access_token, google_refresh_token, user_id) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $access_token, $refresh_token, $user_id);
    }
    $stmt->execute();

    header('Location: settings?msg=gdrive_connected');
    exit();
} else {
    echo "Error exchanging code: " . ($data['error_description'] ?? 'Unknown error');
}
?>
