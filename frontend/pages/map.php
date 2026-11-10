<?php
fe_component('partials/frontend');
$mapLayers = fe_map_layers($fields ?? [], $farms ?? []);
$mapFields = $mapLayers['fields'];
$hasCoords = $mapLayers['has_coords'];
$editFarmUrl = $mapLayers['edit_url'];
$activeFarm = $mapLayers['active_farm'] ?? null;
$farmLabel = '';
if (is_array($activeFarm)) {
    $farmLabel = trim((string) (($activeFarm['location_label'] ?? '') ?: ($activeFarm['location'] ?? '') ?: ($activeFarm['name'] ?? '')));
}
$canEditFarm = fe_can('farms', 'edit') && !empty($activeFarm['id']);
?>
<section class="panel map-page">
    <header class="list-hero">
        <div class="list-hero-copy">
            <h3>Farm Map</h3>
            <p class="muted" id="map-location-label"><?= $farmLabel !== '' ? e($farmLabel) : 'Locate your farm' ?></p>
        </div>
        <div class="list-hero-actions">
            <button class="btn btn-gold btn-sm" type="button" data-map-locate>Use My Current Location</button>
            <button class="btn btn-ghost btn-sm" type="button" data-map-manual>Choose Location Manually</button>
            <?php if (fe_can('fields', 'edit')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('fields-planner')) ?>">Fields planner</a>
            <?php endif; ?>
            <?php if (!$hasCoords && fe_can('farms', 'create') && !$editFarmUrl): ?>
                <a class="btn btn-gold btn-sm" href="<?= e(url('module/farms/create')) ?>">+ Add Farm</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="map-layout map-layout-simple">
        <div class="map-main">
            <div class="map-toolbar" data-manual-panel hidden>
                <form class="search-form" id="map-search" onsubmit="return false;" role="search">
                    <label class="sr-only" for="map-q">Search place</label>
                    <input type="search" id="map-q" placeholder="Search farm address or place…" autocomplete="off">
                    <button class="btn btn-ghost btn-sm" type="submit">Find</button>
                </form>
                <div id="map-search-results" class="gps-search-results" hidden></div>
                <p class="muted" id="map-search-status">Search, tap the map, or drag the marker.</p>
            </div>
            <div id="farm-map" class="map-canvas map-canvas-tall"></div>
            <p class="muted" id="map-gps-status" aria-live="polite">Requesting device location…</p>
            <?php if ($canEditFarm): ?>
                <form method="post" action="<?= e(url('map/save-location')) ?>" class="map-save-bar" id="map-save-form" hidden>
                    <?= fe_csrf_field() ?>
                    <input type="hidden" name="farm_id" value="<?= (int) $activeFarm['id'] ?>">
                    <input type="hidden" name="latitude" id="map-save-lat" value="">
                    <input type="hidden" name="longitude" id="map-save-lng" value="">
                    <input type="hidden" name="location_label" id="map-save-label" value="">
                    <button class="btn btn-gold" type="submit">Save Location</button>
                    <button class="btn btn-ghost" type="button" data-map-cancel-save>Cancel</button>
                </form>
            <?php elseif ($editFarmUrl): ?>
                <p class="map-save-hint"><a class="btn btn-gold btn-sm" href="<?= e($editFarmUrl) ?>">Open farm to save location</a></p>
            <?php endif; ?>
            <div class="map-empty" data-map-empty <?= $hasCoords ? 'hidden' : '' ?>>
                <h4>No farm location saved</h4>
                <p class="muted">Allow GPS or choose a location manually, then save it to your farm.</p>
                <?php if ($editFarmUrl): ?>
                    <a class="btn btn-gold btn-sm" href="<?= e($editFarmUrl) ?>">Edit farm</a>
                <?php elseif (fe_can('farms', 'create')): ?>
                    <a class="btn btn-gold btn-sm" href="<?= e(url('module/farms/create')) ?>">+ Add Farm</a>
                <?php endif; ?>
            </div>
        </div>
        <aside class="map-side map-side-slim">
            <h4>Layers</h4>
            <div class="map-layer-list">
                <label class="map-layer"><input type="checkbox" data-map-layer="farms" checked><span>Farms</span></label>
                <label class="map-layer"><input type="checkbox" data-map-layer="fields" checked><span>Fields</span></label>
                <label class="map-layer"><input type="checkbox" data-map-layer="me" checked><span>My location</span></label>
            </div>
            <h4>Status</h4>
            <div class="map-legend">
                <span class="map-legend-item"><i class="lg lg-active"></i> Active</span>
                <span class="map-legend-item"><i class="lg lg-fallow"></i> Fallow</span>
                <span class="map-legend-item"><i class="lg lg-reserved"></i> Reserved</span>
            </div>
            <h4>Fields</h4>
            <div class="map-field-list" data-field-list></div>
        </aside>
    </div>
</section>
<script>
window.IFMS_MAP = {
    farms: <?= json_encode(array_values(array_filter($farms ?? []))) ?>,
    fields: <?= json_encode($mapFields) ?>,
    autoLocate: true,
    canSave: <?= $canEditFarm ? 'true' : 'false' ?>,
    activeFarmId: <?= (int) ($activeFarm['id'] ?? 0) ?>
};
</script>
