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

$auditService = new AuditLogService();

// Read and validate filters.
$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'actor' => trim((string) ($_GET['actor'] ?? '')),
    'role' => trim((string) ($_GET['role'] ?? '')),
    'module' => trim((string) ($_GET['module'] ?? '')),
    'action' => trim((string) ($_GET['action'] ?? '')),
    'result' => trim((string) ($_GET['result'] ?? '')),
    'keyword' => trim((string) ($_GET['keyword'] ?? '')),
];

// Only accept supported result values.
if (!in_array($filters['result'], ['', 'success', 'failure'], true)) {
    $filters['result'] = '';
}

// Pagination.
$page = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT
);

$page = $page !== false && $page !== null
    ? max(1, $page)
    : 1;

$perPage = filter_var(
    $_GET['per_page'] ?? 25,
    FILTER_VALIDATE_INT
);

if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 25;
}

// Retrieve records and filter options.
$pagination = $auditService->getPaginated(
    $filters,
    $page,
    $perPage
);

$filterOptions = $auditService->getFilterOptions();

$logs = $pagination['logs'];
$totalRecords = $pagination['total'];

// Summary counts.
$summaryStmt = \App\Core\Database::connection()->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN result = 'success' THEN 1 ELSE 0 END) AS successful,
        SUM(CASE WHEN result = 'failure' THEN 1 ELSE 0 END) AS failed
     FROM audit_logs"
);

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    'successful' => 0,
    'failed' => 0,
];

$pageTitle = 'Audit Trail';

require_once dirname(__DIR__) . '/includes/app_header.php';

$escape = static function ($value): string {
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
};

// Build pagination URLs while retaining active filters.
$paginationUrl = static function (int $targetPage) use (
    $filters,
    $perPage
): string {
    $query = array_filter(
        $filters,
        static fn ($value): bool => $value !== ''
    );

    $query['page'] = $targetPage;
    $query['per_page'] = $perPage;

    return base_url('/public/administration/audit_trail.php')
        . '?' . http_build_query($query);
};

?>

