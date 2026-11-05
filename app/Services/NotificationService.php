<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Notification;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

class NotificationService
{
    private Notification $notificationModel;

    public function __construct()
    {
        $this->notificationModel = new Notification();
    }

    /**
     * Notify all active Asset Managers in-app.
     */
    public function notifyActiveAssetManagers(
        string $type,
        string $title,
        string $message,
        string $relatedType,
        int $relatedId
    ): void {
        $this->safely(function () use ($type, $title, $message, $relatedType, $relatedId): void {
            $this->notificationModel->notifyAllActiveAssetManagers(
                $type,
                $title,
                $message,
                $relatedType,
                $relatedId
            );
        });
    }

    /**
     * Notify one user in-app.
     */
    public function notifyUser(
        int $userId,
        string $type,
        string $title,
        string $message,
        string $relatedType,
        int $relatedId
    ): void {
        $this->safely(function () use ($userId, $type, $title, $message, $relatedType, $relatedId): void {
            $this->notificationModel->notifyUser(
                $userId,
                $type,
                $title,
                $message,
                $relatedType,
                $relatedId
            );
        });
    }

    /**
     * Queue an email for all active Asset Managers.
     */
    public function emailActiveAssetManagers(
        string $subject,
        string $message
    ): void {
        $this->safely(function () use ($subject, $message): void {
            $stmt = Database::connection()->prepare(
                'SELECT DISTINCT
                    u.id,
                    u.email,
                    u.first_name,
                    u.last_name
                FROM users AS u
                INNER JOIN roles AS r
                    ON r.id = u.role_id
                WHERE r.name = :role_name
                  AND r.is_active = 1
                  AND u.account_status = :account_status
                  AND u.is_active = 1
                  AND u.email IS NOT NULL
                  AND TRIM(u.email) <> \'\''
            );

            $stmt->execute([
                ':role_name' => 'Asset Manager',
                ':account_status' => 'active',
            ]);

            foreach ($stmt->fetchAll() as $recipient) {
                $email = trim(
                    (string) ($recipient['email'] ?? '')
                );

                if (
                    filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    ) === false
                ) {
                    error_log(
                        '[AssetSys Email] Skipping invalid Asset Manager '
                        . 'email for user ID '
                        . (int) ($recipient['id'] ?? 0)
                    );

                    continue;
                }

                $this->enqueueEmail(
                    $email,
                    $this->recipientName($recipient),
                    $subject,
                    $message
                );
            }
        });
    }

    /**
     * Queue an email for one active user.
     */
    public function emailUser(
        int $userId,
        string $subject,
        string $message
    ): void {
        if ($userId <= 0) {
            return;
        }

        $this->safely(function () use ($userId, $subject, $message): void {
            $stmt = Database::connection()->prepare(
                'SELECT
                    u.id,
                    u.email,
                    u.first_name,
                    u.last_name
                FROM users AS u
                WHERE u.id = :user_id
                  AND u.account_status = :account_status
                  AND u.is_active = 1
                LIMIT 1'
            );

            $stmt->execute([
                ':user_id' => $userId,
                ':account_status' => 'active',
            ]);

            $recipient = $stmt->fetch();

            if (!$recipient) {
                return;
            }

            $email = trim(
                (string) ($recipient['email'] ?? '')
            );

            if (
                filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                ) === false
            ) {
                error_log(
                    '[AssetSys Email] Skipping invalid recipient email '
                    . 'for user ID ' . $userId
                );

                return;
            }

            $this->enqueueEmail(
                $email,
                $this->recipientName($recipient),
                $subject,
                $message
            );
        });
    }

    /**
     * Store an email for asynchronous delivery.
     */
    private function enqueueEmail(
        string $email,
        string $recipientName,
        string $subject,
        string $message
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO email_queue (
                recipient_email,
                recipient_name,
                subject,
                message,
                status,
                attempts,
                available_at
            ) VALUES (
                :recipient_email,
                :recipient_name,
                :subject,
                :message,
                :status,
                0,
                CURRENT_TIMESTAMP
            )'
        );

        $stmt->execute([
            ':recipient_email' => $email,
            ':recipient_name' => $recipientName,
            ':subject' => $subject,
            ':message' => $message,
            ':status' => 'pending',
        ]);
    }

    /**
     * Process queued emails in a bounded batch.
     */
    public function processPendingEmails(int $limit = 20): int
    {
        $limit = max(1, min($limit, 100));
        $db = Database::connection();
        $processed = 0;
        $maxAttempts = 5;

        /*
         * Recover jobs abandoned by a worker that stopped unexpectedly.
         */
        $db->exec(
            "UPDATE email_queue
             SET status = CASE
                    WHEN attempts >= {$maxAttempts} THEN 'failed'
                    ELSE 'pending'
                 END,
                 available_at = CURRENT_TIMESTAMP,
                 locked_at = NULL,
                 last_error = CASE
                    WHEN last_error IS NULL OR last_error = ''
                        THEN 'Recovered after a stale processing lock.'
                    ELSE last_error
                 END
             WHERE status = 'processing'
               AND locked_at < DATE_SUB(
                    CURRENT_TIMESTAMP,
                    INTERVAL 15 MINUTE
               )"
        );

        while ($processed < $limit) {
            $email = null;

            /*
             * Lock and claim one pending job so concurrent workers
             * cannot claim the same queue row at the same time.
             */
            $db->beginTransaction();

            try {
                $stmt = $db->query(
                    "SELECT
                        id,
                        recipient_email,
                        recipient_name,
                        subject,
                        message,
                        attempts
                     FROM email_queue
                     WHERE status = 'pending'
                       AND available_at <= CURRENT_TIMESTAMP
                     ORDER BY id ASC
                     LIMIT 1
                     FOR UPDATE"
                );

                $email = $stmt->fetch();

                if (!$email) {
                    $db->commit();
                    break;
                }

                $claim = $db->prepare(
                    "UPDATE email_queue
                     SET status = 'processing',
                         attempts = attempts + 1,
                         locked_at = CURRENT_TIMESTAMP
                     WHERE id = :id
                       AND status = 'pending'"
                );

                $claim->execute([
                    ':id' => (int) $email['id'],
                ]);

                if ($claim->rowCount() !== 1) {
                    $db->commit();
                    continue;
                }

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                error_log(
                    '[AssetSys Email Queue] Failed to claim email: '
                    . $e->getMessage()
                );

                throw $e;
            }

            $processed++;
            $errorMessage = null;

            $sent = $this->sendEmail(
                (string) $email['recipient_email'],
                (string) $email['recipient_name'],
                (string) $email['subject'],
                (string) $email['message'],
                $errorMessage
            );

            $attempts = (int) $email['attempts'] + 1;

            if ($sent) {
                $update = $db->prepare(
                    "UPDATE email_queue
                     SET status = 'sent',
                         sent_at = CURRENT_TIMESTAMP,
                         locked_at = NULL,
                         last_error = NULL
                     WHERE id = :id
                       AND status = 'processing'"
                );

                $update->execute([
                    ':id' => (int) $email['id'],
                ]);

                continue;
            }

            $errorMessage = trim(
                (string) ($errorMessage ?? 'Unknown email delivery error.')
            );

            $errorMessage = substr($errorMessage, 0, 4000);

            if ($attempts >= $maxAttempts) {
                $update = $db->prepare(
                    "UPDATE email_queue
                     SET status = 'failed',
                         locked_at = NULL,
                         last_error = :last_error
                     WHERE id = :id
                       AND status = 'processing'"
                );

                $update->execute([
                    ':id' => (int) $email['id'],
                    ':last_error' => $errorMessage,
                ]);
            } else {
                $delayMinutes = min(60, 5 * $attempts);

                $update = $db->prepare(
                    "UPDATE email_queue
                     SET status = 'pending',
                         available_at = DATE_ADD(
                             CURRENT_TIMESTAMP,
                             INTERVAL {$delayMinutes} MINUTE
                         ),
                         locked_at = NULL,
                         last_error = :last_error
                     WHERE id = :id
                       AND status = 'processing'"
                );

                $update->execute([
                    ':id' => (int) $email['id'],
                    ':last_error' => $errorMessage,
                ]);
            }
        }

        return $processed;
    }

    /**
     * Send a branded HTML notification with a plain-text fallback.
     */
    private function sendEmail(
        string $email,
        string $recipientName,
        string $subject,
        string $message,
        ?string &$errorMessage = null
    ): bool {
        $errorMessage = null;

        try {
            if (
                !defined('MAIL_HOST')
                || !defined('MAIL_PORT')
                || !defined('MAIL_ENCRYPTION')
                || !defined('MAIL_USERNAME')
                || !defined('MAIL_PASSWORD')
                || !defined('MAIL_FROM_ADDRESS')
                || !defined('MAIL_FROM_NAME')
                || MAIL_HOST === ''
                || MAIL_USERNAME === ''
                || MAIL_FROM_ADDRESS === ''
            ) {
                $errorMessage = 'SMTP configuration is incomplete.';

                error_log('[AssetSys Email] ' . $errorMessage);

                return false;
            }

            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = MAIL_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = MAIL_USERNAME;
            $mail->Password = MAIL_PASSWORD;
            $mail->Port = MAIL_PORT;
            $mail->CharSet = 'UTF-8';

            if (MAIL_ENCRYPTION === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif (MAIL_ENCRYPTION === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $errorMessage = 'Unsupported SMTP encryption setting.';

                error_log('[AssetSys Email] ' . $errorMessage);

                return false;
            }

            $logoPath = dirname(__DIR__, 2)
                . '/public/assets/images/mmgcci-logo-white.png';

            $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
            $mail->addAddress($email, $recipientName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $this->buildHtmlEmail(
                $recipientName,
                $subject,
                $message,
                is_file($logoPath)
            );
            $mail->AltBody = trim(
                "Hello {$recipientName},\n\n"
                . $message
                . "\n\n"
                . APP_NAME
                . "\nThis is an automated notification."
            );

            if (is_file($logoPath)) {
                $mail->addEmbeddedImage(
                    $logoPath,
                    'assetsys_header_logo',
                    'mmgcci-logo-white.png',
                    'base64',
                    'image/png'
                );
            }

            $mail->send();

            return true;
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();

            error_log(
                '[AssetSys Email] Failed to send notification: '
                . $errorMessage
            );

            return false;
        }
    }

    /**
     * Build the reusable HTML notification email.
     */
    private function buildHtmlEmail(
        string $recipientName,
        string $subject,
        string $message,
        bool $hasLogo
    ): string {
        $safeName = $this->escapeHtml($recipientName);
        $safeSubject = $this->escapeHtml($subject);
        $safeMessage = nl2br(
            $this->escapeHtml($message),
            false
        );
        $safeAppName = $this->escapeHtml(APP_NAME);

        $logoHtml = $hasLogo
            ? '<img src="cid:assetsys_header_logo"'
            . ' alt="' . $safeAppName . '"'
            . ' style="display:block;width:100%;max-width:560px;'
            . 'height:auto;margin:0 auto;border:0;">'
            : '<div style="font-size:22px;font-weight:700;'
            . 'color:#007472;">' . $safeAppName . '</div>';

        $actionUrl = '';
        $actionLabel = '';

        if (defined('APP_URL') && APP_URL !== '') {
            if (stripos($subject, 'transfer') !== false) {
                $actionUrl = base_url(
                    '/public/assets/department_transfers.php'
                );
                $actionLabel = 'View Asset Transfers';
            } elseif (stripos($subject, 'disposal') !== false) {
                $actionUrl = base_url(
                    '/public/assets/disposal_requests.php'
                );
                $actionLabel = 'View Disposal Requests';
            }
        }

        $buttonHtml = '';

        if ($actionUrl !== '' && $actionLabel !== '') {
            $safeActionUrl = $this->escapeHtml($actionUrl);
            $safeActionLabel = $this->escapeHtml($actionLabel);

            $buttonHtml = '
                <tr>
                    <td style="padding:8px 32px 28px;text-align:center;">
                        <a href="' . $safeActionUrl . '"
                           style="display:inline-block;background:#007472;
                           color:#ffffff;text-decoration:none;font-weight:600;
                           padding:12px 24px;border-radius:6px;">
                            ' . $safeActionLabel . '
                        </a>
                    </td>
                </tr>';
        }

        return '<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $safeSubject . '</title>
</head>
<body style="margin:0;padding:0;background:#f3f6f6;
             font-family:Arial,Helvetica,sans-serif;color:#263238;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
           style="background:#f3f6f6;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0"
                       cellpadding="0"
                       style="max-width:620px;background:#ffffff;
                              border-radius:10px;overflow:hidden;">
                    <tr>
                        <td style="background:#007472;padding:22px 28px;
                                   text-align:center;">
                            ' . $logoHtml . '
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 32px 12px;">
                            <p style="margin:0 0 12px;color:#667575;
                                      font-size:13px;">ASSET NOTIFICATION</p>
                            <h1 style="margin:0 0 20px;font-size:23px;
                                       line-height:1.35;color:#163d3c;">
                                ' . $safeSubject . '
                            </h1>
                            <p style="margin:0 0 18px;font-size:15px;
                                      line-height:1.7;">Hello '
            . $safeName . ',</p>
                            <div style="font-size:15px;line-height:1.8;
                                        color:#374747;">'
            . $safeMessage .
            '</div>
                        </td>
                    </tr>
                    ' . $buttonHtml . '
                    <tr>
                        <td style="border-top:1px solid #e5eded;
                                   background:#f9fbfb;padding:20px 32px;
                                   text-align:center;">
                            <p style="margin:0 0 6px;font-size:13px;
                                      font-weight:600;color:#007472;">'
            . $safeAppName .
            '</p>
                            <p style="margin:0;font-size:12px;line-height:1.6;
                                      color:#748282;">
                                This is an automated notification. Please do
                                not reply directly to this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Escape dynamic values before inserting them into HTML.
     */
    private function escapeHtml(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    /**
     * Build a safe display name for an email recipient.
     */
    private function recipientName(array $recipient): string
    {
        $name = trim(
            (string) ($recipient['first_name'] ?? '')
            . ' '
            . (string) ($recipient['last_name'] ?? '')
        );

        return $name !== ''
            ? $name
            : 'AssetSys User';
    }

    /**
     * Log in-app notification errors instead of propagating them.
     */
    private function safely(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $e) {
            error_log(
                '[AssetSys Notifications] '
                . $e->getMessage()
            );
        }
    }
}
