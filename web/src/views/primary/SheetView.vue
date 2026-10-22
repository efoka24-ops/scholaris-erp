<script setup lang="ts">
// Feuille de compétences de la classe pour une période (maquette « carnet de suivi ») : un niveau de l'échelle de l'école par élève et
// par compétence. Fonctionne hors ligne : la saisie est gardée sur l'appareil puis envoyée en lot (idempotent, jamais d'écrasement).
// La direction verrouille puis publie la feuille ; après verrouillage, une modification passe par une correction motivée approuvée.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { listPeriods, listStructures, type Period } from '@/api/admin';
import { primary } from '@/api/primary';
import { useContextStore } from '@/stores/context';
import { useI18n, type MessageKey } from '@/i18n';
import { P } from './access';
import { discardDraft, flushDraft, loadDraft, loadSheet, marksIndex, setCell, cellId, type DraftCell, type Sheet } from './offlineSheet';
import { currentYear, useAction, usePermissions } from './usePri';

const { t, locale } = useI18n();
const ctx = useContextStore();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const est = computed(() => ctx.establishmentId ?? '');
const classes = ref<{ id: string; name: string }[]>([]);
const periods = ref<Period[]>([]);
const classId = ref('');
const periodId = ref('');
const sheet = ref<Sheet | null>(null);
const fromCache = ref(false);
const draft = ref<Record<string, DraftCell>>({});
const notice = ref<'sent' | 'queued' | 'conflict' | null>(null);
const conflictMessage = ref('');
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const correction = ref<{ markId: string; code: string; reason: string } | null>(null);

const marks = computed(() => marksIndex(sheet.value?.marks ?? []));
const locked = computed(() => sheet.value !== null && sheet.value.status !== 'open');
const pending = computed(() => Object.keys(draft.value).length);
const canEnter = computed(() => can(...P.competencyEnter));
const canValidate = computed(() => can(...P.competencyValidate));
const label = (l: { label_fr: string; label_en: string | null }): string => (locale.value === 'en' && l.label_en ? l.label_en : l.label_fr);
const scaleLabel = (code: string | null): string => {
  const level = sheet.value?.scale.find((s) => s.code === code);
  return level ? (locale.value === 'en' ? level.label_en : level.label_fr) : '';
};
const domainsWithCompetencies = computed(() => {
  const s = sheet.value;
  if (!s) return [];
  return s.domains
    .map((d) => ({ domain: d, items: s.competencies.filter((c) => c.domain_id === d.id) }))
    .filter((g) => g.items.length > 0);
});
const current = (personId: string, competencyId: string): string | null => {
  const key = cellId(personId, competencyId);
  return draft.value[key]?.scale_code ?? marks.value[key]?.scale_code ?? null;
};

async function init(): Promise<void> {
  await run(async () => {
    const rows = (await listStructures()).filter((s) => s.kind === 'class');
    classes.value = rows.map((s) => ({ id: s.id, name: s.name }));
    classId.value = classes.value[0]?.id ?? '';
    const year = await currentYear();
    if (year) {
      periods.value = await listPeriods(year.id);
      const today = new Date().toISOString().slice(0, 10);
      const main = periods.value.filter((p) => p.kind !== 'sequence');
      periodId.value = (main.find((p) => p.starts_on <= today && today <= p.ends_on) ?? main[0] ?? periods.value[0])?.id ?? '';
    }
  });
}

async function open(): Promise<void> {
  notice.value = null;
  sheet.value = null;
  if (classId.value === '' || periodId.value === '') return;
  await run(async () => {
    const loaded = await loadSheet(est.value, classId.value, periodId.value);
    sheet.value = loaded.sheet;
    fromCache.value = loaded.fromCache;
    draft.value = await loadDraft(est.value, classId.value, periodId.value);
  });
}

async function choose(personId: string, competencyId: string, code: string): Promise<void> {
  if (!sheet.value || locked.value || !canEnter.value) return;
  draft.value = await setCell(est.value, classId.value, periodId.value, draft.value, marks.value[cellId(personId, competencyId)], personId, competencyId, code);
  notice.value = null;
}

async function send(): Promise<void> {
  const result = await run(() => flushDraft(est.value, classId.value, periodId.value));
  if (!result) return;
  if (result.conflict) {
    notice.value = 'conflict';
    conflictMessage.value = result.message ?? '';
    return;
  }
  notice.value = result.offline ? 'queued' : 'sent';
  if (!result.offline) await open();
}

async function reload(): Promise<void> {
  await discardDraft(est.value, classId.value, periodId.value);
  notice.value = null;
  await open();
}

async function lock(): Promise<void> {
  if (await run(() => primary.lockSheet(classId.value, periodId.value))) await open();
}
async function publish(): Promise<void> {
  if (await run(() => primary.publishSheet(classId.value, periodId.value))) await open();
}

function askCorrection(personId: string, competencyId: string): void {
  const mark = marks.value[cellId(personId, competencyId)];
  if (mark) correction.value = { markId: mark.id, code: sheet.value?.scale[0]?.code ?? '', reason: '' };
}
async function sendCorrection(): Promise<void> {
  const c = correction.value;
  if (!c) return;
  if (await run(() => primary.requestCorrection(c.markId, c.code, c.reason.trim()))) correction.value = null;
}

const onOnline = (): void => {
  online.value = true;
  if (pending.value > 0) void send();
};
const onOffline = (): void => {
  online.value = false;
};

watch([classId, periodId], () => void open());
onMounted(async () => {
  window.addEventListener('online', onOnline);
  window.addEventListener('offline', onOffline);
  await init();
  await open();
});
onUnmounted(() => {
  window.removeEventListener('online', onOnline);
  window.removeEventListener('offline', onOffline);
});

