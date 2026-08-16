<?php

$currentPage = basename($_SERVER['PHP_SELF']);
$pageName = str_replace('.php', '', $currentPage);

$authPages = ['login', 'register', 'lock', 'forgot_password', 'reset_password'];
$mainNavPages = ['index', 'add', 'accounts', 'report', 'recurring', 'help'];

$isAuthPage = in_array($pageName, $authPages);
$isMainPage = in_array($pageName, $mainNavPages);
?>


<div id="installModal" class="install-modal glass-modal" style="display: none;">
    <div class="install-content">
        <div class="install-icon">
            <img src="<?= ASSETS_URL ?>icons/icon.svg" alt="App Icon">
        </div>
        <div class="install-text">
            <h4>Install Cash Book</h4>
            <p>Faster access & works offline</p>
        </div>
    </div>
    <div style="display: flex; gap: 0.5rem; margin-left: auto; align-items: center;">
        <button id="installBtn" class="btn btn-sm" style="width: auto; white-space: nowrap;">Install</button>
        <button id="closeInstallBtn" class="btn-icon" style="color: var(--text-muted);"><i class="fa-solid fa-times"></i></button>
    </div>
</div>

<!-- Global Sync Indicator Placeholder -->
<div id="pendingSyncCount" class="pending-badge" style="display: none; position: fixed; top: 10px; right: 10px; z-index: 10002;" title="Pending Sync">0</div>


<header class="<?= $isAuthPage ? 'auth-header-wrapper' : 'app-header' ?> notranslate">
    <?php if ($isMainPage): ?>
        <a href="index" style="text-decoration: none; color: inherit; display: block;">
            <div class="header-left">
                <div class="header-label"><i class="fa-solid fa-wallet"></i> Cash Book</div>
                <div class="header-welcome">
                    <?= $currentPage === 'index.php' ? 'Welcome, ' . htmlspecialchars($_SESSION['user_name'] ?? 'User') : ($pageTitle ?? 'Manage Finances') ?>
                </div>
            </div>
        </a>
        <div class="header-actions">
            <?php if ($currentPage === 'index.php'): ?>
                <a href="javascript:void(0)" onclick="openSearch()" class="header-btn-icon" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></a>
                <a href="javascript:void(0)" onclick="exportToPDF()" class="header-btn-icon hide-mobile" aria-label="Export PDF"><i class="fa-solid fa-file-pdf"></i></a>
            <?php endif; ?>
            <a href="#" id="themeToggle" class="header-btn-icon" aria-label="Toggle Theme"><i class="fa-solid fa-moon"></i></a>
            <a href="settings" class="header-btn-icon hide-mobile" aria-label="Settings"><i class="fa-solid fa-gear"></i></a>
            <a href="logout" onclick="return confirm('Are you sure you want to logout?')" class="header-btn-icon" aria-label="Logout"><i class="fa-solid fa-sign-out-alt"></i></a>
        </div>
    <?php elseif ($isAuthPage): ?>
        <div class="auth-header">
            <a href="index" style="text-decoration: none; color: inherit;">
                <div class="auth-logo">
                    <i class="fa-solid fa-wallet"></i> Cash Book
                </div>
            </a>
        </div>
    <?php else: ?>
        <div class="header-left" style="flex-direction: row; align-items: center; gap: 1rem;">
            <a href="javascript:history.back()" class="header-btn-icon" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="header-label"><?= $pageTitle ?? 'Cash Book' ?></div>
        </div>
    <?php endif; ?>
</header>
