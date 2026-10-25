<?php
$categories = [
    'all' => 'All types',
    'planting' => 'Planting',
    'fertilizer' => 'Fertilizer',
    'spraying' => 'Spraying',
    'irrigation' => 'Irrigation',
    'vaccination' => 'Vaccination',
    'disease' => 'Disease',
    'pest' => 'Pest',
    'stock' => 'Low stock',
    'chemical' => 'Expiring chemicals',
    'maintenance' => 'Maintenance',
    'harvest' => 'Harvest',
    'weather' => 'Weather',
    'market' => 'Market',
];
$activeCat = input('category') ?: 'all';
$grouped = [];
foreach ($rows as $row) {
    $cat = strtolower((string)($row['category'] ?? 'other'));
    $grouped[$cat][] = $row;
}
?>
<section class="panel">
    <header class="panel-tools">
        <h3>Notifications &amp; alerts</h3>
        <div class="tool-row">
            <form class="search-form" method="get">
                <label class="sr-only" for="alert-q">Search alerts</label>
                <input id="alert-q" name="q" value="<?= e(input('q')) ?>" placeholder="Search alerts">
                <select name="filter" aria-label="Read filter">
                    <option value="all" <?= ($filter ?? 'all')==='all'?'selected':'' ?>>All</option>
                    <option value="unread" <?= ($filter ?? '')==='unread'?'selected':'' ?>>Unread</option>
                    <option value="read" <?= ($filter ?? '')==='read'?'selected':'' ?>>Read</option>
                </select>
                <input type="hidden" name="category" value="<?= e($activeCat) ?>">
                <button class="btn btn-ghost btn-sm">Filter</button>
            </form>
            <form method="post" action="<?= e(url('notifications/read-all')) ?>">
                <?= Csrf::field() ?>
                <button class="btn btn-ghost btn-sm" type="submit">Mark all as read</button>
            </form>
        </div>
    </header>
    <div class="filter-chips" role="tablist" aria-label="Alert type">
        <?php foreach ($categories as $key => $label): ?>
            <a class="filter-chip<?= $activeCat === $key ? ' is-active' : '' ?>" href="?<?= e(http_build_query(array_filter(['filter' => $filter ?? 'all', 'q' => input('q'), 'category' => $key === 'all' ? null : $key]))) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <ul class="feed lg">
        <?php
        $shown = 0;
        foreach ($rows as $row):
            $cat = strtolower((string)($row['category'] ?? ''));
            if ($activeCat !== 'all' && $cat !== $activeCat && !str_contains($cat, $activeCat)) {
                continue;
            }
            $shown++;
        ?>
            <li class="<?= empty($row['is_read']) ? 'is-unread' : '' ?>">
                <div>
                    <strong><?= e($row['title']) ?></strong>
                    <span><?= e($row['message']) ?></span>
                    <small><?= e($row['category']) ?> · <?= dater($row['created_at'], 'd M Y H:i') ?> · <?= empty($row['is_read']) ? 'Unread' : 'Read' ?></small>
                </div>
                <div>
                    <?= status_badge($row['severity']) ?>
                    <?php if (!$row['is_read']): ?>
                        <form method="post" action="<?= e(url('notifications/read/'.$row['id'])) ?>">
                            <?= Csrf::field() ?>
                            <button class="btn btn-ghost btn-sm" type="submit">Mark read</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($row['link']): ?><a class="btn btn-ghost btn-sm" href="<?= e(url($row['link'])) ?>">Open</a><?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
        <?php if (!$shown): ?><li class="muted">No alerts match this filter.</li><?php endif; ?>
    </ul>
</section>
