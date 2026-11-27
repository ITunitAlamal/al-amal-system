<script setup>
import {computed, ref, onMounted, watch} from 'vue';
import axios from 'axios';
import {router, usePage} from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import AppIcon from '../../Components/AppIcon.vue';

const props=defineProps({summary:Object,allowed:Array,transactionKinds:{type:Array,default:()=>[]},transactionKind:String,canManageDepartmentBudgets:{type:Boolean,default:false}});
const allowed=ref(props.allowed||[]),metadata=ref({summary:props.summary||{},lookups:{},alerts:[]});
// One side menu. An entry appears only to holders of one of its permissions (the server enforces the same rules).
// In the documentary cycle the server refuses the bookkeeping permissions, so the «المحاسبة» entries disappear.
const procurement=['finance.procurement.manage','finance.procurement.review'];
const paymentPermissions=['finance.payment.draft','finance.payment.review','finance.payment.approve','finance.payment.execute'];
const receiptPermissions=['finance.receipt.draft','finance.receipt.review','finance.receipt.approve','finance.receipt.execute'];
const cyclePermissions=[...procurement,'finance.procurement.director_approve','finance.procurement.board_approve','finance.purchase.receive','finance.purchase.invoice','finance.payment.record','finance.warehouse.manage','finance.stock.receive','finance.stock.issue','finance.stock.count','finance.stock.count.approve','finance.sponsorships.record'];
// Functional units of Accounts. Multiple assigned permissions add their sections without sharing execution rights.
// No area inherits another's permissions: each entry lists only its own.
const groups=[
 {title:'نظرة عامة',icon:'chart',items:[{id:'pending',name:'بانتظار إجرائي',icon:'inbox',any:cyclePermissions},{id:'overview',name:'الدورة المستندية',icon:'git-branch',any:cyclePermissions}]},
 {title:'المشتريات',icon:'shopping-cart',items:[
  {id:'purchases',name:'طلبات الشراء',icon:'shopping-cart',any:[...procurement,'finance.procurement.director_approve','finance.procurement.board_approve']},
  {id:'quotes',name:'عروض الأسعار',icon:'clipboard-list',any:procurement},
  {id:'orders',name:'أوامر الشراء',icon:'file-text',any:procurement},
  {id:'suppliers',name:'الموردون',icon:'handshake',any:procurement},
 ]},
 // The storekeeper and the accountant reach purchase orders only as their hand-off (the server lists the same states).
 {title:'المخازن',icon:'boxes',items:[
  {id:'orders',name:'أوامر بانتظار الاستلام',icon:'file-text',any:['finance.purchase.receive'],unless:procurement,monitor:false},
  {id:'purchase_receipts',name:'الاستلام',icon:'package-check',any:[...procurement,'finance.purchase.receive','finance.purchase.invoice']},
  {id:'stock',name:'الصرف وحركات المخزون',icon:'boxes',any:['finance.stock.receive','finance.stock.issue','finance.warehouse.manage']},
  {id:'items',name:'الأصناف',icon:'list',any:['finance.warehouse.manage']},
  {id:'warehouses',name:'تعريف المخازن',icon:'building',any:['finance.warehouse.manage']},
  {id:'counts',name:'الجرد',icon:'clipboard-list',any:['finance.stock.count','finance.stock.count.approve']},
 ]},
 {title:'المحاسب العام',icon:'wallet',items:[
  {id:'orders',name:'أوامر بانتظار الفاتورة',icon:'file-text',any:['finance.purchase.invoice'],unless:[...procurement,'finance.purchase.receive'],monitor:false},
  {id:'invoices',name:'فواتير الموردين',icon:'receipt',any:['finance.purchase.invoice','finance.procurement.review']},
  {id:'treasuries',name:'الصناديق والبنوك',icon:'banknote',any:['finance.treasury.manage']},
  {id:'receivables',name:'المستحقات',icon:'list',any:['finance.receivable.manage']},
  {id:'transfers',name:'التحويلات',icon:'coins',any:['finance.transfer.execute']},
  {id:'advances',name:'العهد والسلف',icon:'wallet',any:['finance.advance.manage']},
  {id:'cheques',name:'الشيكات',icon:'file-text',any:['finance.cheque.manage']},
  {id:'reconciliations',name:'المطابقة',icon:'book-open',any:['finance.reconcile']},
  {id:'budgets',name:'الموازنات',icon:'chart',any:['finance.budget.manage']},
  {id:'budget_exceptions',name:'استثناءات الموازنة',icon:'list',any:['finance.budget.transfer']},
  {id:'budget_transfers',name:'مناقلات الموازنة',icon:'list',any:['finance.budget.transfer']},
  {id:'funds',name:'التمويل المخصص',icon:'coins',any:['finance.fund.manage']},
  {id:'fund_movements',name:'حركات الأموال المقيدة',icon:'list',any:['finance.fund.manage']},
 ]},
 {title:'الصرف',icon:'banknote',items:[
  {id:'payments',name:'سندات الصرف',icon:'banknote',any:paymentPermissions},
  {id:'payment_vouchers',name:'سندات الصرف على فواتير الموردين',icon:'receipt',any:['finance.payment.record']},
 ]},
 {title:'القبض',icon:'hand-coins',items:[
  {id:'receipts',name:'سندات القبض',icon:'hand-coins',any:receiptPermissions},
  {id:'rentals',name:'قبض الإيجارات وتسوياتها',icon:'building',any:['finance.rentals.record'],href:'/finance/rentals'},
  {id:'sponsorship_receipts',name:'قبض الكفالات',icon:'hand-coins',any:['finance.sponsorships.record'],href:'/finance/sponsorships'},
 ]},
 {title:'الرواتب',icon:'users',items:[
  {id:'payroll_runs',name:'مسير الرواتب والقسائم',icon:'users',any:['finance.payroll.record','finance.payroll.publish','finance.monitor'],href:'/finance/payroll-runs'},
  {id:'payroll',name:'إعداد الرواتب',icon:'users',any:['finance.payroll.manage','finance.payroll.approve']},
  {id:'payroll_items',name:'بنود الرواتب',icon:'list',any:['finance.payroll.manage','finance.payroll.approve']},
 ]},
 {title:'التقارير',icon:'file-spreadsheet',items:[{id:'reports',name:'التقارير والتصدير',icon:'file-spreadsheet',any:['finance.reports.view']},{id:'archive',name:'السجل',icon:'save',any:[...procurement,'finance.purchase.invoice']}]},
 {title:'إدارة القسم',icon:'users',items:[{id:'staff',name:'موظفو القسم وصلاحياتهم',icon:'users',any:['finance.staff.assign'],href:'/finance/staff'},{id:'department_budgets',name:'ميزانيات الأقسام',icon:'chart',any:[],headOnly:true,href:'/finance/department-budgets'}]},
];
// The head's consolidated monitoring (finance.monitor) opens every unit's lists read-only; the server refuses any
// change, and individual salary lines stay with payroll staff.
const MONITOR_ITEMS=['overview','purchases','quotes','orders','suppliers','purchase_receipts','stock','items','warehouses','counts','invoices','archive'];
const areaMeta={procurement:['المشتريات','shopping-cart'],stores:['المخازن','boxes'],accountant:['المحاسب العام','wallet'],payments:['الصرف','banknote'],receipts:['القبض','hand-coins'],payroll:['مسير الرواتب','users']};
const modules=groups.flatMap(g=>g.items);
// The same field descriptors power accessible RTL forms and API validation-compatible payloads.
const f=(key,label,type='text',opts={})=>({key,label,type,...opts});
const schemas={
 treasuries:{url:'/finance/catalog/treasuries',permission:'finance.treasury.manage',fields:[f('code','الرمز'),f('name','اسم الصندوق أو الحساب'),f('kind','النوع','enum',{choices:[['cash','صندوق نقدي'],['bank','حساب بنكي']]}),f('currency','العملة','lookup',{source:'currencies'}),f('bank_name','اسم البنك','text',{optional:true}),f('account_mask','معرف الحساب المحجوب','text',{optional:true})]},
 budgets:{url:'/finance/catalog/budgets',permission:'finance.budget.manage',fields:[f('fiscal_year','السنة المالية','number'),f('department_id','القسم','lookup',{source:'departments'}),f('code','رمز البند'),f('title','بند الموازنة'),f('currency','العملة','lookup',{source:'currencies'}),f('approved_amount','الاعتماد السنوي','money')]},
 suppliers:{url:'/finance/catalog/suppliers',permission:'finance.procurement.manage',fields:[f('name','اسم المورد'),f('registration_no','رقم التسجيل','text',{optional:true}),f('phone','الهاتف','text',{optional:true}),f('email','البريد الإلكتروني','email',{optional:true})]},
 warehouses:{url:'/finance/catalog/warehouses',permission:'finance.warehouse.manage',fields:[f('code','رمز المخزن'),f('name','اسم المخزن')]},
 items:{url:'/finance/catalog/items',permission:'finance.warehouse.manage',fields:[f('sku','رمز الصنف'),f('name','اسم الصنف'),f('unit','وحدة القياس'),f('minimum_qty','الحد الأدنى','quantity')]},
 funds:{url:'/finance/catalog/funds',permission:'finance.fund.manage',fields:[f('name','اسم التمويل'),f('purpose','الغرض المخصص'),f('currency','العملة','lookup',{source:'currencies'})]},
 receivables:{url:'/finance/receivables',permission:'finance.receivable.manage',fields:[f('reference','رقم الاستحقاق'),f('party','الجهة المستحقة عليها'),f('currency','العملة','lookup',{source:'currencies'}),f('amount','المبلغ','money'),f('due_on','تاريخ الاستحقاق','date'),f('kind','التصنيف','enum',{choices:[['rent','إيجار'],['service','خدمة'],['donation','تبرع'],['sponsorship','كفالة'],['grant','منحة'],['other','أخرى']]}),f('description','البيان','textarea',{optional:true})]},
 payments:{url:'/finance/transactions',permission:'finance.payment.draft',kind:'payment',fields:[f('treasury_id','الصندوق أو البنك','lookup',{source:'treasuries'}),f('budget_line_id','بند الموازنة','lookup',{source:'budgets'}),f('amount','المبلغ','money'),f('effective_on','تاريخ الصرف','date'),f('counterparty','المستفيد','text',{optional:true}),f('description','البيان','textarea',{optional:true})]},
 receipts:{url:'/finance/transactions',permission:'finance.receipt.draft',kind:'receipt',fields:[f('treasury_id','الصندوق أو البنك','lookup',{source:'treasuries'}),f('amount','المبلغ','money'),f('effective_on','تاريخ القبض','date'),f('counterparty','الدافع','text',{optional:true}),f('description','البيان','textarea',{optional:true})]},
 purchases:{url:'/finance/purchases',permission:'finance.procurement.manage',fields:[f('department_id','القسم الطالب','lookup',{source:'departments'}),f('budget_line_id','بند الموازنة','lookup',{source:'budgets'}),f('currency','العملة','lookup',{source:'currencies'}),f('purpose','سبب الطلب','textarea'),f('source_module','القسم المصدر','text',{optional:true}),f('source_id','مرجع القسم المصدر','text',{optional:true})],array:'items',arrayFields:[f('item_name','الصنف'),f('quantity','الكمية','quantity'),f('unit','الوحدة'),f('unit_price','سعر الوحدة','price')]},
 stock:{url:'/finance/stock-movements',permission:'finance.stock.receive',fields:[f('warehouse_id','المخزن','lookup',{source:'warehouses'}),f('stock_item_id','الصنف','lookup',{source:'stock_items'}),f('kind','نوع الحركة','enum',{choices:[['receipt','استلام'],['issue','صرف'],['return_in','مرتجع داخل'],['return_out','مرتجع خارج']]}),f('quantity','الكمية','quantity'),f('unit_cost','تكلفة الوحدة','price'),f('reference_type','مرجع الحركة','enum',{choices:[['aims_request','صرف: طلب صيانة أو شراء بانتظار المخزن (رقم الطلب)'],['assistance_request','صرف: مساعدة معتمدة لمستفيد (رقمها)'],['sponsorship','صرف: تسليم عيني من كفالة سارية (رقم الكفالة)'],['stock_movement','مرتجع داخل: حركة الصرف الأصلية (رقمها)'],['purchase_receipt','مرتجع للمورد: محضر استلام المشتريات (GRN)'],['receipt_record','استلام: محضر استلام موثق (تبرع عيني أو رصيد افتتاحي)']]}),f('reference_id','رقم المرجع'),f('note','ملاحظات','text',{optional:true})]},
 counts:{url:'/finance/stock-counts',permission:'finance.stock.count',fields:[f('warehouse_id','المخزن','lookup',{source:'warehouses'}),f('stock_item_id','الصنف','lookup',{source:'stock_items'}),f('physical_qty','الكمية الفعلية','quantity'),f('reason','سبب الجرد أو الفرق','textarea')]},
 transfers:{url:'/finance/transfers',permission:'finance.transfer.execute',fields:[f('from_id','من صندوق/بنك','lookup',{source:'treasuries'}),f('to_id','إلى صندوق/بنك','lookup',{source:'treasuries'}),f('amount','المبلغ','money'),f('reference','مرجع التحويل')]},
 budget_transfers:{url:'/finance/budgets/transfers',permission:'finance.budget.transfer',fields:[f('from_line_id','من بند','lookup',{source:'budgets'}),f('to_line_id','إلى بند','lookup',{source:'budgets'}),f('amount','المبلغ','money'),f('reason','مبرر المناقلة','textarea')]},
 advances:{url:'/finance/advances',permission:'finance.advance.manage',fields:[f('user_id','المستفيد','lookup',{source:'users'}),f('treasury_id','الصندوق/البنك','lookup',{source:'treasuries'}),f('amount','القيمة','money'),f('due_on','تاريخ التسوية','date',{optional:true}),f('purpose','الغرض','textarea')]},
 reconciliations:{url:'/finance/reconciliations',permission:'finance.reconcile',fields:[f('treasury_id','الصندوق/البنك','lookup',{source:'treasuries'}),f('as_of','حتى تاريخ','date'),f('actual','الرصيد الفعلي','money'),f('notes','ملاحظات','textarea',{optional:true})]},
 payroll:{url:'/finance/payroll',permission:'finance.payroll.manage',fields:[f('period','فترة الرواتب (YYYY-MM)'),f('currency','العملة','lookup',{source:'currencies'}),f('hr_approval_reference','مرجع اعتماد الموارد البشرية')],array:'items',arrayFields:[f('employee_id','الموظف','lookup',{source:'employees'}),f('gross','المستحق','money'),f('deductions','الخصم','money')]},
 cheques:{url:'/finance/cheques',permission:'finance.cheque.manage',fields:[f('transaction_id','رقم سند القبض','number'),f('cheque_no','رقم الشيك'),f('bank_name','اسم البنك'),f('due_on','تاريخ الاستحقاق','date')]},
};
const active=ref('overview'),records=ref([]),busy=ref(false),error=ref(''),success=ref(''),form=ref({}),creating=ref(false),selectedPurchase=ref(null),quoteForm=ref({supplier_id:'',amount:'',currency:'',notes:''});
const current=computed(()=>modules.find(x=>x.id===active.value));
const schema=computed(()=>{
 const definition=schemas[active.value];
 // In the documentary cycle the budget is controlled in the external accounting software.
 if(active.value==='purchases'&&definition&&documentary.value)return {...definition,fields:definition.fields.filter(field=>field.key!=='budget_line_id')};
 return definition;
});
const recordPage=ref({current_page:1,last_page:1,total:0});
const can=(p)=>allowed.value.includes(p);
const menu=computed(()=>groups.map(g=>({...g,items:g.items.filter(item=>item.headOnly?props.canManageDepartmentBudgets:item.monitor===false
  ?item.any.some(can)&&!(item.unless||[]).some(can)&&!can('finance.monitor')
  :item.any.some(can)||(can('finance.monitor')&&MONITOR_ITEMS.includes(item.id)))})).filter(g=>g.items.length));
