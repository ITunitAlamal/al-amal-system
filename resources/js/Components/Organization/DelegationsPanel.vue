<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';
import { kpiRequest } from '../../Support/kpiHttp.js';

// Time-limited delegation of one permission inside one department or unit: preview first, then confirm.
const props = defineProps({
    delegations: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({ permissions: [], scopes: [] }) },
});
const tones = { 'ساري': 'success', 'لم يبدأ': 'info', 'منتهٍ': 'muted', 'ملغى': 'muted' };
const today = new Date().toLocaleDateString('en-CA');
const form = useForm({ permission_code: '', scope_department_id: '', delegate_user_id: '', starts_at: today, ends_at: '', reason: '' });
const scope = computed(() => props.options.scopes.find(item => String(item.id) === String(form.scope_department_id)) || null);
const permission = computed(() => props.options.permissions.find(item => item.code === form.permission_code) || null);
const preview = ref(null);
const previewError = ref('');
const checking = ref(false);
watch(() => [form.permission_code, form.scope_department_id, form.delegate_user_id, form.starts_at, form.ends_at], () => { preview.value = null; });
watch(() => form.scope_department_id, () => { form.delegate_user_id = ''; });

async function check() {
    checking.value = true;
    previewError.value = '';
    try {
        preview.value = await kpiRequest('/my-department/delegations/preview', { ...form.data() });
    } catch (error) {
        preview.value = null;
        previewError.value = error.message;
    } finally {
        checking.value = false;
    }
}
function confirm() {
    form.post('/my-department/delegations', { preserveScroll: true, onSuccess: () => { form.reset(); preview.value = null; } });
}
function revoke(row) {
    const reason = window.prompt(`سبب إلغاء تفويض «${row.permission}» لـ ${row.delegate}:`);
    if (reason && reason.trim().length >= 3) {
        router.post(`/my-department/delegations/${row.id}/revoke`, { reason }, { preserveScroll: true });
    }
}
const day = value => String(value || '').slice(0, 10);
</script>

<template>
    <div class="space-y-4">
        <section v-if="options.permissions.length" class="card space-y-3">
            <h2 class="card-title">تفويض صلاحية مؤقتة</h2>
            <p class="text-xs leading-5 text-slate-500">تفوّض صلاحية تملكها أنت، داخل قسمك أو إحدى وحداته، لمدة محددة تنتهي تلقائيًا. لا يستطيع المفوَّض إعادة تفويضها، ويسقط التفويض إذا فقدتَ الصلاحية.</p>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="text-sm font-semibold">الصلاحية
                    <select v-model="form.permission_code" class="field mt-1 w-full"><option value="">اختر</option><option v-for="item in options.permissions" :key="item.code" :value="item.code">{{ item.name }}</option></select>
                </label>
                <label class="text-sm font-semibold">النطاق
                    <select v-model="form.scope_department_id" class="field mt-1 w-full"><option value="">اختر</option><option v-for="item in options.scopes" :key="item.id" :value="item.id">{{ item.type }}: {{ item.name }}</option></select>
                </label>
                <label class="text-sm font-semibold">المفوَّض
                    <select v-model="form.delegate_user_id" class="field mt-1 w-full" :disabled="!scope"><option value="">{{ scope && !scope.people.length ? 'لا يوجد موظفون بحسابات في هذا النطاق' : 'اختر' }}</option><option v-for="person in scope?.people || []" :key="person.id" :value="person.id">{{ person.name }}</option></select>
                </label>
                <label class="text-sm font-semibold">من<input v-model="form.starts_at" type="date" :min="today" class="field mt-1 w-full" /></label>
                <label class="text-sm font-semibold">حتى<input v-model="form.ends_at" type="date" :min="form.starts_at || today" class="field mt-1 w-full" /></label>
                <p v-if="permission" class="self-end text-xs text-slate-500">أقصى مدة: {{ permission.max_days }} يومًا. {{ permission.note }}</p>
                <label class="text-sm font-semibold md:col-span-3">السبب<textarea v-model="form.reason" rows="2" maxlength="1000" class="field mt-1 w-full" placeholder="مثل: تغطية إجازة رئيس القسم"></textarea></label>
            </div>
            <div v-if="preview" class="rounded-xl border p-3 text-sm" :class="preview.errors.length ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-900'">
                <template v-if="preview.errors.length"><p v-for="error in preview.errors" :key="error">{{ error }}</p></template>
                <template v-else><p class="font-semibold">سيُسجَّل التفويض كالتالي:</p><ul class="mt-1 list-inside list-disc"><li v-for="line in preview.lines" :key="line">{{ line }}</li></ul></template>
            </div>
            <p v-if="previewError" class="text-sm text-red-700" role="alert">{{ previewError }}</p>
            <p v-for="(message, key) in form.errors" :key="key" class="text-sm text-red-700" role="alert">{{ message }}</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-secondary" :disabled="checking || !form.permission_code || !form.scope_department_id || !form.delegate_user_id || !form.ends_at" @click="check">{{ checking ? 'جارٍ التحقق…' : 'معاينة' }}</button>
                <button v-if="preview && !preview.errors.length" type="button" class="btn btn-primary" :disabled="form.processing || form.reason.trim().length < 5" @click="confirm">تأكيد التفويض</button>
            </div>
        </section>
        <p v-else class="card text-sm text-slate-500">لا توجد صلاحيات مفعّلة للتفويض في سياسة المؤسسة بعد، أو لا تملك تفويض ما هو مفعّل.</p>

        <section class="card">
            <h2 class="card-title">التفويضات</h2>
            <div v-if="delegations.length" class="aims-table-scroll mt-3">
                <table class="data-table w-full text-sm">
                    <thead><tr><th scope="col">الصلاحية</th><th scope="col">النطاق</th><th scope="col">المفوَّض</th><th scope="col">المفوِّض</th><th scope="col">المدة</th><th scope="col">الحالة</th><th scope="col"><span class="sr-only">إجراء</span></th></tr></thead>
                    <tbody>
                        <tr v-for="row in delegations" :key="row.id">
                            <td>{{ row.permission }}</td><td>{{ row.scope }}</td><td>{{ row.delegate }}</td><td>{{ row.delegator }}</td>
                            <td class="whitespace-nowrap" dir="ltr">{{ day(row.starts_at) }} → {{ day(row.ends_at) }}</td>
                            <td><StatusBadge :label="row.state" :tone="tones[row.state] || 'muted'" /></td>
                            <td><button v-if="row.can_revoke" type="button" class="text-sm text-red-700 hover:underline" @click="revoke(row)">إلغاء</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="mt-2 text-sm text-slate-500">لا توجد تفويضات.</p>
        </section>
    </div>
</template>
