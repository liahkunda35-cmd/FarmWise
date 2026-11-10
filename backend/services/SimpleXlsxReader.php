<?php

/**
 * Lightweight XLSX reader (sharedStrings + worksheet cells).
 * Prefer ZipArchive; fall back to tar/Expand-Archive unpack on hosts without php_zip.
 */
class SimpleXlsxReader
{
    /** @return array{sheets: array<string,string>, shared: list<string>, dir: string} */
    public static function open(string $xlsxPath): array
    {
        if (!is_file($xlsxPath)) {
            throw new RuntimeException('Excel file not found: ' . $xlsxPath);
        }
        $dir = dirname(__DIR__, 2) . '/storage/tmp-xlsx-' . substr(sha1($xlsxPath . filesize($xlsxPath)), 0, 12);
        if (!is_dir($dir . '/xl/worksheets')) {
            self::extract($xlsxPath, $dir);
        }
        $workbook = simplexml_load_file($dir . '/xl/workbook.xml');
        if (!$workbook) {
            throw new RuntimeException('Could not read workbook.xml');
        }
        $rels = simplexml_load_file($dir . '/xl/_rels/workbook.xml.rels');
        $relMap = [];
        if ($rels) {
            foreach ($rels->Relationship as $rel) {
                $relMap[(string) $rel['Id']] = (string) $rel['Target'];
            }
        }
        $ns = $workbook->getNamespaces(true);
        $rNs = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sheets = [];
        foreach ($workbook->sheets->sheet as $sheet) {
            $name = trim((string) $sheet['name']);
            $rid = (string) $sheet->attributes($rNs)['id'];
            $target = $relMap[$rid] ?? '';
            $target = ltrim(str_replace('\\', '/', $target), '/');
            if (str_starts_with($target, 'xl/')) {
                $path = $dir . '/' . $target;
            } else {
                $path = $dir . '/xl/' . $target;
            }
            if ($name !== '' && is_file($path)) {
                $sheets[$name] = $path;
            }
        }
        $shared = [];
        $ssPath = $dir . '/xl/sharedStrings.xml';
        if (is_file($ssPath)) {
            $ss = simplexml_load_file($ssPath);
            if ($ss) {
                foreach ($ss->si as $si) {
                    $t = '';
                    if (isset($si->t)) {
                        $t = (string) $si->t;
                    } else {
                        foreach ($si->r as $r) {
                            $t .= (string) $r->t;
                        }
                    }
                    $shared[] = $t;
                }
            }
        }
        return ['sheets' => $sheets, 'shared' => $shared, 'dir' => $dir];
    }

    /** @return list<list<string|float|null>> */
    public static function sheetRows(string $sheetPath, array $shared, int $maxRows = 0): array
    {
        $sheet = simplexml_load_file($sheetPath);
        if (!$sheet || !isset($sheet->sheetData)) {
            return [];
        }
        $rows = [];
        $count = 0;
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            $maxCol = -1;
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::colIndex($ref);
                $maxCol = max($maxCol, $col);
                $type = (string) $c['t'];
                $raw = isset($c->v) ? (string) $c->v : '';
                if ($type === 's') {
                    $val = $shared[(int) $raw] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = isset($c->is->t) ? (string) $c->is->t : '';
                } elseif ($raw === '') {
                    $val = null;
                } elseif (is_numeric($raw)) {
                    $val = str_contains($raw, '.') ? (float) $raw : (0 + $raw);
                } else {
                    $val = $raw;
                }
                $cells[$col] = $val;
            }
            $line = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $line[] = $cells[$i] ?? null;
            }
            $rows[] = $line;
            $count++;
            if ($maxRows > 0 && $count >= $maxRows) {
                break;
            }
        }
        return $rows;
    }

    public static function cleanup(string $dir): void
    {
        if ($dir === '' || !is_dir($dir) || !str_contains($dir, 'tmp-xlsx-')) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    private static function extract(string $xlsxPath, string $dir): void
    {
        if (is_dir($dir)) {
            self::cleanup($dir);
        }
        @mkdir($dir, 0775, true);
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($xlsxPath) === true) {
                $zip->extractTo($dir);
                $zip->close();
                return;
            }
        }
        $abs = realpath($xlsxPath) ?: $xlsxPath;
        $tar = self::findBinary(['tar.exe', 'tar']);
        if ($tar) {
            $cmd = escapeshellarg($tar) . ' -xf ' . escapeshellarg($abs) . ' -C ' . escapeshellarg($dir);
            exec($cmd . ' 2>&1', $out, $code);
            if ($code === 0 && is_dir($dir . '/xl')) {
                return;
            }
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $zipCopy = $dir . '_src.zip';
            @copy($abs, $zipCopy);
            $ps = 'Expand-Archive -LiteralPath ' . escapeshellarg($zipCopy) . ' -DestinationPath ' . escapeshellarg($dir) . ' -Force';
            exec('powershell -NoProfile -Command ' . escapeshellarg($ps) . ' 2>&1', $out2, $code2);
            @unlink($zipCopy);
            if ($code2 === 0 && is_dir($dir . '/xl')) {
                return;
            }
        }
        throw new RuntimeException('Unable to unpack XLSX (enable php_zip, or install tar). Convert to CSV instead.');
    }

    private static function findBinary(array $names): ?string
    {
        foreach ($names as $name) {
            $where = PHP_OS_FAMILY === 'Windows'
                ? trim((string) shell_exec('where ' . escapeshellarg($name) . ' 2>NUL'))
                : trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
            if ($where !== '') {
                $line = preg_split('/\r\n|\n/', $where)[0] ?? '';
                if ($line !== '' && is_file($line)) {
                    return $line;
                }
                if ($line !== '') {
                    return $name;
                }
            }
        }
        return null;
    }

    private static function colIndex(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/', $ref, $m)) {
            return 0;
        }
        $col = 0;
        foreach (str_split($m[1]) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }
        return $col - 1;
    }
}
