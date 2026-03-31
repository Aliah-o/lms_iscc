<?php
require_once __DIR__ . '/helpers/functions.php';

if (!isInstalled()) { header('Location: ' . BASE_URL . '/install.php'); exit; }
if (isset($_SESSION['user_id'])) { header('Location: ' . BASE_URL . '/profile.php#security'); exit; }

$appName = getAppName();
$appLogoUrl = getAppLogoUrl();

$error = '';
$success = flash('success');
$step = $_GET['step'] ?? 'request';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session token. Please refresh the page and try again.';
    } elseif ($step === 'request') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate reset token and MFA code
                $reset_token = bin2hex(random_bytes(32));
                $mfa_code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

                // Store in session temporarily (in production, store in database with expiry)
                $_SESSION['reset_user_id'] = $user['id'];
                $_SESSION['reset_token'] = $reset_token;
                $_SESSION['reset_mfa_code'] = $mfa_code;
                $_SESSION['reset_expires'] = time() + 600; // 10 minutes

                // Send reset email with MFA code
                $subject = 'Password Reset Request';
                $message = "You requested a password reset for your {$appName} account.\n\n";
                $message .= "Your verification code is: {$mfa_code}\n\n";
                $message .= "Reset link: " . BASE_URL . "/forgot-password.php?step=verify&token={$reset_token}\n\n";
                $message .= "This link expires in 10 minutes. If you did not request this reset, please ignore this email.";
                $headers = "From: no-reply@yourdomain.com\r\n" .
                           "Reply-To: no-reply@yourdomain.com\r\n" .
                           "X-Mailer: PHP/" . phpversion();
                @mail($email, $subject, $message, $headers);

                $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (?, 'password_reset_request', 'Password reset requested', ?, NOW())")
                    ->execute([$user['id'], $_SERVER['REMOTE_ADDR']]);

                header('Location: ' . BASE_URL . '/forgot-password.php?step=verify&token=' . $reset_token);
                exit;
            } else {
                // Don't reveal if email exists or not for security
                $success = 'If an account with that email exists, a reset code has been sent.';
            }
        }
    } elseif ($step === 'verify') {
        $token = $_GET['token'] ?? '';
        $mfa_code = trim($_POST['mfa_code'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($token) || !isset($_SESSION['reset_token']) || !hash_equals($_SESSION['reset_token'], $token)) {
            $error = 'Invalid or expired reset token.';
        } elseif (time() > ($_SESSION['reset_expires'] ?? 0)) {
            $error = 'Reset token has expired.';
        } elseif (!isset($_SESSION['reset_mfa_code']) || !hash_equals($_SESSION['reset_mfa_code'], $mfa_code)) {
            $error = 'Invalid verification code.';
        } elseif (strlen($new_password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            $pdo = getDB();
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $_SESSION['reset_user_id']]);

            $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (?, 'password_reset_complete', 'Password reset completed', ?, NOW())")
                ->execute([$_SESSION['reset_user_id'], $_SERVER['REMOTE_ADDR']]);

            // Clean up session
            unset($_SESSION['reset_user_id'], $_SESSION['reset_token'], $_SESSION['reset_mfa_code'], $_SESSION['reset_expires']);

            $success = 'Password reset successfully. You can now log in with your new password.';
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Reset Password - ISCC Learning Management System">
    <title>Reset Password - <?= e($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?= e($appLogoUrl) ?>">
</head>
<body class="lp-body">
<div class="login-page">
    <div class="login-page-left">
        <a href="<?= BASE_URL ?>/login.php" class="login-back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
        <div class="login-form-area">
            <div class="login-logo-row">
                <img src="<?= e($appLogoUrl) ?>" alt="<?= e($appName) ?> logo" class="login-logo-img">
                <div>
                    <h1 class="login-heading">
                        <?php if ($step === 'request'): ?>Reset Password<?php else: ?>Verify Reset<?php endif; ?>
                    </h1>
                    <p class="login-sub">
                        <?php if ($step === 'request'): ?>Enter your email to receive a reset code<?php else: ?>Enter the code and set your new password<?php endif; ?>
                    </p>
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

            <?php if ($step === 'request'): ?>
            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>
                <label class="login-label">Email Address</label>
                <div class="login-field">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="Enter your email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <button type="submit" class="login-submit"><i class="fas fa-paper-plane me-2"></i>Send Reset Code</button>
            </form>
            <?php elseif ($step === 'verify' && isset($_SESSION['reset_token'])): ?>
            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>
                <label class="login-label">Verification Code</label>
                <div class="login-field">
                    <i class="fas fa-key"></i>
                    <input type="text" name="mfa_code" placeholder="000000" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric">
                </div>
                <label class="login-label">New Password</label>
                <div class="login-field">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="new_password" placeholder="Enter new password" required>
                </div>
                <label class="login-label">Confirm New Password</label>
                <div class="login-field">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                </div>
                <button type="submit" class="login-submit"><i class="fas fa-save me-2"></i>Reset Password</button>
            </form>
            <?php else: ?>
            <div class="login-error">
                <i class="fas fa-exclamation-triangle"></i> Invalid or expired reset link.
            </div>
            <a href="<?= BASE_URL ?>/forgot-password.php" class="login-submit" style="display: inline-block; text-decoration: none;">Try Again</a>
            <?php endif; ?>

            <p class="login-footer-text">&copy; <?= date('Y') ?> <?= e($appName) ?></p>
        </div>
    </div>
    <div class="login-page-right">
        <div class="login-bg-content">
            <h2>Secure Password Reset</h2>
            <p>We'll send a verification code to your email to securely reset your password.</p>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
                        'Requester email: ' . $normalizedEmail,
                        'Lookup status: ' . ($matchedUser ? 'Matched active LMS account' : 'No active LMS account found'),
                    ];

                    if ($matchedUser) {
                        $descriptionLines[] = 'Matched name: ' . trim($matchedUser['first_name'] . ' ' . $matchedUser['last_name']);
                        $descriptionLines[] = 'Username: ' . $matchedUser['username'];
                        $descriptionLines[] = 'Role: ' . ucfirst($matchedUser['role']);
                    } else {
                        $descriptionLines[] = 'Matched name: Not found';
                        $descriptionLines[] = 'Username: Not found';
                        $descriptionLines[] = 'Role: Not found';
                    }

                    $descriptionLines[] = 'Action needed: Review the requester email and assist with a manual password reset if appropriate. Do not send the current password.';
                    $description = implode("\n", $descriptionLines);

                    $stmt = $pdo->prepare("INSERT INTO support_tickets (ticket_number, user_id, requester_email, account_lookup_status, request_source, category, priority, subject, description) VALUES (?, ?, ?, ?, 'forgot_password', 'account', 'high', ?, ?)");
                    $stmt->execute([$ticketNumber, $ticketOwnerId, $normalizedEmail, $lookupStatus, $subject, $description]);
                    $ticketId = (int)$pdo->lastInsertId();

                    foreach ($admins as $admin) {
                        addNotification((int)$admin['id'], 'ticket', 'New password reset request for ' . $normalizedEmail, $ticketId, BASE_URL . '/tickets.php?view=' . $ticketId);
                    }

                    auditLog('password_recovery_ticket_created', 'Password recovery ticket ' . $ticketNumber . ' created for ' . $normalizedEmail . ' (' . $lookupStatus . ')');
                }

                flash('success', 'Password recovery request submitted. The superadmin can now review the email and respond manually through the ticketing system.');
                redirect('/forgot-password.php');
            }

            if ($error !== '') {
                // Fall through and show the validation error on the same page.
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
    <meta name="description" content="Request password assistance for ISCC LMS">
    <title>Forgot Password - <?= e($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?= e($appLogoUrl) ?>">
</head>
<body class="lp-body">
<div class="login-page">
    <div class="login-page-left">
        <a href="<?= BASE_URL ?>/login.php" class="login-back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
        <div class="login-form-area">
            <div class="login-logo-row">
                <img src="<?= e($appLogoUrl) ?>" alt="<?= e($appName) ?> logo" class="login-logo-img">
                <div>
                    <h1 class="login-heading">Forgot Password</h1>
                    <p class="login-sub"><?= e($appName) ?></p>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="login-error">
                <i class="fas fa-exclamation-triangle"></i> <?= e($error) ?>
            </div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div class="login-success">
                <i class="fas fa-check-circle"></i> <?= e($success) ?>
            </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>
                <label class="login-label">Email Address</label>
                <div class="login-field">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="Enter your LMS email" required value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div class="login-helper-row">
                    <span class="login-helper-text">This creates an account support ticket for manual reset assistance.</span>
                </div>
                <button type="submit" class="login-submit"><i class="fas fa-ticket-alt me-2"></i>Request Password Help</button>
            </form>
            <p class="login-footer-text">&copy; <?= date('Y') ?> <?= e($appName) ?></p>
        </div>
    </div>
    <div class="login-page-right">
        <div class="login-right-content">
            <img src="<?= e($appLogoUrl) ?>" alt="<?= e($appName) ?>" class="login-right-logo">
            <h2>Manual Account Recovery</h2>
            <p>Enter the email tied to your LMS account. A support ticket will be created so the superadmin can follow up and reset your access manually.</p>
            <div class="login-right-pills">
                <span>Email-based request</span>
                <span>Support ticket</span>
                <span>Manual admin follow-up</span>
            </div>
        </div>
    </div>
</div>
</body>
</html>
