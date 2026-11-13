<script setup lang="ts">
// Orientation du secondaire (lot E3-D, T905, module `orientation`) : vœux, entretien et avis du conseiller, décision motivée du conseil,
// recours dans le délai secondary.appeal_days. L'élève et le parent lié déposent leurs vœux ou un recours ; le serveur reste juge.
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import type { AcademicYear } from '@/api/admin';
import { secondarySetup, type OpenedStream, type SchoolClass } from '@/api/secondarySetup';
import { secondaryOrientation, type OrientationAppeal, type OrientationCase } from '@/api/secondaryCouncilsOrientation';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction, usePermissions } from '../useSec';

const { t } = useI18n();

const { error, busy, run } = useAction();
const { can } = usePermissions();
const years = ref<AcademicYear[]>([]);
const classes = ref<SchoolClass[]>([]);
const streams = ref<OpenedStream[]>([]);
const yearId = ref('');
const classId = ref('');
const selfService = computed(() => !canManage.value && !canDecide.value && (canSubmitOwn.value || canSubmitAsGuardian.value));
const cases = ref<OrientationCase[]>([]);
const appeals = ref<OrientationAppeal[]>([]);
const selected = ref<OrientationCase | null>(null);
const wishDraft = ref<string[]>([]);
const opinion = ref('');
const proposed = ref('');
const interviewOn = ref('');
const decisionStream = ref('');
const decisionReason = ref('');
const appealReason = ref('');
const appealOutcome = ref<'accepted' | 'rejected'>('rejected');
const appealOutcomeReason = ref('');
const revisedStream = ref('');

const canManage = computed(() => can('secondary.orientation.manage'));
const canDecide = computed(() => can('secondary.council.preside'));
const canSubmitOwn = computed(() => can('secondary.self.read'));
const canSubmitAsGuardian = computed(() => can('secondary.family.read'));
const activeStreams = computed(() => streams.value.filter((stream) => stream.active));

