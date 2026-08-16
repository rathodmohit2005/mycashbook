<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php'; // Use shared functions
require_once __DIR__ . '/auth.php';
requireLogin();

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';

// Process recurring transactions on dashboard load
processRecurringTransactions($conn, $user_id);

// Run auto-backup check
$backup_done = runAutoBackup($conn, $user_id);

// Resolve account filter early so balance queries can be scoped
$filter_account = (int)($_GET['account_id'] ?? 0);

// Helper: build WHERE clause for balance queries
if ($filter_account) {
    $bal_where = "type = ? AND user_id = ? AND account_id = ?";
    $bal_in_params  = ['cash in',  $user_id, $filter_account];
    $bal_out_params = ['cash out', $user_id, $filter_account];
    $bal_types_sii  = "sii";
} else {
    $bal_where = "type = ? AND user_id = ?";
    $bal_in_params  = ['cash in',  $user_id];
    $bal_out_params = ['cash out', $user_id];
    $bal_types_sii  = "si";
}

// Calculate Totals (scoped to selected account or all)
$stmt_in = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE $bal_where");
$stmt_in->bind_param($bal_types_sii, ...$bal_in_params);
$stmt_in->execute();
$total_income = $stmt_in->get_result()->fetch_assoc()['total'] ?? 0;

$stmt_out = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE $bal_where");
$stmt_out->bind_param($bal_types_sii, ...$bal_out_params);
$stmt_out->execute();
$total_expense = $stmt_out->get_result()->fetch_assoc()['total'] ?? 0;

$balance = $total_income - $total_expense;

// Get default currency for balance display
$stmt_pref = $conn->prepare("SELECT default_currency FROM users WHERE id = ?");
$stmt_pref->bind_param("i", $user_id);
$stmt_pref->execute();
$default_cur_code = $stmt_pref->get_result()->fetch_assoc()['default_currency'] ?? 'INR';
$default_symbol = getCurrencySymbol($default_cur_code);

// Part 24: Budget Tracking logic
$stmt_bud = $conn->prepare("SELECT monthly_budget FROM users WHERE id = ?");
$stmt_bud->bind_param("i", $user_id);
$stmt_bud->execute();
$monthly_budget = $stmt_bud->get_result()->fetch_assoc()['monthly_budget'] ?? 0;

$current_month = date('Y-m');
if ($filter_account) {
    $stmt_month_exp = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE user_id = ? AND type = 'cash out' AND DATE_FORMAT(date, '%Y-%m') = ? AND account_id = ?");
    $stmt_month_exp->bind_param("isi", $user_id, $current_month, $filter_account);
} else {
    $stmt_month_exp = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE user_id = ? AND type = 'cash out' AND DATE_FORMAT(date, '%Y-%m') = ?");
    $stmt_month_exp->bind_param("is", $user_id, $current_month);
}
$stmt_month_exp->execute();
$month_expenses = $stmt_month_exp->get_result()->fetch_assoc()['total'] ?? 0;

$budget_percent = 0;
if ($monthly_budget > 0) {
    $budget_percent = min(100, ($month_expenses / $monthly_budget) * 100);
}
$budget_color_class = '';
if ($budget_percent >= 100) $budget_color_class = 'danger';
elseif ($budget_percent >= 80) $budget_color_class = 'warning';

// Filter variables (account_id already set above)
$filter_note    = $_GET['note'] ?? '';
$filter_type    = $_GET['type'] ?? '';
$filter_start   = $_GET['start'] ?? '';
$filter_end     = $_GET['end'] ?? '';

// Normalize dates for SQL
if ($filter_start) $filter_start = date('Y-m-d', strtotime($filter_start));
if ($filter_end) $filter_end = date('Y-m-d', strtotime($filter_end));

// Fetch all accounts for tab bar
$stmt_acc = $conn->prepare("SELECT id, account_name, icon FROM accounts WHERE user_id = ? ORDER BY created_at ASC");
$stmt_acc->bind_param("i", $user_id);
$stmt_acc->execute();
$user_accounts = $stmt_acc->get_result()->fetch_all(MYSQLI_ASSOC);

// Build Fetch Transactions Query
$query = "SELECT * FROM transactions WHERE user_id = ?";
$types = "i";
$params = [$user_id];

