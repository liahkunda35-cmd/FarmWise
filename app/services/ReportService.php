<?php

class ReportService
{
    public static function types(): array
    {
        return [
            'performance' => 'Farm performance',
            'crops' => 'Crop production',
            'yield' => 'Yield analysis',
            'livestock' => 'Livestock',
            'financial' => 'Profit & loss',
            'expenses' => 'Expenses',
            'sales' => 'Sales',
            'inventory' => 'Inventory',
            'labour' => 'Labour',
            'irrigation' => 'Irrigation',
            'pests' => 'Pest & disease',
            'equipment' => 'Equipment',
            'profitability' => 'Crop profitability',
        ];
    }

    public static function build(string $type, array $filters = []): array
    {
        $farmId = Auth::farmId();
        if (Auth::isAdmin() && !empty($filters['farm_id']) && Auth::canAccessFarm((int) $filters['farm_id'])) {
            $farmId = (int) $filters['farm_id'];
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
            'financial' => self::financial($farmSql, $tDate),
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
            'profitability' => self::profitability($farmSql . $oDate),
            default => self::performance($farmSql),
        };
        $period = ($from || $to) ? ('Reporting period: ' . ($from ?: '…') . ' to ' . ($to ?: date('Y-m-d'))) : 'All dates';
        $report['title'] = self::types()[$type] ?? 'Report';
        $report['period'] = $period;
        $report['summary'] = trim(($report['summary'] ?? '') . ' · ' . $period, ' ·');
        return $report;
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

    private static function table(array $headers, array $rows, callable $map, ?array $chart = null): array
    {
        return [
            'headers' => $headers,
            'rows' => array_map($map, $rows),
            'summary' => count($rows) . ' records',
            'chart' => $chart,
        ];
    }

    private static function financial(string $farmSql, string $txnDateSql = ''): array
    {
        $income = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE {$farmSql} {$txnDateSql} AND type='income'");
        $expense = (float) db()->value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE {$farmSql} {$txnDateSql} AND type='expense'");
        $ha = (float) db()->value('SELECT COALESCE(SUM(total_area),0) FROM farms' . (Auth::farmId() ? ' WHERE id=' . (int) Auth::farmId() : ''));
        $kg = (float) db()->value("SELECT COALESCE(SUM(quantity),0) FROM harvests WHERE {$farmSql}");
        $rows = [
            ['Total income', money($income)],
            ['Total expenditure', money($expense)],
            ['Profit / loss', money($income - $expense)],
            ['ROI', $expense > 0 ? round((($income - $expense) / $expense) * 100, 1) . '%' : '—'],
            ['Cost per hectare', $ha > 0 ? money($expense / $ha) : '—'],
            ['Cost per kilogram', $kg > 0 ? money($expense / $kg) : '—'],
        ];
        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'summary' => 'Financial position from posted transactions',
            'chart' => [
                'type' => 'doughnut',
                'labels' => ['Income', 'Expenditure'],
                'data' => [$income, $expense],
            ],
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
            ['Date', 'Category', 'Description', 'Amount'],
            $raw,
            fn($r) => [dater($r['txn_date']), $r['category'], $r['description'], money($r['amount'])],
            ['type' => 'bar', 'labels' => array_keys($byCat), 'data' => array_values($byCat)]
        );
    }

    private static function salesReport(string $farmSql): array
    {
        $raw = db()->select("SELECT * FROM sales_orders WHERE {$farmSql} ORDER BY order_date DESC");
        $byCrop = [];
        foreach ($raw as $r) {
            $byCrop[$r['crop_name']] = ($byCrop[$r['crop_name']] ?? 0) + (float) $r['total_amount'];
        }
        return self::table(
            ['Order', 'Date', 'Crop', 'Qty', 'Total', 'Status'],
            $raw,
            fn($r) => [$r['order_number'], dater($r['order_date']), $r['crop_name'], $r['quantity'], money($r['total_amount']), $r['status']],
            ['type' => 'bar', 'labels' => array_keys($byCrop), 'data' => array_values($byCrop)]
        );
    }

    private static function profitability(string $farmSql): array
    {
        $src = db()->select("SELECT crop_name, SUM(total_amount) AS revenue, SUM(quantity) AS qty FROM sales_orders WHERE {$farmSql} GROUP BY crop_name");
        $rows = [];
        foreach ($src as $r) {
            $rows[] = [$r['crop_name'], qty($r['qty']), money($r['revenue']), $r['qty'] ? money($r['revenue'] / $r['qty']) : '—'];
        }
        return [
            'headers' => ['Crop', 'Sold qty', 'Revenue', 'Avg price'],
            'rows' => $rows,
            'summary' => 'Profitability by crop from sales orders',
            'chart' => [
                'type' => 'bar',
                'labels' => array_column($src, 'crop_name'),
                'data' => array_map('floatval', array_column($src, 'revenue')),
            ],
        ];
    }

    private static function performance(string $farmSql): array
    {
        $ha = (float) db()->value('SELECT COALESCE(SUM(size_hectares),0) FROM fields WHERE ' . $farmSql);
        $rows = [
            ['Total field area (ha)', qty($ha)],
            ['Active plantings', db()->value("SELECT COUNT(*) FROM plantings WHERE {$farmSql} AND status IN ('planted','growing')")],
            ['Livestock on hand', db()->value("SELECT COUNT(*) FROM animals WHERE {$farmSql} AND status='active'")],
            ['Harvested volume', qty(db()->value("SELECT COALESCE(SUM(quantity),0) FROM harvests WHERE {$farmSql}"))],
            ['Open tasks', db()->value("SELECT COUNT(*) FROM tasks WHERE {$farmSql} AND status!='completed'")],
            ['Low-stock items', db()->value("SELECT COUNT(*) FROM inventory_items WHERE {$farmSql} AND quantity<=reorder_level")],
        ];
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
