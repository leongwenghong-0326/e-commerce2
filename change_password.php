<?php

require_once 'config/config.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$errors = [];
$success = "";

if(isset($_POST['update_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword     = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    $stmt = $pdo->prepare("SELECT PasswordHash FROM Users WHERE UserId=:userId");
    $stmt->bindParam(':userId', $userId);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!password_verify($currentPassword, $user['PasswordHash'])) {
        $errors[] = "Current password is incorrect.";
    } elseif($newPassword !== $confirmPassword) {
        $errors[] = "New password and confirm password do not match.";
    } elseif(strlen($newPassword) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmtUpdate = $pdo->prepare("UPDATE Users SET PasswordHash=:hash WHERE UserId=:userId");
        $stmtUpdate->execute([':hash'=>$newHash, ':userId'=>$userId]);
        $success = "Password updated successfully.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h2>Change Password</h2>
    <?php if($errors) echo '<div class="alert alert-danger">'.implode('<br>',$errors).'</div>'; ?>
    <?php if($success) echo '<div class="alert alert-success">'.$success.'</div>'; ?>

    <form method="post">
        <div class="mb-3">
            <label>Current Password</label>
            <input type="password" name="current_password" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>New Password</label>
            <input type="password" name="new_password" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" required>
        </div>
        <button type="submit" name="update_password" class="btn btn-warning">Change Password</button>
        <a href="profile.php" class="btn btn-secondary">Back to Profile</a>
    </form>
</div>
</body>
</html>