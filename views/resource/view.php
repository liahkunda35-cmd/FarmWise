<?php
require dirname(__DIR__) . '/partials/frontend.php';

$controllerRelated = $related ?? [];
$extraRelated = fe_extra_related($module, $row);
// Merge: prefer controller groups, append extras with unique titles
$seenTitles = [];
$allRelated = [];
foreach ($controllerRelated as $g) {
    $seenTitles[strtolower($g['title'])] = true;
    $allRelated[] = $g;
}
foreach ($extraRelated as $g) {
    if (!empty($seenTitles[strtolower($g['title'])])) {
        continue;
    }
    $allRelated[] = $g;
}

$moneyRows = fe_money_rows($module, $row);
$canMap = in_array($module, ['farms', 'fields'], true);
$geo = $module === 'fields' ? fe_extract_geojson($row['boundary_notes'] ?? null) : null;
$nextModule = $module;
$recordPayload = [
    'id' => (int) $row['id'],
    'field_id' => $row['field_id'] ?? null,
    'planting_id' => $row['planting_id'] ?? null,
    'farm_id' => $row['farm_id'] ?? ($module === 'farms' ? $row['id'] : null),
    'customer_id' => $row['customer_id'] ?? null,
    'supplier_id' => $row['supplier_id'] ?? null,
    'invoice_id' => $row['invoice_id'] ?? null,
    'breed_id' => $row['breed_id'] ?? null,
    'animal_id' => $module === 'animals' ? $row['id'] : ($row['animal_id'] ?? null),
    'employee_id' => $module === 'employees' ? $row['id'] : ($row['employee_id'] ?? null),
    'harvest_id' => $module === 'harvests' ? $row['id'] : ($row['harvest_id'] ?? null),
];

// Planting cost to date
$plantingCost = null;
$costPerHa = null;
if ($module === 'plantings' && table_exists('transactions')) {
    try {
        $plantingCost = (float) db()->value('SELECT COALESCE(SUM(cost),0) FROM fertilizer_applications WHERE planting_id = ?', [$row['id']]);
        $fieldArea = null;
        if (!empty($row['field_id'])) {
            $fieldArea = db()->value('SELECT size_hectares FROM fields WHERE id = ?', [$row['field_id']]);
        }
        if ($fieldArea && (float)$fieldArea > 0) {
            $costPerHa = $plantingCost / (float)$fieldArea;
        }
    } catch (Throwable $e) {
        $plantingCost = null;
    }
}

