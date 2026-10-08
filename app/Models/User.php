<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class User
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findById(int $id): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
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
            u.password AS password_hash,
            u.account_status,
            u.must_change_password,
            u.failed_attempts,
            u.locked_until,
            u.password_changed_at,
            u.last_login_at,
            u.is_active,
            r.name AS role_name,
            d.name AS department_name
        FROM users u
        INNER JOIN roles r
            ON r.id = u.role_id
        LEFT JOIN departments d
            ON d.id = u.department_id
        WHERE u.id = :id
        LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch();
    }

    public function findByUsername(string $username): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT
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
            u.password AS password_hash,
            u.account_status,
            u.must_change_password,
            u.failed_attempts,
            u.locked_until,
            u.password_changed_at,
            u.last_login_at,
            u.is_active,
            r.name AS role_name,
            d.name AS department_name
        FROM users u
        INNER JOIN roles r
            ON r.id = u.role_id
        LEFT JOIN departments d
            ON d.id = u.department_id
        WHERE u.username = :username
        LIMIT 1'
        );

        $stmt->execute([
            'username' => $username,
        ]);

        return $stmt->fetch();
    }

    public function updateLastLogin(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET last_login_at = NOW()
             WHERE id = :id
             LIMIT 1'
        );

        return $stmt->execute([
            'id' => $id,
        ]);
    }

    public function resetFailedAttempts(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET failed_attempts = 0,
                 locked_until = NULL
             WHERE id = :id
             LIMIT 1'
        );

        return $stmt->execute([
            'id' => $id,
        ]);
    }

    public function incrementFailedAttempts(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET failed_attempts = failed_attempts + 1
             WHERE id = :id
             LIMIT 1'
        );

        return $stmt->execute([
            'id' => $id,
        ]);
    }

    public function lockUntil(int $id, string $lockedUntil): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET locked_until = :locked_until
             WHERE id = :id
             LIMIT 1'
        );

        return $stmt->execute([
            'id' => $id,
            'locked_until' => $lockedUntil,
        ]);
    }
    public function updatePassword(
        int $id,
        string $passwordHash
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE users
         SET password = :password,
             password_changed_at = NOW(),
             must_change_password = 0,
             failed_attempts = 0,
             locked_until = NULL
         WHERE id = :id
         LIMIT 1'
        );

        return $stmt->execute([
            'id' => $id,
            'password' => $passwordHash,
        ]);
    }
}