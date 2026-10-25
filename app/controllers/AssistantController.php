<?php

class AssistantController
{
    public static function chat(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (!is_post()) {
            self::json(405, ['ok' => false, 'error' => 'POST required.']);
        }

        $body = self::body();
        $csrf = (string) (
            $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_CSRF']
            ?? ($body['_csrf'] ?? '')
            ?? ($_POST['_csrf'] ?? '')
        );
        $expected = Csrf::token();
        if ($csrf === '' || !hash_equals($expected, $csrf)) {
            self::json(419, ['ok' => false, 'error' => 'Security token expired. Refresh the page and try again.']);
        }

        $rate = GeminiService::checkRateLimit();
        if ($rate !== null) {
            self::json(429, ['ok' => false, 'error' => $rate]);
        }

        $message = trim((string) ($body['message'] ?? ''));
        $history = $body['history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        // Farm records only for signed-in users with access; guests get general guidance only
        $farmContext = null;
        if (Auth::check()) {
            $includeFarm = !empty($body['include_farm_context']);
            if ($includeFarm || self::wantsFarmData($message)) {
                try {
                    $farmContext = FarmAssistantContext::snapshot();
                } catch (Throwable $e) {
                    $farmContext = 'Farm record snapshot unavailable right now.';
                }
            }
        }

        try {
            $result = GeminiService::chat($message, $history, $farmContext);
        } catch (Throwable $e) {
            self::json(500, ['ok' => false, 'error' => 'The assistant could not respond. Please try again.']);
        }

        if (empty($result['ok'])) {
            self::json(503, [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Assistant unavailable.'),
            ]);
        }

        self::json(200, [
            'ok' => true,
            'reply' => (string) $result['text'],
            'model' => GeminiService::model(),
            'used_farm_context' => $farmContext !== null,
        ]);
    }

    private static function wantsFarmData(string $message): bool
    {
        $m = strtolower($message);
        foreach (['my farm', 'my crop', 'my livestock', 'my expense', 'my harvest', 'our farm', 'how many', 'total expense', 'total income', 'what do i have', 'current planting', 'my inventory', 'my task'] as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function body(): array
    {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST;
    }

    private static function json(int $code, array $payload): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
