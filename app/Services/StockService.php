<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Material;
use App\Models\MaterialStock;
use App\Models\StockMovement;
use PDO;
use RuntimeException;
use Throwable;

class StockService
{
    private PDO $db;

    private Material $materialModel;

    private MaterialStock $stockModel;

    private StockMovement $stockMovementModel;

    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->db = Database::connection();

        $this->materialModel = new Material();
        $this->stockModel = new MaterialStock();
        $this->stockMovementModel = new StockMovement();
        $this->authorization = new AuthorizationService();
    }

    /**
     * Receive stock into the authenticated user's department.
     */
    public function stockIn(
        int $materialId,
        float $quantity,
        ?int $vendorId = null,
        ?string $referenceNumber = null,
        ?string $notes = null
    ): int {
        $departmentId = $this->authorizeStockOperation();

        $material = $this->getActiveMaterial($materialId);

        $quantity = $this->validateQuantity($quantity);

        $vendorId = $this->normalizeOptionalId($vendorId);

        if ($vendorId !== null) {
            $this->validateReference(
                'vendors',
                $vendorId,
                'Vendor does not exist.'
            );
        }

        return $this->recordMovement(
            materialId: $materialId,
            departmentId: $departmentId,
            movementType: 'in',
            quantity: $quantity,
            vendorId: $vendorId,
            referenceNumber: $this->normalizeOptionalString(
                $referenceNumber
            ),
            notes: $this->normalizeOptionalString($notes),
            purpose: null,
            reason: null,
            issuedToUserId: null,
            material: $material
        );
    }

    /**
     * Issue stock from the authenticated user's department.
     */
    public function stockOut(
        int $materialId,
        float $quantity,
        ?int $issuedToUserId = null,
        ?string $issuedToName = null,
        ?string $purpose = null,
        ?string $referenceNumber = null,
        ?string $notes = null
    ): int {
        $departmentId = $this->authorizeStockOperation();

        $material = $this->getActiveMaterial($materialId);

        $quantity = $this->validateQuantity($quantity);

        $issuedToUserId =
            $this->normalizeOptionalId($issuedToUserId);

        if ($issuedToUserId !== null) {
            $this->validateUserForDepartment(
                $issuedToUserId,
                $departmentId
            );
        }

        $issuedToName = $this->normalizeOptionalString(
            $issuedToName
        );

        if ($issuedToName === null) {
            throw new RuntimeException(
                'Recipient name is required for stock out.'
            );
        }

        if (mb_strlen($issuedToName) > 255) {
            throw new RuntimeException(
                'Recipient name must not exceed 255 characters.'
            );
        }

        $purpose = $this->normalizeOptionalString($purpose);

        if ($purpose === null) {
            throw new RuntimeException(
                'Purpose is required for stock out.'
            );
        }

        return $this->recordMovement(
            materialId: $materialId,
            departmentId: $departmentId,
            issuedToName: $issuedToName,
            movementType: 'out',
            quantity: $quantity,
            vendorId: null,
            referenceNumber: $this->normalizeOptionalString(
                $referenceNumber
            ),
            notes: $this->normalizeOptionalString($notes),
            purpose: $purpose,
            reason: null,
            issuedToUserId: $issuedToUserId,
            material: $material
        );
    }


    /**
     * Return previously issued material to the department stock.
     */

    public function returnStock(
        int $materialId,
        float $quantity,
        int $originalMovementId,
        ?string $referenceNumber = null,
        ?string $notes = null
    ): int {
        $departmentId = $this->authorizeStockOperation();
        $material = $this->getActiveMaterial($materialId);

        if ((int) $material['is_returnable'] !== 1) {
            throw new RuntimeException(
                'This material is not marked as returnable.'
            );
        }

        if ($originalMovementId <= 0) {
            throw new RuntimeException(
                'A valid original stock-out transaction is required.'
            );
        }

        $quantity = $this->validateQuantity($quantity);

        return $this->recordMovement(
            materialId: $materialId,
            departmentId: $departmentId,
            movementType: 'return',
            quantity: $quantity,
            vendorId: null,
            referenceNumber: $this->normalizeOptionalString($referenceNumber),
            notes: $this->normalizeOptionalString($notes),
            purpose: null,
            reason: null,
            issuedToUserId: null,
            material: $material,
            relatedMovementId: $originalMovementId
        );
    }
    /**
     * Correct the physical stock balance.
     *
     * Positive adjustment increases stock.
     * Negative adjustment decreases stock.
     */
    public function adjustStock(
        int $materialId,
        float $quantityDelta,
        string $reason,
        ?string $referenceNumber = null,
        ?string $notes = null,
        ?int $physicalStockAuditItemId = null,
        ?float $expectedBalance = null
    ): int {
        $departmentId = $this->authorizeStockOperation();

        $material = $this->getActiveMaterial($materialId);

        if (!is_finite($quantityDelta) || $quantityDelta == 0.0) {
            throw new RuntimeException(
                'Adjustment quantity must be greater than or less than zero.'
            );
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException(
                'Adjustment reason is required.'
            );
        }

        return $this->recordMovement(
            materialId: $materialId,
            departmentId: $departmentId,
            movementType: 'adjustment',
            quantity: abs($quantityDelta),
            vendorId: null,
            referenceNumber: $this->normalizeOptionalString(
                $referenceNumber
            ),
            notes: $this->normalizeOptionalString($notes),
            purpose: null,
            reason: $reason,
            issuedToUserId: null,
            material: $material,
            quantityDeltaOverride: $quantityDelta,
            physicalStockAuditItemId: $physicalStockAuditItemId,
            expectedBalance: $expectedBalance
        );
    }
    /**
     * Apply an approved physical-audit adjustment to the audited
     * department, rather than the reviewer's own department.
     *
     * Asset Manager access is required. The stock balance is checked
     * against the value recorded during the audit.
     */
    public function adjustStockForPhysicalAudit(
        int $departmentId,
        int $materialId,
        float $quantityDelta,
        float $expectedBalance,
        int $physicalStockAuditItemId,
        string $referenceNumber,
        ?string $notes = null
    ): int {
        $user = $this->authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        if (!$this->authorization->canAccessAssetMaterialAreas()) {
            throw new RuntimeException(
                'Only an Asset Manager may approve physical-audit adjustments.'
            );
        }

        if ($departmentId <= 0 || $physicalStockAuditItemId <= 0) {
            throw new RuntimeException(
                'Invalid physical-audit adjustment details.'
            );
        }

        if (
            !is_finite($quantityDelta)
            || $quantityDelta == 0.0
            || !is_finite($expectedBalance)
            || $expectedBalance < 0
            || round($quantityDelta, 3) != $quantityDelta
            || round($expectedBalance, 3) != $expectedBalance
        ) {
            throw new RuntimeException(
                'Invalid physical-audit quantities.'
            );
        }

        $material = $this->getActiveMaterial($materialId);

        return $this->recordMovement(
            materialId: $materialId,
            departmentId: $departmentId,
            movementType: 'adjustment',
            quantity: abs($quantityDelta),
            vendorId: null,
            referenceNumber: $this->normalizeOptionalString(
                $referenceNumber
            ),
            notes: $this->normalizeOptionalString($notes),
            purpose: null,
            reason: 'Physical stock audit adjustment',
            issuedToUserId: null,
            material: $material,
            quantityDeltaOverride: $quantityDelta,
            physicalStockAuditItemId: $physicalStockAuditItemId,
            expectedBalance: $expectedBalance
        );
    }
    /**
     * Get the authenticated user's department stock.
     */
    public function getDepartmentStock(): array
    {
        $departmentId = $this->authorizeStockOperation();

        $stock = $this->stockModel->getByDepartment(
            $departmentId
        );

        (new AuditLogService())->log(
            action: 'view_department_stock',
            module: 'stock',
            entityType: 'material_stock',
            description: 'Department stock list viewed.',
            newValues: [
                'department_id' => $departmentId,
                'items_count' => count($stock),
            ]
        );

        return $stock;
    }

    /**
     * Get paginated stock movement history for the current department.
     */
    public function getDepartmentMovementPage(
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        $departmentId = $this->authorization->departmentId();

        if (!$departmentId) {
            throw new RuntimeException(
                'Your account is not assigned to a department.'
            );
        }

        $result = $this->stockMovementModel->getPage(
            (int) $departmentId,
            $filters,
            $page,
            $perPage
        );

        (new AuditLogService())->log(
            action: 'view_stock_movement_history',
            module: 'stock',
            entityType: 'stock_movement',
            description: 'Department stock movement history viewed.',
            newValues: [
                'department_id' => (int) $departmentId,
                'page' => $page,
                'per_page' => $perPage,
            ]
        );

        return $result;
    }

    /**
     * Get paginated stock for the authenticated user's department.
     */
    public function getDepartmentStockPage(
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        $departmentId = $this->authorization->departmentId();

        if ($departmentId === null) {
            throw new RuntimeException(
                'Your account must be assigned to a department.'
            );
        }

        $result = $this->stockModel->getPage(
            $departmentId,
            $filters,
            $page,
            $perPage
        );

        (new AuditLogService())->log(
            action: 'view_department_stock_list',
            module: 'stock',
            entityType: 'material_stock',
            description: 'Paginated department stock list viewed.',
            newValues: [
                'department_id' => $departmentId,
                'page' => $page,
                'per_page' => $perPage,
            ]
        );

        return $result;
    }

    /**
     * Get low-stock items for the authenticated user's department.
     */
    public function getLowStock(): array
    {
        $departmentId = $this->authorizeStockOperation();

        $stock = $this->stockModel->getLowStockByDepartment(
            $departmentId
        );

        (new AuditLogService())->log(
            action: 'view_low_stock',
            module: 'stock',
            entityType: 'material_stock',
            description: 'Department low-stock list viewed.',
            newValues: [
                'department_id' => $departmentId,
                'items_count' => count($stock),
            ]
        );

        return $stock;
    }

    /**
     * Get a single department stock record.
     */
    public function getStock(
        int $materialId
    ): array|false {
        $departmentId = $this->authorizeStockOperation();

        if ($materialId <= 0) {
            return false;
        }

        $stock = $this->stockModel->findByMaterialAndDepartment(
            $materialId,
            $departmentId
        );

        if ($stock !== false) {
            (new AuditLogService())->log(
                action: 'view_stock_details',
                module: 'stock',
                entityType: 'material_stock',
                entityId: isset($stock['id']) ? (int) $stock['id'] : null,
                description: 'Department stock details viewed.',
                newValues: [
                    'material_id' => $materialId,
                    'department_id' => $departmentId,
                ]
            );
        }

        return $stock;
    }

    /**
     * Write a stock movement and update the balance atomically.
     */
    private function recordMovement(
        int $materialId,
        int $departmentId,
        string $movementType,
        float $quantity,
        ?int $vendorId,
        ?string $referenceNumber,
        ?string $notes,
        ?string $purpose,
        ?string $reason,
        ?int $issuedToUserId,
        array $material,
        ?float $quantityDeltaOverride = null,
        ?string $issuedToName = null,
        ?int $relatedMovementId = null,
        ?int $physicalStockAuditItemId = null,
        ?float $expectedBalance = null
    ): int {
        $this->validateMovementType(
            $movementType
        );

        $userId = Auth::id();

        if ($userId === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        $quantity = $this->validateQuantity(
            $quantity
        );

        $quantityDelta =
            $quantityDeltaOverride
            ?? match ($movementType) {
                'in', 'return' => $quantity,
                'out' => -$quantity,
                default => throw new RuntimeException(
                    'Invalid stock movement.'
                ),
            };

        $ownsTransaction = !$this->db->inTransaction();

        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            /*
             * Physical-audit adjustments use the audited department's
             * balance. Other movements retain the existing shared-stock
             * behavior.
             */
            $isPhysicalAuditAdjustment =
                $physicalStockAuditItemId !== null;

            $stockDepartmentId =
                $isPhysicalAuditAdjustment
                ? $departmentId
                : null;

            if ($isPhysicalAuditAdjustment) {
                $stmt = $this->db->prepare(
                    'SELECT
                        id,
                        quantity
                    FROM material_stocks
                    WHERE material_id = :material_id
                    AND department_id = :department_id
                    LIMIT 1
                    FOR UPDATE'
                );

                $stmt->execute([
                    'material_id' => $materialId,
                    'department_id' => $stockDepartmentId,
                ]);
            } else {
                /*
                 * Preserve the existing shared-stock creation behavior.
                 */
                $stmt = $this->db->prepare(
                    'INSERT INTO material_stocks (
                        material_id,
                        department_id,
                        quantity
                    )
                    VALUES (
                        :material_id,
                        NULL,
                        0
                    )
                    ON DUPLICATE KEY UPDATE
                        id = LAST_INSERT_ID(id)'
                );

                $stmt->execute([
                    'material_id' => $materialId,
                ]);

                $stmt = $this->db->prepare(
                    'SELECT
                        id,
                        quantity
                    FROM material_stocks
                    WHERE material_id = :material_id
                    AND department_id IS NULL
                    LIMIT 1
                    FOR UPDATE'
                );

                $stmt->execute([
                    'material_id' => $materialId,
                ]);
            }

            $stock = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($stock)) {
                throw new RuntimeException(
                    'Unable to retrieve the correct stock balance.'
                );
            }

            $currentBalance = (float) $stock['quantity'];

            /*
             * Physical audit adjustments must use the same
             * system balance that was recorded during the audit.
             */
            if (
                $expectedBalance !== null
                && round($currentBalance, 3)
                !== round($expectedBalance, 3)
            ) {
                throw new RuntimeException(
                    'The current stock balance has changed since this '
                    . 'audit was conducted. Please conduct a new audit '
                    . 'before approving this adjustment.'
                );
            }

            $newBalance =
                $currentBalance + $quantityDelta;

            /*
             * Never allow stock to become negative.
             */
            if ($newBalance < 0) {
                throw new RuntimeException(
                    'Insufficient stock. Available quantity: '
                    . $this->formatQuantity($currentBalance)
                );
            }

            $stockId = (int) $stock['id'];


            /*
             * Validate returns against the original stock-out.
             * The stock balance row is already locked in this
             * transaction, serializing returns for this stock.
             */
            if ($movementType === 'return') {
                if (
                    $relatedMovementId === null
                    || $relatedMovementId <= 0
                ) {
                    throw new RuntimeException(
                        'Select the original stock-out transaction.'
                    );
                }

                $stmt = $this->db->prepare(
                    'SELECT id, quantity
                     FROM stock_movements
                     WHERE id = :id
                       AND material_stock_id = :material_stock_id
                       AND movement_type = \'out\'
                     LIMIT 1
                     FOR UPDATE'
                );

                $stmt->execute([
                    'id' => $relatedMovementId,
                    'material_stock_id' => $stockId,
                ]);

                $originalMovement = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!is_array($originalMovement)) {
                    throw new RuntimeException(
                        'The selected stock-out transaction is invalid '
                        . 'or does not belong to this material and department.'
                    );
                }

                $stmt = $this->db->prepare(
                    'SELECT COALESCE(SUM(quantity), 0)
                     FROM stock_movements
                     WHERE related_movement_id = :related_movement_id
                       AND movement_type = \'return\''
                );

                $stmt->execute([
                    'related_movement_id' => $relatedMovementId,
                ]);

                $alreadyReturned = (float) $stmt->fetchColumn();
                $originalQuantity = (float) $originalMovement['quantity'];
                $remainingQuantity = round(
                    $originalQuantity - $alreadyReturned,
                    3
                );

                if ($remainingQuantity <= 0) {
                    throw new RuntimeException(
                        'This stock-out transaction has already been '
                        . 'returned in full.'
                    );
                }

                if ($quantity > $remainingQuantity) {
                    throw new RuntimeException(
                        'Return quantity exceeds the remaining quantity of '
                        . $this->formatQuantity($remainingQuantity) . '.'
                    );
                }
            } elseif ($relatedMovementId !== null) {
                throw new RuntimeException(
                    'Only stock returns can reference an original movement.'
                );
            }

            /*
             * Update current balance.
             */
            $stmt = $this->db->prepare(
                'UPDATE material_stocks
                 SET quantity = :quantity
                 WHERE id = :id'
            );

            $stmt->execute([
                'quantity' => $newBalance,
                'id' => $stockId,
            ]);

            /*
             * Record the immutable movement event.
             */
            $stmt = $this->db->prepare(
                'INSERT INTO stock_movements (
                    material_stock_id,
                    movement_type,
                    quantity,
                    quantity_delta,
                    balance_after,
                    vendor_id,
                    issued_to_user_id,
                    issued_to_name,
                    related_movement_id,
                    physical_stock_audit_item_id,
                    reference_number,
                    purpose,
                    reason,
                    notes,
                    moved_at,
                    recorded_by
                )
                VALUES (
                    :material_stock_id,
                    :movement_type,
                    :quantity,
                    :quantity_delta,
                    :balance_after,
                    :vendor_id,
                    :issued_to_user_id,
                    :issued_to_name,
                    :related_movement_id,
                    :physical_stock_audit_item_id,
                    :reference_number,
                    :purpose,
                    :reason,
                    :notes,
                    NOW(),
                    :recorded_by
                )'
            );

            $stmt->execute([
                'material_stock_id' => $stockId,
                'movement_type' => $movementType,
                'quantity' => $quantity,
                'quantity_delta' => $quantityDelta,
                'balance_after' => $newBalance,
                'vendor_id' => $vendorId,
                'issued_to_user_id' => $issuedToUserId,
                'issued_to_name' => $issuedToName,
                'related_movement_id' => $relatedMovementId,
                'physical_stock_audit_item_id' => $physicalStockAuditItemId,
                'reference_number' => $referenceNumber,
                'purpose' => $purpose,
                'reason' => $reason,
                'notes' => $notes,
                'recorded_by' => $userId,
            ]);

            $movementId = (int) $this->db->lastInsertId();

            $action = match (true) {
                $movementType === 'in' => 'stock_in',
                $movementType === 'out' => 'stock_out',
                $movementType === 'return' => 'return_stock',
                $movementType === 'adjustment'
                && $physicalStockAuditItemId !== null
                => 'physical_audit_stock_adjustment',
                $movementType === 'adjustment' => 'adjust_stock',
                default => 'stock_movement',
            };

            (new AuditLogService())->log(
                action: $action,
                module: 'stock',
                entityType: 'stock_movement',
                entityId: $movementId,
                description: 'Stock movement recorded successfully.',
                oldValues: [
                    'material_id' => $materialId,
                    'material_stock_id' => $stockId,
                    'department_id' => $departmentId,
                    'stock_quantity' => $currentBalance,
                ],
                newValues: [
                    'material_id' => $materialId,
                    'material_name' => $material['name'] ?? null,
                    'material_code' => $material['code'] ?? null,
                    'material_stock_id' => $stockId,
                    'department_id' => $departmentId,
                    'movement_type' => $movementType,
                    'quantity' => $quantity,
                    'quantity_delta' => $quantityDelta,
                    'balance_after' => $newBalance,
                    'vendor_id' => $vendorId,
                    'issued_to_user_id' => $issuedToUserId,
                    'issued_to_name' => $issuedToName,
                    'related_movement_id' => $relatedMovementId,
                    'physical_stock_audit_item_id' => $physicalStockAuditItemId,
                    'reference_number' => $referenceNumber,
                    'purpose' => $purpose,
                    'reason' => $reason,
                    'recorded_by' => $userId,
                ]
            );

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return $movementId;
        } catch (Throwable $e) {
            if (
                $ownsTransaction
                && $this->db->inTransaction()
            ) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Only operational asset roles may operate stock.
     */
    private function authorizeStockOperation(): int
    {
        $user = $this->authorization->currentUser();

        if ($user === null) {
            throw new RuntimeException(
                'Authentication required.'
            );
        }

        if (!$this->authorization->canAccessAssetOperations()) {
            throw new RuntimeException(
                'You do not have permission to manage stock.'
            );
        }

        $departmentId =
            $this->authorization->departmentId();

        if ($departmentId === null) {
            throw new RuntimeException(
                'Your account must be assigned to a department.'
            );
        }

        return $departmentId;
    }

    /**
     * Retrieve an active material.
     */
    private function getActiveMaterial(
        int $materialId
    ): array {
        if ($materialId <= 0) {
            throw new RuntimeException(
                'Invalid material.'
            );
        }

        $material =
            $this->materialModel->findById(
                $materialId
            );

        if (!is_array($material)) {
            throw new RuntimeException(
                'Material not found.'
            );
        }

        if ((int) $material['is_active'] !== 1) {
            throw new RuntimeException(
                'Material is inactive.'
            );
        }

        return $material;
    }

    /**
     * Ensure the selected recipient is an active organization user.
     */
    private function validateUserForDepartment(
        int $userId,
        int $departmentId
    ): void {
        $stmt = $this->db->prepare(
            'SELECT id
         FROM users
         WHERE id = :id
           AND account_status = "active"
           AND is_active = 1
         LIMIT 1'
        );

        $stmt->execute([
            'id' => $userId,
        ]);

        if (!$stmt->fetch()) {
            throw new RuntimeException(
                'The selected recipient does not exist or is inactive.'
            );
        }
    }

    /**
     * Validate a referenced database ID.
     */
    private function validateReference(
        string $table,
        int $id,
        string $message
    ): void {
        $allowedTables = [
            'vendors',
        ];

        if (!in_array($table, $allowedTables, true)) {
            throw new RuntimeException(
                'Invalid reference table.'
            );
        }

        $stmt = $this->db->prepare(
            "SELECT id FROM {$table}
             WHERE id = :id
             LIMIT 1"
        );

        $stmt->execute([
            'id' => $id,
        ]);

        if (!$stmt->fetch()) {
            throw new RuntimeException($message);
        }
    }

    private function validateMovementType(
        string $movementType
    ): void {
        $allowed = [
            'in',
            'out',
            'return',
            'adjustment',
        ];

        if (!in_array($movementType, $allowed, true)) {
            throw new RuntimeException(
                'Invalid stock movement type.'
            );
        }
    }

    private function validateQuantity(
        float $quantity
    ): float {
        if (
            !is_finite($quantity)
            || $quantity <= 0
        ) {
            throw new RuntimeException(
                'Quantity must be greater than zero.'
            );
        }

        return round($quantity, 3);
    }

    private function normalizeOptionalId(
        ?int $value
    ): ?int {
        if ($value === null || $value <= 0) {
            return null;
        }

        return $value;
    }

    private function normalizeOptionalString(
        ?string $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === ''
            ? null
            : $value;
    }

    private function formatQuantity(
        float $quantity
    ): string {
        return rtrim(
            rtrim(
                number_format(
                    $quantity,
                    3,
                    '.',
                    ''
                ),
                '0'
            ),
            '.'
        );
    }
}
