<?php
require_once 'db.php';
require_once 'auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reminder_time'])) {
    checkCSRF();
    $time = $_POST['reminder_time'];
    $stmt = $conn->prepare("UPDATE users SET reminder_time = ? WHERE id = ?");
    $stmt->bind_param("si", $time, $user_id);
    if ($stmt->execute()) {
        $message = "Settings updated successfully!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['default_currency'])) {
    checkCSRF();
    $new_currency = $_POST['default_currency'];
    
    // Get old currency first
    $stmt = $conn->prepare("SELECT default_currency FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $old_currency = $stmt->get_result()->fetch_assoc()['default_currency'] ?? 'INR';
    
    if ($old_currency !== $new_currency) {
        $rate = 0; // Initialize as 0 to detect failure
        // Fetch real exchange rate from personal API (Quota: 1,500/mo)
        $apiKey = '4c455fdf1ac5eda93ad4c811';
        $url = "https://v6.exchangerate-api.com/v6/$apiKey/latest/" . urlencode($old_currency);
        $context = stream_context_create([
            'http' => ['timeout' => 5],
            'ssl'  => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ]
        ]);
        $response = @file_get_contents($url, false, $context);
        
        if ($response) {
            $data = json_decode($response, true);
            // v6 Standard API uses 'conversion_rates', v6 Open API uses 'rates'
            $rates = $data['conversion_rates'] ?? $data['rates'] ?? null;
            
            if ($rates && isset($rates[$new_currency])) {
                $rate = (float)$rates[$new_currency];
            } else {
                $error_detail = $data['error-type'] ?? 'Unknown error in API response';
                $message = "API Error: $error_detail";
            }
        }
        
        if ($rate > 0) {
            // Update user's default currency
            $stmt = $conn->prepare("UPDATE users SET default_currency = ? WHERE id = ?");
            $stmt->bind_param("si", $new_currency, $user_id);
            $stmt->execute();
            
            // Convert transactions
            $stmt_tx = $conn->prepare("UPDATE transactions SET amount = amount * ?, currency = ? WHERE user_id = ? AND currency = ?");
            $stmt_tx->bind_param("dsis", $rate, $new_currency, $user_id, $old_currency);
            $stmt_tx->execute();
            
            // Convert recurring templates
            $stmt_rc = $conn->prepare("UPDATE recurring_templates SET amount = amount * ?, currency = ? WHERE user_id = ? AND currency = ?");
            $stmt_rc->bind_param("dsis", $rate, $new_currency, $user_id, $old_currency);
            $stmt_rc->execute();
            
            $message = "Currency changed to $new_currency! Past transactions converted (Rate: 1 $old_currency = " . number_format($rate, 4) . " $new_currency).";
        } else {
            $message = "Failed to fetch live exchange rate. You must be online to change currency.";
        }
    } else {
        $message = "Currency is already set to $new_currency.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['monthly_budget'])) {
    checkCSRF();
    $budget = (float)$_POST['monthly_budget'];
    $stmt = $conn->prepare("UPDATE users SET monthly_budget = ? WHERE id = ?");
    $stmt->bind_param("di", $budget, $user_id);
    if ($stmt->execute()) {
        $message = "Monthly budget updated!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_auto_import_confirm'])) {
    checkCSRF();
    $enabled = isset($_POST['auto_import_confirm']) ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET auto_import_confirm = ? WHERE id = ?");
    $stmt->bind_param("ii", $enabled, $user_id);
    if ($stmt->execute()) {
        $message = "Auto-import confirmation setting updated!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_transaction_detection_settings'])) {
    checkCSRF();
    $phone = trim($_POST['transaction_phone'] ?? '');
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    $autoDetect = isset($_POST['auto_detect_transactions']) ? 1 : 0;
    $confirmBeforeAdd = isset($_POST['auto_import_confirm']) ? 1 : 0;

    $stmt = $conn->prepare("UPDATE users SET phone = ?, auto_detect_transactions = ?, auto_import_confirm = ? WHERE id = ?");
    $stmt->bind_param("siii", $phone, $autoDetect, $confirmBeforeAdd, $user_id);
    if ($stmt->execute()) {
        $message = "Transaction detection settings updated!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    checkCSRF();
    $pin = trim($_POST['pin']);
    // Either a 4-digit numeric string or empty to disable
    if (empty($pin)) {
        $stmt = $conn->prepare("UPDATE users SET pin_code = NULL WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $_SESSION['has_pin'] = false;
        $_SESSION['app_unlocked'] = true;
    } elseif (preg_match('/^\d{4}$/', $pin)) {
        $stmt = $conn->prepare("UPDATE users SET pin_code = ? WHERE id = ?");
        $stmt->bind_param("si", $pin, $user_id);
        $_SESSION['has_pin'] = true;
        $_SESSION['app_unlocked'] = true;
    } else {
        $message = "PIN must be exactly 4 digits.";
    }

    if (isset($stmt) && $stmt->execute()) {
        $message = "Security settings updated!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_email_backup'])) {
    checkCSRF();
    $email_enabled = isset($_POST['email_backup_enabled']) ? 1 : 0;
    
    // Check if entry exists
    $stmt = $conn->prepare("SELECT id FROM backup_settings WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE backup_settings SET email_backup_enabled = ? WHERE user_id = ?");
        $stmt->bind_param("ii", $email_enabled, $user_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO backup_settings (email_backup_enabled, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $email_enabled, $user_id);
    }
    
    if ($stmt->execute()) {
        $message = "Email backup settings updated!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_gdrive_backup'])) {
    checkCSRF();
    $gdrive_enabled = isset($_POST['google_backup_enabled']) ? 1 : 0;
    
    // Check if entry exists
    $stmt = $conn->prepare("SELECT id FROM backup_settings WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE backup_settings SET google_backup_enabled = ? WHERE user_id = ?");
        $stmt->bind_param("ii", $gdrive_enabled, $user_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO backup_settings (google_backup_enabled, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $gdrive_enabled, $user_id);
    }
    
    if ($stmt->execute()) {
        $message = "Google Drive backup settings updated!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup_interval'])) {
    checkCSRF();
    $interval = (int)$_POST['backup_interval'];
    
    // Check if entry exists
    $stmt = $conn->prepare("SELECT id FROM backup_settings WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE backup_settings SET backup_interval = ? WHERE user_id = ?");
        $stmt->bind_param("ii", $interval, $user_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO backup_settings (backup_interval, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $interval, $user_id);
    }
    
    if ($stmt->execute()) {
        $message = "Backup settings updated!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_transactions'])) {
    checkCSRF();
    $year = (int)$_POST['delete_year'];
    $month = (int)$_POST['delete_month'];
    
    if ($year > 0) {
        if ($month === 0) {
            $stmt = $conn->prepare("DELETE FROM transactions WHERE user_id = ? AND YEAR(date) = ?");
            $stmt->bind_param("ii", $user_id, $year);
            $period = "all months of $year";
        } else {
            $stmt = $conn->prepare("DELETE FROM transactions WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?");
            $stmt->bind_param("iii", $user_id, $year, $month);
            $period = date("F", mktime(0, 0, 0, $month, 10)) . " $year";
        }

        if ($stmt->execute()) {
            $deleted = $stmt->affected_rows;
            if ($deleted > 0) {
                $message = "Successfully deleted $deleted transactions for $period.";
            } else {
                $message = "No transactions found for $period.";
            }
        } else {
            $message = "Error deleting transactions.";
        }
    } else {
        $message = "Invalid year selected.";
    }
}

// Get available transaction periods for dynamic dropdowns
$available_periods = [];
$stmt = $conn->prepare("SELECT DISTINCT YEAR(date) as year, MONTH(date) as month FROM transactions WHERE user_id = ? ORDER BY year DESC, month DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $y = $row['year'];
    if (!isset($available_periods[$y])) {
        $available_periods[$y] = [];
    }
    $available_periods[$y][] = [
        'm' => (int)$row['month'],
        'name' => date("F", mktime(0, 0, 0, (int)$row['month'], 10))
    ];
}

// Get current settings
$stmt = $conn->prepare("SELECT reminder_time, default_currency, monthly_budget, pin_code, auto_import_confirm, phone, auto_detect_transactions, name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_settings = $stmt->get_result()->fetch_assoc();
$current_time = $user_settings['reminder_time'] ?? '18:00';
$current_currency = $user_settings['default_currency'] ?? 'INR';
$current_budget = $user_settings['monthly_budget'] ?? 0.00;
$current_pin = $user_settings['pin_code'] ?? '';
$current_auto_import_confirm = $user_settings['auto_import_confirm'] ?? 1;
$current_transaction_phone = $user_settings['phone'] ?? '';
$current_auto_detect_transactions = $user_settings['auto_detect_transactions'] ?? 0;
$current_user_name = $user_settings['name'] ?? 'Cash Book User';

// Get current backup settings
$stmt = $conn->prepare("SELECT backup_interval, email_backup_enabled, google_backup_enabled FROM backup_settings WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$backup_res = $stmt->get_result();
    if ($backup_res->num_rows > 0) {
    $backup_data = $backup_res->fetch_assoc();
    $current_backup_interval = $backup_data['backup_interval'];
    $current_email_backup = $backup_data['email_backup_enabled'];
    $current_gdrive_backup = $backup_data['google_backup_enabled'];
} else {
    $current_backup_interval = 0; // Disabled by default
    $current_email_backup = 0;
    $current_gdrive_backup = 0;
}

$pageTitle = 'Settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Settings - Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/index.css">
    <link rel="manifest" href="manifest">
    <meta name="theme-color" content="#6366f1">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/jspdf.umd.min.js"></script>
    <script src="assets/js/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Currency Section -->
        <div class="section-header">
            <h2 class="section-title"><i class="fa-solid fa-coins"></i> Default Currency</h2>
        </div>
        <div class="stat-card" style="margin-bottom: 2rem;">
            <form action="settings" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label class="form-label">Main currency for display:</label>
                    <select name="default_currency" class="form-control" required>
                        <option value="INR" <?= $current_currency === 'INR' ? 'selected' : '' ?>>INR (₹)</option>
                        <option value="USD" <?= $current_currency === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                        <option value="EUR" <?= $current_currency === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                        <option value="GBP" <?= $current_currency === 'GBP' ? 'selected' : '' ?>>GBP (£)</option>
                        <option value="JPY" <?= $current_currency === 'JPY' ? 'selected' : '' ?>>JPY (¥)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm"><i class="fa-solid fa-check" style="margin-right:0.5rem"></i> Save Currency</button>
            </form>
        </div>

        <!-- App Installation Section (Prominent Placement) -->
        <div class="section-header">
            <h2 class="section-title"><i class="fa-solid fa-mobile-screen-button"></i> App Installation</h2>
        </div>
        <div class="stat-card" id="installSection" style="margin-bottom: 2rem; background: linear-gradient(135deg, rgba(99,102,241,0.05), rgba(129,140,248,0.1)); border: 1px solid var(--primary);">
            <p style="font-size: 0.875rem; color: var(--text-main); font-weight: 500; margin-bottom: 1.25rem;">
                Enjoy a full-screen, native experience with offline access by installing the app on your home screen.
            </p>
            <button id="manualInstallBtn" class="btn"><i class="fa-solid fa-download" style="margin-right: 0.5rem"></i> Install Cash Book</button>
        </div>

        <!-- Transaction Detection Settings -->
        <div class="section-header">
            <h2 class="section-title"><i class="fa-solid fa-magnifying-glass-dollar"></i> Transaction Detection</h2>
        </div>
        <div class="stat-card" style="margin-bottom: 2rem;">
            <form action="settings" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="save_transaction_detection_settings" value="1">

                <div class="form-group">
                    <label class="form-label" for="transaction_phone">Phone Number Used For Transactions</label>
                    <input type="tel" name="transaction_phone" id="transaction_phone" class="form-control" placeholder="Enter mobile number" value="<?= htmlspecialchars($current_transaction_phone) ?>">
                </div>

                <div class="form-group" style="display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
                    <div>
                        <label class="form-label" for="auto_detect_transactions" style="margin-bottom: .3rem; display: block;">Auto Detect Transactions</label>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Automatically convert SMS / notification transaction text into entries.</p>
                    </div>
                    <div class="form-check form-switch" style="margin-left:auto;">
                        <input class="form-check-input" type="checkbox" name="auto_detect_transactions" id="auto_detect_transactions" value="1" style="width: 40px; height: 20px;" <?= $current_auto_detect_transactions ? 'checked' : '' ?> >
                    </div>
                </div>

                <div class="form-group" style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 1rem;">
                    <div>
                        <label class="form-label" for="auto_import_confirm" style="margin-bottom: .3rem; display: block;">Require Confirmation Before Auto Add</label>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">If enabled, the app will ask before saving a detected transaction.</p>
                    </div>
                    <div class="form-check form-switch" style="margin-left:auto;">
                        <input class="form-check-input" type="checkbox" name="auto_import_confirm" id="auto_import_confirm" value="1" style="width: 40px; height: 20px;" <?= $current_auto_import_confirm ? 'checked' : '' ?> >
                    </div>
                </div>

                <button type="submit" class="btn btn-sm" style="margin-top: 1rem;"><i class="fa-solid fa-check" style="margin-right:0.5rem"></i> Save Detection Settings</button>
            </form>
        </div>

        <!-- Budget Section -->
        <div class="section-header">
            <h2 class="section-title"><i class="fa-solid fa-piggy-bank"></i> Monthly Budget</h2>
        </div>
        <div class="stat-card" style="margin-bottom: 2rem;">
            <form action="settings" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label class="form-label">Set your monthly limit (<?= htmlspecialchars($current_currency) ?>):</label>
                    <input type="number" name="monthly_budget" class="form-control" step="0.01" min="0" placeholder="e.g. 2000.00" value="<?= htmlspecialchars($current_budget) ?>" required>
                </div>
                <button type="submit" class="btn btn-sm"><i class="fa-solid fa-check" style="margin-right:0.5rem"></i> Save Budget</button>
            </form>
        </div>

        <!-- Security Section -->
        <div class="section-header">
            <h2 class="section-title"><i class="fa-solid fa-shield-halved"></i> PIN Security</h2>
        </div>
        <div class="stat-card" style="margin-bottom: 2rem;">
            <form action="settings" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label class="form-label">Set 4-Digit PIN (Leave blank to disable):</label>
                    <input type="password" name="pin" class="form-control" maxlength="4" pattern="\d{4}" inputmode="numeric" placeholder="e.g. 1234" value="<?= htmlspecialchars($current_pin) ?>">
                    <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.5rem;">
                        This code will be required when opening the app or after 5 mins of inactivity.
                    </p>
                </div>
                <button type="submit" class="btn btn-sm"><i class="fa-solid fa-lock" style="margin-right:0.5rem"></i> Save Security</button>
            </form>
        </div>

        <?php if (!empty($current_pin)): ?>
            <!-- Part 29: Face & Biometric Section -->
            <div class="section-header">
                <h2 class="section-title"><i class="fa-solid fa-face-smile"></i> Face & Fingerprint Lock</h2>
            </div>
            <div class="stat-card" style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h4 style="margin: 0;">FaceID / Fingerprint</h4>
                    <p style="font-size: 0.75rem; color: var(--text-muted); margin: 0;">Enable high-tech face scan or fingerprint unlock.</p>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="bioToggle" style="width: 40px; height: 20px;" <?= $_SESSION['has_bio'] ? 'checked' : '' ?>>
                </div>
            </div>
        <?php endif; ?>

        <!-- Reminder Section -->
        <div class="section-header">
            <h2 class="section-title"><i class="fa-regular fa-clock"></i> Daily Reminder</h2>
        </div>
        <div class="stat-card" style="margin-bottom: 2rem;">
            <form action="settings" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label class="form-label">Remind me to log expenses at:</label>
                    <input type="time" name="reminder_time" class="form-control" value="<?= htmlspecialchars($current_time) ?>" required>
                </div>
                <!-- Div for app.js parsing -->
                <div id="cfg_reminder" data-time="<?= htmlspecialchars($current_time) ?>" style="display:none;"></div>
                <button type="submit" class="btn btn-sm" id="saveReminderBtn"><i class="fa-solid fa-check" style="margin-right:0.5rem"></i> Save Time</button>
            </form>
            <p id="permStatus" style="font-size: 0.75rem; color: var(--text-muted); margin-top: 1rem;">
                *Requires letting the browser send you notifications.
            </p>
        </div>

        <!-- Backup Section -->
        <div class="section-header">
            <h2 class="section-title"><i class="fa-solid fa-cloud-arrow-down"></i> Auto Backup</h2>
        </div>
        <div class="stat-card" style="margin-bottom: 2rem;">
            <form action="settings" method="POST" style="margin-bottom: 1.5rem;">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label class="form-label">Auto Backup Interval:</label>
                    <select name="backup_interval" id="backupIntervalSelect" class="form-control" required>
                        <option value="0" <?= $current_backup_interval == 0 ? 'selected' : '' ?>>Disabled</option>
                        <option value="5" <?= $current_backup_interval == 5 ? 'selected' : '' ?>>Every 5 Days</option>
                        <option value="7" <?= $current_backup_interval == 7 ? 'selected' : '' ?>>Every 7 Days</option>
                        <option value="15" <?= $current_backup_interval == 15 ? 'selected' : '' ?>>Every 15 Days</option>
                        <option value="30" <?= $current_backup_interval == 30 ? 'selected' : '' ?>>Every 30 Days</option>
                        <option value="90" <?= $current_backup_interval == 90 ? 'selected' : '' ?>>Every 90 Days</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm"><i class="fa-solid fa-check" style="margin-right:0.5rem"></i> Save Interval</button>
            </form>
            <p id="backupHint" style="font-size: 0.7rem; color: var(--text-muted); margin-top: -1rem; margin-bottom: 1rem; <?= $current_backup_interval != 0 ? 'display:none;' : '' ?>">
                *Select an interval first to enable Email or Google Drive backups.
            </p>

            <form action="settings" method="POST" style="margin-bottom: 1.5rem;">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="toggle_email_backup" value="1">
                <div class="stat-card" style="margin: 1rem 0; display: flex; justify-content: space-between; align-items: center; background: rgba(99,102,241,0.03); border: 1px solid var(--border-color);">
                    <div>
                        <h4 style="margin: 0; font-size: 0.9rem;"><i class="fa-solid fa-envelope"></i> Email Backup</h4>
                        <p style="font-size: 0.72rem; color: var(--text-muted); margin: 0;">Receive backup CSV in your email automatically.</p>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input backup-toggle" type="checkbox" name="email_backup_enabled" onchange="this.form.submit()" style="width: 38px; height: 19px;" <?= $current_email_backup ? 'checked' : '' ?> <?= $current_backup_interval == 0 ? 'disabled' : '' ?>>
                    </div>
                </div>
            </form>

            <div class="stat-card" style="margin: 1rem 0; display: flex; justify-content: space-between; align-items: center; background: rgba(99,102,241,0.03); border: 1px solid var(--border-color);">
                <div>
                    <h4 style="margin: 0; font-size: 0.9rem;"><i class="fa-brands fa-google-drive"></i> Google Drive</h4>
                    <p style="font-size: 0.72rem; color: var(--text-muted); margin: 0;">Upload backups to your personal Google Drive.</p>
                </div>
                <div>
                    <?php if (!empty($backup_data['google_access_token'])): ?>
                        <form action="settings" method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="toggle_gdrive_backup" value="1">
                            <div class="form-check form-switch" style="display: inline-block; vertical-align: middle; margin-right: 1rem;">
                                <input class="form-check-input backup-toggle" type="checkbox" name="google_backup_enabled" onchange="this.form.submit()" style="width: 38px; height: 19px;" <?= $current_gdrive_backup ? 'checked' : '' ?> <?= $current_backup_interval == 0 ? 'disabled' : '' ?>>
                            </div>
                        </form>
                        <span style="font-size: 0.75rem; color: var(--success); font-weight: 600;"><i class="fa-solid fa-circle-check"></i> Connected</span>
                    <?php else: ?>
                        <a href="google_auth" class="btn btn-sm" style="background: #4285F4; color: white;"><i class="fa-brands fa-google"></i> Connect</a>
                    <?php endif; ?>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 1.5rem 0;">

            <p style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Manual export and restore options:
            </p>
            <div style="display: flex; gap: 1rem; flex-direction: column;">
                <a href="#" id="mainExportBtn" class="btn"><i class="fa-solid fa-cloud-arrow-down" style="margin-right: 0.5rem"></i> Download Backup</a>
                <a href="backup_history" class="btn btn-secondary"><i class="fa-solid fa-clock-rotate-left" style="margin-right: 0.5rem"></i> View Backup History</a>
                <a href="import" class="btn btn-secondary"><i class="fa-solid fa-cloud-arrow-up" style="margin-right: 0.5rem"></i> Restore Backup</a>
                <a href="help" class="btn btn-secondary"><i class="fa-solid fa-circle-question" style="margin-right: 0.5rem"></i> Help & Terms</a>
            </div>

        </div>

        <!-- Data Management Section -->
        <div class="section-header">
            <h2 class="section-title" style="color: var(--danger);"><i class="fa-solid fa-trash-can"></i> Data Management</h2>
        </div>
        <div class="stat-card" style="margin-bottom: 2rem; border: 1px solid rgba(239, 68, 68, 0.2);">
            <p style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Permanently delete transactions for a specific month and year. This action cannot be undone.
            </p>
            <form action="settings" method="POST" onsubmit="return confirm('Are you absolutely sure? All transactions for the selected period will be permanently deleted.');">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="delete_transactions" value="1">
                
                <?php if (empty($available_periods)): ?>
                    <p style="font-size: 0.875rem; color: var(--text-muted); text-align: center; padding: 1rem;">
                        No transactions found to delete.
                    </p>
                <?php else: ?>
                    <?php 
                    $years = array_keys($available_periods);
                    $firstYear = $years[0];
                    ?>
                    <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Year</label>
                            <select name="delete_year" class="form-control" required onchange="updateDeleteMonths(this.value)">
                                <?php foreach ($years as $y): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Month</label>
                            <select name="delete_month" class="form-control" required>
                                <option value="0">All Months</option>
                                <?php foreach ($available_periods[$firstYear] as $item): ?>
                                    <option value="<?= $item['m'] ?>"><?= $item['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn" style="background: var(--danger); color: white;">
                        <i class="fa-solid fa-trash-can" style="margin-right: 0.5rem"></i> Delete Transactions
                    </button>
                <?php endif; ?>
            </form>
        </div>

    </div>

    <?php require_once 'nav.php'; ?>

    <div id="backupFormatModal" class="modal" onclick="closeBackupModal(event)" role="dialog" aria-modal="true" aria-labelledby="backupFormatModalTitle">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="backupFormatModalTitle">Choose Backup Format</h3>
                <button type="button" class="btn-icon" onclick="closeBackupModal()" aria-label="Close backup modal"><i class="fa-solid fa-times"></i></button>
            </div>
            <p style="font-size:0.9rem; color:var(--text-muted); margin:0 0 1.25rem;">
                Select how you want to download your transaction backup.
            </p>
            <div style="display:grid; gap:1rem;">
                <button id="downloadPdfBackupBtn" class="btn btn-primary" type="button">
                    <i class="fa-solid fa-file-pdf" style="margin-right:0.5rem"></i> Download PDF
                </button>
                <button id="downloadExcelBackupBtn" class="btn btn-secondary" type="button">
                    <i class="fa-solid fa-file-excel" style="margin-right:0.5rem"></i> Download Excel
                </button>
            </div>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
    <script>
        // Part 28: Biometric Registration
        const bioToggle = document.getElementById('bioToggle');
        if (bioToggle) {
            bioToggle.addEventListener('change', async (e) => {
                const enabled = e.target.checked;
                
                if (enabled) {
                    // Check if browser supports WebAuthn
                    if (!window.PublicKeyCredential) {
                        alert("Biometrics are not supported by this browser.");
                        e.target.checked = false;
                        return;
                    }

                    // Simple "Registration" challenge to trigger the system prompt
                    try {
                        const challenge = new Uint8Array(32);
                        window.crypto.getRandomValues(challenge);
                        
                        await navigator.credentials.create({
                            publicKey: {
                                challenge: challenge,
                                rp: { name: "Cash Book" },
                                user: {
                                    id: new Uint8Array(16),
                                    name: "user",
                                    displayName: "User"
                                },
                                pubKeyCredParams: [{ alg: -7, type: "public-key" }],
                                authenticatorSelection: { authenticatorAttachment: "platform" },
                                timeout: 60000
                            }
                        });
                        // If we get here, the user successfully authenticated/registered
                    } catch (err) {
                        console.error(err);
                        alert("Biometric setup canceled or failed.");
                        e.target.checked = false;
                        return;
                    }
                }

                // Sync with server
                const fd = new FormData();
                fd.append('enabled', enabled ? 1 : 0);
                await fetch('api?action=toggle_biometric', { method: 'POST', body: fd });
            });
        }

        // Transaction deletion dynamic dropdowns
        const availablePeriods = <?= json_encode($available_periods) ?>;
        function updateDeleteMonths(year) {
            const monthSelect = document.querySelector('select[name="delete_month"]');
            if (!monthSelect) return;
            
            // Clear existing options except "All Months"
            monthSelect.innerHTML = '<option value="0">All Months</option>';
            
            if (year && availablePeriods[year]) {
                availablePeriods[year].forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item.m;
                    opt.textContent = item.name;
                    monthSelect.appendChild(opt);
                });
            }
        }

        // Dynamic Backup Toggle logic
        const intervalSelect = document.getElementById('backupIntervalSelect');
        const backupToggles = document.querySelectorAll('.backup-toggle');
        const backupHint = document.getElementById('backupHint');

        if (intervalSelect) {
            intervalSelect.addEventListener('change', () => {
                const isDisabled = intervalSelect.value === "0";
                backupToggles.forEach(toggle => {
                    if (isDisabled) {
                        toggle.disabled = true;
                        toggle.checked = false;
                    } else {
                        // We don't enable them here because the user must save the interval first 
                        // to ensure the backend logic is synced. 
                        // Actually, for better UX, let's keep them disabled until they SAVE.
                    }
                });
                backupHint.style.display = isDisabled ? 'block' : 'none';
            });
        }

        // --- Offline Export Logic ---
        const mainExportBtn = document.getElementById('mainExportBtn');

        function handleBackupClick(e) {
            e.preventDefault();
            if (!navigator.onLine) {
                const queue = JSON.parse(localStorage.getItem('offline_transactions') || '[]');
                if (queue.length === 0) {
                    alert("The server backup is unavailable while offline. No unsynced transactions found to export.");
                    return;
                }

                if (confirm("The server backup is offline. Would you like to export your unsynced transactions as a CSV backup?")) {
                    const cleanData = queue.map(t => ({
                        'Transaction Type': t.type,
                        'Amount': t.amount,
                        'Currency': t.currency,
                        'Date': formatBackupDate(t.date),
                        'Note': t.note || '',
                        'Category': t.category || 'General',
                        'Account Name': 'Offline Account'
                    }));
                    downloadCSV(cleanData, 'offline_backup_' + new Date().toISOString().split('T')[0] + '.csv');
                }
                return;
            }

            openBackupModal(e);
        }

        if (mainExportBtn) {
            mainExportBtn.addEventListener('click', handleBackupClick);
        }

        window.addEventListener('online', () => {
            if (mainExportBtn) {
                mainExportBtn.classList.remove('btn-secondary');
            }
        });
        window.addEventListener('offline', () => {
            if (mainExportBtn) {
                mainExportBtn.classList.add('btn-secondary');
            }
        });

        if (!navigator.onLine && mainExportBtn) {
            mainExportBtn.classList.add('btn-secondary');
        }

        function closeBackupModal(event) {
            const modal = document.getElementById('backupFormatModal');
            if (!modal) return;
            if (!event || event.target === modal) {
                modal.classList.remove('active');
                document.body.classList.remove('modal-open');
                resetModalButtons();
            }
        }

        function isValidBackupDate(value) {
            const date = new Date(value);
            return date instanceof Date && !isNaN(date);
        }

        function formatBackupDate(value) {
            if (!value) return '';
            const date = new Date(value);
            if (!isValidBackupDate(date)) return String(value);
            return date.toLocaleString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
        }

        function openBackupModal(event) {
            if (!navigator.onLine) {
                return;
            }
            if (event) event.preventDefault();
            const modal = document.getElementById('backupFormatModal');
            if (!modal) return;
            resetModalButtons();
            modal.classList.add('active');
            document.body.classList.add('modal-open');
        }

        function resetModalButtons() {
            const pdfBtn = document.getElementById('downloadPdfBackupBtn');
            const excelBtn = document.getElementById('downloadExcelBackupBtn');
            if (pdfBtn) {
                pdfBtn.disabled = false;
                pdfBtn.innerHTML = '<i class="fa-solid fa-file-pdf" style="margin-right:0.5rem"></i> Download PDF';
            }
            if (excelBtn) {
                excelBtn.disabled = false;
                excelBtn.innerHTML = '<i class="fa-solid fa-file-excel" style="margin-right:0.5rem"></i> Download Excel';
            }
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeBackupModal();
            }
        });

        function downloadCSV(data, filename) {
            if (!data || !data.length) {
                alert('No records available to export.');
                return;
            }

            const csvRows = [];
            const headers = Object.keys(data[0]);
            csvRows.push(headers.map(header => '"' + String(header).replace(/"/g, '""') + '"').join(','));
            data.forEach(row => {
                const values = headers.map(header => {
                    const escaped = String(row[header] ?? '').replace(/"/g, '""');
                    return '"' + escaped + '"';
                });
                csvRows.push(values.join(','));
            });
            const csvContent = '\uFEFF' + csvRows.join('\r\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        async function generateBackupPDF() {
            const modal = document.getElementById('backupFormatModal');
            const pdfBtn = document.getElementById('downloadPdfBackupBtn');
            
            if (pdfBtn) {
                pdfBtn.disabled = true;
                pdfBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="margin-right:0.5rem"></i> Generating PDF...';
            }
            
            if (modal) {
                modal.classList.remove('active');
            }
            document.body.classList.remove('modal-open');
            const { jsPDF } = window.jspdf || {};
            if (!jsPDF) {
                alert('Unable to generate PDF. Please refresh the page.');
                return;
            }

            // Verify the local Unicode font asset exists before generating PDF.
            async function verifyUnicodeFontAsset() {
                const fontUrl = 'assets/fonts/NotoSans-Regular.ttf';
                try {
                    const resp = await fetch(fontUrl, { method: 'HEAD' });
                    if (!resp.ok) {
                        console.warn('Unicode font asset not reachable:', resp.status, fontUrl);
                        return false;
                    }
                    console.log('Unicode font asset is available for PDF export:', fontUrl);
                    return true;
                } catch (error) {
                    console.warn('Unicode font verification failed:', error);
                    return false;
                }
            }

            // Helper to register a Unicode font (Noto Sans) into jsPDF VFS so ₹ and other unicode render correctly
            async function ensureUnicodeFont(doc) {
                if (doc.__noto_registered) return;
                try {
                    const fontUrl = 'assets/fonts/NotoSans-Regular.ttf';
                    const resp = await fetch(fontUrl);
                    if (!resp.ok) throw new Error('Font fetch failed');
                    const buffer = await resp.arrayBuffer();
                    const bytes = new Uint8Array(buffer);
                    let binary = '';
                    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
                    const base64 = btoa(binary);
                    doc.addFileToVFS('NotoSans-Regular.ttf', base64);
                    doc.addFont('NotoSans-Regular.ttf', 'NotoSans', 'normal');
                    doc.__noto_registered = true;
                } catch (e) {
                    console.warn('Could not load unicode font for PDF, falling back to default:', e);
                }
            }

            try {
                const response = await fetch('api?action=get_transactions', {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' }
                });

                if (!response.ok) {
                    throw new Error('Transaction API request failed with status ' + response.status + ' ' + response.statusText);
                }

                let json;
                try {
                    json = await response.json();
                } catch (parseError) {
                    throw new Error('Unable to parse transactions response as JSON.');
                }

                if (!json || json.status !== 'success') {
                    throw new Error(json?.message || 'Unexpected response from transaction API.');
                }

                if (!Array.isArray(json.data) || json.data.length === 0) {
                    alert('No transactions available for backup.');
                    return;
                }

                const doc = new jsPDF({ unit: 'pt', format: 'a4' });
                await verifyUnicodeFontAsset();
                await ensureUnicodeFont(doc);
                try { doc.setFont('NotoSans', 'normal'); } catch (e) { /* ignore if font not registered */ }

                const pageWidth = doc.internal.pageSize.getWidth();
                const pageHeight = doc.internal.pageSize.getHeight();
                const currentUserName = <?= json_encode($current_user_name ?? 'Cash Book User') ?>;
                const margin = 40;
                const headerY = 40;
                const summaryY = 90;
                const rowHeight = 16;

                const genDate = new Date();
                const genDateStr = genDate.toLocaleString('en-GB', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                });

                const currencySymbols = {
                    INR: '₹',
                    USD: '$',
                    EUR: '€',
                    GBP: '£'
                };

                const formatAmount = (value, currency) => {
                    const amount = Number(value) || 0;
                    const symbol = currencySymbols[currency] || currency || '';
                    return symbol + amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                };

                const formatDate = (value) => {
                    const parsed = new Date(value);
                    if (!(parsed instanceof Date) || isNaN(parsed)) {
                        return String(value || '');
                    }
                    return parsed.toLocaleString('en-GB', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: true
                    });
                };

                const formatMonth = (value) => {
                    const parsed = new Date(value);
                    if (!(parsed instanceof Date) || isNaN(parsed)) {
                        return '';
                    }
                    return parsed.toLocaleString('en-GB', { month: 'short', year: 'numeric' });
                };

                let totalIn = 0;
                let totalOut = 0;
                let runningBalance = 0;
                const transactionDates = [];
                const rows = json.data.map(tx => {
                    const dateValue = tx.date || tx.date_time || tx.formattedDate;
                    const parsedDate = new Date(dateValue);
                    if (parsedDate instanceof Date && !isNaN(parsedDate)) {
                        transactionDates.push(parsedDate);
                    }

                    const amountValue = Number(tx.amount_raw ?? tx.amount ?? 0);
                    const typeLabel = String(tx.type || '').toLowerCase();
                    const isIncome = ['cash in', 'income', 'deposit', 'credit'].includes(typeLabel);

                    if (isIncome) {
                        totalIn += amountValue;
                        runningBalance += amountValue;
                    } else {
                        totalOut += amountValue;
                        runningBalance -= amountValue;
                    }

                    return {
                        date: formatDate(dateValue),
                        month: formatMonth(dateValue),
                        note: String(tx.note || 'Transaction'),
                        category: String(tx.category || 'General'),
                        type: isIncome ? 'Income' : 'Expense',
                        amount: formatAmount(amountValue, tx.currency || ''),
                        balance: formatAmount(runningBalance, tx.currency || ''),
                        isIncome,
                        runningBalance
                    };
                });

                const periodLabel = (() => {
                    if (!transactionDates.length) return 'All Transactions';
                    const sorted = transactionDates.sort((a, b) => a - b);
                    const first = sorted[0];
                    const last = sorted[sorted.length - 1];
                    const firstLabel = first.toLocaleString('en-GB', { month: 'short', year: 'numeric' });
                    const lastLabel = last.toLocaleString('en-GB', { month: 'short', year: 'numeric' });
                    return firstLabel === lastLabel ? firstLabel : `${firstLabel} - ${lastLabel}`;
                })();

                const balanceColor = runningBalance < 0 ? [200, 0, 0] : [0, 120, 0];
                const summaryWidth = (pageWidth - margin * 2 - 20) / 3;

                doc.setFontSize(24);
                doc.setTextColor(20);
                const titleText = currentUserName ? `Cash Book Backup for ${currentUserName.toUpperCase()}` : 'Cash Book Backup';
                doc.text(titleText, margin, headerY);

                doc.setFontSize(10);
                doc.setTextColor(110);
                doc.text('Generated Date: ' + genDateStr, margin, headerY + 24);
                doc.text('Statement Period: ' + periodLabel, margin, headerY + 40);

                const cardHeight = 54;
                const cardRadius = 10;
                const cardSpacing = 10;
                const cardY = summaryY;

                doc.setDrawColor(220);
                doc.setLineWidth(0.5);

                doc.setFillColor(220, 245, 220);
                doc.roundedRect(margin, cardY, summaryWidth, cardHeight, cardRadius, cardRadius, 'F');
                doc.setFillColor(255, 230, 230);
                doc.roundedRect(margin + summaryWidth + cardSpacing, cardY, summaryWidth, cardHeight, cardRadius, cardRadius, 'F');
                doc.setFillColor(230, 235, 255);
                doc.roundedRect(margin + (summaryWidth + cardSpacing) * 2, cardY, summaryWidth, cardHeight, cardRadius, cardRadius, 'F');

                doc.setFontSize(9);
                doc.setTextColor(60);
                doc.text('Total Income', margin + 12, cardY + 18);
                doc.setFontSize(14);
                doc.setTextColor(0, 120, 0);
                doc.text(formatAmount(totalIn, json.data[0]?.currency || ''), margin + 12, cardY + 36);

                doc.setFontSize(9);
                doc.setTextColor(60);
                doc.text('Total Expense', margin + summaryWidth + cardSpacing + 12, cardY + 18);
                doc.setFontSize(14);
                doc.setTextColor(180, 0, 0);
                doc.text(formatAmount(totalOut, json.data[0]?.currency || ''), margin + summaryWidth + cardSpacing + 12, cardY + 36);

                doc.setFontSize(9);
                doc.setTextColor(60);
                doc.text('Current Balance', margin + (summaryWidth + cardSpacing) * 2 + 12, cardY + 18);
                doc.setFontSize(14);
                doc.setTextColor(balanceColor[0], balanceColor[1], balanceColor[2]);
                doc.text(formatAmount(runningBalance, json.data[0]?.currency || ''), margin + (summaryWidth + cardSpacing) * 2 + 12, cardY + 36);

                const tableBody = rows.map(tx => [
                    tx.date,
                    tx.month,
                    tx.note,
                    tx.category,
                    tx.type,
                    tx.amount,
                    tx.balance
                ]);

                doc.autoTable({
                    theme: 'grid',
                    startY: cardY + cardHeight + 24,
                    margin: { left: margin, right: margin, top: 0, bottom: 20 },
                    tableWidth: pageWidth - margin * 2,
                    head: [['Date', 'Month', 'Note', 'Category', 'Type', 'Amount', 'Running Balance']],
                    body: tableBody,
                    headStyles: {
                        fillColor: [135, 206, 250],
                        textColor: 0,
                        fontStyle: 'bold',
                        halign: 'center',
                        valign: 'middle',
                        lineColor: [130, 130, 130],
                        lineWidth: 0.4
                    },
                    alternateRowStyles: { fillColor: [255, 255, 255] },
                    styles: {
                        font: doc.__noto_registered ? 'NotoSans' : 'helvetica',
                        fontSize: 9,
                        cellPadding: 6,
                        overflow: 'linebreak',
                        valign: 'middle',
                        minCellHeight: 18,
                        cellWidth: 'wrap',
                        lineColor: [0, 0, 0],
                        lineWidth: 0.3
                    },
                    tableLineColor: [0, 0, 0],
                    tableLineWidth: 0.5,
                    columnStyles: {
                        0: { cellWidth: 60 },
                        1: { cellWidth: 55 },
                        2: { cellWidth: 130 },
                        3: { cellWidth: 75 },
                        4: { cellWidth: 60, halign: 'center' },
                        5: { cellWidth: 65, halign: 'right' },
                        6: { cellWidth: 60, halign: 'right' }
                    },
                    didParseCell: (data) => {
                        if (data.section === 'body') {
                            const row = rows[data.row.index] || {};
                            const isIncomeCell = row.isIncome === true;
                            const runningBalanceValue = Number(row.runningBalance) || 0;

                            if (data.column.index === 4) {
                                data.cell.styles.fontStyle = 'bold';
                                data.cell.styles.textColor = isIncomeCell ? [0, 120, 0] : [180, 0, 0];
                            }
                            if (data.column.index === 5) {
                                data.cell.styles.textColor = isIncomeCell ? [0, 120, 0] : [180, 0, 0];
                            }
                            if (data.column.index === 6) {
                                data.cell.styles.textColor = runningBalanceValue < 0 ? [180, 0, 0] : [0, 120, 0];
                            }
                        }
                    },
                    didDrawPage: (data) => {
                        const pageCount = doc.internal.getNumberOfPages();
                        const pageNumber = data.pageNumber || 1;
                        doc.setFontSize(9);
                        doc.setTextColor(130);
                        doc.text(`Page ${pageNumber} of ${pageCount}`, pageWidth / 2, pageHeight - 20, { align: 'center' });
                    }
                });

                doc.save('cashbook_backup_' + new Date().toISOString().slice(0, 10) + '.pdf');
            } catch (err) {
                console.error('PDF export failed:', err);
                alert('Failed to generate PDF backup. ' + (err?.message || 'See console for details.'));
            } finally {
                if (pdfBtn) {
                    pdfBtn.disabled = false;
                    pdfBtn.innerHTML = '<i class="fa-solid fa-file-pdf" style="margin-right:0.5rem"></i> Download PDF';
                }
            }
        }

        const downloadPdfBackupBtn = document.getElementById('downloadPdfBackupBtn');
        const downloadExcelBackupBtn = document.getElementById('downloadExcelBackupBtn');

        if (downloadPdfBackupBtn) {
            downloadPdfBackupBtn.addEventListener('click', generateBackupPDF);
        }
        if (downloadExcelBackupBtn) {
            downloadExcelBackupBtn.addEventListener('click', () => {
                const modal = document.getElementById('backupFormatModal');
                const excelBtn = document.getElementById('downloadExcelBackupBtn');
                
                if (excelBtn) {
                    excelBtn.disabled = true;
                    excelBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="margin-right:0.5rem"></i> Downloading Excel...';
                }
                
                if (modal) {
                    modal.classList.remove('active');
                }
                document.body.classList.remove('modal-open');
                
                // Add timestamp to prevent caching
                const timestamp = new Date().getTime();
                window.location.href = 'export.php?format=xlsx&t=' + timestamp;
                
                // Reset button after short delay
                setTimeout(() => {
                    if (excelBtn) {
                        excelBtn.disabled = false;
                        excelBtn.innerHTML = '<i class="fa-solid fa-file-excel" style="margin-right:0.5rem"></i> Download Excel';
                    }
                }, 1000);
            });
        }
    </script>
</body>
</html>
