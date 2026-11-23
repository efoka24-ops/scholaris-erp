<script setup lang="ts">
// Conseil de classe du secondaire (lot E3-A, T900 à T904) : règles du niveau préparées depuis les coefficients de Setup puis publiées,
// types de bulletin, conseil du socle (ouverture, propositions pré-remplies par le secondaire, décisions motivées, validation) et
// émission des bulletins. Le serveur reste juge des droits et des règles (notes verrouillées, motif obligatoire).
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { listPeriods, type AcademicYear, type Period } from '@/api/admin';
import { secondarySetup, type SchoolClass } from '@/api/secondarySetup';
import {
  lineExtras, secondaryCouncils,
  type ReportCardRequest, type ReportCardSettings, type RuleDraft, type SecondaryCouncil, type SecondaryCouncilLine,
} from '@/api/secondaryCouncils';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction, usePermissions } from '../useSec';
import { periodName } from '../periodName';
import { publicationSummary } from './publication';

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();

const years = ref<AcademicYear[]>([]);
const classes = ref<SchoolClass[]>([]);
const periods = ref<Period[]>([]);
const yearId = ref('');
const classId = ref('');
const periodId = ref('');
const scope = ref<'level' | 'class'>('level');
const draft = ref<RuleDraft | null>(null);
const published = ref(false);
const settings = ref<ReportCardSettings | null>(null);
const council = ref<SecondaryCouncil | null>(null);
const cards = ref<ReportCardRequest[]>([]);
const publicationText = ref('');
const edits = ref<Record<string, { decision: string; reason: string }>>({});

const canPrepare = computed(() => can('secondary.council.prepare', 'secondary.council.preside'));
const canPreset = computed(() => can('secondary.settings.manage', 'secondary.council.preside'));
const canPublish = computed(() => can('secondary.reportcard.validate', 'secondary.council.preside', 'secondary.council.prepare'));
const hasIssued = computed(() => cards.value.some((c) => c.status === 'issued'));
const councilPeriods = computed(() => periods.value.filter((p) => (p.kind as string) !== 'sequence'));
const periodLabel = computed(() => {
  const period = periods.value.find((p) => p.id === periodId.value);
  return period ? periodName(period, t) : '';
});
const isOpen = computed(() => council.value?.status === 'open');

async function load(): Promise<void> {
  years.value = (await run(() => secondarySetup.years())) ?? [];
  yearId.value = years.value.find((y) => y.status === 'open')?.id ?? years.value[0]?.id ?? '';
  settings.value = (await run(() => secondaryCouncils.settings())) ?? null;
}
async function loadYear(): Promise<void> {
  if (yearId.value === '') return;
  classes.value = (await run(() => secondarySetup.classes(yearId.value))) ?? [];
  periods.value = (await run(() => listPeriods(yearId.value))) ?? [];
  classId.value = classes.value[0]?.structure_id ?? '';
  periodId.value = councilPeriods.value[0]?.id ?? '';
}
async function loadCouncil(): Promise<void> {
  draft.value = null;
  published.value = false;
  council.value = null;
  cards.value = [];
  if (classId.value === '' || periodId.value === '') return;
  const found = (await run(() => secondaryCouncils.list(classId.value, periodId.value))) ?? [];
  if (found[0]) await show(found[0].id);
}
async function show(id: string): Promise<void> {
  const loaded = await run(() => secondaryCouncils.find(id));
  if (!loaded) return;
  council.value = loaded;
  publicationText.value = '';
  edits.value = Object.fromEntries(loaded.lines.map((l) => [l.id, { decision: l.decision ?? '', reason: l.reason ?? '' }]));
  cards.value = loaded.status === 'validated' ? ((await run(() => secondaryCouncils.reportCards(id))) ?? []) : [];
}

