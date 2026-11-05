<script setup lang="ts">
// Discipline et absences (lot E2-B, T865, T867) : cumul en heures de l'appel par heure de cours du socle (l'appel lui-même se fait
// dans l'écran Appel), barème de l'établissement, propositions de sanction décidées par une personne habilitée selon le niveau du
// palier (RG-PRE-2), exclusions de cours rejouables sans doublon. Le serveur reste juge des droits et des périmètres.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  formatHours, secondaryDiscipline,
  type ClassChoice, type ClassExclusion, type ClassHours, type ComputeResult, type ConductProposal, type DisciplineScale, type ProposalStatus, type ScaleTier,
} from '@/api/secondaryDiscipline';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction, usePermissions } from '../useSec';

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();

const classes = ref<ClassChoice[]>([]);
const classId = ref('');
const from = ref('');
const to = ref('');
const periodLabel = ref('');
const scale = ref<DisciplineScale | null>(null);
const hours = ref<ClassHours | null>(null);
const proposals = ref<ConductProposal[]>([]);
const statusFilter = ref<'' | ProposalStatus>('proposed');
const computed_ = ref<ComputeResult | null>(null);
const reasons = ref<Record<string, string>>({});
const exclusions = ref<ClassExclusion[]>([]);
const exclusionNotice = ref('');
const exclusion = ref({ person_id: '', session_date: new Date().toISOString().slice(0, 10), hour_label: '', reason: '' });
let pendingUuid: string | null = null;

const canCompute = computed(() => can('secondary.discipline.manage'));
const canReadProposals = computed(() => can('secondary.discipline.manage', 'secondary.discipline.validate', 'secondary.discipline.council.preside', 'secondary.attendance.manage'));
const canDecide = computed(() => can('secondary.discipline.manage', 'secondary.discipline.validate'));
const canConvene = computed(() => can('secondary.discipline.manage', 'secondary.discipline.council.preside'));
const canExclude = computed(() => can('secondary.attendance.take', 'secondary.attendance.manage', 'secondary.discipline.manage'));
const canTakeCall = computed(() => can('attendance.take'));

const names = computed(() => Object.fromEntries((hours.value?.students ?? []).map((s) => [s.person_id, `${s.last_name} ${s.first_name}`])));

