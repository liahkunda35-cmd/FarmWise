<?php

/**
 * Read-only analytics over imported ZamStats CFS aggregates.
 * Never invents missing seasons; uses only rows present in the database.
 */
class AgriPatternService
{
    public const MIN_SEASONS_FOR_RANK = 2;

    public static function seasons(): array
    {
        return db()->select(
            "SELECT DISTINCT season_label FROM zamstats_crop_stats ORDER BY season_label ASC"
        );
    }

    public static function provinces(): array
    {
        return db()->select(
            "SELECT DISTINCT province FROM zamstats_crop_stats WHERE province IS NOT NULL AND province <> '' ORDER BY province ASC"
        );
    }

    public static function crops(?string $province = null): array
    {
        if ($province) {
            return db()->select(
                'SELECT DISTINCT crop_name FROM zamstats_crop_stats WHERE province = ? ORDER BY crop_name ASC',
                [$province]
            );
        }
        return db()->select('SELECT DISTINCT crop_name FROM zamstats_crop_stats ORDER BY crop_name ASC');
    }

    /**
     * Series for charts — gaps left when a season is missing (no interpolation).
     * @return array{labels:list<string>, production:list<?float>, yield:list<?float>, area:list<?float>}
     */
    public static function cropTrend(string $crop, ?string $province = null, string $category = 'all'): array
    {
        $params = [$crop, $category];
        $sql = "SELECT season_label, production_mt, yield_mt_ha, area_planted_ha
                FROM zamstats_crop_stats
                WHERE crop_name = ? AND farm_category = ? AND district IS NULL";
        if ($province) {
            $sql .= ' AND province = ?';
            $params[] = $province;
        } else {
            $sql .= " AND province = 'National'";
        }
        $sql .= ' ORDER BY season_label ASC';
        $rows = db()->select($sql, $params);
        $labels = [];
        $production = [];
        $yield = [];
        $area = [];
        foreach ($rows as $r) {
            $labels[] = (string) $r['season_label'];
            $production[] = $r['production_mt'] !== null ? (float) $r['production_mt'] : null;
            $yield[] = $r['yield_mt_ha'] !== null ? (float) $r['yield_mt_ha'] : null;
            $area[] = $r['area_planted_ha'] !== null ? (float) $r['area_planted_ha'] : null;
        }
        return compact('labels', 'production', 'yield', 'area');
    }

    /** @return list<array<string,mixed>> */
    public static function provincialComparison(string $crop, ?string $season = null, string $category = 'all'): array
    {
        if ($season === null || $season === '') {
            // Prefer a season that actually has provincial rows (not only National)
            $season = (string) (db()->value(
                "SELECT season_label FROM zamstats_crop_stats
                 WHERE crop_name = ? AND farm_category = ?
                   AND district IS NULL AND province NOT IN ('National','Total','Zambia')
                 ORDER BY season_label DESC LIMIT 1",
                [$crop, $category]
            ) ?: '');
            if ($season === '') {
                $season = (string) (db()->value(
                    "SELECT season_label FROM zamstats_crop_stats WHERE crop_name = ? AND farm_category = ? ORDER BY season_label DESC LIMIT 1",
                    [$crop, $category]
                ) ?: '');
            }
        }
        if ($season === '') {
            return [];
        }
        $nulls = db()->driver() === 'pgsql' ? ' NULLS LAST' : '';
        return db()->select(
            "SELECT province, season_label, area_planted_ha, production_mt, yield_mt_ha, sales_mt
             FROM zamstats_crop_stats
             WHERE crop_name = ? AND season_label = ? AND farm_category = ?
               AND district IS NULL AND province NOT IN ('National','Total','Zambia')
             ORDER BY production_mt DESC{$nulls}",
            [$crop, $season, $category]
        );
    }

