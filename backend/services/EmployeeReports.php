<?php

/**
 * Individual and overall employee PDF reports from real DB records only.
 */
class EmployeeReports
{
    public static function buildIndividual(array $employee): array
    {
        $farm = !empty($employee['farm_id']) ? db()->find('farms', $employee['farm_id']) : null;
        $user = !empty($employee['user_id']) ? db()->find('users', $employee['user_id']) : null;
        $attendance = db()->select(
            'SELECT work_date, check_in, check_out, hours_worked, status, notes
             FROM attendance WHERE employee_id = ? ORDER BY work_date DESC LIMIT 40',
            [$employee['id']]
        );
        $tasks = db()->select(
            'SELECT title, due_date, priority, status, labour_cost
             FROM tasks WHERE assigned_to = ? ORDER BY id DESC LIMIT 40',
            [$employee['id']]
        );
        $payroll = db()->select(
            'SELECT period_start, period_end, days_worked, gross_pay, net_pay, status, paid_date
             FROM payroll WHERE employee_id = ? ORDER BY period_end DESC LIMIT 20',
            [$employee['id']]
        );

        return [
            'employee' => $employee,
            'farm' => $farm,
            'user' => $user,
            'attendance' => $attendance,
            'tasks' => $tasks,
            'payroll' => $payroll,
            'generated_at' => date('Y-m-d H:i'),
        ];
    }

    public static function pdfBytes(array $data): string
    {
        $e = $data['employee'];
        $farm = $data['farm'] ?? [];
        $user = $data['user'] ?? null;
        $lines = [
            ['style' => 'title', 'text' => 'FarmWise — Employee Report'],
            ['style' => 'muted', 'text' => 'Generated ' . ($data['generated_at'] ?? date('Y-m-d H:i'))],
            ['style' => 'spacer', 'text' => ''],
            ['style' => 'heading', 'text' => 'Employee information'],
            ['style' => 'body', 'text' => 'Name: ' . ($e['name'] ?? '—')],
            ['style' => 'body', 'text' => 'Employee ID: EMP-' . ($e['id'] ?? '')],
            ['style' => 'body', 'text' => 'Phone: ' . (($e['phone'] ?? '') !== '' ? $e['phone'] : '—')],
            ['style' => 'body', 'text' => 'Email: ' . (($e['email'] ?? '') !== '' ? $e['email'] : '—')],
            ['style' => 'body', 'text' => 'Farm: ' . ($farm['name'] ?? '—')],
            ['style' => 'body', 'text' => 'Role: ' . (($e['role_title'] ?? '') !== '' ? $e['role_title'] : '—')],
            ['style' => 'body', 'text' => 'Employment type: ' . (($e['employment_type'] ?? '') !== '' ? $e['employment_type'] : '—')],
            ['style' => 'body', 'text' => 'Date employed: ' . dater($e['hire_date'] ?? null)],
            ['style' => 'body', 'text' => 'Status: ' . ($e['status'] ?? '—')],
            ['style' => 'body', 'text' => 'Daily / wage rate: ' . (isset($e['daily_rate']) ? money($e['daily_rate']) : '—')],
        ];
        if ($user) {
            $lines[] = ['style' => 'body', 'text' => 'Login: ' . (($user['username'] ?? '') !== '' ? $user['username'] : ($user['email'] ?? '—'))
                . ' · account status ' . ($user['status'] ?? '—')
                . (!empty($user['must_change_password']) ? ' · must change password' : '')];
        } else {
            $lines[] = ['style' => 'muted', 'text' => 'No linked login account.'];
        }
        if (!empty($e['notes'])) {
            $lines[] = ['style' => 'muted', 'text' => 'Notes: ' . $e['notes']];
        }

        $lines[] = ['style' => 'spacer', 'text' => ''];
        $lines[] = ['style' => 'heading', 'text' => 'Attendance (recent)'];
        if (empty($data['attendance'])) {
            $lines[] = ['style' => 'muted', 'text' => 'No attendance records in the database.'];
        } else {
            foreach ($data['attendance'] as $a) {
                $lines[] = ['style' => 'body', 'text' => dater($a['work_date']) . ' · ' . ($a['status'] ?? '')
                    . ' · in ' . (($a['check_in'] ?? '') !== '' ? $a['check_in'] : '—')
                    . ' · out ' . (($a['check_out'] ?? '') !== '' ? $a['check_out'] : '—')
                    . ' · hours ' . (($a['hours_worked'] ?? '') !== '' ? $a['hours_worked'] : '—')];
            }
        }

        $lines[] = ['style' => 'spacer', 'text' => ''];
        $lines[] = ['style' => 'heading', 'text' => 'Assigned tasks (recent)'];
        if (empty($data['tasks'])) {
            $lines[] = ['style' => 'muted', 'text' => 'No assigned tasks in the database.'];
        } else {
            foreach ($data['tasks'] as $t) {
                $lines[] = ['style' => 'body', 'text' => ($t['title'] ?? 'Task')
                    . ' · due ' . dater($t['due_date'] ?? null)
                    . ' · ' . ($t['priority'] ?? '')
                    . ' · ' . ($t['status'] ?? '')];
            }
        }

        $lines[] = ['style' => 'spacer', 'text' => ''];
        $lines[] = ['style' => 'heading', 'text' => 'Payroll (recent)'];
        if (empty($data['payroll'])) {
            $lines[] = ['style' => 'muted', 'text' => 'No payroll records in the database.'];
        } else {
            foreach ($data['payroll'] as $p) {
                $lines[] = ['style' => 'body', 'text' => dater($p['period_start']) . '–' . dater($p['period_end'])
                    . ' · days ' . ($p['days_worked'] ?? '—')
                    . ' · net ' . money($p['net_pay'] ?? 0)
                    . ' · ' . ($p['status'] ?? '')];
            }
        }

        $lines[] = ['style' => 'spacer', 'text' => ''];
        $lines[] = ['style' => 'muted', 'text' => 'FarmWise · Official employee record extract. Only database-backed values are included.'];

        return Pdf::fromLines('Employee Report EMP-' . ($e['id'] ?? ''), $lines);
    }

    public static function filename(array $employee): string
    {
        $safe = preg_replace('/[^A-Za-z0-9\-]+/', '-', (string) ($employee['name'] ?? 'employee')) ?: 'employee';
        return 'employee-' . ($employee['id'] ?? '0') . '-' . strtolower($safe) . '.pdf';
    }
}
