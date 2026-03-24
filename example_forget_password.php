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

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
        }

        input {
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

        input:focus {
            border-color: rgba(15, 143, 111, 0.75);
            box-shadow: 0 0 0 4px rgba(15, 143, 111, 0.15);
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