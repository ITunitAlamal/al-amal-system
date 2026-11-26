<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2a4268">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>معهد الأمل للأيتام</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/images/brand/icon-32.png">
    <link rel="apple-touch-icon" href="/images/brand/icon-180.png">
    <link rel="preload" href="/fonts/ibm-plex-sans-arabic-arabic-400.woff2" as="font" type="font/woff2" crossorigin>
    @vite('resources/js/aims.js')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
