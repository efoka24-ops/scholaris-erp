<script setup lang="ts">
// Conseils de discipline (lot E2-B, T866, RG-PRE-3) : convocation (les parents sont prévenus), puis décision du président en séance.
// Une exclusion définitive exige le procès-verbal et l'audition de l'élève et des parents : l'écran le rappelle avant l'envoi, le
// serveur le refuse sinon.
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  FINAL_EXCLUSION, finalExclusionMissing, secondaryDiscipline,
  type ClassChoice, type ConductProposal, type CouncilDecisionInput, type DisciplineCouncil, type StudentHours,
} from '@/api/secondaryDiscipline';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction, usePermissions } from '../useSec';

const { t } = useI18n();
const route = useRoute();
const { error, busy, run } = useAction();
const { can } = usePermissions();

const KINDS = ['conduct_warning', 'conduct_blame', 'temporary_exclusion', FINAL_EXCLUSION, 'no_sanction'] as const;

const classes = ref<ClassChoice[]>([]);
const students = ref<StudentHours[]>([]);
const proposals = ref<ConductProposal[]>([]);
const councils = ref<DisciplineCouncil[]>([]);
const form = ref({ class_id: '', person_id: '', proposal_id: '', facts: '', hearing_on: '', hearing_time: '', place: '', members: '' });
const decisions = ref<Record<string, CouncilDecisionInput>>({});

const canConvene = computed(() => can('secondary.discipline.manage', 'secondary.discipline.council.preside'));
const canDecide = computed(() => can('secondary.discipline.council.preside'));
const names = computed(() => Object.fromEntries(students.value.map((s) => [s.person_id, `${s.last_name} ${s.first_name}`])));
const personProposals = computed(() => proposals.value.filter((p) => p.person_id === form.value.person_id && p.status === 'proposed'));

function decisionOf(id: string): CouncilDecisionInput {
  decisions.value[id] ??= { decision_kind: 'conduct_warning', decision_reason: '', minutes: '', student_heard: false, parents_heard: false, exclusion_days: null };
  return decisions.value[id] as CouncilDecisionInput;
}
const kindKey = (kind: string): MessageKey => (KINDS.includes(kind as (typeof KINDS)[number]) ? `secdc.kind.${kind}` : 'secdc.kind') as MessageKey;

async function loadStudents(): Promise<void> {
  if (form.value.class_id === '') return;
  students.value = (await run(() => secondaryDiscipline.hours(form.value.class_id).catch(() => null)))?.students ?? [];
}
async function load(): Promise<void> {
  councils.value = (await run(() => secondaryDiscipline.councils())) ?? [];
  proposals.value = (await run(() => secondaryDiscipline.proposals('proposed'))) ?? [];
}
async function convene(): Promise<void> {
  const f = form.value;
  const members = f.members.split('\n').map((m) => m.trim()).filter((m) => m !== '');
  const saved = await run(() => secondaryDiscipline.convene({
    person_id: f.person_id, class_id: f.class_id, proposal_id: f.proposal_id || null, facts: f.facts.trim(),
    hearing_on: f.hearing_on, hearing_time: f.hearing_time, place: f.place.trim(), ...(members.length > 0 ? { members } : {}),
  }));
  if (saved) {
    form.value = { ...f, person_id: '', proposal_id: '', facts: '' };
    await load();
  }
}
async function decide(c: DisciplineCouncil): Promise<void> {
  const d = decisionOf(c.id);
  if (finalExclusionMissing(d).length > 0) {
    error.value = new Error(t('secdc.missing'));
    return;
  }
  const body: CouncilDecisionInput = {
    decision_kind: d.decision_kind, decision_reason: d.decision_reason.trim(), student_heard: d.student_heard === true, parents_heard: d.parents_heard === true,
    ...((d.minutes ?? '').trim() !== '' ? { minutes: (d.minutes ?? '').trim() } : {}),
    ...(d.decision_kind === 'temporary_exclusion' && d.exclusion_days ? { exclusion_days: d.exclusion_days } : {}),
  };
  if (await run(() => secondaryDiscipline.decideCouncil(c.id, body))) await load();
}

