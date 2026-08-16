<?php
/**
 * Shared functions for Cash Book PWA
 */

/**
 * Returns currency symbol for a given code
 */
function getCurrencySymbol($code) {
    $symbols = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'INR' => '₹',
        'JPY' => '¥',
        'CNY' => '¥'
    ];
    return $symbols[$code] ?? '$';
}

function normalizeImportText($text) {
    $text = trim($text);
    $text = preg_replace('/\s+/', ' ', $text);
    return $text;
}

function guessCategoryFromText($text) {
    $text = strtolower($text);
    $keywords = [
        'shopping'   => 'Shopping',
        'amazon'     => 'Shopping',
        'flipkart'   => 'Shopping',
        'myntra'     => 'Shopping',
        'grocery'    => 'Food',
        'swiggy'     => 'Food',
        'zomato'     => 'Food',
        'restaurant' => 'Food',
        'dinner'     => 'Food',
        'lunch'      => 'Food',
        'breakfast'  => 'Food',
        'fuel'       => 'Fuel',
        'petrol'     => 'Fuel',
        'diesel'     => 'Fuel',
        'rent'       => 'Housing',
        'electricity'=> 'Bills',
        'water bill' => 'Bills',
        'bill'       => 'Bills',
        'wifi'       => 'Bills',
        'subscription'=> 'Bills',
        'netflix'    => 'Entertainment',
        'hotstar'    => 'Entertainment',
        'spotify'    => 'Entertainment',
        'movie'      => 'Entertainment',
        'uber'       => 'Transport',
        'ola'        => 'Transport',
        'taxi'       => 'Transport',
        'bus'        => 'Transport',
        'train'      => 'Transport',
        'salary'     => 'Income',
        'payroll'    => 'Income',
        'interest'   => 'Income',
        'refund'     => 'Income',
        'cashback'   => 'Income',
        'upi'        => 'Transfer',
        'transfer'   => 'Transfer',
        'loan'       => 'Transfer'
    ];

    foreach ($keywords as $keyword => $category) {
        if (strpos($text, $keyword) !== false) {
            return $category;
        }
    }
    return 'General';
}

function generateTransactionHash($user_id, $type, $amount, $date, $note, $account_id) {
    $cleanNote = strtolower(trim(preg_replace('/\s+/', ' ', $note)));
    $cleanDate = date('Y-m-d H:i', strtotime($date));
    $parts = [$user_id, $type, number_format((float)$amount, 2, '.', ''), $cleanDate, $cleanNote, (int)$account_id];
    return hash('sha256', implode('|', $parts));
}

