<script setup lang="ts">
// Examens côté délégation départementale et direction des examens (EX-EXA-03, 05) : listes des écoles d'un territoire (aucun nom),
// import des résultats officiels (API-10 : admis, ajourné, absent ; aucune note ni règle d'admission inventée), publication aux
// familles (NOT-09), taux de réussite = admis ÷ présents par école et pour le territoire, centres et sous-centres, intervenants et
// état des vacations (décret 2010/1738 : montants lus des paramètres, jamais en dur).
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  publicDashboards,
  publicExams,
  type ExamCentre,
  type ExamOfficial,
  type ExamSession,
  type ExamSummary,
  type ImportReport,
  type TerritoryList,
  type TerritoryNodeView,
  type VacationStatementView,
} from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatXaf } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { useAction } from './usePs';

const { t, locale } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const sessions = ref<ExamSession[]>([]);
const sessionId = ref('');
const territories = ref<TerritoryNodeView[]>([]);
const territoryId = ref('');
const lists = ref<TerritoryList[]>([]);
const results = ref<{ schools: { establishment_id: string; official_code: string; summary: ExamSummary }[]; summary: ExamSummary } | null>(null);
const centres = ref<ExamCentre[]>([]);
const officials = ref<ExamOfficial[]>([]);
const vacations = ref<VacationStatementView | null>(null);
const csv = ref('candidate_number;result\n');
const importReport = ref<ImportReport | null>(null);
const done = ref('');
const centre = ref({ code: '', label: '', parent_centre_id: '', host_establishment_id: '' });
const assignment = ref({ centre_id: '', schools: '' });
const official = ref({ centre_id: '', full_name: '', function_code: 'marker', half_days: 0, copies_marked: 0 });

const canConsolidate = computed(() => ctx.can('public_primary.exams.consolidate'));
const canImport = computed(() => ctx.can('public_primary.exams.import'));
const label = (n: TerritoryNodeView): string => (locale.value === 'en' ? n.label_en : n.label_fr);
const rate = (s: ExamSummary): string => (s.success_rate === null ? '—' : `${s.success_rate} %`);
const FUNCTIONS = ['jury_president', 'mission_officer', 'centre_head', 'sub_centre_head', 'rapporteur', 'secretariat_head', 'pe_coordinator', 'marking_room_head', 'pe_workshop_head', 'secretariat_member', 'marker'];

onMounted(async () => {
  await run(async () => {
    sessions.value = await publicExams.sessions();
    sessionId.value = sessions.value[0]?.id ?? '';
    territories.value = await publicDashboards.entry();
    territoryId.value = territories.value.find((n) => n.type === 'departement')?.id ?? territories.value[0]?.id ?? '';
  });
  await load();
});

async function load(): Promise<void> {
  if (!sessionId.value || !territoryId.value) return;
  await run(async () => {
    lists.value = await publicExams.territoryLists(sessionId.value, territoryId.value);
    results.value = await publicExams.territoryResults(sessionId.value, territoryId.value);
    centres.value = await publicExams.centres(sessionId.value);
    if (canConsolidate.value) {
      officials.value = await publicExams.officials(sessionId.value);
      vacations.value = await publicExams.vacations(sessionId.value, territoryId.value);
    }
  });
}

async function act(action: () => Promise<unknown>, message: string): Promise<void> {
  done.value = '';
  if ((await run(action)) !== undefined) {
    done.value = message;
    await load();
  }
}

async function importResults(): Promise<void> {
  done.value = '';
  const report = await run(() => publicExams.importResults(sessionId.value, territoryId.value, csv.value));
  if (report) {
    importReport.value = report;
    await load();
  }
}

const publish = (): Promise<void> => act(() => publicExams.publish(sessionId.value, territoryId.value), t('pp.exd.published'));
const createCentre = (): Promise<void> =>
  act(
    () =>
      publicExams.createCentre(sessionId.value, {
        territory_id: territoryId.value,
        code: centre.value.code,
        label: centre.value.label,
        ...(centre.value.parent_centre_id ? { parent_centre_id: centre.value.parent_centre_id } : {}),
        ...(centre.value.host_establishment_id ? { host_establishment_id: centre.value.host_establishment_id } : {}),
      }),
    t('pp.exd.centreCreated'),
  );
