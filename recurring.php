<?php
require_once __DIR__ . '/db.php';
require_once 'functions.php';
require_once __DIR__ . '/auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];

// Initial Fetch (Join with accounts for account_name)
$stmt = $conn->prepare("SELECT r.*, a.account_name FROM recurring_templates r LEFT JOIN accounts a ON r.account_id = a.id WHERE r.user_id = ? ORDER BY r.created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$templates = $stmt->get_result();
$pageTitle = 'Recurring Transactions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Recurring - Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container" style="padding-top: 1rem;">
        
        <div class="section-header">
            <h2 class="section-title">Active Schedules</h2>
        </div>

        <?php if ($templates->num_rows > 0): ?>
            <ul class="transaction-list">
                <?php while ($row = $templates->fetch_assoc()): ?>
                    <li class="transaction-item" id="tpl-<?= $row['id'] ?>" onclick="window.location.href='add?tpl_id=<?= $row['id'] ?>'" style="cursor: pointer; flex-direction: column; align-items: stretch; gap: 1rem; border-left: 4px solid <?= $row['status'] === 'active' ? 'var(--primary)' : 'var(--text-muted)' ?>;" title="Click to edit or add record">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div class="transaction-details">
                                <div class="t-icon <?= $row['type'] === 'cash in' ? 'in' : 'out' ?>">
                                    <i class="fa-solid <?= $row['type'] === 'cash in' ? 'fa-arrow-down' : 'fa-arrow-up' ?>"></i>
                                </div>
                                <div class="t-info">
                                    <h4><?= htmlspecialchars($row['note']) ?></h4>
                                    <p>
                                        Every <?= ucfirst($row['frequency']) ?> &bull; 
                                        <span class="tag-category"><?= htmlspecialchars($row['category']) ?></span> &bull;
                                        <span class="tag-category" style="background:var(--primary-light); color:var(--primary);"><i class="fa-solid fa-building-columns"></i> <?= htmlspecialchars($row['account_name'] ?? 'Default') ?></span>
                                    </p>
                                </div>
                            </div>

                            <div class="t-amount <?= $row['type'] === 'cash in' ? 'in' : 'out' ?>">
                                <?= getCurrencySymbol($row['currency']) ?><?= number_format($row['amount'], 2) ?>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); padding-top: 0.75rem;">
                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                Next: <span style="font-weight: 600; color: var(--text-main);"><?= date('M j, Y', strtotime($row['next_run'])) ?></span>
                            </div>
                            <div style="display: flex; gap: 0.5rem;" onclick="event.stopPropagation();">
                                <?php if ($row['status'] === 'active'): ?>
                                    <button onclick="event.stopPropagation(); manageTpl(<?= $row['id'] ?>, 'pause')" class="btn btn-sm" style="width: auto; background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border); padding: 0.4rem 0.8rem;">Pause</button>
                                <?php else: ?>
                                    <button onclick="event.stopPropagation(); manageTpl(<?= $row['id'] ?>, 'resume')" class="btn btn-sm" style="width: auto; background: var(--primary); padding: 0.4rem 0.8rem;">Resume</button>
                                <?php endif; ?>
                                <button onclick="event.stopPropagation(); manageTpl(<?= $row['id'] ?>, 'delete')" class="btn btn-sm" style="width: auto; background: var(--danger); padding: 0.4rem 0.8rem;"><i class="fa-solid fa-trash-can"></i></button>
                            </div>
                        </div>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa-solid fa-repeat fa-3x" style="color:var(--text-muted); opacity: 0.5; margin-bottom: 1rem;"></i>
                <p>No recurring transactions found.</p>
                <p>Check the "Make Recurring" box when adding an entry.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php require_once __DIR__ . '/nav.php'; ?>
    <script>
        async function manageTpl(id, action) {
            if (action === 'delete' && !confirm('Are you sure you want to delete this schedule?')) return;
            
            try {
                const r = await fetch('api?recurring_action=' + action + '&id=' + id);
                const json = await r.json();
                if (json.status === 'success') {
                    if (action === 'delete') {
                        document.getElementById('tpl-' + id).remove();
                    } else {
                        window.location.reload();
                    }
                }
            } catch(e) { alert('Error occurred'); }
        }
    </script>
    <script src="assets/js/app.js"></script>
</body>
</html>
