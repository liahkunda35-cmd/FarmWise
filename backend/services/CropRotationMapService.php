<?php

/**
 * Interactive crop-rotation map data from real farms, fields and plantings.
 */
class CropRotationMapService
{
    public static function build(?array $farm): array
    {
        if (!$farm) {
            return [
                'farm' => null,
                'fields' => [],
                'summary' => [
                    'total' => 0,
                    'occupied' => 0,
                    'vacant' => 0,
                    'awaiting' => 0,
                ],
            ];
        }

        $farmId = (int) $farm['id'];
        $fields = db()->select('SELECT * FROM fields WHERE farm_id = ? ORDER BY name ASC', [$farmId]);
        $plantingsByField = [];
        if ($fields && table_exists('plantings')) {
            $rows = db()->select(
                "SELECT p.*, c.name AS crop_name, c.variety AS crop_variety,
                        (SELECT MIN(h.harvest_date) FROM harvests h WHERE h.planting_id = p.id) AS actual_harvest_date
                 FROM plantings p
                 LEFT JOIN crop_varieties c ON c.id = p.crop_variety_id
                 WHERE p.farm_id = ?
                 ORDER BY p.planting_date ASC, p.id ASC",
                [$farmId]
            );
            foreach ($rows as $row) {
                $fid = (int) ($row['field_id'] ?? 0);
                if ($fid <= 0) {
                    continue;
                }
                $plantingsByField[$fid][] = self::formatPlanting($row);
            }
        }

        $payload = [];
        $occupied = 0;
        $vacant = 0;
        $awaiting = 0;
        foreach ($fields as $field) {
            $fid = (int) $field['id'];
            $history = $plantingsByField[$fid] ?? [];
            $status = self::fieldRotationStatus($history);
            if ($status === 'growing' || $status === 'rotated') {
                $occupied++;
            } elseif ($status === 'awaiting') {
                $awaiting++;
            } else {
                $vacant++;
            }
            $current = self::currentPlanting($history);
            $payload[] = [
                'id' => $fid,
                'name' => (string) ($field['name'] ?? ('Field #' . $fid)),
                'size_hectares' => $field['size_hectares'] ?? null,
                'field_status' => (string) ($field['status'] ?? 'active'),
                'latitude' => $field['latitude'] ?? null,
                'longitude' => $field['longitude'] ?? null,
                'geometry' => self::extractGeometry($field['boundary_notes'] ?? null),
                'rotation_status' => $status,
                'current_crop' => $current['crop_name'] ?? null,
                'history' => array_reverse($history), // newest first for report
            ];
        }

        $boundary = null;
        if (!empty($farm['boundary_geojson'])) {
            $decoded = json_decode((string) $farm['boundary_geojson'], true);
            if (is_array($decoded)) {
                $boundary = $decoded;
            }
        }

        return [
            'farm' => [
                'id' => $farmId,
                'name' => (string) ($farm['name'] ?? 'Farm'),
                'latitude' => $farm['latitude'] ?? null,
                'longitude' => $farm['longitude'] ?? null,
                'location_label' => $farm['location_label'] ?? ($farm['location'] ?? null),
                'boundary' => $boundary,
            ],
            'fields' => $payload,
            'summary' => [
                'total' => count($payload),
                'occupied' => $occupied,
                'vacant' => $vacant,
                'awaiting' => $awaiting,
            ],
        ];
    }

    public static function fieldReport(int $fieldId): ?array
    {
        $field = db()->find('fields', $fieldId);
        if (!$field) {
            return null;
        }
        if (!Auth::isAdmin() && !Auth::canAccessFarm((int) $field['farm_id'])) {
            return null;
        }
        $farm = db()->find('farms', (int) $field['farm_id']);
        $map = self::build($farm);
        foreach ($map['fields'] as $row) {
            if ((int) $row['id'] === $fieldId) {
                return [
                    'farm' => $map['farm'],
                    'field' => $row,
                    'generated_at' => date('Y-m-d H:i'),
                ];
            }
        }
        return null;
    }

