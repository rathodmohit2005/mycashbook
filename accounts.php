<?php
require_once 'db.php';
require_once 'auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    if (($_POST['action'] ?? '') === 'create') {
        $name = trim($_POST['account_name'] ?? '');
        $icon = $_POST['icon'] ?? 'fa-wallet';
        if (!empty($name)) {
            $stmt = $conn->prepare("INSERT INTO accounts (user_id, account_name, icon) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $user_id, $name, $icon);
            $stmt->execute()
                ? ($message = "Account \"" . htmlspecialchars($name) . "\" created!")
                  && ($messageType = "success")
                : ($message = "Error creating account.") && ($messageType = "danger");
        } else {
            $message = "Account name cannot be empty.";
            $messageType = "danger";
        }
    } elseif (($_POST['action'] ?? '') === 'delete') {
        $del_id = (int)($_POST['delete_id'] ?? 0);
        
        // 1. Delete associated transactions first
        $stmt_tx = $conn->prepare("DELETE FROM transactions WHERE account_id = ? AND user_id = ?");
        $stmt_tx->bind_param("ii", $del_id, $user_id);
        $stmt_tx->execute();

        // 2. Delete the account itself
        $stmt = $conn->prepare("DELETE FROM accounts WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $del_id, $user_id);
        $stmt->execute();
        
        header("Location: accounts");
        exit;
    }
}

