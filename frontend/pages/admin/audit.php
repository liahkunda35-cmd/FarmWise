<?php include __DIR__ . '/_nav.php'; ?>
<section class="panel">
    <header class="panel-tools">
        <h3>Audit trail</h3>
    </header>
    <form class="search-form" method="get" action="<?= e(url('audit')) ?>">
        <input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Search details" aria-label="Search">
        <input name="user" value="<?= e(input('user')) ?>" placeholder="User" aria-label="User">
        <input name="module" value="<?= e(input('module')) ?>" placeholder="Module" aria-label="Module">
        <input name="action" value="<?= e(input('action')) ?>" placeholder="Action" aria-label="Action">
        <input type="date" name="date_from" value="<?= e(input('date_from')) ?>" aria-label="From">
        <input type="date" name="date_to" value="<?= e(input('date_to')) ?>" aria-label="To">
        <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
    </form>
    <?php
    $q = strtolower((string) input('q'));
    $userF = strtolower((string) input('user'));
    $modF = strtolower((string) input('module'));
    $actF = strtolower((string) input('action'));
    $from = input('date_from');
    $to = input('date_to');
    $filtered = [];
    foreach ($rows as $r) {
        if ($userF && !str_contains(strtolower((string)($r['user_name'] ?? '')), $userF)) continue;
        if ($modF && !str_contains(strtolower((string)($r['module'] ?? '')), $modF)) continue;
        if ($actF && !str_contains(strtolower((string)($r['action'] ?? '')), $actF)) continue;
        if ($q && !str_contains(strtolower((string)($r['details'] ?? '')), $q)) continue;
        $day = substr((string)($r['created_at'] ?? ''), 0, 10);
        if ($from && $day < $from) continue;
        if ($to && $day > $to) continue;
        $filtered[] = $r;
    }
    ?>
    <div class="table-wrap sticky-head">
        <table>
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($filtered as $r):
                $act = strtolower((string)($r['action'] ?? ''));
                $tone = in_array($act, ['delete', 'restore'], true) ? 'danger' : (in_array($act, ['create', 'login'], true) ? 'ok' : 'info');
            ?>
                <tr class="audit-row" data-expandable>
                    <td><?= dater($r['created_at'], 'd M Y H:i') ?></td>
                    <td><?= e($r['user_name'] ?: 'System') ?></td>
                    <td><span class="badge badge-<?= e($tone) ?>"><?= e($r['action']) ?></span></td>
                    <td><?= e($r['module']) ?></td>
                    <td><?= e($r['record_id']) ?></td>
                    <td>
                        <button type="button" class="linkish" data-toggle-details><?= e(mb_strimwidth((string)($r['details'] ?? ''), 0, 48, '…')) ?></button>
                        <div class="audit-details" hidden><pre><?= e($r['details']) ?></pre></div>
                    </td>
                    <td><?= e($r['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$filtered): ?>
                <tr><td colspan="7" class="muted">No audit records match these filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
document.querySelectorAll('[data-toggle-details]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    const box = btn.parentElement.querySelector('.audit-details');
    if (box) box.hidden = !box.hidden;
  });
});
</script>
