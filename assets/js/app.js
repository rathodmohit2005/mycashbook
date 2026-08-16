/**
 * Cash Book - Main App Logic
 * Version: 2.0.0
 * Includes: PWA Support, Offline Sync, Theme Toggle, Notifications, Lock Timer
 */

// --- 1. Service Worker Registration ---
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('service-worker.js', { scope: './' })
            .then(reg => {
                console.log('SW: Registered with scope:', reg.scope);
                
                // Handle updates
                reg.addEventListener('updatefound', () => {
                    const newWorker = reg.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            // New version available - you could show a "Refresh to update" toast here
                            console.log('SW: New version available');
                        }
                    });
                });
            })
            .catch(err => console.error('SW: Registration failed:', err));
    });
}

// --- 2. App State & UI Management ---
let deferredPrompt;
const APP_STORAGE_KEYS = {
    TRANSACTIONS: 'offline_transactions',
    ACCOUNTS: 'offline_accounts',
    THEME: 'theme',
    PWA_PROMPT: 'pwaPromptSkipped'
};

document.addEventListener('DOMContentLoaded', () => {
    initPWAInstallation();
    initTheme();
    initConnectivity();
    initNotifications();
    initLockTimer();
    
    // Initial sync check
    if (navigator.onLine) {
        syncOfflineData();
    }
});

// --- 3. PWA Installation ---
function initPWAInstallation() {
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        
        const installModal = document.getElementById('installModal');
        if (installModal && !localStorage.getItem(APP_STORAGE_KEYS.PWA_PROMPT)) {
            installModal.style.display = 'flex';
        }
    });

    const installBtn = document.getElementById('installBtn');
    if (installBtn) {
        installBtn.addEventListener('click', async () => {
            const installModal = document.getElementById('installModal');
            if (installModal) installModal.style.display = 'none';
            
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                console.log(`User ${outcome} the install prompt`);
                deferredPrompt = null;
            }
        });
    }

    const closeInstallBtn = document.getElementById('closeInstallBtn');
    if (closeInstallBtn) {
        closeInstallBtn.addEventListener('click', () => {
            const installModal = document.getElementById('installModal');
            if (installModal) installModal.style.display = 'none';
            localStorage.setItem(APP_STORAGE_KEYS.PWA_PROMPT, 'yes');
        });
    }

    const manualInstallBtn = document.getElementById('manualInstallBtn');
    if (manualInstallBtn) {
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        
        if (isStandalone) {
            manualInstallBtn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Already Installed';
            manualInstallBtn.classList.replace('btn-primary', 'btn-secondary');
            manualInstallBtn.disabled = true;
        }

        manualInstallBtn.addEventListener('click', async () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt = null;
            } else {
                alert("To install:\n1. Android: Tap 3 dots > 'Install app'\n2. iOS: Tap Share > 'Add to Home Screen'");
            }
        });
    }
}

// --- 4. Theme Management ---
function initTheme() {
    const currentTheme = localStorage.getItem(APP_STORAGE_KEYS.THEME);
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const isDark = currentTheme === 'dark' || (!currentTheme && prefersDark);

    if (isDark) {
        document.body.classList.add('dark-mode');
    } else {
        document.body.classList.remove('dark-mode');
    }

    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.innerHTML = isDark ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';

        themeToggle.addEventListener('click', (e) => {
            e.preventDefault();
            document.body.classList.toggle('dark-mode');
            const nowDark = document.body.classList.contains('dark-mode');
            localStorage.setItem(APP_STORAGE_KEYS.THEME, nowDark ? 'dark' : 'light');
            themeToggle.innerHTML = nowDark ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
        });
    }
}


// --- 5. Connectivity & Sync ---
function initConnectivity() {
    const updateStatus = () => {
        const isOnline = navigator.onLine;
        let badge = document.getElementById('offlineBadge');
        
        if (!isOnline) {
            if (!badge) {
                badge = document.createElement('div');
                badge.id = 'offlineBadge';
                badge.className = 'offline-banner';
                badge.innerHTML = '<i class="fa-solid fa-plane"></i> Offline Mode';
                document.body.appendChild(badge);
            }
        } else {
            if (badge) {
                badge.classList.add('online');
                badge.innerHTML = '<i class="fa-solid fa-wifi"></i> Back Online';
                setTimeout(() => badge.remove(), 3000);
            }
            syncOfflineData();
        }
        
        // Update pending counts on UI
        updatePendingCounts();
    };

    window.addEventListener('online', updateStatus);
    window.addEventListener('offline', updateStatus);
    updateStatus();
}

function updatePendingCounts() {
    const transactions = JSON.parse(localStorage.getItem(APP_STORAGE_KEYS.TRANSACTIONS) || '[]');
    const accounts = JSON.parse(localStorage.getItem(APP_STORAGE_KEYS.ACCOUNTS) || '[]');
    const total = transactions.length + accounts.length;
    
    const countBadge = document.getElementById('pendingSyncCount');
    if (countBadge) {
        if (total > 0) {
            countBadge.style.display = 'inline-flex';
            countBadge.textContent = total;
        } else {
            countBadge.style.display = 'none';
        }
    }
}

