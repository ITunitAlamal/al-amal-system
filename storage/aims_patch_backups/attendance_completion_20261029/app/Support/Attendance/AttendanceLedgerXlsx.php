<?php

namespace App\Support\Attendance;

use RuntimeException;
use ZipArchive;

/**
 * Writes the attendance ledger as a genuine Office Open XML workbook (one right-to-left sheet, inline strings).
 * Rows come from App\Services\Attendance\AttendanceLedger, the same source as the screens.
 */
final class AttendanceLedgerXlsx
{
    private const SHEET_NAME = 'سجل الدوام';

    /** Cell styles (indexes into cellXfs below). */
    private const TITLE = 1;

    private const HEADER = 2;

    private const CENTER = 3;

    private const TEXT = 4;

    private const NUMBER = 5;

    private const TONES = ['green' => 6, 'yellow' => 7, 'red' => 8, 'grey' => 9, 'blue' => 10, 'amber' => 11];

    private const PUNCTUALITY_TONES = ['on_time' => 'green', 'grace' => 'yellow', 'late' => 'red', 'unknown' => 'grey'];

    private const DAY_TONES = ['complete' => 'green', 'working' => 'blue', 'awaiting_exit' => 'blue', 'incomplete' => 'amber', 'absent' => 'red',
        'review' => 'amber', 'leave' => 'grey', 'holiday' => 'grey', 'rest_day' => 'grey', 'not_started' => 'grey'];

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return string Path of a temporary .xlsx file (the caller deletes it after sending).
     */
    public static function create(array $rows, string $title, bool $withDirections): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('zip_extension_missing');
        }
        $columns = [
            ['التاريخ', 12], ['اسم الموظف', 30], ['الرقم الوظيفي', 13], ['الدوام المقرر', 17], ['الدخول', 10], ['الخروج', 10],
            ['مدة الحضور (دقيقة)', 13], ['حالة الدوام', 24], ['الالتزام بالوقت', 15], ['التأخير الفعلي (دقيقة)', 13],
            ['استخدام السماح (دقيقة)', 13], ['التأخير المحتسب (دقيقة)', 13], ['الخروج المبكر (دقيقة)', 13], ['ملاحظات المراجعة', 45],
            ['ملاحظات التصحيح', 55],
        ];
        if ($withDirections) {
            $columns[] = ['الاتجاه الأصلي ← المصحح', 22];
        }
        $lastColumn = self::column(count($columns) - 1);
        $lastRow = count($rows) + 2;

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .'<dimension ref="A1:'.$lastColumn.$lastRow.'"/>'
            .'<sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/>'
            .'<selection pane="bottomLeft" activeCell="A3" sqref="A3"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/><cols>';
        foreach ($columns as $index => [, $width]) {
            $sheet .= '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"/>';
        }
        $sheet .= '</cols><sheetData>';
        $sheet .= '<row r="1" ht="30" customHeight="1">'.self::text('A1', $title, self::TITLE).'</row><row r="2" ht="36" customHeight="1">';
        foreach ($columns as $index => [$header]) {
            $sheet .= self::text(self::column($index).'2', $header, self::HEADER);
        }
        $sheet .= '</row>';

        foreach ($rows as $position => $row) {
            $number = $position + 3;
            $corrections = [];
            $directions = [];
            foreach ($row['corrections'] ?? [] as $correction) {
                $state = ['applied' => 'فعّال', 'reverted' => 'ملغى', 'pending' => 'بانتظار الاعتماد'][$correction['status']] ?? $correction['status'];
                $corrections[] = $state.' — '.$correction['at'].' — '.$correction['note'].(! empty($correction['reversion_reason']) ? ' — سبب التراجع: '.$correction['reversion_reason'] : '');
                if (! empty($correction['directions'])) {
                    $directions[] = $correction['at'].': '.$correction['directions'].($correction['status'] === 'reverted' ? ' (ملغى)' : '');
                }
            }
            $dayLabel = (string) ($row['day_label'] ?? '').(! empty($row['day_hint']) ? ' — '.$row['day_hint'] : '');
            $punctuality = (string) ($row['punctuality_label'] ?? 'غير محسوم').(! empty($row['punctuality_provisional']) ? ' ('.$row['punctuality_provisional'].')' : '');
            $cells = [
                [$row['work_date'] ?? '', self::CENTER], [$row['employee_name'] ?? '', self::TEXT], [$row['employee_number'] ?? '', self::CENTER],
                [($row['expected_in'] ?? null) ? $row['expected_in'].' - '.$row['expected_out'] : '—', self::CENTER],
                [$row['actual_in'] ?? '—', self::CENTER], [$row['actual_out'] ?? '—', self::CENTER],
                [$row['attendance_minutes'] ?? null, self::NUMBER],
                [$dayLabel, self::TONES[self::DAY_TONES[$row['day_state'] ?? ''] ?? 'grey']],
                [$punctuality, self::TONES[self::PUNCTUALITY_TONES[$row['punctuality'] ?? 'unknown'] ?? 'grey']],
                [$row['actual_late_minutes'] ?? null, self::NUMBER], [$row['grace_used_minutes'] ?? null, self::NUMBER],
                [$row['chargeable_late_minutes'] ?? null, self::NUMBER], [$row['early_minutes'] ?? null, self::NUMBER],
                [implode("\n", $row['notes'] ?? []), self::TEXT], [implode("\n", $corrections), self::TEXT],
            ];
            if ($withDirections) {
                $cells[] = [implode("\n", $directions), self::TEXT];
            }
            $sheet .= '<row r="'.$number.'">';
            foreach ($cells as $index => [$value, $style]) {
                $reference = self::column($index).$number;
                $sheet .= $style === self::NUMBER
                    ? ($value === null || $value === '' ? '<c r="'.$reference.'" s="'.self::NUMBER.'"/>' : '<c r="'.$reference.'" s="'.self::NUMBER.'"><v>'.(int) $value.'</v></c>')
                    : self::text($reference, (string) $value, $style);
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData><autoFilter ref="A2:'.$lastColumn.$lastRow.'"/><mergeCells count="1"><mergeCell ref="A1:'.$lastColumn.'1"/></mergeCells>'
            .'<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            .'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';

        $sheetName = self::escape(self::SHEET_NAME);
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
                .'</Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                .'<dc:title>'.self::escape($title).'</dc:title><dc:creator>AIMS</dc:creator>'
                .'<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>',
            'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>AIMS</Application></Properties>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<bookViews><workbookView/></bookViews><sheets><sheet name="'.$sheetName.'" sheetId="1" r:id="rId1"/></sheets>'
                .'<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''.$sheetName.'\'!$A$2:$'.$lastColumn.'$'.$lastRow.'</definedName></definedNames>'
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        $path = tempnam(sys_get_temp_dir(), 'aims_attendance_');
        if ($path === false) {
            throw new RuntimeException('temporary_file_unavailable');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('archive_open_failed');
        }
        foreach ($parts as $name => $content) {
            if (! $zip->addFromString($name, $content)) {
                $zip->close();
                @unlink($path);
                throw new RuntimeException('archive_write_failed');
            }
        }
        if (! $zip->close() || ! self::isComplete($path, array_keys($parts))) {
            @unlink($path);
            throw new RuntimeException('archive_incomplete');
        }

        return $path;
    }

    /** Re-opens the written package and checks every part is present and well-formed XML. */
    private static function isComplete(string $path, array $names): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            foreach ($names as $name) {
                $content = $zip->getFromName($name);
                if ($content === false) {
                    return false;
                }
                $previous = libxml_use_internal_errors(true);
                $document = new \DOMDocument;
                $valid = $document->loadXML($content, LIBXML_NONET) !== false;
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
                if (! $valid) {
                    return false;
                }
            }

            return true;
        } finally {
            $zip->close();
        }
    }

    private static function text(string $reference, string $value, int $style): string
    {
        if ($value === '') {
            return '<c r="'.$reference.'" s="'.$style.'"/>';
        }

        return '<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.self::escape(mb_substr($value, 0, 32000)).'</t></is></c>';
    }

    /** XML 1.0 text: invalid UTF-8 replaced, control characters removed, markup escaped. */
    private static function escape(string $value): string
    {
        $value = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    }

    private static function column(int $index): string
    {
        $name = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }

    private static function styles(): string
    {
        $border = '<left style="thin"><color rgb="FFB8C4D3"/></left><right style="thin"><color rgb="FFB8C4D3"/></right>'
            .'<top style="thin"><color rgb="FFB8C4D3"/></top><bottom style="thin"><color rgb="FFB8C4D3"/></bottom><diagonal/>';
        $fills = ['FF174275', 'FFDDF3E6', 'FFFFF1B8', 'FFFBDADA', 'FFE8EDF3', 'FFDCEAFB', 'FFFDE7C8'];
        $fillXml = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach ($fills as $color) {
            $fillXml .= '<fill><patternFill patternType="solid"><fgColor rgb="'.$color.'"/><bgColor indexed="64"/></patternFill></fill>';
        }
        $center = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
        $xfs = [
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>',
            '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>',
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'.$center.'</xf>',
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>',
            '<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>',
        ];
        foreach ([3, 4, 5, 6, 7, 8] as $fill) {
            $xfs[] = '<xf numFmtId="0" fontId="3" fillId="'.$fill.'" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="4"><font><sz val="11"/><name val="Arial"/><family val="2"/></font>'
            .'<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Arial"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF1F2937"/><name val="Arial"/><family val="2"/></font></fonts>'
            .'<fills count="'.(2 + count($fills)).'">'.$fillXml.'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>'.$border.'</border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.count($xfs).'">'.implode('', $xfs).'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
