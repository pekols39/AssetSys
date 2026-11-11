<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\PhysicalStockAudit;
use PDO;
use RuntimeException;

class PhysicalStockAuditService
{
    private PDO $db;

    private PhysicalStockAudit $auditModel;

    private AuthorizationService $authorization;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->auditModel = new PhysicalStockAudit();
        $this->authorization = new AuthorizationService();
    }

    /**
     * Get organization-wide consumable materials.
     *
     * The model reads the shared stock row where
     * material_stocks.department_id IS NULL.
     */
    public function getCandidates(string $auditType = 'consumable'): array
    {
        $this->authorizeAuditAccess();

        if ($auditType !== 'consumable') {
            throw new RuntimeException(
                'Unsupported physical stock audit type.'
            );
        }

        $candidates = $this->auditModel->getConsumableCandidates();

        (new AuditLogService())->log(
            action: 'view_physical_audit_candidates',
            module: 'physical_stock_audits',
            entityType: 'physical_stock_audit',
            description: 'Physical stock audit candidates viewed successfully.',
            newValues: [
                'audit_type' => $auditType,
                'candidate_count' => count($candidates),
            ]
        );

        return $candidates;
    }

    /**
     * Record a consumable physical count.
     *
     * This creates an audit-history record only.
     * It never changes material stock quantities.
     */
    public function createAudit(
        string $auditType,
        string $auditDate,
        array $submittedItems,
        ?string $auditNotes = null,
        bool $includeAll = false
    ): int {
        $this->authorizeCustodian();

        if ($auditType !== 'consumable') {
            throw new RuntimeException(
                'Only consumable materials can be physically audited.'
            );
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $auditDate
        );

        if (
            $date === false
            || $date->format('Y-m-d H:i:s') !== $auditDate
        ) {
            throw new RuntimeException(
                'Invalid audit date and time.'
            );
        }

        $candidates = $this->auditModel->getConsumableCandidates();

        if ($candidates === []) {
            throw new RuntimeException(
                'There are no active consumable materials available to count.'
            );
        }

        $candidateMap = [];

        foreach ($candidates as $candidate) {
            $candidateMap[(int) $candidate['material_id']] = $candidate;
        }

        $selectedItems = [];

        foreach ($submittedItems as $submittedItem) {
            $materialId = (int) ($submittedItem['material_id'] ?? 0);

            if (
                $materialId <= 0
                || !isset($candidateMap[$materialId])
            ) {
                throw new RuntimeException(
                    'One or more selected materials are invalid.'
                );
            }

            if (isset($selectedItems[$materialId])) {
                throw new RuntimeException(
                    'Duplicate materials are not allowed.'
                );
            }

            $selectedItems[$materialId] = $submittedItem;
        }

        if (
            $includeAll
            && count($selectedItems) !== count($candidateMap)
        ) {
            throw new RuntimeException(
                'A complete count must include every active consumable material.'
            );
        }

        if ($selectedItems === []) {
            throw new RuntimeException(
                'Select at least one material to count.'
            );
        }

        $items = [];

        foreach ($selectedItems as $materialId => $submittedItem) {
            $candidate = $candidateMap[$materialId];

            $systemQuantity = round(
                (float) $candidate['system_quantity'],
                3
            );

            $physicalQuantity = $this->validateQuantity(
                $submittedItem['physical_quantity'] ?? null
            );

            $items[] = [
                'material_id' => $materialId,
                'system_quantity' => $systemQuantity,
                'physical_quantity' => $physicalQuantity,
                'variance' => round(
                    $physicalQuantity - $systemQuantity,
                    3
                ),
                'item_notes' => $this->normalizeOptionalString(
                    $submittedItem['item_notes'] ?? null
                ),
            ];
        }

        $createdBy = Auth::id();

        if ($createdBy === null) {
            throw new RuntimeException('Authentication required.');
        }

        /*
         * Retain the user's department as optional historical metadata.
         * It is not used to filter candidates or audit history.
         */
        $departmentId = $this->authorization->departmentId();

        $reference = sprintf(
            'PSA-%s-%s',
            date('Ymd'),
            strtoupper(bin2hex(random_bytes(3)))
        );

        $auditId = $this->auditModel->createAudit(
            auditReference: $reference,
            departmentId: $departmentId,
            auditType: 'consumable',
            auditDate: $auditDate,
            auditNotes: $this->normalizeOptionalString($auditNotes),
            createdBy: $createdBy,
            items: $items
        );

        (new AuditLogService())->log(
            action: 'create_physical_stock_audit',
            module: 'physical_stock_audits',
            entityType: 'physical_stock_audit',
            entityId: $auditId,
            description: 'Physical stock audit created successfully.',
            newValues: [
                'audit_reference' => $reference,
                'audit_type' => 'consumable',
                'audit_date' => $auditDate,
                'department_id' => $departmentId,
                'item_count' => count($items),
                'include_all' => $includeAll,
                'created_by' => $createdBy,
            ]
        );

        return $auditId;
    }

    /**
     * Get paginated organization-wide audit history.
     *
     * Department is deliberately not used as an access boundary.
     */
    public function getPaginatedAudits(
        string $search = '',
        string $status = '',
        string $auditType = '',
        int $page = 1,
        int $perPage = 10
    ): array {
        $this->authorizeAuditAccess();

        $audits = $this->auditModel->getPaginatedAudits(
            null,
            $search,
            $status,
            $auditType,
            $page,
            $perPage
        );

        (new AuditLogService())->log(
            action: 'view_physical_audit_list',
            module: 'physical_stock_audits',
            description: 'Physical stock audit list viewed successfully.',
            newValues: [
                'search_filter_used' => $search !== '',
                'status_filter' => $status,
                'audit_type_filter' => $auditType,
                'page' => $page,
                'per_page' => $perPage,
            ]
        );

        return $audits;
    }


    /**
     * Get audit details, including legacy returnable audit history.
     */
    public function getAuditDetails(int $auditId): array
    {
        $this->authorizeAuditAccess();

        $audit = $this->auditModel->getAudit($auditId);

        if ($audit === null) {
            throw new RuntimeException(
                'Physical stock audit not found.'
            );
        }

        $auditType = (string) $audit['audit_type'];

        if (!in_array($auditType, ['consumable', 'returnable'], true)) {
            throw new RuntimeException(
                'Invalid physical stock audit type.'
            );
        }

        $audit['items'] = $this->auditModel->getAuditItems(
            $auditId,
            $auditType
        );

        if ($auditType === 'returnable') {
            $audit['return_history'] =
                $this->auditModel->getReturnHistory($auditId);
        }

        (new AuditLogService())->log(
            action: 'view_physical_audit_details',
            module: 'physical_stock_audits',
            entityType: 'physical_stock_audit',
            entityId: $auditId,
            description: 'Physical stock audit details viewed successfully.',
            newValues: [
                'audit_reference' => (string) $audit['audit_reference'],
                'audit_type' => $auditType,
                'audit_status' => (string) $audit['audit_status'],
            ]
        );

        return $audit;
    }

    /**
     * Property Custodian only: permission to record physical counts.
     */
    private function authorizeCustodian(): void
    {
        if ($this->authorization->currentUser() === null) {
            throw new RuntimeException('Authentication required.');
        }

        if (!$this->authorization->isPropertyCustodian()) {
            throw new RuntimeException(
                'Only a Property Custodian may record consumable physical counts.'
            );
        }
    }

    /**
     * Asset Manager and Property Custodian may view audit history.
     */
    private function authorizeAuditAccess(): void
    {
        if ($this->authorization->currentUser() === null) {
            throw new RuntimeException('Authentication required.');
        }

        if (!$this->authorization->canAccessAssetOperations()) {
            throw new RuntimeException(
                'You do not have permission to access physical stock audit records.'
            );
        }
    }

    private function validateQuantity(mixed $value): float
    {
        if (
            !is_numeric($value)
            || !is_finite((float) $value)
        ) {
            throw new RuntimeException(
                'Enter a valid physical quantity.'
            );
        }

        $quantity = (float) $value;

        if (
            $quantity < 0
            || round($quantity, 3) != $quantity
        ) {
            throw new RuntimeException(
                'Physical quantity must be zero or greater and use no more than three decimal places.'
            );
        }

        return round($quantity, 3);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException(
                'Notes must be valid text.'
            );
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
