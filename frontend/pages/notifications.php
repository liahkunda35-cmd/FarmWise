<?php
$categories = [
    'all' => 'All',
    'weather' => 'Weather',
    'calendar' => 'Calendar',
    'planting' => 'Planting',
    'harvest' => 'Harvest',
    'inventory' => 'Stock',
    'tasks' => 'Tasks',
];
$activeCat = input('category') ?: 'all';
$filter = $filter ?? 'all';
$count = is_array($rows ?? null) ? count($rows) : 0;
?>
<section class="panel list-panel-clean">
    <header class="list-hero">
        <div class="list-hero-copy">
            <h3>Alerts</h3>
            <p class="list-count"><?= (int) $count ?> alert<?= $count === 1 ? '' : 's' ?></p>
        </div>
        <div class="list-hero-actions">
            <form method="post" action="<?= e(url('notifications/read-all')) ?>">
                <?= fe_csrf_field() ?>
                <button class="btn btn-ghost btn-sm" type="submit">Mark all as read</button>
            </form>
        </div>
    </header>

    <div class="simple-tabs" role="tablist" aria-label="Alert filters">
        <a class="simple-tab<?= $filter === 'all' ? ' is-active' : '' ?>" href="?filter=all<?= $activeCat !== 'all' ? '&category=' . e($activeCat) : '' ?>">All</a>
        <a class="simple-tab<?= $filter === 'unread' ? ' is-active' : '' ?>" href="?filter=unread<?= $activeCat !== 'all' ? '&category=' . e($activeCat) : '' ?>">Unread</a>
        <a class="simple-tab<?= $filter === 'read' ? ' is-active' : '' ?>" href="?filter=read<?= $activeCat !== 'all' ? '&category=' . e($activeCat) : '' ?>">Read</a>
    </div>

    <div class="simple-tabs wrap" role="tablist" aria-label="Alert type">
        <?php foreach ($categories as $key => $label): ?>
            <a class="simple-tab<?= $activeCat === $key ? ' is-active' : '' ?>" href="?<?= e(http_build_query(array_filter(['filter' => $filter, 'category' => $key === 'all' ? null : $key]))) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <ul class="feed lg">
    <?php
    $shown = 0;
    foreach ($rows as $row):
        $cat = strtolower((string) ($row['category'] ?? ''));
        if ($activeCat !== 'all' && $cat !== $activeCat && !str_contains($cat, $activeCat)) {
            continue;
        }
        $shown++;
    ?>
        <li class="<?= empty($row['is_read']) ? 'is-unread' : '' ?>">
            <div>
                <strong><?= e($row['title']) ?></strong>
                <span><?= e($row['message']) ?></span>
                <small><?= e($row['category']) ?> · <?= dater($row['created_at'], 'd M Y H:i') ?></small>
            </div>
            <div class="feed-actions">
                <?= status_badge($row['severity']) ?>
                <?php if (!$row['is_read']): ?>
                    <form method="post" action="<?= e(url('notifications/read/'.$row['id'])) ?>">
                        <?= fe_csrf_field() ?>
                        <button class="btn btn-ghost btn-sm" type="submit">Mark read</button>
                    </form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('notifications/dismiss/'.$row['id'])) ?>">
                    <?= fe_csrf_field() ?>
                    <button class="btn btn-ghost btn-sm" type="submit">Dismiss</button>
                </form>
                <?php if ($row['link']): ?><a class="btn btn-ghost btn-sm" href="<?= e(url($row['link'])) ?>">Open</a><?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
    <?php if (!$shown): ?>
        <li class="muted empty-alerts">No alerts at the moment.</li>
    <?php endif; ?>
    </ul>
</section>
