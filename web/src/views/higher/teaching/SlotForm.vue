<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { teaching, TIME, WEEKDAYS, type Assignment, type Room, type SlotCheck, type SlotInput } from '@/api/higher-teaching';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { hk, useHt } from './i18n';

// Création d'un créneau (EX-EDT-02) : vérification préalable des conflits (enseignant, salle, groupe, capacité), puis enregistrement.
const props = defineProps<{ groupId?: string; weekStart: string; rooms: Room[] }>();
const emit = defineEmits<{ (e: 'created'): void }>();
const { t } = useHt();
const higher = useHigherStore();
const act = useHigherAction();

const assignments = ref<Assignment[]>([]);
const check = ref<SlotCheck | null>(null);
const touched = ref(false);
const form = reactive({ assignment_id: '', weekday: 1, start_time: '08:00', end_time: '10:00', valid_from: props.weekStart, valid_to: '', room_id: '' });

const timeBad = computed(() => touched.value && (!TIME.test(form.start_time) || !TIME.test(form.end_time) || form.end_time <= form.start_time));
const shown = computed(() => assignments.value.filter((a) => !props.groupId || a.group_id === props.groupId));

function body(): SlotInput | null {
  touched.value = true;
  if (!form.assignment_id || timeBad.value || !form.valid_from) return null;
  return {
    assignment_id: form.assignment_id, weekday: Number(form.weekday), start_time: form.start_time, end_time: form.end_time, valid_from: form.valid_from,
    valid_to: form.valid_to || null, room_id: form.room_id || null,
  };
}

async function verify(): Promise<void> {
  const b = body();
  if (!b) return;
  check.value = (await act.run(() => teaching.check(b))) ?? null;
}

async function create(): Promise<void> {
  const b = body();
  if (!b) return;
  const res = await act.run(() => teaching.createEntry(b));
  if (!res) return;
  touched.value = false;
  check.value = null;
  emit('created');
}

onMounted(async () => {
  const res = await act.run(() => teaching.assignments({ ...(higher.scope() as Record<string, string>), 'filter[status]': 'active' }));
  if (res) assignments.value = res;
});
</script>

<template>
  <form class="card hi-section hi-form" novalidate data-test="slot-form" @submit.prevent="create">
    <h2 class="hi-wide">{{ t('ht.tt.newSlot') }}</h2>
    <div class="field hi-wide">
      <label for="sl-asg">{{ t('ht.tt.assignment') }}</label>
      <select id="sl-asg" v-model="form.assignment_id" :aria-invalid="touched && !form.assignment_id">
        <option value="" disabled>{{ t('ht.tt.assignmentPick') }}</option>
        <option v-for="a in shown" :key="a.id" :value="a.id">{{ a.element_code }} · {{ t(hk('ht.kind.', a.session_kind)) }} · {{ higher.groupName(a.group_id) }} · {{ a.person_id }}</option>
      </select>
      <span v-if="touched && !form.assignment_id" class="error">{{ t('hi.required') }}</span>
    </div>
    <div class="field"><label for="sl-day">{{ t('ht.tt.weekday') }}</label><select id="sl-day" v-model.number="form.weekday"><option v-for="d in WEEKDAYS" :key="d" :value="d">{{ t(hk('ht.tt.day.', d)) }}</option></select></div>
    <div class="field"><label for="sl-start">{{ t('ht.start') }}</label><input id="sl-start" v-model="form.start_time" type="time" :aria-invalid="timeBad" /></div>
    <div class="field"><label for="sl-end">{{ t('ht.end') }}</label><input id="sl-end" v-model="form.end_time" type="time" :aria-invalid="timeBad" /><span v-if="timeBad" class="error">{{ t('ht.timeBad') }}</span></div>
    <div class="field"><label for="sl-room">{{ t('ht.room') }}</label><select id="sl-room" v-model="form.room_id"><option value="">{{ t('ht.roomNone') }}</option><option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.code }} · {{ r.capacity }}</option></select></div>
    <div class="field"><label for="sl-from">{{ t('ht.tt.validFrom') }}</label><input id="sl-from" v-model="form.valid_from" type="date" /></div>
    <div class="field"><label for="sl-to">{{ t('ht.tt.validTo') }}</label><input id="sl-to" v-model="form.valid_to" type="date" /></div>
    <div class="hi-wide">
      <ErrorNotice :error="act.error.value" />
      <div v-if="check && check.conflicts.length" class="notice notice--warning" role="status" data-test="slot-conflicts">
        <p><strong>{{ t('ht.tt.conflicts') }}</strong></p>
        <ul><li v-for="(c, i) in check.conflicts" :key="i">{{ t(hk('ht.tt.conflict.', c.kind)) }} : {{ c.message }}</li></ul>
      </div>
      <p v-else-if="check" class="notice notice--success" role="status">{{ t('ht.tt.noConflict', { headcount: check.headcount, capacity: check.room_capacity ?? '—' }) }}</p>
    </div>
    <div class="field hi-actions hi-wide">
      <button type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="verify">{{ t('ht.tt.check') }}</button>
      <button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.create') }}</button>
    </div>
  </form>
</template>
