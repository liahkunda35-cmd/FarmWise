<?php

/**
 * Farm reports.
 *
 * Financial definitions (Phase 4):
 * - Order value: sales_orders.total_amount where status != cancelled (commitment, not cash).
 * - Invoiced: invoices.amount where status != void (receivable raised).
 * - Cash received: SUM(payments.amount) [net of refunds] and/or transactions type=income.
 * - Outstanding: invoiced − payments on non-void invoices.
 * - Operating expenses: transactions type=expense (includes refunds as sales_refund).
 * - P&L (cash basis): cash income − expenses. Unpaid orders are NOT income.
 */
class ReportService
{
    /** Report type => module the user must be allowed to view. */
    public static function typePermissions(): array
    {
        return [
            'performance' => 'reports',
            'crops' => 'plantings',
            'yield' => 'harvests',
            'livestock' => 'animals',
            'financial' => 'transactions',
            'receivables' => 'invoices',
            'expenses' => 'transactions',
            'sales' => 'sales_orders',
            'inventory' => 'inventory_items',
            'labour' => 'employees',
            'irrigation' => 'irrigation_schedules',
            'pests' => 'pest_scouting',
            'equipment' => 'equipment',
            'profitability' => 'transactions',
            'budget' => 'budgets',
        ];
    }

    public static function visibleTypes(): array
    {
        $types = [];
        foreach (self::types() as $key => $label) {
            $module = self::typePermissions()[$key] ?? 'reports';
            if (Auth::can($module, 'view')) {
                $types[$key] = $label;
            }
        }
        return $types;
    }

    public static function types(): array
    {
        return [
            'performance' => 'Farm performance',
            'crops' => 'Crop production',
            'yield' => 'Yield analysis',
            'livestock' => 'Livestock',
            'financial' => 'Profit & loss (cash)',
            'receivables' => 'Orders · invoices · cash',
            'expenses' => 'Expenses',
            'sales' => 'Sales orders',
            'inventory' => 'Inventory',
            'labour' => 'Labour',
            'irrigation' => 'Irrigation',
            'pests' => 'Pest & disease',
            'equipment' => 'Equipment',
            'profitability' => 'Crop profitability (cash)',
            'budget' => 'Budget versus actual (cash)',
        ];
    }

