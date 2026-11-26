<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>اتفاقية كفالة {{ $agreement->agreement_no }}</title>
    <style>
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; color: #0f172a; background: #fff; margin: 0; padding: 32px 16px; line-height: 1.9; }
        main { max-width: 760px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #0f172a; padding-bottom: 8px; margin-bottom: 24px; font-size: 14px; }
        .draft { border: 2px dashed #b45309; color: #92400e; padding: 8px 12px; margin-bottom: 16px; font-weight: 600; }
        .text { white-space: pre-wrap; font-size: 15px; }
        footer { margin-top: 32px; border-top: 1px solid #cbd5e1; padding-top: 8px; font-size: 11px; color: #475569; direction: ltr; text-align: left; }
        .no-print { margin-bottom: 16px; }
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<main>
    <p class="no-print"><button type="button" onclick="window.print()">طباعة</button></p>
    <header><strong>اتفاقية كفالة رقم <span dir="ltr">{{ $agreement->agreement_no }}</span></strong><span>@if ($agreement->signed_on) تاريخ التوقيع: <span dir="ltr">{{ $agreement->signed_on }}</span> @endif</span></header>
    @if ($draft)
        <p class="draft">مسودة للمراجعة — ليست النص الموقع. يُحفظ النص النهائي عند تسجيل التوقيع.</p>
    @endif
    <div class="text">{{ $text }}</div>
    @if ($agreement->rendered_hash)
        <footer>SHA-256: {{ $agreement->rendered_hash }}</footer>
    @endif
</main>
</body>
</html>
