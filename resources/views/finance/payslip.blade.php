<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>قسيمة راتب {{ $slip['period'] }} — {{ $slip['reference'] }}</title>
    <style>
        @page { size: A4 portrait; margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; color: #0f172a; background: #fff; margin: 0; padding: 24px 16px; font-size: 13px; }
        main { max-width: 760px; margin: 0 auto; }
        .toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
        .toolbar button { font: inherit; padding: 6px 16px; border-radius: 8px; border: 1px solid #174275; background: #174275; color: #fff; cursor: pointer; }
        .toolbar a { color: #174275; text-decoration: none; padding: 6px; }
        .toolbar p { margin: 0; font-size: 12px; color: #475569; }
        header { border-bottom: 3px solid #174275; padding-bottom: 10px; margin-bottom: 14px; display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        h1 { margin: 0; font-size: 20px; color: #174275; }
        .org { font-size: 13px; color: #475569; margin-top: 4px; }
        .meta { font-size: 12px; color: #334155; line-height: 1.8; }
        dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 18px; margin: 0 0 16px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 10px; }
        dt { font-size: 11px; color: #64748b; } dd { margin: 0 0 4px; font-weight: 600; }
        .cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        table { width: 100%; border-collapse: collapse; }
        caption { text-align: right; font-weight: 700; color: #174275; padding-bottom: 6px; }
        th { background: #174275; color: #fff; font-weight: 600; padding: 7px 8px; border: 1px solid #174275; text-align: right; }
        td { padding: 6px 8px; border: 1px solid #cbd5e1; }
        td.num, .net .num { direction: ltr; text-align: left; white-space: nowrap; font-variant-numeric: tabular-nums; }
        tfoot td { font-weight: 700; background: #f8fafc; }
        .net { margin-top: 16px; padding: 12px 14px; border: 1px solid #f1d27a; background: #fff8e1; border-radius: 10px; display: flex; justify-content: space-between; font-size: 16px; font-weight: 700; }
        footer { margin-top: 18px; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 6px; line-height: 1.7; }
        @media (max-width: 560px) { .cols, dl { grid-template-columns: 1fr; } }
        @media print { .toolbar { display: none; } body { padding: 0; } th { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
<main>
    <div class="toolbar" role="region" aria-label="أدوات الطباعة">
        <button type="button" onclick="window.print()">طباعة أو حفظ PDF</button>
        @if ($slip['own'])
            <a href="{{ route('payslips.create') }}">العودة إلى قسائم راتبي</a>
        @endif
        <p>لحفظ القسيمة PDF اختر الوجهة «حفظ بتنسيق PDF» في نافذة الطباعة.</p>
    </div>
    <header>
        <div>
            <h1>قسيمة راتب</h1>
            <div class="org">جمعية معهد الأمل للأيتام — غزة · قسم الحسابات</div>
        </div>
        <div class="meta">
            <div>المرجع: <span dir="ltr">{{ $slip['reference'] }}</span></div>
            <div>الشهر: <span dir="ltr">{{ $slip['period'] }}</span> · العملة: {{ $slip['currency'] }}</div>
            <div>نُشر المسير: <span dir="ltr">{{ substr($slip['publishedAt'], 0, 16) }}</span></div>
        </div>
    </header>

    <dl>
        <div><dt>اسم الموظف</dt><dd>{{ $slip['employee']['name'] }}</dd></div>
        <div><dt>الرقم الوظيفي</dt><dd dir="ltr" style="text-align:right">{{ $slip['employee']['number'] ?: '—' }}</dd></div>
        <div><dt>القسم</dt><dd>{{ $slip['employee']['department'] ?: '—' }}</dd></div>
        <div><dt>المسمى الوظيفي</dt><dd>{{ $slip['employee']['jobTitle'] ?: '—' }}</dd></div>
    </dl>

    <div class="cols">
        <table>
            <caption>الاستحقاقات</caption>
            <thead><tr><th scope="col">البند</th><th scope="col">المبلغ</th></tr></thead>
            <tbody>
            @foreach ($slip['earnings'] as $line)
                <tr><td>{{ $line['label'] }}</td><td class="num">{{ $line['amount'] }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><td>إجمالي الاستحقاقات</td><td class="num">{{ $slip['gross'] }}</td></tr></tfoot>
        </table>
        <table>
            <caption>الخصومات</caption>
            <thead><tr><th scope="col">البند</th><th scope="col">المبلغ</th></tr></thead>
            <tbody>
            @forelse ($slip['deductions'] as $line)
                <tr><td>{{ $line['label'] }}</td><td class="num">{{ $line['amount'] }}</td></tr>
            @empty
                <tr><td colspan="2">لا خصومات</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr><td>إجمالي الخصومات</td><td class="num">{{ $slip['totalDeductions'] }}</td></tr></tfoot>
        </table>
    </div>

    <div class="net"><span>صافي الراتب</span><span class="num">{{ $slip['net'] }} {{ $slip['currency'] }}</span></div>

    <footer>
        صدرت هذه القسيمة من مسير الرواتب المعتمد والمنشور ({{ $slip['source'] }}{{ $slip['sourceReference'] ? ' — مرجع '.$slip['sourceReference'] : '' }}).
        وقت الإصدار: <span dir="ltr">{{ $slip['issuedAt'] }}</span>. مستند شخصي سري؛ يُسجَّل كل اطلاع عليه في سجل النظام. لا تتضمن القسيمة بيانات حساب بنكي أو رقم هوية.
    </footer>
</main>
</body>
</html>
