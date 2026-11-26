<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} — {{ $generated }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm 10mm; }
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; color: #0f172a; background: #fff; margin: 0; padding: 24px 16px; font-size: 13px; }
        main { max-width: 1200px; margin: 0 auto; }
        header { border-bottom: 3px solid #174275; padding-bottom: 10px; margin-bottom: 14px; display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        h1 { margin: 0; font-size: 20px; color: #174275; }
        .org { font-size: 13px; color: #475569; margin-top: 4px; }
        .meta { font-size: 12px; color: #334155; line-height: 1.8; }
        .toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
        .toolbar button { font: inherit; padding: 6px 16px; border-radius: 8px; border: 1px solid #174275; background: #174275; color: #fff; cursor: pointer; }
        .toolbar p { margin: 0; font-size: 12px; color: #475569; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #174275; color: #fff; font-weight: 600; padding: 7px 8px; border: 1px solid #174275; text-align: right; }
        td { padding: 6px 8px; border: 1px solid #cbd5e1; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        td.num { direction: ltr; text-align: left; white-space: nowrap; font-variant-numeric: tabular-nums; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .totals { margin-top: 12px; padding: 10px 12px; border: 1px solid #f1d27a; background: #fff8e1; border-radius: 8px; font-weight: 600; line-height: 1.9; }
        .empty { padding: 24px; text-align: center; color: #64748b; border: 1px dashed #cbd5e1; border-radius: 8px; }
        footer { margin-top: 18px; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 6px; }
        @media print { .toolbar { display: none; } body { padding: 0; } th { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
<main>
    <div class="toolbar" role="region" aria-label="أدوات الطباعة">
        <button type="button" onclick="window.print()">{{ $pdf ? 'حفظ بصيغة PDF' : 'طباعة' }}</button>
        @if ($pdf)
            <p>في نافذة الطباعة اختر الوجهة «حفظ بتنسيق PDF» ثم احفظ الملف.</p>
        @else
            <p>يُطبع التقرير بالتصفية نفسها المعروضة على الشاشة.</p>
        @endif
    </div>
    <header>
        <div>
            <h1>{{ $title }}</h1>
            <div class="org">جمعية معهد الأمل للأيتام · قسم الحسابات · {{ $area }}</div>
        </div>
        <div class="meta">
            <div>تاريخ الإنشاء: <span dir="ltr">{{ $generated }}</span></div>
            <div>بواسطة: {{ $actor }}</div>
            <div>{{ $filters }}</div>
        </div>
    </header>
    @if (count($rows) === 0)
        <p class="empty">لا توجد سجلات مطابقة للتصفية المختارة.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr>@foreach ($columns as [$label]) <th scope="col">{{ $label }}</th> @endforeach</tr></thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach (array_values($row) as $index => $value)
                            <td @class(['num' => in_array($columns[$index][1], ['money', 'qty', 'date', 'datetime'], true)])>{{ $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <div class="totals">@foreach ($footer as $line)<div>{{ $line }}</div>@endforeach</div>
    <footer>نظام AIMS — مستند صادر من النظام، ويُسجَّل تصديره في سجل تدقيق الحسابات.</footer>
</main>
@if ($pdf)
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
@endif
</body>
</html>
