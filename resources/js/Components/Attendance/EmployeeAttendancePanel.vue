<script setup>
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { getJson, localToday, requestStatusTone, rosterTone } from './attendanceUi.js';

/**
 * «الحضور والانصراف» in the employee file: today's shift and the next two weeks from the canonical roster, the
 * employee's permission («طلب إذن») and swap («تبديل المناوبة») requests, and administrative actions.
 * The employee acts on their own file only; HR and heads see the same data read-only.
 */
const props = defineProps({ employeeId: { type: Number, required: true } });

const data = ref(null);
const error = ref('');
const message = ref('');
const busy = ref(false);
const today = localToday();

async function load() {
    try {
        data.value = await getJson(`/employee-files/${props.employeeId}/attendance/overview`);
        error.value = '';
    } catch (e) {
        error.value = e.message;
    }
}
onMounted(load);

function send(url, payload, success, options = {}) {
    busy.value = true;
    message.value = '';
    router.post(url, payload, {
        preserveScroll: true, preserveState: true, ...options,
        onSuccess: () => { message.value = success; load(); options.done?.(); },
        onError: errors => { message.value = Object.values(errors).join(' — ') || 'تعذّر تنفيذ الطلب.'; },
        onFinish: () => { busy.value = false; },
    });
}

// «طلب إذن»
const permission = ref({ type_value_id: '', work_date: today, from: '', to: '', reason: '', attachment: null });
const chosenType = computed(() => (data.value?.permission_types || []).find(t => t.id === Number(permission.value.type_value_id)));
const ruleText = rules => [
    rules.max_minutes ? `الحد الأقصى ${rules.max_minutes} دقيقة` : null,
    rules.lead_hours ? `قبل ${rules.lead_hours} ساعة على الأقل` : null,
    rules.retro_days !== null && rules.retro_days !== undefined ? `حتى ${rules.retro_days} يوم بأثر رجعي` : null,
    rules.monthly_cap_count ? `${rules.monthly_cap_count} طلبات شهريًا` : null,
    rules.monthly_cap_minutes ? `${rules.monthly_cap_minutes} دقيقة شهريًا` : null,
    { required: 'المرفق إلزامي', none: 'بلا مرفق', optional: 'المرفق اختياري' }[rules.attachment],
].filter(Boolean).join(' · ');
function submitPermission() {
    const p = permission.value;
    if (!p.type_value_id || !p.work_date || !p.from || !p.to || p.reason.trim().length < 5) { message.value = 'أكمل نوع الإذن والتاريخ والوقت والسبب.'; return; }
    send('/attendance/permissions', { ...p }, 'أُرسل طلب الإذن للاعتماد. لا يُحتسب قبل اعتماده.',
        { forceFormData: true, done: () => { permission.value = { type_value_id: '', work_date: today, from: '', to: '', reason: '', attachment: null }; } });
}
function cancelPermission(p) {
    if (window.confirm('سحب طلب الإذن؟')) send(`/attendance/permissions/${p.id}/cancel`, {}, 'سُحب الطلب.');
}

// «تبديل المناوبة»
const swap = ref({ counterpart_employee_id: '', dates: [], reason: '' });
const swapDate = ref('');
function addDate() {
    if (swapDate.value && !swap.value.dates.includes(swapDate.value) && swap.value.dates.length < (data.value?.swap_rules?.max_days || 7)) {
        swap.value.dates = [...swap.value.dates, swapDate.value].sort();
    }
    swapDate.value = '';
}
function submitSwap() {
    const s = swap.value;
    if (!s.counterpart_employee_id || !s.dates.length || s.reason.trim().length < 5) { message.value = 'اختر الزميل والأيام واكتب السبب.'; return; }
    send('/attendance/swaps', { ...s }, 'أُرسل طلب التبديل إلى الزميل.', { done: () => { swap.value = { counterpart_employee_id: '', dates: [], reason: '' }; } });
}
const swapNote = ref({});
function respond(s, accept) {
    if (!window.confirm(accept ? 'الموافقة على التبديل ورفعه للاعتماد؟' : 'الاعتذار عن التبديل؟')) return;
    send(`/attendance/swaps/${s.id}/respond`, { accept, note: swapNote.value[s.id] || null }, accept ? 'وافقت على التبديل.' : 'سُجل اعتذارك.');
}
function cancelSwap(s) {
    if (window.confirm('سحب طلب التبديل؟')) send(`/attendance/swaps/${s.id}/cancel`, {}, 'سُحب الطلب.');
}

