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

$config = require __DIR__ . '/config/config.php';
date_default_timezone_set($config['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['session_name']);
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((string) (getenv('IFMS_SESSION_SECURE') ?: ($_ENV['IFMS_SESSION_SECURE'] ?? '')) === '1');
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $https,
        'use_strict_mode' => true,
    ]);
}

require dirname(__DIR__) . '/frontend/bridge.php';
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
require __DIR__ . '/services/TaskAccess.php';
require __DIR__ . '/services/WorkerRecords.php';
require __DIR__ . '/services/WeatherService.php';
require __DIR__ . '/services/WorkflowService.php';
require __DIR__ . '/services/OperationHooks.php';
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
require __DIR__ . '/services/SslCa.php';
require __DIR__ . '/services/NexusMailer.php';
require __DIR__ . '/services/AccountMail.php';
require __DIR__ . '/services/RegistrationService.php';
require __DIR__ . '/services/ReversalService.php';
require __DIR__ . '/services/SmsGateway.php';
require __DIR__ . '/services/MessageDispatcher.php';
require __DIR__ . '/services/GeminiService.php';
require __DIR__ . '/services/FarmAssistantContext.php';
require __DIR__ . '/controllers/ApiController.php';
require __DIR__ . '/controllers/AssistantController.php';
require __DIR__ . '/services/PresentationData.php';
farmwise_bind_frontend();

try {
    $db = Database::boot();
    if ($db->driver() === 'pgsql') {
        @ini_set('max_execution_time', '120');
        @set_time_limit(120);
    }
    // Disposable tests always migrate the temp SQLite DB and never touch Neon / production flags.
    $testCapture = (string) (getenv('IFMS_TEST_CAPTURE') ?: '') === '1';
    if ($testCapture) {
        Schema::migrate($db);
    } else {
        // Schema install once; additive migrations when version flag missing (keeps Neon RTT low)
        $schemaFlag = dirname(__DIR__) . '/storage/.schema_ready';
        $migFlag = dirname(__DIR__) . '/storage/.schema_mig_ops2';
        $rbacFlag = dirname(__DIR__) . '/storage/.schema_mig_rbac1';
        $revFlag = dirname(__DIR__) . '/storage/.schema_mig_ops4';
        $p2Flag = dirname(__DIR__) . '/storage/.schema_mig_p2wf';
        $scoutFlag = dirname(__DIR__) . '/storage/.schema_mig_p2rbac';
        if (!is_file($schemaFlag)) {
            Schema::migrate($db);
            if ((int) db()->value('SELECT COUNT(*) FROM users') === 0) {
                Seed::run();
            }
            @file_put_contents($schemaFlag, (string) time());
            @file_put_contents($migFlag, (string) time());
            @file_put_contents($rbacFlag, (string) time());
            @file_put_contents($revFlag, (string) time());
            @file_put_contents($p2Flag, (string) time());
            @file_put_contents($scoutFlag, (string) time());
        } elseif (!is_file($migFlag) || !is_file($rbacFlag) || !is_file($revFlag) || !is_file($p2Flag)) {
            Schema::migrate($db);
            @file_put_contents($migFlag, (string) time());
            @file_put_contents($rbacFlag, (string) time());
            @file_put_contents($revFlag, (string) time());
            @file_put_contents($p2Flag, (string) time());
            @file_put_contents($scoutFlag, (string) time());
        } elseif (!is_file($scoutFlag)) {
            Schema::ensureScoutFollowUpIndex($db);
            @file_put_contents($scoutFlag, (string) time());
        }
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
    $msg = $e->getMessage();
    $neonWake = str_contains(strtolower($msg), 'closed the connection')
        || str_contains(strtolower($msg), 'server closed')
        || str_contains(strtolower($msg), 'could not connect')
        || str_contains(strtolower($msg), 'timeout');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Database error: ' . redact_for_log($msg) . PHP_EOL);
        if ($neonWake) {
            fwrite(STDERR, "Neon may be waking — retry in a few seconds.\n");
        }
        exit(1);
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    if ($neonWake) {
        // Auto-retry while Neon compute wakes from idle
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta http-equiv="refresh" content="3">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Starting FarmWise database…</title>'
            . '<style>body{font-family:system-ui,sans-serif;background:#F5F0E3;color:#1D2B22;display:grid;place-items:center;min-height:100vh;margin:0;padding:1.5rem;text-align:center}'
            . '.card{max-width:28rem;background:#fff;border-radius:18px;padding:1.5rem;box-shadow:0 10px 30px rgba(16,43,31,.08)}'
            . 'h1{font-size:1.25rem;margin:0 0 .6rem}p{margin:.4rem 0;line-height:1.5;color:#687267}code{font-size:.8rem}</style></head><body>'
            . '<div class="card"><h1>Waking the FarmWise database…</h1>'
            . '<p>Neon Postgres was idle and is starting up. This page will retry automatically in a few seconds.</p>'
            . '<p><small>If this keeps failing, open the Neon console and start the <strong>Farmawise</strong> project compute, then refresh.</small></p>'
            . '</div></body></html>';
        exit;
    }
    log_internal_error('database', $e);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>FarmWise is unavailable</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#F5F0E3;color:#1D2B22;display:grid;place-items:center;min-height:100vh;margin:0;padding:1.5rem;text-align:center}'
        . '.card{max-width:28rem;background:#fff;border-radius:18px;padding:1.5rem;box-shadow:0 10px 30px rgba(16,43,31,.08)}'
        . 'h1{font-size:1.25rem;margin:0 0 .6rem}p{margin:.4rem 0;line-height:1.5;color:#687267}</style></head><body>'
        . '<div class="card"><h1>FarmWise is temporarily unavailable</h1>'
        . '<p>The database could not be reached. Please try again in a moment.</p>'
        . '<p>If this continues, contact your administrator. Technical details are recorded on the server.</p>'
        . '</div></body></html>';
    exit;
}
