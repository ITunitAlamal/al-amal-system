<?php

namespace App\Support\Spreadsheets;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Streams the rows of ONE named worksheet of an XLSX file with bounded memory (no third-party package).
 *
 * - Only the requested sheet is opened; other sheets are never read, listed or parsed.
 * - Cell values stay exactly as stored (numbers are never cast), so identity and phone numbers keep every digit.
 * - Dates: a numeric cell with a date number format also carries its ISO date, using the workbook's date system.
 */
final class StreamingXlsxReader
{
    private const MAX_ENTRIES = 5000;

    private const MAX_UNCOMPRESSED_BYTES = 600 * 1024 * 1024;

    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    private ?array $sharedStrings = null;

    private ?array $dateStyles = null;

    private bool $date1904 = false;

    private string $sheetPart = '';

    public function __construct(private readonly string $path, private readonly string $sheetName) {}

    /**
     * Validates the archive and locates the sheet with exactly this name (throws `sheet_missing` otherwise; the
     * names of other sheets are never returned). Loads shared strings and date styles.
     */
    public function open(): void
    {
        $zip = new ZipArchive;
        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('xlsx_unreadable');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('xlsx_structure');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (($stat['encryption_method'] ?? 0) !== 0) {
                    throw new RuntimeException('xlsx_encrypted');
                }
                $total += (int) ($stat['size'] ?? 0);
            }
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('xlsx_too_large');
            }
            $workbook = (string) $zip->getFromName('xl/workbook.xml');
            $relationId = null;
            $reader = self::xml($workbook);
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName === 'workbookPr') {
                    $flag = strtolower((string) $reader->getAttribute('date1904'));
                    $this->date1904 = $flag === '1' || $flag === 'true';
                }
                if ($reader->localName === 'sheet' && $reader->getAttribute('name') === $this->sheetName) {
                    $relationId = $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships') ?? $reader->getAttribute('r:id');
                }
            }
            if ($relationId === null) {
                throw new RuntimeException('sheet_missing');
            }
            $relations = self::xml((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
            while ($relations->read()) {
                if ($relations->nodeType === XMLReader::ELEMENT && $relations->localName === 'Relationship' && $relations->getAttribute('Id') === $relationId) {
                    $target = str_replace('\\', '/', (string) $relations->getAttribute('Target'));
                    $this->sheetPart = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                }
            }
            if ($this->sheetPart === '' || str_contains($this->sheetPart, '..') || $zip->statName($this->sheetPart) === false) {
                throw new RuntimeException('sheet_missing');
            }
            $this->sharedStrings = $this->readSharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: null);
            $this->dateStyles = $this->readDateStyles($zip->getFromName('xl/styles.xml') ?: null);
        } finally {
            $zip->close();
        }
    }

    public function date1904(): bool
    {
        return $this->date1904;
    }

    /**
     * Yields [row number => [column index (1 = A) => cell]] in sheet order. Empty rows are skipped.
     *
     * @return Generator<int, array<int, array{value: string, kind: string, date: ?string}>>
     */
    public function rows(int $after = 0): Generator
    {
        if ($this->sharedStrings === null) {
            $this->open();
        }
        $reader = new XMLReader;
        if (! $reader->open('zip://'.str_replace('\\', '/', $this->path).'#'.$this->sheetPart, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('sheet_unreadable');
        }
        try {
            $rowNumber = 0;
            $cells = [];
            $column = 0;
            // Resuming after row N: jump over whole <row> elements without reading their cells, then go on from
            // the first later row (the current node is handled before the next read()).
            $pending = false;
            if ($after > 0) {
                while ($reader->read() && ! ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row')) {
                }
                while ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                    $number = (int) ($reader->getAttribute('r') ?: $rowNumber + 1);
                    if ($number > $after) {
                        $pending = true;
                        break;
                    }
                    $rowNumber = $number;
                    if (! $reader->next('row')) {
                        break;
                    }
                }
            }
            while ($pending || $reader->read()) {
                $pending = false;
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row') {
                    if ($cells !== []) {
                        yield $rowNumber => $cells;
                    }
                    $cells = [];

                    continue;
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName === 'row') {
                    $rowNumber = (int) ($reader->getAttribute('r') ?: $rowNumber + 1);
                    $column = 0;
                    $cells = [];
                    if ($reader->isEmptyElement) {
                        continue;
                    }

                    continue;
                }
                if ($reader->localName !== 'c') {
                    continue;
                }
                $reference = (string) $reader->getAttribute('r');
                $column = $reference !== '' ? self::columnIndex($reference) : $column + 1;
                $cell = $this->cell($reader);
                if ($cell !== null) {
                    $cells[$column] = $cell;
                }
            }
        } finally {
            $reader->close();
        }
    }

    /** @return array{value: string, kind: string, date: ?string}|null */
    private function cell(XMLReader $reader): ?array
    {
        $type = (string) ($reader->getAttribute('t') ?: 'n');
        $isDateStyle = $this->dateStyles[(int) $reader->getAttribute('s')] ?? false;
        if ($reader->isEmptyElement) {
            return null;
        }
        $value = null;
        $depth = $reader->depth;
        while ($reader->read() && ! ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth)) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'v') {
                $value = $reader->readString();
            } elseif ($reader->localName === 't' && $type === 'inlineStr') {
                $value = ($value ?? '').$reader->readString();
            }
        }
        if ($value === null) {
            return null;
        }
        if ($type === 's') {
            $value = $this->sharedStrings[(int) $value] ?? '';
        }
        $kind = match ($type) {
            's', 'str', 'inlineStr' => 'string',
            'b' => 'bool',
            'e' => 'error',
            default => 'number',
        };

        return ['value' => $value, 'kind' => $kind, 'date' => $kind === 'number' && $isDateStyle && is_numeric($value) ? self::serialToDateTime((float) $value, $this->date1904) : null];
    }

    /** Excel serial (1900 or 1904 system) to 'Y-m-d' or 'Y-m-d H:i' when it carries a time. */
    public static function serialToDateTime(float $serial, bool $date1904 = false): ?string
    {
        if ($serial < 1 || $serial > 2958465) {
            return null;
        }
        $base = new \DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30');
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        $date = $base->modify('+'.$days.' days')->modify('+'.$seconds.' seconds');

        return $seconds > 0 ? $date->format('Y-m-d H:i') : $date->format('Y-m-d');
    }

    public static function columnLetter(int $index): string
    {
        $name = '';
        for (; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }

    private static function columnIndex(string $reference): int
    {
        $index = 0;
        foreach (str_split(strtoupper((string) preg_replace('/[^A-Za-z]/', '', $reference))) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }

    /** @return list<string> */
    private function readSharedStrings(?string $xml): array
    {
        if ($xml === null) {
            return [];
        }
        $strings = [];
        $reader = self::xml($xml);
        $current = null;
        $phonetic = 0;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->localName === 'si') {
                    if ($reader->isEmptyElement) {
                        $strings[] = '';
                    } else {
                        $current = '';
                    }
                } elseif ($reader->localName === 'rPh' && ! $reader->isEmptyElement) {
                    $phonetic++;
                } elseif ($reader->localName === 't' && $current !== null && $phonetic === 0) {
                    $current .= $reader->readString();
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($reader->localName === 'rPh') {
                    $phonetic = max(0, $phonetic - 1);
                } elseif ($reader->localName === 'si' && $current !== null) {
                    $strings[] = $current;
                    $current = null;
                }
            }
        }

        return $strings;
    }

    /** @return list<bool> style index => shows a date */
    private function readDateStyles(?string $xml): array
    {
        if ($xml === null) {
            return [];
        }
        $custom = [];
        $styles = [];
        $inCellXfs = false;
        $reader = self::xml($xml);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'cellXfs') {
                $inCellXfs = false;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'numFmt') {
                $custom[(int) $reader->getAttribute('numFmtId')] = (string) $reader->getAttribute('formatCode');
            } elseif ($reader->localName === 'cellXfs') {
                $inCellXfs = ! $reader->isEmptyElement;
            } elseif ($inCellXfs && $reader->localName === 'xf') {
                $id = (int) $reader->getAttribute('numFmtId');
                $styles[] = in_array($id, self::BUILTIN_DATE_FORMATS, true)
                    || (isset($custom[$id]) && preg_match('/[dmyhs]/i', str_ireplace('general', '', preg_replace(['/"[^"]*"/u', '/\\\\./u', '/\[[^\]]*\]/u', '/_./u', '/\*./u', '/[eE][+-]/u'], '', $custom[$id]) ?? '')) === 1);
            }
        }

        return $styles;
    }

    private static function xml(string $xml): XMLReader
    {
        $reader = XMLReader::XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        if (! $reader instanceof XMLReader) {
            throw new RuntimeException('xlsx_corrupt');
        }

        return $reader;
    }
}
