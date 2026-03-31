<?php
// auth.php - Authentication and MFA system

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

initSession();

if (!empty($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

function send_mfa_email($email, $code) {
    $subject = 'Your MFA code';
    $message = "Your MFA code is: $code";
    $headers = "From: no-reply@yourdomain.com\r\n" .
               "Reply-To: no-reply@yourdomain.com\r\n" .
               "X-Mailer: PHP/" . phpversion();
    @mail($email, $subject, $message, $headers);
}

function send_mfa_sms($phone, $code) {
    // Implement your SMS provider API here (Twilio, etc.)
    return true;
}

function require_user_auth() {
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['mfa_verified'])) {
        return;
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'register') {
        $pdo = getDB();
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if (empty($username) || empty($email)) {
            $error = 'Username and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Password confirmation does not match.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
            $stmt->execute([':username' => $username, ':email' => $email]);
            if ($stmt->fetch()) {
                $error = 'A user with that username or email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $insert = $pdo->prepare('INSERT INTO users (username, password, email, role, is_active) VALUES (:username, :password, :email, \'student\', 1)');
                $insert->execute([':username' => $username, ':password' => $hash, ':email' => $email]);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?registered=1');
                exit;
            }
        }
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'login') {
        $pdo = getDB();
        $loginId = trim($_POST['login_id'] ?? '');
        $password = $_POST['password'] ?? '';
        $stmt = $pdo->prepare('SELECT id, password, email FROM users WHERE (username = :login OR email = :login) AND is_active = 1 LIMIT 1');
        $stmt->execute([':login' => $loginId]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['mfa_verified'] = false;
            $_SESSION['mfa_code'] = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['mfa_expires'] = time() + 300; // 5 minutes

            if (!empty($user['email'])) {
                send_mfa_email($user['email'], $_SESSION['mfa_code']);
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?mfa=1');
            exit;
        } else {
            $error = 'Invalid login or password.';
        }
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'verify') {
        $code = trim($_POST['mfa_code'] ?? '');
        if (isset($_SESSION['mfa_code'], $_SESSION['mfa_expires'], $_SESSION['user_id']) && time() <= $_SESSION['mfa_expires'] && hash_equals($_SESSION['mfa_code'], $code)) {
            $_SESSION['mfa_verified'] = true;
            unset($_SESSION['mfa_code'], $_SESSION['mfa_expires']);
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = 'Invalid or expired MFA code.';
        }
    }

    if (!empty($_SESSION['user_id']) && empty($_SESSION['mfa_verified'])) {
        echo '<h2>Enter MFA Code</h2>';
        if (!empty($error)) {
            echo '<div style="color: red;">' . htmlspecialchars($error) . '</div>';
        }
        echo '<form method="POST">';
        echo '<input type="hidden" name="action" value="verify">';
        echo '<label>MFA Code: <input name="mfa_code" required></label><br>';
        echo '<button type="submit">Verify</button>';
        echo '</form>';
        exit;
    }

    if (!empty($_GET['register'])) {
        echo '<h2>Register</h2>';
        if (!empty($error)) {
            echo '<div style="color: red;">' . htmlspecialchars($error) . '</div>';
        }
        echo '<form method="POST">';
        echo '<input type="hidden" name="action" value="register">';
        echo '<label>Username: <input name="username" required></label><br>';
        echo '<label>Email: <input name="email" required></label><br>';
        echo '<label>Password: <input name="password" type="password" required></label><br>';
        echo '<label>Confirm Password: <input name="password_confirm" type="password" required></label><br>';
        echo '<button type="submit">Register</button>';
        echo '</form>';
        echo '<a href="?">Back to login</a>';
        exit;
    }

    echo '<h2>Login</h2>';
    if (!empty($error)) {
        echo '<div style="color: red;">' . htmlspecialchars($error) . '</div>';
    }
    if (!empty($_GET['registered'])) {
        echo '<div style="color: green;">Registration successful. Please login.</div>';
    }
    echo '<form method="POST">';
    echo '<input type="hidden" name="action" value="login">';
    echo '<label>Username or Email: <input name="login_id" required></label><br>';
    echo '<label>Password: <input name="password" type="password" required></label><br>';
    echo '<button type="submit">Login</button>';
    echo '</form>';
    echo '<a href="?register=1">Create account</a>';
    exit;
}
?>
