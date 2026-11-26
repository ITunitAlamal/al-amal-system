<script setup>
import { ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import BasicPanel from '../../../Components/Registry/BasicPanel.vue';
import AddressesPanel from '../../../Components/Registry/AddressesPanel.vue';
import CarePanel from '../../../Components/Registry/CarePanel.vue';
import EconomicPanel from '../../../Components/Registry/EconomicPanel.vue';
import DocumentsPanel from '../../../Components/Registry/DocumentsPanel.vue';
import HouseholdPanel from '../../../Components/Registry/HouseholdPanel.vue';
import GuardianshipsPanel from '../../../Components/Registry/GuardianshipsPanel.vue';

const props = defineProps({ guardian: Object, person: Object, children: Array, households: Array, addresses: Array, economic: Array, care: Array, documents: Array, history: Array, modules: Array,
    options: Object, abilities: Object, tab: String });
const tabs = { basic: 'البيانات الأساسية', children: 'الأيتام المرتبطون', social: 'الوضع الاجتماعي والاقتصادي', residence: 'السكن والنزوح', documents: 'المستندات', requests: 'الطلبات وسجل التغييرات' };
const tab = ref(tabs[props.tab] ? props.tab : 'basic');
watch(tab, value => { const url = new URL(window.location.href); url.searchParams.set('tab', value); window.history.replaceState(window.history.state, '', url); });
const form = useForm({ status: props.guardian.status, notes: props.guardian.notes || '' });
const editing = ref(false);
const requests = () => props.modules.filter(m => m.tab === 'requests');
</script>

<template>
    <AppLayout :title="`ملف الوصي ${person.full_name}`">
        <div class="mx-auto max-w-7xl space-y-5">
            <header class="card flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0 space-y-2">
                    <Link href="/orphans/registry?view=guardians" class="text-sm text-blue-700 hover:underline">← الأوصياء</Link>
                    <h1 class="text-2xl font-bold text-navy-800">{{ person.full_name }}</h1>
                    <p class="text-sm text-slate-600"><span class="font-mono" dir="ltr">{{ guardian.reference_no }}</span> · <span class="font-mono" dir="ltr">{{ person.id_masked }}</span> · {{ person.phone || 'لا يوجد جوال' }}</p>
                    <div class="flex flex-wrap gap-1"><StatusBadge :label="guardian.status === 'active' ? 'نشط' : 'غير نشط'" :tone="guardian.status === 'active' ? 'success' : 'muted'" />
                        <StatusBadge :label="guardian.portal ? `حساب البوابة: ${guardian.portal.account_label}` : 'لا يوجد حساب بوابة'" :tone="guardian.portal?.account_status === 'approved' ? 'success' : 'muted'" />
                        <StatusBadge :label="`أيتام مرتبطون: ${children.filter(c => ['claimed', 'pending', 'approved'].includes(c.status)).length}`" tone="info" /></div>
                    <p class="text-xs text-slate-500">اعتماد حساب البوابة لا يفتح ملف أي طفل؛ الوصول يتطلب وصاية معتمدة ونافذة لكل طفل.</p>
                </div>
            </header>
            <nav class="flex gap-1 overflow-x-auto" role="tablist" aria-label="أقسام ملف الوصي">
                <button v-for="(label, key) in tabs" :key="key" type="button" role="tab" :aria-selected="tab === key" :class="['btn btn-sm shrink-0', tab === key ? 'btn-primary' : 'btn-secondary']" @click="tab = key">{{ label }}</button>
            </nav>

            <section v-if="tab === 'basic'" class="card space-y-4" role="tabpanel">
                <BasicPanel :person="person" :options="options" :abilities="abilities" :show-status="false" />
                <div class="border-t border-slate-100 pt-4">
                    <div class="flex items-center justify-between"><h3 class="card-title">سجل الوصي</h3><button v-if="abilities.edit && !editing" type="button" class="btn btn-sm btn-secondary" @click="editing = true">تعديل</button></div>
                    <p v-if="!editing" class="mt-2 text-sm text-slate-700">{{ guardian.notes || 'لا ملاحظات.' }}</p>
                    <form v-else class="mt-2 grid gap-3 sm:grid-cols-2" @submit.prevent="form.put(`/orphans/registry/guardians/${guardian.public_id}`, { preserveScroll: true, onSuccess: () => { editing = false; } })">
                        <label class="text-sm">الحالة<select v-model="form.status" class="field mt-1"><option value="active">نشط</option><option value="inactive">غير نشط</option></select></label>
                        <label class="text-sm sm:col-span-2">ملاحظات<textarea v-model="form.notes" class="field mt-1" rows="2" maxlength="2000" /></label>
                        <div class="flex gap-2"><button type="submit" class="btn btn-primary" :disabled="form.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="editing = false">إلغاء</button></div>
                    </form>
                </div>
            </section>
            <section v-if="tab === 'children'" class="card space-y-3" role="tabpanel">
                <div class="flex items-center justify-between"><h3 class="card-title">الأيتام المرتبطون وحالة كل ارتباط</h3>
                    <Link v-if="abilities.edit" :href="`/orphans/registry/orphans/create?guardian=${guardian.public_id}`" class="btn btn-sm btn-primary">إضافة يتيم لهذا الوصي</Link></div>
                <GuardianshipsPanel :links="children" :options="options" :abilities="abilities" from-guardian />
            </section>
            <section v-if="tab === 'social'" class="space-y-4" role="tabpanel">
                <div class="card space-y-3"><h3 class="card-title">الوضع الاقتصادي</h3><EconomicPanel :person-id="person.public_id" :items="economic" :options="options" :can-edit="abilities.edit" /></div>
                <div class="card space-y-3"><h3 class="card-title">الأسر المرتبط بها</h3><HouseholdPanel :person-id="person.public_id" :households="households" :options="options" :can-edit="abilities.edit" /></div>
                <div class="card space-y-3"><h3 class="card-title">احتياجات تؤثر على الرعاية (عند الحاجة فقط)</h3><CarePanel :person-id="person.public_id" :items="care" :options="options" :can-edit="abilities.edit" :clinical="abilities.health" /></div>
            </section>
            <section v-if="tab === 'residence'" class="card space-y-3" role="tabpanel"><h3 class="card-title">السكن والنزوح</h3>
                <AddressesPanel :person-id="person.public_id" :addresses="addresses" :options="options" :can-edit="abilities.edit" /></section>
            <section v-if="tab === 'documents'" class="card space-y-3" role="tabpanel"><h3 class="card-title">المستندات</h3>
                <DocumentsPanel :person-id="person.public_id" :items="documents" :options="options" :can-edit="abilities.edit" :can-review="abilities.portal" :can-restrict="abilities.health" /></section>
            <section v-if="tab === 'requests'" class="card space-y-3" role="tabpanel"><h3 class="card-title">الطلبات وسجل التغييرات</h3>
                <div v-for="m in requests()" :key="m.key" class="space-y-1"><h4 class="font-semibold text-navy-800">{{ m.title }}</h4>
                    <ul class="space-y-1 text-sm"><li v-for="(item, i) in m.items" :key="i">{{ item.text }}</li></ul><p v-if="!m.items.length" class="text-sm text-slate-500">{{ m.empty }}</p></div>
                <ul class="space-y-1 text-sm text-slate-700"><li v-for="(h, i) in history" :key="i">{{ h.created_at?.slice(0, 16) }} · {{ h.actor || 'النظام' }} · {{ h.action }}</li></ul>
                <p v-if="!history.length && !requests().length" class="text-sm text-slate-500">لا يوجد سجل.</p></section>
        </div>
    </AppLayout>
</template>
