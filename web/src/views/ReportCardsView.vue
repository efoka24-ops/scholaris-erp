<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ExportButton from '@/components/ExportButton.vue';
import StatusTag from '@/components/StatusTag.vue';
import { http } from '@/api/http';
import type { Person } from '@/api/types';
import { formatDate } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { useVocabulary } from '@/composables/useVocabulary';

// Bulletins (secondaire, maquette Bulletin.html) et relevés de notes (supérieur, maquette Releve.html), à partir des résultats
// calculés. Les colonnes que le calcul ne fournit pas encore (coefficient, rang par matière, enseignant, appréciation par matière,
// notes de contrôle continu et d'examen) ne sont pas affichées plutôt qu'inventées.
interface ResultRow {
  id: string;
  person_id: string;
  structure_id: string;
  period_id: string;
  period_label: string | null;
  average: number | null;
  rank_in_class: number | null;
  class_size: number | null;
  credits_earned: number | null;
  credits_total: number | null;
  mention: string | null;
  decision: string | null;
  details: Array<{ subject_id: string; code: string | null; name: string | null; average: number | null }> | null;
  status: string;
  published_at: string | null;
}
interface Named {
  id: string;
  name?: string;
  label?: string;
  code?: string;
}

const { t, locale } = useI18n();
const ctx = useContextStore();
const { term } = useVocabulary();

const structures = ref<Named[]>([]);
const periods = ref<Named[]>([]);
const structureId = ref('');
const periodId = ref('');
const results = ref<ResultRow[]>([]);
const names = ref<Map<string, string>>(new Map());
const selected = ref<ResultRow | null>(null);
const person = ref<Person | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);

