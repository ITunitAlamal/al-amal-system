<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';

// Relief campaigns of the Sponsorships & Orphans department (never a department of their own).
const props = defineProps({ aidSwitch: { type: Boolean, default: false }, campaigns: { type: Array, default: () => [] }, can: { type: Object, default: () => ({}) }, options: { type: Object, default: null } });
const creating = ref(false);
const form = useForm({ title: '', description: '', objective: '', type_value_id: null, delivery_method_value_id: null, unit_of_benefit: 'household', starts_on: '', ends_on: '',
    registration_deadline: '', budget_amount: null, currency: 'ILS', funding_source: '', finance_fund_id: null, roster_cap: null, priority: 'normal', manager_user_id: null,
    publication_consent: false, eligibility: { min_age: null, max_age: null, orphan_classes: [], governorates: [], displaced_only: false } });
const tone = { draft: 'warning', in_review: 'info', approved: 'info', active: 'success', suspended: 'muted', closing: 'warning', closed: 'muted', rejected: 'danger', cancelled: 'muted' };
</script>

<template>
    <AppLayout title="الحملات الإغاثية">
        <div class="space-y-4">
            <PageHeader eyebrow="قسم الكفالات وشؤون الأيتام" title="الحملات الإغاثية" description="تبقى الحملات تابعة لقسم الكفالات؛ يعتمدها المدير التنفيذي، ويقرر وحده أي استثناء لمستفيد، ويسند رئيس القسم وحده الدفعات للموزعين.">
                <Link v-if="can.devices" href="/relief/devices" class="btn btn-secondary btn-sm">أجهزة التوزيع</Link>
                <button v-if="can.create" type="button" class="btn btn-primary btn-sm" @click="creating = !creating">حملة جديدة</button>
            </PageHeader>
            <nav v-if="aidSwitch" class="flex flex-wrap gap-2" aria-label="الكفالات والمساعدات">
                <Link href="/orphans/sponsorships" class="btn btn-secondary btn-sm">الكفالات</Link>
                <span class="btn btn-primary btn-sm" aria-current="page">الحملات الإغاثية</span>
            </nav>
            <form v-if="creating && options" class="card space-y-3" data-error-scope @submit.prevent="form.post('/relief/campaigns')">
                <h2 class="card-title">مسودة حملة</h2>
                <FormErrorSummary :errors="form.errors" />
                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <label class="sm:col-span-2"><span class="field-label">عنوان الحملة *</span><input v-model="form.title" class="field" maxlength="200" required></label>
                    <label class="sm:col-span-2"><span class="field-label">الوصف</span><textarea v-model="form.description" class="field" rows="2" maxlength="5000"></textarea></label>
                    <label class="sm:col-span-2"><span class="field-label">الهدف</span><textarea v-model="form.objective" class="field" rows="2" maxlength="2000"></textarea></label>
                    <label><span class="field-label">نوع الحملة</span><select v-model="form.type_value_id" class="field"><option :value="null">{{ options.types.length ? 'اختر' : 'لا توجد أنواع في الثوابت بعد' }}</option><option v-for="o in options.types" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                    <label><span class="field-label">طريقة التسليم</span><select v-model="form.delivery_method_value_id" class="field"><option :value="null">—</option><option v-for="o in options.delivery_methods" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                    <label><span class="field-label">وحدة الاستفادة *</span><select v-model="form.unit_of_benefit" class="field"><option value="household">الأسرة (لا يتكرر للإخوة)</option><option value="child">الطفل</option></select></label>
                    <label><span class="field-label">الأولوية</span><select v-model="form.priority" class="field"><option value="normal">عادية</option><option value="high">مرتفعة</option><option value="urgent">عاجلة</option></select></label>
                    <label><span class="field-label">البداية *</span><input v-model="form.starts_on" type="date" class="field" required></label>
                    <label><span class="field-label">النهاية *</span><input v-model="form.ends_on" type="date" class="field" required></label>
                    <label><span class="field-label">سقف عدد المستفيدين</span><input v-model.number="form.roster_cap" type="number" min="1" class="field"></label>
                    <label><span class="field-label">الميزانية</span><input v-model.number="form.budget_amount" type="number" min="0" step="0.01" class="field"></label>
                    <label><span class="field-label">التمويل المخصص (من الحسابات)</span><select v-model="form.finance_fund_id" class="field"><option :value="null">—</option><option v-for="o in options.funds" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                    <label><span class="field-label">مدير الحملة *</span><select v-model="form.manager_user_id" class="field" required><option :value="null">اختر</option><option v-for="o in options.managers" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                    <fieldset class="sm:col-span-2 rounded-lg border border-slate-200 p-3"><legend class="font-semibold">شروط الأهلية (بوابات صريحة منفصلة عن الترتيب)</legend>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <label><span class="field-label">أقل عمر</span><input v-model.number="form.eligibility.min_age" type="number" min="0" max="30" class="field"></label>
                            <label><span class="field-label">أعلى عمر</span><input v-model.number="form.eligibility.max_age" type="number" min="0" max="30" class="field"></label>
                            <label class="flex items-center gap-2"><input v-model="form.eligibility.displaced_only" type="checkbox"> للأسر النازحة فقط</label>
                        </div>
                        <p class="mt-2 text-sm font-semibold">تصنيفات اليتم المشمولة (فارغ = الكل)</p>
                        <label v-for="c in options.classes" :key="c.code" class="me-3 inline-flex items-center gap-1 text-sm"><input v-model="form.eligibility.orphan_classes" type="checkbox" :value="c.code"> {{ c.name }}</label>
                        <p class="mt-2 text-sm font-semibold">النطاق الجغرافي (فارغ = الكل)</p>
                        <label v-for="g in options.governorates" :key="g.id" class="me-3 inline-flex items-center gap-1 text-sm"><input v-model="form.eligibility.governorates" type="checkbox" :value="g.id"> {{ g.name }}</label>
                    </fieldset>
                    <label class="flex items-center gap-2 sm:col-span-2"><input v-model="form.publication_consent" type="checkbox"> يجوز النشر الإعلامي عن الحملة (دون هويات أطفال، وبموافقات مستقلة)</label>
                </div>
                <button class="btn btn-primary" :disabled="form.processing">حفظ المسودة</button>
            </form>
            <section class="card">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="c in campaigns" :key="c.public_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><Link :href="`/relief/campaigns/${c.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ c.reference_no }} — {{ c.title }}</Link>
                            <span class="block text-xs text-slate-500">{{ c.starts_on }} ← {{ c.ends_on }} — وحدة الاستفادة: {{ c.unit_label }}</span></span>
                        <StatusBadge :label="c.status_label" :tone="tone[c.status]" />
                    </li>
                    <li v-if="!campaigns.length" class="py-3 text-slate-500">لا توجد حملات.</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
