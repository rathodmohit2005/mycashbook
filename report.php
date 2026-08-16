<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';

// 1. Determine selected period
$selected_month = $_GET['month'] ?? date('Y-m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// 2. Navigation calculation
$month_time = strtotime($selected_month . "-01");
$prev_month = date('Y-m', strtotime("-1 month", $month_time));
$next_month = date('Y-m', strtotime("+1 month", $month_time));

$prev_year = $selected_year - 1;
$next_year = $selected_year + 1;

// 3. Yearly Stats for Donut Chart (Income vs Expense)
$stmt_year_in = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE type = 'cash in' AND user_id = ? AND YEAR(date) = ?");
$stmt_year_in->bind_param("ii", $user_id, $selected_year);
$stmt_year_in->execute();
$year_income = $stmt_year_in->get_result()->fetch_assoc()['total'] ?? 0;

$stmt_year_out = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE type = 'cash out' AND user_id = ? AND YEAR(date) = ?");
$stmt_year_out->bind_param("ii", $user_id, $selected_year);
$stmt_year_out->execute();
$year_expense = $stmt_year_out->get_result()->fetch_assoc()['total'] ?? 0;

// 4. Monthly Balance for top card
$stmt_in = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE type = 'cash in' AND user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?");
$stmt_in->bind_param("is", $user_id, $selected_month);
$stmt_in->execute();
$total_income = $stmt_in->get_result()->fetch_assoc()['total'] ?? 0;

$stmt_out = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE type = 'cash out' AND user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?");
$stmt_out->bind_param("is", $user_id, $selected_month);
$stmt_out->execute();
$total_expense = $stmt_out->get_result()->fetch_assoc()['total'] ?? 0;

$balance = $total_income - $total_expense;

// 5. Daily Income and Expenses Chart (Monthly)
$stmt_daily = $conn->prepare("
    SELECT DATE(date) as d, type, SUM(amount) as total 
    FROM transactions 
    WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? 
    GROUP BY d, type ORDER BY d ASC
");
$stmt_daily->bind_param("is", $user_id, $selected_month);
$stmt_daily->execute();
$daily_res = $stmt_daily->get_result();

$daily_labels = [];
$daily_data_in = [];
$daily_data_out = [];
$temp_daily = [];

while ($r = $daily_res->fetch_assoc()) {
    $d = date('d M', strtotime($r['d']));
    if (!isset($temp_daily[$d])) {
        $temp_daily[$d] = ['in' => 0, 'out' => 0];
    }
    if ($r['type'] === 'cash in') {
        $temp_daily[$d]['in'] += $r['total'];
    } else {
        $temp_daily[$d]['out'] += $r['total'];
    }
}

foreach ($temp_daily as $d => $totals) {
    $daily_labels[] = $d;
    $daily_data_in[] = $totals['in'];
    $daily_data_out[] = $totals['out'];
}

// 6. Category Analysis Data (Monthly)
$cat_data = [];
$cat_query = "SELECT category, SUM(amount) as total FROM transactions 
              WHERE user_id = ? AND type = 'cash out' AND DATE_FORMAT(date, '%Y-%m') = ? 
              GROUP BY category ORDER BY total DESC";
$stmt_cat = $conn->prepare($cat_query);
$stmt_cat->bind_param("is", $user_id, $selected_month);
$stmt_cat->execute();
$cat_res = $stmt_cat->get_result();
while ($row = $cat_res->fetch_assoc()) {
    $cat_data[] = $row;
}
$cat_labels = array_column($cat_data, 'category');
$cat_values = array_column($cat_data, 'total');

// Get user preference for currency
$stmt_pref = $conn->prepare("SELECT default_currency FROM users WHERE id = ?");
$stmt_pref->bind_param("i", $user_id);
$stmt_pref->execute();
$default_cur_code = $stmt_pref->get_result()->fetch_assoc()['default_currency'] ?? 'INR';
$default_symbol = getCurrencySymbol($default_cur_code);

$pageTitle = 'Monthly Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Monthly Report - Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="manifest">
    <meta name="theme-color" content="#6366f1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- PDF Export Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <style>
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .chart-title {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-main);
        }
        .chart-nav {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .btn-nav {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            background: var(--bg-color);
            border: 1px solid var(--border);
            color: var(--text-main);
            text-decoration: none;
            font-size: 0.7rem;
            transition: var(--transition);
        }
        .btn-nav:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .period-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary);
            min-width: 60px;
            text-align: center;
        }
        /* Loading Spinner */
        #report-loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(2px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }
        .loader-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(99, 102, 241, 0.2);
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div id="report-loader">
        <div class="loader-spinner"></div>
    </div>

    <div class="container" style="padding-top: 0;">
        <!-- Main Month Filter -->
        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.5rem;">
            <div style="flex: 1; display: flex; align-items: center; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.25rem 0.5rem;">
                <button type="button" onclick="changeMonth(-1)" class="btn-nav"><i class="fa-solid fa-chevron-left"></i></button>
                <div id="main_month_label" style="flex: 1; text-align: center; font-weight: 600; font-size: 0.9rem;">
                    <?= date('F Y', $month_time) ?>
                </div>
                <button type="button" onclick="changeMonth(1)" class="btn-nav"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
            <button type="button" onclick="exportReportPDF()" class="btn" style="width: auto; padding: 0 1rem; height: 42px;">
                <i class="fa-solid fa-file-pdf"></i>
            </button>
        </div>

        <div class="balance-card" style="padding: 1rem; margin-bottom: 1rem;">
            <div class="balance-label">Month Balance (<?= $default_cur_code ?>)</div>
            <div id="summary_balance" class="balance-amount" style="font-size: 2rem;"><?= $default_symbol ?><?= number_format($balance, 2) ?></div>
        </div>

        <div class="stats-grid" style="margin-bottom: 1.5rem;">
            <div class="stat-card income" style="padding: 0.75rem;">
                <div class="label"><i class="fa-solid fa-arrow-down" style="color:var(--success)"></i> Total In</div>
                <div id="summary_in" class="amount" style="font-size: 1.125rem;">+<?= $default_symbol ?><?= number_format($total_income, 2) ?></div>
            </div>
            <div class="stat-card expense" style="padding: 0.75rem;">
                <div class="label"><i class="fa-solid fa-arrow-up" style="color:var(--danger)"></i> Total Out</div>
                <div id="summary_out" class="amount" style="font-size: 1.125rem;">-<?= $default_symbol ?><?= number_format($total_expense, 2) ?></div>
            </div>
        </div>

        <!-- Chart.js Analytics Dashboard - Responsive Side-by-Side Row -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;" class="charts-row">
            <div class="stat-card" style="padding: 1rem;">
                <div class="chart-header">
                    <span class="chart-title">Income vs Expense</span>
                    <div class="chart-nav">
                        <button type="button" onclick="changeYear(-1)" class="btn-nav"><i class="fa-solid fa-chevron-left"></i></button>
                        <span id="label_year" class="period-label"><?= $selected_year ?></span>
                        <button type="button" onclick="changeYear(1)" class="btn-nav"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                </div>
                <div style="position: relative; height: 200px; width: 100%;">
                    <canvas id="ratioChart"></canvas>
                </div>
            </div>

            <div class="stat-card" style="padding: 1rem;">
                <div class="chart-header">
                    <span class="chart-title">Daily Activity (In vs Out)</span>
                    <div class="chart-nav">
                        <button type="button" onclick="changeMonth(-1)" class="btn-nav"><i class="fa-solid fa-chevron-left"></i></button>
                        <span id="label_month_short" class="period-label"><?= date('M', $month_time) ?></span>
                        <button type="button" onclick="changeMonth(1)" class="btn-nav"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                </div>
                <div style="position: relative; height: 200px; width: 100%;">
                    <canvas id="dailyChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Category Analysis Chart -->
        <div class="stat-card" style="padding: 1.25rem; margin-bottom: 2rem;">
            <div class="chart-header" style="margin-bottom: 1.25rem;">
                <div style="font-weight: 600; font-size: 0.9375rem; display: flex; align-items: center;">
                    <i class="fa-solid fa-list-check" style="margin-right: 0.5rem; color: var(--primary);"></i> Category Analysis
                </div>
                <div class="chart-nav">
                    <button type="button" onclick="changeMonth(-1)" class="btn-nav"><i class="fa-solid fa-chevron-left"></i></button>
                    <span id="label_month_year" class="period-label"><?= date('M Y', $month_time) ?></span>
                    <button type="button" onclick="changeMonth(1)" class="btn-nav"><i class="fa-solid fa-chevron-right"></i></button>
                </div>
            </div>
            <div style="position: relative; height: 350px; width: 100%;">
                <canvas id="catChart"></canvas>
            </div>
        </div>

    </div>
    <?php require_once 'nav.php'; ?>
    <script src="assets/js/app.js"></script>
    <script>
        // Global variables for persistence without reload
        var currentYear = <?= $selected_year ?>;
        var currentMonth = '<?= $selected_month ?>';
        var currencySymbol = '<?= $default_symbol ?>';

        // Initial Data for Charts
        window.reportInitialData = {
            yearIncome: <?= (float)$year_income ?>,
            yearExpense: <?= (float)$year_expense ?>,
            dailyLabels: <?= json_encode($daily_labels) ?>,
            dailyDataIn: <?= json_encode($daily_data_in) ?>,
            dailyDataOut: <?= json_encode($daily_data_out) ?>,
            catLabels: <?= json_encode($cat_labels) ?>,
            catValues: <?= json_encode($cat_values) ?>
        };
    </script>
    <script src="assets/js/report.js?v=<?= time() ?>"></script>
</body>
</html>
