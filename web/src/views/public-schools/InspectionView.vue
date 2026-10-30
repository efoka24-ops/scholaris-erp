<script setup lang="ts">
// Inspection et suivi pédagogique (INS) : planning des visites, grille paramétrable remplie sur téléphone même sans réseau (coffre
// chiffré, rejeu idempotent, conflits conservés), comptage sur place comparé au registre (O1), recommandations suivies à la visite
// suivante, rapport DOC-26 transmis au directeur, signalement d'un frais exigé illégalement. Le directeur et l'enseignant lisent
// leurs rapports ; les grilles se règlent au niveau national.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  openDocument,
  publicInspection,
  publicSchools,
  type ClassRow,
  type FeeReport,
  type Recommendation,
  type ScaleCode,
  type Visit,
  type VisitGrid,
} from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import VaultGate from './VaultGate.vue';
import { discardVisit, flushVisits, listVisitQueue, loadGrids, queueReport, type QueuedVisit } from './offlineVisits';
import { currentYear, useAction } from './usePs';

const { t, locale } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const est = computed(() => ctx.establishmentId ?? '');
const canWrite = computed(() => ctx.can('public_schools.inspection.write'));
const canManage = computed(() => ctx.can('public_schools.inspection.manage'));
const lang = computed(() => (locale.value === 'en' ? 'en' : 'fr'));

const grids = ref<VisitGrid[]>([]);
const scale = ref<ScaleCode[]>([]);
const classes = ref<ClassRow[]>([]);
const visits = ref<Visit[]>([]);
const openRecs = ref<Recommendation[]>([]);
const fees = ref<FeeReport[]>([]);
const queue = ref<QueuedVisit[]>([]);
const fromCache = ref(false);
const done = ref('');
const plan = ref({ planned_on: todayIso(), structure_id: '', grid_id: '' });
const current = ref<Visit | null>(null);
const answers = ref<Record<string, string>>({});
const counted = ref<number | null>(null);
const observations = ref('');
const newRecs = ref('');
const followups = ref<Record<string, '' | 'in_progress' | 'done' | 'not_done'>>({});
const fee = ref({ reported_on: todayIso(), reporter_kind: 'parent', fee_label: '', amount: null as number | null, description: '' });

const grid = computed(() => grids.value.find((g) => g.id === (current.value?.grid_id ?? '')) ?? null);
const scaleLabel = (s: ScaleCode): string => (locale.value === 'en' ? s.label_en : s.label_fr);
const itemLabel = (i: { label_fr: string; label_en: string }): string => (locale.value === 'en' ? i.label_en : i.label_fr);

onMounted(async () => {
  await run(async () => {
    const loaded = await loadGrids();
    grids.value = loaded.grids;
    fromCache.value = loaded.fromCache;
    if (!loaded.fromCache) {
      scale.value = (await publicInspection.scale()).scale;
      const year = await currentYear();
      if (year) classes.value = await publicSchools.classes(year.id);
    }
  });
  await refresh();
});

async function refresh(): Promise<void> {
  queue.value = await listVisitQueue();
  if (fromCache.value) return;
  await run(async () => {
    visits.value = await publicInspection.visits();
    openRecs.value = await publicInspection.recommendations('open');
    if (canWrite.value) fees.value = await publicInspection.fees();
  });
}

async function importExample(): Promise<void> {
  await run(async () => {
    await publicInspection.importExample();
    grids.value = (await loadGrids()).grids;
  });
}

async function planVisit(): Promise<void> {
  await run(async () => {
    await publicInspection.plan({ planned_on: plan.value.planned_on, ...(plan.value.structure_id ? { structure_id: plan.value.structure_id } : {}), ...(plan.value.grid_id ? { grid_id: plan.value.grid_id } : {}) });
    done.value = t('ps.ins.planned');
  });
  await refresh();
}

function start(v: Visit): void {
  current.value = v;
  answers.value = { ...v.answers };
  counted.value = v.counted_present;
  observations.value = v.observations ?? '';
  newRecs.value = '';
  followups.value = {};
}

async function saveReport(): Promise<void> {
  if (!current.value) return;
  const body = {
    done_on: todayIso(),
    ...(current.value.grid_id ? { grid_id: current.value.grid_id } : {}),
    answers: answers.value,
    ...(counted.value !== null ? { counted_present: counted.value } : {}),
    ...(observations.value.trim() ? { observations: observations.value.trim() } : {}),
    recommendations: newRecs.value.split('\n').map((l) => l.trim()).filter((l) => l !== ''),
    followups: Object.entries(followups.value)
      .filter(([, state]) => state !== '')
      .map(([id, state]) => ({ id, state: state as 'in_progress' | 'done' | 'not_done' })),
  };
  await run(async () => {
    await queueReport(est.value, current.value?.id ?? '', current.value?.class_name || formatDate(current.value?.planned_on ?? ''), body);
    const report = await flushVisits();
    done.value = report.offline ? t('ps.ins.queued') : t('ps.ins.sent');
    current.value = null;
  });
  await refresh();
}

