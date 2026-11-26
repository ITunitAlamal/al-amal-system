<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({ users: Array, assignments: Array, board: Array, threshold: String, minQuotes: Number, catalog: Array, documentary: Boolean, accountingPermissions: Array });
const specialization = ref('general_accountant');
const userId = ref('');
const primary = ref(true);
const assignReason = ref('');
const assignUntil = ref('');
const boardUser = ref('');
const decision = ref('');
const thresholdAmount = ref(String(props.threshold || '500.00'));
const thresholdRef = ref('');
const minimumQuotes = ref(props.minQuotes || 1);
const quotesRef = ref('');
const message = ref('');
const error = ref('');
const busy = ref(false);
const roles = [['finance_head', 'رئيس الحسابات'], ['general_accountant', 'محاسب عام'], ['procurement', 'محاسب مشتريات'], ['warehouse', 'محاسب/أمين مخزن'], ['cash_executor', 'منفذ القبض والصرف'], ['director_approver', 'اعتماد المدير التنفيذي'], ['payroll', 'مسؤول الرواتب'], ['auditor', 'مدقق مالي (اطلاع فقط)']];
const roleName = code => roles.find(role => role[0] === code)?.[1] || code;

async function send(url, data) {
    busy.value = true;
    error.value = '';
    message.value = '';
    try {
        const response = await axios.post(url, data);
        message.value = response.data?.message || 'تم حفظ الإعداد.';
        router.reload({ only: ['assignments', 'board', 'threshold', 'minQuotes'] });
        if (accessUserId.value) {
            await loadAccess();
        }
    } catch (exception) {
        error.value = Object.values(exception.response?.data?.errors || {}).flat().join(' — ') || exception.response?.data?.message || 'تعذر الحفظ';
    } finally {
        busy.value = false;
    }
}

// Per-user finance permissions: the role's default, or the administrator's grant / revocation for this user only.
const accessUserId = ref('');
const access = ref(null);
const accessReason = ref('');
const accessError = ref('');
async function loadAccess() {
    access.value = null;
    accessError.value = '';
    if (!accessUserId.value) {
        return;
    }
    try {
        const { data } = await axios.get(`/admin/finance/users/${accessUserId.value}`);
        access.value = data;
    } catch (exception) {
        accessError.value = exception.response?.data?.message || 'تعذر تحميل صلاحيات المستخدم';
    }
}
const overrideOf = code => access.value?.overrides?.[code]?.effect || 'inherit';
const disabledByMode = code => props.documentary && (props.accountingPermissions || []).includes(code);
async function setOverride(code, effect) {
    if (effect !== 'inherit' && accessReason.value.trim().length < 5) {
        accessError.value = 'اكتب سبب المنح أو السحب (5 أحرف على الأقل) قبل التعديل.';
        await loadAccess();
        return;
    }
    accessError.value = '';
    await send('/admin/finance/permissions', { user_id: accessUserId.value, permission_code: code, effect, reason: effect === 'inherit' ? null : accessReason.value });
}
async function endAssignment(assignment) {
    const reason = prompt(`سبب إنهاء اختصاص «${roleName(assignment.specialization)}» (5 أحرف على الأقل)`) || '';
    if (reason.trim().length < 5) {
        return;
    }
    await send(`/admin/finance/assignments/${assignment.id}/end`, { reason });
}
</script>

