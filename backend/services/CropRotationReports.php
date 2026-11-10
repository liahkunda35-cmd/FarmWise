<?php

class CropRotationReports
{
    public static function fieldPdf(array $report): string
    {
        $farm = $report['farm'] ?? [];
        $field = $report['field'] ?? [];
        $lines = [
            ['style' => 'title', 'text' => 'FarmWise — Field Crop Rotation Report'],
            ['style' => 'muted', 'text' => 'Generated ' . ($report['generated_at'] ?? date('Y-m-d H:i'))],
            ['style' => 'spacer', 'text' => ''],
            ['style' => 'heading', 'text' => 'Field information'],
            ['style' => 'body', 'text' => 'Farm: ' . ($farm['name'] ?? '—')],
            ['style' => 'body', 'text' => 'Field: ' . ($field['name'] ?? '—')],
            ['style' => 'body', 'text' => 'Field ID: ' . ($field['id'] ?? '—')],
            ['style' => 'body', 'text' => 'Size: ' . (isset($field['size_hectares']) && $field['size_hectares'] !== null && $field['size_hectares'] !== '' ? $field['size_hectares'] . ' ha' : '—')],
            ['style' => 'body', 'text' => 'Field status: ' . ($field['field_status'] ?? '—')],
            ['style' => 'body', 'text' => 'Rotation status: ' . self::statusLabel($field['rotation_status'] ?? 'vacant')],
            ['style' => 'body', 'text' => 'Current crop: ' . (($field['current_crop'] ?? '') !== '' ? $field['current_crop'] : '—')],
            ['style' => 'spacer', 'text' => ''],
            ['style' => 'heading', 'text' => ($field['name'] ?? 'Field') . ' — Crop rotation history'],
        ];

        $history = is_array($field['history'] ?? null) ? $field['history'] : [];
        if (!$history) {
            $lines[] = ['style' => 'muted', 'text' => 'No planting records for this field yet.'];
        } else {
            $lines[] = ['style' => 'body', 'text' => 'Crop | Planting date | Harvest date | Season | Status'];
            foreach ($history as $row) {
                $harvest = $row['actual_harvest_date'] ?? $row['expected_harvest'] ?? null;
                $lines[] = ['style' => 'body', 'text' => ($row['crop_name'] ?? '—')
                    . ' | ' . dater($row['planting_date'] ?? null)
                    . ' | ' . ($harvest ? dater($harvest) : '—')
                    . ' | ' . (($row['season'] ?? '') !== '' ? $row['season'] : '—')
                    . ' | ' . ($row['display_status'] ?? $row['status'] ?? '—')];
            }
        }

        $lines[] = ['style' => 'spacer', 'text' => ''];
        $lines[] = ['style' => 'muted', 'text' => 'History is taken from Plantings (and harvest dates where recorded). Previous crops are retained.'];

        return Pdf::fromLines('Field Rotation Report', $lines);
    }

    public static function farmPdf(array $report): string
    {
        $farm = $report['farm'] ?? [];
        $summary = $report['summary'] ?? [];
        $lines = [
            ['style' => 'title', 'text' => 'FarmWise — Farm Crop Rotation Report'],
            ['style' => 'muted', 'text' => 'Generated ' . ($report['generated_at'] ?? date('Y-m-d H:i'))],
            ['style' => 'spacer', 'text' => ''],
            ['style' => 'heading', 'text' => 'Farm summary'],
            ['style' => 'body', 'text' => 'Farm: ' . ($farm['name'] ?? '—')],
            ['style' => 'body', 'text' => 'Total fields: ' . (int) ($summary['total'] ?? 0)],
            ['style' => 'body', 'text' => 'Occupied: ' . (int) ($summary['occupied'] ?? 0)],
            ['style' => 'body', 'text' => 'Vacant: ' . (int) ($summary['vacant'] ?? 0)],
            ['style' => 'body', 'text' => 'Awaiting planting: ' . (int) ($summary['awaiting'] ?? 0)],
        ];

        foreach (($report['fields'] ?? []) as $field) {
            $lines[] = ['style' => 'spacer', 'text' => ''];
            $lines[] = ['style' => 'heading', 'text' => ($field['name'] ?? 'Field') . ' (ID ' . ($field['id'] ?? '') . ')'];
            $lines[] = ['style' => 'body', 'text' => 'Size: ' . (isset($field['size_hectares']) && $field['size_hectares'] !== '' && $field['size_hectares'] !== null ? $field['size_hectares'] . ' ha' : '—')
                . ' · Status: ' . self::statusLabel($field['rotation_status'] ?? 'vacant')
                . ' · Current: ' . (($field['current_crop'] ?? '') !== '' ? $field['current_crop'] : '—')];
            $history = is_array($field['history'] ?? null) ? $field['history'] : [];
            if (!$history) {
                $lines[] = ['style' => 'muted', 'text' => 'No plantings recorded.'];
                continue;
            }
            foreach ($history as $row) {
                $harvest = $row['actual_harvest_date'] ?? $row['expected_harvest'] ?? null;
                $lines[] = ['style' => 'body', 'text' => '• ' . ($row['crop_name'] ?? '—')
                    . ' · planted ' . dater($row['planting_date'] ?? null)
                    . ' · harvest ' . ($harvest ? dater($harvest) : '—')
                    . ' · ' . ($row['display_status'] ?? '')];
            }
        }

        $lines[] = ['style' => 'spacer', 'text' => ''];
        $lines[] = ['style' => 'muted', 'text' => 'FarmWise crop rotation extract from live planting records.'];

        return Pdf::fromLines('Farm Rotation Report', $lines);
    }

    public static function fieldFilename(array $field): string
    {
        $safe = preg_replace('/[^A-Za-z0-9\-]+/', '-', (string) ($field['name'] ?? 'field')) ?: 'field';
        return 'field-rotation-' . (int) ($field['id'] ?? 0) . '-' . strtolower($safe) . '.pdf';
    }

    public static function farmFilename(array $farm): string
    {
        $safe = preg_replace('/[^A-Za-z0-9\-]+/', '-', (string) ($farm['name'] ?? 'farm')) ?: 'farm';
        return 'farm-rotation-' . (int) ($farm['id'] ?? 0) . '-' . strtolower($safe) . '.pdf';
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'growing' => 'Growing crop',
            'awaiting' => 'Awaiting planting',
            'rotated' => 'Different crop in rotation',
            default => 'Unoccupied',
        };
    }
}
