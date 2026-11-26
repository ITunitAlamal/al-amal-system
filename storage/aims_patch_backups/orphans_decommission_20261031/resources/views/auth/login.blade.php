<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#324f7c">
    <title>بوابة الموظفين | معهد الأمل للأيتام</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/images/brand/icon-32.png">
    <link rel="apple-touch-icon" href="/images/brand/icon-180.png">
    @vite(['resources/css/aims.css', 'resources/js/app.js'])
</head>
<body class="employee-login">
    <div class="employee-login-background" aria-hidden="true"></div>
    <header class="employee-login-header">
        <a href="{{ route('login') }}" class="employee-login-brand" aria-label="معهد الأمل للأيتام — بوابة الموظفين">
            <img src="/images/brand/logo-mark.png" alt="" width="54" height="54">
            <span><strong>جمعية معهد الأمل للأيتام</strong><span>بوابة الموظفين · نظام الإدارة المؤسسية</span></span>
        </a>
        <div class="employee-login-header-actions">
            <span class="employee-login-since"><span aria-hidden="true"></span>رسالة أمل منذ 1949</span>
            <a href="https://www.al-amal.ps" target="_blank" rel="noopener noreferrer" class="employee-login-website" aria-label="الموقع الإلكتروني لمعهد الأمل — يفتح في نافذة جديدة">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18M5 7h14M5 17h14"/></svg>
                <span><strong>الموقع الإلكتروني للمعهد</strong><span dir="ltr">www.al-amal.ps</span></span>
            </a>
        </div>
    </header>

    <main class="employee-login-main">
        <div class="employee-login-panel">
            <aside class="employee-login-story" aria-label="معهد الأمل للأيتام">
                <div class="employee-login-sun" aria-hidden="true"></div>
                <div class="employee-login-story-content">
                    <div class="employee-login-logo">
                        <img src="/images/brand/logo.png" alt="شعار معهد الأمل للأيتام" width="827" height="768" fetchpriority="high" decoding="async">
                    </div>
                    <span class="employee-login-gold-line" aria-hidden="true"></span>
                    <h2>رسالتنا الأمل.<br><span>وبجهودكم يستمر العطاء.</span></h2>
                    <p>مساحة عمل تجمعنا، وتسهّل إنجاز مهامنا اليومية<br class="hidden sm:block"> في خدمة رسالة المعهد.</p>
                    <div class="employee-login-story-tags" aria-label="خدمات البوابة">
                        <span>الطلبات والمراسلات</span><span>المتابعة والإنجاز</span>
                    </div>
                </div>
                <p class="employee-login-story-signature" lang="en" dir="ltr">AL-AMAL INSTITUTE FOR ORPHANS</p>
            </aside>

            <section class="employee-login-form-section" aria-labelledby="login-title">
                <div class="employee-login-form-content">
                    <div class="employee-login-welcome">
                        <span class="employee-login-access-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>
                        </span>
                        <span>أهلًا بك في بوابة الموظفين</span>
                    </div>
                    <h1 id="login-title">تسجيل الدخول</h1>
                    <p class="employee-login-intro">خطوتك الأولى ليوم عمل مثمر.<br>أدخل بياناتك للوصول إلى حسابك المؤسسي.</p>

                    <form method="POST" action="{{ route('login.submit') }}" class="employee-login-form" novalidate>
                        @csrf
                        <div>
                            <label for="employee_number" class="field-label">الرقم الوظيفي</label>
                            <div class="employee-login-input-wrap">
                                <svg class="employee-login-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="15" rx="3"/><path d="M8 3v4M16 3v4M15 12h3M15 16h3"/><circle cx="8.5" cy="12" r="2"/><path d="M5.5 17a3 3 0 0 1 6 0"/></svg>
                                <input type="text" id="employee_number" name="employee_number" value="{{ old('employee_number') }}" class="field employee-login-input" dir="ltr" inputmode="text" placeholder="أدخل الرقم الوظيفي" required autofocus autocomplete="username"
                                    @error('employee_number') aria-invalid="true" aria-describedby="employee_number_error" @enderror>
                            </div>
                            @error('employee_number')
                                <p id="employee_number_error" class="field-error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="password" class="field-label">كلمة المرور</label>
                            <div class="employee-login-input-wrap">
                                <svg class="employee-login-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>
                                <input type="password" id="password" name="password" class="field employee-login-input" dir="ltr" placeholder="أدخل كلمة المرور" required autocomplete="current-password"
                                    @error('password') aria-invalid="true" aria-describedby="password_error" @enderror>
                            </div>
                            @error('password')
                                <p id="password_error" class="field-error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="employee-login-submit">
                            <span>الدخول إلى حسابي</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6"/></svg>
                        </button>
                    </form>

                    @if (config('services.hotsms.enabled'))
                        <p class="employee-login-activation">وصلك رمز تفعيل حسابك؟ <a href="{{ route('employee.activation.show') }}">فعّل حسابك الآن</a></p>
                    @endif

                    <div class="employee-login-divider"><span>خدمات أخرى</span></div>
                    <a href="{{ route('guardian.register') }}" class="employee-login-guardian">
                        <span class="employee-login-guardian-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M17 15a5 5 0 0 1 4 4v2"/></svg></span>
                        <span><strong>بوابة الأوصياء</strong><small>تسجيل الأيتام ومتابعة الطلبات</small></span>
                        <span class="employee-login-guardian-arrow" aria-hidden="true">←</span>
                    </a>
                </div>
            </section>
        </div>
    </main>

    @include('partials.portal-footer', ['websiteInHeader' => true])
</body>
</html>
