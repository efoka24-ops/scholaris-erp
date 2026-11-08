<script setup lang="ts">
// Pilotage du secondaire (T970, T971) : tableaux de bord du chef d'établissement, du censeur et de l'intendant (objectifs O1 à O7),
// vue réseau agrégée et masquée, registre des réclamations (O1) et rapports téléchargés en fichier joint. Chaque rubrique n'apparaît
// qu'avec ses droits ; le serveur reste juge (périmètre de classes, masquage des petites cases, aucune donnée nominative).
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n, locale, type MessageKey } from '@/i18n';
import {
  CLAIM_OUTCOMES,
  REPORTS,
  formatObjective,
  secondaryDashboards,
  type ClaimOutcome,
  type DashboardPeriod,
  type DashboardQuery,
  type FinanceDashboard,
  type HeadDashboard,
  type NetworkDashboard,
  type PedagogyDashboard,
  type ReportName,
  type ResultClaim,
} from '@/api/secondaryDashboards';
import type { ExportFormat } from '@/api/exports';
import { S } from '../access';
import { useAction, usePermissions } from '../useSec';
import ObjectiveList from './ObjectiveList.vue';

type Tab = 'head' | 'pedagogy' | 'finance' | 'network' | 'claims' | 'reports';

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();

const tabs = computed<Tab[]>(() => {
  const out: Tab[] = [];
  if (can(...S.dashboardHead) && can(...S.dashboardWhole)) out.push('head');
  if (can(...S.dashboardHead)) out.push('pedagogy');
  if (can(...S.dashboardFinance)) out.push('finance');
  if (can(...S.dashboardNetwork)) out.push('network');
  if (can(...S.dashboardClaims)) out.push('claims');
  if (can(...S.dashboardHead) || can(...S.dashboardNetwork)) out.push('reports');
  return out;
});
const tab = ref<Tab>('head');
const query = reactive<{ year: string; term: string; previous: string }>({ year: '', term: '', previous: '' });
const period = ref<DashboardPeriod | null>(null);
const head = ref<HeadDashboard | null>(null);
const pedagogy = ref<PedagogyDashboard | null>(null);
const finance = ref<FinanceDashboard | null>(null);
const network = ref<NetworkDashboard | null>(null);
const claims = ref<ResultClaim[]>([]);
const notice = ref('');
const claimForm = reactive({ classId: '', personId: '', subject: '', text: '', received: '' });
const closing = reactive<Record<string, { outcome: ClaimOutcome; note: string }>>({});
const reports = computed<ReportName[]>(() => REPORTS.filter((r) => (r === 'network' ? can(...S.dashboardNetwork) : can(...S.dashboardHead))));
const canWriteClaims = computed(() => can(...S.dashboardClaimsWrite));

const params = (): DashboardQuery => ({ academic_year_id: query.year, period_id: query.term });
const number = (value: number | null | undefined): string => (value === null || value === undefined ? '—' : new Intl.NumberFormat(locale.value).format(value));
const percent = (value: number | null | undefined): string => formatObjective(value ?? null, 'percent', locale.value);
const termLabel = (id: string): string => {
  const term = period.value?.terms.find((p) => p.id === id);
  return term ? t('secdb.termLabel', { n: term.ordinal, from: term.starts_on, to: term.ends_on }) : id;
};

async function load(): Promise<void> {
  notice.value = '';
  const q = params();
  if (tab.value === 'head') head.value = (await run(() => secondaryDashboards.head(q))) ?? null;
  if (tab.value === 'pedagogy') pedagogy.value = (await run(() => secondaryDashboards.pedagogy(q))) ?? null;
  if (tab.value === 'finance') finance.value = (await run(() => secondaryDashboards.finance(q))) ?? null;
  if (tab.value === 'network') network.value = (await run(() => secondaryDashboards.network({ academic_year_id: query.year }))) ?? null;
  if (tab.value === 'claims') claims.value = (await run(() => secondaryDashboards.claims(query.year || period.value?.academic_year_id))) ?? [];
  period.value = head.value?.period ?? pedagogy.value?.period ?? finance.value?.period ?? period.value;
}

