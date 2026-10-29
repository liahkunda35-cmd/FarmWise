<?php
$farm = $doc['farm'] ?? [];
$party = $doc['party'] ?? [];
?>
<article class="doc-sheet">
    <header class="doc-head">
        <?= brand_logo('brand-logo brand-logo-doc') ?>
        <div>
            <p class="eyebrow"><?= e(fe_public_config('name')) ?></p>
            <h1><?= e($doc['title']) ?> <?= e($doc['number']) ?></h1>
            <p><?= e($farm['name'] ?? '') ?></p>
            <p class="muted"><?= e($farm['location'] ?? '') ?><?= !empty($farm['phone']) ? ' · ' . e($farm['phone']) : '' ?><?= !empty($farm['email']) ? ' · ' . e($farm['email']) : '' ?></p>
        </div>
    </header>
    <div class="doc-meta">
        <div>
            <h3><?= e($doc['party_label']) ?></h3>
            <p><strong><?= e($party['name'] ?? '—') ?></strong></p>
            <p><?= e($party['contact_person'] ?? '') ?></p>
            <p><?= e($party['phone'] ?? '') ?> <?= e($party['email'] ?? '') ?></p>
            <p><?= e($party['address'] ?? $party['destination'] ?? '') ?></p>
        </div>
        <div>
            <p>Issue date: <strong><?= dater($doc['issue_date']) ?></strong></p>
            <p>Due / valid: <strong><?= dater($doc['due_date']) ?></strong></p>
            <p>Status: <?= status_badge($doc['status']) ?></p>
            <?php if ($doc['payment_status']): ?><p>Payment: <?= status_badge($doc['payment_status']) ?></p><?php endif; ?>
            <p>Currency: <?= e(app_currency()) ?></p>
        </div>
    </div>
    <table class="doc-table">
        <thead>
            <tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($doc['items'] as $item): ?>
            <tr>
                <td><?= e($item['description']) ?></td>
                <td><?= qty($item['quantity']) ?></td>
                <td><?= money($item['unit_price']) ?></td>
                <td><?= money($item['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><th colspan="3">Total</th><th><?= money($doc['total']) ?></th></tr>
            <?php if ($doc['module'] === 'invoices'): ?>
                <tr><th colspan="3">Paid</th><th><?= money($doc['paid']) ?></th></tr>
                <tr><th colspan="3">Balance</th><th><?= money($doc['balance']) ?></th></tr>
            <?php endif; ?>
        </tfoot>
    </table>
    <?php if (!empty($doc['payments'])): ?>
        <h3>Payments</h3>
        <ul>
            <?php foreach ($doc['payments'] as $p): ?>
                <li><?= dater($p['payment_date']) ?> · <?= money($p['amount']) ?> · <?= e($p['method'] ?? '') ?> · <?= e($p['reference'] ?? '') ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($doc['notes']): ?><p class="doc-notes"><?= e($doc['notes']) ?></p><?php endif; ?>
    <p class="doc-sign muted">Authorised signature: ______________________</p>
</article>
