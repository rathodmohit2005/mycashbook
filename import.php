<?php
require_once 'db.php';
require_once 'auth.php';
requireLogin();

$message = '';
$messageType = '';
$importResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    checkCSRF();
    
    $files = $_FILES['csv_file'];
    $successCount = 0;
    $failedCount = 0;
    $duplicateCount = 0;
    $user_id = $_SESSION['user_id'];

    // Pre-fetch accounts once
    $stmt_acc = $conn->prepare("SELECT id, account_name FROM accounts WHERE user_id = ?");
    $stmt_acc->bind_param("i", $user_id);
    $stmt_acc->execute();
    $res_acc = $stmt_acc->get_result();
    $accounts_map = [];
    while ($row = $res_acc->fetch_assoc()) {
        $accounts_map[strtolower(trim($row['account_name']))] = $row['id'];
    }

    // Prepare common statements
    $stmt_ins = $conn->prepare("INSERT INTO transactions (user_id, type, amount, currency, date, note, category, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_dup = $conn->prepare("SELECT id FROM transactions WHERE user_id = ? AND type = ? AND amount = ? AND date = ? AND note = ? AND (account_id = ? OR (account_id IS NULL AND ? IS NULL)) LIMIT 1");

    // Loop through each uploaded file
    $fileCount = is_array($files['name']) ? count($files['name']) : 0;
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
            if (strtolower($ext) === 'csv') {
                if (($handle = fopen($files['tmp_name'][$i], "r")) !== FALSE) {
                    $headers = fgetcsv($handle, 1000, ",");
                    $mapping = ['type' => -1, 'amount' => -1, 'currency' => -1, 'date' => -1, 'note' => -1, 'category' => -1, 'account' => -1];

                    if ($headers) {
                        foreach ($headers as $index => $col) {
                            $col = strtolower(trim($col));
                            if (strpos($col, 'type') !== false) $mapping['type'] = $index;
                            elseif (strpos($col, 'amount') !== false) $mapping['amount'] = $index;
                            elseif (strpos($col, 'currency') !== false) $mapping['currency'] = $index;
                            elseif (strpos($col, 'date') !== false) $mapping['date'] = $index;
                            elseif (strpos($col, 'note') !== false || strpos($col, 'desc') !== false) $mapping['note'] = $index;
                            elseif (strpos($col, 'cat') !== false) $mapping['category'] = $index;
                            elseif (strpos($col, 'acc') !== false) $mapping['account'] = $index;
                        }
                    }

                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        if (empty(array_filter($data))) continue;
                        
                        $raw_type     = ($mapping['type']     !== -1) ? ($data[$mapping['type']]     ?? '') : '';
                        $raw_amount   = ($mapping['amount']   !== -1) ? ($data[$mapping['amount']]   ?? '0') : '0';
                        $raw_currency = ($mapping['currency'] !== -1) ? ($data[$mapping['currency']] ?? 'INR') : 'INR';
                        $raw_date     = ($mapping['date']     !== -1) ? ($data[$mapping['date']]     ?? '') : '';
                        $raw_note     = ($mapping['note']     !== -1) ? ($data[$mapping['note']]     ?? '') : '';
                        $raw_category = ($mapping['category'] !== -1) ? ($data[$mapping['category']] ?? 'General') : 'General';
                        $raw_account  = ($mapping['account']  !== -1) ? ($data[$mapping['account']]  ?? '') : '';

                        $amount = (float)preg_replace('/[^0-9.]/', '', $raw_amount);
                        $currency = substr(trim(strtoupper($raw_currency)), 0, 10) ?: 'INR';
                        $note = trim($raw_note);
                        $category = trim($raw_category) ?: 'General';
                        
                        $type = '';
                        $t_clean = strtolower(trim($raw_type));
                        if (in_array($t_clean, ['cash out', 'debit', 'out', 'expense', 'dr', 'debited'])) $type = 'cash out';
                        elseif (in_array($t_clean, ['cash in', 'credit', 'in', 'income', 'cr', 'credited'])) $type = 'cash in';

                        $dateObj = date_create($raw_date);
                        $date = $dateObj ? $dateObj->format('Y-m-d') : null;

                        if ($amount <= 0 || !$type || !$date) {
                            $failedCount++;
                            continue;
                        }

                        $account_id = null;
                        if ($raw_account !== '') {
                            $acc_key = strtolower(trim($raw_account));
                            if (isset($accounts_map[$acc_key])) {
                                $account_id = $accounts_map[$acc_key];
                            } else {
                                $ins_acc = $conn->prepare("INSERT INTO accounts (user_id, account_name) VALUES (?, ?)");
                                $ins_acc->bind_param("is", $user_id, $raw_account);
                                if ($ins_acc->execute()) {
                                    $account_id = $ins_acc->insert_id;
                                    $accounts_map[$acc_key] = $account_id;
                                }
                            }
                        }

                        $stmt_dup->bind_param("isdssii", $user_id, $type, $amount, $date, $note, $account_id, $account_id);
                        $stmt_dup->execute();
                        if ($stmt_dup->get_result()->num_rows > 0) {
                            $duplicateCount++;
                            continue;
                        }

                        $stmt_ins->bind_param("isdssssi", $user_id, $type, $amount, $currency, $date, $note, $category, $account_id);
                        if ($stmt_ins->execute()) $successCount++;
                        else $failedCount++;
                    }
                    fclose($handle);
                }
            }
        }
    }

    $importResults = [
        'success'    => $successCount,
        'failed'     => $failedCount,
        'duplicates' => $duplicateCount
    ];

    if ($successCount > 0) {
        $message = "Import complete! Aggregated results from $fileCount files shown below.";
        $messageType = "success";
    } else {
        $message = "Import failed or no new transactions found in $fileCount files.";
        $messageType = "danger";
    }
}

