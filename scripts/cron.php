<?php
/**
 * FarmWise scheduled jobs — run via system cron / Task Scheduler.
 *
 * Local (Windows Task Scheduler or):
 *   C:\xampp\php\php.exe C:\Users\Liah\Desktop\FarmMS\scripts\cron.php
 *
 * Linux cron (every 15 minutes):
 *   Every 15 minutes: /usr/bin/php /path/to/FarmMS/scripts/cron.php >> /path/to/FarmMS/storage/cron.log 2>&1
 *
 * Jobs:
 *  - NotificationService::syncAlerts(true) for all farms (low stock, irrigation, vaccinations, etc.)
 *  - Prefetch OpenWeather for farms with GPS (respects WeatherService cache)
 *  - MessageDispatcher::flush() for queued email/SMS
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require dirname(__DIR__) . '/backend/bootstrap.php';

$started = microtime(true);
$log = static function (string $msg): void {
    echo '[' . date('c') . '] ' . $msg . PHP_EOL;
};

$log('FarmWise cron started');

try {
    NotificationService::syncAlerts(true);
    $log('Alerts synced');
} catch (Throwable $e) {
    $log('Alert sync failed: ' . $e->getMessage());
}

try {
    $farms = db()->select("SELECT * FROM farms WHERE status = 'active' AND latitude IS NOT NULL AND longitude IS NOT NULL LIMIT 50");
    $n = 0;
    foreach ($farms as $farm) {
        try {
            WeatherService::fetchForFarm($farm);
            $n++;
        } catch (Throwable $e) {
            $log('Weather farm #' . $farm['id'] . ' failed: ' . $e->getMessage());
        }
    }
    $log('Weather refreshed for ' . $n . ' farm(s)');
} catch (Throwable $e) {
    $log('Weather loop failed: ' . $e->getMessage());
}

try {
    MessageDispatcher::flush();
    $log('Outbound messages flushed');
} catch (Throwable $e) {
    $log('Message flush failed: ' . $e->getMessage());
}

$log(sprintf('Done in %.2fs', microtime(true) - $started));