const higher = computed(() => ctx.activeTile?.type === 'higher');
const label = (n: Named): string => n.name ?? n.label ?? n.code ?? n.id;
const nf = computed(() => new Intl.NumberFormat(locale.value === 'en' ? 'en-GB' : 'fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const num = (v: number | null): string => (v === null ? '—' : nf.value.format(v));
const structureName = computed(() => label(structures.value.find((s) => s.id === structureId.value) ?? { id: '' }));
const rank = (r: number | null): string => (r === null ? '—' : locale.value === 'en' ? String(r) : r === 1 ? '1er' : `${r}e`);

async function loadLists(): Promise<void> {
  try {
    structures.value = await http.all<Named>('/structures', {}, {}, 5);
    periods.value = await http.all<Named>('/periods', {}, {}, 5);
  } catch (e) {
    error.value = e;
  }
}

async function loadResults(): Promise<void> {
  results.value = [];
  selected.value = null;
  if (!structureId.value || !periodId.value) return;
  loading.value = true;
  error.value = null;
  try {
    results.value = await http.all<ResultRow>('/results', { 'filter[structure_id]': structureId.value, 'filter[period_id]': periodId.value }, {}, 5);
    const people = await http.all<Person>('/people', {}, {}, 5);
    names.value = new Map(people.map((p) => [p.id, `${p.last_name.toUpperCase()} ${p.first_name}`]));
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function open(row: ResultRow): Promise<void> {
  selected.value = row;
  person.value = null;
  try {
    person.value = (await http.get<{ data: Person }>(`/people/${row.person_id}`)).data;
  } catch {
    // La fiche personne est facultative pour l'affichage (droit people.read) : le nom du tableau suffit.
  }
}

function print(): void {
  window.print();
}

watch([structureId, periodId], () => void loadResults());
onMounted(() => void loadLists());
</script>

<template>
  <div class="page cards">
    <header class="page-head no-print">
      <p class="eyebrow">{{ t('reportcards.eyebrow') }}</p>
      <h1>{{ higher ? t('reportcards.titleHigher') : t('reportcards.title') }}</h1>
    </header>
    <ErrorNotice :error="error" />

    <div class="row pickers no-print">
      <div class="field">
        <label for="rc-structure">{{ term('grouping') }}</label>
        <select id="rc-structure" v-model="structureId"><option value="" disabled>{{ t('grades.choose') }}</option><option v-for="s in structures" :key="s.id" :value="s.id">{{ label(s) }}</option></select>
      </div>
      <div class="field">
        <label for="rc-period">{{ term('period') }}</label>
        <select id="rc-period" v-model="periodId"><option value="" disabled>{{ t('grades.choose') }}</option><option v-for="p in periods" :key="p.id" :value="p.id">{{ label(p) }}</option></select>
      </div>
      <ExportButton v-if="structureId && periodId" path="/results/export" :query="{ 'filter[structure_id]': structureId, 'filter[period_id]': periodId }" name="resultats" />
    </div>

    <p v-if="!structureId || !periodId" class="card muted no-print">{{ t('grades.pickFirst') }}</p>
    <p v-else-if="loading" class="muted no-print">{{ t('common.loading') }}</p>
    <p v-else-if="results.length === 0" class="card muted no-print">{{ t('reportcards.none') }}</p>

    <div v-if="results.length" class="table-wrap no-print">
      <table>
        <caption class="sr-only">{{ t('reportcards.title') }}</caption>
        <thead>
          <tr>
            <th scope="col" class="num">{{ t('grades.rank') }}</th>
            <th scope="col">{{ term('learner') }}</th>
            <th scope="col" class="num">{{ t('grades.average') }}</th>
            <th scope="col">{{ t('reportcards.mention') }}</th>
            <th scope="col">{{ t('reportcards.status') }}</th>
            <th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in results" :key="r.id">
            <td class="num">{{ r.rank_in_class ?? '—' }}</td>
            <th scope="row">{{ names.get(r.person_id) ?? r.person_id }}</th>
            <td class="num">{{ num(r.average) }}</td>
            <td>{{ r.mention ?? '' }}</td>
            <td><StatusTag :tone="r.status === 'published' ? 'success' : 'neutral'">{{ r.status === 'published' ? t('reportcards.published') : t('reportcards.draft') }}</StatusTag></td>
            <td><button type="button" class="btn" @click="open(r)">{{ higher ? t('reportcards.openStatement') : t('reportcards.openCard') }}</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <article v-if="selected" class="sheet" aria-labelledby="sheet-title">
      <header class="sheet__head">
        <div>
          <b>{{ ctx.activeTile?.name }}</b>
          <div class="muted small">{{ t('reportcards.country') }}</div>
        </div>
        <button type="button" class="btn no-print" @click="print">{{ t('reportcards.print') }}</button>
      </header>
      <h2 id="sheet-title" class="sheet__title">{{ higher ? t('reportcards.statementOf', { period: selected.period_label ?? '' }) : t('reportcards.cardOf', { period: selected.period_label ?? '' }) }}</h2>

      <dl class="ident">
        <div><dt>{{ t('reportcards.name') }}</dt><dd>{{ names.get(selected.person_id) ?? '—' }}</dd></div>
        <div v-if="person?.birth_date"><dt>{{ t('reportcards.born') }}</dt><dd>{{ formatDate(person.birth_date, locale) }}<template v-if="person.birth_place"> · {{ person.birth_place }}</template></dd></div>
        <div><dt>{{ term('grouping') }}</dt><dd>{{ structureName }}<template v-if="selected.class_size"> · {{ t('reportcards.size', { count: selected.class_size }) }}</template></dd></div>
      </dl>

      <table class="subjects">
        <thead>
          <tr><th scope="col">{{ t('reportcards.subject') }}</th><th scope="col" class="num">{{ t('reportcards.subjectAverage') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="d in selected.details ?? []" :key="d.subject_id">
            <th scope="row"><span v-if="d.code" class="code">{{ d.code }}</span> {{ d.name ?? d.subject_id }}</th>
            <td class="num">{{ num(d.average) }}</td>
          </tr>
          <tr v-if="!selected.details || selected.details.length === 0"><td colspan="2" class="muted">{{ t('reportcards.noDetails') }}</td></tr>
        </tbody>
        <tfoot>
          <tr><th scope="row">{{ higher ? t('reportcards.weighted') : t('reportcards.periodAverage') }}</th><td class="num strong">{{ num(selected.average) }} / 20</td></tr>
          <tr v-if="!higher"><th scope="row">{{ t('grades.rank') }}</th><td class="num strong">{{ rank(selected.rank_in_class) }}<template v-if="selected.class_size"> / {{ selected.class_size }}</template></td></tr>
          <tr v-if="higher"><th scope="row">{{ t('reportcards.credits') }}</th><td class="num strong">{{ selected.credits_earned ?? '—' }} / {{ selected.credits_total ?? '—' }}</td></tr>
          <tr><th scope="row">{{ t('reportcards.mention') }}</th><td class="num strong">{{ selected.mention ?? '—' }}</td></tr>
          <tr><th scope="row">{{ higher ? t('reportcards.juryDecision') : t('reportcards.councilDecision') }}</th><td class="num strong">{{ selected.decision ?? '—' }}</td></tr>
        </tfoot>
      </table>
      <p class="muted small">{{ t('reportcards.officialNote') }}</p>
    </article>
  </div>
</template>

<style scoped>
.pickers .field {
  min-width: 220px;
}
.sheet {
  margin-top: 20px;
  background: #fff;
  border: 1px solid var(--ink, #000);
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.sheet__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}
.sheet__title {
  margin: 0;
  text-align: center;
  font-size: 19px;
  letter-spacing: 0.04em;
}
.ident {
  margin: 0;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 8px 16px;
}
.ident div {
  display: flex;
  gap: 8px;
}
.ident dt {
  color: #595959;
}
.ident dd {
  margin: 0;
  font-weight: 700;
}
.subjects {
  width: 100%;
  border-collapse: collapse;
}
.subjects th,
.subjects td {
  padding: 8px 10px;
  border: 1px solid var(--border, #dcdcd8);
  text-align: left;
}
.subjects .num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.strong {
  font-weight: 800;
}
.code {
  font-family: monospace;
  color: #595959;
}
.small {
  font-size: 13px;
}
@media print {
  .no-print {
    display: none !important;
  }
  .sheet {
    border: 0;
  }
}
</style>
