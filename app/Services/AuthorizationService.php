<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Models\User;

class AuthorizationService
{
    private User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    /**
     * Retrieve the authenticated user's current database record.
     *
     * Do not use session role or department values as the source
     * of truth for authorization.
     */
    public function currentUser(): ?array
    {
        $userId = Auth::id();

        if ($userId === null) {
            return null;
        }

        $user = $this->userModel->findById($userId);

        if (!is_array($user)) {
            return null;
        }

        if (
            ($user['account_status'] ?? '') !== 'active'
            || (int) ($user['is_active'] ?? 0) !== 1
        ) {
            return null;
        }

        return $user;
    }

    public function roleName(): ?string
    {
        $user = $this->currentUser();

        return $user !== null
            ? (string) ($user['role_name'] ?? '')
            : null;
    }

    public function departmentId(): ?int
    {
        $user = $this->currentUser();

        if (
            $user === null
            || $user['department_id'] === null
        ) {
            return null;
        }

        return (int) $user['department_id'];
    }

    public function isAdministrator(): bool
    {
        return $this->roleName() === 'Administrator';
    }

    public function isAssetManager(): bool
    {
        return $this->roleName() === 'Asset Manager';
    }

    public function isAssetCustodian(): bool
    {
        return $this->roleName() === 'Asset Custodian';
    }

    /**
     * Check whether the current user may access a department.
     */
    public function canAccessDepartment(int $departmentId): bool
    {
        if ($departmentId <= 0) {
            return false;
        }

        if (!$this->isAssetManager()) {
            return false;
        }

        return $this->departmentId() === $departmentId;
    }

    public function canAccessAsset(int $assetId): bool
    {
        if ($assetId <= 0) {
            return false;
        }

        if ($this->isAdministrator()) {
            return false;
        }

        $userId = Auth::id();

        if ($userId === null) {
            return false;
        }

        $db = \App\Core\Database::connection();

        $stmt = $db->prepare(
            "SELECT department_id
         FROM assets
         WHERE id = :asset_id
         LIMIT 1"
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        $asset = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($asset)) {
            return false;
        }

        if (
            $this->isAssetManager()
            || $this->isAssetCustodian()
        ) {
            return
                (int) ($asset['department_id'] ?? 0)
                === (int) ($this->departmentId() ?? 0);
        }

        return false;
    }
}