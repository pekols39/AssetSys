<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

class ReferenceDataService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function getAssetCategories(): array
    {
        return $this->getActiveRecords(
            'asset_categories',
            'name'
        );
    }

    public function getAssetStatuses(): array
    {
        return $this->getActiveRecords(
            'asset_statuses',
            'name'
        );
    }

    public function getAssetConditions(): array
    {
        return $this->getActiveRecords(
            'asset_conditions',
            'name'
        );
    }

    public function getLocations(): array
    {
        return $this->getActiveRecords(
            'locations',
            'name'
        );
    }
    public function getDepartments(): array
    {
        return $this->getActiveRecords(
            'departments',
            'name'
        );
    }

    public function getRoles(): array
    {
        $stmt = $this->db->query(
            'SELECT
            id,
            name
         FROM roles
         ORDER BY
            CASE name
                WHEN \'Administrator\' THEN 1
                WHEN \'Asset Manager\' THEN 2
                WHEN \'Asset Custodian\' THEN 3
                ELSE 4
            END,
            name ASC'
        );

        return $stmt->fetchAll();
    }
    public function getUsers(): array
    {
        $stmt = $this->db->query(
            'SELECT
                id,
                first_name,
                last_name,
                username,
                employee_number
             FROM users
             WHERE is_active = 1
               AND account_status = \'active\'
             ORDER BY last_name ASC,
                      first_name ASC,
                      username ASC'
        );

        return $stmt->fetchAll();
    }

    public function getVendors(): array
    {
        return $this->getActiveRecords(
            'vendors',
            'name'
        );
    }

    private function getActiveRecords(
        string $table,
        string $orderBy
    ): array {

        $allowedTables = [
            'asset_categories',
            'asset_statuses',
            'asset_conditions',
            'locations',
            'vendors',
            'departments',
        ];

        $allowedOrderColumns = [
            'name',
        ];

        if (
            !in_array(
                $table,
                $allowedTables,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid reference table.'
            );
        }

        if (
            !in_array(
                $orderBy,
                $allowedOrderColumns,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid reference sort column.'
            );
        }

        $sql = sprintf(
            'SELECT
                id,
                name
             FROM %s
             WHERE is_active = 1
             ORDER BY %s ASC',
            $table,
            $orderBy
        );

        $stmt = $this->db->query($sql);

        return $stmt->fetchAll();
    }
}