async function syncOfflineData() {
    const offlineAccounts = JSON.parse(localStorage.getItem(APP_STORAGE_KEYS.ACCOUNTS) || '[]');
    const offlineTransactions = JSON.parse(localStorage.getItem(APP_STORAGE_KEYS.TRANSACTIONS) || '[]');
    
    if (offlineAccounts.length === 0 && offlineTransactions.length === 0) return;

    // Prevention: Don't start multiple syncs
    if (window.isSyncing) return;
    window.isSyncing = true;

    console.log(`[Sync] Starting sync for ${offlineAccounts.length} accounts and ${offlineTransactions.length} transactions`);
    
    showSyncIndicator(`Syncing ${offlineAccounts.length + offlineTransactions.length} items...`);

    try {
        // 1. Sync Accounts First
        let idMap = {};
        if (offlineAccounts.length > 0) {
            const accRes = await fetch('api.php?action=sync_offline_accounts', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ accounts: offlineAccounts })
            });
            const accData = await accRes.json();
            if (accData.status === 'success') {
                localStorage.removeItem(APP_STORAGE_KEYS.ACCOUNTS);
                idMap = accData.id_map || {};
                
                // Map offline account IDs in transactions to new real IDs
                offlineTransactions.forEach(tx => {
                    if (tx.account_id && String(tx.account_id).startsWith('OFFLINE_')) {
                        const offId = tx.account_id.replace('OFFLINE_', '');
                        if (idMap[offId]) {
                            tx.account_id = idMap[offId];
                        }
                    }
                });
            }
        }

        // 2. Sync Transactions
        if (offlineTransactions.length > 0) {
            const txRes = await fetch('api.php?action=sync_offline', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ transactions: offlineTransactions })
            });
            const txData = await txRes.json();
            if (txData.status === 'success') {
                localStorage.removeItem(APP_STORAGE_KEYS.TRANSACTIONS);
            }
        }

        showSyncIndicator('Sync Complete!', 'success');
        
        // Reload specific pages to reflect new data
        const path = window.location.pathname;
        if (path.includes('index') || path.includes('accounts') || path.includes('report')) {
            setTimeout(() => window.location.reload(), 2000);
        }
    } catch (error) {
        console.error('[Sync] Failed:', error);
        showSyncIndicator('Sync failed. Will retry later.', 'error');
    } finally {
        window.isSyncing = false;
        updatePendingCounts();
        setTimeout(hideSyncIndicator, 3000);
    }
}

function showSyncIndicator(msg, type = 'info') {
    let indicator = document.getElementById('syncIndicator');
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'syncIndicator';
        document.body.appendChild(indicator);
    }
    
    indicator.className = `sync-indicator ${type}`;
    indicator.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-rotate fa-spin')}"></i> ${msg}`;
    indicator.style.display = 'flex';
}

function hideSyncIndicator() {
    const indicator = document.getElementById('syncIndicator');
    if (indicator) indicator.style.display = 'none';
}

// --- 6. Notifications & Reminders ---
function initNotifications() {
    if ("Notification" in window && Notification.permission !== "granted" && Notification.permission !== "denied") {
        // Option to request permission could be in Settings
    }

    // Daily Reminder Check
    setInterval(() => {
        const targetTime = localStorage.getItem('cashbook_reminder_time');
        if (!targetTime) return;

        const now = new Date();
        const currentTime = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
        const todayKey = now.toISOString().slice(0, 10);

        if (currentTime === targetTime && sessionStorage.getItem('reminder_fired_' + todayKey) !== 'true') { 
            if (Notification.permission === "granted") {
                new Notification("Cash Book", {
                    body: "Don't forget to add today's transactions!",
                    icon: "assets/icons/icon.svg"
                });
                sessionStorage.setItem('reminder_fired_' + todayKey, "true");
            }
        }
    }, 30000); // Check every 30s
}

// --- 7. Security (Lock Timer) ---
function initLockTimer() {
    let inactivityTimer;
    const LOCK_TIMEOUT = 10 * 60 * 1000; // 10 Minutes

    function resetTimer() {
        clearTimeout(inactivityTimer);
        const path = window.location.pathname;
        if (path.includes('login') || path.includes('register') || path.includes('lock')) return;

        inactivityTimer = setTimeout(async () => {
            try {
                if (navigator.onLine) {
                    await fetch('api.php?action=lock');
                    window.location.href = 'lock.php';
                }
            } catch(e) {}
        }, LOCK_TIMEOUT);
    }

    ['mousemove', 'keypress', 'click', 'scroll', 'touchstart'].forEach(evt => {
        window.addEventListener(evt, resetTimer);
    });
    resetTimer();
}

// --- 8. Helper Functions ---
function getCurrencySymbol(code) {
    const symbols = { 'INR': '₹', 'USD': '$', 'EUR': '€', 'GBP': '£' };
    return symbols[code] || code;
}

window.CashBook = {
    sync: syncOfflineData,
    updatePending: updatePendingCounts
};
