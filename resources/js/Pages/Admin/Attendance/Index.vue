<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import AttendanceLedger from '../../../Components/Attendance/AttendanceLedger.vue';
import HrActionsPanel from '../../../Components/Attendance/HrActionsPanel.vue';
import RequestsPanel from '../../../Components/Attendance/RequestsPanel.vue';
import RosterPanel from '../../../Components/Attendance/RosterPanel.vue';
import PunchTimeReview from '../../../Components/Attendance/PunchTimeReview.vue';
import { getJson } from '../../../Components/Attendance/attendanceUi.js';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import StatusStepper from '../../../Components/StatusStepper.vue';
import { createDeviceStatusPoller } from '../../../attendance/deviceStatusPolling.js';
const props = defineProps({username:String,navigation:Array,devices:Array,schedules:Array,days:Array,assignments:Array,overrides:Array,punches:Array,employees:Array,departments:Array,canApprove:Boolean,canManageDeviceConnections:Boolean,safeImportReady:Boolean,syncEnabled:{type:Boolean,default:false},agentId:{type:String,default:'local'},probeIntervalSeconds:{type:Number,default:30},scheduleChangeRequests:{type:Array,default:()=>[]},initialTab:{type:String,default:'today'},graceMinutes:{type:Number,default:null},can:{type:Object,default:()=>({})},autoCorrection:{type:Object,default:()=>({enabled:false,recent:[],pending:[]})},completion:{type:Boolean,default:false},constants:{type:Object,default:()=>({})},timeReview:{type:Array,default:()=>[]}});

// One HR workspace: five sections, each with its own sub-sections; old ?tab= links still land in the right place.
const tabs=computed(()=>[{k:'today',n:'سجل الدوام'},{k:'shifts',n:'الورديات والإسناد'},{k:'requests',n:'الطلبات والموافقات'},...(props.can.viewHrActions?[{k:'actions',n:'الإجراءات الإدارية'}]:[]),{k:'devices',n:'الأجهزة والمزامنة'}]);
// `can` carries one flag per endpoint guard (AttendanceController::index): a sub-section whose only content is a form the
// server would refuse for this user (manual punch, CSV report) is not listed at all.
const subtabs=computed(()=>({shifts:[['roster','جدول المناوبات'],['templates','قوالب الدوام'],['assignments','التكليفات'],['exceptions','الاستثناءات والإعفاءات']],requests:[['inbox','الإذن وتبديل المناوبة'],['corrections','تصحيح البصمات'],...(props.can.manualPunch?[['manual','إدخال يدوي']]:[])],devices:[['devices','الأجهزة'],['punches','سجل الحركات'],...(props.can.report?[['reports','تقرير CSV']]:[])]}));
const legacy={ledger:['today'],corrections:['requests','corrections'],manual:['requests','manual'],punches:['devices','punches'],reports:['devices','reports'],devices:['devices','devices'],schedules:['shifts','templates'],assignments:['shifts','assignments'],overrides:['shifts','exceptions'],roster:['shifts','roster'],inbox:['requests','inbox']};
const [initialTab,initialSub]=legacy[props.initialTab]||[props.initialTab];
const tab=ref(tabs.value.some(t=>t.k===initialTab)?initialTab:'today');
const sub=ref({shifts:'roster',requests:'inbox',devices:'devices',...(initialSub&&subtabs.value[initialTab]?.some(([key])=>key===initialSub)?{[initialTab]:initialSub}:{})});
const ledger=ref(null);
const requestsPanel=ref(null);
const inboxCounts=ref({permissions:0,swaps:0});
const pendingApprovals=computed(()=>(props.canApprove?props.assignments.filter(a=>a.status==='pending').length+props.overrides.filter(o=>o.status==='pending').length+props.scheduleChangeRequests.filter(c=>c.status==='pending').length:0));
const requestBadge=computed(()=>(props.autoCorrection.pending?.length||0)+props.timeReview.length+inboxCounts.value.permissions+inboxCounts.value.swaps);
const pendingCount=computed(()=>props.autoCorrection.pending?.length||0);
const syncDevices=computed(()=>props.devices.filter(d=>d.is_active&&d.sync_mode==='pull'&&d.import_approved_at));
const syncMessage=ref('');
const lastSyncSeen={};
function requestSync(){
  if(!syncDevices.value.length)return;
  syncMessage.value='';
  syncDevices.value.forEach(d=>router.post(`/admin/attendance/devices/${d.id}/sync`,{mode:'import'},{preserveScroll:true,preserveState:true,
    onSuccess:()=>{syncMessage.value='أُرسل طلب المزامنة؛ ينفذه الوكيل خلال دقيقة، ويتحدث السجل تلقائيًا بعد انتهائها.';},
    onError:errors=>{syncMessage.value=Object.values(errors).join(' — ')||'تعذّر إرسال طلب المزامنة.';}}));
}
onMounted(async()=>{if(props.completion){try{inboxCounts.value=(await getJson('/admin/attendance/requests')).counts;}catch{/* counts are optional */}}});
const statusDevices=computed(()=>props.devices.filter(d=>d.is_active));
// Devices whose punches are not current (failed or overdue sync): the ledger's missing punches may only be unimported.
const staleDevices=computed(()=>statusDevices.value.filter(d=>statusForDevice(d).data_stale));
const revertReasons=ref({});
const reviewNotes=ref({});
const correctionBusy=ref(false);
const correctionMessage=ref('');
function correctionPost(url,data,success){correctionBusy.value=true;correctionMessage.value='';router.post(url,data,{preserveScroll:true,preserveState:true,onSuccess:()=>{correctionMessage.value=success;ledger.value?.refresh();},onError:errors=>{correctionMessage.value=Object.values(errors).join(' — ')||'تعذّر تنفيذ الإجراء.';},onFinish:()=>{correctionBusy.value=false;}});}
function revertAuto(c){const reason=(revertReasons.value[c.id]||'').trim();if(reason.length<10){correctionMessage.value='اكتب سبب التراجع (10 أحرف على الأقل).';return;}if(!window.confirm('التراجع عن التصحيح التلقائي وإعادة احتساب اليوم؟ لن يُعاد تطبيقه تلقائيًا.'))return;correctionPost(`/admin/attendance/auto-corrections/${c.id}/revert`,{reason},'تم التراجع عن التصحيح وإعادة الاحتساب.');}
function decideManual(c,decision){if(!window.confirm(decision==='approve'?'اعتماد التصحيح وإعادة احتساب اليوم؟':'رفض طلب التصحيح؟'))return;correctionPost(`/admin/attendance/corrections/${c.id}/decision`,{decision,review_note:reviewNotes.value[c.id]||null},'تم تسجيل القرار.');}
const dirLabel=v=>({in:'دخول',out:'خروج',unknown:'غير معروف'}[v]||v);
const personName=c=>[c.first_name_ar,c.father_name_ar,c.family_name_ar].filter(Boolean).join(' ');
const localTime=v=>v?new Date(String(v).replace(' ','T').replace(/([+-]\d{2})$/,'$1:00')).toLocaleString('en-GB',{hour12:false,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}):'—';
const device=useForm({name:'',location:'',host:'',port:4370,protocol:'auto',is_active:false,probe_enabled:false,probe_interval_seconds:props.probeIntervalSeconds,assigned_agent:props.agentId,driver:'pyzk',timezone:'',connection_timeout:6,sync_interval_seconds:300,communication_password:''});
const manual=useForm({employee_id:'',occurred_at:'',direction:'in',reason:''});
const editDeviceId=ref(null);
const editDevice=useForm({name:'',location:'',host:'',port:4370,protocol:'auto',probe_enabled:false,probe_interval_seconds:props.probeIntervalSeconds,assigned_agent:props.agentId,driver:'pyzk',timezone:'',connection_timeout:6,sync_interval_seconds:300,communication_password:''});
const deviceConnection=useForm({enabled:false,sync_mode:'disabled'});
const probeRequestForm=useForm({});
const syncRequestForm=useForm({});
const mapping=useForm({device_id:'',employee_id:'',device_user_id:''});
const report=ref({from:'',to:'',employee_id:''});
const deviceStatuses=ref({});
const deviceStatusPollingError=ref(false);
const deviceStatusPoller=createDeviceStatusPoller({
  url:'/admin/attendance/devices/status',
  intervalMs:5000,
  onStatus:statuses=>{
    deviceStatuses.value=Object.fromEntries(statuses.map(status=>[status.id,status]));deviceStatusPollingError.value=false;
    // A finished device sync brings new punches: refresh the ledger without touching its filters.
    statuses.forEach(status=>{const seen=lastSyncSeen[status.id];lastSyncSeen[status.id]=status.last_sync_success_at;if(seen!==undefined&&seen!==status.last_sync_success_at){ledger.value?.refresh();if(syncMessage.value)syncMessage.value='اكتملت المزامنة وتم تحديث السجل.';}});
  },
  onError:()=>{deviceStatusPollingError.value=true;},
});
const pollsStatus=()=>tab.value==='devices'||tab.value==='today';
function updateDeviceStatusPolling(){const active=pollsStatus();if(active&&Object.keys(deviceStatuses.value).length>0)deviceStatusPollingError.value=true;deviceStatusPoller.setActive(active);}
function updateDeviceStatusVisibility(){const visible=document.visibilityState==='visible';if(visible&&pollsStatus()&&Object.keys(deviceStatuses.value).length>0)deviceStatusPollingError.value=true;deviceStatusPoller.setVisible(visible);}
watch(tab,updateDeviceStatusPolling);
onMounted(()=>{document.addEventListener('visibilitychange',updateDeviceStatusVisibility);updateDeviceStatusVisibility();updateDeviceStatusPolling();});
onUnmounted(()=>{document.removeEventListener('visibilitychange',updateDeviceStatusVisibility);deviceStatusPoller.dispose();});
function statusForDevice(d){return deviceStatuses.value[d.id]||{device_state:d.is_active&&d.probe_enabled?'unknown':'disabled',agent_state:'unknown',sync_state:d.sync_mode==='pull'?'never':'disabled',probe_interval_seconds:d.probe_interval_seconds||props.probeIntervalSeconds};}
function stateLabel(state){return {connected:'متصل',disconnected:'فشل الاتصال',checking:'جارٍ الفحص',syncing:'المزامنة قيد التنفيذ',running:'المزامنة قيد التنفيذ',queued:'بانتظار الوكيل',unknown:'غير معروف',stale:'بيانات قديمة',stopped:'متوقف',disabled:'معطّل',successful:'تعمل',failed:'فشل الاتصال',never:'لم تبدأ بعد'}[state]||'غير معروف';}
function statusTone(state){return {connected:'text-green-700',successful:'text-green-700',disconnected:'text-red-700',failed:'text-red-700',stopped:'text-red-700',stale:'text-amber-700',checking:'text-sky-700',syncing:'text-sky-700',running:'text-sky-700',queued:'text-amber-700',unknown:'text-slate-600',disabled:'text-slate-500',never:'text-slate-600'}[state]||'text-slate-600';}
function statusPill(state){return {connected:'bg-emerald-50 ring-emerald-200',successful:'bg-emerald-50 ring-emerald-200',disconnected:'bg-red-50 ring-red-200',failed:'bg-red-50 ring-red-200',stopped:'bg-red-50 ring-red-200',stale:'bg-amber-50 ring-amber-200',queued:'bg-amber-50 ring-amber-200',checking:'bg-sky-50 ring-sky-200',syncing:'bg-sky-50 ring-sky-200',running:'bg-sky-50 ring-sky-200'}[state]||'bg-slate-50 ring-slate-200';}
function shortTime(value){return value?new Date(String(value).replace(' ','T').replace(/([+-]\d{2})$/,'$1:00')).toLocaleTimeString('en-GB',{hour12:false,hour:'2-digit',minute:'2-digit'}):'—';}
function statusDate(value){return value?new Date(value).toLocaleString('en-GB',{hour12:false}):'لم تسجل';}
function visibleStatus(state){return deviceStatusPollingError.value&&['connected','successful'].includes(state)?'stale':state;}
function visibleDeviceState(status){return visibleStatus(status.device_state);}
function beginDeviceEdit(d){editDeviceId.value=d.id;editDevice.name=d.name;editDevice.location=d.location||'';editDevice.host=d.host;editDevice.port=Number(d.port);editDevice.protocol=d.protocol;editDevice.probe_enabled=Boolean(d.probe_enabled);editDevice.probe_interval_seconds=Number(d.probe_interval_seconds||props.probeIntervalSeconds);editDevice.assigned_agent=d.assigned_agent||props.agentId;editDevice.driver=d.driver||'pyzk';editDevice.timezone=d.timezone||'';editDevice.connection_timeout=d.connection_timeout||6;editDevice.sync_interval_seconds=d.sync_interval_seconds||300;editDevice.communication_password='';}
function saveDeviceEdit(){editDevice.put(`/admin/attendance/devices/${editDeviceId.value}`,{preserveScroll:true,onSuccess:()=>editDeviceId.value=null});}
function updateDeviceConnection(d,enabled,syncMode){if(!window.confirm('تأكيد تغيير إعداد اتصال الجهاز؟'))return;deviceConnection.enabled=enabled;deviceConnection.sync_mode=syncMode;deviceConnection.post(`/admin/attendance/devices/${d.id}/connection`,{preserveScroll:true});}
function requestDeviceProbe(d){probeRequestForm.post(`/admin/attendance/devices/${d.id}/probe`,{preserveScroll:true});}
function requestDeviceSync(d){syncRequestForm.post(`/admin/attendance/devices/${d.id}/sync`,{preserveScroll:true});}
function downloadReport(){if(!report.value.from||!report.value.to)return;const qs=new URLSearchParams({from:report.value.from,to:report.value.to});if(report.value.employee_id)qs.set('employee_id',report.value.employee_id);window.location.assign(`/admin/attendance/report.csv?${qs.toString()}`);}

