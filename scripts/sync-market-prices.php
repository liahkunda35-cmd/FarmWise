<?php
declare(strict_types=1);

/**
 * Sync WFP/HDX Zambia food prices into market_prices.
 * Usage:
 *   php scripts/sync-market-prices.php
 *   php scripts/sync-market-prices.php --months=24
 *   php scripts/sync-market-prices.php path/to/local.csv --months=12
 */
require dirname(__DIR__) . '/backend/bootstrap.php';
set_time_limit(600);

$csv = null;
$months = 36;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--months=')) {
        $months = max(6, min(120, (int) substr($arg, 9)));
    } elseif (!str_starts_with($arg, '--')) {
        $csv = $arg;
    }
}

echo 'Syncing WFP/HDX Zambia food prices (months_back=' . $months . ")...\n";
try {
    $result = MarketPriceSync::syncHdx($csv, $months, static function ($ins, $upd, $skip) {
        echo "  progress inserted={$ins} updated={$upd} skipped={$skip}\n";
    });
    echo $result['message'] . PHP_EOL;
    $n = (int) db()->value("SELECT COUNT(*) FROM market_prices WHERE source = 'wfp_hdx'");
    echo "wfp_hdx rows in DB: {$n}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
