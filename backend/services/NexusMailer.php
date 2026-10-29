<?php

/**
 * Nexus transactional email (NexusProMail / WRNexus-compatible REST).
 * Credentials come only from environment / Settings — never frontend.
 */
class NexusMailer
{
    public static function configured(): bool
    {
        return self::apiKey() !== '' && self::baseUrl() !== '';
    }

    public static function apiKey(): string
    {
        foreach (['NEXUS_API_KEY', 'NEXUSPROMAIL_API_KEY'] as $name) {
            $key = getenv($name);
            if ($key === false || $key === null || $key === '') {
                $key = $_ENV[$name] ?? '';
            }
            $key = trim((string) $key);
            if ($key !== '') {
                return $key;
            }
        }
        return trim((string) Settings::get('nexus_api_key', ''));
    }

    public static function baseUrl(): string
    {
        $url = getenv('NEXUS_API_URL');
        if ($url === false || $url === null || $url === '') {
            $url = $_ENV['NEXUS_API_URL'] ?? Settings::get('nexus_api_url', 'https://app.nexuspromail.com');
        }
        return rtrim(trim((string) $url), '/');
    }

    public static function provider(): string
    {
        $p = getenv('NEXUS_PROVIDER') ?: ($_ENV['NEXUS_PROVIDER'] ?? Settings::get('nexus_provider', 'nexuspromail'));
        $p = strtolower(trim((string) $p));
        return in_array($p, ['nexuspromail', 'wrnexus', 'auto'], true) ? $p : 'nexuspromail';
    }

    public static function fromEmail(): string
    {
        $v = getenv('NEXUS_FROM_EMAIL') ?: ($_ENV['NEXUS_FROM_EMAIL'] ?? Settings::get('nexus_from_email', ''));
        if ($v === '' || $v === false || $v === null) {
            $v = Settings::get('smtp_from', '') ?: (config('mail_from') ?? '');
        }
        return trim((string) $v);
    }

    public static function fromName(): string
    {
        $v = getenv('NEXUS_FROM_NAME') ?: ($_ENV['NEXUS_FROM_NAME'] ?? Settings::get('nexus_from_name', ''));
        if ($v === '' || $v === false || $v === null) {
            $v = Settings::get('smtp_from_name', '') ?: (string) config('name', 'FarmWise');
        }
        return trim((string) $v) ?: 'FarmWise';
    }

    public static function senderKey(): string
    {
        $v = getenv('NEXUS_SENDER_KEY') ?: ($_ENV['NEXUS_SENDER_KEY'] ?? Settings::get('nexus_sender_key', ''));
        return trim((string) $v);
    }

    /** Template credential map (optional Nexus template IDs). */
    public static function templateId(string $key): string
    {
        $envKey = 'NEXUS_TEMPLATE_' . strtoupper($key);
        $v = getenv($envKey) ?: ($_ENV[$envKey] ?? Settings::get(strtolower($envKey), ''));
        return trim((string) $v);
    }