async function prepareDraft(): Promise<void> {
  published.value = false;
  draft.value = (await run(() => secondaryCouncils.ruleDraft(classId.value, scope.value))) ?? null;
}
async function publish(): Promise<void> {
  const d = draft.value;
  if (!d) return;
  // C-118 : version du niveau (s'il a des coefficients) puis une version par classe d'une série à coefficients propres.
  const versions = [
    ...(d.structure_id !== null ? [{ academic_year_id: d.academic_year_id, label: d.label, definition: d.definition, structure_id: d.structure_id }] : []),
    ...(d.stream_overrides ?? []).map((o) => ({ academic_year_id: d.academic_year_id, label: o.label, definition: o.definition, structure_id: o.structure_id })),
  ];
  let all = versions.length > 0;
  for (const version of versions) {
    if ((await run(() => secondaryCouncils.publishRules(version))) === undefined) {
      all = false;
      break;
    }
  }
  published.value = all;
}
async function preset(): Promise<void> {
  settings.value = (await run(() => secondaryCouncils.presetSettings())) ?? settings.value;
}
async function open(): Promise<void> {
  const opened = await run(() => secondaryCouncils.open({ structure_id: classId.value, period_id: periodId.value, period_label: periodLabel.value }));
  if (opened) await show(opened.id);
}
async function act(action: 'refresh' | 'validate'): Promise<void> {
  if (!council.value) return;
  const id = council.value.id;
  const done = await run(() => secondaryCouncils[action](id));
  if (done) await show(id);
}
async function generate(): Promise<void> {
  if (!council.value) return;
  const id = council.value.id;
  cards.value = (await run(() => secondaryCouncils.generateReportCards(id))) ?? cards.value;
}
async function publishCards(): Promise<void> {
  if (!council.value) return;
  const id = council.value.id;
  const done = await run(() => secondaryCouncils.publishReportCards(id));
  if (done) publicationText.value = publicationSummary(t, done);
}
async function saveLine(line: SecondaryCouncilLine): Promise<void> {
  if (!council.value) return;
  const edit = editOf(line.id);
  const id = council.value.id;
  const saved = await run(() => secondaryCouncils.updateLine(id, line.id, { decision: edit.decision || null, reason: edit.reason.trim() || null }));
  if (saved) await show(id);
}

function editOf(lineId: string): { decision: string; reason: string } {
  edits.value[lineId] ??= { decision: '', reason: '' };
  return edits.value[lineId];
}
function nameOf(personId: string): string {
  return council.value?.lines.find((l) => l.person_id === personId)?.name ?? personId;
}
function chips(line: SecondaryCouncilLine): string[] {
  const x = lineExtras(line);
  const out: string[] = [];
  if (x.work_proposal) out.push(t('secc.work', { label: x.work_proposal.label }));
  if (x.distinction_refused && x.refused_proposal) out.push(t('secc.distinctionRefused', { label: x.refused_proposal.label }));
  if (x.promotion) out.push(t('secc.promotion', { label: x.promotion.label }) + (x.promotion.conditional_possible ? ` (${t('secc.conditional')})` : ''));
  if (x.absence_hours) out.push(t('secc.hours', { justified: String(x.absence_hours.justified), unjustified: String(x.absence_hours.unjustified) }));
  if (x.conduct_sanctions?.length) out.push(t('secc.conduct', { labels: x.conduct_sanctions.map((s) => s.label).join(', ') }));
  if (x.subject_alerts?.length) out.push(t('secc.alerts', { count: String(x.subject_alerts.length) }));
  if (x.practical_alerts?.length) out.push(t('secc.practicalAlerts'));
  if (x.not_evaluated?.length) out.push(t('secc.notEvaluated', { count: String(x.not_evaluated.length) }));
  if (x.workshop_practical) out.push(t('secc.workshopMarks', { points: String(x.workshop_practical.points), outOf: String(x.workshop_practical.out_of), count: String(x.workshop_practical.evaluations) }));
  return out;
}
/** Libellé traduit d'une valeur technique (état, type de bulletin, rôle de matière) ; la valeur brute seulement si aucune traduction n'existe. */
function labelOf(key: string, raw: string): string {
  const out = t(key as MessageKey);
  return out === key ? raw : out;
}
const councilStatus = (status: string): string => labelOf(`secc.councilStatus.${status}`, status);
const reportType = (code: string): string => labelOf(`secc.reportType.${code}`, code);
const subjectRole = (role: string): string => labelOf(`secs.roles.${role}`, role);
function cardStatus(status: string): string {
  return t(`secc.cardStatus.${status}` as MessageKey);
}

