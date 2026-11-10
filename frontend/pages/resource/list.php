<?php fe_component('partials/frontend'); ?>
<?php
$recordLabel = $total === 1 ? ($def['singular'] ?? 'record') : ($def['title'] ?? 'records');
$description = trim((string) ($def['description'] ?? ''));
if ($description === '') {
    $description = 'Manage your ' . strtolower((string) ($def['title'] ?? 'records')) . '.';
}
$showExport = fe_can($module, 'view') && $total > 0;
?>
<section class="panel list-panel list-panel-clean" data-list-panel>
    <header class="list-hero">
        <div class="list-hero-copy">
            <h3><?= e($def['title']) ?></h3>
            <p class="muted"><?= e($description) ?></p>
            <p class="list-count"><?= (int) $total ?> <?= e($recordLabel) ?></p>
        </div>
        <div class="list-hero-actions<?= $module === 'employees' ? ' employees-hero-actions' : '' ?>">
            <?php if (fe_can($module, 'create')): ?>
                <a class="btn btn-gold btn-sm" href="<?= e(url('module/'.$module.'/create')) ?>"><?= $module === 'employees' ? 'Add employee' : ('+ Add ' . ($def['singular'] ?? 'record')) ?></a>
            <?php endif; ?>
            <?php if ($module === 'fields' && fe_can('fields', 'view')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('fields-planner')) ?>">Divide on map</a>
            <?php endif; ?>
            <?php if ($module === 'employees' && fe_can('reports', 'view')): ?>
                <?php if ($rows): ?>
                    <div class="employees-report-pick">
                        <label class="sr-only" for="employee-report-pick">Employee for individual report</label>
                        <select id="employee-report-pick" data-employee-report-select>
                            <?php foreach ($rows as $emp): ?>
                                <option value="<?= e(url('module/employees/report/' . (int) $emp['id'])) ?>">
                                    <?= e((string) ($emp['name'] ?? 'Employee')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-ghost btn-sm" data-employee-report-go>Individual report</button>
                    </div>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('reports?type=labour')) ?>">Full employees report</a>
            <?php endif; ?>
            <?php if ($showExport && $module !== 'employees'): ?>
                <a class="btn btn-ghost btn-sm" href="?<?= e(request_query(['export' => 'csv', 'page' => null])) ?>">Download report</a>
            <?php endif; ?>
        </div>
        <?php if ($module === 'employees' && fe_can('reports', 'view') && $rows): ?>
        <script>
        (function () {
            var sel = document.querySelector('[data-employee-report-select]');
            var go = document.querySelector('[data-employee-report-go]');
            if (!sel || !go) return;
            go.addEventListener('click', function () {
                if (sel.value) window.location.href = sel.value;
            });
        })();
        </script>
        <?php endif; ?>
    </header>

    <?php if ($module === 'attendance'):
        $perf = $attendancePerf ?? ['total' => 0, 'present' => 0, 'absent' => 0, 'leave' => 0, 'half_day' => 0, 'rate' => 0];
        $rate = (float) ($perf['rate'] ?? 0);
        $deg = max(0, min(100, $rate)) * 3.6;
    ?>
    <section class="attendance-perf" aria-label="Attendance performance">
        <div class="attendance-ring" style="--att-deg: <?= e((string) $deg) ?>deg" role="img" aria-label="Attendance rate <?= e((string) $rate) ?> percent">
            <div class="attendance-ring-inner">
                <strong><?= e(rtrim(rtrim(number_format($rate, 1), '0'), '.')) ?>%</strong>
                <span>Attendance</span>
            </div>
        </div>
        <div class="attendance-perf-stats">
            <article><b><?= (int) ($perf['present'] ?? 0) ?></b><span>Present</span></article>
            <article><b><?= (int) ($perf['half_day'] ?? 0) ?></b><span>Half day</span></article>
            <article><b><?= (int) ($perf['leave'] ?? 0) ?></b><span>Leave</span></article>
            <article><b><?= (int) ($perf['absent'] ?? 0) ?></b><span>Absent</span></article>
            <article><b><?= (int) ($perf['total'] ?? 0) ?></b><span>Records</span></article>
        </div>
    </section>
    <?php endif; ?>

    <div class="table-wrap sticky-head">
        <table class="data-table" data-module="<?= e($module) ?>">
            <thead>
            <tr>
                <?php foreach ($columns as $name => $field):
                    $nextDir = (($sort ?? '') === $name && ($dir ?? '') === 'ASC') ? 'desc' : 'asc';
                    ?>
                    <th data-col="<?= e($name) ?>">
                        <a href="?<?= e(request_query(['sort' => $name, 'dir' => $nextDir, 'page' => 1])) ?>">
                            <?= e($field['label']) ?><?= ($sort ?? '') === $name ? (($dir ?? '') === 'ASC' ? ' ↑' : ' ↓') : '' ?>
                        </a>
                    </th>
                <?php endforeach; ?>
                <th data-col="_actions">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr class="data-row">
                    <?php foreach ($columns as $name => $field): ?>
                        <td data-col="<?= e($name) ?>" data-label="<?= e($field['label']) ?>"><?php
                            if ($name === 'status') {
                                echo status_badge($row[$name] ?? '');
                            } elseif ($module === 'transactions' && in_array($name, ['description', 'category'], true) && !empty($row['related_module']) && !empty($row['related_id'])) {
                                $srcMod = (string) $row['related_module'];
                                $srcId = (int) $row['related_id'];
                                $href = fe_rel_href($srcMod, $srcId);
                                echo e(ResourceController::displayValue($field, $row[$name] ?? null));
                                if ($href) {
                                    echo ' <a class="from-badge" href="' . e($href) . '">From: ' . e(ucwords(str_replace('_', ' ', $srcMod))) . ' #' . $srcId . '</a>';
                                } else {
                                    echo ' <span class="from-badge from-manual">From: ' . e($srcMod) . ' #' . $srcId . '</span>';
                                }
                            } elseif ($module === 'transactions' && empty($row['related_module']) && $name === 'description') {
                                echo e(ResourceController::displayValue($field, $row[$name] ?? null));
                                echo ' <span class="from-badge from-manual">Manual</span>';
                            } else {
                                echo fe_display($field, $row[$name] ?? null, true);
                            }
                        ?></td>
                    <?php endforeach; ?>
                    <td class="row-actions" data-col="_actions" data-label="Actions">
                        <a href="<?= e(url('module/'.$module.'/view/'.$row['id'])) ?>">View</a>
                        <?php if (fe_can($module, 'edit')): ?>
                            <a href="<?= e(url('module/'.$module.'/edit/'.$row['id'])) ?>">Edit</a>
                        <?php endif; ?>
                        <?php if ($module === 'employees' && fe_can('reports', 'view')): ?>
                            <a href="<?= e(url('module/employees/report/'.$row['id'])) ?>">Report</a>
                        <?php endif; ?>
                        <?php if (fe_can($module, 'delete')): ?>
                            <form method="post" action="<?= e(url('module/'.$module.'/delete/'.$row['id'])) ?>" data-confirm="Delete this record?">
                                <?= fe_csrf_field() ?>
                                <button type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr class="empty-row">
                    <td colspan="<?= count($columns) + 1 ?>">
                        <div class="empty-state">
                            <?php if ($module === 'farms'): ?>
                                <h4>No farm registered yet.</h4>
                            <?php else: ?>
                                <h4>No <?= e(strtolower($def['title'])) ?> yet</h4>
                            <?php endif; ?>
                            <?php if (fe_can($module, 'create')): ?>
                                <a class="btn btn-gold btn-sm" href="<?= e(url('module/'.$module.'/create')) ?>">+ Add <?= e($def['singular']) ?></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (($pages ?? 1) > 1): ?>
        <div class="pager" role="navigation" aria-label="Pagination">
            <?php foreach (fe_pager_pages((int)$page, (int)$pages) as $i): ?>
                <?php if ($i === '…'): ?>
                    <span class="pager-ellipsis">…</span>
                <?php else: ?>
                    <a class="<?= $i===$page?'active':'' ?>" href="?<?= e(request_query(['page' => $i])) ?>"><?= (int)$i ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
