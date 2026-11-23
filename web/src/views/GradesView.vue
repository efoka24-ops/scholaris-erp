<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import GradeHistory from '@/components/GradeHistory.vue';
import GradeImportPanel from '@/components/GradeImportPanel.vue';
import ExportButton from '@/components/ExportButton.vue';
import StatusTag from '@/components/StatusTag.vue';
import { ApiError, http } from '@/api/http';
import { isColumnEditable, type ClassCouncil, type GradeGrid, type GridCell, type GridColumn, type GridRow } from '@/api/assessment';
import { useContextStore } from '@/stores/context';
import { useOfflineStore } from '@/stores/offline';
import { useI18n } from '@/i18n';
import { useVocabulary } from '@/composables/useVocabulary';
import { cellKey, localEdits, parseScore, queueGradeEdit, validateScore } from '@/offline/grades';
import { resolveConflict, type QueueItem } from '@/offline/queue';
import { cacheGet, cachePut } from '@/offline/db';
import { formatDateTime } from '@/lib/format';

// Grille de saisie (maquettes Notes / NotesMobile) et conseil de classe.
const { t, tp, locale } = useI18n();
const ctx = useContextStore();
const offline = useOfflineStore();
const { term } = useVocabulary();

interface Named {
  id: string;
  name?: string;
  label?: string;
  code?: string;
}
const structures = ref<Named[]>([]);
const periods = ref<Named[]>([]);
const structureId = ref('');
const periodId = ref('');
const grid = ref<GradeGrid | null>(null);
const fromCache = ref(false);
const edits = ref<Map<string, QueueItem>>(new Map());
const cellErrors = ref<Record<string, string>>({});
const savedAt = ref<string | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);
const tab = ref<'grid' | 'council' | 'import'>('grid');
const historyOf = ref<{ gradeId: string; title: string } | null>(null);
const canImport = computed(() => ctx.can('assessment.enter', 'assessment.validate'));

const council = ref<ClassCouncil | null>(null);
const councilReasons = ref<Record<string, string>>({});
const councilBusy = ref(false);

const est = computed(() => ctx.establishmentId ?? '');
const label = (n: Named): string => n.name ?? n.label ?? n.code ?? n.id;

async function loadLists(): Promise<void> {
  try {
    structures.value = await http.all<Named>('/structures', {}, {}, 5);
    periods.value = await http.all<Named>('/periods', {}, {}, 5);
  } catch (e) {
    error.value = e;
  }
}

function effective(row: GridRow, col: GridColumn): { value: number | null; absence: GridCell['absence']; pending: boolean; conflict: boolean } {
  const server = row.cells[col.id];
  const local = edits.value.get(cellKey(col.id, row.person_id));
  if (local) {
    return {
      value: (local.payload.value as number | null) ?? null,
      absence: (local.payload.absence as GridCell['absence']) ?? null,
      pending: local.status === 'pending',
      conflict: local.status === 'conflict',
    };
  }
  return { value: server?.value ?? null, absence: server?.absence ?? null, pending: false, conflict: false };
}

const counters = computed(() => {
  let errors = 0;
  let missing = 0;
  if (grid.value) {
    for (const row of grid.value.rows) {
      for (const col of grid.value.columns) {
        const e = effective(row, col);
        if (e.absence) continue;
        if (e.value === null) missing++;
        else if (e.value > col.max_score) errors++;
      }
    }
  }
  return { errors: errors + Object.keys(cellErrors.value).length, missing };
});

async function loadGrid(): Promise<void> {
  if (!structureId.value || !periodId.value) return;
  loading.value = true;
  error.value = null;
  const key = `grid:${est.value}:${structureId.value}:${periodId.value}`;
  try {
    const res = await http.get<{ data: GradeGrid }>('/grade-sheets', { structure_id: structureId.value, period_id: periodId.value });
    grid.value = res.data;
    fromCache.value = false;
    await cachePut(key, res.data);
  } catch (e) {
    const cached = e instanceof ApiError && (e.network || e.status >= 500) ? await cacheGet<GradeGrid>(key) : undefined;
    if (cached) {
      grid.value = cached;
      fromCache.value = true;
    } else {
      error.value = e;
      grid.value = null;
    }
  } finally {
    edits.value = await localEdits(est.value);
    loading.value = false;
  }
}

