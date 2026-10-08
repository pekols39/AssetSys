<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\Session;

class Csrf
{
    public static function generate(): string
    {
        $token = Session::get(CSRF_TOKEN_NAME);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));

            Session::set(
                CSRF_TOKEN_NAME,
                $token
            );
        }

        return $token;
    }

    public static function validate(?string $token): bool
    {
        $sessionToken = Session::get(CSRF_TOKEN_NAME);

        if (
            !is_string($token)
            || $token === ''
            || !is_string($sessionToken)
            || $sessionToken === ''
        ) {
            return false;
        }

        return hash_equals(
            $sessionToken,
            $token
        );
    }

    public static function field(): string
    {
        return '<input type="hidden" name="'
            . htmlspecialchars(
                CSRF_TOKEN_NAME,
                ENT_QUOTES,
                'UTF-8'
            )
            . '" value="'
            . htmlspecialchars(
                self::generate(),
                ENT_QUOTES,
                'UTF-8'
            )
            . '">';
    }
}