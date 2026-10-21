<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Asset;
use PDO;
use RuntimeException;
use App\Core\Auth;
use App\Services\AuthorizationService;
use App\Models\AssetAssignment;

class AssetService
{
    private PDO $db;

    private Asset $assetModel;

    private AssetAssignment $assignmentModel;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->assetModel = new Asset();
        $this->assignmentModel = new AssetAssignment();
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

        if (!$authorization->canAccessAssetMaterialAreas()) {
            throw new RuntimeException(
                'You do not have permission to create assets.'
            );
        }

        // The authenticated Asset Manager may enter any department.
        // The submitted department name will be resolved by create().
    }

    /**
     * Create a new asset.
     */
    public function create(
        array $data,
        int $createdBy
    ): int {
        $this->authorizeAssetCreation($data, $createdBy);

        if ($createdBy <= 0) {
            throw new RuntimeException('Invalid creator.');
        }

        $departmentName = trim(
            (string) ($data['department_name'] ?? '')
        );

        $locationName = trim(
            (string) ($data['location_name'] ?? '')
        );

        $assignedToName = trim(
            (string) ($data['assigned_to_name'] ?? '')
        );

        if ($departmentName === '') {
            throw new RuntimeException('Department is required.');
        }

        if (mb_strlen($departmentName) > 150) {
            throw new RuntimeException(
                'Department name cannot exceed 150 characters.'
            );
        }

        if (mb_strlen($locationName) > 150) {
            throw new RuntimeException(
                'Location name cannot exceed 150 characters.'
            );
        }

        if (mb_strlen($assignedToName) > 150) {
            throw new RuntimeException(
                'Assigned employee name cannot exceed 150 characters.'
            );
        }

        $this->db->beginTransaction();

        try {
            $data['department_id'] = $this->resolveOrCreateNamedRecord(
                'departments',
                $departmentName
            );

            $data['location_id'] = $locationName === ''
                ? null
                : $this->resolveOrCreateNamedRecord(
                    'locations',
                    $locationName
                );

            // A manual assignee does not require a user account.
            $data['assigned_to_name'] = $assignedToName !== ''
                ? $assignedToName
                : null;

            $data['assigned_to_user_id'] = null;

            // Generate the asset code on the server.
            // Ignore any asset_code submitted by the browser.
            $data['asset_code'] = $this->generateAssetCode();

            $data = $this->validateAndNormalize($data);

            $this->validateUniqueFields($data);

            $data['created_by'] = $createdBy;

            if (
                !isset($data['current_book_value'])
                || $data['current_book_value'] === ''
            ) {
                $data['current_book_value'] = $data['purchase_cost'];
            }

            /*
             * If a manual assignee was entered, set the asset status to
             * Assigned before inserting the asset.
             */
            if (!empty($data['assigned_to_name'])) {
                $assignedStatusId = $this->getStatusId('Assigned');

                if ($assignedStatusId === null) {
                    throw new RuntimeException(
                        'Assigned asset status is not configured or inactive.'
                    );
                }

                $data['status_id'] = $assignedStatusId;
            }

            $assetId = $this->assetModel->create($data);

            /*
             * Record the initial assignment in the same transaction as
             * the asset creation.
             */
            if (!empty($data['assigned_to_name'])) {
                $this->assignmentModel->create(
                    $assetId,
                    null,
                    $createdBy,
                    date('Y-m-d H:i:s'),
                    null,
                    'Initial assignment during asset creation.',
                    $data['assigned_to_name']
                );
            }

            $this->db->commit();

            return $assetId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException(
                'Unable to create the asset.',
                0,
                $e
            );
        }
    }

    /**
     * Generate the next daily asset code.
     *
     * Format: YYYYMMDD-00001
     * The counter resets each calendar day.
     */
    private function generateAssetCode(): string
    {
        $sequenceDate = date('Y-m-d');
        $datePrefix = date('Ymd');

        /*
         * Insert the daily counter if it does not exist.
         * Otherwise, increment it atomically. The row remains
         * locked by the transaction until commit or rollback.
         */
        $stmt = $this->db->prepare(
            'INSERT INTO asset_code_sequences (
                sequence_date,
                last_number
            ) VALUES (
                :sequence_date,
                1
            )
            ON DUPLICATE KEY UPDATE
                last_number = last_number + 1'
        );

        $stmt->execute([
            'sequence_date' => $sequenceDate,
        ]);

        $stmt = $this->db->prepare(
            'SELECT last_number
             FROM asset_code_sequences
             WHERE sequence_date = :sequence_date
             FOR UPDATE'
        );

        $stmt->execute([
            'sequence_date' => $sequenceDate,
        ]);

        $sequenceNumber = (int) $stmt->fetchColumn();

        if ($sequenceNumber < 1) {
            throw new RuntimeException(
                'Unable to generate an asset code.'
            );
        }

        /*
         * Protect against collisions with existing assets that
         * already use today's YYYYMMDD-NNNNN code format.
         */
        $stmt = $this->db->prepare(
            'SELECT MAX(
                CAST(
                    SUBSTRING_INDEX(asset_code, "-", -1)
                    AS UNSIGNED
                )
             )
             FROM assets
             WHERE asset_code REGEXP :pattern'
        );

        $stmt->execute([
            'pattern' => '^' . $datePrefix . '-[0-9]+$',
        ]);

        $existingMaximum = (int) $stmt->fetchColumn();

        if ($existingMaximum >= $sequenceNumber) {
            $sequenceNumber = $existingMaximum + 1;

            $stmt = $this->db->prepare(
                'UPDATE asset_code_sequences
                 SET last_number = :last_number
                 WHERE sequence_date = :sequence_date'
            );

            $stmt->execute([
                'last_number' => $sequenceNumber,
                'sequence_date' => $sequenceDate,
            ]);
        }

        return $datePrefix . '-'
            . str_pad(
                (string) $sequenceNumber,
                5,
                '0',
                STR_PAD_LEFT
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

        // Preserve the system-generated asset code.
        $data['asset_code'] = $existing['asset_code'];

        // Preserve fields managed by dedicated workflows.
        $data['department_id'] =
            $existing['department_id'] ?? null;

        $data['location_id'] =
            $existing['location_id'] ?? null;

        $data['assigned_to_user_id'] =
            $existing['assigned_to_user_id'] ?? null;

        $data['assigned_to_name'] =
            $existing['assigned_to_name'] ?? null;

        // Preserve the book value maintained by depreciation
        // whenever this asset has a depreciation schedule.
        $scheduleStmt = $this->db->prepare(
            'SELECT id
             FROM depreciation_schedules
             WHERE asset_id = :asset_id
             LIMIT 1'
        );

        $scheduleStmt->execute([
            'asset_id' => $id,
        ]);

        if ($scheduleStmt->fetchColumn() !== false) {
            $data['current_book_value'] =
                $existing['current_book_value'];
        }

        $data = $this->validateAndNormalize(
            $data
        );

        $statusStmt = $this->db->prepare(
            'SELECT name
         FROM asset_statuses
         WHERE id = :status_id
         LIMIT 1'
        );

        $statusStmt->execute([
            'status_id' => (int) $data['status_id'],
        ]);

        $statusName = $statusStmt->fetchColumn();

        if ($statusName === false) {
            throw new RuntimeException(
                'The selected asset status does not exist.'
            );
        }

        $requestedStatus = strtolower(
            trim((string) $statusName)
        );

        $currentStatus = strtolower(
            trim((string) (
                $existing['status_name']
                ?? $existing['status']
                ?? ''
            ))
        );

        // Terminal lifecycle states cannot be changed through
        // ordinary asset editing.
        $protectedStatuses = [
            'lost',
            'retired',
            'disposed',
            'for disposal',
            'pending disposal',
        ];

        if (
            in_array(
                $currentStatus,
                $protectedStatuses,
                true
            )
            && $requestedStatus !== $currentStatus
        ) {
            throw new RuntimeException(
                'This asset has a protected lifecycle status. Use the appropriate dedicated workflow to manage its lifecycle.'
            );
        }

        $isAssigned = !empty(
            $existing['assigned_to_user_id']
        ) || trim(
                (string) ($existing['assigned_to_name'] ?? '')
            ) !== '';

        if (
            $requestedStatus === 'available'
            && $isAssigned
        ) {
            throw new RuntimeException(
                'Return the assigned asset before setting its status to Available.'
            );
        }

        if (
            $requestedStatus === 'assigned'
            && !$isAssigned
        ) {
            throw new RuntimeException(
                'Use the Assign Asset workflow before setting the status to Assigned.'
            );
        }

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


        if (!$authorization->canAccessAssetMaterialAreas()) {
            throw new RuntimeException(
                'You do not have permission to edit assets.'
            );
        }
    }

    /**
     * Find or create a department or location by name.
     */
    private function resolveOrCreateNamedRecord(
        string $table,
        string $name
    ): int {
        $allowedTables = [
            'departments',
            'locations',
        ];

        if (!in_array($table, $allowedTables, true)) {
            throw new RuntimeException('Invalid reference table.');
        }

        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }

        $stmt = $this->db->prepare(
            "SELECT id, is_active
         FROM {$table}
         WHERE name = :name
         LIMIT 1
         FOR UPDATE"
        );

        $stmt->execute(['name' => $name]);

        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($record) {
            if (!(int) $record['is_active']) {
                throw new RuntimeException(
                    'The selected department or location is inactive.'
                );
            }

            return (int) $record['id'];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO {$table} (name, is_active)
         VALUES (:name, 1)"
        );

        $stmt->execute(['name' => $name]);

        $id = (int) $this->db->lastInsertId();

        if ($id <= 0) {
            throw new RuntimeException(
                'Unable to create the department or location.'
            );
        }

        return $id;
    }

    /**
     * Find an active asset status by name.
     */
    private function getStatusId(
        string $statusName
    ): ?int {
        $stmt = $this->db->prepare(
            'SELECT id
         FROM asset_statuses
         WHERE name = :name
           AND is_active = 1
         LIMIT 1'
        );

        $stmt->execute([
            'name' => $statusName,
        ]);

        $id = $stmt->fetchColumn();

        if ($id === false) {
            return null;
        }

        return (int) $id;
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

        $assignedToName = trim(
            (string) ($data['assigned_to_name'] ?? '')
        );

        if (mb_strlen($assignedToName) > 150) {
            throw new RuntimeException(
                'Assigned employee name cannot exceed 150 characters.'
            );
        }

        $assignedToName = $assignedToName !== ''
            ? $assignedToName
            : null;

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

            'assigned_to_name' => $assignedToName,

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