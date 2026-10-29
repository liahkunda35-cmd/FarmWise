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

        $lang = GeminiService::normalizeLanguage((string) ($body['language'] ?? $body['lang'] ?? 'en'));
        self::persistLanguagePreference($lang);

        // Client may request mode, but farm data is gated strictly by Auth + mode
        $requestedMode = strtolower(trim((string) ($body['mode'] ?? 'public')));
        $isAuth = Auth::check();

        if ($requestedMode === 'auth') {
            if (!$isAuth) {
                self::json(401, [
                    'ok' => false,
                    'error' => 'Please sign in to use the FarmWise farm assistant.',
                    'mode' => 'public',
                ]);
            }
            $mode = 'auth';
        } else {
            // Landing / public assistant — never attach private farm records
            $mode = 'public';
        }

        $firstName = '';
        $farmContext = null;
        if ($mode === 'auth' && $isAuth) {
            $firstName = self::firstName((string) (Auth::user()['name'] ?? ''));
            try {
                $farmContext = FarmAssistantContext::snapshot();
            } catch (Throwable $e) {
                GeminiService::logSafe('farm_context', get_class($e));
                $farmContext = 'Farm record snapshot unavailable right now. Give general FarmWise guidance and ask the user to check their dashboard modules. Do not invent farm numbers or weather.';
            }
        }

        $ui = GeminiService::uiStrings($lang);

        // Greeting short-circuit (no Gemini call)
        $topic = GeminiService::topicDecision($message, is_array($history) ? $history : [], $lang);
        if ($topic['action'] === 'greeting') {
            $part = self::dayPart();
            if ($mode === 'auth' && $firstName !== '') {
                $reply = str_replace(['{part}', '{name}'], [$part, $firstName], $ui['greeting_auth']);
            } elseif ($mode === 'auth') {
                $reply = str_replace('{part}', $part, $ui['greeting_auth_noname']);
            } else {
                $reply = str_replace('{part}', $part, $ui['greeting_public']);
            }
            self::json(200, [
                'ok' => true,
                'reply' => $reply,
                'model' => 'local-greeting',
                'mode' => $mode,
                'language' => $lang,
                'authenticated' => $isAuth && $mode === 'auth',
                'first_name' => $firstName,
                'used_farm_context' => false,
                'ai_configured' => GeminiService::configured(),
            ]);
        }

        try {
            $result = GeminiService::chat($message, $history, $farmContext, $mode, $firstName, $lang);
        } catch (Throwable $e) {
            GeminiService::logSafe('assistant_exception', get_class($e));
            self::json(500, [
                'ok' => false,
                'error' => $ui['error'],
                'language' => $lang,
            ]);
        }

        if (empty($result['ok'])) {
            self::json(503, [
                'ok' => false,
                'error' => (string) ($result['error'] ?? $ui['error']),
                'mode' => $mode,
                'language' => $lang,
            ]);
        }

        self::json(200, [
            'ok' => true,
            'reply' => (string) $result['text'],
            'model' => (string) ($result['model'] ?? GeminiService::model()),
            'mode' => $mode,
            'language' => $lang,
            'authenticated' => $isAuth && $mode === 'auth',
            'first_name' => $firstName,
            'used_farm_context' => $farmContext !== null,
            'ai_configured' => GeminiService::configured(),
        ]);
    }

    /** Persist preferred assistant language for signed-in users. */
    private static function persistLanguagePreference(string $lang): void
    {
        $lang = GeminiService::normalizeLanguage($lang);
        $_SESSION['assistant_language'] = $lang;
        if (!Auth::check()) {
            return;
        }
        $uid = (int) (Auth::id() ?? 0);
        if ($uid <= 0 || !class_exists('Settings')) {
            return;
        }
        try {
            Settings::put('assistant_lang_user_' . $uid, $lang);
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    public static function preferredLanguage(): string
    {
        if (!empty($_SESSION['assistant_language'])) {
            return GeminiService::normalizeLanguage((string) $_SESSION['assistant_language']);
        }
        if (Auth::check() && class_exists('Settings')) {
            $uid = (int) (Auth::id() ?? 0);
            if ($uid > 0) {
                try {
                    $saved = Settings::get('assistant_lang_user_' . $uid);
                    if (is_string($saved) && $saved !== '') {
                        return GeminiService::normalizeLanguage($saved);
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
        return 'en';
    }

    private static function dayPart(): string
    {
        $h = (int) date('G');
        if ($h < 12) {
            return 'morning';
        }
        if ($h < 17) {
            return 'afternoon';
        }
        return 'evening';
    }

    private static function firstName(string $fullName): string
    {
        $fullName = trim(preg_replace('/\s+/u', ' ', $fullName) ?? '');
        if ($fullName === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $fullName) ?: [];
        $first = (string) ($parts[0] ?? '');
        $first = preg_replace('/[^\p{L}\p{N}.\'\-]/u', '', $first) ?? '';
        return mb_substr($first, 0, 40);
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