    public static function farmReport(?array $farm): ?array
    {
        if (!$farm) {
            return null;
        }
        $map = self::build($farm);
        return [
            'farm' => $map['farm'],
            'fields' => $map['fields'],
            'summary' => $map['summary'],
            'generated_at' => date('Y-m-d H:i'),
        ];
    }

    private static function formatPlanting(array $row): array
    {
        $status = strtolower((string) ($row['status'] ?? 'planted'));
        $crop = trim((string) (($row['crop_name'] ?? '') ?: 'Crop'));
        if (!empty($row['crop_variety'])) {
            $crop .= ' (' . $row['crop_variety'] . ')';
        }
        return [
            'id' => (int) ($row['id'] ?? 0),
            'crop_name' => $crop,
            'planting_date' => $row['planting_date'] ?? null,
            'expected_harvest' => $row['expected_harvest'] ?? null,
            'actual_harvest_date' => $row['actual_harvest_date'] ?? null,
            'season' => $row['season'] ?? null,
            'growth_stage' => $row['growth_stage'] ?? null,
            'status' => $status,
            'display_status' => self::displayStatus($status, $row['actual_harvest_date'] ?? null),
        ];
    }

    private static function displayStatus(string $status, $actualHarvest): string
    {
        if ($actualHarvest || $status === 'harvested') {
            return 'Harvested';
        }
        if ($status === 'failed') {
            return 'Failed';
        }
        if ($status === 'planned') {
            return 'Planned';
        }
        if (in_array($status, ['planted', 'growing'], true)) {
            return 'Growing';
        }
        return ucfirst($status ?: 'Unknown');
    }

    /** @param list<array<string,mixed>> $history chronological ascending */
    private static function currentPlanting(array $history): ?array
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $st = strtolower((string) ($history[$i]['status'] ?? ''));
            if (in_array($st, ['planted', 'growing'], true)) {
                return $history[$i];
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $history chronological ascending */
    public static function fieldRotationStatus(array $history): string
    {
        if (!$history) {
            return 'vacant';
        }
        $active = [];
        foreach ($history as $row) {
            $st = strtolower((string) ($row['status'] ?? ''));
            if (in_array($st, ['planted', 'growing'], true)) {
                $active[] = $row;
            }
        }
        if ($active) {
            $current = $active[count($active) - 1];
            $prev = null;
            foreach (array_reverse($history) as $row) {
                if ((int) $row['id'] === (int) $current['id']) {
                    continue;
                }
                $prev = $row;
                break;
            }
            if ($prev) {
                $a = strtolower(preg_replace('/\s*\(.*$/', '', (string) $current['crop_name']));
                $b = strtolower(preg_replace('/\s*\(.*$/', '', (string) $prev['crop_name']));
                if ($a !== '' && $b !== '' && $a !== $b) {
                    return 'rotated';
                }
            }
            return 'growing';
        }

        $latest = $history[count($history) - 1];
        $st = strtolower((string) ($latest['status'] ?? ''));
        $harvestDate = $latest['actual_harvest_date'] ?? $latest['expected_harvest'] ?? $latest['planting_date'] ?? null;
        if ($st === 'harvested' || $latest['actual_harvest_date']) {
            if ($harvestDate && strtotime((string) $harvestDate) >= strtotime('-120 days')) {
                return 'awaiting';
            }
        }
        if ($st === 'planned') {
            return 'awaiting';
        }
        return 'vacant';
    }

    public static function extractGeometry($notes): ?array
    {
        if (!$notes) {
            return null;
        }
        if (!preg_match('/\[\[GEOJSON\]\](\{[\s\S]*\})\s*$/', (string) $notes, $m)) {
            return null;
        }
        $geo = json_decode($m[1], true);
        return is_array($geo) ? $geo : null;
    }

    public static function statusColors(): array
    {
        return [
            'growing' => ['hex' => '#2f6b4f', 'label' => 'Growing crop'],
            'awaiting' => ['hex' => '#b08c3e', 'label' => 'Recently harvested / awaiting planting'],
            'rotated' => ['hex' => '#6a8fad', 'label' => 'Different crop in rotation'],
            'vacant' => ['hex' => '#8a948c', 'label' => 'Unoccupied'],
            'selected' => ['hex' => '#c45c26', 'label' => 'Selected field'],
        ];
    }
}
