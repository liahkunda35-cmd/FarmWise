<?php
declare(strict_types=1);

$root = dirname(__DIR__);
putenv('DATABASE_URL');
$_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = '';
require $root . '/backend/bootstrap.php';

$fail = 0;
$ok = static function (bool $pass, string $label, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? '[OK] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$admin = db()->first("SELECT * FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
$ok((bool) $admin, 'Admin available');
Auth::setOverride($admin);
$_SESSION['user_id'] = (int) $admin['id'];

$farmId = db()->insert('farms', [
    'name' => 'CR Test Farm ' . substr(bin2hex(random_bytes(3)), 0, 6),
    'location' => 'Test Location',
    'latitude' => -15.4167,
    'longitude' => 28.2833,
    'location_precision' => 'confirmed',
    'status' => 'active',
    'join_code' => generate_join_code(),
]);
$_SESSION['active_farm_id'] = $farmId;

$fieldA = db()->insert('fields', [
    'farm_id' => $farmId,
    'name' => 'Field Alpha',
    'size_hectares' => 4.5,
    'status' => 'active',
    'latitude' => -15.4170,
    'longitude' => 28.2840,
    'boundary_notes' => "[[GEOJSON]]" . json_encode([
        'type' => 'Polygon',
        'coordinates' => [[
            [28.2835, -15.4165], [28.2845, -15.4165], [28.2845, -15.4175], [28.2835, -15.4175], [28.2835, -15.4165],
        ]],
    ]),
]);
$fieldB = db()->insert('fields', [
    'farm_id' => $farmId,
    'name' => 'Field Beta',
    'size_hectares' => 3.0,
    'status' => 'active',
    'latitude' => -15.4180,
    'longitude' => 28.2850,
]);

$cropMaize = db()->insert('crop_varieties', [
    'farm_id' => $farmId, 'name' => 'Maize', 'variety' => 'Test', 'category' => 'cereal', 'status' => 'active',
]);
$cropSoya = db()->insert('crop_varieties', [
    'farm_id' => $farmId, 'name' => 'Soybeans', 'variety' => 'Test', 'category' => 'oilseed', 'status' => 'active',
]);

db()->insert('plantings', [
    'farm_id' => $farmId, 'field_id' => $fieldA, 'crop_variety_id' => $cropMaize,
    'season' => '2024/25', 'planting_date' => '2025-01-10', 'expected_harvest' => '2025-05-20',
    'status' => 'harvested', 'growth_stage' => 'harvested',
]);
db()->insert('harvests', [
    'farm_id' => $farmId, 'planting_id' => (int) db()->value('SELECT MAX(id) FROM plantings WHERE field_id = ?', [$fieldA]),
    'field_id' => $fieldA, 'harvest_date' => '2025-05-20', 'quantity' => 1000, 'unit' => 'kg', 'status' => 'completed',
]);
db()->insert('plantings', [
    'farm_id' => $farmId, 'field_id' => $fieldA, 'crop_variety_id' => $cropSoya,
    'season' => '2025/26', 'planting_date' => date('Y-m-d', strtotime('-20 days')),
    'expected_harvest' => date('Y-m-d', strtotime('+90 days')),
    'status' => 'growing', 'growth_stage' => 'vegetative',
]);

$farm = db()->find('farms', $farmId);
$map = CropRotationMapService::build($farm);
$ok((int) $map['summary']['total'] === 2, 'Summary total fields', json_encode($map['summary']));
$ok((int) $map['summary']['occupied'] >= 1, 'At least one occupied field');
$ok((int) $map['summary']['vacant'] >= 1, 'Vacant field counted');

$alpha = null;
foreach ($map['fields'] as $f) {
    if ((int) $f['id'] === $fieldA) {
        $alpha = $f;
    }
}
$ok($alpha !== null, 'Field Alpha in map payload');
$ok(($alpha['rotation_status'] ?? '') === 'rotated', 'Alpha marked rotated (soya after maize)', $alpha['rotation_status'] ?? '');
$ok(count($alpha['history'] ?? []) === 2, 'Alpha keeps both planting history rows', (string) count($alpha['history'] ?? []));
$ok(!empty($alpha['geometry']), 'Alpha geometry from boundary_notes');

$report = CropRotationMapService::fieldReport($fieldA);
$ok($report !== null, 'Field report builds');
$pdf = CropRotationReports::fieldPdf($report);
$ok(str_starts_with($pdf, '%PDF'), 'Field PDF generated');
$farmPdf = CropRotationReports::farmPdf(CropRotationMapService::farmReport($farm));
$ok(str_starts_with($farmPdf, '%PDF'), 'Farm PDF generated');

$vacantStatus = CropRotationMapService::fieldRotationStatus([]);
$ok($vacantStatus === 'vacant', 'Empty history is vacant');

// Cleanup test rows (keep users)
foreach (db()->select('SELECT id FROM plantings WHERE farm_id = ?', [$farmId]) as $p) {
    db()->query('DELETE FROM harvests WHERE planting_id = ?', [$p['id']]);
}
db()->query('DELETE FROM plantings WHERE farm_id = ?', [$farmId]);
db()->query('DELETE FROM crop_varieties WHERE farm_id = ?', [$farmId]);
db()->query('DELETE FROM fields WHERE farm_id = ?', [$farmId]);
db()->delete('farms', $farmId);
Auth::setOverride(null);

echo PHP_EOL . ($fail === 0 ? 'ALL CHECKS PASSED' : "FAILED {$fail} CHECK(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
