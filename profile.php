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

// =====================
// FETCH PROFILE
// =====================
$stmt = $pdo->prepare("
    SELECT FirstName, LastName, PhoneNumber, ProfilePhotoUrl 
    FROM UserProfile 
    WHERE UserId = :userId
");
$stmt->execute([':userId' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$firstName = $user['FirstName'] ?? '';
$lastName  = $user['LastName'] ?? '';
$phone     = $user['PhoneNumber'] ?? '';

// Default avatar
$defaultAvatar = 'asset/image/default_image.png';
$profilePhoto = $defaultAvatar;

if(!empty($user['ProfilePhotoUrl']) && file_exists(__DIR__ . '/' . $user['ProfilePhotoUrl'])) {
    $profilePhoto = $user['ProfilePhotoUrl'];
}

// =====================
// FETCH DEFAULT ADDRESS
// =====================
$stmtAddress = $pdo->prepare("
    SELECT AddressLine1, AddressLine2, City, State, Postcode
    FROM Addresses
    WHERE UserId = :userId AND IsDefault = 1
    LIMIT 1
");
$stmtAddress->execute([':userId' => $userId]);
$address = $stmtAddress->fetch(PDO::FETCH_ASSOC) ?: [];

// =====================
// HANDLE PROFILE UPDATE
// =====================
if(isset($_POST['update_profile'])) {
    $firstName = trim($_POST['first_name']);
    $lastName  = trim($_POST['last_name']);
    $phone     = trim($_POST['phone']);

    if(empty($firstName) || empty($lastName)) {
        $errors[] = "First name and last name are required.";
    }

    // Handle profile photo
    if(isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == 0) {
        $allowedTypes = ['image/jpeg','image/png','image/gif'];
        $fileTmpPath = $_FILES['profile_photo']['tmp_name'];
        $fileType = @mime_content_type($fileTmpPath);

        if(!in_array($fileType, $allowedTypes)) {
            $errors[] = "Invalid image type.";
        } else {
            $targetDir = 'uploads/avatars/';
            if(!is_dir($targetDir)) mkdir($targetDir, 0755, true);

            $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
            $newFileName = $targetDir . uniqid('avatar_', true) . '.' . $ext;

            if(move_uploaded_file($fileTmpPath, $newFileName)){
                $profilePhoto = $newFileName;
            } else {
                $errors[] = "Failed to upload avatar.";
            }
        }
    }

    if(empty($errors)) {
        $stmtCheck = $pdo->prepare("SELECT ProfileId FROM UserProfile WHERE UserId = :userId");
        $stmtCheck->execute([':userId' => $userId]);

        if($stmtCheck->rowCount() > 0) {
            $stmtUpdate = $pdo->prepare("
                UPDATE UserProfile 
                SET FirstName=:firstName, LastName=:lastName, PhoneNumber=:phone, ProfilePhotoUrl=:photo
                WHERE UserId=:userId
            ");
            $stmtUpdate->execute([
                ':firstName'=>$firstName,
                ':lastName'=>$lastName,
                ':phone'=>$phone,
                ':photo'=>$profilePhoto,
                ':userId'=>$userId
            ]);
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO UserProfile (ProfileId, UserId, FirstName, LastName, PhoneNumber, ProfilePhotoUrl)
                VALUES (UUID(), :userId, :firstName, :lastName, :phone, :photo)
            ");
            $stmtInsert->execute([
                ':userId'=>$userId,
                ':firstName'=>$firstName,
                ':lastName'=>$lastName,
                ':phone'=>$phone,
                ':photo'=>$profilePhoto
            ]);
        }

        $success = "Profile updated successfully.";
    }
}

// =====================
// NAVBAR INFO VARIABLES FOR nav.php
// =====================
$userAvatar = $profilePhoto;
$fullName   = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
if(empty($fullName)) $fullName = "User";

// =====================
// INCLUDE NAVBAR
// =====================
require_once 'layout/nav.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile</title>
</head>
<body class="p-4">

<div class="container">
    <h2>My Profile</h2>

    <?php if($errors): ?>
        <div class="alert alert-danger"><?php echo implode('<br>', $errors); ?></div>
    <?php endif; ?>

    <?php if($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

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
        <br><br>
        <a href="change_password.php" class="btn btn-warning">Change Password</a>
    </form>

    <!-- ADDRESS SECTION -->
    <div class="card mt-4">
        <div class="card-header"><h5>📍 My Default Address</h5></div>
        <div class="card-body">
            <?php if($address): ?>
                <?php
                    $parts = array_filter([
                        $address['AddressLine1'] ?? null,
                        $address['AddressLine2'] ?? null,
                        $address['Postcode'] ?? null,
                        $address['City'] ?? null,
                        $address['State'] ?? null
                    ]);
                    $fullAddress = implode(', ', $parts);
                ?>
                <p><?php echo htmlspecialchars($fullAddress); ?></p>
            <?php else: ?>
                <p class="text-muted">No address added yet.</p>
            <?php endif; ?>
            <a href="change_address.php" class="btn btn-primary mt-2">Manage Addresses</a>
        </div>
    </div>
    <br>
    <a href="index.php" class="btn btn-secondary">Back</a>
</div>

</body>
</html>