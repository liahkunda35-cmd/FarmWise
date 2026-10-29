<?php

/**
 * Cross-module workflows that stay inside the existing permission and farm checks.
 * Callers must already have authorised the source record.
 */
class WorkflowService
{
    /** @return array{days_worked:?float,gross_pay:?float,net_pay:?float,calculation_note:string,has_rows:bool} */
    public static function attendanceSuggestion(int $employeeId, int $farmId, string $start, string $end): array
    {
        $blank = static function (string $note): array {
            return [
                'days_worked' => null,
                'gross_pay' => null,
                'net_pay' => null,
                'calculation_note' => $note,
                'has_rows' => false,
            ];
        };
        if ($employeeId <= 0 || $farmId <= 0 || !is_valid_date($start) || !is_valid_date($end) || $start > $end) {
            return $blank('The payroll period or employee is incomplete, so pay was not calculated.');
        }
        $employee = db()->find('employees', $employeeId);
        if (!$employee || (int) ($employee['farm_id'] ?? 0) !== $farmId) {
            return $blank('This employee is not on the payroll farm, so attendance was not used.');
        }
        $rows = db()->select(
            'SELECT work_date, status FROM attendance WHERE employee_id = ? AND farm_id = ? AND work_date >= ? AND work_date <= ? ORDER BY id',
            [$employeeId, $farmId, $start, $end]
        );
        if (!$rows) {
            return $blank('No attendance was recorded for ' . $start . ' to ' . $end . '. Days worked were left blank so a missing record is not treated as zero.');
        }
        $byDate = [];
        $dupes = [];
        $rank = ['present' => 3, 'half_day' => 2, 'absent' => 1, 'leave' => 1];
        foreach ($rows as $row) {
            $date = (string) ($row['work_date'] ?? '');
            if ($date === '') {
                continue;
            }
            if (isset($byDate[$date])) {
                $dupes[$date] = true;
            }
            $score = $rank[strtolower((string) ($row['status'] ?? ''))] ?? 0;
            $prev = $byDate[$date]['_rank'] ?? -1;
            if ($score >= $prev) {
                $byDate[$date] = ['status' => strtolower((string) ($row['status'] ?? '')), '_rank' => $score];
            }
        }
        $days = 0.0;
        $absent = 0;
        $unknown = 0;
        foreach ($byDate as $row) {
            if ($row['status'] === 'present') {
                $days += 1;
            } elseif ($row['status'] === 'half_day') {
                $days += 0.5;
            } elseif ($row['status'] === 'absent' || $row['status'] === 'leave') {
                $absent++;
            } else {
                $unknown++;
            }
        }
        $notes = [count($byDate) . ' distinct day(s) from ' . count($rows) . ' attendance row(s).'];
        if ($dupes) {
            $notes[] = 'Duplicate dates were counted once: ' . implode(', ', array_keys($dupes)) . '.';
        }
        if ($absent) {
            $notes[] = $absent . ' absence or leave day(s) were not paid.';
        }
        if ($unknown) {
            $notes[] = $unknown . ' row(s) had an unrecognised status and were not counted.';
        }
        $rate = $employee['daily_rate'] ?? null;
        $gross = null;
        if ($rate === null || $rate === '' || !is_numeric($rate) || (float) $rate <= 0) {
            $notes[] = 'This employee has no daily rate, so pay was not calculated.';
        } else {
            $gross = round($days * (float) $rate, 2);
            $notes[] = 'Suggested gross is days worked × daily rate (' . $rate . '). Review this draft before approval. Payroll was not marked paid.';
        }
        return [
            'days_worked' => $days,
            'gross_pay' => $gross,
            'net_pay' => $gross,
            'calculation_note' => implode(' ', $notes),
            'has_rows' => true,
        ];
    }

