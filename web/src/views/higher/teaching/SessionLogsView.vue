<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatMinutes, LOG_STATUSES, teaching, TIME, type Assignment, type SessionLog } from '@/api/higher-teaching';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import TeachingFrame from './TeachingFrame.vue';
import { TP } from './access';
import { hk, useHt } from './i18n';

// Cahier de textes et émargement (EX-ENS-03 à 05) : l'enseignant consigne puis signe ; le délégué du groupe ou le chef de
// département contre-signe. Sans droit de lecture du périmètre, l'écran montre « mes » séances (mine=1).
const { t, locale } = useHt();
const ctx = useContextStore();
const auth = useAuthStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const mine = computed(() => !ctx.can(...TP.logRead, ...TP.countersign));
const canWrite = computed(() => ctx.can(...TP.logWrite) && !higher.readOnly);
const canCountersign = computed(() => !higher.readOnly && (ctx.can(...TP.countersign) || mine.value));
const items = ref<SessionLog[]>([]);
const myAssignments = ref<Assignment[]>([]);
const statusFilter = ref('');
const signing = ref<SessionLog | null>(null);
const touched = ref(false);
const today = new Date().toISOString().slice(0, 10);
const form = reactive({ assignment_id: '', held_on: today, start_time: '08:00', end_time: '10:00', content: '', homework: '' });
const timeBad = computed(() => touched.value && (!TIME.test(form.start_time) || !TIME.test(form.end_time) || form.end_time <= form.start_time));
const tone = (s: string) => (s === 'countersigned' || s === 'validated' ? 'success' : toneOf(s) ?? 'neutral');

async function load(): Promise<void> {
  const q: Record<string, string> = mine.value ? { mine: '1' } : { ...(higher.scope() as Record<string, string>) };
  if (statusFilter.value) q['filter[status]'] = statusFilter.value;
  const res = await list.run(() => teaching.logs(q));
  if (res) items.value = res;
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.assignment_id || !form.held_on || timeBad.value) return;
  const res = await act.run(() =>
    teaching.createLog({ assignment_id: form.assignment_id, held_on: form.held_on, start_time: form.start_time, end_time: form.end_time, content: form.content.trim() || null, homework: form.homework.trim() || null }),
  );
  if (!res) return;
  touched.value = false;
  Object.assign(form, { content: '', homework: '' });
  await load();
}

async function sign(): Promise<void> {
  const l = signing.value;
  if (!l) return;
  const res = await act.run(() => teaching.signLog(l.id));
  signing.value = null;
  if (res) await load();
}

async function countersign(l: SessionLog): Promise<void> {
  const res = await act.run(() => teaching.countersignLog(l.id));
  if (res) await load();
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups');
  if (canWrite.value) {
    const res = await act.run(() => teaching.assignments({ mine: '1', 'filter[status]': 'active' }));
    if (res) myAssignments.value = res;
  }
  await load();
});
</script>

<template>
  <TeachingFrame :title="t('ht.log.title')" :lede="t('ht.log.lede')" @school-change="load">
    <div class="hi-form">
      <div class="field">
        <label for="lg-status">{{ t('hi.status') }}</label>
        <select id="lg-status" v-model="statusFilter" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option v-for="s in LOG_STATUSES" :key="s" :value="s">{{ t(hk('ht.log.state.', s)) }}</option></select>
      </div>
    </div>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('hi.date') }}</th><th scope="col">{{ t('hi.group') }}</th><th scope="col">{{ t('ht.kind') }}</th><th scope="col">{{ t('ht.log.content') }}</th><th scope="col" class="num">{{ t('ht.log.duration') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="l in items" :key="l.id">
            <th scope="row">{{ formatDate(l.held_on, locale) }} · {{ l.start_time }}</th>
            <td>{{ higher.groupName(l.group_id) }}</td>
            <td>{{ t(hk('ht.kind.', l.session_kind)) }}</td>
            <td>{{ l.content ?? '—' }}<span v-if="l.homework" class="muted hi-small"><br />{{ t('ht.log.homework') }} : {{ l.homework }}</span></td>
            <td class="num">{{ formatMinutes(l.minutes) }}</td>
            <td><span class="tag" :class="`tag--${tone(l.status)}`">{{ t(hk('ht.log.state.', l.status)) }}</span></td>
            <td class="hi-actions">
              <button v-if="canWrite && l.status === 'draft' && l.person_id === auth.me?.person_id" type="button" class="hi-linkbtn" @click="signing = l">{{ t('ht.log.sign') }}</button>
              <button v-if="canCountersign && l.status === 'signed' && l.person_id !== auth.me?.person_id" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="countersign(l)">{{ t('ht.log.countersign') }}</button>
            </td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="7" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <section v-if="signing" class="card card--strong hi-section" role="dialog" aria-labelledby="sign-title" data-test="sign-log">
      <h2 id="sign-title">{{ t('ht.log.sign') }} · {{ formatDate(signing.held_on, locale) }}</h2>
      <p>{{ t('ht.log.signText') }}</p>
      <div class="hi-actions"><button type="button" class="btn btn--ink" :disabled="act.busy.value" @click="sign">{{ t('hi.confirm') }}</button><button type="button" class="btn btn--quiet" @click="signing = null">{{ t('hi.back') }}</button></div>
    </section>

    <form v-if="canWrite" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('ht.log.new') }}</h2>
      <div class="field hi-wide">
        <label for="lg-asg">{{ t('ht.tt.assignment') }}</label>
        <select id="lg-asg" v-model="form.assignment_id" :aria-invalid="touched && !form.assignment_id">
          <option value="" disabled>{{ t('ht.tt.assignmentPick') }}</option>
          <option v-for="a in myAssignments" :key="a.id" :value="a.id">{{ a.element_code }} · {{ t(hk('ht.kind.', a.session_kind)) }} · {{ higher.groupName(a.group_id) }}</option>
        </select>
        <span v-if="touched && !form.assignment_id" class="error">{{ t('hi.required') }}</span>
      </div>
      <div class="field"><label for="lg-date">{{ t('ht.log.heldOn') }}</label><input id="lg-date" v-model="form.held_on" type="date" /></div>
      <div class="field"><label for="lg-start">{{ t('ht.start') }}</label><input id="lg-start" v-model="form.start_time" type="time" :aria-invalid="timeBad" /></div>
      <div class="field"><label for="lg-end">{{ t('ht.end') }}</label><input id="lg-end" v-model="form.end_time" type="time" :aria-invalid="timeBad" /><span v-if="timeBad" class="error">{{ t('ht.timeBad') }}</span></div>
      <div class="field hi-wide"><label for="lg-content">{{ t('ht.log.content') }}</label><textarea id="lg-content" v-model="form.content" /></div>
      <div class="field hi-wide"><label for="lg-hw">{{ t('ht.log.homework') }}</label><textarea id="lg-hw" v-model="form.homework" /></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.save') }}</button></div>
    </form>
  </TeachingFrame>
</template>
