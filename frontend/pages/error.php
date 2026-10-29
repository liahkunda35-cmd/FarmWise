<?php $errorCode = $errorCode ?? 500; $errorMessage = $errorMessage ?? 'Something went wrong.'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= brand_head((string) $errorCode) ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="public-body">
<main class="auth-wrap">
    <section class="auth-card">
        <a class="brand brand-error" href="<?= e(url()) ?>"><?= brand_logo() ?></a>
        <h1><?= e($errorCode) ?></h1>
        <p><?= e($errorMessage) ?></p>
        <a class="btn btn-gold" href="<?= e(url(fe_check() ? 'dashboard' : '')) ?>">Return</a>
    </section>
</main>
</body>
</html>