<div class="container-fluid py-4">

    <div class="d-flex flex-column flex-md-row
                justify-content-between align-items-md-center
                gap-3 mb-4">

        <div>
            <h1 class="h3 mb-1">Audit Trail</h1>

            <p class="text-muted mb-0">
                Review system activities, account actions,
                and security-related events.
            </p>
        </div>

        <a
            href="<?= $escape(base_url('/public/administration/index.php')) ?>"
            class="btn btn-outline-secondary"
        >
            <i class="fa-solid fa-arrow-left me-1"></i>
            Administration Dashboard
        </a>

    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">

        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">
                        Total Activities
                    </div>

                    <div class="h3 mb-0">
                        <?= number_format((int) $summary['total']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">
                        Successful Activities
                    </div>

                    <div class="h3 mb-0 text-success">
                        <?= number_format((int) $summary['successful']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">
                        Failed Activities
                    </div>

                    <div class="h3 mb-0 text-danger">
                        <?= number_format((int) $summary['failed']) ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">Filter Audit Records</h2>
        </div>

        <div class="card-body">

            <form
                method="get"
                action="<?= $escape(base_url('/public/administration/audit_trail.php')) ?>"
            >

                <div class="row g-3">

                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="date_from">
                            Date From
                        </label>

                        <input
                            type="date"
                            class="form-control"
                            id="date_from"
                            name="date_from"
                            value="<?= $escape($filters['date_from']) ?>"
                        >
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="date_to">
                            Date To
                        </label>

                        <input
                            type="date"
                            class="form-control"
                            id="date_to"
                            name="date_to"
                            value="<?= $escape($filters['date_to']) ?>"
                        >
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="actor">
                            Actor
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="actor"
                            name="actor"
                            placeholder="Name or user ID"
                            value="<?= $escape($filters['actor']) ?>"
                        >
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="role">
                            Role
                        </label>

                        <select
                            class="form-select"
                            id="role"
                            name="role"
                        >
                            <option value="">All Roles</option>

                            <?php foreach ($filterOptions['roles'] as $role): ?>
                                <option
                                    value="<?= $escape($role) ?>"
                                    <?= $filters['role'] === $role ? 'selected' : '' ?>
                                >
                                    <?= $escape($role) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="module">
                            Module
                        </label>

                        <select
                            class="form-select"
                            id="module"
                            name="module"
                        >
                            <option value="">All Modules</option>

                            <?php foreach ($filterOptions['modules'] as $module): ?>
                                <option
                                    value="<?= $escape($module) ?>"
                                    <?= $filters['module'] === $module ? 'selected' : '' ?>
                                >
                                    <?= $escape(ucwords(str_replace('_', ' ', $module))) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="action">
                            Action
                        </label>

                        <select
                            class="form-select"
                            id="action"
                            name="action"
                        >
                            <option value="">All Actions</option>

                            <?php foreach ($filterOptions['actions'] as $action): ?>
                                <option
                                    value="<?= $escape($action) ?>"
                                    <?= $filters['action'] === $action ? 'selected' : '' ?>
                                >
                                    <?= $escape(ucwords(str_replace('_', ' ', $action))) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="result">
                            Result
                        </label>

                        <select
                            class="form-select"
                            id="result"
                            name="result"
                        >
                            <option value="">All Results</option>
                            <option
                                value="success"
                                <?= $filters['result'] === 'success' ? 'selected' : '' ?>
                            >
                                Success
                            </option>
                            <option
                                value="failure"
                                <?= $filters['result'] === 'failure' ? 'selected' : '' ?>
                            >
                                Failure
                            </option>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="keyword">
                            Keyword Search
                        </label>

                        <input
                            type="search"
                            class="form-control"
                            id="keyword"
                            name="keyword"
                            placeholder="Description, IP, entity..."
                            value="<?= $escape($filters['keyword']) ?>"
                        >
                    </div>

                    <div class="col-12 d-flex flex-wrap gap-2">

                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-filter me-1"></i>
                            Apply Filters
                        </button>

                        <a
                            href="<?= $escape(base_url('/public/administration/audit_trail.php')) ?>"
                            class="btn btn-outline-secondary"
                        >
                            Clear Filters
                        </a>

                    </div>

                </div>

            </form>

        </div>

    </div>

    <!-- Audit records -->
    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3
                    d-flex flex-column flex-md-row
                    justify-content-between align-items-md-center gap-2">

            <div>
                <h2 class="h5 mb-1">Activity Records</h2>

                <p class="text-muted small mb-0">
                    <?= number_format($totalRecords) ?> matching record(s)
                </p>
            </div>

            <form method="get" class="d-flex align-items-center gap-2">

                <?php foreach ($filters as $key => $value): ?>
                    <?php if ($value !== ''): ?>
                        <input
                            type="hidden"
                            name="<?= $escape($key) ?>"
                            value="<?= $escape($value) ?>"
                        >
                    <?php endif; ?>
                <?php endforeach; ?>

                <label for="per_page" class="small text-muted">
                    Show
                </label>

                <select
                    name="per_page"
                    id="per_page"
                    class="form-select form-select-sm"
                    onchange="this.form.submit()"
                >
                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                        <option
                            value="<?= $size ?>"
                            <?= $perPage === $size ? 'selected' : '' ?>
                        >
                            <?= $size ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            </form>

        </div>

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Date / Time</th>
                            <th>Actor</th>
                            <th>Role</th>
                            <th>Action</th>
                            <th>Module</th>
                            <th>Result</th>
                            <th class="text-end">Details</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if ($logs === []): ?>

                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="fa-solid fa-clipboard-list
                                              fa-2x text-muted mb-2"></i>

                                    <p class="mb-0 text-muted">
                                        No audit records found.
                                    </p>
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($logs as $log): ?>

                                <tr>

                                    <td>
                                        #<?= (int) $log['id'] ?>
                                    </td>

                                    <td class="text-nowrap">
                                        <?= $escape($log['created_at']) ?>
                                    </td>

                                    <td>
                                        <div class="fw-semibold">
                                            <?= $escape($log['actor_name'] ?: 'Unknown User') ?>
                                        </div>

                                        <div class="small text-muted">
                                            User ID:
                                            <?= $escape($log['user_id'] ?? 'N/A') ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= $escape($log['actor_role'] ?: 'N/A') ?>
                                    </td>

                                    <td>
                                        <?= $escape(
                                            ucwords(
                                                str_replace('_', ' ', $log['action'])
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= $escape(
                                            ucwords(
                                                str_replace('_', ' ', $log['module'])
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?php if ($log['result'] === 'success'): ?>
                                            <span class="badge bg-success">
                                                Success
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">
                                                Failure
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-end">
                                        <a
                                            href="<?= $escape(
                                                base_url(
                                                    '/public/administration/audit_log_details.php?id='
                                                    . (int) $log['id']
                                                )
                                            ) ?>"
                                            class="btn btn-sm btn-outline-primary"
                                        >
                                            <i class="fa-solid fa-eye me-1"></i>
                                            View
                                        </a>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <!-- Pagination -->
        <?php if ($pagination['total_pages'] > 1): ?>

            <div class="card-footer bg-white">

                <div class="d-flex flex-column flex-md-row
                            justify-content-between align-items-center gap-3">

                    <div class="small text-muted">
                        Page <?= $pagination['page'] ?>
                        of <?= $pagination['total_pages'] ?>
                    </div>

                    <nav aria-label="Audit trail pagination">

                        <ul class="pagination pagination-sm mb-0">

                            <li class="page-item
                                <?= $pagination['page'] <= 1 ? 'disabled' : '' ?>">

                                <a
                                    class="page-link"
                                    href="<?= $escape($paginationUrl(
                                        max(1, $pagination['page'] - 1)
                                    )) ?>"
                                >
                                    Previous
                                </a>

                            </li>

                            <?php
                            $startPage = max(
                                1,
                                $pagination['page'] - 2
                            );

                            $endPage = min(
                                $pagination['total_pages'],
                                $pagination['page'] + 2
                            );
                            ?>

                            <?php for (
                                $number = $startPage;
                                $number <= $endPage;
                                $number++
                            ): ?>

                                <li class="page-item
                                    <?= $number === $pagination['page'] ? 'active' : '' ?>">

                                    <a
                                        class="page-link"
                                        href="<?= $escape($paginationUrl($number)) ?>"
                                    >
                                        <?= $number ?>
                                    </a>

                                </li>

                            <?php endfor; ?>

                            <li class="page-item
                                <?= $pagination['page'] >= $pagination['total_pages'] ? 'disabled' : '' ?>">

                                <a
                                    class="page-link"
                                    href="<?= $escape($paginationUrl(
                                        min(
                                            $pagination['total_pages'],
                                            $pagination['page'] + 1
                                        )
                                    )) ?>"
                                >
                                    Next
                                </a>

                            </li>

                        </ul>

                    </nav>

                </div>

            </div>

        <?php endif; ?>

    </div>

    <div class="alert alert-info mt-4 mb-0">
        <i class="fa-solid fa-shield-halved me-2"></i>
        Audit records are provided for monitoring and accountability.
        This page does not provide record editing or deletion.
    </div>

</div>

<?php
require_once dirname(__DIR__) . '/includes/app_footer.php';
?>