<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use PDOException;
use RuntimeException;

final class AssetReferenceDataService
{
    private PDO $db;

    /**
     * Only asset-master reference lists belong here. Workflow states,
     * user roles, and other security-controlled values are intentionally
     * excluded.
     */
    private const TYPES = [
        'categories' => [
            'table' => 'asset_categories',
            'label' => 'Categories',
            'singular' => 'Category',
        ],
        'statuses' => [
            'table' => 'asset_statuses',
            'label' => 'Statuses',
            'singular' => 'Status',
        ],
        'conditions' => [
            'table' => 'asset_conditions',
            'label' => 'Conditions',
            'singular' => 'Condition',
        ],
        'departments' => [
            'table' => 'departments',
            'label' => 'Owner / Departments',
            'singular' => 'Owner / Department',
        ],
        'locations' => [
            'table' => 'locations',
            'label' => 'Locations',
            'singular' => 'Location',
        ],
        'suppliers' => [
            'table' => 'vendors',
            'label' => 'Suppliers',
            'singular' => 'Supplier',
        ],
    ];

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public static function types(): array
    {
        return self::TYPES;
    }

    public static function type(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            throw new RuntimeException('Invalid asset reference-data type.');
        }

        return self::TYPES[$type];
    }

    public function getAll(string $type): array
    {
        $config = self::type($type);
        $table = $config['table'];

        $stmt = $this->db->query(
            "SELECT id, name, is_active
             FROM {$table}
             ORDER BY is_active DESC, name ASC, id ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(string $type, string $name): void
    {
        $config = self::type($type);
        $name = $this->normalizeName($name);
        $this->assertUniqueName($config['table'], $name);

        $stmt = $this->db->prepare(
            "INSERT INTO {$config['table']} (name, is_active)
             VALUES (:name, 1)"
        );

        try {
            $stmt->execute(['name' => $name]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new RuntimeException('That value already exists.');
            }

            throw $e;
        }
    }

    public function update(string $type, int $id, string $name): void
    {
        $config = self::type($type);

        if ($id <= 0) {
            throw new RuntimeException('Invalid reference-data record.');
        }

        $name = $this->normalizeName($name);
        $this->assertUniqueName($config['table'], $name, $id);

        if ($type === 'statuses') {
            $current = $this->findName($config['table'], $id);
            $protected = ['assigned', 'in use', 'lost', 'retired', 'disposed', 'for disposal', 'pending disposal'];

            if (
                $current !== null
                && in_array(strtolower(trim($current)), $protected, true)
                && strcasecmp(trim($current), $name) !== 0
            ) {
                throw new RuntimeException(
                    'This status is used by the asset lifecycle rules and cannot be renamed.'
                );
            }
        }

        $stmt = $this->db->prepare(
            "UPDATE {$config['table']}
             SET name = :name
             WHERE id = :id
             LIMIT 1"
        );

        $stmt->execute([
            'name' => $name,
            'id' => $id,
        ]);

        if ($stmt->rowCount() === 0 && $this->findName($config['table'], $id) === null) {
            throw new RuntimeException('Reference-data record not found.');
        }
    }

    public function setActive(string $type, int $id, bool $active): void
    {
        $config = self::type($type);

        if ($id <= 0 || $this->findName($config['table'], $id) === null) {
            throw new RuntimeException('Reference-data record not found.');
        }

        if (!$active && $type === 'statuses') {
            $name = strtolower(trim((string) $this->findName($config['table'], $id)));
            $protected = ['assigned', 'in use', 'lost', 'retired', 'disposed', 'for disposal', 'pending disposal'];

            if (in_array($name, $protected, true)) {
                throw new RuntimeException(
                    'This status is used by the asset lifecycle rules and cannot be deactivated.'
                );
            }
        }

        $stmt = $this->db->prepare(
            "UPDATE {$config['table']}
             SET is_active = :is_active
             WHERE id = :id"
        );

        $stmt->execute([
            'is_active' => $active ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function delete(string $type, int $id): string
    {
        $config = self::type($type);

        if ($id <= 0 || $this->findName($config['table'], $id) === null) {
            throw new RuntimeException('Reference-data record not found.');
        }

        if ($type === 'statuses') {
            $name = strtolower(trim((string) $this->findName($config['table'], $id)));
            $protected = ['assigned', 'in use', 'lost', 'retired', 'disposed', 'for disposal', 'pending disposal'];

            if (in_array($name, $protected, true)) {
                throw new RuntimeException(
                    'This status is required by the asset lifecycle rules. Deactivate or delete is not allowed.'
                );
            }
        }

        try {
            $stmt = $this->db->prepare(
                "DELETE FROM {$config['table']} WHERE id = :id"
            );
            $stmt->execute(['id' => $id]);

            return 'Reference value deleted.';
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            // Existing asset or workflow records still reference this value.
            // Preserve their foreign keys and hide the value from new selections.
            $stmt = $this->db->prepare(
                "UPDATE {$config['table']}
                 SET is_active = 0
                 WHERE id = :id"
            );
            $stmt->execute(['id' => $id]);

            return 'This value is already used by existing records, so it was deactivated instead of permanently deleted.';
        }
    }

    private function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '') {
            throw new RuntimeException('A name is required.');
        }

        if (mb_strlen($name) > 150) {
            throw new RuntimeException('The name cannot exceed 150 characters.');
        }

        return $name;
    }

    private function assertUniqueName(string $table, string $name, ?int $ignoreId = null): void
    {
        $sql = "SELECT id FROM {$table} WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))";
        $params = ['name' => $name];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore_id';
            $params['ignore_id'] = $ignoreId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetchColumn() !== false) {
            throw new RuntimeException('That value already exists.');
        }
    }

    private function findName(string $table, int $id): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT name FROM {$table} WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }
}
