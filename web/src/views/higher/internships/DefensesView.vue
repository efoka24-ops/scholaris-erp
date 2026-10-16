<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { DEFENSE_STATUSES, JURY_ROLES, internships, type Defense, type DefenseStatus, type JuryMember } from '@/api/higher-internships';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import InternshipsNav from './InternshipsNav.vue';
import { S } from './perms';
import { ik, useInternshipsI18n } from './i18n';

// Soutenances (API-18, EX-STG-05/06) : planning, programmation avec jury (RG-STG-1/2 et conflits contrôlés par le serveur),
// convocation (DOC-21, NOT-13), note du président ou du responsable des stages, PV (DOC-22). « Mes jurys » pour un membre.
const { ts, locale } = useInternshipsI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Defense[]>([]);
const status = ref<DefenseStatus | ''>('');
const mine = ref(!ctx.can(...S.read));
const showForm = ref(false);
const tried = ref(false);
const form = reactive({ internship_id: '', held_on: '', start_time: '', end_time: '', room: '' });
const jury = ref<JuryMember[]>([{ person_id: '', jury_role: 'president' }, { person_id: '', jury_role: 'supervisor' }, { person_id: '', jury_role: 'member' }]);
const grades = reactive<Record<string, string>>({});
const remarks = reactive<Record<string, string>>({});

const writable = computed(() => !higher.readOnly);
const canSchedule = computed(() => ctx.can(...S.defense) && writable.value);
const formMissing = computed(() => Object.values(form).some((v) => !v.trim()) || jury.value.some((m) => !m.person_id.trim()));

async function load(): Promise<void> {
  const q: Record<string, string> = {};
  if (status.value) q['filter[status]'] = status.value;
  if (mine.value) q.mine = '1';
  const res = await list.run(() => internships.defenses(q));
  if (res) items.value = res;
}

async function schedule(): Promise<void> {
  tried.value = true;
  if (formMissing.value) return;
  const res = await act.run(() => internships.schedule({ ...form, jury: jury.value.map((m) => ({ person_id: m.person_id.trim(), jury_role: m.jury_role })) }));
  if (!res) return;
  showForm.value = false;
  tried.value = false;
  await load();
}

async function apply(fn: () => Promise<unknown>): Promise<void> {
  if ((await act.run(fn)) !== undefined) await load();
}

function saveGrade(d: Defense): Promise<void> {
  const value = Number((grades[d.id] ?? '').replace(',', '.'));
  if (!(grades[d.id] ?? '').trim() || Number.isNaN(value)) return Promise.resolve();
  return apply(() => internships.grade(d.id, value, remarks[d.id]?.trim() || undefined));
}