    public static function build(string $type, array $filters = []): array
    {
        $farmId = Auth::farmId();
        if (Auth::isAdmin() && !empty($filters['farm_id']) && Auth::canAccessFarm((int) $filters['farm_id'])) {
            $farmId = (int) $filters['farm_id'];
        }
        if (!$farmId && !Auth::isAdmin()) {
            return self::table(['Notice'], [['Select an active farm to view this report.']]);
        }
        $farmSql = $farmId ? 'farm_id = ' . (int) $farmId : '1=1';
        $pFarm = $farmId ? 'p.farm_id = ' . (int) $farmId : '1=1';
        $hFarm = $farmId ? 'h.farm_id = ' . (int) $farmId : '1=1';
        $sFarm = $farmId ? 's.farm_id = ' . (int) $farmId : '1=1';
        $eFarm = $farmId ? 'e.farm_id = ' . (int) $farmId : '1=1';
        $from = !empty($filters['date_from']) && is_valid_date($filters['date_from']) ? $filters['date_from'] : null;
        $to = !empty($filters['date_to']) && is_valid_date($filters['date_to']) ? $filters['date_to'] : null;
        $status = !empty($filters['status']) ? str_replace("'", '', (string) $filters['status']) : null;
        $pDate = self::between('p.planting_date', $from, $to);
        $hDate = self::between('h.harvest_date', $from, $to);
        $tDate = self::between('txn_date', $from, $to);
        $oDate = self::between('order_date', $from, $to);
        $iDate = self::between('invoice_date', $from, $to);
        $payDate = self::between('payment_date', $from, $to);
        $sDate = self::between('s.scout_date', $from, $to);
        $wDate = self::between('log_date', $from, $to);
        $aStatus = $status ? " AND status = '" . $status . "'" : '';
        $pStatus = $status ? " AND p.status = '" . $status . "'" : '';
        $hStatus = $status ? " AND h.status = '" . $status . "'" : '';
        $sStatus = $status ? " AND s.status = '" . $status . "'" : '';
        $report = match ($type) {
            'crops' => self::table(
                ['Crop', 'Field', 'Planted', 'Stage', 'Status', 'Expected harvest'],
                db()->select("SELECT c.name, f.name AS field_name, p.planting_date, p.growth_stage, p.status, p.expected_harvest FROM plantings p LEFT JOIN crop_varieties c ON c.id=p.crop_variety_id LEFT JOIN fields f ON f.id=p.field_id WHERE {$pFarm} {$pDate} {$pStatus} ORDER BY p.planting_date DESC"),
                fn($r) => [$r['name'], $r['field_name'], dater($r['planting_date']), $r['growth_stage'], $r['status'], dater($r['expected_harvest'])]
            ),
            'yield' => self::table(
                ['Date', 'Field', 'Quantity', 'Expected', 'Losses', 'Grade'],
                db()->select("SELECT h.*, f.name AS field_name FROM harvests h LEFT JOIN fields f ON f.id=h.field_id WHERE {$hFarm} {$hDate} ORDER BY harvest_date DESC"),
                fn($r) => [dater($r['harvest_date']), $r['field_name'], $r['quantity'] . ' ' . $r['unit'], $r['expected_quantity'], $r['losses'], $r['grade']]
            ),
            'livestock' => self::table(
                ['Tag', 'Name', 'Species', 'Status', 'Weight', 'Birth'],
                db()->select("SELECT * FROM animals WHERE {$farmSql} {$aStatus} ORDER BY id DESC"),
                fn($r) => [$r['tag_number'], $r['name'], $r['species'], $r['status'], $r['current_weight'], dater($r['birth_date'])]
            ),
            'financial' => self::financial($farmSql, $tDate, $payDate),
            'receivables' => self::receivables($farmSql, $oDate, $iDate, $payDate),
            'expenses' => self::expenseReport($farmSql . $tDate),
            'sales' => self::salesReport($farmSql . $oDate . $aStatus),
            'inventory' => self::table(
                ['Item', 'Category', 'Qty', 'Reorder', 'Expiry', 'Value'],
                db()->select("SELECT * FROM inventory_items WHERE {$farmSql} {$aStatus} ORDER BY name"),
                fn($r) => [$r['name'], $r['category'], $r['quantity'] . ' ' . $r['unit'], $r['reorder_level'], dater($r['expiry_date']), money($r['quantity'] * $r['unit_cost'])]
            ),
            'labour' => self::table(
                ['Employee', 'Role', 'Rate', 'Days present (30d)'],
                db()->select("SELECT e.*, (SELECT COUNT(*) FROM attendance a WHERE a.employee_id=e.id AND a.status='present' AND a.work_date >= ?) AS days_present FROM employees e WHERE {$eFarm}", [$from ?: date('Y-m-d', strtotime('-30 days'))]),
                fn($r) => [$r['name'], $r['role_title'], money($r['daily_rate']), $r['days_present']]
            ),
            'irrigation' => self::table(
                ['Date', 'Water (L)', 'Pump hours', 'Cost', 'Issue'],
                db()->select("SELECT * FROM water_logs WHERE {$farmSql} {$wDate} ORDER BY log_date DESC"),
                fn($r) => [dater($r['log_date']), $r['water_litres'], $r['pump_hours'], money($r['cost']), $r['issue_flag']]
            ),
            'pests' => self::table(
                ['Date', 'Field', 'Severity', 'Status', 'Notes'],
                db()->select("SELECT s.*, f.name AS field_name FROM pest_scouting s LEFT JOIN fields f ON f.id=s.field_id WHERE {$sFarm} {$sDate} {$sStatus} ORDER BY scout_date DESC"),
                fn($r) => [dater($r['scout_date']), $r['field_name'], $r['severity'], $r['status'], $r['notes']]
            ),
            'equipment' => self::table(
                ['Asset', 'Category', 'Hours', 'Cost', 'Depreciation / yr', 'Next service'],
                db()->select("SELECT * FROM equipment WHERE {$farmSql} {$aStatus} ORDER BY name"),
                fn($r) => [$r['name'], $r['category'], $r['operating_hours'], money($r['purchase_cost']), money($r['useful_life_years'] ? $r['purchase_cost'] / $r['useful_life_years'] : 0), dater($r['next_service'])]
            ),
            'profitability' => self::profitability($farmSql, $payDate),
            'budget' => self::budgetVsActual($farmSql, $from, $to),
            default => self::performance($farmSql),
        };
        $period = ($from || $to) ? ('Reporting period: ' . ($from ?: '…') . ' to ' . ($to ?: date('Y-m-d'))) : 'All dates';
        $report['title'] = self::types()[$type] ?? 'Report';
        $report['period'] = $period;
        $report['summary'] = trim(($report['summary'] ?? '') . ' · ' . $period, ' ·');
        return $report;
    }

