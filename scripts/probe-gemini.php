<?php
declare(strict_types=1);

$root = dirname(__DIR__);
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

$key = trim((string)(getenv('GEMINI_API_KEY') ?: ''));
$modelEnv = trim((string)(getenv('GEMINI_MODEL') ?: ''));
echo 'key_len=' . strlen($key) . ' model_env=' . $modelEnv . PHP_EOL;

function tryModel(string $key, string $model, int $timeout = 45): array
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent?key='
        . rawurlencode($key);
    $body = json_encode([
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => 'Reply with exactly: OK farming']],
        ]],
        'generationConfig' => ['maxOutputTokens' => 32],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string)$res, true);
    $msg = is_array($data) ? (string)($data['error']['message'] ?? '') : '';
    $text = '';
    foreach (($data['candidates'][0]['content']['parts'] ?? []) as $p) {
        if (!empty($p['text'])) {
            $text .= (string)$p['text'];
        }
    }
    return [
        'model' => $model,
        'http' => $code,
        'curl' => $cerr !== '' ? $cerr : 'ok',
        'err' => substr($msg, 0, 160),
        'text' => substr(trim($text), 0, 80),
    ];
}

$candidates = array_values(array_unique(array_filter([
    $modelEnv,
    'gemini-3.8-flash',
    'gemini-3.7-flash',
    'gemini-3.6-flash',
    'gemini-3.5-flash',
    'gemini-flash-lite-latest',
    'gemini-3.1-flash-lite',
    'gemini-flash-latest',
])));

foreach ($candidates as $m) {
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $r = tryModel($key, $m, 40);
        echo sprintf(
            "model=%s attempt=%d http=%d curl=%s err=%s text=%s\n",
            $r['model'],
            $attempt,
            $r['http'],
            $r['curl'],
            $r['err'],
            $r['text']
        );
        if ($r['http'] === 200 && $r['text'] !== '') {
            break 2;
        }
        if ($r['http'] === 503) {
            usleep(800000 * $attempt);
            continue;
        }
        break;
    }
}
