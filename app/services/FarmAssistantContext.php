<?php

/**
 * Read-only farm snapshot for the AI assistant.
 * Always scoped to the authenticated user's accessible farm(s).
 */
class FarmAssistantContext
{
    public static function snapshot(): string
    {
        if (!Auth::check()) {
            return 'No authenticated user.';
        }

        $lines = [];
        $lines[] = 'User: ' . (Auth::user()['name'] ?? 'Unknown') . ' (role: ' . (Auth::role() ?? 'n/a') . ')';
        $farmId = Auth::farmId();

        if (Auth::isAdmin() && !$farmId) {
            $farmCount = (int) db()->value('SELECT COUNT(*) FROM farms');
            $lines[] = 'Scope: administrator viewing all farms (' . $farmCount . ' farms).';
            $lines[] = 'Ask the user to select a specific farm for detailed record summaries.';
            return implode("\n", $lines);
        }

        if (!$farmId) {
            $lines[] = 'No farm is currently selected for this user.';
            return implode("\n", $lines);
        }

        $farm = db()->find('farms', $farmId);
        if (!$farm || !Auth::canAccessFarm((int) $farmId)) {
            return 'No authorized farm access.';
        }

        $lines[] = 'Active farm: ' . ($farm['name'] ?? 'Farm #' . $farmId);
        $lines[] = 'Location: ' . (($farm['location'] ?? '') !== '' ? $farm['location'] : 'not set');
        $lines[] = 'Total area (ha): ' . ($farm['total_area'] ?? '0');

        $lines[] = 'Fields: ' . (int) db()->value('SELECT COUNT(*) FROM fields WHERE farm_id = ?', [$farmId]);
        $lines[] = 'Active plantings: ' . (int) db()->value(
            "SELECT COUNT(*) FROM plantings WHERE farm_id = ? AND status IN ('planted','growing')",
            [$farmId]
        );
        $lines[] = 'Active animals: ' . (int) db()->value(
            "SELECT COUNT(*) FROM animals WHERE farm_id = ? AND status = 'active'",
            [$farmId]
        );
        $lines[] = 'Inventory items: ' . (int) db()->value('SELECT COUNT(*) FROM inventory_items WHERE farm_id = ?', [$farmId]);
        $lines[] = 'Open tasks: ' . (int) db()->value(
            "SELECT COUNT(*) FROM tasks WHERE farm_id = ? AND status != 'completed'",
            [$farmId]
        );

        if (Auth::can('transactions', 'view') || Auth::can('dashboard', 'view')) {
            $expense = (float) db()->value(
                "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE farm_id = ? AND type = 'expense'",
                [$farmId]
            );
            $income = (float) db()->value(
                "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE farm_id = ? AND type = 'income'",
                [$farmId]
            );
            $lines[] = 'Recorded expenses total: ' . number_format($expense, 2);
            $lines[] = 'Recorded income total: ' . number_format($income, 2);
        }

        if (Auth::can('harvests', 'view') || Auth::can('dashboard', 'view')) {
            $harvests = db()->select(
                'SELECT harvest_date, quantity, unit, grade, status FROM harvests WHERE farm_id = ? ORDER BY harvest_date DESC LIMIT 5',
                [$farmId]
            );
            if ($harvests) {
                $lines[] = 'Recent harvests:';
                foreach ($harvests as $h) {
                    $lines[] = '- ' . ($h['harvest_date'] ?? '') . ': ' . ($h['quantity'] ?? '') . ' ' . ($h['unit'] ?? '') .
                        ' (grade ' . ($h['grade'] ?? 'n/a') . ', ' . ($h['status'] ?? '') . ')';
                }
            } else {
                $lines[] = 'Recent harvests: none recorded.';
            }
        }

        $plantings = db()->select(
            "SELECT p.season, p.growth_stage, p.status, p.planting_date, c.name AS crop
             FROM plantings p
             LEFT JOIN crop_varieties c ON c.id = p.crop_variety_id
             WHERE p.farm_id = ?
             ORDER BY p.id DESC LIMIT 5",
            [$farmId]
        );
        if ($plantings) {
            $lines[] = 'Recent plantings:';
            foreach ($plantings as $p) {
                $lines[] = '- ' . ($p['crop'] ?: 'Crop') . ' / ' . ($p['season'] ?? '') .
                    ' — stage ' . ($p['growth_stage'] ?? 'n/a') . ', status ' . ($p['status'] ?? '') .
                    ', planted ' . ($p['planting_date'] ?? '');
            }
        }

        $lines[] = 'Note: figures above are from authorized FarmWise records only.';
        return implode("\n", $lines);
    }
}
