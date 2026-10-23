<script setup lang="ts">
// Appel par demi-journée (matin / après-midi), même sans réseau : P / A / R par élève, brouillon gardé sur l'appareil, envoi
// idempotent dès que le réseau revient. Maquette « Appel » : un geste par élève, « tous présents » puis exceptions.
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { listStructures } from '@/api/admin';
import type { AttendanceStatus } from '@/api/types';
import { clearStatus, loadRoster, saveDraft, setStatus, type Draft, type RosterEntry } from '@/offline/attendance';
import { useOfflineStore } from '@/stores/offline';
import { useContextStore } from '@/stores/context';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDate, nowHhmm, todayIso } from '@/lib/format';
import { cachedClasses, counters, DAY_PARTS, loadPartDraft, queuedPart, rememberClasses, submitPartCall, type DayPart } from './offlineCall';

const { t, locale } = useI18n();
const ctx = useContextStore();
const offline = useOfflineStore();

const date = todayIso();
const est = computed(() => ctx.establishmentId ?? '');
const classes = ref<{ id: string; name: string }[]>([]);
const classId = ref('');
const part = ref<DayPart>(new Date().getHours() < 12 ? 'morning' : 'afternoon');
const roster = ref<RosterEntry[]>([]);
const draft = ref<Draft | null>(null);
const fromCache = ref(false);
const queued = ref(false);
const result = ref<'sent' | 'queued' | null>(null);
const error = ref<unknown>(null);
const submitting = ref(false);

const stats = computed(() => (draft.value ? counters(draft.value) : { present: 0, absent: 0, late: 0, recorded: 0 }));
const complete = computed(() => roster.value.length > 0 && stats.value.recorded === roster.value.length);
const className = computed(() => classes.value.find((c) => c.id === classId.value)?.name ?? '');

async function loadClasses(): Promise<void> {
  try {
    const rows = (await listStructures()).filter((s) => s.kind === 'class').map((s) => ({ id: s.id, name: s.name }));
    classes.value = rows;
    await rememberClasses(est.value, rows);
  } catch (e) {
    const cached = await cachedClasses(est.value);
    if (cached) classes.value = cached;
    else error.value = e;
  }
  if (classId.value === '' && classes.value.length > 0) classId.value = classes.value[0]!.id;
}

async function openClass(): Promise<void> {
  result.value = null;
  roster.value = [];
  draft.value = null;
  if (classId.value === '') return;
  try {
    const r = await loadRoster(est.value, classId.value);
    roster.value = r.roster;
    fromCache.value = r.fromCache;
    draft.value = await loadPartDraft(est.value, classId.value, part.value, date);
    queued.value = await queuedPart(classId.value, part.value, date);
  } catch (e) {
    error.value = e;
  }
}

async function persist(next: Draft): Promise<void> {
  draft.value = next;
  await saveDraft(est.value, next);
}

async function mark(personId: string, status: AttendanceStatus): Promise<void> {
  if (!draft.value) return;
  if (draft.value.lines[personId]?.status === status) return persist(clearStatus(draft.value, personId));
  return persist(setStatus(draft.value, personId, status, status === 'R' ? nowHhmm() : undefined));
}

async function allPresent(): Promise<void> {
  if (!draft.value) return;
  let next = draft.value;
  for (const r of roster.value) if (!next.lines[r.person_id]) next = setStatus(next, r.person_id, 'P');
  await persist(next);
}

async function validate(): Promise<void> {
  if (!draft.value || !complete.value) return;
  submitting.value = true;
  error.value = null;
  try {
    const label = `${className.value} · ${t(`pri.call.part.${part.value}` as MessageKey)} · ${formatDate(date, locale.value)}`;
    await submitPartCall(est.value, classId.value, part.value, draft.value, roster.value, label);
    const report = offline.online ? await offline.sync() : null;
    await offline.refresh();
    result.value = report && !report.offline && report.sent > 0 && offline.pending === 0 ? 'sent' : 'queued';
    draft.value = null;
    queued.value = true;
  } catch (e) {
    error.value = e;
  } finally {
    submitting.value = false;
  }
}

watch([classId, part], () => void openClass());
onMounted(async () => {
  await loadClasses();
  await openClass();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.call.title') }}</h1>
      <p class="lede">{{ t('pri.call.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card">
      <div class="grid2">
        <div class="field">
          <label for="cl-class">{{ t('pri.call.class') }}</label>
          <select id="cl-class" v-model="classId">
            <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <fieldset class="field parts">
          <legend>{{ t('pri.call.partLabel') }}</legend>
          <label v-for="p in DAY_PARTS" :key="p" class="check">
            <input v-model="part" type="radio" name="pri-part" :value="p" /> {{ t(`pri.call.part.${p}` as MessageKey) }}
          </label>
        </fieldset>
      </div>
      <p class="muted">{{ formatDate(date, locale) }}<span v-if="fromCache"> · {{ t('pri.call.cached') }}</span></p>
    </section>

    <p v-if="result" class="notice" role="status">{{ result === 'sent' ? t('pri.call.sent') : t('pri.call.queued') }}</p>
    <p v-else-if="queued" class="notice" role="status">{{ t('pri.call.alreadyQueued') }}</p>

    <section v-if="draft" class="card" aria-labelledby="cl-roster">
      <h2 id="cl-roster">{{ t('pri.call.roster', { count: roster.length }) }}</h2>
      <p class="counters" role="status">
        <span class="tag tag--success">{{ t('pri.call.present', { count: stats.present }) }}</span>
        <span class="tag tag--warning">{{ t('pri.call.late', { count: stats.late }) }}</span>
        <span class="tag tag--error">{{ t('pri.call.absent', { count: stats.absent }) }}</span>
      </p>
      <button type="button" class="btn btn--quiet" @click="allPresent">{{ t('pri.call.allPresent') }}</button>
      <ul class="list">
        <li v-for="r in roster" :key="r.person_id" class="row row--between">
          <span class="grow">{{ r.name }}</span>
          <span class="marks" role="group" :aria-label="r.name">
            <button v-for="s in (['P', 'R', 'A'] as const)" :key="s" type="button" class="btn mark" :class="{ 'btn--primary': draft.lines[r.person_id]?.status === s }" :aria-pressed="draft.lines[r.person_id]?.status === s" @click="mark(r.person_id, s)">{{ s }}</button>
          </span>
        </li>
      </ul>
      <button type="button" class="btn btn--primary" :disabled="!complete || submitting" @click="validate">{{ t('pri.call.validate', { done: stats.recorded, total: roster.length }) }}</button>
    </section>
  </div>
</template>

<style scoped>
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
.parts { border: 0; padding: 0; }
.list { list-style: none; margin: 12px 0; padding: 0; display: grid; gap: 8px; }
.list > li { padding: 8px 0; border-top: 1px solid var(--border, #dcdcd8); }
.grow { flex: 1; }
.marks { display: flex; gap: 6px; }
.mark { min-width: 3rem; min-height: 2.75rem; }
.counters { display: flex; gap: 8px; flex-wrap: wrap; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