if ($filter_account) {
    $query .= " AND account_id = ?";
    $types .= "i";
    $params[] = $filter_account;
}
if ($filter_note) {
    $query .= " AND note LIKE ?";
    $types .= "s";
    $params[] = '%' . $filter_note . '%';
}
if ($filter_type && in_array($filter_type, ['cash in', 'cash out'])) {
    $query .= " AND type = ?";
    $types .= "s";
    $params[] = $filter_type;
}
if ($filter_start) {
    $query .= " AND DATE(date) >= DATE(?)";
    $types .= "s";
    $params[] = $filter_start;
}
if ($filter_end) {
    $query .= " AND DATE(date) <= DATE(?)";
    $types .= "s";
    $params[] = $filter_end;
}

$query .= " ORDER BY date DESC, id DESC LIMIT 50";

$stmt_trans = $conn->prepare($query);
$stmt_trans->bind_param($types, ...$params);
$stmt_trans->execute();
$transactions = $stmt_trans->get_result();

// --- Backup Reminder Logic ---
$stmt_bkp = $conn->prepare("SELECT backup_interval, last_backup_date FROM backup_settings WHERE user_id = ?");
$stmt_bkp->bind_param("i", $user_id);
$stmt_bkp->execute();
$bkp_settings = $stmt_bkp->get_result()->fetch_assoc();

