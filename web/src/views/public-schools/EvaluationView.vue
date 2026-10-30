<script setup lang="ts">
// Saisie des critères (EX-EVA-03, API-06) sur téléphone et hors ligne : une discipline à la fois, une ligne par élève, une case par
// critère et par occasion (un appui : réussi, deux : échoué, trois : absent). La saisie est gardée chiffrée sur l'appareil et envoyée
// dès que le réseau est là ; les mentions (A, ECA, NA selon l'échelle paramétrée) sont calculées par le serveur.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicPrimary, type ClassRow, type Grid, type LearningUnit, type Outcome } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { useOfflineStore } from '@/stores/offline';
import VaultGate from './VaultGate.vue';
import { cellKey, flushChecks, listChecksQueue, loadDraft, loadGrid, nextOutcome, saveDraft, submitChecks, discardChecks, type Draft, type QueuedChecks } from './offlineChecks';
import { loadClasses } from './offlineCalls';
import { currentYear, useAction } from './usePs';
import { sealGet, sealPut } from './vault';

const { t, locale } = useI18n();
const ctx = useContextStore();
const offline = useOfflineStore();
const { error, busy, run } = useAction();

const est = computed(() => ctx.establishmentId ?? '');
const yearLabel = ref('');
const classes = ref<ClassRow[]>([]);
const classId = ref('');
const units = ref<LearningUnit[]>([]);
const unitId = ref('');
const grid = ref<Grid | null>(null);
const fromCache = ref(false);
const frameId = ref('');
const draft = ref<Draft>({});
const queue = ref<QueuedChecks[]>([]);
const outcome = ref<'sent' | 'queued' | null>(null);
const announce = ref('');

const selectedClass = computed(() => classes.value.find((c) => c.structure_id === classId.value) ?? null);
const frame = computed(() => grid.value?.frames.find((f) => f.id === frameId.value) ?? null);
const occasions = computed(() => Array.from({ length: grid.value?.occasions ?? 0 }, (_, i) => i + 1));
const changes = computed(() => Object.keys(draft.value).length);
const label = (o: { label_fr: string; label_en: string }): string => (locale.value === 'en' ? o.label_en : o.label_fr);

function outcomeOf(personId: string, criterionId: string, occasion: number): Outcome | undefined {
  const key = cellKey(personId, criterionId, occasion);
  return draft.value[key] ?? grid.value?.cells.find((c) => c.person_id === personId && c.criterion_id === criterionId && c.occasion_no === occasion)?.outcome;
}
const symbol = (o: Outcome | undefined): string => (o === 'success' ? '1' : o === 'fail' ? '0' : o === 'absent' ? 'abs' : '·');
const outcomeLabel = (o: Outcome | undefined): string => t(o === 'success' ? 'pp.eval.success' : o === 'fail' ? 'pp.eval.fail' : o === 'absent' ? 'pp.eval.absent' : 'pp.eval.empty');
function mentionOf(personId: string): string {
  const m = grid.value?.mentions.find((x) => x.person_id === personId && x.frame_id === frameId.value);
  if (!m) return '—';
  const level = grid.value?.scale.find((s) => s.code === m.mention);
  return m.mention === 'not_assessed' ? t('pp.eval.notAssessed') : level ? `${m.mention} · ${label(level)}` : m.mention;
}
const frozen = (personId: string): boolean => grid.value?.frozen.includes(personId) === true;

onMounted(async () => {
  queue.value = await listChecksQueue().catch(() => []);
  await run(async () => {
    let year: { id: string; label: string } | null;
    try {
      const y = await currentYear();
      year = y ? { id: y.id, label: y.label } : null;
      if (year) await sealPut('eval-year', year);
    } catch {
      year = (await sealGet<{ id: string; label: string }>('eval-year')) ?? null;
    }
    if (!year) return;
    yearLabel.value = year.label;
    const res = await loadClasses(est.value, year.id);
    classes.value = res.classes;
    fromCache.value = res.fromCache;
  });
});

