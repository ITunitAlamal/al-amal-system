<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';

const props = defineProps({
    groups: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    values: { type: Object, default: null },
    selectedGroupId: { type: Number, default: null },
    selectedTypeId: { type: Number, default: null },
});

const page = usePage();
const base = '/admin/master-data';
const mode = computed(() => new URLSearchParams((page.url || '').split('?')[1] || '').get('view') || 'list');
const editingId = computed(() => Number(new URLSearchParams((page.url || '').split('?')[1] || '').get('id')) || null);
const group = computed(() => props.groups.find(x => Number(x.id) === Number(props.selectedGroupId)) || null);
const type = computed(() => props.types.find(x => Number(x.id) === Number(props.selectedTypeId)) || null);
const record = computed(() => {
    if (mode.value === 'edit-group') return props.groups.find(x => Number(x.id) === editingId.value) || null;
    if (mode.value === 'edit-type') return props.types.find(x => Number(x.id) === editingId.value) || null;
    if (mode.value === 'edit-value') return (props.values?.data || []).find(x => Number(x.id) === editingId.value) || null;
    return null;
});
const isForm = computed(() => mode.value !== 'list');
const editing = computed(() => mode.value.startsWith('edit-'));
const entity = computed(() => mode.value.endsWith('group') ? 'group' : mode.value.endsWith('type') ? 'type' : 'value');
const title = computed(() => {
    const name = { group: 'مجموعة ثوابت', type: 'نوع ثابت', value: 'قيمة ثابت' }[entity.value];
    return `${editing.value ? 'تعديل' : 'إضافة'} ${name}`;
});
const flash = computed(() => page.props.flash?.success || '');
const search = ref('');
const valueRows = computed(() => (props.values?.data || []).filter(x =>
    `${x.code} ${x.name_ar} ${x.name_en || ''}`.toLowerCase().includes(search.value.trim().toLowerCase())
));

function url(params = {}) {
    const query = new URLSearchParams();
    if (params.group_id) query.set('group_id', params.group_id);
    if (params.type_id) query.set('type_id', params.type_id);
    if (params.view) query.set('view', params.view);
    if (params.id) query.set('id', params.id);
    return base + (query.toString() ? `?${query.toString()}` : '');
}
const backUrl = computed(() => url({ group_id: props.selectedGroupId, type_id: props.selectedTypeId }));
const form = useForm({
    group_id: '', type_id: '', code: '', name_ar: '', name_en: '', description: '',
    parent_id: '', sort_order: 0, is_active: true, effective_from: '', effective_to: '', aliases: '',
});
// Other wordings of the same value (old files, imports); one per line.
const aliasesOf = row => { try { const m = typeof row?.metadata === 'string' ? JSON.parse(row.metadata) : row?.metadata; return (m?.aliases || []).join('\n'); } catch { return ''; } };
const today = new Date().toISOString().slice(0, 10);
function valueState(v) {
    if (!v.is_active) return { label: 'معطل', tone: 'muted' };
    if (v.effective_from && v.effective_from > today) return { label: `يسري من ${v.effective_from}`, tone: 'info' };
    if (v.effective_to && v.effective_to < today) return { label: `انتهى في ${v.effective_to}`, tone: 'warning' };
    return { label: 'نشط', tone: 'success' };
}

watch(() => [page.url, record.value?.id], () => {
    form.reset();
    form.clearErrors();
    const row = record.value;
    form.group_id = row?.group_id ?? props.selectedGroupId ?? '';
    form.type_id = row?.type_id ?? props.selectedTypeId ?? '';
    form.code = row?.code ?? '';
    form.name_ar = row?.name_ar ?? '';
    form.name_en = row?.name_en ?? '';
    form.description = row?.description ?? '';
    form.parent_id = row?.parent_id ?? '';
    form.sort_order = row?.sort_order ?? 0;
    form.is_active = row?.is_active === undefined ? true : Boolean(row.is_active);
    form.effective_from = row?.effective_from ? String(row.effective_from).slice(0, 10) : '';
    form.effective_to = row?.effective_to ? String(row.effective_to).slice(0, 10) : '';
    form.aliases = aliasesOf(row);
}, { immediate: true });

