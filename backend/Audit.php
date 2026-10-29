<?php

class Audit
{
    public static function log(string $action, ?string $module = null, $recordId = null, ?string $details = null): void
    {
        try {
            db()->insert('audit_logs', [
                'user_id' => Auth::id(),
                'farm_id' => Auth::farmId(),
                'action' => $action,
                'module' => $module,
                'record_id' => $recordId,
                'details' => $details,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            // never break the request because of audit
        }
    }
}
