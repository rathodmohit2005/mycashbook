<?php
date_default_timezone_set('Asia/Kolkata');

// ============================================================
//  DATABASE CONFIGURATION
//  Auto-detects LOCAL (WAMP) vs LIVE (InfinityFree hosting)
// ============================================================

$http_host = $_SERVER['HTTP_HOST'] ?? '';
$host_only = explode(':', $http_host)[0];

$isLocal = in_array($host_only, ['localhost', '127.0.0.1'])
        || str_starts_with($host_only, '192.168.')
        || str_starts_with($host_only, '10.');

// Auto-detect base path for assets
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_dir = str_replace(basename($script_name), '', $script_name);
define('BASE_URL', $base_dir);
define('ASSETS_URL', $base_dir . 'assets/');

if ($isLocal) {
    // ---- LOCAL / WAMP settings ----
    $host   = 'localhost';
    $user   = 'root';
    $pass   = '';           // Default WAMP MySQL password is empty
    $dbName = 'cashbook';
} else {
    // ---- LIVE HOSTING settings (InfinityFree / mycashbook.free.nf) ----
    // ⚠️  Replace these values with your actual hosting credentials from vPanel
    $host   = 'sql104.infinityfree.com';  // Your MySQL hostname from vPanel
    $user   = 'if0_41751901';             // Your MySQL username from vPanel
    $pass   = 'rXPgHuJlbVlWVPs';         // Your MySQL password from vPanel
    $dbName = 'if0_41751901_cashbook';    // Your database name from vPanel
}

// ============================================================
//  CONNECT
// ============================================================
$conn = new mysqli($host, $user, $pass);

if ($conn->connect_error) {
    die("Database connection failed. Please check your credentials.");
}

// On live hosting the DB must already exist (hosting doesn't allow CREATE DATABASE)
if ($isLocal) {
    $conn->query("CREATE DATABASE IF NOT EXISTS `$dbName`");
}

if (!$conn->select_db($dbName)) {
    die("Cannot select database '$dbName'. Make sure it exists on your hosting panel.");
}

// UTF-8 support
$conn->set_charset('utf8mb4');

