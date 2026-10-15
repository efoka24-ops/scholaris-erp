<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { attendance, teaching, TIME, type ReplacementExam, type Room } from '@/api/higher-teaching';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import PersonPicker from '../PersonPicker.vue';
import YearField from '../YearField.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import ElementField from './ElementField.vue';
import GroupField from './GroupField.vue';
import TeachingFrame from './TeachingFrame.vue';
import { TP } from './access';
import { hk, useHt } from './i18n';

// Épreuves de remplacement après absence justifiée (ABJ, RG-NOT-5) : demande (scolarité), programmation et clôture (examens).
const { t, locale } = useHt();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<ReplacementExam[]>([]);
const rooms = ref<Room[]>([]);
const touched = ref(false);
const form = reactive({ person_id: null as string | null, curriculum_id: '', element_id: '', group_id: '', academic_year_id: '', absence_date: '', justification_ref: '', reason: '' });
const scheduling = ref<ReplacementExam | null>(null);
const slot = reactive({ exam_date: '', start_time: '08:00', end_time: '10:00', room_id: '' });
const slotTried = ref(false);

const canRequest = computed(() => ctx.can(...TP.attendanceManage) && !higher.readOnly);
const canSchedule = computed(() => ctx.can(...TP.replacementSchedule) && !higher.readOnly);
const slotBad = computed(() => slotTried.value && (!slot.exam_date || !TIME.test(slot.start_time) || !TIME.test(slot.end_time) || slot.end_time <= slot.start_time));
const tone = (s: string): string => (s === 'done' ? 'success' : s === 'cancelled' ? 'warning' : 'neutral');

async function load(): Promise<void> {
  const res = await list.run(() => attendance.replacements(higher.scope()));
  if (res) items.value = res;
}

async function request(): Promise<void> {
  touched.value = true;
  const f = form;
  if (!f.person_id || !f.element_id || !f.group_id || !f.academic_year_id || !f.absence_date || !f.justification_ref.trim() || !f.reason.trim()) return;
  const person = f.person_id;
  const res = await act.run(() =>
    attendance.requestReplacement({ person_id: person, element_id: f.element_id, group_id: f.group_id, academic_year_id: f.academic_year_id, absence_date: f.absence_date, justification_ref: f.justification_ref.trim(), reason: f.reason.trim() }),
  );
  if (!res) return;
  touched.value = false;
  Object.assign(form, { absence_date: '', justification_ref: '', reason: '' });
  await load();
}

async function confirmSchedule(): Promise<void> {
  slotTried.value = true;
  const r = scheduling.value;
  if (!r || slotBad.value) return;
  const res = await act.run(() => attendance.scheduleReplacement(r.id, { exam_date: slot.exam_date, start_time: slot.start_time, end_time: slot.end_time, room_id: slot.room_id || null }));
  if (!res) return;
  scheduling.value = null;
  slotTried.value = false;
  await load();
}

async function close(r: ReplacementExam, status: 'done' | 'cancelled'): Promise<void> {
  const res = await act.run(() => attendance.closeReplacement(r.id, status));
  if (res) await load();
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups', 'levels');
  if (canSchedule.value) {
    const r = await act.run(() => teaching.rooms(higher.scope()));
    if (r) rooms.value = r.filter((x) => x.active);
  }
  await load();
});
</script>

<template>
  <TeachingFrame group="attendance" :title="t('ht.rep.title')" :lede="t('ht.rep.lede')" @school-change="load">
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('ht.rep.student') }}</th><th scope="col">{{ t('hi.group') }}</th><th scope="col">{{ t('ht.rep.absenceDate') }}</th><th scope="col">{{ t('ht.exam.date') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in items" :key="r.id">
            <th scope="row" class="hi-mono">{{ r.person_id }}</th>
            <td>{{ higher.groupName(r.group_id) }}</td>
            <td>{{ formatDate(r.absence_date, locale) }}</td>
            <td>{{ r.exam_date ? `${formatDate(r.exam_date, locale)} · ${r.start_time}` : '—' }}</td>
            <td><span class="tag" :class="`tag--${tone(r.status)}`">{{ t(hk('ht.rep.state.', r.status)) }}</span></td>
            <td class="hi-actions">
              <template v-if="canSchedule">
                <button v-if="r.status === 'requested' || r.status === 'scheduled'" type="button" class="hi-linkbtn" @click="scheduling = r">{{ t('ht.rep.schedule') }}</button>
                <button v-if="r.status === 'scheduled'" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="close(r, 'done')">{{ t('ht.rep.done') }}</button>
                <button v-if="r.status === 'requested' || r.status === 'scheduled'" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="close(r, 'cancelled')">{{ t('ht.rep.cancel') }}</button>
              </template>
            </td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="6" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <section v-if="scheduling" class="card card--strong hi-section hi-form" role="dialog" aria-labelledby="rs-title" data-test="schedule-replacement">
      <h2 id="rs-title" class="hi-wide">{{ t('ht.rep.schedule') }} · {{ scheduling.person_id }}</h2>
      <div class="field"><label for="rs-date">{{ t('ht.exam.date') }}</label><input id="rs-date" v-model="slot.exam_date" type="date" :aria-invalid="slotTried && !slot.exam_date" /></div>
      <div class="field"><label for="rs-start">{{ t('ht.start') }}</label><input id="rs-start" v-model="slot.start_time" type="time" /></div>
      <div class="field"><label for="rs-end">{{ t('ht.end') }}</label><input id="rs-end" v-model="slot.end_time" type="time" /><span v-if="slotBad" class="error">{{ t('ht.timeBad') }}</span></div>
      <div class="field"><label for="rs-room">{{ t('ht.room') }}</label><select id="rs-room" v-model="slot.room_id"><option value="">{{ t('ht.roomNone') }}</option><option v-for="x in rooms" :key="x.id" :value="x.id">{{ x.code }}</option></select></div>
      <div class="hi-actions hi-wide"><button type="button" class="btn btn--ink" :disabled="act.busy.value" @click="confirmSchedule">{{ t('hi.confirm') }}</button><button type="button" class="btn btn--quiet" @click="scheduling = null">{{ t('hi.back') }}</button></div>
    </section>

    <form v-if="canRequest" class="card hi-section hi-form" novalidate @submit.prevent="request">
      <h2 class="hi-wide">{{ t('ht.rep.new') }}</h2>
      <div class="hi-wide"><PersonPicker id="rp-person" v-model="form.person_id" :label="t('ht.rep.student')" /><span v-if="touched && !form.person_id" class="error">{{ t('hi.required') }}</span></div>
      <GroupField id="rp-grp" v-model="form.group_id" :invalid="touched" />
      <ElementField id="rp-el" v-model:curriculum-id="form.curriculum_id" v-model:element-id="form.element_id" :invalid="touched" />
      <YearField id="rp-year" v-model="form.academic_year_id" :invalid="touched && !form.academic_year_id" />
      <div class="field"><label for="rp-date">{{ t('ht.rep.absenceDate') }}</label><input id="rp-date" v-model="form.absence_date" type="date" :aria-invalid="touched && !form.absence_date" /></div>
      <div class="field"><label for="rp-just">{{ t('ht.rep.justification') }}</label><input id="rp-just" v-model="form.justification_ref" type="text" :aria-invalid="touched && !form.justification_ref.trim()" /></div>
      <div class="field hi-wide"><label for="rp-reason">{{ t('hi.reason') }}</label><textarea id="rp-reason" v-model="form.reason" :aria-invalid="touched && !form.reason.trim()" /><span v-if="touched && !form.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.create') }}</button></div>
    </form>
  </TeachingFrame>
</template>
