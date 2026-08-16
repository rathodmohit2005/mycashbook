<?php
/**
 * One-time database repair script.
 * Open this in your browser ONCE, then delete it.
 * URL: http://localhost/my_cash_book/db_repair.php
 */
require_once 'db.php';

echo "<h2>Cash Book - Database Repair</h2><pre>";

$steps = [
    // Make phone nullable
    "ALTER TABLE users MODIFY COLUMN phone VARCHAR(15) NULL"                             => "Make 'phone' nullable",
    // Ensure email column exists
    "ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL"                              => "Add 'email' column (if missing)",
    // Ensure password column exists
    "ALTER TABLE users ADD COLUMN password VARCHAR(255) NULL"                           => "Add 'password' column (if missing)",
    // Ensure reset columns exist
    "ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL"                         => "Add 'reset_token' column (if missing)",
    "ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL"                          => "Add 'reset_expires' column (if missing)",
];

foreach ($steps as $sql => $label) {
    $result = $conn->query($sql);
    if ($result) {
        echo "✅ $label\n";
    } else {
        // 1060 = Duplicate column — not an error, column already exists
        if ($conn->errno === 1060) {
            echo "⏭️  $label (already exists, skipped)\n";
        } else {
            echo "❌ $label — Error: " . $conn->error . "\n";
        }
    }
}

echo "\n<strong>Done! You can delete this file now (db_repair.php).</strong>";
echo "</pre>";
?>
