<template>
    <!-- Same layout as a role board while it loads, so nothing jumps and no zero is shown as data. -->
    <div class="space-y-4" role="status" aria-live="polite">
        <span class="sr-only">جارٍ تحميل لوحة المؤشرات…</span>
        <div class="h-6 w-56 animate-pulse rounded bg-slate-200 motion-reduce:animate-none" aria-hidden="true"></div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" aria-hidden="true">
            <div class="h-32 animate-pulse rounded-2xl bg-white ring-1 ring-slate-200 motion-reduce:animate-none sm:col-span-2"></div>
            <div v-for="i in 3" :key="i" class="h-32 animate-pulse rounded-2xl bg-white ring-1 ring-slate-200 motion-reduce:animate-none"></div>
        </div>
        <div class="grid gap-3 lg:grid-cols-3" aria-hidden="true">
            <div class="h-64 animate-pulse rounded-2xl bg-white ring-1 ring-slate-200 motion-reduce:animate-none lg:col-span-2"></div>
            <div class="h-64 animate-pulse rounded-2xl bg-white ring-1 ring-slate-200 motion-reduce:animate-none"></div>
        </div>
    </div>
</template>
