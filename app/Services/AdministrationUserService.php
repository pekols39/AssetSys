<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

class AdministrationUserService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Get all user accounts for administrator management.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $stmt = $this->db->query(
            'SELECT
            u.id AS user_id,
            u.employee_number,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.username,
            u.email,
            u.position,
            u.account_status,
            u.must_change_password,
            u.is_active,
            u.last_login_at,
            r.name AS role_name,
            d.name AS department_name
         FROM users u
         INNER JOIN roles r
            ON r.id = u.role_id
         LEFT JOIN departments d
            ON d.id = u.department_id
         ORDER BY
            u.last_name ASC,
            u.first_name ASC,
            u.username ASC'
        );

        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($users)) {
            throw new RuntimeException(
                'Unable to load user accounts.'
            );
        }

        return $users;
    }

    /**
     * Get one user account by ID.
     */
    public function findById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT
            u.id AS user_id,
            u.employee_number,
            u.role_id,
            u.department_id,
            u.position,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.username,
            u.email,
            u.account_status,
            u.must_change_password,
            u.is_active,
            u.last_login_at,
            r.name AS role_name,
            d.name AS department_name
         FROM users u
         INNER JOIN roles r
            ON r.id = u.role_id
         LEFT JOIN departments d
            ON d.id = u.department_id
         WHERE u.id = :user_id
         LIMIT 1'
        );

        $stmt->execute([
            'user_id' => $userId,
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user === false) {
            return null;
        }

        return $user;
    }

    /**
     * Update an administrator-managed user account.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $userId, array $data): bool
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException(
                'Invalid user account.'
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE users
         SET
            employee_number = :employee_number,
            first_name = :first_name,
            middle_name = :middle_name,
            last_name = :last_name,
            username = :username,
            email = :email,
            role_id = :role_id,
            department_id = :department_id,
            position = :position,
            account_status = :account_status,
            is_active = :is_active,
            must_change_password = :must_change_password
         WHERE id = :user_id
         LIMIT 1'
        );

        $stmt->execute([
            'employee_number' =>
                $data['employee_number'] ?? null,

            'first_name' =>
                $data['first_name'] ?? null,

            'middle_name' =>
                $data['middle_name'] ?? null,

            'last_name' =>
                $data['last_name'] ?? null,

            'username' =>
                $data['username'] ?? null,

            'email' =>
                $data['email'] ?? null,

            'role_id' =>
                (int) ($data['role_id'] ?? 0),

            'department_id' =>
                $data['department_id'] !== null
                ? (int) $data['department_id']
                : null,

            'position' => $data['position'] ?? null,

            'account_status' =>
                $data['account_status'] ?? 'active',

            'is_active' =>
                (int) ($data['is_active'] ?? 0),

            'must_change_password' =>
                (int) ($data['must_change_password'] ?? 0),

            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }
}