<?php

/**
 * Import official ZamStats Crop Forecast Survey aggregate tables (Excel/CSV).
 * Never accepts NADA household microdata — only published provincial/national aggregates.
 */
class ZamstatsAgriImport
{
    private const PROVINCES = [
        'Central', 'Copperbelt', 'Eastern', 'Luapula', 'Lusaka',
        'Muchinga', 'Northern', 'North-Western', 'North Western', 'Southern', 'Western',
        'Zambia', 'National', 'Total',
    ];

    /** @return array{dataset_id:int,inserted:int,updated:int,skipped:int,message:string} */
    public static function importFile(string $path, array $meta = []): array
    {
        Schema::ensureMarketAndAgriSchema(db());
        if (!is_file($path)) {
            throw new RuntimeException('Import file not found.');
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xlsm'], true)) {
            return self::importCfsWorkbook($path, $meta);
        }
        if ($ext === 'csv') {
            return self::importCsv($path, $meta);
        }
        throw new RuntimeException('Supported formats: .xlsx or .csv aggregate tables only.');
    }

    /** @return array{dataset_id:int,inserted:int,updated:int,skipped:int,message:string} */
    public static function importCfsWorkbook(string $path, array $meta = []): array
    {
        $book = SimpleXlsxReader::open($path);
        $checksum = hash_file('sha256', $path) ?: null;
        $seasonDefault = self::detectSeasonFromBook($book) ?? ($meta['season_label'] ?? '2025/2026');
        $title = $meta['title'] ?? ('Crop Forecast Survey ' . $seasonDefault);
        $datasetId = self::registerDataset([
            'title' => $title,
            'source_url' => $meta['source_url'] ?? 'https://www.zamstats.gov.zm/agriculture-and-environment/',
            'season_label' => $seasonDefault,
            'license_note' => $meta['license_note'] ?? 'Zambia Statistics Agency / Ministry of Agriculture — published CFS aggregate tables. Not NADA household microdata.',
            'imported_by' => $meta['imported_by'] ?? null,
            'checksum' => $checksum,
        ]);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($book['sheets'] as $sheetName => $sheetPath) {
            $norm = strtolower(trim($sheetName));
            $rows = SimpleXlsxReader::sheetRows($sheetPath, $book['shared']);
            if ($norm === 'provincial' || $norm === 'provincial ') {
                $r = self::ingestProvinceCropTable($datasetId, $rows, $seasonDefault, 'all', trim($sheetName));
            } elseif ($norm === 'sm_provincial' || $norm === 'sm provincial') {
                $r = self::ingestProvinceCropTable($datasetId, $rows, $seasonDefault, 'small_medium', trim($sheetName));
            } elseif ($norm === 'ls province' || $norm === 'ls_province') {
                $r = self::ingestProvinceCropTable($datasetId, $rows, $seasonDefault, 'large', trim($sheetName));
            } elseif ($norm === 'national' || $norm === 'national ') {
                $r = self::ingestNationalCropTable($datasetId, $rows, $seasonDefault, 'all', trim($sheetName));
            } elseif ($norm === 'sm national') {
                $r = self::ingestNationalCropTable($datasetId, $rows, $seasonDefault, 'small_medium', trim($sheetName));
            } elseif ($norm === 'ls national') {
                $r = self::ingestNationalCropTable($datasetId, $rows, $seasonDefault, 'large', trim($sheetName));
            } elseif ($norm === '% change' || $norm === 'percent change') {
                $r = self::ingestPercentChangePhs($datasetId, $rows, trim($sheetName));
            } else {
                continue;
            }
            $inserted += $r['inserted'];
            $updated += $r['updated'];
            $skipped += $r['skipped'];
        }

        db()->update('zamstats_datasets', [
            'row_count' => $inserted + $updated,
            'imported_at' => date('Y-m-d H:i:s'),
        ], $datasetId);

        return [
            'dataset_id' => $datasetId,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'message' => "CFS import \"{$title}\": +{$inserted} new, {$updated} updated aggregates (skipped {$skipped}).",
        ];
    }

