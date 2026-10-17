<?php

declare(strict_types=1);

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'success' => false,
    'message' => 'Department transfer approvals and rejections are no longer supported. Transfers are completed immediately.',
]);

exit;