const convene = (d: Defense) => apply(() => internships.convene(d.id));
const minutes = (d: Defense) => apply(() => internships.minutes(d.id));

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="ts('hs.s.title')" :lede="ts('hs.s.lede')" section="portal">
    <InternshipsNav />
    <div class="hi-form">
      <div class="field">
        <label for="df-st">{{ ts('hs.status') }}</label>
        <select id="df-st" v-model="status" @change="load"><option value="">{{ ts('hs.all') }}</option><option v-for="s in DEFENSE_STATUSES" :key="s" :value="s">{{ ts(ik('hs.dstate.', s)) }}</option></select>
      </div>
      <label class="hi-check"><input v-model="mine" type="checkbox" data-test="mine" @change="load"> {{ ts('hs.s.mine') }}</label>
      <button v-if="canSchedule" type="button" class="btn btn--primary" data-test="new-defense" @click="showForm = !showForm">{{ ts('hs.s.new') }}</button>
    </div>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />

    <form v-if="showForm" class="card hi-form" data-test="defense-form" @submit.prevent="schedule">
      <div class="field"><label for="ds-i">{{ ts('hs.s.internship') }}</label><input id="ds-i" v-model="form.internship_id" maxlength="40"></div>
      <div class="field"><label for="ds-d">{{ ts('hs.s.day') }}</label><input id="ds-d" v-model="form.held_on" type="date"></div>
      <div class="field"><label for="ds-s">{{ ts('hs.s.start') }}</label><input id="ds-s" v-model="form.start_time" type="time"></div>
      <div class="field"><label for="ds-e">{{ ts('hs.s.end') }}</label><input id="ds-e" v-model="form.end_time" type="time"></div>
      <div class="field"><label for="ds-r">{{ ts('hs.s.room') }}</label><input id="ds-r" v-model="form.room" maxlength="80"></div>
      <fieldset class="hi-wide">
        <legend>{{ ts('hs.s.jury') }}</legend>
        <p class="muted hi-small">{{ ts('hs.s.juryRule') }}</p>
        <div v-for="(m, i) in jury" :key="i" class="hi-form" data-test="jury-member">
          <div class="field"><label :for="`jm-p-${i}`">{{ ts('hs.s.person') }}</label><input :id="`jm-p-${i}`" v-model="m.person_id" maxlength="40"></div>
          <div class="field"><label :for="`jm-r-${i}`">{{ ts('hs.d.kind') }}</label><select :id="`jm-r-${i}`" v-model="m.jury_role"><option v-for="r in JURY_ROLES" :key="r" :value="r">{{ ts(ik('hs.role.', r)) }}</option></select></div>
        </div>
        <button type="button" class="btn btn--quiet" @click="jury.push({ person_id: '', jury_role: 'member' })">{{ ts('hs.s.addMember') }}</button>
      </fieldset>
      <p v-if="tried && formMissing" class="error">{{ ts('hs.required') }}</p>
      <button type="submit" class="btn btn--primary" :disabled="act.busy.value" data-test="schedule">{{ ts('hs.s.schedule') }}</button>
    </form>

    <ul class="hi-list card">
      <li v-for="d in items" :key="d.id" data-test="defense-row">
        <div class="hi-grow">
          <strong>{{ ts('hs.s.slot', { date: formatDate(d.held_on, locale), start: d.start_time, end: d.end_time, room: d.room }) }}</strong>
          <p class="muted hi-small">{{ d.jury.map((m) => `${m.display_name || m.person_id} (${ts(ik('hs.role.', m.jury_role))})`).join(', ') }}</p>
          <p v-if="d.grade !== null" class="hi-small">{{ ts('hs.m.grade', { grade: d.grade }) }}</p>
        </div>
        <span class="tag" :class="`tag--${toneOf(d.status) ?? 'neutral'}`">{{ ts(ik('hs.dstate.', d.status)) }}</span>
        <div v-if="writable" class="hi-actions" style="flex-basis: 100%">
          <button v-if="canSchedule && d.status === 'scheduled'" type="button" class="btn btn--quiet" data-test="convene" @click="convene(d)">{{ ts('hs.s.convene') }}</button>
          <template v-if="d.status === 'convened' || d.status === 'graded'">
            <div class="field"><label :for="`g-${d.id}`">{{ ts('hs.s.grade') }}</label><input :id="`g-${d.id}`" v-model="grades[d.id]" inputmode="decimal" :data-test="`grade-${d.id}`"></div>
            <div class="field"><label :for="`rm-${d.id}`">{{ ts('hs.s.remarks') }}</label><input :id="`rm-${d.id}`" v-model="remarks[d.id]" maxlength="1000"></div>
            <button type="button" class="btn btn--quiet" data-test="save-grade" @click="saveGrade(d)">{{ ts('hs.s.saveGrade') }}</button>
          </template>
          <button v-if="d.status === 'graded'" type="button" class="btn btn--primary" data-test="minutes" @click="minutes(d)">{{ ts('hs.s.minutes') }}</button>
        </div>
      </li>
      <li v-if="items.length === 0" class="muted">{{ ts('hs.none') }}</li>
    </ul>
  </HigherFrame>
</template>
