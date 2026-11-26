<?php

namespace App\Support\Attendance;

use RuntimeException;
use ZipArchive;

final class AttendanceLedgerXlsx
{
    private static function esc(mixed $s): string
    {
        return htmlspecialchars((string) ($s ?? ''), ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    public static function create(array $rows, string $from, string $to): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required for XLSX export.');
        }
        $path = tempnam(sys_get_temp_dir(), 'aims_ledger_');
        if ($path === false) {
            throw new RuntimeException('Cannot create temporary file.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('Cannot open xlsx archive.');
        }
        $headers = ['التاريخ', 'اسم الموظف', 'الرقم الوظيفي', 'الدوام المقرر', 'الدخول', 'الخروج', 'مدة الحضور (دقيقة)', 'تأخير محتسب (دقيقة)', 'خروج مبكر (دقيقة)', 'حالة الدوام', 'الالتزام بالوقت', 'ملاحظات التصحيح التلقائي'];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $widths = [15, 28, 17, 25, 13, 13, 22, 23, 23, 26, 26, 75];
        $xml .= '<cols>';
        foreach ($widths as $i => $w) {
            $xml .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"/>';
        } $xml .= '</cols><sheetData>';
        $xml .= '<row r="1" ht="32" customHeight="1">'.self::cell('A', 1, 'سجل دوام الموظفين — من '.$from.' إلى '.$to, 1).'</row>';
        $xml .= '<row r="2" ht="30" customHeight="1">';
        foreach ($headers as $i => $h) {
            $xml .= self::cell(self::col($i), 2, $h, 2);
        } $xml .= '</row>';
        foreach ($rows as $k => $r) {
            $n = $k + 3;
            $kind = $r['punctuality'] ?? 'neutral';
            $style = ['on_time' => 3, 'grace_used' => 4, 'late' => 5][$kind] ?? 6;
            $notes = [];
            foreach (($r['auto_correction_notes'] ?? []) as $c) {
                $dir = fn ($v) => $v === 'in' ? 'دخول' : ($v === 'out' ? 'خروج' : (string) $v);
                $notes[] = ($c['status'] === 'applied' ? 'فعّال' : 'ملغى').' | '.($c['at'] ?? '').' | '.$dir($c['original_direction'] ?? '').' ← '.$dir($c['corrected_direction'] ?? '').' | '.($c['note'] ?? '').(! empty($c['reversion_reason']) ? ' | سبب التراجع: '.$c['reversion_reason'] : '');
            }
            $values = [$r['work_date'] ?? '', $r['employee_name'] ?? '', $r['employee_number'] ?? '', trim(($r['expected_in'] ?? '—').' - '.($r['expected_out'] ?? '—')), $r['actual_in'] ?? '—', $r['actual_out'] ?? '—', $r['attendance_minutes'] ?? '', $r['late_minutes'] ?? '', $r['early_minutes'] ?? '', $r['status'] ?? '', $r['punctuality_label'] ?? 'غير محسوم', implode("\n", $notes)];
            $xml .= '<row r="'.$n.'" ht="28" customHeight="1">';
            foreach ($values as $i => $v) {
                $isNum = in_array($i, [6, 7, 8], true) && $v !== '' && is_numeric($v);
                $xml .= self::cell(self::col($i), $n, $v, $i === 10 ? $style : 7, $isNum);
            }
            $xml .= '</row>';
        }
        $last = count($rows) + 2;
        $xml .= '</sheetData><autoFilter ref="A2:L'.$last.'"/><mergeCells count="1"><mergeCell ref="A1:L1"/></mergeCells><pageMargins left="0.3" right="0.3" top="0.6" bottom="0.6" header="0.3" footer="0.3"/></worksheet>';
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="سجل الدوام" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => $xml,
        ];
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private static function col(int $idx): string
    {
        $s = '';
        for ($idx++; $idx > 0; $idx = intdiv($idx - 1, 26)) {
            $s = chr(65 + ($idx - 1) % 26).$s;
        }

return $s;
    }

    private static function cell(string $col, int $row, mixed $v, int $style, bool $numeric = false): string
    {
        $ref = $col.$row;

        return $numeric ? '<c r="'.$ref.'" s="'.$style.'"><v>'.(float) $v.'</v></c>' : '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.self::esc($v).'</t></is></c>';
    }

    private static function styles(): string
    {
        $cells = [];
        foreach ([3, 4, 5, 6, 0] as $fill) {
            $cells[] = '<xf numFmtId="0" fontId="0" fillId="'.$fill.'" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts>'
            .'<fills count="7"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF174275"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE2F4E9"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF0BB"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFCE2E2"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8EDF3"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD0D9E3"/></left><right style="thin"><color rgb="FFD0D9E3"/></right><top style="thin"><color rgb="FFD0D9E3"/></top><bottom style="thin"><color rgb="FFD0D9E3"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .implode('',$cells).'</cellXfs></styleSheet>';
    }
}