$pageTitle = 'Restore Backup';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Restore Backup - Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($importResults): ?>
            <div class="stat-card" style="margin-bottom: 2rem; border-left: 4px solid var(--primary);">
                <h3 style="margin-top: 0;"><i class="fa-solid fa-list-check"></i> Combined Import Results</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">Imported</div>
                        <div style="font-size: 1.25rem; font-weight: 700; color: var(--success);"><?= $importResults['success'] ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">Duplicates</div>
                        <div style="font-size: 1.25rem; font-weight: 700; color: #f59e0b;"><?= $importResults['duplicates'] ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">Failed/Invalid</div>
                        <div style="font-size: 1.25rem; font-weight: 700; color: var(--danger);"><?= $importResults['failed'] ?></div>
                    </div>
                </div>
                <div style="margin-top: 1.5rem;">
                    <a href="index" class="btn btn-sm">Go to Dashboard</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="section-header">
            <h2 class="section-title"><i class="fa-solid fa-upload"></i> Upload CSV Backups</h2>
        </div>
        
        <div class="stat-card" style="margin-bottom: 2rem;">
            <p style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Select one or more CSV files to restore your transactions. The system will automatically aggregate results and skip duplicates.
            </p>
            
            <form action="import" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label class="form-label">Choose CSV Files:</label>
                    <input type="file" name="csv_file[]" class="form-control" accept=".csv" multiple required style="padding: 0.5rem;">
                </div>
                <button type="submit" class="btn"><i class="fa-solid fa-file-import" style="margin-right: 0.5rem"></i> Restore Now</button>
            </form>
        </div>

        <div class="stat-card">
            <h4 style="margin-top: 0;"><i class="fa-solid fa-circle-info" style="color: var(--primary);"></i> Formatting Tips</h4>
            <ul style="font-size: 0.825rem; color: var(--text-muted); padding-left: 1.25rem; line-height: 1.6;">
                <li>Headers like "Date", "Amount", "Type", and "Note" are used for automatic column mapping.</li>
                <li>"Type" should be "Cash In" or "Cash Out" (or synonyms like "Credit/Debit").</li>
                <li>If a "Category" or "Account" isn't recognized, it will be automatically handled.</li>
                <li>Duplicates are skipped based on matching date, amount, type, and note.</li>
            </ul>
        </div>
    </div>

    <?php require_once 'nav.php'; ?>
    <script src="assets/js/app.js"></script>
    <script>
        document.querySelector('form').addEventListener('submit', function(e) {
            if (!navigator.onLine) {
                e.preventDefault();
                const fileInput = document.querySelector('input[type="file"]');
                if (fileInput.files.length > 0) {
                    for (let i = 0; i < fileInput.files.length; i++) {
                        handleOfflineImport(fileInput.files[i]);
                    }
                }
            }
        });
    </script>
</body>
</html>
