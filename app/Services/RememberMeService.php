<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

class RememberMeService
{
    private PDO $db;

    private const COOKIE_NAME = 'assetsys_remember';

    private const COOKIE_LIFETIME = 2592000; // 30 days

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function cleanupExpiredTokens(): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM remember_tokens
         WHERE expires_at <= NOW()'
        );

        $stmt->execute();
    }

    public function createToken(int $userId): void
    {

        $this->cleanupExpiredTokens();

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));

        $validatorHash = hash(
            'sha256',
            $validator
        );

        $expiresAt = date(
            'Y-m-d H:i:s',
            time() + self::COOKIE_LIFETIME
        );

        $stmt = $this->db->prepare(
            'INSERT INTO remember_tokens
                (
                    user_id,
                    selector,
                    validator_hash,
                    expires_at
                )
             VALUES
                (
                    :user_id,
                    :selector,
                    :validator_hash,
                    :expires_at
                )'
        );

        $stmt->execute([
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => $validatorHash,
            'expires_at' => $expiresAt,
        ]);

        $cookieValue = $selector . ':' . $validator;

        setcookie(
            self::COOKIE_NAME,
            $cookieValue,
            [
                'expires' => time() + self::COOKIE_LIFETIME,
                'path' => '/',
                'secure' => (
                    isset($_SERVER['HTTPS'])
                    && $_SERVER['HTTPS'] !== 'off'
                ),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    public function attemptAutoLogin(): bool
    {
        $this->cleanupExpiredTokens();

        if (
            !isset($_COOKIE[self::COOKIE_NAME])
            || !is_string($_COOKIE[self::COOKIE_NAME])
        ) {
            return false;
        }

        $parts = explode(
            ':',
            $_COOKIE[self::COOKIE_NAME],
            2
        );

        if (count($parts) !== 2) {
            $this->expireCookie();
            return false;
        }

        [$selector, $validator] = $parts;

        if (
            $selector === ''
            || $validator === ''
        ) {
            $this->expireCookie();
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT
                rt.id,
                rt.user_id,
                rt.selector,
                rt.validator_hash,
                rt.expires_at,
                u.id AS user_id,
                u.role_id,
                u.department_id,
                u.position,
                u.employee_number,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.username,
                u.email,
                u.account_status,
                u.must_change_password,
                u.password_changed_at,
                u.session_version,
                r.name AS role_name,
                d.name AS department_name
            FROM remember_tokens rt
            INNER JOIN users u
                ON u.id = rt.user_id
            INNER JOIN roles r
                ON r.id = u.role_id
            LEFT JOIN departments d
                ON d.id = u.department_id
            WHERE rt.selector = :selector
            AND rt.expires_at > NOW()
            AND u.is_active = 1
            AND u.account_status = :account_status
            LIMIT 1'
        );

        $stmt->execute([
            'selector' => $selector,
            'account_status' => 'active',
        ]);

        $token = $stmt->fetch();

        if (!$token) {
            $this->expireCookie();
            return false;
        }

        $validatorHash = hash(
            'sha256',
            $validator
        );

        if (
            !hash_equals(
                (string) $token['validator_hash'],
                $validatorHash
            )
        ) {
            $this->revokeSelector($selector);
            $this->expireCookie();

            return false;
        }

        $user = [
            'user_id' => (int) $token['user_id'],
            'role_id' => (int) $token['role_id'],
            'department_id' => $token['department_id'] !== null
                ? (int) $token['department_id']
                : null,
            'employee_number' => $token['employee_number'],
            'first_name' => $token['first_name'],
            'middle_name' => $token['middle_name'],
            'last_name' => $token['last_name'],
            'username' => $token['username'],
            'email' => $token['email'],
            'role_name' => $token['role_name'],
            'department_name' => $token['department_name'],
            'position' => $token['position'] ?? null,
            'must_change_password' => (int) $token['must_change_password'],
            'password_changed_at' => $token['password_changed_at'],
            'session_version' => (int) $token['session_version'],
        ];

        $this->rotateToken(
            (int) $token['id'],
            (int) $token['user_id']
        );

        \App\Core\Auth::login($user);

        return true;
    }


    public function clearToken(): void
    {
        $cookieValue = $_COOKIE[self::COOKIE_NAME] ?? null;

        if (is_string($cookieValue) && $cookieValue !== '') {
            $parts = explode(':', $cookieValue, 2);

            if (count($parts) === 2 && $parts[0] !== '') {
                $stmt = $this->db->prepare(
                    'DELETE FROM remember_tokens
                 WHERE selector = :selector'
                );

                $stmt->execute([
                    'selector' => $parts[0],
                ]);
            }
        }

        // Always expire the browser cookie, even if it is missing
        // or malformed in the current PHP request.
        $this->expireCookie();
    }


    public function clearAllTokensForUser(int $userId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM remember_tokens
             WHERE user_id = :user_id'
        );

        $stmt->execute([
            'user_id' => $userId,
        ]);

        $this->expireCookie();
    }

    private function revokeSelector(string $selector): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM remember_tokens
         WHERE selector = :selector'
        );

        $stmt->execute([
            'selector' => $selector,
        ]);
    }

    private function rotateToken(
        int $tokenId,
        int $userId
    ): void {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));

        $validatorHash = hash(
            'sha256',
            $validator
        );

        $expiresAt = date(
            'Y-m-d H:i:s',
            time() + self::COOKIE_LIFETIME
        );

        $stmt = $this->db->prepare(
            'UPDATE remember_tokens
         SET selector = :selector,
             validator_hash = :validator_hash,
             expires_at = :expires_at,
             last_used_at = NOW()
         WHERE id = :id
           AND user_id = :user_id
         LIMIT 1'
        );

        $stmt->execute([
            'selector' => $selector,
            'validator_hash' => $validatorHash,
            'expires_at' => $expiresAt,
            'id' => $tokenId,
            'user_id' => $userId,
        ]);

        $cookieValue = $selector . ':' . $validator;

        setcookie(
            self::COOKIE_NAME,
            $cookieValue,
            [
                'expires' => time() + self::COOKIE_LIFETIME,
                'path' => '/',
                'secure' => (
                    isset($_SERVER['HTTPS'])
                    && $_SERVER['HTTPS'] !== 'off'
                ),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    private function expireCookie(): void
    {
        setcookie(
            self::COOKIE_NAME,
            '',
            [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => (
                    isset($_SERVER['HTTPS'])
                    && $_SERVER['HTTPS'] !== 'off'
                ),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        unset($_COOKIE[self::COOKIE_NAME]);
    }
}