async function onInput(row: GridRow, col: GridColumn, raw: string): Promise<void> {
  const k = cellKey(col.id, row.person_id);
  const value = parseScore(raw);
  // Aucun changement par rapport à la valeur affichée : rien à enregistrer.
  if (value === effective(row, col).value && !(k in cellErrors.value)) return;
  const check = validateScore(value, col.max_score);
  if (check !== 'ok') {
    cellErrors.value = {
      ...cellErrors.value,
      [k]: t(check === 'over' ? 'grades.errOver' : 'grades.errInvalid', { line: row.number, title: col.title, value: raw.trim(), max: col.max_score }),
    };
    return;
  }
  const { [k]: _gone, ...rest } = cellErrors.value;
  cellErrors.value = rest;
  await save(row, col, value as number | null, null);
}

async function toggleAbsent(row: GridRow, col: GridColumn): Promise<void> {
  const e = effective(row, col);
  await save(row, col, null, e.absence === 'justified' ? null : 'justified');
}

async function save(row: GridRow, col: GridColumn, value: number | null, absence: 'justified' | 'unjustified' | null): Promise<void> {
  const server = row.cells[col.id];
  try {
    await queueGradeEdit(est.value, { assessment_id: col.id, person_id: row.person_id, value, absence, base_version: server?.version ?? 0 }, `${row.name} · ${col.title}`);
    edits.value = await localEdits(est.value);
    savedAt.value = new Date().toISOString();
    await offline.refresh();
    // Enregistrement automatique : envoi immédiat s'il y a du réseau, sinon à son retour.
    if (offline.online) void offline.sync().then(async () => (edits.value = await localEdits(est.value)));
  } catch (e) {
    error.value = e;
  }
}

async function choose(item: QueueItem, choice: 'keep-server' | 'resend'): Promise<void> {
  await resolveConflict(item.client_uuid, choice);
  edits.value = await localEdits(est.value);
  await offline.refresh();
  if (choice === 'resend') await offline.sync();
  await loadGrid();
}

const conflicts = computed(() => [...edits.value.values()].filter((i) => i.status === 'conflict'));
const serverGrade = (i: QueueItem): { score?: number | null; absence?: string | null; version?: number } => (i.server_version ?? {}) as { score?: number | null; absence?: string | null; version?: number };

// Conseil de classe
async function loadCouncil(): Promise<void> {
  if (!structureId.value || !periodId.value) return;
  councilBusy.value = true;
  error.value = null;
  try {
    const list = await http.get<{ data: Array<{ id: string }> }>('/class-councils', { 'filter[structure_id]': structureId.value, 'filter[period_id]': periodId.value });
    const first = list.data[0];
    council.value = first ? (await http.get<{ data: ClassCouncil }>(`/class-councils/${first.id}`)).data : null;
  } catch (e) {
    error.value = e;
  } finally {
    councilBusy.value = false;
  }
}

async function openCouncil(): Promise<void> {
  councilBusy.value = true;
  error.value = null;
  try {
    council.value = (await http.post<{ data: ClassCouncil }>('/class-councils', { structure_id: structureId.value, period_id: periodId.value })).data;
  } catch (e) {
    error.value = e;
  } finally {
    councilBusy.value = false;
  }
}

const needsReason = (line: { decision: string | null; proposal: string | null }): boolean => !!line.decision && line.decision !== line.proposal;

