<?php
if (!defined('CODERAI')) die('Direct access not allowed');

require_once __DIR__ . '/../core/Crypto.php';

$error = '';
$success = '';
$validToken = false;
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = 'Invalid reset link';
} else {
    $db = Bootstrap::getDB();

    // Verify token
    $stmt = $db->prepare("
        SELECT s.user_id, u.email
        FROM sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.token = ? AND s.expires_at > NOW() AND s.user_agent = 'PASSWORD_RESET'
    ");
    $stmt->execute(['reset_' . $token]);
    $result = $stmt->fetch();

    if ($result) {
        $validToken = true;

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $newPassword = $_POST['password'] ?? '';
            $confirmPassword = $_POST['password_confirm'] ?? '';

            if (strlen($newPassword) < 8) {
                $error = 'Password must be at least 8 characters';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Passwords do not match';
            } else {
                // Update password
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $result['user_id']]);

                // Delete reset token
                $stmt = $db->prepare("DELETE FROM sessions WHERE token = ?");
                $stmt->execute(['reset_' . $token]);

                $success = 'Password reset successfully! You can now login.';
                $validToken = false;
            }
        }
    } else {
        $error = 'Invalid or expired reset link';
    }
}

$csrf = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - CoderAI</title>
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
        .form-group { margin-bottom: 20px; position: relative; }
        label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
        input[type="password"], input[type="text"] {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid #e1e1e1;
            border-radius: 6px;
            font-size: 16px;
        }
        input:focus { outline: none; border-color: #4a90d9; }
        .toggle-password {
            position: absolute; right: 12px; top: 38px;
            cursor: pointer; color: #666; font-size: 18px;
        }
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
        .error { background: #fee; color: #c00; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
        .success { background: #efe; color: #060; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
        .links { margin-top: 20px; text-align: center; }
        .links a { color: #4a90d9; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Reset Password</h1>
        <p class="subtitle">Enter your new password</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($validToken): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <div class="form-group">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" required minlength="8">
                <span class="toggle-password" onclick="togglePassword('password')">👁</span>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirm Password</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
                <span class="toggle-password" onclick="togglePassword('password_confirm')">👁</span>
            </div>

            <button type="submit">Reset Password</button>
        </form>
        <?php endif; ?>

        <div class="links">
            <a href="/login">← Back to Login</a>
        </div>
    </div>

    <script>
    function togglePassword(id) {
        var pwd = document.getElementById(id);
        var icon = pwd.nextElementSibling;
        if (pwd.type === 'password') {
            pwd.type = 'text';
            icon.textContent = '🙈';
        } else {
            pwd.type = 'password';
            icon.textContent = '👁';
        }
    }
    </script>
</body>
</html>
