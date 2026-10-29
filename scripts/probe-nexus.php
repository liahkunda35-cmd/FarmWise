<?php
declare(strict_types=1);

$root = dirname(__DIR__);
foreach (['.env', '.env.local'] as $f) {
    $path = $root . DIRECTORY_SEPARATOR . $f;
    if (!is_file($path)) {
        continue;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string) $line);
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
        if ($f === '.env.local' || getenv($n) === false) {
            putenv($n . '=' . $v);
            $_ENV[$n] = $v;
        }
    }
}

if (!class_exists('Settings', false)) {
    class Settings
    {
        public static function get(string $key, $default = null)
        {
            return $default;
        }
    }
}
function config($key = null, $default = null)
{
    return $default ?? 'FarmWise';
}

require $root . '/backend/services/NexusMailer.php';

$key = NexusMailer::apiKey();
echo 'key_len=' . strlen($key) . ' provider=' . NexusMailer::provider() . ' url=' . NexusMailer::baseUrl() . PHP_EOL;
echo 'from_email=' . (NexusMailer::fromEmail() !== '' ? 'set' : 'missing') . PHP_EOL;

$attempts = [
    [
        'label' => 'api_v1_api_send',
        'url' => NexusMailer::baseUrl() . '/api/v1/api/send',
        'body' => [
            'to' => 'probe@example.com',
            'from' => 'noreply@example.com',
            'subject' => 'FarmWise probe',
            'html' => '<p>probe</p>',
        ],
    ],
    [
        'label' => 'v1_api_send',
        'url' => NexusMailer::baseUrl() . '/v1/api/send',
        'body' => [
            'to' => 'probe@example.com',
            'from' => 'noreply@example.com',
            'subject' => 'FarmWise probe',
            'html' => '<p>probe</p>',
        ],
    ],
];

foreach ($attempts as $a) {
    $ch = curl_init($a['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($a['body'], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $data = json_decode($raw, true);
    $msg = '';
    if (is_array($data)) {
        $msg = is_array($data['error'] ?? null)
            ? (string) ($data['error']['message'] ?? json_encode($data['error']))
            : (string) ($data['message'] ?? $data['error'] ?? '');
    }
    echo $a['label'] . ' http=' . $code . ' curl=' . ($err !== '' ? $err : 'ok')
        . ' msg=' . substr($msg !== '' ? $msg : preg_replace('/\s+/', ' ', $raw), 0, 180) . PHP_EOL;
}

$send = NexusMailer::send('probe@example.com', 'FarmWise Nexus probe', '<p>probe</p>', 'probe');
echo 'NexusMailer::send ok=' . (!empty($send['ok']) ? '1' : '0')
    . ' http=' . ($send['http'] ?? '')
    . ' endpoint=' . ($send['endpoint'] ?? '')
    . ' err=' . substr((string) ($send['error'] ?? ''), 0, 200)
    . PHP_EOL;
