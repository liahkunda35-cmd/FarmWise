<?php include __DIR__ . '/_nav.php'; ?>
<section class="panel">
    <header>
        <div>
            <h3>Outbound email & SMS</h3>
            <p class="muted">Every send attempt is stored with the provider response. Failed items stay failed until settings are fixed and a new alert is raised.</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('integrations')) ?>">Integrations</a>
    </header>
    <div class="table-wrap">
        <table>
            <thead><tr><th>When</th><th>Channel</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Error</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= dater($r['created_at'], 'd M Y H:i') ?></td>
                    <td><?= e($r['channel']) ?></td>
                    <td><?= e($r['recipient']) ?></td>
                    <td><?= e($r['subject']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= e($r['error']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="muted">No outbound messages yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

