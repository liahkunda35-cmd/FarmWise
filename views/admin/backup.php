<?php include __DIR__ . '/_nav.php'; ?>
<section class="panel">
    <header class="panel-tools">
        <div>
            <h3>Data backup</h3>
            <p class="muted">Create a local snapshot of the farm database. Store copies off-server for disaster recovery. Restore replaces current data after a safety backup is taken.</p>
        </div>
        <form method="post" action="<?= e(url('backup/run')) ?>">
            <?= Csrf::field() ?>
            <button class="btn btn-gold" type="submit">Create backup</button>
        </form>
    </header>
    <ul class="feed lg">
        <?php foreach ($files as $file): $base = basename($file); ?>
            <li>
                <div>
                    <strong><?= e($base) ?></strong>
                    <span><?= date('d M Y H:i', filemtime($file)) ?> Â· <?= round(filesize($file)/1024, 1) ?> KB</span>
                </div>
                <div class="tool-row">
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('backup/download?file='.rawurlencode($base))) ?>">Download</a>
                    <?php if (Auth::can('backups', 'delete')): ?>
                        <form method="post" action="<?= e(url('backup/restore')) ?>" data-restore-form>
                            <?= Csrf::field() ?>
                            <input type="hidden" name="file" value="<?= e($base) ?>">
                            <input name="confirm_restore" placeholder="Type RESTORE" required>
                            <button class="btn btn-ghost btn-sm" type="submit">Restore</button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
        <?php if (!$files): ?><li class="muted">No backups yet.</li><?php endif; ?>
    </ul>
</section>

