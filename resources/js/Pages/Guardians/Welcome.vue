<script setup>
import { Link } from '@inertiajs/vue3';
import GuardianLayout from '../../Layouts/GuardianLayout.vue';
import PortalFooter from '../../Components/PortalFooter.vue';

// Public welcome page of portal.al-amal.ps: two clear actions, how it works, privacy and help. No figures, no people.
defineProps({ contact: { type: String, default: null } });
const steps = [
    ['أنشئ حسابك', 'بالاسم ورقم الهوية ورقم الجوال وكلمة مرور. لا يلزم أن يكون لك ملف سابق في المعهد.'],
    ['قدّم طلب تسجيل الأسرة', 'طلب واحد للأسرة، وفيه بطاقة لكل يتيم. يُحفظ تلقائيًا، ويمكنك إكماله على مراحل.'],
    ['تابع المراجعة', 'يراجع القسم حسابك ثم وصايتك على كل طفل على حدة، ويبلغك بما يلزم في «الإشعارات».'],
];
const faq = [
    ['هل إنشاء الحساب يعني قبول الطلب؟', 'لا. الحساب يتيح لك تقديم الطلب ومتابعته فقط. يُعتمد الحساب، وتُعتمد الوصاية على كل طفل، كلٌّ بقرار مستقل من القسم.'],
    ['متى أرى بيانات الأطفال الرسمية؟', 'بعد اعتماد حسابك واعتماد وصايتك على الطفل وما دامت الوصاية سارية. لا يكفي السكن في الأسرة نفسها.'],
    ['هل المستندات مطلوبة عند التقديم؟', 'لا، المرفقات اختيارية في البداية، ويمكن إضافتها لاحقًا أو عند طلب القسم.'],
    ['نسيت كلمة المرور، ماذا أفعل؟', 'إن كان رقم جوالك مؤكدًا تصلك رسالة برمز الاستعادة. وإلا راجع القسم لإصدار كلمة مرور مؤقتة.'],
    ['من يطّلع على بياناتي؟', 'موظفو القسم المخولون فقط، ولأغراض المراجعة وتقديم الخدمة. البيانات الصحية مقيدة بصلاحية خاصة.'],
];
</script>

<template>
    <GuardianLayout title="بوابة المستفيدين">
        <section class="overflow-hidden rounded-2xl bg-gradient-to-bl from-navy-800 via-navy-700 to-emerald-700 p-6 text-white sm:p-10">
            <p class="text-sm text-emerald-100">معهد الأمل للأيتام — غزة</p>
            <h1 class="mt-2 font-display text-2xl font-bold leading-snug sm:text-3xl">بوابة المستفيدين للأوصياء وأولياء الأمور</h1>
            <p class="mt-3 max-w-2xl text-sm leading-7 text-navy-100">سجّل أسرتك وأيتامك، وتابع طلباتك وقرارات القسم، واطّلع على المساعدات المعلنة لك، من جوالك وفي أي وقت.</p>
            <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                <Link href="/guardian/login" class="btn bg-white px-6 py-3 text-base font-bold text-navy-800 hover:bg-navy-50">تسجيل الدخول</Link>
                <Link href="/guardian/register" class="btn border border-white/60 px-6 py-3 text-base font-bold text-white hover:bg-white/10">إنشاء حساب</Link>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-3" aria-labelledby="how">
            <h2 id="how" class="sr-only">كيف تعمل البوابة</h2>
            <article v-for="([title, text], i) in steps" :key="title" class="card">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-50 font-bold text-emerald-800">{{ i + 1 }}</span>
                <h3 class="mt-2 font-semibold text-navy-800">{{ title }}</h3>
                <p class="mt-1 text-sm leading-7 text-slate-600">{{ text }}</p>
            </article>
        </section>

        <section class="card space-y-2" aria-labelledby="faq">
            <h2 id="faq" class="card-title">أسئلة شائعة</h2>
            <details v-for="[q, a] in faq" :key="q" class="rounded-lg border border-slate-200 px-4 py-3">
                <summary class="cursor-pointer font-semibold text-navy-800">{{ q }}</summary>
                <p class="mt-2 text-sm leading-7 text-slate-600">{{ a }}</p>
            </details>
        </section>

        <section class="card text-sm leading-7 text-slate-600">
            <h2 class="card-title">الخصوصية والتواصل</h2>
            <p>تُستخدم بياناتك لمراجعة طلبك وتقديم خدمات المعهد فقط، وتُحفظ المستندات بشكل خاص. البوابة لا تعتمد أي قرار تلقائيًا.</p>
            <p v-if="contact" class="mt-2 whitespace-pre-line">{{ contact }}</p>
            <p v-else class="mt-2">للاستفسار راجع قسم شؤون الأيتام والكفالات في مقر المعهد.</p>
        </section>
        <template #footer><PortalFooter /></template>
    </GuardianLayout>
</template>
