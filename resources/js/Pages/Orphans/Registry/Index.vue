<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import ActiveFilters from '../../../Components/ActiveFilters.vue';
import AppIcon from '../../../Components/AppIcon.vue';
import ImportIcon from '../../../Components/Registry/ImportIcon.vue';
import PreviewPanel from '../../../Components/Registry/PreviewPanel.vue';
import Pagination from '../../../Components/Pagination.vue';
import { fileTones, idFormatTones, linkTones } from '../../../Support/orphans.js';

const props = defineProps({ view: String, filters: Object, rows: Object, options: Object, counts: Object, abilities: Object, importUrl: { type: String, default: null } });
const blank = () => ({ q: '', first_name: '', father_name: '', grandfather_name: '', family_name: '', governorate: '', sex: '', age_band: '', class: '', status: '', completeness: '',
    guardianship: '', displaced: '', education: '', issue: '', linked: '', portal: '', sponsorship: '', benefit: '', assigned: '', sort: 'recent' });
const form = ref({ ...blank(), ...props.filters });
watch(() => props.filters, value => { form.value = { ...blank(), ...value }; if (['cards', 'table'].includes(value.layout)) layout.value = value.layout; });
const layout = ref(props.filters.layout === 'cards' ? 'cards' : 'table');
const page = usePage();
const layoutKey = () => `aims.registry.layout.${page.props.auth?.user?.id}`;
onMounted(() => {
    if (props.filters.layout) return;
    try { const saved = localStorage.getItem(layoutKey()); if (['cards', 'table'].includes(saved)) layout.value = saved; } catch { /* Optional preference only. */ }
});
const advanced = ref(['first_name', 'father_name', 'grandfather_name', 'family_name'].some(k => props.filters[k]));
const moreFilters = ref(['governorate', 'sex', 'age_band', 'class', 'status', 'completeness', 'guardianship', 'displaced', 'education', 'issue', 'linked', 'portal', 'sponsorship', 'benefit'].some(k => props.filters[k]));
const preview = ref(null);
const busy = ref(false);
const addOpen = ref(false);
const orphans = computed(() => props.view === 'orphans');

function go(extra = {}) {
    if (busy.value) return;
    busy.value = true;
    const query = Object.fromEntries(Object.entries({ ...form.value, view: props.view, layout: layout.value, ...extra }).filter(([, v]) => v !== '' && v != null && !(v === 'recent')));
    router.get('/orphans/registry', query, { preserveState: true, preserveScroll: true, onFinish: () => { busy.value = false; } });
}
function switchView(view) { form.value = { ...blank(), q: form.value.q }; go({ view }); }
function setLayout(value) {
    layout.value = value;
    try { localStorage.setItem(layoutKey(), value); } catch { /* Optional preference only. */ }
    go({ page: props.rows.current_page });
}
const labels = computed(() => ({
    sex: { male: 'ذكر', female: 'أنثى', unknown: 'الجنس غير محدد' }, completeness: { complete: 'ملف مكتمل', incomplete: 'ملف غير مكتمل' },
    guardianship: { approved: 'وصاية معتمدة', unverified: 'بلا وصاية معتمدة' }, displaced: { yes: 'نازح حاليًا', no: 'غير نازح' },
    linked: { none: 'بلا أيتام مرتبطين', pending: 'لديه ارتباط بانتظار التحقق', approved: 'لديه وصاية معتمدة' }, portal: { yes: 'له حساب بوابة', no: 'بلا حساب بوابة' }, sponsorship: { yes: 'مكفول حاليًا', no: 'غير مكفول' },
    benefit: { none: 'لم يستفد بعد', 30: 'استفاد خلال 30 يومًا', 90: 'استفاد خلال 90 يومًا', 365: 'استفاد خلال سنة' },
}));
const find = (list, id) => (list || []).find(x => String(x.id) === String(id))?.name || (id === 'unknown' ? 'غير محدد' : id);
const active = computed(() => Object.entries(props.filters).filter(([k]) => !['view', 'layout', 'sort'].includes(k)).map(([key, value]) => {
    const names = { q: `البحث: ${value}`, first_name: `الاسم الأول: ${value}`, father_name: `اسم الأب: ${value}`, grandfather_name: `اسم الجد: ${value}`, family_name: `العائلة: ${value}`,
        governorate: `المحافظة: ${(props.options.governorates || []).find(g => String(g.id) === String(value))?.name_ar || (value === 'unknown' ? 'غير محددة' : value)}`,
        age_band: `الفئة العمرية: ${find(props.options.age_bands, value)}`, class: `الفئة: ${find(props.options.classes, value)}`, education: `التعليم: ${find(props.options.education, value)}`,
        status: `الحالة: ${props.options.statuses?.[value] || value}`, assigned: value === 'none' ? 'غير مسند لموظف' : 'مسند لموظف محدد', issue: props.options.issues?.[value] || value };
    return { key, label: names[key] || labels.value[key]?.[value] || `${key}: ${value}` };
}));
function removeFilter(key) { form.value[key] = ''; go(); }
function clearFilters() { form.value = blank(); go(); }
function open(row) { preview.value = { type: orphans.value ? 'orphan' : 'guardian', public_id: row.public_id, name: row.full_name }; }
const fileUrl = row => orphans.value ? `/orphans/registry/orphans/${row.public_id}` : `/orphans/registry/guardians/${row.public_id}`;
</script>

