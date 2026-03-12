<?php
require_once 'config/config.php';

$message = "";
$showForm = false;
$token = "";

// Step 1: Get token from GET or POST
if(isset($_GET['token'])){
    $token = $_GET['token'];
} elseif(isset($_POST['token'])){
    $token = $_POST['token'];
}

// Step 2: Validate token
if(!empty($token)){
    $sql = "SELECT UserId FROM Users WHERE ResetToken = :token AND ResetTokenExpiry > NOW() LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if($user){
        $showForm = true;

        // Step 3: Handle form submission
        if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'], $_POST['confirm_password'])){
            $newPassword = trim($_POST['new_password']);
            $confirmPassword = trim($_POST['confirm_password']);

            // Validate password
            if(strlen($newPassword) < 6){
                $message = "Password must be at least 6 characters.";
            } elseif($newPassword !== $confirmPassword){
                $message = "Passwords do not match.";
            } else {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                // Update password and clear token/expiry
                $updateSql = "UPDATE Users SET Password = :password, ResetToken = NULL, ResetTokenExpiry = NULL WHERE UserId = :userId";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([
                    ':password' => $passwordHash,
                    ':userId' => $user['UserId']
                ]);

                $message = "Password has been reset successfully!";
                $showForm = false;
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
        <p><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <?php if($showForm): ?>
        <form method="POST" action="">
            <!-- Keep token hidden -->
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

            <label for="new_password">New Password:</label>
            <input type="password" id="new_password" name="new_password" required><br><br>

            <label for="confirm_password">Confirm Password:</label>
            <input type="password" id="confirm_password" name="confirm_password" required><br><br>

            <button type="submit">Reset Password</button>
        </form>
    <?php endif; ?>
</body>
</html>