onMounted(async () => {
  classes.value = await secondaryDiscipline.classes().catch(() => []);
  const q = route.query;
  if (typeof q.class === 'string' && q.class !== '') form.value.class_id = q.class;
  await loadStudents();
  if (typeof q.person === 'string') form.value.person_id = q.person;
  if (typeof q.proposal === 'string') form.value.proposal_id = q.proposal;
  await load();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secdc.title') }}</h1>
      <p class="lede">{{ t('secdc.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="canConvene" class="card" aria-labelledby="secdc-new">
      <h2 id="secdc-new">{{ t('secdc.newTitle') }}</h2>
      <form class="stack" @submit.prevent="convene">
        <div class="field">
          <label for="secdc-class">{{ t('secd.class') }}</label>
          <select v-if="classes.length > 0" id="secdc-class" v-model="form.class_id" required @change="loadStudents">
            <option value="" disabled>{{ t('secd.chooseClass') }}</option>
            <option v-for="c in classes" :key="c.id" :value="c.structure_id">{{ c.label }}</option>
          </select>
          <input v-else id="secdc-class" v-model.trim="form.class_id" type="text" maxlength="40" required @change="loadStudents" />
        </div>
        <div class="field">
          <label for="secdc-student">{{ t('secdc.student') }}</label>
          <select id="secdc-student" v-model="form.person_id" required>
            <option value="" disabled>—</option>
            <option v-for="s in students" :key="s.person_id" :value="s.person_id">{{ s.last_name }} {{ s.first_name }}</option>
          </select>
        </div>
        <div class="field">
          <label for="secdc-proposal">{{ t('secdc.proposal') }}</label>
          <select id="secdc-proposal" v-model="form.proposal_id">
            <option value="">{{ t('secdc.none') }}</option>
            <option v-for="p in personProposals" :key="p.id" :value="p.id">{{ p.proposed_label }} · {{ p.period_label }}</option>
          </select>
        </div>
        <div class="field">
          <label for="secdc-facts">{{ t('secdc.facts') }}</label>
          <textarea id="secdc-facts" v-model="form.facts" maxlength="2000" rows="3" required />
        </div>
        <div class="row">
          <div class="field">
            <label for="secdc-date">{{ t('secdc.hearingOn') }}</label>
            <input id="secdc-date" v-model="form.hearing_on" type="date" required />
          </div>
          <div class="field">
            <label for="secdc-time">{{ t('secdc.hearingTime') }}</label>
            <input id="secdc-time" v-model="form.hearing_time" type="time" required />
          </div>
        </div>
        <div class="field">
          <label for="secdc-place">{{ t('secdc.place') }}</label>
          <input id="secdc-place" v-model="form.place" type="text" maxlength="160" required />
        </div>
        <div class="field">
          <label for="secdc-members">{{ t('secdc.members') }}</label>
          <textarea id="secdc-members" v-model="form.members" rows="3" />
        </div>
        <button type="submit" :disabled="busy">{{ t('secdc.submit') }}</button>
      </form>
    </section>

    <section class="card" aria-labelledby="secdc-list">
      <h2 id="secdc-list">{{ t('secdc.listTitle') }}</h2>
      <p v-if="councils.length === 0" class="muted">{{ t('secdc.noCouncils') }}</p>
      <ul v-else class="rows">
        <li v-for="c in councils" :key="c.id">
          <strong>{{ names[c.person_id] ?? c.person_id }}</strong>
          <span class="tag" :class="{ 'tag--warning': c.status === 'convened' }">{{ t(`secdc.st.${c.status}` as MessageKey) }}</span>
          <div class="muted">{{ t('secdc.line', { date: c.hearing_on, time: c.hearing_time, place: c.place }) }}</div>
          <p>{{ c.facts }}</p>
          <p v-if="c.decision_kind">{{ t('secdc.decision', { kind: t(kindKey(c.decision_kind)) }) }} · {{ c.decision_reason }}</p>
          <form v-if="c.status === 'convened' && canDecide" class="stack" @submit.prevent="decide(c)">
            <h3>{{ t('secdc.decideTitle') }}</h3>
            <div class="field">
              <label :for="`secdc-kind-${c.id}`">{{ t('secdc.kind') }}</label>
              <select :id="`secdc-kind-${c.id}`" v-model="decisionOf(c.id).decision_kind">
                <option v-for="k in KINDS" :key="k" :value="k">{{ t(kindKey(k)) }}</option>
              </select>
            </div>
            <div class="field">
              <label :for="`secdc-reason-${c.id}`">{{ t('secdc.reason') }}</label>
              <input :id="`secdc-reason-${c.id}`" v-model="decisionOf(c.id).decision_reason" type="text" maxlength="1000" required />
            </div>
            <div v-if="decisionOf(c.id).decision_kind === 'temporary_exclusion'" class="field">
              <label :for="`secdc-days-${c.id}`">{{ t('secdc.days') }}</label>
              <input :id="`secdc-days-${c.id}`" v-model.number="decisionOf(c.id).exclusion_days" type="number" min="1" max="365" />
            </div>
            <div class="field">
              <label :for="`secdc-minutes-${c.id}`">{{ t('secdc.minutes') }}</label>
              <textarea :id="`secdc-minutes-${c.id}`" v-model="decisionOf(c.id).minutes" rows="4" maxlength="20000" :required="decisionOf(c.id).decision_kind === FINAL_EXCLUSION" />
            </div>
            <label class="check"><input v-model="decisionOf(c.id).student_heard" type="checkbox" /> {{ t('secdc.studentHeard') }}</label>
            <label class="check"><input v-model="decisionOf(c.id).parents_heard" type="checkbox" /> {{ t('secdc.parentsHeard') }}</label>
            <p v-if="finalExclusionMissing(decisionOf(c.id)).length > 0" class="notice notice--warning" role="status">{{ t('secdc.missing') }}</p>
            <button type="submit" :disabled="busy">{{ t('secdc.record') }}</button>
          </form>
        </li>
      </ul>
    </section>
  </div>
</template>