async function openClass(): Promise<void> {
  grid.value = null;
  units.value = [];
  unitId.value = '';
  const level = selectedClass.value?.level_codes[0];
  if (!level || !yearLabel.value) return;
  await run(async () => {
    const name = `units:${yearLabel.value}:${level}`;
    try {
      units.value = await publicPrimary.units(yearLabel.value, level);
      await sealPut(name, units.value);
    } catch {
      units.value = (await sealGet<LearningUnit[]>(name)) ?? [];
    }
  });
}

async function openUnit(): Promise<void> {
  grid.value = null;
  outcome.value = null;
  if (!unitId.value || !classId.value) return;
  await run(async () => {
    const res = await loadGrid(est.value, unitId.value, classId.value);
    grid.value = res.grid;
    fromCache.value = res.fromCache;
    frameId.value = grid.value?.frames[0]?.id ?? '';
    draft.value = await loadDraft(est.value, unitId.value, classId.value);
  });
}

async function tap(personId: string, criterionId: string, occasion: number, pupilName: string, code: string): Promise<void> {
  if (frozen(personId)) return;
  const next = nextOutcome(outcomeOf(personId, criterionId, occasion));
  draft.value = { ...draft.value, [cellKey(personId, criterionId, occasion)]: next };
  announce.value = `${pupilName}, ${code} ${t('pp.eval.occasion', { n: occasion })} : ${outcomeLabel(next)}`;
  await saveDraft(est.value, unitId.value, classId.value, draft.value);
}

async function validate(): Promise<void> {
  if (!grid.value || changes.value === 0) return;
  await run(async () => {
    await submitChecks(est.value, grid.value as Grid, draft.value);
    draft.value = {};
    const report = offline.online ? await flushChecks() : null;
    outcome.value = report && report.sent > 0 && !report.offline ? 'sent' : 'queued';
    queue.value = await listChecksQueue();
    if (report && report.sent > 0) await openUnit();
  });
}

async function retry(): Promise<void> {
  await run(async () => {
    await flushChecks();
    queue.value = await listChecksQueue();
  });
}

async function discard(uuid: string): Promise<void> {
  await discardChecks(uuid);
  queue.value = await listChecksQueue();
}
</script>

