<?php
declare(strict_types=1);

/**
 * Import ZamStats Crop Forecast Survey aggregates from Excel/CSV.
 * Usage:
 *   php scripts/import-zamstats-cfs.php
 *   php scripts/import-zamstats-cfs.php path/to/file.xlsx
 *   php scripts/import-zamstats-cfs.php path/to/rain.csv --kind=rainfall --season=2024/2025
 */
require dirname(__DIR__) . '/backend/bootstrap.php';
set_time_limit(600);

$path = $argv[1] ?? (dirname(__DIR__) . '/Crop-Forecast-Survey-2025_2026.xlsx');
$meta = [
    'imported_by' => null,
    'source_url' => 'https://www.zamstats.gov.zm/agriculture-and-environment/',
    'kind' => 'crop',
];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--kind=')) {
        $meta['kind'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--season=')) {
        $meta['season_label'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--title=')) {
        $meta['title'] = substr($arg, 8);
    }
}

if (!is_file($path)) {
    fwrite(STDERR, "File not found: {$path}\n");
    exit(1);
}

echo "Importing {$path}...\n";
try {
    $result = ZamstatsAgriImport::importFile($path, $meta);
    echo $result['message'] . PHP_EOL;
    echo 'dataset_id=' . $result['dataset_id'] . ' inserted=' . $result['inserted'] . ' updated=' . $result['updated'] . PHP_EOL;
    $crops = (int) db()->value('SELECT COUNT(*) FROM zamstats_crop_stats');
    $rain = (int) db()->value('SELECT COUNT(*) FROM zamstats_rainfall');
    $live = (int) db()->value('SELECT COUNT(*) FROM zamstats_livestock');
    echo "Totals: crop_stats={$crops} rainfall={$rain} livestock={$live}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