const assign = (): Promise<void> =>
  act(
    () => publicExams.assign(sessionId.value, assignment.value.centre_id, assignment.value.schools.split(',').map((s) => s.trim()).filter((s) => s !== '')),
    t('pp.exd.assigned'),
  );
const addOfficial = (): Promise<void> => act(() => publicExams.addOfficial(sessionId.value, { ...official.value }), t('pp.exd.officialAdded'));
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('pp.exd.title') }}</h1>
      <p class="lede">{{ t('pp.exd.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>

    <section class="card controls">
      <div class="field">
        <label for="ed-session">{{ t('pp.exa.session') }}</label>
        <select id="ed-session" v-model="sessionId" @change="load">
          <option v-for="s in sessions" :key="s.id" :value="s.id">{{ s.label }} · {{ s.year_label }}</option>
        </select>
      </div>
      <div class="field">
        <label for="ed-territory">{{ t('pp.exd.territory') }}</label>
        <select id="ed-territory" v-model="territoryId" @change="load">
          <option v-for="n in territories" :key="n.id" :value="n.id">{{ label(n) }}</option>
        </select>
      </div>
    </section>

    <section class="card" aria-labelledby="ed-lists">
      <h2 id="ed-lists">{{ t('pp.exd.lists') }}</h2>
      <p v-if="lists.length === 0" class="muted">{{ t('pp.exd.noLists') }}</p>
      <ul class="rows">
        <li v-for="l in lists" :key="l.establishment_id" class="row">
          <strong>{{ l.official_code }}</strong> · {{ l.list ? l.list.state : t('pp.exa.noList') }}
          <span class="muted"> · {{ t('pp.exd.counts', { validated: l.counts.validated ?? 0, blocked: l.counts.blocked ?? 0, proposed: l.counts.proposed ?? 0 }) }}</span>
          <span v-if="l.list && l.list.amendments_count > 0" class="tag">{{ t('pp.exa.frozen', { n: l.list.amendments_count }) }}</span>
        </li>
      </ul>
    </section>

    <section v-if="results" class="card" aria-labelledby="ed-results">
      <h2 id="ed-results">{{ t('pp.exa.results') }}</h2>
      <p>{{ t('pp.exa.summary', { candidates: results.summary.candidates, present: results.summary.present, passed: results.summary.passed }) }} · <strong>{{ rate(results.summary) }}</strong> {{ t('pp.exa.rateFormula') }}</p>
      <table class="table">
        <thead><tr><th>{{ t('pp.exd.school') }}</th><th>{{ t('pp.exd.present') }}</th><th>{{ t('pp.exa.passed') }}</th><th>{{ t('pp.exd.rate') }}</th></tr></thead>
        <tbody>
          <tr v-for="s in results.schools" :key="s.establishment_id"><td>{{ s.official_code }}</td><td>{{ s.summary.present }}</td><td>{{ s.summary.passed }}</td><td>{{ rate(s.summary) }}</td></tr>
        </tbody>
      </table>
    </section>

    <section v-if="canImport" class="card controls" aria-labelledby="ed-import">
      <h2 id="ed-import">{{ t('pp.exd.import') }}</h2>
      <p class="muted">{{ t('pp.exd.importHelp') }}</p>
      <label for="ed-csv" class="sr-only">CSV</label>
      <textarea id="ed-csv" v-model="csv" rows="6"></textarea>
      <div class="actions">
        <button type="button" class="btn btn--primary" :disabled="busy" @click="importResults">{{ t('pp.exd.importButton') }}</button>
        <button type="button" class="btn" :disabled="busy" @click="publish">{{ t('pp.exd.publish') }}</button>
      </div>
      <div v-if="importReport">
        <p>{{ t('pp.exd.imported', { n: importReport.imported, rejected: importReport.rejected.length }) }}</p>
        <ul>
          <li v-for="r in importReport.rejected" :key="r.line">{{ t('pp.exd.rejectedLine', { line: r.line, key: r.key }) }} {{ r.reason }}</li>
        </ul>
      </div>
    </section>

    <section v-if="canConsolidate" class="card controls" aria-labelledby="ed-centres">
      <h2 id="ed-centres">{{ t('pp.exd.centres') }}</h2>
      <ul class="rows">
        <li v-for="c in centres" :key="c.id" class="row">{{ c.code }} · {{ c.label }} <span class="tag">{{ t(c.kind === 'centre' ? 'pp.exd.centre' : 'pp.exd.subCentre') }}</span></li>
      </ul>
      <form class="controls" @submit.prevent="createCentre">
        <div class="field"><label for="ed-code">{{ t('pp.exd.code') }}</label><input id="ed-code" v-model="centre.code" type="text" required maxlength="40" /></div>
        <div class="field"><label for="ed-label">{{ t('pp.exd.label') }}</label><input id="ed-label" v-model="centre.label" type="text" required maxlength="160" /></div>
        <div class="field">
          <label for="ed-parent">{{ t('pp.exd.parent') }}</label>
          <select id="ed-parent" v-model="centre.parent_centre_id">
            <option value="">—</option>
            <option v-for="c in centres.filter((x) => x.kind === 'centre')" :key="c.id" :value="c.id">{{ c.code }}</option>
          </select>
        </div>
        <button type="submit" class="btn" :disabled="busy">{{ t('pp.exd.createCentre') }}</button>
      </form>
      <form class="controls" @submit.prevent="assign">
        <div class="field">
          <label for="ed-assign">{{ t('pp.exd.centre') }}</label>
          <select id="ed-assign" v-model="assignment.centre_id" required>
            <option v-for="c in centres" :key="c.id" :value="c.id">{{ c.code }}</option>
          </select>
        </div>
        <div class="field"><label for="ed-schools">{{ t('pp.exd.schools') }}</label><input id="ed-schools" v-model="assignment.schools" type="text" required /></div>
        <button type="submit" class="btn" :disabled="busy">{{ t('pp.exd.assign') }}</button>
      </form>
    </section>

    <section v-if="canConsolidate" class="card controls" aria-labelledby="ed-vac">
      <h2 id="ed-vac">{{ t('pp.exd.vacations') }}</h2>
      <p class="muted">{{ vacations?.source }}</p>
      <ul class="rows">
        <li v-for="o in officials" :key="o.id" class="row">{{ o.full_name }} · {{ o.function_code }} · {{ t('pp.exd.halfDays', { n: o.half_days }) }} · {{ t('pp.exd.copies', { n: o.copies_marked }) }}</li>
      </ul>
      <form class="controls" @submit.prevent="addOfficial">
        <div class="field">
          <label for="ed-ocentre">{{ t('pp.exd.centre') }}</label>
          <select id="ed-ocentre" v-model="official.centre_id" required>
            <option v-for="c in centres" :key="c.id" :value="c.id">{{ c.code }}</option>
          </select>
        </div>
        <div class="field"><label for="ed-oname">{{ t('pp.exd.officialName') }}</label><input id="ed-oname" v-model="official.full_name" type="text" required maxlength="160" /></div>
        <div class="field">
          <label for="ed-ofn">{{ t('pp.exd.function') }}</label>
          <select id="ed-ofn" v-model="official.function_code">
            <option v-for="f in FUNCTIONS" :key="f" :value="f">{{ f }}</option>
          </select>
        </div>
        <div class="field"><label for="ed-ohd">{{ t('pp.exd.halfDaysLabel') }}</label><input id="ed-ohd" v-model.number="official.half_days" type="number" min="0" /></div>
        <div class="field"><label for="ed-ocp">{{ t('pp.exd.copiesLabel') }}</label><input id="ed-ocp" v-model.number="official.copies_marked" type="number" min="0" /></div>
        <button type="submit" class="btn" :disabled="busy">{{ t('pp.exd.addOfficial') }}</button>
      </form>
      <p v-if="vacations">
        {{ t('pp.exd.totals', { officials: formatXaf(vacations.total_officials), directors: formatXaf(vacations.total_directors), total: formatXaf(vacations.total) }) }}
        <span class="tag">{{ vacations.rates_status === 'official' ? t('pp.ref.official') : t('pp.ref.toConfirm') }}</span>
      </p>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.row { border: 1px solid var(--input-border); padding: 8px; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { text-align: left; padding: 4px; border-bottom: 1px solid var(--input-border); }
textarea { width: 100%; font-family: monospace; }
</style>
