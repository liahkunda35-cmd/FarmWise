<?php require dirname(__DIR__) . '/partials/frontend.php'; ?>
<section class="panel">
    <header><h3><?= e($id ? 'Edit' : 'New') ?> <?= e($def['singular']) ?></h3></header>
    <form method="post" action="<?= e(url('module/'.$module.'/'.($id?'update/'.$id:'store'))) ?>" class="stack-form" data-unsaved-warn data-module="<?= e($module) ?>">
        <?= Csrf::field() ?>
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
                        if (!$value && !empty($field['auto_farm']) && Auth::farmId()) {
                            $value = Auth::farmId();
                        }
                        // Query-string preselect (create forms)
                        if (!$id && $value === '' && input($name) !== null && input($name) !== '') {
                            $value = input($name);
                        }
                        $err = $errors[$name] ?? null;
                        if (!empty($field['auto_farm']) && !Auth::isAdmin() && Auth::farmId()) {
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
                                $opts = [];
                                $optMeta = []; // id => ['parent' => ...]
                                if (($field['table']??'') === 'farms') {
                                    $opts = farm_options();
                                } else {
                                    $where = null; $params = [];
                                    $relDef = module_def($field['table']);
                                    $relHasFarm = $relDef && isset($relDef['fields']['farm_id']);
                                    if (!empty($def['farm_scoped']) && Auth::farmId() && $relHasFarm) {
                                        $where = 'farm_id = ?';
                                        $params = [Auth::farmId()];
                                    }
                                    $labelCol = $field['label_col'] ?? 'name';
                                    if (($field['table'] ?? '') === 'plantings') {
                                        $sql = 'SELECT p.id, p.field_id, p.season, c.name AS crop_name, c.variety, f.name AS field_name FROM plantings p LEFT JOIN crop_varieties c ON c.id=p.crop_variety_id LEFT JOIN fields f ON f.id=p.field_id';
                                        if ($where) { $sql .= ' WHERE p.'.$where; }
                                        $sql .= ' ORDER BY p.id DESC';
                                        $prows = db()->select($sql, $params);
                                        foreach ($prows as $r) {
                                            $opts[$r['id']] = fe_planting_label($r);
                                            $optMeta[$r['id']] = ['parent' => (string)($r['field_id'] ?? '')];
                                        }
                                    } elseif (($field['table'] ?? '') === 'harvests') {
                                        $hrows = db()->select('SELECT id, planting_id, quantity, unit, harvest_date FROM harvests'.($where ? ' WHERE '.$where : '').' ORDER BY id DESC LIMIT 200', $params);
                                        foreach ($hrows as $r) {
                                            $opts[$r['id']] = fe_rel_label('harvests', $r['id']);
                                            $optMeta[$r['id']] = ['parent' => (string)($r['planting_id'] ?? '')];
                                        }
                                    } elseif ($labelCol === 'id') {
                                        $rows = db()->select('SELECT id FROM '.$field['table'].($where ? ' WHERE '.$where : ''), $params);
                                        foreach ($rows as $r) { $opts[$r['id']] = fe_rel_label($field['table'], $r['id']); }
                                    } else {
                                        $opts = rel_options($field['table'], $labelCol, $where, $params);
                                    }
                                    // Parent attrs for dependent selects
                                    if (($field['table'] ?? '') === 'irrigation_systems') {
                                        $irows = db()->select('SELECT id, field_id FROM irrigation_systems'.($where ? ' WHERE '.$where : ''), $params);
                                        foreach ($irows as $r) { $optMeta[$r['id']] = ['parent' => (string)($r['field_id'] ?? ''), 'field' => (string)($r['field_id'] ?? '')]; }
                                    }
                                    if (($field['table'] ?? '') === 'animals') {
                                        $arows = db()->select('SELECT id, breed_id FROM animals'.($where ? ' WHERE '.$where : ''), $params);
                                        foreach ($arows as $r) { $optMeta[$r['id']] = ['breed' => (string)($r['breed_id'] ?? '')]; }
                                    }
                                }
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
                <?php if (Auth::can('inventory_items', 'view')): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('module/inventory_items?q=seed')) ?>">Open seed inventory →</a>
                <?php endif; ?>
            </fieldset>
        <?php endif; ?>

        <?php
        $productFields = ['product_name', 'feed_name', 'treatment_name'];
        $hasProduct = false;
        foreach ($productFields as $pf) { if (isset($def['fields'][$pf])) { $hasProduct = $pf; break; } }
        ?>
        <?php if ($hasProduct && Auth::can('inventory_items', 'view') && table_exists('inventory_items')): ?>
            <fieldset class="form-section">
                <legend>Inventory link</legend>
                <label class="wide">
                    <span class="field-label">Pick from inventory (optional)</span>
                    <select data-inventory-picker data-target-name="<?= e($hasProduct) ?>">
                        <option value="">— Use product name as typed —</option>
                        <?php
                        $invWhere = Auth::farmId() ? 'WHERE farm_id = ?' : '';
                        $invParams = Auth::farmId() ? [Auth::farmId()] : [];
                        $items = db()->select('SELECT id, name, unit, quantity, reorder_level, category FROM inventory_items '.$invWhere.' ORDER BY name LIMIT 300', $invParams);
                        foreach ($items as $it):
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
        <?php if ($hasGps): ?>
            <fieldset class="form-section">
                <legend>Location</legend>
                <p class="muted">Set a pin, search a place, or use your GPS. <?= $module === 'fields' ? 'Draw a boundary to fill size (ha).' : '' ?></p>
                <div class="search-form gps-toolbar">
                    <label class="sr-only" for="gps-q">Search location</label>
                    <input type="search" id="gps-q" placeholder="Search location" autocomplete="off">
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