async function recordClaim(): Promise<void> {
  const year = query.year || period.value?.academic_year_id || '';
  const saved = await run(() => secondaryDashboards.recordClaim({
    academic_year_id: year,
    class_id: claimForm.classId.trim(),
    person_id: claimForm.personId.trim(),
    claim_text: claimForm.text.trim(),
    period_id: query.term || period.value?.term?.id || null,
    subject_label: claimForm.subject.trim() || null,
    ...(claimForm.received ? { received_on: claimForm.received } : {}),
  }));
  if (saved) {
    notice.value = t('secdb.claims.recorded');
    Object.assign(claimForm, { classId: '', personId: '', subject: '', text: '', received: '' });
    await load();
  }
}

function closer(id: string): { outcome: ClaimOutcome; note: string } {
  closing[id] ??= { outcome: 'unfounded', note: '' };
  return closing[id];
}

async function closeClaim(id: string): Promise<void> {
  const form = closer(id);
  if (form.note.trim() === '') return;
  const saved = await run(() => secondaryDashboards.closeClaim(id, form.outcome, form.note.trim()));
  if (saved) {
    notice.value = t('secdb.claims.closedOk');
    await load();
  }
}

async function download(report: ReportName, format: ExportFormat): Promise<void> {
  const done = await run(async () => {
    await secondaryDashboards.download(report, format, { ...params(), previous_year_id: query.previous });
    return true;
  });
  if (done) notice.value = t('secdb.downloaded');
}

