<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= brand_head($title ?? 'Document') ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="print-body">
<div class="print-toolbar no-print">
    <button class="btn btn-gold" type="button" onclick="window.print()">Print</button>
    <a class="btn btn-ghost" href="<?= e(url(safe_return_path(input('return', 'dashboard')))) ?>">Back</a>
</div>
<main class="print-sheet">
    <?= $content ?? '' ?>
</main>
</body>
</html>
