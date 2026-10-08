<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

class AdministrationDashboardService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function getSummary(): array
    {
        $stmt = $this->db->query(
            'SELECT
                COUNT(*) AS total_users,
                SUM(
                    CASE
                        WHEN account_status = \'active\'
                             AND is_active = 1
                        THEN 1
                        ELSE 0
                    END
                ) AS active_users,
                SUM(
                    CASE
                        WHEN account_status <> \'active\'
                             OR is_active = 0
                        THEN 1
                        ELSE 0
                    END
                ) AS inactive_users,
                SUM(
                    CASE
                        WHEN must_change_password = 1
                        THEN 1
                        ELSE 0
                    END
                ) AS password_change_users
             FROM users'
        );

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException(
                'Unable to load administrator dashboard summary.'
            );
        }

        return [
            'total_users' => (int) ($row['total_users'] ?? 0),
            'active_users' => (int) ($row['active_users'] ?? 0),
            'inactive_users' => (int) ($row['inactive_users'] ?? 0),
            'password_change_users' => (int) (
                $row['password_change_users'] ?? 0
            ),
        ];
    }

    public function getSystemStatus(): array
    {
        $databaseConnected = false;

        try {
            $this->db->query('SELECT 1');
            $databaseConnected = true;
        } catch (\Throwable) {
            $databaseConnected = false;
        }

        $storagePath =
            dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR
            . 'storage';

        return [
            'database' => $databaseConnected,

            'storage_exists' =>
                is_dir($storagePath),

            'storage_writable' =>
                is_dir($storagePath)
                && is_writable($storagePath),

            'debug_mode' =>
                defined('APP_DEBUG')
                && APP_DEBUG === true,
        ];
    }
}