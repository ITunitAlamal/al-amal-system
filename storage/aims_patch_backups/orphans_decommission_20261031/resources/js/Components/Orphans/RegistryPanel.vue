<script setup>
import { ref } from 'vue';
import axios from 'axios';
import StatusBadge from '../StatusBadge.vue';

// Side panel of «الأيتام والأوصياء»: «الوصي ← الأيتام المرتبطون به» directly (no household in between), or the child
// with every guardian link. Each link is decided on its own; one approval never covers another child.
const props = defineProps({ kind: { type: String, required: true }, data: { type: Object, required: true }, can: { type: Object, required: true } });
const emit = defineEmits(['open-child', 'open-guardian', 'changed', 'close', 'add-child']);
const tone = { pending: 'warning', approved: 'success', rejected: 'danger' };
const sexLabel = s => ({ male: 'ذكر', female: 'أنثى' }[s] || 'الجنس غير محدد');
const deciding = ref(null);
const form = ref({ decision: 'approve', note: '', replace: false });
const linkForm = ref({ child_national_id: '', evidence_note: '' });
const linking = ref(false);
const busy = ref(false);
const error = ref('');
const message = ref('');
function errorText(e) { return Object.values(e.response?.data?.errors || {}).flat().join(' — ') || e.response?.data?.message || 'تعذر إتمام العملية'; }
function startDecision(link, decision) { deciding.value = link.id; form.value = { decision, note: '', replace: false }; error.value = ''; }
async function decide(link) {
    if (form.value.decision === 'approve' && !confirm('اعتماد هذا الرابط لهذا اليتيم وحده؟ يُسجَّل اسمك ودليلك في السجل.')) return;
    busy.value = true; error.value = ''; message.value = '';
    try { await axios.post(`/orphans/registry/links/${link.id}/decision`, form.value); deciding.value = null; message.value = 'سُجّل القرار.'; emit('changed'); }
    catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
async function linkChild() {
    busy.value = true; error.value = ''; message.value = '';
    try { await axios.post(`/orphans/registry/guardians/${props.data.guardian.id}/links`, linkForm.value); linking.value = false; linkForm.value = { child_national_id: '', evidence_note: '' };
        message.value = 'سُجّل ادعاء الوصاية «بانتظار التحقق».'; emit('changed'); }
    catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
</script>

<template>
    <section class="space-y-4" :aria-label="kind === 'guardian' ? 'الوصي وأيتامه' : 'ملف اليتيم'">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div v-if="kind === 'guardian'" class="min-w-0">
                <h2 class="card-title break-words">{{ data.guardian.name }}</h2>
                <p class="text-xs text-slate-500">{{ data.guardian.reference_no || 'بلا رقم' }} · <span dir="ltr">{{ data.guardian.national_id }}</span> · {{ data.guardian.origin === 'staff' ? 'سجّله موظف — بلا حساب بوابة' : (data.guardian.portal ? 'حساب بوابة مفعّل' : 'حساب بوابة غير مفعّل') }}</p>
            </div>
            <div v-else class="min-w-0">
                <h2 class="card-title break-words">{{ data.child.name }}</h2>
                <p class="text-xs text-slate-500"><span dir="ltr">{{ data.child.national_id || 'بلا رقم هوية' }}</span> · {{ data.child.dob }} · {{ sexLabel(data.child.sex) }} · {{ data.child.orphan_class || 'نوع اليتم غير مصنف' }}</p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" @click="emit('close')">العودة إلى النتائج</button>
        </div>
        <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
        <div v-if="message" class="alert-success" role="status">{{ message }}</div>

        <template v-if="kind === 'child'">
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-500">حالة الملف</dt><dd class="font-semibold">{{ data.child.status_label }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-500">التحقق من البيانات</dt><dd class="font-semibold">{{ data.child.verification }}</dd></div>
            </dl>
            <div class="rounded-xl border border-gold-200 bg-gold-50 p-3 text-sm"><span class="font-bold text-navy-800">الإجراء المطلوب: </span>{{ data.next_action }}</div>
            <p class="text-xs text-slate-500">{{ data.child.household ? `ملف أسرة للبيانات الاجتماعية: ${data.child.household}` : 'لا يرتبط بملف أسرة؛ الأسرة للبيانات الاجتماعية والسكنية ولا تعني وصاية.' }}</p>
        </template>

        <h3 class="text-sm font-bold text-navy-800">{{ kind === 'guardian' ? `الأيتام المرتبطون بهذا الوصي (${data.links.filter(l => l.live).length})` : 'الوصي الشرعي وادعاءات الوصاية' }}</h3>
        <p v-if="!data.links.length" class="text-sm text-slate-500">لا توجد روابط بعد.</p>
        <ul class="space-y-2">
            <li v-for="link in data.links" :key="link.id" class="rounded-xl border p-3 text-sm" :class="link.live ? 'border-slate-200' : 'border-slate-100 opacity-70'">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="min-w-0">
                        <template v-if="kind === 'guardian'"><button type="button" class="font-semibold text-blue-700 hover:underline" @click="emit('open-child', link.child.id)">{{ link.child.name }}</button>
                            <span dir="ltr" class="mr-1 text-xs text-slate-500">{{ link.child.national_id || '' }}</span> · {{ link.child.age !== null ? `${link.child.age} سنة` : '' }}</template>
                        <template v-else><button type="button" class="font-semibold text-blue-700 hover:underline" @click="emit('open-guardian', link.guardian.id)">{{ link.guardian.name }}</button>
                            <span class="mr-1 text-xs text-slate-500">{{ link.guardian.reference_no }}</span></template>
                        <span class="block text-xs text-slate-500">{{ link.origin }}{{ link.kinship ? ` · الصلة: ${link.kinship}` : '' }}{{ link.starts_on ? ` · منذ ${link.starts_on}` : '' }}{{ !link.live ? ` · انتهت: ${link.ended_reason}` : '' }}</span>
                    </span>
                    <StatusBadge :label="link.live ? link.status_label : 'منتهية'" :tone="link.live ? tone[link.status] : 'muted'" />
                </div>
                <p v-if="link.evidence_note" class="mt-1 text-xs text-slate-600">الدليل: {{ link.evidence_note }}</p>
                <p v-if="link.decision_note" class="text-xs text-slate-600">القرار: {{ link.decision_note }}</p>
                <div v-if="link.live && link.status === 'pending' && (can.approve || can.reject)" class="mt-2">
                    <div v-if="deciding !== link.id" class="flex flex-wrap gap-2">
                        <button v-if="can.approve" type="button" class="btn btn-primary btn-sm" @click="startDecision(link, 'approve')">اعتماد الرابط</button>
                        <button v-if="can.reject" type="button" class="btn btn-secondary btn-sm" @click="startDecision(link, 'reject')">رفض مع السبب</button>
                    </div>
                    <form v-else class="space-y-2 rounded-lg bg-slate-50 p-2" @submit.prevent="decide(link)">
                        <label class="block"><span class="field-label">{{ form.decision === 'approve' ? 'الدليل الذي تم التحقق منه' : 'سبب الرفض' }} <span class="text-red-600">*</span></span>
                            <textarea v-model="form.note" required minlength="10" maxlength="2000" rows="2" class="field" /></label>
                        <label v-if="form.decision === 'approve' && (link.other_approved || data.links.some(l => l.live && l.status === 'approved' && l.id !== link.id))" class="flex items-center gap-2 text-xs">
                            <input v-model="form.replace" type="checkbox" />استبدال الوصي المعتمد الحالي (تنتهي وصايته اليوم ويبقى في السجل)</label>
                        <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="busy || form.note.trim().length < 10">{{ form.decision === 'approve' ? 'تأكيد الاعتماد' : 'تأكيد الرفض' }}</button>
                            <button type="button" class="btn btn-secondary btn-sm" @click="deciding = null">إلغاء</button></div>
                    </form>
                </div>
            </li>
        </ul>

        <template v-if="kind === 'guardian' && can.records">
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary btn-sm" @click="emit('add-child', data.guardian)">إضافة يتيم لهذا الوصي</button>
                <button type="button" class="btn btn-secondary btn-sm" :aria-expanded="linking" @click="linking = !linking">ربط يتيم مسجل</button>
            </div>
            <form v-if="linking" class="space-y-2 rounded-xl border border-slate-200 p-3 text-sm" @submit.prevent="linkChild">
                <p class="text-xs text-slate-500">يُسجَّل ادعاء وصاية جديد «بانتظار التحقق»؛ لا يغيّر وصيًا معتمدًا حتى يُعتمد ويُختار الاستبدال.</p>
                <label class="block"><span class="field-label">رقم هوية اليتيم</span><input v-model="linkForm.child_national_id" required maxlength="20" dir="ltr" class="field" /></label>
                <label class="block"><span class="field-label">دليل الوصاية <span class="text-red-600">*</span></span><textarea v-model="linkForm.evidence_note" required minlength="10" rows="2" class="field" /></label>
                <button class="btn btn-primary btn-sm" :disabled="busy">تسجيل الادعاء</button>
            </form>
        </template>
    </section>
</template>