<template>
    <AppLayout title="إعداد اختصاصات الحسابات">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader eyebrow="قسم الحسابات" title="إعداد اختصاصات الحسابات" description="مدير النظام يُسند الاختصاصات ولا يكتسب تلقائيًا حق اعتماد المعاملات أو صرفها." />
            <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
            <div v-if="message" class="alert-success" role="status">{{ message }}</div>

            <section class="card space-y-4">
                <h2 class="card-title">تعيين اختصاص محاسب</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="fin-user" class="field-label">المستخدم</label>
                        <select id="fin-user" v-model="userId" class="field"><option value="">اختر</option><option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option></select>
                    </div>
                    <div>
                        <label for="fin-role" class="field-label">الاختصاص</label>
                        <select id="fin-role" v-model="specialization" class="field"><option v-for="role in roles" :key="role[0]" :value="role[0]">{{ role[1] }}</option></select>
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="fin-assign-reason" class="field-label">سبب الإسناد ومرجعه</label>
                        <input id="fin-assign-reason" v-model="assignReason" class="field" placeholder="مثال: تكليف إداري رقم ..." />
                    </div>
                    <div>
                        <label for="fin-assign-until" class="field-label">ينتهي في (اختياري)</label>
                        <input id="fin-assign-until" v-model="assignUntil" type="date" class="field" />
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="primary" type="checkbox" />اختصاص أساسي لهذا الموظف</label>
                <p class="text-xs leading-5 text-slate-500">الإسناد الحساس (الصرف، الرواتب، الاعتماد) يُرسَل إلى المدير التنفيذي ولا يسري قبل قراره. يُرفض الإسناد الذي يجمع مهمتين متعارضتين إلا استثناءً مؤقتًا من صفحة <a href="/finance/staff" class="font-semibold text-navy-700 underline">موظفو القسم وصلاحياتهم</a>.</p>
                <button type="button" :disabled="busy || !userId || assignReason.trim().length < 5" class="btn btn-primary" @click="send('/admin/finance/assign', { user_id: userId, specialization, is_primary: primary, reason: assignReason, valid_until: assignUntil || null })">حفظ الاختصاص</button>
                <div class="border-t border-slate-100 pt-3">
                    <EmptyState v-if="!assignments?.length" title="لم تُسند اختصاصات بعد" />
                    <ul v-else class="divide-y divide-slate-100 text-sm">
                        <li v-for="assignment in assignments" :key="assignment.id" class="flex flex-wrap items-center justify-between gap-2 py-2" :class="assignment.is_active ? '' : 'opacity-60'">
                            <span class="font-semibold text-navy-800">{{ assignment.name }}</span>
                            <span class="flex items-center gap-3 text-slate-600">
                                <span>{{ roleName(assignment.specialization) }}{{ assignment.is_primary ? ' · أساسي' : '' }}{{ assignment.is_active ? '' : ' · منتهٍ' }}</span>
                                <button v-if="assignment.is_active" type="button" class="text-xs font-semibold text-red-700 hover:underline" :disabled="busy" @click="endAssignment(assignment)">إنهاء الاختصاص</button>
                            </span>
                        </li>
                    </ul>
                </div>
            </section>

            <section class="card space-y-4" aria-labelledby="fin-access-title">
                <div>
                    <h2 id="fin-access-title" class="card-title">الصلاحيات التفصيلية لكل مستخدم</h2>
                    <p class="mt-1 text-sm text-slate-600">الاختصاص يمنح صلاحيات دوره. هنا تُمنح صلاحية إضافية أو تُسحب صلاحية لهذا المستخدم وحده، ويسري التعديل فورًا ويُسجَّل في سجل تدقيق الحسابات. لا يغني المنح عن وجود اختصاص فعّال، ولا تنتقل صلاحية من وحدة إلى أخرى.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="fin-access-user" class="field-label">المستخدم</label>
                        <select id="fin-access-user" v-model="accessUserId" class="field" @change="loadAccess"><option value="">اختر</option><option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option></select>
                    </div>
                    <div>
                        <label for="fin-access-reason" class="field-label">سبب المنح أو السحب</label>
                        <input id="fin-access-reason" v-model="accessReason" class="field" placeholder="مثال: تكليف إداري رقم ..." />
                    </div>
                </div>
                <div v-if="accessError" class="alert-error" role="alert">{{ accessError }}</div>
                <template v-if="access">
                    <div class="grid gap-3 text-sm sm:grid-cols-2">
                        <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">الأدوار:</span> {{ access.roles.join('، ') || '—' }}</div>
                        <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">الاختصاصات الفعّالة:</span> {{ access.assignments.filter(a => a.is_active).map(a => roleName(a.specialization)).join('، ') || 'لا يوجد — لن تسري أي صلاحية مالية' }}</div>
                    </div>
                    <p v-if="access.user.is_admin" class="alert-info text-sm">حساب مدير النظام: لا تُمنح له قرارات الاعتماد والصرف، ولا يعدّل مدير النظام صلاحياته بنفسه.</p>
                    <div v-if="access.conflicts.length" class="rounded-lg border border-gold-300 bg-gold-50 p-3 text-sm" role="status">
                        <strong class="text-gold-800">تنبيه فصل المهام:</strong>
                        <ul class="mt-1 list-disc space-y-0.5 pr-5"><li v-for="(pair, i) in access.conflicts" :key="i">{{ pair[0] }} + {{ pair[1] }}</li></ul>
                        <p class="mt-1 text-xs text-slate-600">النظام يمنع الشخص نفسه من خطوتين متعارضتين في المعاملة نفسها. لا يُمنح تعارض جديد إلا استثناءً مؤقتًا يقرره المدير التنفيذي.</p>
                    </div>
                    <div v-for="area in catalog" :key="area.code" class="overflow-x-auto">
                        <h3 class="mb-2 text-sm font-bold text-navy-800">{{ area.name }}</h3>
                        <table class="data-table min-w-full text-sm">
                            <thead><tr><th scope="col">الصلاحية</th><th scope="col">من الدور</th><th scope="col">قرار مدير النظام</th><th scope="col">النتيجة</th></tr></thead>
                            <tbody>
                                <tr v-for="permission in area.permissions" :key="permission.code">
                                    <td><span class="block">{{ permission.label }}</span><code dir="ltr" class="text-xs text-slate-400">{{ permission.code }}</code></td>
                                    <td>{{ access.rolePermissions.includes(permission.code) ? 'نعم' : '—' }}</td>
                                    <td>
                                        <label class="sr-only" :for="`ovr-${permission.code}`">قرار مدير النظام: {{ permission.label }}</label>
                                        <select :id="`ovr-${permission.code}`" class="field py-1 text-sm" :value="overrideOf(permission.code)" :disabled="busy || access.user.id === $page.props.auth?.user?.id" @change="setOverride(permission.code, $event.target.value)">
                                            <option value="inherit">حسب الدور</option><option value="grant">منح لهذا المستخدم</option><option value="deny">سحب من هذا المستخدم</option>
                                        </select>
                                    </td>
                                    <td>
                                        <span v-if="disabledByMode(permission.code)" class="text-xs text-slate-500">معطّلة في الدورة المستندية</span>
                                        <span v-else-if="access.effective.includes(permission.code)" class="font-semibold text-green-700">فعّالة</span>
                                        <span v-else class="text-slate-500">غير فعّالة</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </section>

            <section class="card space-y-3">
                <h2 class="card-title">أمين الصندوق — عضو مجلس الإدارة</h2>
                <p class="text-sm text-slate-600">يوافق على طلبات الشراء التي تزيد عن {{ threshold || '500.00' }} شيكل، ولا يحصل على صلاحية قبض أو صرف.</p>
                <select v-model="boardUser" class="field" aria-label="عضو مجلس الإدارة"><option value="">اختر عضو مجلس الإدارة المصرّح له</option><option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option></select>
                <input v-model="decision" placeholder="مرجع قرار مجلس الإدارة" class="field" aria-label="مرجع قرار مجلس الإدارة" />
                <button type="button" :disabled="busy || !boardUser || decision.trim().length < 5" class="btn btn-primary" @click="send('/admin/finance/board', { user_id: boardUser, decision_reference: decision })">اعتماد الإسناد</button>
                <ul v-if="board?.length" class="divide-y divide-slate-100 border-t border-slate-100 pt-2 text-sm">
                    <li v-for="member in board" :key="member.user_id" class="py-2 font-semibold text-navy-800">{{ member.name }}</li>
                </ul>
            </section>

            <div class="grid gap-5 md:grid-cols-2">
                <section class="card space-y-3">
                    <h2 class="card-title">حد موافقة مجلس الإدارة</h2>
                    <p class="text-sm text-slate-600">الحد الحالي {{ threshold }} شيكل. أي تعديل يحتاج قرارًا إداريًا معتمدًا.</p>
                    <input v-model="thresholdAmount" inputmode="decimal" class="field" aria-label="الحد الجديد" />
                    <input v-model="thresholdRef" placeholder="مرجع قرار تعديل الحد" class="field" aria-label="مرجع القرار" />
                    <button type="button" :disabled="busy || thresholdRef.trim().length < 5" class="btn btn-primary" @click="send('/admin/finance/threshold', { amount: thresholdAmount, decision_reference: thresholdRef })">تحديث الحد</button>
                </section>
                <section class="card space-y-3">
                    <h2 class="card-title">سياسة عروض أسعار المشتريات</h2>
                    <p class="text-sm text-slate-600">الحد الأدنى لعدد الموردين المتقدمين بعروض أسعار، مع حفظ مرجع القرار.</p>
                    <input v-model.number="minimumQuotes" type="number" min="1" max="20" class="field" aria-label="الحد الأدنى للعروض" />
                    <input v-model="quotesRef" placeholder="مرجع قرار سياسة العروض" class="field" aria-label="مرجع القرار" />
                    <button type="button" :disabled="busy || quotesRef.trim().length < 5" class="btn btn-primary" @click="send('/finance/purchase-rules', { min_quotes: minimumQuotes, decision_reference: quotesRef })">اعتماد السياسة</button>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