    /**
     * Each cash expense is allocated to at most one budget.
     * A category match wins over a blank category. Ties use the shortest period, then the earliest start, then the lowest id.
     * Expenses that match no budget stay in one unallocated row. Income is shown once for the whole period.
     */
    private static function budgetVsActual(string $farmSql, ?string $from, ?string $to): array
    {
        $basis = 'Each posted cash expense is counted once. A budget with the same category is preferred over a budget with no category. If several budgets still match, the shortest period is used, then the earliest start date, then the lowest record id. Expenses that match no budget are listed once as unallocated and are not given a planned figure. Income is shown once for the report period. Unpaid purchase orders and unpaid invoices are excluded. No allocated rows means no expense was assigned, which is different from a recorded zero.';
        $budgets = db()->select("SELECT * FROM budgets WHERE {$farmSql} ORDER BY period_start, id");
        $expenses = db()->select("SELECT id, txn_date, category, amount FROM transactions WHERE {$farmSql} AND type = 'expense' ORDER BY txn_date, id");
        $incomeRows = db()->select("SELECT id, txn_date, amount FROM transactions WHERE {$farmSql} AND type = 'income' ORDER BY txn_date, id");
        if (!$budgets && !$expenses) {
            $report = self::table(['Notice'], [['No budgets and no cash expenses have been recorded for this farm.']]);
            $report['summary'] = $basis;
            return $report;
        }
        $allocated = [];
        $unallocated = [];
        $usedExpenseIds = [];
        foreach ($expenses as $expense) {
            $date = (string) ($expense['txn_date'] ?? '');
            if (!is_valid_date($date) || ($from && $date < $from) || ($to && $date > $to)) {
                continue;
            }
            $pool = [];
            foreach ($budgets as $budget) {
                if (!self::budgetCovers($budget, $date, $from, $to)) {
                    continue;
                }
                $budgetCategory = trim((string) ($budget['category'] ?? ''));
                $expenseCategory = trim((string) ($expense['category'] ?? ''));
                if ($budgetCategory !== '' && strcasecmp($budgetCategory, $expenseCategory) !== 0) {
                    continue;
                }
                $pool[] = $budget;
            }
            $specific = array_values(array_filter($pool, static fn(array $budget): bool => trim((string) ($budget['category'] ?? '')) !== ''));
            $choices = $specific !== [] ? $specific : $pool;
            if ($choices === []) {
                $unallocated[] = $expense;
                $usedExpenseIds[(int) $expense['id']] = true;
                continue;
            }
            usort($choices, static function (array $a, array $b): int {
                $length = self::budgetLength($a) <=> self::budgetLength($b);
                if ($length !== 0) {
                    return $length;
                }
                $start = strcmp((string) ($a['period_start'] ?? ''), (string) ($b['period_start'] ?? ''));
                return $start !== 0 ? $start : ((int) $a['id'] <=> (int) $b['id']);
            });
            $winner = (int) $choices[0]['id'];
            $allocated[$winner][] = $expense;
            $usedExpenseIds[(int) $expense['id']] = true;
        }
        $mapped = [];
        $allocatedTotal = 0.0;
        $shownBudget = false;
        foreach ($budgets as $budget) {
            if (!self::budgetOverlapsReport($budget, $from, $to)) {
                continue;
            }
            $shownBudget = true;
            $rows = $allocated[(int) $budget['id']] ?? [];
            $planned = is_numeric($budget['planned_amount'] ?? null) ? (float) $budget['planned_amount'] : null;
            $actual = null;
            if ($rows !== []) {
                $actual = 0.0;
                foreach ($rows as $row) {
                    $actual += (float) $row['amount'];
                }
                $actual = round($actual, 2);
                $allocatedTotal += $actual;
            }
            $category = trim((string) ($budget['category'] ?? ''));
            $mapped[] = [
                (string) ($budget['name'] ?? 'Budget'),
                $category === '' ? 'Uncategorised budget' : $category,
                self::budgetWindowLabel($budget, $from, $to),
                $planned === null ? 'Planned amount missing' : money($planned),
                $actual === null ? 'No expenses allocated' : money($actual),
                $actual === null || $planned === null ? 'Not calculated' : money(round($planned - $actual, 2)),
                $rows === [] ? 'No cash expense was assigned to this budget.' : count($rows) . ' expense row(s), each counted once.',
            ];
        }
        $unallocatedTotal = 0.0;
        foreach ($unallocated as $row) {
            $unallocatedTotal += (float) $row['amount'];
        }
        $unallocatedTotal = round($unallocatedTotal, 2);
        if ($unallocated !== []) {
            $mapped[] = [
                'Unallocated cash expenses',
                'No matching budget',
                ($from ?: '…') . ' – ' . ($to ?: '…'),
                'No budget',
                money($unallocatedTotal),
                'Not calculated',
                count($unallocated) . ' expense row(s) matched no budget and are not included in the budget rows above.',
            ];
        }
        $incomeTotal = 0.0;
        $incomeCount = 0;
        foreach ($incomeRows as $row) {
            $date = (string) ($row['txn_date'] ?? '');
            if (!is_valid_date($date) || ($from && $date < $from) || ($to && $date > $to)) {
                continue;
            }
            $incomeCount++;
            $incomeTotal += (float) $row['amount'];
        }
        $mapped[] = [
            'Income for the period',
            'All income categories',
            ($from ?: '…') . ' – ' . ($to ?: '…'),
            'Not a budget',
            $incomeCount > 0 ? money(round($incomeTotal, 2)) : 'No income rows',
            'Not calculated',
            'Shown once so income is not repeated on every budget.',
        ];
        $mapped[] = [
            'Expense total',
            'Allocated plus unallocated',
            ($from ?: '…') . ' – ' . ($to ?: '…'),
            '—',
            money(round($allocatedTotal + $unallocatedTotal, 2)),
            '—',
            count($usedExpenseIds) . ' distinct expense row(s).',
        ];
        if (!$shownBudget && $unallocated === []) {
            $mapped[] = ['No overlap', '', 'The selected report dates do not overlap any budget period.', '—', 'No expenses allocated', '—', '—'];
        }
        $report = self::table(
            ['Budget', 'Category scope', 'Period used', 'Planned', 'Actual expenses', 'Planned minus expenses', 'Allocation note'],
            $mapped
        );
        $report['summary'] = $basis;
        return $report;
    }

