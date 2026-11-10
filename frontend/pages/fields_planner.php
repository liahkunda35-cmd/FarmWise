<?php
$farm = is_array($farm ?? null) ? $farm : null;
$fields = is_array($fields ?? null) ? $fields : [];
$fieldPayload = [];
foreach ($fields as $f) {
    $geo = null;
    if (!empty($f['boundary_notes']) && preg_match('/\[\[GEOJSON\]\](\{[\s\S]*\})\s*$/', (string) $f['boundary_notes'], $m)) {
        $geo = json_decode($m[1], true);
    }
    $fieldPayload[] = [
        'id' => (int) $f['id'],
        'name' => $f['name'],
        'status' => $f['status'] ?? 'active',
        'size_hectares' => $f['size_hectares'] ?? 0,
        'description' => $f['description'] ?? ($f['land_use'] ?? ''),
        'latitude' => $f['latitude'] ?? null,
        'longitude' => $f['longitude'] ?? null,
        'geometry' => $geo,
        'planting_count' => (int) ($f['planting_count'] ?? 0),
    ];
}
$boundary = null;
if (!empty($farm['boundary_geojson'])) {
    $boundary = json_decode((string) $farm['boundary_geojson'], true);
}
$canEdit = fe_can('fields', 'edit');
$canCreate = fe_can('fields', 'create');
$canDelete = fe_can('fields', 'delete');
?>
<section class="panel fields-planner-page">
    <header class="list-hero">
        <div class="list-hero-copy">
            <h3>Fields &amp; Plots</h3>
            <p class="muted"><?= $farm ? e($farm['name']) : 'No farm registered yet' ?></p>
            <?php if ($farm): ?>
                <p class="list-count" data-field-count><?= count($fieldPayload) ?> field<?= count($fieldPayload) === 1 ? '' : 's' ?></p>
            <?php endif; ?>
        </div>
        <div class="list-hero-actions">
            <?php if ($farm && $canEdit): ?>
                <button class="btn btn-ghost btn-sm" type="button" data-draw-farm-boundary>Draw farm boundary</button>
                <button class="btn btn-gold btn-sm" type="button" data-divide-fields>Divide Farm into Fields</button>
                <button class="btn btn-ghost btn-sm" type="button" data-draw-irregular-field>Draw corners</button>
                <button class="btn btn-forest btn-sm" type="button" data-save-fields-plan>Save farm boundary</button>
            <?php elseif (fe_can('farms', 'create')): ?>
                <a class="btn btn-gold" href="<?= e(url('module/farms/create')) ?>">+ Add Farm</a>
            <?php endif; ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('module/fields')) ?>">Table view</a>
        </div>
    </header>

    <?php if (!$farm): ?>
        <div class="empty-state">
            <h4>No farm registered yet. Please add a farm to get started.</h4>
            <p class="muted">Fields &amp; Plots uses your saved farm location and boundary.</p>
            <?php if (fe_can('farms', 'create')): ?>
                <a class="btn btn-gold" href="<?= e(url('module/farms/create')) ?>">+ Add Farm</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="fields-planner-layout">
            <div class="fields-planner-map-wrap map-snip-shell">
                <div class="map-snip-banner" data-fields-snip-banner hidden>Drag to select a field area, then release</div>
                <div id="fields-planner-map" class="map-canvas map-canvas-tall"></div>
                <p class="muted" id="fields-planner-status" aria-live="polite">
                    <?= $boundary
                        ? 'Use Divide Farm into Fields, then drag on the map like a snipping tool.'
                        : 'Draw the farm boundary first, then divide it into named fields.' ?>
                </p>
            </div>
            <aside class="fields-planner-side">
                <h4 data-field-form-title>Field details</h4>
                <div data-field-detail class="field-detail muted">Select an area on the map to auto-fill this form.</div>
                <form id="fields-planner-form" method="post" action="<?= e(url('fields-planner/save')) ?>">
                    <?= fe_csrf_field() ?>
                    <input type="hidden" name="boundary_geojson" id="boundary_geojson" value="">
                    <input type="hidden" name="fields_geojson" id="fields_geojson" value="[]">
                    <input type="hidden" name="delete_ids" id="delete_ids" value="[]">
                    <input type="hidden" id="field-edit-id" value="">

                    <label>Farm
                        <input type="text" id="field-edit-farm" value="<?= e($farm['name']) ?>" readonly>
                    </label>
                    <label>Field name
                        <input type="text" id="field-edit-name" maxlength="120" placeholder="Field #1" disabled>
                    </label>
                    <label>Description <span class="muted">(optional)</span>
                        <textarea id="field-edit-description" rows="2" maxlength="500" disabled></textarea>
                    </label>
                    <label>Total area (ha)
                        <input type="number" id="field-edit-area" step="0.01" min="0" readonly disabled>
                    </label>
                    <p class="muted tiny" data-field-area-source hidden>Calculated from the selected boundary</p>
                    <div class="fp-coord-row">
                        <label>Latitude
                            <input type="text" id="field-edit-lat" readonly disabled>
                        </label>
                        <label>Longitude
                            <input type="text" id="field-edit-lng" readonly disabled>
                        </label>
                    </div>
                    <label>Status
                        <select id="field-edit-status" disabled>
                            <option value="active">Active</option>
                            <option value="fallow">Fallow / Vacant</option>
                            <option value="reserved">Reserved</option>
                        </select>
                    </label>
                    <p class="muted tiny" data-field-boundary-note hidden>Boundary polygon ready to save from map selection.</p>
                    <div class="form-actions">
                        <button class="btn btn-gold" type="button" data-save-field disabled>Save Field</button>
                        <button class="btn btn-ghost btn-sm" type="button" data-cancel-field-draft hidden>Cancel</button>
                        <button class="btn btn-ghost btn-sm" type="button" data-edit-field-boundary disabled>Edit boundary</button>
                        <button class="btn btn-ghost btn-sm" type="button" data-delete-field disabled>Delete field</button>
                    </div>
                </form>
            </aside>
        </div>

        <div class="fields-planner-list-wrap">
            <h4>Fields &amp; Plots</h4>
            <div class="table-wrap sticky-head">
                <table class="data-table fields-planner-table">
                    <thead>
                        <tr>
                            <th>Field Name</th>
                            <th>Area (ha)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody data-fields-list>
                        <?php if (!$fieldPayload): ?>
                            <tr data-empty-row>
                                <td colspan="4" class="muted">No fields yet. Use Divide Farm into Fields to create Field #1.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fieldPayload as $f): ?>
                                <tr data-field-row="<?= (int) $f['id'] ?>">
                                    <td><?= e($f['name']) ?></td>
                                    <td><?= e(number_format((float) $f['size_hectares'], 2)) ?></td>
                                    <td><?= e(ucfirst((string) $f['status'])) ?></td>
                                    <td>
                                        <button type="button" class="btn btn-ghost btn-sm" data-focus-field="<?= (int) $f['id'] ?>">View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        window.IFMS_FIELDS_PLANNER = {
            farm: <?= json_encode([
                'id' => (int) $farm['id'],
                'name' => $farm['name'],
                'latitude' => $farm['latitude'] ?? null,
                'longitude' => $farm['longitude'] ?? null,
                'boundary' => $boundary,
            ], JSON_UNESCAPED_UNICODE) ?>,
            fields: <?= json_encode($fieldPayload, JSON_UNESCAPED_UNICODE) ?>,
            canEdit: <?= $canEdit ? 'true' : 'false' ?>,
            canCreate: <?= $canCreate ? 'true' : 'false' ?>,
            canDelete: <?= $canDelete ? 'true' : 'false' ?>,
            csrf: <?= json_encode(fe_csrf_token()) ?>,
            saveFieldUrl: <?= json_encode(url('fields-planner/save-field')) ?>,
            deleteFieldUrl: <?= json_encode(url('fields-planner/delete-field')) ?>
        };
        </script>
    <?php endif; ?>
</section>
