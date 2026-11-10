<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';
set_time_limit(300);

$fail = 0;
$ok = static function (bool $pass, string $label, string $detail = '') use (&$fail): void {
    if (!$pass) {
        $fail++;
    }
    echo ($pass ? 'PASS' : 'FAIL') . '  ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

Schema::ensureMarketAndAgriSchema(db());

$ok(in_array('zamstats_crop_stats', db()->tables(), true), 'zamstats_crop_stats table exists');
$ok(in_array('source', db()->columns('market_prices'), true), 'market_prices.source column exists');
$ok(in_array('external_key', db()->columns('market_prices'), true), 'market_prices.external_key column exists');

$cropCount = (int) db()->value('SELECT COUNT(*) FROM zamstats_crop_stats');
$ok($cropCount > 50, 'CFS aggregates imported', 'rows=' . $cropCount);

$seasons = db()->select('SELECT DISTINCT season_label FROM zamstats_crop_stats ORDER BY 1');
$ok(count($seasons) >= 1, 'At least one CFS season present', implode(', ', array_column($seasons, 'season_label')));

$prov = (int) db()->value("SELECT COUNT(*) FROM zamstats_crop_stats WHERE province = 'Central' AND crop_name LIKE 'Maize%'");
$ok($prov > 0, 'Provincial maize rows for Central exist', 'n=' . $prov);

$natPhs = (int) db()->value("SELECT COUNT(*) FROM zamstats_crop_stats WHERE province = 'National' AND season_label = '2024/2025'");
$ok($natPhs > 0, 'PHS 2024/2025 national actuals from % Change sheet', 'n=' . $natPhs);

$trend = AgriPatternService::cropTrend('Maize (for grain)', null);
$ok(count($trend['labels']) >= 1, 'National maize trend has labels', json_encode($trend['labels']));

$comp = AgriPatternService::provincialComparison('Maize (for grain)');
$ok(count($comp) >= 5, 'Provincial maize comparison has provinces', 'n=' . count($comp));

$rec = AgriPatternService::recommendForProvince('Southern', 5);
$ok(!empty($rec['items']), 'Southern province recommendations non-empty', 'n=' . count($rec['items']));
$ok(str_contains($rec['disclaimer'], 'not a guaranteed forecast'), 'Recommendation carries disclaimer');

$rain = (int) db()->value('SELECT COUNT(*) FROM zamstats_rainfall');
$ok($rain === 0, 'No fabricated rainfall rows', 'count=' . $rain);

$live = (int) db()->value('SELECT COUNT(*) FROM zamstats_livestock');
$ok($live === 0, 'No fabricated livestock rows', 'count=' . $live);

$snippet = AgriPatternService::assistantSnippet('Eastern');
$ok(str_contains($snippet, 'ZamStats CFS') || str_contains($snippet, 'Seasons in database'), 'AI CFS snippet generated');
$ok(!str_contains(strtolower($snippet), 'guaranteed yield of'), 'AI snippet does not invent guarantees');

// Market price unit conversion
$ok(MarketPriceSync::toPricePerKg(100, '50 KG') === 2.0, '50 KG bag converts to per-kg');
$ok(MarketPriceSync::toPricePerKg(5, 'L') === null, 'Litre unit does not invent per-kg');

// Bulletin import smoke
$bul = MarketPriceSync::importBulletinRows([[
    'crop_name' => 'Maize (white) TEST',
    'market' => 'Lusaka',
    'price_date' => '2026-01-15',
    'price' => 12.5,
    'unit' => 'KG',
    'province' => 'Lusaka',
]], 1);
$ok($bul['inserted'] + $bul['updated'] === 1, 'Bulletin import upserts one row');
$testRow = db()->first("SELECT * FROM market_prices WHERE crop_name = 'Maize (white) TEST'");
$ok($testRow && ($testRow['source'] ?? '') === 'zamstats_bulletin', 'Bulletin row labelled zamstats_bulletin');
if ($testRow) {
    db()->delete('market_prices', (int) $testRow['id']);
}

$mktSnippet = AgriPatternService::marketAssistantSnippet(null);
$ok(str_contains($mktSnippet, 'Market price'), 'Market AI snippet works');

echo PHP_EOL . ($fail === 0 ? 'ALL CHECKS PASSED' : "FAILED {$fail} CHECK(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
