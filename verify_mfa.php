<?php
require_once __DIR__ . '/helpers/functions.php';

if (!isInstalled()) { header('Location: ' . BASE_URL . '/install.php'); exit; }
if (!isset($_SESSION['user_id'])) { header('Location: ' . BASE_URL . '/login.php'); exit; }
if (isset($_SESSION['mfa_verified']) && $_SESSION['mfa_verified']) { header('Location: ' . BASE_URL . '/dashboard.php'); exit; }

$appName = getAppName();
$appLogoUrl = getAppLogoUrl();

$error = '';
$success = flash('success');

$user = currentUser();
if (!$user) {
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session token. Please refresh the page and try again.';
    } else {
        $code = trim($_POST['mfa_code'] ?? '');
        if (isset($_SESSION['mfa_code'], $_SESSION['mfa_expires']) && time() <= $_SESSION['mfa_expires'] && hash_equals($_SESSION['mfa_code'], $code)) {
            $_SESSION['mfa_verified'] = true;
            unset($_SESSION['mfa_code'], $_SESSION['mfa_expires']);
            $pdo = getDB();
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (?, 'mfa_verified', 'MFA verification successful', ?, NOW())")
                ->execute([$user['id'], $_SERVER['REMOTE_ADDR']]);
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        } else {
            $error = 'Invalid or expired MFA code.';
        }
    }
}

// Resend code if requested
if (isset($_GET['resend'])) {
    $_SESSION['mfa_code'] = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['mfa_expires'] = time() + 300; // 5 minutes

    if (!empty($user['email'])) {
        $subject = 'Your MFA verification code';
        $message = "Your verification code is: {$_SESSION['mfa_code']}\n\nIf you did not request this, please contact support.";
        $headers = "From: no-reply@yourdomain.com\r\n" .
                   "Reply-To: no-reply@yourdomain.com\r\n" .
                   "X-Mailer: PHP/" . phpversion();
        @mail($user['email'], $subject, $message, $headers);
    }

    $success = 'A new verification code has been sent to your email.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MFA Verification - ISCC Learning Management System">
    <title>MFA Verification - <?= e($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?= e($appLogoUrl) ?>">
</head>
<body class="lp-body">
<div class="login-page">
    <div class="login-page-left">
        <a href="<?= BASE_URL ?>/login.php?logout=1" class="login-back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
        <div class="login-form-area">
            <div class="login-logo-row">
                <img src="<?= e($appLogoUrl) ?>" alt="<?= e($appName) ?> logo" class="login-logo-img">
                <div>
                    <h1 class="login-heading">Verify Identity</h1>
                    <p class="login-sub">Enter the 6-digit code sent to your email</p>
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
                <label class="login-label">Verification Code</label>
                <div class="login-field">
                    <i class="fas fa-key"></i>
                    <input type="text" name="mfa_code" placeholder="000000" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric">
                </div>
                <div class="login-helper-row">
                    <span class="login-helper-text">Code expires in 5 minutes</span>
                    <a href="?resend=1" class="login-helper-link">Resend code</a>
                </div>
                <button type="submit" class="login-submit"><i class="fas fa-check me-2"></i>Verify</button>
            </form>
            <p class="login-footer-text">&copy; <?= date('Y') ?> <?= e($appName) ?></p>
        </div>
    </div>
    <div class="login-page-right">
        <div class="login-bg-content">
            <h2>Secure Access</h2>
            <p>Multi-factor authentication helps protect your account from unauthorized access.</p>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const codeInput = document.querySelector('input[name="mfa_code"]');
    codeInput.addEventListener('input', function(e) {
        this.value = this.value.replace(/\D/g, '');
        if (this.value.length === 6) {
            this.form.submit();
        }
    });
});
</script>
</body>
</html>