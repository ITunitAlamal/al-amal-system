<script setup>
import { ref, computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
const props = defineProps({username:String,navigation:Array,devices:Array,schedules:Array,days:Array,assignments:Array,overrides:Array,punches:Array,employees:Array,departments:Array,canApprove:Boolean,scheduleChangeRequests:{type:Array,default:()=>[]}});
const page=usePage();
const tab=ref('manual');
const device=useForm({name:'',location:'',host:'',port:4370,protocol:'auto',is_active:false});
const manual=useForm({employee_id:'',occurred_at:'',direction:'in',reason:''});
const schedule=useForm({name:'',days:[1,2,3,4,7].map(weekday=>({weekday,start_time:'08:00',end_time:'16:00',ends_next_day:false}))});
const editScheduleId=ref(null);
const editScheduleForm=useForm({action:'edit',name:'',days:[]});
const changeForm=useForm({action:'set_default'});
const approvalForm=useForm({decision:'approved'});
function beginScheduleEdit(s){
  editScheduleId.value=s.id;
  editScheduleForm.name=s.name;
  editScheduleForm.days=props.days.filter(d=>Number(d.schedule_id)===Number(s.id)).map(d=>({weekday:Number(d.weekday),start_time:String(d.start_time).slice(0,5),end_time:String(d.end_time).slice(0,5),ends_next_day:!!d.ends_next_day}));
}
function toggleEditScheduleDay(id){
  editScheduleForm.days=editScheduleForm.days.some(d=>d.weekday===id)
    ? editScheduleForm.days.filter(d=>d.weekday!==id)
    : [...editScheduleForm.days,{weekday:id,start_time:'08:00',end_time:'16:00',ends_next_day:false}].sort((a,b)=>a.weekday-b.weekday);
}
function submitScheduleEdit(){
  editScheduleForm.post(`/admin/attendance/schedules/${editScheduleId.value}/changes`,{preserveScroll:true,onSuccess:()=>editScheduleId.value=null});
}
function requestScheduleAction(id,action){
  if(!window.confirm('إرسال هذا التغيير لاعتماد المدير التنفيذي؟'))return;
  changeForm.action=action;
  changeForm.post(`/admin/attendance/schedules/${id}/changes`,{preserveScroll:true});
}
function decideScheduleChange(id,decision){
  if(!window.confirm('تأكيد القرار؟'))return;
  approvalForm.decision=decision;
  approvalForm.post(`/admin/attendance/schedules/changes/${id}/decision`,{preserveScroll:true});
}
const scheduleActionName={edit:'تعديل',set_default:'تعيين افتراضي',activate:'تفعيل',deactivate:'تعطيل'};
const assignment=useForm({schedule_id:'',employee_id:'',department_id:'',starts_on:'',ends_on:''});
const override=useForm({label:'',kind:'ramadan',employee_id:'',department_id:'',starts_on:'',ends_on:'',start_time:'',end_time:'',ends_next_day:false,reason:''});
const weekdays=[{id:1,name:'الاثنين'},{id:2,name:'الثلاثاء'},{id:3,name:'الأربعاء'},{id:4,name:'الخميس'},{id:5,name:'الجمعة'},{id:6,name:'السبت'},{id:7,name:'الأحد'}];
const chosen = computed(()=>new Set(schedule.days.map(d=>d.weekday)));
function toggleDay(id){schedule.days=chosen.value.has(id)?schedule.days.filter(d=>d.weekday!==id):[...schedule.days,{weekday:id,start_time:'08:00',end_time:'16:00',ends_next_day:false}].sort((a,b)=>a.weekday-b.weekday);}
const post=(form,path)=>form.post(path,{preserveScroll:true,onSuccess:()=>form.reset()});
const decide=(type,id,decision)=>{if(window.confirm('تأكيد القرار؟')) useForm({decision}).post(`/admin/attendance/decisions/${type}/${id}`,{preserveScroll:true});};
const employeeName=(id)=>{let e=props.employees.find(x=>x.id===Number(id));return e?`${e.first_name_ar} ${e.family_name_ar} (#${e.employee_number})`:`#${id}`;};
</script>
<template>
<AppLayout :username="username" :navigation="navigation">
  <div dir="rtl" class="max-w-7xl mx-auto p-5 space-y-5">
    <a href="/admin/attendance/compensatory" class="inline-block mb-3 bg-amber-100 text-amber-950 rounded px-4 py-2">العطل والإجازات التعويضية</a>

    <div><h1 class="text-2xl font-bold">الحضور والانصراف</h1><p class="text-sm text-gray-500">جداول مرنة، استثناءات رمضان والطوارئ، إدخال يدوي، وتجهيز أجهزة ZKTeco المتعددة</p></div>
    <div v-if="page.props.flash?.success" class="p-3 rounded bg-green-50 text-green-800">{{page.props.flash.success}}</div>
    <div v-if="Object.keys(page.props.errors||{}).length" class="p-3 rounded bg-red-50 text-red-700"><p v-for="(value,key) in page.props.errors" :key="key">{{key}}: {{value}}</p></div>
    <div class="flex flex-wrap gap-2 border-b pb-3">
      <button v-for="item in [{k:'manual',n:'الإدخال اليدوي'},{k:'punches',n:'سجل الحركات'},{k:'devices',n:'أجهزة البصمة'},{k:'schedules',n:'قوالب الدوام'},{k:'assignments',n:'تكليف الجداول'},{k:'overrides',n:'رمضان والطوارئ'}]" :key="item.k" @click="tab=item.k" :class="tab===item.k?'bg-blue-800 text-white':'bg-gray-100'" class="px-3 py-2 rounded">{{item.n}}</button>
    </div>
    <section v-if="tab==='manual'" class="rounded border p-4 space-y-3"><h2 class="font-semibold">تسجيل حضور أو انصراف يدوي</h2>
      <form class="grid md:grid-cols-2 gap-3" @submit.prevent="post(manual,'/admin/attendance/manual')">
        <label>الموظف<select v-model="manual.employee_id" required class="w-full border rounded p-2"><option value="">اختر</option><option v-for="e in employees" :key="e.id" :value="e.id">{{employeeName(e.id)}}</option></select></label>
        <label>التاريخ والوقت<input v-model="manual.occurred_at" type="datetime-local" required class="w-full border rounded p-2" /></label>
        <label>نوع الحركة<select v-model="manual.direction" class="w-full border rounded p-2"><option value="in">حضور</option><option value="out">انصراف</option></select></label>
        <label>سبب الإدخال<textarea v-model="manual.reason" required minlength="5" class="w-full border rounded p-2" /></label>
        <button class="bg-blue-800 text-white rounded px-4 py-2" :disabled="manual.processing">حفظ الحركة</button>
      </form>
    </section>
    <section v-if="tab==='punches'" class="rounded border p-4 overflow-x-auto"><h2 class="font-semibold mb-3">آخر 100 حركة</h2><table class="w-full text-sm"><thead><tr><th class="p-2">الموظف</th><th>الوقت</th><th>الحركة</th><th>المصدر</th><th>السبب</th></tr></thead><tbody><tr v-for="p in punches" :key="p.id" class="border-t"><td class="p-2">{{p.first_name_ar}} {{p.family_name_ar}}</td><td>{{p.occurred_at}}</td><td>{{p.direction==='in'?'حضور':'انصراف'}}</td><td>{{p.source}}</td><td>{{p.reason||'—'}}</td></tr></tbody></table></section>
    <section v-if="tab==='devices'" class="rounded border p-4 space-y-3"><h2 class="font-semibold">الأجهزة — إضافة جهاز (يُحفظ غير متصل حتى تركيب موصل معتمد)</h2>
      <form class="grid md:grid-cols-2 gap-3" @submit.prevent="post(device,'/admin/attendance/devices')">
        <label>اسم الجهاز<input v-model="device.name" required class="w-full border rounded p-2" /></label><label>الموقع<input v-model="device.location" class="w-full border rounded p-2" /></label>
        <label>عنوان IP أو اسم المضيف<input v-model="device.host" required class="w-full border rounded p-2" /></label><label>المنفذ الخارجي<input v-model.number="device.port" type="number" min="1" max="65535" required class="w-full border rounded p-2" /></label>
        <label>البروتوكول<select v-model="device.protocol" class="w-full border rounded p-2"><option value="auto">تلقائي</option><option value="tcp">TCP</option><option value="udp">UDP</option></select></label>
        <button class="bg-blue-800 text-white rounded px-4 py-2" :disabled="device.processing">إضافة الجهاز</button>
      </form><div v-for="d in devices" :key="d.id" class="border-t p-2">{{d.name}} — {{d.host}}:{{d.port}} ({{d.protocol}}) — {{d.is_active?'نشط':'معلّق'}} <small class="text-gray-500">{{d.location}}</small></div>
    </section>
    <section v-if="tab==='schedules'" class="rounded border p-4 space-y-3"><h2 class="font-semibold">إنشاء قالب دوام</h2>
      <form class="space-y-3" @submit.prevent="post(schedule,'/admin/attendance/schedules')">
        <label>اسم الجدول<input v-model="schedule.name" required class="w-full border rounded p-2" /></label>
        <div class="flex flex-wrap gap-2"><label v-for="d in weekdays" :key="d.id" class="border rounded p-2"><input type="checkbox" :checked="chosen.has(d.id)" @change="toggleDay(d.id)" /> {{d.name}}</label></div>
        <div class="grid md:grid-cols-4 gap-2" v-for="d in schedule.days" :key="d.weekday"><b>{{weekdays.find(w=>w.id===d.weekday)?.name}}</b><input v-model="d.start_time" type="time" class="border p-2 rounded"/><input v-model="d.end_time" type="time" class="border p-2 rounded"/><label><input type="checkbox" v-model="d.ends_next_day"/> ينتهي اليوم التالي</label></div>
        <button class="bg-blue-800 text-white rounded px-4 py-2" :disabled="schedule.processing">حفظ القالب</button>
      </form>
      <div v-for="s in schedules" :key="s.id" class="border-t py-3 flex flex-wrap gap-2 items-center justify-between">
        <div><strong>{{s.name}}</strong> — {{days.filter(d=>d.schedule_id===s.id).length}} أيام
          <span class="text-xs px-2" :class="s.is_active?'text-green-700':'text-red-700'">{{s.is_active?'نشط':'معطل'}}</span>
          <span v-if="s.is_default" class="bg-amber-100 text-amber-900 px-2 rounded">الافتراضي</span>
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="border rounded px-3 py-1" @click="beginScheduleEdit(s)">تعديل</button>
          <button v-if="s.is_active&&!s.is_default" type="button" class="border rounded px-3 py-1" @click="requestScheduleAction(s.id,'set_default')">تعيين كافتراضي</button>
          <button v-if="!s.is_active" type="button" class="border rounded px-3 py-1" @click="requestScheduleAction(s.id,'activate')">تفعيل</button>
          <button v-if="s.is_active&&!s.is_default" type="button" class="border rounded px-3 py-1" @click="requestScheduleAction(s.id,'deactivate')">تعطيل</button>
        </div>
      </div>
      <form v-if="editScheduleId!==null" class="border rounded p-3 space-y-3" @submit.prevent="submitScheduleEdit">
        <h3 class="font-semibold">تعديل قالب الدوام — يُرسل للاعتماد</h3>
        <label>الاسم <input v-model="editScheduleForm.name" required maxlength="150" class="border rounded p-2 w-full" /></label>
        <div class="flex flex-wrap gap-2"><label v-for="d in weekdays" :key="d.id"><input type="checkbox" :checked="editScheduleForm.days.some(x=>x.weekday===d.id)" @change="toggleEditScheduleDay(d.id)" /> {{d.name}}</label></div>
        <div v-for="d in editScheduleForm.days" :key="d.weekday" class="grid md:grid-cols-4 gap-2">
          <b>{{weekdays.find(x=>x.id===d.weekday)?.name}}</b><input type="time" v-model="d.start_time" required class="border rounded p-1" />
          <input type="time" v-model="d.end_time" required class="border rounded p-1" /><label><input type="checkbox" v-model="d.ends_next_day" /> اليوم التالي</label>
        </div>
        <div class="flex gap-2"><button type="submit" class="bg-blue-800 text-white px-3 py-2 rounded" :disabled="editScheduleForm.processing">إرسال التعديل للاعتماد</button>
        <button type="button" class="border px-3 py-2 rounded" @click="editScheduleId=null">إلغاء</button></div>
      </form>
      <div class="border-t pt-3 space-y-2"><h3 class="font-semibold">طلبات تعديل القوالب</h3>
        <div v-for="c in scheduleChangeRequests" :key="c.id" class="flex flex-wrap items-center gap-2 border-b py-2">
          <span>طلب #{{c.id}} — {{scheduleActionName[c.action]||c.action}} — القالب #{{c.schedule_id}} — {{c.status}}</span>
          <template v-if="canApprove&&c.status==='pending'"><button class="text-green-700" @click="decideScheduleChange(c.id,'approved')">اعتماد</button><button class="text-red-700" @click="decideScheduleChange(c.id,'rejected')">رفض</button></template>
        </div>
      </div>
    </section>
    <section v-if="tab==='assignments'" class="rounded border p-4 space-y-3"><h2 class="font-semibold">تكليف جدول دوام — يحتاج اعتماد المدير التنفيذي</h2>
      <form class="grid md:grid-cols-2 gap-3" @submit.prevent="post(assignment,'/admin/attendance/assignments')">
        <label>القالب<select v-model="assignment.schedule_id" required class="w-full border rounded p-2"><option value="">اختر</option><option v-for="s in schedules" :value="s.id" :key="s.id">{{s.name}}</option></select></label>
        <label>موظف (اختياري)<select v-model="assignment.employee_id" class="w-full border rounded p-2"><option value="">الكل / حسب القسم</option><option v-for="e in employees" :value="e.id" :key="e.id">{{employeeName(e.id)}}</option></select></label>
        <label>القسم (اختياري)<select v-model="assignment.department_id" class="w-full border rounded p-2"><option value="">بدون قسم</option><option v-for="d in departments" :key="d.id" :value="d.id">{{d.name}}</option></select></label>
        <label>من تاريخ<input type="date" v-model="assignment.starts_on" required class="w-full border rounded p-2" /></label><label>إلى تاريخ<input type="date" v-model="assignment.ends_on" class="w-full border rounded p-2" /></label>
        <button class="bg-blue-800 text-white rounded px-4 py-2" :disabled="assignment.processing">إرسال للاعتماد</button>
      </form><div v-for="a in assignments" :key="a.id" class="border-t py-2">تكليف #{{a.id}} — {{a.status}} — {{a.starts_on}} <button v-if="canApprove&&a.status==='pending'" class="text-green-800 mx-2" @click="decide('assignment',a.id,'approved')">اعتماد</button><button v-if="canApprove&&a.status==='pending'" class="text-red-700" @click="decide('assignment',a.id,'rejected')">رفض</button></div>
    </section>
    <section v-if="tab==='overrides'" class="rounded border p-4 space-y-3"><h2 class="font-semibold">استثناء رمضان أو الطوارئ — يحتاج اعتمادًا</h2>
      <form class="grid md:grid-cols-2 gap-3" @submit.prevent="post(override,'/admin/attendance/overrides')">
        <label>العنوان<input v-model="override.label" required class="w-full border rounded p-2" /></label><label>النوع<select v-model="override.kind" class="w-full border rounded p-2"><option value="ramadan">رمضان</option><option value="emergency">طوارئ</option><option value="special">خاص</option></select></label>
        <label>الموظف (اختياري)<select v-model="override.employee_id" class="w-full border rounded p-2"><option value="">جميع الموظفين أو القسم</option><option v-for="e in employees" :value="e.id" :key="e.id">{{employeeName(e.id)}}</option></select></label>
        <label>القسم (اختياري)<select v-model="override.department_id" class="w-full border rounded p-2"><option value="">كل الجمعية</option><option v-for="d in departments" :value="d.id" :key="d.id">{{d.name}}</option></select></label>
        <label>من تاريخ<input v-model="override.starts_on" type="date" required class="w-full border rounded p-2" /></label><label>حتى تاريخ<input v-model="override.ends_on" type="date" required class="w-full border rounded p-2" /></label>
        <label>بداية الوقت<input v-model="override.start_time" type="time" class="w-full border rounded p-2" /></label><label>نهاية الوقت<input v-model="override.end_time" type="time" class="w-full border rounded p-2" /></label>
        <label><input type="checkbox" v-model="override.ends_next_day"/> نهاية الوردية باليوم التالي</label><label>السبب<textarea v-model="override.reason" minlength="5" required class="w-full border rounded p-2" /></label>
        <button class="bg-blue-800 text-white rounded px-4 py-2" :disabled="override.processing">إرسال الاستثناء للاعتماد</button>
      </form><div v-for="o in overrides" :key="o.id" class="border-t py-2">{{o.label}} — {{o.status}} — {{o.starts_on}} إلى {{o.ends_on}} <button v-if="canApprove&&o.status==='pending'" class="text-green-800 mx-2" @click="decide('override',o.id,'approved')">اعتماد</button><button v-if="canApprove&&o.status==='pending'" class="text-red-700" @click="decide('override',o.id,'rejected')">رفض</button></div>
    </section>
  </div>
</AppLayout>
</template>