$docStatus = $row['status'] ?? null;
$timeline = [];
if (in_array($module, ['invoices', 'sales_orders', 'purchase_orders', 'quotations'], true)) {
    $timeline = match ($module) {
        'invoices' => ['draft', 'sent', 'partial', 'paid', 'cancelled'],
        'sales_orders' => ['draft', 'confirmed', 'fulfilled', 'cancelled'],
        'purchase_orders' => ['draft', 'ordered', 'received', 'cancelled'],
        'quotations' => ['draft', 'sent', 'accepted', 'rejected'],
        default => [],
    };
}
?>
<div class="record-page" data-record-page data-module="<?= e($module) ?>" data-record-id="<?= e($row['id']) ?>">
    <section class="panel">
        <header class="panel-tools">
            <div>
                <h3><?= e($def['singular']) ?>
                    <?php if ($module === 'plantings'): ?>
                        — <?= e(fe_planting_label(fe_load_planting($row['id']))) ?>
                    <?php else: ?>
                        #<?= e($row['id']) ?>
                    <?php endif; ?>
                </h3>
                <?php if (!empty($row['status'])): ?><?= status_badge($row['status']) ?><?php endif; ?>
            </div>
            <div class="tool-row">
                <?php if (!empty($document)): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('module/'.$module.'/print/'.$row['id'])) ?>">Print</a>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('module/'.$module.'/pdf/'.$row['id'])) ?>">PDF</a>
                    <button type="button" class="btn btn-ghost btn-sm" data-doc-preview>Preview</button>
                <?php endif; ?>
                <?php if (Auth::can($module, 'edit')): ?>
                    <a class="btn btn-gold btn-sm" href="<?= e(url('module/'.$module.'/edit/'.$row['id'])) ?>">Edit</a>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('module/'.$module)) ?>">Back</a>
            </div>
        </header>

        <div class="next-steps" data-next-steps data-module="<?= e($nextModule) ?>" hidden>
            <span class="next-steps-label">What next?</span>
            <div class="next-steps-chips" data-next-chips></div>
        </div>

        <?php if ($timeline): ?>
            <ol class="status-timeline" aria-label="Document status">
                <?php foreach ($timeline as $step):
                    $done = $docStatus && (strtolower((string)$docStatus) === $step || array_search($step, $timeline) <= array_search(strtolower((string)$docStatus), $timeline));
                ?>
                    <li class="<?= $done ? 'is-done' : '' ?><?= strtolower((string)$docStatus) === $step ? ' is-current' : '' ?>"><?= e(ucfirst($step)) ?></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <div data-tabs class="record-tabs">
            <div class="ui-tabs" role="tablist">
                <button type="button" data-tab="overview" class="is-active" role="tab">Overview</button>
                <?php foreach ($allRelated as $i => $group): ?>
                    <button type="button" data-tab="rel-<?= (int)$i ?>" role="tab"><?= e($group['title']) ?></button>
                <?php endforeach; ?>
                <button type="button" data-tab="money" role="tab">Money</button>
                <?php if (isset($attachments)): ?>
                    <button type="button" data-tab="files" role="tab">Files</button>
                <?php endif; ?>
                <?php if ($canMap): ?>
                    <button type="button" data-tab="map" role="tab">Map</button>
                <?php endif; ?>
                <button type="button" data-tab="activity" role="tab">Activity</button>
            </div>

            <div data-tab-panel="overview">
                <dl class="detail-grid">
                    <?php foreach ($def['fields'] as $name => $field): ?>
                        <div>
                            <dt><?= e($field['label']) ?></dt>
                            <dd><?php
                                if ($name === 'boundary_notes') {
                                    echo e(fe_strip_geojson_notes($row[$name] ?? '') ?: '—');
                                } elseif ($name === 'status') {
                                    echo status_badge($row[$name] ?? '');
                                } else {
                                    echo fe_display($field, $row[$name] ?? null, true);
                                }
                            ?></dd>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($module === 'farms' && !empty($row['join_code'])): ?>
                        <div>
                            <dt>Farm join code</dt>
                            <dd>
                                <strong data-copy-text="<?= e($row['join_code']) ?>"><?= e($row['join_code']) ?></strong>
                                <button type="button" class="btn btn-ghost btn-sm" data-copy>Copy</button>
                                <p class="muted">Share with managers and workers so they can join this farm.</p>
                            </dd>
                        </div>
                    <?php endif; ?>
                </dl>

                <?php if ($module === 'plantings'): ?>
                    <div class="hub-cards">
                        <article class="hub-card">
                            <h4>Cost to date</h4>
                            <b><?= $plantingCost !== null ? e(money($plantingCost)) : '—' ?></b>
                            <?php if ($costPerHa !== null): ?>
                                <span class="muted"><?= e(money($costPerHa)) ?> / ha</span>
                            <?php endif; ?>
                        </article>
                        <article class="hub-card">
                            <h4>Seed &amp; seedlings</h4>
                            <p><?= e(($row['seed_quantity'] ?? '—') . ' ' . ($row['seed_unit'] ?? '')) ?></p>
                            <?php if (Auth::can('inventory_items', 'view')): ?>
                                <a href="<?= e(url('module/inventory_items?q=seed')) ?>">Inventory (seeds) →</a>
                            <?php endif; ?>
                        </article>
                        <?php if (Auth::can('yield_forecasts', 'view') || Auth::can('forecasts', 'view')): ?>
                        <article class="hub-card">
                            <h4>Forecast</h4>
                            <a href="<?= e(url('forecasts')) ?>">Open yield forecasts →</a>
                        </article>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($module === 'animals' && table_exists('livestock_weights')): ?>
                    <?php
                    $weights = db()->select('SELECT weigh_date, weight_kg FROM livestock_weights WHERE animal_id = ? ORDER BY weigh_date ASC LIMIT 30', [$row['id']]);
                    ?>
                    <?php if ($weights): ?>
                        <div class="chart-wrap" style="height:180px;margin-top:1rem">
                            <canvas id="weightChart" aria-label="Weight growth"></canvas>
                        </div>
                        <script>
                        window.IFMS_WEIGHT = {
                            labels: <?= json_encode(array_column($weights, 'weigh_date')) ?>,
                            data: <?= json_encode(array_map('floatval', array_column($weights, 'weight_kg'))) ?>
                        };
                        </script>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php foreach ($allRelated as $i => $group): ?>
                <div data-tab-panel="rel-<?= (int)$i ?>" hidden>
                    <header class="panel-tools" style="margin-bottom:.8rem">
                        <h4><?= e($group['title']) ?></h4>
                        <div class="tool-row">
                            <?php if (!empty($group['create_url'])): ?>
                                <a class="btn btn-gold btn-sm" href="<?= e($group['create_url']) ?>">Add</a>
                            <?php elseif (Auth::can($group['module'], 'create')): ?>
                                <a class="btn btn-gold btn-sm" href="<?= e(url('module/'.$group['module'].'/create')) ?>">Add</a>
                            <?php endif; ?>
                            <?php if (!empty($group['filter_url']) || Auth::can($group['module'], 'view')): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= e($group['filter_url'] ?? url('module/'.$group['module'])) ?>">Open filtered list</a>
                            <?php endif; ?>
                        </div>
                    </header>
                    <?php if (empty($group['rows'])): ?>
                        <div class="empty-state compact">
                            <p class="muted">No related <?= e(strtolower($group['title'])) ?> yet.</p>
                        </div>
                    <?php else: ?>
                        <ul class="feed">
                            <?php foreach ($group['rows'] as $rel): ?>
                                <li>
                                    <a class="feed-link" href="<?= e(url('module/'.$group['module'].'/view/'.$rel['id'])) ?>">
                                        <strong><?= e($rel[$group['label']] ?? option_label($rel) ?: ('#'.$rel['id'])) ?></strong>
                                        <span><?= !empty($rel['status']) ? status_badge($rel['status']) : '' ?> <?= e($rel['created_at'] ?? '') ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div data-tab-panel="money" hidden>
                <?php if (!$moneyRows): ?>
                    <div class="empty-state compact">
                        <p class="muted">No linked income or expense rows for this record yet.</p>
                        <?php if (Auth::can('transactions', 'view')): ?>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('module/transactions')) ?>">Open Income &amp; Expenses</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <ul class="feed">
                        <?php foreach ($moneyRows as $txn): ?>
                            <li>
                                <a class="feed-link" href="<?= e(url('module/transactions/view/'.$txn['id'])) ?>">
                                    <strong><?= e($txn['description'] ?? $txn['category'] ?? 'Transaction') ?></strong>
                                    <span><?= e(($txn['type'] ?? '') . ' · ' . money($txn['amount'] ?? 0)) ?> <?= !empty($txn['txn_date']) ? e(dater($txn['txn_date'])) : '' ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php if (isset($attachments)): ?>
            <div data-tab-panel="files" hidden>
                <?php if (Auth::can($module, 'edit')): ?>
                    <form class="search-form" method="post" action="<?= e(url('module/'.$module.'/attach/'.$row['id'])) ?>" enctype="multipart/form-data">
                        <?= Csrf::field() ?>
                        <label class="sr-only" for="attach-file">Upload file</label>
                        <input id="attach-file" type="file" name="file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,image/*,application/pdf" required>
                        <button class="btn btn-gold btn-sm" type="submit">Upload</button>
                    </form>
                    <p class="muted">JPG, PNG, GIF, WEBP or PDF up to 5 MB.</p>
                <?php endif; ?>
                <div class="attach-grid">
                    <?php foreach ($attachments as $att): ?>
                        <figure class="attach-card">
                            <?php if (AttachmentService::isImage($att)): ?>
                                <a href="<?= e(url('module/'.$module.'/file/'.$att['id'])) ?>" target="_blank" rel="noopener">
                                    <img src="<?= e(url('module/'.$module.'/file/'.$att['id'])) ?>" alt="<?= e($att['original_name']) ?>">
                                </a>
                            <?php else: ?>
                                <a href="<?= e(url('module/'.$module.'/file/'.$att['id'])) ?>"><?= e($att['original_name']) ?></a>
                            <?php endif; ?>
                            <figcaption>
                                <?= e($att['original_name']) ?>
                                <?php if (Auth::can($module, 'edit')): ?>
                                    <form method="post" action="<?= e(url('module/'.$module.'/unattach/'.$att['id'])) ?>" data-confirm="Remove this file?">
                                        <?= Csrf::field() ?>
                                        <button type="submit">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
                <?php if (!$attachments): ?><p class="muted">No files attached yet.</p><?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($canMap): ?>
            <div data-tab-panel="map" hidden>
                <div id="record-mini-map" class="map-canvas map-canvas-sm" data-farm-map="mini"
                     data-lat="<?= e($row['latitude'] ?? '') ?>"
                     data-lng="<?= e($row['longitude'] ?? '') ?>"
                     data-geojson="<?= e($geo ? json_encode($geo) : '') ?>"
                     data-label="<?= e(option_label($row) ?: ($def['singular'].' #'.$row['id'])) ?>"></div>
                <?php if (empty($row['latitude']) && empty($row['longitude']) && !$geo): ?>
                    <div class="empty-state compact">
                        <p class="muted">No coordinates yet.</p>
                        <?php if (Auth::can($module, 'edit')): ?>
                            <a class="btn btn-gold btn-sm" href="<?= e(url('module/'.$module.'/edit/'.$row['id'])) ?>">Set location</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div data-tab-panel="activity" hidden>
                <?php
                $activity = [];
                if (table_exists('audit_logs') && Auth::can('audit_logs', 'view')) {
                    try {
                        $activity = db()->select(
                            'SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.module = ? AND a.record_id = ? ORDER BY a.id DESC LIMIT 20',
                            [$module, $row['id']]
                        );
                    } catch (Throwable $e) {
                        $activity = [];
                    }
                }
                ?>
                <?php if (!$activity): ?>
                    <p class="muted">No activity log entries for this record<?= Auth::can('audit_logs', 'view') ? '' : ' (or you do not have audit access)' ?>.</p>
                <?php else: ?>
                    <ul class="feed">
                        <?php foreach ($activity as $a): ?>
                            <li>
                                <strong><?= e($a['action'] ?? 'event') ?></strong>
                                <span><?= e($a['user_name'] ?? 'System') ?> · <?= e($a['created_at'] ?? '') ?></span>
                                <?php if (!empty($a['details'])): ?><p class="muted"><?= e($a['details']) ?></p><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if (!empty($document)): ?>
    <section class="panel">
        <header><h3>Email this <?= e(strtolower($def['singular'])) ?></h3></header>
        <form class="search-form" method="post" action="<?= e(url('module/'.$module.'/email/'.$row['id'])) ?>">
            <?= Csrf::field() ?>
            <label class="sr-only" for="doc-email">Recipient email</label>
            <input id="doc-email" type="email" name="email" placeholder="Recipient email" required>
            <input name="message" placeholder="Optional message" aria-label="Optional message">
            <button class="btn btn-gold btn-sm" type="submit">Email document</button>
        </form>
    </section>
    <div class="ui-modal" id="doc-preview-modal" hidden>
        <div class="ui-modal-backdrop" data-close-preview></div>
        <div class="ui-modal-card" style="max-width:720px;max-height:85vh;overflow:auto">
            <header class="panel-tools">
                <h3>Preview</h3>
                <button type="button" class="btn btn-ghost btn-sm" data-close-preview>Close</button>
            </header>
            <iframe title="Document preview" src="<?= e(url('module/'.$module.'/print/'.$row['id'])) ?>" style="width:100%;min-height:60vh;border:1px solid var(--line);border-radius:12px"></iframe>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>
window.IFMS_RECORD = <?= json_encode(['module' => $module, 'record' => $recordPayload, 'canCreate' => array_values(array_filter(array_map(function ($m) {
    return Auth::can($m, 'create') ? $m : null;
}, ['fields','warehouses','employees','budgets','soil_records','crop_rotations','plantings','irrigation_systems','crop_growth','fertilizer_applications','spray_schedules','pest_scouting','tasks','harvests','storage_lots','sales_orders','invoices','payments','dispatches','quotations','purchase_orders','stock_movements','livestock_health','livestock_feed','livestock_weights','breeding_records','livestock_production','attendance','payroll','equipment_logs','water_logs','contracts'])))]) ?>;
</script>
