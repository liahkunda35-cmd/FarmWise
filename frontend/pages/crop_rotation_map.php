<?php
$farm = is_array($farm ?? null) ? $farm : null;
$mapData = is_array($mapData ?? null) ? $mapData : ['farm' => null, 'fields' => [], 'summary' => ['total' => 0, 'occupied' => 0, 'vacant' => 0, 'awaiting' => 0]];
$summary = $mapData['summary'];
$colors = CropRotationMapService::statusColors();
?>
<section class="panel crop-rotation-page">
    <header class="list-hero">
        <div class="list-hero-copy">
            <h3>Crop Rotation</h3>
            <p class="muted"><?= $farm ? e($farm['name']) : 'Register a farm to begin' ?></p>
        </div>
        <div class="list-hero-actions">
            <?php if ($farm && !empty($mapData['fields'])): ?>
                <button class="btn btn-gold btn-sm" type="button" data-cr-select-field>Select Field</button>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('module/plantings/create')) ?>">+ Add planting</a>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('crop-rotation/farm-report')) ?>">Farm report PDF</a>
            <?php endif; ?>
            <?php if (fe_can('crop_rotations', 'view')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('module/crop_rotations')) ?>">Rotation notes</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$farm): ?>
        <div class="empty-state">
            <h4>No farm registered yet.</h4>
            <p class="muted">Register a farm and create fields before using the crop rotation map.</p>
            <?php if (fe_can('farms', 'create')): ?>
                <a class="btn btn-gold" href="<?= e(url('module/farms/create')) ?>">+ Add Farm</a>
            <?php endif; ?>
        </div>
    <?php elseif (empty($mapData['fields'])): ?>
        <div class="empty-state">
            <h4>No fields on this farm yet.</h4>
            <p class="muted">Create fields in Fields &amp; Plots, then return here to track crop history.</p>
            <?php if (fe_can('fields', 'create')): ?>
                <a class="btn btn-gold" href="<?= e(url('fields-planner')) ?>">Open Fields &amp; Plots</a>
                <a class="btn btn-ghost" href="<?= e(url('module/fields/create')) ?>">+ Add Field</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="cr-summary" aria-label="Field utilisation summary">
            <div><span>Total fields</span><b><?= (int) $summary['total'] ?></b></div>
            <div><span>Occupied</span><b><?= (int) $summary['occupied'] ?></b></div>
            <div><span>Vacant</span><b><?= (int) $summary['vacant'] ?></b></div>
            <div><span>Awaiting planting</span><b><?= (int) $summary['awaiting'] ?></b></div>
        </div>

        <div class="cr-layout">
            <div class="cr-map-wrap">
                <div class="map-snip-shell">
                    <div class="map-snip-banner" data-cr-snip-banner hidden>Drag around a field like a snipping tool, then release</div>
                    <div id="crop-rotation-map" class="map-canvas map-canvas-tall"></div>
                </div>
                <p class="muted" id="cr-map-status" aria-live="polite">Click a field, or press Select Field and drag around it.</p>
                <div class="cr-legend">
                    <?php foreach (['growing', 'awaiting', 'rotated', 'vacant'] as $key): ?>
                        <span><i style="background:<?= e($colors[$key]['hex']) ?>"></i><?= e($colors[$key]['label']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <aside class="cr-side" data-cr-panel>
                <div data-cr-empty class="muted">Select a field to view its crop rotation report.</div>
                <div data-cr-report hidden>
                    <header class="cr-report-head">
                        <h4 data-cr-field-name>—</h4>
                        <p class="muted" data-cr-field-meta></p>
                    </header>
                    <p><strong>Current crop:</strong> <span data-cr-current>—</span></p>
                    <div class="table-wrap">
                        <table class="data-table cr-history-table">
                            <thead>
                                <tr>
                                    <th>Crop</th>
                                    <th>Planting</th>
                                    <th>Harvest</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody data-cr-history></tbody>
                        </table>
                    </div>
                    <div class="form-actions">
                        <a class="btn btn-gold btn-sm" data-cr-field-report href="#">Generate Field Report</a>
                        <a class="btn btn-ghost btn-sm" data-cr-add-planting href="#">Add planting</a>
                    </div>
                </div>
                <div data-cr-chooser hidden>
                    <h4>Choose field</h4>
                    <p class="muted">Your selection covers more than one field.</p>
                    <div data-cr-chooser-list class="cr-chooser-list"></div>
                </div>
            </aside>
        </div>
        <script>
        window.IFMS_CROP_ROTATION = <?= json_encode([
            'map' => $mapData,
            'colors' => $colors,
            'urls' => [
                'fieldReport' => url('crop-rotation/field-report'),
                'plantingCreate' => url('module/plantings/create'),
                'base' => url(''),
            ],
        ], JSON_UNESCAPED_UNICODE) ?>;
        </script>
    <?php endif; ?>
</section>