async function load(): Promise<void> {
  years.value = await run(() => secondarySetup.years()) ?? [];
  if (yearId.value === '') yearId.value = years.value.find((year) => year.status === 'open')?.id ?? years.value[0]?.id ?? '';
  await loadYear();
}
async function loadYear(): Promise<void> {
  if (yearId.value === '') return;
  if (selfService.value) {
    classes.value = [];
    streams.value = await run(() => secondarySetup.streams()) ?? [];
    if (canSubmitOwn.value) {
      const ownCase = await run(() => secondaryOrientation.me(yearId.value));
      cases.value = ownCase ? [ownCase] : [];
    } else {
      cases.value = await run(() => secondaryOrientation.family(yearId.value)) ?? [];
    }
    appeals.value = await run(() => secondaryOrientation.myAppeals(yearId.value)) ?? [];
    if (!selected.value || !cases.value.some((item) => item.person_id === selected.value?.person_id)) selected.value = cases.value[0] ?? null;
    if (selected.value) selectCase(selected.value);
    return;
  }
  classes.value = await run(() => secondarySetup.classes(yearId.value)) ?? [];
  streams.value = await run(() => secondarySetup.streams()) ?? [];
  if (!classes.value.some((item) => item.structure_id === classId.value)) classId.value = classes.value[0]?.structure_id ?? '';
  await loadClass();
}
async function loadClass(): Promise<void> {
  if (classId.value === '' || yearId.value === '') {
    cases.value = [];
    appeals.value = [];
    selected.value = null;
    return;
  }
  cases.value = await run(() => secondaryOrientation.cases(classId.value, yearId.value)) ?? [];
  appeals.value = await run(() => secondaryOrientation.appeals(classId.value)) ?? [];
  if (selected.value) {
    const refreshed = cases.value.find((item) => item.person_id === selected.value?.person_id);
    if (refreshed) selectCase(refreshed);
    else selected.value = null;
  }
}
function selectCase(item: OrientationCase): void {
  selected.value = item;
  wishDraft.value = [...item.wishes];
  opinion.value = item.counsellor_opinion ?? '';
  proposed.value = item.proposed_stream_id ?? '';
  interviewOn.value = item.interview_on ?? '';
  decisionStream.value = item.latest_decision?.stream_id ?? '';
  decisionReason.value = item.latest_decision?.reason ?? '';
}
async function saveWishes(): Promise<void> {
  if (!selected.value) return;
  const saved = canManage.value
    ? await run(() => secondaryOrientation.saveWishes(selected.value?.person_id ?? '', yearId.value, wishDraft.value))
    : await run(() => secondaryOrientation.saveOwnWishes(yearId.value, wishDraft.value));
  if (saved) {
    if (selfService.value) await loadYear();
    else await loadClass();
  }
}
async function saveAdvice(): Promise<void> {
  if (!selected.value) return;
  const saved = await run(() => secondaryOrientation.saveAdvice(selected.value?.person_id ?? '', yearId.value, {
    counsellor_opinion: opinion.value.trim() || null,
    proposed_stream_id: proposed.value || null,
    interview_on: interviewOn.value || null,
  }));
  if (saved) await loadClass();
}
async function saveDecision(): Promise<void> {
  if (!selected.value || !decisionStream.value || !decisionReason.value.trim()) return;
  const saved = await run(() => secondaryOrientation.decide(selected.value?.person_id ?? '', yearId.value, decisionStream.value, decisionReason.value.trim()));
  if (saved) await loadClass();
}
async function fileAppeal(): Promise<void> {
  if (!selected.value || !appealReason.value.trim()) return;
  const saved = await run(() => secondaryOrientation.appeal(selected.value?.person_id ?? '', yearId.value, appealReason.value.trim()));
  if (saved) {
    appealReason.value = '';
    if (selfService.value) await loadYear();
    else await loadClass();
  }
}
async function reviewAppeal(item: OrientationAppeal): Promise<void> {
  if (!appealOutcomeReason.value.trim()) return;
  const saved = await run(() => secondaryOrientation.decideAppeal(item.id, appealOutcome.value, appealOutcomeReason.value.trim(), appealOutcome.value === 'accepted' ? revisedStream.value : null));
  if (saved) {
    appealOutcomeReason.value = '';
    revisedStream.value = '';
    await loadClass();
  }
}

function streamCode(id: string): string {
  return streams.value.find((stream) => stream.id === id)?.code ?? id;
}
function appealStatus(status: OrientationAppeal['status']): string {
  return t(`seco.status.${status}` as MessageKey);
}

watch(yearId, loadYear);
watch(classId, loadClass);
onMounted(load);
</script>

