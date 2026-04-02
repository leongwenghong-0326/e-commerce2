
<?php

include_once 'config/config.php';

function sanitizeRedirectTarget(?string $redirect): string
{
    $default = 'index.php';
    $redirect = trim((string) $redirect);

    if ($redirect === '') {
        return $default;
    }

    if (str_starts_with($redirect, '//')) {
        return $default;
    }

    $parts = parse_url($redirect);
    if ($parts === false) {
        return $default;
    }

    if (isset($parts['scheme']) || isset($parts['host'])) {
        return $default;
    }

    $path = strtolower((string) ($parts['path'] ?? ''));
    if ($path === 'member_login.php' || str_ends_with($path, '/member_login.php')) {
        return $default;
    }

    return $redirect;
}

$requestedRedirect = (string) ($_POST['redirect'] ?? ($_GET['redirect'] ?? ''));
$safeRedirect = sanitizeRedirectTarget($requestedRedirect);

if (isset($_SESSION['user_id'])) {
    header('Location: ' . $safeRedirect);
    exit();
}

function sendJsonResponse(int $statusCode, string $message, array $extra = []): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        sendJsonResponse(422, 'Please enter both email and password.');
    }

    // Query user with linked role name
    $sql = "SELECT * 
            FROM Users u 
            JOIN Roles r ON u.RoleId = r.RoleId 
            WHERE u.Email = :email LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        if ((int)$user['IsActive'] === 0) {
            sendJsonResponse(403, 'Your account is inactive. Please contact support.');
        }

        if (password_verify($password, $user['PasswordHash'])) {
            // Login success: update LastLogin and set session
            $updateSql = "UPDATE Users SET LastLogin = NOW() WHERE UserId = :userId";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->bindParam(':userId', $user['UserId']);
            $updateStmt->execute();

            $_SESSION['user_id'] = $user['UserId'];
            $_SESSION['role'] = $user['RoleName'];

            sendJsonResponse(200, 'Login successful. Redirecting...', ['redirect' => $safeRedirect]);
        } else {
            sendJsonResponse(401, 'Invalid email or password.');
        }
    } else {
        sendJsonResponse(401, 'User does not exist.');
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce | Member Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="asset/css/member-theme.css">
    <link rel="stylesheet" href="asset/css/member-login.css">
    
</head>
<body>
    <main class="shell">
        <section class="hero">
            <span class="pill">Member Area</span>
            <h1>Sign in and keep shopping smooth.</h1>
            <p>Access your orders, saved addresses, and account settings with your member account.</p>
            <ul>
                <li>Fast checkout with saved profile data</li>
                <li>Track order updates in one place</li>
                <li>Secure password verification</li>
            </ul>
        </section>

        <section class="panel">
            <h2>Welcome back</h2>
            <p class="sub">Log in to continue your shopping journey.</p>

            <form id="loginForm" action="member_login.php" method="post" novalidate>
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($safeRedirect); ?>">
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" autocomplete="email" required>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                </div>

                <div class="actions">
                    <button type="submit" id="submitBtn">Login</button>
                    <a href="forget_password.php">Forgot Password?</a>
                </div>

                <p class="sub auth-switch">
                    Don't have an account?
                    <a href="member_register.php">Register here</a>
                </p>

                <p id="message" class="message" aria-live="polite"></p>
            </form>
        </section>
    </main>

    <script>
        const loginForm = document.getElementById('loginForm');
        const messageBox = document.getElementById('message');
        const submitBtn = document.getElementById('submitBtn');

        loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            messageBox.textContent = '';
            messageBox.className = 'message';
            submitBtn.disabled = true;

            try {
                const response = await fetch('member_login.php', {
                    method: 'POST',
                    body: new FormData(loginForm),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const result = await response.json();
                const isSuccess = response.ok;

                messageBox.textContent = result.message || 'Unexpected response from server.';
                messageBox.classList.add(isSuccess ? 'success' : 'error');

                if (isSuccess && result.redirect) {
                    setTimeout(() => {
                        window.location.href = result.redirect;
                    }, 700);
                }
            } catch (error) {
                messageBox.textContent = 'Network error. Please try again.';
                messageBox.classList.add('error');
            } finally {
                submitBtn.disabled = false;
            }
        });
    </script>
</body>
</html>
