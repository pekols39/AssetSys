<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Models\Notification;
use App\Services\AuthorizationService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    AuthMiddleware::handle();

    $authorization = new AuthorizationService();
    $currentUser = $authorization->currentUser();

    if ($currentUser === null || Auth::id() === null) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Authentication is required.',
        ]);

        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');

        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.',
        ]);

        exit;
    }

    $csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;

    if (!is_string($csrfToken) || !Csrf::validate($csrfToken)) {
        http_response_code(419);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired CSRF token.',
        ]);

        exit;
    }

    $notificationId = filter_var(
        $_POST['notification_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($notificationId === false || $notificationId === null || $notificationId <= 0) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'A valid notification ID is required.',
        ]);

        exit;
    }

    $userId = (int) $currentUser['user_id'];
    $notificationModel = new Notification();

    $notificationModel->markAsRead(
        (int) $notificationId,
        $userId
    );

    echo json_encode([
        'success' => true,
        'message' => 'Notification read status updated.',
        'unread_count' => $notificationModel->countUnreadForUser($userId),
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    error_log(
        'Mark notification as read API error: ' . $e->getMessage()
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update notification status.',
    ]);
}