    /**
     * Recommend crops for a province from historical mean yield/production.
     * Requires at least MIN_SEASONS_FOR_RANK distinct seasons when available;
     * if fewer, returns observed data with an explicit limited-history note.
     * @return array{province:string,items:list<array<string,mixed>>,disclaimer:string,rainfall_note:?string}
     */
    public static function recommendForProvince(?string $province, int $limit = 8): array
    {
        $province = $province ? ZamstatsAgriImport::normalizeProvince($province) : '';
        if ($province === '' || in_array($province, ['National', 'Total', 'Zambia'], true)) {
            return [
                'province' => $province ?: 'n/a',
                'items' => [],
                'disclaimer' => 'Set the farm province to see CFS-based crop recommendations for that region.',
                'rainfall_note' => null,
            ];
        }
        $rows = db()->select(
            "SELECT crop_name,
                    COUNT(DISTINCT season_label) AS seasons,
                    AVG(yield_mt_ha) AS avg_yield,
                    MIN(yield_mt_ha) AS min_yield,
                    MAX(yield_mt_ha) AS max_yield,
                    AVG(production_mt) AS avg_production,
                    MIN(season_label) AS first_season,
                    MAX(season_label) AS last_season
             FROM zamstats_crop_stats
             WHERE province = ? AND farm_category = 'all' AND district IS NULL
               AND yield_mt_ha IS NOT NULL
             GROUP BY crop_name
             HAVING COUNT(DISTINCT season_label) >= 1
             ORDER BY AVG(yield_mt_ha) DESC, AVG(production_mt) DESC
             LIMIT ?",
            [$province, $limit]
        );
        $items = [];
        foreach ($rows as $r) {
            $seasons = (int) $r['seasons'];
            $trend = self::yieldTrendDirection((string) $r['crop_name'], $province);
            $items[] = [
                'crop_name' => $r['crop_name'],
                'seasons' => $seasons,
                'avg_yield' => $r['avg_yield'] !== null ? round((float) $r['avg_yield'], 3) : null,
                'min_yield' => $r['min_yield'] !== null ? round((float) $r['min_yield'], 3) : null,
                'max_yield' => $r['max_yield'] !== null ? round((float) $r['max_yield'], 3) : null,
                'avg_production' => $r['avg_production'] !== null ? round((float) $r['avg_production'], 1) : null,
                'first_season' => $r['first_season'],
                'last_season' => $r['last_season'],
                'trend' => $trend,
                'limited_history' => $seasons < self::MIN_SEASONS_FOR_RANK,
            ];
        }
        $rain = self::rainfallNote($province);
        $disclaimer = 'Historical pattern from ZamStats Crop Forecast Survey aggregates for '
            . $province . ' — not a guaranteed forecast. Missing seasons are omitted, not filled.';
        return [
            'province' => $province,
            'items' => $items,
            'disclaimer' => $disclaimer,
            'rainfall_note' => $rain,
        ];
    }

    /** Compare farm harvest yields to provincial CFS averages when both exist. */
    public static function farmVersusProvince(int $farmId): array
    {
        $farm = db()->find('farms', $farmId);
        if (!$farm) {
            return [];
        }
        $province = ZamstatsAgriImport::normalizeProvince((string) ($farm['province'] ?? ''));
        if ($province === '') {
            return ['note' => 'Farm province not set — cannot compare to CFS provincial averages.'];
        }
        $harvests = db()->select(
            "SELECT h.quantity, h.unit, f.size_hectares, c.name AS crop
             FROM harvests h
             LEFT JOIN plantings p ON p.id = h.planting_id
             LEFT JOIN fields f ON f.id = COALESCE(h.field_id, p.field_id)
             LEFT JOIN crop_varieties c ON c.id = p.crop_variety_id
             WHERE h.farm_id = ?
             ORDER BY h.harvest_date DESC LIMIT 20",
            [$farmId]
        );
        $out = [];
        foreach ($harvests as $h) {
            $crop = trim((string) ($h['crop'] ?? ''));
            if ($crop === '') {
                continue;
            }
            $ha = (float) ($h['size_hectares'] ?? 0);
            $qty = (float) ($h['quantity'] ?? 0);
            $unit = strtolower((string) ($h['unit'] ?? 'kg'));
            if ($ha <= 0 || $qty <= 0) {
                continue;
            }
            // Convert farm yield to mt/ha when unit is kg
            $farmYieldMtHa = null;
            if (in_array($unit, ['kg', 'kgs', 'kilogram', 'kilograms'], true)) {
                $farmYieldMtHa = ($qty / 1000) / $ha;
            } elseif (in_array($unit, ['mt', 'ton', 'tons', 'tonne', 'tonnes'], true)) {
                $farmYieldMtHa = $qty / $ha;
            }
            if ($farmYieldMtHa === null) {
                continue;
            }
            $match = self::bestCropMatch($crop, $province);
            if (!$match) {
                continue;
            }
            $out[] = [
                'farm_crop' => $crop,
                'cfs_crop' => $match['crop_name'],
                'province' => $province,
                'farm_yield_mt_ha' => round($farmYieldMtHa, 3),
                'cfs_avg_yield_mt_ha' => $match['avg_yield'],
                'cfs_seasons' => $match['seasons'],
                'cfs_range' => [$match['min_yield'], $match['max_yield']],
                'season_span' => $match['first_season'] . '–' . $match['last_season'],
            ];
        }
        return $out;
    }

