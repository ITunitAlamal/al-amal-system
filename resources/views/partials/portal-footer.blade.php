{{-- Shared public portal footer; keep PortalFooter.vue in sync. --}}
<footer class="relative mt-auto bg-navy-950 text-navy-100" role="contentinfo">
    <div class="h-0.5 bg-gradient-to-l from-blue-500 via-gold-400 to-navy-700" aria-hidden="true"></div>
    <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-6 px-6 py-6 text-center lg:flex-row lg:text-start">
        <div class="flex items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white p-1.5">
                <img src="/images/brand/icon-180.png" alt="" width="32" height="32" class="h-full w-full object-contain" aria-hidden="true">
            </span>
            <div class="text-start leading-6">
                <p class="font-display text-sm font-bold text-white">جمعية معهد الأمل للأيتام</p>
                <p class="text-xs text-navy-200">جميع الحقوق محفوظة © 2026</p>
            </div>
        </div>
        <p class="text-sm leading-7 text-navy-100">تطوير <strong class="font-semibold text-gold-300">قسم تكنولوجيا المعلومات</strong> - معهد الأمل <span dir="ltr">2026</span></p>
        @unless ($websiteInHeader ?? false)
        <a href="https://www.al-amal.ps" target="_blank" rel="noopener noreferrer" class="group flex items-center gap-3 rounded-xl border border-white/15 px-4 py-2.5 text-start transition hover:border-gold-300/60 hover:bg-white/5 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-gold-300" aria-label="الموقع الإلكتروني لمعهد الأمل — يفتح في نافذة جديدة">
            <svg class="h-6 w-6 shrink-0 text-gold-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18M5 7h14M5 17h14"/></svg>
            <span><span class="block text-xs text-navy-200">الموقع الإلكتروني للمعهد</span><span class="block text-sm font-medium tracking-wide text-white group-hover:text-gold-200" dir="ltr">www.al-amal.ps</span></span>
            <svg class="h-3.5 w-3.5 text-navy-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3h7v7M21 3l-9 9M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5"/></svg>
        </a>
        @endunless
    </div>
</footer>