<template>
    <AppLayout title="إدارة الأيتام والأوصياء">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader eyebrow="شؤون الأيتام والكفالات" title="إدارة الأيتام والأوصياء" description="سجل موحد للأيتام والأوصياء. الارتباط بالوصي شرط لكل ملف يتيم، والاعتماد يتم لكل طفل على حدة.">
                <div v-if="abilities.edit" class="relative">
                    <button type="button" class="btn btn-primary" :aria-expanded="addOpen" @click="addOpen = !addOpen"><AppIcon name="plus" /> إضافة جديد <AppIcon name="chevron-down" :size="14" /></button>
                    <div v-if="addOpen" class="absolute end-0 z-20 mt-1 w-56 rounded-xl border border-slate-200 bg-white p-1 shadow-lg" @click="addOpen = false">
                        <Link href="/orphans/registry/guardians/create" class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-50">إضافة وصي</Link>
                        <Link href="/orphans/registry/orphans/create" class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-50">إضافة يتيم (مع وصيه)</Link>
                        <Link href="/orphans/registrations" class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-50">تسجيل أسرة بعدة أيتام</Link>
                    </div>
                </div>
                <Link v-if="importUrl && abilities.import" :href="importUrl" class="btn btn-secondary" title="استيراد بيانات الأيتام والأوصياء والكشوفات مع التحقق المسبق واكتشاف الأخطاء والتكرار">
                    <ImportIcon /> الاستيراد الذكي
                </Link>
            </PageHeader>

            <nav class="flex gap-2" aria-label="عرض السجل">
                <button type="button" :class="['btn', orphans ? 'btn-primary' : 'btn-secondary']" :aria-pressed="orphans" @click="switchView('orphans')">الأيتام <span class="tabular-nums">({{ counts.orphans }})</span></button>
                <button type="button" :class="['btn', !orphans ? 'btn-primary' : 'btn-secondary']" :aria-pressed="!orphans" @click="switchView('guardians')">الأوصياء <span class="tabular-nums">({{ counts.guardians }})</span></button>
            </nav>

            <section class="card p-0" :aria-busy="busy">
                <form class="space-y-3 border-b border-slate-200 p-4" @submit.prevent="go()">
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="min-w-0 flex-1 text-sm font-semibold text-slate-700">بحث سريع
                            <input v-model="form.q" type="search" class="field mt-1.5 font-normal" autocomplete="off" :placeholder="orphans ? 'الاسم أو جزء منه، رقم الملف ORP-…، أو رقم هوية اليتيم كاملًا' : 'الاسم أو جزء منه، رقم الوصي GRD-…، رقم هوية الوصي كاملًا أو الجوال'">
                        </label>
                        <button type="submit" class="btn btn-primary" :disabled="busy">{{ busy ? 'جارٍ البحث…' : 'بحث' }}</button>
                        <button type="button" class="btn btn-secondary" :aria-expanded="advanced" @click="advanced = !advanced">الاسم الرباعي</button>
                        <button type="button" class="btn btn-secondary" :aria-expanded="moreFilters" @click="moreFilters = !moreFilters">فلاتر إضافية</button>
                    </div>
                    <div v-if="advanced" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="text-sm">الاسم الأول<input v-model="form.first_name" class="field mt-1"></label>
                        <label class="text-sm">اسم الأب<input v-model="form.father_name" class="field mt-1"></label>
                        <label class="text-sm">اسم الجد<input v-model="form.grandfather_name" class="field mt-1"></label>
                        <label class="text-sm">اسم العائلة<input v-model="form.family_name" class="field mt-1"></label>
                        <p class="text-xs text-slate-500 sm:col-span-2 lg:col-span-4">كل جزء يُطابق كما هو بعد توحيد الهمزات والتاء المربوطة؛ الجزء غير المسجل لا يُخمَّن.</p>
                    </div>
                    <div v-if="moreFilters" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="text-sm">المحافظة<select v-model="form.governorate" class="field mt-1"><option value="">الكل</option><option v-for="g in options.governorates" :key="g.id" :value="g.id">{{ g.name_ar }}</option><option value="unknown">غير محددة</option></select></label>
                        <template v-if="orphans">
                            <label class="text-sm">الفئة العمرية<select v-model="form.age_band" class="field mt-1"><option value="">الكل</option><option v-for="b in options.age_bands" :key="b.id" :value="b.id">{{ b.name }}</option><option value="unknown">تاريخ الميلاد غير متوفر</option></select></label>
                            <label class="text-sm">الجنس<select v-model="form.sex" class="field mt-1"><option value="">الكل</option><option value="male">ذكر</option><option value="female">أنثى</option><option value="unknown">غير محدد</option></select></label>
                            <label class="text-sm">فئة اليتم<select v-model="form.class" class="field mt-1"><option value="">الكل</option><option v-for="c in options.classes" :key="c.id" :value="c.id">{{ c.name }}</option><option value="unknown">غير مصنف</option></select></label>
                            <label class="text-sm">حالة الملف<select v-model="form.status" class="field mt-1"><option value="">القائمة (عدا المنتهية والمؤرشفة)</option><option v-for="(label, key) in options.statuses" :key="key" :value="key">{{ label }}</option></select></label>
                            <label class="text-sm">الاكتمال<select v-model="form.completeness" class="field mt-1"><option value="">الكل</option><option value="complete">مكتمل</option><option value="incomplete">غير مكتمل</option></select></label>
                            <label class="text-sm">الوصاية<select v-model="form.guardianship" class="field mt-1"><option value="">الكل</option><option value="approved">معتمدة</option><option value="unverified">غير معتمدة بعد</option></select></label>
                            <label class="text-sm">النزوح<select v-model="form.displaced" class="field mt-1"><option value="">الكل</option><option value="yes">نازح حاليًا</option><option value="no">غير نازح</option></select></label>
                            <label class="text-sm">الكفالة<select v-model="form.sponsorship" class="field mt-1"><option value="">الكل</option><option value="yes">مكفول حاليًا</option><option value="no">غير مكفول</option></select></label>
                            <label class="text-sm">آخر استفادة مؤكدة<select v-model="form.benefit" class="field mt-1"><option value="">الكل</option><option value="30">خلال 30 يومًا</option><option value="90">خلال 90 يومًا</option><option value="365">خلال سنة</option><option value="none">لم يستفد بعد</option></select></label>
                            <label class="text-sm">الحالة التعليمية<select v-model="form.education" class="field mt-1"><option value="">الكل</option><option v-for="e in options.education" :key="e.id" :value="e.id">{{ e.name }}</option><option value="unknown">غير مسجلة</option></select></label>
                        </template>
                        <template v-else>
                            <label class="text-sm">الارتباطات<select v-model="form.linked" class="field mt-1"><option value="">الكل</option><option value="approved">لديه وصاية معتمدة</option><option value="pending">لديه ارتباط بانتظار التحقق</option><option value="none">بلا أيتام مرتبطين</option></select></label>
                            <label class="text-sm">حساب البوابة<select v-model="form.portal" class="field mt-1"><option value="">الكل</option><option value="yes">مرتبط</option><option value="no">غير مرتبط</option></select></label>
                        </template>
                        <label class="text-sm">ملاحظات البيانات<select v-model="form.issue" class="field mt-1"><option value="">الكل</option><option v-for="(label, key) in options.issues" :key="key" :value="key">{{ label }}</option></select></label>
                        <label class="text-sm">الترتيب<select v-model="form.sort" class="field mt-1"><option value="recent">الأحدث</option><option value="name">الاسم</option><option value="reference">الرقم المرجعي</option><option v-if="orphans" value="age">الأصغر سنًا</option></select></label>
                    </div>
                </form>
                <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <ActiveFilters :items="active" :count="rows.total" @remove="removeFilter" @clear="clearFilters" />
                    <div class="flex gap-1" role="group" aria-label="طريقة العرض">
                        <button type="button" :class="['btn btn-sm', layout === 'table' ? 'btn-primary' : 'btn-secondary']" :aria-pressed="layout === 'table'" @click="setLayout('table')">جدول</button>
                        <button type="button" :class="['btn btn-sm', layout === 'cards' ? 'btn-primary' : 'btn-secondary']" :aria-pressed="layout === 'cards'" @click="setLayout('cards')">بطاقات</button>
                    </div>
                </div>

                <div class="px-4 pb-4">
                    <EmptyState v-if="!rows.data.length" :title="Object.keys(filters).length > 1 ? 'لا توجد نتائج مطابقة' : (orphans ? 'لا توجد ملفات أيتام بعد' : 'لا يوجد أوصياء مسجلون بعد')"
                        :description="orphans ? 'ملف اليتيم يُنشأ مع وصيه في خطوة واحدة.' : 'يمكن تسجيل الوصي قبل ربط أي يتيم به.'">
                        <Link v-if="abilities.edit" :href="orphans ? '/orphans/registry/orphans/create' : '/orphans/registry/guardians/create'" class="btn btn-primary">{{ orphans ? 'إضافة يتيم' : 'إضافة وصي' }}</Link>
                    </EmptyState>
                    <div v-else-if="layout === 'table'" class="overflow-x-auto">
                        <table class="aims-responsive-table data-table min-w-[760px]">
                            <thead><tr>
                                <th scope="col">{{ orphans ? 'اليتيم' : 'الوصي' }}</th><th scope="col">رقم الهوية</th>
                                <th v-if="orphans" scope="col">العمر</th><th v-if="orphans" scope="col">الوصي</th><th v-else scope="col">الأيتام</th>
                                <th scope="col">المنطقة</th><th scope="col">الحالة</th><th scope="col"><span class="sr-only">الإجراءات</span></th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="row in rows.data" :key="row.public_id" class="cursor-pointer hover:bg-slate-50" @click="open(row)">
                                    <td data-label="الاسم"><button type="button" class="rounded py-1 text-start font-semibold text-navy-800 hover:text-blue-700 hover:underline" :aria-label="`معاينة ${row.full_name}`" @click.stop="open(row)">{{ row.full_name }}</button><span class="block font-mono text-xs text-slate-500" dir="ltr">{{ row.reference_no }}</span></td>
                                    <td data-label="رقم الهوية"><span class="font-mono" dir="ltr">{{ row.id_masked }}</span> <StatusBadge v-if="row.id_format !== 'valid_format'" :label="options.issues[`id_${row.id_format === 'invalid_format' ? 'invalid' : row.id_format}`] || 'وثيقة أخرى'" :tone="idFormatTones[row.id_format]" /></td>
                                    <td v-if="orphans" data-label="العمر">{{ row.age ?? 'غير متوفر' }}</td>
                                    <td v-if="orphans" data-label="الوصي">{{ row.guardian_name }} <StatusBadge v-if="row.guardian_status" :label="{ approved: 'معتمدة', pending: 'بانتظار التحقق', claimed: 'مُدّعاة' }[row.guardian_status]" :tone="linkTones[row.guardian_status]" /></td>
                                    <td v-else data-label="الأيتام">{{ row.children_count }} <span class="text-xs text-slate-500">(معتمد: {{ row.approved_count }})</span></td>
                                    <td data-label="المنطقة">{{ [row.governorate, row.locality].filter(Boolean).join(' — ') || 'غير محددة' }}</td>
                                    <td data-label="الحالة"><StatusBadge v-if="orphans" :label="options.statuses[row.status]" :tone="fileTones[row.status]" /><StatusBadge v-else :label="row.status === 'active' ? 'نشط' : 'غير نشط'" :tone="row.status === 'active' ? 'success' : 'muted'" />
                                        <span v-if="orphans" class="ms-1 text-xs text-slate-500">{{ row.completeness }}%</span></td>
                                    <td data-label="الإجراءات" @click.stop><Link :href="fileUrl(row)" class="btn btn-sm btn-secondary">الملف</Link></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <ul v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <li v-for="row in rows.data" :key="row.public_id">
                            <button type="button" class="card h-full w-full text-start hover:border-blue-300" @click="open(row)">
                                <span class="flex items-start justify-between gap-2"><span class="font-semibold text-navy-800">{{ row.full_name }}</span>
                                    <StatusBadge v-if="orphans" :label="options.statuses[row.status]" :tone="fileTones[row.status]" /></span>
                                <span class="mt-1 block font-mono text-xs text-slate-500" dir="ltr">{{ row.reference_no }} · {{ row.id_masked }}</span>
                                <span class="mt-2 block text-sm text-slate-600">{{ [row.governorate, row.locality].filter(Boolean).join(' — ') || 'المنطقة غير محددة' }}</span>
                                <span v-if="orphans" class="mt-1 block text-sm text-slate-600">الوصي: {{ row.guardian_name || '—' }} · العمر: {{ row.age ?? 'غير متوفر' }}</span>
                                <span v-else class="mt-1 block text-sm text-slate-600">الأيتام المرتبطون: {{ row.children_count }} (معتمد: {{ row.approved_count }})</span>
                            </button>
                        </li>
                    </ul>
                </div>
                <Pagination :page="rows" :busy="busy" />
            </section>
        </div>
        <PreviewPanel :target="preview" @close="preview = null" />
    </AppLayout>
</template>
