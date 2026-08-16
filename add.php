<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
requireLogin();

// Fetch user's accounts for the selector
$stmt_accs = $conn->prepare("SELECT id, account_name, icon FROM accounts WHERE user_id = ? ORDER BY created_at ASC");
$stmt_accs->bind_param("i", $_SESSION['user_id']);
$stmt_accs->execute();
$user_accounts = $stmt_accs->get_result()->fetch_all(MYSQLI_ASSOC);

$message = '';
$messageType = '';
$edit_id = $_GET['id'] ?? null;
$tpl_id = $_GET['tpl_id'] ?? ($_GET['from_tpl'] ?? null);
$edit_data = null;
$tpl_data = null;

// If we are in Edit mode, fetch the existing transaction
if ($edit_id) {
    $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $edit_id, $_SESSION['user_id']);
    $stmt->execute();
    $edit_data = $stmt->get_result()->fetch_assoc();

    if (!$edit_data) {
        header("Location: index");
        exit;
    }
}
// If loaded from Active Schedule row, pre-fill form data
elseif ($tpl_id) {
    $stmt_tpl = $conn->prepare("SELECT * FROM recurring_templates WHERE id = ? AND user_id = ?");
    $stmt_tpl->bind_param("ii", $tpl_id, $_SESSION['user_id']);
    $stmt_tpl->execute();
    $tpl_data = $stmt_tpl->get_result()->fetch_assoc();

    if ($tpl_data) {
        // Fallback to first user account if template account_id is null
        $tpl_account_id = !empty($tpl_data['account_id']) ? (int)$tpl_data['account_id'] : (!empty($user_accounts[0]['id']) ? (int)$user_accounts[0]['id'] : null);
        $edit_data = [
            'type' => $tpl_data['type'],
            'amount' => $tpl_data['amount'],
            'note' => $tpl_data['note'],
            'category' => $tpl_data['category'],
            'currency' => $tpl_data['currency'],
            'account_id' => $tpl_account_id,
            'date' => date('Y-m-d H:i:s')
        ];
    }
}


