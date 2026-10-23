<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Models\Asset;
use App\Models\AssetQrCode;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

class AssetQrCodeService
{
    private AssetQrCode $qrCodeModel;

    private Asset $assetModel;

    private AuthorizationService $authorizationService;

    public function __construct()
    {
        $this->qrCodeModel = new AssetQrCode();
        $this->assetModel = new Asset();
        $this->authorizationService = new AuthorizationService();
    }

    /**
     * Create a permanent QR identity for an existing asset.
     *
     * Only the currently authenticated, authorized operational
     * account may create QR identities.
     *
     * An existing QR identity is returned rather than replaced.
     */
    public function createForAsset(int $assetId): array
    {
        if ($assetId <= 0) {
            throw new InvalidArgumentException(
                'A valid asset ID is required.'
            );
        }

        $userId = Auth::id();

        if ($userId === null || $userId <= 0) {
            throw new RuntimeException(
                'Authentication is required to create an asset QR code.'
            );
        }

        if (
            !$this->authorizationService
                ->canAccessAssetOperations()
        ) {
            throw new RuntimeException(
                'You are not authorized to create asset QR codes.'
            );
        }

        if (
            !$this->authorizationService
                ->canAccessAsset($assetId)
        ) {
            throw new RuntimeException(
                'The asset does not exist or you are not authorized to access it.'
            );
        }

        $asset = $this->assetModel->findById($assetId);

        if (!is_array($asset)) {
            throw new RuntimeException(
                'The requested asset could not be found.'
            );
        }

        $existing = $this->qrCodeModel->findByAssetId($assetId);

        if (is_array($existing)) {
            if ((int) ($existing['is_active'] ?? 0) !== 1) {
                throw new RuntimeException(
                    'This asset already has an inactive QR identity. '
                    . 'It must be reactivated through an authorized workflow.'
                );
            }

            return $existing;
        }

        /*
         * Retry only when an insert fails due to a uniqueness
         * constraint. The asset's existing QR is never overwritten.
         */
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $token = bin2hex(random_bytes(32));

            try {
                $this->qrCodeModel->create(
                    $assetId,
                    $token,
                    $userId
                );

                $created = $this->qrCodeModel
                    ->findByAssetId($assetId);

                if (!is_array($created)) {
                    throw new RuntimeException(
                        'The QR record was created but could not be retrieved.'
                    );
                }

                return $created;
            } catch (PDOException $e) {
                /*
                 * SQLSTATE 23000 covers integrity constraint
                 * violations. A competing request may have created
                 * the asset's QR record after our initial lookup.
                 */
                if ($e->getCode() === '23000') {
                    $existing = $this->qrCodeModel
                        ->findByAssetId($assetId);

                    if (is_array($existing)) {
                        return $existing;
                    }

                    if ($attempt < 2) {
                        continue;
                    }
                }

                throw $e;
            }
        }

        throw new RuntimeException(
            'Unable to create a unique QR identity. Please try again.'
        );
    }

    /**
     * Resolve an active QR token to its asset.
     *
     * This method does not grant permission to view or modify
     * the asset. The calling endpoint must enforce authorization.
     */
    public function findActiveByToken(string $token): array|false
    {
        if (
            strlen($token) !== 64
            || !ctype_xdigit($token)
        ) {
            return false;
        }

        return $this->qrCodeModel->findActiveByToken($token);
    }
}