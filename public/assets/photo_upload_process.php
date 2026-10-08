<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetFileService;

header('Content-Type: application/json; charset=utf-8');

try {
    AuthMiddleware::handle();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);

        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.',
        ]);

        exit;
    }

    if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
        http_response_code(419);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired security token.',
        ]);

        exit;
    }

    $assetId = (int) ($_POST['asset_id'] ?? 0);

    if ($assetId <= 0) {
        throw new RuntimeException('Invalid asset.');
    }

    if (
        !isset($_FILES['photo'])
        || !is_array($_FILES['photo'])
    ) {
        throw new RuntimeException('No photo was uploaded.');
    }

    $caption = isset($_POST['caption'])
        ? (string) $_POST['caption']
        : null;

    $isPrimary =
        isset($_POST['is_primary'])
        && (string) $_POST['is_primary'] === '1';

    $service = new AssetFileService();

    $photoId = $service->uploadPhoto(
        $assetId,
        (int) Auth::id(),
        $_FILES['photo'],
        $caption,
        $isPrimary
    );

    echo json_encode([
        'success' => true,
        'message' => 'Photo uploaded successfully.',
        'photo_id' => $photoId,
    ]);
} catch (RuntimeException $e) {
http_response_code(422);

echo json_encode([
    'success' => false,
    'message' => $e->getMessage(),
]);

} catch (Throwable $e) {
error_log(
'Asset photo upload error: '
. $e->getMessage()
);

http_response_code(500);

echo json_encode([
    'success' => false,
    'message' => 'Unable to upload the photo at this time.',
]);

}
