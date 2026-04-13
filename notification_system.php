<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/notification.php';

abstract class Notification
{
    protected string $recipient;

    public function __construct(string $recipient)
    {
        $this->recipient = trim($recipient);
    }

    final public function logNotification(string $channel, string $message): void
    {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $line = sprintf(
            "[%s] channel=%s recipient=%s message=%s%s",
            date('Y-m-d H:i:s'),
            $channel,
            $this->recipient,
            str_replace(["\r", "\n"], ' ', $message),
            PHP_EOL
        );

        file_put_contents($logDir . '/notification.log', $line, FILE_APPEND);
    }

    abstract public function send(string $message): void;

    protected function getConfigValue(string $envKey, string $constKey = ''): string
    {
        $fromEnv = trim((string) getenv($envKey));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        if ($constKey !== '' && defined($constKey)) {
            return trim((string) constant($constKey));
        }

        return '';
    }
}

class EmailNotification extends Notification
{
    public function send(string $message): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->getConfigValue('SMTP_HOST', 'NOTIFY_SMTP_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = $this->getConfigValue('SMTP_USERNAME', 'NOTIFY_SMTP_USERNAME');
            $mail->Password = $this->getConfigValue('SMTP_PASSWORD', 'NOTIFY_SMTP_PASSWORD');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int) ($this->getConfigValue('SMTP_PORT', 'NOTIFY_SMTP_PORT') ?: 587);

            if ($mail->Username === '' || $mail->Password === '') {
                $this->logNotification('email', $message . ' | status=simulated');
                return;
            }

            $fromAddress = $this->getConfigValue('SMTP_FROM_EMAIL', 'NOTIFY_SMTP_FROM_EMAIL');
            if ($fromAddress === '') {
                $fromAddress = (string) $mail->Username;
            }
            $fromName = $this->getConfigValue('SMTP_FROM_NAME', 'NOTIFY_SMTP_FROM_NAME');
            if ($fromName === '') {
                $fromName = 'E-commerce';
            }

            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($this->recipient);
            $mail->isHTML(true);
            $mail->Subject = 'Order Confirmation';
            $mail->Body = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
            $mail->AltBody = $message;

            $mail->send();
            $this->logNotification('email', $message . ' | status=sent');
        } catch (Exception $e) {
            $this->logNotification('email', $message . ' | status=failed | error=' . $e->getMessage());
            throw new RuntimeException('Email send failed: ' . $e->getMessage());
        }
    }
}

class OrderService
{
    /**
     * @param Notification[] $notifications
     */
    public function notifyOrderPlaced(array $notifications, string $message): void
    {
        foreach ($notifications as $notification) {
            if (!$notification instanceof Notification) {
                continue;
            }

            $notification->send($message);
        }
    }
}
