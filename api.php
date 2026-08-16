<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

// API: Add Transaction via AJAX
if ($action === 'add_transaction') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
    
    // Check CSRF manually for API
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        echo json_encode(['status' => 'error', 'message' => 'Security Error - CSRF Invalid']);
        exit;
    }

    $type        = $_POST['type'] ?? '';
    $amount      = (float)($_POST['amount'] ?? 0);
    $note        = trim($_POST['note'] ?? '');
    $currency    = $_POST['currency'] ?? 'INR';
    $date        = $_POST['date'] ?? date('Y-m-d H:i:s');
    $is_recurring = isset($_POST['is_recurring']) && $_POST['is_recurring'] === 'on';
    $frequency   = $_POST['frequency'] ?? '';
    $category    = $_POST['category'] ?? 'General';
    $account_id_api = (int)($_POST['account_id'] ?? 0) ?: null;
    $user_id     = $_SESSION['user_id'];
    
    // Fallback Auto-Cat if they submitted 'Auto' or left it blank
    if (empty($category) || $category === 'Auto') {
        $noteLower = strtolower($note);
        if (strpos($noteLower, 'amazon') !== false || strpos($noteLower, 'flipkart') !== false) {
            $category = 'Shopping';
        } elseif (strpos($noteLower, 'petrol') !== false || strpos($noteLower, 'fuel') !== false) {
            $category = 'Fuel';
        } elseif (strpos($noteLower, 'swiggy') !== false || strpos($noteLower, 'zomato') !== false || strpos($noteLower, 'food') !== false) {
            $category = 'Food';
        } else {
            $category = 'General';
        }
    }

    if (in_array($type, ['cash in', 'cash out']) && $amount > 0) {
        $raw_source = trim($_POST['raw_source'] ?? '');
        $source_type = trim($_POST['source_type'] ?? 'manual');
        $source_reference = trim($_POST['source_reference'] ?? '');
        $merchant_name = trim($_POST['merchant_name'] ?? '');
        $upi_id = trim($_POST['upi_id'] ?? '');
        $transaction_hash = generateTransactionHash($user_id, $type, $amount, $date, $note, $account_id_api);

        if (transactionExists($conn, $user_id, $transaction_hash)) {
            echo json_encode(['status' => 'error', 'message' => 'Duplicate transaction detected.']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, note, date, category, currency, account_id, raw_source, source_type, source_reference, merchant_name, upi_id, is_auto_import, transaction_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Query Prep Failed: ' . $conn->error]);
            exit;
        }
        $is_auto_import = $raw_source ? 1 : 0;
        $stmt->bind_param("isdssssissssiss", $user_id, $type, $amount, $note, $date, $category, $currency, $account_id_api, $raw_source, $source_type, $source_reference, $merchant_name, $upi_id, $is_auto_import, $transaction_hash);
        if ($stmt->execute()) {
            // If recurring, also create a template
            if ($is_recurring && !empty($frequency)) {
                $d = new DateTime($date);
                if ($frequency === 'daily') $d->modify('+1 day');
                elseif ($frequency === 'weekly') $d->modify('+1 week');
                elseif ($frequency === 'monthly') $d->modify('+1 month');
                elseif ($frequency === 'yearly') $d->modify('+1 year');
                $n_run = $d->format('Y-m-d');

                $tpl = $conn->prepare("INSERT INTO recurring_templates (user_id, account_id, type, amount, note, category, currency, frequency, next_run) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $tpl->bind_param("iisdsssss", $user_id, $account_id_api, $type, $amount, $note, $category, $currency, $frequency, $n_run);
                $tpl->execute();

            }
            echo json_encode(['status' => 'success', 'message' => 'Transaction added!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database execution failed.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid amount or type.']);
    }
    exit;
}

// API: Parse transaction text (SMS / notification / shared text)
if ($action === 'parse_transaction_text') {
    header('Content-Type: application/json');
    $text = trim($_GET['text'] ?? $_POST['text'] ?? '');
    if (empty($text)) {
        echo json_encode(['status' => 'error', 'message' => 'Text is required for parsing.']);
        exit;
    }

    $parsed = parseTransactionText($text);
    echo json_encode(['status' => 'success', 'data' => $parsed]);
    exit;
}

// API: Auto import a parsed transaction
if ($action === 'import_transaction') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

    $raw_source = trim($_POST['raw_source'] ?? '');
    $parsed = !empty($raw_source) ? parseTransactionText($raw_source) : [];

    $type      = $_POST['type'] ?? ($parsed['type'] ?? 'cash out');
    $amount    = (float)($_POST['amount'] ?? ($parsed['amount'] ?? 0));
    $note      = trim($_POST['note'] ?? ($parsed['note'] ?? 'Smart Import'));
    $date      = $_POST['date'] ?? ($parsed['date_time'] ?? date('Y-m-d H:i:s'));
    $category  = $_POST['category'] ?? ($parsed['category'] ?? 'General');
    $currency  = $_POST['currency'] ?? 'INR';
    $accountId = (int)($_POST['account_id'] ?? 0) ?: null;
    $sourceType= $_POST['source_type'] ?? ($parsed['source_type'] ?? 'sms');
    $sourceRef = trim($_POST['source_reference'] ?? ($parsed['source_reference'] ?? ''));
    $merchant  = trim($_POST['merchant_name'] ?? ($parsed['merchant_name'] ?? ''));
    $upiId     = trim($_POST['upi_id'] ?? ($parsed['upi_id'] ?? ''));
    $user_id   = $_SESSION['user_id'];

    if (in_array($type, ['cash in', 'cash out']) && $amount > 0) {
        $stmtSettings = $conn->prepare("SELECT auto_detect_transactions, auto_import_confirm FROM users WHERE id = ?");
        $stmtSettings->bind_param("i", $user_id);
        $stmtSettings->execute();
        $settings = $stmtSettings->get_result()->fetch_assoc();
        $autoDetectEnabled = (int)($settings['auto_detect_transactions'] ?? 0);
        $confirmRequired = (int)($settings['auto_import_confirm'] ?? 1);

        if (!$autoDetectEnabled) {
            echo json_encode(['status' => 'error', 'message' => 'Auto detect is disabled in your settings.']);
            exit;
        }

        $transaction_hash = generateTransactionHash($user_id, $type, $amount, $date, $note, $accountId);
        if (transactionExists($conn, $user_id, $transaction_hash)) {
            echo json_encode(['status' => 'error', 'message' => 'Duplicate transaction detected.']);
            exit;
        }

        if ($confirmRequired) {
            echo json_encode([
                'status' => 'confirm_required',
                'message' => 'Confirmation required before adding this detected transaction.',
                'data' => ['type' => $type, 'amount' => $amount, 'note' => $note, 'date' => $date, 'category' => $category, 'currency' => $currency, 'source_type' => $sourceType, 'source_reference' => $sourceRef, 'merchant_name' => $merchant, 'upi_id' => $upiId, 'raw_source' => $raw_source]
            ]);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, note, date, category, currency, account_id, raw_source, source_type, source_reference, merchant_name, upi_id, is_auto_import, transaction_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Query preparation failed: ' . $conn->error]);
            exit;
        }

        $is_auto_import = 1;
        $stmt->bind_param("isdssssissssiss", $user_id, $type, $amount, $note, $date, $category, $currency, $accountId, $raw_source, $sourceType, $sourceRef, $merchant, $upiId, $is_auto_import, $transaction_hash);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Imported transaction successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database execution failed.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid import payload.']);
    }
    exit;
}

// API: Fetch Transactions list dynamically
if ($action === 'get_transactions') {
    header('Content-Type: application/json');
    $search = $_GET['note'] ?? '';
    $typeFilter = $_GET['type'] ?? '';
    $dateStart = $_GET['start'] ?? '';
    $dateEnd = $_GET['end'] ?? '';

    // Normalize dates for SQL (handle MM/DD/YYYY or other formats)
    if ($dateStart) $dateStart = date('Y-m-d', strtotime($dateStart));
    if ($dateEnd) $dateEnd = date('Y-m-d', strtotime($dateEnd));
    $user_id = $_SESSION['user_id'];


    $where = ["user_id = ?"];
    $types = "i";
    $params = [$user_id];

    if ($search) {
        $where[] = "note LIKE ?";
        $types .= "s";
        $params[] = "%" . $search . "%";
    }
    if ($typeFilter) {
        $where[] = "type = ?";
        $types .= "s";
        $params[] = $typeFilter;
    }
    if ($dateStart) {
        $where[] = "DATE(date) >= DATE(?)";
        $types .= "s";
        $params[] = $dateStart;
    }
    if ($dateEnd) {
        $where[] = "DATE(date) <= DATE(?)";
        $types .= "s";
        $params[] = $dateEnd;
    }

    $sql = "SELECT id, type, amount, note, date, category, currency FROM transactions WHERE " . implode(" AND ", $where) . " ORDER BY date DESC, id DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Fetch Prep Failed']);
        exit;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $transactions = [];
    while ($row = $res->fetch_assoc()) {
        $row['formattedDate'] = date('M j, Y, g:i A', strtotime($row['date']));
        $row['symbol'] = getCurrencySymbol($row['currency']);
        $row['amount_raw'] = $row['amount'];
        $row['amount'] = number_format($row['amount'], 2);
        // Map type to icons and classes just like index.php does
        $row['is_in'] = ($row['type'] === 'cash in');
        $transactions[] = $row;
    }

    echo json_encode(['status' => 'success', 'data' => $transactions]);
    exit;
}

// API: Suggest Category based on note
if ($action === 'suggest_category') {
    header('Content-Type: application/json');
    $note = trim($_GET['note'] ?? '');
    $user_id = $_SESSION['user_id'];
    
    if (strlen($note) < 2) {
        echo json_encode(['status' => 'success', 'category' => 'General']);
        exit;
    }

    // 1. Check history for exact or similar notes
    $stmt = $conn->prepare("
        SELECT category, COUNT(*) as count 
        FROM transactions 
        WHERE user_id = ? AND note LIKE ? 
        GROUP BY category 
        ORDER BY count DESC LIMIT 1
    ");
    if (!$stmt) {
        echo json_encode(['status' => 'success', 'category' => 'General']);
        exit;
    }
    $searchTerm = "%" . $note . "%";
    $stmt->bind_param("is", $user_id, $searchTerm);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        echo json_encode(['status' => 'success', 'category' => $row['category'], 'source' => 'history']);
        exit;
    }

    // 2. Fallback to keyword mapping (matching the PHP logic in add.php/api.php)
    $cat = 'General';
    $nl = strtolower($note);
    $map = [
        'amazon' => 'Shopping', 'flipkart' => 'Shopping', 'mart' => 'Shopping', 'store' => 'Shopping',
        'zomato' => 'Food', 'swiggy' => 'Food', 'pizza' => 'Food', 'burger' => 'Food', 'restaurant' => 'Food',
        'petrol' => 'Fuel', 'diesel' => 'Fuel', 'fuel' => 'Fuel', 'gas' => 'Fuel',
        'salary' => 'Salary', 'rent' => 'Housing', 'electricity' => 'Utility', 'water' => 'Utility',
        'netflix' => 'Entertainment', 'movie' => 'Entertainment', 'ticket' => 'Entertainment'
    ];
    
    foreach ($map as $key => $val) {
        if (strpos($nl, $key) !== false) {
            $cat = $val;
            break;
        }
    }

    echo json_encode(['status' => 'success', 'category' => $cat, 'source' => 'keywords']);
    exit;
}

// API: Reset PIN with Password (Recovery)
if ($action === 'reset_pin_with_password') {
    header('Content-Type: application/json');
    $password = $_POST['password'] ?? '';
    $user_id = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    if ($res && password_verify($password, $res['password'])) {
        // Clear PIN and Unlock
        $stmt_upd = $conn->prepare("UPDATE users SET pin_code = NULL, biometric_enabled = 0 WHERE id = ?");
        $stmt_upd->bind_param("i", $user_id);
        $stmt_upd->execute();
        
        $_SESSION['app_unlocked'] = true;
        $_SESSION['has_pin'] = false;
        $_SESSION['has_bio'] = false;
        
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Password']);
    }
    exit;
}

// API: Verify PIN and Unlock
if ($action === 'unlock') {
    header('Content-Type: application/json');
    $pin = $_POST['pin'] ?? '';
    $user_id = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("SELECT pin_code FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    if ($res && $res['pin_code'] === $pin) {
        $_SESSION['app_unlocked'] = true;
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid PIN']);
    }
    exit;
}

// API: Biometric Unlock (JS-verified)
if ($action === 'unlock_bio') {
    header('Content-Type: application/json');
    // In a real app, you'd verify the WebAuthn signature here.
    // For this implementation, we rely on the client-side navigator.credentials check.
    $_SESSION['app_unlocked'] = true;
    echo json_encode(['status' => 'success']);
    exit;
}

// API: Toggle Biometric
if ($action === 'toggle_biometric') {
    header('Content-Type: application/json');
    $enabled = (int)($_POST['enabled'] ?? 0);
    $user_id = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("UPDATE users SET biometric_enabled = ? WHERE id = ?");
    $stmt->bind_param("ii", $enabled, $user_id);
    if ($stmt->execute()) {
        $_SESSION['has_bio'] = (bool)$enabled;
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// API: Force Lock (for inactivity)
if ($action === 'lock') {
    header('Content-Type: application/json');
    $_SESSION['app_unlocked'] = false;
    echo json_encode(['status' => 'success']);
    exit;
}

// API: Voice Assistant Summary
if ($action === 'get_voice_summary') {
    header('Content-Type: application/json');
    $user_id = $_SESSION['user_id'];
    $today = date('Y-m-d');
    
    // Total Today
    $stmt1 = $conn->prepare("SELECT SUM(amount) as total FROM transactions WHERE user_id = ? AND date = ? AND type = 'cash out'");
    $stmt1->bind_param("is", $user_id, $today);
    $stmt1->execute();
    $today_spent = (float)$stmt1->get_result()->fetch_assoc()['total'];
    
    // Current Balance
    $stmt2 = $conn->prepare("SELECT 
        (SELECT SUM(amount) FROM transactions WHERE user_id = ? AND type = 'cash in') - 
        (SELECT SUM(amount) FROM transactions WHERE user_id = ? AND type = 'cash out') as bal");
    $stmt2->bind_param("ii", $user_id, $user_id);
    $stmt2->execute();
    $balance = (float)$stmt2->get_result()->fetch_assoc()['bal'];
    
    echo json_encode([
        'status' => 'success',
        'today_spent' => $today_spent,
        'balance' => $balance
    ]);
    exit;
}

// API: Manage Recurring (Pause/Resume/Delete)
if (isset($_GET['recurring_action'])) {
    header('Content-Type: application/json');
    $r_action = $_GET['recurring_action'];
    $tid = (int)($_GET['id'] ?? 0);
    $user_id = $_SESSION['user_id'];

    if ($r_action === 'pause') {
        $stmt = $conn->prepare("UPDATE recurring_templates SET status = 'paused' WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $tid, $user_id);
    } elseif ($r_action === 'resume') {
        $stmt = $conn->prepare("UPDATE recurring_templates SET status = 'active' WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $tid, $user_id);
    } elseif ($r_action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM recurring_templates WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $tid, $user_id);
    }

    if (isset($stmt) && $stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// API: Sync Offline Transactions
if ($action === 'sync_offline') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $transactions = $input['transactions'] ?? [];
    $user_id = $_SESSION['user_id'];
    
    if (empty($transactions)) {
        echo json_encode(['status' => 'success', 'count' => 0]);
        exit;
    }
    
    $success_count = 0;
    $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, note, date, category, currency, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($transactions as $t) {
        $type     = $t['type'] ?? 'cash out';
        $amount   = (float)($t['amount'] ?? 0);
        $note     = trim($t['note'] ?? 'Offline Transaction');
        $date     = $t['date'] ?? date('Y-m-d');
        $currency = $t['currency'] ?? 'INR';
        $category = $t['category'] ?? 'General';
        $acc_id   = (int)($t['account_id'] ?? 0) ?: null;
        
        if ($amount > 0) {
            $stmt->bind_param("isdssssi", $user_id, $type, $amount, $note, $date, $category, $currency, $acc_id);
            if ($stmt->execute()) {
                $success_count++;
            }
        }
    }
    
    echo json_encode(['status' => 'success', 'count' => $success_count]);
    exit;
}

// API: Sync Offline Accounts
if ($action === 'sync_offline_accounts') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $accounts = $input['accounts'] ?? [];
    $user_id = $_SESSION['user_id'];
    
    if (empty($accounts)) {
        echo json_encode(['status' => 'success', 'count' => 0]);
        exit;
    }
    
    $success_count = 0;
    $id_map = [];
    $stmt = $conn->prepare("INSERT INTO accounts (user_id, account_name, icon) VALUES (?, ?, ?)");
    
    foreach ($accounts as $a) {
        $name = trim($a['account_name'] ?? '');
        $icon = $a['icon'] ?? 'fa-wallet';
        $off_id = $a['offline_id'] ?? 0;
        
        if (!empty($name)) {
            $stmt->bind_param("iss", $user_id, $name, $icon);
            if ($stmt->execute()) {
                $success_count++;
                if ($off_id) {
                    $id_map[$off_id] = $conn->insert_id;
                }
            }
        }
    }
    
    echo json_encode(['status' => 'success', 'count' => $success_count, 'id_map' => $id_map]);
    exit;
}

// API: Get Report Data for Charts (Dynamic)
if ($action === 'get_report_data') {
    header('Content-Type: application/json');
    $user_id = $_SESSION['user_id'];
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = $_GET['month'] ?? date('Y-m');
    
    // 1. Yearly Ratio (Donut)
    $stmt_y_in = $conn->prepare("SELECT SUM(amount) as total FROM transactions WHERE user_id = ? AND type = 'cash in' AND YEAR(date) = ?");
    $stmt_y_in->bind_param("ii", $user_id, $year);
    $stmt_y_in->execute();
    $y_in = (float)$stmt_y_in->get_result()->fetch_assoc()['total'];

    $stmt_y_out = $conn->prepare("SELECT SUM(amount) as total FROM transactions WHERE user_id = ? AND type = 'cash out' AND YEAR(date) = ?");
    $stmt_y_out->bind_param("ii", $user_id, $year);
    $stmt_y_out->execute();
    $y_out = (float)$stmt_y_out->get_result()->fetch_assoc()['total'];

    // 2. Monthly Stats (Summary Cards)
    $stmt_m_in = $conn->prepare("SELECT SUM(amount) as total FROM transactions WHERE user_id = ? AND type = 'cash in' AND DATE_FORMAT(date, '%Y-%m') = ?");
    $stmt_m_in->bind_param("is", $user_id, $month);
    $stmt_m_in->execute();
    $m_in = (float)$stmt_m_in->get_result()->fetch_assoc()['total'];

    $stmt_m_out = $conn->prepare("SELECT SUM(amount) as total FROM transactions WHERE user_id = ? AND type = 'cash out' AND DATE_FORMAT(date, '%Y-%m') = ?");
    $stmt_m_out->bind_param("is", $user_id, $month);
    $stmt_m_out->execute();
    $m_out = (float)$stmt_m_out->get_result()->fetch_assoc()['total'];

    // 3. Monthly Daily Chart
    $stmt_m_daily = $conn->prepare("SELECT DATE(date) as d, type, SUM(amount) as total FROM transactions WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? GROUP BY d, type ORDER BY d ASC");
    $stmt_m_daily->bind_param("is", $user_id, $month);
    $stmt_m_daily->execute();
    $m_daily_res = $stmt_m_daily->get_result();
    
    $m_daily_labels = [];
    $m_daily_data_in = [];
    $m_daily_data_out = [];
    $temp_daily = [];

    while ($r = $m_daily_res->fetch_assoc()) {
        $d = date('d M', strtotime($r['d']));
        if (!isset($temp_daily[$d])) {
            $temp_daily[$d] = ['in' => 0, 'out' => 0];
        }
        if ($r['type'] === 'cash in') {
            $temp_daily[$d]['in'] += $r['total'];
        } else {
            $temp_daily[$d]['out'] += $r['total'];
        }
    }

    foreach ($temp_daily as $d => $totals) {
        $m_daily_labels[] = $d;
        $m_daily_data_in[] = $totals['in'];
        $m_daily_data_out[] = $totals['out'];
    }

    // 4. Monthly Category Chart
    $stmt_m_cat = $conn->prepare("SELECT category, SUM(amount) as total FROM transactions WHERE user_id = ? AND type = 'cash out' AND DATE_FORMAT(date, '%Y-%m') = ? GROUP BY category ORDER BY total DESC");
    $stmt_m_cat->bind_param("is", $user_id, $month);
    $stmt_m_cat->execute();
    $m_cat_res = $stmt_m_cat->get_result();
    $m_cat_labels = [];
    $m_cat_data = [];
    while ($r = $m_cat_res->fetch_assoc()) {
        $m_cat_labels[] = $r['category'];
        $m_cat_data[] = $r['total'];
    }

    echo json_encode([
        'status' => 'success',
        'yearly' => [
            'income' => $y_in,
            'expense' => $y_out,
            'year' => $year
        ],
        'monthly' => [
            'total_in' => $m_in,
            'total_out' => $m_out,
            'balance' => $m_in - $m_out,
            'month' => $month,
            'month_label' => date('F Y', strtotime($month . '-01')),
            'month_short' => date('M', strtotime($month . '-01')),
            'month_year' => date('M Y', strtotime($month . '-01')),
            'daily_labels' => $m_daily_labels,
            'daily_data_in' => $m_daily_data_in,
            'daily_data_out' => $m_daily_data_out,
            'cat_labels' => $m_cat_labels,
            'cat_data' => $m_cat_data
        ]
    ]);
    exit;
}

// API: Process Voice/Text Command (NLU)
if ($action === 'process_nlu') {
    header('Content-Type: application/json');
    $input = trim($_GET['q'] ?? '');
    if (empty($input)) {
        echo json_encode(['status' => 'error', 'message' => 'No input']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $lower = strtolower($input);
    
    // 1. Account Detection (Fetch from DB & Fallback to First/Default Account)
    $account = 'Cash';
    $account_id = null;
    $first_acc_id = null;
    $first_acc_name = 'Cash';

    $stmt_acc = $conn->prepare("SELECT id, account_name FROM accounts WHERE user_id = ? ORDER BY id ASC");
    $stmt_acc->bind_param("i", $user_id);
    $stmt_acc->execute();
    $accounts = $stmt_acc->get_result();
    while ($acc = $accounts->fetch_assoc()) {
        if ($first_acc_id === null) {
            $first_acc_id = (int)$acc['id'];
            $first_acc_name = $acc['account_name'];
        }
        if (strpos($lower, strtolower($acc['account_name'])) !== false) {
            $account = $acc['account_name'];
            $account_id = (int)$acc['id'];
            break;
        }
    }
    // If no specific account was named in speech, default to first user account
    if (!$account_id && $first_acc_id) {
        $account_id = $first_acc_id;
        $account = $first_acc_name;
    }

    // 2. Type Detection (Income vs Expense)
    $type = 'expense'; // Default
    $is_income_keyword = preg_match('/(received|receive|recive|recived|got|credited|credit|salary|income|plus|added|add|\+|aavak|malyu|mila|mili|given by|pocket money|deposit|deposited)/i', $input);
    $is_expense_keyword = preg_match('/(spent|paid|buy|bought|kharch|kharchu|kharchyu|apyu|kharcho|bill|pay|given|gave|sent|debited|withdrawn|out)/i', $input);

    if ($is_income_keyword && !$is_expense_keyword) {
        $type = 'income';
    } else if ($is_expense_keyword) {
        $type = 'expense';
    } else if (preg_match('/\bfrom\b/i', $input) && !preg_match('/\b(to|for|at|on|paid|spent|bought)\b/i', $input)) {
        $type = 'income';
    }

    // 3. Amount Extraction (support symbols, words, typos like ruppys, k/L shorthand, dates filtering)
    $amount = 0;
    // 1) Match number with explicit currency symbol or word
    if (preg_match('/(?:rs\.?|inr|usd|eur|gbp|[$€£₹])\s*([\d,.]+(?:\s*[kK]|\s*lakh|\s*lac)?)|([\d,.]+(?:\s*[kK]|\s*lakh|\s*lac)?)\s*(?:rupees|ruppes|ruppys|rupeas|rupee|inr|usd|eur|gbp|dollars|bucks|rs\.?|[$€£₹])/i', $input, $matches)) {
        $raw = $matches[1] ?: $matches[2];
        $raw = trim(str_ireplace([' ', ','], ['', ''], $raw));
        if (preg_match('/([\d.]+)k$/i', $raw, $mm)) {
            $amount = (float)$mm[1] * 1000;
        } else if (preg_match('/([\d.]+)(?:lakh|lac)$/i', $raw, $mm)) {
            $amount = (float)$mm[1] * 100000;
        } else {
            $amount = (float)$raw;
        }
    } 
    // 2) Fallback: filter numbers (excluding ordinal dates e.g. 24th or 4-digit years)
    if ($amount == 0) {
        if (preg_match_all('/\b\d+(?:\.\d+)?(?:\s*(?:k|K|lakh|lac))?\b/i', $input, $all_matches)) {
            $candidates = [];
            foreach ($all_matches[0] as $numStr) {
                // Ignore date ordinals e.g., 24th, 1st
                if (preg_match('/' . preg_quote($numStr, '/') . '\s*(?:st|nd|rd|th)/i', $input)) {
                    continue;
                }
                $val = str_ireplace([' ', ','], ['', ''], $numStr);
                if (preg_match('/([\d.]+)k$/i', $val, $mm)) {
                    $candidates[] = (float)$mm[1] * 1000;
                } else if (preg_match('/([\d.]+)(?:lakh|lac)$/i', $val, $mm)) {
                    $candidates[] = (float)$mm[1] * 100000;
                } else {
                    $cVal = (float)$val;
                    if ($cVal >= 1990 && $cVal <= 2099 && count($all_matches[0]) > 1) {
                        continue; // skip year number if multiple numbers present
                    }
                    $candidates[] = $cVal;
                }
            }
            if (!empty($candidates)) {
                $amount = max($candidates);
            }
        }
    }

    // 4. Currency Detection
    $currency = 'INR';
    if (preg_match('/usd|dollar|bucks|\$/i', $input)) $currency = 'USD';
    elseif (preg_match('/eur|€/i', $input)) $currency = 'EUR';
    elseif (preg_match('/gbp|£/i', $input)) $currency = 'GBP';

    // 5. Category Mapping (Exact option values matching add.php select element)
    $category_map = [
        'Food' => ['food', 'restaurant', 'dinner', 'lunch', 'breakfast', 'eat', 'swiggy', 'zomato', 'pizza', 'burger', 'tea', 'coffee', 'chai', 'nashta', 'nasto', 'hotel', 'cafe', 'groceries', 'grocery'],
        'Fuel' => ['fuel', 'petrol', 'diesel', 'gas', 'travel', 'cab', 'uber', 'ola', 'taxi', 'auto', 'bus', 'train', 'flight', 'ticket'],
        'Shopping' => ['shopping', 'amazon', 'flipkart', 'myntra', 'mart', 'store', 'clothes', 'buy', 'bought', 'dukan', 'mall', 'shop'],
        'Income' => ['income', 'salary', 'bonus', 'dividend', 'interest', 'aavak', 'father', 'mother', 'family', 'parent', 'received', 'recive', 'recived', 'receive', 'got', 'malyu', 'mila', 'mili', 'pocket money', 'gift'],
        'Housing' => ['housing', 'rent', 'bill', 'electricity', 'water', 'recharge', 'wifi', 'netflix', 'light bill', 'gas bill', 'maintenance'],
        'Cash' => ['cash', 'atm', 'withdrawal', 'withdrawn']
    ];

    $category = ($type === 'income') ? 'Income' : 'General';
    $custom_category = '';
    foreach ($category_map as $cat => $keywords) {
        foreach ($keywords as $key) {
            if (strpos($lower, $key) !== false) {
                $category = $cat;
                break 2;
            }
        }
    }

    // 6. Note Extraction
    $note = '';
    // Pattern 1: 'spent 500 on groceries', 'paid ₹500 for taxi', 'recive 5000 ruppys from my father'
    if (preg_match('/(?:spent|paid|received|receive|recive|recived|got|credited|debited)\s+(?:rs\.?|[$€£₹])?\s*[\d,\.]+(?:\s*(?:k|K|lakh|lac))?\s*(?:rupees|ruppes|ruppys|rupeas|rupee|inr|usd)?\s*(?:on|for|from|to|by|at|in)\s+([^,\.\n]+)/i', $input, $m)) {
        $note = trim($m[1]);
    }
    // Pattern 2: 'from father', 'for groceries', 'at BigMart', 'to brother'
    else if (preg_match('/(?:for|at|to|from|by|in|on)\s+([^,\.\n\d]+)/i', $input, $m)) {
        $note = trim($m[1]);
    }
    // Pattern 3: capture remaining words after action verbs
    else if (preg_match('/(?:paid|spent|received|receive|recive|recived|kharch|kharchu|kharchyu|apyu|aavak|malyu|mila)\s+([^,\.\d]{3,})/i', $input, $m)) {
        $note = trim($m[1]);
    }

    if (empty($note)) {
        $note = $input;
    }

    // Clean filler words from beginning of note
    $note = preg_replace('/^(?:rupees|ruppes|ruppys|rupeas|rupee|rs\.?|inr|usd|my|a|an|the)\s+/i', '', $note);
    $note = trim($note);
    if (empty($note)) {
        $note = 'Transaction';
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'account' => $account,
            'account_id' => $account_id,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'date_time' => date('Y-m-d\TH:i'),
            'category' => $category,
            'custom_category' => $custom_category,
            'note' => ucfirst($note)
        ]
    ]);
    exit;
}
?>
