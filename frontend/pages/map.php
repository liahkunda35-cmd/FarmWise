<?php
fe_component('partials/frontend');
$mapLayers = fe_map_layers($fields ?? [], $farms ?? []);
$mapFields = $mapLayers['fields'];
$hasCoords = $mapLayers['has_coords'];
$activeFarm = $mapLayers['active_farm'];
$editFarmUrl = $mapLayers['edit_url'];
?>
<section class="panel map-page">
    <header class="panel-tools">
        <div>
            <h3>Farm Map</h3>
            <p class="muted">Boundaries, farms, irrigation and scouting at field centres.</p>
        </div>
        <form class="search-form" id="map-search" onsubmit="return false;" role="search">
            <label class="sr-only" for="map-q">Search place</label>
            <input type="search" id="map-q" placeholder="Search a place…" autocomplete="off">
            <button class="btn btn-ghost btn-sm" type="submit">Find</button>
            <button class="btn btn-ghost btn-sm" type="button" data-map-locate>My location</button>
        </form>
    </header>

    <div class="map-layout">
        <div class="map-main">
            <div id="map-search-results" class="gps-search-results" hidden></div>
            <div id="farm-map" class="map-canvas map-canvas-tall"></div>
            <p class="muted" id="map-gps-status" aria-live="polite">GPS: optional blue pin for your location.</p>
            <p class="muted" id="map-search-status">Search needs 3+ characters. Pick a result from the list.</p>
            <div class="map-empty" data-map-empty <?= $hasCoords ? 'hidden' : '' ?>>
                <h4>No farm locations yet</h4>
                <p class="muted">Centred on Zambia until you set coordinates. Add a pin on your farm or field.</p>
                <?php if ($editFarmUrl): ?>
                    <a class="btn btn-gold btn-sm" href="<?= e($editFarmUrl) ?>">Set this farm's location</a>
                <?php elseif (fe_can('farms', 'create')): ?>
                    <a class="btn btn-gold btn-sm" href="<?= e(url('module/farms/create')) ?>">Create a farm</a>
                <?php endif; ?>
            </div>
        </div>
        <aside class="map-side">
            <h4>Layers</h4>
            <label class="map-layer"><input type="checkbox" data-map-layer="farms" checked> Farms</label>
            <label class="map-layer"><input type="checkbox" data-map-layer="fields" checked> Fields</label>
            <label class="map-layer"><input type="checkbox" data-map-layer="irrigation"> Irrigation systems</label>
            <label class="map-layer"><input type="checkbox" data-map-layer="scouting"> Pest scouting</label>
            <label class="map-layer"><input type="checkbox" data-map-layer="me" checked> My location</label>
            <div class="map-legend">
                <span><i class="lg lg-active"></i> Active</span>
                <span><i class="lg lg-fallow"></i> Fallow</span>
                <span><i class="lg lg-reserved"></i> Reserved</span>
            </div>
            <h4>Fields</h4>
            <div class="map-field-list" data-field-list></div>
            <p class="muted" style="font-size:.78rem;margin-top:.8rem">Warehouses and water sources have no coordinates yet — listed in pending notes.</p>
        </aside>
    </div>
</section>
<script>
window.IFMS_MAP = {
    farms: <?= json_encode(array_values(array_filter($farms ?? []))) ?>,
    fields: <?= json_encode($mapFields) ?>
};
</script>
