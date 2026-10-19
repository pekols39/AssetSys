<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class AssetQrScanLog
{
    public function create(
        int $qrCodeId,
        int $assetId,
        ?int $scannedBy
    ): bool {
        if ($qrCodeId <= 0 || $assetId <= 0) {
            throw new \InvalidArgumentException(
                'A valid QR code and asset are required.'
            );
        }

        if ($scannedBy !== null && $scannedBy <= 0) {
            throw new \InvalidArgumentException(
                'The scanning user ID must be positive.'
            );
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'INSERT INTO asset_qr_scan_logs
                (asset_qr_code_id, asset_id, scanned_by)
             VALUES
                (:qr_code_id, :asset_id, :scanned_by)'
        );

        $stmt->bindValue(':qr_code_id', $qrCodeId, PDO::PARAM_INT);
        $stmt->bindValue(':asset_id', $assetId, PDO::PARAM_INT);

        if ($scannedBy === null) {
            $stmt->bindValue(':scanned_by', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':scanned_by', $scannedBy, PDO::PARAM_INT);
        }

        return $stmt->execute();
    }


    /**
     * Retrieve filtered, paginated QR scan history.
     * QR tokens are deliberately excluded from monitoring results.
     */
    public function getRecentScans(
        int $limit = 10,
        int $offset = 0,
        array $filters = []
    ): array {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        [$whereSql, $params] = $this->buildScanFilters($filters);

        $sql = '
            SELECT
                logs.id AS scan_log_id,
                logs.asset_qr_code_id,
                logs.asset_id,
                logs.scanned_by,
                logs.scanned_at,
                assets.asset_tag,
                assets.asset_name,
                users.first_name,
                users.last_name,
                users.username
            FROM asset_qr_scan_logs AS logs
            INNER JOIN assets
                ON assets.id = logs.asset_id
            INNER JOIN asset_qr_codes AS qr
                ON qr.id = logs.asset_qr_code_id
            LEFT JOIN users
                ON users.id = logs.scanned_by
            ' . $whereSql . '
            ORDER BY logs.scanned_at DESC, logs.id DESC
            LIMIT :limit OFFSET :offset
        ';

        $stmt = Database::connection()->prepare($sql);

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_STR);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count QR scans matching the supplied filters.
     */
    public function countScans(array $filters = []): int
    {
        [$whereSql, $params] = $this->buildScanFilters($filters);

        $sql = '
            SELECT COUNT(*)
            FROM asset_qr_scan_logs AS logs
            INNER JOIN assets
                ON assets.id = logs.asset_id
            LEFT JOIN users
                ON users.id = logs.scanned_by
            ' . $whereSql;

        $stmt = Database::connection()->prepare($sql);

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_STR);
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Build parameterized filters shared by history and count queries.
     */
    private function buildScanFilters(array $filters): array
    {
        $conditions = [];
        $params = [];

        $assetTag = trim((string) ($filters['asset_tag'] ?? ''));

        if ($assetTag !== '') {
            $conditions[] = 'assets.asset_tag LIKE :asset_tag';
            $params[':asset_tag'] = '%' . $assetTag . '%';
        }

        $scanner = trim((string) ($filters['scanner'] ?? ''));

        if ($scanner !== '') {
            $conditions[] = "(
                users.username LIKE :scanner_username
                OR users.first_name LIKE :scanner_first_name
                OR users.last_name LIKE :scanner_last_name
                OR CONCAT_WS(' ', users.first_name, users.last_name)
                    LIKE :scanner_full_name
            )";

            $search = '%' . $scanner . '%';
            $params[':scanner_username'] = $search;
            $params[':scanner_first_name'] = $search;
            $params[':scanner_last_name'] = $search;
            $params[':scanner_full_name'] = $search;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));

        if ($dateFrom !== '') {
            $conditions[] = 'logs.scanned_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));

        if ($dateTo !== '') {
            $conditions[] = 'logs.scanned_at < :date_to_exclusive';
            $params[':date_to_exclusive'] = date(
                'Y-m-d H:i:s',
                strtotime($dateTo . ' +1 day')
            );
        }

        $whereSql = $conditions === []
            ? ''
            : ' WHERE ' . implode(' AND ', $conditions);

        return [$whereSql, $params];
    }
}
