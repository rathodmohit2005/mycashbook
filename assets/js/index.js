/* =============================================
   index.js — Dashboard-specific JavaScript
   ============================================= */

// Open / Close filter search modal
function openSearch() {
    document.getElementById('filterModal').classList.add('active');
}

function closeSearch() {
    document.getElementById('filterModal').classList.remove('active');
}

// ---- Live Transaction Filter ----
document.addEventListener('DOMContentLoaded', () => {
    const searchForm = document.getElementById('filterForm');

    if (searchForm) {
        // Filter Type Selector Logic
        const typeBtns = searchForm.querySelectorAll('.type-option');
        const typeInput = document.getElementById('filter_type_input');

        typeBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                typeBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                typeInput.value = btn.dataset.type;
                triggerUpdate();
            });
        });

        const triggerUpdate = async (e) => {
            if (e) e.preventDefault();

            const fd = new FormData(searchForm);
            const q = new URLSearchParams(fd).toString();

            try {
                const r = await fetch('api?action=get_transactions&' + q);
                const json = await r.json();

                if (json.status === 'success') {
                    const container = document.querySelector('.container');
                    let list = document.querySelector('.transaction-list');

                    if (json.data.length === 0) {
                        if (list) list.remove();
                        if (!document.querySelector('.empty-state')) {
                            const empty = document.createElement('div');
                            empty.className = 'empty-state';
                            empty.innerHTML = '<i class="fa-solid fa-receipt fa-3x" style="color:var(--text-muted); opacity: 0.5; margin-bottom: 1rem;"></i><p>No transactions found.</p>';
                            container.appendChild(empty);
                        }
                        return;
                    }

                    if (document.querySelector('.empty-state')) document.querySelector('.empty-state').remove();
                    if (!list) {
                        list = document.createElement('ul');
                        list.className = 'transaction-list';
                        container.appendChild(list);
                    }

                    list.innerHTML = '';
                    json.data.forEach(tx => {
                        const typeClass = tx.is_in ? 'in' : 'out';
                        const iconClass = tx.is_in ? 'fa-arrow-down' : 'fa-arrow-up';
                        const operator = tx.is_in ? '+' : '-';

                        const li = document.createElement('li');
                        li.className = 'transaction-item';
                        li.style.cursor = 'pointer';
                        li.onclick = () => window.location.href = 'add?id=' + tx.id;
                        li.innerHTML = '<div class="transaction-details">' +
                                '<div class="t-icon ' + typeClass + '">' +
                                    '<i class="fa-solid ' + iconClass + '"></i>' +
                                '</div>' +
                                '<div class="t-info">' +
                                    '<h4>' + (tx.note || 'Transaction') + '</h4>' +
                                    '<p>' + tx.formattedDate + ' &bull; <span class="tag-category">' + (tx.category || 'General') + '</span></p>' +
                                '</div>' +
                            '</div>' +
                            '<div class="t-amount ' + typeClass + '">' +
                                operator + tx.symbol + tx.amount +
                            '</div>';
                        list.appendChild(li);
                    });
                }

            } catch (e) {
                console.error("Filter failed:", e);
                if (!navigator.onLine) {
                    const existingBadge = document.getElementById('searchOfflineBadge');
                    if (!existingBadge) {
                        const badge = document.createElement('div');
                        badge.id = 'searchOfflineBadge';
                        badge.style = "background:var(--danger); color:white; font-size:0.7rem; padding:4px 8px; border-radius:4px; margin-top:5px; text-align:center;";
                        badge.innerHTML = '<i class="fa-solid fa-plane"></i> Filtering is limited while offline';
                        searchForm.appendChild(badge);
                        setTimeout(() => badge.remove(), 5000);
                    }
                }
            }

        };

        searchForm.addEventListener('input', (e) => {
            if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'SELECT') return;
            triggerUpdate(e);
        });

        searchForm.addEventListener('submit', (e) => {
            triggerUpdate(e);
            closeSearch();
        });
    }
});

// ---- PDF Export ----
async function exportToPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();

    const searchForm = document.querySelector('form[method="GET"]');
    const fd = new FormData(searchForm);
    const q = new URLSearchParams(fd).toString();

    try {
        const r = await fetch('api?action=get_transactions&' + q);
        const json = await r.json();

        if (json.status === 'success' && json.data.length > 0) {
            const data = json.data;

            // Title
            doc.setFontSize(18);
            doc.text('Cash Book - Transaction Report', 14, 20);
            doc.setFontSize(10);
            doc.setTextColor(100);
            doc.text('Generated on: ' + new Date().toLocaleDateString(), 14, 28);

            // Totals
            let tin = 0, tout = 0;
            data.forEach(tx => {
                const amt = parseFloat(tx.amount_raw);
                if (tx.is_in) tin += amt;
                else tout += amt;
            });
            doc.setFontSize(11);
            doc.setTextColor(0);
            doc.text('Total In: ' + data[0].symbol + tin.toFixed(2), 14, 38);
            doc.text('Total Out: ' + data[0].symbol + tout.toFixed(2), 60, 38);
            doc.text('Net Balance: ' + data[0].symbol + (tin - tout).toFixed(2), 120, 38);

            // Table
            const tableData = data.map(tx => [
                tx.formattedDate,
                tx.note || 'Transaction',
                tx.category || 'General',
                tx.type.toUpperCase(),
                (tx.is_in ? '+' : '-') + tx.symbol + tx.amount
            ]);

            doc.autoTable({
                startY: 45,
                head: [['Date', 'Note', 'Category', 'Type', 'Amount']],
                body: tableData,
                headStyles: { fillColor: [99, 102, 241] },
                didParseCell: function(data) {
                    if (data.column.index === 4) {
                        if (data.cell.raw.startsWith('+')) data.cell.styles.textColor = [16, 185, 129];
                        else data.cell.styles.textColor = [239, 68, 68];
                    }
                }
            });

            doc.save('CashBook_Report_' + new Date().toISOString().slice(0, 10) + '.pdf');
        } else {
            alert('No transactions to export.');
        }
    } catch (e) {
        console.error(e);
        alert('Failed to generate PDF.');
    }
}
