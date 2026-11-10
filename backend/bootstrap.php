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
require __DIR__ . '/services/DemoSignIn.php';
require __DIR__ . '/services/ReversalService.php';
require __DIR__ . '/services/SmsGateway.php';
require __DIR__ . '/services/MessageDispatcher.php';
require __DIR__ . '/services/GeminiService.php';
require __DIR__ . '/services/FarmAssistantContext.php';
require __DIR__ . '/services/SimpleXlsxReader.php';
require __DIR__ . '/services/MarketPriceSync.php';
require __DIR__ . '/services/ZamstatsAgriImport.php';
require __DIR__ . '/services/AgriPatternService.php';
require __DIR__ . '/services/EmployeeAccounts.php';
require __DIR__ . '/services/EmployeeReports.php';
require __DIR__ . '/services/CropRotationMapService.php';
require __DIR__ . '/services/CropRotationReports.php';
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
        $codeFlag = dirname(__DIR__) . '/storage/.schema_mig_farmcode1';
        if (!is_file($codeFlag)) {
            Schema::ensureFarmCodeSecurity($db);
            @file_put_contents($codeFlag, (string) time());
        }
        $perfFlag = dirname(__DIR__) . '/storage/.schema_mig_perf1';
        if (!is_file($perfFlag)) {
            Schema::ensureListIndexes($db);
            @file_put_contents($perfFlag, (string) time());
        }
        $agriFlag = dirname(__DIR__) . '/storage/.schema_mig_agri1';
        if (!is_file($agriFlag)) {
            Schema::ensureMarketAndAgriSchema($db);
            @file_put_contents($agriFlag, (string) time());
        }
        $empFlag = dirname(__DIR__) . '/storage/.schema_mig_emp1';
        if (!is_file($empFlag)) {
            Schema::ensureEmployeeSchema($db);
            @file_put_contents($empFlag, (string) time());
        }
        $opsUiFlag = dirname(__DIR__) . '/storage/.schema_mig_opsui1';
        if (!is_file($opsUiFlag)) {
            Schema::ensureOpsUiSchema($db);
            Schema::purgeDemoOpsData($db);
            @file_put_contents($opsUiFlag, (string) time());
        }
        $fieldsDescFlag = dirname(__DIR__) . '/storage/.schema_mig_fieldsdesc1';
        if (!is_file($fieldsDescFlag)) {
            Schema::ensureOpsUiSchema($db);
            @file_put_contents($fieldsDescFlag, (string) time());
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
    $low = strtolower($msg);
    $neonWake = str_contains($low, 'closed the connection')
        || str_contains($low, 'server closed')
        || str_contains($low, 'could not connect')
        || str_contains($low, 'timeout')
        || str_contains($low, 'connection refused')
        || str_contains($low, 'endpoint id is not specified')
        || str_contains($low, 'the database system is starting')
        || str_contains($low, 'remaining connection slots')
        || str_contains($low, 'sqlstate[08006]');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Database error: ' . redact_for_log($msg) . PHP_EOL);
        if ($neonWake) {
            fwrite(STDERR, "Neon may be waking — retry in a few seconds.\n");
        }
        exit(1);
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    // Always auto-retry: Neon idle wake is the usual cause on local demo / projector setups
    $title = $neonWake ? 'Starting FarmWise database…' : 'FarmWise is temporarily unavailable';
    $heading = $neonWake ? 'Waking the FarmWise database…' : 'FarmWise is temporarily unavailable';
    $body = $neonWake
        ? 'Neon Postgres was idle and is starting up. This page will retry automatically in a few seconds.'
        : 'The database could not be reached. Retrying automatically…';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta http-equiv="refresh" content="3">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#F5F0E3;color:#1D2B22;display:grid;place-items:center;min-height:100vh;margin:0;padding:1.5rem;text-align:center}'
        . '.card{max-width:28rem;background:#fff;border-radius:18px;padding:1.5rem;box-shadow:0 10px 30px rgba(16,43,31,.08)}'
        . 'h1{font-size:1.25rem;margin:0 0 .6rem}p{margin:.4rem 0;line-height:1.5;color:#687267}'
        . 'a.btn{display:inline-block;margin-top:.85rem;padding:.65rem 1.1rem;border-radius:999px;background:#D9B66F;color:#1a1408;text-decoration:none;font-weight:650}</style></head><body>'
        . '<div class="card"><h1>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><small>If this keeps failing, confirm the Neon project compute is awake, then refresh.</small></p>'
        . '<p><a class="btn" href="' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8') . '">Retry now</a></p>'
        . '</div></body></html>';
    if (!$neonWake) {
        log_internal_error('database', $e);
    }
    exit;
}
