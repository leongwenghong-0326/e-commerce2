<?php 

include_once 'config/config.php';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $email = $_POST['email'];
    $password = $_POST['pass'];
    $confirmPassword = $_POST['confirm_pass'];
    $firstName = $_POST['first_name'];
    $lastName = $_POST['last_name'];
    $phone = $_POST['phone'];

    $targetDir = "uploads/avatars/";
    $defaultAvatar = "asset/image/default_avatar.png";
    $profilePhotoUrl = $defaultAvatar;

    if(isset($_FILES['avatar']) && $_FILES['avatar']['error']=== UPLOAD_ERR_OK){
        $fileTmpPath = $_FILES['avatar']['tmp_name'];
        $fileName = $_FILES['avatar']['name'];
        $fileExtension = strtolower(pathinfo($fileName,PATHINFO_EXTENSION));

        $allowedExt = ['jpg','jpeg','png','gif'];
        if(in_array($fileExtension, $allowedExt)){
            //Use UUID to generate a unique file name to avoid conflicts
            $newFileName = vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex(random_bytes(16)),4)) . '.' . $fileExtension;
            $destPath = $targetDir . $newFileName;

            if(move_uploaded_file($fileTmpPath, $destPath)){
                $profilePhotoUrl = $destPath;
            } else {
                echo json_encode(["error" => "Failed to upload avatar."]);
                exit();
            }
        }
    }

    try{
        $pdo->beginTransaction();

        $userId = vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex(random_bytes(16)),4));

        if($password !== $confirmPassword){
            echo json_encode(["error" => "Passwords do not match."]);
            exit();
        }
        $passwordHash = password_hash($confirmPassword,PASSWORD_DEFAULT);

        $roleStmt = $pdo->prepare("SELECT RoleId FROM Roles WHERE `RoleName` =  'Member' LIMIT 1");
        $roleStmt->execute();
        $role = $roleStmt->fetchColumn();

        $insUser = $pdo->prepare("INSERT INTO Users (`UserId`,`RoleId`,`Email`,`PasswordHash`,`CreatedDate`)VALUES (?,?,?,?,?)");
        $insUser->execute([$userId, $role, $email, $passwordHash, date('Y-m-d H:i:s')]);

        $profileId = vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex(random_bytes(16)),4));
        $insProfile = $pdo->prepare("INSERT INTO UserProfile (`ProfileId`,`UserId`,`FirstName`,`LastName`,`PhoneNumber`,`ProfilePhotoUrl`,`CreateDate`)VALUES (?,?,?,?,?,?,?)");
        $insProfile->execute([$profileId, $userId, $firstName, $lastName, $phone, $profilePhotoUrl, date('Y-m-d H:i:s')]);

        $pdo->commit();
        echo json_encode(["message" => "Registration successful. You can now log in."]);
        exit();
    }catch(Exception $e){
        $pdo->rollBack();
        echo json_encode(["error" => "An error occurred during registration. Please try again."]);
        exit();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce | Register</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="asset/css/member-theme.css">
    <link rel="stylesheet" href="asset/css/member-register.css">
    
</head>
<body>
    <main class="shell">
        <section class="hero">
            <span class="pill">Member Area</span>
            <h1>Join and start shopping today.</h1>
            <p>Create your free account to access orders, saved addresses, and faster checkout.</p>
            <ul>
                <li>Fast checkout with saved profile data</li>
                <li>Track order updates in one place</li>
                <li>Secure account with password protection</li>
            </ul>
        </section>

        <section class="panel">
            <h2>Create Account</h2>
            <p class="sub">Fill in your details to get started.</p>

            <form id="registerForm" novalidate enctype="multipart/form-data">
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" autocomplete="email" required>
                </div>

                <div class="field">
                    <label for="pass">Password</label>
                    <input type="password" id="pass" name="pass" autocomplete="new-password" required>
                </div>

                <div class="field">
                    <label for="confirm_pass">Confirm Password</label>
                    <input type="password" id="confirm_pass" name="confirm_pass" autocomplete="new-password" required>
                </div>

                <hr class="divider">

                <div class="field-row">
                    <div>
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" autocomplete="given-name" required>
                    </div>
                    <div>
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" autocomplete="family-name" required>
                    </div>
                </div>

                <div class="field">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" autocomplete="tel">
                </div>

                <div class="field">
                    <label for="avatar">Profile Photo <span class="field-note">(optional)</span></label>
                    <input type="file" id="avatar" name="avatar" accept="image/*">
                </div>

                <div class="actions">
                    <button type="submit" id="submitBtn">Register</button>
                    <a href="member_login.php">Already have an account?</a>
                </div>

                <p id="message" class="message" aria-live="polite"></p>
            </form>
        </section>
    </main>

    <script>
        const registerForm = document.getElementById('registerForm');
        const messageBox  = document.getElementById('message');
        const submitBtn   = document.getElementById('submitBtn');

        registerForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            messageBox.textContent = '';
            messageBox.className = 'message';
            submitBtn.disabled = true;

            try {
                const response = await fetch('member_register.php', {
                    method: 'POST',
                    body: new FormData(registerForm),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const result = await response.json();
                const isSuccess = response.ok && result.message;

                messageBox.textContent = result.message || result.error || 'Unexpected response from server.';
                messageBox.classList.add(isSuccess ? 'success' : 'error');

                if (isSuccess) {
                    registerForm.reset();
                    setTimeout(() => {
                        window.location.href = 'member_login.php';
                    }, 1500);
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
