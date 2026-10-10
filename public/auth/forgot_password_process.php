<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Session;
use App\Helpers\Csrf;
use PHPMailer\PHPMailer\PHPMailer;

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$loginUrl = APP_URL . '/public/login.php';
$forgotUrl = APP_URL . '/public/forgot_password.php';

$genericMessage =
    'If an eligible account matches that email address, '
    . 'reset instructions will be sent. Check your inbox.';

$redirectWithMessage = static function (
    string $url,
    string $message
): never {
    Session::set('password_reset_request_message', $message);
    header('Location: ' . $url, true, 303);
    exit;
};

/*
 * Validate CSRF.
 */
$csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;

if (!is_string($csrfToken) || !Csrf::validate($csrfToken)) {
    http_response_code(419);
    exit('Your session has expired. Please refresh the page and try again.');
}

/*
 * Validate email input.
 */
$email = $_POST['email'] ?? '';

if (!is_string($email)) {
    $redirectWithMessage($forgotUrl, $genericMessage);
}

$email = trim($email);

if (
    $email === ''
    || strlen($email) > 150
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false
) {
    $redirectWithMessage($forgotUrl, $genericMessage);
}

/*
 * Basic per-session throttling.
 */
$now = time();
$lastRequest = Session::get('password_reset_last_request', 0);

if (
    is_int($lastRequest)
    && $lastRequest > 0
    && ($now - $lastRequest) < 60
) {
    $redirectWithMessage($forgotUrl, $genericMessage);
}

Session::set('password_reset_last_request', $now);

$db = null;

