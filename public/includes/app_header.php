<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\AuthorizationService;

$authorization = new AuthorizationService();

Auth::user();

$pageTitle = $pageTitle ?? APP_NAME;
$pageStylesheet = $pageStylesheet ?? null;

$currentUser = Auth::user();

$firstName = $currentUser['first_name']
    ?? $currentUser['username']
    ?? 'User';

$roleName = $currentUser['role_name']
    ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= e($pageTitle) ?> | <?= e(APP_NAME) ?>
    </title>

    <meta name="description" content="<?= e(APP_NAME) ?>">

    <meta name="author" content="IT Department">

    <!-- Company Font -->
    <link rel="stylesheet" href="<?= e(asset('css/font.css')) ?>">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <!-- Global CSS -->
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">

    <!-- Responsive CSS -->
    <link rel="stylesheet" href="<?= e(asset('css/responsive.css')) ?>">

    <!-- Brand Override -->
    <link rel="stylesheet" href="<?= e(asset('css/brand-override.css')) ?>">

    <!-- Application CSS -->
    <link rel="stylesheet" href="<?= e(asset('css/app-layout.css')) ?>">

    <?php if (!empty($pageStylesheet)): ?>

        <link rel="stylesheet" href="<?= e(asset($pageStylesheet)) ?>">

    <?php endif; ?>

    <link rel="icon" href="<?= e(asset('images/favicon.ico')) ?>">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

</head>

<body>

<?php require __DIR__ . '/app_sidebar.php'; ?>

<div class="app-mobile-header">

    <button
        type="button"
        class="app-mobile-menu-button"
        id="sidebarToggle"
        aria-label="Open navigation menu"
        aria-controls="appSidebar"
        aria-expanded="false"
    >
        <i class="fa-solid fa-bars"></i>
    </button>

    <a
        href="<?= e(base_url('/public/dashboard/')) ?>"
        class="app-mobile-brand"
    >
        <span class="app-mobile-brand-icon">
            <i class="fa-solid fa-boxes-stacked"></i>
        </span>

        <span class="app-mobile-brand-text">
            Asset System
        </span>
    </a>

</div>

<div
    class="app-sidebar-overlay"
    id="sidebarOverlay"
    aria-hidden="true"
></div>

<main class="app-main">