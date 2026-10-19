<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Models\Asset;
use App\Models\AssetQrCode;
use App\Services\AuthorizationService;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

if (!$authorization->canAccessAssetMaterialAreas()) {
    http_response_code(403);
    exit('You do not have permission to print asset QR labels.');
}

if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
    http_response_code(419);
    exit('Your security token is invalid or expired. Refresh the page and try again.');
}

$assetId = filter_var($_POST['asset_id'] ?? null, FILTER_VALIDATE_INT);

if ($assetId === false || $assetId === null || $assetId <= 0) {
    http_response_code(400);
    exit('A valid asset ID is required.');
}

if (!$authorization->canAccessAsset((int) $assetId)) {
    http_response_code(403);
    exit('You do not have permission to access this asset.');
}

$tempImage = null;

try {
    $assetModel = new Asset();
    $asset = $assetModel->findById((int) $assetId);

    if (!is_array($asset)) {
        http_response_code(404);
        exit('Asset not found.');
    }

    $qrModel = new AssetQrCode();
    $qrRecord = $qrModel->findByAssetId((int) $assetId);

    if (
        !is_array($qrRecord)
        || (int) ($qrRecord['is_active'] ?? 0) !== 1
        || !preg_match('/\A[a-f0-9]{64}\z/i', (string) ($qrRecord['qr_token'] ?? ''))
    ) {
        http_response_code(409);
        exit('This asset does not have an active QR identity.');
    }

    $scanUrl = base_url(
        '/public/assets/scan.php?token=' . rawurlencode((string) $qrRecord['qr_token'])
    );

    $qrPng = (new Builder(
        writer: new PngWriter(),
        data: $scanUrl,
        size: 200,
        margin: 2
    ))->build()->getString();

    $qrImage = imagecreatefromstring($qrPng);

    if ($qrImage === false) {
        throw new RuntimeException('Unable to render the QR image.');
    }

    // B1 label: 50 mm x 30 mm at 384 x 240 pixels.
    $label = imagecreatetruecolor(384, 240);

    if ($label === false) {
        imagedestroy($qrImage);
        throw new RuntimeException('Unable to create the label image.');
    }

    $white = imagecolorallocate($label, 255, 255, 255);
    $black = imagecolorallocate($label, 0, 0, 0);

    imagefill($label, 0, 0, $white);
    imagecopyresampled($label, $qrImage, 8, 20, 0, 0, 192, 192, imagesx($qrImage), imagesy($qrImage));
    imagedestroy($qrImage);

    $font = 'C:\\Windows\\Fonts\\arial.ttf';
    $boldFont = 'C:\\Windows\\Fonts\\arialbd.ttf';

    if (!is_file($font) || !is_file($boldFont) || !function_exists('imagettftext')) {
        imagedestroy($label);
        throw new RuntimeException('The Windows Arial fonts or FreeType support are unavailable.');
    }

    $assetName = trim((string) ($asset['asset_name'] ?? 'Unnamed Asset'));
    $assetCode = trim((string) ($asset['asset_code'] ?? '—'));
    $assetTag = trim((string) ($asset['asset_tag'] ?? ''));

    // Keep each line short enough for the right-hand label area.
    $fitText = static function (string $text, int $maxChars): string {
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $maxChars
                ? mb_substr($text, 0, max(1, $maxChars - 1), 'UTF-8') . '…'
                : $text;
        }

        return strlen($text) > $maxChars
            ? substr($text, 0, max(1, $maxChars - 3)) . '...'
            : $text;
    };

    imagettftext($label, 12, 0, 208, 65, $black, $boldFont, $fitText($assetName, 22));
    imagettftext($label, 10, 0, 208, 105, $black, $font, 'Code: ' . $fitText($assetCode, 18));

    if ($assetTag !== '') {
        imagettftext($label, 9, 0, 208, 140, $black, $font, 'Tag: ' . $fitText($assetTag, 17));
    }

    $tempImage = tempnam(sys_get_temp_dir(), 'assetsys-qr-');

    if ($tempImage === false) {
        imagedestroy($label);
        throw new RuntimeException('Unable to allocate a temporary label file.');
    }

    // GD identifies the image format from the encoder, not the filename extension.
    if (!imagepng($label, $tempImage)) {
        imagedestroy($label);
        throw new RuntimeException('Unable to save the temporary label image.');
    }

    imagedestroy($label);

    $python = dirname(__DIR__, 2) . '/tools/niimbot/.venv311/Scripts/python.exe';
    $port = 'COM3';

    if (!is_file($python)) {
        throw new RuntimeException('The configured NIIMBOT Python environment was not found.');
    }

    $command = [
        $python,
        '-m',
        'niimprint',
        '--model',
        'b1',
        '--conn',
        'usb',
        '--addr',
        $port,
        '--density',
        '4',
        '--image',
        $tempImage,
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];


    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        dirname(__DIR__, 2),
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the NIIMBOT print process.');
    }

    fclose($pipes[0]);

    // Avoid blocking indefinitely if the printer or driver hangs.
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + 30.0;
    $timedOut = false;
    $childExitCode = null;

    while (true) {
        $status = proc_get_status($process);

        foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $streamName) {
            $chunk = stream_get_contents($pipes[$index]);

            if ($chunk !== false && $chunk !== '') {
                if ($streamName === 'stdout') {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        if (!$status['running']) {
            $childExitCode = (int) $status['exitcode'];
            break;
        }

        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);

            // Give the process a short opportunity to stop.
            usleep(250000);

            $status = proc_get_status($process);

            if ($status['running']) {
                proc_terminate($process, 9);
            }

            break;
        }

        usleep(50000);
    }

    // Drain any remaining output before closing the pipes.
    foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $streamName) {
        $chunk = stream_get_contents($pipes[$index]);

        if ($chunk !== false && $chunk !== '') {
            if ($streamName === 'stdout') {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
        }

        fclose($pipes[$index]);
    }

    $closeExitCode = proc_close($process);

    $exitCode = $childExitCode !== null
        ? $childExitCode
        : $closeExitCode;

    if ($timedOut) {
        error_log(
            'NIIMBOT label print timed out for asset ID ' . (int) $assetId
        );

        http_response_code(504);
        exit('The NIIMBOT print process timed out. Check the printer connection before trying again.');
    }

    if ($exitCode !== 0) {
        error_log(
            'NIIMBOT label print failed for asset ID '
            . (int) $assetId
            . '; exit code '
            . $exitCode
            . '; output: '
            . substr(trim($stderr . ' ' . $stdout), 0, 1000)
        );

        http_response_code(502);
        exit('The NIIMBOT printer did not report a successful print. Check the printer connection and application logs.');
    }


    header('Location: ' . base_url('/public/assets/qr.php?asset_id=' . (int) $assetId . '&printed=1'), true, 303);
    exit;

} catch (Throwable $e) {
    error_log(
        'Asset QR label print error for asset ID '
        . (int) $assetId
        . ': '
        . get_class($e)
        . ' — '
        . $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to print the asset QR label. Check the PHP/Apache error log for details.');
} finally {
    if (is_string($tempImage) && is_file($tempImage)) {
        @unlink($tempImage);
    }
}
