<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { formatDateTime } from '../../Support/dates.js';

// «إسناد إلى موظف»: who holds the request's current step, and, for the head who supervises it, one-step
// assignment, reassignment and withdrawal. The server decides who may do what (StepAssignments).
const props = defineProps({
    assignment: { type: Object, required: true },
    publicId: { type: String, required: true },
    revision: { type: Number, required: true },
});
const emit = defineEmits(['changed']);

const assigned = computed(() => props.assignment.mode === 'assigned');
const options = computed(() => (props.assignment.candidates ?? []).filter(person => person.id !== props.assignment.holder?.id));
const form = useForm({ expected_revision: props.revision, to_user_id: null, note: '' });
watch(() => props.revision, value => { form.expected_revision = value; });
const message = computed(() => form.errors.assignment || form.errors.to_user_id || form.errors.note || form.errors.expected_revision);

function send(path) {
    form.post(`/workflows/${props.publicId}/${path}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('to_user_id', 'note');
            emit('changed');
        },
    });
}
</script>

<template>
    <section id="assignment" class="card" aria-labelledby="assignment-title">
        <h2 id="assignment-title" class="card-title">إسناد المرحلة</h2>
        <p v-if="assigned && assignment.holder" class="mt-2 text-sm text-slate-700">
            لدى: <strong class="text-navy-800">{{ assignment.holder.name }}</strong>
            <template v-if="assignment.assigned_by"> — أسندها: <strong class="text-navy-800">{{ assignment.assigned_by.name }}</strong></template>
            <span v-if="assignment.assigned_at" class="text-xs text-slate-500"> · {{ formatDateTime(assignment.assigned_at) }}</span>
        </p>
        <p v-if="assigned && assignment.note" class="mt-2 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm text-slate-700">{{ assignment.note }}</p>

        <form v-if="assignment.can_supervise" class="mt-4 grid gap-3 md:grid-cols-3" @submit.prevent="send('assign')">
            <p class="text-sm text-slate-500 md:col-span-3">
                <template v-if="assigned && !assignment.can_assign">هذه المرحلة لا تُسند إلى موظف؛ يمكنك سحبها لتنفيذها بنفسك.</template>
                <template v-else-if="assigned">تبقى لك صلاحية نقل المهمة إلى موظف آخر أو سحبها لتنفيذها بنفسك.</template>
                <template v-else>المهمة لديك: نفّذها بنفسك، أو أسندها إلى موظف من القسم فتنتقل إلى «مهامي» لديه.</template>
            </p>
            <p v-if="assignment.on_hold" class="alert alert-warning md:col-span-3" role="status">
                المعاملة معلقة: تبقى معلقة عند انتقالها، ويستأنفها من يستلمها.
            </p>
            <template v-if="assignment.can_assign">
                <div>
                    <label for="assignment-user" class="field-label">الموظف <span class="text-red-600">*</span></label>
                    <select id="assignment-user" v-model="form.to_user_id" required class="field" :disabled="!options.length">
                        <option :value="null" disabled>{{ options.length ? 'اختر' : 'لا يوجد موظف مؤهل آخر في القسم' }}</option>
                        <option v-for="person in options" :key="person.id" :value="person.id">{{ person.name }} ({{ person.open_tasks }} لديه)</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="assignment-note" class="field-label">ملاحظة (اختيارية)</label>
                    <input id="assignment-note" v-model="form.note" type="text" maxlength="2000" class="field" />
                </div>
            </template>
            <p v-if="message" class="field-error md:col-span-3" role="alert">{{ message }}</p>
            <div class="flex flex-wrap gap-2 md:col-span-3">
                <button v-if="assignment.can_assign" type="submit" class="btn btn-primary" :disabled="form.processing || !options.length">{{ assigned ? 'إعادة الإسناد' : 'إسناد إلى موظف' }}</button>
                <button v-if="assigned" type="button" class="btn btn-secondary" :disabled="form.processing" @click="send('withdraw-assignment')">سحب الإسناد</button>
            </div>
        </form>
    </section>
</template>