function levelKey(level: string): MessageKey {
  return (['surveillant_general', 'chef_etablissement', 'conseil_discipline'].includes(level) ? `secd.level.${level}` : 'secd.level.other') as MessageKey;
}
function tierText(tier: ScaleTier): string {
  const label = tier.label ?? tier.code;
  return tier.above_hours !== undefined ? t('secd.tierAbove', { hours: tier.above_hours, label }) : t('secd.tierFrom', { hours: tier.from_hours ?? 0, label });
}
function newUuid(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `ex-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

async function loadHours(): Promise<void> {
  if (classId.value === '') return;
  hours.value = (await run(() => secondaryDiscipline.hours(classId.value, from.value || undefined, to.value || undefined))) ?? null;
  exclusions.value = (await run(() => secondaryDiscipline.exclusions(classId.value))) ?? [];
}
async function loadProposals(): Promise<void> {
  if (canReadProposals.value) proposals.value = (await run(() => secondaryDiscipline.proposals(statusFilter.value === '' ? undefined : statusFilter.value))) ?? [];
}
async function compute(): Promise<void> {
  const result = await run(() => secondaryDiscipline.compute({
    class_id: classId.value || null,
    ...(from.value && to.value ? { from: from.value, to: to.value } : {}),
    ...(periodLabel.value.trim() ? { period_label: periodLabel.value.trim() } : {}),
  }));
  if (result) {
    computed_.value = result;
    await loadProposals();
    await loadHours();
  }
}
async function decide(p: ConductProposal, decision: 'apply' | 'reject'): Promise<void> {
  const reason = (reasons.value[p.id] ?? '').trim();
  if (decision === 'reject' && reason === '') {
    error.value = new Error(t('secd.rejectReason'));
    return;
  }
  if (await run(() => secondaryDiscipline.decide(p.id, decision, reason || undefined))) {
    reasons.value[p.id] = '';
    await loadProposals();
  }
}
async function reportExclusion(): Promise<void> {
  const e = exclusion.value;
  if (classId.value === '' || e.person_id === '' || e.hour_label.trim() === '' || e.reason.trim() === '') return;
  pendingUuid ??= newUuid();
  const saved = await run(() => secondaryDiscipline.reportExclusion({ client_uuid: pendingUuid as string, class_id: classId.value, person_id: e.person_id, session_date: e.session_date, hour_label: e.hour_label.trim(), reason: e.reason.trim() }));
  if (saved) {
    pendingUuid = null;
    exclusionNotice.value = saved.replayed ? t('secd.exclusionReplayed') : t('secd.exclusionSaved');
    exclusion.value = { ...e, person_id: '', reason: '' };
    exclusions.value = (await run(() => secondaryDiscipline.exclusions(classId.value))) ?? [];
  }
}

onMounted(async () => {
  scale.value = (await run(() => secondaryDiscipline.scale())) ?? null;
  // Sans droit de lecture du paramétrage (enseignant), la classe se saisit : la liste est un confort, pas une erreur.
  classes.value = await secondaryDiscipline.classes().catch(() => []);
  await loadProposals();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secd.title') }}</h1>
      <p class="lede">{{ t('secd.intro') }}</p>
      <RouterLink v-if="canTakeCall" class="button" :to="{ name: 'attendance' }">{{ t('secd.takeCall') }}</RouterLink>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="scale" class="card" aria-labelledby="secd-scale">
      <h2 id="secd-scale">{{ t('secd.scaleTitle') }}</h2>
      <p v-if="scale.errors.length > 0" class="notice notice--warning" role="alert">{{ t('secd.scaleInvalid', { errors: scale.errors.join(' ') }) }}</p>
      <template v-else-if="scale.configured">
        <ul class="rows"><li v-for="tier in scale.scale.tiers" :key="tier.code">{{ tierText(tier) }}</li></ul>
      </template>
      <template v-else>
        <p class="notice notice--warning" role="status">{{ t('secd.scaleMissing') }}</p>
        <p class="muted">{{ t('secd.exampleTitle') }}</p>
        <ul class="rows"><li v-for="tier in scale.example.tiers" :key="tier.code">{{ tierText(tier) }}</li></ul>
      </template>
    </section>

    <section class="card" aria-labelledby="secd-filter">
      <h2 id="secd-filter" class="sr-only">{{ t('secd.class') }}</h2>
      <form class="row" @submit.prevent="loadHours">
        <div class="field">
          <label for="secd-class">{{ t('secd.class') }}</label>
          <select v-if="classes.length > 0" id="secd-class" v-model="classId">
            <option value="">{{ t('secd.chooseClass') }}</option>
            <option v-for="c in classes" :key="c.id" :value="c.structure_id">{{ c.label }}</option>
          </select>
          <input v-else id="secd-class" v-model.trim="classId" type="text" maxlength="40" />
        </div>
        <div class="field">
          <label for="secd-from">{{ t('secd.from') }}</label>
          <input id="secd-from" v-model="from" type="date" />
        </div>
        <div class="field">
          <label for="secd-to">{{ t('secd.to') }}</label>
          <input id="secd-to" v-model="to" type="date" />
        </div>
        <button type="submit" :disabled="busy || classId === ''">{{ t('secd.show') }}</button>
      </form>
      <p class="muted">{{ t('secd.periodHint') }}</p>
    </section>

    <section v-if="hours" class="card" aria-labelledby="secd-hours">
      <h2 id="secd-hours">{{ t('secd.hoursTitle') }} · {{ hours.class_name }}</h2>
      <p class="muted">{{ t('secd.period', { label: hours.period.label, from: hours.period.from, to: hours.period.to }) }}</p>
      <p v-if="hours.students.length === 0" class="muted">{{ t('secd.noStudents') }}</p>
      <ul v-else class="rows">
        <li v-for="s in hours.students" :key="s.person_id">
          <strong>{{ s.last_name }} {{ s.first_name }}</strong>
          <div class="muted">
            {{ t('secd.absent', { hours: formatHours(s.absent_hours) }) }} ·
            <span :class="{ 'tag tag--warning': s.unjustified_hours > 0 }">{{ t('secd.unjustified', { hours: formatHours(s.unjustified_hours) }) }}</span> ·
            {{ t('secd.justified', { hours: formatHours(s.justified_hours) }) }} · {{ t('secd.lates', { count: s.lates }) }}
          </div>
          <span v-for="p in s.proposals" :key="p.id" class="tag">{{ p.proposed_label }} · {{ t(`secd.st.${p.status}` as MessageKey) }}</span>
        </li>
      </ul>
      <form v-if="canCompute" class="row" @submit.prevent="compute">
        <div class="field">
          <label for="secd-label">{{ t('secd.periodLabel') }}</label>
          <input id="secd-label" v-model="periodLabel" type="text" maxlength="80" />
        </div>
        <button type="submit" :disabled="busy || (scale !== null && !scale.configured)">{{ t('secd.compute') }}</button>
      </form>
      <p v-if="computed_" class="notice notice--success" role="status">
        {{ t('secd.computed', { created: computed_.created.length, unchanged: computed_.unchanged, examined: computed_.examined }) }}
      </p>
    </section>

    <section v-if="canReadProposals" class="card" aria-labelledby="secd-proposals">
      <h2 id="secd-proposals">{{ t('secd.proposalsTitle') }}</h2>
      <div class="field">
        <label for="secd-status">{{ t('secd.filterStatus') }}</label>
        <select id="secd-status" v-model="statusFilter" @change="loadProposals">
          <option value="">{{ t('secd.all') }}</option>
          <option v-for="s in ['proposed', 'applied', 'rejected', 'superseded']" :key="s" :value="s">{{ t(`secd.st.${s}` as MessageKey) }}</option>
        </select>
      </div>
      <p v-if="proposals.length === 0" class="muted">{{ t('secd.noProposals') }}</p>
      <ul v-else class="rows">
        <li v-for="p in proposals" :key="p.id">
          <strong>{{ names[p.person_id] ?? p.person_id }}</strong>
          <span class="tag" :class="{ 'tag--warning': p.status === 'proposed' }">{{ t(`secd.st.${p.status}` as MessageKey) }}</span>
          <span v-if="p.repeat_offence" class="tag tag--warning">{{ t('secd.repeat') }}</span>
          <div>{{ t('secd.proposalLine', { label: p.proposed_label, hours: formatHours(p.unjustified_hours), period: p.period_label }) }}</div>
          <div class="muted">{{ t(levelKey(p.decision_level)) }}</div>
          <div v-if="p.decided_at" class="muted">{{ t('secd.decidedBy', { who: p.decided_by ?? '', date: p.decided_at.slice(0, 10) }) }}<template v-if="p.decision_reason"> · {{ p.decision_reason }}</template></div>
          <div v-if="p.status === 'proposed' && canDecide" class="row">
            <p v-if="p.decision_level === 'conseil_discipline'" class="muted">{{ t('secd.councilOnly') }}</p>
            <button v-else type="button" :disabled="busy" @click="decide(p, 'apply')">{{ t('secd.apply') }}</button>
            <div class="field">
              <label :for="`secd-reason-${p.id}`">{{ t('secd.rejectReason') }}</label>
              <input :id="`secd-reason-${p.id}`" v-model="reasons[p.id]" type="text" maxlength="1000" />
            </div>
            <button type="button" class="button--secondary" :disabled="busy" @click="decide(p, 'reject')">{{ t('secd.reject') }}</button>
            <RouterLink v-if="p.decision_level === 'conseil_discipline' && canConvene" :to="{ name: 'sec-discipline-councils', query: { proposal: p.id, person: p.person_id, class: p.class_id ?? '' } }">
              {{ t('secd.convene') }}
            </RouterLink>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="canExclude && hours" class="card" aria-labelledby="secd-exclusions">
      <h2 id="secd-exclusions">{{ t('secd.exclusionsTitle') }}</h2>
      <form class="stack" @submit.prevent="reportExclusion">
        <div class="field">
          <label for="secd-ex-student">{{ t('secd.exclusionStudent') }}</label>
          <select id="secd-ex-student" v-model="exclusion.person_id" required>
            <option value="" disabled>—</option>
            <option v-for="s in hours.students" :key="s.person_id" :value="s.person_id">{{ s.last_name }} {{ s.first_name }}</option>
          </select>
        </div>
        <div class="field">
          <label for="secd-ex-date">{{ t('secd.exclusionDate') }}</label>
          <input id="secd-ex-date" v-model="exclusion.session_date" type="date" required />
        </div>
        <div class="field">
          <label for="secd-ex-hour">{{ t('secd.exclusionHour') }}</label>
          <input id="secd-ex-hour" v-model="exclusion.hour_label" type="text" maxlength="20" required />
        </div>
        <div class="field">
          <label for="secd-ex-reason">{{ t('secd.exclusionReason') }}</label>
          <input id="secd-ex-reason" v-model="exclusion.reason" type="text" maxlength="500" required />
        </div>
        <button type="submit" :disabled="busy">{{ t('secd.exclusionReport') }}</button>
      </form>
      <p v-if="exclusionNotice" class="notice notice--success" role="status">{{ exclusionNotice }}</p>
      <p v-if="exclusions.length === 0" class="muted">{{ t('secd.noExclusions') }}</p>
      <ul v-else class="rows">
        <li v-for="x in exclusions" :key="x.id">
          <strong>{{ names[x.person_id] ?? x.person_id }}</strong>
          <div class="muted">{{ t('secd.exclusionLine', { date: x.session_date, hour: x.hour_label, reason: x.reason }) }}</div>
        </li>
      </ul>
    </section>
  </div>
</template>
