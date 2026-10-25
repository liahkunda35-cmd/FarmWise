<?php

class Pdf
{
    public static function table(string $title, array $headers, array $rows, string $subtitle = ''): string
    {
        $lines = [];
        $lines[] = ['style' => 'title', 'text' => $title];
        if ($subtitle !== '') {
            $lines[] = ['style' => 'muted', 'text' => $subtitle];
        }
        $lines[] = ['style' => 'spacer', 'text' => ''];
        $lines[] = ['style' => 'heading', 'text' => implode('  |  ', $headers)];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = trim(html_entity_decode(strip_tags((string) $cell), ENT_QUOTES, 'UTF-8'));
            }
            $lines[] = ['style' => 'body', 'text' => implode('  |  ', $cells)];
        }
        if (!$rows) {
            $lines[] = ['style' => 'muted', 'text' => 'No records for the selected filters.'];
        }
        return self::fromLines($title, $lines);
    }

    public static function fromLines(string $title, array $lines): string
    {
        $pages = [];
        $content = '';
        $y = 800;
        $flush = function () use (&$pages, &$content) {
            $pages[] = $content;
            $content = '';
        };
        $write = function (string $text, int $size, int $yPos) use (&$content) {
            $safe = self::escape($text);
            $content .= "BT /F1 {$size} Tf 48 {$yPos} Td ({$safe}) Tj ET\n";
        };
        foreach ($lines as $line) {
            $style = $line['style'] ?? 'body';
            $text = (string) ($line['text'] ?? '');
            $size = ['title' => 18, 'heading' => 11, 'muted' => 9, 'body' => 10, 'spacer' => 8][$style] ?? 10;
            $gap = $style === 'title' ? 28 : ($style === 'spacer' ? 12 : 16);
            if ($y < 60) {
                $flush();
                $y = 800;
            }
            if ($style !== 'spacer') {
                foreach (self::wrap($text, $style === 'title' ? 60 : 92) as $part) {
                    if ($y < 60) {
                        $flush();
                        $y = 800;
                    }
                    $write($part, $size, $y);
                    $y -= $gap;
                }
            } else {
                $y -= $gap;
            }
        }
        $flush();

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageIds = [];
        $fontId = 3;
        $objects[] = 'placeholder-pages';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $kids = [];
        foreach ($pages as $stream) {
            $stream .= "BT /F1 8 Tf 48 36 Td (" . self::escape(config('name') . ' · ' . $title) . ") Tj ET\n";
            $streamObj = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
            $objects[] = $streamObj;
            $contentId = count($objects);
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontId . " 0 R >> >> /Contents {$contentId} 0 R >>";
            $pageIds[] = count($objects);
        }
        $kidsRef = implode(' ', array_map(fn($id) => $id . ' 0 R', $pageIds));
        $objects[1] = '<< /Type /Pages /Count ' . count($pageIds) . ' /Kids [' . $kidsRef . '] >>';

        $out = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $count = count($objects) + 1;
        $out .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $out;
    }

    private static function wrap(string $text, int $width): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') {
            return [''];
        }
        $words = explode(' ', $text);
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $try = $current === '' ? $word : $current . ' ' . $word;
            if (strlen($try) > $width && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $try;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines ?: [''];
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