function transactionExists($conn, $user_id, $hash) {
    $stmt = $conn->prepare("SELECT id FROM transactions WHERE user_id = ? AND transaction_hash = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param("is", $user_id, $hash);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result && $result->num_rows > 0;
}

function parseTransactionText($text) {
    $text = normalizeImportText($text);
    $result = [
        'raw_source'   => $text,
        'type'         => 'cash out',
        'amount'       => 0.00,
        'date_time'    => date('Y-m-d H:i:s'),
        'note'         => 'Transaction',
        'category'     => 'General',
        'merchant_name'=> null,
        'upi_id'       => null,
        'source_type'  => 'sms',
        'source_reference' => null
    ];

    // Amount extraction
    if (preg_match('/(?:₹|Rs\.?|INR|USD|EUR|GBP|\$)\s*([0-9,]+(?:\.[0-9]{1,2})?)/i', $text, $m)) {
        $result['amount'] = floatval(str_replace(',', '', $m[1]));
    } elseif (preg_match('/([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{1,2})?)\s*(?:rupees|rs\.?|INR|USD|EUR|GBP|dollars|bucks)/i', $text, $m)) {
        $result['amount'] = floatval(str_replace(',', '', $m[1]));
    } elseif (preg_match('/\b([0-9]+(?:\.[0-9]{1,2})?)\b/', $text, $m)) {
        $result['amount'] = floatval($m[1]);
    }

    // Transaction type detection
    if (preg_match('/\b(received|credited|deposited|added|refund|cashback|salary|income|recd)\b/i', $text)) {
        $result['type'] = 'cash in';
    } elseif (preg_match('/\b(debited|paid|spent|withdrawn|sent|purchased|transferred|purchase|swipe|txn|deducted|paid to|sent to)\b/i', $text)) {
        $result['type'] = 'cash out';
    }

    // Date and time extraction
    if (preg_match('/(\d{1,2}[\/\-\. ]\d{1,2}[\/\-\. ]\d{2,4})/', $text, $m)) {
        $dateStr = str_replace(['.', ' '], ['-', '-'], $m[1]);
        if (strtotime($dateStr) !== false) {
            $result['date_time'] = date('Y-m-d H:i:s', strtotime($dateStr));
        }
    }
    if (preg_match('/(\d{1,2}:\d{2})(?:\s*(AM|PM))?/i', $text, $m)) {
        $timeStr = strtoupper($m[1] . (isset($m[2]) ? ' ' . $m[2] : ''));
        $currentDate = date('Y-m-d', strtotime($result['date_time']));
        if (strtotime("$currentDate $timeStr") !== false) {
            $result['date_time'] = date('Y-m-d H:i:s', strtotime("$currentDate $timeStr"));
        }
    }

    // UPI / Merchant ID extraction
    if (preg_match('/([a-zA-Z0-9.+\-_]{2,}@[a-zA-Z0-9\-\.]{2,})/', $text, $m)) {
        $result['upi_id'] = $m[1];
        $result['merchant_name'] = $m[1];
    }

    // Transaction reference / unique ID extraction
    if (preg_match('/\b(?:ref|txn|tnx|utr|transaction id|transaction reference|payment id|payment ref)\b[:\s-]*([A-Za-z0-9\-]+)/i', $text, $m)) {
        $result['source_reference'] = $m[1];
    }

    // Merchant or sender/receiver extraction
    if (preg_match('/(?:from|to|at|via|for|by|with)\s+([A-Za-z0-9 &\-\.\(\)]+?)(?:\s+on|\s+at|\s+txn|\s+ref|\s+id|\s+for|\s+\d|\.|,|$)/i', $text, $m)) {
        $candidate = trim($m[1]);
        if (strlen($candidate) > 1 && strlen($candidate) < 80) {
            $result['note'] = $candidate;
            if (!$result['merchant_name']) {
                $result['merchant_name'] = $candidate;
            }
        }
    }

    if ($result['note'] === 'Transaction') {
        if (preg_match('/\b(amazon|flipkart|zomato|swiggy|uber|ola|paytm|myntra|netflix|hotstar|hdfc|icici|sbi|axis|phonepe|google pay|g pay|bhim|paypal|apple pay)\b/i', $text, $m)) {
            $result['note'] = ucwords(strtolower($m[1]));
        } elseif (preg_match('/txn\s*[:\-]?\s*([A-Za-z0-9]+)/i', $text, $m)) {
            $result['note'] = 'Transaction ' . $m[1];
        }
    }

    $result['category'] = guessCategoryFromText($text);

    if (empty($result['note']) || strtolower($result['note']) === 'transaction') {
        $result['note'] = 'Smart Import';
    }

    return $result;
}

/** 
 * Process recurring transactions for a user
 * Returns count of transactions created
 */
function processRecurringTransactions($conn, $user_id) {
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT * FROM recurring_templates WHERE user_id = ? AND status = 'active' AND next_run <= ?");
    $stmt->bind_param("is", $user_id, $today);
    $stmt->execute();
    $templates = $stmt->get_result();

    // Fetch first user account as fallback if template account_id is null
    $first_acc_id = null;
    $acc_stmt = $conn->prepare("SELECT id FROM accounts WHERE user_id = ? ORDER BY id ASC LIMIT 1");
    $acc_stmt->bind_param("i", $user_id);
    $acc_stmt->execute();
    $acc_res = $acc_stmt->get_result()->fetch_assoc();
    if ($acc_res) {
        $first_acc_id = (int)$acc_res['id'];
    }

    $count = 0;
    while ($tpl = $templates->fetch_assoc()) {
        $next_run = $tpl['next_run'];
        $acc_id = !empty($tpl['account_id']) ? (int)$tpl['account_id'] : $first_acc_id;
        
        // Loop to handle "catch-up" (multiple occurrences if user missed days)
        while ($next_run <= $today) {
            // Create a real transaction
            $ins = $conn->prepare("INSERT INTO transactions (user_id, type, amount, note, date, category, currency, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param("isdssssi", $user_id, $tpl['type'], $tpl['amount'], $tpl['note'], $next_run, $tpl['category'], $tpl['currency'], $acc_id);
            $ins->execute();
            $count++;


            // Calculate NEXT next_run
            $d = new DateTime($next_run);
            if ($tpl['frequency'] === 'daily') $d->modify('+1 day');
            elseif ($tpl['frequency'] === 'weekly') $d->modify('+1 week');
            elseif ($tpl['frequency'] === 'monthly') $d->modify('+1 month');
            elseif ($tpl['frequency'] === 'yearly') $d->modify('+1 year');
            $next_run = $d->format('Y-m-d');
        }

        // Update template header with NEW future next_run
        $upd = $conn->prepare("UPDATE recurring_templates SET next_run = ? WHERE id = ?");
        $upd->bind_param("si", $next_run, $tpl['id']);
        $upd->execute();
    }
    return $count;
}

/**
 * Checks if auto-backup is due and runs it
 */
function runAutoBackup($conn, $user_id) {
    // 1. Get backup settings
    $stmt = $conn->prepare("SELECT backup_interval, last_backup_date FROM backup_settings WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 0) return false;
    
    $settings = $res->fetch_assoc();
    $interval = (int)$settings['backup_interval'];
    $last_date = $settings['last_backup_date'];
    
    if ($interval <= 0) return false; // Auto-backup disabled
    
    $today = new DateTime();
    $should_backup = false;
    
    if (!$last_date) {
        $should_backup = true;
    } else {
        $last = new DateTime($last_date);
        $diff = $today->diff($last)->days;
        if ($diff >= $interval) {
            $should_backup = true;
        }
    }
    
    if ($should_backup) {
        // 1.5 Get user email and settings
        $stmt_user = $conn->prepare("SELECT u.email, bs.email_backup_enabled, bs.google_access_token, bs.google_refresh_token, bs.google_backup_enabled FROM users u JOIN backup_settings bs ON u.id = bs.user_id WHERE u.id = ?");
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $user_data = $stmt_user->get_result()->fetch_assoc();
        
        $user_email    = $user_data['email'] ?? '';
        $email_enabled = (bool)($user_data['email_backup_enabled'] ?? false);
        $g_access      = $user_data['google_access_token'] ?? '';
        $g_refresh     = $user_data['google_refresh_token'] ?? '';
        $g_enabled     = (bool)($user_data['google_backup_enabled'] ?? false);

        // 2. Generate CSV
        $filename = "backup_" . $user_id . "_" . date('Y-m-d') . ".csv";
        $dir = __DIR__ . "/backups";
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        
        $filepath = $dir . "/" . $filename;
        $fp = fopen($filepath, 'w');
        
        // CSV Headers (matching export.php)
        fputcsv($fp, ['Date', 'Amount', 'Type', 'Note', 'Category', 'Account']);
        
        // Fetch transactions (matching export.php query)
        $stmt = $conn->prepare("SELECT t.date, t.amount, t.type, t.note, t.category, IFNULL(a.account_name, '') as account_name FROM transactions t LEFT JOIN accounts a ON t.account_id = a.id WHERE t.user_id = ? ORDER BY t.date DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $transactions = $stmt->get_result();
        
        while ($row = $transactions->fetch_assoc()) {
            fputcsv($fp, $row);
        }
        
        fclose($fp);
        
        // 2.5 Send Email if enabled
        if ($email_enabled && !empty($user_email)) {
            sendEmailWithAttachment($user_email, "Your Cash Book Backup", "Here is your latest backup.", $filepath, $filename);
        }

        // 2.6 Upload to Google Drive if connected and enabled
        if ($g_enabled && !empty($g_access)) {
            uploadToGoogleDrive($conn, $user_id, $filepath, $filename, $g_access, $g_refresh);
        }

        // 3. Log the backup
        $log_stmt = $conn->prepare("INSERT INTO backup_logs (user_id, file_name) VALUES (?, ?)");
        $log_stmt->bind_param("is", $user_id, $filename);
        $log_stmt->execute();
        
        // 4. Update last_backup_date
        $today_str = date('Y-m-d');
        $upd = $conn->prepare("UPDATE backup_settings SET last_backup_date = ? WHERE user_id = ?");
        $upd->bind_param("si", $today_str, $user_id);
        $upd->execute();
        
        // 5. Cleanup local file to save space
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        return true;
    }
    
    return false;
}

/**
 * Uploads a file to Google Drive using the provided access token
 */
function uploadToGoogleDrive($conn, $user_id, $file_path, $file_name, $access_token, $refresh_token) {
    require_once __DIR__ . '/config.php';
    
    // 1. Get or Create Folder "CashBook Backups"
    $folder_id = getOrCreateFolder($access_token, "CashBook Backups");
    
    $metadata = [
        'name' => $file_name,
        'mimeType' => 'text/csv'
    ];
    if ($folder_id) {
        $metadata['parents'] = [$folder_id];
    }
    
    $boundary = "-------" . md5(time());
    $data = "--" . $boundary . "\r\n";
    $data .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $data .= json_encode($metadata) . "\r\n";
    $data .= "--" . $boundary . "\r\n";
    $data .= "Content-Type: text/csv\r\n\r\n";
    $data .= file_get_contents($file_path) . "\r\n";
    $data .= "--" . $boundary . "--";
    
    $ch = curl_init("https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $access_token,
        "Content-Type: multipart/related; boundary=" . $boundary,
        "Content-Length: " . strlen($data)
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($status == 401 && !empty($refresh_token)) {
        // Token expired, refresh and retry once
        $new_token = refreshGoogleToken($conn, $user_id, $refresh_token);
        if ($new_token) {
            return uploadToGoogleDrive($conn, $user_id, $file_path, $file_name, $new_token, $refresh_token);
        }
    }
    
    return $status == 200;
}

/**
 * Helper to find or create a folder in Google Drive
 */
function getOrCreateFolder($access_token, $folder_name) {
    // Search for existing folder
    $query = "name = '$folder_name' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
    $url = "https://www.googleapis.com/drive/v3/files?" . http_build_query(['q' => $query]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $access_token]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    if (!empty($res['files'])) {
        return $res['files'][0]['id'];
    }
    
    // Create new folder
    $metadata = [
        'name' => $folder_name,
        'mimeType' => 'application/vnd.google-apps.folder'
    ];
    $ch = curl_init("https://www.googleapis.com/drive/v3/files");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $access_token,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($metadata));
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    return $res['id'] ?? null;
}

/**
 * Refreshes the Google OAuth2 access token
 */
function refreshGoogleToken($conn, $user_id, $refresh_token) {
    require_once __DIR__ . '/config.php';
    
    $ch = curl_init("https://oauth2.googleapis.com/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'refresh_token' => $refresh_token,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'grant_type' => 'refresh_token'
    ]));
    
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);
    
    if (isset($data['access_token'])) {
        $new_access_token = $data['access_token'];
        $stmt = $conn->prepare("UPDATE backup_settings SET google_access_token = ? WHERE user_id = ?");
        $stmt->bind_param("si", $new_access_token, $user_id);
        $stmt->execute();
        return $new_access_token;
    }
    
    return false;
}

/**
 * Sends an email with an attachment using PHP mail()
 */
function sendEmailWithAttachment($to, $subject, $message, $file_path, $file_name) {
    $content = file_get_contents($file_path);
    $content = chunk_split(base64_encode($content));
    $uid = md5(uniqid(time()));
    
    $header = "From: Cash Book <noreply@{$_SERVER['HTTP_HOST']}>\r\n";
    $header .= "MIME-Version: 1.0\r\n";
    $header .= "Content-Type: multipart/mixed; boundary=\"".$uid."\"";
    
    $nmessage = "--".$uid."\r\n";
    $nmessage .= "Content-type:text/plain; charset=iso-8859-1\r\n";
    $nmessage .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $nmessage .= $message."\r\n\r\n";
    $nmessage .= "--".$uid."\r\n";
    $nmessage .= "Content-Type: application/octet-stream; name=\"".$file_name."\"\r\n";
    $nmessage .= "Content-Transfer-Encoding: base64\r\n";
    $nmessage .= "Content-Disposition: attachment; filename=\"".$file_name."\"\r\n\r\n";
    $nmessage .= $content."\r\n\r\n";
    $nmessage .= "--".$uid."--";

    return mail($to, $subject, $nmessage, $header);
}
?>
