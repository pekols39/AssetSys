<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AdministrationUserService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    Auth::logout();

    header(
        'Location: '
        . base_url('/public/login.php')
    );

    exit;
}

if (!$authorization->isAdministrator()) {
    http_response_code(403);
    exit('Access denied. Administrator access required.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;

if (!Csrf::validate($csrfToken)) {
    http_response_code(403);
    exit('Invalid security token.');
}

$userId = filter_var(
    $_POST['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (
    $userId === false
    || $userId === null
    || $userId <= 0
) {
    http_response_code(400);
    exit('Invalid user account.');
}

$roleId = filter_var(
    $_POST['role_id'] ?? null,
    FILTER_VALIDATE_INT
);

$departmentId = filter_var(
    $_POST['department_id'] ?? null,
    FILTER_VALIDATE_INT
);

$position = trim(
    (string) ($_POST['position'] ?? '')
);

if ($position === '') {
    http_response_code(422);
    exit('Position is required.');
}

if (mb_strlen($position) > 150) {
    http_response_code(422);
    exit('Position must not exceed 150 characters.');
}

if (
    $roleId === false
    || $roleId === null
    || $roleId <= 0
    || $departmentId === false
    || $departmentId === null
    || $departmentId <= 0
) {
    http_response_code(422);
    exit(
        'A valid role, department, and position are required.'
    );
}

$employeeNumber = trim(
    (string) (
        $_POST['employee_number'] ?? ''
    )
);

$firstName = trim(
    (string) (
        $_POST['first_name'] ?? ''
    )
);

$middleName = trim(
    (string) (
        $_POST['middle_name'] ?? ''
    )
);

$lastName = trim(
    (string) (
        $_POST['last_name'] ?? ''
    )
);

$username = trim(
    (string) (
        $_POST['username'] ?? ''
    )
);

$email = trim(
    (string) (
        $_POST['email'] ?? ''
    )
);

$accountStatus = strtolower(
    trim(
        (string) (
            $_POST['account_status'] ?? ''
        )
    )
);

$isActive = isset(
    $_POST['is_active']
) ? 1 : 0;

$mustChangePassword = isset(
    $_POST['must_change_password']
) ? 1 : 0;

if (
    $employeeNumber === ''
    || $firstName === ''
    || $lastName === ''
    || $username === ''
    || $email === ''
) {
    http_response_code(422);
    exit(
        'Employee number, first name, last name, username, '
        . 'and email are required.'
    );
}

if (
    strlen($employeeNumber) > 100
    || strlen($firstName) > 100
    || strlen($middleName) > 100
    || strlen($lastName) > 100
    || strlen($username) > 100
    || strlen($email) > 255
) {
    http_response_code(422);
    exit('One or more fields exceed the allowed length.');
}

if (
    !preg_match(
        '/^[A-Za-z0-9._-]+$/',
        $username
    )
) {
    http_response_code(422);
    exit(
        'Username may only contain letters, numbers, dots, '
        . 'underscores, and hyphens.'
    );
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    exit('Please provide a valid email address.');
}

if (
    !in_array(
        $accountStatus,
        ['active', 'inactive'],
        true
    )
) {
    http_response_code(422);
    exit('Invalid account status.');
}

if (
    $accountStatus === 'inactive'
) {
    $isActive = 0;
}

if (
    $accountStatus === 'active'
    && $isActive !== 1
) {
    $isActive = 1;
}

$userService = new AdministrationUserService();

$account = $userService->findById($userId);

if ($account === null) {
    http_response_code(404);
    exit('User account not found.');
}

$currentUserId = Auth::id();

if ($currentUserId === null) {
    Auth::logout();

    header(
        'Location: '
        . base_url('/public/login.php')
    );

    exit;
}

if ((int) $currentUserId === (int) $userId) {
    if (
        (int) $roleId
        !== (int) ($account['role_id'] ?? 0)
    ) {
        http_response_code(422);
        exit(
            'You cannot change your own administrator role.'
        );
    }

    if (
        $isActive === 0
        || $accountStatus !== 'active'
    ) {
        http_response_code(422);
        exit(
            'You cannot deactivate your own administrator account.'
        );
    }
}

$db = \App\Core\Database::connection();

$stmt = $db->prepare(
    'SELECT id
     FROM roles
     WHERE id = :role_id
       AND name IN (
            \'Administrator\',
            \'Asset Manager\'
       )
     LIMIT 1'
);

$stmt->execute([
    'role_id' => $roleId,
]);

if ($stmt->fetchColumn() === false) {
    http_response_code(422);
    exit('Invalid role selected.');
}

$stmt = $db->prepare(
    'SELECT id
     FROM departments
     WHERE id = :department_id
       AND is_active = 1
     LIMIT 1'
);

$stmt->execute([
    'department_id' => $departmentId,
]);

if ($stmt->fetchColumn() === false) {
    http_response_code(422);
    exit('Invalid department selected.');
}

/*
 * Prevent duplicate username, employee number,
 * and email values belonging to another account.
 */
$stmt = $db->prepare(
    'SELECT id
     FROM users
     WHERE (
            username = :username
            OR employee_number = :employee_number
            OR email = :email
     )
       AND id <> :user_id
     LIMIT 1'
);

$stmt->execute([
    'username' => $username,
    'employee_number' => $employeeNumber,
    'email' => $email,
    'user_id' => $userId,
]);

$duplicate = $stmt->fetch(
    \PDO::FETCH_ASSOC
);

if (is_array($duplicate)) {
    http_response_code(409);
    exit(
        'Another user account already uses the supplied '
        . 'username, employee number, or email address.'
    );
}

try {

    $updated = $userService->update(
        $userId,
        [
            'employee_number' => $employeeNumber,
            'first_name' => $firstName,
            'middle_name' => $middleName !== ''
                ? $middleName
                : null,
            'last_name' => $lastName,
            'username' => $username,
            'email' => $email,
            'role_id' => $roleId,
            'department_id' => $departmentId,
            'position' => $position,
            'account_status' => $accountStatus,
            'is_active' => $isActive,
            'must_change_password' => $mustChangePassword,
        ]
    );

    if (!$updated) {
        throw new RuntimeException(
            'No account changes were saved.'
        );
    }

} catch (\Throwable $e) {

    http_response_code(500);

    exit(
        'Unable to update the user account.'
    );
}

header(
    'Location: '
    . base_url(
        '/public/administration/users/view.php?id='
        . $userId
        . '&success=1'
    )
);

exit;