    private static function budgetCovers(array $budget, string $date, ?string $from, ?string $to): bool
    {
        $start = (string) ($budget['period_start'] ?? '');
        $end = (string) ($budget['period_end'] ?? '');
        if (!is_valid_date($start) || ($end !== '' && !is_valid_date($end))) {
            return false;
        }
        if ($date < $start || ($end !== '' && $date > $end)) {
            return false;
        }
        return self::budgetOverlapsReport($budget, $from, $to);
    }

    private static function budgetOverlapsReport(array $budget, ?string $from, ?string $to): bool
    {
        $start = (string) ($budget['period_start'] ?? '');
        $end = (string) ($budget['period_end'] ?? '');
        if (!is_valid_date($start)) {
            return false;
        }
        if ($to && $start > $to) {
            return false;
        }
        if ($from && $end !== '' && $end < $from) {
            return false;
        }
        return true;
    }

    private static function budgetLength(array $budget): int
    {
        $start = strtotime((string) ($budget['period_start'] ?? ''));
        $end = strtotime((string) ($budget['period_end'] ?? ''));
        if ($start === false) {
            return PHP_INT_MAX;
        }
        if ($end === false) {
            return PHP_INT_MAX - 1;
        }
        return max(0, (int) (($end - $start) / 86400));
    }