const periodName = (p: Period): string => `${t(`pri.sheet.kind.${p.kind}` as MessageKey)} ${p.ordinal}`;
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.sheet.title') }}</h1>
      <p class="lede">{{ t('pri.sheet.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card">
      <div class="grid2">
        <div class="field">
          <label for="sh-class">{{ t('pri.call.class') }}</label>
          <select id="sh-class" v-model="classId">
            <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <div class="field">
          <label for="sh-period">{{ t('pri.sheet.period') }}</label>
          <select id="sh-period" v-model="periodId">
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ periodName(p) }}</option>
          </select>
        </div>
      </div>
      <p v-if="!online" class="tag tag--warning">{{ t('pri.sheet.offline') }}</p>
      <p v-if="fromCache" class="muted">{{ t('pri.sheet.cached') }}</p>
      <p v-if="sheet" class="muted">
        <span class="tag" :class="locked ? 'tag--neutral' : 'tag--success'">{{ t(`pri.sheet.status.${sheet.status}` as MessageKey) }}</span>
      </p>
    </section>

    <p v-if="notice === 'sent'" class="notice" role="status">{{ t('pri.sheet.sent') }}</p>
    <p v-if="notice === 'queued'" class="notice" role="status">{{ t('pri.sheet.queued', { count: pending }) }}</p>
    <div v-if="notice === 'conflict'" class="notice notice--error" role="alert">
      <p>{{ t('pri.sheet.conflict') }} {{ conflictMessage }}</p>
      <button type="button" class="btn" :disabled="busy" @click="reload">{{ t('pri.sheet.reload') }}</button>
    </div>

    <template v-if="sheet">
      <section v-if="sheet.competencies.length === 0" class="card"><p class="muted">{{ t('pri.sheet.noCompetencies') }}</p></section>
      <section v-else-if="sheet.learners.length === 0" class="card"><p class="muted">{{ t('pri.sheet.noLearners') }}</p></section>
      <template v-else>
      <section v-for="l in sheet.learners" :key="l.person_id" class="card" :aria-label="l.name">
        <h2>{{ l.name }}</h2>
        <div v-for="g in domainsWithCompetencies" :key="g.domain.id" class="domain">
          <h3>{{ label(g.domain) }}</h3>
          <div v-for="c in g.items" :key="c.id" class="comp">
            <p class="comp-name">
              <strong>{{ c.code }}</strong> · {{ label(c) }}
              <span v-if="draft[cellId(l.person_id, c.id)]" class="tag tag--warning">{{ t('pri.sheet.draft') }}</span>
            </p>
            <div class="levels" role="group" :aria-label="label(c)">
              <button
                v-for="s in sheet.scale"
                :key="s.code"
                type="button"
                class="btn level"
                :class="{ 'btn--primary': current(l.person_id, c.id) === s.code }"
                :aria-pressed="current(l.person_id, c.id) === s.code"
                :disabled="locked || !canEnter"
                @click="choose(l.person_id, c.id, s.code)"
              >{{ locale === 'en' ? s.label_en : s.label_fr }}</button>
              <button v-if="locked && canEnter && marks[cellId(l.person_id, c.id)]" type="button" class="btn btn--quiet" @click="askCorrection(l.person_id, c.id)">{{ t('pri.sheet.correct') }}</button>
            </div>
            <p v-if="locked && marks[cellId(l.person_id, c.id)]" class="muted">{{ scaleLabel(marks[cellId(l.person_id, c.id)]!.scale_code) }}</p>
          </div>
        </div>
      </section>
      </template>

      <section v-if="correction" class="card" aria-labelledby="sh-corr">
        <h2 id="sh-corr">{{ t('pri.sheet.correctTitle') }}</h2>
        <form class="grid2" @submit.prevent="sendCorrection">
          <div class="field">
            <label for="co-code">{{ t('pri.sheet.newLevel') }}</label>
            <select id="co-code" v-model="correction.code">
              <option v-for="s in sheet.scale" :key="s.code" :value="s.code">{{ locale === 'en' ? s.label_en : s.label_fr }}</option>
            </select>
          </div>
          <div class="field"><label for="co-reason">{{ t('pri.sheet.reason') }}</label><input id="co-reason" v-model="correction.reason" type="text" maxlength="255" required /></div>
          <div class="row">
            <button type="submit" class="btn btn--primary" :disabled="busy || correction.reason.trim() === ''">{{ t('pri.sheet.sendCorrection') }}</button>
            <button type="button" class="btn btn--quiet" @click="correction = null">{{ t('pri.sheet.cancel') }}</button>
          </div>
        </form>
      </section>

      <div class="actions">
        <button v-if="!locked && canEnter" type="button" class="btn btn--primary" :disabled="busy || pending === 0" @click="send">{{ t('pri.sheet.send', { count: pending }) }}</button>
        <button v-if="!locked && canValidate" type="button" class="btn" :disabled="busy || pending > 0" @click="lock">{{ t('pri.sheet.lock') }}</button>
        <button v-if="locked && sheet.status !== 'published' && canValidate" type="button" class="btn" :disabled="busy" @click="publish">{{ t('pri.sheet.publish') }}</button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
.domain { margin-top: 12px; }
.comp { padding: 8px 0; border-top: 1px solid var(--border, #dcdcd8); }
.comp-name { margin: 0 0 6px; }
.levels { display: flex; flex-wrap: wrap; gap: 6px; }
.level { min-height: 2.75rem; }
.actions { display: flex; gap: 12px; flex-wrap: wrap; position: sticky; bottom: 0; padding: 12px 0; background: var(--paper, #fff); }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
