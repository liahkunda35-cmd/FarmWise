<?php

class SmsGateway
{
    public static function send(string $to, string $message): array
    {
        $to = preg_replace('/\s+/', '', $to);
        if ($to === '' || !preg_match('/^\+?[0-9]{8,15}$/', $to)) {
            return ['ok' => false, 'error' => 'Invalid mobile number. Use international format, e.g. +26097...'];
        }
        $provider = strtolower((string) (Settings::get('sms_provider') ?: 'none'));
        if ($provider === 'none' || $provider === '') {
            return ['ok' => false, 'error' => 'SMS provider is not configured.'];
        }
        if ($provider === 'africastalking') {
            return self::africaTalking($to, $message);
        }
        if ($provider === 'twilio') {
            return self::twilio($to, $message);
        }
        return self::generic($to, $message);
    }

    private static function africaTalking(string $to, string $message): array
    {
        $username = Settings::get('sms_user');
        $apiKey = Settings::get('sms_api_key');
        if (!$username || !$apiKey) {
            return ['ok' => false, 'error' => 'Africa\'s Talking username and API key are required.'];
        }
        $fields = [
            'username' => $username,
            'to' => $to,
            'message' => $message,
        ];
        $from = Settings::get('sms_sender');
        if ($from) {
            $fields['from'] = $from;
        }
        return self::http('https://api.africastalking.com/version1/messaging', $fields, [
            'apiKey: ' . $apiKey,
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    private static function twilio(string $to, string $message): array
    {
        $sid = Settings::get('sms_user');
        $token = Settings::get('sms_api_key');
        $from = Settings::get('sms_sender');
        if (!$sid || !$token || !$from) {
            return ['ok' => false, 'error' => 'Twilio Account SID, auth token and sender number are required.'];
        }
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
        return self::http($url, [
            'To' => $to,
            'From' => $from,
            'Body' => $message,
        ], [], $sid . ':' . $token);
    }

    private static function generic(string $to, string $message): array
    {
        $url = Settings::get('sms_url');
        if (!$url) {
            return ['ok' => false, 'error' => 'Generic SMS requires an HTTP endpoint URL.'];
        }
        return self::http($url, [
            'to' => $to,
            'message' => $message,
            'api_key' => Settings::get('sms_api_key'),
            'sender' => Settings::get('sms_sender'),
        ], ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);
    }

    private static function http(string $url, array $fields, array $headers = [], ?string $basic = null): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL is required to send SMS.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($basic) {
            curl_setopt($ch, CURLOPT_USERPWD, $basic);
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'error' => $err ?: 'SMS HTTP request failed.'];
        }
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => 'SMS gateway HTTP ' . $code . ': ' . substr((string) $body, 0, 240)];
    }
}
