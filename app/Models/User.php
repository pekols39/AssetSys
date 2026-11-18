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
            u.session_version,
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
            u.session_version,
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

    /**
     * Atomically record a failed login and apply the configured lockout.
     *
     * The user row is locked inside a transaction so concurrent failed
     * login requests cannot calculate the threshold from stale counters.
     *
     * @return array{
     *     failed_attempts:int,
     *     locked:bool,
     *     newly_locked:bool,
     *     locked_until:?string
     * }
     */
    public function recordFailedLoginAttempt(
        int $id,
        int $maxAttempts,
        int $lockoutMinutes
    ): array {
        $maxAttempts = max(1, $maxAttempts);
        $lockoutMinutes = max(1, $lockoutMinutes);

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'SELECT failed_attempts, locked_until
                 FROM users
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['id' => $id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new RuntimeException(
                    'The account could not be found while recording a failed login.'
                );
            }

            $now = time();
            $lockedUntil = $user['locked_until'] ?? null;

            if (
                $lockedUntil !== null
                && $lockedUntil !== ''
                && strtotime((string) $lockedUntil) > $now
            ) {
                $this->db->commit();

                return [
                    'failed_attempts' => (int) $user['failed_attempts'],
                    'locked' => true,
                    'newly_locked' => false,
                    'locked_until' => (string) $lockedUntil,
                ];
            }

            // Start a fresh counter after a previous lockout has expired.
            $currentAttempts = (
                $lockedUntil !== null
                && $lockedUntil !== ''
                && strtotime((string) $lockedUntil) <= $now
            ) ? 0 : (int) $user['failed_attempts'];

            $failedAttempts = $currentAttempts + 1;
            $newlyLocked = $failedAttempts >= $maxAttempts;
            $newLockedUntil = $newlyLocked
                ? date('Y-m-d H:i:s', $now + ($lockoutMinutes * 60))
                : null;

            $update = $this->db->prepare(
                'UPDATE users
                 SET failed_attempts = :failed_attempts,
                     locked_until = :locked_until
                 WHERE id = :id
                 LIMIT 1'
            );
            $update->execute([
                'failed_attempts' => $failedAttempts,
                'locked_until' => $newLockedUntil,
                'id' => $id,
            ]);

            $this->db->commit();

            return [
                'failed_attempts' => $failedAttempts,
                'locked' => $newlyLocked,
                'newly_locked' => $newlyLocked,
                'locked_until' => $newLockedUntil,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
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
             locked_until = NULL,
             session_version = session_version + 1
         WHERE id = :id
         LIMIT 1'
        );

        return $stmt->execute([
            'id' => $id,
            'password' => $passwordHash,
        ]);
    }
}