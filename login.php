<?php
require_once __DIR__ . '/helpers/functions.php';

if (!isInstalled()) { header('Location: ' . BASE_URL . '/install.php'); exit; }
if (isset($_SESSION['user_id'])) { header('Location: ' . BASE_URL . '/dashboard.php'); exit; }

$appName = getAppName();
$appLogoUrl = getAppLogoUrl();

$error = '';
$success = flash('success');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session token. Please refresh the page and try again.';
    }
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$error && (empty($username) || empty($password))) {
        $error = 'Please enter both username and password.';
    } elseif (!$error) {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Reset failed attempts on successful login
            unset($_SESSION['login_attempts'], $_SESSION['last_attempt']);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // Generate MFA code
            $_SESSION['mfa_code'] = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['mfa_expires'] = time() + 300; // 5 minutes
            $_SESSION['mfa_verified'] = false;

            // Send MFA code via email
            if (!empty($user['email'])) {
                $subject = 'Your MFA verification code';
                $message = "Your verification code is: {$_SESSION['mfa_code']}\n\nIf you did not request this, please contact support.";
                $headers = "From: no-reply@yourdomain.com\r\n" .
                           "Reply-To: no-reply@yourdomain.com\r\n" .
                           "X-Mailer: PHP/" . phpversion();
                @mail($user['email'], $subject, $message, $headers);
            }

            $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (?, 'user_login', 'Login successful, MFA required', ?, NOW())")
                ->execute([$user['id'], $_SERVER['REMOTE_ADDR']]);

            header('Location: ' . BASE_URL . '/verify_mfa.php');
            exit;
        } else {
            // Track failed attempts
            if (!isset($_SESSION['login_attempts'])) {
                $_SESSION['login_attempts'] = 0;
            }
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt'] = time();

            // Rate limiting: lock out after 5 failed attempts for 15 minutes
            if ($_SESSION['login_attempts'] >= 5) {
                $lockout_time = 15 * 60; // 15 minutes
                if (time() - ($_SESSION['last_attempt'] ?? 0) < $lockout_time) {
                    $error = 'Too many failed login attempts. Please try again in 15 minutes.';
                } else {
                    // Reset after lockout period
                    $_SESSION['login_attempts'] = 1;
                }
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Login to ISCC Learning Management System">
    <title>Login - <?= e($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?= e($appLogoUrl) ?>">
</head>
<body class="lp-body">
<div class="login-page">
    <div class="login-page-left">
        <a href="<?= BASE_URL ?>/" class="login-back-link"><i class="fas fa-arrow-left"></i> Back to Home</a>
        <div class="login-form-area">
            <div class="login-logo-row">
                <img src="<?= e($appLogoUrl) ?>" alt="<?= e($appName) ?> logo" class="login-logo-img">
                <div>
                    <h1 class="login-heading">Sign In</h1>
                    <p class="login-sub"><?= e($appName) ?></p>
                </div>
            </div>
            <?php if ($error): ?>
            <div class="login-error">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div class="login-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
            </div>
            <?php endif; ?>
            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>
                <label class="login-label">Username</label>
                <div class="login-field">
                    <i class="fas fa-user"></i>
                    <input type="text" name="username" placeholder="Enter your username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
                <label class="login-label">Password</label>
                <div class="login-field">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="loginPasswordInput" name="password" placeholder="Enter your password" required>
                    <button type="button" class="login-field-action" id="loginPasswordToggle" aria-label="Show password" aria-pressed="false">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="login-helper-row">
                    <span class="login-helper-text">Use your assigned LMS account.</span>
                    <a href="<?= BASE_URL ?>/forgot-password.php" class="login-helper-link">Forgot password?</a>
                </div>
                <button type="submit" class="login-submit"><i class="fas fa-sign-in-alt me-2"></i>Sign In</button>
            </form>
            <p class="login-footer-text">&copy; <?= date('Y') ?> <?= e($appName) ?></p>
        </div>
    </div>
    <div class="login-page-right">
        <div class="login-right-content">
            <img src="<?= e($appLogoUrl) ?>" alt="<?= e($appName) ?>" class="login-right-logo">
            <h2><?= e($appName) ?></h2>
            <p>Quirino Stadium, Zone V, Bantay, Ilocos Sur</p>
            <div class="login-right-pills">
                <span>Free Tuition</span>
                <span>BSIT Program</span>
                <span>CHED Regulated</span>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('loginPasswordInput');
    const toggleButton = document.getElementById('loginPasswordToggle');
    if (!passwordInput || !toggleButton) {
        return;
    }

    toggleButton.addEventListener('click', function() {
        const showPassword = passwordInput.type === 'password';
        passwordInput.type = showPassword ? 'text' : 'password';
        toggleButton.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
        toggleButton.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
        toggleButton.innerHTML = '<i class="fas ' + (showPassword ? 'fa-eye-slash' : 'fa-eye') + '"></i>';
    });
});
</script>
</body>
</html>