    /** @return array{dataset_id:int,inserted:int,updated:int,skipped:int,message:string} */
    public static function importCsv(string $path, array $meta = []): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException('Could not open CSV.');
        }
        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            throw new RuntimeException('Empty CSV.');
        }
        $map = self::mapHeader($header);
        $kind = $meta['kind'] ?? 'crop';
        $season = $meta['season_label'] ?? 'unknown';
        $title = $meta['title'] ?? ('ZamStats CSV ' . basename($path));
        $datasetId = self::registerDataset([
            'title' => $title,
            'source_url' => $meta['source_url'] ?? null,
            'season_label' => $season,
            'license_note' => $meta['license_note'] ?? 'Admin-curated published aggregate CSV (not NADA microdata).',
            'imported_by' => $meta['imported_by'] ?? null,
            'checksum' => hash_file('sha256', $path) ?: null,
        ]);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        while (($row = fgetcsv($fh)) !== false) {
            if ($kind === 'rainfall') {
                $r = self::upsertRainfall($datasetId, self::rowAssoc($map, $row), $season);
            } elseif ($kind === 'livestock') {
                $r = self::upsertLivestock($datasetId, self::rowAssoc($map, $row), $season);
            } else {
                $assoc = self::rowAssoc($map, $row);
                if (empty($assoc['crop_name']) || empty($assoc['province'])) {
                    $skipped++;
                    continue;
                }
                $r = self::upsertCropStat($datasetId, [
                    'season_label' => $assoc['season_label'] ?? $season,
                    'crop_name' => self::normalizeCrop((string) $assoc['crop_name']),
                    'province' => self::normalizeProvince((string) $assoc['province']),
                    'district' => $assoc['district'] ?? null,
                    'farm_category' => $assoc['farm_category'] ?? 'all',
                    'area_planted_ha' => self::num($assoc['area_planted_ha'] ?? null),
                    'area_harvested_ha' => self::num($assoc['area_harvested_ha'] ?? null),
                    'production_mt' => self::num($assoc['production_mt'] ?? null),
                    'yield_mt_ha' => self::num($assoc['yield_mt_ha'] ?? null),
                    'fertiliser_basal_mt' => self::num($assoc['fertiliser_basal_mt'] ?? null),
                    'fertiliser_top_mt' => self::num($assoc['fertiliser_top_mt'] ?? null),
                    'sales_mt' => self::num($assoc['sales_mt'] ?? null),
                    'source_sheet' => 'csv',
                ]);
            }
            if ($r === 'insert') {
                $inserted++;
            } elseif ($r === 'update') {
                $updated++;
            } else {
                $skipped++;
            }
        }
        fclose($fh);
        db()->update('zamstats_datasets', [
            'row_count' => $inserted + $updated,
            'imported_at' => date('Y-m-d H:i:s'),
        ], $datasetId);
        return [
            'dataset_id' => $datasetId,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'message' => "CSV import \"{$title}\": +{$inserted} new, {$updated} updated.",
        ];
    }

    /** @param list<list<mixed>> $rows */
    private static function ingestProvinceCropTable(int $datasetId, array $rows, string $season, string $category, string $sheet): array
    {
        $headerIdx = self::findHeaderRow($rows, ['crop', 'province']);
        if ($headerIdx === null) {
            return ['inserted' => 0, 'updated' => 0, 'skipped' => count($rows)];
        }
        $map = self::mapHeader(array_map(static fn ($v) => (string) ($v ?? ''), $rows[$headerIdx]));
        $crop = '';
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $assoc = self::rowAssoc($map, $rows[$i]);
            $c = trim((string) ($assoc['crop_name'] ?? ''));
            if ($c !== '') {
                $crop = self::normalizeCrop($c);
            }
            $provRaw = trim((string) ($assoc['province'] ?? ''));
            if ($crop === '' || $provRaw === '') {
                $skipped++;
                continue;
            }
            $province = self::normalizeProvince($provRaw);
            if ($province === 'Total') {
                $province = 'National';
            }
            $planted = self::num($assoc['area_planted_ha'] ?? null);
            $harvested = self::num($assoc['area_harvested_ha'] ?? null);
            $prod = self::num($assoc['production_mt'] ?? null);
            $yield = self::num($assoc['yield_mt_ha'] ?? null);
            $sales = self::num($assoc['sales_mt'] ?? null);
            if ($planted === null && $harvested === null && $prod === null && $yield === null) {
                $skipped++;
                continue;
            }
            if ($yield === null && $planted && $planted > 0 && $prod !== null) {
                $yield = round($prod / $planted, 6);
            }
            $status = self::upsertCropStat($datasetId, [
                'season_label' => $season,
                'crop_name' => $crop,
                'province' => $province,
                'district' => null,
                'farm_category' => $category,
                'area_planted_ha' => $planted,
                'area_harvested_ha' => $harvested,
                'production_mt' => $prod,
                'yield_mt_ha' => $yield,
                'sales_mt' => $sales,
                'source_sheet' => $sheet,
            ]);
            if ($status === 'insert') {
                $inserted++;
            } elseif ($status === 'update') {
                $updated++;
            } else {
                $skipped++;
            }
        }
        return compact('inserted', 'updated', 'skipped');
    }

    /** @param list<list<mixed>> $rows */
    private static function ingestNationalCropTable(int $datasetId, array $rows, string $season, string $category, string $sheet): array
    {
        $headerIdx = self::findHeaderRow($rows, ['crop']);
        if ($headerIdx === null) {
            return ['inserted' => 0, 'updated' => 0, 'skipped' => count($rows)];
        }
        $map = self::mapHeader(array_map(static fn ($v) => (string) ($v ?? ''), $rows[$headerIdx]));
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $assoc = self::rowAssoc($map, $rows[$i]);
            $crop = self::normalizeCrop((string) ($assoc['crop_name'] ?? ''));
            if ($crop === '') {
                $skipped++;
                continue;
            }
            $planted = self::num($assoc['area_planted_ha'] ?? null);
            $harvested = self::num($assoc['area_harvested_ha'] ?? null);
            $prod = self::num($assoc['production_mt'] ?? null);
            $yield = self::num($assoc['yield_mt_ha'] ?? null);
            $sales = self::num($assoc['sales_mt'] ?? null);
            if ($planted === null && $prod === null && $yield === null) {
                $skipped++;
                continue;
            }
            if ($yield === null && $planted && $planted > 0 && $prod !== null) {
                $yield = round($prod / $planted, 6);
            }
            $status = self::upsertCropStat($datasetId, [
                'season_label' => $season,
                'crop_name' => $crop,
                'province' => 'National',
                'district' => null,
                'farm_category' => $category,
                'area_planted_ha' => $planted,
                'area_harvested_ha' => $harvested,
                'production_mt' => $prod,
                'yield_mt_ha' => $yield,
                'sales_mt' => $sales,
                'source_sheet' => $sheet,
            ]);
            if ($status === 'insert') {
                $inserted++;
            } elseif ($status === 'update') {
                $updated++;
            } else {
                $skipped++;
            }
        }
        return compact('inserted', 'updated', 'skipped');
    }

    /**
     * PHS 2024/2025 actuals vs CFS 2025/2026 from the % Change sheet (national only).
     * @param list<list<mixed>> $rows
     */
    private static function ingestPercentChangePhs(int $datasetId, array $rows, string $sheet): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        // Row layout: title, metric headers, Actual/Forecast labels, then crop rows
        for ($i = 0; $i < count($rows); $i++) {
            $crop = trim((string) ($rows[$i][0] ?? ''));
            if ($crop === '' || str_starts_with(strtolower($crop), 'percentage') || strtolower($crop) === 'crop') {
                continue;
            }
            if (!is_numeric($rows[$i][1] ?? null) && !is_numeric($rows[$i][7] ?? null)) {
                continue;
            }
            $cropName = self::normalizeCrop($crop);
            // Columns: Crop, Area Planted Actual, Forecast, %, Area Harv Actual, Forecast, %, Prod Actual, Forecast, %, Yield Actual, Forecast, %
            $phs = [
                'season_label' => '2024/2025',
                'crop_name' => $cropName,
                'province' => 'National',
                'district' => null,
                'farm_category' => 'all',
                'area_planted_ha' => self::num($rows[$i][1] ?? null),
                'area_harvested_ha' => self::num($rows[$i][4] ?? null),
                'production_mt' => self::num($rows[$i][7] ?? null),
                'yield_mt_ha' => self::num($rows[$i][10] ?? null),
                'sales_mt' => null,
                'source_sheet' => $sheet . ' (PHS actual)',
            ];
            $cfs = [
                'season_label' => '2025/2026',
                'crop_name' => $cropName,
                'province' => 'National',
                'district' => null,
                'farm_category' => 'all',
                'area_planted_ha' => self::num($rows[$i][2] ?? null),
                'area_harvested_ha' => self::num($rows[$i][5] ?? null),
                'production_mt' => self::num($rows[$i][8] ?? null),
                'yield_mt_ha' => self::num($rows[$i][11] ?? null),
                'sales_mt' => null,
                'source_sheet' => $sheet . ' (CFS forecast)',
            ];
            foreach ([$phs, $cfs] as $stat) {
                if ($stat['production_mt'] === null && $stat['area_planted_ha'] === null && $stat['yield_mt_ha'] === null) {
                    $skipped++;
                    continue;
                }
                $status = self::upsertCropStat($datasetId, $stat);
                if ($status === 'insert') {
                    $inserted++;
                } elseif ($status === 'update') {
                    $updated++;
                } else {
                    $skipped++;
                }
            }
        }
        return compact('inserted', 'updated', 'skipped');
    }

    /** @param array<string,mixed> $stat */
    private static function upsertCropStat(int $datasetId, array $stat): string
    {
        $season = (string) $stat['season_label'];
        $crop = (string) $stat['crop_name'];
        $province = (string) $stat['province'];
        $district = $stat['district'] !== null && $stat['district'] !== '' ? (string) $stat['district'] : null;
        $cat = (string) ($stat['farm_category'] ?? 'all');
        $key = 'cfs:' . sha1(implode('|', [$season, $crop, $province, $district ?? '', $cat]));
        $payload = [
            'dataset_id' => $datasetId,
            'season_label' => mb_substr($season, 0, 40),
            'crop_name' => mb_substr($crop, 0, 160),
            'province' => mb_substr($province, 0, 120),
            'district' => $district ? mb_substr($district, 0, 120) : null,
            'farm_category' => mb_substr($cat, 0, 40),
            'area_planted_ha' => $stat['area_planted_ha'],
            'area_harvested_ha' => $stat['area_harvested_ha'] ?? null,
            'production_mt' => $stat['production_mt'],
            'yield_mt_ha' => $stat['yield_mt_ha'],
            'fertiliser_basal_mt' => $stat['fertiliser_basal_mt'] ?? null,
            'fertiliser_top_mt' => $stat['fertiliser_top_mt'] ?? null,
            'sales_mt' => $stat['sales_mt'] ?? null,
            'source_sheet' => mb_substr((string) ($stat['source_sheet'] ?? ''), 0, 80),
            'external_key' => $key,
        ];
        $existing = db()->first('SELECT id FROM zamstats_crop_stats WHERE external_key = ?', [$key]);
        if ($existing) {
            db()->update('zamstats_crop_stats', $payload, (int) $existing['id']);
            return 'update';
        }
        db()->insert('zamstats_crop_stats', $payload);
        return 'insert';
    }

    /** @param array<string,mixed> $row */
    private static function upsertRainfall(int $datasetId, array $row, string $season): string
    {
        $metric = trim((string) ($row['metric'] ?? $row['rainfall_metric'] ?? ''));
        $value = self::num($row['value'] ?? $row['rainfall'] ?? null);
        $province = self::normalizeProvince((string) ($row['province'] ?? 'National'));
        if ($metric === '' || $value === null) {
            return 'skip';
        }
        $seasonLabel = (string) ($row['season_label'] ?? $season);
        $district = trim((string) ($row['district'] ?? '')) ?: null;
        $unit = trim((string) ($row['unit'] ?? 'mm')) ?: 'mm';
        $key = 'rain:' . sha1(implode('|', [$seasonLabel, $province, $district ?? '', $metric]));
        $payload = [
            'dataset_id' => $datasetId,
            'season_label' => mb_substr($seasonLabel, 0, 40),
            'province' => mb_substr($province, 0, 120),
            'district' => $district,
            'metric' => mb_substr($metric, 0, 80),
            'value' => $value,
            'unit' => mb_substr($unit, 0, 40),
            'external_key' => $key,
        ];
        $existing = db()->first('SELECT id FROM zamstats_rainfall WHERE external_key = ?', [$key]);
        if ($existing) {
            db()->update('zamstats_rainfall', $payload, (int) $existing['id']);
            return 'update';
        }
        db()->insert('zamstats_rainfall', $payload);
        return 'insert';
    }

    /** @param array<string,mixed> $row */
    private static function upsertLivestock(int $datasetId, array $row, string $year): string
    {
        $species = trim((string) ($row['species'] ?? $row['livestock'] ?? ''));
        $head = self::num($row['headcount'] ?? $row['number'] ?? null);
        $province = self::normalizeProvince((string) ($row['province'] ?? 'National'));
        if ($species === '' || $head === null) {
            return 'skip';
        }
        $yearLabel = (string) ($row['year_label'] ?? $row['season_label'] ?? $year);
        $key = 'live:' . sha1(implode('|', [$yearLabel, $province, $species]));
        $payload = [
            'dataset_id' => $datasetId,
            'year_label' => mb_substr($yearLabel, 0, 40),
            'province' => mb_substr($province, 0, 120),
            'species' => mb_substr($species, 0, 120),
            'headcount' => $head,
            'external_key' => $key,
        ];
        $existing = db()->first('SELECT id FROM zamstats_livestock WHERE external_key = ?', [$key]);
        if ($existing) {
            db()->update('zamstats_livestock', $payload, (int) $existing['id']);
            return 'update';
        }
        db()->insert('zamstats_livestock', $payload);
        return 'insert';
    }

    /** @param array<string,mixed> $meta */
    private static function registerDataset(array $meta): int
    {
        if (!empty($meta['checksum'])) {
            $existing = db()->first('SELECT id FROM zamstats_datasets WHERE checksum = ?', [$meta['checksum']]);
            if ($existing) {
                db()->update('zamstats_datasets', [
                    'title' => $meta['title'],
                    'source_url' => $meta['source_url'] ?? null,
                    'season_label' => $meta['season_label'] ?? null,
                    'license_note' => $meta['license_note'] ?? null,
                    'imported_by' => $meta['imported_by'] ?? null,
                    'imported_at' => date('Y-m-d H:i:s'),
                ], (int) $existing['id']);
                return (int) $existing['id'];
            }
        }
        return db()->insert('zamstats_datasets', [
            'title' => $meta['title'],
            'source_url' => $meta['source_url'] ?? null,
            'season_label' => $meta['season_label'] ?? null,
            'license_note' => $meta['license_note'] ?? null,
            'imported_by' => $meta['imported_by'] ?? null,
            'imported_at' => date('Y-m-d H:i:s'),
            'row_count' => 0,
            'checksum' => $meta['checksum'] ?? null,
        ]);
    }

    /** @param array{sheets:array<string,string>,shared:list<string>} $book */
    private static function detectSeasonFromBook(array $book): ?string
    {
        $found = [];
        foreach ($book['sheets'] as $name => $path) {
            $rows = SimpleXlsxReader::sheetRows($path, $book['shared'], 3);
            foreach ($rows as $row) {
                $text = implode(' ', array_map(static fn ($v) => (string) ($v ?? ''), $row));
                if (preg_match_all('/(20\d{2}\s*\/\s*20\d{2})/', $text, $matches)) {
                    foreach ($matches[1] as $raw) {
                        $found[] = preg_replace('/\s+/', '', $raw);
                    }
                }
            }
            // Prefer National / Provincial sheet titles over % Change comparisons
            $norm = strtolower(trim($name));
            if (in_array($norm, ['national', 'national ', 'provincial', 'provincial '], true) && $found) {
                // keep scanning to collect; prefer max later
            }
        }
        $found = array_values(array_unique(array_filter($found)));
        if (!$found) {
            return null;
        }
        rsort($found, SORT_STRING);
        return $found[0];
    }

    /** @param list<list<mixed>> $rows @param list<string> $needles */
    private static function findHeaderRow(array $rows, array $needles): ?int
    {
        foreach ($rows as $i => $row) {
            $cells = [];
            foreach ($row as $v) {
                $cells[] = strtolower(trim((string) ($v ?? '')));
            }
            // Prefer true column headers (short labels), not long title rows
            $looksLikeTitle = false;
            foreach ($cells as $c) {
                if (strlen($c) > 80) {
                    $looksLikeTitle = true;
                    break;
                }
            }
            if ($looksLikeTitle) {
                continue;
            }
            $ok = true;
            foreach ($needles as $n) {
                $n = strtolower($n);
                $found = false;
                foreach ($cells as $c) {
                    if ($c === $n || str_starts_with($c, $n . ' ') || str_starts_with($c, $n . '(')) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $i;
            }
        }
        return null;
    }

    /** @param list<string> $header @return array<string,int> */
    private static function mapHeader(array $header): array
    {
        $aliases = [
            'crop_name' => ['crop', 'crop_name', 'commodity', 'crop name'],
            'province' => ['province', 'admin1', 'region'],
            'district' => ['district', 'admin2'],
            'season_label' => ['season', 'season_label', 'agricultural season', 'year'],
            'farm_category' => ['farm_category', 'category', 'scale'],
            'area_planted_ha' => ['area planted (ha)', 'area planted', 'area_planted_ha', 'planted'],
            'area_harvested_ha' => ['area to be harvested (ha)', 'area harvested (ha)', 'area harvested', 'area_harvested_ha', 'harvested'],
            'production_mt' => ['expected production (mt)', 'production (mt)', 'production_mt', 'production'],
            'yield_mt_ha' => ['expected yield on area planted (mt/ha)', 'expected yield (mt/ha)', 'yield (mt/ha)', 'yield_mt_ha', 'yield'],
            'sales_mt' => ['expected sales (mt)', 'sales (mt)', 'sales_mt', 'sales'],
            'fertiliser_basal_mt' => ['fertiliser basal', 'fertilizer basal', 'basal'],
            'fertiliser_top_mt' => ['fertiliser top', 'fertilizer top', 'top dressing'],
            'metric' => ['metric', 'rainfall_metric'],
            'value' => ['value', 'rainfall', 'mm'],
            'unit' => ['unit'],
            'species' => ['species', 'livestock'],
            'headcount' => ['headcount', 'number', 'heads'],
            'year_label' => ['year_label', 'year'],
        ];
        $map = [];
        $normalized = [];
        foreach ($header as $i => $h) {
            $normalized[$i] = strtolower(trim(preg_replace('/\s+/', ' ', (string) $h) ?? ''));
        }
        foreach ($aliases as $field => $opts) {
            foreach ($normalized as $i => $h) {
                if (in_array($h, $opts, true)) {
                    $map[$field] = $i;
                    break;
                }
            }
            if (!isset($map[$field])) {
                foreach ($normalized as $i => $h) {
                    foreach ($opts as $opt) {
                        if ($h !== '' && str_contains($h, $opt)) {
                            $map[$field] = $i;
                            break 2;
                        }
                    }
                }
            }
        }
        return $map;
    }

    /** @param array<string,int> $map @param list<mixed> $row */
    private static function rowAssoc(array $map, array $row): array
    {
        $out = [];
        foreach ($map as $field => $i) {
            $out[$field] = $row[$i] ?? null;
        }
        return $out;
    }

    public static function normalizeCrop(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $name = rtrim($name, ' *');
        $name = str_replace(['(', ')'], ['(', ')'], $name);
        // Unify common CFS label variants
        $replacements = [
            'Maize(for grain)' => 'Maize (for grain)',
            'Sorghum(for grain)' => 'Sorghum (for grain)',
            'Sorghum (for Grain)' => 'Sorghum (for grain)',
            'Soya beans (for grain)' => 'Soybeans (for grain)',
            'Groundnuts (Unshelled)' => 'Groundnuts (unshelled)',
            'Groundnuts(unshelled)' => 'Groundnuts (unshelled)',
            'Mixed Beans (for grain)' => 'Mixed beans (for grain)',
            'Cow Peas (for grain)' => 'Cowpeas (for grain)',
            'Sweet Potatoes (for tuber)' => 'Sweet potatoes (tuber)',
            'Irish Potatoes (for tuber)' => 'Irish potatoes (tuber)',
            'Seed Cotton' => 'Seed cotton',
        ];
        return $replacements[$name] ?? $name;
    }

    public static function normalizeProvince(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if (strcasecmp($name, 'North Western') === 0) {
            return 'North-Western';
        }
        if (strcasecmp($name, 'Zambia') === 0 || strcasecmp($name, 'Total') === 0) {
            return $name === 'Total' ? 'Total' : 'National';
        }
        foreach (self::PROVINCES as $p) {
            if (strcasecmp($p, $name) === 0) {
                return $p === 'Zambia' ? 'National' : ($p === 'North Western' ? 'North-Western' : $p);
            }
        }
        return $name;
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v)) {
            $v = str_replace([',', ' '], '', $v);
        }
        if (!is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }
}
