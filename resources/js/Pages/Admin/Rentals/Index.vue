<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import GeographicAddress from '../../../Components/GeographicAddress.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import RentalFiles from '../../../Components/RentalFiles.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

const p = defineProps({
    registryDocuments: { type: Array, default: () => [] },
    properties: Array, floors: Array, units: Array, unitEvents: Array, tenants: Array, applications: Array, contracts: Array, contractUnits: Array,
    dues: Array, totals: Array, cases: Array, settlements: Array, readings: Array, notices: Array, options: Object, maintenancePriorities: Array,
    currencies: Array, labels: Object, settings: Object, manageRegistry: Boolean, canDecide: Boolean, canManage: Boolean, isAdmin: Boolean, masterBuildings: Array,
    maintenanceLinks: { type: Array, default: () => [] }, costBearers: { type: Object, default: () => ({}) },
});

const page = usePage();
const tabs = [
    ['overview', 'الملخص'], ['registry', 'الأصول والأعيان'], ['tenants', 'المستأجرون'], ['applications', 'طلبات الاستئجار'], ['contracts', 'العقود'],
    ['dues', 'الاستحقاقات والأرصدة'], ['settlements', 'التسويات'], ['cases', 'الإنهاء والإخلاء'], ['meters', 'العدادات'], ['notices', 'الإنذارات'],
];
// Dashboard links open a tab directly (?tab=contracts); an unknown value falls back to the summary.
const requestedTab = new URLSearchParams(page.url.split('?')[1] || '').get('tab');
const tab = ref(tabs.some(([key]) => key === requestedTab) ? requestedTab : 'overview');
// The address follows the tab (bookmarks, back button), without reloading the page.
watch(tab, value => {
    const base = String(page.url).split('?')[0];
    router.replace({ url: value === 'overview' ? base : `${base}?tab=${value}`, preserveScroll: true, preserveState: true });
});

