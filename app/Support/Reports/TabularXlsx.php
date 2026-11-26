<?php

namespace App\Support\Reports;

use DOMDocument;
use RuntimeException;
use ZipArchive;

/**
 * A plain right-to-left Office Open XML workbook for a tabular report: title, a line of details (generation time,
 * user, filters), the header, the rows (numbers stay numeric) and closing total lines. No external library.
 */
final class TabularXlsx
{
    private const TITLE = 1;

    private const META = 2;

    private const HEADER = 3;

    private const TEXT = 4;

    private const NUMBER = 5;

    private const TOTAL = 6;

    /**
     * @param  list<array{0: string, 1: string}>  $columns  [Arabic label, type text|money|qty|date|...]
     * @param  list<list<?string>>  $rows  cell values in column order; money/qty cells are plain decimal strings
     * @param  list<string>  $footer  total lines written under the table
     * @return string Path of a temporary .xlsx file (the caller deletes it after sending).
     */
    public static function create(string $title, string $meta, array $columns, array $rows, array $footer, string $sheetName = 'التقرير'): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('zip_extension_missing');
        }
        $last = self::column(count($columns) - 1);
        $headerRow = 3;
        $lastDataRow = $headerRow + count($rows);
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .'<sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/><cols>';
        foreach ($columns as $index => [$label, $type]) {
            $width = in_array($type, ['money', 'qty', 'date'], true) ? 15 : max(14, min(45, mb_strlen($label) + 10));
            $sheet .= '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"/>';
        }
        $sheet .= '</cols><sheetData>'
            .'<row r="1" ht="28" customHeight="1">'.self::text('A1', $title, self::TITLE).'</row>'
            .'<row r="2">'.self::text('A2', $meta, self::META).'</row><row r="3" ht="24" customHeight="1">';
        foreach ($columns as $index => [$label]) {
            $sheet .= self::text(self::column($index).'3', $label, self::HEADER);
        }
        $sheet .= '</row>';
        foreach ($rows as $position => $cells) {
            $number = $headerRow + 1 + $position;
            $sheet .= '<row r="'.$number.'">';
            foreach ($columns as $index => [, $type]) {
                $reference = self::column($index).$number;
                $value = $cells[$index] ?? null;
                $numeric = in_array($type, ['money', 'qty'], true) && $value !== null && preg_match('/^-?\d+(\.\d+)?$/', $value);
                $sheet .= $numeric
                    ? '<c r="'.$reference.'" s="'.self::NUMBER.'"><v>'.$value.'</v></c>'
                    : self::text($reference, (string) ($value ?? ''), self::TEXT);
            }
            $sheet .= '</row>';
        }
        $row = $lastDataRow + 1;
        $merges = ['A1:'.$last.'1', 'A2:'.$last.'2'];
        foreach ($footer as $line) {
            $row++;
            $sheet .= '<row r="'.$row.'">'.self::text('A'.$row, $line, self::TOTAL).'</row>';
            $merges[] = 'A'.$row.':'.$last.$row;
        }
        $sheet .= '</sheetData>'
            .($rows !== [] ? '<autoFilter ref="A3:'.$last.$lastDataRow.'"/>' : '')
            .'<mergeCells count="'.count($merges).'">'.implode('', array_map(fn (string $ref): string => '<mergeCell ref="'.$ref.'"/>', $merges)).'</mergeCells>'
            .'<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            .'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';

        $name = self::escape(mb_substr($sheetName, 0, 31));
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                .'</Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                .'<dc:title>'.self::escape($title).'</dc:title><dc:creator>AIMS</dc:creator>'
                .'<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<bookViews><workbookView/></bookViews><sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets>'
                .($rows !== [] ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''.$name.'\'!$A$3:$'.$last.'$'.$lastDataRow.'</definedName></definedNames>' : '')
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        $path = tempnam(sys_get_temp_dir(), 'aims_report_');
        if ($path === false) {
            throw new RuntimeException('temporary_file_unavailable');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('archive_open_failed');
        }
        foreach ($parts as $part => $content) {
            if (! $zip->addFromString($part, $content)) {
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

    /** @param list<string> $names */
    private static function isComplete(string $path, array $names): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            foreach ($names as $name) {
                $content = $zip->getFromName($name);
                $previous = libxml_use_internal_errors(true);
                $valid = $content !== false && (new DOMDocument)->loadXML($content, LIBXML_NONET) !== false;
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
        $right = '<alignment horizontal="right" vertical="center" wrapText="1"/>';
        $center = '<alignment horizontal="center" vertical="center" wrapText="1"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.###"/></numFmts>'
            .'<fonts count="4"><font><sz val="11"/><name val="Arial"/><family val="2"/></font>'
            .'<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Arial"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF1F2937"/><name val="Arial"/><family val="2"/></font></fonts>'
            .'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF174275"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF1B8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>'.$border.'</border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="7">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1">'.$right.'</xf>'
            .'<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'.$right.'</xf>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>'
            .'<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'.$right.'</xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
