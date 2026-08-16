<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index");
    exit;
}

$message = '';
$messageType = '';
$f_name = '';
$f_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $f_name     = trim($_POST['name'] ?? '');
    $f_email    = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';

    // --- Validations ---
    if (!$f_name || !$f_email || !$password) {
        $message = "Please fill in all required fields.";
        $messageType = "danger";
    } elseif (!filter_var($f_email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = "danger";
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters.";
        $messageType = "danger";
    } elseif ($password !== $confirm_pw) {
        $message = "Passwords do not match.";
        $messageType = "danger";
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $f_email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $message = "This email is already registered. <a href='login' style='color:inherit;font-weight:600;'>Login instead</a>.";
            $messageType = "danger";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt2 = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            $stmt2->bind_param("sss", $f_name, $f_email, $hashed);

            if ($stmt2->execute()) {
                $message = "Account created successfully! Redirecting to login...";
                $messageType = "success";
                echo "<script>setTimeout(function() { window.location.href = 'login'; }, 1800);</script>";
            } else {
                // Show the actual MySQL error so we can debug it
                $message = "Registration failed: " . htmlspecialchars($conn->error);
                $messageType = "danger";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Register - Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="manifest">
    <meta name="theme-color" content="#6366f1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-body">
    <?php include 'header.php'; ?>

    <div class="auth-page">
        <!-- Visual Side (Desktop only) -->
        <div class="auth-visual">
            <div class="auth-visual-content">
                <h1>Join 10,000+ Smart Savers.</h1>
                <p>Start your journey towards financial freedom today. Your personal finance assistant is just a few clicks away.</p>
            </div>
        </div>

        <!-- Form Side -->
        <div class="auth-form-side">
            <div class="auth-container">
                <h2 style="margin-bottom: 0.5rem; font-weight: 700; font-size: 2rem;">Create Account</h2>
                <p style="color: var(--text-muted); margin-bottom: 2rem; font-size: 0.9rem;">Join us and start managing your wealth.</p>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?>">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <form action="register" method="POST" novalidate id="registerForm">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="form-group">
                        <label class="form-label" for="name">Full Name <span style="color:#ef4444">*</span></label>
                        <input type="text" name="name" id="name" class="form-control" required
                               placeholder="John Doe" value="<?= htmlspecialchars($f_name) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">Email Address <span style="color:#ef4444">*</span></label>
                        <input type="email" name="email" id="email" class="form-control" required
                               placeholder="you@example.com" value="<?= htmlspecialchars($f_email) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Password <span style="color:#ef4444">*</span></label>
                        <div style="position: relative;">
                            <input type="password" name="password" id="password" class="form-control" required
                                   placeholder="••••••••" minlength="6" style="padding-right: 40px;">
                            <i class="fa-regular fa-eye toggle-password" data-target="password" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); font-size: 1.1rem; padding: 5px;"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm Password <span style="color:#ef4444">*</span></label>
                        <div style="position: relative;">
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required
                                   placeholder="••••••••" style="padding-right: 40px;">
                            <i class="fa-regular fa-eye toggle-password" data-target="confirm_password" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); font-size: 1.1rem; padding: 5px;"></i>
                        </div>
                        <p id="matchMsg" style="font-size:0.75rem; margin-top:0.3rem;"></p>
                    </div>

                    <button type="submit" class="btn" style="margin-top: 1rem; font-weight: 600;">
                        Create Account
                    </button>
                </form>

                <p style="text-align: center; margin-top: 2.5rem; color: var(--text-muted); font-size: 0.875rem;">
                    Already have an account? <a href="login" style="color: var(--primary); text-decoration: none; font-weight: 600;">Login here</a>
                </p>
            </div>
        </div>
    </div>

        <script>
            document.getElementById('confirm_password').addEventListener('input', function() {
                const pw = document.getElementById('password').value;
                const msg = document.getElementById('matchMsg');
                if (!this.value) { msg.textContent = ''; return; }
                if (this.value === pw) {
                    msg.textContent = '✓ Passwords match';
                    msg.style.color = 'var(--success)';
                } else {
                    msg.textContent = '✗ Passwords do not match';
                    msg.style.color = 'var(--danger)';
                }
            });

            document.querySelectorAll('.toggle-password').forEach(icon => {
                icon.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const passwordField = document.getElementById(targetId);
                    const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordField.setAttribute('type', type);
                    this.classList.toggle('fa-eye-slash');
                    this.classList.toggle('fa-eye');
                });
            });
        </script>

        <script src="assets/js/app.js"></script>
    </div>
</body>
</html>
