<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { CHANGE_KINDS, teaching, TIME, type ChangeKind, type Room, type TimetableEntry } from '@/api/higher-teaching';
import PersonPicker from '../PersonPicker.vue';
import { useHigherAction } from '../useHigherAction';
import { hk, useHt } from './i18n';

// Report, annulation, remplacement ou changement de salle d'une séance (EX-EDT-05), notifiés aux étudiants et enseignants (NOT-05).
const props = defineProps<{ entry: TimetableEntry; occursOn: string; rooms: Room[] }>();
const emit = defineEmits<{ (e: 'done'): void; (e: 'close'): void }>();
const { t } = useHt();
const act = useHigherAction();
const tried = ref(false);
const form = reactive({ change_kind: 'postponed' as ChangeKind, new_date: '', new_start_time: props.entry.start_time, new_end_time: props.entry.end_time, replacement_person_id: null as string | null, new_room_id: '', reason: '' });

const timeBad = computed(() => form.change_kind === 'postponed' && (!TIME.test(form.new_start_time) || !TIME.test(form.new_end_time) || form.new_end_time <= form.new_start_time));

async function submit(): Promise<void> {
  tried.value = true;
  const k = form.change_kind;
  if (!form.reason.trim() || (k === 'postponed' && (!form.new_date || timeBad.value)) || (k === 'replaced' && !form.replacement_person_id) || (k === 'room_changed' && !form.new_room_id)) return;
  const res = await act.run(() =>
    teaching.change({
      entry_id: props.entry.id, change_kind: k, occurs_on: props.occursOn, reason: form.reason.trim(),
      ...(k === 'postponed' ? { new_date: form.new_date, new_start_time: form.new_start_time, new_end_time: form.new_end_time } : {}),
      ...(k === 'replaced' ? { replacement_person_id: form.replacement_person_id } : {}),
      ...(k === 'room_changed' ? { new_room_id: form.new_room_id } : {}),
    }),
  );
  if (res) emit('done');
}
</script>

<template>
  <section class="card card--strong hi-section hi-form" role="dialog" aria-labelledby="chg-title" data-test="change-form">
    <h2 id="chg-title" class="hi-wide">{{ t('ht.tt.change') }} · {{ entry.element_title }} · {{ t(hk('ht.tt.day.', entry.weekday)) }} {{ entry.start_time }}</h2>
    <p class="hi-wide muted">{{ t('ht.tt.occursOn') }} {{ occursOn }}</p>
    <div class="field"><label for="chg-kind">{{ t('ht.tt.changeKind') }}</label><select id="chg-kind" v-model="form.change_kind"><option v-for="c in CHANGE_KINDS" :key="c" :value="c">{{ t(hk('ht.tt.change.', c)) }}</option></select></div>
    <template v-if="form.change_kind === 'postponed'">
      <div class="field"><label for="chg-date">{{ t('ht.tt.newDate') }}</label><input id="chg-date" v-model="form.new_date" type="date" :aria-invalid="tried && !form.new_date" /></div>
      <div class="field"><label for="chg-start">{{ t('ht.start') }}</label><input id="chg-start" v-model="form.new_start_time" type="time" /></div>
      <div class="field"><label for="chg-end">{{ t('ht.end') }}</label><input id="chg-end" v-model="form.new_end_time" type="time" /><span v-if="tried && timeBad" class="error">{{ t('ht.timeBad') }}</span></div>
    </template>
    <div v-if="form.change_kind === 'replaced'" class="hi-wide"><PersonPicker id="chg-person" v-model="form.replacement_person_id" :label="t('ht.tt.replacement')" /></div>
    <div v-if="form.change_kind === 'room_changed'" class="field">
      <label for="chg-room">{{ t('ht.tt.newRoom') }}</label>
      <select id="chg-room" v-model="form.new_room_id" :aria-invalid="tried && !form.new_room_id"><option value="" disabled>{{ t('ht.room') }}</option><option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.code }} · {{ r.capacity }}</option></select>
    </div>
    <div class="field hi-wide"><label for="chg-reason">{{ t('hi.reason') }}</label><textarea id="chg-reason" v-model="form.reason" :aria-invalid="tried && !form.reason.trim()" /><span v-if="tried && !form.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
    <div class="hi-wide"><ErrorNotice :error="act.error.value" /></div>
    <div class="hi-actions hi-wide"><button type="button" class="btn btn--ink" :disabled="act.busy.value" @click="submit">{{ t('hi.confirm') }}</button><button type="button" class="btn btn--quiet" @click="emit('close')">{{ t('hi.back') }}</button></div>
  </section>
</template>
