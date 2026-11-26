<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2a4268">
    <title>@yield('title') | معهد الأمل للأيتام</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/images/brand/icon-32.png">
    {{-- Self-contained styles: an error page must render even when the asset build is unavailable. --}}
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
            background: #f5f7fb; color: #1e2a3d; font-family: "Segoe UI", Tahoma, "Noto Sans Arabic", Arial, sans-serif; line-height: 1.8; }
        main { width: 100%; max-width: 520px; background: #fff; border: 1px solid #e3e8f0; border-radius: 20px; padding: 32px 28px;
            box-shadow: 0 10px 30px rgba(42, 66, 104, .08); text-align: center; }
        img { height: 72px; width: auto; margin: 0 auto 12px; display: block; }
        .code { display: inline-block; font-size: 13px; font-weight: 700; color: #8a6d1d; background: #fbf4e2; border-radius: 999px; padding: 2px 12px; }
        h1 { margin: 12px 0 6px; font-size: 22px; color: #2a4268; }
        p { margin: 0 0 8px; font-size: 15px; color: #475569; }
        .actions { margin-top: 22px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        a { display: inline-block; border-radius: 12px; padding: 9px 18px; font-size: 14px; font-weight: 700; text-decoration: none; }
        .primary { background: #2a4268; color: #fff; }
        .secondary { background: #fff; color: #2a4268; border: 1px solid #cbd5e1; }
    </style>
</head>
<body>
    <main role="main">
        <img src="/images/brand/logo.png" alt="شعار معهد الأمل للأيتام">
        <span class="code">@yield('code')</span>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        @hasSection('hint')
            <p>@yield('hint')</p>
        @endif
        <div class="actions">
            <a class="secondary" href="javascript:history.back()">العودة للصفحة السابقة</a>
            <a class="primary" href="/dashboard">الصفحة الرئيسية</a>
        </div>
    </main>
</body>
</html>
