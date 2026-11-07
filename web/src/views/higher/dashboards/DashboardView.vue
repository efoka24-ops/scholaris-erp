<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { BOARD_KINDS, dashboards, type Board, type BoardKind, type BoardRow, type BoardTable, type Cell } from '@/api/higher-dashboards';
import { ExportFormatUnavailable, saveBlob, type ExportFormat } from '@/api/exports';
import { formatDateTime, formatXaf } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import BarChart from './BarChart.vue';
import { dk, useDashboardsI18n } from './i18n';

// Tableau de bord IPES (PIL) : cartes de chiffres, graphique sobre et tableaux d'agrégats ; export CSV ou Excel de chaque tableau.
// Les effectifs inférieurs au seuil du serveur arrivent masqués (« < 5 ») : jamais de donnée nominative.
const props = defineProps<{ kind: string }>();
const { td, locale } = useDashboardsI18n();
const higher = useHigherStore();
const action = useHigherAction();
const data = ref<Board | null>(null);
const from = ref('');
const to = ref('');
const year = ref('');
const notice = ref('');

const kind = computed<BoardKind | null>(() => ((BOARD_KINDS as string[]).includes(props.kind) ? (props.kind as BoardKind) : null));
const numberLocale = computed(() => (locale.value === 'en' ? 'en-GB' : 'fr-FR'));

/** Graphique de chaque tableau de bord : tableau source, colonnes du libellé, colonne de valeur. */
const CHARTS: Record<BoardKind, { table: string; labels: string[]; value: string; unit: string }> = {
  enrolment: { table: 'funnel', labels: ['program'], value: 'applications', unit: '' },
  finance: { table: 'collection', labels: ['program'], value: 'recovery_rate', unit: ' %' },
  grading: { table: 'progress', labels: ['element_code'], value: 'entry_rate', unit: ' %' },
  results: { table: 'success_element', labels: ['element_code'], value: 'success_rate', unit: ' %' },
  attendance: { table: 'assiduity', labels: ['label'], value: 'absence_rate', unit: ' %' },
  vacations: { table: 'vacations', labels: ['department', 'month'], value: 'net_xaf', unit: ' XAF' },
  national: { table: 'national', labels: ['exam_type', 'program'], value: 'pass_rate', unit: ' %' },
  minesup: { table: 'by_program', labels: ['program'], value: 'enrolled', unit: '' },
};

function text(column: string, value: Cell): string {
  const raw = value === null || value === undefined ? '' : String(value);
  return td(dk(`hd.val.${column}.`, raw), {}, raw);
}

const chart = computed(() => {
  if (!data.value || !kind.value) return null;
  const spec = CHARTS[kind.value];
  const table = data.value.tables.find((t) => t.key === spec.table);
  const items = (table?.rows ?? [])
    .filter((r) => !r.suppressed && typeof r[spec.value] === 'number')
    .map((r) => ({ label: spec.labels.map((c) => text(c, r[c])).join(' · '), value: r[spec.value] as number }));

  return { title: td('hd.chart', { label: td(dk('hd.chart.', kind.value)) }), items, unit: spec.unit };
});

function cell(row: BoardRow, column: string): string {
  const value = row[column];
  if (value === null || value === undefined) return row.suppressed ? td('hd.suppressed', { n: data.value?.suppressed_below ?? 5 }) : '—';
  if (typeof value === 'number') {
    if (column.endsWith('_rate')) return td('hd.rate', { value: value.toLocaleString(numberLocale.value) });
    if (column.endsWith('_xaf')) return formatXaf(value, locale.value);
    return value.toLocaleString(numberLocale.value);
  }
  return text(column, value);
}

function cardValue(card: Board['cards'][number]): string {
  if (card.value === null) return td('hd.suppressed', { n: data.value?.suppressed_below ?? 5 });
  const n = card.value.toLocaleString(numberLocale.value);
  if (card.unit === 'percent') return td('hd.rate', { value: n });
  if (card.unit === 'xaf') return formatXaf(card.value, locale.value);

  return n;
}

const hasSuppressed = computed(() => (data.value?.tables ?? []).some((t) => t.rows.some((r) => r.suppressed)) || (data.value?.cards ?? []).some((c) => c.value === null));
const isNumeric = (table: BoardTable, column: string): boolean => table.rows.some((r) => typeof r[column] === 'number');

