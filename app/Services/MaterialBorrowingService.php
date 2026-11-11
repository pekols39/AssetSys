<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\MaterialBorrowing;
use PDO;
use RuntimeException;
use Throwable;

class MaterialBorrowingService
{
    private PDO $db;
    private AuthorizationService $authorization;
    private MaterialBorrowing $borrowingModel;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->authorization = new AuthorizationService();
        $this->borrowingModel = new MaterialBorrowing();
    }

    public function getBorrowableMaterials(): array
    {
        $this->authorizeAccess();

        $materials = $this->borrowingModel->getBorrowableMaterials();

        (new AuditLogService())->log(
            action: 'view_borrowable_materials',
            module: 'material_borrowing',
            entityType: 'material',
            description: 'Borrowable materials list viewed.',
            newValues: [
                'materials_count' => count($materials),
            ]
        );

        return $materials;
    }

    public function getOutstandingPage(
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        $this->authorizeAccess();

        $result = $this->borrowingModel->getOutstandingPage(
            $filters,
            $page,
            $perPage
        );

        (new AuditLogService())->log(
            action: 'view_outstanding_borrowings',
            module: 'material_borrowing',
            entityType: 'material_borrowing',
            description: 'Outstanding material borrowings list viewed.',
            newValues: [
                'page' => $page,
                'per_page' => $perPage,
            ]
        );

        return $result;
    }

    public function getHistoryPage(
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        $this->authorizeAccess();

        $result = $this->borrowingModel->getHistoryPage(
            $filters,
            $page,
            $perPage
        );

        (new AuditLogService())->log(
            action: 'view_borrowing_history',
            module: 'material_borrowing',
            entityType: 'material_borrowing',
            description: 'Material borrowing history viewed.',
            newValues: [
                'page' => $page,
                'per_page' => $perPage,
            ]
        );

        return $result;
    }

    public function findDetails(int $borrowingId): array|false
    {
        $this->authorizeAccess();

        if ($borrowingId <= 0) {
            return false;
        }

        $result = $this->borrowingModel->findDetails($borrowingId);

        if ($result !== false) {
            (new AuditLogService())->log(
                action: 'view_borrowing_details',
                module: 'material_borrowing',
                entityType: 'material_borrowing',
                entityId: $borrowingId,
                description: 'Material borrowing details viewed.'
            );
        }

        return $result;
    }

    public function createBorrowing(
        int $materialId,
        string $borrowerName,
        string $quantity,
        string $location,
        string $purpose,
        string $borrowedAt,
        string $expectedReturnDate,
        ?string $referenceNumber = null,
        ?string $notes = null
    ): int {
        $this->authorizeAccess();
        $userId = (int) Auth::id();

        $borrowerName = $this->requiredText($borrowerName, 255, 'Borrower name is required.');
        $location = $this->requiredText($location, 255, 'Location is required.');
        $purpose = $this->requiredText($purpose, 255, 'Purpose is required.');
        $quantityMilli = $this->quantityToMilli($quantity);
        $borrowedAt = $this->validateDateTime($borrowedAt, 'Borrow date and time is invalid.');
        $expectedReturnDate = $this->validateDate($expectedReturnDate, 'Expected return date is invalid.');
        $referenceNumber = $this->optionalText($referenceNumber, 100);
        $notes = $this->optionalText($notes, 1000);

        if ($materialId <= 0) {
            throw new RuntimeException('Please select a valid material.');
        }
        if ($expectedReturnDate < substr($borrowedAt, 0, 10)) {
            throw new RuntimeException('Expected return date cannot be earlier than the borrow date.');
        }

        $this->db->beginTransaction();
        try {
            $stockStmt = $this->db->prepare(
                'SELECT
                    ms.id,
                    ms.quantity,
                    m.code,
                    m.name,
                    m.unit
                 FROM material_stocks ms
                 INNER JOIN materials m ON m.id = ms.material_id
                 WHERE ms.material_id = :material_id
                   AND ms.department_id IS NULL
                   AND m.is_active = 1
                   AND m.is_returnable = 1
                 LIMIT 1
                 FOR UPDATE'
            );
            $stockStmt->execute(['material_id' => $materialId]);
            $stock = $stockStmt->fetch();

            if (!$stock) {
                throw new RuntimeException('The selected material is not an active returnable material with an organization-wide stock record.');
            }

            $availableMilli = $this->quantityToMilli((string) $stock['quantity'], true);
            if ($quantityMilli > $availableMilli) {
                throw new RuntimeException('Insufficient available stock. Current available quantity: ' . $this->milliToString($availableMilli) . ' ' . $stock['unit'] . '.');
            }

            $newBalance = $availableMilli - $quantityMilli;
            $update = $this->db->prepare(
                'UPDATE material_stocks SET quantity = :quantity WHERE id = :id'
            );
            $update->execute([
                'quantity' => $this->milliToString($newBalance),
                'id' => (int) $stock['id'],
            ]);

            $insert = $this->db->prepare(
                'INSERT INTO material_borrowings (
                    material_stock_id, borrower_name, quantity, location, purpose,
                    borrowed_at, expected_return_date, reference_number, notes, recorded_by
                 ) VALUES (
                    :material_stock_id, :borrower_name, :quantity, :location, :purpose,
                    :borrowed_at, :expected_return_date, :reference_number, :notes, :recorded_by
                 )'
            );
            $insert->execute([
                'material_stock_id' => (int) $stock['id'],
                'borrower_name' => $borrowerName,
                'quantity' => $this->milliToString($quantityMilli),
                'location' => $location,
                'purpose' => $purpose,
                'borrowed_at' => $borrowedAt,
                'expected_return_date' => $expectedReturnDate,
                'reference_number' => $referenceNumber,
                'notes' => $notes,
                'recorded_by' => $userId,
            ]);

            $id = (int) $this->db->lastInsertId();

            (new AuditLogService())->log(
                action: 'create_material_borrowing',
                module: 'material_borrowing',
                entityType: 'material_borrowing',
                entityId: $id,
                description: 'Material borrowing record created successfully.',
                oldValues: [
                    'material_id' => $materialId,
                    'material_stock_id' => (int) $stock['id'],
                    'available_quantity' => $this->milliToString($availableMilli),
                ],
                newValues: [
                    'material_id' => $materialId,
                    'material_code' => (string) $stock['code'],
                    'material_name' => (string) $stock['name'],
                    'material_stock_id' => (int) $stock['id'],
                    'borrower_name' => $borrowerName,
                    'quantity_borrowed' => $this->milliToString($quantityMilli),
                    'location' => $location,
                    'purpose' => $purpose,
                    'borrowed_at' => $borrowedAt,
                    'expected_return_date' => $expectedReturnDate,
                    'reference_number' => $referenceNumber,
                    'available_quantity_after' => $this->milliToString($newBalance),
                    'recorded_by' => $userId,
                ]
            );

            $this->db->commit();

            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function recordReturn(
        int $borrowingId,
        string $quantity,
        string $returnedAt,
        string $condition,
        ?string $conditionNotes = null,
        ?string $notes = null
    ): int {
        $this->authorizeAccess();
        $userId = (int) Auth::id();
        $quantityMilli = $this->quantityToMilli($quantity);
        $returnedAt = $this->validateDateTime($returnedAt, 'Return date and time is invalid.');
        $allowedConditions = ['good', 'damaged', 'incomplete', 'other'];

        if ($borrowingId <= 0) {
            throw new RuntimeException('Invalid borrowing record.');
        }
        if (!in_array($condition, $allowedConditions, true)) {
            throw new RuntimeException('Select a valid item condition.');
        }

        $conditionNotes = $this->optionalText($conditionNotes, 5000);
        $notes = $this->optionalText($notes, 1000);
        if (in_array($condition, ['damaged', 'incomplete', 'other'], true) && $conditionNotes === null) {
            throw new RuntimeException('Please describe the item condition.');
        }

        $this->db->beginTransaction();
        try {
            // Lock the parent borrowing row first. Every return operation locks
            // this same row, serializing partial returns for this borrowing.
            $borrowStmt = $this->db->prepare(
                'SELECT id, material_stock_id, quantity, borrowed_at
                 FROM material_borrowings
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $borrowStmt->execute(['id' => $borrowingId]);
            $borrowing = $borrowStmt->fetch();
            if (!$borrowing) {
                throw new RuntimeException('Borrowing record not found.');
            }

            if ($returnedAt < (string) $borrowing['borrowed_at']) {
                throw new RuntimeException('Return date and time cannot be earlier than the borrow date and time.');
            }

            $returnsStmt = $this->db->prepare(
                'SELECT COALESCE(SUM(quantity), 0)
                 FROM material_borrowing_returns
                 WHERE borrowing_id = :borrowing_id'
            );
            $returnsStmt->execute(['borrowing_id' => $borrowingId]);
            $returnedQuantity = (string) $returnsStmt->fetchColumn();

            $borrowedMilli = $this->quantityToMilli((string) $borrowing['quantity']);
            $returnedMilli = $this->quantityToMilli($returnedQuantity, true);
            $outstandingMilli = $borrowedMilli - $returnedMilli;
            if ($quantityMilli > $outstandingMilli) {
                throw new RuntimeException('Return quantity exceeds the outstanding borrowed quantity of ' . $this->milliToString(max(0, $outstandingMilli)) . '.');
            }

            $stockStmt = $this->db->prepare(
                'SELECT id, quantity FROM material_stocks WHERE id = :id FOR UPDATE'
            );
            $stockStmt->execute(['id' => (int) $borrowing['material_stock_id']]);
            $stock = $stockStmt->fetch();
            if (!$stock) {
                throw new RuntimeException('The linked material stock record could not be found.');
            }

            $newBalance = $this->quantityToMilli((string) $stock['quantity'], true) + $quantityMilli;
            $update = $this->db->prepare(
                'UPDATE material_stocks SET quantity = :quantity WHERE id = :id'
            );
            $update->execute([
                'quantity' => $this->milliToString($newBalance),
                'id' => (int) $stock['id'],
            ]);

            $insert = $this->db->prepare(
                'INSERT INTO material_borrowing_returns (
                    borrowing_id, quantity, returned_at, item_condition,
                    condition_notes, notes, recorded_by
                 ) VALUES (
                    :borrowing_id, :quantity, :returned_at, :item_condition,
                    :condition_notes, :notes, :recorded_by
                 )'
            );
            $insert->execute([
                'borrowing_id' => $borrowingId,
                'quantity' => $this->milliToString($quantityMilli),
                'returned_at' => $returnedAt,
                'item_condition' => $condition,
                'condition_notes' => $conditionNotes,
                'notes' => $notes,
                'recorded_by' => $userId,
            ]);

            $id = (int) $this->db->lastInsertId();

            (new AuditLogService())->log(
                action: 'record_material_return',
                module: 'material_borrowing',
                entityType: 'material_borrowing_return',
                entityId: $id,
                description: 'Material return recorded successfully.',
                oldValues: [
                    'borrowing_id' => $borrowingId,
                    'borrowed_quantity' => $this->milliToString($borrowedMilli),
                    'previously_returned_quantity' => $this->milliToString($returnedMilli),
                    'outstanding_quantity' => $this->milliToString($outstandingMilli),
                    'stock_quantity' => $this->milliToString(
                        $this->quantityToMilli((string) $stock['quantity'], true)
                    ),
                ],
                newValues: [
                    'borrowing_id' => $borrowingId,
                    'return_id' => $id,
                    'quantity_returned' => $this->milliToString($quantityMilli),
                    'total_returned_quantity' => $this->milliToString(
                        $returnedMilli + $quantityMilli
                    ),
                    'remaining_outstanding_quantity' => $this->milliToString(
                        $outstandingMilli - $quantityMilli
                    ),
                    'returned_at' => $returnedAt,
                    'item_condition' => $condition,
                    'condition_notes' => $conditionNotes,
                    'stock_quantity_after' => $this->milliToString($newBalance),
                    'recorded_by' => $userId,
                ]
            );

            $this->db->commit();

            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function authorizeAccess(): array
    {
        $user = $this->authorization->currentUser();
        if ($user === null) {
            throw new RuntimeException('Authentication required.');
        }
        if (!$this->authorization->isPropertyCustodian()) {
            throw new RuntimeException('You do not have permission to access material borrowing.');
        }
        return $user;
    }

    private function quantityToMilli(string $value, bool $allowZero = false): int
    {
        $value = trim($value);
        if (!preg_match('/^\d+(?:\.\d{1,3})?$/', $value)) {
            throw new RuntimeException('Quantity must be greater than zero and have no more than three decimal places.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 11) {
            throw new RuntimeException('Quantity is too large.');
        }
        $milli = ((int) $whole * 1000) + (int) $fraction;
        if (($allowZero ? $milli < 0 : $milli <= 0) || $milli > 99999999999999) {
            throw new RuntimeException('Quantity must be greater than zero and within the supported range.');
        }
        return $milli;
    }

    private function milliToString(int $milli): string
    {
        return intdiv($milli, 1000) . '.' . str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function requiredText(string $value, int $max, string $message): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new RuntimeException($message);
        }
        return $value;
    }

    private function optionalText(?string $value, int $max): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new RuntimeException('One of the optional text fields exceeds its maximum length.');
        }
        return $value;
    }

    private function validateDate(string $value, string $message): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException($message);
        }
        return $value;
    }

    private function validateDateTime(string $value, string $message): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
        if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
            throw new RuntimeException($message);
        }
        return $date->format('Y-m-d H:i:s');
    }
}
