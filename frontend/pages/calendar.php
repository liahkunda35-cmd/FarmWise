<?php
$cursor = $start;
$days = [];
while ($cursor <= $end) {
    $days[] = $cursor;
    $cursor = $cursor->modify('+1 day');
}
$editEvent = is_array($editEvent ?? null) ? $editEvent : null;
$categories = is_array($categories ?? null) ? $categories : [];
$farms = is_array($farms ?? null) ? $farms : [];
$showForm = !empty($showForm);
$formTitle = $editEvent ? 'Edit event' : 'Add event';
?>
<section class="panel calendar-page">
    <header class="list-hero">
        <div class="list-hero-copy">
            <h3>Calendar</h3>
            <p class="muted"><?= e($focus->format('F Y')) ?></p>
        </div>
        <div class="list-hero-actions">
            <?php if (fe_can('calendar', 'create')): ?>
                <a class="btn btn-gold" href="?view=month&date=<?= e($focus->format('Y-m-d')) ?>&new=1">+ Add Event</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($showForm && fe_can('calendar', $editEvent ? 'edit' : 'create')): ?>
        <section class="panel calendar-form-panel" id="event-form">
            <header><h3><?= e($formTitle) ?></h3></header>
            <form method="post" action="<?= e(url('calendar/save')) ?>" class="stack-form">
                <?= fe_csrf_field() ?>
                <?php if ($editEvent): ?><input type="hidden" name="id" value="<?= (int) $editEvent['id'] ?>"><?php endif; ?>
                <label>Event title
                    <input type="text" name="title" required maxlength="160" value="<?= e($editEvent['title'] ?? '') ?>">
                </label>
                <label>Description
                    <textarea name="description" rows="3"><?= e($editEvent['description'] ?? '') ?></textarea>
                </label>
                <div class="form-row-3">
                    <label>Date
                        <input type="date" name="event_date" required value="<?= e($editEvent['event_date'] ?? $focus->format('Y-m-d')) ?>">
                    </label>
                    <label>Start time
                        <input type="time" name="start_time" value="<?= e(substr((string) ($editEvent['start_time'] ?? ''), 0, 5)) ?>">
                    </label>
                    <label>End time
                        <input type="time" name="end_time" value="<?= e(substr((string) ($editEvent['end_time'] ?? ''), 0, 5)) ?>">
                    </label>
                </div>
                <div class="form-row-3">
                    <label>Category
                        <select name="category">
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= (($editEvent['category'] ?? 'general') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Related farm
                        <select name="farm_id">
                            <option value="">—</option>
                            <?php foreach ($farms as $farm): ?>
                                <option value="<?= (int) $farm['id'] ?>" <?= ((int) ($editEvent['farm_id'] ?? fe_farm_id()) === (int) $farm['id']) ? 'selected' : '' ?>><?= e($farm['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="check-inline">
                        <input type="checkbox" name="reminder" value="1" <?= ($editEvent ? !empty($editEvent['reminder']) : true) ? 'checked' : '' ?>>
                        Reminder
                    </label>
                </div>
                <div class="form-actions">
                    <button class="btn btn-gold" type="submit">Save Event</button>
                    <a class="btn btn-ghost" href="?view=<?= e($view) ?>&date=<?= e($focus->format('Y-m-d')) ?>">Cancel</a>
                    <?php if ($editEvent && fe_can('calendar', 'delete')): ?>
                        <button class="btn btn-ghost" formaction="<?= e(url('calendar/delete')) ?>" formmethod="post" data-confirm="Delete this event?">Delete</button>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <div class="cal-grid <?= $view === 'month' ? 'cal-month' : 'cal-list' ?>">
        <?php foreach ($days as $day):
            $key = $day->format('Y-m-d');
            $items = $byDate[$key] ?? [];
            ?>
            <article class="cal-day <?= $key === date('Y-m-d') ? 'is-today' : '' ?>">
                <h4><?= e($day->format('D d M')) ?></h4>
                <?php foreach ($items as $event): ?>
                    <a class="cal-event cal-<?= e($event['kind']) ?>" href="<?= e(url('calendar?event=' . (int) $event['id'] . '&view=' . $view . '&date=' . $key)) ?>">
                        <?= e($event['title']) ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!$items): ?><p class="muted cal-empty">No events</p><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
