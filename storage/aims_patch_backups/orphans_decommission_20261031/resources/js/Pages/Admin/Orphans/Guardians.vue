<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import FormErrorSummary from '../../../Components/FormErrorSummary.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import StatusStepper from '../../../Components/StatusStepper.vue';
import { guardianStates } from '../../../Support/guardianProgress.js';
import { confirmRecordAction } from '../../../Support/actionConfirmation.js';

const props = defineProps({ username: String, applications: Object, selected: Object, statusFilter: String });
const verification = { unavailable: 'الخدمة غير متاحة', pending: 'قيد التحقق', verified: 'هوية الوصي مثبتة', ineligible: 'الأهلية غير مثبتة' };
const resultLabels = { verified: 'متحقق', pending: 'بانتظار التحقق', incomplete: 'غير مكتمل', conflict: 'تعارض', ineligible: 'خارج الفئة' };
const resultTone = { verified: 'success', pending: 'info', incomplete: 'warning', conflict: 'danger', ineligible: 'muted' };
const countOf = result => (props.selected?.children || []).filter(child => child.result === result).length;
const form = useForm({ decision: 'approve', note: '' });
function decide(id, decision) {
    if (form.processing) return;
    const actions = {
        approve: ['اعتماد طلب الوصاية', `سيُرسل طلب اعتماد الوصي وربط الأطفال المتحقق منهم (${props.selected.verifiedChildren}).`],
        needs_changes: ['إعادة طلب الوصاية للاستكمال', 'سيُعاد الطلب للوصي مع ملاحظة القرار لاستكمال البيانات.'],
        reject: ['رفض طلب الوصاية', 'سيُسجّل رفض الطلب مع ملاحظة القرار.'],
    };
    const action = actions[decision];
    if (!action || !confirmRecordAction(action[0], `${props.selected.guardian_name} — ${id}`, action[1])) return;
    form.decision = decision;
    form.post(`/admin/orphans/guardians/${id}/review`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="طلبات الأوصياء">
        <div class="space-y-6">
            <PageHeader eyebrow="الكفالات والأيتام" title="طلبات الأوصياء" description="التحقق من أهلية الوصي، ثم مراجعة الطلب واعتماده قبل إنشاء روابط الأطفال." />

            <section v-if="!selected" class="card">
                <div class="mb-5 flex flex-wrap items-center gap-3">
                    <label for="guardian-status" class="text-sm font-semibold text-slate-700">حالة الطلب</label>
                    <select id="guardian-status" :value="statusFilter || ''" class="field w-auto" @change="router.get('/admin/orphans/guardians', { status: $event.target.value }, { preserveState: true })">
                        <option value="">جميع الحالات</option>
                        <template v-for="(state, key) in guardianStates" :key="key"><option v-if="key !== 'verifying'" :value="key">{{ state[0] }}</option></template>
                    </select>
                </div>
                <EmptyState v-if="!applications?.data.length" title="لا توجد طلبات ضمن هذا العرض" />
                <div v-else class="overflow-x-auto">
                    <table class="data-table min-w-[640px]">
                        <thead><tr><th>الوصي</th><th>حالة الطلب</th><th>التحقق</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="app in applications.data" :key="app.public_id">
                                <td class="font-semibold text-navy-800">{{ app.guardian_name }}</td>
                                <td><StatusBadge :label="guardianStates[app.status]?.[0] || app.status" :tone="guardianStates[app.status]?.[1] || 'muted'" /></td>
                                <td>{{ verification[app.verification_status] || '—' }}</td>
                                <td><Link :href="`/admin/orphans/guardians/${app.public_id}`" class="btn btn-secondary btn-sm">عرض الطلب</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <nav v-if="applications?.prev_page_url || applications?.next_page_url" class="mt-5 flex gap-2" aria-label="الصفحات">
                    <Link v-if="applications.prev_page_url" :href="applications.prev_page_url" class="btn btn-secondary btn-sm">السابق</Link>
                    <Link v-if="applications.next_page_url" :href="applications.next_page_url" class="btn btn-secondary btn-sm">التالي</Link>
                </nav>
            </section>

            <template v-else>
                <Link href="/admin/orphans/guardians" class="text-sm font-semibold text-blue-700 hover:underline">→ العودة إلى الطلبات</Link>
                <section class="card space-y-5 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="card-title text-xl">{{ selected.guardian_name }}</h2>
                        <StatusBadge :label="guardianStates[selected.status]?.[0] || selected.status" :tone="guardianStates[selected.status]?.[1] || 'muted'" />
                    </div>
                    <StatusStepper :steps="selected.progress.steps" :summary="selected.progress.summary" />
                    <dl class="grid gap-5 border-t border-slate-100 pt-5 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-500">هوية الوصي</dt><dd class="mt-1 font-semibold" dir="ltr">{{ selected.guardian_identity }}</dd></div>
                        <div><dt class="text-slate-500">الجوال</dt><dd class="mt-1 font-semibold" dir="ltr">{{ selected.phone }}</dd></div>
                        <div><dt class="text-slate-500">هوية المتوفى</dt><dd class="mt-1 font-semibold" dir="ltr">{{ selected.deceased_identity }}</dd></div>
                        <div><dt class="text-slate-500">نتيجة التحقق</dt><dd class="mt-1 font-semibold">{{ verification[selected.verification_status] || '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-slate-500">الصفة والعلاقة المصرّح بها</dt><dd class="mt-1 leading-7">{{ selected.relationship }}</dd></div>
                    </dl>
                    <p v-if="!selected.registryAvailable" class="alert-warning leading-7">خدمة التحقق الخارجي غير متاحة. الاعتماد واستيراد الأطفال متوقفان إلى حين تسجيل نتيجة تحقق موثوقة.</p>
                    <button v-if="selected.canVerify" type="button" class="btn btn-secondary" @click="router.post(`/admin/orphans/guardians/${selected.public_id}/verify`)">إعادة التحقق</button>
                </section>

                <section class="card p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="card-title">نتيجة التحقق لكل طفل</h2>
                        <div v-if="selected.children.length" class="flex flex-wrap gap-1.5">
                            <template v-for="(label, key) in resultLabels" :key="key">
                                <StatusBadge v-if="countOf(key)" :label="`${label}: ${countOf(key)}`" :tone="resultTone[key]" />
                            </template>
                        </div>
                    </div>
                    <p class="mt-2 text-xs leading-6 text-slate-500">يُربط عند الاعتماد الأطفال «المتحقق منهم» فقط. القرابة وحدها لا تمنح صفة التمثيل، ولا يُرفض الطلب كله بسبب طفل واحد.</p>
                    <EmptyState v-if="!selected.children.length" class="mt-4" title="لا توجد نتيجة تحقق للأطفال بعد" description="تظهر النتائج بعد استجابة خدمة السجل المدني." />
                    <div v-else class="mt-4 space-y-3">
                        <article v-for="child in selected.children" :key="child.id" class="rounded-xl border p-4" :class="child.result === 'verified' ? 'border-emerald-200 bg-emerald-50/40' : child.result === 'conflict' ? 'border-red-200 bg-red-50/40' : 'border-slate-200'">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <strong class="text-navy-800">{{ child.full_name }}</strong>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <StatusBadge v-if="child.linked" label="مرتبط بالوصي" tone="navy" />
                                    <StatusBadge :label="child.result_label" :tone="resultTone[child.result] || 'muted'" />
                                </div>
                            </div>
                            <dl class="mt-3 grid gap-2 text-xs text-slate-600 sm:grid-cols-3">
                                <div><dt class="text-slate-400">تاريخ الميلاد</dt><dd class="mt-0.5 font-semibold text-slate-700" dir="ltr">{{ child.date_of_birth }}</dd></div>
                                <div><dt class="text-slate-400">القرابة من المعرفات الرسمية</dt><dd class="mt-0.5 font-semibold text-slate-700">{{ child.kinship }}</dd></div>
                                <div><dt class="text-slate-400">سند التمثيل</dt><dd class="mt-0.5 font-semibold text-slate-700">{{ child.authority }}<span v-if="child.authority_valid_until" class="font-normal"> حتى <span dir="ltr">{{ child.authority_valid_until }}</span></span></dd></div>
                            </dl>
                            <ul v-if="child.reasons.length" class="mt-3 list-disc space-y-1 pr-5 text-xs leading-6 text-slate-700">
                                <li v-for="(reason, index) in child.reasons" :key="index">{{ reason }}</li>
                            </ul>
                        </article>
                    </div>
                </section>

                <section v-if="selected.canReview || selected.canApprove || selected.canReject" data-error-scope class="card border-2 border-gold-300 p-6">
                    <h2 class="card-title">قرار المشرف</h2>
                    <FormErrorSummary :errors="form.errors" class="mt-4" />
                    <label for="review-note" class="field-label mt-4">ملاحظة القرار أو بنود الاستكمال</label>
                    <textarea id="review-note" data-error-field="note" v-model="form.note" maxlength="2000" rows="3" class="field" />
                    <div class="mt-5 flex flex-wrap gap-2">
                        <button v-if="selected.canApprove" type="button" :disabled="form.processing" class="btn btn-primary" @click="decide(selected.public_id, 'approve')">اعتماد وربط الأطفال المتحقق منهم ({{ selected.verifiedChildren }})</button>
                        <button v-if="selected.canReview" type="button" :disabled="form.processing" class="btn btn-secondary" @click="decide(selected.public_id, 'needs_changes')">إعادة للاستكمال</button>
                        <button v-if="selected.canReject" type="button" :disabled="form.processing" class="btn btn-danger" @click="decide(selected.public_id, 'reject')">رفض الطلب</button>
                    </div>
                </section>
                <p v-if="selected.review_note" class="card text-sm leading-7"><strong>ملاحظة القرار:</strong> {{ selected.review_note }}</p>
            </template>
        </div>
    </AppLayout>
</template>
