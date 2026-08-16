<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index");
    exit;
}

$message = '';
$messageType = '';
$token = trim($_GET['token'] ?? '');
$validToken = false;
$userId = null;

// Validate token
if ($token) {
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $userId = $user['id'];
        $validToken = true;
    } else {
        $message = "This reset link is invalid or has expired. Please request a new one.";
        $messageType = "danger";
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    checkCSRF();
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 6) {
        $message = "Password must be at least 6 characters.";
        $messageType = "danger";
    } elseif ($newPassword !== $confirmPassword) {
        $message = "Passwords do not match.";
        $messageType = "danger";
    } else {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update password and clear token
        $stmt2 = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $stmt2->bind_param("si", $hashed, $userId);

        if ($stmt2->execute()) {
            $message = "Password reset successfully! Redirecting to login...";
            $messageType = "success";
            $validToken = false; // Hide form
            echo "<script>setTimeout(function() { window.location.href = 'login'; }, 2000);</script>";
        } else {
            $message = "Something went wrong. Please try again.";
            $messageType = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Reset Password - Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .password-toggle {
            position: relative;
        }
        .password-toggle input {
            padding-right: 3rem;
        }
        .eye-btn {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 1rem;
        }
        .strength-bar {
            height: 4px;
            border-radius: 4px;
            margin-top: 0.4rem;
            transition: all 0.3s;
            background: var(--border);
        }
        .strength-bar .fill {
            height: 100%;
            border-radius: 4px;
            transition: all 0.3s;
        }
    </style>
</head>
<body class="auth-body">
    <?php include 'header.php'; ?>

    <div class="auth-page">
        <!-- Visual Side (Desktop only) -->
        <div class="auth-visual">
            <div class="auth-visual-content">
                <h1>Protect Your Account.</h1>
                <p>A strong password is your first line of defense. Use a mix of letters, numbers, and symbols.</p>
            </div>
        </div>

        <!-- Form Side -->
        <div class="auth-form-side">
            <div class="auth-container">
                <div style="text-align: center; margin-bottom: 2rem;">
                    <div style="width: 70px; height: 70px; background: linear-gradient(135deg, var(--primary), #818cf8); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                        <i class="fa-solid fa-lock-open" style="font-size: 1.75rem; color: white;"></i>
                    </div>
                    <h2 style="font-weight: 700; font-size: 1.5rem; margin-bottom: 0.5rem;">Reset Password</h2>
                    <?php if ($validToken): ?>
                        <p style="color: var(--text-muted); font-size: 0.875rem;">
                            Hello <strong><?= htmlspecialchars($user['name']) ?></strong>, set your new password below.
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?>" style="margin-bottom: 1.5rem;">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <?php if ($validToken): ?>
                    <form action="reset_password?token=<?= htmlspecialchars($token) ?>" method="POST" id="resetForm">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                        <div class="form-group">
                            <label class="form-label" for="password">New Password</label>
                            <div class="password-toggle">
                                <input type="password" name="password" id="password" class="form-control" required
                                       placeholder="Min. 6 characters" minlength="6" oninput="checkStrength(this.value)" autofocus>
                                <button type="button" class="eye-btn" onclick="toggleEye('password', this)">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <div class="strength-bar"><div class="fill" id="strengthFill"></div></div>
                            <p id="strengthLabel" style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.25rem;"></p>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm Password</label>
                            <div class="password-toggle">
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required
                                       placeholder="Repeat your password">
                                <button type="button" class="eye-btn" onclick="toggleEye('confirm_password', this)">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <p id="matchLabel" style="font-size: 0.7rem; margin-top: 0.25rem;"></p>
                        </div>

                        <button type="submit" class="btn" style="margin-top: 1rem; font-weight: 600;" id="submitBtn">
                            Set New Password
                        </button>
                    </form>
                <?php elseif ($messageType !== 'success'): ?>
                    <div style="text-align: center;">
                        <a href="forgot_password" class="btn" style="display: inline-flex; font-weight: 600;">
                            Request New Link
                        </a>
                    </div>
                <?php endif; ?>

                <p style="text-align: center; margin-top: 2rem; color: var(--text-muted); font-size: 0.875rem;">
                    <a href="login" style="color: var(--primary); text-decoration: none; font-weight: 600;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Login
                    </a>
                </p>
            </div>
        </div>
    </div>

    <script>
        function toggleEye(fieldId, btn) {
            const input = document.getElementById(fieldId);
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        function checkStrength(val) {
            const fill = document.getElementById('strengthFill');
            const label = document.getElementById('strengthLabel');
            let score = 0;
            if (val.length >= 6) score++;
            if (val.length >= 10) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            const levels = [
                { color: '#ef4444', text: 'Very weak', pct: '20%' },
                { color: '#f97316', text: 'Weak', pct: '40%' },
                { color: '#eab308', text: 'Fair', pct: '60%' },
                { color: '#22c55e', text: 'Strong', pct: '80%' },
                { color: '#10b981', text: 'Very strong', pct: '100%' },
            ];
            const level = levels[score - 1] || { color: '#64748b', text: '', pct: '0%' };
            fill.style.width = level.pct;
            fill.style.background = level.color;
            label.textContent = level.text;
            label.style.color = level.color;
        }

        // Live confirm match check
        document.getElementById('confirm_password')?.addEventListener('input', function() {
            const pass = document.getElementById('password').value;
            const label = document.getElementById('matchLabel');
            if (this.value === '') { label.textContent = ''; return; }
            if (this.value === pass) {
                label.textContent = '✓ Passwords match';
                label.style.color = 'var(--success)';
            } else {
                label.textContent = '✗ Passwords do not match';
                label.style.color = 'var(--danger)';
            }
        });
    </script>
    <script src="assets/js/app.js"></script>
</body>
</html>