const money = n => Number(n || 0).toLocaleString('ar-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const byId = (list, id) => (list || []).find(x => Number(x.id) === Number(id));
const nameOf = (list, id, field = 'name') => byId(list, id)?.[field] ?? (id ? `#${id}` : '—');
const optionName = (type, id) => (p.options[type] || []).find(o => o.id === Number(id))?.name ?? '—';
const unitLabel = unit => (unit ? `${nameOf(p.properties, unit.property_id)} / ${unit.code} ${unit.name}` : '—');
const record = (type, id) => `/admin/rentals/records/${type}/${id}`;
const field = 'block text-sm font-medium text-slate-700';
const submit = (form, url, options = {}) => form.post(url, { preserveScroll: true, onSuccess: () => form.reset(), ...options });

/* Summary */
const counts = computed(() => ({
    all: p.units.length,
    rented: p.units.filter(u => u.availability === 'rented').length,
    vacant: p.units.filter(u => u.availability === 'vacant').length,
    active: p.contracts.filter(c => c.status === 'active').length,
}));
const exportForm = useForm({ type: 'units', reason: '' });
function exportCsv() {
    if (exportForm.reason.trim().length < 5) { exportForm.setError('reason', 'اكتب سبب التصدير (5 أحرف على الأقل).'); return; }
    window.location.href = `/admin/rentals/report.csv?type=${exportForm.type}&reason=${encodeURIComponent(exportForm.reason)}`;
}
const settingsForm = useForm({ renewal_alert_days: p.settings?.renewal_alert_days ?? 90, overdue_alert_days: p.settings?.overdue_alert_days ?? 0 });

/* Maintenance of the units: return a unit to vacant once its request closed, and record who bears the cost */
const costForms = {};
const costForm = m => (costForms[m.id] ??= useForm({ cost_bearer: m.cost_bearer || '', cost_reference: m.cost_reference || '' }));
function releaseUnit(m) {
    if (confirm(`إعادة العين ${m.unit_code} شاغرة بعد إغلاق طلب الصيانة ${m.reference_no}؟`)) router.post(`/admin/rentals/maintenance/${m.id}/release`, {}, { preserveScroll: true });
}

/* Registry */
const blankProperty = { kind_value_id: null, ownership_value_id: null, plot_number: '', parcel_number: '', location: '', description: '', governorate_value_id: null, locality_value_id: null, area_value_id: null, subarea_value_id: null, address_other: false, address_details: '' };
const property = useForm({ master_building_value_id: '', ...blankProperty });
const propertyEdit = useForm({ ...blankProperty });
const editingProperty = ref(null);
function editProperty(row) {
    editingProperty.value = row.id;
    Object.assign(propertyEdit, Object.fromEntries(Object.keys(blankProperty).map(k => [k, row[k] ?? blankProperty[k]])));
}
const floor = useForm({ property_id: '', name: '', sort_order: 0 });
const blankUnit = { floor_id: '', code: '', name: '', type_value_id: null, usage_value_id: null, area_m2: '', has_mezzanine: false, mezzanine_area_m2: '', directions: '', rentable: true, description: '' };
const unit = useForm({ property_id: '', ...blankUnit });
const filters = ref({ property_id: '', status: '' });
const filteredUnits = computed(() => p.units.filter(u => (!filters.value.property_id || Number(u.property_id) === Number(filters.value.property_id)) && (!filters.value.status || u.availability === filters.value.status)));
const selectedUnit = ref(null);
const unitEdit = useForm({ ...blankUnit });
const unitStatus = useForm({ status: '', reason: '' });
const maintenance = useForm({ unit_id: null, contract_id: null, description: '', priority_value_id: '' });
function manageUnit(row) {
    selectedUnit.value = row;
    Object.assign(unitEdit, Object.fromEntries(Object.keys(blankUnit).map(k => [k, row[k] ?? blankUnit[k]])));
    unitStatus.reset(); maintenance.reset(); unitEdit.clearErrors();
    maintenance.unit_id = row.id;
}
const unitHistory = computed(() => p.unitEvents.filter(e => selectedUnit.value && Number(e.unit_id) === Number(selectedUnit.value.id)));

/* Tenants */
const blankTenant = { type: 'person', name: '', identity_number: '', representative: '', phone: '', phone2: '', email: '', address: '', activity: '', governorate_value_id: null, locality_value_id: null, area_value_id: null, subarea_value_id: null, address_other: false, address_details: '' };
const tenant = useForm({ ...blankTenant });
const tenantEdit = useForm({ ...blankTenant });
const editingTenant = ref(null);
function editTenant(row) {
    editingTenant.value = row.id;
    Object.assign(tenantEdit, Object.fromEntries(Object.keys(blankTenant).map(k => [k, row[k] ?? blankTenant[k]])));
}
const tenantTypes = { person: 'فرد', company: 'شركة', association: 'جمعية', institution: 'مؤسسة' };

/* Applications and contracts */
const application = useForm({ tenant_id: '', unit_id: '', purpose: '', study: '' });
const lettableUnits = computed(() => p.units.filter(u => u.rentable && !['draft', 'out_of_service'].includes(u.availability)));
const contract = useForm({
    number: '', tenant_id: '', kind: 'new', application_id: '', parent_contract_id: '', issued_on: '', starts_on: '', ends_on: '', currency_code: p.currencies?.[0] ?? 'ILS',
    frequency: 'monthly', total_rent: '', auto_renew: false, renewal_clause: '', terms: '', change_reason: '', items: [{ unit_id: '', rent_share: '', rent_per_m2: '' }], services: [],
});
const occupying = computed(() => p.contracts.filter(c => ['active', 'expired'].includes(c.status)));
const contractUnitOptions = computed(() => {
    const parentUnits = p.contractUnits.filter(x => Number(x.contract_id) === Number(contract.parent_contract_id)).map(x => Number(x.unit_id));
    return p.units.filter(u => u.rentable && (['vacant', 'reserved'].includes(u.availability) || parentUnits.includes(Number(u.id))));
});
function renew(row, kind) {
    tab.value = 'contracts';
    contract.reset();
    Object.assign(contract, { kind, parent_contract_id: row.id, tenant_id: row.tenant_id, currency_code: row.currency_code, frequency: row.frequency, total_rent: row.total_rent,
        items: p.contractUnits.filter(x => Number(x.contract_id) === Number(row.id)).map(x => ({ unit_id: x.unit_id, rent_share: x.rent_share, rent_per_m2: x.rent_per_m2 ?? '' })) });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
const unitsOf = id => p.contractUnits.filter(x => Number(x.contract_id) === Number(id)).map(x => unitLabel(byId(p.units, x.unit_id))).join('، ');

/* Dues, settlements, terminations */
const dueFilter = ref('open');
const shownDues = computed(() => p.dues.filter(d => dueFilter.value === 'all' || (dueFilter.value === 'open' && Number(d.remaining) > 0)
    || (dueFilter.value === 'overdue' && d.overdue) || (dueFilter.value === 'paid' && d.payment_state === 'paid')));
const settlement = useForm({ due_id: '', type_value_id: '', effect: 'reduce', amount: '', reason: '' });
function settle(due) { settlement.reset(); settlement.due_id = due.id; tab.value = 'settlements'; }
const termination = useForm({ contract_id: '', termination_reason_value_id: '', departure_on: '', reason: '', details: '' });

/* Meters and notices */
const reading = useForm({ unit_id: '', contract_id: '', case_id: '', purpose: 'periodic', meter_kind: 'كهرباء', reading: '', read_on: '', photo: null });
const notice = useForm({ contract_id: '', kind: 'overdue', body: '' });
const noticeKinds = { overdue: 'تأخر', payment_due: 'استحقاق', renewal: 'تجديد', document: 'وثيقة', maintenance: 'صيانة', other: 'أخرى' };
const purposes = { handover: 'عند التسليم', periodic: 'دورية', eviction: 'عند الإخلاء' };
function issue(id) { if (confirm('تسجيل إصدار الإنذار وتاريخه؟')) router.post(`/admin/rentals/notices/${id}/issue`, {}, { preserveScroll: true }); }
</script>

<template>
<AppLayout title="الأصول والإيجارات">
<div dir="rtl" class="mx-auto max-w-7xl space-y-5">
    <PageHeader eyebrow="الأصول والمباني" title="الأصول والمباني والإيجارات" description="المباني من الثوابت؛ الطلبات والعقود والإنهاء والتسويات عبر مسار العمل المركزي؛ القبض يُسجله قسم الحسابات من البرنامج المحاسبي.">
        <Link v-if="manageRegistry" href="/admin/rentals" class="btn btn-secondary">العودة إلى الإيجارات</Link>
        <Link v-else-if="canManage" href="/admin/master-data/buildings?tab=registry" class="btn btn-secondary">إدارة المباني من ثوابت النظام</Link>
    </PageHeader>

    <nav class="flex flex-wrap gap-2 border-b border-slate-200 pb-3" aria-label="أقسام الوحدة">
        <button v-for="[key, label] in tabs" :key="key" type="button" @click="tab = key" :aria-current="tab === key ? 'page' : undefined"
            class="rounded-lg px-3 py-2 text-sm font-semibold" :class="tab === key ? 'bg-navy-700 text-white' : 'bg-white text-navy-800 ring-1 ring-slate-200 hover:bg-navy-50'">{{ label }}</button>
    </nav>

    <!-- Summary -->
    <section v-if="tab === 'overview'" class="space-y-4">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div v-for="s in [{ n: 'إجمالي الأعيان', v: counts.all }, { n: 'مؤجرة', v: counts.rented }, { n: 'شاغرة', v: counts.vacant }, { n: 'العقود الفعالة', v: counts.active }]" :key="s.n" class="card">
                <span class="block text-sm text-slate-500">{{ s.n }}</span><b class="text-2xl text-navy-800">{{ s.v }}</b>
            </div>
        </div>
        <div class="card overflow-x-auto">
            <h2 class="card-title mb-2">الأرصدة حسب العملة</h2>
            <p class="mb-3 text-xs text-slate-500">المستحق الأصلي، والمقبوض الذي سجلته الحسابات من البرنامج المحاسبي، والتسويات المرحّلة، والمتبقي؛ كل عملة وحدها.</p>
            <table v-if="totals.length" class="data-table">
                <thead><tr><th>العملة</th><th>المستحق</th><th>المقبوض</th><th>التخفيضات المرحّلة</th><th>المتبقي</th><th>منه متأخر</th></tr></thead>
                <tbody><tr v-for="t in totals" :key="t.currency"><td dir="ltr">{{ t.currency }}</td><td>{{ money(t.amount) }}</td><td>{{ money(t.paid) }}</td><td>{{ money(t.reduced) }}</td><td>{{ money(t.remaining) }}</td><td :class="Number(t.overdue) ? 'font-bold text-red-700' : ''">{{ money(t.overdue) }}</td></tr></tbody>
            </table>
            <EmptyState v-else title="لا توجد استحقاقات بعد" description="تتولد الاستحقاقات عند تفعيل العقد بعد اعتماده وتوقيعه." />
        </div>
        <div v-if="maintenanceLinks.length" class="card overflow-x-auto">
            <h2 class="card-title mb-2">صيانة الأعيان</h2>
            <p class="mb-3 text-xs text-slate-500">بعد إغلاق طلب الصيانة تُعاد العين «تحت الصيانة» شاغرة بنقرة. التكلفة على المستأجر تُطالَب بطلب تسوية «زيادة المستحق».</p>
            <table class="data-table">
                <thead><tr><th>الطلب</th><th>العين</th><th>المرحلة</th><th>من يتحمل التكلفة</th><th></th></tr></thead>
                <tbody><tr v-for="m in maintenanceLinks" :key="m.id">
                    <td><Link :href="m.url" class="text-blue-700 hover:underline" dir="ltr">{{ m.reference_no }}</Link></td>
                    <td>{{ m.unit_code ? `${m.unit_code} ${m.unit_name}` : '—' }}<span v-if="m.unit_availability" class="block text-[11px] text-slate-500">{{ labels.units[m.unit_availability] }}</span></td>
                    <td>{{ m.step || m.status }}</td>
                    <td>
                        <form v-if="canManage" class="flex flex-wrap gap-1" @submit.prevent="costForm(m).put(`/admin/rentals/maintenance/${m.id}/cost`, { preserveScroll: true })">
                            <select v-model="costForm(m).cost_bearer" class="field w-28" required aria-label="من يتحمل التكلفة"><option value="">—</option><option v-for="(label, key) in costBearers" :key="key" :value="key">{{ label }}</option></select>
                            <input v-model="costForm(m).cost_reference" class="field w-32" maxlength="150" placeholder="المرجع المحاسبي" dir="ltr">
                            <button class="btn btn-secondary btn-sm" :disabled="costForm(m).processing">حفظ</button>
                        </form>
                        <span v-else>{{ costBearers[m.cost_bearer] || '—' }}</span>
                    </td>
                    <td><button v-if="canManage && m.releasable" type="button" class="btn btn-primary btn-sm" @click="releaseUnit(m)">إعادة العين شاغرة</button></td>
                </tr></tbody>
            </table>
        </div>
        <form class="card grid gap-3 md:grid-cols-4" @submit.prevent="exportCsv">
            <h2 class="card-title md:col-span-4">تصدير CSV (يُطلب السبب ويُسجل في التدقيق)</h2>
            <select v-model="exportForm.type" class="field" aria-label="نوع التصدير"><option value="units">الأعيان</option><option value="contracts">العقود</option><option value="dues">الاستحقاقات والأرصدة</option></select>
            <input v-model="exportForm.reason" class="field md:col-span-2" placeholder="سبب التصدير" aria-label="سبب التصدير">
            <button class="btn btn-secondary">تصدير</button>
            <p v-if="exportForm.errors.reason" class="field-error md:col-span-4">{{ exportForm.errors.reason }}</p>
        </form>
        <form v-if="isAdmin" class="card grid gap-3 md:grid-cols-3" @submit.prevent="settingsForm.put('/admin/rentals/settings', { preserveScroll: true })">
            <h2 class="card-title md:col-span-3">مدد التنبيه (مدير النظام)</h2>
            <label :class="field">التنبيه قبل انتهاء العقد (أيام)<input v-model.number="settingsForm.renewal_alert_days" type="number" min="1" max="365" class="field mt-1"></label>
            <label :class="field">تنبيه التأخر بعد موعد الاستحقاق (أيام)<input v-model.number="settingsForm.overdue_alert_days" type="number" min="0" max="90" class="field mt-1"></label>
            <div class="flex items-end"><button class="btn btn-primary" :disabled="settingsForm.processing">حفظ</button></div>
        </form>
    </section>

    <!-- Registry -->
    <section v-if="tab === 'registry'" class="space-y-5">
        <div v-if="manageRegistry && canManage" class="grid gap-4 lg:grid-cols-3">
            <form class="card space-y-2" @submit.prevent="submit(property, '/admin/master-data/buildings/properties')">
                <h3 class="text-sm font-bold text-navy-800">إضافة أصل / مبنى</h3>
                <select v-model="property.master_building_value_id" class="field" required aria-label="المبنى من الثوابت">
                    <option value="">اختر مبنى من ثوابت النظام</option>
                    <option v-for="b in masterBuildings" :key="b.id" :value="b.id">{{ b.name_ar }} ({{ b.code }})</option>
                </select>
                <p v-if="!masterBuildings.length" class="text-xs leading-5 text-gold-800">لا توجد مبانٍ في ثوابت النظام بعد. يضيفها مدير النظام من «الثوابت» ← «ثوابت المباني والمرافق»، ثم تظهر هنا.</p>
                <p v-if="property.errors.master_building_value_id" class="field-error">{{ property.errors.master_building_value_id }}</p>
                <select v-model="property.kind_value_id" class="field" aria-label="نوع الأصل"><option :value="null">نوع الأصل</option><option v-for="o in options.asset_types" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                <select v-model="property.ownership_value_id" class="field" aria-label="الملكية"><option :value="null">الملكية{{ options.property_ownership_types.length ? '' : ' (لا قيم في الثوابت)' }}</option><option v-for="o in options.property_ownership_types" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                <div class="grid grid-cols-2 gap-2"><input v-model="property.plot_number" class="field" placeholder="رقم القطعة"><input v-model="property.parcel_number" class="field" placeholder="رقم القسيمة"></div>
                <GeographicAddress :model-value="property" :errors="property.errors" optional @update:model-value="Object.assign(property, $event)" />
                <textarea v-model="property.description" class="field" rows="2" placeholder="ملاحظات"></textarea>
                <button :disabled="property.processing" class="btn btn-primary">حفظ الأصل</button>
            </form>
            <form class="card space-y-2" @submit.prevent="submit(floor, '/admin/master-data/buildings/floors')">
                <h3 class="text-sm font-bold text-navy-800">طابق (اختياري)</h3>
                <select v-model="floor.property_id" class="field" required aria-label="المبنى"><option value="">اختر المبنى</option><option v-for="a in properties" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                <input v-model="floor.name" class="field" placeholder="اسم الطابق" required>
                <input v-model.number="floor.sort_order" type="number" class="field" placeholder="الترتيب">
                <p v-if="floor.errors.name" class="field-error">{{ floor.errors.name }}</p>
                <button :disabled="floor.processing" class="btn btn-primary">حفظ الطابق</button>
            </form>
            <form class="card space-y-2" @submit.prevent="submit(unit, '/admin/master-data/buildings/units')">
                <h3 class="text-sm font-bold text-navy-800">إضافة عين (تبدأ «قيد الإعداد»)</h3>
                <select v-model="unit.property_id" class="field" required aria-label="المبنى"><option value="">الأصل / المبنى</option><option v-for="a in properties" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                <select v-model="unit.floor_id" class="field" aria-label="الطابق"><option value="">بلا طابق</option><option v-for="f in floors.filter(x => Number(x.property_id) === Number(unit.property_id))" :key="f.id" :value="f.id">{{ f.name }}</option></select>
                <div class="grid grid-cols-2 gap-2"><input v-model="unit.code" class="field" placeholder="رقم العين" required><input v-model="unit.name" class="field" placeholder="اسم العين" required></div>
                <select v-model="unit.type_value_id" class="field" aria-label="نوع العين"><option :value="null">نوع العين{{ options.rental_unit_types.length ? '' : ' (لا قيم في الثوابت)' }}</option><option v-for="o in options.rental_unit_types" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                <select v-model="unit.usage_value_id" class="field" aria-label="الاستخدام"><option :value="null">الاستخدام{{ options.rental_unit_usages.length ? '' : ' (لا قيم في الثوابت)' }}</option><option v-for="o in options.rental_unit_usages" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                <div class="grid grid-cols-2 gap-2"><input v-model="unit.area_m2" type="number" step="0.01" min="0" class="field" placeholder="المساحة م²"><input v-model="unit.directions" class="field" placeholder="الاتجاهات"></div>
                <label class="block text-sm"><input v-model="unit.has_mezzanine" type="checkbox"> يحتوي على سدة</label>
                <input v-if="unit.has_mezzanine" v-model="unit.mezzanine_area_m2" type="number" step="0.01" min="0" class="field" placeholder="مساحة السدة">
                <label class="block text-sm"><input v-model="unit.rentable" type="checkbox"> قابلة للتأجير</label>
                <p v-if="unit.errors.code" class="field-error">{{ unit.errors.code }}</p>
                <button :disabled="unit.processing" class="btn btn-primary">حفظ العين</button>
            </form>
        </div>

        <div v-if="properties.length" class="card overflow-x-auto">
            <h3 class="card-title mb-3">الأصول والمباني</h3>
            <table class="data-table">
                <thead><tr><th>الرمز</th><th>الاسم</th><th>النوع</th><th>القطعة / القسيمة</th><th>الموقع</th><th></th></tr></thead>
                <tbody>
                    <template v-for="a in properties" :key="a.id">
                        <tr><td dir="ltr">{{ a.code }}</td><td>{{ a.name }}</td><td>{{ optionName('asset_types', a.kind_value_id) }}</td><td>{{ a.plot_number || '—' }} / {{ a.parcel_number || '—' }}</td><td>{{ a.location || '—' }}</td>
                            <td><button v-if="canManage" type="button" class="btn btn-secondary btn-sm" @click="editProperty(a)">تعديل</button></td></tr>
                        <tr v-if="editingProperty === a.id"><td colspan="6">
                            <form class="grid gap-2 md:grid-cols-3" @submit.prevent="propertyEdit.put(`/admin/rentals/properties/${a.id}`, { preserveScroll: true, onSuccess: () => (editingProperty = null) })">
                                <select v-model="propertyEdit.kind_value_id" class="field" aria-label="نوع الأصل"><option :value="null">نوع الأصل</option><option v-for="o in options.asset_types" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                                <select v-model="propertyEdit.ownership_value_id" class="field" aria-label="الملكية"><option :value="null">الملكية</option><option v-for="o in options.property_ownership_types" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                                <div class="grid grid-cols-2 gap-2"><input v-model="propertyEdit.plot_number" class="field" placeholder="رقم القطعة"><input v-model="propertyEdit.parcel_number" class="field" placeholder="رقم القسيمة"></div>
                                <GeographicAddress class="md:col-span-3" :model-value="propertyEdit" :errors="propertyEdit.errors" optional @update:model-value="Object.assign(propertyEdit, $event)" />
                                <textarea v-model="propertyEdit.description" class="field md:col-span-2" rows="2" placeholder="ملاحظات"></textarea>
                                <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="propertyEdit.processing">حفظ</button><button type="button" class="btn btn-secondary btn-sm" @click="editingProperty = null">إلغاء</button></div>
                            </form>
                            <RentalFiles class="mt-3" owner="property" :owner-id="a.id" :documents="registryDocuments" :types="options.rental_document_types" :can-upload="canManage" />
                        </td></tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="card overflow-x-auto">
            <h3 class="card-title mb-3">سجل الأعيان</h3>
            <div class="mb-2 flex flex-wrap gap-2">
                <select v-model="filters.property_id" class="field w-auto" aria-label="تصفية بالمبنى"><option value="">جميع الأصول</option><option v-for="a in properties" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                <select v-model="filters.status" class="field w-auto" aria-label="تصفية بالحالة"><option value="">كل الحالات</option><option v-for="(label, key) in labels.units" :key="key" :value="key">{{ label }}</option></select>
            </div>
            <table v-if="filteredUnits.length" class="data-table">
                <thead><tr><th>الرمز</th><th>العين</th><th>المبنى</th><th>الطابق</th><th>المساحة</th><th>النوع</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="u in filteredUnits" :key="u.id">
                        <td dir="ltr">{{ u.code }}</td><td>{{ u.name }}</td><td>{{ nameOf(properties, u.property_id) }}</td><td>{{ u.floor_id ? nameOf(floors, u.floor_id) : 'بدون طابق' }}</td>
                        <td>{{ u.area_m2 || '—' }}</td><td>{{ optionName('rental_unit_types', u.type_value_id) }}</td><td><StatusBadge :label="labels.units[u.availability] || u.availability" :status="u.availability" /></td>
                        <td><button v-if="canManage" type="button" class="btn btn-secondary btn-sm" @click="manageUnit(u)">إدارة</button></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="لا توجد أعيان" description="تُسجل الأعيان من «إدارة المباني من ثوابت النظام»." />
        </div>

        <div v-if="selectedUnit" class="card space-y-4" aria-live="polite">
            <div class="flex items-center justify-between gap-2"><h3 class="card-title">العين {{ unitLabel(selectedUnit) }}</h3><button type="button" class="btn btn-secondary btn-sm" @click="selectedUnit = null">إغلاق</button></div>
            <div class="grid gap-4 lg:grid-cols-3">
                <form class="space-y-2" @submit.prevent="unitEdit.put(`/admin/rentals/units/${selectedUnit.id}`, { preserveScroll: true })">
                    <h4 class="text-sm font-bold">تعديل البيانات</h4>
                    <div class="grid grid-cols-2 gap-2"><input v-model="unitEdit.code" class="field" required aria-label="رقم العين"><input v-model="unitEdit.name" class="field" required aria-label="اسم العين"></div>
                    <select v-model="unitEdit.floor_id" class="field" aria-label="الطابق"><option value="">بلا طابق</option><option v-for="f in floors.filter(x => Number(x.property_id) === Number(selectedUnit.property_id))" :key="f.id" :value="f.id">{{ f.name }}</option></select>
                    <select v-model="unitEdit.type_value_id" class="field" aria-label="نوع العين"><option :value="null">نوع العين</option><option v-for="o in options.rental_unit_types" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                    <select v-model="unitEdit.usage_value_id" class="field" aria-label="الاستخدام"><option :value="null">الاستخدام</option><option v-for="o in options.rental_unit_usages" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                    <div class="grid grid-cols-2 gap-2"><input v-model="unitEdit.area_m2" type="number" step="0.01" min="0" class="field" placeholder="المساحة م²"><input v-model="unitEdit.directions" class="field" placeholder="الاتجاهات"></div>
                    <label class="block text-sm"><input v-model="unitEdit.has_mezzanine" type="checkbox"> سدة</label>
                    <input v-if="unitEdit.has_mezzanine" v-model="unitEdit.mezzanine_area_m2" type="number" step="0.01" min="0" class="field" placeholder="مساحة السدة">
                    <label class="block text-sm"><input v-model="unitEdit.rentable" type="checkbox"> قابلة للتأجير</label>
                    <textarea v-model="unitEdit.description" class="field" rows="2" placeholder="وصف"></textarea>
                    <p v-for="(message, key) in unitEdit.errors" :key="key" class="field-error">{{ message }}</p>
                    <button class="btn btn-primary btn-sm" :disabled="unitEdit.processing">حفظ</button>
                </form>
                <div class="space-y-4">
                    <form class="space-y-2" @submit.prevent="unitStatus.post(`/admin/rentals/units/${selectedUnit.id}/status`, { preserveScroll: true, onSuccess: () => { unitStatus.reset(); selectedUnit = null; } })">
                        <h4 class="text-sm font-bold">تغيير الحالة (الحالية: {{ labels.units[selectedUnit.availability] }})</h4>
                        <p v-if="!(labels.manual[selectedUnit.availability] || []).length" class="text-xs text-slate-500">{{ selectedUnit.availability === 'rented' ? 'العين مؤجرة: تتغير حالتها بإكمال معاملة إنهاء وإخلاء.' : 'لا انتقال يدوي من هذه الحالة.' }}</p>
                        <template v-else>
                            <select v-model="unitStatus.status" class="field" required aria-label="الحالة الجديدة"><option value="">الحالة الجديدة</option><option v-for="s in labels.manual[selectedUnit.availability]" :key="s" :value="s">{{ labels.units[s] }}</option></select>
                            <textarea v-model="unitStatus.reason" class="field" rows="2" required placeholder="السبب (مثل: تم فحص الجاهزية)"></textarea>
                            <p v-for="(message, key) in unitStatus.errors" :key="key" class="field-error">{{ message }}</p>
                            <button class="btn btn-secondary btn-sm" :disabled="unitStatus.processing">تغيير الحالة</button>
                        </template>
                    </form>
                    <form class="space-y-2" @submit.prevent="submit(maintenance, '/admin/rentals/maintenance')">
                        <h4 class="text-sm font-bold">طلب صيانة أو فحص (المسار المركزي)</h4>
                        <textarea v-model="maintenance.description" class="field" rows="2" required placeholder="وصف العطل أو الفحص المطلوب"></textarea>
                        <select v-model="maintenance.priority_value_id" class="field" required aria-label="الأولوية"><option value="">الأولوية</option><option v-for="o in maintenancePriorities" :key="o.id" :value="o.id">{{ o.name_ar }}</option></select>
                        <p v-for="(message, key) in maintenance.errors" :key="key" class="field-error">{{ message }}</p>
                        <button class="btn btn-secondary btn-sm" :disabled="maintenance.processing">فتح طلب صيانة</button>
                    </form>
                </div>
                <div>
                    <h4 class="mb-2 text-sm font-bold">سجل الحالة</h4>
                    <ol v-if="unitHistory.length" class="space-y-2 text-xs">
                        <li v-for="(e, i) in unitHistory" :key="i" class="rounded-lg border border-slate-100 p-2">
                            <b>{{ labels.units[e.from_status] || '—' }} ← {{ labels.units[e.to_status] }}</b> · {{ e.created_at }}<br>{{ e.reason }}<span v-if="e.actor"> — {{ e.actor }}</span>
                        </li>
                    </ol>
                    <p v-else class="text-xs text-slate-500">لا تغييرات مسجلة ضمن آخر 100 حدث.</p>
                    <RentalFiles class="mt-4" owner="unit" :owner-id="selectedUnit.id" :documents="registryDocuments" :types="options.rental_document_types" :can-upload="canManage" />
                </div>
            </div>
        </div>
    </section>

    <!-- Tenants -->
    <section v-if="tab === 'tenants'" class="space-y-4">
        <form v-if="canManage" class="card grid gap-3 md:grid-cols-3" @submit.prevent="submit(tenant, '/admin/rentals/tenants')">
            <h2 class="card-title md:col-span-3">تسجيل مستأجر</h2>
            <select v-model="tenant.type" class="field" aria-label="نوع المستأجر"><option v-for="(label, key) in tenantTypes" :key="key" :value="key">{{ label }}</option></select>
            <input v-model="tenant.name" class="field" required placeholder="الاسم">
            <input v-model="tenant.identity_number" class="field" placeholder="رقم الهوية أو التسجيل">
            <input v-model="tenant.representative" class="field" placeholder="الممثل المفوض">
            <input v-model="tenant.phone" class="field" placeholder="الهاتف الأول" dir="ltr">
            <input v-model="tenant.phone2" class="field" placeholder="الهاتف الثاني" dir="ltr">
            <input v-model="tenant.email" type="email" class="field" placeholder="البريد" dir="ltr">
            <input v-model="tenant.activity" class="field md:col-span-2" placeholder="طبيعة النشاط">
            <GeographicAddress class="md:col-span-3" :model-value="tenant" :errors="tenant.errors" optional @update:model-value="Object.assign(tenant, $event)" />
            <p v-if="tenant.errors.identity_number" class="field-error md:col-span-3">{{ tenant.errors.identity_number }}</p>
            <button class="btn btn-primary" :disabled="tenant.processing">حفظ المستأجر</button>
        </form>
        <div class="card overflow-x-auto">
            <h3 class="card-title mb-3">سجل المستأجرين</h3>
            <table v-if="tenants.length" class="data-table">
                <thead><tr><th>الاسم</th><th>النوع</th><th>الهوية / التسجيل</th><th>الهاتف</th><th>العقود</th><th></th></tr></thead>
                <tbody>
                    <template v-for="t in tenants" :key="t.id">
                        <tr><td>{{ t.name }}</td><td>{{ tenantTypes[t.type] }}</td><td dir="ltr">{{ t.identity_number || '—' }}</td><td dir="ltr">{{ t.phone || '—' }}</td>
                            <td>{{ contracts.filter(c => Number(c.tenant_id) === Number(t.id)).length }}</td>
                            <td><button v-if="canManage" type="button" class="btn btn-secondary btn-sm" @click="editTenant(t)">تعديل</button></td></tr>
                        <tr v-if="editingTenant === t.id"><td colspan="6">
                            <form class="grid gap-2 md:grid-cols-3" @submit.prevent="tenantEdit.put(`/admin/rentals/tenants/${t.id}`, { preserveScroll: true, onSuccess: () => (editingTenant = null) })">
                                <select v-model="tenantEdit.type" class="field" aria-label="النوع"><option v-for="(label, key) in tenantTypes" :key="key" :value="key">{{ label }}</option></select>
                                <input v-model="tenantEdit.name" class="field" required aria-label="الاسم">
                                <input v-model="tenantEdit.identity_number" class="field" placeholder="رقم الهوية أو التسجيل">
                                <input v-model="tenantEdit.representative" class="field" placeholder="الممثل">
                                <input v-model="tenantEdit.phone" class="field" placeholder="الهاتف الأول" dir="ltr">
                                <input v-model="tenantEdit.phone2" class="field" placeholder="الهاتف الثاني" dir="ltr">
                                <input v-model="tenantEdit.email" type="email" class="field" placeholder="البريد" dir="ltr">
                                <input v-model="tenantEdit.activity" class="field md:col-span-2" placeholder="طبيعة النشاط">
                                <GeographicAddress class="md:col-span-3" :model-value="tenantEdit" :errors="tenantEdit.errors" optional @update:model-value="Object.assign(tenantEdit, $event)" />
                                <p v-for="(message, key) in tenantEdit.errors" :key="key" class="field-error md:col-span-3">{{ message }}</p>
                                <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="tenantEdit.processing">حفظ</button><button type="button" class="btn btn-secondary btn-sm" @click="editingTenant = null">إلغاء</button></div>
                            </form>
                            <RentalFiles class="mt-3" owner="tenant" :owner-id="t.id" :documents="registryDocuments" :types="options.rental_document_types" :can-upload="canManage" />
                        </td></tr>
                    </template>
                </tbody>
            </table>
            <EmptyState v-else title="لا يوجد مستأجرون" />
        </div>
    </section>

    <!-- Applications -->
    <section v-if="tab === 'applications'" class="space-y-4">
        <form v-if="canManage" class="card space-y-2" @submit.prevent="application.post('/admin/rentals/applications')">
            <h3 class="card-title">طلب استئجار ودراسة (يُحفظ مسودة ثم يُرسل للمدير التنفيذي من صفحته)</h3>
            <div class="grid gap-2 md:grid-cols-2">
                <select v-model="application.tenant_id" required class="field" aria-label="المستأجر"><option value="">المستأجر</option><option v-for="t in tenants" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                <select v-model="application.unit_id" class="field" aria-label="العين المقترحة"><option value="">العين المقترحة (اختياري)</option><option v-for="u in lettableUnits" :key="u.id" :value="u.id">{{ unitLabel(u) }} — {{ labels.units[u.availability] }}</option></select>
            </div>
            <textarea v-model="application.purpose" class="field" rows="2" placeholder="طبيعة العمل والغرض من الاستخدام واحتياجات الموقع"></textarea>
            <textarea v-model="application.study" class="field" rows="3" placeholder="تقرير الدراسة: الاحتياجات والمخاطر والملاحظات والتوصية"></textarea>
            <p v-for="(message, key) in application.errors" :key="key" class="field-error">{{ message }}</p>
            <button :disabled="application.processing" class="btn btn-primary">حفظ المسودة</button>
        </form>
        <div class="card overflow-x-auto">
            <table v-if="applications.length" class="data-table">
                <thead><tr><th>الرقم</th><th>المستأجر</th><th>العين</th><th>الحالة</th><th>المرحلة</th><th></th></tr></thead>
                <tbody><tr v-for="a in applications" :key="a.id">
                    <td dir="ltr">{{ a.reference_no || `#${a.id}` }}</td><td>{{ nameOf(tenants, a.tenant_id) }}</td><td>{{ a.unit_id ? unitLabel(byId(units, a.unit_id)) : '—' }}</td>
                    <td><StatusBadge :label="labels.applications[a.status] || a.status" :status="a.status" /></td><td class="text-xs">{{ a.step || '—' }}</td>
                    <td><Link :href="record('application', a.id)" class="btn btn-secondary btn-sm">فتح</Link></td>
                </tr></tbody>
            </table>
            <EmptyState v-else title="لا توجد طلبات استئجار" />
        </div>
    </section>

    <!-- Contracts -->
    <section v-if="tab === 'contracts'" class="space-y-4">
        <form v-if="canManage" class="card space-y-3" @submit.prevent="contract.post('/admin/rentals/contracts')">
            <h3 class="card-title">إعداد عقد (يُحفظ مسودة ثم يُرفع للاعتماد من صفحته)</h3>
            <div class="grid gap-2 md:grid-cols-3">
                <label :class="field">نوع العقد<select v-model="contract.kind" class="field mt-1"><option v-for="(label, key) in labels.kinds" :key="key" :value="key">{{ label }}</option></select></label>
                <label v-if="contract.kind !== 'new'" :class="field">العقد الأصلي<select v-model="contract.parent_contract_id" class="field mt-1" required><option value="">اختر</option><option v-for="c in occupying" :key="c.id" :value="c.id">{{ c.number }} — {{ nameOf(tenants, c.tenant_id) }}</option></select></label>
                <label :class="field">رقم العقد<input v-model="contract.number" class="field mt-1" required dir="ltr"></label>
                <label :class="field">المستأجر<select v-model="contract.tenant_id" class="field mt-1" required><option value="">اختر</option><option v-for="t in tenants" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
                <label v-if="contract.kind === 'new'" :class="field">طلب الاستئجار المقبول (اختياري)<select v-model="contract.application_id" class="field mt-1"><option value="">بدون</option><option v-for="a in applications.filter(x => x.status === 'approved')" :key="a.id" :value="a.id">{{ a.reference_no || `#${a.id}` }} — {{ nameOf(tenants, a.tenant_id) }}</option></select></label>
                <label :class="field">تاريخ تحرير العقد<input v-model="contract.issued_on" type="date" class="field mt-1"></label>
                <label :class="field">{{ contract.kind === 'amendment' ? 'تاريخ سريان الملحق' : 'بداية العقد' }}<input v-model="contract.starts_on" type="date" required class="field mt-1"></label>
                <label :class="field">نهاية العقد<input v-model="contract.ends_on" type="date" required class="field mt-1"></label>
                <label :class="field">العملة<select v-model="contract.currency_code" class="field mt-1" dir="ltr"><option v-for="c in currencies" :key="c" :value="c">{{ c }}</option></select></label>
                <label :class="field">دورية السداد<select v-model="contract.frequency" class="field mt-1"><option value="monthly">شهري</option><option value="quarterly">ربع سنوي</option><option value="semiannual">نصف سنوي</option><option value="annual">سنوي</option><option value="custom">جدول مخصص</option></select></label>
                <label :class="field">قيمة الإيجار لكل دفعة<input v-model="contract.total_rent" type="number" step="0.01" min="0.01" class="field mt-1" required></label>
                <label class="flex items-center gap-2 text-sm"><input v-model="contract.auto_renew" type="checkbox"> بند تجديد تلقائي</label>
            </div>
            <textarea v-if="contract.kind !== 'new'" v-model="contract.change_reason" class="field" rows="2" required placeholder="سبب التجديد أو التعديل"></textarea>
            <textarea v-model="contract.renewal_clause" class="field" rows="2" placeholder="نص بند التجديد"></textarea>
            <textarea v-model="contract.terms" class="field" rows="3" placeholder="الشروط"></textarea>
            <h4 class="font-bold">الأعيان وحصصها من قيمة الدفعة</h4>
            <div v-for="(item, i) in contract.items" :key="i" class="flex flex-wrap gap-2">
                <select v-model="item.unit_id" class="field flex-1" required aria-label="العين"><option value="">اختر عينًا</option><option v-for="u in contractUnitOptions" :key="u.id" :value="u.id">{{ unitLabel(u) }} — {{ labels.units[u.availability] }}</option></select>
                <input v-model="item.rent_share" type="number" min="0.01" step="0.01" class="field w-36" placeholder="حصة العين" required>
                <input v-model="item.rent_per_m2" type="number" min="0" step="0.01" class="field w-36" placeholder="إيجار المتر">
                <button type="button" class="btn btn-secondary btn-sm" :disabled="contract.items.length === 1" @click="contract.items.splice(i, 1)">حذف</button>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" @click="contract.items.push({ unit_id: '', rent_share: '', rent_per_m2: '' })">+ عين أخرى</button>
            <h4 class="font-bold">الخدمات</h4>
            <div v-for="(service, i) in contract.services" :key="`s${i}`" class="flex flex-wrap gap-2">
                <select v-model="service.service_value_id" class="field flex-1" required aria-label="الخدمة"><option value="">الخدمة</option><option v-for="o in options.rental_services" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                <select v-model="service.billing" class="field w-48" aria-label="طريقة الاحتساب"><option value="included">مرصدة ضمن العقد</option><option value="separate">مدفوعة منفصلة لكل دفعة</option></select>
                <input v-if="service.billing === 'separate'" v-model="service.amount" type="number" min="0.01" step="0.01" class="field w-36" placeholder="قيمة الخدمة" required>
                <button type="button" class="btn btn-secondary btn-sm" @click="contract.services.splice(i, 1)">حذف</button>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" @click="contract.services.push({ service_value_id: '', billing: 'included', amount: '', notes: '' })">+ خدمة</button>
            <p v-for="(message, key) in contract.errors" :key="key" class="field-error">{{ message }}</p>
            <div><button class="btn btn-primary" :disabled="contract.processing">حفظ المسودة</button></div>
        </form>
        <div class="card overflow-x-auto">
            <table v-if="contracts.length" class="data-table">
                <thead><tr><th>العقد</th><th>النوع</th><th>المستأجر</th><th>الأعيان</th><th>المدة</th><th>الدفعة</th><th>الحالة</th><th></th></tr></thead>
                <tbody><tr v-for="c in contracts" :key="c.id">
                    <td dir="ltr">{{ c.number }}</td><td>{{ labels.kinds[c.kind] }}</td><td>{{ nameOf(tenants, c.tenant_id) }}</td><td class="text-xs">{{ unitsOf(c.id) }}</td>
                    <td class="text-xs">{{ c.starts_on }} ← {{ c.ends_on }}</td><td>{{ money(c.total_rent) }} {{ c.currency_code }}</td>
                    <td><StatusBadge :label="labels.contracts[c.status] || c.status" :status="c.status" /><p v-if="c.step" class="text-[11px] text-slate-500">{{ c.step }}</p></td>
                    <td class="space-x-1 space-x-reverse whitespace-nowrap">
                        <Link :href="record('contract', c.id)" class="btn btn-secondary btn-sm">فتح</Link>
                        <button v-if="canManage && ['active', 'expired'].includes(c.status)" type="button" class="btn btn-secondary btn-sm" @click="renew(c, 'renewal')">تجديد</button>
                        <button v-if="canManage && c.status === 'active'" type="button" class="btn btn-secondary btn-sm" @click="renew(c, 'amendment')">ملحق</button>
                    </td>
                </tr></tbody>
            </table>
            <EmptyState v-else title="لا توجد عقود" />
        </div>
    </section>

    <!-- Dues -->
    <section v-if="tab === 'dues'" class="card space-y-3 overflow-x-auto">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="card-title">الاستحقاقات والأرصدة</h3>
            <select v-model="dueFilter" class="field w-auto" aria-label="تصفية"><option value="open">غير المسددة</option><option value="overdue">المتأخرة</option><option value="paid">المسددة</option><option value="all">الكل</option></select>
        </div>
        <p class="text-xs text-gold-800">القبض يسجله قسم الحسابات من البرنامج المحاسبي؛ لا يُدخل قبض هنا. أي إعفاء أو خصم أو تعديل يمر بطلب تسوية معتمد.</p>
        <table v-if="shownDues.length" class="data-table">
            <thead><tr><th>العقد</th><th>الاستحقاق</th><th>البند</th><th>المستحق الأصلي</th><th>المقبوض</th><th>التسويات (−/+)</th><th>المتبقي</th><th>الحالة</th><th></th></tr></thead>
            <tbody><tr v-for="d in shownDues" :key="d.id">
                <td dir="ltr">{{ d.contract_number }}</td><td>{{ d.due_on }}</td><td>{{ d.kind === 'service' ? optionName('rental_services', d.service_value_id) : 'إيجار' }}</td>
                <td>{{ money(d.amount) }} <span dir="ltr" class="text-xs">{{ d.currency_code }}</span></td><td>{{ money(d.paid) }}</td>
                <td>{{ money(d.reduced) }} / {{ money(d.increased) }}</td><td class="font-semibold">{{ money(d.remaining) }}</td>
                <td><StatusBadge :status="d.overdue ? 'overdue' : d.payment_state" /></td>
                <td><button v-if="canManage && d.status !== 'cancelled'" type="button" class="btn btn-secondary btn-sm" @click="settle(d)">طلب تسوية</button></td>
            </tr></tbody>
        </table>
        <EmptyState v-else title="لا توجد استحقاقات في هذا التصنيف" />
    </section>

    <!-- Settlements -->
    <section v-if="tab === 'settlements'" class="space-y-4">
        <form v-if="canManage" class="card grid gap-2 md:grid-cols-2" @submit.prevent="settlement.post('/admin/rentals/settlements')">
            <h3 class="card-title md:col-span-2">طلب تسوية (إعفاء أو خصم أو تصحيح) — يمر بالحسابات ثم المدير التنفيذي ثم الترحيل</h3>
            <select v-model="settlement.due_id" class="field" required aria-label="الاستحقاق"><option value="">الاستحقاق</option><option v-for="d in dues.filter(x => x.status !== 'cancelled')" :key="d.id" :value="d.id">{{ d.contract_number }} — {{ d.due_on }} — متبقٍ {{ money(d.remaining) }} {{ d.currency_code }}</option></select>
            <select v-model="settlement.type_value_id" class="field" required aria-label="نوع التسوية"><option value="">نوع التسوية</option><option v-for="o in options.rental_settlement_types" :key="o.id" :value="o.id">{{ o.name }}</option></select>
            <select v-model="settlement.effect" class="field" aria-label="أثر التسوية"><option v-for="(label, key) in labels.effects" :key="key" :value="key">{{ label }}</option></select>
            <input v-model="settlement.amount" type="number" min="0.01" step="0.01" class="field" required placeholder="المبلغ">
            <textarea v-model="settlement.reason" class="field md:col-span-2" rows="2" required placeholder="السبب والمرجع"></textarea>
            <p v-for="(message, key) in settlement.errors" :key="key" class="field-error md:col-span-2">{{ message }}</p>
            <div><button class="btn btn-primary" :disabled="settlement.processing">حفظ المسودة</button></div>
        </form>
        <div class="card overflow-x-auto">
            <table v-if="settlements.length" class="data-table">
                <thead><tr><th>الرقم</th><th>العقد</th><th>النوع</th><th>الأثر</th><th>المبلغ</th><th>الحالة</th><th></th></tr></thead>
                <tbody><tr v-for="s in settlements" :key="s.id">
                    <td dir="ltr">{{ s.reference_no || `#${s.id}` }}</td><td dir="ltr">{{ nameOf(contracts, s.contract_id, 'number') }}</td><td>{{ optionName('rental_settlement_types', s.type_value_id) }}</td>
                    <td>{{ s.effect === 'reduce' ? 'تخفيض' : 'زيادة' }}</td><td>{{ money(s.amount) }} {{ s.currency_code }}</td>
                    <td><StatusBadge :label="labels.settlements[s.status] || s.status" :status="s.status" /><p v-if="s.step" class="text-[11px] text-slate-500">{{ s.step }}</p></td>
                    <td><Link :href="record('settlement', s.id)" class="btn btn-secondary btn-sm">فتح</Link></td>
                </tr></tbody>
            </table>
            <EmptyState v-else title="لا توجد تسويات" />
        </div>
    </section>

    <!-- Terminations and evictions -->
    <section v-if="tab === 'cases'" class="space-y-4">
        <form v-if="canManage" class="card grid gap-2 md:grid-cols-2" @submit.prevent="termination.post('/admin/rentals/terminations')">
            <h3 class="card-title md:col-span-2">طلب إنهاء وإخلاء — فحص، ثم مخالصة الحسابات، ثم قرار المدير التنفيذي، ثم محضر الاستلام</h3>
            <select v-model="termination.contract_id" class="field" required aria-label="العقد"><option value="">العقد</option><option v-for="c in occupying" :key="c.id" :value="c.id">{{ c.number }} — {{ nameOf(tenants, c.tenant_id) }} ({{ labels.contracts[c.status] }})</option></select>
            <select v-model="termination.termination_reason_value_id" class="field" required aria-label="سبب الإنهاء"><option value="">سبب الإنهاء</option><option v-for="o in options.rental_termination_reasons" :key="o.id" :value="o.id">{{ o.name }}</option></select>
            <label :class="field">تاريخ المغادرة<input v-model="termination.departure_on" type="date" required class="field mt-1"></label>
            <textarea v-model="termination.reason" class="field" rows="2" required placeholder="البيان (طلب المستأجر، عدم التجديد…)"></textarea>
            <textarea v-model="termination.details" class="field md:col-span-2" rows="2" placeholder="تفاصيل إضافية"></textarea>
            <p v-for="(message, key) in termination.errors" :key="key" class="field-error md:col-span-2">{{ message }}</p>
            <div><button class="btn btn-primary" :disabled="termination.processing">حفظ المسودة</button></div>
        </form>
        <div class="card overflow-x-auto">
            <table v-if="cases.length" class="data-table">
                <thead><tr><th>الرقم</th><th>العقد</th><th>المغادرة</th><th>الحالة</th><th></th></tr></thead>
                <tbody><tr v-for="k in cases" :key="k.id">
                    <td dir="ltr">{{ k.reference_no || `#${k.id}` }}</td><td dir="ltr">{{ nameOf(contracts, k.contract_id, 'number') }}</td><td>{{ k.departure_on || '—' }}</td>
                    <td><StatusBadge :label="labels.cases[k.status] || k.status" :status="k.status" /><p v-if="k.step" class="text-[11px] text-slate-500">{{ k.step }}</p></td>
                    <td><Link :href="record('case', k.id)" class="btn btn-secondary btn-sm">فتح</Link></td>
                </tr></tbody>
            </table>
            <EmptyState v-else title="لا توجد معاملات إنهاء" />
        </div>
    </section>

    <!-- Meters -->
    <section v-if="tab === 'meters'" class="space-y-4">
        <form v-if="canManage" class="card grid gap-2 md:grid-cols-3" @submit.prevent="submit(reading, '/admin/rentals/readings', { forceFormData: true })">
            <h3 class="card-title md:col-span-3">إدخال قراءة عداد</h3>
            <select v-model="reading.purpose" class="field" aria-label="الغرض"><option v-for="(label, key) in purposes" :key="key" :value="key">{{ label }}</option></select>
            <select v-model="reading.unit_id" class="field" required aria-label="العين"><option value="">العين</option><option v-for="u in units" :key="u.id" :value="u.id">{{ unitLabel(u) }}</option></select>
            <select v-model="reading.contract_id" class="field" :required="reading.purpose === 'handover'" aria-label="العقد"><option value="">العقد{{ reading.purpose === 'handover' ? '' : ' (اختياري)' }}</option><option v-for="c in contracts" :key="c.id" :value="c.id">{{ c.number }}</option></select>
            <select v-if="reading.purpose === 'eviction'" v-model="reading.case_id" class="field" required aria-label="معاملة الإنهاء"><option value="">معاملة الإنهاء</option><option v-for="k in cases" :key="k.id" :value="k.id">{{ k.reference_no || `#${k.id}` }}</option></select>
            <input v-model="reading.meter_kind" class="field" placeholder="نوع العداد" required>
            <input v-model="reading.reading" type="number" min="0" step="0.001" class="field" placeholder="القراءة" required>
            <input v-model="reading.read_on" type="date" class="field" required aria-label="تاريخ القراءة">
            <label :class="field">صورة العداد<input type="file" accept="image/*,.pdf" class="field mt-1" @input="reading.photo = $event.target.files[0]"></label>
            <p v-for="(message, key) in reading.errors" :key="key" class="field-error md:col-span-3">{{ message }}</p>
            <div><button class="btn btn-primary" :disabled="reading.processing">حفظ</button></div>
        </form>
        <div class="card overflow-x-auto">
            <table v-if="readings.length" class="data-table">
                <thead><tr><th>العين</th><th>الغرض</th><th>العداد</th><th>القراءة</th><th>التاريخ</th><th>أدخلها</th></tr></thead>
                <tbody><tr v-for="m in readings" :key="m.id"><td>{{ unitLabel(byId(units, m.unit_id)) }}</td><td>{{ purposes[m.purpose] }}</td><td>{{ m.meter_kind }}</td><td>{{ m.reading }}</td><td>{{ m.read_on }}</td><td>{{ m.recorded_by_name || '—' }}</td></tr></tbody>
            </table>
            <EmptyState v-else title="لا توجد قراءات" />
        </div>
    </section>

    <!-- Notices -->
    <section v-if="tab === 'notices'" class="space-y-4">
        <form v-if="canManage" class="card space-y-2" @submit.prevent="submit(notice, '/admin/rentals/notices')">
            <h3 class="card-title">مسودة إنذار أو تنبيه</h3>
            <div class="grid gap-2 md:grid-cols-2">
                <select v-model="notice.contract_id" class="field" required aria-label="العقد"><option value="">العقد</option><option v-for="c in contracts" :key="c.id" :value="c.id">{{ c.number }}</option></select>
                <select v-model="notice.kind" class="field" aria-label="النوع"><option v-for="(label, key) in noticeKinds" :key="key" :value="key">{{ label }}</option></select>
            </div>
            <textarea v-model="notice.body" class="field" rows="3" required placeholder="نص الإنذار"></textarea>
            <button class="btn btn-primary" :disabled="notice.processing">حفظ المسودة</button>
        </form>
        <p class="text-sm text-gold-800">يُسلَّم الإنذار للمستأجر خارج النظام؛ «تسجيل الإصدار» يثبت تاريخ تسليمه.</p>
        <div v-for="n in notices" :key="n.id" class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-3">
            <strong>{{ noticeKinds[n.kind] || n.kind }}</strong><StatusBadge :status="n.status" /><span dir="ltr" class="text-xs">{{ nameOf(contracts, n.contract_id, 'number') }}</span>
            <span class="flex-1 text-sm">{{ n.body }}</span><span v-if="n.sent_at" class="text-xs text-slate-500">{{ n.sent_at }}</span>
            <button v-if="canManage && n.status === 'draft'" type="button" class="btn btn-secondary btn-sm" @click="issue(n.id)">تسجيل الإصدار</button>
        </div>
    </section>
</div>
</AppLayout>
</template>
