<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { EXAM_SESSIONS, teaching, TIME, type ExamSchedule, type ExamSession, type Room } from '@/api/higher-teaching';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import { splitCodes, toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import ElementField from './ElementField.vue';
import GroupField from './GroupField.vue';
import TeachingFrame from './TeachingFrame.vue';
import { TP } from './access';
import { hk, useHt } from './i18n';

// Planning des examens (EX-EDT-06) : programmation, publication, convocations (les non-autorisés à composer sont exclus) et
// feuille d'émargement.
const { t, locale } = useHt();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<ExamSchedule[]>([]);
const rooms = ref<Room[]>([]);
const sessionFilter = ref('');
const message = ref('');
const touched = ref(false);
const form = reactive({ curriculum_id: '', element_id: '', group_id: '', exam_session: 'normal' as ExamSession, exam_date: '', start_time: '08:00', end_time: '10:00', room_id: '', supervisors: '', paper_ref: '' });
const canManage = computed(() => ctx.can(...TP.examManage) && !higher.readOnly);
const timeBad = computed(() => touched.value && (!TIME.test(form.start_time) || !TIME.test(form.end_time) || form.end_time <= form.start_time));
const roomCode = (id: string | null): string => rooms.value.find((r) => r.id === id)?.code ?? '—';

async function load(): Promise<void> {
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (sessionFilter.value) q['filter[exam_session]'] = sessionFilter.value;
  const res = await list.run(() => teaching.exams(q));
  if (res) items.value = res;
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.element_id || !form.group_id || !form.exam_date || timeBad.value) return;
  const res = await act.run(() =>
    teaching.createExam({
      curriculum_id: form.curriculum_id, element_id: form.element_id, group_id: form.group_id, exam_session: form.exam_session, exam_date: form.exam_date,
      start_time: form.start_time, end_time: form.end_time, room_id: form.room_id || null, supervisors: splitCodes(form.supervisors), paper_ref: form.paper_ref.trim() || null,
    }),
  );
  if (!res) return;
  touched.value = false;
  await load();
}

async function step(x: ExamSchedule, kind: 'publish' | 'cancel' | 'convocations' | 'sheet'): Promise<void> {
  message.value = '';
  const res = await act.run(async () => {
    if (kind === 'publish') return teaching.publishExam(x.id);
    if (kind === 'cancel') return teaching.cancelExam(x.id);
    if (kind === 'sheet') return teaching.attendanceSheet(x.id);
    const r = await teaching.convocations(x.id);
    message.value = t('ht.exam.convocationsDone', { count: r.issued });
    return r;
  });
  if (res && kind !== 'sheet') await load();
  else if (res) message.value = t('ht.done');
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups', 'levels');
  const r = await act.run(() => teaching.rooms(higher.scope()));
  if (r) rooms.value = r.filter((x) => x.active);
  await load();
});
</script>

<template>
  <TeachingFrame :title="t('ht.exam.title')" :lede="t('ht.exam.lede')" @school-change="load">
    <div class="hi-form">
      <div class="field">
        <label for="ex-session">{{ t('ht.exam.session') }}</label>
        <select id="ex-session" v-model="sessionFilter" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option v-for="s in EXAM_SESSIONS" :key="s" :value="s">{{ t(hk('ht.exam.session.', s)) }}</option></select>
      </div>
    </div>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="message" class="notice notice--success" role="status">{{ message }}</p>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('hi.date') }}</th><th scope="col">{{ t('ht.element') }}</th><th scope="col">{{ t('hi.group') }}</th><th scope="col">{{ t('ht.exam.session') }}</th><th scope="col">{{ t('ht.room') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="x in items" :key="x.id">
            <th scope="row">{{ formatDate(x.exam_date, locale) }} · {{ x.start_time }}–{{ x.end_time }}</th>
            <td>{{ x.element_title }}</td>
            <td>{{ higher.groupName(x.group_id) }}</td>
            <td>{{ t(hk('ht.exam.session.', x.exam_session)) }}</td>
            <td>{{ roomCode(x.room_id) }}</td>
            <td><span class="tag" :class="`tag--${toneOf(x.status) ?? 'neutral'}`">{{ t(hk('ht.exam.state.', x.status)) }}</span></td>
            <td class="hi-actions">
              <template v-if="canManage">
                <button v-if="x.status === 'planned'" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="step(x, 'publish')">{{ t('ht.exam.publish') }}</button>
                <button v-if="x.status === 'published'" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="step(x, 'convocations')">{{ t('ht.exam.convocations') }}</button>
                <button v-if="x.status === 'published'" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="step(x, 'sheet')">{{ t('ht.exam.sheet') }}</button>
                <button v-if="x.status !== 'cancelled'" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="step(x, 'cancel')">{{ t('hi.cancel') }}</button>
              </template>
            </td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="7" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('ht.exam.new') }}</h2>
      <ElementField id="ex-el" v-model:curriculum-id="form.curriculum_id" v-model:element-id="form.element_id" :invalid="touched" />
      <GroupField id="ex-grp" v-model="form.group_id" :invalid="touched" />
      <div class="field"><label for="ex-s">{{ t('ht.exam.session') }}</label><select id="ex-s" v-model="form.exam_session"><option v-for="s in EXAM_SESSIONS" :key="s" :value="s">{{ t(hk('ht.exam.session.', s)) }}</option></select></div>
      <div class="field"><label for="ex-date">{{ t('ht.exam.date') }}</label><input id="ex-date" v-model="form.exam_date" type="date" :aria-invalid="touched && !form.exam_date" /></div>
      <div class="field"><label for="ex-start">{{ t('ht.start') }}</label><input id="ex-start" v-model="form.start_time" type="time" /></div>
      <div class="field"><label for="ex-end">{{ t('ht.end') }}</label><input id="ex-end" v-model="form.end_time" type="time" :aria-invalid="timeBad" /><span v-if="timeBad" class="error">{{ t('ht.timeBad') }}</span></div>
      <div class="field"><label for="ex-room">{{ t('ht.room') }}</label><select id="ex-room" v-model="form.room_id"><option value="">{{ t('ht.roomNone') }}</option><option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.code }} · {{ r.capacity }}</option></select></div>
      <div class="field"><label for="ex-sup">{{ t('ht.exam.supervisors') }}</label><input id="ex-sup" v-model="form.supervisors" type="text" aria-describedby="ex-sup-hint" /><span id="ex-sup-hint" class="hint">{{ t('ht.exam.supervisorsHint') }}</span></div>
      <div class="field"><label for="ex-paper">{{ t('ht.exam.paper') }}</label><input id="ex-paper" v-model="form.paper_ref" type="text" /></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.create') }}</button></div>
    </form>
  </TeachingFrame>
</template>
