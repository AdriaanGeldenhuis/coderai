<?php
if (!defined('CODERAI')) die('Direct access not allowed');

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crypto.php';

// Redirect if already logged in
if (Auth::check()) {
    header('Location: /dashboard');
    exit;
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Please enter your email';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } else {
        $db = Bootstrap::getDB();

        // Check if email exists
        $stmt = $db->prepare("SELECT id, name FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate reset token
            $token = Crypto::randomToken(32);
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Store token (we'll use sessions table for simplicity)
            $stmt = $db->prepare("
                INSERT INTO sessions (user_id, token, expires_at, ip_address, user_agent)
                VALUES (?, ?, ?, ?, 'PASSWORD_RESET')
            ");
            $stmt->execute([$user['id'], 'reset_' . $token, $expires, $_SERVER['REMOTE_ADDR']]);

            // In production, send email here
            // For now, show the reset link
            $resetLink = "https://coderai.co.za/reset-password?token=" . $token;
            $success = "Reset link generated! In production, this would be emailed. For now: <br><small style='word-break:break-all;'>{$resetLink}</small>";
        } else {
            // Don't reveal if email exists
            $success = 'If this email exists, you will receive a password reset link.';
        }
    }
}

$csrf = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - CoderAI</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
        }
        h1 { color: #1a1a2e; margin-bottom: 10px; font-size: 28px; }
        .subtitle { color: #666; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
        input[type="email"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e1e1;
            border-radius: 6px;
            font-size: 16px;
        }
        input:focus { outline: none; border-color: #4a90d9; }
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #4a90d9 0%, #1a1a2e 100%);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(74,144,217,0.4); }
        .error { background: #fee; color: #c00; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
        .success { background: #efe; color: #060; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
        .links { margin-top: 20px; text-align: center; }
        .links a { color: #4a90d9; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Forgot Password</h1>
        <p class="subtitle">Enter your email to reset password</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?= $success ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <button type="submit">Send Reset Link</button>
        </form>

        <div class="links">
            <a href="/login">← Back to Login</a>
        </div>
    </div>
</body>
</html>
