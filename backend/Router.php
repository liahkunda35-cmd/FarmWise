<?php

class Router
{
    public static function dispatch(): void
    {
        $path = trim(current_path(), '/');
        $parts = $path === '' ? [] : explode('/', $path);
        $head = $parts[0] ?? '';

        if ($head === '' ) {
            AuthController::landing();
            return;
        }

        match ($head) {
            'login' => request_method() === 'POST' ? AuthController::login() : AuthController::loginForm(),
            'register' => request_method() === 'POST' ? AuthController::register() : AuthController::registerForm(),
            'activate' => request_method() === 'POST' ? AuthController::activate() : AuthController::activateForm(),
            'forgot-password' => request_method() === 'POST' ? AuthController::forgot() : AuthController::forgotForm(),
            'reset-password' => request_method() === 'POST' ? AuthController::reset() : AuthController::resetForm(),
            'logout' => AuthController::logout(),
            'api' => ApiController::dispatch($parts),
            // JSON assistant endpoint (auth checked inside controller — avoid HTML login redirect)
            'assistant' => self::assistant($parts),
            default => self::protected($parts),
        };
    }

    private static function assistant(array $parts): void
    {
        $action = $parts[1] ?? '';
        if ($action === 'chat' && is_post()) {
            AssistantController::chat();
            return;
        }
        abort(404, 'Assistant route not found.');
    }

    private static function protected(array $parts): void
    {
        Auth::requireAuth();
        $head = $parts[0] ?? 'dashboard';
        $a = $parts[1] ?? null;
        $b = $parts[2] ?? null;
        $c = $parts[3] ?? null;

        if ($head === 'dashboard') {
            DashboardController::index();
            return;
        }
        if ($head === 'tasks' && $a === 'complete' && $b && is_post()) {
            DashboardController::completeTask($b);
            return;
        }
        if ($head === 'profile') {
            if ($a === 'password' && is_post()) {
                WorkspaceController::changePassword();
                return;
            }
            if (is_post()) {
                WorkspaceController::saveProfile();
                return;
            }
            WorkspaceController::profile();
            return;
        }
        if ($head === 'switch-farm' && is_post()) {
            WorkspaceController::switchFarm();
            return;
        }
        if ($head === 'join-farm' && is_post()) {
            WorkspaceController::joinFarm();
            return;
        }
        if ($head === 'settings') {
            if (is_post()) {
                WorkspaceController::saveSettings();
                return;
            }
            WorkspaceController::settings();
            return;
        }
        if ($head === 'calendar') {
            WorkspaceController::calendar();
            return;
        }
        if ($head === 'weather') {
            AppPagesController::weather();
            return;
        }
        if ($head === 'notifications') {
            if ($a === 'read-all' && is_post()) {
                AppPagesController::markAllRead();
                return;
            }
            if ($a === 'read' && $b) {
                AppPagesController::markRead($b);
                return;
            }
            AppPagesController::notifications();
            return;
        }
        if ($head === 'reports') {
            AppPagesController::reports();
            return;
        }
        if ($head === 'forecasts') {
            if ($a === 'generate' && is_post()) {
                AppPagesController::generateForecast();
                return;
            }
            AppPagesController::forecasts();
            return;
        }
        if ($head === 'market-insights') {
            AppPagesController::marketInsights();
            return;
        }
        if ($head === 'users') {
            if ($a === 'pending' && $b) {
                if (is_post() && $c === 'approve') {
                    AppPagesController::approveRegistration($b);
                    return;
                }
                if (is_post() && $c === 'reject') {
                    AppPagesController::rejectRegistration($b);
                    return;
                }
                AppPagesController::pendingRegistration($b);
                return;
            }
            if ($a === 'edit' && $b) {
                AppPagesController::editUser($b);
                return;
            }
            if ($a === 'save') {
                AppPagesController::saveUser($b);
                return;
            }
            if ($a === 'delete' && $b) {
                AppPagesController::deleteUser($b);
                return;
            }
            AppPagesController::users();
            return;
        }
        if ($head === 'audit') {
            AppPagesController::audit();
            return;
        }
        if ($head === 'backup') {
            if ($a === 'run') {
                AppPagesController::runBackup();
                return;
            }
            if ($a === 'download') {
                AppPagesController::downloadBackup(input('file'));
                return;
            }
            if ($a === 'restore' && is_post()) {
                AppPagesController::restoreBackup(input('file'));
                return;
            }
            AppPagesController::backup();
            return;
        }
        if ($head === 'search') {
            WorkspaceController::search();
            return;
        }
        if ($head === 'map') {
            AppPagesController::map();
            return;
        }
        if ($head === 'geocode') {
            AppPagesController::geocode();
            return;
        }
        if ($head === 'integrations') {
            if ($a === 'save' && is_post()) {
                AppPagesController::saveIntegrations();
                return;
            }
            if ($a === 'test-email' && is_post()) {
                AppPagesController::testEmail();
                return;
            }
            if ($a === 'test-sms' && is_post()) {
                AppPagesController::testSms();
                return;
            }
            AppPagesController::integrations();
            return;
        }
        if ($head === 'messages') {
            AppPagesController::messages();
            return;
        }
        if ($head === 'module' && $a) {
            ResourceController::handle($a, $b, $c);
            return;
        }
        abort(404, 'Page not found.');
    }
}