    /**
     * Fill a draft payroll payload from attendance. Approved and paid rows are left unchanged.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $previous
     * @return array<string,mixed>
     */
    public static function applyAttendanceSuggestion(array $payload, ?array $previous = null): array
    {
        $locked = $previous && in_array((string) ($previous['status'] ?? ''), ['approved', 'paid'], true);
        if ($locked) {
            return $payload;
        }
        if ((string) ($payload['status'] ?? 'draft') !== 'draft') {
            return $payload;
        }
        if (($payload['days_worked'] ?? null) !== null && $payload['days_worked'] !== '') {
            return $payload;
        }
        $suggestion = self::attendanceSuggestion(
            (int) ($payload['employee_id'] ?? 0),
            (int) ($payload['farm_id'] ?? 0),
            (string) ($payload['period_start'] ?? ''),
            (string) ($payload['period_end'] ?? '')
        );
        if ($suggestion['has_rows'] && $suggestion['days_worked'] !== null) {
            $payload['days_worked'] = $suggestion['days_worked'];
        }
        if (($payload['gross_pay'] === null || $payload['gross_pay'] === '') && $suggestion['gross_pay'] !== null) {
            $payload['gross_pay'] = $suggestion['gross_pay'];
        }
        $deductions = is_numeric($payload['deductions'] ?? null) ? (float) $payload['deductions'] : 0.0;
        if (($payload['net_pay'] === null || $payload['net_pay'] === '') && $payload['gross_pay'] !== null && $payload['gross_pay'] !== '') {
            $payload['net_pay'] = round((float) $payload['gross_pay'] - $deductions, 2);
        }
        if (trim((string) ($payload['calculation_note'] ?? '')) === '') {
            $payload['calculation_note'] = $suggestion['calculation_note'];
        }
        return $payload;
    }

    public static function findScoutFollowUp(int $scoutingId): ?int
    {
        if ($scoutingId <= 0) {
            return null;
        }
        $row = db()->first(
            "SELECT id FROM tasks WHERE related_module = 'pest_scouting' AND related_id = ? ORDER BY id LIMIT 1",
            [$scoutingId]
        );
        return $row ? (int) $row['id'] : null;
    }

    /** Serialise follow-up creation for one scouting record inside the current transaction. */
    public static function lockScoutFollowUp(int $scoutingId): void
    {
        if ($scoutingId <= 0 || db()->driver() !== 'pgsql') {
            return;
        }
        db()->query('SELECT pg_advisory_xact_lock(?)', [730000000 + ($scoutingId % 100000000)]);
    }

    /**
     * Reject a task that points at a scouting record on another farm or field,
     * or at a scouting record that already has a different follow-up task.
     */
    public static function scoutLinkError(array $payload, ?int $ignoreTaskId = null): ?string
    {
        if ((string) ($payload['related_module'] ?? '') !== 'pest_scouting') {
            return null;
        }
        $scoutingId = (int) ($payload['related_id'] ?? 0);
        if ($scoutingId <= 0) {
            return null;
        }
        $scout = db()->find('pest_scouting', $scoutingId);
        $farmId = (int) ($payload['farm_id'] ?? 0);
        if (!$scout || (int) ($scout['farm_id'] ?? 0) !== $farmId) {
            return 'That scouting record is not on this farm.';
        }
        $scoutField = (int) ($scout['field_id'] ?? 0);
        $taskField = (int) ($payload['field_id'] ?? 0);
        if ($scoutField > 0 && $taskField > 0 && $taskField !== $scoutField) {
            return 'The task field must match the scouting field.';
        }
        $existing = self::findScoutFollowUp($scoutingId);
        if ($existing && $existing !== (int) $ignoreTaskId) {
            return 'A follow-up task already exists for this scouting record.';
        }
        return null;
    }