<template>
  <div class="page pp-eval">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('pp.eval.title') }}</h1>
      <p class="lede">{{ t('pp.eval.intro') }}</p>
    </header>
    <VaultGate>
      <ErrorNotice :error="error" />
      <p v-if="!offline.online" class="notice notice--warning" role="status">{{ t('pp.eval.offline') }}</p>
      <p v-if="fromCache" class="muted" role="status">{{ t('ps.call.fromCache') }}</p>

      <section class="card controls" :aria-label="t('pp.eval.choose')">
        <div class="field">
          <label for="pp-class">{{ t('pp.common.class') }}</label>
          <select id="pp-class" v-model="classId" @change="openClass">
            <option value="">—</option>
            <option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option>
          </select>
        </div>
        <div class="field">
          <label for="pp-unit">{{ t('pp.common.unit') }}</label>
          <select id="pp-unit" v-model="unitId" :disabled="units.length === 0" @change="openUnit">
            <option value="">—</option>
            <option v-for="u in units" :key="u.id" :value="u.id">{{ t('pp.common.unitLabel', { n: u.unit_no }) }} · {{ formatDate(u.starts_on) }} – {{ formatDate(u.ends_on) }}</option>
          </select>
        </div>
        <p v-if="classId && units.length === 0" class="muted">{{ t('pp.eval.noUnits') }}</p>
        <div v-if="grid" class="field">
          <label for="pp-frame">{{ t('pp.common.discipline') }}</label>
          <select id="pp-frame" v-model="frameId">
            <option v-for="f in grid.frames" :key="f.id" :value="f.id">{{ label(f) }}</option>
          </select>
        </div>
      </section>

      <section v-if="grid && frame" class="card" :aria-label="t('pp.eval.grid')">
        <p class="muted">{{ t('pp.eval.hint', { min: grid.mastery_min_success, total: grid.occasions }) }}</p>
        <ul class="criteria-legend">
          <li v-for="c in frame.criteria" :key="c.id"><strong>{{ c.code }}</strong> {{ label(c) }}<span v-if="c.kind === 'perfection'" class="tag">{{ t('pp.eval.perfection') }}</span></li>
        </ul>
        <ul class="pupils">
          <li v-for="p in grid.pupils" :key="p.person_id" class="pupil">
            <div class="pupil__head">
              <strong>{{ p.name }}</strong>
              <span class="tag" :aria-label="t('pp.eval.mention')">{{ mentionOf(p.person_id) }}</span>
              <span v-if="frozen(p.person_id)" class="tag tag--warning">{{ t('pp.eval.frozen') }}</span>
            </div>
            <div v-for="c in frame.criteria" :key="c.id" class="crit">
              <span class="crit__code">{{ c.code }}</span>
              <button
                v-for="o in occasions"
                :key="o"
                type="button"
                class="cell"
                :class="`cell--${outcomeOf(p.person_id, c.id, o) ?? 'empty'}`"
                :disabled="frozen(p.person_id) || busy"
                :aria-label="`${p.name}, ${c.code}, ${t('pp.eval.occasion', { n: o })} : ${outcomeLabel(outcomeOf(p.person_id, c.id, o))}`"
                @click="tap(p.person_id, c.id, o, p.name, c.code)"
              >
{{ symbol(outcomeOf(p.person_id, c.id, o)) }}
</button>
            </div>
          </li>
        </ul>
      </section>

      <div v-if="grid" class="bar" role="region" :aria-label="t('pp.eval.summary')">
        <p class="bar__counts" aria-live="polite">{{ t('pp.eval.changes', { count: changes }) }}</p>
        <button type="button" class="btn btn--primary btn--large" :disabled="busy || changes === 0" @click="validate">{{ t('pp.eval.validate') }}</button>
      </div>
      <p v-if="outcome === 'sent'" class="notice notice--success" role="status">{{ t('pp.eval.sent') }}</p>
      <p v-else-if="outcome === 'queued'" class="notice notice--success" role="status">{{ t('pp.eval.queued') }}</p>
      <p class="sr-only" aria-live="polite">{{ announce }}</p>

      <section v-if="queue.length > 0" class="card" :aria-label="t('pp.eval.queue')">
        <h2>{{ t('pp.eval.queue') }}</h2>
        <ul>
          <li v-for="q in queue" :key="q.client_uuid">
            {{ q.class_label }} · {{ t('pp.common.unitLabel', { n: q.unit_no }) }} · {{ t(q.status === 'pending' ? 'pp.eval.status.pending' : q.status === 'conflict' ? 'pp.eval.status.conflict' : 'pp.eval.status.error') }}
            <span v-if="q.message" class="muted"> — {{ q.message }}</span>
            <button v-if="q.status !== 'pending'" type="button" class="btn btn--small" @click="discard(q.client_uuid)">{{ t('pp.eval.discard') }}</button>
          </li>
        </ul>
        <button type="button" class="btn" :disabled="busy || !offline.online" @click="retry">{{ t('pp.eval.retry') }}</button>
      </section>
    </VaultGate>
  </div>
</template>

<style scoped>
.pp-eval { max-width: 760px; padding-bottom: 7rem; }
.controls { display: grid; gap: 8px; }
.criteria-legend { list-style: none; padding: 0; margin: 0 0 12px; display: grid; gap: 4px; }
.pupils { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.pupil { border: 1px solid var(--input-border); padding: 8px; }
.pupil__head { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 6px; }
.crit { display: flex; align-items: center; gap: 6px; margin: 4px 0; }
.crit__code { width: 2.5rem; font-weight: 700; }
.cell { min-width: 48px; min-height: 44px; font: 700 15px var(--font); border: 1px solid var(--input-border); background: var(--surface); color: var(--ink); cursor: pointer; }
.cell--success { border: 2px solid var(--success, var(--ink)); }
.cell--fail { border: 2px solid var(--warning); }
.cell--absent { border: 2px dashed var(--ink); }
.bar { position: fixed; left: 0; right: 0; bottom: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; padding: 10px 16px; background: var(--surface); border-top: 2px solid var(--ink); z-index: 5; }
.bar__counts { margin: 0; font-weight: 700; flex: 1 1 12rem; }
@media (min-width: 900px) { .bar { position: sticky; margin-top: 12px; } }
</style>