// Administrative actions (the employee's own, issued)
const actionResponse = ref({});
function acknowledge(a) { send(`/attendance/actions/${a.id}/acknowledge`, {}, 'سُجل اطلاعك.'); }
function respondAction(a) {
    const text = (actionResponse.value[a.id] || '').trim();
    if (text.length < 5) { message.value = 'اكتب ردك (5 أحرف على الأقل).'; return; }
    if (window.confirm('تسجيل الرد؟ لا يمكن تعديله بعد الحفظ.')) send(`/attendance/actions/${a.id}/respond`, { response: text }, 'سُجل ردك.');
}
</script>

<template>
    <section class="space-y-4" dir="rtl">
        <p v-if="error" role="alert" class="alert-error">{{ error }}</p>
        <p v-if="message" role="status" class="alert-info">{{ message }}</p>
        <template v-if="data">
            <!-- Today and the next two weeks -->
            <div class="card space-y-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h3 class="card-title">دوام اليوم</h3>
                    <span v-if="data.today" class="rounded-full px-3 py-1 text-sm font-bold ring-1" :class="rosterTone[data.today.status] || rosterTone.none">{{ data.today.label }}</span>
                    <span v-if="data.today?.shift" class="text-sm text-slate-600">{{ data.today.shift }}</span>
                    <span v-if="data.today?.tier" class="text-xs text-slate-500">({{ data.today.tier }})</span>
                </div>
                <div class="flex gap-2 overflow-x-auto pb-1" role="list" aria-label="جدول الأيام القادمة">
                    <div v-for="day in data.roster" :key="day.date" role="listitem" class="min-w-[7.5rem] rounded-lg p-2 text-xs ring-1" :class="rosterTone[day.status] || rosterTone.none">
                        <span class="block font-semibold">{{ day.weekday }}</span>
                        <span class="block text-[11px] opacity-70" dir="ltr">{{ day.date.slice(5) }}</span>
                        <span class="mt-1 block font-bold" dir="auto">{{ day.label }}</span>
                        <span v-if="day.swap" class="mt-1 block">⇄ تبديل معتمد</span>
                        <span v-else-if="day.exemption" class="mt-1 block">{{ day.exemption }}</span>
                        <span v-else-if="day.override_label" class="mt-1 block">{{ day.override_label }}</span>
                    </div>
                </div>
            </div>

            <!-- Permissions -->
            <div class="card space-y-3">
                <h3 class="card-title">طلبات الإذن</h3>
                <form v-if="data.can.request_permission" class="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="submitPermission">
                    <label class="text-sm font-semibold text-slate-700">نوع الإذن
                        <select v-model="permission.type_value_id" required class="field mt-1"><option value="">اختر</option>
                            <option v-for="t in data.permission_types" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                    </label>
                    <label class="text-sm font-semibold text-slate-700">التاريخ (يوم الوردية)<input v-model="permission.work_date" type="date" required class="field mt-1" /></label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-sm font-semibold text-slate-700">من<input v-model="permission.from" type="time" required class="field mt-1" /></label>
                        <label class="text-sm font-semibold text-slate-700">إلى<input v-model="permission.to" type="time" required class="field mt-1" /></label>
                    </div>
                    <label class="text-sm font-semibold text-slate-700 sm:col-span-2">السبب<input v-model="permission.reason" required maxlength="2000" class="field mt-1" /></label>
                    <label class="text-sm font-semibold text-slate-700">مرفق (PDF أو صورة)<input type="file" accept=".pdf,.jpg,.jpeg,.png" class="field mt-1" @change="permission.attachment = $event.target.files[0] || null" /></label>
                    <p v-if="chosenType" class="text-xs text-slate-600 sm:col-span-2 lg:col-span-3">{{ chosenType.effect_label }}<template v-if="ruleText(chosenType.rules)"> — {{ ruleText(chosenType.rules) }}</template>. لا يُخصم شيء من الراتب تلقائيًا.</p>
                    <div><button class="btn btn-primary" :disabled="busy">إرسال الطلب</button></div>
                </form>
                <p v-if="!data.permissions.length" class="text-sm text-slate-500">لا توجد طلبات إذن.</p>
                <ul class="divide-y divide-slate-100">
                    <li v-for="p in data.permissions" :key="p.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
                        <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="requestStatusTone[p.status]">{{ p.status_label }}</span>
                        <strong>{{ p.type }}</strong><span dir="ltr">{{ p.work_date }} {{ p.from }}–{{ p.to }}</span><span>{{ p.minutes }} د</span>
                        <span class="text-slate-600">{{ p.reason }}</span>
                        <span v-if="p.decision_note" class="text-xs text-slate-500">القرار: {{ p.decision_note }}</span>
                        <span v-if="p.revocation_reason" class="text-xs text-slate-500">سبب الإلغاء: {{ p.revocation_reason }}</span>
                        <a :href="p.url" class="text-xs text-navy-700 underline">التفاصيل والمسار</a>
                        <button v-if="p.can_cancel" type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="cancelPermission(p)">سحب</button>
                    </li>
                </ul>
            </div>

            <!-- Swaps -->
            <div class="card space-y-3">
                <h3 class="card-title">تبديل المناوبة</h3>
                <form v-if="data.can.request_swap" class="space-y-2 rounded-lg border border-slate-200 p-3" @submit.prevent="submitSwap">
                    <p class="text-xs text-slate-600">يتبادل الطرفان وردية كل يوم مختار. لا يتغير الجدول قبل موافقة الزميل واعتماد الطلب. يشترط راحة {{ data.swap_rules.min_rest_hours }} ساعات على الأقل بين الورديات، وحتى {{ data.swap_rules.max_days }} أيام في الطلب.</p>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label class="text-sm font-semibold text-slate-700">الزميل
                            <select v-model="swap.counterpart_employee_id" required class="field mt-1"><option value="">اختر</option>
                                <option v-for="c in data.swap_candidates" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                        </label>
                        <div class="text-sm font-semibold text-slate-700">الأيام
                            <div class="mt-1 flex gap-2"><input v-model="swapDate" type="date" :min="today" class="field" aria-label="يوم التبديل" /><button type="button" class="btn btn-secondary btn-sm" @click="addDate">إضافة</button></div>
                            <div class="mt-1 flex flex-wrap gap-1"><button v-for="d in swap.dates" :key="d" type="button" class="rounded-full bg-navy-50 px-2 py-0.5 text-xs" :aria-label="`إزالة ${d}`" @click="swap.dates = swap.dates.filter(x => x !== d)">{{ d }} ✕</button></div>
                        </div>
                        <label class="text-sm font-semibold text-slate-700">السبب<input v-model="swap.reason" required maxlength="2000" class="field mt-1" /></label>
                    </div>
                    <p v-if="!data.swap_candidates.length" class="text-xs text-amber-800">لا يوجد زملاء متاحون للتبديل في قسمك.</p>
                    <button class="btn btn-primary" :disabled="busy || !data.swap_candidates.length">إرسال إلى الزميل</button>
                </form>
                <p v-if="!data.swaps.length" class="text-sm text-slate-500">لا توجد طلبات تبديل.</p>
                <div v-for="s in data.swaps" :key="s.id" class="space-y-1 rounded-lg border border-slate-200 p-3 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="requestStatusTone[s.status]">{{ s.status_label }}</span>
                        <strong>{{ s.role === 'requester' ? 'طلبت التبديل مع' : 'طلب منك التبديل' }} {{ s.other }}</strong>
                        <a :href="s.url" class="text-xs text-navy-700 underline">التفاصيل والمسار</a>
                    </div>
                    <p v-for="d in s.days" :key="d.date" class="text-xs"><span dir="ltr">{{ d.date }}</span>: ورديتك {{ d.mine }} ⇄ وردية الزميل {{ d.theirs }}</p>
                    <p class="text-xs text-slate-600">{{ s.reason }}</p>
                    <p v-if="s.counterpart_note" class="text-xs text-slate-600">رد الزميل: {{ s.counterpart_note }}</p>
                    <p v-if="s.decision_note" class="text-xs text-slate-600">القرار: {{ s.decision_note }}</p>
                    <p v-if="s.reversal_reason" class="text-xs text-slate-600">سبب الإلغاء بعد الاعتماد: {{ s.reversal_reason }}</p>
                    <div v-if="s.can_respond" class="flex flex-wrap gap-2">
                        <input v-model="swapNote[s.id]" class="field min-w-0 flex-1" maxlength="1000" placeholder="ملاحظة (اختيارية)" aria-label="ملاحظة الرد" />
                        <button type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="respond(s, true)">موافقة</button>
                        <button type="button" class="btn btn-danger btn-sm" :disabled="busy" @click="respond(s, false)">اعتذار</button>
                    </div>
                    <button v-if="s.can_cancel" type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="cancelSwap(s)">سحب الطلب</button>
                </div>
            </div>

            <!-- Administrative actions -->
            <div v-if="data.hr_actions.length" class="card space-y-3">
                <h3 class="card-title">الإجراءات الإدارية</h3>
                <p class="text-xs text-slate-500">سجل سري: يظهر للموظف بعد الإصدار فقط، ولا يترتب عليه أي خصم تلقائي.</p>
                <div v-for="a in data.hr_actions" :key="a.id" class="space-y-1 rounded-lg border border-red-100 bg-red-50/40 p-3 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="requestStatusTone[a.status]">{{ a.status_label }}</span>
                        <strong>{{ a.type }}</strong><span class="text-xs text-slate-500">{{ a.reference_no }}</span><span dir="ltr" class="text-xs">{{ a.incident_date }}</span>
                    </div>
                    <p>{{ a.facts }}</p>
                    <a v-if="a.has_attachment" :href="`/admin/attendance/actions/${a.id}/attachment`" class="text-xs text-navy-700 underline">المرفق</a>
                    <p v-if="a.acknowledged_at" class="text-xs text-slate-600">اطّلعت عليه في {{ a.acknowledged_at }}</p>
                    <p v-if="a.employee_response" class="text-xs text-slate-700">ردك: {{ a.employee_response }}</p>
                    <template v-if="data.can.respond_actions && a.status === 'issued'">
                        <button v-if="!a.acknowledged_at" type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="acknowledge(a)">تأكيد الاطلاع</button>
                        <div v-if="!a.employee_response" class="flex flex-wrap gap-2">
                            <textarea v-model="actionResponse[a.id]" rows="2" maxlength="3000" class="field min-w-0 flex-1" placeholder="ردك (اختياري، مرة واحدة)" aria-label="الرد على الإجراء"></textarea>
                            <button type="button" class="btn btn-secondary btn-sm self-start" :disabled="busy" @click="respondAction(a)">تسجيل الرد</button>
                        </div>
                    </template>
                </div>
            </div>
        </template>
        <p v-else-if="!error" class="text-sm text-slate-500">جارٍ التحميل...</p>
    </section>
</template>
