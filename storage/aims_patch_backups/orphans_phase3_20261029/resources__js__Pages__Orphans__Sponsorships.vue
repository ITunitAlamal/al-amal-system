<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';

// Sponsors, sponsorships, one-off donations and assistance of the department. Money is recorded by Accounts only.
const props = defineProps({
    tab: { type: String, default: 'sponsorships' },
    filters: { type: Object, default: () => ({}) },
    sponsorships: { type: Array, default: () => [] },
    sponsors: { type: Array, default: () => [] },
    contributions: { type: Array, default: () => [] },
    assistance: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    options: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});
const current = ref(props.tab);
const tabs = computed(() => [['sponsorships', `الكفالات (${props.sponsorships.length})`], ['sponsors', `الكفلاء (${props.sponsors.length})`],
    ['contributions', `التبرعات لمرة واحدة (${props.contributions.length})`], ['assistance', `المساعدات (${props.assistance.length})`]]);
const tone = { draft: 'warning', pending_approval: 'info', active: 'success', paused: 'muted', ended: 'muted', rejected: 'danger', cancelled: 'muted', superseded: 'muted' };
const statusLabels = { draft: 'مسودة', pending_approval: 'بانتظار الاعتماد', active: 'نشطة', paused: 'موقوفة', ended: 'منتهية', rejected: 'مرفوضة', cancelled: 'ملغاة', superseded: 'مستبدلة' };

const search = ref(props.filters.q || '');
const status = ref(props.filters.status || '');
function filter() { router.get('/orphans/sponsorships', { q: search.value || undefined, status: status.value || undefined, tab: 'sponsorships' }, { preserveState: true, replace: true }); }

const creating = ref(false);
const form = useForm({ sponsor_id: null, beneficiary_type: 'child', beneficiary_reference: '', type_value_id: null, amount: '', currency: 'ILS', frequency_value_id: null,
    purpose_value_id: null, savings_amount: '', starts_on: '', ends_on: '', conditions: '', source: '' });
const purpose = computed(() => (props.options?.sponsorship_purposes || []).find(p => p.id === form.purpose_value_id)?.flags || {});
const split = computed(() => purpose.value.expenses && purpose.value.savings);
function create() { form.post('/orphans/sponsorships', { preserveScroll: true }); }

const sponsorForm = useForm({ id: null, kind: 'person', name: '', country: '', phone: '', email: '', address: '', preferences: '', confidential: false, status: 'active' });
const editingSponsor = ref(false);
function editSponsor(s = null) {
    editingSponsor.value = true;
    sponsorForm.clearErrors();
    Object.assign(sponsorForm, s ? { id: s.id, kind: s.kind, name: s.name, country: s.country || '', phone: s.phone || '', email: s.email || '', address: s.address || '',
        preferences: s.preferences || '', confidential: s.confidential, status: s.status } : { id: null, kind: 'person', name: '', country: '', phone: '', email: '', address: '', preferences: '', confidential: false, status: 'active' });
}
function saveSponsor() {
    const done = { preserveScroll: true, onSuccess: () => { editingSponsor.value = false; } };
    sponsorForm.id ? sponsorForm.put(`/orphans/sponsors/${sponsorForm.id}`, done) : sponsorForm.post('/orphans/sponsors', done);
}