// Fetch accounts with transaction count
$stmt = $conn->prepare("
    SELECT a.id, a.account_name, a.icon, a.created_at,
           COUNT(t.id) AS tx_count
    FROM accounts a
    LEFT JOIN transactions t ON t.account_id = a.id
    WHERE a.user_id = ?
    GROUP BY a.id
    ORDER BY a.created_at ASC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$accounts = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Accounts - Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="manifest">
    <meta name="theme-color" content="#6366f1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .account-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            text-decoration: none;
            color: inherit;
        }
        .account-card:active { transform: scale(0.98); }
        .acc-icon {
            width: 46px; height: 46px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #8b5cf6);
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.1rem; flex-shrink: 0;
        }
        .acc-info { flex: 1; margin-left: 1rem; }
        .acc-info h4 { margin: 0 0 0.2rem; font-size: 1rem; font-weight: 600; }
        .acc-info p  { margin: 0; font-size: 0.8rem; color: var(--text-muted); }
        
        .icon-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        .icon-opt {
            aspect-ratio: 1;
            border-radius: var(--radius-md);
            border: 2px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 1.1rem;
            color: var(--text-muted);
            transition: var(--transition);
        }
        .icon-opt.selected {
            border-color: var(--primary);
            background: rgba(99,102,241,0.1);
            color: var(--primary);
        }

        /* Desktop Layout Refinement */
        @media (min-width: 992px) {
            .accounts-grid {
                display: grid;
                grid-template-columns: 400px 1fr;
                gap: 2.5rem;
                align-items: start;
            }
            .stat-card {
                position: sticky;
                top: 6rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

        <div class="accounts-grid">
            <!-- Left Side: Create Account -->
            <div>
                <div class="section-header">
                    <h2 class="section-title"><i class="fa-solid fa-plus-circle"></i> New Account</h2>
                </div>
                <div class="stat-card" style="margin-bottom: 2rem;">
                    <form action="accounts" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="icon" id="selectedIcon" value="fa-wallet">

                        <div class="form-group">
                            <label class="form-label">Account Name</label>
                            <input type="text" name="account_name" class="form-control"
                                   placeholder="e.g. Kotak Bank, Online Cash, Wallet"
                                   required maxlength="50">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Choose Icon</label>
                            <div class="icon-grid">
                                <?php
                                $icons = [
                                    'fa-wallet'              => 'Wallet',
                                    'fa-building-columns'    => 'Bank',
                                    'fa-money-bill-wave'     => 'Cash',
                                    'fa-piggy-bank'          => 'Savings',
                                    'fa-briefcase'           => 'Business',
                                    'fa-credit-card'         => 'Card',
                                    'fa-coins'               => 'Coins',
                                    'fa-mobile-screen'       => 'Online',
                                    'fa-chart-line'          => 'Investment',
                                    'fa-hand-holding-dollar' => 'Loan',
                                ];
                                foreach ($icons as $cls => $label): ?>
                                    <div class="icon-opt <?= $cls === 'fa-wallet' ? 'selected' : '' ?>"
                                         onclick="pickIcon('<?= $cls ?>', this)" title="<?= $label ?>">
                                        <i class="fa-solid <?= $cls ?>"></i>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" class="btn" style="margin-top: 1rem;">
                            <i class="fa-solid fa-plus"></i> Create Account
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right Side: Account List -->
            <div>
                <div class="section-header">
                    <h2 class="section-title"><i class="fa-solid fa-layer-group"></i> Your Accounts</h2>
                </div>

                <div id="accountsList" style="display: flex; flex-direction: column; gap: 0.75rem;">
                <?php if ($accounts->num_rows > 0):
                    while ($acc = $accounts->fetch_assoc()): ?>
                    <div class="account-card">
                        <a href="index?account_id=<?= $acc['id'] ?>"
                           style="display:flex; align-items:center; flex:1; text-decoration:none; color:inherit;">
                            <div class="acc-icon">
                                <i class="fa-solid <?= htmlspecialchars($acc['icon']) ?>"></i>
                            </div>
                            <div class="acc-info">
                                <h4><?= htmlspecialchars($acc['account_name']) ?></h4>
                                <p><?= $acc['tx_count'] ?> transaction<?= $acc['tx_count'] != 1 ? 's' : '' ?>
                                   &bull; Created <?= date('M j, Y', strtotime($acc['created_at'])) ?></p>
                            </div>
                        </a>
                        <form action="accounts" method="POST" style="margin-left:0.75rem;"
                              onsubmit="return confirm('Delete this account? WARNING: All transactions inside this account will also be deleted forever!')">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="delete_id" value="<?= $acc['id'] ?>">
                            <button type="submit" class="btn-icon" style="color: var(--danger);">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                <?php endwhile; ?>
                </div>
                <?php
                else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-layer-group fa-3x"
                           style="color:var(--text-muted); opacity:0.4; margin-bottom:1rem;"></i>
                        <p>No accounts yet.</p>
                        <p>Create your first account above to get started.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <?php require_once 'nav.php'; ?>
    <script src="assets/js/app.js"></script>
    <script>
        // Optimistic UI: Show offline accounts in the list
        document.addEventListener('DOMContentLoaded', () => {
            const offlineAccounts = JSON.parse(localStorage.getItem('offline_accounts') || '[]');
            const listContainer = document.getElementById('accountsList');
            
            if (offlineAccounts.length > 0) {
                offlineAccounts.forEach(acc => {
                    const div = document.createElement('div');
                    div.className = 'account-card';
                    div.innerHTML = '<div style="display:flex; align-items:center; flex:1;">' +
                        '<div class="acc-icon">' +
                            '<i class="fa-solid ' + acc.icon + '"></i>' +
                        '</div>' +
                        '<div class="acc-info">' +
                            '<h4>' + acc.account_name + '</h4>' +
                            '<p>0 transactions &bull; Created Just Now</p>' +
                        '</div>' +
                    '</div>' +
                    '<div style="margin-left:0.75rem;">' +
                        '<button class="btn-icon" style="color: var(--danger);" onclick="alert(\'This offline account will be deleted locally on sync.\')">' +
                            '<i class="fa-solid fa-trash"></i>' +
                        '</button>' +
                    '</div>';
                    // Insert before the empty state or at the top of the list
                    const firstCard = document.querySelector('.account-card');
                    if (firstCard) {
                        firstCard.parentNode.insertBefore(div, firstCard);
                    } else {
                        const emptyState = document.querySelector('.empty-state');
                        if (emptyState) emptyState.style.display = 'none';
                        listContainer.appendChild(div);
                    }
                });
            }
        });

        function pickIcon(cls, el) {
            document.querySelectorAll('.icon-opt').forEach(o => o.classList.remove('selected'));
            el.classList.add('selected');
            document.getElementById('selectedIcon').value = cls;
        }


        // Offline Account Submission Logic
        const accountForm = document.querySelector('form[action="accounts"]');
        if (accountForm) {
            accountForm.addEventListener('submit', (e) => {
                const action = accountForm.querySelector('input[name="action"]').value;
                if (action === 'create' && !navigator.onLine) {
                    e.preventDefault();
                    
                    const formData = new FormData(accountForm);
                    const data = {};
                    formData.forEach((value, key) => { data[key] = value; });
                    
                    data.offline_id = Date.now();
                    data.created_at = new Date().toISOString();
                    
                    const queue = JSON.parse(localStorage.getItem('offline_accounts') || '[]');
                    queue.push(data);
                    localStorage.setItem('offline_accounts', JSON.stringify(queue));
                    
                    alert('Account saved locally! It will sync when you are back online.');
                    window.location.reload();
                }
            });
        }

    </script>
</body>
</html>