// ============================================================
//  TABLE INITIALIZATION
// ============================================================
function initDB($conn) {
    global $dbName;

    // ---- Users table ----
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        name               VARCHAR(100)  NOT NULL,
        email              VARCHAR(150)  UNIQUE NOT NULL,
        password           VARCHAR(255)  NOT NULL,
        phone              VARCHAR(15)   NULL,
        pin_code           VARCHAR(6)    NULL,
        biometric_enabled  TINYINT(1)    DEFAULT 0,
        biometric_cred_id  TEXT          NULL,
        reminder_time      VARCHAR(5)    DEFAULT '18:00',
        default_currency   VARCHAR(10)   DEFAULT 'INR',
        monthly_budget     DECIMAL(10,2) DEFAULT 0.00,
        auto_import_confirm TINYINT(1)   DEFAULT 1,
        auto_detect_transactions TINYINT(1) DEFAULT 0,
        reset_token        VARCHAR(64)   NULL,
        reset_expires      DATETIME      NULL,
        created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
    )");

    // ---- Safe migrations (adds missing columns without breaking existing data) ----
    addColIfMissing($conn, $dbName, 'users', 'phone',         'VARCHAR(15) NULL');
    addColIfMissing($conn, $dbName, 'users', 'pin_code',      'VARCHAR(6) NULL');
    addColIfMissing($conn, $dbName, 'users', 'reset_token',   'VARCHAR(64) NULL');
    addColIfMissing($conn, $dbName, 'users', 'reset_expires', 'DATETIME NULL');
    addColIfMissing($conn, $dbName, 'users', 'monthly_budget','DECIMAL(10,2) DEFAULT 0.00');
    addColIfMissing($conn, $dbName, 'users', 'default_currency','VARCHAR(10) DEFAULT \'INR\'');
    addColIfMissing($conn, $dbName, 'users', 'reminder_time', 'VARCHAR(5) DEFAULT \'18:00\'');
    addColIfMissing($conn, $dbName, 'users', 'biometric_enabled','TINYINT(1) DEFAULT 0');
    addColIfMissing($conn, $dbName, 'users', 'biometric_cred_id','TEXT NULL');

    // Make phone nullable if it was previously NOT NULL
    $conn->query("ALTER TABLE users MODIFY COLUMN phone VARCHAR(15) NULL");

    // ---- Transactions table ----
    $conn->query("CREATE TABLE IF NOT EXISTS transactions (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        type       ENUM('cash in','cash out') NOT NULL,
        amount     DECIMAL(10,2) NOT NULL,
        note       TEXT,
        date       DATETIME NOT NULL,
        category   VARCHAR(50)  DEFAULT 'General',
        currency   VARCHAR(10)  DEFAULT 'INR',
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // ---- Recurring templates table ----
    $conn->query("CREATE TABLE IF NOT EXISTS recurring_templates (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        account_id INT NULL,
        type       ENUM('cash in','cash out') NOT NULL,
        amount     DECIMAL(10,2) NOT NULL,
        note       TEXT NOT NULL,
        category   VARCHAR(50)  DEFAULT 'General',
        currency   VARCHAR(10)  DEFAULT 'INR',
        frequency  ENUM('daily','weekly','monthly','yearly') NOT NULL,
        next_run   DATE NOT NULL,
        status     ENUM('active','paused') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Auto-migrate account_id column if missing in existing installations
    $chk_rec_acc = $conn->query("SHOW COLUMNS FROM recurring_templates LIKE 'account_id'");
    if ($chk_rec_acc && $chk_rec_acc->num_rows === 0) {
        @$conn->query("ALTER TABLE recurring_templates ADD COLUMN account_id INT NULL AFTER user_id");
    }


    // ---- Accounts table (multi-book feature) ----
    $conn->query("CREATE TABLE IF NOT EXISTS accounts (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        account_name VARCHAR(100) NOT NULL,
        icon         VARCHAR(50)  DEFAULT 'fa-wallet',
        created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // ---- Backup Settings table ----
    $conn->query("CREATE TABLE IF NOT EXISTS backup_settings (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        user_id              INT NOT NULL,
        backup_interval      INT DEFAULT 0, -- days (0 = disabled, 5, 7, 15, 30, 90)
        last_backup_date     DATE DEFAULT NULL,
        email_backup_enabled TINYINT(1) DEFAULT 0,
        google_access_token   TEXT DEFAULT NULL,
        google_refresh_token  TEXT DEFAULT NULL,
        google_backup_enabled TINYINT(1) DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // ---- Safe migration: add columns if missing ----
    addColIfMissing($conn, $dbName, 'backup_settings', 'email_backup_enabled', 'TINYINT(1) DEFAULT 0');
    addColIfMissing($conn, $dbName, 'backup_settings', 'google_access_token',  'TEXT DEFAULT NULL');
    addColIfMissing($conn, $dbName, 'backup_settings', 'google_refresh_token', 'TEXT DEFAULT NULL');
    addColIfMissing($conn, $dbName, 'backup_settings', 'google_backup_enabled','TINYINT(1) DEFAULT 0');

    // ---- Backup Logs table (history) ----
    $conn->query("CREATE TABLE IF NOT EXISTS backup_logs (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        file_name   VARCHAR(255) NOT NULL,
        backup_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // ---- Safe migration: add account_id to transactions ----
    addColIfMissing($conn, $dbName, 'transactions', 'account_id', 'INT NULL DEFAULT NULL');
    addColIfMissing($conn, $dbName, 'transactions', 'raw_source', 'TEXT NULL');
    addColIfMissing($conn, $dbName, 'transactions', 'source_type', 'VARCHAR(30) NULL DEFAULT NULL');
    addColIfMissing($conn, $dbName, 'transactions', 'source_reference', 'VARCHAR(150) NULL DEFAULT NULL');
    addColIfMissing($conn, $dbName, 'transactions', 'merchant_name', 'VARCHAR(100) NULL DEFAULT NULL');
    addColIfMissing($conn, $dbName, 'transactions', 'upi_id', 'VARCHAR(100) NULL DEFAULT NULL');
    addColIfMissing($conn, $dbName, 'transactions', 'transaction_hash', 'VARCHAR(64) NULL DEFAULT NULL');
    addColIfMissing($conn, $dbName, 'transactions', 'is_auto_import', 'TINYINT(1) DEFAULT 0');
    addIndexIfMissing($conn, $dbName, 'transactions', 'idx_transactions_user_hash', 'user_id, transaction_hash', true);

    // ---- Safe migration: add auto-import confirmation and transaction detection settings ----
    addColIfMissing($conn, $dbName, 'users', 'auto_import_confirm', 'TINYINT(1) DEFAULT 1');
    addColIfMissing($conn, $dbName, 'users', 'auto_detect_transactions', 'TINYINT(1) DEFAULT 0');

    // Migrate date to DATETIME if it's still DATE
    $conn->query("ALTER TABLE transactions MODIFY COLUMN date DATETIME NOT NULL");
}

/**
 * Safely adds a column to a table only if it doesn't already exist.
 * Works on MySQL 5.6, 5.7, 8.0, and MariaDB (avoids IF NOT EXISTS syntax).
 */
function addColIfMissing($conn, $dbName, $table, $column, $definition) {
    $res = $conn->query(
        "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = '$dbName'
           AND TABLE_NAME   = '$table'
           AND COLUMN_NAME  = '$column'"
    );
    if ($res && $res->fetch_assoc()['cnt'] == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function addIndexIfMissing($conn, $dbName, $table, $indexName, $columns, $unique = false) {
    $type = $unique ? 'UNIQUE' : 'INDEX';
    $res = $conn->query(
        "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = '$dbName'
           AND TABLE_NAME   = '$table'
           AND INDEX_NAME   = '$indexName'"
    );
    if ($res && $res->fetch_assoc()['cnt'] == 0) {
        $conn->query("ALTER TABLE `$table` ADD $type `$indexName` ($columns)");
    }
}

// Run initialization
initDB($conn);
?>

