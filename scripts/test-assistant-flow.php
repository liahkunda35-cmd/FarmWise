<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/backend/services/GeminiService.php';

foreach (['.env.local', '.env'] as $f) {
    $path = $root . DIRECTORY_SEPARATOR . $f;
    if (!is_file($path)) {
        continue;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string)$line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$n, $v] = explode('=', $line, 2);
        $n = trim($n);
        $v = trim($v);
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"'))
            || (str_starts_with($v, "'") && str_ends_with($v, "'"))
        ) {
            $v = substr($v, 1, -1);
        }
        if (getenv($n) === false) {
            putenv($n . '=' . $v);
            $_ENV[$n] = $v;
        }
    }
}

echo 'candidates=' . implode(',', GeminiService::modelCandidates()) . PHP_EOL;

$math = GeminiService::topicDecision('Help me with my mathematics assignment', [], 'en');
echo 'topic_math=' . $math['action'] . PHP_EOL;
echo 'topic_hello=' . GeminiService::topicDecision('Hello', [], 'bem')['action'] . PHP_EOL;
echo 'topic_farm=' . GeminiService::topicDecision('Should I irrigate my maize today?', [], 'en')['action'] . PHP_EOL;

$farmContext = "Active farm: Demo Farm\nLocation precision: approximate\nPlace: Lusaka, Zambia\nLive weather block:\n- meta.ok: true\n- updated_at: 2026-10-26T12:00:00+02:00\nCurrent observation:\n- temperature_c: 28\n- conditions: clear sky\nForecast:\n- 2026-10-26: tmax 31C, tmin 18C, precip_mm 0, rain_chance_pct 5\n- 2026-10-27: tmax 30C, tmin 17C, precip_mm 2, rain_chance_pct 20\nPlantings: maize, stage vegetative, planted 2026-09-01\nNote: do not invent weather.";

$t0 = microtime(true);
$r1 = GeminiService::chat(
    'Should I irrigate my maize today based on the weather?',
    [],
    $farmContext,
    'auth',
    'Liah',
    'en'
);
echo 'chat1_ok=' . (!empty($r1['ok']) ? '1' : '0')
    . ' model=' . ($r1['model'] ?? '')
    . ' secs=' . round(microtime(true) - $t0, 2)
    . ' err=' . substr((string)($r1['error'] ?? ''), 0, 100)
    . ' text=' . substr(preg_replace('/\s+/', ' ', (string)($r1['text'] ?? '')), 0, 220)
    . PHP_EOL;

if (!empty($r1['ok'])) {
    $t0 = microtime(true);
    $r2 = GeminiService::chat(
        'What about tomorrow?',
        [
            ['role' => 'user', 'text' => 'Should I irrigate my maize today based on the weather?'],
            ['role' => 'model', 'text' => (string)$r1['text']],
        ],
        $farmContext,
        'auth',
        'Liah',
        'en'
    );
    echo 'chat2_ok=' . (!empty($r2['ok']) ? '1' : '0')
        . ' model=' . ($r2['model'] ?? '')
        . ' secs=' . round(microtime(true) - $t0, 2)
        . ' text=' . substr(preg_replace('/\s+/', ' ', (string)($r2['text'] ?? $r2['error'] ?? '')), 0, 180)
        . PHP_EOL;
}

$off = GeminiService::chat('Help me with my mathematics assignment', [], null, 'auth', 'Liah', 'en');
echo 'offtopic_model=' . ($off['model'] ?? '') . ' text=' . substr((string)($off['text'] ?? ''), 0, 140) . PHP_EOL;

foreach (['bem', 'nya', 'toi'] as $lang) {
    $t0 = microtime(true);
    $rr = GeminiService::chat('Which crops can I plant this week near Lusaka?', [], $farmContext, 'auth', 'Liah', $lang);
    echo "lang_{$lang}_ok=" . (!empty($rr['ok']) ? '1' : '0')
        . ' secs=' . round(microtime(true) - $t0, 2)
        . ' model=' . ($rr['model'] ?? '')
        . ' sample=' . substr(preg_replace('/\s+/', ' ', (string)($rr['text'] ?? $rr['error'] ?? '')), 0, 140)
        . PHP_EOL;
}
