<?php

include_once 'config/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

if($_SERVER['REQUEST_METHOD']=="POST"){

    $email = $_POST['email'];

    $sql = "SELECT * FROM Users u JOIN Roles r 
            ON u.RoleId = r.RoleId WHERE u.Email = :email 
            AND r.RoleName = 'Member' LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch();

    if($user){
        $token = bin2hex(random_bytes(16));
        $expiry_time = date("Y-m-d H:i:s", strtotime("+ 5 minutes"));

        $update_sql = "UPDATE Users SET ResetToken = :token,
                       ResetTokenExpiry = :expiry WHERE Email = :email";
        $update = $pdo ->prepare($update_sql);
        $update->execute([
            ':token' => $token,
            ':expiry' => $expiry_time,
            ':email' => $user['Email']
        ]);

        $mail = new PHPMailer(true);

        try{
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'your_email@gmail.com';
            $mail->Password = 'your_email_password';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('your_email@gmail.com','E-commerce');
            $mail->addAddress($email);

            $resetLink = $base_url . "/e-commerce2/reset_password.php?token=" . $token;

            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Request';
            $mail->Body = "Click the link to reset your password: <a href='$resetLink'>$resetLink</a>";
            $mail->AltBody = "The link will expire in 5 minutes.";

            $mail->send();
            echo json_encode(["message" => "Password reset link has been sent to your email."]);
            exit();

        }catch(Exception $e){
            http_response_code(500);
            echo json_encode(["message" => "Failed to send email. Please try again later."]);
            exit();
        }
    }else{
        http_response_code(404);
        echo json_encode(["message" => "Email not found."]);
        exit();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce | Forgot Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="asset/css/member-theme.css">
    <link rel="stylesheet" href="asset/css/member-example-forget-password.css">
</head>
<body>
    <main class="shell">
        <section class="hero">
            <span class="pill">Account Recovery</span>
            <h1>Reset your password easily.</h1>
            <p>Enter your registered email and we'll send you a reset link right away.</p>
            <ul>
                <li>Reset link expires in 5 minutes</li>
                <li>Check your spam folder if needed</li>
                <li>Contact support if you need further help</li>
            </ul>
        </section>

        <section class="panel">
            <h2>Forgot Password</h2>
            <p class="sub">We'll email you a link to reset your password.</p>

            <form id="forgotForm" novalidate>
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" autocomplete="email" required>
                </div>

                <div class="actions">
                    <button type="submit" id="submitBtn">Send Reset Link</button>
                    <a href="member_login.php">Back to Login</a>
                </div>

                <p id="message" class="message" aria-live="polite"></p>
            </form>
        </section>
    </main>

    <script>
        const forgotForm = document.getElementById('forgotForm');
        const messageBox = document.getElementById('message');
        const submitBtn  = document.getElementById('submitBtn');

        forgotForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            messageBox.textContent = '';
            messageBox.className = 'message';
            submitBtn.disabled = true;

            try {
                const response = await fetch('forget_password.php', {
                    method: 'POST',
                    body: new FormData(forgotForm),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const result = await response.json();
                const isSuccess = response.ok;

                messageBox.textContent = result.message || 'Unexpected response from server.';
                messageBox.classList.add(isSuccess ? 'success' : 'error');
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
