<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class Notification
{
    /**
     * Notify every active user assigned to the Asset Manager role.
     */
    public function notifyAllActiveAssetManagers(
        string $type,
        string $title,
        string $message,
        string $relatedType,
        int $relatedId
    ): void {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'INSERT INTO notifications (
                user_id,
                notification_type,
                title,
                message,
                related_type,
                related_id
            )
            SELECT
                u.id,
                :notification_type,
                :title,
                :message,
                :related_type,
                :related_id
            FROM users AS u
            INNER JOIN roles AS r
                ON r.id = u.role_id
            WHERE r.name = :role_name
              AND r.is_active = 1
              AND u.account_status = :account_status
              AND u.is_active = 1'
        );

        $stmt->execute([
            ':notification_type' => $type,
            ':title' => $title,
            ':message' => $message,
            ':related_type' => $relatedType,
            ':related_id' => $relatedId,
            ':role_name' => 'Asset Manager',
            ':account_status' => 'active',
        ]);
    }

    /**
     * Notify one user, typically the original request submitter.
     */
    public function notifyUser(
        int $userId,
        string $type,
        string $title,
        string $message,
        string $relatedType,
        int $relatedId
    ): void {
        if ($userId <= 0 || $relatedId <= 0) {
            throw new \InvalidArgumentException(
                'A valid user and related record are required.'
            );
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO notifications (
                user_id,
                notification_type,
                title,
                message,
                related_type,
                related_id
            )
            VALUES (
                :user_id,
                :notification_type,
                :title,
                :message,
                :related_type,
                :related_id
            )'
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':notification_type', $type, PDO::PARAM_STR);
        $stmt->bindValue(':title', $title, PDO::PARAM_STR);
        $stmt->bindValue(':message', $message, PDO::PARAM_STR);
        $stmt->bindValue(':related_type', $relatedType, PDO::PARAM_STR);
        $stmt->bindValue(':related_id', $relatedId, PDO::PARAM_INT);

        $stmt->execute();
    }

    /**
     * Retrieve the most recent notifications belonging to one user.
     */
    public function getForUser(
        int $userId,
        int $limit = 20
    ): array {
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min($limit, 100));

        $stmt = Database::connection()->prepare(
            'SELECT
            n.id,
            n.notification_type,
            n.title,
            n.message,
            n.related_type,
            n.related_id,
            n.is_read,
            n.read_at,
            n.created_at,
            a.id AS asset_id,
            a.asset_code,
            a.asset_name
        FROM notifications AS n
        LEFT JOIN asset_disposals AS d
            ON n.related_type = \'disposal\'
            AND d.id = n.related_id
        LEFT JOIN asset_department_transfers AS t
            ON n.related_type = \'department_transfer\'
            AND t.id = n.related_id
        LEFT JOIN assets AS a
            ON a.id = CASE
                WHEN n.related_type = \'disposal\'
                    THEN d.asset_id
                WHEN n.related_type = \'department_transfer\'
                    THEN t.asset_id
                ELSE NULL
            END
        WHERE n.user_id = :user_id
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT :limit'
        );

        $stmt->bindValue(
            ':user_id',
            $userId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count unread notifications belonging to one user.
     */
    public function countUnreadForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*)
             FROM notifications
             WHERE user_id = :user_id
               AND is_read = 0'
        );

        $stmt->bindValue(
            ':user_id',
            $userId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Mark a notification as read only if it belongs to the user.
     */
    public function markAsRead(
        int $notificationId,
        int $userId
    ): bool {
        if ($notificationId <= 0 || $userId <= 0) {
            return false;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE notifications
             SET is_read = 1,
                 read_at = CURRENT_TIMESTAMP
             WHERE id = :notification_id
               AND user_id = :user_id
               AND is_read = 0'
        );

        $stmt->bindValue(
            ':notification_id',
            $notificationId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':user_id',
            $userId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
}
