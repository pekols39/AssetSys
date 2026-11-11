<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;
use JsonException;

class AuditLogService
{
    private PDO $db;

    private AuthorizationService $authorization;

    private const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'current_password',
        'new_password',
        'confirm_password',
        'token',
        'access_token',
        'refresh_token',
        'reset_token',
        'remember_token',
        'secret',
        'client_secret',
        'api_key',
        'private_key',
        'authorization',
        'cookie',
        'csrf_token',
        'session_id',
    ];

    public function __construct()
    {
        $this->db = Database::connection();
        $this->authorization = new AuthorizationService();
    }

    /**
     * Record an activity in the centralized audit log.
     *
     * This method does not start, commit, or roll back transactions.
     * It participates in any transaction already opened by the caller.
     */
    public function log(
        string $action,
        string $module,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        string $result = 'success',
        ?int $actorUserId = null
    ): int {
        $action = trim($action);
        $module = trim($module);

        if ($action === '' || mb_strlen($action) > 100) {
            throw new RuntimeException('Invalid audit action.');
        }

        if ($module === '' || mb_strlen($module) > 100) {
            throw new RuntimeException('Invalid audit module.');
        }

        if (!in_array($result, ['success', 'failure'], true)) {
            throw new RuntimeException('Invalid audit result.');
        }

        if ($entityId !== null && $entityId <= 0) {
            $entityId = null;
        }

        // Use the explicitly supplied actor ID when provided.
        // Otherwise, use the authenticated session user.
        $userId = $actorUserId ?? Auth::id();

        $user = null;

        if ($userId !== null) {
            $user = $actorUserId !== null
                ? $this->authorization->findActiveUserById($userId)
                : $this->authorization->currentUser();
        }

        $actorName = null;
        $actorRole = null;

        if (is_array($user)) {
            $nameParts = array_filter([
                trim((string) ($user['first_name'] ?? '')),
                trim((string) ($user['middle_name'] ?? '')),
                trim((string) ($user['last_name'] ?? '')),
            ]);

            $actorName = implode(' ', $nameParts);
            $actorRole = trim((string) ($user['role_name'] ?? ''));

            if ($actorName === '') {
                $actorName = null;
            }

            if ($actorRole === '') {
                $actorRole = null;
            }
        }

        $oldJson = $this->encodeValues($oldValues);
        $newJson = $this->encodeValues($newValues);

        // Store only the request path, excluding query parameters.
        // This prevents tokens in URLs from being recorded.
        $requestUri = parse_url(
            (string) ($_SERVER['REQUEST_URI'] ?? ''),
            PHP_URL_PATH
        );

        if (!is_string($requestUri)) {
            $requestUri = null;
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;

        $stmt = $this->db->prepare(
            'INSERT INTO audit_logs (
                user_id,
                actor_name,
                actor_role,
                action,
                result,
                module,
                entity_type,
                entity_id,
                description,
                old_values,
                new_values,
                ip_address,
                user_agent,
                request_method,
                request_uri
            ) VALUES (
                :user_id,
                :actor_name,
                :actor_role,
                :action,
                :result,
                :module,
                :entity_type,
                :entity_id,
                :description,
                :old_values,
                :new_values,
                :ip_address,
                :user_agent,
                :request_method,
                :request_uri
            )'
        );

        $stmt->execute([
            'user_id' => $userId,
            'actor_name' => $actorName,
            'actor_role' => $actorRole,
            'action' => $action,
            'result' => $result,
            'module' => $module,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $this->truncate($description, 500),
            'old_values' => $oldJson,
            'new_values' => $newJson,
            'ip_address' => $this->truncate($ipAddress, 45),
            'user_agent' => $this->truncate($userAgent, 500),
            'request_method' => $this->truncate($requestMethod, 10),
            'request_uri' => $this->truncate($requestUri, 500),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Encode audit values after recursively removing sensitive fields.
     */
    private function encodeValues(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        $sanitized = $this->sanitizeValues($values);

        try {
            return json_encode(
                $sanitized,
                JSON_THROW_ON_ERROR
                | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Unable to encode audit information.',
                0,
                $e
            );
        }
    }

    /**
     * Recursively remove sensitive information.
     */
    private function sanitizeValues(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $normalizedKey = strtolower(
                preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)
            );

            if (
                in_array(
                    $normalizedKey,
                    self::SENSITIVE_KEYS,
                    true
                )
                || str_contains($normalizedKey, 'password')
                || str_contains($normalizedKey, 'token')
                || str_contains($normalizedKey, 'secret')
            ) {
                continue;
            }

            if (is_array($value)) {
                $value = $this->sanitizeValues($value);
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Safely truncate optional metadata.
     */
    private function truncate(
        ?string $value,
        int $maximumLength
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maximumLength);
    }
    /**
     * Retrieve paginated audit records with optional filters.
     *
     * Supported filters:
     * date_from, date_to, actor, role, module, action,
     * result, keyword.
     *
     * @return array{
     *     logs: array<int, array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     total_pages: int
     * }
     */
    public function getPaginated(
        array $filters = [],
        int $page = 1,
        int $perPage = 25
    ): array {
        $page = max(1, $page);

        if (!in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 25;
        }

        $conditions = [];
        $params = [];

        // Date filters.
        $dateFrom = $filters['date_from'] ?? '';

        if (
            is_string($dateFrom)
            && $this->isValidAuditDate($dateFrom)
        ) {
            $conditions[] = 'created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = $filters['date_to'] ?? '';

        if (
            is_string($dateTo)
            && $this->isValidAuditDate($dateTo)
        ) {
            $conditions[] = 'created_at < :date_to';
            $params['date_to'] = date(
                'Y-m-d 00:00:00',
                strtotime($dateTo . ' +1 day')
            );
        }

        // Exact-match filters.
        $exactFilters = [
            'role' => 'actor_role',
            'module' => 'module',
            'action' => 'action',
            'result' => 'result',
        ];

        foreach ($exactFilters as $filterKey => $column) {
            $value = $filters[$filterKey] ?? '';

            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $conditions[] = "`{$column}` = :filter_{$filterKey}";
            $params["filter_{$filterKey}"] = trim($value);
        }

        // Actor search.
        $actor = $filters['actor'] ?? '';

        if (is_string($actor) && trim($actor) !== '') {
            $conditions[] = '(
                actor_name LIKE :actor_name
                OR CAST(user_id AS CHAR) LIKE :actor_user_id
            )';

            $actorSearch = '%' . trim($actor) . '%';

            $params['actor_name'] = $actorSearch;
            $params['actor_user_id'] = $actorSearch;
        }

        // General keyword search.
        $keyword = $filters['keyword'] ?? '';

        if (is_string($keyword) && trim($keyword) !== '') {
            $keywordSearch = '%' . trim($keyword) . '%';

            $conditions[] = '(
                actor_name LIKE :keyword_actor
                OR action LIKE :keyword_action
                OR module LIKE :keyword_module
                OR entity_type LIKE :keyword_entity
                OR description LIKE :keyword_description
                OR ip_address LIKE :keyword_ip
                OR request_uri LIKE :keyword_uri
                OR CAST(user_id AS CHAR) LIKE :keyword_user
                OR CAST(entity_id AS CHAR) LIKE :keyword_entity_id
            )';

            $params['keyword_actor'] = $keywordSearch;
            $params['keyword_action'] = $keywordSearch;
            $params['keyword_module'] = $keywordSearch;
            $params['keyword_entity'] = $keywordSearch;
            $params['keyword_description'] = $keywordSearch;
            $params['keyword_ip'] = $keywordSearch;
            $params['keyword_uri'] = $keywordSearch;
            $params['keyword_user'] = $keywordSearch;
            $params['keyword_entity_id'] = $keywordSearch;
        }

        $whereSql = $conditions !== []
            ? ' WHERE ' . implode(' AND ', $conditions)
            : '';

        // Count matching records.
        $countStmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM audit_logs'
            . $whereSql
        );

        $countStmt->execute($params);

        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(
            1,
            (int) ceil($total / $perPage)
        );

        $page = min($page, $totalPages);

        $offset = ($page - 1) * $perPage;

        // Retrieve the requested page.
        $stmt = $this->db->prepare(
            'SELECT
                id,
                user_id,
                actor_name,
                actor_role,
                action,
                result,
                module,
                entity_type,
                entity_id,
                description,
                old_values,
                new_values,
                ip_address,
                user_agent,
                request_method,
                request_uri,
                created_at
             FROM audit_logs'
            . $whereSql
            . ' ORDER BY created_at DESC, id DESC
                 LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue(
                ':' . $key,
                $value,
                PDO::PARAM_STR
            );
        }

        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'logs' => is_array($logs) ? $logs : [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Retrieve distinct values for audit filters.
     *
     * @return array{
     *     roles: array<int, string>,
     *     modules: array<int, string>,
     *     actions: array<int, string>
     * }
     */
    public function getFilterOptions(): array
    {
        $options = [];

        $columns = [
            'roles' => 'actor_role',
            'modules' => 'module',
            'actions' => 'action',
        ];

        foreach ($columns as $key => $column) {
            $stmt = $this->db->query(
                "SELECT DISTINCT `{$column}`
                 FROM audit_logs
                 WHERE `{$column}` IS NOT NULL
                   AND `{$column}` <> ''
                 ORDER BY `{$column}` ASC"
            );

            $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $options[$key] = array_map(
                'strval',
                is_array($values) ? $values : []
            );
        }

        return $options;
    }

    /**
     * Retrieve one audit record by its ID.
     */
    public function findLogById(int $logId): ?array
    {
        if ($logId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT
                id,
                user_id,
                actor_name,
                actor_role,
                action,
                result,
                module,
                entity_type,
                entity_id,
                description,
                old_values,
                new_values,
                ip_address,
                user_agent,
                request_method,
                request_uri,
                created_at
             FROM audit_logs
             WHERE id = :id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $logId,
        ]);

        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        return $log === false ? null : $log;
    }

    /**
     * Validate a YYYY-MM-DD date.
     */
    private function isValidAuditDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        [$year, $month, $day] = array_map(
            'intval',
            explode('-', $date)
        );

        return checkdate($month, $day, $year);
    }
}
