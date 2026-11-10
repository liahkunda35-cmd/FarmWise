<?php
/**
 * Smoke checks for Fields & Plots division + field dropdown options.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
putenv('DATABASE_URL');
$_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = '';

require $root . '/backend/bootstrap.php';

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

Schema::ensureOpsUiSchema(db());

$cols = array_flip(db()->columns('fields'));
check('fields.description column exists', isset($cols['description']));
check('fields.boundary_notes column exists', isset($cols['boundary_notes']));
check('farms.boundary_geojson column exists', isset(array_flip(db()->columns('farms'))['boundary_geojson']));

$nav = require $root . '/backend/config/navigation.php';
$fieldsNav = null;
foreach ($nav as $section) {
    foreach ($section['items'] as $item) {
        if (($item['nav_id'] ?? '') === 'fields_plots' || (($item['label'] ?? '') === 'Fields & Plots')) {
            $fieldsNav = $item;
            break 2;
        }
    }
}
check('Fields & Plots nav points to fields-planner', ($fieldsNav['href'] ?? '') === 'fields-planner');

$admin = db()->first("SELECT * FROM users WHERE role = 'admin' LIMIT 1");
check('admin user present', (bool) $admin);
Auth::setOverride($admin);
$_SESSION['user_id'] = (int) $admin['id'];

$farmId = db()->insert('farms', [
    'name' => 'Fields Planner Test Farm ' . time(),
    'owner_id' => (int) $admin['id'],
    'status' => 'active',
    'latitude' => -15.3875,
    'longitude' => 28.3228,
    'boundary_geojson' => json_encode([
        'type' => 'Polygon',
        'coordinates' => [[
            [28.3200, -15.3860],
            [28.3250, -15.3860],
            [28.3250, -15.3890],
            [28.3200, -15.3890],
            [28.3200, -15.3860],
        ]],
    ]),
]);
check('test farm created', $farmId > 0);
Auth::setActiveFarm($farmId);

$geo1 = [
    'type' => 'Polygon',
    'coordinates' => [[
        [28.3205, -15.3862],
        [28.3220, -15.3862],
        [28.3220, -15.3872],
        [28.3205, -15.3872],
        [28.3205, -15.3862],
    ]],
];
$field1 = db()->insert('fields', [
    'farm_id' => $farmId,
    'name' => 'Field #1',
    'size_hectares' => 1.25,
    'status' => 'active',
    'description' => 'North plot',
    'latitude' => -15.3867,
    'longitude' => 28.3212,
    'boundary_notes' => '[[GEOJSON]]' . json_encode($geo1),
]);
$field2 = db()->insert('fields', [
    'farm_id' => $farmId,
    'name' => 'Field #2',
    'size_hectares' => 0.85,
    'status' => 'active',
    'latitude' => -15.3878,
    'longitude' => 28.3235,
    'boundary_notes' => '[[GEOJSON]]' . json_encode([
        'type' => 'Polygon',
        'coordinates' => [[
            [28.3230, -15.3875],
            [28.3245, -15.3875],
            [28.3245, -15.3885],
            [28.3230, -15.3885],
            [28.3230, -15.3875],
        ]],
    ]),
]);
check('Field #1 created', $field1 > 0);
check('Field #2 created', $field2 > 0);

$opts = rel_options('fields', 'name', 'farm_id = ?', [$farmId]);
check('dropdown includes Field #1', isset($opts[$field1]) && $opts[$field1] === 'Field #1');
check('dropdown includes Field #2', isset($opts[$field2]) && $opts[$field2] === 'Field #2');

$otherFarm = db()->insert('farms', [
    'name' => 'Other Farm ' . time(),
    'owner_id' => (int) $admin['id'],
    'status' => 'active',
]);
$otherField = db()->insert('fields', [
    'farm_id' => $otherFarm,
    'name' => 'Other Field',
    'status' => 'active',
    'size_hectares' => 1,
]);
$scoped = rel_options('fields', 'name', 'farm_id = ?', [$farmId]);
check('other farm field excluded from dropdown', !isset($scoped[$otherField]));

$plantingBlocked = ReversalService::validateDelete('fields', ['id' => $field1]);
check('empty field can delete (no history yet)', $plantingBlocked === null);

// Create planting and confirm delete protection
$crop = db()->first('SELECT id FROM crop_varieties LIMIT 1');
if (!$crop) {
    $cropId = db()->insert('crop_varieties', [
        'name' => 'Test Maize',
        'variety' => 'Test',
        'status' => 'active',
    ]);
} else {
    $cropId = (int) $crop['id'];
}
$plantingId = db()->insert('plantings', [
    'farm_id' => $farmId,
    'field_id' => $field1,
    'crop_variety_id' => $cropId,
    'season' => '2026',
    'status' => 'planted',
    'planting_date' => date('Y-m-d'),
]);
check('planting linked to Field #1', $plantingId > 0);
$blocked = ReversalService::validateDelete('fields', ['id' => $field1]);
check('field with planting cannot be deleted', is_string($blocked) && str_contains($blocked, 'planting'));

$map = CropRotationMapService::build(db()->find('farms', $farmId));
$names = array_map(static fn ($f) => $f['name'] ?? '', $map['fields'] ?? []);
check('crop rotation map uses same Field #1', in_array('Field #1', $names, true));
check('crop rotation map uses same Field #2', in_array('Field #2', $names, true));

// Cleanup test rows
try {
    db()->delete('plantings', $plantingId);
    db()->delete('fields', $field1);
    db()->delete('fields', $field2);
    db()->delete('fields', $otherField);
    db()->delete('farms', $farmId);
    db()->delete('farms', $otherFarm);
} catch (Throwable $e) {
    echo '[WARN] cleanup: ' . $e->getMessage() . PHP_EOL;
}

echo $fail === 0 ? "ALL CHECKS PASSED\n" : "FAILED: {$fail}\n";
exit($fail === 0 ? 0 : 1);
