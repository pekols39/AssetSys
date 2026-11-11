<?php

declare(strict_types=1);

namespace App\Core;
use App\Services\AuditLogService;
use Throwable;

class Auth
{
    public static function check(): bool
    {
        Session::start();

        return isset($_SESSION['user']);
    }

    public static function user(): ?array
    {
        Session::start();

        $user = $_SESSION['user'] ?? null;

        return is_array($user) ? $user : null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        if (!$user || !isset($user['user_id'])) {
            return null;
        }

        return (int) $user['user_id'];
    }

    public static function login(array $user): void
    {
        Session::regenerate();

        Session::set('user', $user);
        Session::set('LAST_ACTIVITY', time());
    }

    public static function logout(): void
    {
        // Capture the authenticated user before destroying the session.
        $user = self::user();

        if ($user !== null) {
            try {
                (new AuditLogService())->log(
                    action: 'logout',
                    module: 'authentication',
                    entityType: 'user',
                    entityId: isset($user['user_id'])
                    ? (int) $user['user_id']
                    : null,
                    description: 'User session ended.',
                    newValues: [
                        'logout_status' => 'successful',
                    ]
                );
            } catch (Throwable $e) {
                error_log(
                    'Logout audit logging failed: '
                    . $e->getMessage()
                );
            }
        }

        // Preserve the existing session destruction behavior.
        Session::destroy();
    }
}