<?php
$cursor = $start;
$days = [];
while ($cursor <= $end) {
    $days[] = $cursor;
    $cursor = $cursor->modify('+1 day');
}
$prev = $focus->modify($view === 'day' ? '-1 day' : ($view === 'week' ? '-7 days' : '-1 month'))->format('Y-m-d');
$next = $focus->modify($view === 'day' ? '+1 day' : ($view === 'week' ? '+7 days' : '+1 month'))->format('Y-m-d');
?>
<section class="panel">
    <header class="panel-tools">
        <div>
            <h3>Operations calendar</h3>
            <p class="muted">Events come from plantings, harvests, tasks, irrigation, spraying and livestock records.</p>
        </div>
        <div class="tool-row">
            <a class="btn btn-ghost btn-sm" href="?view=<?= e($view) ?>&date=<?= e($prev) ?>">Previous</a>
            <a class="btn btn-ghost btn-sm" href="?view=<?= e($view) ?>&date=<?= e(date('Y-m-d')) ?>">Today</a>
            <a class="btn btn-ghost btn-sm" href="?view=<?= e($view) ?>&date=<?= e($next) ?>">Next</a>
            <a class="btn btn-sm <?= $view==='month'?'btn-gold':'btn-ghost' ?>" href="?view=month&date=<?= e($focus->format('Y-m-d')) ?>">Month</a>
            <a class="btn btn-sm <?= $view==='week'?'btn-gold':'btn-ghost' ?>" href="?view=week&date=<?= e($focus->format('Y-m-d')) ?>">Week</a>
            <a class="btn btn-sm <?= $view==='day'?'btn-gold':'btn-ghost' ?>" href="?view=day&date=<?= e($focus->format('Y-m-d')) ?>">Day</a>
        </div>
    </header>
    <div class="cal-grid <?= $view === 'month' ? 'cal-month' : 'cal-list' ?>">
        <?php foreach ($days as $day):
            $key = $day->format('Y-m-d');
            $items = $byDate[$key] ?? [];
            ?>
            <article class="cal-day <?= $key === date('Y-m-d') ? 'is-today' : '' ?>">
                <h4><?= e($day->format('D d M')) ?></h4>
                <?php foreach ($items as $event): ?>
                    <a class="cal-event cal-<?= e($event['kind']) ?>" href="<?= e($event['url']) ?>">
                        <?= e($event['title']) ?>
                        <?php if ($event['status']): ?><?= status_badge($event['status']) ?><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!$items): ?><p class="muted">No events</p><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
