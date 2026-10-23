<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\User;

class AuthorizationService
{
    private User $userModel;

    private const OPERATIONAL_ROLE = 'Asset Manager';
    private const OPERATIONAL_USERNAME = 'manager';

    public function __construct()
    {
        $this->userModel = new User();
    }

    /**
     * Retrieve the authenticated user's current database record.
     *
     * Session role and department values are not trusted as the
     * source of truth for authorization.
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
            || !array_key_exists('department_id', $user)
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

    /**
     * Check the operational role.
     *
     * This method checks the role only. Use
     * canAccessAssetMaterialAreas() for the four restricted areas.
     */
    public function isAssetManager(): bool
    {
        return $this->roleName() === self::OPERATIONAL_ROLE;
    }


    /**
     * Check whether the current active user has the Property Custodian role.
     */
    public function isPropertyCustodian(): bool
    {
        return $this->roleName() === 'Property Custodian';
    }

    /**
     * Compatibility method for existing callers.
     *
     * The old Asset Custodian role has been retired.
     */
    public function isAssetCustodian(): bool
    {
        return false;
    }

    /**
     * Only the active account named "manager" with the Asset Manager
     * role may access the restricted manager-only areas.
     */
    public function canAccessAssetMaterialAreas(): bool
    {
        $user = $this->currentUser();

        if ($user === null) {
            return false;
        }

        return ($user['username'] ?? '') === self::OPERATIONAL_USERNAME
            && ($user['role_name'] ?? '') === self::OPERATIONAL_ROLE;
    }

    /**
     * Allow authorized Asset Manager and Property Custodian accounts
     * to enter general asset-operational workflows.
     *
     * This does not grant access to manager-only reports, exports,
     * or depreciation functions. Those must continue to use their
     * manager-specific authorization checks.
     */
    public function canAccessAssetOperations(): bool
    {
        return $this->canAccessAssetMaterialAreas()
            || $this->isPropertyCustodian();
    }


    /**
     * Check whether the operational user may access a department.
     *
     * Keep this existing role-based behavior for callers outside
     * the four restricted areas.
     */
    public function canAccessDepartment(int $departmentId): bool
    {
        if ($departmentId <= 0) {
            return false;
        }

        return $this->isAssetManager();
    }


    /**
     * Check whether the authorized operational account may access
     * an existing asset, regardless of its department.
     *
     * Administrators and other Asset Manager accounts are denied.
     */
    public function canAccessAsset(int $assetId): bool
    {
        if ($assetId <= 0 || !$this->canAccessAssetOperations()) {
            return false;
        }

        $db = Database::connection();

        $stmt = $db->prepare(
            'SELECT id
             FROM assets
             WHERE id = :asset_id
             LIMIT 1'
        );

        $stmt->execute([
            'asset_id' => $assetId,
        ]);

        return $stmt->fetchColumn() !== false;
    }
}