<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

class Setting
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function get(
        string $key,
        ?string $default = null
    ): ?string {
        $stmt = $this->db->prepare(
            'SELECT setting_value
             FROM settings
             WHERE setting_key = :setting_key
             LIMIT 1'
        );

        $stmt->execute([
            'setting_key' => $key,
        ]);

        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        return (string) $value;
    }

    public function getInt(
        string $key,
        int $default = 0
    ): int {
        return (int) $this->get(
            $key,
            (string) $default
        );
    }

    /**
     * Retrieve all settings for the administration interface.
     */
    public function getAll(): array
    {
        $stmt = $this->db->query(
            'SELECT
                id,
                setting_key,
                setting_value,
                setting_type,
                description,
                is_editable,
                updated_by,
                updated_at
             FROM settings
             ORDER BY setting_key ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update an existing setting only when it is editable.
     *
     * The caller must validate the submitted value according
     * to the setting's type before calling this method.
     */
    public function updateEditable(
        string $key,
        string $value,
        int $updatedBy
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE settings
             SET setting_value = :setting_value,
                 updated_by = :updated_by,
                 updated_at = CURRENT_TIMESTAMP
             WHERE setting_key = :setting_key
               AND is_editable = 1'
        );

        $stmt->execute([
            'setting_value' => $value,
            'updated_by' => $updatedBy,
            'setting_key' => $key,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        // Distinguish an unchanged value from a missing or
        // non-editable setting.
        $check = $this->db->prepare(
            'SELECT is_editable
             FROM settings
             WHERE setting_key = :setting_key
             LIMIT 1'
        );

        $check->execute([
            'setting_key' => $key,
        ]);

        $editable = $check->fetchColumn();

        if ($editable === false || (int) $editable !== 1) {
            throw new RuntimeException(
                'The setting does not exist or cannot be edited.'
            );
        }

        return true;
    }
}