    public static function assistantSnippet(?string $province): string
    {
        $lines = [];
        $lines[] = 'ZamStats CFS historical notes (published aggregates only; do not invent missing seasons):';
        $count = (int) db()->value('SELECT COUNT(*) FROM zamstats_crop_stats');
        if ($count === 0) {
            $lines[] = '- No ZamStats crop statistics imported yet.';
            return implode("\n", $lines);
        }
        $seasons = db()->select('SELECT DISTINCT season_label FROM zamstats_crop_stats ORDER BY season_label');
        $lines[] = '- Seasons in database: ' . implode(', ', array_map(static fn ($r) => $r['season_label'], $seasons));
        if ($province) {
            $rec = self::recommendForProvince($province, 5);
            if ($rec['items']) {
                $lines[] = '- Top CFS crops for ' . $rec['province'] . ' by mean yield (observed seasons only):';
                foreach ($rec['items'] as $item) {
                    $range = ($item['min_yield'] !== null && $item['max_yield'] !== null)
                        ? " range {$item['min_yield']}–{$item['max_yield']} mt/ha"
                        : '';
                    $lim = !empty($item['limited_history']) ? ' [limited seasons]' : '';
                    $lines[] = "  · {$item['crop_name']}: avg yield {$item['avg_yield']} mt/ha over {$item['seasons']} season(s) ({$item['first_season']} to {$item['last_season']}), trend {$item['trend']}{$range}{$lim}";
                }
                $lines[] = '- ' . $rec['disclaimer'];
            } else {
                $lines[] = '- No provincial CFS rows for ' . $province . '.';
            }
            if (!empty($rec['rainfall_note'])) {
                $lines[] = '- ' . $rec['rainfall_note'];
            }
        } else {
            $lines[] = '- Farm province unknown; national CFS season list only.';
        }
        $rainCount = (int) db()->value('SELECT COUNT(*) FROM zamstats_rainfall');
        $liveCount = (int) db()->value('SELECT COUNT(*) FROM zamstats_livestock');
        if ($rainCount === 0) {
            $lines[] = '- No ZamStats rainfall series imported (do not invent rainfall stats).';
        }
        if ($liveCount === 0) {
            $lines[] = '- No ZamStats livestock aggregates imported (do not invent herd stats).';
        }
        return implode("\n", $lines);
    }

    public static function marketAssistantSnippet(?int $farmId): string
    {
        $lines = ['Market price notes (cite source; never invent prices):'];
        $params = [];
        $where = '1=1';
        if ($farmId) {
            $where = '(farm_id = ? OR farm_id IS NULL)';
            $params[] = $farmId;
        }
        $latest = db()->select(
            "SELECT crop_name, market, price_date, price_per_kg, price, unit, currency, source, source_label, province
             FROM market_prices WHERE {$where}
             ORDER BY price_date DESC, id DESC LIMIT 8",
            $params
        );
        if (!$latest) {
            $lines[] = '- No market price rows available.';
            return implode("\n", $lines);
        }
        foreach ($latest as $p) {
            $src = MarketPriceSync::sourceBadge((string) ($p['source'] ?? ''));
            $label = $p['source_label'] ?: $src;
            $amt = $p['price_per_kg'] !== null && (float) $p['price_per_kg'] > 0
                ? ((float) $p['price_per_kg']) . ' /kg'
                : (($p['price'] ?? '') . ' /' . ($p['unit'] ?? ''));
            $lines[] = '- ' . ($p['crop_name'] ?? '') . ' @ ' . ($p['market'] ?? '')
                . ' on ' . ($p['price_date'] ?? '') . ': ' . $amt
                . ' ' . ($p['currency'] ?? 'ZMW')
                . ($p['province'] ? ' (' . $p['province'] . ')' : '')
                . ' [' . $label . ']';
        }
        return implode("\n", $lines);
    }

