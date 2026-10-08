<?php

declare(strict_types=1);

namespace App\Core;

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
        Session::destroy();
    }
}