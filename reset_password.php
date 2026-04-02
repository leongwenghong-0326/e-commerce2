<?php
require_once 'config/config.php';

$user       = null;
$tokenError = '';

if (!isset($_GET['token']) || trim($_GET['token']) === '') {
    $tokenError = 'No reset token provided.';
} else {
    $token = trim($_GET['token']);

    $stmt = $pdo->prepare("SELECT UserId, ResetTokenExpiry FROM Users WHERE ResetToken = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();

    if (!$user || $user['ResetTokenExpiry'] < date('Y-m-d H:i:s')) {
        $tokenError = 'This reset link is invalid or has expired.';
        $user = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    header('Content-Type: application/json; charset=utf-8');

    $newPassword     = $_POST['new_password']     ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        http_response_code(422);
        echo json_encode(['message' => 'Password must be at least 8 characters.']);
        exit();
    }

    if ($newPassword !== $confirmPassword) {
        http_response_code(422);
        echo json_encode(['message' => 'Passwords do not match.']);
        exit();
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE Users SET PasswordHash = :password, ResetToken = NULL, ResetTokenExpiry = NULL WHERE UserId = :user_id")
        ->execute([':password' => $passwordHash, ':user_id' => $user['UserId']]);

    echo json_encode(['message' => 'Password reset successfully. Redirecting to login...', 'redirect' => 'member_login.php']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce | Reset Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="asset/css/member-theme.css">
    <link rel="stylesheet" href="asset/css/member-reset-password.css">
    
</head>
<body>
    <div class="card">
        <div class="icon-wrap">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 0 1 21.75 8.25Z"/>
            </svg>
        </div>

        <h1>Reset Password</h1>
        <p class="sub">Choose a strong new password for your account.</p>

        <?php if ($tokenError): ?>
            <div class="error-banner"><?php echo htmlspecialchars($tokenError); ?></div>
            <a class="back-link" href="forget_password.php">&larr; Request a new reset link</a>
        <?php else: ?>
            <form id="resetForm" method="POST" novalidate>
                <div class="field">
                    <label for="new_password">New Password <span class="field-note">(min 8 characters)</span></label>
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
                </div>
                <div class="field">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                </div>

                <button type="submit" id="submitBtn">Reset Password</button>
                <p id="message" class="message" aria-live="polite"></p>
            </form>

            <a class="back-link" href="member_login.php">&larr; Back to Login</a>

            <script>
                const form      = document.getElementById('resetForm');
                const msgBox    = document.getElementById('message');
                const submitBtn = document.getElementById('submitBtn');

                form.addEventListener('submit', async (e) => {
                    e.preventDefault();

                    msgBox.textContent = '';
                    msgBox.className = 'message';
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Resetting...';

                    try {
                        const resp   = await fetch(window.location.href, {
                            method: 'POST',
                            body: new FormData(form),
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        const result = await resp.json();

                        msgBox.textContent = result.message || 'Unexpected server response.';
                        msgBox.classList.add(resp.ok ? 'success' : 'error');

                        if (resp.ok && result.redirect) {
                            setTimeout(() => { window.location.href = result.redirect; }, 1400);
                        }
                    } catch {
                        msgBox.textContent = 'Network error. Please try again.';
                        msgBox.classList.add('error');
                    } finally {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Reset Password';
                    }
                });
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
