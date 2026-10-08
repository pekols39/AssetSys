<?php

declare(strict_types=1);

namespace App\Core;

require_once dirname(__DIR__) . '/config/config.php';

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_DATABASE
        );

        try {
            self::$connection = new PDO(
                $dsn,
                DB_USERNAME,
                DB_PASSWORD,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            return self::$connection;
        } catch (PDOException $e) {
            if (defined('APP_DEBUG') && APP_DEBUG === true) {
                throw new RuntimeException(
                    'Database connection failed: ' . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }

            throw new RuntimeException(
                'Database connection failed.',
                (int) $e->getCode(),
                $e
            );
        }
    }
}