<?php

/**
 * Extra server checks for worker field records.
 * Workers may report their own work. They cannot post costs, record another
 * employee's attendance, edit someone else's growth note, or file livestock deaths.
 */
class WorkerRecords
{
    /** Modules where a worker must not enter a cost amount. */
    public static function costModules(): array
    {
        return ['livestock_feed', 'livestock_health', 'water_logs', 'equipment_logs'];
    }

    public static function saveError(string $module, array $payload, ?array $old): ?string
    {
        if (Auth::role() !== 'worker') {
            return null;
        }
        if ($module === 'attendance') {
            return self::attendanceError($payload);
        }
        if (in_array($module, self::costModules(), true)) {
            $cost = round((float) ($payload['cost'] ?? 0), 2);
            if ($cost > 0) {
                return 'Workers can record the field activity, but not a cost.';
            }
        }
        if ($module === 'livestock_health' && (string) ($payload['record_type'] ?? '') === 'death') {
            return 'Workers cannot mark an animal as deceased. Ask a manager or owner.';
        }
        if ($module === 'crop_growth' && $old) {
            return self::growthEditError($old);
        }
        if ($module === 'storage_lots') {
            return 'Workers cannot create or change storage lots.';
        }
        return null;
    }

    public static function growthEditError(array $row): ?string
    {
        if (Auth::role() !== 'worker') {
            return null;
        }
        $ownerId = (int) ($row['recorded_by'] ?? 0);
        if ($ownerId <= 0 || $ownerId !== (int) Auth::id()) {
            return 'You can only update a growth note you recorded.';
        }
        return null;
    }

    public static function attendanceError(array $payload): ?string
    {
        if (Auth::role() !== 'worker') {
            return null;
        }
        $employeeId = class_exists('TaskAccess') ? TaskAccess::employeeIdForUser() : null;
        if (!$employeeId) {
            return 'Link your account to an employee record before recording attendance.';
        }
        if ((int) ($payload['employee_id'] ?? 0) !== $employeeId) {
            return 'You can only record your own attendance.';
        }
        return null;
    }

    /**
     * Zero cost fields for workers before hooks run (defence in depth).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function sanitizePayload(string $module, array $payload): array
    {
        if (Auth::role() !== 'worker') {
            return $payload;
        }
        if (in_array($module, self::costModules(), true) && array_key_exists('cost', $payload)) {
            $payload['cost'] = 0;
        }
        return $payload;
    }

    /** Hide money fields when the role cannot view transactions. */
    public static function withoutValuation(array $def): array
    {
        if (Auth::can('transactions', 'view')) {
            return $def;
        }
        foreach (['unit_cost', 'purchase_cost', 'cost', 'labour_cost'] as $field) {
            if (isset($def['fields'][$field])) {
                unset($def['fields'][$field]);
            }
        }
        return $def;
    }
}
