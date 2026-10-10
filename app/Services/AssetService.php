<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Asset;
use PDO;
use RuntimeException;
use App\Core\Auth;
use App\Services\AuthorizationService;

class AssetService
{
    private PDO $db;

    private Asset $assetModel;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->assetModel = new Asset();
    }


    /**
     * Enforce permissions when creating an asset.
     */
    private function authorizeAssetCreation(
        array &$data,
        int $createdBy
    ): void {
        $authorization = new AuthorizationService();
        $user = $authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        $authenticatedUserId = Auth::id();

        if (
            $authenticatedUserId === null
            || $createdBy !== $authenticatedUserId
        ) {
            throw new RuntimeException(
                'Invalid asset creator.'
            );
        }

        if (
            !$authorization->isAssetManager()
            && !$authorization->isAssetCustodian()
        ) {
            throw new RuntimeException(
                'You do not have permission to create assets.'
            );
        }

        $departmentId = $authorization->departmentId();

        if ($departmentId === null) {
            throw new RuntimeException(
                'Your account must be assigned to a department.'
            );
        }

        // Asset Managers and Asset Custodians can create assets
        // only for their own department, regardless of submitted form data.
        $data['department_id'] = $departmentId;
    }

    /**
     * Create a new asset.
     */
    public function create(
        array $data,
        int $createdBy
    ): int {
        $this->authorizeAssetCreation(
            $data,
            $createdBy
        );

        $data = $this->validateAndNormalize(
            $data
        );

        if ($createdBy <= 0) {
            throw new RuntimeException(
                'Invalid creator.'
            );
        }

        $this->validateUniqueFields(
            $data
        );

        $data['created_by'] = $createdBy;

        if (
            !isset($data['current_book_value'])
            || $data['current_book_value'] === ''
        ) {
            $data['current_book_value'] =
                $data['purchase_cost'];
        }

        return $this->assetModel->create(
            $data
        );
    }

    /**
     * Update an existing asset.
     */
    public function update(
        int $id,
        array $data
    ): bool {
        if ($id <= 0) {
            throw new RuntimeException(
                'Invalid asset.'
            );
        }

        $existing = $this->assetModel->findById(
            $id
        );

        if (!$existing) {
            throw new RuntimeException(
                'Asset not found.'
            );
        }

        $this->authorizeAssetUpdate($existing);

        $data = $this->validateAndNormalize(
            $data
        );

        $this->validateUniqueFields(
            $data,
            $id
        );

        return $this->assetModel->update(
            $id,
            $data
        );
    }

    /**
     * Find an asset by ID.
     */
    public function findById(
        int $id
    ): array|false {
        if ($id <= 0) {
            return false;
        }

        return $this->assetModel->findById(
            $id
        );
    }

    /**
     * Find an asset by asset code.
     */
    public function findByAssetCode(
        string $assetCode
    ): array|false {
        $assetCode = trim($assetCode);

        if ($assetCode === '') {
            return false;
        }

        return $this->assetModel->findByAssetCode(
            $assetCode
        );
    }

    /**
     * Get all assets.
     */
    public function getAll(
        ?int $departmentId = null,
        ?int $assignedToUserId = null
    ): array {
        return $this->assetModel->getAll(
            $departmentId,
            $assignedToUserId
        );
    }

    /**
     * Get a filtered page of assets for the asset register.
     */
    public function getPage(
        array $filters = [],
        ?int $departmentId = null,
        int $page = 1,
        int $perPage = 25
    ): array {
        return $this->assetModel->getPage(
            $filters,
            $departmentId,
            $page,
            $perPage
        );
    }

    /**
     * Enforce asset ownership permissions in the service layer.
     */
    private function authorizeAssetUpdate(array $asset): void
    {
        $authorization = new AuthorizationService();
        $user = $authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }


        if (
            !$authorization->isAssetManager()
            && !$authorization->isAssetCustodian()
        ) {
            throw new RuntimeException(
                'You do not have permission to edit assets.'
            );
        }

        $userDepartmentId = $authorization->departmentId();

        $assetDepartmentId = $asset['department_id'] === null
            ? null
            : (int) $asset['department_id'];

        if (
            $userDepartmentId === null
            || $assetDepartmentId === null
            || $userDepartmentId !== $assetDepartmentId
        ) {
            throw new RuntimeException(
                'You may only edit assets assigned to your department.'
            );
        }
    }

    /**
     * Validate and normalize asset data.
     */
    private function validateAndNormalize(
        array $data
    ): array {
        $assetCode = trim(
            (string) ($data['asset_code'] ?? '')
        );

        $assetName = trim(
            (string) ($data['asset_name'] ?? '')
        );

        if ($assetCode === '') {
            throw new RuntimeException(
                'Asset code is required.'
            );
        }

        if ($assetName === '') {
            throw new RuntimeException(
                'Asset name is required.'
            );
        }

        $categoryId = (int) (
            $data['category_id'] ?? 0
        );

        if ($categoryId <= 0) {
            throw new RuntimeException(
                'Asset category is required.'
            );
        }

        $statusId = (int) (
            $data['status_id'] ?? 0
        );

        if ($statusId <= 0) {
            throw new RuntimeException(
                'Asset status is required.'
            );
        }

        $this->validateReferenceId(
            'asset_categories',
            $categoryId,
            'Asset category does not exist.'
        );

        $this->validateReferenceId(
            'asset_statuses',
            $statusId,
            'Asset status does not exist.'
        );

        $purchaseCost = $this->normalizeMoney(
            $data['purchase_cost'] ?? 0,
            'Purchase cost'
        );

        $salvageValue = $this->normalizeMoney(
            $data['salvage_value'] ?? 0,
            'Salvage value'
        );

        if ($salvageValue > $purchaseCost) {
            throw new RuntimeException(
                'Salvage value cannot exceed purchase cost.'
            );
        }

        $usefulLife = $this->normalizeOptionalDecimal(
            $data['useful_life_years'] ?? null
        );

        if (
            $usefulLife !== null
            && $usefulLife <= 0
        ) {
            throw new RuntimeException(
                'Useful life must be greater than zero.'
            );
        }

        $depreciationMethod = trim(
            (string) (
                $data['depreciation_method']
                ?? 'straight_line'
            )
        );

        if ($depreciationMethod === '') {
            $depreciationMethod = 'straight_line';
        }

        $supplierId =
            $this->normalizeOptionalId(
                $data['supplier_id'] ?? null
            );

        $locationId =
            $this->normalizeOptionalId(
                $data['location_id'] ?? null
            );

        $departmentId = $this->normalizeOptionalId(
            $data['department_id'] ?? null
        );

        $conditionId =
            $this->normalizeOptionalId(
                $data['condition_id'] ?? null
            );

        $assignedToUserId =
            $this->normalizeOptionalId(
                $data['assigned_to_user_id'] ?? null
            );

        if ($supplierId !== null) {
            $this->validateReferenceId(
                'vendors',
                $supplierId,
                'Supplier does not exist.'
            );
        }

        if ($locationId !== null) {
            $this->validateReferenceId(
                'locations',
                $locationId,
                'Location does not exist.'
            );
        }

        if ($departmentId !== null) {
            $this->validateReferenceId(
                'departments',
                $departmentId,
                'Department does not exist.'
            );
        }

        if ($conditionId !== null) {
            $this->validateReferenceId(
                'asset_conditions',
                $conditionId,
                'Asset condition does not exist.'
            );
        }

        if ($assignedToUserId !== null) {
            $this->validateReferenceId(
                'users',
                $assignedToUserId,
                'Assigned user does not exist.'
            );
        }

        $currentBookValue =
            $data['current_book_value']
            ?? $purchaseCost;

        $currentBookValue = $this->normalizeMoney(
            $currentBookValue,
            'Current book value'
        );

        return [
            'asset_code' => $assetCode,

            'asset_tag' => $this->normalizeOptionalString(
                $data['asset_tag'] ?? null
            ),

            'serial_number' =>
                $this->normalizeOptionalString(
                    $data['serial_number'] ?? null
                ),

            'category_id' => $categoryId,

            'asset_name' => $assetName,

            'description' =>
                $this->normalizeOptionalString(
                    $data['description'] ?? null
                ),

            'manufacturer' =>
                $this->normalizeOptionalString(
                    $data['manufacturer'] ?? null
                ),

            'model' =>
                $this->normalizeOptionalString(
                    $data['model'] ?? null
                ),

            'model_number' =>
                $this->normalizeOptionalString(
                    $data['model_number'] ?? null
                ),

            'purchase_date' =>
                $this->normalizeDate(
                    $data['purchase_date'] ?? null,
                    'Purchase date'
                ),

            'purchase_cost' => $purchaseCost,

            'supplier_id' => $supplierId,

            'invoice_number' =>
                $this->normalizeOptionalString(
                    $data['invoice_number'] ?? null
                ),

            'purchase_order_number' =>
                $this->normalizeOptionalString(
                    $data['purchase_order_number'] ?? null
                ),

            'warranty_start' =>
                $this->normalizeDate(
                    $data['warranty_start'] ?? null,
                    'Warranty start'
                ),

            'warranty_end' =>
                $this->normalizeDate(
                    $data['warranty_end'] ?? null,
                    'Warranty end'
                ),

            'useful_life_years' => $usefulLife,

            'salvage_value' => $salvageValue,

            'depreciation_method' =>
                $depreciationMethod,

            'current_book_value' =>
                $currentBookValue,

            'status_id' => $statusId,

            'location_id' => $locationId,

            'department_id' => $departmentId,

            'condition_id' => $conditionId,

            'assigned_to_user_id' => $assignedToUserId,

        ];
    }

    /**
     * Validate unique asset identifiers.
     */
    private function validateUniqueFields(
        array $data,
        ?int $ignoreId = null
    ): void {
        $assetCode = $data['asset_code'];

        $stmt = $this->db->prepare(
            'SELECT id
             FROM assets
             WHERE asset_code = :asset_code
             LIMIT 1'
        );

        $stmt->execute([
            'asset_code' => $assetCode,
        ]);

        $existingId = $stmt->fetchColumn();

        if (
            $existingId !== false
            && (
                $ignoreId === null
                || (int) $existingId !== $ignoreId
            )
        ) {
            throw new RuntimeException(
                'Asset code already exists.'
            );
        }

        $this->validateUniqueOptionalField(
            'asset_tag',
            $data['asset_tag'] ?? null,
            $ignoreId,
            'Asset tag already exists.'
        );

        $this->validateUniqueOptionalField(
            'serial_number',
            $data['serial_number'] ?? null,
            $ignoreId,
            'Serial number already exists.'
        );
    }

    /**
     * Validate an optional unique field.
     */
    private function validateUniqueOptionalField(
        string $column,
        ?string $value,
        ?int $ignoreId,
        string $errorMessage
    ): void {
        if ($value === null || $value === '') {
            return;
        }

        $allowedColumns = [
            'asset_tag',
            'serial_number',
        ];

        if (
            !in_array(
                $column,
                $allowedColumns,
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid unique field.'
            );
        }

        $sql = sprintf(
            'SELECT id
             FROM assets
             WHERE %s = :value
             LIMIT 1',
            $column
        );

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'value' => $value,
        ]);

        $existingId = $stmt->fetchColumn();

        if (
            $existingId !== false
            && (
                $ignoreId === null
                || (int) $existingId !== $ignoreId
            )
        ) {
            throw new RuntimeException(
                $errorMessage
            );
        }
    }

    /**
     * Normalize money values.
     */
    private function normalizeMoney(
        mixed $value,
        string $fieldName
    ): float {
        if ($value === null || $value === '') {
            return 0.00;
        }

        if (
            !is_numeric($value)
            || (float) $value < 0
        ) {
            throw new RuntimeException(
                $fieldName
                . ' must be a valid non-negative amount.'
            );
        }

        return round(
            (float) $value,
            2
        );
    }

    /**
     * Normalize an optional decimal value.
     */
    private function normalizeOptionalDecimal(
        mixed $value
    ): ?float {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new RuntimeException(
                'Invalid decimal value.'
            );
        }

        return (float) $value;
    }

    /**
     * Normalize an optional foreign-key ID.
     */
    private function normalizeOptionalId(
        mixed $value
    ): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        if ($id <= 0) {
            throw new RuntimeException(
                'Invalid reference ID.'
            );
        }

        return $id;
    }

    /**
     * Normalize an optional string.
     */
    private function normalizeOptionalString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }

    /**
     * Normalize an optional date.
     */
    private function normalizeDate(
        mixed $value,
        string $fieldName
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        $date = \DateTimeImmutable::createFromFormat(
            'Y-m-d',
            $value
        );

        $errors = \DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || (
                $errors !== false
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
            || $date->format('Y-m-d') !== $value
        ) {
            throw new RuntimeException(
                $fieldName
                . ' must be a valid date.'
            );
        }

        return $value;
    }

    /**
     * Validate that a referenced record exists.
     */
    private function validateReferenceId(
        string $table,
        int $id,
        string $errorMessage
    ): void {
        $allowedTables = [
            'asset_categories',
            'asset_statuses',
            'asset_conditions',
            'locations',
            'vendors',
            'departments',
            'users',
        ];

        if (
            !in_array(
                $table,
                $allowedTables,
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid reference table.'
            );
        }

        $sql = sprintf(
            'SELECT id
         FROM %s
         WHERE id = :id
         LIMIT 1',
            $table
        );

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'id' => $id,
        ]);

        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException(
                $errorMessage
            );
        }
    }
}