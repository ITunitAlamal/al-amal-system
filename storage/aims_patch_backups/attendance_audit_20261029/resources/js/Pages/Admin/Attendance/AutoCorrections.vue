<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
const props = defineProps({ date:String, name:String, ledger:Array, corrections:Array, suggestions:Array, canRevert:Boolean, autoEnabled:Boolean });
const date=ref(props.date), name=ref(props.name), reason=ref('');
const badge={on_time:'bg-green-100 text-green-800', grace_used:'bg-yellow-100 text-yellow-900', late:'bg-red-100 text-red-800',neutral:'bg-slate-100 text-slate-700'};
function search(){router.get('/admin/attendance/auto-corrections',{date:date.value,name:name.value},{preserveState:true});}
function revert(c){if(!reason.value.trim() || reason.value.trim().length<10) return window.alert('أدخل سبب التراجع (10 أحرف على الأقل)');if(!window.confirm('هل تريد التراجع عن التصحيح وإعادة احتساب الدوام؟'))return;router.post(`/admin/attendance/auto-corrections/${c.id}/revert`,{reason:reason.value},{preserveScroll:true});}
</script>
<template>
<Head title="مراجعة الدوام والتصحيح الذكي" />
<div dir="rtl" class="p-6 space-y-6">
  <h1 class="text-2xl font-bold">مراجعة الدوام والتصحيح الذكي</h1>
  <p class="text-sm">تصحيح اتجاه البصمة يحفظ السجل الأصلي. اللون يتعلق بوقت الدخول فقط؛ الخروج المبكر يظهر بشكل مستقل.</p>
  <div class="flex flex-wrap gap-3 items-end"><label>التاريخ<input v-model="date" type="date" class="form-input block" /></label><label>البحث باسم الموظف فقط<input v-model="name" class="form-input block" placeholder="اسم الموظف" /></label><button class="btn btn-primary" @click="search">عرض</button><Link class="btn btn-secondary" href="/admin/attendance/operations">عمليات الحضور</Link></div>
  <section class="card p-4 overflow-x-auto"><h2 class="font-bold mb-3">سجل الدوام حسب الحالة</h2><table class="w-full text-right text-sm"><thead><tr><th>الموظف</th><th>الدخول</th><th>الخروج</th><th>حالة الدخول</th><th>الخروج المبكر</th><th>الحالة اليومية</th></tr></thead><tbody><tr v-for="x in ledger" :key="x.employee_id" class="border-t"><td>{{x.first_name_ar}} {{x.family_name_ar}}</td><td>{{x.actual_in||'—'}}</td><td>{{x.actual_out||'—'}}</td><td><span :class="['rounded px-2 py-1',badge[x.punctuality]||badge.neutral]">{{x.punctuality_label}}</span></td><td>{{x.early_minutes>0 ? x.early_minutes+' دقيقة':'—'}}</td><td>{{x.status}}</td></tr></tbody></table><p v-if="!ledger?.length">لا توجد سجلات لهذا التاريخ.</p></section>
  <section class="card p-4"><h2 class="font-bold mb-2">مقترحات التصحيح التلقائي (معاينة فقط)</h2><p>التصحيح الآلي: {{autoEnabled?'مفعّل':'معطّل حتى اعتماد الإدارة'}}</p><p v-for="s in suggestions" :key="s.punch_id" class="border-b py-2">الموظف #{{s.employee_id}} — {{s.date}}: {{s.reason_ar}}</p><p v-if="!suggestions?.length">لا توجد مقترحات تستوفي شروط التصحيح الصارمة في الأيام الأخيرة.</p></section>
  <section class="card p-4"><h2 class="font-bold mb-3">سجل التصحيحات التلقائية والتراجع</h2><label v-if="canRevert">سبب التراجع<textarea v-model="reason" class="form-input block w-full" maxlength="1000" rows="2" placeholder="ملاحظة التراجع الإلزامية" /></label><table class="w-full text-right text-sm"><thead><tr><th>الموظف</th><th>الأصل</th><th>المعتمد</th><th>ملاحظة النظام</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody><tr v-for="c in corrections" :key="c.id" class="border-t"><td>{{c.first_name_ar}} {{c.family_name_ar}}</td><td>{{c.original_direction}}</td><td>{{c.corrected_direction}}</td><td>{{c.reason_ar}}<p v-if="c.reversion_reason">التراجع: {{c.reversion_reason}}</p></td><td>{{c.status}}</td><td><button v-if="canRevert && c.status==='applied'" class="btn btn-secondary" @click="revert(c)">تراجع</button></td></tr></tbody></table></section>
</div>
</template>
