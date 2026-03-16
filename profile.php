<?php

require_once 'config/config.php';

// Redirect if not logged in
if(!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$errors = [];
$success = "";

// Fetch user profile
$stmt = $pdo->prepare("SELECT FirstName, LastName, PhoneNumber, ProfilePhotoUrl FROM UserProfile WHERE UserId = :userId");
$stmt->bindParam(':userId', $userId);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$firstName = $user['FirstName'] ?? '';
$lastName  = $user['LastName'] ?? '';
$phone     = $user['PhoneNumber'] ?? '';
$profilePhoto = $user['ProfilePhotoUrl'] ?? 'assets/default-avatar.png';

// Handle profile update
if(isset($_POST['update_profile'])) {
    $firstName = trim($_POST['first_name']);
    $lastName  = trim($_POST['last_name']);
    $phone     = trim($_POST['phone']);

    // Handle profile photo upload
    if(isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif'];
        if(in_array($ext, $allowed)) {
            $newFileName = 'uploads/avatars/' . uniqid() . '.' . $ext;
            if(!is_dir('uploads/avatars')) mkdir('uploads/avatars', 0755, true);
            move_uploaded_file($_FILES['profile_photo']['tmp_name'], $newFileName);
            $profilePhoto = $newFileName;
        } else {
            $errors[] = "Invalid file type.";
        }
    }

    // Insert or update profile
    $stmtCheck = $pdo->prepare("SELECT ProfileId FROM UserProfile WHERE UserId = :userId");
    $stmtCheck->bindParam(':userId', $userId);
    $stmtCheck->execute();

    if($stmtCheck->rowCount() > 0) {
        $stmtUpdate = $pdo->prepare("
            UPDATE UserProfile SET FirstName=:firstName, LastName=:lastName, PhoneNumber=:phone, ProfilePhotoUrl=:photo
            WHERE UserId=:userId
        ");
        $stmtUpdate->execute([':firstName'=>$firstName, ':lastName'=>$lastName, ':phone'=>$phone, ':photo'=>$profilePhoto, ':userId'=>$userId]);
    } else {
        $stmtInsert = $pdo->prepare("
            INSERT INTO UserProfile (ProfileId, UserId, FirstName, LastName, PhoneNumber, ProfilePhotoUrl)
            VALUES (UUID(), :userId, :firstName, :lastName, :phone, :photo)
        ");
        $stmtInsert->execute([':userId'=>$userId, ':firstName'=>$firstName, ':lastName'=>$lastName, ':phone'=>$phone, ':photo'=>$profilePhoto]);
    }

    $success = "Profile updated successfully.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h2>Update Profile</h2>
    <?php if($errors) echo '<div class="alert alert-danger">'.implode('<br>',$errors).'</div>'; ?>
    <?php if($success) echo '<div class="alert alert-success">'.$success.'</div>'; ?>

    <form method="post" enctype="multipart/form-data">
        <div class="mb-3">
            <label>First Name</label>
            <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($firstName); ?>">
        </div>
        <div class="mb-3">
            <label>Last Name</label>
            <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($lastName); ?>">
        </div>
        <div class="mb-3">
            <label>Phone Number</label>
            <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($phone); ?>">
        </div>
        <div class="mb-3">
            <label>Profile Photo</label>
            <input type="file" name="profile_photo" class="form-control">
            <img src="<?php echo htmlspecialchars($profilePhoto); ?>" class="img-thumbnail mt-2" width="100">
        </div>
        <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
        <a href="index.php" class="btn btn-secondary">Back to Home</a>
        <a href="change_password.php" class="btn btn-warning">Change Password</a>
    </form>
</div>
</body>
</html>