    /**
     * @return array{ok:bool,error:?string,provider?:string,http?:int,raw?:string,endpoint?:string}
     */
    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): array
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid recipient email.'];
        }
        if (!self::configured()) {
            return [
                'ok' => false,
                'error' => 'Nexus email is not configured. Set NEXUS_API_KEY and NEXUS_API_URL (and sender details) on the server.',
            ];
        }
        // NexusProMail expects a verified from-address; fail clearly before calling the API.
        if (self::fromEmail() === '' && self::provider() !== 'wrnexus') {
            return [
                'ok' => false,
                'error' => 'NEXUS_FROM_EMAIL is not set. Add a verified sender address in .env / .env.local (server-side only).',
            ];
        }

        $text = $textBody ?? trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)));
        $provider = self::provider();

        if ($provider === 'wrnexus') {
            return self::sendWrNexus($to, $subject, $htmlBody, $text);
        }
        if ($provider === 'auto') {
            $npm = self::sendNexusProMail($to, $subject, $htmlBody, $text);
            if (!empty($npm['ok'])) {
                return $npm;
            }
            if (self::senderKey() !== '') {
                $wrn = self::sendWrNexus($to, $subject, $htmlBody, $text);
                if (!empty($wrn['ok'])) {
                    return $wrn;
                }
            }
            return $npm;
        }
        return self::sendNexusProMail($to, $subject, $htmlBody, $text);
    }

    /** @return array{ok:bool,error:?string,provider?:string,http?:int,raw?:string,endpoint?:string} */
    private static function sendNexusProMail(string $to, string $subject, string $html, string $text): array
    {
        $base = self::baseUrl();
        $from = self::fromEmail();
        $fromName = self::fromName();

        // Live host accepts /api/v1/api/send (n8n + transactional docs). Marketing pages also cite /v1/api/send.
        $payloadFull = array_filter([
            'to' => $to,
            'from' => $from !== '' ? $from : null,
            'from_name' => $fromName !== '' ? $fromName : null,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ], static fn ($v) => $v !== null && $v !== '');
        $payloadMinimal = [
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
        $attempts = [
            ['url' => $base . '/api/v1/api/send', 'payload' => $payloadFull],
            ['url' => $base . '/api/v1/api/send', 'payload' => $payloadMinimal],
            ['url' => $base . '/v1/api/send', 'payload' => $payloadFull],
            ['url' => $base . '/v1/api/send', 'payload' => $payloadMinimal],
        ];

        $last = ['ok' => false, 'error' => 'Nexus send failed.', 'provider' => 'nexuspromail'];
        foreach ($attempts as $attempt) {
            $result = self::postJson($attempt['url'], $attempt['payload'], 'nexuspromail');
            $result['endpoint'] = $attempt['url'];
            if (!empty($result['ok'])) {
                return $result;
            }
            $last = $result;
            $http = (int) ($result['http'] ?? 0);
            // Wrong path — try next variant.
            if ($http === 404) {
                continue;
            }
            // Auth / validation / rate-limit: stop (retrying other paths will not fix a bad key).
            if (in_array($http, [401, 403, 422, 429], true)) {
                return $result;
            }
        }
        return $last;
    }

    /** @return array{ok:bool,error:?string,provider?:string,http?:int,raw?:string,endpoint?:string} */
    private static function sendWrNexus(string $to, string $subject, string $html, string $text): array
    {
        $senderKey = self::senderKey();
        if ($senderKey === '') {
            return [
                'ok' => false,
                'error' => 'WRNexus requires NEXUS_SENDER_KEY (assigned sender credential). Create one in the Nexus dashboard, then set it in .env.',
            ];
        }
        $url = 'https://api.wrnexus.com/email/send';
        if (str_contains(self::baseUrl(), 'wrnexus')) {
            $url = self::baseUrl() . '/email/send';
        }
        $payload = [
            'to' => $to,
            'senderKey' => $senderKey,
            'fromName' => self::fromName(),
            'subject' => $subject,
            'htmlBody' => $html,
            'textBody' => $text,
        ];
        $result = self::postJson($url, $payload, 'wrnexus');
        $result['endpoint'] = $url;
        return $result;
    }

    /** @return array{ok:bool,error:?string,provider?:string,http?:int,raw?:string} */
    private static function postJson(string $url, array $payload, string $provider): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Authorization: Bearer ' . self::apiKey(),
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $http = 0;
        $raw = '';
        $err = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            if (class_exists('SslCa')) {
                SslCa::applyCurl($ch);
            } else {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            }
            $raw = (string) curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch) ?: '';
            curl_close($ch);
            if ($err !== '' && class_exists('SslCa') && ($hint = SslCa::diagnoseHint($err))) {
                $err = $err . ' — ' . $hint;
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'content' => $json,
                    'timeout' => 25,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = (string) @file_get_contents($url, false, $ctx);
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $http = (int) $m[1];
            }
        }

        if ($raw === '' && $err !== '') {
            return ['ok' => false, 'error' => 'Nexus network error: ' . $err, 'provider' => $provider, 'http' => $http];
        }

        $data = json_decode($raw, true);
        if ($http >= 200 && $http < 300) {
            if (is_array($data) && isset($data['success']) && $data['success'] === false) {
                return [
                    'ok' => false,
                    'error' => self::safeError((string) ($data['message'] ?? $data['error'] ?? 'Nexus rejected the send.')),
                    'provider' => $provider,
                    'http' => $http,
                    'raw' => self::safeError($raw),
                ];
            }
            return ['ok' => true, 'error' => null, 'provider' => $provider, 'http' => $http];
        }

        $msg = '';
        if (is_array($data)) {
            if (isset($data['error']) && is_array($data['error'])) {
                $msg = (string) ($data['error']['message'] ?? json_encode($data['error']));
            } else {
                $msg = (string) ($data['message'] ?? $data['error'] ?? '');
            }
        }
        if ($msg === '') {
            $msg = $http === 401
                ? 'Nexus rejected the API key (HTTP 401). Use a Premium/Enterprise (or valid sandbox) key from the Nexus dashboard.'
                : ('Nexus send failed (HTTP ' . $http . ').');
        }
        return [
            'ok' => false,
            'error' => self::safeError($msg),
            'provider' => $provider,
            'http' => $http,
            'raw' => self::safeError(mb_substr($raw, 0, 300)),
        ];
    }

    private static function safeError(string $msg): string
    {
        $msg = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer REDACTED', $msg) ?? $msg;
        $key = self::apiKey();
        if ($key !== '') {
            $msg = str_replace($key, 'REDACTED', $msg);
        }
        return mb_substr(trim($msg), 0, 400);
    }

    /** Configuration checklist for admins (no secrets). */
    public static function checklist(): array
    {
        return [
            'NEXUS_API_KEY' => self::apiKey() !== '' ? 'set' : 'missing',
            'NEXUS_API_URL' => self::baseUrl(),
            'NEXUS_PROVIDER' => self::provider(),
            'NEXUS_FROM_EMAIL' => self::fromEmail() !== '' ? 'set' : 'missing',
            'NEXUS_FROM_NAME' => self::fromName(),
            'NEXUS_SENDER_KEY' => self::provider() === 'wrnexus'
                ? (self::senderKey() !== '' ? 'set' : 'missing (required for WRNexus)')
                : (self::senderKey() !== '' ? 'set' : 'optional'),
            'templates' => [
                'registration_received' => self::templateId('registration_received') ?: '(inline HTML used)',
                'account_approved' => self::templateId('account_approved') ?: '(inline HTML used)',
                'account_rejected' => self::templateId('account_rejected') ?: '(inline HTML used)',
                'activation' => self::templateId('activation') ?: '(inline HTML used)',
                'password_reset' => self::templateId('password_reset') ?: '(inline HTML used)',
            ],
        ];
    }
}
