<script setup lang="ts">
// Tableau de bord du directeur : par classe, effectif, absents du jour (matin et après-midi), paiements en retard et suivi de saisie
// des feuilles de compétences ; puis les contrôles à faire avant la clôture de l'année. Lecture seule : le serveur calcule tout
// (aucun seuil dans l'écran) et ne renvoie que des totaux par classe.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import KpiCard from '@/components/KpiCard.vue';
import { listPeriods, listYears, type AcademicYear, type Period } from '@/api/admin';
import { primary, type ClosureReport, type Dashboard } from '@/api/primary';
import type { Indicator } from '@/api/types';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDate, formatXaf } from '@/lib/format';
import { P } from './access';
import { BADGE_TONE, entryPercent, sheetBadge, watchFirst } from './dashboardModel';
import { pickYear, useAction, usePermissions } from './usePri';

const { t, locale } = useI18n();
const { can } = usePermissions();
const { error, run } = useAction();
const closureAction = useAction();

const data = ref<Dashboard | null>(null);
const periods = ref<Period[]>([]);
const years = ref<AcademicYear[]>([]);
const yearId = ref('');
const report = ref<ClosureReport | null>(null);
const canClose = computed(() => can(...P.closureRead));

const periodName = (id: string): string => {
  const p = periods.value.find((x) => x.id === id);
  return p ? `${t(`pri.sheet.kind.${p.kind}` as MessageKey)} ${p.ordinal}` : t('pri.dash.periodUnknown');
};
const classes = computed(() => (data.value ? watchFirst(data.value.classes) : []));
const indicators = computed((): Indicator[] => {
  const totals = data.value?.totals;
  if (!totals) return [];
  const item = (key: string, label: MessageKey, value: number, unit = 'count', detail?: Indicator['detail']): Indicator => ({ key, label: t(label), available: true, value, unit, detail });
  return [
    item('learners', 'pri.dash.learners', totals.learners, 'count', { classes: t('pri.dash.classesCount', { count: totals.classes }) }),
    item('am', 'pri.dash.absentMorning', totals.absent_morning),
    item('pm', 'pri.dash.absentAfternoon', totals.absent_afternoon),
    item('pay', 'pri.dash.latePayers', totals.late_payers, 'count', { balance: formatXaf(totals.late_balance_xaf, locale.value) }),
    item('sheets', 'pri.dash.sheetsLate', totals.sheets_late),
  ];
});

async function load(): Promise<void> {
  const loaded = await run(async () => {
    const [dashboard, allYears] = await Promise.all([primary.dashboard(), listYears()]);
    years.value = allYears;
    const wanted = dashboard.period ? allYears.find((y) => y.id === dashboard.period?.academic_year_id) : null;
    yearId.value = (wanted ?? pickYear(allYears))?.id ?? '';
    if (yearId.value !== '') periods.value = await listPeriods(yearId.value);
    return dashboard;
  });
  data.value = loaded ?? null;
}

async function check(): Promise<void> {
  report.value = (await closureAction.run(() => primary.closureCheck(yearId.value === '' ? undefined : yearId.value))) ?? null;
}