// Handle Form Submission (Add, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $user_id = $_SESSION['user_id'];

    // Handle Delete
    if (isset($_POST['delete_transaction']) && $edit_id) {
        $stmt = $conn->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $edit_id, $user_id);
        if ($stmt->execute()) {
            $_SESSION['message'] = "Transaction deleted successfully!";
            $_SESSION['messageType'] = "success";
            header("Location: index");
            exit;
        } else {
            $message = "Error deleting transaction.";
            $messageType = "danger";
        }
    }
    // Handle Add or Update
    else {
        $type = $_POST['type'] ?? '';
        $amount = (float) ($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $date = $_POST['date'] ?? date('Y-m-d H:i:s');
        $currency = $_POST['currency'] ?? 'INR';
        $category = $_POST['category'] ?? 'General';
        if ($category === 'Others' && !empty($_POST['custom_category'])) {
            $category = trim($_POST['custom_category']);
        }
        $account_id_post = (int) ($_POST['account_id'] ?? 0);
        if ($account_id_post <= 0) {
            $account_id_post = null; // Still allow null if no account exists, but we'll validate in JS
        }
        $transaction_id = $_POST['transaction_id'] ?? null;

        if (in_array($type, ['cash in', 'cash out']) && $amount > 0) {
            if ($transaction_id) {
                // Update Existing
                $stmt = $conn->prepare("UPDATE transactions SET type = ?, amount = ?, note = ?, date = ?, category = ?, currency = ?, account_id = ? WHERE id = ? AND user_id = ?");
                $stmt->bind_param("sdssssiii", $type, $amount, $note, $date, $category, $currency, $account_id_post, $transaction_id, $user_id);
                $action_text = "updated";
            } else {
                // Insert New
                $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, note, date, category, currency, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isdssssi", $user_id, $type, $amount, $note, $date, $category, $currency, $account_id_post);
                $action_text = "saved";
            }

            if ($stmt->execute()) {
                // Handle Recurring (Only for new transactions or if you want to allow changing templates)
                if (isset($_POST['is_recurring']) && !$transaction_id) {
                    $frequency = $_POST['frequency'] ?? 'monthly';
                    $d = new DateTime($date);
                    if ($frequency === 'daily')
                        $d->modify('+1 day');
                    elseif ($frequency === 'weekly')
                        $d->modify('+1 week');
                    elseif ($frequency === 'monthly')
                        $d->modify('+1 month');
                    elseif ($frequency === 'yearly')
                        $d->modify('+1 year');
                    $next_run = $d->format('Y-m-d');

                    $tpl = $conn->prepare("INSERT INTO recurring_templates (user_id, account_id, type, amount, note, category, currency, frequency, next_run) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $tpl->bind_param("iisdsssss", $user_id, $account_id_post, $type, $amount, $note, $category, $currency, $frequency, $next_run);
                    $tpl->execute();

                }

                $_SESSION['message'] = "Transaction $action_text successfully!";
                $_SESSION['messageType'] = "success";
                if ($transaction_id) {
                    // Update — go back to dashboard
                    header("Location: index");
                    exit;
                } else {
                    // New transaction — stay on page, reset form via JS flag
                    $message = "✓ Transaction saved successfully! Add another below.";
                    $messageType = "success";
                    $reset_form = true;
                }
            } else {
                $message = "Error saving transaction.";
                $messageType = "danger";
            }
        }
    }
}

// Get default currency and auto-import confirmation preference
$stmt = $conn->prepare("SELECT default_currency, auto_import_confirm FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$userSettings = $stmt->get_result()->fetch_assoc();
$default_currency = $userSettings['default_currency'] ?? 'INR';
$confirm_auto_import = (int)($userSettings['auto_import_confirm'] ?? 1);

// Pre-fill values for edit (or blank after successful save)
$f_type     = (!empty($reset_form)) ? 'cash out'          : ($edit_data['type']     ?? 'cash out');
$f_amount   = (!empty($reset_form)) ? ''                  : ($edit_data['amount']   ?? '');
$f_note     = (!empty($reset_form)) ? ''                  : ($edit_data['note']     ?? '');
$f_date     = (!empty($reset_form)) ? date('Y-m-d\TH:i') : date('Y-m-d\TH:i', strtotime($edit_data['date'] ?? date('Y-m-d H:i')));
$f_currency = (!empty($reset_form)) ? $default_currency   : ($edit_data['currency'] ?? $default_currency);
$f_category = (!empty($reset_form)) ? 'General'           : ($edit_data['category'] ?? 'General');
$f_account_id = (!empty($reset_form)) ? 0 : (int)($edit_data['account_id'] ?? 0);
$pageTitle = $edit_id ? 'Edit' : 'Smart Add';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $edit_id ? 'Edit' : 'Add' ?> Transaction - Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="manifest">
    <meta name="theme-color" content="#6366f1">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-secondary: #f1f5f9;
        }

        body.dark-mode {
            --bg-secondary: #1e293b;
        }

        .smart-input-section {
            background: var(--bg-secondary);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .smart-input-section label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .sms-textarea {
            width: 100%;
            height: 100px;
            border-radius: var(--radius-md);
            padding: 0.875rem;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.95rem;
            resize: none;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        .sms-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .btn-smart {
            padding: 1rem;
            font-size: 1rem;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-weight: 600;
        }

        .btn-voice {
            background-color: var(--primary);
            color: white;
            border: none;
        }

        .btn-detect {
            background-color: var(--success);
            color: white;
            border: none;
        }

        .btn-smart:active {
            transform: scale(0.98);
        }

        .voice-active {
            animation: pulse-red 1.5s infinite;
            background-color: var(--danger) !important;
        }

        @keyframes pulse-red {
            0% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
            }

            70% {
                box-shadow: 0 0 0 15px rgba(239, 68, 68, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
            }
        }

        .form-card {
            background: var(--surface);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }

        .section-divider {
            height: 1px;
            background: var(--border);
            margin: 2rem 0;
            position: relative;
        }

        .section-divider span {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--bg-color);
            padding: 0 1rem;
            color: var(--text-muted);
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .required-asterisk {
            color: #ef4444;
            margin-left: 2px;
        }

        .error-message {
            color: #ef4444;
            font-size: 0.75rem;
            margin-top: 4px;
            display: none;
        }
    </style>
</head>

<body>

    <?php include 'header.php'; ?>

    <div class="container" style="padding-top: 1rem;">

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (!$edit_id): ?>
            <!-- Smart Input Section -->
            <div class="smart-input-section">
                <label><i class="fa-solid fa-wand-magic-sparkles"></i> Smart Detection</label>
                <textarea id="smartInput" class="sms-textarea" placeholder="Paste SMS here or use Voice..."></textarea>
                <div class="action-buttons">
                    <button type="button" id="voiceBtn" class="btn-smart btn-voice">
                        <i class="fa-solid fa-microphone"></i> Voice
                    </button>
                    <button type="button" onclick="processSmartInput()" class="btn-smart btn-detect">
                        <i class="fa-solid fa-bolt"></i> Auto Fill
                    </button>
                </div>
            </div>

            <div class="section-divider"><span>Manual Form</span></div>
        <?php endif; ?>

        <div class="form-card">
            <form action="add<?= $edit_id ? '?id=' . $edit_id : '' ?>" method="POST" id="transactionForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <?php if ($edit_id): ?>
                    <input type="hidden" name="transaction_id" value="<?= $edit_id ?>">
                <?php endif; ?>

                <input type="hidden" name="raw_source" id="raw_source" value="<?= htmlspecialchars($_GET['text'] ?? '') ?>">
                <input type="hidden" name="source_type" id="source_type" value="<?= isset($_GET['text']) ? 'share' : 'manual' ?>">
                <input type="hidden" name="source_reference" id="source_reference" value="">
                <input type="hidden" name="merchant_name" id="merchant_name" value="">
                <input type="hidden" name="upi_id" id="upi_id" value="">

                <!-- Account Selector -->
                <?php if (!empty($user_accounts)): ?>
                    <div class="form-group">
                        <label class="form-label" for="account_id">Account / Book <span class="required-asterisk">*</span></label>
                        <select name="account_id" id="account_id" class="form-control" required>
                            <option value="">— Select Account —</option>
                            <?php foreach ($user_accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>" <?= $f_account_id == $acc['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($acc['account_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning" style="margin-bottom:1rem; font-size:0.875rem;">
                        <i class="fa-solid fa-circle-info"></i>
                        No accounts yet. <a href="accounts" style="color:var(--primary); font-weight:600;">Create one
                            first</a>.
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Transaction Type <span class="required-asterisk">*</span></label>
                    <select name="type" id="type" class="form-control">
                        <option value="cash out" <?= $f_type === 'cash out' ? 'selected' : '' ?>>Cash Out (-)</option>
                        <option value="cash in" <?= $f_type === 'cash in' ? 'selected' : '' ?>>Cash In (+)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="amount">Amount (<?= htmlspecialchars($f_currency) ?>) <span class="required-asterisk">*</span></label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <span style="font-weight: 600; font-size: 1.1rem; color: var(--text-muted); padding: 0 0.5rem;">
                            <?= htmlspecialchars($f_currency) ?>
                        </span>
                        <input type="hidden" name="currency" value="<?= htmlspecialchars($f_currency) ?>">
                        <input type="number" name="amount" id="amount" class="form-control" step="any" min="0"
                            placeholder="0.00" value="<?= $f_amount ?>" style="flex: 1;">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="date">Date & Time <span class="required-asterisk">*</span></label>
                    <input type="datetime-local" name="date" id="date" class="form-control" value="<?= $f_date ?>"
                        required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="note">Note / Description <span class="required-asterisk">*</span></label>
                    <input type="text" name="note" id="note" class="form-control" placeholder="e.g. Amazon, Salary"
                        value="<?= htmlspecialchars($f_note) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="category">Category</label>
                    <select name="category" id="category" class="form-control" onchange="toggleCustomCategory()">
                        <option value="General" <?= $f_category === 'General' ? 'selected' : '' ?>>General</option>
                        <option value="Shopping" <?= $f_category === 'Shopping' ? 'selected' : '' ?>>Shopping</option>
                        <option value="Fuel" <?= $f_category === 'Fuel' ? 'selected' : '' ?>>Fuel</option>
                        <option value="Food" <?= $f_category === 'Food' ? 'selected' : '' ?>>Food</option>
                        <option value="Cash" <?= $f_category === 'Cash' ? 'selected' : '' ?>>Cash / ATM</option>
                        <option value="Income" <?= $f_category === 'Income' ? 'selected' : '' ?>>Income / Salary</option>
                        <option value="Housing" <?= $f_category === 'Housing' ? 'selected' : '' ?>>Housing</option>
                        <option value="Others" <?= $f_category === 'Others' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <div id="customCatGroup" style="display: none; margin-top: 1rem; animation: slideDown 0.3s ease;">
                    <label class="form-label">Custom Category Name</label>
                    <input type="text" name="custom_category" id="custom_category" class="form-control"
                        placeholder="e.g. Donation, Hobby">
                </div>

                <?php if (!$edit_id): ?>
                    <div class="form-group"
                        style="margin-top: 1.5rem; padding: 1rem; background: var(--bg-secondary); border-radius: var(--radius-md);">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <label class="form-label" style="margin-bottom: 0;">Make Recurring?</label>
                            <input type="checkbox" name="is_recurring" id="is_recurring" <?= !empty($tpl_data) ? 'checked' : '' ?>
                                onchange="document.getElementById('frequency_box').style.display = this.checked ? 'block' : 'none'">
                        </div>
                        <div id="frequency_box" style="display: <?= !empty($tpl_data) ? 'block' : 'none' ?>; margin-top: 1rem;">
                            <select name="frequency" class="form-control">
                                <option value="daily" <?= ($tpl_data['frequency'] ?? '') === 'daily' ? 'selected' : '' ?>>Daily</option>
                                <option value="weekly" <?= ($tpl_data['frequency'] ?? '') === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                <option value="monthly" <?= ($tpl_data['frequency'] ?? 'monthly') === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="yearly" <?= ($tpl_data['frequency'] ?? '') === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <div
                    style="display: grid; grid-template-columns: <?= $edit_id ? '1fr 1fr' : '1fr' ?>; gap: 1rem; margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary" style="padding: 1rem; font-size: 1.1rem;" onclick="return <?= $edit_id ? "confirm('Save changes to this transaction?')" : "true" ?>">
                        <i class="fa-solid fa-floppy-disk"></i> <?= $edit_id ? 'Update' : 'Save' ?>
                    </button>
                    <?php if ($edit_id): ?>
                        <button type="submit" name="delete_transaction" class="btn"
                            style="background: var(--danger); color: white; padding: 1rem; font-size: 1.1rem;"
                            onclick="return confirm('Delete this transaction?')">
                            <i class="fa-solid fa-trash"></i> Delete
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

    </div>

    <?php require_once 'nav.php'; ?>

    <script>
        // Optimistic UI: Include offline accounts in the dropdown
        document.addEventListener('DOMContentLoaded', () => {
            const offlineAccounts = JSON.parse(localStorage.getItem('offline_accounts') || '[]');
            const accountSelect = document.getElementById('account_id');

            if (offlineAccounts.length > 0 && accountSelect) {
                offlineAccounts.forEach(acc => {
                    const opt = document.createElement('option');
                    opt.value = "OFFLINE_" + acc.offline_id; // Temporary ID
                    opt.textContent = acc.account_name + " (Pending Sync)";
                    accountSelect.appendChild(opt);
                });
            }
        });

        const userAccounts = <?= json_encode($user_accounts) ?>;
        const rawSourceInput = document.getElementById('raw_source');
        const sourceTypeInput = document.getElementById('source_type');
        const sourceReferenceInput = document.getElementById('source_reference');
        const merchantNameInput = document.getElementById('merchant_name');
        const upiIdInput = document.getElementById('upi_id');
        const autoImportConfirm = <?= $confirm_auto_import ? 'true' : 'false' ?>;

        function processSmartInput(shouldAutoSubmit = false) {
            const input = document.getElementById('smartInput').value.trim();
            if (!input) return;

            rawSourceInput.value = input;
            if (!sourceTypeInput.value || sourceTypeInput.value === 'manual') {
                sourceTypeInput.value = 'sms';
            }

            const lowerInput = input.toLowerCase();

            // A. Transaction Type
            const isIncomeKeyword = /(received|receive|recive|recived|got|credited|credit|salary|income|plus|added|add|\+|aavak|malyu|mila|mili|given by|pocket money|deposit|deposited)/i.test(input);
            const isExpenseKeyword = /(spent|paid|buy|bought|kharch|kharchu|kharchyu|apyu|kharcho|bill|pay|given|gave|sent|debited|withdrawn|out)/i.test(input);

            if (isIncomeKeyword && !isExpenseKeyword) {
                document.getElementById('type').value = 'cash in';
            } else if (isExpenseKeyword) {
                document.getElementById('type').value = 'cash out';
            } else if (/\bfrom\b/i.test(input) && !/\b(to|for|at|on|paid|spent|bought)\b/i.test(input)) {
                document.getElementById('type').value = 'cash in';
            } else {
                document.getElementById('type').value = 'cash out';
            }

            // B. Amount
            // 1. Try to find number WITH currency indicators (highest priority)
            const amtMatch = input.match(/(?:Rs\.?|INR|USD|EUR|GBP|[$€£₹])\s*([\d,.]+)|([\d,.]+)\s*(?:rupees|ruppes|ruppys|rupeas|rupee|INR|USD|EUR|GBP|dollars|bucks|rs\.?|[$€£₹])/i);
            
            if (amtMatch) {
                let amtStr = (amtMatch[1] || amtMatch[2]).replace(/,/g, '');
                const cleanAmt = amtStr.split('.')[0]; 
                document.getElementById('amount').value = cleanAmt;
            } else {
                // 2. Fallback: Find numbers but ignore those that look like dates (e.g. 24th, 1st)
                // We'll look for the largest number that isn't a date-day
                const numbers = input.match(/\d+/g);
                if (numbers) {
                    // Filter out numbers followed by th, st, nd, rd
                    const candidates = numbers.filter(n => {
                        const idx = input.indexOf(n);
                        const after = input.substring(idx + n.length, idx + n.length + 2).toLowerCase();
                        return !['th', 'st', 'nd', 'rd'].includes(after);
                    });
                    
                    if (candidates.length > 0) {
                        // Pick the largest candidate as the likely amount
                        const largest = Math.max(...candidates.map(Number));
                        document.getElementById('amount').value = largest;
                    } else {
                        document.getElementById('amount').value = numbers[0];
                    }
                }
            }

            // C. Currency (Auto-detect removed, using fixed account currency)

            // D. Date
            // 1. Check for specific date in brackets (24-04-2026)
            const dateMatch = input.match(/\((\d{1,2}-\d{1,2}-\d{4})/);
            if (dateMatch) {
                const parts = dateMatch[1].split('-');
                const formattedDate = parts[2] + '-' + parts[1].padStart(2, '0') + '-' + parts[0].padStart(2, '0') + 'T' + new Date().getHours().toString().padStart(2, '0') + ':' + new Date().getMinutes().toString().padStart(2, '0');
                document.getElementById('date').value = formattedDate;
            } 
            // 2. Natural Language: "30 October", "October 30", "30th Oct"
            else {
                const months = ['january','february','march','april','may','june','july','august','september','october','november','december'];
                const shortMonths = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
                
                let foundDate = false;
                
                // Regex for "30 October" or "30th Oct"
                const pattern1 = new RegExp('(\\d{1,2})(?:st|nd|rd|th)?\\s+(' + months.join('|') + '|' + shortMonths.join('|') + ')', 'i');
                // Regex for "October 30" or "Oct 30th"
                const pattern2 = new RegExp('(' + months.join('|') + '|' + shortMonths.join('|') + ')\\s+(\\d{1,2})(?:st|nd|rd|th)?', 'i');
                
                const m1 = input.match(pattern1);
                const m2 = input.match(pattern2);
                
                let day, monthName;
                if (m1) { day = m1[1]; monthName = m1[2].toLowerCase(); }
                else if (m2) { day = m2[2]; monthName = m2[1].toLowerCase(); }
                
                if (day && monthName) {
                    let monthIdx = months.indexOf(monthName);
                    if (monthIdx === -1) monthIdx = shortMonths.indexOf(monthName);
                    
                    if (monthIdx !== -1) {
                        const year = new Date().getFullYear();
                        const nowTime = new Date().toTimeString().slice(0, 5);
                        const formattedDate = year + '-' + String(monthIdx + 1).padStart(2, '0') + '-' + day.padStart(2, '0') + 'T' + nowTime;
                        document.getElementById('date').value = formattedDate;
                        foundDate = true;
                    }
                }
                
                if (!foundDate) {
                    if (/today/i.test(input)) {
                        document.getElementById('date').value = new Date().toISOString().slice(0, 16);
                    } else if (/yesterday/i.test(input)) {
                        const d = new Date();
                        d.setDate(d.getDate() - 1);
                        document.getElementById('date').value = d.toISOString().slice(0, 16);
                    }
                }
            }

            // E. Note / Description
            // Look for "from [Name]", "to [Name]", "for [Item]", "at [Store]", "in [Place]"
            const notePattern = /(?:from|to|for|at|on|in|by|with|paid to|sent to)\s+([^,.\d]+)/i;
            const noteMatch = input.match(notePattern);
            if (noteMatch) {
                document.getElementById('note').value = noteMatch[1].trim();
            } else {
                // Fallback: search between keywords
                const altMatch = input.match(/at\s+(.+?)(?:\s+Ref|\s+TID|[\(\[])/i);
                if (altMatch) {
                    document.getElementById('note').value = altMatch[1].trim();
                } else if (/salary/i.test(input)) {
                    document.getElementById('note').value = 'Salary';
                } else {
                    document.getElementById('note').value = 'Transaction';
                }
            }

            // E.1 Merchant / UPI details
            const upiMatch = input.match(/([a-zA-Z0-9.+\-_]{2,}@[a-zA-Z0-9\-\.]{2,})/);
            if (upiMatch) {
                upiIdInput.value = upiMatch[1];
                merchantNameInput.value = merchantNameInput.value || upiMatch[1];
            }
            merchantNameInput.value = merchantNameInput.value || document.getElementById('note').value;

            // F. Category (Auto Detect)
            const noteText = document.getElementById('note').value.toLowerCase();
            const catSelect = document.getElementById('category');
            
            if (/amazon|flipkart|shopping|store|myntra/i.test(noteText) || /amazon|flipkart/i.test(input)) {
                catSelect.value = 'Shopping';
            } else if (/petrol|fuel|diesel|gas/i.test(noteText) || /petrol|fuel/i.test(input)) {
                catSelect.value = 'Fuel';
            } else if (/swiggy|zomato|food|restaurant|dinner|lunch|breakfast|eat/i.test(noteText) || /swiggy|zomato|food/i.test(input)) {
                catSelect.value = 'Food';
            } else if (/atm|cash/i.test(noteText) || /atm/i.test(input)) {
                catSelect.value = 'Cash';
            } else if (/salary|income|bonus|interest|father|mother|family|parent|received|recive|receive|got/i.test(noteText) || /salary|income|father|mother/i.test(input) || document.getElementById('type').value === 'cash in') {
                catSelect.value = 'Income';
            } else if (/rent|electricity|water|wifi|bill/i.test(noteText)) {
                catSelect.value = 'Housing';
            } else {
                catSelect.value = 'General';
            }

            // G. Account (Auto Detect from Voice/Text)
            if (typeof userAccounts !== 'undefined' && userAccounts && userAccounts.length > 0) {
                const sortedAccounts = [...userAccounts].sort((a, b) => b.account_name.length - a.account_name.length);
                let matched = false;
                for (const acc of sortedAccounts) {
                    const accName = acc.account_name.toLowerCase();
                    if (lowerInput.includes(accName)) {
                        const accSelect = document.getElementById('account_id');
                        if (accSelect) {
                            accSelect.value = acc.id;
                            matched = true;
                        }
                        break;
                    }
                }
                const accSelect = document.getElementById('account_id');
                if (accSelect && !accSelect.value) {
                    accSelect.value = userAccounts[0].id;
                }
            }

            // Visual Feedback
            document.getElementById('smartInput').style.borderColor = 'var(--success)';
            setTimeout(() => {
                document.getElementById('smartInput').style.borderColor = 'var(--border)';
            }, 1000);

            if (shouldAutoSubmit) {
                const amount = parseFloat(document.getElementById('amount').value);
                const noteValue = document.getElementById('note').value.trim();
                const dateValue = document.getElementById('date').value;
                const account = document.getElementById('account_id').value;
                if (amount > 0 && noteValue && dateValue && account) {
                    if (autoImportConfirm) {
                        if (confirm('Auto-detected transaction looks ready. Save it now?')) {
                            transactionForm.submit();
                        }
                    } else {
                        transactionForm.submit();
                    }
                }
            }
        }

        const voiceBtn = document.getElementById('voiceBtn');
        const smartInput = document.getElementById('smartInput');

        if (voiceBtn) {
            voiceBtn.addEventListener('click', () => {
                toggleAssistant();
            });
        }

        // Auto-process if coming from Dashboard Voice Assistant
        window.addEventListener('load', () => {
            const urlParams = new URLSearchParams(window.location.search);
            
            // 1. Check for structured NLU data (High priority)
            const nluData = urlParams.get('nlu');
            if (nluData) {
                try {
                    const data = JSON.parse(nluData);
                    if (data.type) document.getElementById('type').value = data.type === 'income' ? 'cash in' : 'cash out';
                    if (data.amount) document.getElementById('amount').value = data.amount;
                    if (data.currency) {
                        const curEl = document.getElementById('currency');
                        if (curEl) curEl.value = data.currency;
                    }
                    if (data.note) document.getElementById('note').value = data.note;
                    if (data.account_id) document.getElementById('account_id').value = data.account_id;
                    if (data.date_time) document.getElementById('date').value = data.date_time;
                    
                    if (data.category) {
                        const catSelect = document.getElementById('category');
                        let exists = Array.from(catSelect.options).some(opt => opt.value === data.category);
                        if (exists) {
                            catSelect.value = data.category;
                        } else {
                            catSelect.value = 'Others';
                            toggleCustomCategory();
                            if (data.custom_category) document.getElementById('custom_category').value = data.custom_category;
                        }
                    }

                    // Visual highlight of prefilled fields
                    ['type', 'amount', 'note', 'category', 'account_id'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) {
                            el.style.borderColor = 'var(--success)';
                            setTimeout(() => el.style.borderColor = '', 1500);
                        }
                    });

                    return; // Done if NLU found
                } catch (e) { console.error("NLU Parse failed", e); }
            }

            // 2. Fallback to raw smart text processing and shared text support
            const shareText = urlParams.get('text') || urlParams.get('smart');
            if (shareText) {
                const smartInput = document.getElementById('smartInput');
                if (smartInput) {
                    smartInput.value = shareText;
                    processSmartInput(true);
                }
            }
        });
        function toggleCustomCategory() {
            const cat = document.getElementById('category').value;
            const group = document.getElementById('customCatGroup');
            group.style.display = (cat === 'Others') ? 'block' : 'none';
            if (cat === 'Others') document.getElementById('custom_category').focus();
        }

        // Initialize on load
        window.addEventListener('DOMContentLoaded', toggleCustomCategory);

        // --- Improved Form Handling (Offline First) ---
        const transactionForm = document.getElementById('transactionForm');
        
        transactionForm.addEventListener('submit', function(e) {
            let isValid = true;
            
            // 1. Reset Styles
            document.querySelectorAll('.error-message').forEach(m => m.remove());
            document.querySelectorAll('.form-control').forEach(i => i.style.borderColor = '');

            const showError = (id, msg) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.style.borderColor = 'var(--danger)';
                const group = el.closest('.form-group') || el.parentNode;
                const error = document.createElement('p');
                error.className = 'error-message';
                error.style.cssText = 'color:var(--danger);font-size:0.75rem;margin-top:5px;display:flex;align-items:center;gap:4px;';
                error.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + msg;
                group.appendChild(error);
                isValid = false;
            };

            // 2. Validation
            const accountId = document.getElementById('account_id').value;
            const amount = parseFloat(document.getElementById('amount').value);
            const date = document.getElementById('date').value;
            const note = document.getElementById('note').value.trim();

            if (!accountId) showError('account_id', 'Please select an account');
            if (isNaN(amount) || amount <= 0) showError('amount', 'Enter a valid amount > 0');
            if (!date) showError('date', 'Please select a date');
            if (!note) showError('note', 'Note is required');

            if (!isValid) {
                e.preventDefault();
                if (navigator.vibrate) navigator.vibrate(50);
                return;
            }

            // 3. Offline Logic
            if (!navigator.onLine) {
                e.preventDefault();
                
                const formData = new FormData(transactionForm);
                const data = {};
                formData.forEach((value, key) => { data[key] = value; });
                
                // Metadata for sync
                data.offline_id = Date.now();
                data.created_at = new Date().toISOString();
                
                const queue = JSON.parse(localStorage.getItem('offline_transactions') || '[]');
                queue.push(data);
                localStorage.setItem('offline_transactions', JSON.stringify(queue));
                
                // UI Feedback
                alert('Saved locally! Transaction will sync when you are back online.');
                window.location.href = 'index.php';
            }
        });


        // --- Manual Mode Detection ---
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('manual') === '1') {
                const manualForm = document.getElementById('transactionForm');
                if (manualForm) {
                    setTimeout(() => {
                        manualForm.scrollIntoView({ behavior: 'smooth' });
                        const amountInput = document.getElementById('amount');
                        if (amountInput) amountInput.focus();
                    }, 500); // Wait for page to settle
                }
            }
        });
    </script>
    <script src="assets/js/offline-sync.js" defer></script>
    <script src="assets/js/app.js?v=2" defer></script>
</body>
</html>
