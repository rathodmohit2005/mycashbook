/**
 * Offline Transaction Sync Manager
 * Stores transactions locally when offline and syncs when back online
 */

class OfflineTransactionSync {
    constructor() {
        this.dbName = 'CashBookDB';
        this.dbVersion = 1;
        this.storeName = 'offlineTransactions';
        this.statusStoreName = 'syncStatus';
        this.isOnline = navigator.onLine;
        this.db = null;
        this.initDB();
        this.setupEventListeners();
    }

    // Initialize IndexedDB
    initDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.dbVersion);
            
            request.onerror = () => reject(request.error);
            request.onsuccess = () => {
                this.db = request.result;
                console.log('[OfflineSync] IndexedDB initialized');
                resolve(this.db);
            };
            
            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                // Create object store for offline transactions
                if (!db.objectStoreNames.contains(this.storeName)) {
                    db.createObjectStore(this.storeName, { keyPath: 'id', autoIncrement: true });
                }
                // Create object store for sync status
                if (!db.objectStoreNames.contains(this.statusStoreName)) {
                    db.createObjectStore(this.statusStoreName, { keyPath: 'key' });
                }
                console.log('[OfflineSync] DB schema created');
            };
        }).catch(e => console.warn('[OfflineSync] DB init failed:', e));
    }

    // Setup online/offline event listeners
    setupEventListeners() {
        window.addEventListener('online', () => {
            this.isOnline = true;
            console.log('[OfflineSync] Back online');
            this.showToast('Back online - syncing transactions...');
            this.syncPendingTransactions();
        });
        
        window.addEventListener('offline', () => {
            this.isOnline = false;
            console.log('[OfflineSync] Gone offline');
            this.showToast('Offline mode - transactions will sync when online');
        });
    }

    // Save transaction for offline use
    async saveOfflineTransaction(transactionData) {
        if (!this.db) await this.initDB();
        
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction([this.storeName], 'readwrite');
            const store = tx.objectStore(this.storeName);
            
            const offlineTx = {
                ...transactionData,
                timestamp: new Date().toISOString(),
                synced: false,
                error: null
            };
            
            const request = store.add(offlineTx);
            
            request.onerror = () => {
                console.error('[OfflineSync] Failed to save transaction:', request.error);
                reject(request.error);
            };
            
            request.onsuccess = () => {
                console.log('[OfflineSync] Transaction saved locally:', request.result);
                this.showToast('Transaction saved (will sync when online)');
                resolve(request.result);
            };
        });
    }

    // Get all pending (unsynced) transactions
    async getPendingTransactions() {
        if (!this.db) await this.initDB();
        
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction([this.storeName], 'readonly');
            const store = tx.objectStore(this.storeName);
            const request = store.getAll();
            
            request.onerror = () => reject(request.error);
            request.onsuccess = () => {
                const pending = request.result.filter(t => !t.synced);
                resolve(pending);
            };
        });
    }

    // Sync pending transactions to server
    async syncPendingTransactions() {
        if (!this.isOnline) {
            console.log('[OfflineSync] Still offline, skipping sync');
            return;
        }
        
        const pending = await this.getPendingTransactions();
        if (pending.length === 0) {
            console.log('[OfflineSync] No pending transactions to sync');
            return;
        }
        
        console.log('[OfflineSync] Syncing', pending.length, 'transaction(s)');
        
        for (const tx of pending) {
            try {
                const result = await this.uploadTransaction(tx);
                await this.markAsSynced(tx.id, result);
                this.showToast(`Synced: ${tx.note || 'Transaction'}`);
            } catch (error) {
                console.error('[OfflineSync] Sync failed for transaction:', tx.id, error);
                await this.markSyncError(tx.id, error.message);
                this.showToast(`Sync failed: ${error.message}`);
            }
        }
    }

    // Upload single transaction to server
    async uploadTransaction(transaction) {
        const response = await fetch('api?action=add_transaction_offline', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: transaction.type,
                amount: transaction.amount,
                currency: transaction.currency,
                date: transaction.date,
                note: transaction.note,
                category: transaction.category,
                account_id: transaction.account_id,
                csrf_token: this.getCsrfToken()
            })
        });
        
        if (!response.ok) {
            throw new Error(`Server error: ${response.status}`);
        }
        
        return await response.json();
    }

    // Mark transaction as synced
    async markAsSynced(txId, serverResponse) {
        if (!this.db) await this.initDB();
        
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction([this.storeName], 'readwrite');
            const store = tx.objectStore(this.storeName);
            
            const getRequest = store.get(txId);
            getRequest.onsuccess = () => {
                const record = getRequest.result;
                record.synced = true;
                record.serverResponse = serverResponse;
                record.syncedAt = new Date().toISOString();
                
                const updateRequest = store.put(record);
                updateRequest.onsuccess = () => resolve();
                updateRequest.onerror = () => reject(updateRequest.error);
            };
        });
    }

    // Mark transaction sync error
    async markSyncError(txId, errorMsg) {
        if (!this.db) await this.initDB();
        
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction([this.storeName], 'readwrite');
            const store = tx.objectStore(this.storeName);
            
            const getRequest = store.get(txId);
            getRequest.onsuccess = () => {
                const record = getRequest.result;
                record.error = errorMsg;
                record.syncAttempts = (record.syncAttempts || 0) + 1;
                
                const updateRequest = store.put(record);
                updateRequest.onsuccess = () => resolve();
                updateRequest.onerror = () => reject(updateRequest.error);
            };
        });
    }

    // Get CSRF token from page
    getCsrfToken() {
        const token = document.querySelector('input[name="csrf_token"]');
        return token ? token.value : '';
    }

    // Show toast notification
    showToast(message) {
        // Create a simple toast if not already in DOM
        let toast = document.getElementById('offlineToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'offlineToast';
            toast.style.cssText = `
                position: fixed;
                bottom: 20px;
                left: 20px;
                background: #333;
                color: white;
                padding: 12px 16px;
                border-radius: 4px;
                z-index: 9999;
                font-size: 14px;
            `;
            document.body.appendChild(toast);
        }
        
        toast.textContent = message;
        toast.style.display = 'block';
        
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }

    // Export for testing
    async getStoredTransactionCount() {
        if (!this.db) await this.initDB();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction([this.storeName], 'readonly');
            const store = tx.objectStore(this.storeName);
            const request = store.count();
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);
        });
    }
}

// Initialize on page load
let offlineSync = null;
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        offlineSync = new OfflineTransactionSync();
    });
} else {
    offlineSync = new OfflineTransactionSync();
}
