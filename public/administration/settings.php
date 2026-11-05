<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Models\Setting;
use App\Middleware\AuthMiddleware;

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

AuthMiddleware::handle();

/*
|--------------------------------------------------------------------------
| Administrator authorization
|--------------------------------------------------------------------------
*/

$user = Auth::user();

if (
    !$user
    || ($user['role_name'] ?? '') !== 'Administrator'
) {
    http_response_code(403);
    exit('403 Forbidden: Administrator access required.');
}

/*
|--------------------------------------------------------------------------
| Load application settings
|--------------------------------------------------------------------------
*/

$settingModel = new Setting();
$settings = $settingModel->getAll();

$pageTitle = 'Administration Settings';

require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Administration Settings</h1>
            <p class="text-muted mb-0">
                Manage application-wide configuration.
            </p>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Settings updated successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"
                    aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger" role="alert">
            The settings could not be updated. Please review your input and try again.
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">Application Configuration</h2>
        </div>

        <div class="card-body">
            <form
                method="post"
                action="<?= htmlspecialchars(
                    base_url('/public/administration/update_process.php'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >
                <?= Csrf::field() ?>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Setting</th>
                                <th scope="col">Description</th>
                                <th scope="col">Value</th>
                                <th scope="col">Type</th>
                                <th scope="col">Editable</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($settings as $setting): ?>
                                <?php
                                $key = (string) $setting['setting_key'];
                                $type = strtolower(
                                    (string) $setting['setting_type']
                                );
                                $value = (string) ($setting['setting_value'] ?? '');
                                $editable = (int) $setting['is_editable'] === 1;

                                $label = ucwords(
                                    str_replace('_', ' ', $key)
                                );
                                ?>

                                <tr>
                                    <td>
                                        <strong>
                                            <?= htmlspecialchars(
                                                $label,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </strong>
                                        <div class="small text-muted">
                                            <code><?= htmlspecialchars(
                                                $key,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?></code>
                                        </div>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) ($setting['description'] ?? ''),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?php if ($editable && $type === 'boolean'): ?>
                                            <select
                                                class="form-select"
                                                name="settings[<?= htmlspecialchars(
                                                    $key,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>]"
                                            >
                                                <option value="1" <?= $value === '1' ? 'selected' : '' ?>>
                                                    Enabled
                                                </option>
                                                <option value="0" <?= $value === '0' ? 'selected' : '' ?>>
                                                    Disabled
                                                </option>
                                            </select>

                                        <?php elseif ($editable && $type === 'integer'): ?>
                                            <input
                                                type="number"
                                                step="1"
                                                class="form-control"
                                                name="settings[<?= htmlspecialchars(
                                                    $key,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>]"
                                                value="<?= htmlspecialchars(
                                                    $value,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                required
                                            >

                                        <?php elseif ($editable && $type === 'decimal'): ?>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                class="form-control"
                                                name="settings[<?= htmlspecialchars(
                                                    $key,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>]"
                                                value="<?= htmlspecialchars(
                                                    $value,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                required
                                            >

                                        <?php elseif ($editable && $key === 'default_depreciation_method'): ?>
                                            <select
                                                class="form-select"
                                                name="settings[<?= htmlspecialchars(
                                                    $key,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>]"
                                            >
                                                <option value="straight_line" <?= $value === 'straight_line' ? 'selected' : '' ?>>
                                                    Straight Line
                                                </option>
                                                <option value="declining_balance" <?= $value === 'declining_balance' ? 'selected' : '' ?>>
                                                    Declining Balance
                                                </option>
                                            </select>

                                        <?php elseif ($editable && $key === 'currency'): ?>
                                            <select
                                                class="form-select"
                                                name="settings[<?= htmlspecialchars(
                                                    $key,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>]"
                                            >
                                                <option value="PHP" <?= $value === 'PHP' ? 'selected' : '' ?>>
                                                    PHP - Philippine Peso
                                                </option>
                                                <option value="USD" <?= $value === 'USD' ? 'selected' : '' ?>>
                                                    USD - US Dollar
                                                </option>
                                            </select>

                                        <?php elseif ($editable && $key === 'date_format'): ?>
                                            <select
                                                class="form-select"
                                                name="settings[<?= htmlspecialchars(
                                                    $key,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>]"
                                            >
                                                <option value="Y-m-d" <?= $value === 'Y-m-d' ? 'selected' : '' ?>>
                                                    YYYY-MM-DD
                                                </option>
                                                <option value="d/m/Y" <?= $value === 'd/m/Y' ? 'selected' : '' ?>>
                                                    DD/MM/YYYY
                                                </option>
                                                <option value="m/d/Y" <?= $value === 'm/d/Y' ? 'selected' : '' ?>>
                                                    MM/DD/YYYY
                                                </option>
                                            </select>

                                        <?php elseif ($editable): ?>
                                            <input
                                                type="text"
                                                class="form-control"
                                                name="settings[<?= htmlspecialchars(
                                                    $key,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>]"
                                                value="<?= htmlspecialchars(
                                                    $value,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                required
                                            >

                                        <?php else: ?>
                                            <span>
                                                <?= htmlspecialchars(
                                                    $value,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span class="badge bg-secondary">
                                            <?= htmlspecialchars(
                                                $type,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php if ($editable): ?>
                                            <span class="text-success">Yes</span>
                                        <?php else: ?>
                                            <span class="text-muted">No</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-end mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>
                        Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>