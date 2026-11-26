<script setup>
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import CopyReference from '../../../Components/CopyReference.vue';
import EmployeeFileView from '../../../Components/Employee/EmployeeFileView.vue';
import { formatDateTime } from '../../../Support/dates.js';

/**
 * The employee file for HR, the executive director and the employee's department head. The server sends only
 * the sections this viewer may see; account actions are HR management only.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    sections: { type: Object, default: () => ({}) },
    labels: { type: Object, default: () => ({}) },
    qualifications: { type: Array, default: () => [] },
    placements: { type: Array, default: () => [] },
    photo: { type: Object, default: null },
    documents: { type: Object, default: null },
    documentTypes: { type: Array, default: () => [] },
    pay: { type: Object, default: null },
    bank: { type: Object, default: null },
    payChange: { type: Object, default: null },
    payChoices: { type: Object, default: null },
    leave: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    provenance: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
    fileLimits: { type: Object, default: () => ({}) },
    account: { type: Object, default: null },
    activation: { type: Object, default: null },
    accountCandidate: { type: Object, default: null },
    directCreate: { type: Boolean, default: false },
});

const page = usePage();
const base = '/admin/employee-profiles';
const flash = computed(() => page.props.flash?.success || '');
const accountError = computed(() => page.props.errors?.account || page.props.errors?.confirm || page.props.errors?.code || page.props.errors?.phone || '');
const view = computed(() => {
    const { account, activation, accountCandidate, directCreate, ...rest } = props;
    return rest;
});
const dateTime = value => formatDateTime(value);
const activationForm = useForm({});
const linkForm = useForm({ confirm: false });
const createForm = useForm({ confirm: false });
const confirmLink = ref(false);
const confirmCreate = ref(false);
const sendActivation = () => activationForm.post(`${base}/${props.employee.id}/activation-code`, { preserveScroll: true });
const directForm = useForm({ password: '', password_confirmation: '', confirm: false });
const activateDirect = () => directForm.post(`${base}/${props.employee.id}/activate-direct`, { preserveScroll: true, onSuccess: () => directForm.reset() });
function link() { linkForm.confirm = confirmLink.value; linkForm.post(`${base}/${props.employee.id}/link-account`, { preserveScroll: true }); }
function create() { createForm.confirm = confirmCreate.value; createForm.post(`${base}/${props.employee.id}/create-account`, { preserveScroll: true }); }
const directCreateForm = useForm({ confirm: false, activation_method: 'direct', temporary_password: '', temporary_password_confirmation: '' });
function createActive() { directCreateForm.post(`${base}/${props.employee.id}/create-account`, { preserveScroll: true, onSuccess: () => directCreateForm.reset() }); }
</script>

<template>
    <AppLayout :title="employee.display_name || 'ملف الموظف'">
        <div class="mx-auto max-w-6xl space-y-4">
            <Link v-if="can.manage" :href="base" class="text-sm font-semibold text-blue-700 hover:underline">→ ملفات الموظفين</Link>
            <p v-if="flash" class="alert-success">{{ flash }}</p>

            <EmployeeFileView v-bind="view">
                <template #actions>
                    <div class="flex flex-wrap gap-2">
                        <Link v-if="can.edit" :href="`${base}/${employee.id}/edit`" class="btn btn-primary">تعديل الملف</Link>
                        <Link v-if="can.manage" :href="`/leaves/balances?employee_id=${employee.id}`" class="btn btn-secondary">أرصدة الإجازات</Link>
                    </div>
                </template>
            </EmployeeFileView>

            <section v-if="can.manage" class="card space-y-3">
                <h2 class="card-title">حساب الدخول</h2>
                <div v-if="account" class="flex flex-wrap items-center gap-2 text-sm">
                    <StatusBadge :label="account.is_active ? 'مفعّل' : 'بانتظار التفعيل'" :tone="account.is_active ? 'success' : 'warning'" />
                    <CopyReference :value="account.employee_number" label="اسم المستخدم" />
                </div>
                <div v-if="activation" class="rounded-xl border border-gold-200 bg-gold-50 p-3 text-xs leading-6 text-slate-700">
                    <p v-if="activation.lastSentAt">أُرسل آخر رمز تفعيل في {{ dateTime(activation.lastSentAt) }}. يستخدمه الموظف من صفحة «تفعيل الحساب» خلال 5 دقائق.</p>
                    <p v-else-if="activation.lastRequestedAt">طُلب رمز في {{ dateTime(activation.lastRequestedAt) }} ولم يُؤكَّد إرساله بعد.</p>
                    <p v-else>لم يُرسل رمز تفعيل بعد.</p>
                    <button v-if="activation.smsEnabled" type="button" class="btn btn-sm btn-secondary mt-2" :disabled="activationForm.processing" @click="sendActivation">{{ activationForm.processing ? 'جارٍ الإرسال...' : (activation.lastRequestedAt ? 'إعادة إرسال رمز التفعيل' : 'إرسال رمز التفعيل') }}</button>
                    <p v-else class="mt-1 text-gold-800">خدمة SMS معطّلة حاليًا؛ لا يمكن إرسال الرمز.</p>
                    <form v-if="activation.directAllowed" class="mt-3 space-y-2 border-t border-gold-200 pt-3" @submit.prevent="activateDirect">
                        <p class="font-semibold text-slate-800">خيار ثانٍ لمدير النظام: تفعيل مباشر دون SMS</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input v-model="directForm.password" type="password" class="field" required minlength="10" maxlength="128" autocomplete="new-password" placeholder="كلمة مرور مؤقتة (10 أحرف على الأقل)" aria-label="كلمة مرور مؤقتة" />
                            <input v-model="directForm.password_confirmation" type="password" class="field" required minlength="10" maxlength="128" autocomplete="new-password" placeholder="تأكيد كلمة المرور المؤقتة" aria-label="تأكيد كلمة المرور المؤقتة" />
                        </div>
                        <label class="flex items-start gap-2"><input v-model="directForm.confirm" type="checkbox" class="mt-1" /> راجعت هوية الموظف وسأسلّمه كلمة المرور شخصيًا؛ يُلزَم بتغييرها عند أول دخول.</label>
                        <p v-if="directForm.errors.password" class="field-error">{{ directForm.errors.password }}</p>
                        <button type="submit" class="btn btn-sm btn-primary" :disabled="directForm.processing || !directForm.confirm">{{ directForm.processing ? 'جارٍ التفعيل...' : 'تفعيل الحساب الآن' }}</button>
                    </form>
                </div>
                <template v-if="!account">
                    <p class="text-sm text-slate-600">لا يوجد حساب دخول لهذا الملف. لا تُنشأ الحسابات تلقائيًا من الاستيراد؛ اختر إجراءً صريحًا بعد مراجعة الهوية.</p>
                    <div v-if="accountCandidate" class="rounded-xl border border-slate-200 p-3 text-sm">
                        <p>يوجد حساب بالرقم الوظيفي نفسه باسم «<strong>{{ accountCandidate.name }}</strong>» ({{ accountCandidate.is_active ? 'نشط' : 'غير نشط' }}).</p>
                        <p v-if="!accountCandidate.linkable" class="mt-1 text-red-700">الحساب مرتبط بملف آخر؛ راجع الرقم الوظيفي.</p>
                        <p v-else-if="!accountCandidate.name_matches" class="mt-1 text-gold-800">الاسم الأول واسم العائلة في الملف لا يطابقان اسم الحساب؛ صحّح أحدهما قبل الربط.</p>
                        <template v-else>
                            <label class="mt-2 flex items-start gap-2"><input v-model="confirmLink" type="checkbox" class="mt-1" /> راجعت هوية صاحب الحساب وأؤكد أنه صاحب هذا الملف. لا تتغير كلمة المرور ولا الصلاحيات ولا حالة الحساب.</label>
                            <button type="button" class="btn btn-sm btn-primary mt-2" :disabled="linkForm.processing || !confirmLink" @click="link">ربط بالحساب القائم</button>
                        </template>
                    </div>
                    <div v-else class="rounded-xl border border-slate-200 p-3 text-sm">
                        <label class="flex items-start gap-2"><input v-model="confirmCreate" type="checkbox" class="mt-1" /> أنشئ حساب دخول غير مفعّل برقمه الوظيفي؛ يفعّله الموظف برمز SMS على جواله الأساسي ويختار كلمة مروره بنفسه.</label>
                        <button type="button" class="btn btn-sm btn-secondary mt-2" :disabled="createForm.processing || !confirmCreate" @click="create">إنشاء حساب دخول</button>
                    </div>
                    <form v-if="!accountCandidate && directCreate" class="space-y-2 rounded-xl border border-slate-200 p-3 text-sm" @submit.prevent="createActive">
                        <p class="font-semibold text-slate-800">خيار ثانٍ لمدير النظام: إنشاء حساب مفعّل الآن دون SMS</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input v-model="directCreateForm.temporary_password" type="password" class="field" required minlength="10" maxlength="128" autocomplete="new-password" placeholder="كلمة مرور مؤقتة (10 أحرف على الأقل)" aria-label="كلمة مرور مؤقتة" />
                            <input v-model="directCreateForm.temporary_password_confirmation" type="password" class="field" required minlength="10" maxlength="128" autocomplete="new-password" placeholder="تأكيد كلمة المرور المؤقتة" aria-label="تأكيد كلمة المرور المؤقتة" />
                        </div>
                        <label class="flex items-start gap-2"><input v-model="directCreateForm.confirm" type="checkbox" class="mt-1" /> راجعت هوية الموظف وسأسلّمه كلمة المرور شخصيًا؛ يُلزَم بتغييرها عند أول دخول.</label>
                        <p v-if="directCreateForm.errors.temporary_password" class="field-error">{{ directCreateForm.errors.temporary_password }}</p>
                        <button type="submit" class="btn btn-sm btn-primary" :disabled="directCreateForm.processing || !directCreateForm.confirm">{{ directCreateForm.processing ? 'جارٍ الإنشاء...' : 'إنشاء حساب مفعّل' }}</button>
                    </form>
                </template>
                <p v-if="accountError" class="field-error">{{ accountError }}</p>
            </section>
        </div>
    </AppLayout>
</template>