    /** Draft follow-up task for a high or critical scouting record. Returns the task id. */
    public static function ensureScoutTask(array $payload, int $scoutingId): ?int
    {
        if ($scoutingId <= 0) {
            return null;
        }
        $severity = strtolower((string) ($payload['severity'] ?? ''));
        if (!in_array($severity, ['high', 'critical'], true)) {
            return null;
        }
        self::lockScoutFollowUp($scoutingId);
        $existing = self::findScoutFollowUp($scoutingId);
        if ($existing) {
            return $existing;
        }
        $farmId = (int) ($payload['farm_id'] ?? 0);
        if ($farmId <= 0 || !db()->find('farms', $farmId)) {
            return null;
        }
        $fieldId = (int) ($payload['field_id'] ?? 0);
        $field = $fieldId > 0 ? db()->find('fields', $fieldId) : null;
        if (!$field || (int) ($field['farm_id'] ?? 0) !== $farmId) {
            $fieldId = 0;
        }
        $pestName = 'pest or disease';
        $control = '';
        $pestId = (int) ($payload['pest_id'] ?? 0);
        if ($pestId > 0) {
            $pest = db()->find('pest_catalog', $pestId);
            $pestFarm = (int) ($pest['farm_id'] ?? 0);
            if ($pest && ($pestFarm === 0 || $pestFarm === $farmId)) {
                $pestName = (string) ($pest['name'] ?: $pestName);
                $control = trim((string) ($pest['recommended_control'] ?? ''));
            }
        }
        $notes = trim((string) ($payload['notes'] ?? ''));
        $description = 'Draft follow-up from scouting record #' . $scoutingId . ' (' . $severity . ').';
        if ($notes !== '') {
            $description .= "\nObservation: " . $notes;
        }
        if ($control !== '') {
            $description .= "\nRecommended action: " . $control;
        }
        $description .= "\nA manager should review this draft and assign a worker.";
        $savepoint = self::savepoint('wf_scout_task');
        try {
            $taskId = (int) db()->insert('tasks', [
                'farm_id' => $farmId,
                'title' => mb_substr('Follow up: ' . $pestName . ' (' . $severity . ')', 0, 160),
                'description' => $description,
                'field_id' => $fieldId > 0 ? $fieldId : null,
                'related_module' => 'pest_scouting',
                'related_id' => $scoutingId,
                'created_by' => Auth::id(),
                'due_date' => date('Y-m-d', strtotime('+2 days')),
                'priority' => 'high',
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            self::releaseSavepoint('wf_scout_task', $savepoint);
            return $taskId;
        } catch (Throwable $e) {
            self::rollbackSavepoint('wf_scout_task', $savepoint);
            $again = db()->first(
                "SELECT id FROM tasks WHERE related_module = 'pest_scouting' AND related_id = ? ORDER BY id LIMIT 1",
                [$scoutingId]
            );
            return $again ? (int) $again['id'] : null;
        }
    }

    /**
     * One draft purchase order for an accepted quotation.
     * @return int|null existing or new purchase order id
     */
    public static function ensurePurchaseOrder(array $quote, int $quotationId): ?int
    {
        if ($quotationId <= 0 || (string) ($quote['status'] ?? '') !== 'accepted') {
            return null;
        }
        $existing = db()->first('SELECT id FROM purchase_orders WHERE quotation_id = ? ORDER BY id LIMIT 1', [$quotationId]);
        if ($existing) {
            return (int) $existing['id'];
        }
        $farmId = (int) ($quote['farm_id'] ?? 0);
        $supplierId = (int) ($quote['supplier_id'] ?? 0);
        $supplier = $supplierId > 0 ? db()->find('suppliers', $supplierId) : null;
        if ($farmId <= 0 || !$supplier || (int) ($supplier['farm_id'] ?? 0) !== $farmId) {
            return null;
        }
        $qty = $quote['quantity'] ?? null;
        $price = $quote['unit_price'] ?? null;
        $item = trim((string) ($quote['item_name'] ?? ''));
        if ($item === '' || !is_numeric($qty) || (float) $qty <= 0 || !is_numeric($price) || (float) $price < 0) {
            return null;
        }
        $total = is_numeric($quote['total'] ?? null) ? (float) $quote['total'] : round((float) $qty * (float) $price, 2);
        $number = 'PO-' . date('Ymd') . '-' . $quotationId;
        $savepoint = self::savepoint('wf_quote_po');
        try {
            $poId = (int) db()->insert('purchase_orders', [
                'farm_id' => $farmId,
                'supplier_id' => $supplierId,
                'quotation_id' => $quotationId,
                'po_number' => $number,
                'item_name' => mb_substr($item, 0, 160),
                'quantity' => $qty,
                'unit_price' => $price,
                'total' => $total,
                'order_date' => date('Y-m-d'),
                'status' => 'pending_approval',
                'payment_status' => 'unpaid',
                'notes' => 'Converted from quotation #' . ($quote['quote_number'] ?? (string) $quotationId),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            self::releaseSavepoint('wf_quote_po', $savepoint);
            return $poId;
        } catch (Throwable $e) {
            self::rollbackSavepoint('wf_quote_po', $savepoint);
            $again = db()->first('SELECT id FROM purchase_orders WHERE quotation_id = ? ORDER BY id LIMIT 1', [$quotationId]);
            return $again ? (int) $again['id'] : null;
        }
    }

    private static function savepoint(string $name): bool
    {
        try {
            db()->pdo()->exec('SAVEPOINT ' . $name);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function releaseSavepoint(string $name, bool $active): void
    {
        if (!$active) {
            return;
        }
        try {
            db()->pdo()->exec('RELEASE SAVEPOINT ' . $name);
        } catch (Throwable $e) {
        }
    }

    private static function rollbackSavepoint(string $name, bool $active): void
    {
        if (!$active) {
            return;
        }
        try {
            db()->pdo()->exec('ROLLBACK TO SAVEPOINT ' . $name);
        } catch (Throwable $e) {
        }
    }
}