function query(): Record<string, string> {
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (from.value) q.from = from.value;
  if (to.value) q.to = to.value;
  if (year.value.trim()) q.academic_year_id = year.value.trim();

  return q;
}

async function load(): Promise<void> {
  if (!kind.value) return;
  notice.value = '';
  const res = await action.run(() => dashboards.get(kind.value as BoardKind, query()));
  if (res) data.value = res;
}

async function download(table: BoardTable, format: ExportFormat): Promise<void> {
  if (!kind.value) return;
  notice.value = '';
  try {
    const blob = await dashboards.exportTable(kind.value, table.key, query(), format);
    saveBlob(blob, `scholaris-${kind.value}-${table.key}.${format}`);
  } catch (e) {
    if (e instanceof ExportFormatUnavailable) notice.value = td('hd.exportUnavailable');
    else action.error.value = e;
  }
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
watch(() => props.kind, () => void load());
</script>

<template>
  <HigherFrame :title="kind ? td(dk('hd.kind.', kind)) : td('hd.title')" :lede="kind ? td(dk('hd.kindText.', kind)) : ''" section="portal" @school-change="load">
    <p><router-link :to="{ name: 'higher-dashboards' }">← {{ td('hd.back') }}</router-link></p>
    <ErrorNotice :error="action.error.value" />
    <p v-if="notice" class="notice" role="status">{{ notice }}</p>
    <form class="hi-form" @submit.prevent="load">
      <div class="field"><label for="db-from">{{ td('hd.from') }}</label><input id="db-from" v-model="from" type="date" /></div>
      <div class="field"><label for="db-to">{{ td('hd.to') }}</label><input id="db-to" v-model="to" type="date" /></div>
      <div class="field"><label for="db-year">{{ td('hd.year') }}</label><input id="db-year" v-model="year" type="text" maxlength="40" :placeholder="td('hd.yearPlaceholder')" /></div>
      <div class="field"><button class="btn" type="submit" :disabled="action.busy.value">{{ td('hd.refresh') }}</button></div>
    </form>

    <template v-if="data">
      <dl class="db-cards" data-test="cards">
        <div v-for="c in data.cards" :key="c.key" class="card">
          <dt>{{ td(dk('hd.card.', c.key)) }}</dt>
          <dd class="hi-total" :data-test="`card-${c.key}`">{{ cardValue(c) }}</dd>
        </div>
      </dl>

      <BarChart v-if="chart" :title="chart.title" :items="chart.items" :unit="chart.unit" />

      <section v-for="table in data.tables" :key="table.key" class="db-table" :data-test="`table-${table.key}`">
        <div class="db-head">
          <h2>{{ td(dk('hd.table.', table.key)) }}</h2>
          <div class="db-export" role="group" :aria-label="td('hd.exportOf', { table: td(dk('hd.table.', table.key)) })">
            <button class="btn btn--quiet" type="button" @click="download(table, 'csv')">{{ td('hd.exportCsv') }}</button>
            <button class="btn btn--quiet" type="button" @click="download(table, 'xlsx')">{{ td('hd.exportXlsx') }}</button>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th v-for="col in table.columns" :key="col" scope="col" :class="{ num: isNumeric(table, col) }">{{ td(dk('hd.col.', col)) }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in table.rows" :key="i" data-test="row" :class="{ muted: row.suppressed }">
                <td v-for="col in table.columns" :key="col" :class="{ num: isNumeric(table, col) }">{{ cell(row, col) }}</td>
              </tr>
              <tr v-if="table.rows.length === 0"><td :colspan="table.columns.length" class="muted">{{ td('hd.none') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <p v-if="hasSuppressed" class="hi-small muted" data-test="suppressed-note">{{ td('hd.suppressedNote', { n: data.suppressed_below }) }}</p>
      <ul v-if="data.notes.length > 0" class="hi-small muted"><li v-for="n in data.notes" :key="n">{{ n }}</li></ul>
      <p class="hi-small muted">{{ td('hd.generated', { date: formatDateTime(data.generated_at, locale) }) }}</p>
    </template>
  </HigherFrame>
</template>

<style scoped>
.db-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin: 0 0 20px;
}
.db-cards dt {
  color: var(--muted);
  font-size: 13px;
}
.db-cards dd {
  margin: 4px 0 0;
}
.db-head {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: space-between;
}
.db-export {
  display: flex;
  gap: 6px;
}
.db-table {
  margin-bottom: 24px;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
</style>
