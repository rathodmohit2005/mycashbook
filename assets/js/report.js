/**
 * report.js - Monthly Report Analytics
 * Moved from report.php to bypass hosting firewall blocks
 */

// Chart instances
var ratioChart, dailyChart, catChart;

document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') return;

    var ctxRatio = document.getElementById('ratioChart').getContext('2d');
    ratioChart = new Chart(ctxRatio, {
        type: 'doughnut',
        data: {
            labels: ['Income', 'Expense'],
            datasets: [{
                data: [window.reportInitialData.yearIncome, window.reportInitialData.yearExpense],
                backgroundColor: ['#10b981', '#ef4444'],
                borderWidth: 0
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    var ctxDaily = document.getElementById('dailyChart').getContext('2d');
    dailyChart = new Chart(ctxDaily, {
        type: 'bar',
        data: {
            labels: window.reportInitialData.dailyLabels,
            datasets: [
                {
                    label: 'Income',
                    data: window.reportInitialData.dailyDataIn,
                    backgroundColor: '#10b981',
                    borderRadius: 4
                },
                {
                    label: 'Expense',
                    data: window.reportInitialData.dailyDataOut,
                    backgroundColor: '#ef4444',
                    borderRadius: 4
                }
            ]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            scales: { 
                x: { stacked: false },
                y: { beginAtZero: true, stacked: false } 
            },
            interaction: {
                mode: 'index',
                intersect: false,
            }
        }
    });

    var ctxCat = document.getElementById('catChart').getContext('2d');
    catChart = new Chart(ctxCat, {
        type: 'bar',
        data: {
            labels: window.reportInitialData.catLabels,
            datasets: [{
                label: 'Spent Amount',
                data: window.reportInitialData.catValues,
                backgroundColor: '#818cf8',
                borderRadius: 8,
                maxBarThickness: 30
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { grid: { display: false }, ticks: { font: { size: 12, weight: '600' } } }
            }
        }
    });
});


function fetchReportData() {
    var loader = document.getElementById('report-loader');
    loader.style.display = 'flex';
    
    var cacheKey = 'report_cache_' + currentYear + '_' + currentMonth;

    fetch('api.php?action=get_report_data&year=' + currentYear + '&month=' + currentMonth)
        .then(function(response) { return response.json(); })
        .then(function(res) {
            if (res.status === 'success') {
                // Store in cache for offline use
                localStorage.setItem(cacheKey, JSON.stringify(res));
                renderReport(res);
            }
        })
        .catch(function(e) {
            console.warn("Network fetch failed, checking offline cache...", e);
            var cached = localStorage.getItem(cacheKey);
            if (cached) {
                renderReport(JSON.parse(cached), true);
            } else {
                alert("This report is not available offline. Please view it once while online to cache it.");
            }
        })
        .finally(function() {
            setTimeout(function() { loader.style.display = 'none'; }, 150);
        });
}

function renderReport(res, isOffline = false) {
    // Update Yearly Chart
    ratioChart.data.datasets[0].data = [res.yearly.income, res.yearly.expense];
    ratioChart.update();
    document.getElementById('label_year').innerText = res.yearly.year + (isOffline ? ' (Offline)' : '');

    // Update Monthly Stats
    document.getElementById('main_month_label').innerText = res.monthly.month_label + (isOffline ? ' ⚠️' : '');
    document.getElementById('label_month_short').innerText = res.monthly.month_short;
    document.getElementById('label_month_year').innerText = res.monthly.month_year;
    
    document.getElementById('summary_balance').innerText = currencySymbol + res.monthly.balance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('summary_in').innerText = '+' + currencySymbol + res.monthly.total_in.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('summary_out').innerText = '-' + currencySymbol + res.monthly.total_out.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});

    // Update Daily Activity Chart
    dailyChart.data.labels = res.monthly.daily_labels;
    dailyChart.data.datasets[0].data = res.monthly.daily_data_in;
    dailyChart.data.datasets[1].data = res.monthly.daily_data_out;
    dailyChart.update('none'); // Update without animation for snappier feel

    // Update Category Analysis Chart
    catChart.data.labels = res.monthly.cat_labels;
    catChart.data.datasets[0].data = res.monthly.cat_data;
    catChart.update('none');
}


function changeYear(delta) {
    currentYear += delta;
    var parts = currentMonth.split('-');
    parts[0] = currentYear;
    currentMonth = parts.join('-');
    fetchReportData();
}

function changeMonth(delta) {
    var d = new Date(currentMonth + '-01');
    d.setMonth(d.getMonth() + delta);
    currentMonth = d.toISOString().slice(0, 7);
    currentYear = d.getFullYear();
    fetchReportData();
}

function exportReportPDF() {
    var jspdf = window.jspdf;
    if (!jspdf) return;
    
    var doc = new jspdf.jsPDF();
    var monthVal = currentMonth;
    
    // Fetch transactions for that month only
    var start = monthVal + '-01';
    var year = parseInt(monthVal.split('-')[0]);
    var month = parseInt(monthVal.split('-')[1]);
    var end = new Date(year, month, 0).toISOString().slice(0, 10);

    fetch('api?action=get_transactions&start=' + start + '&end=' + end)
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json.status === 'success' && json.data.length > 0) {
                var data = json.data;
                var symbol = data[0].symbol;

                doc.setFontSize(18);
                doc.text('Cash Book - Monthly Statement', 14, 20);
                doc.setFontSize(14);
                doc.text(currentMonth, 14, 30);
                
                doc.setFontSize(10);
                doc.setTextColor(100);
                doc.text('Generated on: ' + new Date().toLocaleDateString(), 14, 38);

                // Monthly Summary
                var tin = 0, tout = 0;
                data.forEach(function(tx) {
                    var amt = parseFloat(tx.amount_raw);
                    if (tx.is_in) tin += amt; else tout += amt;
                });

                doc.setFontSize(11);
                doc.setTextColor(0);
                doc.text('Total In: ' + symbol + tin.toFixed(2), 14, 48);
                doc.text('Total Out: ' + symbol + tout.toFixed(2), 60, 48);
                doc.text('Net Balance: ' + symbol + (tin-tout).toFixed(2), 120, 48);

                var tableData = data.map(function(tx) {
                    return [
                        tx.formattedDate,
                        tx.note || 'Transaction',
                        tx.category || 'General',
                        tx.type === 'cash in' ? 'IN' : 'OUT',
                        (tx.is_in ? '+' : '-') + tx.symbol + tx.amount
                    ];
                });

                doc.autoTable({
                    startY: 55,
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

                doc.save('CashBook_Statement_' + monthVal + '.pdf');
            } else {
                alert('No transactions found for this month.');
            }
        })
        .catch(function(e) {
            console.error(e);
            alert('Failed to generate PDF.');
        });
}
