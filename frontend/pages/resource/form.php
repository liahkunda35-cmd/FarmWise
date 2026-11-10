<?php fe_component('partials/frontend'); ?>
<section class="panel form-panel">
    <header class="form-panel-header">
        <div>
            <h3><?= e($id ? 'Edit' : 'Add') ?> <?= e($def['singular']) ?></h3>
            <?php if (!empty($def['description'])): ?>
                <p class="muted"><?= e($def['description']) ?></p>
            <?php elseif ($module !== 'farms'): ?>
                <p class="muted"><?= $id ? 'Update this record and save your changes.' : 'Complete the fields below, then save.' ?></p>
            <?php endif; ?>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('module/'.$module)) ?>">Back to list</a>
    </header>
    <form method="post" action="<?= e(url('module/'.$module.'/'.($id?'update/'.$id:'store'))) ?>" class="stack-form" data-unsaved-warn data-module="<?= e($module) ?>" data-validate-form novalidate>
        <?= fe_csrf_field() ?>
        <?php
        $sections = [
            'Basic' => [],
            'Schedule' => [],
            'Cost' => [],
            'Notes' => [],
            'More details' => [],
        ];
        $scheduleKeys = ['date', 'scheduled', 'due', 'period', 'planting_date', 'expected', 'harvest', 'actual', 'forecast'];
        $costKeys = ['cost', 'amount', 'price', 'total', 'pay', 'balance', 'principal', 'quantity', 'unit', 'dosage'];
        $noteKeys = ['note', 'description', 'observation', 'message', 'boundary'];
        foreach ($def['fields'] as $name => $field) {
            if (!empty($field['optional_details'])) {
                $sections['More details'][$name] = $field;
                continue;
            }
            $n = strtolower($name . ' ' . ($field['label'] ?? ''));
            $bucket = 'Basic';
            foreach ($noteKeys as $k) { if (str_contains($n, $k)) { $bucket = 'Notes'; break; } }
            foreach ($costKeys as $k) { if (str_contains($n, $k)) { $bucket = 'Cost'; break; } }
            foreach ($scheduleKeys as $k) { if (str_contains($n, $k)) { $bucket = 'Schedule'; break; } }
            $sections[$bucket][$name] = $field;
        }
        // Drop empty sections
        $sections = array_filter($sections);
        $farmLocOwned = ['latitude', 'longitude', 'location', 'location_label', 'location_precision', 'boundary_geojson'];
        ?>

        <?php foreach ($sections as $sectionLabel => $fields): ?>
            <?php
            $isOptionalBlock = $sectionLabel === 'More details';
            if ($isOptionalBlock): ?>
                <details class="form-optional-details">
                    <summary>More details (optional)</summary>
            <?php endif; ?>
            <fieldset class="form-section<?= $isOptionalBlock ? ' form-section-optional' : '' ?>">
                <?php if (!$isOptionalBlock): ?><legend><?= e($sectionLabel) ?></legend><?php endif; ?>
                <div class="form-grid">
                    <?php foreach ($fields as $name => $field):
                        $value = old($name, $row[$name] ?? '');
                        if ($name === 'boundary_notes') {
                            // Handled in Location section (GeoJSON line kept in hidden field)
                            continue;
                        }
                        // Farm location wizard / map picker own these fields
                        if (!empty($field['hide_in_form']) || in_array($name, $farmLocOwned, true)) {
                            if ($module === 'farms' && in_array($name, $farmLocOwned, true)) {
                                continue; // rendered once in the location wizard
                            }
                            if (!$id && ($value === '' || $value === null) && input($name) !== null && input($name) !== '') {
                                $value = input($name);
                            }
                            if ($value !== '' && $value !== null) {
                                echo '<input type="hidden" name="'.e($name).'" id="field-'.e($name).'" value="'.e((string)$value).'"'.(in_array($name, $farmLocOwned, true) ? ' data-loc-field="'.e($name).'"' : '').'>';
                            }
                            continue;
                        }
                        if (!$value && !empty($field['auto_farm'])) {
                            if (fe_farm_id()) {
                                $value = fe_farm_id();
                            } elseif (function_exists('farm_options')) {
                                $onlyFarms = farm_options();
                                if (count($onlyFarms) === 1) {
                                    $value = (string) array_key_first($onlyFarms);
                                }
                            }
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
                                    <?php
                                    $opts = $field['options'] ?? [];
                                    if ($value !== '' && $value !== null && !array_key_exists((string) $value, $opts)) {
                                        $opts = [(string) $value => (string) $value] + $opts;
                                    }
                                    foreach ($opts as $k=>$l): ?>
                                        <option value="<?= e($k) ?>" <?= (string)$value===(string)$k?'selected':'' ?>><?= e($l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif (($field['type']??'') === 'rel'):
                                $built = fe_relation_choices($field, $def);
                                $opts = $built['options'];
                                $optMeta = $built['meta'];
                                // Keep historically selected field even if status filtered out
                                if (($field['table'] ?? '') === 'fields' && $value !== '' && $value !== null && !isset($opts[(string) $value]) && !isset($opts[(int) $value])) {
                                    $hist = db()->find('fields', (int) $value);
                                    if ($hist) {
                                        $opts[(string) $hist['id']] = (string) $hist['name'];
                                        $optMeta[(string) $hist['id']] = [
                                            'parent' => (string) ($hist['farm_id'] ?? ''),
                                            'farm' => (string) ($hist['farm_id'] ?? ''),
                                            'status' => (string) ($hist['status'] ?? ''),
                                        ];
                                    }
                                }
                                // Guarantee farm fields appear even if active-farm session was empty at first paint
                                if ((($field['table'] ?? '') === 'fields' || $name === 'field_id') && !$opts) {
                                    $farmForFields = (int) (fe_farm_id() ?: 0);
                                    if ($farmForFields <= 0) {
                                        $only = farm_options();
                                        if (count($only) === 1) {
                                            $farmForFields = (int) array_key_first($only);
                                        }
                                    }
                                    if ($farmForFields > 0 && table_exists('fields')) {
                                        foreach (db()->select(
                                            "SELECT id, name, farm_id, status FROM fields WHERE farm_id = ? AND COALESCE(status, 'active') IN ('active','fallow','reserved') ORDER BY name",
                                            [$farmForFields]
                                        ) as $fr) {
                                            $opts[(string) $fr['id']] = (string) $fr['name'];
                                            $optMeta[(string) $fr['id']] = [
                                                'parent' => (string) $fr['farm_id'],
                                                'farm' => (string) $fr['farm_id'],
                                                'status' => (string) ($fr['status'] ?? 'active'),
                                            ];
                                        }
                                    }
                                }
                                $dependsOn = null;
                                $dependsAttr = 'data-parent';
                                if ($name === 'planting_id' && isset($def['fields']['field_id'])) {
                                    $dependsOn = 'field_id';
                                }
                                if ($name === 'field_id' && isset($def['fields']['farm_id'])) {
                                    $dependsOn = 'farm_id';
                                    $dependsAttr = 'data-farm';
                                }
                                $isFieldSelect = ($field['table'] ?? '') === 'fields' || $name === 'field_id';
                            ?>
                                <select name="<?= e($name) ?>" id="field-<?= e($name) ?>"
                                    <?= $required ? 'required' : '' ?>
                                    <?= (($field['table'] ?? '') === 'farms' || $isFieldSelect) ? 'data-searchable="1"' : '' ?>
                                    <?= $dependsOn ? 'data-depends-on="'.e($dependsOn).'" data-depends-attr="'.e($dependsAttr).'"' : '' ?>
                                    <?= $isFieldSelect ? 'data-fields-select="1" data-fields-url="'.e(url('fields-options')).'"' : '' ?>
                                    <?= ($field['table'] ?? '') === 'irrigation_systems' ? 'data-prefill-field="field_id" data-prefill-attr="data-field"' : '' ?>
                                    <?= ($field['table'] ?? '') === 'animals' ? 'data-prefill-field="breed_id" data-prefill-attr="data-breed"' : '' ?>
                                    <?= ($field['table'] ?? '') === 'breeds' ? 'data-prefill-field="species" data-prefill-attr="data-species"' : '' ?>
                                >
                                    <option value="">Select…</option>
                                    <?php foreach ($opts as $k=>$l):
                                        $meta = $optMeta[$k] ?? [];
                                    ?>
                                        <option value="<?= e($k) ?>"
                                            <?= (string)$value===(string)$k?'selected':'' ?>
                                            <?= isset($meta['parent']) ? 'data-parent="'.e($meta['parent']).'"' : '' ?>
                                            <?= isset($meta['farm']) ? 'data-farm="'.e($meta['farm']).'"' : (isset($meta['parent']) && $isFieldSelect ? 'data-farm="'.e($meta['parent']).'"' : '') ?>
                                            <?= isset($meta['field']) ? 'data-field="'.e($meta['field']).'"' : '' ?>
                                            <?= isset($meta['breed']) ? 'data-breed="'.e($meta['breed']).'"' : '' ?>
                                            <?= isset($meta['species']) ? 'data-species="'.e($meta['species']).'"' : '' ?>
                                            <?= isset($meta['rate']) ? 'data-rate="'.e($meta['rate']).'"' : '' ?>
                                            <?= isset($meta['role']) ? 'data-role="'.e($meta['role']).'"' : '' ?>
                                        ><?= e($l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (($field['table'] ?? '') === 'farms' && !$opts): ?>
                                    <small class="field-hint muted">
                                        No farms registered yet.
                                        <a href="<?= e(url('module/farms/create')) ?>">Add a farm</a> first.
                                    </small>
                                <?php endif; ?>
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
                            <?php if (!$err && !empty($field['help'])): ?><small class="field-hint muted"><?= e($field['help']) ?></small><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <?php if ($isOptionalBlock): ?></details><?php endif; ?>
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
            ?>
            <fieldset class="form-section farm-location-wizard" data-farm-location-wizard>
                <legend>Farm location</legend>
                <div class="farm-loc-actions">
                    <button class="btn btn-gold" type="button" data-loc-gps data-use-my-location>Use my location</button>
                    <button class="btn btn-ghost" type="button" data-loc-snip>Select area</button>
                    <button class="btn btn-ghost btn-sm" type="button" data-clear-boundary>Clear area</button>
                </div>
                <div class="search-form gps-toolbar" data-manual-location>
                    <label class="sr-only" for="gps-q">Search place</label>
                    <input type="search" id="gps-q" placeholder="Search town, village or address…" autocomplete="off">
                    <button class="btn btn-ghost btn-sm" type="button" data-loc-find>Find</button>
                </div>
                <div id="gps-search-results" class="gps-search-results" hidden></div>
                <div class="farm-loc-status-row" aria-live="polite">
                    <span class="loc-badge loc-badge-<?= e($prec === 'confirmed' ? 'ok' : ($prec === 'approximate' ? 'warn' : 'muted')) ?>" data-loc-badge>
                        <?php if ($prec === 'confirmed'): ?>
                            Pin set
                        <?php elseif ($prec === 'approximate'): ?>
                            Approximate place
                        <?php else: ?>
                            Waiting for GPS or selection…
                        <?php endif; ?>
                    </span>
                </div>
                <p class="muted" id="gps-status" data-loc-status>Getting your current location…</p>
                <p class="farm-loc-label muted" data-loc-label-text><?= e(old('location_label', $row['location_label'] ?? '')) ?></p>
                <div class="map-snip-shell">
                    <div class="map-snip-banner" data-snip-banner hidden>Drag to select your farm area, then release</div>
                    <div id="gps-map" class="map-canvas map-canvas-farm" data-farm-map="picker"
                         data-lat-input="latitude" data-lng-input="longitude"
                         data-precision-input="location_precision"
                         data-label-input="location_label"
                         data-boundary-input="boundary_geojson"
                         data-area-input="total_area"
                         data-snip-boundary="1"
                         data-mode="farm-wizard"
                         data-auto-locate="1"></div>
                </div>
                <p class="muted" data-boundary-area></p>
                <input type="hidden" name="latitude" id="field-latitude" value="<?= e(old('latitude', $row['latitude'] ?? '')) ?>" data-loc-field="latitude">
                <input type="hidden" name="longitude" id="field-longitude" value="<?= e(old('longitude', $row['longitude'] ?? '')) ?>" data-loc-field="longitude">
                <input type="hidden" name="location_label" id="field-location_label" value="<?= e(old('location_label', $row['location_label'] ?? '')) ?>" data-loc-field="location_label">
                <input type="hidden" name="location_precision" id="field-location_precision" value="<?= e($prec) ?>" data-loc-field="location_precision">
                <input type="hidden" name="location" id="field-location" value="<?= e(old('location', $row['location'] ?? '')) ?>" data-loc-field="location">
                <input type="hidden" name="boundary_geojson" id="field-boundary_geojson" value="<?= e(old('boundary_geojson', $row['boundary_geojson'] ?? '')) ?>" data-loc-field="boundary_geojson">
            </fieldset>
        <?php elseif ($hasGps): ?>
            <?php
            $mapFarm = fe_active_farm();
            $mapFarmLat = is_array($mapFarm) ? ($mapFarm['latitude'] ?? '') : '';
            $mapFarmLng = is_array($mapFarm) ? ($mapFarm['longitude'] ?? '') : '';
            ?>
            <fieldset class="form-section">
                <legend>Location on the map</legend>
                <div class="farm-loc-actions">
                    <button class="btn btn-gold btn-sm" type="button" data-use-my-location>Use my location</button>
                    <?php if ($module === 'fields'): ?>
                        <button class="btn btn-ghost btn-sm" type="button" data-loc-snip>Select area</button>
                        <button class="btn btn-ghost btn-sm" type="button" data-draw-boundary>Draw corners</button>
                        <button class="btn btn-ghost btn-sm" type="button" data-clear-boundary>Clear</button>
                    <?php endif; ?>
                </div>
                <div class="search-form gps-toolbar">
                    <label class="sr-only" for="gps-q">Search place</label>
                    <input type="search" id="gps-q" placeholder="Search place…" autocomplete="off">
                    <button class="btn btn-ghost btn-sm" type="button" id="gps-search">Find</button>
                </div>
                <div id="gps-search-results" class="gps-search-results" hidden></div>
                <div class="map-snip-shell">
                    <div class="map-snip-banner" data-snip-banner hidden>Drag to select the plot area, then release</div>
                    <div id="gps-map" class="map-canvas map-canvas-farm" data-farm-map="picker"
                         data-lat-input="latitude" data-lng-input="longitude"
                         <?php if ($mapFarmLat !== '' && $mapFarmLng !== ''): ?>
                         data-farm-lat="<?= e((string) $mapFarmLat) ?>"
                         data-farm-lng="<?= e((string) $mapFarmLng) ?>"
                         <?php endif; ?>
                         <?= $module === 'fields' ? 'data-boundary-input="boundary_notes" data-area-input="size_hectares" data-snip-boundary="1"' : '' ?>></div>
                </div>
                <p class="muted" id="gps-status" aria-live="polite"><?= $module === 'fields' ? 'Tap Select area and drag around the plot, or Use my location.' : '' ?></p>
                <?php if ($module === 'fields'): ?>
                    <p class="muted" data-boundary-area></p>
                    <textarea name="boundary_notes" id="field-boundary_notes" class="sr-only" aria-hidden="true"><?= e(old('boundary_notes', $row['boundary_notes'] ?? '')) ?></textarea>
                <?php endif; ?>
            </fieldset>
        <?php endif; ?>

        <?php if ($module === 'employees' && (empty($row['user_id']) || !$id)): ?>
            <fieldset class="form-section" data-employee-login>
                <legend>Login account</legend>
                <p class="muted">Tick this box to create a login. After save, FarmWise shows the full details and an <strong>Open email app</strong> button so you can send email, username, and temporary password to the employee.</p>
                <label class="wide checkbox-row">
                    <input type="checkbox" name="create_login" value="1" <?= old('create_login', '1') === '1' ? 'checked' : '' ?> data-toggle-login-fields>
                    <span>Create login account and prepare email details for employee</span>
                </label>
                <div class="form-grid" data-login-fields <?= old('create_login', '1') === '1' ? '' : 'hidden' ?>>
                    <label>
                        <span class="field-label">Login email</span>
                        <input type="email" name="login_email" value="<?= e(old('login_email', $row['email'] ?? '')) ?>" maxlength="190" placeholder="Uses employee email if blank">
                    </label>
                    <label>
                        <span class="field-label">Username (optional)</span>
                        <input type="text" name="login_username" value="<?= e(old('login_username')) ?>" maxlength="30" placeholder="Auto-generated if blank">
                    </label>
                    <label class="wide">
                        <span class="field-label">Temporary password</span>
                        <input type="text" name="temp_password" value="<?= e(old('temp_password')) ?>" autocomplete="new-password" placeholder="Type the password to share, or leave blank to auto-generate">
                        <small class="muted">Shown on-screen after save (and included in the email you send). Must be changed on first sign-in.</small>
                    </label>
                </div>
            </fieldset>
            <script>
            (function () {
                var box = document.querySelector('[data-toggle-login-fields]');
                var fields = document.querySelector('[data-login-fields]');
                if (!box || !fields) return;
                var sync = function () { fields.hidden = !box.checked; };
                box.addEventListener('change', sync);
                sync();
            })();
            </script>
        <?php endif; ?>

        <?php if ($module === 'payroll'): ?>
            <aside class="payslip-preview" data-payslip-preview hidden>
                <div class="payslip-preview-head">
                    <h4>Pay slip preview</h4>
                    <span class="muted" data-payslip-status>Waiting for employee and period…</span>
                </div>
                <dl class="payslip-preview-grid">
                    <div><dt>Employee</dt><dd data-payslip-employee>—</dd></div>
                    <div><dt>Period</dt><dd data-payslip-period>—</dd></div>
                    <div><dt>Days worked</dt><dd data-payslip-days>—</dd></div>
                    <div><dt>Gross pay</dt><dd data-payslip-gross>—</dd></div>
                    <div><dt>Deductions</dt><dd data-payslip-deductions>—</dd></div>
                    <div><dt>Net pay</dt><dd data-payslip-net class="payslip-net">—</dd></div>
                </dl>
                <p class="muted tiny" data-payslip-note></p>
            </aside>
        <?php endif; ?>

        <div class="form-actions">
            <?php if ($module === 'payroll'): ?>
                <button class="btn btn-gold" type="submit" data-busy-label="Generating pay slip…"><?= $id ? 'Update pay slip' : 'Generate pay slip' ?></button>
            <?php else: ?>
                <button class="btn btn-gold" type="submit"><?= $id ? 'Save changes' : 'Save' ?></button>
            <?php endif; ?>
            <a class="btn btn-ghost" href="<?= e(url('module/'.$module)) ?>">Cancel</a>
        </div>
    </form>
    <?php if ($module === 'payroll'): ?>
        <script>
        (function () {
            var form = document.querySelector('form[data-module="payroll"]');
            if (!form) return;
            var preview = document.querySelector('[data-payslip-preview]');
            var suggestUrl = <?= json_encode(url('module/payroll/suggest')) ?>;
            var emp = form.querySelector('#field-employee_id');
            var start = form.querySelector('#field-period_start');
            var end = form.querySelector('#field-period_end');
            var days = form.querySelector('#field-days_worked');
            var gross = form.querySelector('#field-gross_pay');
            var deductions = form.querySelector('#field-deductions');
            var net = form.querySelector('#field-net_pay');
            var note = form.querySelector('#field-calculation_note');
            var timer = null;
            var money = function (n) {
                if (n === null || n === undefined || n === '' || isNaN(Number(n))) return '—';
                return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            };
            var setText = function (sel, val) {
                var el = preview && preview.querySelector(sel);
                if (el) el.textContent = val;
            };
            var paintLocal = function () {
                if (!preview) return;
                preview.hidden = false;
                var opt = emp && emp.selectedOptions ? emp.selectedOptions[0] : null;
                setText('[data-payslip-employee]', opt && opt.value ? opt.textContent : '—');
                setText('[data-payslip-period]', (start && start.value && end && end.value) ? (start.value + ' → ' + end.value) : '—');
                setText('[data-payslip-days]', days && days.value !== '' ? days.value : '—');
                setText('[data-payslip-gross]', money(gross && gross.value));
                setText('[data-payslip-deductions]', money(deductions && deductions.value ? deductions.value : 0));
                setText('[data-payslip-net]', money(net && net.value));
            };
            var recomputeNet = function () {
                if (!gross || gross.value === '' || isNaN(Number(gross.value))) return;
                var d = deductions && deductions.value !== '' && !isNaN(Number(deductions.value)) ? Number(deductions.value) : 0;
                if (net) net.value = (Number(gross.value) - d).toFixed(2);
                paintLocal();
            };
            var applySuggestion = function (data, fillBlanks) {
                if (!data || !data.ok) {
                    setText('[data-payslip-status]', (data && data.error) || 'Could not calculate yet.');
                    paintLocal();
                    return;
                }
                if (fillBlanks) {
                    if (days && (days.value === '' || days.dataset.autofilled === '1') && data.days_worked !== null && data.days_worked !== undefined) {
                        days.value = data.days_worked;
                        days.dataset.autofilled = '1';
                    }
                    if (gross && (gross.value === '' || gross.dataset.autofilled === '1') && data.gross_pay !== null && data.gross_pay !== undefined) {
                        gross.value = data.gross_pay;
                        gross.dataset.autofilled = '1';
                    }
                    if (note && (note.value.trim() === '' || note.dataset.autofilled === '1') && data.calculation_note) {
                        note.value = data.calculation_note;
                        note.dataset.autofilled = '1';
                    }
                }
                if (net && data.net_pay !== null && data.net_pay !== undefined) {
                    net.value = data.net_pay;
                } else {
                    recomputeNet();
                }
                setText('[data-payslip-status]', 'Ready to generate');
                setText('[data-payslip-note]', data.calculation_note || '');
                paintLocal();
            };
            var fetchSuggest = function (fillBlanks) {
                if (!emp || !emp.value || !start || !start.value || !end || !end.value) {
                    if (preview) {
                        preview.hidden = false;
                        setText('[data-payslip-status]', 'Waiting for employee and period…');
                        paintLocal();
                    }
                    return;
                }
                setText('[data-payslip-status]', 'Calculating…');
                paintLocal();
                var q = new URLSearchParams({
                    employee_id: emp.value,
                    period_start: start.value,
                    period_end: end.value,
                    deductions: (deductions && deductions.value) || '0'
                });
                if (days && days.value !== '' && days.dataset.autofilled !== '1') q.set('days_worked', days.value);
                if (gross && gross.value !== '' && gross.dataset.autofilled !== '1') q.set('gross_pay', gross.value);
                fetch(suggestUrl + '?' + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { applySuggestion(data, fillBlanks); })
                    .catch(function () { setText('[data-payslip-status]', 'Could not reach pay calculator.'); });
            };
            var schedule = function (fillBlanks) {
                clearTimeout(timer);
                timer = setTimeout(function () { fetchSuggest(!!fillBlanks); }, 280);
            };
            [emp, start, end].forEach(function (el) {
                if (!el) return;
                el.addEventListener('change', function () { schedule(true); });
            });
            if (days) days.addEventListener('input', function () { days.dataset.autofilled = '0'; schedule(false); recomputeNet(); });
            if (gross) gross.addEventListener('input', function () { gross.dataset.autofilled = '0'; recomputeNet(); });
            if (deductions) deductions.addEventListener('input', function () { schedule(false); recomputeNet(); });
            if (note) note.addEventListener('input', function () { note.dataset.autofilled = '0'; });
            schedule(true);
        })();
        </script>
    <?php endif; ?>
</section>
