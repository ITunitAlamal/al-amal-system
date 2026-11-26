<?php

namespace App\Services\Hr\Import;

use Illuminate\Validation\ValidationException;
use XMLReader;
use ZipArchive;

/**
 * Reads the first worksheet of an XLSX file or a CSV file without third-party packages.
 *
 * Every cell keeps its text exactly as stored in the file: numbers are never cast, so identity, phone and
 * account numbers keep their digits. Each cell also reports whether Excel formats it as a date or a
 * percentage and whether its value is a cached formula result.
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 5000;

    public const MAX_COLUMNS = 80;

    private const MAX_UNCOMPRESSED_BYTES = 60 * 1024 * 1024;

    private const MAX_ENTRIES = 2000;

    /** Excel built-in number formats that show a date or a time. */
    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    private const BUILTIN_PERCENT_FORMATS = [9, 10];

    /**
     * @return array{sheet: string, rows: array<int, array<int, array{value: string, kind: string, format: string, formula: bool, date: ?string}>>}
     */
    public function read(string $path, string $extension): array
    {
        return match (strtolower($extension)) {
            'xlsx' => $this->readXlsx($path),
            'csv', 'txt' => $this->readCsv($path),
            default => throw ValidationException::withMessages(['file' => 'الملف يجب أن يكون XLSX أو CSV.']),
        };
    }

    /**
     * @return array{sheet: string, rows: array<int, array<int, array{value: string, kind: string, format: string, formula: bool, date: ?string}>>}
     */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['file' => 'تعذر فتح ملف Excel. تأكد أنه بصيغة XLSX وغير محمي بكلمة مرور.']);
        }

        try {
            $this->guardArchive($zip);

            $workbook = $this->xml($zip, 'xl/workbook.xml');
            if ($workbook === null) {
                throw ValidationException::withMessages(['file' => 'الملف لا يحتوي مصنف Excel صالحًا.']);
            }
            [$sheetName, $relationId, $date1904] = $this->firstSheet($workbook);
            $target = $this->sheetTarget($this->xml($zip, 'xl/_rels/workbook.xml.rels') ?? '', $relationId);
            $sheet = $this->xml($zip, $target);
            if ($sheet === null) {
                throw ValidationException::withMessages(['file' => 'تعذر قراءة الورقة الأولى في الملف.']);
            }

            return [
                'sheet' => $sheetName,
                'rows' => $this->sheetRows($sheet, $this->sharedStrings($this->xml($zip, 'xl/sharedStrings.xml')), $this->styleFormats($this->xml($zip, 'xl/styles.xml')), $date1904),
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Refuses encrypted archives and archives that would expand beyond the size limit (zip bombs).
     */
    private function guardArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw ValidationException::withMessages(['file' => 'بنية الملف غير مألوفة لملف Excel.']);
        }
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                continue;
            }
            if (($stat['encryption_method'] ?? 0) !== 0) {
                throw ValidationException::withMessages(['file' => 'الملف محمي بكلمة مرور. احفظه دون حماية ثم ارفعه.']);
            }
            $total += (int) $stat['size'];
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw ValidationException::withMessages(['file' => 'محتوى الملف أكبر من الحد المسموح للاستيراد.']);
            }
        }
    }

    private function xml(ZipArchive $zip, string $entry): ?string
    {
        $content = $zip->getFromName($entry);

        return $content === false ? null : $content;
    }

    /**
     * @return array{0: string, 1: string, 2: bool}
     */
    private function firstSheet(string $workbook): array
    {
        $reader = $this->reader($workbook);
        $date1904 = false;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'workbookPr') {
                $value = (string) $reader->getAttribute('date1904');
                $date1904 = $value === '1' || strtolower($value) === 'true';
            }
            if ($reader->localName === 'sheet') {
                $relationId = $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')
                    ?? $reader->getAttribute('r:id');

                return [(string) $reader->getAttribute('name'), (string) $relationId, $date1904];
            }
        }

        throw ValidationException::withMessages(['file' => 'لا توجد أوراق عمل في الملف.']);
    }

    private function sheetTarget(string $relations, string $relationId): string
    {
        $reader = $this->reader($relations);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Relationship' && $reader->getAttribute('Id') === $relationId) {
                $target = str_replace('\\', '/', (string) $reader->getAttribute('Target'));
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                if (str_contains($target, '..')) {
                    break;
                }

                return $target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(?string $xml): array
    {
        if ($xml === null) {
            return [];
        }
        $strings = [];
        $reader = $this->reader($xml);
        $current = null;
        $phonetic = 0;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                match ($reader->localName) {
                    'si' => $current = '',
                    'rPh' => $phonetic += $reader->isEmptyElement ? 0 : 1,
                    't' => $current !== null && $phonetic === 0 ? $current .= $reader->readString() : null,
                    default => null,
                };
                if ($reader->localName === 'si' && $reader->isEmptyElement) {
                    $strings[] = '';
                    $current = null;
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

    /**
     * The display category of each cell style: date, percent or general.
     *
     * @return list<string>
     */
    private function styleFormats(?string $xml): array
    {
        if ($xml === null) {
            return [];
        }
        $custom = [];
        $styles = [];
        $reader = $this->reader($xml);
        $inCellXfs = false;
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
                $styles[] = match (true) {
                    in_array($id, self::BUILTIN_DATE_FORMATS, true) => 'date',
                    in_array($id, self::BUILTIN_PERCENT_FORMATS, true) => 'percent',
                    isset($custom[$id]) => $this->customFormatCategory($custom[$id]),
                    default => 'general',
                };
            }
        }

        return $styles;
    }

    private function customFormatCategory(string $code): string
    {
        // Ignore quoted literals, escaped characters, bracketed colour/locale/condition sections, padding and
        // fill characters and the scientific exponent, so only real date tokens remain.
        $plain = preg_replace(['/"[^"]*"/u', '/\\\\./u', '/\[[^\]]*\]/u', '/_./u', '/\*./u', '/[eE][+-]/u'], '', $code) ?? '';
        if (str_contains($plain, '%')) {
            return 'percent';
        }

        return preg_match('/[dmyhs]/i', str_ireplace('general', '', $plain)) === 1 ? 'date' : 'general';
    }

    /**
     * @param  list<string>  $strings
     * @param  list<string>  $formats
     * @return array<int, array<int, array{value: string, kind: string, format: string, formula: bool, date: ?string}>>
     */
    private function sheetRows(string $xml, array $strings, array $formats, bool $date1904): array
    {
        $rows = [];
        $reader = $this->reader($xml);
        $rowNumber = 0;
        $column = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'row') {
                $rowNumber = (int) ($reader->getAttribute('r') ?: $rowNumber + 1);
                $column = 0;
                if ($rowNumber > self::MAX_ROWS + 20) {
                    throw ValidationException::withMessages(['file' => 'عدد الصفوف أكبر من الحد المسموح ('.self::MAX_ROWS.' صف). قسّم الملف.']);
                }

                continue;
            }
            if ($reader->localName !== 'c') {
                continue;
            }
            $reference = (string) $reader->getAttribute('r');
            $column = $reference !== '' ? $this->columnIndex($reference) : $column + 1;
            if ($column > self::MAX_COLUMNS) {
                continue;
            }
            $cell = $this->cell($reader, $strings, $formats, $date1904);
            if ($cell !== null) {
                $rows[$rowNumber][$column] = $cell;
            }
        }
        ksort($rows);

        return $rows;
    }

    /**
     * @param  list<string>  $strings
     * @param  list<string>  $formats
     * @return array{value: string, kind: string, format: string, formula: bool, date: ?string}|null
     */
    private function cell(XMLReader $reader, array $strings, array $formats, bool $date1904): ?array
    {
        $type = (string) ($reader->getAttribute('t') ?: 'n');
        $format = $formats[(int) $reader->getAttribute('s')] ?? 'general';
        if ($reader->isEmptyElement) {
            return null;
        }
        $value = null;
        $formula = false;
        $depth = $reader->depth;
        while ($reader->read() && ! ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth)) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'f') {
                $formula = true;
            } elseif ($reader->localName === 'v') {
                $value = $reader->readString();
            } elseif ($reader->localName === 't' && $type === 'inlineStr') {
                $value = ($value ?? '').$reader->readString();
            }
        }
        if ($value === null) {
            return null;
        }

        $kind = match ($type) {
            's' => 'string',
            'str', 'inlineStr' => 'string',
            'b' => 'bool',
            'e' => 'error',
            default => 'number',
        };
        if ($type === 's') {
            $value = $strings[(int) $value] ?? '';
        }
        $date = null;
        if ($kind === 'number' && $format === 'date' && is_numeric($value)) {
            $date = $this->serialToDate((float) $value, $date1904);
        }

        return ['value' => $value, 'kind' => $kind, 'format' => $format, 'formula' => $formula, 'date' => $date];
    }

    public function serialToDate(float $serial, bool $date1904 = false): ?string
    {
        if ($serial < 1 || $serial > 2958465) {
            return null;
        }
        $base = $date1904 ? new \DateTimeImmutable('1904-01-01') : new \DateTimeImmutable('1899-12-30');

        return $base->modify('+'.(int) floor($serial).' days')->format('Y-m-d');
    }

    private function columnIndex(string $reference): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $reference));
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }

    private function reader(string $xml): XMLReader
    {
        $reader = XMLReader::XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        if (! $reader instanceof XMLReader) {
            throw ValidationException::withMessages(['file' => 'محتوى الملف تالف.']);
        }

        return $reader;
    }

    /**
     * CSV cells are text. Excel's Arabic CSV export is often Windows-1256, so that encoding is converted.
     *
     * @return array{sheet: string, rows: array<int, array<int, array{value: string, kind: string, format: string, formula: bool, date: ?string}>>}
     */
    private function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path, false, null, 0, self::MAX_UNCOMPRESSED_BYTES + 1);
        if (strlen($content) > self::MAX_UNCOMPRESSED_BYTES) {
            throw ValidationException::withMessages(['file' => 'الملف أكبر من الحد المسموح للاستيراد.']);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (! mb_check_encoding($content, 'UTF-8')) {
            $converted = function_exists('iconv') ? @iconv('CP1256', 'UTF-8//IGNORE', $content) : false;
            if ($converted === false) {
                throw ValidationException::withMessages(['file' => 'ترميز الملف غير مدعوم. احفظه بصيغة CSV UTF-8.']);
            }
            $content = $converted;
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $candidate): int => substr_count($firstLine, $candidate))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        $rowNumber = 0;
        while (($values = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rowNumber++;
            if ($rowNumber > self::MAX_ROWS + 20) {
                fclose($handle);

                throw ValidationException::withMessages(['file' => 'عدد الصفوف أكبر من الحد المسموح ('.self::MAX_ROWS.' صف). قسّم الملف.']);
            }
            foreach (array_slice($values, 0, self::MAX_COLUMNS) as $index => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $rows[$rowNumber][$index + 1] = ['value' => (string) $value, 'kind' => 'string', 'format' => 'general', 'formula' => false, 'date' => null];
            }
        }
        fclose($handle);

        return ['sheet' => 'CSV', 'rows' => $rows];
    }
}
