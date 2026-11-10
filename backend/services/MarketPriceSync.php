<?php

/**
 * Import Zambia market prices from WFP/HDX CSV and ZamStats bulletin forms.
 * Labels HDX rows as wfp_hdx (ZamStats may contribute upstream; no per-row source in CSV).
 */
class MarketPriceSync
{
    public const HDX_URL = 'https://data.humdata.org/dataset/3f74001c-3554-4c54-bd86-c66208563316/resource/d9a34dc4-ff1d-43bf-9592-03d12d027848/download/wfp_food_prices_zmb.csv';

    /** @return array{inserted:int,updated:int,skipped:int,message:string} */
    public static function syncHdx(?string $csvPath = null, int $monthsBack = 36, ?callable $progress = null): array
    {
        Schema::ensureMarketAndAgriSchema(db());
        $path = $csvPath;
        $tmp = null;
        if ($path === null || $path === '') {
            $tmp = dirname(__DIR__, 2) . '/storage/tmp-wfp-zambia-prices.csv';
            self::download(self::HDX_URL, $tmp);
            $path = $tmp;
        }
        if (!is_file($path)) {
            throw new RuntimeException('HDX CSV not found.');
        }

        $since = (new DateTimeImmutable('first day of this month'))->modify('-' . max(1, $monthsBack) . ' months')->format('Y-m-d');
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException('Could not open CSV.');
        }
        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            throw new RuntimeException('Empty CSV.');
        }
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
        // Skip HXL metadata row when present
        $peekPos = ftell($fh);
        $maybeHxl = fgetcsv($fh);
        if ($maybeHxl && isset($maybeHxl[0]) && str_starts_with(trim((string) $maybeHxl[0]), '#')) {
            // consumed
        } else {
            fseek($fh, $peekPos);
        }

        $idx = static function (array $aliases) use ($header): ?int {
            foreach ($aliases as $a) {
                $i = array_search($a, $header, true);
                if ($i !== false) {
                    return (int) $i;
                }
            }
            return null;
        };
        $iDate = $idx(['date']);
        $iAdmin1 = $idx(['admin1', 'province']);
        $iAdmin2 = $idx(['admin2', 'district']);
        $iMarket = $idx(['market']);
        $iCommodity = $idx(['commodity', 'crop', 'crop_name']);
        $iUnit = $idx(['unit']);
        $iCurrency = $idx(['currency']);
        $iPrice = $idx(['price']);
        $iPriceType = $idx(['pricetype', 'price_type']);
        $iPriceFlag = $idx(['priceflag', 'price_flag']);
        if ($iDate === null || $iCommodity === null || $iPrice === null) {
            fclose($fh);
            throw new RuntimeException('CSV missing required columns (date, commodity, price).');
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $batch = 0;
        while (($row = fgetcsv($fh)) !== false) {
            if (!$row || count($row) < 3) {
                $skipped++;
                continue;
            }
            $date = substr(trim((string) ($row[$iDate] ?? '')), 0, 10);
            if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $since) {
                $skipped++;
                continue;
            }
            $flag = $iPriceFlag !== null ? strtolower(trim((string) ($row[$iPriceFlag] ?? ''))) : 'actual';
            if ($flag !== '' && $flag !== 'actual' && $flag !== 'aggregate') {
                $skipped++;
                continue;
            }
            $crop = trim((string) ($row[$iCommodity] ?? ''));
            $price = (float) ($row[$iPrice] ?? 0);
            if ($crop === '' || $price <= 0) {
                $skipped++;
                continue;
            }
            $unit = $iUnit !== null ? trim((string) ($row[$iUnit] ?? '')) : 'KG';
            $market = $iMarket !== null ? trim((string) ($row[$iMarket] ?? '')) : '';
            $province = $iAdmin1 !== null ? trim((string) ($row[$iAdmin1] ?? '')) : '';
            $district = $iAdmin2 !== null ? trim((string) ($row[$iAdmin2] ?? '')) : '';
            $currency = $iCurrency !== null ? trim((string) ($row[$iCurrency] ?? 'ZMW')) : 'ZMW';
            $priceType = $iPriceType !== null ? trim((string) ($row[$iPriceType] ?? '')) : '';
            $perKg = self::toPricePerKg($price, $unit);
            $external = 'wfp:' . sha1(implode('|', [$date, $market, $crop, $unit, $priceType, (string) $price]));

            $existing = db()->first('SELECT id FROM market_prices WHERE external_key = ?', [$external]);
            $payload = [
                'farm_id' => null,
                'crop_name' => mb_substr($crop, 0, 120),
                'market' => mb_substr($market !== '' ? $market : 'Zambia market', 0, 120),
                'price_date' => $date,
                'price' => round($price, 4),
                'price_per_kg' => $perKg,
                'unit' => mb_substr($unit !== '' ? $unit : 'KG', 0, 40),
                'currency' => mb_substr($currency !== '' ? $currency : 'ZMW', 0, 12),
                'province' => $province !== '' ? mb_substr($province, 0, 120) : null,
                'district' => $district !== '' ? mb_substr($district, 0, 120) : null,
                'source' => 'wfp_hdx',
                'source_label' => 'WFP/HDX Zambia Food Prices',
                'external_key' => $external,
                'imported_at' => date('Y-m-d H:i:s'),
                'demand_level' => 'medium',
                'notes' => $priceType !== '' ? ('Price type: ' . $priceType) : null,
            ];
            if ($existing) {
                db()->update('market_prices', $payload, (int) $existing['id']);
                $updated++;
            } else {
                // Legacy NOT NULL on price_per_kg: store 0 only when conversion impossible
                if ($payload['price_per_kg'] === null) {
                    $payload['price_per_kg'] = 0;
                }
                db()->insert('market_prices', $payload);
                $inserted++;
            }
            $batch++;
            if ($progress && $batch % 500 === 0) {
                $progress($inserted, $updated, $skipped);
            }
        }
        fclose($fh);
        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'message' => "HDX sync complete since {$since}: +{$inserted} new, {$updated} updated, {$skipped} skipped.",
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{inserted:int,updated:int,skipped:int,message:string}
     */
    public static function importBulletinRows(array $rows, ?int $userId = null): array
    {
        Schema::ensureMarketAndAgriSchema(db());
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $crop = trim((string) ($row['crop_name'] ?? $row['commodity'] ?? ''));
            $date = substr(trim((string) ($row['price_date'] ?? $row['date'] ?? '')), 0, 10);
            $price = (float) ($row['price'] ?? $row['price_per_kg'] ?? 0);
            $unit = trim((string) ($row['unit'] ?? 'KG'));
            if ($crop === '' || $date === '' || $price <= 0) {
                $skipped++;
                continue;
            }
            $market = trim((string) ($row['market'] ?? 'ZamStats bulletin'));
            $province = trim((string) ($row['province'] ?? ''));
            $district = trim((string) ($row['district'] ?? ''));
            $perKg = self::toPricePerKg($price, $unit);
            if ($perKg === null && isset($row['price_per_kg']) && (float) $row['price_per_kg'] > 0) {
                $perKg = (float) $row['price_per_kg'];
            }
            $external = 'zamstats:' . sha1(implode('|', [$date, $market, $crop, $unit, $province, $district, (string) $price]));
            $existing = db()->first('SELECT id FROM market_prices WHERE external_key = ?', [$external]);
            $payload = [
                'farm_id' => null,
                'crop_name' => mb_substr($crop, 0, 120),
                'market' => mb_substr($market, 0, 120),
                'price_date' => $date,
                'price' => round($price, 4),
                'price_per_kg' => $perKg ?? 0,
                'unit' => mb_substr($unit !== '' ? $unit : 'KG', 0, 40),
                'currency' => 'ZMW',
                'province' => $province !== '' ? mb_substr($province, 0, 120) : null,
                'district' => $district !== '' ? mb_substr($district, 0, 120) : null,
                'source' => 'zamstats_bulletin',
                'source_label' => 'Zambia Statistics Agency Monthly Bulletin',
                'external_key' => $external,
                'imported_at' => date('Y-m-d H:i:s'),
                'demand_level' => trim((string) ($row['demand_level'] ?? 'medium')) ?: 'medium',
                'notes' => trim((string) ($row['notes'] ?? '')) ?: ('Imported by user #' . ($userId ?? 0)),
            ];
            if ($existing) {
                db()->update('market_prices', $payload, (int) $existing['id']);
                $updated++;
            } else {
                db()->insert('market_prices', $payload);
                $inserted++;
            }
        }
        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'message' => "ZamStats bulletin import: +{$inserted} new, {$updated} updated, {$skipped} skipped.",
        ];
    }

    public static function sourceBadge(string $source): string
    {
        return match ($source) {
            'wfp_hdx' => 'WFP/HDX',
            'zamstats_bulletin' => 'ZamStats bulletin',
            'farmer' => 'Farmer',
            default => $source !== '' ? $source : 'Unknown',
        };
    }

    public static function toPricePerKg(float $price, string $unit): ?float
    {
        $u = strtoupper(trim($unit));
        if ($u === '' || $u === 'KG' || $u === 'KG.' || $u === 'KILOGRAM' || $u === 'KILOGRAMS') {
            return round($price, 4);
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*KG/', $u, $m)) {
            $kg = (float) $m[1];
            return $kg > 0 ? round($price / $kg, 4) : null;
        }
        if (in_array($u, ['50 KG', '50KG', '50 KG BAG'], true)) {
            return round($price / 50, 4);
        }
        if (in_array($u, ['25 KG', '25KG'], true)) {
            return round($price / 25, 4);
        }
        if (in_array($u, ['90 KG', '90KG'], true)) {
            return round($price / 90, 4);
        }
        // Non-mass units: do not invent a per-kg figure
        return null;
    }

    private static function download(string $url, string $dest): void
    {
        @mkdir(dirname($dest), 0775, true);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $fp = fopen($dest, 'wb');
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_USERAGENT => 'FarmWise/1.0 (market price sync)',
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);
            if (!$ok || $code === 202 || $code >= 400 || !is_file($dest) || filesize($dest) < 100) {
                @unlink($dest);
                $hint = $code === 202
                    ? ' HDX/WAF returned 202 challenge — download the CSV in a browser and sync with a local file path.'
                    : '';
                throw new RuntimeException('HDX download failed (HTTP ' . $code . ').' . $hint);
            }
            return;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 180, 'header' => "User-Agent: FarmWise/1.0\r\n"]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || strlen($data) < 100) {
            throw new RuntimeException('HDX download failed. Download the CSV manually and pass a local path.');
        }
        file_put_contents($dest, $data);
    }
}