// Shift templates: weekly (fixed or flexible) or rotation cycles, with their own grace, break and punch windows.
const settingDefaults=()=>({kind:'fixed',grace_minutes:'',break_minutes:0,break_paid:true,required_minutes:'',required_punches:2,window_before_minutes:'',window_after_minutes:'',cycle_days:'',cycle_anchor:'',location:'',notes:'',effective_from:'',effective_to:''});
const schedule=useForm({name:'',...settingDefaults(),days:[1,2,3,4,7].map(weekday=>({weekday,start_time:'08:00',end_time:'16:00',ends_next_day:false}))});
const editScheduleId=ref(null);
const editScheduleForm=useForm({action:'edit',name:'',...settingDefaults(),days:[]});
const changeForm=useForm({action:'set_default'});
const approvalForm=useForm({decision:'approved'});
const weekdays=[{id:1,name:'الاثنين'},{id:2,name:'الثلاثاء'},{id:3,name:'الأربعاء'},{id:4,name:'الخميس'},{id:5,name:'الجمعة'},{id:6,name:'السبت'},{id:7,name:'الأحد'}];
const dayChoices=form=>form.kind==='rotation'?Array.from({length:Math.min(56,Number(form.cycle_days)||0)},(_,i)=>({id:i+1,name:`يوم ${i+1}`})):weekdays;
const dayName=(form,id)=>dayChoices(form).find(d=>d.id===id)?.name||`يوم ${id}`;
function toggleFormDay(form,id){form.days=form.days.some(d=>d.weekday===id)?form.days.filter(d=>d.weekday!==id):[...form.days,{weekday:id,start_time:'08:00',end_time:'16:00',ends_next_day:false}].sort((a,b)=>a.weekday-b.weekday);}
function beginScheduleEdit(s){
  editScheduleId.value=s.id;
  editScheduleForm.name=s.name;
  for(const key of Object.keys(settingDefaults()))editScheduleForm[key]=s[key]===null||s[key]===undefined?settingDefaults()[key]:s[key];
  editScheduleForm.days=props.days.filter(d=>Number(d.schedule_id)===Number(s.id)).map(d=>({weekday:Number(d.weekday),start_time:String(d.start_time).slice(0,5),end_time:String(d.end_time).slice(0,5),ends_next_day:!!d.ends_next_day}));
}
function submitScheduleEdit(){editScheduleForm.post(`/admin/attendance/schedules/${editScheduleId.value}/changes`,{preserveScroll:true,onSuccess:()=>editScheduleId.value=null});}
function requestScheduleAction(id,action){if(!window.confirm('إرسال هذا التغيير لاعتماد المدير التنفيذي؟'))return;changeForm.action=action;changeForm.post(`/admin/attendance/schedules/${id}/changes`,{preserveScroll:true});}
function decideScheduleChange(id,decision){if(!window.confirm('تأكيد القرار؟'))return;approvalForm.decision=decision;approvalForm.post(`/admin/attendance/schedules/changes/${id}/decision`,{preserveScroll:true});}
const scheduleActionName={edit:'تعديل',set_default:'تعيين افتراضي',activate:'تفعيل',deactivate:'تعطيل'};
const scheduleSummary=s=>[props.constants.kinds?.[s.kind||'fixed'],s.grace_minutes!==null&&s.grace_minutes!==undefined?`سماح ${s.grace_minutes} د`:null,s.break_minutes?`استراحة ${s.break_minutes} د${s.break_paid?'':' غير مدفوعة'}`:null,s.kind==='rotation'?`دورة ${s.cycle_days} يومًا من ${s.cycle_anchor}`:null,s.kind==='flexible'?`${s.required_minutes} دقيقة مطلوبة`:null,s.location,s.effective_from?`من ${s.effective_from}`:null,s.effective_to?`حتى ${s.effective_to}`:null].filter(Boolean).join(' · ');
const assignment=useForm({schedule_id:'',employee_id:'',department_id:'',starts_on:'',ends_on:''});
const override=useForm({label:'',kind:'ramadan',employee_id:'',department_id:'',starts_on:'',ends_on:'',start_time:'',end_time:'',ends_next_day:false,reason:''});
const post=(form,path)=>form.post(path,{preserveScroll:true,onSuccess:()=>form.reset()});
const decide=(type,id,decision)=>{if(window.confirm('تأكيد القرار؟')) useForm({decision}).post(`/admin/attendance/decisions/${type}/${id}`,{preserveScroll:true});};
const revokeReasons=ref({});
function revokeOverride(o){const reason=(revokeReasons.value[o.id]||'').trim();if(reason.length<10){window.alert('اكتب سبب السحب (10 أحرف على الأقل).');return;}if(window.confirm('سحب الاستثناء المعتمد وإعادة احتساب الأيام المتأثرة؟'))router.post(`/admin/attendance/overrides/${o.id}/revoke`,{reason},{preserveScroll:true});}
const overrideKind={ramadan:'رمضان',emergency:'طوارئ',special:'خاص',exemption:'إعفاء',swap:'تبديل مناوبة',emergency_change:'تغيير طارئ'};
const employeeName=(id)=>{let e=props.employees.find(x=>x.id===Number(id));return e?`${e.first_name_ar} ${e.family_name_ar} (#${e.employee_number})`:`#${id}`;};
const departmentName=id=>props.departments.find(d=>d.id===Number(id))?.name||`#${id}`;
</script>
<template>
<AppLayout title="الحضور والانصراف">
  <div dir="rtl" class="mx-auto max-w-7xl space-y-5">

    <PageHeader eyebrow="الموارد البشرية" title="الحضور والانصراف" :description="`سجل الدوام، الورديات، الطلبات والموافقات، والأجهزة في مساحة واحدة.${graceMinutes!==null?' فترة السماح المعتمدة: '+graceMinutes+' دقائق (ما لم تحدد الوردية غيرها).':''}`">
      <button v-if="can.sync&&syncDevices.length" type="button" class="btn btn-primary inline-flex items-center gap-1" @click="requestSync">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 20h14"/></svg>
        مزامنة جهاز البصمة
      </button>
      <a href="/admin/attendance/compensatory" class="btn btn-secondary">العطل والإجازات التعويضية</a>
    </PageHeader>

    <!-- Device and agent status: stored readings, refreshed every 5 seconds while this page is visible. -->
    <div v-if="tab==='today'&&statusDevices.length" class="flex flex-wrap items-center gap-2 text-sm" role="status" aria-live="polite">
      <template v-for="d in statusDevices" :key="d.id">
        <span class="rounded-full px-3 py-1 ring-1" :class="statusPill(visibleDeviceState(statusForDevice(d)))">{{d.name}}: <strong :class="statusTone(visibleDeviceState(statusForDevice(d)))">{{visibleDeviceState(statusForDevice(d))==='connected'?'الجهاز متصل':stateLabel(visibleDeviceState(statusForDevice(d)))}}</strong></span>
        <span class="rounded-full px-3 py-1 ring-1" :class="statusPill(visibleStatus(statusForDevice(d).agent_state))">الوكيل: <strong :class="statusTone(visibleStatus(statusForDevice(d).agent_state))">{{stateLabel(visibleStatus(statusForDevice(d).agent_state))}}</strong></span>
        <span class="rounded-full px-3 py-1 ring-1" :class="statusPill(visibleStatus(statusForDevice(d).sync_state))">المزامنة: <strong :class="statusTone(visibleStatus(statusForDevice(d).sync_state))">{{stateLabel(visibleStatus(statusForDevice(d).sync_state))}}</strong> — آخر مزامنة {{shortTime(statusForDevice(d).last_sync_success_at)}}<template v-if="statusForDevice(d).sync_error_label"> — {{statusForDevice(d).sync_error_label}}</template></span>
      </template>
      <span v-if="deviceStatusPollingError" class="text-xs text-amber-800">تعذّر تحديث الحالة الآن؛ المعروض آخر قراءة.</span>
    </div>
    <div v-if="(tab==='today'||tab==='devices')&&staleDevices.length" role="alert" class="alert-warning space-y-1 text-sm">
      <p class="font-semibold">بيانات البصمة غير محدّثة: لم تُستورد الحركات الأخيرة من الجهاز.</p>
      <p v-for="d in staleDevices" :key="d.id">{{d.name}}: آخر مزامنة ناجحة {{localTime(statusForDevice(d).last_sync_success_at)}}<template v-if="statusForDevice(d).sync_retry_at"> — المحاولة التلقائية التالية {{shortTime(statusForDevice(d).sync_retry_at)}}</template>.</p>
      <p>حالات «بصمة ناقصة» والغياب بعد آخر مزامنة قد تكون حركات لم تُستورد بعد؛ لا تُبنى عليها قرارات قبل اكتمال المزامنة.</p>
    </div>
    <p v-if="syncMessage" role="status" class="alert-info">{{syncMessage}}</p>

    <nav class="flex flex-wrap gap-2 border-b border-slate-200 pb-3" aria-label="أقسام الحضور والانصراف">
      <button v-for="item in tabs" :key="item.k" type="button" @click="tab=item.k" :aria-current="tab===item.k?'page':undefined" :class="tab===item.k?'bg-navy-700 text-white':'bg-white text-navy-800 ring-1 ring-slate-200 hover:bg-navy-50'" class="rounded-lg px-3 py-2 text-sm font-semibold">{{item.n}}
        <span v-if="item.k==='requests'&&requestBadge" class="ms-1 rounded-full bg-gold-400 px-1.5 text-xs text-navy-900" :aria-label="`${requestBadge} بانتظار الإجراء`">{{requestBadge}}</span>
        <span v-if="item.k==='shifts'&&pendingApprovals" class="ms-1 rounded-full bg-gold-400 px-1.5 text-xs text-navy-900" :aria-label="`${pendingApprovals} بانتظار الاعتماد`">{{pendingApprovals}}</span></button>
    </nav>
    <div v-if="subtabs[tab]" class="flex flex-wrap gap-1" role="tablist">
      <button v-for="[key,label] in subtabs[tab]" :key="key" type="button" role="tab" :aria-selected="sub[tab]===key" @click="sub[tab]=key" :class="sub[tab]===key?'bg-navy-100 text-navy-900 ring-navy-300':'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'" class="rounded-full px-3 py-1 text-xs font-semibold ring-1">{{label}}</button>
    </div>

    <AttendanceLedger v-if="tab==='today'" ref="ledger" mode="hr" endpoint="/admin/attendance/employee-ledger" export-url="/admin/attendance/employee-ledger.xlsx"
      :day-url="(employeeId,date)=>`/admin/attendance/employee-ledger/${employeeId}/${date}`" :employees="employees" :confidential-export="Boolean(can.viewHrActions)" />

    <!-- ===== الورديات والإسناد ===== -->
    <RosterPanel v-if="tab==='shifts'&&sub.shifts==='roster'" :schedules="schedules" :departments="departments" :employees="employees" :constants="constants" :can="can" :completion="completion" />

    <section v-if="tab==='shifts'&&sub.shifts==='templates'" class="card space-y-3"><h2 class="card-title">{{can.createSchedule?'إنشاء قالب دوام':'قوالب الدوام'}}</h2>
      <p v-if="!can.createSchedule" class="text-sm text-slate-600">إنشاء قالب جديد لرئيس قسم الموارد البشرية أو مدير النظام.</p>
      <form v-if="can.createSchedule" class="space-y-3" @submit.prevent="post(schedule,'/admin/attendance/schedules')">
        <div class="grid gap-3 md:grid-cols-4">
          <label class="block space-y-1 text-sm font-semibold text-slate-700 md:col-span-2">اسم القالب<input v-model="schedule.name" required class="field" /></label>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">النوع<select v-model="schedule.kind" class="field" @change="schedule.days=schedule.kind==='rotation'?[]:schedule.days"><option v-for="(label,key) in constants.kinds" :key="key" :value="key">{{label}}</option></select></label>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">الموقع<input v-model="schedule.location" maxlength="150" class="field" /></label>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">فترة السماح (دقيقة)<input v-model="schedule.grace_minutes" type="number" min="0" max="240" class="field" :placeholder="graceMinutes!==null?`العامة: ${graceMinutes}`:'العامة'" /></label>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">الاستراحة (دقيقة)<input v-model.number="schedule.break_minutes" type="number" min="0" max="600" class="field" /></label>
          <label class="flex items-end gap-2 text-sm"><input v-model="schedule.break_paid" type="checkbox" /> الاستراحة مدفوعة</label>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">البصمات المطلوبة<select v-model.number="schedule.required_punches" class="field"><option :value="2">دخول وخروج</option><option :value="1">دخول فقط</option></select></label>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">نافذة قبل البداية (دقيقة)<input v-model="schedule.window_before_minutes" type="number" min="0" max="720" class="field" placeholder="240" /></label>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">نافذة بعد النهاية (دقيقة)<input v-model="schedule.window_after_minutes" type="number" min="0" max="720" class="field" placeholder="240" /></label>
          <label v-if="schedule.kind==='flexible'" class="block space-y-1 text-sm font-semibold text-slate-700">دقائق العمل المطلوبة<input v-model="schedule.required_minutes" type="number" min="1" max="1440" required class="field" /></label>
          <template v-if="schedule.kind==='rotation'">
            <label class="block space-y-1 text-sm font-semibold text-slate-700">طول الدورة (يوم)<input v-model="schedule.cycle_days" type="number" min="1" max="56" required class="field" /></label>
            <label class="block space-y-1 text-sm font-semibold text-slate-700">تاريخ اليوم الأول من الدورة<input v-model="schedule.cycle_anchor" type="date" required class="field" /></label>
          </template>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">يسري من<input v-model="schedule.effective_from" type="date" class="field" /></label>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">حتى<input v-model="schedule.effective_to" type="date" class="field" /></label>
        </div>
        <p class="text-xs text-slate-600">{{schedule.kind==='flexible'?'الدوام المرن: الأوقات أدناه هي النطاق المسموح، ولا يُحتسب تأخير عن البداية؛ يُقارن الوقت المنجز بالدقائق المطلوبة.':schedule.kind==='rotation'?'المناوبات الدورية: حدد أيام الدورة التي فيها عمل وأوقاتها؛ الأيام غير المحددة راحة.':'الوردية الليلية: فعّل «ينتهي اليوم التالي» (مثل 20:00–08:00) فتُحسب ورديةً واحدة مؤرخة بيوم بدايتها.'}}</p>
        <div class="flex flex-wrap gap-2"><label v-for="d in dayChoices(schedule)" :key="d.id" class="btn btn-secondary btn-sm"><input type="checkbox" :checked="schedule.days.some(x=>x.weekday===d.id)" @change="toggleFormDay(schedule,d.id)" /> {{d.name}}</label></div>
        <div class="grid md:grid-cols-4 gap-2" v-for="d in schedule.days" :key="d.weekday"><b>{{dayName(schedule,d.weekday)}}</b><input v-model="d.start_time" type="time" class="field" aria-label="البداية"/><input v-model="d.end_time" type="time" class="field" aria-label="النهاية"/><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="d.ends_next_day"/> ينتهي اليوم التالي</label></div>
        <p v-for="(message, key) in schedule.errors" :key="key" class="field-error">{{ message }}</p>
        <button class="btn btn-primary" :disabled="schedule.processing">حفظ القالب</button>
      </form>
      <div v-for="s in schedules" :key="s.id" class="border-t py-3 flex flex-wrap gap-2 items-center justify-between">
        <div><strong>{{s.name}}</strong> — {{days.filter(d=>d.schedule_id===s.id).length}} أيام
          <span class="text-xs px-2" :class="s.is_active?'text-emerald-700':'text-red-700'">{{s.is_active?'نشط':'معطل'}}</span>
          <span v-if="s.is_default" class="bg-gold-100 text-gold-900 px-2 rounded">الافتراضي</span>
          <span class="block text-xs text-slate-500">{{scheduleSummary(s)}}</span>
        </div>
        <div v-if="can.requestScheduleChange" class="flex flex-wrap gap-2">
          <button type="button" class="btn btn-secondary btn-sm" @click="beginScheduleEdit(s)">تعديل</button>
          <button v-if="s.is_active&&!s.is_default" type="button" class="btn btn-secondary btn-sm" @click="requestScheduleAction(s.id,'set_default')">تعيين كافتراضي</button>
          <button v-if="!s.is_active" type="button" class="btn btn-secondary btn-sm" @click="requestScheduleAction(s.id,'activate')">تفعيل</button>
          <button v-if="s.is_active&&!s.is_default" type="button" class="btn btn-secondary btn-sm" @click="requestScheduleAction(s.id,'deactivate')">تعطيل</button>
        </div>
      </div>
      <form v-if="can.requestScheduleChange&&editScheduleId!==null" class="rounded-xl border border-slate-200 bg-white p-3 space-y-3" @submit.prevent="submitScheduleEdit">
        <h3 class="text-sm font-bold text-navy-800">تعديل قالب الدوام — يُرسل للاعتماد</h3>
        <div class="grid gap-2 md:grid-cols-4">
          <label class="block space-y-1 text-sm font-semibold text-slate-700 md:col-span-2">الاسم <input v-model="editScheduleForm.name" required maxlength="150" class="field" /></label>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">النوع<select v-model="editScheduleForm.kind" class="field"><option v-for="(label,key) in constants.kinds" :key="key" :value="key">{{label}}</option></select></label>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">فترة السماح<input v-model="editScheduleForm.grace_minutes" type="number" min="0" max="240" class="field" /></label>
          <label class="block space-y-1 text-sm font-semibold text-slate-700">الاستراحة<input v-model.number="editScheduleForm.break_minutes" type="number" min="0" max="600" class="field" /></label>
          <label class="flex items-end gap-2 text-sm"><input v-model="editScheduleForm.break_paid" type="checkbox" /> مدفوعة</label>
          <label v-if="editScheduleForm.kind==='flexible'" class="block space-y-1 text-sm font-semibold text-slate-700">دقائق مطلوبة<input v-model="editScheduleForm.required_minutes" type="number" min="1" max="1440" class="field" /></label>
          <template v-if="editScheduleForm.kind==='rotation'"><label class="block space-y-1 text-sm font-semibold text-slate-700">طول الدورة<input v-model="editScheduleForm.cycle_days" type="number" min="1" max="56" class="field" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">اليوم الأول<input v-model="editScheduleForm.cycle_anchor" type="date" class="field" /></label></template>
        </div>
        <div class="flex flex-wrap gap-2"><label v-for="d in dayChoices(editScheduleForm)" :key="d.id"><input type="checkbox" :checked="editScheduleForm.days.some(x=>x.weekday===d.id)" @change="toggleFormDay(editScheduleForm,d.id)" /> {{d.name}}</label></div>
        <div v-for="d in editScheduleForm.days" :key="d.weekday" class="grid md:grid-cols-4 gap-2">
          <b>{{dayName(editScheduleForm,d.weekday)}}</b><input type="time" v-model="d.start_time" required class="field" aria-label="البداية" />
          <input type="time" v-model="d.end_time" required class="field" aria-label="النهاية" /><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="d.ends_next_day" /> اليوم التالي</label>
        </div>
        <p v-for="(message, key) in editScheduleForm.errors" :key="key" class="field-error">{{ message }}</p>
        <div class="flex gap-2"><button type="submit" class="btn btn-primary" :disabled="editScheduleForm.processing">إرسال التعديل للاعتماد</button>
        <button type="button" class="btn btn-secondary btn-sm" @click="editScheduleId=null">إلغاء</button></div>
      </form>
      <div class="border-t pt-3 space-y-2"><h3 class="text-sm font-bold text-navy-800">طلبات تعديل القوالب</h3>
        <p class="text-xs text-slate-500">عند الاعتماد يسري التعديل من أمس فصاعدًا؛ الأيام الأقدم لا يُعاد احتسابها إلا بأمر صريح بعد المعاينة.</p>
        <p v-if="!scheduleChangeRequests.length" class="text-sm text-slate-500">لا توجد طلبات تعديل.</p>
        <div v-for="c in scheduleChangeRequests" :key="c.id" class="space-y-2 border-b py-2">
          <div class="flex flex-wrap items-center gap-2">
            <span>طلب #{{c.id}} — {{scheduleActionName[c.action]||c.action}} — القالب #{{c.schedule_id}}</span><StatusBadge :status="c.status" />
            <template v-if="canApprove&&c.status==='pending'"><button type="button" class="btn btn-primary btn-sm mx-1" @click="decideScheduleChange(c.id,'approved')">اعتماد</button><button type="button" class="btn btn-danger btn-sm" @click="decideScheduleChange(c.id,'rejected')">رفض</button></template>
          </div>
          <StatusStepper v-if="c.steps" :steps="c.steps" compact />
        </div>
      </div>
    </section>

    <section v-if="tab==='shifts'&&sub.shifts==='assignments'" class="card space-y-3"><h2 class="card-title">{{can.createAssignment?'تكليف جدول دوام — يحتاج اعتماد المدير التنفيذي':'تكليفات جداول الدوام'}}</h2>
      <p class="text-sm text-slate-600">الأولوية: استثناء الموظف ← تبديل المناوبة المعتمد ← استثناء القسم ثم العام ← تكليف الموظف ← تكليف القسم ← تكليف عام ← القالب الافتراضي. إن لم ينطبق شيء: «لا يوجد دوام مقرر» وليس غيابًا.<template v-if="can.createAssignment"> للتكليف الجماعي مع معاينة الأثر استخدم «جدول المناوبات».</template><template v-else> يُعدّ التكليف رئيس قسم الموارد البشرية أو مدير النظام.</template></p>
      <form v-if="can.createAssignment" class="grid md:grid-cols-2 gap-3" @submit.prevent="post(assignment,'/admin/attendance/assignments')">
        <label class="block space-y-1 text-sm font-semibold text-slate-700">القالب<select v-model="assignment.schedule_id" required class="field"><option value="">اختر</option><option v-for="s in schedules" :value="s.id" :key="s.id">{{s.name}}</option></select></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">موظف (اختياري)<select v-model="assignment.employee_id" class="field"><option value="">الكل / حسب القسم</option><option v-for="e in employees" :value="e.id" :key="e.id">{{employeeName(e.id)}}</option></select></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">القسم (اختياري)<select v-model="assignment.department_id" class="field"><option value="">بدون قسم</option><option v-for="d in departments" :key="d.id" :value="d.id">{{d.name}}</option></select></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">من تاريخ<input type="date" v-model="assignment.starts_on" required class="field" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">إلى تاريخ<input type="date" v-model="assignment.ends_on" class="field" /></label>
        <p v-for="(message, key) in assignment.errors" :key="key" class="field-error md:col-span-2">{{ message }}</p>
        <button class="btn btn-primary" :disabled="assignment.processing">إرسال للاعتماد</button>
      </form>
      <p v-if="!assignments.length" class="text-sm text-slate-500">لا توجد تكليفات دوام.</p>
      <div v-for="a in assignments" :key="a.id" class="space-y-2 border-t border-slate-100 py-2">
        <div class="flex flex-wrap items-center gap-2">تكليف #{{a.id}} <StatusBadge :status="a.status" /> {{schedules.find(s=>s.id===a.schedule_id)?.name}} — {{a.employee_id?employeeName(a.employee_id):a.department_id?departmentName(a.department_id):'كل الجمعية'}} — من {{a.starts_on}}<span v-if="a.ends_on"> إلى {{a.ends_on}}</span><button v-if="canApprove&&a.status==='pending'" type="button" class="btn btn-primary btn-sm mx-1" @click="decide('assignment',a.id,'approved')">اعتماد</button><button v-if="canApprove&&a.status==='pending'" type="button" class="btn btn-danger btn-sm" @click="decide('assignment',a.id,'rejected')">رفض</button></div>
        <StatusStepper v-if="a.steps" :steps="a.steps" compact />
      </div>
    </section>

    <section v-if="tab==='shifts'&&sub.shifts==='exceptions'" class="card space-y-3"><h2 class="card-title">الاستثناءات والإعفاءات — تحتاج اعتمادًا</h2>
      <p v-if="can.createOverride" class="text-sm text-slate-600">الإعفاء من الدوام أو البصمة يُنشأ من «جدول المناوبات» (مع معاينة الأثر واختيار السبب من ثوابت النظام). هنا استثناءات رمضان والطوارئ وقائمة كل الاستثناءات وسحبها بسبب موثق.</p>
      <p v-else class="text-sm text-slate-600">يُعدّ الاستثناء أو الإعفاء رئيس قسم الموارد البشرية أو مدير النظام. هنا قائمة كل الاستثناءات وقرارات اعتمادها.</p>
      <form v-if="can.createOverride" class="grid md:grid-cols-2 gap-3" @submit.prevent="post(override,'/admin/attendance/overrides')">
        <label class="block space-y-1 text-sm font-semibold text-slate-700">العنوان<input v-model="override.label" required class="field" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">النوع<select v-model="override.kind" class="field"><option value="ramadan">رمضان</option><option value="emergency">طوارئ</option><option value="special">خاص</option></select></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">الموظف (اختياري)<select v-model="override.employee_id" class="field"><option value="">جميع الموظفين أو القسم</option><option v-for="e in employees" :value="e.id" :key="e.id">{{employeeName(e.id)}}</option></select></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">القسم (اختياري)<select v-model="override.department_id" class="field"><option value="">كل الجمعية</option><option v-for="d in departments" :value="d.id" :key="d.id">{{d.name}}</option></select></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">من تاريخ<input v-model="override.starts_on" type="date" required class="field" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">حتى تاريخ<input v-model="override.ends_on" type="date" required class="field" /></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">بداية الوقت<input v-model="override.start_time" type="time" class="field" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">نهاية الوقت<input v-model="override.end_time" type="time" class="field" /></label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="override.ends_next_day"/> نهاية الوردية باليوم التالي</label><label class="block space-y-1 text-sm font-semibold text-slate-700">السبب<textarea v-model="override.reason" minlength="5" required class="field" /></label>
        <p v-for="(message, key) in override.errors" :key="key" class="field-error md:col-span-2">{{ message }}</p>
        <button class="btn btn-primary" :disabled="override.processing">إرسال الاستثناء للاعتماد</button>
      </form>
      <div v-for="o in overrides" :key="o.id" class="space-y-1 border-t border-slate-100 py-2 text-sm">
        <div class="flex flex-wrap items-center gap-2"><strong>{{o.label}}</strong> <StatusBadge :status="o.status" /><span class="text-xs text-slate-600">{{overrideKind[o.kind]||o.kind}}<template v-if="o.effect&&o.effect!=='schedule'"> — {{constants.effects?.[o.effect]}}</template></span>
          <span>{{o.employee_id?employeeName(o.employee_id):o.department_id?departmentName(o.department_id):'كل الجمعية'}}</span><span dir="ltr">{{o.starts_on}} → {{o.ends_on}}</span><span v-if="o.start_time" dir="ltr">{{String(o.start_time).slice(0,5)}}–{{String(o.end_time).slice(0,5)}}</span>
          <button v-if="canApprove&&o.status==='pending'" class="btn btn-primary btn-sm mx-1" @click="decide('override',o.id,'approved')">اعتماد</button><button v-if="canApprove&&o.status==='pending'" class="btn btn-danger btn-sm" @click="decide('override',o.id,'rejected')">رفض</button></div>
        <p class="text-xs text-slate-600">{{o.reason}}</p>
        <p v-if="o.revocation_reason" class="text-xs text-slate-500">سبب السحب: {{o.revocation_reason}}</p>
        <div v-if="completion&&can.approveRoster&&o.status==='approved'&&!o.swap_id" class="flex flex-wrap gap-2"><input v-model="revokeReasons[o.id]" class="field min-w-0 flex-1" maxlength="2000" placeholder="سبب سحب الاستثناء المعتمد" aria-label="سبب السحب" /><button type="button" class="btn btn-secondary btn-sm" @click="revokeOverride(o)">سحب</button></div>
      </div>
    </section>

    <!-- ===== الطلبات والموافقات ===== -->
    <RequestsPanel v-if="tab==='requests'&&sub.requests==='inbox'" ref="requestsPanel" :can="can" :constants="constants" :completion="completion" @counts="c=>inboxCounts=c" />

    <section v-if="tab==='requests'&&sub.requests==='corrections'" class="space-y-4">
      <p v-if="correctionMessage" role="status" class="alert-info">{{correctionMessage}}</p>
      <!-- HR-20: only the daylight-saving punches this user confirms (the executive director's share has no other screen). -->
      <PunchTimeReview v-if="timeReview.length" :events="timeReview" />
      <div class="card space-y-3">
        <h2 class="card-title">طلبات تصحيح بانتظار الاعتماد ({{pendingCount}})</h2>
        <p class="text-sm text-slate-600">تطلبها الموارد البشرية من تفاصيل اليوم في السجل، ويقررها رئيس قسم الموارد البشرية (أو المدير التنفيذي إن كان رئيس القسم مقدمها أو صاحب البصمة)، ولا يقررها مقدم الطلب ولا صاحب البصمة. لا يتغير أصل البصمة؛ يُحفظ الاتجاه المصحح بجانبه.</p>
        <p v-if="!pendingCount" class="text-sm text-slate-500">لا توجد طلبات معلقة.</p>
        <div v-for="c in autoCorrection.pending" :key="c.id" class="space-y-2 rounded-lg border border-slate-200 p-3 text-sm">
          <div class="flex flex-wrap gap-x-4 gap-y-1"><strong>{{personName(c)}}</strong><span dir="ltr">{{localTime(c.occurred_at)}}</span><span>{{dirLabel(c.original_direction)}} ← {{dirLabel(c.corrected_direction)}}</span><span class="text-slate-500">طلبه {{c.requested_by_name}}</span></div>
          <p>{{c.reason}}</p>
          <p v-if="!c.can_decide" class="text-xs text-slate-500">{{Number(c.requested_by)===can.userId?'طلبته أنت؛ ':''}}بانتظار قرار رئيس قسم الموارد البشرية، أو المدير التنفيذي إن كان رئيس القسم مقدمه أو صاحب البصمة.</p>
          <div v-else class="flex flex-wrap gap-2"><input v-model="reviewNotes[c.id]" class="field min-w-0 flex-1" maxlength="1000" placeholder="ملاحظة القرار (اختيارية)" aria-label="ملاحظة القرار" /><button type="button" class="btn btn-primary btn-sm" :disabled="correctionBusy" @click="decideManual(c,'approve')">اعتماد</button><button type="button" class="btn btn-danger btn-sm" :disabled="correctionBusy" @click="decideManual(c,'reject')">رفض</button></div>
        </div>
      </div>
      <div class="card space-y-3">
        <h2 class="card-title">التصحيحات التلقائية لاتجاه البصمة</h2>
        <p class="text-sm text-slate-600">التصحيح التلقائي {{autoCorrection.enabled?'مفعّل':'معطّل'}}. يُطبق فقط عند بصمتين بالاتجاه نفسه قرب بداية الدوام ونهايته، بعد انتهاء الدوام بساعتين، دون أي بصمة أو طلب آخر. البصمة الأصلية لا تتغير، والتراجع يتطلب سببًا ولا يُعاد التطبيق بعده.</p>
        <p v-if="!autoCorrection.recent?.length" class="text-sm text-slate-500">لا توجد تصحيحات تلقائية.</p>
        <div v-for="c in autoCorrection.recent" :key="c.id" class="space-y-2 rounded-lg border border-slate-200 p-3 text-sm" :class="c.status==='reverted'?'bg-slate-50':''">
          <div class="flex flex-wrap items-center gap-x-4 gap-y-1"><strong>{{personName(c)}}</strong><span dir="ltr">{{localTime(c.occurred_at)}}</span><span>{{dirLabel(c.original_direction)}} ← {{dirLabel(c.corrected_direction)}}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="c.status==='applied'?'bg-emerald-100 text-emerald-800':'bg-slate-200 text-slate-700'">{{c.status==='applied'?'فعّال':'ملغى'}}</span><span class="text-xs text-slate-500">{{c.rule_code}} v{{c.rule_version}}</span></div>
          <p>{{c.reason_ar}}</p>
          <p v-if="c.status==='reverted'" class="text-slate-700">تراجع {{c.reverted_by_name||''}} في {{localTime(c.reverted_at)}}: {{c.reversion_reason}}</p>
          <div v-if="can.revertAuto&&c.status==='applied'" class="flex flex-wrap gap-2"><input v-model="revertReasons[c.id]" class="field min-w-0 flex-1" maxlength="1000" placeholder="سبب التراجع (إلزامي)" aria-label="سبب التراجع عن التصحيح" /><button type="button" class="btn btn-secondary btn-sm" :disabled="correctionBusy" @click="revertAuto(c)">التراجع عن التصحيح</button></div>
        </div>
      </div>
    </section>
    <section v-if="tab==='requests'&&sub.requests==='manual'&&can.manualPunch" class="card space-y-3"><h2 class="card-title">تسجيل حضور أو انصراف يدوي</h2>
      <form class="grid md:grid-cols-2 gap-3" @submit.prevent="post(manual,'/admin/attendance/manual')">
        <label class="block space-y-1 text-sm font-semibold text-slate-700">الموظف<select v-model="manual.employee_id" required class="field"><option value="">اختر</option><option v-for="e in employees" :key="e.id" :value="e.id">{{employeeName(e.id)}}</option></select></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">التاريخ والوقت<input v-model="manual.occurred_at" type="datetime-local" required class="field" /></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">نوع الحركة<select v-model="manual.direction" class="field"><option value="in">حضور</option><option value="out">انصراف</option></select></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">سبب الإدخال<textarea v-model="manual.reason" required minlength="5" class="field" /></label>
        <p v-for="(message, key) in manual.errors" :key="key" class="field-error md:col-span-2">{{ message }}</p>
        <button class="btn btn-primary" :disabled="manual.processing">حفظ الحركة</button>
      </form>
    </section>

    <!-- ===== الإجراءات الإدارية ===== -->
    <HrActionsPanel v-if="tab==='actions'&&can.viewHrActions" :employees="employees" />

    <!-- ===== الأجهزة والمزامنة ===== -->
    <section v-if="tab==='devices'&&sub.devices==='punches'" class="card overflow-x-auto"><h2 class="card-title mb-3">آخر 100 حركة</h2><table class="data-table"><thead><tr><th class="p-2">الموظف</th><th>الوقت</th><th>الحركة</th><th>المصدر</th><th>السبب</th></tr></thead><tbody><tr v-for="p in punches" :key="p.id" class="border-t"><td class="p-2">{{p.first_name_ar||p.raw_user_id||'غير مطابق'}} {{p.family_name_ar}}</td><td>{{p.occurred_at}}</td><td>{{p.direction==='in'?'حضور':p.direction==='out'?'انصراف':'خام — بانتظار سياسة الرموز'}}</td><td>{{p.source}}</td><td>{{p.reason||'—'}}</td></tr></tbody></table></section>
    <section v-if="tab==='devices'&&sub.devices==='reports'&&can.report" class="card space-y-3"><h2 class="card-title">تقرير الحضور (CSV)</h2><p class="text-sm">يعرض النتائج التي تم احتسابها مسبقًا، ولا يعيد احتساب البيانات أو الأرصدة. للتقرير الكامل بالألوان استخدم «تصدير Excel» في سجل الدوام.</p><div class="grid md:grid-cols-3 gap-2"><label class="block space-y-1 text-sm font-semibold text-slate-700">من<input type="date" class="field" v-model="report.from" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">إلى<input type="date" class="field" v-model="report.to" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">الموظف<select class="field" v-model="report.employee_id"><option value="">الجميع</option><option v-for="e in employees" :key="e.id" :value="e.id">{{employeeName(e.id)}}</option></select></label></div><button type="button" class="btn btn-primary" @click="downloadReport">تصدير CSV</button></section>
    <section v-if="tab==='devices'&&sub.devices==='devices'" class="card space-y-3"><h2 class="card-title">أجهزة الحضور وحالة الاتصال</h2>
      <p class="text-sm text-slate-600">تُحدّث الحالة المخزنة كل بضع ثوانٍ. فحص الجهاز الفعلي مستقل ويعمل كل فترة محددة للجهاز، ولا تقرأ هذه الصفحة سجلات البصمة.</p>
      <a v-if="can.operations" href="/admin/attendance/operations" class="btn btn-secondary">نتائج المعاينة واعتماد الاستيراد والمراجعة</a>
      <p v-if="!safeImportReady" class="text-amber-700">الاستيراد الآمن متوقف حتى نشر الترحيل المعتمد.</p>
      <p v-if="deviceStatusPollingError" role="status" class="rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-800">تعذر تحديث الحالة الآن؛ لن تُعرض الحالة السابقة على أنها متصلة.</p>
      <p v-if="!can.manageDevices" class="text-sm text-slate-600">للاطلاع على حالة الأجهزة. إضافة جهاز وتعديل إعداداته وربط أرقام البصمة لرئيس قسم الموارد البشرية أو مدير النظام.</p>
      <form v-if="can.manageDevices" class="grid md:grid-cols-2 gap-3" @submit.prevent="post(device,'/admin/attendance/devices')">
        <label class="block space-y-1 text-sm font-semibold text-slate-700">اسم الجهاز<input v-model="device.name" required class="field" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">الموقع<input v-model="device.location" class="field" /></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">عنوان IPv4 المعتمد<input v-model="device.host" required class="field" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">المنفذ الخارجي<input v-model.number="device.port" type="number" min="1" max="65535" required class="field" /></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">البروتوكول<select v-model="device.protocol" class="field"><option value="auto">تلقائي</option><option value="tcp">TCP</option><option value="udp">UDP</option></select></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">الوكيل المحلي المعيّن<input v-model="device.assigned_agent" required maxlength="100" pattern="[A-Za-z0-9_-]+" class="field" /></label>
        <label class="block space-y-1 text-sm font-semibold text-slate-700">فاصل الفحص الفعلي (بالثواني)<input v-model.number="device.probe_interval_seconds" type="number" min="30" max="3600" step="30" required class="field" /></label>
        <label class="flex items-center gap-2 text-sm font-semibold text-slate-700"><input v-model="device.probe_enabled" type="checkbox" /> تفعيل فحص الاتصال الدوري (منفصل عن مزامنة الحركات)</label>
        <template v-if="safeImportReady">
          <label class="block space-y-1 text-sm">برنامج الاتصال<select v-model="device.driver" class="field"><option value="pyzk">ZKTeco / pyzk</option></select></label>
          <label class="block space-y-1 text-sm">المنطقة الزمنية للجهاز (مثل Asia/Gaza)<input v-model="device.timezone" required class="field" /></label>
          <label class="block space-y-1 text-sm">مهلة الاتصال بالثواني<input v-model.number="device.connection_timeout" type="number" min="1" max="15" required class="field" /></label>
          <label class="block space-y-1 text-sm">فاصل المزامنة بالثواني<input v-model.number="device.sync_interval_seconds" type="number" min="60" max="86400" required class="field" /></label>
          <label class="block space-y-1 text-sm">كلمة اتصال الجهاز (اتركها فارغة للإبقاء عليها)<input v-model="device.communication_password" type="password" autocomplete="new-password" class="field" /></label>
        </template>
        <p v-for="(message, key) in device.errors" :key="key" class="field-error md:col-span-2">{{ message }}</p>
        <button class="btn btn-primary" :disabled="device.processing">إضافة الجهاز</button>
      </form>
      <div v-for="d in devices" :key="d.id" class="space-y-3 border-t p-3">
        <div class="flex flex-wrap items-center gap-3">
          <strong>{{d.name}}</strong><span>{{d.host}}:{{d.port}} ({{d.protocol}})</span><span>{{d.location||'—'}}</span>
          <button v-if="can.manageDevices" type="button" @click="beginDeviceEdit(d)" class="btn btn-secondary btn-sm">تعديل الإعدادات</button>
          <button v-if="canManageDeviceConnections&&d.is_active&&d.probe_enabled" type="button" @click="requestDeviceProbe(d)" class="btn btn-secondary btn-sm" :disabled="probeRequestForm.processing">اختبار الاتصال</button>
          <button v-if="canManageDeviceConnections&&!d.is_active" type="button" @click="updateDeviceConnection(d,true,'disabled')" class="btn btn-secondary btn-sm" :disabled="deviceConnection.processing">اعتماد اتصال الفحص دون مزامنة</button>
          <button v-if="canManageDeviceConnections&&d.is_active&&d.sync_mode==='pull'" type="button" class="btn btn-secondary btn-sm inline-flex items-center gap-1" :disabled="syncRequestForm.processing||!syncEnabled" :title="syncEnabled?'سحب الحركات من الجهاز الآن':'فعّل مزامنة الحركات في إعدادات الخادم أولًا'" :aria-label="syncEnabled?'سحب الحركات من الجهاز الآن':'مزامنة الحركات غير مفعلة على الخادم'" @click="requestDeviceSync(d)">
            <svg v-if="syncRequestForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M12 3a9 9 0 019 9h-3a6 6 0 00-6-6V3z"/></svg>
            <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 20h14"/></svg>
            <span>{{d.import_approved_at?'مزامنة الآن':'معاينة الاستيراد الأول'}}</span>
          </button>
          <button v-if="canManageDeviceConnections&&d.is_active&&d.sync_mode==='pull'" type="button" @click="updateDeviceConnection(d,true,'disabled')" class="btn btn-secondary btn-sm" :disabled="deviceConnection.processing">إيقاف مزامنة الحركات</button>
          <button v-if="canManageDeviceConnections&&d.is_active&&d.sync_mode!=='pull'" type="button" @click="updateDeviceConnection(d,true,'pull')" class="btn btn-secondary btn-sm" :disabled="deviceConnection.processing">تفعيل مزامنة الحركات</button>
          <button v-if="canManageDeviceConnections&&d.is_active" type="button" @click="updateDeviceConnection(d,false,'disabled')" class="btn btn-secondary btn-sm" :disabled="deviceConnection.processing">تعطيل الجهاز</button>
        </div>
        <div class="grid gap-2 rounded-lg border border-slate-200 p-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
          <p>حالة الجهاز: <strong :class="statusTone(visibleDeviceState(statusForDevice(d)))">{{stateLabel(visibleDeviceState(statusForDevice(d)))}}</strong></p>
          <p>حالة الوكيل: <strong :class="statusTone(visibleStatus(statusForDevice(d).agent_state))">{{stateLabel(visibleStatus(statusForDevice(d).agent_state))}}</strong></p>
          <p>مزامنة الحضور: <strong :class="statusTone(visibleStatus(statusForDevice(d).sync_state))">{{stateLabel(visibleStatus(statusForDevice(d).sync_state))}}</strong></p>
          <p>آخر فحص فعلي: {{statusDate(statusForDevice(d).last_probe_at)}}</p>
          <p>آخر نجاح اتصال: {{statusDate(statusForDevice(d).last_success_at)}}</p>
          <p>زمن الاستجابة: {{statusForDevice(d).probe_latency_ms===null||statusForDevice(d).probe_latency_ms===undefined?'—':`${statusForDevice(d).probe_latency_ms} ms`}}</p>
          <p>آخر ظهور للوكيل: {{statusDate(statusForDevice(d).agent_last_seen_at)}}</p>
          <p>آخر مزامنة ناجحة: {{statusDate(statusForDevice(d).last_sync_success_at)}}</p>
          <p v-if="statusForDevice(d).firmware">البرنامج الثابت: {{statusForDevice(d).firmware}} — {{statusForDevice(d).platform}}</p>
          <p v-if="statusForDevice(d).clock_drift_seconds!==null && statusForDevice(d).clock_drift_seconds!==undefined">فرق ساعة الجهاز: {{statusForDevice(d).clock_drift_seconds}} ثانية</p>
          <p v-if="statusForDevice(d).last_failure_at">آخر فشل: {{statusDate(statusForDevice(d).last_failure_at)}} — {{statusForDevice(d).last_failure_code}}</p>
          <p v-if="statusForDevice(d).sync_failure_count>0">محاولات مزامنة فاشلة متتالية: {{statusForDevice(d).sync_failure_count}}<template v-if="statusForDevice(d).sync_retry_at"> — التالية تلقائيًا {{shortTime(statusForDevice(d).sync_retry_at)}}</template></p>
          <p v-if="statusForDevice(d).latest_run?.received!==undefined">{{statusForDevice(d).latest_run.dry_run?'معاينة':'استيراد خام'}}: {{statusForDevice(d).latest_run.received}} مستلمة، {{statusForDevice(d).latest_run.inserted}} جديدة، {{statusForDevice(d).latest_run.duplicates}} مكررة، {{statusForDevice(d).latest_run.unmatched}} غير مطابقة</p>
          <p>الوكيل: {{d.assigned_agent||agentId}} — فحص فعلي كل {{statusForDevice(d).probe_interval_seconds}} ثانية</p>
          <p v-if="statusForDevice(d).probe_error_code" class="text-red-700">رمز نتيجة الفحص: {{statusForDevice(d).probe_error_code}}</p>
        </div>
        <p v-if="syncRequestForm.errors.sync" role="alert" class="text-sm text-red-700">{{syncRequestForm.errors.sync}}</p>
      </div>
      <form v-if="can.manageDevices&&editDeviceId!==null" class="grid gap-2 rounded border p-3 md:grid-cols-2" @submit.prevent="saveDeviceEdit"><h3 class="font-bold md:col-span-2">تعديل الجهاز</h3><label class="block space-y-1 text-sm font-semibold text-slate-700">الاسم<input class="field" required v-model="editDevice.name" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">الموقع<input class="field" v-model="editDevice.location" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">IP أو المضيف<input class="field" required v-model="editDevice.host" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">المنفذ<input class="field" type="number" min="1" max="65535" required v-model.number="editDevice.port" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">البروتوكول<select class="field" v-model="editDevice.protocol"><option value="auto">تلقائي</option><option value="tcp">TCP</option><option value="udp">UDP</option></select></label><label class="block space-y-1 text-sm font-semibold text-slate-700">الوكيل المحلي المعيّن<input class="field" required maxlength="100" pattern="[A-Za-z0-9_-]+" v-model="editDevice.assigned_agent" /></label><label class="block space-y-1 text-sm font-semibold text-slate-700">فاصل الفحص الفعلي (بالثواني)<input class="field" type="number" min="30" max="3600" step="30" required v-model.number="editDevice.probe_interval_seconds" /></label><label class="flex items-center gap-2 text-sm font-semibold text-slate-700"><input v-model="editDevice.probe_enabled" type="checkbox" /> تفعيل الفحص الدوري</label>        <template v-if="safeImportReady">
          <label class="block space-y-1 text-sm">برنامج الاتصال<select v-model="editDevice.driver" class="field"><option value="pyzk">ZKTeco / pyzk</option></select></label>
          <label class="block space-y-1 text-sm">المنطقة الزمنية للجهاز (مثل Asia/Gaza)<input v-model="editDevice.timezone" required class="field" /></label>
          <label class="block space-y-1 text-sm">مهلة الاتصال بالثواني<input v-model.number="editDevice.connection_timeout" type="number" min="1" max="15" required class="field" /></label>
          <label class="block space-y-1 text-sm">فاصل المزامنة بالثواني<input v-model.number="editDevice.sync_interval_seconds" type="number" min="60" max="86400" required class="field" /></label>
          <label class="block space-y-1 text-sm">كلمة اتصال الجهاز (اتركها فارغة للإبقاء عليها)<input v-model="editDevice.communication_password" type="password" autocomplete="new-password" class="field" /></label>
        </template><p v-for="(message, key) in editDevice.errors" :key="key" class="field-error md:col-span-2">{{ message }}</p><div><button class="btn btn-primary" :disabled="editDevice.processing">حفظ</button><button type="button" class="btn btn-secondary btn-sm" @click="editDeviceId=null">إلغاء</button></div></form>
      <form v-if="can.manageDevices" class="grid md:grid-cols-3 gap-2 border rounded p-3" @submit.prevent="post(mapping,'/admin/attendance/mappings')"><h3 class="text-sm font-bold text-navy-800 md:col-span-3">ربط رقم البصمة بالموظف</h3><select class="field" required v-model="mapping.device_id"><option value="">الجهاز</option><option v-for="d in devices" :key="d.id" :value="d.id">{{d.name}}</option></select><select class="field" required v-model="mapping.employee_id"><option value="">الموظف</option><option v-for="e in employees" :key="e.id" :value="e.id">{{employeeName(e.id)}}</option></select><input class="field" required placeholder="رقم المستخدم في الجهاز" v-model="mapping.device_user_id"/><button class="btn btn-primary" :disabled="mapping.processing">حفظ الربط</button><p v-for="(message, key) in mapping.errors" :key="key" class="field-error md:col-span-3">{{ message }}</p></form>
      <p class="text-sm text-amber-800">فحص الاتصال لا يقرأ الحركات. تظل مزامنة سجلات الحضور عملية مستقلة وتخضع لإعداداتها وصلاحياتها الحالية. لا تُفعّل الفحص إلا بعد إضافة عنوان الجهاز إلى قائمة المضيفين المسموحين على الخادم.</p>
    </section>
  </div>
</AppLayout>
</template>
