<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index");
    exit;
}

$message = '';
$messageType = '';
$resetLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $email = trim($_POST['email'] ?? '');

    if ($email) {
        // Check if email exists
        $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // Generate a secure token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Store token in DB
            $stmt2 = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $stmt2->bind_param("ssi", $token, $expires, $user['id']);
            $stmt2->execute();

            // Build reset link
            $host = $_SERVER['HTTP_HOST'];
            $dir  = dirname($_SERVER['REQUEST_URI']);
            $resetLink = "http://{$host}{$dir}/reset_password?token={$token}";

            $message = "Reset link generated for <strong>" . htmlspecialchars($user['name']) . "</strong>. Copy the link below and open it.";
            $messageType = "success";
        } else {
            $message = "No account found with that email address.";
            $messageType = "danger";
        }
    } else {
        $message = "Please enter your email address.";
        $messageType = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Forgot Password - Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-body">
    <?php include 'header.php'; ?>

    <div class="auth-page">
        <!-- Visual Side (Desktop only) -->
        <div class="auth-visual">
            <div class="auth-visual-content">
                <h1>Security is Our Priority.</h1>
                <p>Your data is encrypted and protected. Resetting your password is quick and secure.</p>
            </div>
        </div>

        <!-- Form Side -->
        <div class="auth-form-side">
            <div class="auth-container">
                <div style="text-align: center; margin-bottom: 2rem;">
                    <div style="width: 70px; height: 70px; background: linear-gradient(135deg, var(--primary), #818cf8); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                        <i class="fa-solid fa-key" style="font-size: 1.75rem; color: white;"></i>
                    </div>
                    <h2 style="font-weight: 700; font-size: 1.5rem; margin-bottom: 0.5rem;">Forgot Password?</h2>
                    <p style="color: var(--text-muted); font-size: 0.875rem;">Enter your registered email to get a reset link.</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?>" style="margin-bottom: 1.5rem;">
                        <?= $message ?>
                    </div>
                <?php endif; ?>

                <?php if ($resetLink): ?>
                    <div class="stat-card" style="margin-bottom: 1.5rem; border-left: 4px solid var(--primary); padding: 1.5rem;">
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                            <i class="fa-solid fa-clock"></i> This link expires in <strong>1 hour</strong>.
                        </p>
                        <p style="font-size: 0.8rem; font-weight: 600; margin-bottom: 0.75rem;">Reset Link:</p>
                        <div style="background: var(--bg-color); border-radius: var(--radius-md); padding: 0.75rem; word-break: break-all; font-size: 0.7rem; color: var(--primary); margin-bottom: 1rem; border: 1px solid var(--border);">
                            <?= htmlspecialchars($resetLink) ?>
                        </div>
                        <a href="<?= htmlspecialchars($resetLink) ?>" class="btn" style="text-align: center; font-weight: 600;">
                            <i class="fa-solid fa-arrow-right" style="margin-right: 0.5rem;"></i> Open Reset Page
                        </a>
                    </div>
                <?php endif; ?>

                <?php if (!$resetLink): ?>
                    <form action="forgot_password" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="form-group">
                            <label class="form-label" for="email">Email Address</label>
                            <input type="email" name="email" id="email" class="form-control" required placeholder="you@example.com" autofocus>
                        </div>
                        <button type="submit" class="btn" style="margin-top: 1rem; font-weight: 600;">
                            Get Reset Link
                        </button>
                    </form>
                <?php else: ?>
                    <form action="forgot_password" method="GET">
                        <button type="submit" class="btn btn-secondary" style="width: 100%; font-weight: 600;">
                            Try Another Email
                        </button>
                    </form>
                <?php endif; ?>

                <p style="text-align: center; margin-top: 2rem; color: var(--text-muted); font-size: 0.875rem;">
                    Remembered it? <a href="login" style="color: var(--primary); text-decoration: none; font-weight: 600;">Back to Login</a>
                </p>
            </div>
        </div>
    </div>
    <script src="assets/js/app.js"></script>
</body>
</html>
