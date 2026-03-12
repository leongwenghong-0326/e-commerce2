<?php
require_once 'config/config.php';

$message = "";
$showForm = false;
$token = "";

if (isset($_GET['token'])) {
    $token = trim($_GET['token']);
} elseif (isset($_POST['token'])) {
    $token = trim($_POST['token']);
}

if (!empty($token)) {
    // Validate token and expiry
    $sql = "SELECT UserId FROM Users WHERE ResetToken = :token AND ResetTokenExpiry > NOW() LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $showForm = true;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'], $_POST['confirm_password'])) {
            $newPassword = $_POST['new_password'];
            $confirmPassword = $_POST['confirm_password'];

            if (strlen($newPassword) < 8) { // Increased to 8 for better security
                $message = "Password must be at least 8 characters.";
            } elseif ($newPassword !== $confirmPassword) {
                $message = "Passwords do not match.";
            } else {
                try {
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                    // Update and BURN the token immediately
                    $updateSql = "UPDATE Users SET Password = :password, ResetToken = NULL, ResetTokenExpiry = NULL WHERE UserId = :userId";
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([
                        ':password' => $passwordHash,
                        ':userId' => $user['UserId']
                    ]);

                    header("Location: login.php?reset=success");
                    exit;
                } catch (PDOException $e) {
                    $message = "Database error. Please try again later.";
                }
            }
        }
    } else {
        $message = "Invalid or expired token.";
    }
} else {
    $message = "No token provided.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce || Reset Password</title>
</head>
<body>
    <h2>Reset Password</h2>

    <?php if($message): ?>
        <p style="color:red;"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <?php if($showForm): ?>
        <form method="POST" action="">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

            <label for="new_password">New Password:</label>
            <input type="password" id="new_password" name="new_password" required minlength="8"><br><br>

            <label for="confirm_password">Confirm Password:</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8"><br><br>

            <button type="submit">Reset Password</button>
        </form>
    <?php endif; ?>
</body>
</html>