async function retry(): Promise<void> {
  await run(async () => {
    const report = await flushVisits();
    done.value = report.offline ? t('ps.ins.queued') : t('ps.ins.sent');
  });
  await refresh();
}

async function discard(item: QueuedVisit): Promise<void> {
  await discardVisit(item.client_uuid);
  await refresh();
}

async function report(v: Visit): Promise<void> {
  await run(async () => openDocument((await publicInspection.document(v.id, lang.value)).document.id));
}

async function reportFee(): Promise<void> {
  await run(async () => {
    const { amount, ...rest } = fee.value;
    await publicInspection.reportFee({ ...rest, ...(amount === null || Number.isNaN(amount) ? {} : { amount }) });
    done.value = t('ps.ins.feeReported');
  });
  await refresh();
}

async function handleFee(f: FeeReport, state: string): Promise<void> {
  const resolution = window.prompt(t('ps.ins.resolution')) ?? '';
  if (resolution.trim() === '') return;
  await run(() => publicInspection.handleFee(f.id, state, resolution));
  await refresh();
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.ins.title') }}</h1>
      <p class="lede">{{ t('ps.ins.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>
    <p v-if="fromCache" class="notice">{{ t('ps.ins.offline') }}</p>

    <VaultGate>
      <section v-if="queue.length > 0" class="card" aria-labelledby="in-queue">
        <h2 id="in-queue">{{ t('ps.ins.queue', { n: queue.length }) }}</h2>
        <ul class="rows">
          <li v-for="q in queue" :key="q.client_uuid" class="row">
            {{ q.label }} · <span class="tag">{{ q.status }}</span> <span v-if="q.message" class="muted">{{ q.message }}</span>
            <button v-if="q.status !== 'pending'" type="button" class="btn btn--small" @click="discard(q)">{{ t('ps.ins.discard') }}</button>
          </li>
        </ul>
        <button type="button" class="btn" :disabled="busy" @click="retry">{{ t('ps.ins.retry') }}</button>
      </section>

      <section v-if="current" class="card controls" aria-labelledby="in-report">
        <h2 id="in-report">{{ t('ps.ins.report') }} · {{ current.class_name || formatDate(current.planned_on) }}</h2>
        <fieldset v-for="item in grid?.items ?? []" :key="item.code" class="item">
          <legend>{{ itemLabel(item) }} <span class="muted">({{ item.section }})</span></legend>
          <div class="actions">
            <label v-for="s in scale" :key="s.code" class="choice">
              <input v-model="answers[item.code]" type="radio" :name="`in-${item.code}`" :value="s.code" />
              {{ scaleLabel(s) }}
            </label>
          </div>
        </fieldset>
        <p v-if="!grid" class="muted">{{ t('ps.ins.noGrid') }}</p>
        <div class="field">
          <label for="in-counted">{{ t('ps.ins.counted') }}</label>
          <input id="in-counted" v-model.number="counted" type="number" min="0" inputmode="numeric" />
        </div>
        <div class="field">
          <label for="in-obs">{{ t('ps.ins.observations') }}</label>
          <textarea id="in-obs" v-model="observations" rows="3" maxlength="2000"></textarea>
        </div>
        <div v-if="openRecs.length > 0">
          <h3>{{ t('ps.ins.followups') }}</h3>
          <div v-for="r in openRecs" :key="r.id" class="field">
            <label :for="`fu-${r.id}`">{{ r.label }}</label>
            <select :id="`fu-${r.id}`" v-model="followups[r.id]">
              <option value="">—</option>
              <option value="in_progress">{{ t('ps.ins.inProgress') }}</option>
              <option value="done">{{ t('ps.ins.doneState') }}</option>
              <option value="not_done">{{ t('ps.ins.notDone') }}</option>
            </select>
          </div>
        </div>
        <div class="field">
          <label for="in-recs">{{ t('ps.ins.recommendations') }}</label>
          <textarea id="in-recs" v-model="newRecs" rows="3"></textarea>
        </div>
        <div class="actions">
          <button type="button" class="btn btn--primary" :disabled="busy" @click="saveReport">{{ t('ps.ins.save') }}</button>
          <button type="button" class="btn" @click="current = null">{{ t('ps.ins.cancel') }}</button>
        </div>
      </section>
    </VaultGate>

    <section v-if="canManage" class="card controls">
      <h2>{{ t('ps.ins.grids') }}</h2>
      <ul class="rows">
        <li v-for="g in grids" :key="g.id" class="row">{{ itemLabel(g) }} · v{{ g.version }} · {{ t('ps.ins.items', { n: g.items.length }) }}</li>
      </ul>
      <button type="button" class="btn" :disabled="busy" @click="importExample">{{ t('ps.ins.importExample') }}</button>
    </section>

    <form v-if="canWrite && !fromCache" class="card controls" @submit.prevent="planVisit">
      <h2>{{ t('ps.ins.plan') }}</h2>
      <div class="field"><label for="in-date">{{ t('pp.common.date') }}</label><input id="in-date" v-model="plan.planned_on" type="date" required /></div>
      <div class="field">
        <label for="in-class">{{ t('pp.common.class') }}</label>
        <select id="in-class" v-model="plan.structure_id">
          <option value="">—</option>
          <option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option>
        </select>
      </div>
      <div class="field">
        <label for="in-grid">{{ t('ps.ins.grid') }}</label>
        <select id="in-grid" v-model="plan.grid_id">
          <option value="">—</option>
          <option v-for="g in grids" :key="g.id" :value="g.id">{{ itemLabel(g) }} v{{ g.version }}</option>
        </select>
      </div>
      <button type="submit" class="btn" :disabled="busy">{{ t('ps.ins.planButton') }}</button>
    </form>

    <section class="card" aria-labelledby="in-visits">
      <h2 id="in-visits">{{ t('ps.ins.visits') }}</h2>
      <p v-if="visits.length === 0" class="muted">{{ t('ps.ins.noVisits') }}</p>
      <ul class="rows">
        <li v-for="v in visits" :key="v.id" class="row">
          <p>
            <strong>{{ formatDate(v.done_on ?? v.planned_on) }}</strong> · {{ v.class_name || '—' }} · {{ v.inspector_name }}
            · <span class="tag">{{ v.state === 'done' ? t('ps.ins.stateDone') : t('ps.ins.statePlanned') }}</span>
          </p>
          <p v-if="v.counted_present !== null" class="muted">{{ t('ps.ins.headcount', { counted: v.counted_present, registry: v.registry_count ?? '—', gap: v.headcount_gap_rate ?? '—' }) }}</p>
          <ul v-if="v.recommendations.length > 0">
            <li v-for="r in v.recommendations" :key="r.id">{{ r.label }} · {{ r.state }}</li>
          </ul>
          <div class="actions">
            <button v-if="canWrite && v.state === 'planned'" type="button" class="btn btn--small" @click="start(v)">{{ t('ps.ins.fill') }}</button>
            <button v-if="canWrite && v.state === 'done'" type="button" class="btn btn--small" :disabled="busy" @click="report(v)">{{ t('ps.ins.doc26') }}</button>
            <button v-if="!canWrite && v.report_document_id && ctx.can('documents.read')" type="button" class="btn btn--small" :disabled="busy" @click="openDocument(v.report_document_id)">{{ t('ps.ins.doc26') }}</button>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="canWrite && !fromCache" class="card controls" aria-labelledby="in-fees">
      <h2 id="in-fees">{{ t('ps.ins.fees') }}</h2>
      <p class="muted">{{ t('ps.ins.feesHelp') }}</p>
      <ul class="rows">
        <li v-for="f in fees" :key="f.id" class="row">
          {{ formatDate(f.reported_on) }} · {{ f.fee_label }} · <span class="tag">{{ f.state }}</span> <span v-if="f.resolution" class="muted">{{ f.resolution }}</span>
          <div v-if="f.state === 'open' || f.state === 'investigating'" class="actions">
            <button type="button" class="btn btn--small" @click="handleFee(f, 'investigating')">{{ t('ps.ins.investigate') }}</button>
            <button type="button" class="btn btn--small" @click="handleFee(f, 'confirmed')">{{ t('ps.ins.confirm') }}</button>
            <button type="button" class="btn btn--small" @click="handleFee(f, 'dismissed')">{{ t('ps.ins.dismiss') }}</button>
          </div>
        </li>
      </ul>
      <form class="controls" @submit.prevent="reportFee">
        <div class="field"><label for="fe-date">{{ t('pp.common.date') }}</label><input id="fe-date" v-model="fee.reported_on" type="date" required /></div>
        <div class="field">
          <label for="fe-kind">{{ t('ps.ins.reporter') }}</label>
          <select id="fe-kind" v-model="fee.reporter_kind">
            <option value="parent">{{ t('ps.ins.reporterParent') }}</option>
            <option value="inspection">{{ t('ps.ins.reporterInspection') }}</option>
            <option value="teacher">{{ t('ps.ins.reporterTeacher') }}</option>
            <option value="other">{{ t('ps.ins.reporterOther') }}</option>
          </select>
        </div>
        <div class="field"><label for="fe-label">{{ t('ps.ins.feeLabel') }}</label><input id="fe-label" v-model="fee.fee_label" type="text" required maxlength="160" /></div>
        <div class="field"><label for="fe-amount">{{ t('ps.ins.amount') }}</label><input id="fe-amount" v-model.number="fee.amount" type="number" min="0" /></div>
        <div class="field"><label for="fe-desc">{{ t('ps.ins.description') }}</label><textarea id="fe-desc" v-model="fee.description" rows="3" required maxlength="1000"></textarea></div>
        <button type="submit" class="btn" :disabled="busy">{{ t('ps.ins.reportFee') }}</button>
      </form>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.row { border: 1px solid var(--input-border); padding: 8px; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.item { border: 1px solid var(--input-border); padding: 6px; }
.choice { display: inline-flex; align-items: center; gap: 4px; min-height: 44px; }
textarea { width: 100%; }
</style>
