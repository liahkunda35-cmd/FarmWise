<?php

/**
 * Presentation bridge.
 *
 * Pages may call these functions. They must not open a database connection,
 * read environment secrets, or name backend classes. The host application
 * registers the handlers in farmwise_bind_frontend() (or its own binder).
 */
final class FrontendBridge
{
    /** @var array<string,callable> */
    private static array $handlers = [];

    public static function bind(string $name, callable $handler): void
    {
        self::$handlers[$name] = $handler;
    }

    public static function call(string $name, array $args = [], mixed $default = null): mixed
    {
        if (!isset(self::$handlers[$name])) {
            return $default;
        }
        return (self::$handlers[$name])(...$args);
    }
}

function fe_component(string $name): void
{
    $safe = str_replace(['..', '\\'], ['', '/'], $name);
    $file = __DIR__ . '/components/' . $safe . '.php';
    if (is_file($file)) {
        require $file;
    }
}

function fe_can(string $module, string $action = 'view'): bool
{
    return (bool) FrontendBridge::call('can', [$module, $action], false);
}

function fe_user(): ?array
{
    $user = FrontendBridge::call('user', [], null);
    return is_array($user) ? $user : null;
}

function fe_check(): bool
{
    return (bool) FrontendBridge::call('check', [], false);
}

function fe_id(): ?int
{
    $id = FrontendBridge::call('id', [], null);
    return $id === null || $id === '' ? null : (int) $id;
}

function fe_role(): string
{
    return (string) FrontendBridge::call('role', [], '');
}

function fe_roles(): array
{
    $roles = FrontendBridge::call('roles', [], []);
    return is_array($roles) ? $roles : [];
}

function fe_is_admin(): bool
{
    return (bool) FrontendBridge::call('is_admin', [], false);
}

function fe_farm_id(): ?int
{
    $id = FrontendBridge::call('farm_id', [], null);
    return $id ? (int) $id : null;
}

function fe_accessible_farms(): array
{
    $rows = FrontendBridge::call('accessible_farms', [], []);
    return is_array($rows) ? $rows : [];
}

function fe_can_manage_user(array $user): bool
{
    return (bool) FrontendBridge::call('can_manage_user', [$user], false);
}

function fe_csrf_field(): string
{
    return (string) FrontendBridge::call('csrf_field', [], '');
}

function fe_csrf_token(): string
{
    return (string) FrontendBridge::call('csrf_token', [], '');
}

function fe_unread_count($userId = null, $farmId = null): int
{
    return (int) FrontendBridge::call('unread', [$userId, $farmId], 0);
}

function fe_public_config(?string $key = null, mixed $default = null): mixed
{
    $public = FrontendBridge::call('public_config', [], []);
    if (!is_array($public)) {
        $public = [];
    }
    if ($key === null) {
        return $public;
    }
    return $public[$key] ?? $default;
}

function fe_active_farm(): ?array
{
    $farm = FrontendBridge::call('active_farm', [], null);
    return is_array($farm) ? $farm : null;
}
