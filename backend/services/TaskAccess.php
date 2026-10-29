<?php

/**
 * Task visibility helpers: workers only see tasks assigned to their employee record.
 */
class TaskAccess
{
    /** Employee row for the logged-in user on the active (or given) farm. */
    public static function employeeForUser(?int $userId = null, ?int $farmId = null): ?array
    {
        $userId = $userId ?: Auth::id();
        if (!$userId) {
            return null;
        }
        $farmId = $farmId ?: Auth::farmId();
        if ($farmId) {
            $row = db()->first(
                'SELECT * FROM employees WHERE user_id = ? AND farm_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
                [$userId, $farmId, 'active']
            );
            if ($row) {
                return $row;
            }
        }
        return db()->first(
            'SELECT * FROM employees WHERE user_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$userId, 'active']
        );
    }

    public static function employeeIdForUser(?int $userId = null, ?int $farmId = null): ?int
    {
        $emp = self::employeeForUser($userId, $farmId);
        return $emp ? (int) $emp['id'] : null;
    }

    /** Workers may only view/edit tasks assigned to them. */
    public static function workerCanAccessTask(array $task): bool
    {
        if (Auth::role() !== 'worker') {
            return true;
        }
        $empId = self::employeeIdForUser();
        if (!$empId) {
            return false;
        }
        return (int) ($task['assigned_to'] ?? 0) === $empId;
    }

    /**
     * Extra SQL for task lists. Non-workers are unchanged.
     * Workers only match their own employee id.
     *
     * @return array{0:string,1:array}
     */
    public static function listConstraint(string $column = 'assigned_to'): array
    {
        if (Auth::role() !== 'worker') {
            return ['', []];
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            return [' AND 1=0', []];
        }
        $empId = self::employeeIdForUser();
        if (!$empId) {
            return [' AND 1=0', []];
        }
        return [' AND ' . $column . ' = ?', [$empId]];
    }

    /**
     * Validate assigned employee belongs to the task farm.
     * @return string|null error
     */
    public static function validateAssignee(?int $employeeId, ?int $farmId): ?string
    {
        if (!$employeeId) {
            return null;
        }
        $emp = db()->find('employees', $employeeId);
        if (!$emp) {
            return 'Assigned worker was not found.';
        }
        if ($farmId && (int) ($emp['farm_id'] ?? 0) !== (int) $farmId) {
            return 'Assigned worker must belong to the same farm as the task.';
        }
        if (($emp['status'] ?? '') === 'inactive') {
            return 'Cannot assign a task to an inactive worker.';
        }
        return null;
    }
}
