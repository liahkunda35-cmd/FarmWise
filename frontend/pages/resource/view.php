<?php
fe_component('partials/frontend');

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
if ($module === 'plantings') {
    $costing = fe_planting_cost($row);
    $plantingCost = $costing['cost'];
    $costPerHa = $costing['per_ha'];
}

$docStatus = $row['status'] ?? null;
$timeline = [];
if (in_array($module, ['invoices', 'sales_orders', 'purchase_orders', 'quotations'], true)) {
    $timeline = match ($module) {
        'invoices' => ['draft', 'sent', 'partial', 'paid', 'cancelled'],
        'sales_orders' => ['draft', 'confirmed', 'fulfilled', 'cancelled'],
        'purchase_orders' => ['pending_approval', 'approved', 'received', 'rejected'],
        'quotations' => ['received', 'accepted', 'rejected'],
        default => [],
    };
}
?>
<div class="record-page" data-record-page data-module="<?= e($module) ?>" data-record-id="<?= e($row['id']) ?>">
    <?php if ($module === 'farms'): ?>
        <section class="farm-profile" aria-label="Farm summary">
            <article>
                <span>Farm</span>
                <strong><?= e($row['name'] ?? '') ?></strong>
            </article>
            <article>
                <span>Location</span>
                <strong><?= e(trim((string) ($row['location'] ?? '')) !== '' ? $row['location'] : 'Not set') ?></strong>
            </article>
            <?php if (!empty($row['latitude']) && !empty($row['longitude'])): ?>
                <article>
                    <span>Coordinates</span>
                    <strong><?= e($row['latitude']) ?>, <?= e($row['longitude']) ?></strong>
                </article>
            <?php endif; ?>
            <?php if (farm_code_feature_enabled() && !empty($row['join_code']) && (fe_role() === 'owner' || fe_is_admin())): ?>
                <article>
                    <span>Farm Code</span>
                    <strong data-copy-text="<?= e($row['join_code']) ?>"><?= e($row['join_code']) ?></strong>
                    <button type="button" class="btn btn-ghost btn-sm" data-copy>Copy</button>
                </article>
            <?php endif; ?>
        </section>
    <?php endif; ?>
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
                <?php if ($module === 'employees'): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('module/employees/report/'.$row['id'])) ?>">Generate Report</a>
                    <?php if (fe_can('employees', 'edit') && !empty($row['user_id'])): ?>
                        <form method="post" action="<?= e(url('module/employees/reset-password/'.$row['id'])) ?>" data-confirm="Issue a new temporary password? The employee must change it on next sign-in.">
                            <?= fe_csrf_field() ?>
                            <button class="btn btn-ghost btn-sm" type="submit">New temporary password</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($module === 'payroll' || !empty($document)): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('module/'.$module.'/print/'.$row['id'])) ?>">Print</a>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('module/'.$module.'/pdf/'.$row['id'])) ?>">PDF</a>
                    <?php if (!empty($document)): ?>
                        <button type="button" class="btn btn-ghost btn-sm" data-doc-preview>Preview</button>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (fe_can($module, 'edit')): ?>
                    <a class="btn btn-gold btn-sm" href="<?= e(url('module/'.$module.'/edit/'.$row['id'])) ?>">Edit</a>
                <?php endif; ?>
                <?php if ($module === 'stock_movements' && fe_can('stock_movements', 'create')): ?>
                    <form method="post" action="<?= e(url('module/stock_movements/delete/'.$row['id'])) ?>" data-confirm="Record a compensating stock movement (history is kept)?" style="display:inline">
                        <?= fe_csrf_field() ?>
                        <input type="hidden" name="reverse" value="1">
                        <input type="hidden" name="reason" value="Reversed from record view">
                        <button type="submit" class="btn btn-ghost btn-sm">Reverse movement</button>
                    </form>
                <?php endif; ?>
                <?php if ($module === 'payments' && fe_can('payments', 'create') && (float)($row['amount'] ?? 0) > 0): ?>
                    <form method="post" action="<?= e(url('module/payments/delete/'.$row['id'])) ?>" data-confirm="Record a refund (ledger offset; original payment kept)?" style="display:inline">
                        <?= fe_csrf_field() ?>
                        <input type="hidden" name="refund" value="1">
                        <input type="hidden" name="reason" value="Refunded from record view">
                        <button type="submit" class="btn btn-ghost btn-sm">Refund payment</button>
                    </form>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('module/'.$module)) ?>">Back</a>
            </div>
        </header>

        <div class="next-steps" data-next-steps data-module="<?= e($nextModule) ?>" hidden>
            <span class="next-steps-label">What next?</span>
            <div class="next-steps-chips" data-next-chips></div>
        </div>

        <?php if ($module === 'payroll'):
            $payEmployee = !empty($row['employee_id']) ? db()->find('employees', (int) $row['employee_id']) : null;
            $payFarm = !empty($row['farm_id']) ? db()->find('farms', (int) $row['farm_id']) : null;
            $payGross = is_numeric($row['gross_pay'] ?? null) ? (float) $row['gross_pay'] : null;
            $payDed = is_numeric($row['deductions'] ?? null) ? (float) $row['deductions'] : 0.0;
            $payNet = is_numeric($row['net_pay'] ?? null) ? (float) $row['net_pay'] : ($payGross !== null ? $payGross - $payDed : null);
        ?>
            <article class="payslip-card" aria-label="Pay slip">
                <header class="payslip-card-head">
                    <div>
                        <p class="eyebrow">Pay slip</p>
                        <h4>PS-<?= e($row['id']) ?></h4>
                        <p class="muted"><?= e($payFarm['name'] ?? 'Farm') ?></p>
                    </div>
                    <div class="payslip-card-status">
                        <?= status_badge($row['status'] ?? 'draft') ?>
                        <strong class="payslip-card-net"><?= $payNet !== null ? money($payNet) : '—' ?></strong>
                        <span class="muted">Net pay</span>
                    </div>
                </header>
                <dl class="payslip-card-grid">
                    <div>
                        <dt>Employee</dt>
                        <dd>
                            <strong><?= e($payEmployee['name'] ?? '—') ?></strong>
                            <?php if (!empty($payEmployee['role_title'])): ?>
                                <span class="muted"><?= e($payEmployee['role_title']) ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Period</dt>
                        <dd><?= e(dater($row['period_start'] ?? null)) ?> → <?= e(dater($row['period_end'] ?? null)) ?></dd>
                    </div>
                    <div>
                        <dt>Days worked</dt>
                        <dd><?= e($row['days_worked'] ?? '—') ?></dd>
                    </div>
                    <div>
                        <dt>Daily rate</dt>
                        <dd><?= isset($payEmployee['daily_rate']) ? money($payEmployee['daily_rate']) : '—' ?></dd>
                    </div>
                    <div>
                        <dt>Gross pay</dt>
                        <dd><?= $payGross !== null ? money($payGross) : '—' ?></dd>
                    </div>
                    <div>
                        <dt>Deductions</dt>
                        <dd><?= money($payDed) ?></dd>
                    </div>
                    <?php if (!empty($row['paid_date'])): ?>
                        <div>
                            <dt>Paid date</dt>
                            <dd><?= e(dater($row['paid_date'])) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
                <?php if (!empty($row['calculation_note'])): ?>
                    <p class="payslip-card-note muted"><?= e($row['calculation_note']) ?></p>
                <?php endif; ?>
            </article>
        <?php endif; ?>

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
                <?php if ($module === 'farms'): ?>
                    <?php
                    $prec = (string) ($row['location_precision'] ?? 'none');
                    $hasPin = !empty($row['latitude']) && !empty($row['longitude']);
                    $isEmpty = static function ($v): bool {
                        $s = trim((string) ($v ?? ''));
                        return $s === '' || $s === '—';
                    };
                    $aboutFields = ['name', 'total_area', 'status', 'established_date', 'description'];
                    $placeFields = ['country', 'province', 'district', 'town', 'landmark', 'location'];
                    $contactFields = ['phone', 'email'];
                    ?>
                    <div class="farm-overview">
                        <section class="farm-ov-card">
                            <h4>About the farm</h4>
                            <dl class="detail-rows">
                                <?php foreach ($aboutFields as $name):
                                    if (!isset($def['fields'][$name])) continue;
                                    $field = $def['fields'][$name];
                                    $raw = $row[$name] ?? null;
                                    if ($name !== 'name' && $name !== 'status' && $name !== 'total_area' && $isEmpty($raw)) continue;
                                ?>
                                    <div>
                                        <dt><?= e($field['label']) ?></dt>
                                        <dd><?php
                                            if ($name === 'status') {
                                                echo status_badge($row[$name] ?? '');
                                            } else {
                                                echo fe_display($field, $raw, true);
                                            }
                                        ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        </section>

                        <section class="farm-ov-card">
                            <h4>Place</h4>
                            <dl class="detail-rows">
                                <?php foreach ($placeFields as $name):
                                    if (!isset($def['fields'][$name])) continue;
                                    $field = $def['fields'][$name];
                                    $raw = $row[$name] ?? null;
                                    if ($isEmpty($raw) && !in_array($name, ['country', 'location'], true)) continue;
                                ?>
                                    <div>
                                        <dt><?= e($field['label']) ?></dt>
                                        <dd><?= fe_display($field, $raw, true) ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        </section>

                        <section class="farm-ov-card">
                            <h4>Contact</h4>
                            <dl class="detail-rows">
                                <?php
                                $hasContact = false;
                                foreach ($contactFields as $name):
                                    if (!isset($def['fields'][$name])) continue;
                                    $raw = $row[$name] ?? null;
                                    if ($isEmpty($raw)) continue;
                                    $hasContact = true;
                                    $field = $def['fields'][$name];
                                ?>
                                    <div>
                                        <dt><?= e($field['label']) ?></dt>
                                        <dd><?= fe_display($field, $raw, true) ?></dd>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$hasContact): ?>
                                    <div>
                                        <dt>Contact</dt>
                                        <dd class="muted">No phone or email added yet.</dd>
                                    </div>
                                <?php endif; ?>
                            </dl>
                        </section>

                        <section class="farm-ov-card">
                            <h4>Map &amp; code</h4>
                            <dl class="detail-rows">
                                <div>
                                    <dt>Map location</dt>
                                    <dd class="farm-ov-map">
                                        <?php if ($prec === 'confirmed' && $hasPin): ?>
                                            <span class="loc-badge loc-badge-ok">Exact farm pin</span>
                                        <?php elseif ($prec === 'approximate' && $hasPin): ?>
                                            <span class="loc-badge loc-badge-warn">Town / village (approximate)</span>
                                        <?php else: ?>
                                            <span class="loc-badge loc-badge-muted">Not set on map yet</span>
                                        <?php endif; ?>
                                        <?php if (!empty($row['location_label'])): ?>
                                            <span class="farm-ov-map-label"><?= e($row['location_label']) ?></span>
                                        <?php elseif (!empty($row['location'])): ?>
                                            <span class="farm-ov-map-label"><?= e($row['location']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($hasPin): ?>
                                            <span class="muted tiny"><?= e($row['latitude']) ?>, <?= e($row['longitude']) ?></span>
                                        <?php endif; ?>
                                        <?php if (fe_can('farms', 'edit')): ?>
                                            <a class="btn btn-ghost btn-sm" href="<?= e(url('module/farms/edit/'.$row['id'])) ?>">Update location on map</a>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <?php if (farm_code_feature_enabled() && !empty($row['join_code'])): ?>
                                <div>
                                    <dt>Farm Code</dt>
                                    <dd class="farm-ov-code">
                                        <strong data-copy-text="<?= e($row['join_code']) ?>"><?= e($row['join_code']) ?></strong>
                                        <button type="button" class="btn btn-ghost btn-sm" data-copy>Copy</button>
                                    </dd>
                                </div>
                                <?php endif; ?>
                            </dl>
                        </section>
                    </div>
                    <section class="farm-ov-map-panel" aria-label="Farm map">
                        <div class="farm-ov-map-head">
                            <h4>Farm map</h4>
                            <?php if (fe_can('farms', 'edit')): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('module/farms/edit/'.$row['id'])) ?>">
                                    <?= !empty($row['boundary_geojson']) ? 'Edit area on map' : 'Select farm area' ?>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php if ($hasPin || !empty($row['boundary_geojson'])): ?>
                            <div
                                id="farm-overview-map"
                                class="map-canvas map-canvas-farm-ov"
                                data-farm-map="mini"
                                data-lat="<?= e($row['latitude'] ?? '') ?>"
                                data-lng="<?= e($row['longitude'] ?? '') ?>"
                                data-label="<?= e($row['name'] ?? 'Farm') ?>"
                                <?php if (!empty($row['boundary_geojson'])): ?>
                                    data-geojson="<?= e($row['boundary_geojson']) ?>"
                                <?php endif; ?>
                            ></div>
                            <?php if (empty($row['boundary_geojson'])): ?>
                                <p class="muted farm-ov-map-empty">Only a pin is saved so far. Click <strong>Select farm area</strong>, drag a box around the land, then Save — the shaded area will show here.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="muted farm-ov-map-empty">No map pin yet. Use Select farm area to place this farm on the map.</p>
                        <?php endif; ?>
                    </section>
                <?php else: ?>
                <dl class="detail-grid">
                    <?php foreach ($def['fields'] as $name => $field): ?>
                        <?php
                        if (in_array($name, ['latitude', 'longitude'], true) && empty($row[$name])) {
                            continue;
                        }
                        ?>
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
                </dl>
                <?php endif; ?>

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
                            <?php if (fe_can('inventory_items', 'view')): ?>
                                <a href="<?= e(url('module/inventory_items?q=seed')) ?>">Inventory (seeds) →</a>
                            <?php endif; ?>
                        </article>
                        <?php if (fe_can('yield_forecasts', 'view') || fe_can('forecasts', 'view')): ?>
                        <article class="hub-card">
                            <h4>Forecast</h4>
                            <a href="<?= e(url('forecasts')) ?>">Open yield forecasts →</a>
                        </article>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($module === 'animals'): ?>
                    <?php $weights = fe_animal_weights((int) $row['id']); ?>
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
                            <?php elseif (fe_can($group['module'], 'create')): ?>
                                <a class="btn btn-gold btn-sm" href="<?= e(url('module/'.$group['module'].'/create')) ?>">Add</a>
                            <?php endif; ?>
                            <?php if (!empty($group['filter_url']) || fe_can($group['module'], 'view')): ?>
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
                                        <strong><?= e(
                                            ($rel[$group['label']] ?? null)
                                                ?: ($rel['crop_name'] ?? null)
                                                ?: option_label($rel)
                                                ?: ('#'.$rel['id'])
                                        ) ?></strong>
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
                        <?php if (fe_can('transactions', 'view')): ?>
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
                <?php if (fe_can($module, 'edit')): ?>
                    <form class="search-form" method="post" action="<?= e(url('module/'.$module.'/attach/'.$row['id'])) ?>" enctype="multipart/form-data" data-validate-form novalidate>
                        <?= fe_csrf_field() ?>
                        <label class="sr-only" for="attach-file">Upload file</label>
                        <input id="attach-file" type="file" name="file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,image/*,application/pdf" required>
                        <span class="field-msg" data-field-error="file" hidden></span>
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
                                <?php if (fe_can($module, 'edit')): ?>
                                    <form method="post" action="<?= e(url('module/'.$module.'/unattach/'.$att['id'])) ?>" data-confirm="Remove this file?">
                                        <?= fe_csrf_field() ?>
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
                        <?php if (fe_can($module, 'edit')): ?>
                            <a class="btn btn-gold btn-sm" href="<?= e(url('module/'.$module.'/edit/'.$row['id'])) ?>">Set location</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div data-tab-panel="activity" hidden>
                <?php
                $activity = fe_record_activity($module, (int) $row['id']);
                ?>
                <?php if (!$activity): ?>
                    <p class="muted">No activity log entries for this record<?= fe_can('audit_logs', 'view') ? '' : ' (or you do not have audit access)' ?>.</p>
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
            <?= fe_csrf_field() ?>
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
    return fe_can($m, 'create') ? $m : null;
}, ['fields','warehouses','employees','budgets','soil_records','crop_rotations','plantings','crop_varieties','irrigation_systems','crop_growth','fertilizer_applications','spray_schedules','pest_scouting','pest_treatments','tasks','harvests','storage_lots','sales_orders','invoices','payments','dispatches','quotations','purchase_orders','stock_movements','customers','suppliers','livestock_health','livestock_feed','livestock_weights','breeding_records','livestock_production','attendance','payroll','equipment_logs','water_logs','contracts','yield_forecasts','land_use_plans']))), 'canEdit' => fe_can($module, 'edit')]) ?>;
</script>
