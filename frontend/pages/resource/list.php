<?php fe_component('partials/frontend'); ?>
<section class="panel list-panel" data-list-panel>
    <header class="panel-tools">
        <div>
            <h3><?= e($def['title']) ?></h3>
            <p class="muted"><?= e($def['description'] ?? ($total . ' records')) ?></p>
        </div>
        <div class="tool-row">
            <form class="search-form" method="get">
                <label class="sr-only" for="list-q">Search</label>
                <input id="list-q" type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Search…">
                <?php if (isset($def['fields']['status'])): ?>
                    <label class="sr-only" for="list-status">Status</label>
                    <select id="list-status" name="status">
                        <option value="">All statuses</option>
                        <?php foreach ($def['fields']['status']['options'] as $k => $l): ?>
                            <option value="<?= e($k) ?>" <?= input('status')===$k?'selected':'' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if (!empty($dateCol)): ?>
                    <input type="date" name="date_from" value="<?= e(input('date_from')) ?>" aria-label="Start date">
                    <input type="date" name="date_to" value="<?= e(input('date_to')) ?>" aria-label="End date">
                <?php endif; ?>
                <input type="hidden" name="sort" value="<?= e($sort ?? 'id') ?>">
                <input type="hidden" name="dir" value="<?= e($dir ?? 'desc') ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('module/'.$module)) ?>">Reset</a>
            </form>
            <button type="button" class="btn btn-ghost btn-sm" data-col-toggle aria-haspopup="true">Columns</button>
            <a class="btn btn-ghost btn-sm" href="?<?= e(request_query(['export' => 'csv', 'page' => null])) ?>">Export CSV</a>
            <?php if (fe_can($module, 'create')): ?>
                <a class="btn btn-gold btn-sm" href="<?= e(url('module/'.$module.'/create')) ?>">Add <?= e($def['singular']) ?></a>
            <?php endif; ?>
        </div>
    </header>

    <?php
    $chips = [];
    if (input('q')) $chips[] = ['q', 'Search: ' . input('q')];
    if (input('status')) $chips[] = ['status', 'Status: ' . input('status')];
    if (input('date_from')) $chips[] = ['date_from', 'From: ' . input('date_from')];
    if (input('date_to')) $chips[] = ['date_to', 'To: ' . input('date_to')];
    ?>
    <?php if ($chips): ?>
        <div class="filter-chips" aria-label="Active filters">
            <?php foreach ($chips as [$key, $label]): ?>
                <a class="filter-chip" href="?<?= e(request_query([$key => null, 'page' => 1])) ?>"><?= e($label) ?> ×</a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="col-toggle-menu" data-col-menu hidden>
        <?php foreach ($columns as $name => $field): ?>
            <label><input type="checkbox" data-col-key="<?= e($name) ?>" checked> <?= e($field['label']) ?></label>
        <?php endforeach; ?>
    </div>

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
                <th data-col="_actions"></th>
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
                    <td colspan="<?= count($columns)+1 ?>">
                        <div class="empty-state">
                            <h4>No <?= e(strtolower($def['title'])) ?> yet</h4>
                            <p class="muted">Nothing matches this view.</p>
                            <?php if (fe_can($module, 'create')): ?>
                                <a class="btn btn-gold btn-sm" href="<?= e(url('module/'.$module.'/create')) ?>">Add your first <?= e(strtolower($def['singular'])) ?></a>
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
