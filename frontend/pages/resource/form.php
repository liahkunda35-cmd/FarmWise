<?php fe_component('partials/frontend'); ?>
<section class="panel">
    <header><h3><?= e($id ? 'Edit' : 'New') ?> <?= e($def['singular']) ?></h3></header>
    <form method="post" action="<?= e(url('module/'.$module.'/'.($id?'update/'.$id:'store'))) ?>" class="stack-form" data-unsaved-warn data-module="<?= e($module) ?>" data-validate-form novalidate>
        <?= fe_csrf_field() ?>
        <?php
        $sections = [
            'Basic' => [],
            'Schedule' => [],
            'Cost' => [],
            'Notes' => [],
        ];
        $scheduleKeys = ['date', 'scheduled', 'due', 'period', 'planting_date', 'expected', 'harvest', 'actual', 'forecast'];
        $costKeys = ['cost', 'amount', 'price', 'total', 'pay', 'balance', 'principal', 'quantity', 'unit', 'dosage'];
        $noteKeys = ['note', 'description', 'observation', 'message', 'boundary'];
        foreach ($def['fields'] as $name => $field) {
            $n = strtolower($name . ' ' . ($field['label'] ?? ''));
            $bucket = 'Basic';
            foreach ($noteKeys as $k) { if (str_contains($n, $k)) { $bucket = 'Notes'; break; } }
            foreach ($costKeys as $k) { if (str_contains($n, $k)) { $bucket = 'Cost'; break; } }
            foreach ($scheduleKeys as $k) { if (str_contains($n, $k)) { $bucket = 'Schedule'; break; } }
            $sections[$bucket][$name] = $field;
        }
        // Drop empty sections
        $sections = array_filter($sections);
        ?>

        <?php foreach ($sections as $sectionLabel => $fields): ?>
            <fieldset class="form-section">
                <legend><?= e($sectionLabel) ?></legend>
                <div class="form-grid">
                    <?php foreach ($fields as $name => $field):
                        $value = old($name, $row[$name] ?? '');
                        if ($name === 'boundary_notes') {
                            // Handled in Location section (GeoJSON line kept in hidden field)
                            continue;
                        }
                        // Farm location wizard / map picker own these fields
                        if (!empty($field['hide_in_form']) || in_array($name, ['latitude', 'longitude', 'location', 'location_label', 'location_precision'], true)) {
                            if (!$id && ($value === '' || $value === null) && input($name) !== null && input($name) !== '') {
                                $value = input($name);
                            }
                            if ($value !== '' && $value !== null) {
                                echo '<input type="hidden" name="'.e($name).'" id="field-'.e($name).'" value="'.e((string)$value).'"'.(in_array($name, ['latitude', 'longitude', 'location', 'location_label', 'location_precision'], true) ? ' data-loc-field="'.e($name).'"' : '').'>';
                            }
                            continue;
                        }
                        if (!$value && !empty($field['auto_farm']) && fe_farm_id()) {
                            $value = fe_farm_id();
                        }
                        // Query-string preselect (create forms)
                        if (!$id && $value === '' && input($name) !== null && input($name) !== '') {
                            $value = input($name);
                        }
                        // Workflow: ?employee_id= → assigned_to on new tasks
                        if (!$id && $name === 'assigned_to' && ($value === '' || $value === null) && input('employee_id')) {
                            $value = input('employee_id');
                        }
                        $err = $errors[$name] ?? null;
                        if (!empty($field['auto_farm']) && !fe_is_admin() && fe_farm_id()) {
                            echo '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';
                            continue;
                        }
                        $required = in_array('required', $field['rules'] ?? [], true);
                        $unitSuffix = '';
                        if (preg_match('/\(([^)]+)\)/', (string)($field['label'] ?? ''), $um)) {
                            $unitSuffix = $um[1];
                        } elseif (in_array($name, ['quantity', 'cost', 'amount', 'total', 'size_ha', 'area_ha'], true)) {
                            $unitSuffix = $name === 'size_ha' || $name === 'area_ha' ? 'ha' : '';
                        }
                    ?>
                        <label class="<?= ($field['type']??'')==='textarea' ? 'wide' : '' ?>" data-field="<?= e($name) ?>">
                            <span class="field-label"><?= e($field['label']) ?><?php if ($required): ?> <em class="req" title="Required">*</em><?php endif; ?></span>
                            <?php if (($field['type']??'') === 'textarea'): ?>
                                <textarea name="<?= e($name) ?>" id="field-<?= e($name) ?>" <?= $required ? 'required' : '' ?>><?= e($value) ?></textarea>
                            <?php elseif (($field['type']??'') === 'select'): ?>
                                <select name="<?= e($name) ?>" id="field-<?= e($name) ?>" <?= $required ? 'required' : '' ?>>
                                    <option value="">Select…</option>
                                    <?php foreach ($field['options'] as $k=>$l): ?>
                                        <option value="<?= e($k) ?>" <?= (string)$value===(string)$k?'selected':'' ?>><?= e($l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif (($field['type']??'') === 'rel'):
                                $built = fe_relation_choices($field, $def);
                                $opts = $built['options'];
                                $optMeta = $built['meta'];
                                $dependsOn = null;
                                if ($name === 'planting_id' && isset($def['fields']['field_id'])) {
                                    $dependsOn = 'field_id';
                                }
                            ?>
                                <select name="<?= e($name) ?>" id="field-<?= e($name) ?>"
                                    <?= $required ? 'required' : '' ?>
                                    <?= $dependsOn ? 'data-depends-on="'.e($dependsOn).'" data-depends-attr="data-parent"' : '' ?>
                                    <?= ($field['table'] ?? '') === 'irrigation_systems' ? 'data-prefill-field="field_id" data-prefill-attr="data-field"' : '' ?>
                                    <?= ($field['table'] ?? '') === 'animals' ? 'data-prefill-field="breed_id" data-prefill-attr="data-breed"' : '' ?>
                                >
                                    <option value="">Select…</option>
                                    <?php foreach ($opts as $k=>$l):
                                        $meta = $optMeta[$k] ?? [];
                                    ?>
                                        <option value="<?= e($k) ?>"
                                            <?= (string)$value===(string)$k?'selected':'' ?>
                                            <?= isset($meta['parent']) ? 'data-parent="'.e($meta['parent']).'"' : '' ?>
                                            <?= isset($meta['field']) ? 'data-field="'.e($meta['field']).'"' : '' ?>
                                            <?= isset($meta['breed']) ? 'data-breed="'.e($meta['breed']).'"' : '' ?>
                                        ><?= e($l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <span class="<?= $unitSuffix ? 'input-with-suffix' : '' ?>">
                                    <input
                                        type="<?= e(in_array($field['type']??'text', ['email','number','date','tel']) ? $field['type'] : 'text') ?>"
                                        name="<?= e($name) ?>"
                                        id="<?= e($name==='latitude' ? 'field-lat' : ($name==='longitude' ? 'field-lng' : 'field-'.$name)) ?>"
                                        value="<?= e($value) ?>"
                                        <?= $required ? 'required' : '' ?>
                                        <?= isset($field['step']) ? 'step="'.e($field['step']).'"' : '' ?>
                                        <?= isset($field['min']) ? 'min="'.e($field['min']).'"' : '' ?>
                                        <?= isset($field['max']) ? 'max="'.e($field['max']).'"' : '' ?>
                                        <?= isset($field['maxlength']) ? 'maxlength="'.e($field['maxlength']).'"' : '' ?>
                                        <?= ($field['type'] ?? '') === 'tel' ? 'inputmode="tel" data-validate="'.($required ? 'phone' : 'phone-optional').'"' : '' ?>
                                        <?= ($field['type'] ?? '') === 'email' ? 'data-validate="'.($required ? 'email' : 'email-optional').'"' : '' ?>
                                    >
                                    <?php if ($unitSuffix): ?><span class="suffix"><?= e($unitSuffix) ?></span><?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($err): ?><small class="field-error" role="alert"><?= e($err) ?></small><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>

        <?php if ($module === 'plantings'): ?>
            <fieldset class="form-section">
                <legend>Seed &amp; seedlings</legend>
                <p class="muted">Track seed quantity above, and open Inventory for seed stock.</p>
                <?php if (fe_can('inventory_items', 'view')): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('module/inventory_items?q=seed')) ?>">Open seed inventory →</a>
                <?php endif; ?>
            </fieldset>
        <?php endif; ?>

        <?php
        $productFields = ['product_name', 'feed_name', 'treatment_name'];
        $hasProduct = false;
        foreach ($productFields as $pf) { if (isset($def['fields'][$pf])) { $hasProduct = $pf; break; } }
        ?>
        <?php $inventoryChoices = $hasProduct ? fe_inventory_choices() : null; ?>
        <?php if ($hasProduct && is_array($inventoryChoices)): ?>
            <fieldset class="form-section">
                <legend>Inventory link</legend>
                <label class="wide">
                    <span class="field-label">Pick from inventory (optional)</span>
                    <select data-inventory-picker data-target-name="<?= e($hasProduct) ?>">
                        <option value="">— Use product name as typed —</option>
                        <?php
                        foreach ($inventoryChoices as $it):
                            $low = ($it['reorder_level'] !== null && (float)$it['quantity'] <= (float)$it['reorder_level']);
                        ?>
                            <option value="<?= e($it['name']) ?>"
                                data-unit="<?= e($it['unit'] ?? '') ?>"
                                data-stock="<?= e($it['quantity'] ?? 0) ?>"
                                data-low="<?= $low ? '1' : '0' ?>">
                                <?= e($it['name']) ?> — <?= e(($it['quantity'] ?? 0).' '.($it['unit'] ?? '')) ?><?= $low ? ' ⚠ low' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="muted" data-inventory-hint></small>
                </label>
            </fieldset>
        <?php endif; ?>

        <?php $hasGps = isset($def['fields']['latitude']) && isset($def['fields']['longitude']); ?>
        <?php if ($hasGps && $module === 'farms'): ?>
            <?php
            $prec = old('location_precision', $row['location_precision'] ?? 'none');
            $hasCoords = old('latitude', $row['latitude'] ?? '') !== '' && old('longitude', $row['longitude'] ?? '') !== '';
            ?>
            <fieldset class="form-section farm-location-wizard" data-farm-location-wizard>
                <legend>Where is this farm?</legend>
                <p class="farm-loc-lede">You do <strong>not</strong> need to know map numbers. Tell us the town or village, then find it on the map.</p>

                <ol class="farm-loc-steps">
                    <li><span class="farm-loc-step-num">1</span> Fill in country, province, district and town above.</li>
                    <li><span class="farm-loc-step-num">2</span> Tap <em>Find on map</em> to see matching places.</li>
                    <li><span class="farm-loc-step-num">3</span> Choose the right place, or drag the pin closer to the farm.</li>
                </ol>

                <div class="farm-loc-actions">
                    <button class="btn btn-gold" type="button" data-loc-find>
                        <i data-lucide="search"></i> Find on map
                    </button>
                    <button class="btn btn-ghost" type="button" data-loc-gps data-use-my-location>
                        <i data-lucide="navigation"></i> Use my phone location
                    </button>
                </div>
                <p class="muted farm-loc-gps-hint">“Use my phone location” asks permission first. Only use it when you are standing at the farm.</p>

                <div id="gps-search-results" class="gps-search-results" hidden></div>

                <div class="farm-loc-status-row" aria-live="polite">
                    <span class="loc-badge loc-badge-<?= e($prec === 'confirmed' ? 'ok' : ($prec === 'approximate' ? 'warn' : 'muted')) ?>" data-loc-badge>
                        <?php if ($prec === 'confirmed'): ?>
                            Exact farm pin saved
                        <?php elseif ($prec === 'approximate'): ?>
                            Town / village location (approximate — not the exact farm gate)
                        <?php else: ?>
                            Location not set yet — you can still save the farm
                        <?php endif; ?>
                    </span>
                </div>
                <p class="muted" id="gps-status" data-loc-status></p>
                <p class="farm-loc-label muted" data-loc-label-text><?= e(old('location_label', $row['location_label'] ?? '')) ?></p>

                <div id="gps-map" class="map-canvas map-canvas-farm" data-farm-map="picker"
                     data-lat-input="latitude" data-lng-input="longitude"
                     data-precision-input="location_precision"
                     data-label-input="location_label"
                     data-mode="farm-wizard"></div>

                <details class="farm-loc-advanced">
                    <summary>Show map numbers (for technicians)</summary>
                    <p class="muted">Latitude and longitude are filled automatically. Change them only if you know what they mean.</p>
                    <div class="form-grid">
                        <label>Latitude <input type="text" data-loc-show-lat readonly value="<?= e(old('latitude', $row['latitude'] ?? '')) ?>"></label>
                        <label>Longitude <input type="text" data-loc-show-lng readonly value="<?= e(old('longitude', $row['longitude'] ?? '')) ?>"></label>
                    </div>
                </details>
            </fieldset>
        <?php elseif ($hasGps): ?>
            <fieldset class="form-section">
                <legend>Location on the map</legend>
                <p class="muted">Search a place, tap the map to drop a pin, or use GPS. <?= $module === 'fields' ? 'You can also draw a field boundary.' : '' ?></p>
                <div class="search-form gps-toolbar">
                    <label class="sr-only" for="gps-q">Search place</label>
                    <input type="search" id="gps-q" placeholder="Town, village or landmark" autocomplete="off">
                    <button class="btn btn-ghost btn-sm" type="button" id="gps-search">Search</button>
                    <button class="btn btn-ghost btn-sm" type="button" id="gps-locate" data-use-my-location>Use my location</button>
                </div>
                <div id="gps-search-results" class="gps-search-results" hidden></div>
                <div id="gps-map" class="map-canvas map-canvas-sm" data-farm-map="picker"
                     data-lat-input="latitude" data-lng-input="longitude"
                     <?= $module === 'fields' ? 'data-boundary-input="boundary_notes" data-area-input="size_hectares"' : '' ?>></div>
                <p class="muted" id="gps-status" aria-live="polite"></p>
                <?php if ($module === 'fields'): ?>
                    <div class="tool-row" style="margin-top:.6rem">
                        <button class="btn btn-ghost btn-sm" type="button" data-draw-boundary>Draw boundary</button>
                        <button class="btn btn-ghost btn-sm" type="button" data-walk-boundary>Record by walking</button>
                        <button class="btn btn-ghost btn-sm" type="button" data-clear-boundary>Clear boundary</button>
                    </div>
                    <p class="muted" data-boundary-area></p>
                    <textarea name="boundary_notes" id="field-boundary_notes" class="sr-only" aria-hidden="true"><?= e(old('boundary_notes', $row['boundary_notes'] ?? '')) ?></textarea>
                <?php endif; ?>
            </fieldset>
        <?php endif; ?>

        <div class="form-actions">
            <button class="btn btn-gold" type="submit">Save</button>
            <a class="btn btn-ghost" href="<?= e(url('module/'.$module)) ?>">Cancel</a>
        </div>
    </form>
</section>