async function decide(lineId: string, decision: string, proposal: string | null): Promise<void> {
  if (!council.value) return;
  const reason = councilReasons.value[lineId] ?? '';
  if (decision !== proposal && reason.trim() === '') {
    error.value = new ApiError(422, { type: 'about:blank', title: '', status: 422, detail: t('grades.reasonRequired') });
    return;
  }
  councilBusy.value = true;
  error.value = null;
  try {
    council.value = (await http.patch<{ data: ClassCouncil }>(`/class-councils/${council.value.id}/lines/${lineId}`, { decision, reason: decision === proposal ? null : reason.trim() })).data;
  } catch (e) {
    error.value = e;
  } finally {
    councilBusy.value = false;
  }
}

async function validateCouncil(): Promise<void> {
  if (!council.value) return;
  councilBusy.value = true;
  error.value = null;
  try {
    council.value = (await http.post<{ data: ClassCouncil }>(`/class-councils/${council.value.id}/validate`)).data;
  } catch (e) {
    error.value = e;
  } finally {
    councilBusy.value = false;
  }
}

watch([structureId, periodId], () => {
  grid.value = null;
  council.value = null;
  cellErrors.value = {};
  void loadGrid();
  if (tab.value === 'council') void loadCouncil();
});
watch(tab, (v) => {
  if (v === 'council' && !council.value) void loadCouncil();
});
onMounted(loadLists);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('grades.eyebrow') }}</p>
      <h1>{{ t('grades.title') }}</h1>
    </header>

    <ErrorNotice :error="error" />

    <div class="row pickers">
      <div class="field">
        <label for="g-structure">{{ term('grouping') }}</label>
        <select id="g-structure" v-model="structureId">
          <option value="" disabled>{{ t('grades.choose') }}</option>
          <option v-for="s in structures" :key="s.id" :value="s.id">{{ label(s) }}</option>
        </select>
      </div>
      <div class="field">
        <label for="g-period">{{ term('period') }}</label>
        <select id="g-period" v-model="periodId">
          <option value="" disabled>{{ t('grades.choose') }}</option>
          <option v-for="p in periods" :key="p.id" :value="p.id">{{ label(p) }}</option>
        </select>
      </div>
    </div>

    <div v-if="ctx.can('assessment.read')" class="row" data-test="grades-exports">
      <ExportButton path="/results/export" :query="{ 'filter[structure_id]': structureId, 'filter[period_id]': periodId }" name="resultats" :caption="t('export.results')" />
      <ExportButton path="/grades/export" name="notes" :caption="t('export.grades')" />
    </div>

    <div class="tabs" role="tablist" :aria-label="t('grades.title')">
      <button id="tab-grid" role="tab" type="button" :aria-selected="tab === 'grid'" aria-controls="panel-grid" @click="tab = 'grid'">{{ t('grades.tabGrid') }}</button>
      <button v-if="canImport" id="tab-import" role="tab" type="button" :aria-selected="tab === 'import'" aria-controls="panel-import" @click="tab = 'import'">{{ t('grades.tabImport') }}</button>
      <button id="tab-council" role="tab" type="button" :aria-selected="tab === 'council'" aria-controls="panel-council" @click="tab = 'council'">{{ t('grades.tabCouncil') }}</button>
    </div>

    <section v-show="tab === 'grid'" id="panel-grid" role="tabpanel" aria-labelledby="tab-grid">
      <p v-if="!structureId || !periodId" class="card muted">{{ t('grades.pickFirst') }}</p>
      <p v-if="loading" class="muted">{{ t('common.loading') }}</p>
      <p v-if="fromCache" class="notice notice--warning">{{ t('grades.fromCache') }}</p>

      <template v-if="grid">
        <p class="stats" aria-live="polite">
          <strong>{{ tp('grades.errors', counters.errors) }}</strong> · <strong>{{ tp('grades.missing', counters.missing) }}</strong>
          <span v-if="offline.pendingGrades > 0"> · {{ tp('grades.pending', offline.pendingGrades) }}</span>
          <span v-if="savedAt" class="muted"> · {{ t('grades.autosaved', { time: formatDateTime(savedAt, locale) }) }}</span>
        </p>
        <ul v-if="Object.keys(cellErrors).length" class="errs">
          <li v-for="(m, k) in cellErrors" :key="k" role="alert">{{ m }}</li>
        </ul>

        <div v-if="conflicts.length" class="notice notice--warning" role="alert">
          <h2>{{ t('grades.conflictTitle') }}</h2>
          <p>{{ t('grades.conflictIntro') }}</p>
          <ul>
            <li v-for="c in conflicts" :key="c.client_uuid" class="conflict">
              <strong>{{ c.label }}</strong>
              <div class="versions">
                <div class="card"><h3>{{ t('grades.yourVersion') }}</h3><p>{{ c.payload.absence ? t('grades.absent') : (c.payload.value ?? '—') }}</p></div>
                <div class="card"><h3>{{ t('grades.serverVersion') }}</h3><p>{{ serverGrade(c).absence ? t('grades.absent') : (serverGrade(c).score ?? '—') }}</p></div>
              </div>
              <div class="row">
                <button type="button" class="btn" @click="choose(c, 'keep-server')">{{ t('sync.keepServer') }}</button>
                <button type="button" class="btn" @click="choose(c, 'resend')">{{ t('sync.resend') }}</button>
              </div>
            </li>
          </ul>
        </div>

        <GradeHistory v-if="historyOf" :key="historyOf.gradeId" :grade-id="historyOf.gradeId" :title="historyOf.title" @close="historyOf = null" />

        <div class="table-wrap">
          <table>
            <caption class="sr-only">{{ t('grades.title') }}</caption>
            <thead>
              <tr>
                <th scope="col">#</th>
                <th scope="col">{{ term('learner') }}</th>
                <th v-for="c in grid.columns" :key="c.id" scope="col" class="num">
                  {{ c.title }}<br /><small class="muted">{{ t('grades.weight', { weight: c.weight, max: c.max_score }) }}</small>
                  <StatusTag tone="neutral">{{ c.status }}</StatusTag>
                </th>
                <th scope="col" class="num">{{ t('grades.average') }}</th>
                <th scope="col">{{ t('grades.appraisal') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in grid.rows" :key="r.person_id">
                <td>{{ r.number }}</td>
                <th scope="row">{{ r.name }}<br /><small class="muted">{{ r.matricule }}</small></th>
                <td v-for="c in grid.columns" :key="c.id" class="num cell">
                  <template v-if="effective(r, c).absence">
                    <StatusTag tone="neutral">{{ t('grades.absent') }}</StatusTag>
                  </template>
                  <input
                    v-else
                    type="text"
                    inputmode="decimal"
                    :aria-label="`${r.name} · ${c.title}`"
                    :value="effective(r, c).value ?? ''"
                    :disabled="!isColumnEditable(c.status)"
                    :aria-invalid="!!cellErrors[cellKey(c.id, r.person_id)]"
                    @change="onInput(r, c, ($event.target as HTMLInputElement).value)"
                  />
                  <button v-if="isColumnEditable(c.status)" type="button" class="abs" :aria-pressed="!!effective(r, c).absence" @click="toggleAbsent(r, c)">{{ t('grades.absShort') }}</button>
                  <StatusTag v-if="effective(r, c).pending" tone="warning">{{ t('grades.queued') }}</StatusTag>
                  <StatusTag v-if="effective(r, c).conflict" tone="ink">{{ t('grades.conflict') }}</StatusTag>
                  <button
                    v-if="r.cells[c.id]?.grade_id"
                    type="button"
                    class="abs"
                    :aria-label="t('grades.historyOf', { name: r.name, title: c.title })"
                    @click="historyOf = { gradeId: r.cells[c.id]!.grade_id!, title: `${r.name} · ${c.title}` }"
                  >
                    {{ t('grades.historyButton') }}
                  </button>
                </td>
                <td class="num">{{ r.average ?? '—' }}</td>
                <td>{{ r.appraisal ?? '' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <section v-if="canImport" v-show="tab === 'import'" id="panel-import" role="tabpanel" aria-labelledby="tab-import">
      <p v-if="!grid" class="card muted">{{ t('grades.pickFirst') }}</p>
      <GradeImportPanel v-else :columns="grid.columns" @imported="loadGrid" />
    </section>

    <section v-show="tab === 'council'" id="panel-council" role="tabpanel" aria-labelledby="tab-council">
      <p v-if="!structureId || !periodId" class="card muted">{{ t('grades.pickFirst') }}</p>
      <template v-else>
        <p v-if="councilBusy" class="muted">{{ t('common.loading') }}</p>
        <div v-if="!council && !councilBusy" class="card">
          <p>{{ t('grades.noCouncil') }}</p>
          <button type="button" class="btn btn--primary" @click="openCouncil">{{ t('grades.openCouncil') }}</button>
        </div>
        <template v-if="council">
          <p><StatusTag tone="neutral">{{ council.status }}</StatusTag></p>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th class="num" scope="col">{{ t('grades.rank') }}</th>
                  <th scope="col">{{ term('learner') }}</th>
                  <th class="num" scope="col">{{ t('grades.average') }}</th>
                  <th class="num" scope="col">{{ t('grades.unjustified') }}</th>
                  <th scope="col">{{ t('grades.proposal') }}</th>
                  <th scope="col">{{ t('grades.decision') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="l in council.lines" :key="l.id">
                  <td class="num">{{ l.rank_in_class }}</td>
                  <th scope="row">{{ l.name }}</th>
                  <td class="num">{{ l.average ?? '—' }}</td>
                  <td class="num">{{ l.unjustified_absences ?? 0 }}</td>
                  <td>{{ l.proposal }}</td>
                  <td>
                    <label class="sr-only" :for="`dec-${l.id}`">{{ t('grades.decision') }} {{ l.name }}</label>
                    <input :id="`dec-${l.id}`" type="text" :value="l.decision ?? l.proposal ?? ''" :disabled="council.status !== 'open' && council.status !== 'draft'" @change="decide(l.id, ($event.target as HTMLInputElement).value, l.proposal)" />
                    <div v-if="needsReason({ decision: l.decision, proposal: l.proposal }) || councilReasons[l.id] !== undefined">
                      <label class="sr-only" :for="`rs-${l.id}`">{{ t('grades.reason') }}</label>
                      <input :id="`rs-${l.id}`" v-model="councilReasons[l.id]" type="text" :placeholder="t('grades.reason')" />
                    </div>
                    <small v-if="l.reason" class="muted">{{ t('grades.modified', { reason: l.reason }) }}</small>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <button type="button" class="btn btn--primary" :disabled="councilBusy || council.status === 'validated'" @click="validateCouncil">{{ t('grades.validateCouncil') }}</button>
        </template>
      </template>
    </section>
  </div>
</template>

<style scoped>
.pickers .field {
  min-width: 220px;
}
.tabs {
  display: flex;
  border-bottom: 1px solid var(--ink);
  margin: 0.5rem 0 1rem;
}
.tabs button {
  min-height: var(--tap);
  padding: 0 1rem;
  font: inherit;
  font-weight: 700;
  background: transparent;
  border: 1px solid transparent;
  border-bottom: 0;
  cursor: pointer;
}
.tabs button[aria-selected='true'] {
  background: var(--surface);
  border-color: var(--ink);
  border-bottom: 3px solid var(--accent);
}
.cell {
  white-space: nowrap;
}
.cell input {
  width: 5.5rem;
  text-align: right;
}
.abs {
  min-height: var(--tap);
  min-width: var(--tap);
  font: inherit;
  background: var(--surface);
  border: 1px solid var(--border);
  cursor: pointer;
}
.abs[aria-pressed='true'] {
  background: var(--ink);
  color: #fff;
}
.errs {
  color: var(--danger);
  font-weight: 600;
}
.versions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
  margin: 0.5rem 0;
}
.conflict {
  margin-bottom: 1rem;
  list-style: none;
}
.stats {
  font-size: 15px;
}
</style>