function save() {
    if (form.processing) return;
    const e = entity.value;
    const endpoints = {
        group: '/admin/master-data/groups',
        type: '/admin/master-data/types',
        value: '/admin/master-data/values',
    };
    const payload = {
        name_ar: form.name_ar,
        name_en: form.name_en || null,
        description: form.description || null,
        sort_order: Number(form.sort_order) || 0,
    };
    if (e === 'value') {
        payload.effective_from = form.effective_from || null;
        payload.effective_to = form.effective_to || null;
        payload.aliases = form.aliases || '';
    }
    if (editing.value) {
        payload.is_active = Boolean(form.is_active);
    } else {
        payload.code = form.code.trim();
        if (e === 'type') payload.group_id = Number(form.group_id);
        if (e === 'value') {
            payload.type_id = Number(form.type_id);
            payload.parent_id = form.parent_id ? Number(form.parent_id) : null;
        }
    }
    form.transform(() => payload);
    const options = {
        preserveScroll: false,
        onSuccess: () => router.visit(backUrl.value),
        onError: () => window.scrollTo?.({ top: 0, behavior: 'smooth' }),
    };
    if (editing.value) form.put(`${endpoints[e]}/${editingId.value}`, options);
    else form.post(endpoints[e], options);
}
</script>

<template>
    <AppLayout :title="isForm ? title : 'ثوابت النظام'">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="إعدادات النظام · البيانات المرجعية" :title="isForm ? title : 'ثوابت النظام'" description="البيانات المرجعية المركزية التي تظهر في القوائم المنسدلة لجميع الوحدات.">
                <Link v-if="isForm" :href="backUrl" class="btn btn-secondary">العودة إلى الثوابت</Link>
                <Link v-else-if="!group" :href="url({ view: 'create-group' })" class="btn btn-primary">+ مجموعة جديدة</Link>
            </PageHeader>

            <template v-if="!isForm">
                <nav v-if="group" aria-label="مسار الثوابت" class="card flex flex-wrap items-center gap-2 py-3 text-sm">
                    <Link :href="base" class="font-semibold text-blue-700 hover:underline">جميع المجموعات</Link>
                    <span class="text-slate-400" aria-hidden="true">←</span>
                    <Link v-if="type" :href="url({ group_id: group.id })" class="font-semibold text-blue-700 hover:underline">{{ group.name_ar }}</Link>
                    <span v-else class="font-bold text-navy-800">{{ group.name_ar }}</span>
                    <template v-if="type">
                        <span class="text-slate-400" aria-hidden="true">←</span>
                        <span class="font-bold text-navy-800">{{ type.name_ar }}</span>
                    </template>
                </nav>

                <section v-if="!group" class="card">
                    <h2 class="card-title mb-4">المجموعات الرئيسية <span class="text-sm font-normal text-slate-500">({{ groups.length }})</span></h2>
                    <EmptyState v-if="!groups.length" title="لا توجد مجموعات بعد" description="أضف أول مجموعة ثوابت لتبدأ تنظيم البيانات المرجعية." />
                    <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <article v-for="g in groups" :key="g.id" class="group rounded-xl border border-slate-200 p-4 transition hover:border-blue-300 hover:shadow-sm">
                            <div class="flex items-start justify-between gap-2">
                                <Link :href="url({ group_id: g.id })" class="min-w-0 flex-1 font-semibold text-navy-800 hover:text-blue-700" :aria-label="`فتح ${g.name_ar}`">{{ g.name_ar }} <span aria-hidden="true">←</span></Link>
                                <StatusBadge :label="g.is_active ? 'نشطة' : 'معطلة'" :tone="g.is_active ? 'success' : 'muted'" />
                            </div>
                            <p class="mt-1 text-xs text-slate-400" dir="ltr">{{ g.code }}</p>
                            <Link :href="url({ group_id: g.id, view: 'edit-group', id: g.id })" class="mt-3 inline-block text-xs font-semibold text-blue-700 hover:underline">تعديل المجموعة</Link>
                        </article>
                    </div>
                </section>

                <section v-if="group && !type" class="card">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="card-title">أنواع الثوابت — {{ group.name_ar }}</h2>
                            <p class="mt-1 text-xs text-slate-500">اضغط على اسم النوع لإدارة قيمه.</p>
                        </div>
                        <Link :href="url({ group_id: group.id, view: 'create-type' })" class="btn btn-primary">+ نوع جديد</Link>
                    </div>

                    <!-- الفئات والمسميات والسلالم تُدار في جداول متخصصة، لا في master_data_values -->
                    <div v-if="group.code === 'work'" class="mb-5 grid gap-3 md:grid-cols-2">
                        <div class="alert-warning">
                            <h3 class="font-bold text-navy-800">الفئات والمسميات الوظيفية</h3>
                            <p class="mt-1 leading-6">إدارة الفئات والمسميات وربط كل مسمى بفئته.</p>
                            <Link href="/admin/job-catalog" class="btn btn-sm btn-primary mt-3">فتح الفئات والمسميات ←</Link>
                        </div>
                        <div class="alert-warning">
                            <h3 class="font-bold text-navy-800">السلالم والدرجات الوظيفية</h3>
                            <p class="mt-1 leading-6">السلالم ودرجاتها ونطاقات الرواتب الاختيارية، دون تعديل رواتب الموظفين تلقائيًا.</p>
                            <Link href="/admin/career-scales" class="btn btn-sm btn-primary mt-3">فتح السلالم والدرجات ←</Link>
                        </div>
                    </div>

                    <EmptyState v-if="!types.length && group.code !== 'work'" title="لا توجد أنواع ثوابت عامة في هذه المجموعة" description="قد تكون ثوابتها متخصصة وتُدار في صفحات أخرى." />
                    <div v-else class="grid gap-2 md:grid-cols-2">
                        <div v-for="t in types" :key="t.id" class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 transition hover:border-blue-300">
                            <div class="min-w-0">
                                <Link :href="url({ group_id: group.id, type_id: t.id })" class="font-semibold text-navy-800 hover:text-blue-700">{{ t.name_ar }} <span aria-hidden="true">←</span></Link>
                                <p class="mt-1 flex items-center gap-2 text-xs text-slate-400"><span dir="ltr">{{ t.code }}</span><StatusBadge :label="t.is_active ? 'نشط' : 'معطل'" :tone="t.is_active ? 'success' : 'muted'" /></p>
                            </div>
                            <Link :href="url({ group_id: group.id, type_id: t.id, view: 'edit-type', id: t.id })" class="btn btn-sm btn-secondary">تعديل</Link>
                        </div>
                    </div>
                </section>

                <section v-if="type" class="card">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="card-title">قيم الثابت — {{ type.name_ar }}</h2>
                            <p class="mt-1 text-xs text-slate-500">القيم التي تظهر في القوائم المنسدلة لجميع الوحدات.</p>
                        </div>
                        <Link :href="url({ group_id: group.id, type_id: type.id, view: 'create-value' })" class="btn btn-primary">+ قيمة جديدة</Link>
                    </div>
                    <input v-model="search" type="search" placeholder="ابحث بالاسم أو الرمز..." aria-label="بحث في القيم" class="field mb-4 md:max-w-sm" />
                    <div class="overflow-x-auto">
                        <table class="data-table min-w-[570px]">
                            <thead><tr><th>الاسم العربي</th><th>الرمز</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                            <tbody>
                                <tr v-for="v in valueRows" :key="v.id">
                                    <td class="font-medium text-navy-800">{{ v.name_ar }}</td>
                                    <td class="text-slate-500" dir="ltr">{{ v.code }}</td>
                                    <td><StatusBadge :label="valueState(v).label" :tone="valueState(v).tone" /></td>
                                    <td><Link :href="url({ group_id: group.id, type_id: type.id, view: 'edit-value', id: v.id })" class="btn btn-sm btn-secondary">تعديل</Link></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <EmptyState v-if="!valueRows.length" class="mt-3" :title="search ? 'لا توجد قيم مطابقة للبحث' : 'لا توجد قيم بعد'" :description="search ? 'البحث يشمل قيم الصفحة الحالية فقط.' : 'أضف أول قيمة لهذا النوع.'" />
                    <div v-if="values?.links?.length > 3" class="mt-4 flex flex-wrap gap-2">
                        <template v-for="(link, i) in values.links" :key="i">
                            <Link v-if="link.url" :href="link.url" :class="['btn btn-sm', link.active ? 'btn-primary' : 'btn-secondary']" preserve-scroll><span v-html="link.label" /></Link>
                            <span v-else class="btn btn-sm btn-secondary opacity-40" v-html="link.label" />
                        </template>
                    </div>
                </section>
            </template>

            <section v-else-if="!editing || record" class="card sm:p-6">
                <form class="max-w-3xl space-y-5" @submit.prevent="save">
                    <p v-if="!editing" class="alert-info">يُحفظ السجل في قاعدة البيانات ويُستخدم في الوحدات المرتبطة. الرمز ثابت ولا يمكن تغييره لاحقًا.</p>
                    <div v-if="entity === 'type' && !editing">
                        <label for="md-group" class="field-label">المجموعة الرئيسية *</label>
                        <select id="md-group" v-model="form.group_id" required class="field"><option value="" disabled>اختر المجموعة</option><option v-for="g in groups.filter(x => x.is_active)" :key="g.id" :value="g.id">{{ g.name_ar }}</option></select>
                        <p v-if="form.errors.group_id" class="field-error">{{ form.errors.group_id }}</p>
                    </div>
                    <div v-if="entity === 'value' && !editing">
                        <label for="md-type" class="field-label">نوع الثابت *</label>
                        <select id="md-type" v-model="form.type_id" required class="field"><option value="" disabled>اختر النوع</option><option v-for="t in types.filter(x => x.is_active)" :key="t.id" :value="t.id">{{ t.name_ar }}</option></select>
                        <p v-if="form.errors.type_id" class="field-error">{{ form.errors.type_id }}</p>
                    </div>
                    <div v-if="!editing">
                        <label for="md-code" class="field-label">الرمز (بالإنجليزية) *</label>
                        <input id="md-code" v-model="form.code" required pattern="[a-z][a-z0-9_]*" maxlength="100" dir="ltr" class="field text-left" placeholder="example_code" />
                        <p class="mt-1 text-xs text-slate-500">أحرف إنجليزية صغيرة وأرقام وشرطة سفلية، ويبدأ بحرف.</p>
                        <p v-if="entity === 'value' && types.find(t => Number(t.id) === Number(form.type_id))?.code === 'attendance_grace_minutes'" class="mt-1 rounded-lg bg-gold-50 p-2 text-xs text-gold-900">
                            يقرأ النظام فترة السماح من الرمز: اكتب min_ ثم عدد الدقائق، مثل min_10 لعشر دقائق (من 0 إلى 240). تبقى قيمة واحدة نشطة فقط.
                        </p>
                        <p v-if="form.errors.code" class="field-error">{{ form.errors.code }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="md-name-ar" class="field-label">الاسم بالعربية *</label>
                            <input id="md-name-ar" v-model="form.name_ar" required maxlength="255" class="field" />
                            <p v-if="form.errors.name_ar" class="field-error">{{ form.errors.name_ar }}</p>
                        </div>
                        <div>
                            <label for="md-name-en" class="field-label">الاسم بالإنجليزية</label>
                            <input id="md-name-en" v-model="form.name_en" maxlength="255" dir="ltr" class="field text-left" />
                            <p v-if="form.errors.name_en" class="field-error">{{ form.errors.name_en }}</p>
                        </div>
                    </div>
                    <div>
                        <label for="md-description" class="field-label">الوصف / الملاحظات</label>
                        <textarea id="md-description" v-model="form.description" maxlength="5000" rows="3" class="field" />
                        <p v-if="form.errors.description" class="field-error">{{ form.errors.description }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="md-sort" class="field-label">ترتيب العرض</label>
                            <input id="md-sort" v-model.number="form.sort_order" type="number" min="0" required class="field" />
                            <p v-if="form.errors.sort_order" class="field-error">{{ form.errors.sort_order }}</p>
                        </div>
                        <label v-if="editing" class="flex items-center gap-3 pt-6 text-sm font-medium text-slate-700"><input v-model="form.is_active" type="checkbox" /> نشط / متاح للاختيار</label>
                    </div>
                    <div v-if="entity === 'value'" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="md-from" class="field-label">يسري من (اختياري)</label>
                            <input id="md-from" v-model="form.effective_from" type="date" class="field" />
                            <p v-if="form.errors.effective_from" class="field-error">{{ form.errors.effective_from }}</p>
                        </div>
                        <div>
                            <label for="md-to" class="field-label">ينتهي في (اختياري)</label>
                            <input id="md-to" v-model="form.effective_to" type="date" class="field" />
                            <p v-if="form.errors.effective_to" class="field-error">{{ form.errors.effective_to }}</p>
                        </div>
                        <p class="text-xs leading-5 text-slate-500 sm:col-span-2">خارج هذه الفترة لا تظهر القيمة في الطلبات الجديدة، وتبقى ظاهرة في السجلات السابقة. لا تُحذف القيم المستخدمة.</p>
                        <div class="sm:col-span-2">
                            <label for="md-aliases" class="field-label">أسماء بديلة (اختياري، كل اسم في سطر)</label>
                            <textarea id="md-aliases" v-model="form.aliases" rows="3" maxlength="2000" class="field" placeholder="مثال: جزئي بليغ" />
                            <p class="text-xs text-slate-500">كتابات أخرى للقيمة نفسها كما ترد في الملفات القديمة أو الاستيراد؛ تُطابق تلقائيًا مع هذه القيمة بدل إنشاء قيمة مكررة.</p>
                            <p v-if="form.errors.aliases" class="field-error">{{ form.errors.aliases }}</p>
                        </div>
                    </div>
                    <div v-if="entity === 'value' && !editing && values?.data?.length">
                        <label for="md-parent" class="field-label">القيمة الأب (اختياري)</label>
                        <select id="md-parent" v-model="form.parent_id" class="field"><option value="">بدون قيمة أب</option><option v-for="v in values.data.filter(x => x.is_active)" :key="v.id" :value="v.id">{{ v.name_ar }}</option></select>
                        <p class="mt-1 text-xs text-gold-700">تعرض القائمة قيم الصفحة الحالية فقط.</p>
                        <p v-if="form.errors.parent_id" class="field-error">{{ form.errors.parent_id }}</p>
                    </div>
                    <p v-if="Object.keys(form.errors).length" role="alert" class="alert-error">راجع الحقول المشار إليها قبل الحفظ.</p>
                    <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-5">
                        <button type="submit" :disabled="form.processing" class="btn btn-primary">{{ form.processing ? 'جارٍ الحفظ...' : 'حفظ البيانات' }}</button>
                        <Link :href="backUrl" class="btn btn-secondary">إلغاء</Link>
                    </div>
                </form>
            </section>
            <p v-else role="alert" class="alert-error">لم يُعثر على السجل في البيانات المحملة. ارجع إلى القائمة وافتح السجل من الصفحة التي يظهر فيها.</p>
        </div>
    </AppLayout>
</template>