<template>
  <div class="page orientation">
    <header class="page-head">
      <p class="eyebrow">{{ t('seco.eyebrow') }}</p>
      <h1>{{ t('seco.title') }}</h1>
      <p class="lede">{{ t('seco.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card filters" :aria-label="t('seco.selection')">
      <label class="field">{{ t('seco.year') }}
        <select v-model="yearId" :disabled="busy"><option value="" disabled>{{ t('seco.chooseYear') }}</option><option v-for="year in years" :key="year.id" :value="year.id">{{ year.label }}</option></select>
      </label>
      <label v-if="!selfService" class="field">{{ t('seco.class') }}
        <select v-model="classId" :disabled="busy || classes.length === 0"><option value="" disabled>{{ t('seco.chooseClass') }}</option><option v-for="item in classes" :key="item.structure_id" :value="item.structure_id">{{ item.label }}</option></select>
      </label>
    </section>

    <div class="columns">
      <section v-if="!selfService || (canSubmitAsGuardian && !canSubmitOwn)" class="card roster" aria-labelledby="orientation-roster">
        <h2 id="orientation-roster">{{ selfService ? t('seco.linked') : t('seco.students') }} · {{ cases.length }}</h2>
        <p v-if="cases.length === 0" class="muted">{{ selfService ? t('seco.noLinked') : t('seco.noStudents') }}</p>
        <ul v-else class="rows">
          <li v-for="item in cases" :key="item.person_id">
            <button type="button" class="student" :aria-pressed="selected?.person_id === item.person_id" @click="selectCase(item)">
              <strong>{{ item.student_name }}</strong>
              <span class="muted">{{ item.latest_decision ? t('seco.decided', { stream: streamCode(item.latest_decision.stream_id) }) : t('seco.pending') }}</span>
            </button>
          </li>
        </ul>
      </section>

      <section v-if="selected" :key="selected.person_id" class="card detail" aria-labelledby="orientation-detail">
        <h2 id="orientation-detail">{{ selected.student_name }}</h2>
        <form v-if="canManage || (selfService && canSubmitOwn)" class="stack section-form" @submit.prevent="saveWishes">
          <fieldset class="field wishes">
<legend>{{ t('seco.wishes') }} <span class="muted">{{ t('seco.wishesMax') }}</span></legend>
            <label v-for="stream in activeStreams" :key="stream.id" class="wish-option"><input v-model="wishDraft" type="checkbox" :value="stream.id" :disabled="wishDraft.length >= 8 && !wishDraft.includes(stream.id)" /> {{ stream.code }} · {{ stream.label }}</label>
            <p v-if="activeStreams.length === 0" class="muted">{{ t('seco.noStream') }}</p>
          </fieldset>
          <button class="btn" type="submit" :disabled="busy">{{ t('seco.saveWishes') }}</button>
        </form>
        <ul v-else class="chips"><li v-for="wish in selected.wishes" :key="wish">{{ streamCode(wish) }}</li><li v-if="selected.wishes.length === 0" class="muted">{{ t('seco.noWish') }}</li></ul>

        <form v-if="canManage" class="stack section-form" @submit.prevent="saveAdvice">
          <h3>{{ t('seco.adviceTitle') }}</h3>
          <label class="field">{{ t('seco.interview') }} <input v-model="interviewOn" type="date" /></label>
          <label class="field">{{ t('seco.proposed') }}
            <select v-model="proposed"><option value="">{{ t('seco.none') }}</option><option v-for="stream in activeStreams" :key="stream.id" :value="stream.id">{{ stream.code }} · {{ stream.label }}</option></select>
          </label>
          <label class="field">{{ t('seco.opinion') }} <textarea v-model="opinion" maxlength="255" rows="3" /></label>
          <button class="btn" type="submit" :disabled="busy">{{ t('seco.saveAdvice') }}</button>
        </form>
        <p v-else class="muted">{{ selected.counsellor_opinion || t('seco.noOpinion') }}<template v-if="selected.interview_on"> · {{ t('seco.interviewOn', { date: selected.interview_on }) }}</template></p>

        <form v-if="canDecide" class="stack section-form" @submit.prevent="saveDecision">
          <h3>{{ t('seco.decisionTitle') }}</h3>
          <label class="field">{{ t('seco.retained') }}
            <select v-model="decisionStream" required><option value="" disabled>{{ t('seco.chooseStream') }}</option><option v-for="stream in activeStreams" :key="stream.id" :value="stream.id">{{ stream.code }} · {{ stream.label }}</option></select>
          </label>
          <label class="field">{{ t('seco.reason') }} <textarea v-model="decisionReason" maxlength="255" rows="3" required /></label>
          <button class="btn btn--primary" type="submit" :disabled="busy">{{ t('seco.saveDecision') }}</button>
        </form>
        <div v-else-if="selected.latest_decision" class="decision"><strong>{{ t('seco.decisionOn', { date: selected.latest_decision.decision_on }) }}</strong><p>{{ streamCode(selected.latest_decision.stream_id) }} · {{ selected.latest_decision.reason }}</p></div>

        <form v-if="selected.latest_decision && (canManage || canSubmitOwn || canSubmitAsGuardian)" class="stack section-form" @submit.prevent="fileAppeal">
          <h3>{{ t('seco.appealTitle') }}</h3>
          <label class="field">{{ t('seco.appealReason') }} <textarea v-model="appealReason" maxlength="255" rows="3" required /></label>
          <button class="btn" type="submit" :disabled="busy">{{ t('seco.fileAppeal') }}</button>
        </form>
      </section>
      <section v-else class="card empty"><p class="muted">{{ t('seco.selectStudent') }}</p></section>
    </div>

    <section class="card appeals" aria-labelledby="orientation-appeals">
      <h2 id="orientation-appeals">{{ t('seco.appeals') }} · {{ appeals.length }}</h2>
      <p v-if="appeals.length === 0" class="muted">{{ t('seco.noAppeal') }}</p>
      <ul v-else class="rows">
        <li v-for="item in appeals" :key="item.id" class="appeal-row">
          <div><strong>{{ cases.find((candidate) => candidate.person_id === item.person_id)?.student_name ?? t('seco.studentFallback') }}</strong><span class="tag" :class="{ 'tag--warning': item.status === 'pending' }">{{ appealStatus(item.status) }}</span><p>{{ item.reason }}</p><small class="muted">{{ t('seco.submittedOn', { date: item.submitted_on }) }}</small></div>
          <form v-if="canDecide && item.status === 'pending'" class="stack review" @submit.prevent="reviewAppeal(item)">
            <label class="field">{{ t('seco.outcome') }} <select v-model="appealOutcome"><option value="rejected">{{ t('seco.reject') }}</option><option value="accepted">{{ t('seco.accept') }}</option></select></label>
            <label v-if="appealOutcome === 'accepted'" class="field">{{ t('seco.revised') }} <select v-model="revisedStream" required><option value="" disabled>{{ t('seco.choose') }}</option><option v-for="stream in activeStreams" :key="stream.id" :value="stream.id">{{ stream.code }} · {{ stream.label }}</option></select></label>
            <label class="field">{{ t('seco.outcomeReason') }} <textarea v-model="appealOutcomeReason" rows="2" maxlength="255" required /></label>
            <button class="btn btn--primary" type="submit" :disabled="busy">{{ t('seco.review') }}</button>
          </form>
          <p v-else-if="item.outcome_reason" class="muted">{{ item.outcome_reason }}</p>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.filters, .columns { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.columns { align-items: start; margin-top: 16px; }
.field { display: grid; gap: 6px; }
.field select, .field textarea { width: 100%; }
.wishes { border: 0; padding: 0; }
.wish-option { display: flex; align-items: center; gap: 8px; }
.rows { display: grid; list-style: none; gap: 10px; padding: 0; margin: 0; }
.student { display: grid; width: 100%; gap: 4px; padding: 12px; text-align: left; border: 1px solid var(--border, #d8dee8); border-radius: 12px; background: var(--surface, #fff); color: inherit; }
.student[aria-pressed='true'] { border-color: var(--primary, #3568d4); box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary, #3568d4) 18%, transparent); }
.section-form { border-top: 1px solid var(--border, #e1e5eb); padding-top: 16px; margin-top: 18px; }
.section-form h3 { margin: 0; }
.chips { display: flex; flex-wrap: wrap; gap: 8px; list-style: none; padding: 0; }
.chips li { padding: 5px 10px; border-radius: 999px; background: var(--surface-muted, #f1f4f8); }
.decision { padding: 12px; border-radius: 12px; background: var(--surface-muted, #f1f4f8); margin-top: 16px; }
.appeals { margin-top: 16px; }
.appeal-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(220px, .7fr); gap: 20px; padding: 12px 0; border-top: 1px solid var(--border, #e1e5eb); }
.tag { display: inline-flex; margin-left: 8px; padding: 3px 8px; border-radius: 999px; background: var(--surface-muted, #f1f4f8); }
.tag--warning { background: #fff1d2; color: #7a4c00; }
.empty { min-height: 120px; display: grid; place-items: center; }
@media (max-width: 760px) { .filters, .columns, .appeal-row { grid-template-columns: minmax(0, 1fr); } }
</style>