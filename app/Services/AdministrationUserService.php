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
    /**
     * Create an administrator-managed user account.
     *
     * Accounts created here are restricted to Finance and the
     * Asset Manager / Property Custodian roles.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $password = $data['initial_password'] ?? null;

        if (
            !is_string($password)
            || strlen($password) < 12
            || strlen($password) > 72
        ) {
            throw new \InvalidArgumentException(
                'The initial password must be between 12 and 72 bytes long.'
            );
        }

        $roleId = filter_var(
            $data['role_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($roleId === false || $roleId === null || $roleId <= 0) {
            throw new \InvalidArgumentException(
                'A valid account role is required.'
            );
        }

        $roleStmt = $this->db->prepare(
            'SELECT id
             FROM roles
             WHERE id = :role_id
               AND is_active = 1
               AND name IN (
                   \'Asset Manager\',
                   \'Property Custodian\'
               )
             LIMIT 1'
        );

        $roleStmt->execute([
            'role_id' => $roleId,
        ]);

        if ($roleStmt->fetchColumn() === false) {
            throw new \InvalidArgumentException(
                'Only active Asset Manager and Property Custodian roles are allowed.'
            );
        }

        $financeDepartmentId = 3;

        $departmentStmt = $this->db->prepare(
            'SELECT id
             FROM departments
             WHERE id = :department_id
               AND LOWER(TRIM(name)) = \'finance\'
               AND is_active = 1
             LIMIT 1'
        );

        $departmentStmt->execute([
            'department_id' => $financeDepartmentId,
        ]);

        if ($departmentStmt->fetchColumn() === false) {
            throw new RuntimeException(
                'The active Finance department could not be found.'
            );
        }

        $passwordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        if (!is_string($passwordHash)) {
            throw new RuntimeException(
                'Unable to secure the initial password.'
            );
        }

        $lockName = 'assetsys_fin_employee_number';
        $lockAcquired = false;

        try {
            $lockStmt = $this->db->prepare(
                'SELECT GET_LOCK(:lock_name, 10)'
            );

            $lockStmt->execute([
                'lock_name' => $lockName,
            ]);

            $lockResult = $lockStmt->fetchColumn();

            if ((string) $lockResult !== '1') {
                throw new RuntimeException(
                    'Unable to acquire the employee-number generation lock.'
                );
            }

            $lockAcquired = true;

            /*
             * Read matching employee numbers from every department.
             * The advisory lock serializes account creation through
             * this service, while the unique index remains the final
             * safeguard against duplicate employee numbers.
             */
            $numbersStmt = $this->db->query(
                'SELECT employee_number
                 FROM users
                 WHERE employee_number REGEXP \'^[Ff][Ii][Nn]-[0-9]+$\''
            );

            $usedNumbers = [];

            foreach (
                $numbersStmt->fetchAll(PDO::FETCH_COLUMN)
                as $existingNumber
            ) {
                if (
                    is_string($existingNumber)
                    && preg_match(
                        '/^FIN-(\d+)$/i',
                        $existingNumber,
                        $matches
                    )
                ) {
                    $usedNumbers[(int) $matches[1]] = true;
                }
            }

            $nextNumber = 1;

            while (isset($usedNumbers[$nextNumber])) {
                $nextNumber++;
            }

            $employeeNumber = sprintf(
                'Fin-%04d',
                $nextNumber
            );

            $stmt = $this->db->prepare(
                'INSERT INTO users (
                    role_id,
                    department_id,
                    position,
                    employee_number,
                    first_name,
                    middle_name,
                    last_name,
                    username,
                    email,
                    password,
                    account_status,
                    must_change_password,
                    is_active
                ) VALUES (
                    :role_id,
                    :department_id,
                    :position,
                    :employee_number,
                    :first_name,
                    :middle_name,
                    :last_name,
                    :username,
                    :email,
                    :password,
                    :account_status,
                    1,
                    :is_active
                )'
            );

            $stmt->execute([
                'role_id' => $roleId,
                'department_id' => $financeDepartmentId,
                'position' => $data['position'] ?? null,
                'employee_number' => $employeeNumber,
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $passwordHash,
                'account_status' => 'active',
                'is_active' => 1,
            ]);

            return (int) $this->db->lastInsertId();
        } finally {
            if ($lockAcquired) {
                $releaseStmt = $this->db->prepare(
                    'SELECT RELEASE_LOCK(:lock_name)'
                );

                $releaseStmt->execute([
                    'lock_name' => $lockName,
                ]);
            }
        }
    }
}