const documentary=computed(()=>!!metadata.value.mode?.documentary);
const externalSystem=computed(()=>metadata.value.mode?.external_system||'البرنامج المحاسبي');
const cycle=ref({stages:[],pending:[]}),archive=ref({data:[],current_page:1,last_page:1,total:0}),archiveSearch=ref('');
async function loadCycle(){busy.value=true;try{const {data}=await axios.get('/finance/cycle');cycle.value=data;}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
async function loadArchive(page=1){busy.value=true;try{const {data}=await axios.get('/finance/archive',{params:{q:archiveSearch.value||undefined,page}});archive.value=data;}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
// Shortcuts from a row or a pending item to the form that completes it.
function prefill(id,values){chosenOperation.value=id;startOperation();Object.assign(operationForm.value,values);setTimeout(()=>document.getElementById('finance-operations')?.scrollIntoView({behavior:'smooth',block:'start'}),60);}
function rowForms(r){const a=[];
 if(active.value==='orders'&&can('finance.purchase.receive')&&['issued','partial_received'].includes(r.state))a.push(['goods','استلام الأصناف',{order_id:r.id}]);
 if(active.value==='orders'&&can('finance.purchase.invoice')&&['partial_received','received'].includes(r.state))a.push(['invoice','تسجيل فاتورة المورد',{order_id:r.id}]);
 if(['invoices','payment_vouchers'].includes(active.value)&&documentary.value&&can('finance.payment.record')&&r.state!=='paid')a.push(['externalVoucher','تسجيل رقم سند الصرف',{invoice_id:r.id}]);
 return a;}
async function openPending(item){
 if(item.module==='sponsorship_receipts'){router.visit(`/finance/sponsorships?due=${item.id}`);return;}
 const forms={receive:['goods',{order_id:item.id}],invoice:['invoice',{order_id:item.id}],voucher:['externalVoucher',{invoice_id:item.id}]};
 const module=item.kind==='voucher'?'payment_vouchers':item.module;
 await openModule(module);
 syncUrl(module);
 if(forms[item.kind]){prefill(...forms[item.kind]);return;}
 if(item.module==='purchases')await openPurchase(item.id);}
const canCreate=computed(()=>schema.value&&can(schema.value.permission));
const human={id:'الرقم',document_no:'السند',number:'رقم الطلب',name:'الاسم',title:'البند',amount:'المبلغ',total_amount:'الإجمالي',approved_amount:'المعتمد',currency:'العملة',state:'الحالة',created_at:'تاريخ الإنشاء',code:'الرمز',purpose:'الغرض',effective_on:'التاريخ',reference:'المرجع',due_on:'تاريخ الاستحقاق',period:'الفترة',kind:'النوع',quantity:'الكمية',counterparty:'الجهة',party:'الجهة',invoice_no:'الفاتورة',purchase_request_id:'طلب الشراء',order_id:'أمر الشراء'};
const money=(v)=>v===null||v===undefined?'—':String(v);
const fieldsFor=(r)=>Object.keys(r||{}).filter(k=>!['updated_at','source_id','metadata','password','notes','description','created_by','approved_by','reviewed_by','executed_by','director_approved_by','board_approved_by','created_at','payment_id','external_recorded_by','external_recorded_at','closure_released_amount','valuation_rate_id','budget_line_id'].includes(k)).slice(0,9);
Object.assign(human,{external_voucher_no:'سند الصرف (البرنامج المحاسبي)',external_voucher_on:'تاريخ السند',external_voucher_amount:'قيمة السند',invoice_on:'تاريخ الفاتورة',supplier_id:'المورد',department_id:'القسم',selected:'مختار',received_by:'المستلم',warehouse_id:'المخزن',stock_item_id:'الصنف',unit_cost:'تكلفة الوحدة',sku:'رمز الصنف',unit:'الوحدة',minimum_qty:'الحد الأدنى',physical_qty:'الكمية الفعلية',recorded_qty:'الكمية المسجلة',reason:'السبب',is_active:'فعال',registration_no:'رقم التسجيل',phone:'الهاتف',email:'البريد',evaluated_ils:'التقييم بالشيكل',source_module:'القسم المصدر'});
const currentFields=computed(()=>records.value.length?fieldsFor(records.value[0]):[]);
function blank(s){const v={};for(const x of s?.fields||[])v[x.key]=x.type==='date'?new Date().toISOString().slice(0,10):'';if(s?.array)v[s.array]=[Object.fromEntries(s.arrayFields.map(x=>[x.key,'']))];return v;}
function startCreate(){form.value=blank(schema.value);creating.value=true;error.value='';success.value='';}
function addLine(){if(!schema.value?.array)return;form.value[schema.value.array].push(Object.fromEntries(schema.value.arrayFields.map(x=>[x.key,''])));}
function lookupOptions(field){return (metadata.value.lookups||{})[field.source]||[];}
function optionValue(field,item){return field.source==='currencies'?item.code:item.id;}
function optionText(field,item){return field.source==='currencies'?`${item.code} — ${item.name_ar}`:field.source==='employees'?`${item.employee_number} — ${item.first_name_ar} ${item.family_name_ar}`:field.source==='stock_items'?`${item.sku} — ${item.name}`:field.source==='budgets'?`${item.title} (${item.currency})`:field.source==='funds'?`${item.name} (${item.currency})`:item.name;}
const isCatalog=(m)=>['treasuries','budgets','suppliers','warehouses','items','funds'].includes(m);
function routeFor(m){if(['payments','receipts'].includes(m))return `/finance/records/transactions?kind=${m==='payments'?'payment':'receipt'}`;if(m==='payment_vouchers')return '/finance/records/invoices';return isCatalog(m)?`/finance/catalog/${m}`:`/finance/records/${m}`;}
async function refreshMeta(){try{const {data}=await axios.get('/finance/bootstrap');metadata.value=data;allowed.value=data.allowed||[];}catch(e){error.value=errorText(e);}}
function errorText(e){const errors=e.response?.data?.errors;return errors?Object.values(errors).flat().join(' — '):e.response?.data?.message||'تعذر إتمام العملية';}
async function openModule(m,pageNumber=1){active.value=m;creating.value=false;selectedPurchase.value=null;records.value=[];recordPage.value={current_page:1,last_page:1,total:0};error.value='';if(m==='overview'||m==='pending'){await loadCycle();return;}if(m==='archive'){await loadArchive(pageNumber);return;}if(m==='reports'){await loadReports();return;}busy.value=true;try{const {data}=await axios.get(routeFor(m),{params:{page:pageNumber}});if(active.value===m){records.value=data.data||[];recordPage.value={current_page:data.current_page||1,last_page:data.last_page||1,total:data.total||0};}}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
async function createRecord(){if(!schema.value)return;busy.value=true;error.value='';success.value='';try{const payload={...form.value,...(schema.value.kind?{kind:schema.value.kind}:{})};for(const x of schema.value.fields||[]){if(x.optional&&(payload[x.key]===''||payload[x.key]===undefined))payload[x.key]=null;}const {data}=await axios.post(schema.value.url,payload);success.value=`تم تسجيل العملية رقم ${data.id||'—'} بنجاح.`;creating.value=false;await refreshMeta();await openModule(active.value);}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
function actionsFor(r){let a=[];const add=(label,per,url,when)=>{if(when&&can(per))a.push({label,url});};
 if(['payments','receipts'].includes(active.value)){const kind=active.value==='payments'?'payment':'receipt';add('إرسال للمراجعة',`finance.${kind}.draft`,`/finance/transactions/${r.id}/submit`,r.state==='draft');add('مراجعة',`finance.${kind}.review`,`/finance/transactions/${r.id}/review`,r.state==='pending_review');add('اعتماد المدير',`finance.${kind}.approve`,`/finance/transactions/${r.id}/approve`,r.state==='pending_director');add('تنفيذ',`finance.${kind}.execute`,`/finance/transactions/${r.id}/execute`,r.state==='approved');add('طلب عكس','finance.reversal.request',`/finance/transactions/${r.id}/reverse`,r.state==='executed');}
 if(active.value==='purchases'){add('إرسال','finance.procurement.manage',`/finance/purchases/${r.id}/submit`,r.state==='draft');add('رئيس الحسابات','finance.procurement.review',`/finance/purchases/${r.id}/review`,r.state==='pending_finance_head');add('المدير التنفيذي','finance.procurement.director_approve',`/finance/purchases/${r.id}/director`,r.state==='pending_director');add('أمين الصندوق/المجلس','finance.procurement.board_approve',`/finance/purchases/${r.id}/board`,r.state==='pending_board_treasurer');}
 if(active.value==='orders')add('إغلاق أمر الشراء','finance.procurement.manage',`/finance/orders/${r.id}/close`,r.state==='received');
 if(active.value==='transfers'){add('اعتماد','finance.transfer.approve',`/finance/transfers/${r.id}/approve`,r.state==='pending_approval');add('تنفيذ','finance.transfer.execute',`/finance/transfers/${r.id}/execute`,r.state==='approved');}
 if(active.value==='budget_exceptions')add('اعتماد الاستثناء','finance.budget.approve',`/finance/budgets/exceptions/${r.id}/approve`,r.state==='pending');
 if(active.value==='budget_transfers')add('اعتماد المناقلة','finance.budget.approve',`/finance/budgets/transfers/${r.id}/approve`,r.state==='pending');
 if(active.value==='counts')add('اعتماد الجرد','finance.stock.count.approve',`/finance/stock-counts/${r.id}/approve`,r.state==='pending');
 if(active.value==='payroll')add('اعتماد المسير','finance.payroll.approve',`/finance/payroll/${r.id}/approve`,r.state==='pending_approval');
 if(active.value==='fund_movements')add('اعتماد حركة التمويل','finance.fund.approve',`/finance/fund-movements/${r.id}/approve`,r.state==='pending');
 return a;}
async function runAction(a){if(!confirm(`تأكيد إجراء: ${a.label}؟`))return;busy.value=true;error.value='';try{await axios.post(a.url,a.label==='طلب عكس'?{reason:prompt('سبب العكس (10 أحرف على الأقل)')||''}:{});success.value='تمت العملية وحفظ السجل.';await refreshMeta();await openModule(active.value);}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
async function openPurchase(id){busy.value=true;error.value='';try{const {data}=await axios.get(`/finance/purchases/${id}`);selectedPurchase.value=data;quoteForm.value={supplier_id:'',amount:'',currency:data.purchase.currency,notes:''};linesForm.value=data.items?.length?data.items.map(i=>({item_name:i.item_name,quantity:i.quantity,unit:i.unit,unit_price:i.unit_price})):[{item_name:'',quantity:'',unit:'',unit_price:''}];decisionNote.value='';}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
// Draft lines (a request from «طلباتي» arrives without prices) and the stage holder's reject/return with a reason.
const linesForm=ref([]),decisionNote=ref('');
const PURCHASE_STAGES={pending_finance_head:'finance.procurement.review',pending_director:'finance.procurement.director_approve',pending_board_treasurer:'finance.procurement.board_approve'};
const stagePermission=computed(()=>PURCHASE_STAGES[selectedPurchase.value?.purchase?.state]||null);
async function saveLines(){busy.value=true;error.value='';try{const id=selectedPurchase.value.purchase.id;await axios.post(`/finance/purchases/${id}/items`,{items:linesForm.value});await openPurchase(id);success.value='حُفظت البنود.';}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
const cancelReason=ref('');
const cancellableOrder=computed(()=>{const o=selectedPurchase.value?.orders?.[0];return !o||['issued','partial_received'].includes(o.state);});
async function cancelPurchase(){if(!confirm('تأكيد الإلغاء؟ يُحفظ السبب في سجل الطلب.'))return;await purchaseAction(`/finance/purchases/${selectedPurchase.value.purchase.id}/cancel`,{reason:cancelReason.value});cancelReason.value='';}
async function purchaseDecision(decision){await purchaseAction(`/finance/purchases/${selectedPurchase.value.purchase.id}/decision/${decision}`,{note:decisionNote.value});}
async function addQuote(){if(!selectedPurchase.value)return;busy.value=true;try{await axios.post(`/finance/purchases/${selectedPurchase.value.purchase.id}/quotes`,quoteForm.value);await openPurchase(selectedPurchase.value.purchase.id);success.value='تمت إضافة عرض السعر.';}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
async function purchaseAction(url,payload={}){busy.value=true;try{await axios.post(url,payload);const id=selectedPurchase.value.purchase.id;await openPurchase(id);await openModule('purchases');await openPurchase(id);success.value='تم حفظ الإجراء.';}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
// Contextual operations supplement the standard entry forms, without raw JSON input.
const op=(id,name,url,permission,fields,array=null,arrayFields=null)=>({id,name,url,permission,fields,array,arrayFields});
const operations=[
 op('budgetException','طلب استثناء للموازنة','/finance/budgets/exceptions','finance.budget.transfer',[f('budget_line_id','بند الموازنة','lookup',{source:'budgets'}),f('amount','المبلغ','money'),f('decision_reference','مرجع قرار الاستثناء'),f('reason','المبرر','textarea')]),
 op('stockTransfer','نقل صنف بين مخزنين','/finance/stock-transfers','finance.stock.issue',[f('from_id','المخزن المصدر','lookup',{source:'warehouses'}),f('to_id','المخزن الوجهة','lookup',{source:'warehouses'}),f('stock_item_id','الصنف','lookup',{source:'stock_items'}),f('quantity','الكمية','quantity'),f('reference','مرجع النقل')]),
 op('quote','عرض سعر لمورد','/finance/purchases/{purchase_id}/quotes','finance.procurement.manage',[f('purchase_id','رقم طلب الشراء','number'),f('supplier_id','المورد','lookup',{source:'suppliers'}),f('amount','قيمة العرض','money'),f('currency','العملة','lookup',{source:'currencies'})]),
 op('order','إصدار أمر شراء','/finance/purchases/{purchase_id}/orders','finance.procurement.manage',[f('purchase_id','رقم طلب الشراء','number')]),
 op('goods','استلام أصناف أمر شراء','/finance/orders/{order_id}/receipts','finance.purchase.receive',[f('order_id','رقم أمر الشراء','number'),f('notes','ملاحظات الاستلام','textarea',{optional:true})],'items',[f('purchase_item_id','رقم بند الشراء','number'),f('stock_item_id','الصنف','lookup',{source:'stock_items'}),f('warehouse_id','المخزن','lookup',{source:'warehouses'}),f('quantity','الكمية المقبولة','quantity'),f('unit_cost','تكلفة الوحدة','price'),f('rejected_qty','الكمية المرفوضة','quantity',{optional:true}),f('rejection_reason','سبب الرفض','text',{optional:true})]),
 op('closeOrder','إغلاق أمر شراء بعد الاستلام والسداد','/finance/orders/{order_id}/close','finance.procurement.manage',[f('order_id','رقم أمر الشراء','number')]),
 op('invoice','تسجيل فاتورة شراء','/finance/orders/{order_id}/invoices','finance.purchase.invoice',[f('order_id','رقم أمر الشراء','number'),f('invoice_no','رقم الفاتورة'),f('invoice_on','تاريخ الفاتورة','date'),f('amount','قيمة الفاتورة','money')]),
 op('invoiceDraft','إنشاء سند صرف من فاتورة','/finance/invoices/{invoice_id}/payments/draft','finance.payment.draft',[f('invoice_id','رقم الفاتورة الداخلية','number'),f('treasury_id','الصندوق/البنك','lookup',{source:'treasuries'}),f('amount','الدفعة','money'),f('effective_on','تاريخ الصرف','date')]),
 op('invoicePay','ربط فاتورة بسند صرف','/finance/invoices/{invoice_id}/payments','finance.purchase.invoice',[f('invoice_id','رقم الفاتورة الداخلية','number'),f('transaction_id','رقم سند الصرف المنفذ','number')]),
 op('allocation','تخصيص سند قبض لمستحقات','/finance/receipts/{receipt_id}/allocations','finance.receipt.execute',[f('receipt_id','رقم سند القبض المنفذ','number')],'allocations',[f('module','المصدر','enum',{choices:[['finance','مستحق مالي داخلي']]}),f('reference','رقم المستحق','number'),f('amount','قيمة التخصيص','money')]),
 op('advanceDisburse','ربط صرف السلفة بسند','/finance/advances/{advance_id}/disburse','finance.advance.manage',[f('advance_id','رقم السلفة','number'),f('transaction_id','سند الصرف المنفذ','number')]),
 op('advanceSettlement','تسوية سلفة','/finance/advances/{advance_id}/settlements','finance.advance.manage',[f('advance_id','رقم السلفة','number'),f('kind','نوع التسوية','enum',{choices:[['expense','نفقة موثقة'],['refund','استرداد']]}),f('amount','المبلغ','money'),f('reference','المرجع الداعم'),f('transaction_id','سند القبض للاسترداد','number',{optional:true})]),
 op('fundMovement','حركة تمويل مخصص','/finance/funds/{fund_id}/movements','finance.fund.manage',[f('fund_id','رقم التمويل','lookup',{source:'funds'}),f('kind','نوع الحركة','enum',{choices:[['receive','استلام تمويل'],['commit','التزام'],['release','تحرير التزام'],['spend','صرف من التخصيص']]}),f('amount','المبلغ','money'),f('reference','رقم المرجع'),f('reason','السبب','textarea',{optional:true})]),
 op('payrollPay','ربط راتب بسند صرف','/finance/payroll-items/{payroll_item_id}/pay','finance.payroll.manage',[f('payroll_item_id','رقم بند الراتب','number'),f('transaction_id','رقم سند الصرف المنفذ','number')]),
 op('chequeStatus','تحديث حالة شيك','/finance/cheques/{cheque_id}/status','finance.cheque.manage',[f('cheque_id','رقم الشيك الداخلي','number'),f('state','الحالة','enum',{choices:[['cleared','محصّل'],['returned','مرتجع']]} )]),
 op('resolve','تسوية فرق المطابقة','/finance/reconciliations/{reconciliation_id}/resolve','finance.reconcile',[f('reconciliation_id','رقم المطابقة','number'),f('reference','المرجع'),f('reason','سبب التسوية','textarea')]),
 op('quotePolicy','سياسة عروض الأسعار','/finance/purchase-rules','finance.procurement.policy',[f('min_quotes','الحد الأدنى لعروض الأسعار','number'),f('decision_reference','مرجع قرار الإدارة')]),
 op('valuation','تقييم شراء بعملة أجنبية بالشيكل','/finance/purchases/{purchase_id}/valuation','finance.procurement.manage',[f('purchase_id','رقم طلب الشراء','number'),f('rate_id','رقم سعر الصرف المعتمد','number')]),
 op('externalVoucher','تسجيل رقم سند الصرف من البرنامج المحاسبي على فاتورة مورد','/finance/invoices/{invoice_id}/external-voucher','finance.payment.record',[f('invoice_id','رقم الفاتورة الداخلية','number'),f('voucher_no','رقم سند الصرف'),f('voucher_on','تاريخ السند','date'),f('amount','قيمة السند بعملة الفاتورة','money')]),
];
// Bookkeeping operations disappear in the documentary cycle; the external voucher exists only there.
const bookkeepingOperations=['invoiceDraft','invoicePay','allocation','advanceDisburse','advanceSettlement','fundMovement','payrollPay','chequeStatus','resolve','budgetException'];
const visibleOperations=computed(()=>operations.filter(x=>can(x.permission)&&(documentary.value?!bookkeepingOperations.includes(x.id):x.id!=='externalVoucher')));
const chosenOperation=ref(''),operationForm=ref({});
const operation=computed(()=>operations.find(x=>x.id===chosenOperation.value));
function startOperation(){if(!operation.value)return;operationForm.value=blank(operation.value);}
async function submitOperation(){if(!operation.value)return;busy.value=true;error.value='';try{const payload={...operationForm.value};let url=operation.value.url;for(const match of url.matchAll(/\{(\w+)\}/g)){url=url.replace(match[0],encodeURIComponent(payload[match[1]]));delete payload[match[1]];}for(const field of operation.value.fields||[]){if(field.optional&&payload[field.key]==='')payload[field.key]=null;}await axios.post(url,payload);success.value='تم تنفيذ الإجراء وتسجيله.';chosenOperation.value='';await refreshMeta();await openModule(active.value);}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
// Reports: the server lists only the reports this user may view; preview and exports use the same filters.
const reportList=ref([]),reportKey=ref(''),reportFilters=ref({from:'',to:'',state:'',q:''}),report=ref(null),reportError=ref('');
const reportAreas=computed(()=>Object.entries(areaMeta).map(([code,[name,icon]])=>({code,name,icon,items:reportList.value.filter(r=>r.area===code)})).filter(a=>a.items.length));
const selectedReport=computed(()=>reportList.value.find(r=>r.key===reportKey.value)||null);
function reportParams(page){const p=new URLSearchParams();for(const [k,v] of Object.entries(reportFilters.value))if(v)p.set(k,v);if(page&&page>1)p.set('page',page);return p.toString();}
const exportFormats=[['xlsx','Excel','file-spreadsheet'],['pdf','PDF','file-down'],['csv','CSV','file-text'],['print','طباعة','printer']];
const exportUrl=(format)=>`/finance/reports/${reportKey.value}/export/${format}${reportParams()?`?${reportParams()}`:''}`;
async function loadReports(){busy.value=true;try{const {data}=await axios.get('/finance/reports');reportList.value=data.reports||[];if(!selectedReport.value&&reportList.value.length)chooseReport(reportList.value[0].key);}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
function chooseReport(key){reportKey.value=key;report.value=null;reportError.value='';reportFilters.value={from:'',to:'',state:'',q:''};runReport(1);}
async function runReport(page=1){if(!reportKey.value)return;busy.value=true;reportError.value='';try{const {data}=await axios.get(`/finance/reports/${reportKey.value}${reportParams(page)?`?${reportParams(page)}`:''}`);report.value=data;}catch(e){report.value=null;reportError.value=errorText(e);}finally{busy.value=false;}}
// An export can be refused after the page loaded (revoked permission, too many rows): check before leaving the page.
async function exportReport(format){reportError.value='';try{const url=exportUrl(format);if(format==='pdf'||format==='print'){window.open(url,'_blank','noopener');return;}const response=await fetch(url,{credentials:'same-origin'});if(!response.ok){reportError.value=response.status===403?'لا تملك صلاحية تصدير هذا التقرير.':response.status===422?(await response.json().catch(()=>({}))).message||'تعذر التصدير':'تعذر التصدير';return;}const blob=await response.blob();const name=(response.headers.get('content-disposition')||'').match(/filename="?([^";]+)"?/)?.[1]||`AIMS_${reportKey.value}.${format}`;const link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download=name;document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(link.href),2000);}catch(e){reportError.value='تعذر التصدير، تحقق من الاتصال.';}}
// Department overview: the document-cycle stages grouped by functional area.
const stageAreas=computed(()=>['procurement','stores','accountant','payments','receipts'].map(code=>({code,name:areaMeta[code][0],icon:areaMeta[code][1],stages:(cycle.value.stages||[]).filter(s=>s.area===code)})).filter(a=>a.stages.length).map(a=>({...a,open:a.stages.reduce((n,s)=>n+Number(s.count||0),0)})));
const closedStage=computed(()=>(cycle.value.stages||[]).find(s=>s.area==='archive'));
// Each section has its own address (/finance?view=orders): the one global sidebar links to it, and bookmarks and
// the browser history work. Switching sections updates the address without reloading the page.
const inertiaPage=usePage();
const viewFromUrl=()=>{const query=new URLSearchParams(String(inertiaPage.url).split('?')[1]||'');const view=query.get('view');if(view==='transactions')return (query.get('kind')||props.transactionKind||props.transactionKinds[0])==='receipt'?'receipts':'payments';if(view==='payments'&&can('finance.payment.record')&&!paymentPermissions.some(can))return 'payment_vouchers';return view;};
const pageSections=computed(()=>menu.value.flatMap(g=>g.items).filter(m=>!m.href).map(m=>m.id));
const activeGroup=computed(()=>menu.value.find(g=>g.items.some(m=>m.id===active.value))||null);
function syncUrl(id){if(viewFromUrl()!==id)router.replace({url:`/finance?view=${encodeURIComponent(id)}`,preserveScroll:true,preserveState:true});}
async function go(m){if(!m)return;if(m.href){router.visit(m.href);return;}if(m.id!==active.value)await openModule(m.id);syncUrl(m.id);}
watch(()=>inertiaPage.url,()=>{const view=viewFromUrl();if(view&&view!==active.value&&pageSections.value.includes(view))openModule(view);});
onMounted(async()=>{await refreshMeta();const requested=viewFromUrl();const first=pageSections.value.includes(requested)?requested:pageSections.value[0];if(!first){const destination=menu.value.flatMap(g=>g.items).find(m=>m.href);if(destination)router.visit(destination.href);return;}await openModule(first);syncUrl(first);});

// Arabic labels and tones for the state codes the finance services write.
const stateLabels={draft:['مسودة','warning'],pending:['بانتظار الاعتماد','info'],pending_review:['بانتظار المراجعة','info'],pending_approval:['بانتظار الاعتماد','info'],pending_director:['بانتظار المدير التنفيذي','info'],pending_finance_head:['بانتظار رئيس الحسابات','info'],pending_board_treasurer:['بانتظار أمين الصندوق','info'],approved:['معتمد','success'],executed:['منفّذ','success'],closed:['مغلق','muted'],disbursed:['مصروف','success'],issued:['صادر','success'],matched:['مطابق','success'],paid:['مدفوع','success'],received:['مستلم','success'],settled:['مسوّى','success'],reversed:['معكوس','muted'],rejected:['مرفوض','danger'],cancelled:['ملغى','muted'],cleared:['محصّل','success'],returned:['مرتجع','danger'],ordered:['صدر أمر الشراء','success']};
stateLabels.partial_received=['استلام جزئي','info'];
const stateLabel=(state,module)=>module==='invoices'&&state==='pending'?'بانتظار سند الصرف':stateLabels[state]?.[0]||state||'—';
const stateTone=(state)=>stateLabels[state]?.[1]||'muted';
const supplierName=(id)=>(metadata.value.lookups?.suppliers||[]).find(s=>Number(s.id)===Number(id))?.name||`مورد #${id}`;
// Purchase requests: draft → finance head → executive director → (board treasurer above the limit) → approved → purchase order.
</script>
<template>
<AppLayout title="الحسابات والعمليات المالية">
 <div class="space-y-5">
  <PageHeader eyebrow="قسم الحسابات" :title="documentary?'الدورة المستندية للحسابات':'الحسابات والعمليات المالية'"
   :description="documentary?`من طلب الشراء حتى فاتورة المورد ورقم سند الصرف. القيود والصناديق والموازنات تُدار في ${externalSystem}. تظهر لك الأقسام والإجراءات حسب صلاحيتك فقط.`:'المعاملات والالتزامات والمشتريات والمخزون، وتظهر لك الإجراءات حسب اختصاصك فقط.'" />
  <!-- Each employee sees their assigned Accounts units; several assignments may be held together. -->
  <nav v-if="menu.length" aria-label="أقسام الحسابات" class="card space-y-3 p-3">
   <div class="flex flex-wrap gap-2">
    <button v-for="group in menu" :key="group.title" type="button" @click="go(group.items[0])" :aria-current="activeGroup?.title===group.title?'true':undefined"
     class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold transition" :class="activeGroup?.title===group.title?'bg-navy-700 text-white':'bg-slate-100 text-navy-800 hover:bg-navy-50'"><AppIcon :name="group.icon" :size="16" /><span>{{group.title}}</span></button>
   </div>
   <div v-if="activeGroup&&activeGroup.items.length>1" class="flex gap-1 overflow-x-auto border-t border-slate-100 pt-3">
    <button v-for="m in activeGroup.items" :key="m.id" type="button" @click="go(m)" :aria-current="active===m.id?'page':undefined"
     class="flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm transition" :class="active===m.id?'bg-gold-50 font-semibold text-navy-900 ring-1 ring-gold-300':'text-slate-700 hover:bg-navy-50'">
     <AppIcon :name="m.icon" :size="16" /><span>{{m.name}}</span>
     <span v-if="m.id==='pending'&&cycle.pending.length" class="rounded-full bg-gold-500 px-1.5 text-xs text-white">{{cycle.pending.length}}</span>
     <span v-else-if="metadata.summary?.[m.id] !== undefined" class="rounded-full bg-slate-100 px-1.5 text-xs text-slate-600">{{metadata.summary[m.id]}}</span>
    </button>
   </div>
  </nav>
  <p v-else class="card text-sm text-slate-500">لا توجد لك صلاحيات في قسم الحسابات.</p>
   <div class="min-w-0 space-y-4">
    <div v-if="error" role="alert" class="alert-error">{{error}}</div>
    <div v-if="success" role="status" class="alert-success">{{success}}</div>

    <section v-if="active==='pending'" class="card min-w-0">
     <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div><h2 class="card-title">بانتظار إجرائي</h2><p class="mt-1 text-xs text-slate-500">المستندات التي تنتظر إجراءك الآن حسب صلاحياتك. لا تظهر لك طلبات أنشأتها لمراجعتها أو اعتمادها.</p></div>
      <button type="button" @click="loadCycle" class="btn btn-secondary btn-sm" :disabled="busy">تحديث</button>
     </div>
     <EmptyState v-if="!busy&&!cycle.pending.length" title="لا يوجد ما ينتظر إجراءك" description="تظهر هنا المستندات عند وصولها إلى مرحلتك." />
     <div v-else class="overflow-x-auto">
      <table class="data-table min-w-full">
       <thead><tr><th scope="col">الإجراء المطلوب</th><th scope="col">المستند</th><th scope="col">بيان</th><th scope="col">منذ</th><th scope="col"><span class="sr-only">فتح</span></th></tr></thead>
       <tbody><tr v-for="(item,i) in cycle.pending" :key="i">
        <td class="font-semibold text-navy-800">{{item.action}}</td><td dir="ltr" class="text-right">{{item.reference}}</td><td>{{item.detail||'—'}}</td><td class="whitespace-nowrap text-xs text-slate-500">{{item.since?.slice(0,16)||'—'}}</td>
        <td><button type="button" class="btn btn-primary btn-sm" @click="openPending(item)">فتح</button></td>
       </tr></tbody>
      </table>
     </div>
    </section>

    <section v-else-if="active==='overview'" class="space-y-5">
     <div v-if="metadata.alerts?.length" class="card border-gold-200">
      <h2 class="card-title mb-3">التنبيهات التشغيلية</h2>
      <div class="grid gap-2 md:grid-cols-2"><div v-for="(alert,i) in metadata.alerts" :key="i" class="flex justify-between rounded-lg border border-gold-100 bg-gold-50 p-3 text-sm"><span>{{alert.title}}</span><strong class="text-gold-800">{{alert.count}}</strong></div></div>
     </div>
     <div>
      <h2 class="card-title">مراحل الدورة المستندية</h2>
      <p class="mt-1 text-xs text-slate-500">عدد المستندات المفتوحة في كل مرحلة، موزعة على وحدات قسم الحسابات. اضغط المرحلة لفتح قائمتها إن كانت ضمن صلاحياتك.<span v-if="documentary"> السداد يُنفَّذ في {{externalSystem}} ويُسجَّل رقم سنده على الفاتورة.</span></p>
     </div>
     <EmptyState v-if="!busy&&!stageAreas.length" title="لا توجد بيانات للدورة المستندية بعد" />
     <div class="grid gap-4 lg:grid-cols-3">
      <article v-for="area in stageAreas" :key="area.code" class="card min-w-0 space-y-3">
       <header class="flex items-center justify-between gap-2">
        <h3 class="flex items-center gap-2 text-base font-bold text-navy-800"><AppIcon :name="area.icon" :size="20" /><span>{{area.name}}</span></h3>
        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="area.open?'bg-gold-100 text-gold-800':'bg-slate-100 text-slate-500'">{{area.open}} مفتوح</span>
       </header>
       <ul class="divide-y divide-slate-100">
        <li v-for="stage in area.stages" :key="stage.key">
         <button type="button" class="flex w-full items-center justify-between gap-3 py-2.5 text-right text-sm transition enabled:hover:text-navy-700 disabled:cursor-default" :disabled="!menu.some(g=>g.items.some(x=>x.id===stage.module))" @click="go(modules.find(x=>x.id===stage.module))">
          <span class="min-w-0"><span class="block text-slate-700">{{stage.label}}</span><span class="text-xs text-slate-400">{{stage.owner}}</span></span>
          <strong class="font-display text-xl" :class="stage.count?'text-navy-800':'text-slate-300'">{{stage.count}}</strong>
         </button>
        </li>
       </ul>
      </article>
     </div>
     <p v-if="closedStage" class="text-sm text-slate-500">{{closedStage.label}}: <strong class="text-navy-800">{{closedStage.count}}</strong></p>
    </section>

    <section v-else-if="active==='archive'" class="card min-w-0">
     <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div><h2 class="card-title">السجل</h2><p class="mt-1 text-xs text-slate-500">الدورات المستندية المغلقة: طلب الشراء وأمره وفواتيره وأرقام سندات الصرف.</p></div>
      <form class="flex gap-2" @submit.prevent="loadArchive(1)"><label for="archive-search" class="sr-only">بحث</label><input id="archive-search" v-model="archiveSearch" class="field" placeholder="رقم الطلب أو الأمر أو الفاتورة أو السند" /><button type="submit" class="btn btn-secondary btn-sm" :disabled="busy">بحث</button></form>
     </div>
     <EmptyState v-if="!busy&&!archive.data?.length" title="لا توجد دورات مغلقة بعد" />
     <div v-else class="overflow-x-auto">
      <table class="data-table min-w-full">
       <thead><tr><th scope="col">طلب الشراء</th><th scope="col">أمر الشراء</th><th scope="col">القسم</th><th scope="col">المورد</th><th scope="col">القيمة</th><th scope="col">الفواتير ← السندات</th><th scope="col">أُغلق</th></tr></thead>
       <tbody><tr v-for="row in archive.data" :key="row.id"><td dir="ltr" class="text-right">{{row.request_number}}</td><td dir="ltr" class="text-right">{{row.order_number}}</td><td>{{row.department||'—'}}</td><td>{{row.supplier||'—'}}</td><td>{{row.amount}} {{row.currency}}</td><td>{{row.invoices||'—'}}</td><td class="whitespace-nowrap text-xs">{{row.closed_at?.slice(0,10)}}</td></tr></tbody>
      </table>
     </div>
     <nav v-if="archive.last_page>1" aria-label="صفحات السجل" class="mt-4 flex items-center justify-between gap-3">
      <button type="button" :disabled="busy||archive.current_page<=1" @click="loadArchive(archive.current_page-1)" class="btn btn-secondary btn-sm">السابق</button>
      <span class="text-sm text-slate-600">صفحة {{archive.current_page}} من {{archive.last_page}}</span>
      <button type="button" :disabled="busy||archive.current_page>=archive.last_page" @click="loadArchive(archive.current_page+1)" class="btn btn-secondary btn-sm">التالي</button>
     </nav>
    </section>

    <section v-else-if="active==='reports'" class="grid min-w-0 gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
     <nav aria-label="قائمة التقارير" class="card h-fit space-y-4">
      <div><h2 class="card-title">التقارير والتصدير</h2><p class="mt-1 text-xs text-slate-500">تظهر التقارير المسموح لك بها فقط، ويُسجَّل كل تصدير في سجل التدقيق.</p></div>
      <EmptyState v-if="!busy&&!reportList.length" title="لا توجد تقارير متاحة لصلاحياتك" />
      <div v-for="area in reportAreas" :key="area.code">
       <h3 class="mb-1 flex items-center gap-1.5 text-xs font-bold text-slate-500"><AppIcon :name="area.icon" :size="14" />{{area.name}}</h3>
       <ul class="space-y-1">
        <li v-for="r in area.items" :key="r.key"><button type="button" @click="chooseReport(r.key)" :aria-current="reportKey===r.key?'page':undefined" class="w-full rounded-lg px-3 py-2 text-right text-sm transition" :class="reportKey===r.key?'bg-gold-50 font-semibold text-navy-900 ring-1 ring-gold-300':'text-slate-700 hover:bg-navy-50'">{{r.title}}</button></li>
       </ul>
      </div>
     </nav>
     <div v-if="selectedReport" class="card min-w-0 space-y-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
       <div><h2 class="card-title">{{selectedReport.title}}</h2><p class="mt-1 text-xs text-slate-500">{{report?.filters||'بدون تصفية'}}</p></div>
       <div v-if="selectedReport.export" class="flex flex-wrap gap-2" role="group" aria-label="تصدير التقرير">
        <button v-for="[format,label,icon] in exportFormats" :key="format" type="button" class="btn btn-secondary btn-sm inline-flex items-center gap-1.5" :title="`تصدير ${label} بالتصفية الحالية`" :disabled="busy" @click="exportReport(format)"><AppIcon :name="icon" :size="16" /><span>{{label}}</span></button>
       </div>
       <p v-else class="text-xs text-slate-500">العرض فقط؛ التصدير يحتاج صلاحية «تصدير التقارير».</p>
      </div>
      <form class="grid gap-3 rounded-xl bg-slate-50 p-3 sm:grid-cols-2 xl:grid-cols-5" @submit.prevent="runReport(1)">
       <label><span class="field-label">{{reportKey==='stock_balances'?'الرصيد حتى تاريخ':'إلى تاريخ'}}</span><input v-model="reportFilters.to" type="date" class="field" /></label>
       <label v-if="reportKey!=='stock_balances'"><span class="field-label">من تاريخ</span><input v-model="reportFilters.from" type="date" class="field" /></label>
       <label v-if="selectedReport.states.length"><span class="field-label">الحالة</span><select v-model="reportFilters.state" class="field"><option value="">الكل</option><option v-for="[code,label] in selectedReport.states" :key="code" :value="code">{{label}}</option></select></label>
       <label><span class="field-label">بحث</span><input v-model="reportFilters.q" type="search" class="field" placeholder="رقم أو اسم" /></label>
       <div class="flex items-end gap-2"><button type="submit" class="btn btn-primary btn-sm inline-flex items-center gap-1.5" :disabled="busy"><AppIcon name="search" :size="16" /><span>عرض</span></button><button type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="chooseReport(reportKey)">مسح</button></div>
      </form>
      <div v-if="reportError" role="alert" class="alert-error">{{reportError}}</div>
      <p v-if="busy" class="text-sm text-slate-500" role="status">جارٍ تحميل التقرير...</p>
      <template v-if="report">
       <EmptyState v-if="!report.rows.length" title="لا توجد سجلات مطابقة للتصفية المختارة" />
       <div v-else class="overflow-x-auto">
        <table class="data-table min-w-full">
         <thead><tr><th v-for="c in report.columns" :key="c.key" scope="col">{{c.label}}</th></tr></thead>
         <tbody><tr v-for="(row,i) in report.rows" :key="i"><td v-for="c in report.columns" :key="c.key" class="whitespace-nowrap" :class="['money','qty'].includes(c.type)?'text-left tabular-nums':''" :dir="['money','qty','date','datetime'].includes(c.type)?'ltr':undefined">
          <StatusBadge v-if="c.type==='state'" :label="row[c.key]" :tone="stateTone(selectedReport.states.find(s=>s[1]===row[c.key])?.[0])" /><template v-else>{{row[c.key]}}</template>
         </td></tr></tbody>
        </table>
       </div>
       <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gold-200 bg-gold-50 px-4 py-3 text-sm">
        <span>عدد السجلات: <strong>{{report.totals.count}}</strong></span>
        <span v-for="m in report.totals.money" :key="m.currency">الإجمالي ({{m.currency}}): <strong dir="ltr">{{m.amount}}</strong></span>
        <span v-if="report.totals.count>report.max_rows" class="text-red-700">النتائج أكثر من {{report.max_rows}} سجل؛ ضيّق التصفية قبل التصدير.</span>
       </div>
       <nav v-if="report.page.last>1" aria-label="صفحات التقرير" class="flex items-center justify-between gap-3">
        <button type="button" :disabled="busy||report.page.current<=1" @click="runReport(report.page.current-1)" class="btn btn-secondary btn-sm">السابق</button>
        <span class="text-sm text-slate-600">صفحة {{report.page.current}} من {{report.page.last}}</span>
        <button type="button" :disabled="busy||report.page.current>=report.page.last" @click="runReport(report.page.current+1)" class="btn btn-secondary btn-sm">التالي</button>
       </nav>
      </template>
     </div>
    </section>

    <section v-else class="card min-w-0">
     <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div><h2 class="card-title">{{current?.name}}</h2><p class="mt-1 text-xs text-slate-500">لا تظهر إجراءات لا تملك صلاحيتها.</p></div>
      <div class="flex gap-2"><button type="button" @click="openModule(active)" class="btn btn-secondary btn-sm" :disabled="busy">تحديث</button><button v-if="canCreate" type="button" @click="startCreate" class="btn btn-primary btn-sm">+ إضافة</button></div>
     </div>
     <p v-if="busy" class="py-3 text-sm text-slate-500" role="status">جارٍ تنفيذ الطلب...</p>
     <EmptyState v-if="!busy && !records.length" title="لا توجد بيانات مسجلة في هذه القائمة" />
     <div v-else-if="records.length" class="overflow-x-auto">
      <table class="data-table min-w-full">
       <thead><tr><th v-for="col in currentFields" :key="col" scope="col">{{human[col]||col}}</th><th>إجراءات</th></tr></thead>
       <tbody>
        <tr v-for="r in records" :key="r.id">
         <td v-for="col in currentFields" :key="col" class="max-w-56 truncate whitespace-nowrap" :title="String(r[col]??'')">
          <StatusBadge v-if="col==='state'" :label="stateLabel(r[col],active)" :tone="stateTone(r[col])" />
          <template v-else>{{money(r[col])}}</template>
         </td>
         <td><div class="flex flex-wrap gap-1">
          <button v-if="active==='purchases'" type="button" @click="openPurchase(r.id)" class="btn btn-secondary btn-sm">التفاصيل</button>
          <button v-for="a in actionsFor(r)" :key="a.url" type="button" @click="runAction(a)" class="btn btn-primary btn-sm" :disabled="busy">{{a.label}}</button>
          <button v-for="[id,label,values] in rowForms(r)" :key="id" type="button" @click="prefill(id,values)" class="btn btn-secondary btn-sm" :disabled="busy">{{label}}</button>
         </div></td>
        </tr>
       </tbody>
      </table>
     </div>
     <nav v-if="recordPage.last_page>1" aria-label="صفحات السجلات" class="mt-4 flex items-center justify-between gap-3">
      <button type="button" :disabled="busy||recordPage.current_page<=1" @click="openModule(active,recordPage.current_page-1)" class="btn btn-secondary btn-sm">السابق</button>
      <span class="text-sm text-slate-600">صفحة {{recordPage.current_page}} من {{recordPage.last_page}} — {{recordPage.total}} سجلًا</span>
      <button type="button" :disabled="busy||recordPage.current_page>=recordPage.last_page" @click="openModule(active,recordPage.current_page+1)" class="btn btn-secondary btn-sm">التالي</button>
     </nav>
    </section>

    <section v-if="creating&&schema" class="card">
     <div class="mb-5 flex items-center justify-between"><h2 class="card-title">إضافة — {{current?.name}}</h2><button @click="creating=false" type="button" class="btn btn-secondary btn-sm">إغلاق</button></div>
     <form @submit.prevent="createRecord" class="space-y-5">
      <div class="grid gap-4 sm:grid-cols-2">
       <label v-for="field in schema.fields" :key="field.key" class="block"><span class="field-label">{{field.label}} <span v-if="!field.optional" class="text-red-600">*</span></span>
        <select v-if="field.type==='enum'" v-model="form[field.key]" :required="!field.optional" class="field"><option value="">اختر...</option><option v-for="[v,label] in field.choices" :key="v" :value="v">{{label}}</option></select>
        <select v-else-if="field.type==='lookup'" v-model="form[field.key]" :required="!field.optional" class="field"><option value="">اختر...</option><option v-for="item in lookupOptions(field)" :key="optionValue(field,item)" :value="optionValue(field,item)">{{optionText(field,item)}}</option></select>
        <textarea v-else-if="field.type==='textarea'" v-model="form[field.key]" :required="!field.optional" rows="3" class="field" />
        <input v-else v-model="form[field.key]" :type="field.type==='date'?'date':field.type==='email'?'email':field.type==='number'?'number':'text'" :inputmode="['money','price','quantity'].includes(field.type)?'decimal':'text'" :required="!field.optional" class="field" />
       </label>
      </div>
      <div v-if="schema.array" class="border-t border-slate-100 pt-4">
       <div class="mb-3 flex items-center justify-between"><h3 class="text-sm font-bold text-navy-800">بنود الطلب</h3><button @click="addLine" type="button" class="btn btn-secondary btn-sm">+ إضافة بند</button></div>
       <div v-for="(line,i) in form[schema.array]" :key="i" class="mb-3 grid gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-3">
        <label v-for="field in schema.arrayFields" :key="field.key" class="text-xs"><span class="mb-1 block font-semibold text-slate-600">{{field.label}}</span>
         <select v-if="field.type==='lookup'" v-model="line[field.key]" required class="field"><option value="">اختر...</option><option v-for="item in lookupOptions(field)" :key="optionValue(field,item)" :value="optionValue(field,item)">{{optionText(field,item)}}</option></select>
         <input v-else v-model="line[field.key]" required class="field" :inputmode="['money','price','quantity'].includes(field.type)?'decimal':'text'" />
        </label>
        <button v-if="form[schema.array].length>1" type="button" class="justify-self-start text-sm text-red-700 hover:underline" @click="form[schema.array].splice(i,1)">حذف البند</button>
       </div>
      </div>
      <div class="flex gap-2"><button type="submit" :disabled="busy" class="btn btn-primary px-6">حفظ</button><button type="button" @click="creating=false" class="btn btn-secondary">إلغاء</button></div>
     </form>
    </section>

    <section v-if="selectedPurchase" class="card space-y-5">
     <div class="flex items-center justify-between"><h2 class="card-title">طلب الشراء {{selectedPurchase.purchase.number}}</h2><button type="button" @click="selectedPurchase=null" class="btn btn-secondary btn-sm">إغلاق</button></div>
     <StatusStepper :steps="selectedPurchase.progress?.steps || []" :summary="selectedPurchase.progress?.summary" />
     <div class="grid gap-3 text-sm sm:grid-cols-3">
      <div class="rounded-lg bg-slate-50 p-3">الحالة: <StatusBadge :label="stateLabel(selectedPurchase.purchase.state)" :tone="stateTone(selectedPurchase.purchase.state)" /></div>
      <div class="rounded-lg bg-slate-50 p-3">الإجمالي: <strong>{{selectedPurchase.purchase.total_amount}} {{selectedPurchase.purchase.currency}}</strong></div>
      <div class="rounded-lg bg-slate-50 p-3">أمر الشراء: <strong>{{selectedPurchase.orders?.[0]?.number||'غير صادر'}}</strong></div>
     </div>
     <p v-if="selectedPurchase.purchase.source_module==='aims_request'" class="rounded-lg bg-blue-50 p-3 text-sm">طلب موظف من «طلباتي». <span class="whitespace-pre-line">{{selectedPurchase.purchase.purpose}}</span></p>
     <p v-if="selectedPurchase.purchase.decision_note" class="alert-warning text-sm">{{selectedPurchase.purchase.state==='rejected'?'سبب الرفض':'سبب الإعادة'}}: {{selectedPurchase.purchase.decision_note}}</p>
     <div><h3 class="mb-2 text-sm font-bold text-navy-800">بنود الشراء</h3><div v-for="item in selectedPurchase.items" :key="item.id" class="border-b border-slate-100 py-2 text-sm">{{item.item_name}} — {{item.quantity}} {{item.unit}} × {{item.unit_price}}</div>
      <p v-if="!selectedPurchase.items?.length" class="text-sm text-slate-500">لا بنود بعد: سعّر الأصناف ثم أرسل الطلب.</p></div>
     <form v-if="can('finance.procurement.manage')&&selectedPurchase.purchase.state==='draft'" @submit.prevent="saveLines" class="space-y-2 rounded-xl border border-slate-200 p-4">
      <h3 class="text-sm font-bold text-navy-800">تعديل بنود المسودة</h3>
      <div v-for="(line,i) in linesForm" :key="i" class="grid gap-2 sm:grid-cols-5">
       <label class="sm:col-span-2"><span class="sr-only">الصنف</span><input v-model="line.item_name" required class="field" placeholder="الصنف" /></label>
       <label><span class="sr-only">الكمية</span><input v-model="line.quantity" required class="field" inputmode="decimal" placeholder="الكمية" /></label>
       <label><span class="sr-only">الوحدة</span><input v-model="line.unit" required class="field" placeholder="الوحدة" /></label>
       <label><span class="sr-only">سعر الوحدة</span><input v-model="line.unit_price" required class="field" inputmode="decimal" placeholder="سعر الوحدة" /></label>
      </div>
      <div class="flex flex-wrap gap-2"><button type="button" class="btn btn-secondary btn-sm" @click="linesForm.push({item_name:'',quantity:'',unit:'',unit_price:''})">إضافة بند</button>
       <button v-if="linesForm.length>1" type="button" class="btn btn-secondary btn-sm" @click="linesForm.pop()">حذف آخر بند</button>
       <button type="submit" class="btn btn-primary btn-sm" :disabled="busy">حفظ البنود</button>
       <button v-if="selectedPurchase.items?.length" type="button" class="btn btn-gold btn-sm" :disabled="busy" @click="purchaseAction(`/finance/purchases/${selectedPurchase.purchase.id}/submit`)">إرسال لمراجعة رئيس الحسابات</button></div>
     </form>
     <div v-if="stagePermission&&can(stagePermission)" class="space-y-2 rounded-xl border border-slate-200 p-4">
      <h3 class="text-sm font-bold text-navy-800">رفض الطلب أو إعادته إلى المشتريات</h3>
      <label class="block"><span class="field-label">السبب (إلزامي)</span><input v-model="decisionNote" class="field" maxlength="2000" /></label>
      <div class="flex flex-wrap gap-2"><button type="button" class="btn btn-secondary btn-sm" :disabled="busy||decisionNote.trim().length<5" @click="purchaseDecision('return')">إعادة للمشتريات</button>
       <button type="button" class="btn btn-danger btn-sm" :disabled="busy||decisionNote.trim().length<5" @click="purchaseDecision('reject')">رفض الطلب</button></div>
     </div>
     <p v-if="selectedPurchase.purchase.cancellation_reason" class="alert-warning text-sm">سبب الإلغاء: {{selectedPurchase.purchase.cancellation_reason}}</p>
     <p v-else-if="selectedPurchase.orders?.[0]?.cancellation_reason" class="alert-warning text-sm">أُلغي المتبقي غير المورَّد من أمر الشراء: {{selectedPurchase.orders[0].cancellation_reason}}</p>
     <div v-if="can('finance.procurement.director_approve')&&selectedPurchase.purchase.state==='approved'&&cancellableOrder" class="space-y-2 rounded-xl border border-red-200 p-4">
      <h3 class="text-sm font-bold text-navy-800">{{selectedPurchase.orders?.[0]?.state==='partial_received'?'إلغاء المتبقي غير المورَّد من أمر الشراء':'إلغاء طلب الشراء المعتمد'}}</h3>
      <p class="text-xs text-slate-600">{{selectedPurchase.orders?.[0]?.state==='partial_received'?'يُحتسب الأمر مستلمًا بما ورد فعلًا، ثم يُفوتر ويُغلق عليه.':'يُلغى الطلب وأمر الشراء الصادر له (إن وجد) ما دام لم يُستلم منه شيء.'}}</p>
      <label class="block"><span class="field-label">سبب الإلغاء (إلزامي)</span><input v-model="cancelReason" class="field" maxlength="2000" /></label>
      <button type="button" class="btn btn-danger btn-sm" :disabled="busy||cancelReason.trim().length<10" @click="cancelPurchase">تأكيد الإلغاء</button>
     </div>
     <div><h3 class="mb-2 text-sm font-bold text-navy-800">عروض الموردين</h3>
      <p v-if="!selectedPurchase.quotes?.length" class="text-sm text-slate-500">لم تُسجل عروض بعد.</p>
      <div v-for="q in selectedPurchase.quotes" :key="q.id" class="flex items-center justify-between gap-2 border-b border-slate-100 py-2 text-sm">
       <span>{{supplierName(q.supplier_id)}} — {{q.amount}} {{q.currency}} <StatusBadge v-if="q.selected" label="العرض المختار" tone="success" /></span>
       <button v-if="can('finance.procurement.manage')&&selectedPurchase.purchase.state==='approved'&&!q.selected" type="button" @click="purchaseAction(`/finance/purchases/${selectedPurchase.purchase.id}/quotes/${q.id}/select`)" class="btn btn-secondary btn-sm">اختيار العرض</button>
      </div>
     </div>
     <form v-if="can('finance.procurement.manage')&&!selectedPurchase.orders?.length" @submit.prevent="addQuote" class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-2">
      <h3 class="text-sm font-bold text-navy-800 sm:col-span-2">إضافة عرض سعر</h3>
      <label><span class="field-label">المورد</span><select v-model="quoteForm.supplier_id" required class="field"><option value="">اختر المورد</option><option v-for="s in metadata.lookups?.suppliers" :key="s.id" :value="s.id">{{s.name}}</option></select></label>
      <label><span class="field-label">القيمة</span><input v-model="quoteForm.amount" required class="field" inputmode="decimal" /></label>
      <label><span class="field-label">العملة</span><input v-model="quoteForm.currency" readonly class="field bg-slate-50" /></label>
      <label><span class="field-label">الملاحظات</span><input v-model="quoteForm.notes" class="field" /></label>
      <button type="submit" class="btn btn-primary sm:col-span-2" :disabled="busy">حفظ عرض السعر</button>
     </form>
     <button v-if="can('finance.procurement.manage')&&selectedPurchase.purchase.state==='approved'&&!selectedPurchase.orders?.length" type="button" @click="purchaseAction(`/finance/purchases/${selectedPurchase.purchase.id}/orders`)" class="btn btn-gold" :disabled="busy">إصدار أمر شراء</button>
    </section>

    <section v-if="visibleOperations.length&&!['pending','overview','archive','reports'].includes(active)" id="finance-operations" class="card space-y-4">
     <h2 class="card-title">{{documentary?'إجراءات الدورة المستندية':'إجراءات مالية إضافية'}}</h2>
     <label class="block"><span class="field-label">اختر الإجراء المتاح حسب صلاحياتك</span><select v-model="chosenOperation" @change="startOperation" class="field"><option value="">اختر إجراء...</option><option v-for="operation in visibleOperations" :key="operation.id" :value="operation.id">{{operation.name}}</option></select></label>
     <form v-if="operation" @submit.prevent="submitOperation" class="space-y-4">
      <div class="grid gap-3 sm:grid-cols-2">
       <label v-for="field in operation.fields" :key="field.key"><span class="field-label">{{field.label}} <span v-if="!field.optional" class="text-red-600">*</span></span>
        <select v-if="field.type==='lookup'" v-model="operationForm[field.key]" :required="!field.optional" class="field"><option value="">اختر</option><option v-for="item in lookupOptions(field)" :key="optionValue(field,item)" :value="optionValue(field,item)">{{optionText(field,item)}}</option></select>
        <select v-else-if="field.type==='enum'" v-model="operationForm[field.key]" :required="!field.optional" class="field"><option value="">اختر</option><option v-for="[val,label] in field.choices" :key="val" :value="val">{{label}}</option></select>
        <textarea v-else-if="field.type==='textarea'" v-model="operationForm[field.key]" rows="2" :required="!field.optional" class="field" />
        <input v-else v-model="operationForm[field.key]" :type="field.type==='date'?'date':'text'" :inputmode="['number','money','quantity','price'].includes(field.type)?'decimal':'text'" :required="!field.optional" class="field" />
       </label>
      </div>
      <div v-if="operation.array" class="space-y-3 border-t border-slate-100 pt-4">
       <div class="flex justify-between"><h3 class="text-sm font-bold text-navy-800">البنود</h3><button type="button" @click="operationForm[operation.array].push(Object.fromEntries(operation.arrayFields.map(x=>[x.key,''])))" class="btn btn-secondary btn-sm">+ بند</button></div>
       <div v-for="(line,index) in operationForm[operation.array]" :key="index" class="grid gap-2 rounded-lg bg-slate-50 p-3 sm:grid-cols-3">
        <label v-for="field in operation.arrayFields" :key="field.key" class="text-xs"><span class="mb-1 block font-semibold text-slate-600">{{field.label}}</span>
         <select v-if="field.type==='lookup'" v-model="line[field.key]" :required="!field.optional" class="field"><option value="">اختر</option><option v-for="item in lookupOptions(field)" :key="optionValue(field,item)" :value="optionValue(field,item)">{{optionText(field,item)}}</option></select>
         <select v-else-if="field.type==='enum'" v-model="line[field.key]" required class="field"><option value="">اختر</option><option v-for="[val,label] in field.choices" :key="val" :value="val">{{label}}</option></select>
         <input v-else v-model="line[field.key]" :required="!field.optional" class="field" />
        </label>
        <button v-if="operationForm[operation.array].length>1" type="button" @click="operationForm[operation.array].splice(index,1)" class="text-right text-xs text-red-700 hover:underline">حذف البند</button>
       </div>
      </div>
      <button type="submit" :disabled="busy" class="btn btn-primary px-6">حفظ الإجراء</button>
     </form>
    </section>
   </div>
 </div>
</AppLayout>
</template>
