<?php

namespace App\Services\Orphans\Import;

use App\Support\Spreadsheets\StreamingXlsxReader;
use Generator;
use RuntimeException;
use ZipArchive;

/**
 * Streams rows of an uploaded XLSX (one named sheet, bounded memory, zip-bomb limits in StreamingXlsxReader) or CSV
 * (declared encoding). Values stay strings: identity and phone numbers keep their leading zeros; formulas are never
 * evaluated (a cell's stored value is read as text).
 */
final class SourceFile
{
    /** Sheet names from workbook.xml, without reading any sheet. @return list<string> */
    public static function sheets(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('ملف Excel غير صالح.');
        }
        try {
            $xml = $zip->getFromName('xl/workbook.xml');
            if ($xml === false || strlen($xml) > 5 * 1024 * 1024) {
                throw new RuntimeException('ملف Excel غير صالح.');
            }
            preg_match_all('/<sheet\b[^>]*\bname="([^"]+)"/u', $xml, $m);

            return array_map(fn ($n) => html_entity_decode($n, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
        } finally {
            $zip->close();
        }
    }

    /** @return Generator<int, array<int, string>> row number => [column index (1-based) => text] */
    public static function rows(string $path, string $format, ?string $sheet, ?string $encoding, int $after = 0): Generator
    {
        if ($format === 'xlsx') {
            // $after: rows up to this number are skipped inside the reader without parsing them (resuming a batch).
            foreach ((new StreamingXlsxReader($path, (string) $sheet))->rows($after) as $number => $cells) {
                yield $number => array_map(fn (array $c) => $c['date'] ?? $c['value'], $cells);
            }

            return;
        }
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('تعذر فتح الملف.');
        }
        try {
            $number = 0;
            $first = true;
            while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $number++;
                if ($line === [null]) {
                    continue;
                }
                $cells = [];
                foreach ($line as $i => $value) {
                    $value = (string) $value;
                    if ($first && $i === 0) {
                        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
                    }
                    if ($encoding && strtoupper($encoding) !== 'UTF-8') {
                        $value = (string) mb_convert_encoding($value, 'UTF-8', $encoding);
                    } elseif (! mb_check_encoding($value, 'UTF-8')) {
                        throw new RuntimeException('ترميز الملف ليس UTF-8؛ اختر الترميز الصحيح (مثل Windows-1256).');
                    }
                    if (trim($value) !== '') {
                        $cells[$i + 1] = $value;
                    }
                }
                $first = false;
                if ($cells !== []) {
                    yield $number => $cells;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