$backup_due = false;
if ($bkp_settings && $bkp_settings['backup_interval'] > 0) {
    if (!$bkp_settings['last_backup_date']) {
        $backup_due = true;
    } else {
        $last = new DateTime($bkp_settings['last_backup_date']);
        $diff = (new DateTime())->diff($last)->days;
        if ($diff >= $bkp_settings['backup_interval']) {
            $backup_due = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/index.css">
    <link rel="manifest" href="manifest">
    <meta name="theme-color" content="#6366f1">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="CashBook">
    <link rel="icon" href="<?= ASSETS_URL ?>icons/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= ASSETS_URL ?>icons/icon-192.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- PDF Export Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script>
        // Backup Reminder JS
        document.addEventListener('DOMContentLoaded', () => {
            const backupDue = <?= $backup_due ? 'true' : 'false' ?>;
            if (backupDue) {
                // 1. Browser Notification
                if ("Notification" in window) {
                    if (Notification.permission === "granted") {
                        showNotification();
                    } else if (Notification.permission !== "denied") {
                        Notification.requestPermission().then(permission => {
                            if (permission === "granted") showNotification();
                        });
                    }
                }
            }

            function showNotification() {
                new Notification("Backup Due", {
                    body: "Your backup is due. Please backup your data to keep it safe.",
                    icon: "assets/icons/icon.svg"
                });
            }
        });
    </script>
    <style>
        /* ── Account Switcher Bar ── */
        .acct-bar {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1.25rem;
        }
        .acct-switcher-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.925rem;
            font-weight: 600;
            text-align: left;
        }
        .acct-switcher-btn:active { transform: scale(0.98); }
        .acct-sw-left { display: flex; align-items: center; gap: 0.65rem; }
        .acct-sw-icon {
            width: 34px; height: 34px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #8b5cf6);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 0.85rem; flex-shrink: 0;
        }
        .acct-sw-icon.all-ico { background: linear-gradient(135deg, #64748b, #94a3b8); }
        .acct-manage-link {
            display: flex; align-items: center; justify-content: center;
            width: 38px; height: 38px; flex-shrink: 0;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-muted);
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.9rem;
        }
        .acct-manage-link:hover { color: var(--primary); border-color: var(--primary); }

        /* ── Bottom Sheet ── */
        .acct-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 900;
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
        }
        .acct-overlay.open { 
            display: flex; 
            align-items: flex-end; /* Mobile: Bottom sheet */
        }
        @media (min-width: 992px) {
            .acct-overlay.open {
                align-items: center; /* Desktop: Centered modal */
                justify-content: center;
            }
        }
        .acct-sheet {
            background: var(--surface);
            border-radius: 20px 20px 0 0;
            width: 100%; max-height: 85vh;
            overflow-y: auto; 
            padding: 1.5rem 1.25rem 2rem; /* Default padding */
            animation: sheetUp 0.22s cubic-bezier(0.32,0.72,0,1);
        }
        @media (max-width: 991px) {
            .acct-sheet {
                padding-bottom: calc(6rem + env(safe-area-inset-bottom)); /* Mobile only bottom padding */
            }
        }
        @media (min-width: 992px) {
            .acct-sheet {
                width: 500px;
                border-radius: var(--radius-lg);
                padding: 2rem;
                box-shadow: var(--shadow-lg);
                animation: modalScale 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            }
        }
        @keyframes modalScale {
            from { transform: scale(0.9); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }
        @keyframes sheetUp {
            from { transform: translateY(100%); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }
        .acct-sheet-handle {
            width: 40px; height: 4px;
            background: var(--border);
            border-radius: 2px;
            margin: 0 auto 1.25rem;
        }
        @media (min-width: 992px) {
            .acct-sheet-handle { display: none; }
        }
        .acct-sheet-hdr {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.1rem;
        }
        .acct-sheet-hdr h3 { margin: 0; font-size: 1.05rem; }
        .acct-row {
            display: flex; align-items: center; gap: 0.85rem;
            padding: 0.8rem 0.9rem;
            border-radius: var(--radius-md);
            margin-bottom: 0.35rem;
            text-decoration: none; color: inherit;
            transition: var(--transition);
            cursor: pointer;
        }
        .acct-row:hover  { background: rgba(99,102,241,0.07); }
        .acct-row.sel {
            background: rgba(99,102,241,0.12);
            border: 1px solid rgba(99,102,241,0.25);
        }
        .acct-row-icon {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #8b5cf6);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1rem; flex-shrink: 0;
        }
        .acct-row-icon.ico-all { background: linear-gradient(135deg, #64748b, #94a3b8); }
        .acct-row-name { font-weight: 600; font-size: 0.925rem; }
        .acct-row-sub  { font-size: 0.75rem; color: var(--text-muted); }
        .acct-check { margin-left: auto; color: var(--primary); font-size: 1.1rem; }
        .acct-new-btn {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.8rem 0.9rem;
            border-radius: var(--radius-md);
            border: 2px dashed var(--border);
            margin-top: 0.6rem;
            color: var(--primary); font-weight: 600; font-size: 0.9rem;
            text-decoration: none;
            transition: var(--transition);
        }
        .acct-new-btn:hover { background: rgba(99,102,241,0.06); border-color: var(--primary); }
    </style>
</head>

    <?php include 'header.php'; ?>

    <div class="container" style="position: relative; z-index: 20;">
        <?php 
        $msg = $_SESSION['message'] ?? '';
        $msgType = $_SESSION['messageType'] ?? 'success';
        if ($msg): 
            unset($_SESSION['message']);
            unset($_SESSION['messageType']);
        ?>
            <div class="alert alert-<?= $msgType ?> flash-message" style="font-size: 0.9rem; padding: 0.8rem 1rem; margin-bottom: 1rem; border-radius: 12px; display: flex; align-items: center; gap: 0.75rem; animation: slideDown 0.3s ease-out; box-shadow: var(--shadow-md);">
                <i class="fa-solid <?= $msgType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i> 
                <span><?= htmlspecialchars($msg) ?></span>
            </div>
            <script>
                setTimeout(() => {
                    const msg = document.querySelector('.flash-message');
                    if (msg) {
                        msg.style.opacity = '0';
                        msg.style.transform = 'translateY(-10px)';
                        setTimeout(() => msg.remove(), 300);
                    }
                }, 3000);
            </script>
        <?php endif; ?>

        <?php if (isset($backup_done) && $backup_done): ?>
            <div class="alert alert-success" style="font-size: 0.85rem; padding: 0.6rem 1rem; margin-bottom: 1rem; border-radius: 12px; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-cloud-check"></i> Backup completed
            </div>
        <?php endif; ?>

        <!-- Account Switcher -->
        <?php
        $cur_acct = null;
        if ($filter_account && !empty($user_accounts)) {
            foreach ($user_accounts as $a) {
                if ($a['id'] == $filter_account) { $cur_acct = $a; break; }
            }
        }
        ?>
        <div class="acct-bar">
            <button class="acct-switcher-btn" onclick="openSheet()">
                <div class="acct-sw-left">
                    <div class="acct-sw-icon <?= !$cur_acct ? 'all-ico' : '' ?>">
                        <i class="fa-solid <?= $cur_acct ? htmlspecialchars($cur_acct['icon']) : 'fa-layer-group' ?>"></i>
                    </div>
                    <div>
                        <div style="font-weight:700; font-size:0.95rem;">
                            <?= $cur_acct ? htmlspecialchars($cur_acct['account_name']) : 'All Accounts' ?>
                        </div>
                        <div style="font-size:0.72rem; color:var(--text-muted); font-weight:400;">Tap to switch account</div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); font-size:0.8rem;"></i>
            </button>
            <a href="accounts" class="acct-manage-link" title="Manage Accounts">
                <i class="fa-solid fa-sliders"></i>
            </a>
        </div>

        <!-- Backup Status Badge -->
        <?php if ($bkp_settings && $bkp_settings['last_backup_date']): ?>
            <div style="display: flex; justify-content: flex-end; margin-top: -0.5rem; margin-bottom: 1rem;">
                <div style="background: rgba(var(--primary-rgb), 0.05); border: 1px solid var(--border); padding: 4px 10px; border-radius: 20px; font-size: 0.68rem; color: var(--text-muted); display: flex; align-items: center; gap: 4px;">
                    <i class="fa-solid fa-cloud-check" style="color: var(--success); font-size: 0.75rem;"></i>
                    Last Backup: <span style="font-weight: 700; color: var(--text-main);"><?= date('M j, Y', strtotime($bkp_settings['last_backup_date'])) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Account Bottom Sheet -->
        <div class="acct-overlay" id="acctOverlay" onclick="overlayClick(event)">
            <div class="acct-sheet">
                <div class="acct-sheet-handle"></div>
                <div class="acct-sheet-hdr">
                    <h3>Switch Account</h3>
                    <button class="btn-icon" onclick="closeSheet()"><i class="fa-solid fa-times"></i></button>
                </div>

                <!-- All Accounts option -->
                <a href="index" class="acct-row <?= !$filter_account ? 'sel' : '' ?>">
                    <div class="acct-row-icon ico-all"><i class="fa-solid fa-layer-group"></i></div>
                    <div>
                        <div class="acct-row-name">All Accounts</div>
                        <div class="acct-row-sub">Show every transaction</div>
                    </div>
                    <?php if (!$filter_account): ?>
                        <i class="fa-solid fa-circle-check acct-check"></i>
                    <?php endif; ?>
                </a>

                <?php foreach ($user_accounts as $acc): ?>
                <a href="index?account_id=<?= $acc['id'] ?>" class="acct-row <?= $filter_account == $acc['id'] ? 'sel' : '' ?>">
                    <div class="acct-row-icon"><i class="fa-solid <?= htmlspecialchars($acc['icon']) ?>"></i></div>
                    <div>
                        <div class="acct-row-name"><?= htmlspecialchars($acc['account_name']) ?></div>
                    </div>
                    <?php if ($filter_account == $acc['id']): ?>
                        <i class="fa-solid fa-circle-check acct-check"></i>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>

                <a href="accounts" class="acct-new-btn">
                    <i class="fa-solid fa-plus-circle"></i> Create New Account
                </a>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="balance-card" style="grid-column: 1 / -1;">
                <div class="balance-label">TOTAL BALANCE (<?= $default_cur_code ?>)</div>
                <div class="balance-amount"><?= $default_symbol ?><?= number_format($balance, 2) ?></div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                <!-- Income/Expense Stats -->
                <div class="stats-grid" style="margin-bottom: 0;">
                    <div class="stat-card income">
                        <div class="label" style="color:var(--success); font-weight: 600;"><i class="fa-solid fa-arrow-down"></i> CASH IN</div>
                        <div class="amount">+<?= $default_symbol ?><?= number_format($total_income, 2) ?></div>
                    </div>
                    <div class="stat-card expense">
                        <div class="label" style="color:var(--danger); font-weight: 600;"><i class="fa-solid fa-arrow-up"></i> CASH OUT</div>
                        <div class="amount">-<?= $default_symbol ?><?= number_format($total_expense, 2) ?></div>
                    </div>
                </div>

                <?php if ($monthly_budget > 0): ?>
                    <!-- Budget Tracking Section -->
                    <div class="budget-container" style="margin-bottom: 0;">
                        <div class="budget-header">
                            <span class="budget-title">Monthly Budget</span>
                            <span class="budget-stats"><?= $default_symbol ?><?= number_format($month_expenses, 2) ?> / <?= $default_symbol ?><?= number_format($monthly_budget, 2) ?></span>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill <?= $budget_color_class ?>" style="width: <?= $budget_percent ?>%;"></div>
                        </div>
                        <div class="budget-footer">
                            <span><?= round($budget_percent) ?>% spent</span>
                            <span><?= $default_symbol ?><?= number_format(max(0, $monthly_budget - $month_expenses), 2) ?> left</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($monthly_budget > 0): ?>
            <?php if ($month_expenses >= $monthly_budget): ?>
                <div class="alert alert-danger" style="margin-top: -1rem; margin-bottom: 2rem;">
                    <i class="fa-solid fa-circle-exclamation"></i> <strong>Budget Exceeded!</strong> You have spent more than your monthly limit.
                </div>
            <?php elseif ($month_expenses >= $monthly_budget * 0.8): ?>
                <div class="alert alert-warning" style="margin-top: -1rem; margin-bottom: 2rem; background-color: rgba(245, 158, 11, 0.1); color: #b45309; border: 1px solid rgba(245, 158, 11, 0.2);">
                    <i class="fa-solid fa-triangle-exclamation"></i> <strong>Approaching Limit!</strong> You have used <?= round($budget_percent) ?>% of your budget.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Recent Transactions -->
        <div class="section-header" style="margin-bottom: 1rem;">
            <h2 class="section-title">Recent Transactions</h2>
        </div>

        <?php if ($transactions->num_rows > 0): ?>
            <ul class="transaction-list">
                <?php while ($row = $transactions->fetch_assoc()): ?>
                    <?php
                    $isIn = $row['type'] === 'cash in';
                    $iconClass = $isIn ? 'fa-arrow-down' : 'fa-arrow-up';
                    $typeClass = $isIn ? 'in' : 'out';
                    $dateFormatted = date('M j, Y, g:i A', strtotime($row['date']));
                    ?>
                    <li class="transaction-item" onclick="window.location.href='add?id=<?= $row['id'] ?>'" style="cursor: pointer;">
                        <div class="transaction-details">
                            <div class="t-icon <?= $typeClass ?>">
                                <i class="fa-solid <?= $iconClass ?>"></i>
                            </div>
                            <div class="t-info">
                                <h4><?= htmlspecialchars($row['note'] ?: 'Transaction') ?></h4>
                                <p><?= $dateFormatted ?> &bull; <span class="tag-category"><?= htmlspecialchars($row['category'] ?? 'General') ?></span></p>
                            </div>
                        </div>
                        <div class="t-amount <?= $typeClass ?>">
                            <?= $isIn ? '+' : '-' ?><?= getCurrencySymbol($row['currency']) ?><?= number_format($row['amount'], 2) ?>
                        </div>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa-solid fa-receipt fa-3x" style="color:var(--text-muted); opacity: 0.5; margin-bottom: 1rem;"></i>
                <p>No transactions found.</p>
                <p>Tap the + button to add one.</p>
            </div>
        <?php endif; ?>

    </div>

    <!-- Floating Action Group -->
    <div class="fab-group">
        <button class="voice-fab" onclick="toggleAssistant()" aria-label="Voice Assistant"><div class="pulse-ring"></div><i class="fa-solid fa-microphone"></i></button>
        <a href="add" class="fab" aria-label="Add Transaction"><i class="fa-solid fa-plus"></i></a>
    </div>

    <!-- Voice Assistant Overlay is loaded globally in nav.php -->

    <!-- Filter Modal -->
    <div id="filterModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Filter Transactions</h3>
                <button onclick="closeSearch()" class="btn-icon"><i class="fa-solid fa-times"></i></button>
            </div>
            <form id="filterForm" action="" method="GET" style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
                <div class="search-group">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="note" class="form-control" placeholder="Search note..." value="<?= htmlspecialchars($filter_note) ?>">
                </div>

                <div class="filter-row">
                    <div style="position: relative;">
                        <label style="font-size: 0.7rem; color: var(--text-muted); position: absolute; top: -15px; left: 5px;">From</label>
                        <input type="date" name="start" class="form-control" value="<?= htmlspecialchars($filter_start) ?>" style="padding: 0.5rem; font-size: 0.85rem;">
                    </div>
                    <div style="position: relative;">
                        <label style="font-size: 0.7rem; color: var(--text-muted); position: absolute; top: -15px; left: 5px;">To</label>
                        <input type="date" name="end" class="form-control" value="<?= htmlspecialchars($filter_end) ?>" style="padding: 0.5rem; font-size: 0.85rem;">
                    </div>
                </div>

                <div class="type-selector" style="margin-bottom: 0;">
                    <button type="button" class="type-option all <?= !$filter_type ? 'active' : '' ?>" data-type="">All</button>
                    <button type="button" class="type-option in <?= $filter_type === 'cash in' ? 'active' : '' ?>" data-type="cash in">In</button>
                    <button type="button" class="type-option out <?= $filter_type === 'cash out' ? 'active' : '' ?>" data-type="cash out">Out</button>
                    <input type="hidden" name="type" id="filter_type_input" value="<?= htmlspecialchars($filter_type) ?>">
                </div>

                <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                    <button type="submit" class="btn" style="flex: 1;">Apply Filters</button>
                    <?php if ($filter_note || $filter_type || $filter_start || $filter_end): ?>
                        <a href="index" class="btn btn-secondary" style="flex: 0 0 auto;"><i class="fa-solid fa-rotate-left"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php require_once 'nav.php'; ?>
    <script src="assets/js/app.js?v=2" defer></script>
    <script src="assets/js/index.js?v=2" defer></script>
    <script>
        // Optimistic UI for Transactions
        document.addEventListener('DOMContentLoaded', () => {
            const offlineTx = JSON.parse(localStorage.getItem('offline_transactions') || '[]');
            const list = document.querySelector('.transaction-list');
            const emptyState = document.querySelector('.empty-state');

            if (offlineTx.length > 0) {
                if (emptyState) emptyState.style.display = 'none';
                
                // If list doesn't exist (because it was empty), create it
                let listEl = list;
                if (!listEl) {
                    listEl = document.createElement('ul');
                    listEl.className = 'transaction-list';
                    document.querySelector('.section-header').after(listEl);
                }


                offlineTx.forEach(tx => {
                    const li = document.createElement('li');
                    li.className = 'transaction-item';
                    li.style.position = 'relative';
                    const isIn = tx.type === 'cash in';
                    li.innerHTML = '<div class="transaction-details">' +
                        '<div class="t-icon ' + (isIn ? 'in' : 'out') + '">' +
                            '<i class="fa-solid ' + (isIn ? 'fa-arrow-down' : 'fa-arrow-up') + '"></i>' +
                        '</div>' +
                        '<div class="t-info">' +
                            '<h4>' + tx.note + ' <span style="font-size:0.6rem; background:var(--primary); color:white; padding:2px 6px; border-radius:10px; margin-left:5px;"><i class="fa-solid fa-rotate"></i> Pending</span></h4>' +
                            '<p>' + new Date(tx.created_at || tx.date).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) + ' &bull; <span class="tag-category">' + tx.category + '</span></p>' +
                        '</div>' +
                    '</div>' +
                    '<div class="t-amount ' + (isIn ? 'in' : 'out') + '">' +
                        (isIn ? '+' : '-') + (tx.currency || 'INR') + parseFloat(tx.amount).toFixed(2) +
                    '</div>';
                    listEl.insertBefore(li, listEl.firstChild);
                });

            }
        });

        function openSheet() {
            document.getElementById('acctOverlay').classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closeSheet() {
            document.getElementById('acctOverlay').classList.remove('open');
            document.body.style.overflow = '';
        }
        function overlayClick(e) {
            if (e.target === document.getElementById('acctOverlay')) closeSheet();
        }
        // Close on ESC key
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSheet(); });

        // --- FAB Long Press Logic ---
        const fab = document.querySelector('.fab');
        let fabTimer;
        if (fab) {
            const cancelHold = () => clearTimeout(fabTimer);
            fab.addEventListener('touchstart', () => {
                fabTimer = setTimeout(() => window.location.href = 'add?manual=1', 800);
            });
            fab.addEventListener('touchend', cancelHold);
            fab.addEventListener('touchmove', cancelHold);
            fab.addEventListener('mousedown', (e) => {
                if (e.button === 0) fabTimer = setTimeout(() => window.location.href = 'add?manual=1', 800);
            });
            fab.addEventListener('mouseup', cancelHold);
            fab.addEventListener('mouseleave', cancelHold);
        }
    </script>
</body>
</html>