<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
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

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');

        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.',
        ]);

        exit;
    }

    $userId = (int) $currentUser['user_id'];
    $notificationModel = new Notification();

    echo json_encode([
        'success' => true,
        'data' => [
            'notifications' => $notificationModel->getForUser($userId),
            'unread_count' => $notificationModel->countUnreadForUser($userId),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    error_log(
        'Notifications API error: ' . $e->getMessage()
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load notifications.',
    ]);
}