const checkLabel = (key: string, fallback: string): string => {
  const known: Record<string, MessageKey> = { 'primary.competency_sheets': 'pri.dash.check.sheets', 'primary.promotion_map': 'pri.dash.check.map' };
  const k = known[key];
  return k ? t(k) : fallback;
};
const mapError = (code: string): string => t(`pri.levels.err.${code}` as MessageKey);
const classNames = computed(() => Object.fromEntries((data.value?.classes ?? []).map((c) => [c.class_id, c.name])));

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.dash.title') }}</h1>
      <p class="lede">{{ t('pri.dash.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <template v-if="data">
      <p class="muted" role="status">
        {{ t('pri.dash.asOf', { date: formatDate(data.date, locale) }) }}
        <template v-if="data.period"> · {{ periodName(data.period.id) }}</template>
        <template v-else> · {{ t('pri.dash.noPeriod') }}</template>
      </p>
      <p v-if="data.perimeter === 'classes'" class="notice" role="note">{{ t('pri.dash.limited') }}</p>

      <div class="grid grid--kpi" role="list" :aria-label="t('pri.dash.totals')">
        <div v-for="i in indicators" :key="i.key" role="listitem"><KpiCard :indicator="i" /></div>
      </div>

      <section class="card" aria-labelledby="pri-dash-classes">
        <h2 id="pri-dash-classes">{{ t('pri.dash.byClass') }}</h2>
        <p class="muted">{{ t('pri.dash.absencesNote') }}</p>
        <p v-if="classes.length === 0" class="muted">{{ t('pri.dash.noClass') }}</p>
        <ul class="classes">
          <li v-for="c in classes" :key="c.class_id" class="class-card">
            <div class="class-card__head">
              <strong>{{ c.name }}</strong>
              <span class="tag" :class="`tag--${BADGE_TONE[sheetBadge(c.sheet)]}`">{{ t(`pri.dash.badge.${sheetBadge(c.sheet)}` as MessageKey) }}</span>
            </div>
            <dl class="facts">
              <div>
                <dt>{{ t('pri.dash.learnersShort') }}</dt>
                <dd>
                  {{ c.learners }}<span v-if="c.capacity !== null" class="muted"> / {{ c.capacity }}</span>
                  <span v-if="c.over_capacity" class="tag tag--warning">{{ t('pri.dash.overCapacity') }}</span>
                </dd>
              </div>
              <div><dt>{{ t('pri.dash.absentMorning') }}</dt><dd>{{ c.absent_morning }}</dd></div>
              <div><dt>{{ t('pri.dash.absentAfternoon') }}</dt><dd>{{ c.absent_afternoon }}</dd></div>
              <div>
                <dt>{{ t('pri.dash.latePayers') }}</dt>
                <dd>{{ c.late_payers }}<span v-if="c.late_payers > 0" class="muted"> · {{ formatXaf(c.late_balance_xaf, locale) }}</span></dd>
              </div>
              <div v-if="c.sheet.current">
                <dt>{{ t('pri.dash.sheet') }}</dt>
                <dd>
                  <template v-if="entryPercent(c.sheet) !== null">{{ c.sheet.current.entered }} / {{ c.sheet.current.expected }} ({{ entryPercent(c.sheet) }} %)</template>
                  <template v-else>{{ t('pri.dash.nothingExpected') }}</template>
                  <span v-if="c.sheet.current.deadline" class="muted"> · {{ t('pri.dash.deadline', { date: formatDate(c.sheet.current.deadline, locale) }) }}</span>
                </dd>
              </div>
              <div v-if="c.sheet.overdue_periods.length > 0">
                <dt>{{ t('pri.dash.toFinish') }}</dt>
                <dd>{{ c.sheet.overdue_periods.map(periodName).join(', ') }}</dd>
              </div>
            </dl>
          </li>
        </ul>
        <p class="muted">{{ t('pri.dash.deadlineHelp') }}</p>
      </section>

      <section v-if="canClose" class="card" aria-labelledby="pri-dash-close">
        <h2 id="pri-dash-close">{{ t('pri.dash.closeTitle') }}</h2>
        <p class="muted">{{ t('pri.dash.closeIntro') }}</p>
        <div class="field">
          <label for="pri-dash-year">{{ t('pri.ref.year') }}</label>
          <select id="pri-dash-year" v-model="yearId">
            <option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option>
          </select>
        </div>
        <button type="button" class="btn btn--primary" :disabled="closureAction.busy.value" @click="check">{{ t('pri.dash.closeCheck') }}</button>
        <ErrorNotice :error="closureAction.error.value" />
        <div v-if="report" class="closure" role="status">
          <p>
            <span class="tag" :class="report.ready ? 'tag--success' : 'tag--warning'">{{ report.ready ? t('pri.dash.ready') : t('pri.dash.notReady', { count: report.blocking_count }) }}</span>
          </p>
          <ul class="checks">
            <li v-for="item in report.checks" :key="item.key">
              <strong>{{ checkLabel(item.key, item.label) }}</strong> :
              <span class="tag" :class="item.status === 'ok' ? 'tag--success' : item.status === 'blocking' ? 'tag--warning' : 'tag--neutral'">{{ t(`pri.dash.status.${item.status}` as MessageKey) }}</span>
              <span v-if="item.detail" class="muted"> {{ item.detail }}</span>
            </li>
          </ul>
          <template v-if="report.sheets_to_lock.length > 0">
            <h3>{{ t('pri.dash.sheetsToLock') }}</h3>
            <ul>
              <li v-for="s in report.sheets_to_lock" :key="s.class_id">{{ s.name }} : {{ s.periods.map(periodName).join(', ') }}</li>
            </ul>
          </template>
          <template v-if="report.promotion_map_errors.length > 0">
            <h3>{{ t('pri.dash.mapErrors') }}</h3>
            <ul>
              <li v-for="e in report.promotion_map_errors" :key="e.class_structure_id">{{ classNames[e.class_structure_id] ?? t('pri.dash.classUnknown') }} : {{ mapError(e.code) }}</li>
            </ul>
          </template>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.classes { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 19rem), 1fr)); }
.class-card { border: 1px solid var(--border, #dcdcd8); padding: 12px; display: grid; gap: 8px; align-content: start; overflow-wrap: anywhere; }
.class-card__head { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
.facts { margin: 0; display: grid; gap: 6px; }
.facts > div { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.facts dt { color: var(--muted, #5c5c58); }
.facts dd { margin: 0; text-align: right; }
.facts dd .tag { margin-left: 6px; }
.checks { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 6px; }
.closure { margin-top: 12px; }
</style>