watch(yearId, loadYear);
watch([classId, periodId], loadCouncil);
onMounted(load);
</script>

<template>
  <div class="page sec-councils">
    <header class="page-head">
      <h1>{{ t('secc.title') }}</h1>
      <p class="lede">{{ t('secc.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card filters">
      <label class="field">{{ t('secc.year') }}
        <select v-model="yearId" :disabled="busy"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option></select>
      </label>
      <label class="field">{{ t('secc.class') }}
        <select v-model="classId" :disabled="busy"><option value="" disabled>{{ t('secc.choose') }}</option><option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option></select>
      </label>
      <label class="field">{{ t('secc.period') }}
        <select v-model="periodId" :disabled="busy"><option value="" disabled>{{ t('secc.choose') }}</option><option v-for="p in councilPeriods" :key="p.id" :value="p.id">{{ periodName(p, t) }}</option></select>
      </label>
    </section>

    <section v-if="canPrepare && classId" class="card">
      <h2>{{ t('secc.rulesTitle') }}</h2>
      <p class="muted">{{ t('secc.rulesIntro') }}</p>
      <div class="row">
        <label><input v-model="scope" type="radio" value="level" /> {{ t('secc.scopeLevel') }}</label>
        <label><input v-model="scope" type="radio" value="class" /> {{ t('secc.scopeClass') }}</label>
        <button class="btn" type="button" :disabled="busy" @click="prepareDraft">{{ t('secc.prepareDraft') }}</button>
      </div>
      <template v-if="draft">
        <p v-if="draft.from_example" class="notice">{{ t('secc.fromExample', { notice: draft.example_notice ?? '' }) }}</p>
        <div v-if="draft.coefficients.length > 0" class="table-wrap">
          <table class="table">
            <thead><tr><th>{{ t('secc.subject') }}</th><th>{{ t('secc.coefficient') }}</th><th>{{ t('secc.role') }}</th></tr></thead>
            <tbody>
              <tr v-for="line in draft.coefficients" :key="line.subject_id">
                <td>{{ line.label }}</td><td>{{ line.coefficient }}</td><td>{{ subjectRole(line.role) }}<span v-if="!line.counts_in_average" class="muted"> · {{ t('secc.outOfAverage') }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="muted">{{ t('secc.levelNoCoefficient') }}</p>
        <template v-for="o in draft.stream_overrides ?? []" :key="o.structure_id">
          <h3>{{ t('secc.streamOverride', { class: o.class_label }) }}</h3>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>{{ t('secc.subject') }}</th><th>{{ t('secc.coefficient') }}</th><th>{{ t('secc.role') }}</th></tr></thead>
              <tbody>
                <tr v-for="line in o.coefficients" :key="line.subject_id">
                  <td>{{ line.label }}</td><td>{{ line.coefficient }}</td><td>{{ subjectRole(line.role) }}<span v-if="!line.counts_in_average" class="muted"> · {{ t('secc.outOfAverage') }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        <button class="btn btn--primary" type="button" :disabled="busy" @click="publish">{{ t('secc.publishRules') }}</button>
        <p v-if="published" class="ok">{{ t('secc.rulesPublished') }}</p>
      </template>
    </section>

    <section class="card">
      <h2>{{ t('secc.settingsTitle') }}</h2>
      <p v-if="settings?.report_card_types">{{ t('secc.typesSet', { types: Object.values(settings.report_card_types).map(reportType).join(', ') }) }}</p>
      <p v-else class="muted">{{ t('secc.typesMissing') }}</p>
      <button v-if="canPreset && !settings?.report_card_types" class="btn" type="button" :disabled="busy" @click="preset">{{ t('secc.preset') }}</button>
      <p class="muted small">{{ t('secc.letterheadHint') }}</p>
    </section>

    <section v-if="classId && periodId" class="card">
      <template v-if="!council">
        <p class="muted">{{ t('secc.noCouncil') }}</p>
        <button v-if="canPrepare" class="btn btn--primary" type="button" :disabled="busy" @click="open">{{ t('secc.open') }}</button>
      </template>
      <template v-else>
        <div class="row">
          <strong>{{ periodLabel || council.period_label }}</strong>
          <span class="tag">{{ t('secc.status', { status: councilStatus(council.status) }) }}</span>
          <template v-if="isOpen && canPrepare">
            <button class="btn" type="button" :disabled="busy" @click="act('refresh')">{{ t('secc.refresh') }}</button>
            <button class="btn btn--primary" type="button" :disabled="busy" :title="t('secc.validateHint')" @click="act('validate')">{{ t('secc.validate') }}</button>
          </template>
          <button v-if="!isOpen && canPrepare" class="btn btn--primary" type="button" :disabled="busy" @click="generate">{{ t('secc.generate') }}</button>
          <button v-if="!isOpen && canPublish && hasIssued" class="btn" type="button" data-test="publish-cards" :disabled="busy" :title="t('secc.publishFamiliesHint')" @click="publishCards">{{ t('secc.publishFamilies') }}</button>
        </div>
        <p v-if="isOpen" class="muted small">{{ t('secc.validateHint') }}</p>
        <p v-if="!isOpen && canPublish && hasIssued && !publicationText" class="muted small">{{ t('secc.publishFamiliesHint') }}</p>
        <p v-if="publicationText" class="ok" data-test="publish-summary">{{ publicationText }}</p>
        <ul class="lines">
          <li v-for="line in council.lines" :key="line.id" class="line">
            <div class="who">
              <strong>{{ line.rank_in_class ?? '-' }}. {{ line.name ?? line.person_id }}</strong>
              <span>{{ t('secc.average') }} : {{ line.average ?? '—' }}</span>
              <span v-if="line.conduct" class="muted">{{ line.conduct }}</span>
            </div>
            <ul class="chips"><li v-for="chip in chips(line)" :key="chip">{{ chip }}</li></ul>
            <form v-if="isOpen && canPrepare" class="decide" @submit.prevent="saveLine(line)">
              <label class="field">{{ t('secc.decision') }} <input v-model="editOf(line.id).decision" maxlength="160" /></label>
              <label class="field">{{ t('secc.reason') }} <input v-model="editOf(line.id).reason" maxlength="255" /></label>
              <button class="btn" type="submit" :disabled="busy">{{ t('secc.save') }}</button>
            </form>
            <p v-else class="muted">{{ t('secc.decision') }} : {{ line.decision ?? '—' }}<template v-if="line.reason"> · {{ line.reason }}</template></p>
            <RouterLink class="link" :to="{ name: 'sec-report-cards', query: { council: council.id, person: line.person_id } }">{{ t('secc.preview') }}</RouterLink>
          </li>
        </ul>
        <template v-if="cards.length">
          <h3>{{ t('secc.reportCards') }}</h3>
          <ul class="lines">
            <li v-for="card in cards" :key="card.id" class="row">
              <span>{{ nameOf(card.person_id) }}</span><span class="tag">{{ cardStatus(card.status) }}</span><span v-if="card.error_message" class="muted">{{ card.error_message }}</span>
            </li>
          </ul>
        </template>
      </template>
    </section>
  </div>
</template>

<style scoped>
.filters { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.card { margin-top: 16px; }
.field { display: grid; gap: 6px; }
.row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.table-wrap { overflow-x: auto; margin: 12px 0; }
.lines { list-style: none; padding: 0; margin: 12px 0 0; display: grid; gap: 12px; }
.line { border-top: 1px solid var(--border, #e1e5eb); padding-top: 12px; display: grid; gap: 8px; }
.who { display: flex; flex-wrap: wrap; gap: 12px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; list-style: none; padding: 0; margin: 0; }
.chips li, .tag { padding: 3px 9px; border-radius: 999px; background: var(--surface-muted, #f1f4f8); font-size: .9em; }
.decide { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; align-items: end; }
.notice { background: #fff1d2; color: #7a4c00; padding: 8px 12px; border-radius: 8px; }
.ok { color: #1d6b38; }
.small { font-size: .9em; }
@media (max-width: 760px) { .filters, .decide { grid-template-columns: minmax(0, 1fr); } }
</style>
