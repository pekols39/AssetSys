<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Models\User;
use App\Models\Setting;
use RuntimeException;
use Throwable;

class AuthService
{
    private User $userModel;
    private Setting $settingModel;

    public function __construct()
    {
        $this->userModel = new User();
        $this->settingModel = new Setting();
    }

    /**
     * Record authentication events without allowing an audit failure
     * to interrupt the existing authentication process.
     */
    private function logAuthenticationEvent(
        string $action,
        string $description,
        string $result = 'success',
        ?int $actorUserId = null,
        array $newValues = []
    ): void {
        try {
            (new AuditLogService())->log(
                action: $action,
                module: 'authentication',
                entityType: 'user',
                description: $description,
                newValues: $newValues,
                result: $result,
                actorUserId: $actorUserId
            );
        } catch (Throwable $e) {
            error_log(
                'Authentication audit logging failed: '
                . $e->getMessage()
            );
        }
    }

    public function login(
        string $username,
        string $password
    ): array {
        $username = trim($username);

        if ($username === '' || $password === '') {
            $this->logAuthenticationEvent(
                action: 'login_failed',
                description: 'Login failed because required credentials were missing.',
                result: 'failure',
                newValues: [
                    'reason' => 'missing_credentials',
                ]
            );

            throw new RuntimeException(
                'Username and password are required.'
            );
        }

        $user = $this->userModel->findByUsername($username);

        if (!$user) {
            $this->logAuthenticationEvent(
                action: 'login_failed',
                description: 'Login failed because the account could not be identified.',
                result: 'failure',
                newValues: [
                    'reason' => 'unknown_account',
                ]
            );

            throw new RuntimeException(
                'Invalid username or password.'
            );
        }

        $userId = (int) $user['user_id'];

        /*
         * --------------------------------------------------------
         * Account status
         * --------------------------------------------------------
         */

        if (
            (int) ($user['is_active'] ?? 0) !== 1
            || ($user['account_status'] ?? '') !== 'active'
        ) {
            $this->logAuthenticationEvent(
                action: 'login_failed',
                description: 'Login failed because the account is inactive.',
                result: 'failure',
                actorUserId: $userId,
                newValues: [
                    'reason' => 'inactive_account',
                    'account_status' => $user['account_status'] ?? null,
                    'is_active' => (int) ($user['is_active'] ?? 0),
                ]
            );

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
            $this->logAuthenticationEvent(
                action: 'login_failed',
                description: 'Login failed because the account is temporarily locked.',
                result: 'failure',
                actorUserId: $userId,
                newValues: [
                    'reason' => 'account_locked',
                    'locked_until' => $lockedUntil,
                ]
            );

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
                    $userId,
                    $lockedUntil
                );

                $this->logAuthenticationEvent(
                    action: 'login_failed',
                    description: 'Login failed and the account was temporarily locked after reaching the maximum failed attempts.',
                    result: 'failure',
                    actorUserId: $userId,
                    newValues: [
                        'reason' => 'maximum_failed_attempts',
                        'failed_attempts' => $failedAttempts,
                        'maximum_attempts' => $maxAttempts,
                        'locked_until' => $lockedUntil,
                    ]
                );

                throw new RuntimeException(
                    'Too many failed login attempts. '
                    . 'Your account has been temporarily locked.'
                );
            }

            $this->userModel->incrementFailedAttempts(
                $userId
            );

            $this->logAuthenticationEvent(
                action: 'login_failed',
                description: 'Login failed because the password was incorrect.',
                result: 'failure',
                actorUserId: $userId,
                newValues: [
                    'reason' => 'invalid_password',
                    'failed_attempts' => $failedAttempts,
                    'maximum_attempts' => $maxAttempts,
                ]
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
            $userId
        );

        $this->logAuthenticationEvent(
            action: 'login_security_reset',
            description: 'Failed login attempts and temporary lockout were cleared after successful authentication.',
            result: 'success',
            actorUserId: $userId,
            newValues: [
                'failed_attempts' => 0,
                'locked_until' => null,
            ]
        );

        $this->userModel->updateLastLogin(
            $userId
        );

        unset($user['password_hash']);

        $sessionUser = [
            'user_id' => $userId,
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
            'session_version' => (int) $user['session_version'],
        ];

        Auth::login($sessionUser);

        $this->logAuthenticationEvent(
            action: 'login',
            description: 'User logged in successfully.',
            newValues: [
                'login_status' => 'successful',
                'role_name' => $user['role_name'],
                'department_name' => $user['department_name'],
            ]
        );

        return $sessionUser;
    }
}