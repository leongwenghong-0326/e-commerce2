<?php 

include_once '../config/config.php';

if (isset($_SESSION['admin_id']) && ($_SESSION['role'] ?? '') === 'Admin') {
    header('Location: admin_dashboard.php');
    exit;
}

function sendJsonResponse(int $statusCode, string $message, array $extra = []): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        sendJsonResponse(422, 'Please enter both email and password.');
    }

    // 1. 查询用户及其关联的角色名称
    $sql = "SELECT u.UserId, u.PasswordHash, r.RoleName 
            FROM Users u 
            JOIN Roles r ON u.RoleId = r.RoleId 
            WHERE u.Email = :email LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // 2. 验证密码哈希
        if (password_verify($password, $user['PasswordHash'])) {
            
            // 3. 核心权限校验：如果是 Member 尝试登录 Admin 页面
            if ($user['RoleName'] !== 'Admin') {
                sendJsonResponse(403, 'You do not have administrator privileges to access this page.');
            }

            // 4. 登录成功，更新LastLogin并设置管理员 Session
            $updateSql = "UPDATE Users SET LastLogin = NOW() WHERE UserId = :userId";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->bindParam(':userId', $user['UserId']);
            $updateStmt->execute();

            $_SESSION['admin_id'] = $user['UserId'];
            $_SESSION['role'] = $user['RoleName'];

            sendJsonResponse(200, 'Login successful. Redirecting...', ['redirect' => 'admin_dashboard.php']);
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
    <title>Admin Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../asset/css/admin-layout-responsive.css">
    <link rel="stylesheet" href="../asset/css/admin-login.css">
    
</head>
<body>
    <main class="shell">
        <section class="intro">
            <span class="tag">Admin Portal</span>
            <h1>Control your store with confidence.</h1>
            <p>Only administrator accounts can access this area. Sign in to manage orders, products, and account activity.</p>
            <ul>
                <li>Protected access for admin role only</li>
                <li>Encrypted password verification</li>
                <li>Session-based authentication</li>
            </ul>
        </section>

        <section class="panel">
            <h2>Welcome back</h2>
            <p class="subtitle">Use your admin credentials to continue.</p>

            <form id="loginForm" action="login.php" method="post" novalidate>
                <div class="field">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" autocomplete="email" required>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                </div>

                <button id="submitBtn" type="submit">Sign In</button>
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
                const response = await fetch('login.php', {
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
                    window.setTimeout(() => {
                        window.location.href = result.redirect;
                    }, 650);
                }
            } catch (error) {
                messageBox.textContent = 'Network error. Please try again.';
                messageBox.classList.add('error');
            } finally {
                submitBtn.disabled = false;
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>