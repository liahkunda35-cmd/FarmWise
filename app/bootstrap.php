<?php

declare(strict_types=1);

// Load Neon / local env files before config() reads getenv()
(static function (): void {
    $root = dirname(__DIR__);
    foreach (['.env', '.env.local'] as $file) {
        $path = $root . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            continue;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name === '') {
                continue;
            }
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            // Do not override real process env
            if (getenv($name) === false) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
})();

$config = require dirname(__DIR__) . '/config/config.php';
date_default_timezone_set($config['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['session_name']);
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Schema.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Csrf.php';
require __DIR__ . '/PasswordPolicy.php';
require __DIR__ . '/Validator.php';
require __DIR__ . '/Audit.php';
require __DIR__ . '/Seed.php';
require __DIR__ . '/Router.php';
require __DIR__ . '/services/NotificationService.php';
require __DIR__ . '/services/WeatherService.php';
require __DIR__ . '/services/ReportService.php';
require __DIR__ . '/controllers/ResourceController.php';
require __DIR__ . '/controllers/AuthController.php';
require __DIR__ . '/controllers/DashboardController.php';
require __DIR__ . '/controllers/AppPagesController.php';
require __DIR__ . '/controllers/WorkspaceController.php';
require __DIR__ . '/Pdf.php';
require __DIR__ . '/services/DocumentService.php';
require __DIR__ . '/services/AttachmentService.php';
require __DIR__ . '/services/CalendarService.php';
require __DIR__ . '/Settings.php';
require __DIR__ . '/Jwt.php';
require __DIR__ . '/services/Mailer.php';
require __DIR__ . '/services/SmsGateway.php';
require __DIR__ . '/services/MessageDispatcher.php';
require __DIR__ . '/services/GeminiService.php';
require __DIR__ . '/services/FarmAssistantContext.php';
require __DIR__ . '/controllers/ApiController.php';
require __DIR__ . '/controllers/AssistantController.php';

try {
    $db = Database::boot();
    if ($db->driver() === 'pgsql') {
        @ini_set('max_execution_time', '120');
        @set_time_limit(120);
    }
    // Schema install/migrate once, then skip on every request (critical for Neon RTT)
    $schemaFlag = dirname(__DIR__) . '/storage/.schema_ready';
    if (!is_file($schemaFlag)) {
        Schema::migrate($db);
        if ((int) db()->value('SELECT COUNT(*) FROM users') === 0) {
            Seed::run();
        }
        @file_put_contents($schemaFlag, (string) time());
    }
    // Timezone: prefer session cache, then one settings lookup
    $tz = $_SESSION['_tz'] ?? null;
    if (!is_string($tz) || $tz === '') {
        $tz = Settings::get('timezone', $config['timezone']);
        if (is_string($tz) && $tz !== '') {
            $_SESSION['_tz'] = $tz;
        }
    }
    if (is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database error: ' . htmlspecialchars($e->getMessage());
    exit;
}
