<script setup>
import { ref, computed, watch, onMounted } from 'vue';

const props = defineProps({ employees: { type: Array, default: () => [] } });
const localDay = () => {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone:'Asia/Gaza', year:'numeric',month:'2-digit',day:'2-digit' }).formatToParts(new Date());
  const p = Object.fromEntries(parts.map(x => [x.type,x.value]));
  return `${p.year}-${p.month}-${p.day}`;
};
const from = ref(localDay());
const to = ref(localDay());
const employeeId = ref('');
const employeeSearch = ref('');
const showEmployeeResults = ref(false);
const normalizedName = (value) => String(value || '')
  .normalize('NFKD')
  .replace(/[\u064B-\u065F\u0670\u0640]/g, '')
  .replace(/[أإآ]/g, 'ا')
  .replace(/ى/g, 'ي')
  .replace(/ة/g, 'ه')
  .trim().toLowerCase();
const employeeName = (e) => [e.first_name_ar, e.middle_name_ar, e.third_name_ar, e.family_name_ar]
  .filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
const visibleEmployees = computed(() => {
  const q = normalizedName(employeeSearch.value);
  if (!q) return [];
  const words = q.split(/\s+/).filter(Boolean);
  return props.employees.filter((e) => {
    const n = normalizedName(employeeName(e));
    return words.every((w) => n.includes(w));
  }).slice(0, 20);
});
function chooseEmployee(e) {
  employeeId.value = String(e.id);
  employeeSearch.value = employeeName(e);
  showEmployeeResults.value = false;
}
function clearEmployee() {
  employeeId.value = '';
  employeeSearch.value = '';
  showEmployeeResults.value = false;
}
function onEmployeeNameInput() {
  employeeId.value = '';
  showEmployeeResults.value = true;
}
const status = ref('');
const records = ref([]);
const page = ref(1);
const totalPages = ref(1);
const total = ref(0);
const loading = ref(false);
const error = ref('');
const statuses = { present:'حاضر', late:'متأخر', early:'خروج مبكر', incomplete_punches:'بصمة ناقصة', missing_punches:'بدون بصمة', off:'عطلة', off_day_punch:'بصمة في عطلة', emergency_holiday:'عطلة طارئة', holiday_or_leave_work_review:'عمل في إجازة للمراجعة', schedule_missing:'بدون جدول', invalid_schedule:'جدول غير صالح', grace_unapproved:'فترة السماح غير معتمدة' };
const minutesLabel = (v) => v === null || v === undefined ? '—' : `${Math.floor(v / 60)}س ${v % 60}د`;
async function load(newPage=1) {
  error.value = '';
  if (employeeSearch.value.trim() && !employeeId.value) { error.value='اختر الموظف من قائمة الأسماء أولًا.'; return; }
  if (!from.value || !to.value || from.value > to.value) { error.value='اختر فترة صحيحة.'; return; }
  const days = Math.round((Date.parse(to.value+'T12:00:00Z')-Date.parse(from.value+'T12:00:00Z'))/86400000);
  if (days > 30) { error.value='الحد الأقصى 31 يومًا.'; return; }
  loading.value=true;
  const params = new URLSearchParams({from:from.value,to:to.value,page:String(newPage)});
  if (employeeId.value) params.set('employee_id',String(employeeId.value));
  if (status.value) params.set('status',status.value);
  try {
    const response=await fetch('/admin/attendance/employee-ledger?'+params.toString(), {credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
    if (!response.ok) throw new Error(response.status===403?'ليس لديك صلاحية الاطلاع على السجل.':`تعذّر تحميل البيانات (${response.status}).`);
    const result=await response.json();
    records.value=result.data||[];page.value=result.current_page||1;totalPages.value=result.last_page||1;total.value=result.total||0;
  } catch(e) { error.value=e.message||'تعذّر تحميل البيانات.';records.value=[]; }
  finally { loading.value=false; }
}
// AIMS_LEDGER_EXCEL_V1
function exportXlsx() {
  error.value = '';
  if (!from.value || !to.value || from.value > to.value) { error.value = 'اختر فترة زمنية صحيحة.'; return; }
  const days = Math.round((Date.parse(to.value+'T12:00:00Z') - Date.parse(from.value+'T12:00:00Z'))/86400000);
  if (days > 30) { error.value = 'الحد الأقصى للتصدير 31 يومًا.'; return; }
  if (typeof employeeSearch !== 'undefined' && employeeSearch.value?.trim() && !employeeId.value) { error.value='اختر الموظف من قائمة الأسماء أولًا.'; return; }
  const params = new URLSearchParams({from:from.value,to:to.value});
  if (employeeId.value) params.set('employee_id',String(employeeId.value));
  if (status.value) params.set('status',status.value);
  window.location.assign('/admin/attendance/employee-ledger.xlsx?'+params.toString());
}
onMounted(()=>load());
watch([from,to,employeeId,status],()=>{records.value=[];total.value=0;});
</script>

<template>
  <section class="card space-y-4" dir="rtl">
    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="card-title">سجل دوام الموظفين</h2>
<!-- AIMS_LEDGER_MANUAL_REFRESH_V1: refresh calculated attendance data without full page reload -->
<button type="button" class="btn btn-secondary inline-flex items-center gap-2" :disabled="loading" @click="!loading && load(page)">
  <svg class="h-4 w-4" :class="{ 'animate-spin': loading }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14-5L4 8"/><path d="M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14 5l2-2"/><path d="M20 20v-4h-4"/></svg>
  {{ loading ? 'جارٍ تحديث السجل...' : 'تحديث حالة البصمات' }}
</button><span class="text-sm text-slate-600">النتائج المحسوبة مسبقًا — عرض فقط</span></div>
    <form class="grid gap-3 md:grid-cols-5" @submit.prevent="load(1)">
      <label class="text-sm font-semibold">من تاريخ<input v-model="from" required type="date" class="field mt-1" /></label>
      <label class="text-sm font-semibold">إلى تاريخ<input v-model="to" required type="date" class="field mt-1" /></label>
      <div class="relative text-sm font-semibold">
  <label for="ledger-employee-search">اسم الموظف</label>
  <div class="mt-1 flex gap-1">
    <input id="ledger-employee-search" v-model="employeeSearch" class="field w-full" type="text"
      autocomplete="off" placeholder="ابحث باسم الموظف أو جزء منه" aria-autocomplete="list"
      aria-controls="ledger-employee-matches" @input="onEmployeeNameInput" @focus="showEmployeeResults=true"
      @keydown.esc="showEmployeeResults=false" />
    <button v-if="employeeSearch" type="button" class="btn btn-secondary" @click="clearEmployee">مسح</button>
  </div>
  <ul v-if="showEmployeeResults && employeeSearch.trim()" id="ledger-employee-matches"
    class="absolute z-30 mt-1 max-h-56 w-full overflow-y-auto rounded border border-slate-300 bg-white shadow-lg" role="listbox">
    <li v-for="e in visibleEmployees" :key="e.id" role="option" :aria-selected="String(e.id)===employeeId">
      <button type="button" class="w-full px-3 py-2 text-right hover:bg-slate-100" @click="chooseEmployee(e)">{{employeeName(e)}}</button>
    </li>
    <li v-if="!visibleEmployees.length" class="px-3 py-2 text-slate-600">لا يوجد موظف بهذا الاسم</li>
  </ul>
  <p v-if="employeeSearch && !employeeId" class="mt-1 text-xs text-amber-700">اختر اسم الموظف من نتائج البحث، أو امسح الحقل لعرض الجميع.</p>
</div>
      <label class="text-sm font-semibold">الحالة<select v-model="status" class="field mt-1"><option value="">كل الحالات</option><option v-for="(label,key) in statuses" :key="key" :value="key">{{label}}</option></select></label>
      <div class="flex items-end gap-2"><button class="btn btn-primary" :disabled="loading">{{loading?'جارٍ البحث':'عرض النتائج'}}</button><button type="button" class="btn btn-secondary" @click="exportXlsx">تصدير Excel (.xlsx)</button></div>
    </form>
    <p v-if="error" role="alert" class="text-red-700">{{error}}</p>
    <div class="text-sm text-slate-700">عدد النتائج: <strong>{{total}}</strong> — الصفحة {{page}} من {{totalPages}}</div>
    <div class="overflow-x-auto"><table class="data-table aims-ledger-polished w-full"><thead><tr><th>التاريخ</th><th>الموظف</th><th>الدوام المقرر</th><th>الدخول</th><th>الخروج</th><th>مدة الحضور</th><th>تأخير</th><th>خروج مبكر</th><th>الحالة</th><th>الالتزام بالوقت</th><th>ملاحظات التصحيح التلقائي</th></tr></thead>
      <tbody><tr v-for="r in records" :key="r.id"><td>{{r.work_date}}</td><td>{{r.employee_name}} (#{{r.employee_number}})</td><td>{{r.expected_in||'—'}} – {{r.expected_out||'—'}}</td><td>{{r.actual_in||'—'}}</td><td>{{r.actual_out||'—'}}</td><td>{{minutesLabel(r.attendance_minutes)}}</td><td>{{r.late_minutes??'—'}}</td><td>{{r.early_minutes??'—'}}</td><td>{{r.display_status==='working'?'قيد العمل — بانتظار الخروج':r.display_status==='potential_entry'?'دخول محتمل — للمراجعة':(statuses[r.status]||r.status)}} <span v-if="r.early_minutes > 0" class="text-xs text-red-700">(خروج مبكر)</span></td>
<td><!-- AIMS_LEDGER_COLORS_V1 -->
 <span class="inline-flex rounded-full px-2 py-1 text-xs font-bold" :class="r.punctuality==='on_time'?'bg-green-100 text-green-800':r.punctuality==='grace_used'?'bg-yellow-100 text-yellow-900':r.punctuality==='late'?'bg-red-100 text-red-800':'bg-slate-100 text-slate-700'">{{r.punctuality_label||'غير محسوم'}} <span v-if="r.potential_entry" class="text-[10px]">⚠</span></span>
</td>
<td class="min-w-64 text-sm">
 <!-- AIMS_LEDGER_LIVE_ENTRY_V1 --><div v-if="r.potential_entry_note" class="mb-2 rounded-md border border-amber-300 bg-amber-50 p-2 text-amber-950"><strong>تنبيه مبدئي:</strong> {{r.potential_entry_note}}</div>
 <template v-if="r.auto_correction_notes?.length">
  <div v-for="c in r.auto_correction_notes" :key="c.id" class="mb-2 rounded-md border border-slate-200 p-2">
   <span class="inline-block rounded px-2 py-0.5 text-xs font-semibold" :class="c.status==='applied'?'bg-emerald-100 text-emerald-800':'bg-slate-100 text-slate-700'">{{c.status==='applied'?'تصحيح تلقائي فعّال':'تصحيح ملغى'}}</span>
   <span class="text-xs text-slate-600">{{c.at}} — {{c.original_direction==='in'?'دخول':'خروج'}} ← {{c.corrected_direction==='in'?'دخول':'خروج'}}</span>
   <div class="mt-1 whitespace-normal">{{c.note}}</div>
   <div v-if="c.status==='reverted'" class="mt-1 text-slate-700">سبب التراجع: {{c.reversion_reason||'غير محدد'}}</div>
  </div>
 </template>
 <span v-else class="text-slate-400">—</span>
</td></tr><tr v-if="!loading&&!records.length"><td colspan="11" class="py-4 text-center">لا توجد نتائج للفلاتر المحددة.</td></tr></tbody></table></div>
    <div class="flex items-center gap-3"><button class="btn btn-secondary" type="button" :disabled="loading||page<=1" @click="load(page-1)">السابق</button><button class="btn btn-secondary" type="button" :disabled="loading||page>=totalPages" @click="load(page+1)">التالي</button></div>
    <p class="text-xs text-slate-500">مدة الحضور هي الفرق بين أول دخول وآخر خروج محسوبَين؛ وليست بالضرورة ساعات عمل مستحقة. الحالات غير المعتادة تحتاج مراجعة الموارد البشرية.</p>
  </section>
</template>

<style scoped>
/* AIMS_LEDGER_EXCEL_V1 — readable boundaries and colored attendance rows */
.aims-ledger-polished { border-collapse: separate; border-spacing: 0; min-width: 1220px; font-size: .87rem; }
.aims-ledger-polished :deep(th), .aims-ledger-polished :deep(td) { border-bottom: 1px solid #cbd5e1; border-left: 1px solid #dbe3ed; padding: 11px 12px; vertical-align: middle; text-align: right; }
.aims-ledger-polished :deep(th:first-child), .aims-ledger-polished :deep(td:first-child) { border-right: 1px solid #dbe3ed; }
.aims-ledger-polished :deep(th) { color: #fff; background: #194674; white-space: nowrap; font-weight: 750; border-top: 1px solid #12385d; }
.aims-ledger-polished :deep(tbody tr:nth-child(even)) { background: #f6f9fd; }
.aims-ledger-polished :deep(tbody tr:hover) { background: #eaf3ff; }
.aims-ledger-polished :deep(tbody tr:has(td span.bg-green-100)) { box-shadow: inset -4px 0 0 #22a06b; }
.aims-ledger-polished :deep(tbody tr:has(td span.bg-yellow-100)) { box-shadow: inset -4px 0 0 #e0a300; }
.aims-ledger-polished :deep(tbody tr:has(td span.bg-red-100)) { box-shadow: inset -4px 0 0 #d94545; }
</style>
<style>
/* AIMS_LEDGER_RESPONSIVE_V1 */
.aims-ledger-polished { width: 100% !important; min-width: 0 !important; max-width: 100% !important; table-layout: fixed; border-collapse: separate; border-spacing: 0; }
.aims-ledger-polished th, .aims-ledger-polished td { box-sizing: border-box; min-width: 0; overflow-wrap: anywhere; word-break: normal; white-space: normal; vertical-align: top; }
.aims-ledger-polished th { font-size: .77rem; line-height: 1.6; }
.aims-ledger-polished td { font-size: .78rem; line-height: 1.7; }
.aims-ledger-polished th:nth-child(1) { width: 9%; }
.aims-ledger-polished th:nth-child(2) { width: 12%; }
.aims-ledger-polished th:nth-child(3) { width: 11%; }
.aims-ledger-polished th:nth-child(4), .aims-ledger-polished th:nth-child(5) { width: 8%; }
.aims-ledger-polished th:nth-child(6) { width: 8%; }
.aims-ledger-polished th:nth-child(7), .aims-ledger-polished th:nth-child(8) { width: 6%; }
.aims-ledger-polished th:nth-child(9) { width: 9%; }
.aims-ledger-polished th:nth-child(10) { width: 10%; }
.aims-ledger-polished th:nth-child(11) { width: 13%; }
.aims-ledger-polished td.min-w-64 { min-width: 0; }
.aims-ledger-polished td span { max-width: 100%; white-space: normal; }
@media (max-width: 1250px) {
  .aims-ledger-polished, .aims-ledger-polished tbody, .aims-ledger-polished tr, .aims-ledger-polished td { display: block !important; width: 100% !important; }
  .aims-ledger-polished thead { display: none !important; }
  .aims-ledger-polished tbody tr { background: #fff !important; border: 1px solid #cbd5e1; border-radius: 12px; box-shadow: 0 1px 3px rgba(15,23,42,.08); margin-bottom: 12px; overflow: hidden; }
  .aims-ledger-polished tbody tr:nth-child(even) { background: #f8fafc !important; }
  .aims-ledger-polished tbody tr td { display: grid !important; grid-template-columns: minmax(95px,30%) minmax(0,1fr); gap: 10px; border: 0 !important; border-bottom: 1px solid #e2e8f0 !important; padding: 9px 12px !important; text-align: right !important; align-items: start; }
  .aims-ledger-polished tbody tr td:last-child { border-bottom: 0 !important; }
  .aims-ledger-polished tbody tr td::before { font-weight: 700; color: #334155; }
  .aims-ledger-polished tbody tr td:nth-child(1)::before { content: 'التاريخ'; }
  .aims-ledger-polished tbody tr td:nth-child(2)::before { content: 'الموظف'; }
  .aims-ledger-polished tbody tr td:nth-child(3)::before { content: 'الدوام المقرر'; }
  .aims-ledger-polished tbody tr td:nth-child(4)::before { content: 'الدخول'; }
  .aims-ledger-polished tbody tr td:nth-child(5)::before { content: 'الخروج'; }
  .aims-ledger-polished tbody tr td:nth-child(6)::before { content: 'مدة الحضور'; }
  .aims-ledger-polished tbody tr td:nth-child(7)::before { content: 'تأخير'; }
  .aims-ledger-polished tbody tr td:nth-child(8)::before { content: 'خروج مبكر'; }
  .aims-ledger-polished tbody tr td:nth-child(9)::before { content: 'حالة الدوام'; }
  .aims-ledger-polished tbody tr td:nth-child(10)::before { content: 'الالتزام بالوقت'; }
  .aims-ledger-polished tbody tr td:nth-child(11)::before { content: 'ملاحظات التصحيح'; }
  .aims-ledger-polished tbody tr td[colspan] { display: block !important; text-align: center !important; }
  .aims-ledger-polished tbody tr td[colspan]::before { content: none !important; }
  .aims-ledger-polished tbody tr td.min-w-64 { min-width: 0 !important; }
}
@media (max-width: 480px) { .aims-ledger-polished tbody tr td { grid-template-columns: minmax(90px,37%) minmax(0,1fr); padding: 8px 9px !important; } }
</style>