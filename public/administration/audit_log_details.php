<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\AuditLogService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$user = $authorization->currentUser();

if ($user === null) {
    Auth::logout();

    header(
        'Location: ' . base_url('/public/login.php')
    );

    exit;
}

if (!$authorization->isAdministrator()) {
    http_response_code(403);
    exit('403 Forbidden: Administrator access required.');
}

$logId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($logId === false || $logId === null || $logId <= 0) {
    http_response_code(400);
    exit('Invalid audit record ID.');
}

$auditService = new AuditLogService();
$log = $auditService->findLogById($logId);

if ($log === null) {
    http_response_code(404);
    exit('Audit record not found.');
}

// Record that an administrator viewed an audit record.
// Logging failure must not prevent the detail page from loading.
try {
    $auditService->log(
        action: 'view_audit_log_details',
        module: 'administration',
        entityType: 'audit_log',
        entityId: $logId,
        description: 'Audit log record details viewed.'
    );
} catch (\Throwable $e) {
    error_log(
        'Audit detail view logging failed: '
        . $e->getMessage()
    );
}

$escape = static function ($value): string {
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
};

// Decode stored JSON values for readable presentation.
$decodeValues = static function ($json): ?array {
    if (!is_string($json) || $json === '') {
        return null;
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : null;
};

$oldValues = $decodeValues($log['old_values']);
$newValues = $decodeValues($log['new_values']);

$formatJson = static function (?array $values): string {
    if ($values === null || $values === []) {
        return 'No recorded values.';
    }

    return json_encode(
        $values,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: 'Unable to display recorded values.';
};

$pageTitle = 'Audit Record Details';

require_once dirname(__DIR__) . '/includes/app_header.php';

?>

<div class="container-fluid py-4">

    <div class="d-flex flex-column flex-md-row
                justify-content-between align-items-md-center
                gap-3 mb-4">

        <div>
            <h1 class="h3 mb-1">Audit Record Details</h1>

            <p class="text-muted mb-0">
                Detailed activity information and recorded changes.
            </p>
        </div>

        <a
            href="<?= $escape(
                base_url('/public/administration/audit_trail.php')
            ) ?>"
            class="btn btn-outline-secondary"
        >
            <i class="fa-solid fa-arrow-left me-1"></i>
            Back to Audit Trail
        </a>

    </div>

    <!-- Record identification -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">
                Record #<?= (int) $log['id'] ?>
            </h2>
        </div>

        <div class="card-body">

            <div class="row g-4">

                <div class="col-12 col-md-4">
                    <div class="small text-muted">Date and Time</div>
                    <div class="fw-semibold">
                        <?= $escape($log['created_at']) ?>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="small text-muted">Result</div>

                    <?php if ($log['result'] === 'success'): ?>
                        <span class="badge bg-success">
                            Success
                        </span>
                    <?php else: ?>
                        <span class="badge bg-danger">
                            Failure
                        </span>
                    <?php endif; ?>
                </div>

                <div class="col-12 col-md-4">
                    <div class="small text-muted">Action</div>
                    <div class="fw-semibold">
                        <?= $escape(
                            ucwords(
                                str_replace('_', ' ', $log['action'])
                            )
                        ) ?>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="small text-muted">Actor</div>
                    <div class="fw-semibold">
                        <?= $escape($log['actor_name'] ?: 'Unknown User') ?>
                    </div>
                    <div class="small text-muted">
                        User ID: <?= $escape($log['user_id'] ?? 'N/A') ?>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="small text-muted">Role</div>
                    <div class="fw-semibold">
                        <?= $escape($log['actor_role'] ?: 'N/A') ?>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="small text-muted">Module</div>
                    <div class="fw-semibold">
                        <?= $escape(
                            ucwords(
                                str_replace('_', ' ', $log['module'])
                            )
                        ) ?>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="small text-muted">Entity Type</div>
                    <div>
                        <?= $escape($log['entity_type'] ?: 'N/A') ?>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="small text-muted">Entity ID</div>
                    <div>
                        <?= $escape($log['entity_id'] ?? 'N/A') ?>
                    </div>
                </div>

                <div class="col-12">
                    <div class="small text-muted">Description</div>
                    <div>
                        <?= $escape($log['description'] ?: 'No description recorded.') ?>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- Before and after values -->
    <div class="row g-4 mb-4">

        <div class="col-12 col-lg-6">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-header bg-white py-3">
                    <h2 class="h5 mb-0">
                        <i class="fa-solid fa-clock-rotate-left me-2"></i>
                        Previous Values
                    </h2>
                </div>

                <div class="card-body">

                    <pre class="bg-light border rounded p-3 mb-0"
                         style="white-space: pre-wrap; overflow-wrap: anywhere;"><?= $escape(
                             $formatJson($oldValues)
                         ) ?></pre>

                </div>

            </div>

        </div>

        <div class="col-12 col-lg-6">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-header bg-white py-3">
                    <h2 class="h5 mb-0">
                        <i class="fa-solid fa-arrow-right-arrow-left me-2"></i>
                        New Values
                    </h2>
                </div>

                <div class="card-body">

                    <pre class="bg-light border rounded p-3 mb-0"
                         style="white-space: pre-wrap; overflow-wrap: anywhere;"><?= $escape(
                             $formatJson($newValues)
                         ) ?></pre>

                </div>

            </div>

        </div>

    </div>

    <!-- Request metadata -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">
                <i class="fa-solid fa-network-wired me-2"></i>
                Request Metadata
            </h2>
        </div>

        <div class="card-body">

            <div class="row g-4">

                <div class="col-12 col-md-4">
                    <div class="small text-muted">IP Address</div>
                    <div class="fw-semibold">
                        <?= $escape($log['ip_address'] ?: 'N/A') ?>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="small text-muted">Request Method</div>
                    <div class="fw-semibold">
                        <?= $escape($log['request_method'] ?: 'N/A') ?>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="small text-muted">Request URI</div>
                    <div class="fw-semibold text-break">
                        <?= $escape($log['request_uri'] ?: 'N/A') ?>
                    </div>
                </div>

                <div class="col-12">
                    <div class="small text-muted">User Agent</div>
                    <div class="text-break">
                        <?= $escape($log['user_agent'] ?: 'N/A') ?>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <div class="alert alert-info mb-0">
        <i class="fa-solid fa-shield-halved me-2"></i>
        This audit record is displayed in read-only mode.
        No editing or deletion is available through this page.
    </div>

</div>

<?php
require_once dirname(__DIR__) . '/includes/app_footer.php';
?>