    private static function yieldTrendDirection(string $crop, string $province): string
    {
        $rows = db()->select(
            "SELECT season_label, yield_mt_ha FROM zamstats_crop_stats
             WHERE crop_name = ? AND province = ? AND farm_category = 'all' AND district IS NULL
               AND yield_mt_ha IS NOT NULL
             ORDER BY season_label ASC",
            [$crop, $province]
        );
        if (count($rows) < 2) {
            return 'insufficient seasons';
        }
        $first = (float) $rows[0]['yield_mt_ha'];
        $last = (float) $rows[count($rows) - 1]['yield_mt_ha'];
        if ($first <= 0) {
            return 'n/a';
        }
        $change = ($last - $first) / $first;
        if ($change > 0.05) {
            return 'improving (' . $rows[0]['season_label'] . '→' . $rows[count($rows) - 1]['season_label'] . ')';
        }
        if ($change < -0.05) {
            return 'declining (' . $rows[0]['season_label'] . '→' . $rows[count($rows) - 1]['season_label'] . ')';
        }
        return 'stable (' . $rows[0]['season_label'] . '→' . $rows[count($rows) - 1]['season_label'] . ')';
    }

    private static function rainfallNote(string $province): ?string
    {
        $rows = db()->select(
            "SELECT season_label, metric, value, unit FROM zamstats_rainfall
             WHERE province = ? OR province = 'National'
             ORDER BY season_label DESC LIMIT 5",
            [$province]
        );
        if (!$rows) {
            return 'No ZamStats rainfall series imported for this province.';
        }
        $bits = [];
        foreach ($rows as $r) {
            $bits[] = $r['season_label'] . ' ' . $r['metric'] . '=' . $r['value'] . ($r['unit'] ? ' ' . $r['unit'] : '');
        }
        return 'Rainfall records: ' . implode('; ', $bits);
    }

    private static function bestCropMatch(string $farmCrop, string $province): ?array
    {
        $candidates = db()->select(
            "SELECT crop_name,
                    COUNT(DISTINCT season_label) AS seasons,
                    AVG(yield_mt_ha) AS avg_yield,
                    MIN(yield_mt_ha) AS min_yield,
                    MAX(yield_mt_ha) AS max_yield,
                    MIN(season_label) AS first_season,
                    MAX(season_label) AS last_season
             FROM zamstats_crop_stats
             WHERE province = ? AND farm_category = 'all' AND district IS NULL AND yield_mt_ha IS NOT NULL
             GROUP BY crop_name",
            [$province]
        );
        $farmLower = strtolower($farmCrop);
        $best = null;
        $bestScore = 0;
        foreach ($candidates as $c) {
            $name = strtolower((string) $c['crop_name']);
            $score = 0;
            if ($name === $farmLower) {
                $score = 100;
            } elseif (str_contains($name, $farmLower) || str_contains($farmLower, explode(' ', $name)[0])) {
                $score = 60;
            } elseif (str_contains($farmLower, 'maize') && str_contains($name, 'maize (for grain)')) {
                $score = 80;
            } elseif (str_contains($farmLower, 'soya') && str_contains($name, 'soybean')) {
                $score = 80;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'crop_name' => $c['crop_name'],
                    'seasons' => (int) $c['seasons'],
                    'avg_yield' => round((float) $c['avg_yield'], 3),
                    'min_yield' => round((float) $c['min_yield'], 3),
                    'max_yield' => round((float) $c['max_yield'], 3),
                    'first_season' => $c['first_season'],
                    'last_season' => $c['last_season'],
                ];
            }
        }
        return $bestScore >= 60 ? $best : null;
    }
}