    private static function budgetWindowLabel(array $budget, ?string $from, ?string $to): string
    {
        $start = (string) ($budget['period_start'] ?? '');
        $end = (string) ($budget['period_end'] ?? '');
        if ($from && $from > $start) {
            $start = $from;
        }
        if ($to && ($end === '' || $to < $end)) {
            $end = $to;
        }
        return dater($start) . ' – ' . ($end !== '' ? dater($end) : 'open');
    }

    private static function between(string $column, ?string $from, ?string $to): string
    {
        $sql = '';
        if ($from) {
            $sql .= " AND {$column} >= '" . $from . "'";
        }
        if ($to) {
            $sql .= " AND {$column} <= '" . $to . "'";
        }
        return $sql;
    }

    private static function table(array $headers, array $rows, callable $map = null, ?array $chart = null): array
    {
        if ($map === null) {
            $mapped = $rows;
        } else {
            $mapped = array_map($map, $rows);
        }
        return [
            'headers' => $headers,
            'rows' => $mapped,
            'summary' => count($mapped) . ' records',
            'chart' => $chart,
        ];
    }

    /** Cash-basis P&L: payments / income txns vs expenses. Orders are excluded. */
    private static function financial(string $farmSql, string $txnDateSql = '', string $payDateSql = ''): array
    {
        $cashFromPayments = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE {$farmSql} {$payDateSql}");
        $incomeTxn = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE {$farmSql} {$txnDateSql} AND type='income'");
        // Prefer payments as cash received; fall back to income txns if no payments table use
        $cashIncome = $cashFromPayments;
        $expense = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE {$farmSql} {$txnDateSql} AND type='expense'");
        $orderValue = (float) db()->value("SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE {$farmSql} AND status <> 'cancelled'");
        $invoiced = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE {$farmSql} AND status <> 'void'");
        $ha = (float) db()->value('SELECT COALESCE(SUM(total_area),0) FROM farms' . (Auth::farmId() ? ' WHERE id=' . (int) Auth::farmId() : ''));
        $kg = (float) db()->value("SELECT COALESCE(SUM(quantity),0) FROM harvests WHERE {$farmSql}");
        $profit = $cashIncome - $expense;
        $rows = [
            ['Cash received (payments, net of refunds)', money($cashIncome)],
            ['Ledger income postings (reference)', money($incomeTxn)],
            ['Operating & other expenses (ledger)', money($expense)],
            ['Profit / loss (cash − expenses)', money($profit)],
            ['ROI (cash profit ÷ expenses)', $expense > 0 ? round(($profit / $expense) * 100, 1) . '%' : '—'],
            ['Open order value (not cash)', money($orderValue)],
            ['Invoiced amount (excl. void)', money($invoiced)],
            ['Outstanding (invoiced − payments)', money(max(0, $invoiced - $cashFromPayments))],
            ['Cost per hectare (expenses)', $ha > 0 ? money($expense / $ha) : '—'],
            ['Cost per kilogram harvested', $kg > 0 ? money($expense / $kg) : '—'],
        ];
        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'summary' => 'Cash-basis P&L. Unpaid sales orders are not counted as income.',
            'chart' => [
                'type' => 'doughnut',
                'labels' => ['Cash received', 'Expenses'],
                'data' => [$cashIncome, $expense],
            ],
        ];
    }

    private static function receivables(string $farmSql, string $oDate, string $iDate, string $payDate): array
    {
        $orders = (float) db()->value("SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE {$farmSql} {$oDate} AND status <> 'cancelled'");
        $cancelled = (float) db()->value("SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE {$farmSql} {$oDate} AND status = 'cancelled'");
        $invoiced = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE {$farmSql} {$iDate} AND status <> 'void'");
        $voided = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE {$farmSql} {$iDate} AND status = 'void'");
        $collected = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE {$farmSql} {$payDate}");
        // Include legacy 'ordered'/'draft' rows if any remain from earlier seeds.
        $poSpend = (float) db()->value("SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE {$farmSql} AND status IN ('approved','received','ordered','pending_approval')");
        $supplierPaid = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE {$farmSql} AND type='expense' AND category IN ('procurement','purchases','purchase_orders')");
        $rows = [
            ['Sales order value (excl. cancelled)', money($orders)],
            ['Cancelled order value', money($cancelled)],
            ['Invoiced (excl. void)', money($invoiced)],
            ['Voided invoices', money($voided)],
            ['Payments received (net)', money($collected)],
            ['Customer outstanding', money(max(0, $invoiced - $collected))],
            ['Purchase orders (approved/received)', money($poSpend)],
            ['Supplier-related expenses (ledger)', money($supplierPaid)],
        ];
        // Detail lines: open invoices
        $open = db()->select(
            "SELECT invoice_number, invoice_date, amount, status,
                    (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.invoice_id = invoices.id) AS paid
             FROM invoices WHERE {$farmSql} AND status NOT IN ('void','paid') ORDER BY invoice_date DESC LIMIT 40"
        );
        $detail = [];
        foreach ($open as $r) {
            $detail[] = [
                $r['invoice_number'],
                dater($r['invoice_date']),
                money($r['amount']),
                money($r['paid']),
                money(max(0, (float) $r['amount'] - (float) $r['paid'])),
                $r['status'],
            ];
        }
        return [
            'headers' => ['Invoice', 'Date', 'Invoiced', 'Paid', 'Balance', 'Status'],
            'rows' => $detail ?: [['—', '—', money(0), money(0), money(0), 'none open']],
            'summary' => 'Orders ' . money($orders) . ' · Invoiced ' . money($invoiced) . ' · Cash ' . money($collected) . ' · Outstanding ' . money(max(0, $invoiced - $collected)),
            'chart' => [
                'type' => 'bar',
                'labels' => ['Orders', 'Invoiced', 'Cash', 'Outstanding'],
                'data' => [$orders, $invoiced, $collected, max(0, $invoiced - $collected)],
            ],
            'definitions' => $rows,
        ];
    }

    private static function expenseReport(string $farmSql): array
    {
        $raw = db()->select("SELECT * FROM transactions WHERE {$farmSql} AND type='expense' ORDER BY txn_date DESC");
        $byCat = [];
        foreach ($raw as $r) {
            $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + (float) $r['amount'];
        }
        return self::table(
            ['Date', 'Category', 'Description', 'Amount', 'Reference'],
            $raw,
            fn($r) => [dater($r['txn_date']), $r['category'], $r['description'], money($r['amount']), $r['reference']],
            ['type' => 'bar', 'labels' => array_keys($byCat), 'data' => array_values($byCat)]
        );
    }

    private static function salesReport(string $farmSql): array
    {
        $raw = db()->select("SELECT * FROM sales_orders WHERE {$farmSql} ORDER BY order_date DESC");
        $byCrop = [];
        foreach ($raw as $r) {
            if (($r['status'] ?? '') === 'cancelled') {
                continue;
            }
            $byCrop[$r['crop_name']] = ($byCrop[$r['crop_name']] ?? 0) + (float) $r['total_amount'];
        }
        $out = self::table(
            ['Order', 'Date', 'Crop', 'Qty', 'Order value', 'Status'],
            $raw,
            fn($r) => [$r['order_number'], dater($r['order_date']), $r['crop_name'], $r['quantity'], money($r['total_amount']), $r['status']],
            ['type' => 'bar', 'labels' => array_keys($byCrop), 'data' => array_values($byCrop)]
        );
        $out['summary'] = count($raw) . ' orders (order values are commitments, not cash received)';
        return $out;
    }

    /** Cash collected attributed by crop via invoice→sales_order when possible. */
    private static function profitability(string $farmSql, string $payDate): array
    {
        $rows = [];
        $labels = [];
        $data = [];
        try {
            $src = db()->select(
                "SELECT COALESCE(so.crop_name, 'Unallocated') AS crop_name,
                        COALESCE(SUM(pay.amount),0) AS cash,
                        COALESCE(SUM(so.quantity),0) AS qty
                 FROM payments pay
                 LEFT JOIN invoices i ON i.id = pay.invoice_id
                 LEFT JOIN sales_orders so ON so.id = i.sales_order_id
                 WHERE pay.{$farmSql} {$payDate}
                 GROUP BY COALESCE(so.crop_name, 'Unallocated')"
            );
            // Fix farmSql placement — pay.farm_id
        } catch (Throwable $e) {
            $src = [];
        }
        // Rebuild with correct SQL (farmSql is "farm_id = N")
        $src = db()->select(
            "SELECT COALESCE(so.crop_name, 'Unallocated') AS crop_name,
                    COALESCE(SUM(pay.amount),0) AS cash
             FROM payments pay
             LEFT JOIN invoices i ON i.id = pay.invoice_id
             LEFT JOIN sales_orders so ON so.id = i.sales_order_id
             WHERE pay.{$farmSql} {$payDate}
             GROUP BY COALESCE(so.crop_name, 'Unallocated')"
        );
        foreach ($src as $r) {
            $labels[] = $r['crop_name'];
            $data[] = (float) $r['cash'];
            $rows[] = [$r['crop_name'], money($r['cash']), 'Cash from payments'];
        }
        if (!$rows) {
            $rows[] = ['—', money(0), 'No payments in period'];
        }
        return [
            'headers' => ['Crop', 'Cash received', 'Basis'],
            'rows' => $rows,
            'summary' => 'Profitability by crop uses payments received (cash), not unpaid order values.',
            'chart' => [
                'type' => 'bar',
                'labels' => $labels,
                'data' => $data,
            ],
        ];
    }

    private static function performance(string $farmSql): array
    {
        [$taskSql, $taskParams] = TaskAccess::listConstraint();
        $rows = [];
        if (Auth::can('fields', 'view')) {
            $rows[] = ['Total field area (ha)', qty((float) db()->value('SELECT COALESCE(SUM(size_hectares),0) FROM fields WHERE ' . $farmSql))];
        }
        if (Auth::can('plantings', 'view')) {
            $rows[] = ['Active plantings', db()->value("SELECT COUNT(*) FROM plantings WHERE {$farmSql} AND status IN ('planted','growing')")];
        }
        if (Auth::can('animals', 'view')) {
            $rows[] = ['Livestock on hand', db()->value("SELECT COUNT(*) FROM animals WHERE {$farmSql} AND status='active'")];
        }
        if (Auth::can('harvests', 'view')) {
            $rows[] = ['Harvested volume', qty(db()->value("SELECT COALESCE(SUM(quantity),0) FROM harvests WHERE {$farmSql}"))];
        }
        if (Auth::can('tasks', 'view')) {
            $rows[] = ['Open tasks', db()->value("SELECT COUNT(*) FROM tasks WHERE {$farmSql}{$taskSql} AND status!='completed'", $taskParams)];
        }
        if (Auth::can('inventory_items', 'view')) {
            $rows[] = ['Low-stock items', db()->value("SELECT COUNT(*) FROM inventory_items WHERE {$farmSql} AND quantity<=reorder_level")];
        }
        if (!$rows) {
            $rows[] = ['Notice', 'No operational figures are available for this role.'];
        }
        return [
            'headers' => ['KPI', 'Value'],
            'rows' => $rows,
            'summary' => 'Operational snapshot',
            'chart' => [
                'type' => 'bar',
                'labels' => array_column($rows, 0),
                'data' => array_map(fn($r) => (float) preg_replace('/[^0-9.\-]/', '', (string) $r[1]), $rows),
            ],
        ];
    }
}