watch(tab, load);
onMounted(async () => {
  tab.value = tabs.value[0] ?? 'head';
  if (tabs.value.length > 0) await load();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secdb.title') }}</h1>
      <p class="lede">{{ t('secdb.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>

    <nav class="tabs" :aria-label="t('secdb.tabs')">
      <button v-for="item in tabs" :key="item" type="button" class="tab" :aria-pressed="tab === item" @click="tab = item">{{ t(`secdb.tab.${item}` as MessageKey) }}</button>
    </nav>

    <form v-if="tab !== 'reports'" class="card stack" :aria-label="t('secdb.filters')" @submit.prevent="load">
      <div class="field">
        <label for="sdb-year">{{ t('secdb.year') }}</label>
        <input id="sdb-year" v-model="query.year" type="text" maxlength="40" autocomplete="off" :placeholder="period?.academic_year_id ?? ''" />
        <p class="hint">{{ t('secdb.yearHint') }}</p>
      </div>
      <div v-if="tab !== 'network' && tab !== 'claims'" class="field">
        <label for="sdb-term">{{ t('secdb.term') }}</label>
        <select id="sdb-term" v-model="query.term">
          <option value="">{{ t('secdb.termCurrent') }}</option>
          <option v-for="term in period?.terms ?? []" :key="term.id" :value="term.id">{{ termLabel(term.id) }}</option>
        </select>
      </div>
      <div><button type="submit" class="btn" :disabled="busy">{{ busy ? t('secdb.loading') : t('secdb.refresh') }}</button></div>
    </form>

    <template v-if="tab === 'head' && head">
      <section class="card" aria-labelledby="sdb-objectives">
        <h2 id="sdb-objectives">{{ t('secdb.objectives') }}</h2>
        <p class="muted">{{ t('secdb.toConfirm') }}</p>
        <p v-for="alert in head.alerts" :key="alert.code" class="notice" role="status">
          {{ alert.status === 'overdue' ? t('secdb.alertYearEndLate', { date: alert.deadline }) : t('secdb.alertYearEnd', { date: alert.deadline, days: alert.days_left }) }}
        </p>
        <ObjectiveList :objectives="head.objectives" />
        <p v-if="head.restricted.length > 0" class="muted">{{ t('secdb.restricted') }}</p>
      </section>
      <section class="card" aria-labelledby="sdb-figures">
        <h2 id="sdb-figures">{{ t('secdb.figures') }}</h2>
        <dl class="figures">
          <div><dt>{{ t('secdb.classes') }}</dt><dd>{{ number(head.figures.classes) }}</dd></div>
          <div><dt>{{ t('secdb.students') }}</dt><dd>{{ number(head.figures.students) }}</dd></div>
          <div><dt>{{ t('secdb.female') }}</dt><dd>{{ number(head.figures.female) }}</dd></div>
          <div><dt>{{ t('secdb.male') }}</dt><dd>{{ number(head.figures.male) }}</dd></div>
          <div><dt>{{ t('secdb.genderUnknown') }}</dt><dd>{{ number(head.figures.gender_unknown) }}</dd></div>
          <div><dt>{{ t('secdb.staff') }}</dt><dd>{{ number(head.figures.staff.total) }}</dd></div>
          <div><dt>{{ t('secdb.teaching') }}</dt><dd>{{ number(head.figures.staff.teaching) }}</dd></div>
        </dl>
        <h3>{{ t('secdb.exams') }}</h3>
        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ t('secdb.exam') }}</th><th>{{ t('secdb.candidates') }}</th><th>{{ t('secdb.results') }}</th><th>{{ t('secdb.passed') }}</th><th>{{ t('secdb.passRate') }}</th></tr></thead>
            <tbody>
              <tr v-for="exam in head.figures.exams" :key="exam.session_id">
                <td>{{ exam.exam_label }}</td><td>{{ number(exam.candidates) }}</td><td>{{ number(exam.results) }}</td><td>{{ number(exam.passed) }}</td><td>{{ percent(exam.pass_rate) }}</td>
              </tr>
              <tr v-if="head.figures.exams.length === 0"><td colspan="5">{{ t('secdb.noExam') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <section v-if="tab === 'pedagogy' && pedagogy" class="card" aria-labelledby="sdb-pedagogy">
      <h2 id="sdb-pedagogy">{{ t('secdb.tab.pedagogy') }}</h2>
      <p v-if="pedagogy.scope === 'classes'" class="muted">{{ t('secdb.scopeClasses') }}</p>
      <ObjectiveList :objectives="pedagogy.objectives" />
      <div class="table-wrap">
        <table>
          <thead><tr><th>{{ t('secdb.class') }}</th><th>{{ t('secdb.students') }}</th><th>{{ t('secdb.reportCards') }}</th><th>{{ t('secdb.coverage') }}</th><th>{{ t('secdb.absence') }}</th></tr></thead>
          <tbody>
            <tr v-for="row in pedagogy.classes" :key="row.class_id">
              <td>{{ row.label }}</td>
              <td>{{ number(row.students) }}</td>
              <td>{{ row.report_cards_issued_on ? `${row.report_cards_issued_on} (${row.report_cards_on_time ? t('secdb.onTime') : t('secdb.late')})` : t('secdb.notIssued') }}</td>
              <td>{{ percent(row.coverage_percent) }}</td>
              <td>{{ formatObjective(row.unjustified_hours_per_student ?? null, 'hours_per_student', locale) }}</td>
            </tr>
            <tr v-if="pedagogy.classes.length === 0"><td colspan="5">{{ t('secdb.noClass') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="tab === 'finance' && finance" class="card" aria-labelledby="sdb-finance">
      <h2 id="sdb-finance">{{ t('secdb.tab.finance') }}</h2>
      <p class="muted">{{ t(`secdb.feesMode.${finance.fees_mode}` as MessageKey) }}</p>
      <ObjectiveList :objectives="finance.objectives" />
      <dl class="figures">
        <div><dt>{{ t('secdb.net') }}</dt><dd>{{ number(finance.totals.net_xaf) }} XAF</dd></div>
        <div><dt>{{ t('secdb.paid') }}</dt><dd>{{ number(finance.totals.paid_xaf) }} XAF</dd></div>
        <div><dt>{{ t('secdb.balance') }}</dt><dd>{{ number(finance.totals.balance_xaf) }} XAF</dd></div>
        <div><dt>{{ t('secdb.overdue') }}</dt><dd>{{ number(finance.totals.overdue_xaf) }} XAF</dd></div>
        <div><dt>{{ t('secdb.recovery') }}</dt><dd>{{ percent(finance.totals.recovery_percent) }}</dd></div>
      </dl>
      <div class="table-wrap">
        <table>
          <thead><tr><th>{{ t('secdb.class') }}</th><th>{{ t('secdb.students') }}</th><th>{{ t('secdb.net') }}</th><th>{{ t('secdb.paid') }}</th><th>{{ t('secdb.balance') }}</th><th>{{ t('secdb.recovery') }}</th></tr></thead>
          <tbody>
            <tr v-for="row in finance.classes" :key="row.class_id">
              <td>{{ row.label }}</td><td>{{ number(row.students) }}</td><td>{{ number(row.net_xaf) }}</td><td>{{ number(row.paid_xaf) }}</td><td>{{ number(row.balance_xaf) }}</td><td>{{ percent(row.recovery_percent) }}</td>
            </tr>
            <tr v-if="finance.classes.length === 0"><td colspan="6">{{ t('secdb.noClass') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="tab === 'network' && network" class="card" aria-labelledby="sdb-network">
      <h2 id="sdb-network">{{ t('secdb.tab.network') }}</h2>
      <p class="muted">{{ t('secdb.network.intro', { min: network.minimum_cell_size }) }}</p>
      <div class="table-wrap">
        <table>
          <thead><tr><th>{{ t('secdb.network.establishment') }}</th><th>{{ t('secdb.students') }}</th><th>{{ t('secdb.female') }}</th><th>{{ t('secdb.male') }}</th><th>{{ t('secdb.passRate') }}</th><th>{{ t('secdb.network.recovery') }}</th></tr></thead>
          <tbody>
            <tr v-for="row in network.establishments" :key="row.establishment">
              <td>{{ row.establishment }}</td>
              <td>{{ row.masked.includes('students') ? t('secdb.network.masked') : number(row.students) }}</td>
              <td>{{ row.masked.includes('female') ? t('secdb.network.masked') : number(row.female) }}</td>
              <td>{{ row.masked.includes('male') ? t('secdb.network.masked') : number(row.male) }}</td>
              <td>{{ row.masked.includes('exam_pass_rate') ? t('secdb.network.masked') : percent(row.exam_pass_rate) }}</td>
              <td>{{ row.masked.includes('recovery_rate') ? t('secdb.network.masked') : percent(row.recovery_rate) }}</td>
            </tr>
            <tr v-if="network.establishments.length === 0"><td colspan="6">{{ t('secdb.network.empty') }}</td></tr>
          </tbody>
          <tfoot v-if="network.establishments.length > 0">
            <tr>
              <th scope="row">{{ t('secdb.network.total') }}</th>
              <td>{{ number(network.consolidated.students) }}</td><td>{{ number(network.consolidated.female) }}</td><td>{{ number(network.consolidated.male) }}</td>
              <td>{{ percent(network.consolidated.exam_pass_rate) }}</td><td>{{ percent(network.consolidated.recovery_rate) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <section v-if="tab === 'claims'" class="card" aria-labelledby="sdb-claims">
      <h2 id="sdb-claims">{{ t('secdb.tab.claims') }}</h2>
      <p class="muted">{{ t('secdb.claims.intro') }}</p>
      <form v-if="canWriteClaims" class="stack" @submit.prevent="recordClaim">
        <h3>{{ t('secdb.claims.new') }}</h3>
        <div class="field"><label for="sdb-c-class">{{ t('secdb.claims.class') }}</label><input id="sdb-c-class" v-model="claimForm.classId" type="text" maxlength="40" required /></div>
        <div class="field"><label for="sdb-c-person">{{ t('secdb.claims.person') }}</label><input id="sdb-c-person" v-model="claimForm.personId" type="text" maxlength="40" required /></div>
        <div class="field"><label for="sdb-c-subject">{{ t('secdb.claims.subject') }}</label><input id="sdb-c-subject" v-model="claimForm.subject" type="text" maxlength="160" /></div>
        <div class="field"><label for="sdb-c-text">{{ t('secdb.claims.text') }}</label><textarea id="sdb-c-text" v-model="claimForm.text" maxlength="500" rows="3" required></textarea></div>
        <div class="field"><label for="sdb-c-received">{{ t('secdb.claims.received') }}</label><input id="sdb-c-received" v-model="claimForm.received" type="date" /></div>
        <div><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secdb.claims.record') }}</button></div>
      </form>
      <ul class="claims">
        <li v-for="claim in claims" :key="claim.id" class="claim">
          <p><strong>{{ claim.received_on }}</strong> · {{ claim.subject_label || '—' }} · {{ claim.status === 'open' ? t('secdb.claims.open') : t('secdb.claims.closed') }}</p>
          <p>{{ claim.claim_text }}</p>
          <p v-if="claim.outcome">{{ t(`secdb.outcome.${claim.outcome}` as MessageKey) }} : {{ claim.outcome_note }}</p>
          <form v-if="claim.status === 'open' && canWriteClaims" class="stack" @submit.prevent="closeClaim(claim.id)">
            <div class="field">
              <label :for="`sdb-o-${claim.id}`">{{ t('secdb.claims.outcome') }}</label>
              <select :id="`sdb-o-${claim.id}`" v-model="closer(claim.id).outcome">
                <option v-for="o in CLAIM_OUTCOMES" :key="o" :value="o">{{ t(`secdb.outcome.${o}` as MessageKey) }}</option>
              </select>
            </div>
            <div class="field"><label :for="`sdb-n-${claim.id}`">{{ t('secdb.claims.note') }}</label><input :id="`sdb-n-${claim.id}`" v-model="closer(claim.id).note" type="text" maxlength="500" required /></div>
            <div><button type="submit" class="btn" :disabled="busy">{{ t('secdb.claims.close') }}</button></div>
          </form>
        </li>
        <li v-if="claims.length === 0" class="muted">{{ t('secdb.claims.empty') }}</li>
      </ul>
    </section>

    <section v-if="tab === 'reports'" class="card stack" aria-labelledby="sdb-reports">
      <h2 id="sdb-reports">{{ t('secdb.tab.reports') }}</h2>
      <p class="muted">{{ t('secdb.reports.intro') }}</p>
      <div class="field"><label for="sdb-r-year">{{ t('secdb.year') }}</label><input id="sdb-r-year" v-model="query.year" type="text" maxlength="40" autocomplete="off" /></div>
      <div class="field"><label for="sdb-r-previous">{{ t('secdb.reports.previous') }}</label><input id="sdb-r-previous" v-model="query.previous" type="text" maxlength="40" autocomplete="off" /></div>
      <ul class="reports">
        <li v-for="report in reports" :key="report">
          <span>{{ t(`secdb.report.${report}` as MessageKey) }}</span>
          <span class="actions">
            <button type="button" class="btn" :disabled="busy" @click="download(report, 'csv')">{{ t('secdb.reports.csv') }}</button>
            <button type="button" class="btn" :disabled="busy" @click="download(report, 'xlsx')">{{ t('secdb.reports.xlsx') }}</button>
          </span>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.figures { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin: 0 0 12px; }
@media (min-width: 640px) { .figures { grid-template-columns: repeat(4, 1fr); } }
.figures div { border: 1px solid var(--border, #d9dee5); border-radius: 8px; padding: 8px; }
.figures dt { font-size: .9em; color: var(--text-muted, #5b6573); }
.figures dd { margin: 0; font-weight: 700; font-size: 1.2rem; }
.claims, .reports { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.claim { border: 1px solid var(--border, #d9dee5); border-radius: 8px; padding: 10px; }
.claim p { margin: 2px 0; overflow-wrap: anywhere; }
.reports li { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; align-items: center; }
.actions { display: flex; gap: 8px; }
.muted { color: var(--text-muted, #5b6573); }
</style>