const donation = useForm({ sponsor_id: null, donor_name: '', donor_contact: '', amount: '', currency: 'ILS', earmark_type: 'general', earmark_reference: '', notes: '' });
function pledge() { donation.post('/orphans/contributions', { preserveScroll: true, onSuccess: () => donation.reset() }); }
function cancelPledge(c) {
    const reason = prompt('سبب إلغاء التعهد:');
    if (reason) router.post(`/orphans/contributions/${c.id}/cancel`, { reason }, { preserveScroll: true });
}
const money = n => Number(n || 0).toLocaleString('ar-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <AppLayout title="الكفالات والتبرعات والمساعدات">
        <div class="space-y-4">
            <PageHeader eyebrow="قسم الكفالات وشؤون الأيتام" title="الكفالات والكفلاء والتبرعات" description="تُعدّ الكفالة في القسم، يوصي بها رئيسه ويعتمدها المدير التنفيذي. التحصيل والصرف والادخار تسجلها الحسابات من سنداتها؛ لا يُعد أي مبلغ مدفوعًا قبل ذلك.">
                <Link href="/orphans" class="btn btn-secondary btn-sm">ملفات الأيتام والأسر</Link>
            </PageHeader>

            <div class="grid gap-3 sm:grid-cols-5">
                <div class="card"><p class="text-xs text-slate-500">كفالات نشطة</p><p class="text-2xl font-bold">{{ counts.active }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">بانتظار الاعتماد</p><p class="text-2xl font-bold">{{ counts.pending }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">غير منتظمة (متأخرة)</p><p class="text-2xl font-bold">{{ counts.irregular }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">أطفال مكفولون</p><p class="text-2xl font-bold">{{ counts.sponsored_children }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">أطفال نشطون غير مكفولين</p><p class="text-2xl font-bold">{{ counts.unsponsored_children }}</p></div>
            </div>

            <div class="flex flex-wrap gap-2" role="tablist">
                <button v-for="[key, label] in tabs" :key="key" type="button" role="tab" :aria-selected="current === key" class="btn btn-sm" :class="current === key ? 'btn-primary' : 'btn-secondary'" @click="current = key">{{ label }}</button>
            </div>

            <template v-if="current === 'sponsorships'">
                <div class="flex flex-wrap items-center gap-2">
                    <form class="flex flex-1 flex-wrap gap-2" @submit.prevent="filter">
                        <input v-model="search" class="field max-w-xs" maxlength="40" placeholder="رقم الكفالة أو ملف الطفل أو الأسرة أو الكافل">
                        <select v-model="status" class="field max-w-[12rem]"><option value="">القائمة (دون الملغاة والمستبدلة)</option><option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option></select>
                        <button class="btn btn-secondary btn-sm">بحث</button>
                    </form>
                    <button v-if="can.manage" type="button" class="btn btn-primary btn-sm" @click="creating = !creating">كفالة جديدة</button>
                </div>

                <form v-if="creating && options" class="card space-y-3" data-error-scope @submit.prevent="create">
                    <h2 class="card-title">مسودة كفالة جديدة</h2>
                    <FormErrorSummary :errors="form.errors" />
                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                        <label class="block sm:col-span-2"><span class="field-label">الكافل *</span><select v-model="form.sponsor_id" class="field" required><option :value="null">اختر — أو سجّل كافلًا من تبويب الكفلاء</option><option v-for="s in options.sponsors" :key="s.id" :value="s.id">{{ s.label }}</option></select></label>
                        <label class="block"><span class="field-label">نوع الكفالة</span><select v-model="form.type_value_id" class="field"><option :value="null">—</option><option v-for="o in options.sponsorship_types" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <fieldset class="block"><legend class="field-label">المستفيد *</legend>
                            <label class="me-3"><input v-model="form.beneficiary_type" type="radio" value="child"> طفل</label><label><input v-model="form.beneficiary_type" type="radio" value="household"> أسرة</label></fieldset>
                        <label class="block sm:col-span-2"><span class="field-label">{{ form.beneficiary_type === 'child' ? 'رقم ملف الطفل (ملف نشط) *' : 'رقم ملف الأسرة (FAM-…) *' }}</span><input v-model="form.beneficiary_reference" dir="ltr" class="field" maxlength="30" required></label>
                        <label class="block"><span class="field-label">المبلغ لكل دورة *</span><input v-model="form.amount" inputmode="decimal" dir="ltr" class="field" required></label>
                        <label class="block"><span class="field-label">العملة *</span><select v-model="form.currency" class="field" required><option v-for="c in options.currencies" :key="c" :value="c">{{ c }}</option></select></label>
                        <label class="block"><span class="field-label">الدورية *</span><select v-model="form.frequency_value_id" class="field" required><option :value="null">اختر</option><option v-for="o in options.sponsorship_frequencies" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <label class="block"><span class="field-label">الغرض *</span><select v-model="form.purpose_value_id" class="field" required><option :value="null">اختر</option><option v-for="o in options.sponsorship_purposes" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <label v-if="split" class="block"><span class="field-label">منه للادخار *</span><input v-model="form.savings_amount" inputmode="decimal" dir="ltr" class="field" required></label>
                        <p v-if="split && form.amount" class="self-end text-xs text-slate-500">للمصاريف: {{ money(Number(form.amount) - Number(form.savings_amount || 0)) }}</p>
                        <label class="block"><span class="field-label">تاريخ البداية *</span><input v-model="form.starts_on" type="date" class="field" required></label>
                        <label class="block"><span class="field-label">تاريخ النهاية (اختياري)</span><input v-model="form.ends_on" type="date" class="field"></label>
                        <label class="block"><span class="field-label">المصدر/المرجع</span><input v-model="form.source" class="field" maxlength="200"></label>
                        <label class="block sm:col-span-3"><span class="field-label">الشروط</span><textarea v-model="form.conditions" class="field" rows="2" maxlength="2000"></textarea></label>
                    </div>
                    <button class="btn btn-primary btn-sm" :disabled="form.processing">حفظ المسودة</button>
                </form>

                <section class="card overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الكفالة</th><th>الكافل</th><th>المستفيد</th><th>المبلغ</th><th>الدورية</th><th>الحالة</th></tr></thead>
                        <tbody>
                            <tr v-for="s in sponsorships" :key="s.public_id" class="border-t border-slate-100">
                                <td class="py-2"><Link :href="`/orphans/sponsorships/${s.public_id}`" class="font-semibold text-blue-700 hover:underline" dir="ltr">{{ s.sponsorship_no }}</Link></td>
                                <td>{{ s.sponsor }}</td><td>{{ s.beneficiary }}</td><td>{{ money(s.amount) }} <span dir="ltr" class="text-xs">{{ s.currency }}</span></td><td>{{ s.frequency }}</td>
                                <td class="space-x-1"><StatusBadge :label="s.status_label" :tone="tone[s.status]" /><StatusBadge v-if="s.status === 'active' && s.regularity" :label="s.regularity" tone="muted" /></td>
                            </tr>
                            <tr v-if="!sponsorships.length"><td colspan="6" class="py-4 text-center text-slate-500">لا توجد كفالات مطابقة.</td></tr>
                        </tbody>
                    </table>
                </section>
            </template>

            <template v-else-if="current === 'sponsors'">
                <div v-if="can.manage"><button type="button" class="btn btn-primary btn-sm" @click="editSponsor()">كافل جديد</button></div>
                <form v-if="editingSponsor" class="card space-y-3" data-error-scope @submit.prevent="saveSponsor">
                    <h2 class="card-title">{{ sponsorForm.id ? 'تعديل بيانات الكافل' : 'كافل جديد' }}</h2>
                    <FormErrorSummary :errors="sponsorForm.errors" />
                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                        <label class="block"><span class="field-label">النوع *</span><select v-model="sponsorForm.kind" class="field"><option v-for="(label, key) in options.kinds" :key="key" :value="key">{{ label }}</option></select></label>
                        <label class="block sm:col-span-2"><span class="field-label">الاسم *</span><input v-model="sponsorForm.name" class="field" maxlength="200" required></label>
                        <label class="block"><span class="field-label">الدولة</span><input v-model="sponsorForm.country" class="field" maxlength="100"></label>
                        <label class="block"><span class="field-label">الهاتف</span><input v-model="sponsorForm.phone" dir="ltr" class="field" maxlength="20"></label>
                        <label class="block"><span class="field-label">البريد</span><input v-model="sponsorForm.email" type="email" dir="ltr" class="field" maxlength="150"></label>
                        <label class="block sm:col-span-3"><span class="field-label">العنوان</span><input v-model="sponsorForm.address" class="field" maxlength="500"></label>
                        <label class="block sm:col-span-3"><span class="field-label">التفضيلات المعتمدة</span><textarea v-model="sponsorForm.preferences" class="field" rows="2" maxlength="1000"></textarea></label>
                        <label class="flex items-center gap-2"><input v-model="sponsorForm.confidential" type="checkbox"> كافل سري (لا يظهر اسمه خارج القسم والحسابات والمدير التنفيذي)</label>
                        <label v-if="sponsorForm.id" class="block"><span class="field-label">الحالة</span><select v-model="sponsorForm.status" class="field"><option value="active">نشط</option><option value="inactive">غير نشط</option></select></label>
                    </div>
                    <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="sponsorForm.processing">حفظ</button><button type="button" class="btn btn-secondary btn-sm" @click="editingSponsor = false">إلغاء</button></div>
                </form>
                <section class="card overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الرقم</th><th>الاسم</th><th>النوع</th><th>الدولة</th><th>كفالات نشطة</th><th>الحالة</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="s in sponsors" :key="s.id" class="border-t border-slate-100">
                                <td class="py-2" dir="ltr">{{ s.donor_no }}</td><td>{{ s.name }}<span v-if="s.confidential" class="ms-1 text-xs text-amber-700">(سري)</span></td><td>{{ s.kind_label }}</td><td>{{ s.country || '—' }}</td>
                                <td>{{ s.active_count }}</td><td><StatusBadge :status="s.status" /></td>
                                <td><button v-if="can.manage" type="button" class="btn btn-secondary btn-sm" @click="editSponsor(s)">تعديل</button></td>
                            </tr>
                            <tr v-if="!sponsors.length"><td colspan="7" class="py-4 text-center text-slate-500">لا يوجد كفلاء مسجلون.</td></tr>
                        </tbody>
                    </table>
                </section>
            </template>

            <template v-else-if="current === 'contributions'">
                <form v-if="can.manage" class="card space-y-3" data-error-scope @submit.prevent="pledge">
                    <h2 class="card-title">تسجيل تبرع لمرة واحدة (تعهد)</h2>
                    <p class="text-xs text-slate-500">لا يُنشئ كفالة دورية ولا يضيف إلى ادخار أي طفل. يصبح «مقبوضًا» عندما تسجل الحسابات سند القبض.</p>
                    <FormErrorSummary :errors="donation.errors" />
                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                        <label class="block"><span class="field-label">كافل مسجل</span><select v-model="donation.sponsor_id" class="field"><option :value="null">— متبرع غير مسجل</option><option v-for="s in options.sponsors" :key="s.id" :value="s.id">{{ s.label }}</option></select></label>
                        <label v-if="!donation.sponsor_id" class="block"><span class="field-label">اسم المتبرع *</span><input v-model="donation.donor_name" class="field" maxlength="200" required></label>
                        <label v-if="!donation.sponsor_id" class="block"><span class="field-label">وسيلة التواصل</span><input v-model="donation.donor_contact" class="field" maxlength="200"></label>
                        <label class="block"><span class="field-label">المبلغ *</span><input v-model="donation.amount" inputmode="decimal" dir="ltr" class="field" required></label>
                        <label class="block"><span class="field-label">العملة *</span><select v-model="donation.currency" class="field"><option v-for="c in options.currencies" :key="c" :value="c">{{ c }}</option></select></label>
                        <label class="block"><span class="field-label">التخصيص *</span><select v-model="donation.earmark_type" class="field"><option v-for="(label, key) in options.earmarks" :key="key" :value="key">{{ label }}</option></select></label>
                        <label v-if="donation.earmark_type !== 'general'" class="block"><span class="field-label">رقم {{ donation.earmark_type === 'child' ? 'ملف الطفل' : donation.earmark_type === 'household' ? 'ملف الأسرة' : 'الحملة' }} *</span><input v-model="donation.earmark_reference" dir="ltr" class="field" maxlength="30" required></label>
                        <label class="block sm:col-span-3"><span class="field-label">ملاحظات</span><input v-model="donation.notes" class="field" maxlength="1000"></label>
                    </div>
                    <button class="btn btn-primary btn-sm" :disabled="donation.processing">تسجيل التعهد</button>
                </form>
                <section class="card overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">المرجع</th><th>المتبرع</th><th>المبلغ</th><th>التخصيص</th><th>الحالة</th><th>سند الحسابات</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="c in contributions" :key="c.id" class="border-t border-slate-100">
                                <td class="py-2" dir="ltr">{{ c.reference_no }}</td><td>{{ c.donor }}</td><td>{{ money(c.amount) }} <span dir="ltr" class="text-xs">{{ c.currency }}</span></td><td>{{ c.earmark }}</td>
                                <td><StatusBadge :label="c.status_label" :tone="c.status === 'collected' ? 'success' : c.status === 'pledged' ? 'info' : 'muted'" /></td>
                                <td dir="ltr">{{ c.accounting_reference || '—' }}</td>
                                <td><button v-if="c.can_cancel" type="button" class="btn btn-secondary btn-sm" @click="cancelPledge(c)">إلغاء</button></td>
                            </tr>
                            <tr v-if="!contributions.length"><td colspan="7" class="py-4 text-center text-slate-500">لا توجد تبرعات مسجلة.</td></tr>
                        </tbody>
                    </table>
                </section>
            </template>

            <section v-else class="card space-y-2 text-sm">
                <p class="text-xs text-slate-500">يُقدَّم طلب المساعدة من ملف الأسرة (تبويب «الكفالات والمساعدات») مع مستنداته.</p>
                <ul class="divide-y divide-slate-100">
                    <li v-for="a in assistance" :key="a.public_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><Link :href="`/orphans/assistance/${a.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ a.reference_no }}</Link> — أسرة {{ a.household }} — {{ a.type || 'مساعدة' }}<span v-if="a.estimated_value"> — {{ money(a.estimated_value) }} {{ a.currency }}</span></span>
                        <StatusBadge :label="a.status_label" :tone="a.status === 'delivered' ? 'success' : a.status === 'rejected' ? 'danger' : 'info'" />
                    </li>
                    <li v-if="!assistance.length" class="py-3 text-slate-500">لا توجد طلبات مساعدة.</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