try {
    /*
     * Verify SMTP and reset configuration.
     */
    if (
        !defined('MAIL_HOST')
        || !defined('MAIL_PORT')
        || !defined('MAIL_ENCRYPTION')
        || !defined('MAIL_USERNAME')
        || !defined('MAIL_PASSWORD')
        || !defined('MAIL_FROM_ADDRESS')
        || !defined('MAIL_FROM_NAME')
        || !defined('PASSWORD_RESET_TTL_MINUTES')
        || MAIL_HOST === ''
        || MAIL_USERNAME === ''
        || MAIL_PASSWORD === ''
        || filter_var(MAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new RuntimeException(
            'Password reset email configuration is incomplete.'
        );
    }

    $ttlMinutes = (int) PASSWORD_RESET_TTL_MINUTES;

    if ($ttlMinutes < 1 || $ttlMinutes > 1440) {
        throw new RuntimeException(
            'Password reset expiration configuration is invalid.'
        );
    }

    $db = Database::connection();

    /*
     * Find an eligible account without revealing whether it exists.
     */
    $stmt = $db->prepare(
        "SELECT id, email
         FROM users
         WHERE email = :email
           AND account_status = 'active'
           AND is_active = 1
         LIMIT 1"
    );

    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !is_string($user['email'] ?? null)) {
        $redirectWithMessage($forgotUrl, $genericMessage);
    }

    /*
     * Generate a cryptographically secure, one-time token.
     * Only its SHA-256 hash is stored in the database.
     */
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $userId = (int) $user['id'];

    $expiresAt = (new DateTimeImmutable(
        'now',
        new DateTimeZone(date_default_timezone_get())
    ))
        ->modify('+' . $ttlMinutes . ' minutes')
        ->format('Y-m-d H:i:s');

    $resetUrl = APP_URL
        . '/public/reset_password.php?token='
        . rawurlencode($rawToken);

    /*
     * Prepare escaped email content.
     */
    $safeAppName = htmlspecialchars(
        (string) APP_NAME,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $safeResetUrl = htmlspecialchars(
        $resetUrl,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $safeTtl = (string) $ttlMinutes;

    /*
     * The logo is embedded in the email instead of linking to
     * localhost, which recipients cannot access.
     */
    $logoPath = dirname(__DIR__, 2)
        . '/public/assets/images/mmgcci-logo-white.png';

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
        throw new RuntimeException(
            'Unsupported SMTP encryption setting.'
        );
    }

    $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
    $mail->addAddress($user['email']);

    /*
     * Embed the existing white company logo when available.
     */
    $hasLogo = is_file($logoPath) && is_readable($logoPath);

    if ($hasLogo) {
        $mail->addEmbeddedImage(
            $logoPath,
            'assetsys-company-logo',
            'company-logo.png',
            'base64',
            'image/png'
        );
    }

    $logoHtml = $hasLogo
        ? '<img src="cid:assetsys-company-logo" '
            . 'alt="' . $safeAppName . '" '
            . 'width="190" '
            . 'style="display:block;width:190px;max-width:100%;'
            . 'height:auto;border:0;margin:0 auto;">'
        : '<div style="font-size:25px;font-weight:700;'
            . 'letter-spacing:1px;color:#ffffff;">'
            . $safeAppName
            . '</div>';

    /*
     * Branded HTML email.
     *
     * Table-based layout and inline CSS improve compatibility
     * with Gmail, Outlook, and other email clients.
     */
    $mail->isHTML(true);
    $mail->Subject = APP_NAME . ' - Password Reset';

    $mail->Body = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;
             font-family:Arial,Helvetica,sans-serif;color:#334155;">

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
           border="0" style="background-color:#f1f5f9;">
        <tr>
            <td align="center" style="padding:35px 14px;">

                <table role="presentation" width="600" cellspacing="0"
                       cellpadding="0" border="0"
                       style="width:100%;max-width:600px;
                              background-color:#ffffff;
                              border-radius:12px;overflow:hidden;">

                    <!-- Company header -->
                    <tr>
                        <td align="center"
                            style="background-color:#005957;
                                   padding:32px 24px 28px;">

                            ' . $logoHtml . '

                            <p style="margin:18px 0 0;color:#ffffff;
                                      font-size:20px;font-weight:bold;
                                      line-height:1.4;">
                                Company Asset Management System
                            </p>

                            <p style="margin:8px 0 0;color:#cce8e6;
                                      font-size:11px;letter-spacing:2px;">
                                ACCOUNT SECURITY
                            </p>
                        </td>
                    </tr>

                    <!-- Main message -->
                    <tr>
                        <td style="padding:36px 32px 20px;">

                            <h1 style="margin:0 0 18px;text-align:center;
                                       color:#0f172a;font-size:25px;
                                       line-height:1.35;">
                                Reset Your Password
                            </h1>

                            <p style="margin:0 0 18px;font-size:15px;
                                      line-height:1.8;color:#475569;">
                                We received a request to reset the password
                                for your account.
                            </p>

                            <p style="margin:0 0 24px;font-size:15px;
                                      line-height:1.8;color:#475569;">
                                Click the button below to choose a new
                                password for your account.
                            </p>

                            <!-- Reset button -->
                            <table role="presentation" cellspacing="0"
                                   cellpadding="0" border="0" align="center"
                                   style="margin:0 auto 26px;">
                                <tr>
                                    <td align="center"
                                        bgcolor="#007472"
                                        style="border-radius:7px;">
                                        <a href="' . $safeResetUrl . '"
                                           target="_blank"
                                           style="display:inline-block;
                                                  padding:15px 30px;
                                                  color:#ffffff;
                                                  font-size:15px;
                                                  font-weight:bold;
                                                  line-height:1.3;
                                                  text-decoration:none;
                                                  border-radius:7px;">
                                            Reset My Password
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Expiry notice -->
                            <table role="presentation" width="100%"
                                   cellspacing="0" cellpadding="0" border="0"
                                   style="background-color:#ecfdf5;
                                          border:1px solid #b7e4d7;
                                          border-radius:7px;">
                                <tr>
                                    <td style="padding:15px 16px;">
                                        <p style="margin:0;color:#166534;
                                                  font-size:13px;
                                                  line-height:1.7;">
                                            <strong>Security notice:</strong>
                                            This link expires in '
                                                . $safeTtl .
                                            ' minutes and can only be used once.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:24px 0 8px;font-size:13px;
                                      line-height:1.8;color:#64748b;">
                                If the button does not work, copy and paste
                                this link into your browser:
                            </p>

                            <p style="margin:0 0 24px;word-break:break-all;
                                      font-size:12px;line-height:1.7;">
                                <a href="' . $safeResetUrl . '"
                                   target="_blank"
                                   style="color:#007472;">
                                    ' . $safeResetUrl . '
                                </a>
                            </p>

                            <hr style="border:0;border-top:1px solid #e2e8f0;
                                       margin:0 0 22px;">

                            <p style="margin:0 0 12px;font-size:13px;
                                      line-height:1.8;color:#64748b;">
                                If you did not request a password reset,
                                you can safely ignore this email. Your
                                password will not change unless the link
                                is used to complete the reset.
                            </p>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center"
                            style="padding:22px 24px;
                                   background-color:#f8fafc;
                                   border-top:1px solid #e2e8f0;">

                            <p style="margin:0 0 7px;color:#475569;
                                      font-size:12px;font-weight:bold;">
                                ' . $safeAppName . '
                            </p>

                            <p style="margin:0;color:#94a3b8;
                                      font-size:11px;line-height:1.7;">
                                Automated security notification.
                                Please do not reply to this email.
                            </p>

                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>';

    /*
     * Plain-text fallback for email clients that do not display HTML.
     */
    $mail->AltBody =
        APP_NAME . " - Password Reset\n\n"
        . "We received a request to reset the password for your account.\n\n"
        . "Open this link to choose a new password:\n"
        . $resetUrl . "\n\n"
        . 'This link expires in ' . $ttlMinutes
        . " minutes and can only be used once.\n\n"
        . "If you did not request this, you can safely ignore this email.\n\n"
        . APP_NAME;

    /*
     * Save the token and send the email.
     * Commit only after PHPMailer reports a successful send.
     */
    $db->beginTransaction();

    $delete = $db->prepare(
        'DELETE FROM password_reset_tokens WHERE user_id = :user_id'
    );

    $delete->execute(['user_id' => $userId]);

    $insert = $db->prepare(
        'INSERT INTO password_reset_tokens
            (user_id, token_hash, expires_at)
         VALUES
            (:user_id, :token_hash, :expires_at)'
    );

    $insert->execute([
        'user_id' => $userId,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
    ]);

    $mail->send();

    $db->commit();

    $redirectWithMessage($forgotUrl, $genericMessage);

} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        error_log(
            'AssetSys forgot-password request failed: '
            . $e->getMessage()
        );
    }

    $redirectWithMessage($forgotUrl, $genericMessage);
}