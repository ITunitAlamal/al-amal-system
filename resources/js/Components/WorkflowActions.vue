<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

// The actions the central workflow offers the current user on a module record (approve, return, reject, ...).
const props = defineProps({ workflow: { type: Object, default: null }, title: { type: String, default: 'الإجراء المطلوب منك' } });
const actions = computed(() => props.workflow?.actions ?? []);
const selected = ref(null);
const chosen = computed(() => actions.value.find(action => action.id === selected.value) || null);
const form = useForm({ transition_id: null, revision: 0, comment: '' });
function act() {
    form.transition_id = selected.value;
    form.revision = props.workflow.revision;
    form.post(`/workflows/${props.workflow.publicId}/act`, { preserveScroll: true, onSuccess: () => { selected.value = null; form.reset('comment'); } });
}
</script>

<template>
    <section v-if="actions.length" class="card space-y-3">
        <h2 class="card-title">{{ title }}</h2>
        <div class="flex flex-wrap gap-2">
            <button v-for="a in actions" :key="a.id" type="button" class="btn btn-sm" :class="selected === a.id ? 'btn-primary' : 'btn-secondary'" @click="selected = a.id; form.clearErrors()">{{ a.name }}</button>
        </div>
        <form v-if="chosen" class="space-y-2" @submit.prevent="act">
            <textarea v-model="form.comment" rows="3" maxlength="5000" class="field" :required="Boolean(chosen.requires_comment)" :placeholder="chosen.requires_comment ? 'التعليق إلزامي لهذا الإجراء' : 'تعليق (اختياري)'"></textarea>
            <p v-for="(message, key) in form.errors" :key="key" class="field-error">{{ message }}</p>
            <button type="submit" class="btn btn-primary btn-sm" :disabled="form.processing">تأكيد: {{ chosen.name }}</button>
        </form>
    </section>
</template>
