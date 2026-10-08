<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Models\User;
use App\Models\Setting;
use RuntimeException;

class AuthService
{
    private User $userModel;
    private Setting $settingModel;
    public function __construct()
    {
        $this->userModel = new User();
        $this->settingModel = new Setting();
    }

    public function login(
        string $username,
        string $password
    ): array {
        $username = trim($username);

        if ($username === '' || $password === '') {
            throw new RuntimeException(
                'Username and password are required.'
            );
        }

        $user = $this->userModel->findByUsername($username);

        if (!$user) {
            throw new RuntimeException(
                'Invalid username or password.'
            );
        }

        /*
         * --------------------------------------------------------
         * Account status
         * --------------------------------------------------------
         */

        if (
            (int) ($user['is_active'] ?? 0) !== 1
            || ($user['account_status'] ?? '') !== 'active'
        ) {
            throw new RuntimeException(
                'Invalid username or password.'
            );
        }

        /*
         * --------------------------------------------------------
         * Existing account lock
         * --------------------------------------------------------
         */

        $lockedUntil = $user['locked_until'] ?? null;

        if (
            $lockedUntil !== null
            && $lockedUntil !== ''
            && strtotime((string) $lockedUntil) > time()
        ) {
            throw new RuntimeException(
                'Your account is temporarily locked. Please try again later.'
            );
        }

        /*
         * --------------------------------------------------------
         * Password verification
         * --------------------------------------------------------
         */

        if (
            !isset($user['password_hash'])
            || !password_verify(
                $password,
                (string) $user['password_hash']
            )
        ) {
            $failedAttempts =
                ((int) ($user['failed_attempts'] ?? 0)) + 1;

            $maxAttempts = max(
                1,
                $this->settingModel->getInt(
                    'login_max_attempts',
                    5
                )
            );

            $lockoutMinutes = max(
                1,
                $this->settingModel->getInt(
                    'login_lockout_minutes',
                    15
                )
            );

            if ($failedAttempts >= $maxAttempts) {

                $lockedUntil = date(
                    'Y-m-d H:i:s',
                    time() + ($lockoutMinutes * 60)
                );

                $this->userModel->lockUntil(
                    (int) $user['user_id'],
                    $lockedUntil
                );

                throw new RuntimeException(
                    'Too many failed login attempts. '
                    . 'Your account has been temporarily locked.'
                );
            }

            $this->userModel->incrementFailedAttempts(
                (int) $user['user_id']
            );

            throw new RuntimeException(
                'Invalid username or password.'
            );
        }

        /*
         * --------------------------------------------------------
         * Successful login
         * --------------------------------------------------------
         */

        $this->userModel->resetFailedAttempts(
            (int) $user['user_id']
        );

        $this->userModel->updateLastLogin(
            (int) $user['user_id']
        );

        unset($user['password_hash']);

        $sessionUser = [
            'user_id' => (int) $user['user_id'],
            'role_id' => (int) $user['role_id'],
            'department_id' => $user['department_id'] !== null
                ? (int) $user['department_id']
                : null,
            'employee_number' => $user['employee_number'],
            'first_name' => $user['first_name'],
            'middle_name' => $user['middle_name'],
            'last_name' => $user['last_name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role_name' => $user['role_name'],
            'department_name' => $user['department_name'],
            'position' => $user['position'] ?? null,
            'must_change_password' => (int) $user['must_change_password'],
            'password_changed_at' => $user['password_changed_at'],
        ];

        Auth::login($sessionUser);

        return $sessionUser;
    }
}