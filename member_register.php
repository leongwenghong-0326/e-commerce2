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
    <style>
        :root {
            --bg-start: #f4fbf8;
            --bg-end: #e8f4ff;
            --ink: #1b2530;
            --panel: rgba(255, 255, 255, 0.82);
            --line: rgba(27, 37, 48, 0.14);
            --accent: #0f8f6f;
            --accent-strong: #0b6f56;
            --success: #176d43;
            --danger: #b32020;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Outfit', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 10% 15%, rgba(15, 143, 111, 0.22), transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(39, 124, 198, 0.18), transparent 35%),
                linear-gradient(135deg, var(--bg-start), var(--bg-end));
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .shell {
            width: min(980px, 100%);
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-radius: 22px;
            overflow: hidden;
            border: 1px solid var(--line);
            box-shadow: 0 22px 48px rgba(10, 36, 60, 0.14);
            background: var(--panel);
            backdrop-filter: blur(10px);
            animation: rise 420ms ease-out;
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .hero {
            padding: 48px;
            border-right: 1px solid var(--line);
            background:
                linear-gradient(170deg, rgba(255, 255, 255, 0.38), rgba(255, 255, 255, 0.1)),
                repeating-linear-gradient(140deg, transparent, transparent 16px, rgba(27, 37, 48, 0.03) 16px, rgba(27, 37, 48, 0.03) 32px);
        }

        .hero .pill {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 600;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.6);
        }

        .hero h1 {
            margin: 16px 0 10px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(2rem, 5vw, 3rem);
            line-height: 1.05;
            letter-spacing: -0.02em;
            max-width: 11ch;
        }

        .hero p {
            margin: 0;
            line-height: 1.55;
            max-width: 34ch;
            opacity: 0.88;
        }

        .hero ul {
            list-style: none;
            padding: 0;
            margin: 22px 0 0;
            display: grid;
            gap: 8px;
            font-size: 14px;
        }

        .hero li::before {
            content: '• ';
            color: var(--accent-strong);
            font-weight: 700;
        }

        .panel {
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .panel h2 {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.8rem;
            letter-spacing: -0.01em;
        }

        .panel .sub {
            margin: 8px 0 22px;
            font-size: 14px;
            opacity: 0.82;
        }

        .field {
            margin-bottom: 14px;
        }

        .field-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }

        .divider {
            border: none;
            border-top: 1px solid var(--line);
            margin: 18px 0;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"] {
            width: 100%;
            border: 1px solid rgba(27, 37, 48, 0.2);
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 15px;
            font-family: inherit;
            background: rgba(255, 255, 255, 0.92);
            transition: border-color 160ms ease, box-shadow 160ms ease;
            outline: none;
        }

        input[type="email"]:focus,
        input[type="password"]:focus,
        input[type="text"]:focus {
            border-color: rgba(15, 143, 111, 0.75);
            box-shadow: 0 0 0 4px rgba(15, 143, 111, 0.15);
        }

        input[type="file"] {
            width: 100%;
            font-size: 13px;
            font-family: inherit;
            padding: 8px 0;
            color: var(--ink);
        }

        .actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 6px;
        }

        button {
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: #fff;
            padding: 12px 16px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            min-width: 128px;
            transition: transform 160ms ease, box-shadow 160ms ease;
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 16px rgba(11, 111, 86, 0.35);
        }

        button:disabled {
            opacity: 0.7;
            cursor: wait;
            transform: none;
            box-shadow: none;
        }

        a {
            color: #11589b;
            text-decoration: none;
            font-weight: 500;
        }

        a:hover {
            text-decoration: underline;
        }

        .message {
            margin-top: 14px;
            min-height: 22px;
            font-size: 14px;
            font-weight: 600;
        }

        .message.success { color: var(--success); }
        .message.error   { color: var(--danger); }

        @media (max-width: 860px) {
            .shell {
                grid-template-columns: 1fr;
            }

            .hero {
                border-right: none;
                border-bottom: 1px solid var(--line);
                padding: 32px;
            }

            .panel {
                padding: 32px;
            }

            .field-row {
                grid-template-columns: 1fr;
            }

            .actions {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
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
                    <label for="avatar">Profile Photo <span style="font-weight:400;opacity:.7;font-size:12px;">(optional)</span></label>
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