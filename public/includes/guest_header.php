<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$pageTitle = $pageTitle ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>

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

    <?php if (!empty($pageStylesheet)): ?>

        <link rel="stylesheet" href="<?= e(asset($pageStylesheet)) ?>">

    <?php endif; ?>

    <!-- Favicon -->
    <link rel="icon" href="<?= e(asset('images/favicon.ico')) ?>">

</head>

<body>