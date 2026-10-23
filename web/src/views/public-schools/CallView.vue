<script setup lang="ts">
// Appel par demi-journée (T644) : tous présents par défaut, un appui par absent, un second pour un retard. Fonctionne hors ligne :
// l'appel est écrit sur l'appareil (coffre chiffré) puis rejoué dès que le réseau revient. 60 élèves en moins d'une minute.
import { computed, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import type { AttendanceDay, ClassRow, DayPart, RosterPupil } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, nowHhmm, todayIso } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { useOfflineStore } from '@/stores/offline';
import PendingCalls from './PendingCalls.vue';
import VaultGate from './VaultGate.vue';
import { counters, cycle, loadClasses, loadDay, loadDraft, loadRoster, saveDraft, setArrival, submitCall, type Draft } from './offlineCalls';
import { useAction, currentYear, fullName } from './usePs';
import { usePendingCalls } from './usePendingCalls';
import { sealGet, sealPut } from './vault';

const { t } = useI18n();
const ctx = useContextStore();
const offline = useOfflineStore();
const queue = usePendingCalls();
const { error, busy, run } = useAction();

const date = todayIso();
const dayPart = ref<DayPart>(new Date().getHours() < 12 ? 'morning' : 'afternoon');
const classes = ref<ClassRow[]>([]);
const classId = ref('');
const roster = ref<RosterPupil[]>([]);
const draft = ref<Draft | null>(null);
const day = ref<AttendanceDay | null>(null);
const fromCache = ref(false);
/** Liste de classe : `unavailable` = le serveur ne la rend pas (404/501) ; `offline` = pas de réseau et rien en cache ; `empty` = classe sans élève. */
const rosterProblem = ref<'unavailable' | 'offline' | 'empty' | null>(null);
const reason = ref('');
const outcome = ref<'sent' | 'queued' | null>(null);
const announce = ref('');

type State = 'present' | 'absent' | 'late';
const est = computed(() => ctx.establishmentId ?? '');
const selected = computed(() => classes.value.find((c) => c.structure_id === classId.value) ?? null);
const stats = computed(() => (draft.value ? counters(draft.value, roster.value.length) : { present: roster.value.length, absent: 0, late: 0 }));
const partTaken = computed(() => day.value?.parts.find((p) => p.day_part === dayPart.value)?.taken === true);
const needsReason = computed(() => partTaken.value && reason.value.trim() === '');
const pendingNow = computed(() => queue.items.value.filter((i) => i.status === 'pending').length);

function stateOf(p: RosterPupil): State {
  const s = draft.value?.exceptions[p.person_id]?.status;
  return s === 'A' ? 'absent' : s === 'R' ? 'late' : 'present';
}
function stateLabel(s: State): string {
  return t(s === 'absent' ? 'ps.call.absent' : s === 'late' ? 'ps.call.late' : 'ps.call.present');
}
const partLabel = (p: DayPart): string => t(p === 'morning' ? 'ps.call.morning' : 'ps.call.afternoon');

async function start(): Promise<void> {
  await queue.refresh();
  await run(async () => {
    let yearId: string | undefined;
    try {
      yearId = (await currentYear())?.id;
      if (yearId) await sealPut('year', yearId);
    } catch {
      yearId = await sealGet<string>('year');
    }
    if (!yearId) throw new Error('year');
    const res = await loadClasses(est.value, yearId);
    classes.value = res.classes;
    fromCache.value = res.fromCache;
    if (classes.value.length === 1 && classes.value[0]) classId.value = classes.value[0].structure_id;
    if (classId.value) await openClass();
  });
}

async function openClass(): Promise<void> {
  outcome.value = null;
  roster.value = [];
  draft.value = null;
  rosterProblem.value = null;
  if (!classId.value) return;
  await run(async () => {
    try {
      const res = await loadRoster(est.value, classId.value);
      roster.value = res.roster;
      fromCache.value = fromCache.value || res.fromCache;
    } catch (e) {
      if (e instanceof ApiError && (e.status === 404 || e.status === 501)) rosterProblem.value = 'unavailable';
      else if (e instanceof ApiError && e.network) rosterProblem.value = 'offline';
      else throw e;
      return;
    }
    if (roster.value.length === 0) {
      rosterProblem.value = 'empty';
      return;
    }
    draft.value = await loadDraft(est.value, classId.value, date, dayPart.value);
    day.value = offline.online ? await loadDay(est.value, classId.value, date) : null;
  });
}

async function changePart(): Promise<void> {
  outcome.value = null;
  if (!classId.value || roster.value.length === 0) return;
  draft.value = await loadDraft(est.value, classId.value, date, dayPart.value);
}

async function tap(pupil: RosterPupil): Promise<void> {
  if (!draft.value) return;
  draft.value = cycle(draft.value, pupil.person_id, nowHhmm());
  announce.value = `${fullName(pupil)} : ${stateLabel(stateOf(pupil))}`;
  await saveDraft(est.value, draft.value);
}

async function arrival(pupil: RosterPupil, event: Event): Promise<void> {
  if (!draft.value) return;
  draft.value = setArrival(draft.value, pupil.person_id, (event.target as HTMLInputElement).value);
  await saveDraft(est.value, draft.value);
}

async function validate(): Promise<void> {
  if (!draft.value || !selected.value) return;
  await run(async () => {
    await submitCall(est.value, draft.value as Draft, selected.value?.label ?? '', partTaken.value ? reason.value.trim() : undefined);
    const report = offline.online ? await queue.sync() : null;
    outcome.value = report && report.sent > 0 && !report.offline ? 'sent' : 'queued';
    draft.value = await loadDraft(est.value, classId.value, date, dayPart.value);
    reason.value = '';
    await queue.refresh();
    if (offline.online) day.value = await loadDay(est.value, classId.value, date);
  });
}
</script>

<template>
  <div class="page ps-call">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.call.title') }}</h1>
      <p class="lede">{{ formatDate(date) }}</p>
    </header>

    <VaultGate @unlocked="start">
      <ErrorNotice :error="error" />
      <p v-if="!offline.online" class="notice notice--warning" role="status">{{ t('ps.call.offlineNote') }}</p>
      <p v-if="fromCache" class="muted" role="status">{{ t('ps.call.fromCache') }}</p>
      <PendingCalls :queue="queue" />

      <section class="card controls" :aria-label="t('ps.call.choose')">
        <div class="field">
          <label for="ps-class">{{ t('ps.call.class') }}</label>
          <select id="ps-class" v-model="classId" @change="openClass">
            <option value="">{{ t('ps.call.pick') }}</option>
            <option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option>
          </select>
        </div>
        <fieldset class="field parts">
          <legend class="label">{{ t('ps.call.part') }}</legend>
          <label class="check"><input v-model="dayPart" type="radio" value="morning" name="ps-part" @change="changePart" />{{ partLabel('morning') }}</label>
          <label class="check"><input v-model="dayPart" type="radio" value="afternoon" name="ps-part" @change="changePart" />{{ partLabel('afternoon') }}</label>
        </fieldset>
        <p v-if="partTaken" class="notice notice--warning" role="status">{{ t('ps.call.alreadyTaken') }}</p>
      </section>

      <p v-if="rosterProblem" class="notice notice--warning" role="alert">{{ t(rosterProblem === 'offline' ? 'ps.call.rosterOffline' : rosterProblem === 'empty' ? 'ps.call.rosterEmpty' : 'ps.call.noRoster') }}</p>

      <section v-if="draft && roster.length > 0" class="card" :aria-label="t('ps.call.list')">
        <p class="muted">{{ t('ps.call.hint') }}</p>
        <ul class="roster">
          <li v-for="p in roster" :key="p.person_id" :class="`roster__row roster__row--${stateOf(p)}`">
            <button type="button" class="roster__btn" :aria-label="`${fullName(p)} : ${stateLabel(stateOf(p))}. ${t('ps.call.tapHint')}`" @click="tap(p)">
              <span class="roster__name">{{ fullName(p) }}</span>
              <span class="tag" :class="stateOf(p) === 'present' ? 'tag--success' : stateOf(p) === 'absent' ? 'tag--warning' : 'tag--accent'">
                {{ stateLabel(stateOf(p)) }}
              </span>
            </button>
            <input
              v-if="stateOf(p) === 'late'"
              type="time"
              class="roster__time"
              :aria-label="t('ps.call.arrivedAt', { name: fullName(p) })"
              :value="draft.exceptions[p.person_id]?.arrived_at ?? ''"
              @change="arrival(p, $event)"
            />
          </li>
        </ul>
      </section>

      <div v-if="draft && roster.length > 0" class="bar" role="region" :aria-label="t('ps.call.summary')">
        <p class="bar__counts" aria-live="polite" data-test="call-counts">
          {{ t('ps.call.counts', { present: stats.present, absent: stats.absent, late: stats.late }) }}
        </p>
        <div v-if="partTaken" class="field bar__reason">
          <label for="ps-reason">{{ t('ps.call.reason') }}</label>
          <input id="ps-reason" v-model="reason" type="text" maxlength="255" />
        </div>
        <button type="button" class="btn btn--primary btn--large" :disabled="busy || needsReason" @click="validate">
          {{ t('ps.call.validate', { count: roster.length }) }}
        </button>
      </div>
      <p v-if="outcome === 'sent'" class="notice notice--success" role="status">{{ t('ps.call.sent') }}</p>
      <p v-else-if="outcome === 'queued'" class="notice notice--success" role="status">{{ t('ps.call.queued') }}</p>
      <p class="sr-only" aria-live="polite">{{ announce }}</p>
      <p class="muted">
        <router-link :to="{ name: 'ps-device' }">{{ t('ps.call.deviceLink') }}</router-link>
        <span v-if="pendingNow > 0"> · {{ t('ps.call.pendingShort', { count: pendingNow }) }}</span>
      </p>
    </VaultGate>
  </div>
</template>

<style scoped>
.ps-call { max-width: 720px; padding-bottom: 7rem; }
.controls { display: grid; gap: 8px; }
.parts { border: 0; padding: 0; margin: 0; }
.parts .check { display: inline-flex; margin-right: 1.5rem; }
.roster { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.roster__row { display: flex; align-items: stretch; gap: 8px; }
.roster__btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 52px;
  padding: 0 14px;
  font: 600 16px var(--font);
  text-align: left;
  color: var(--ink);
  background: var(--surface);
  border: 1px solid var(--input-border);
  cursor: pointer;
}
.roster__row--absent .roster__btn { border: 2px solid var(--warning); }
.roster__row--late .roster__btn { border: 2px dashed var(--ink); }
.roster__time { width: 7.5rem; flex: none; }
.bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
  padding: 10px 16px;
  background: var(--surface);
  border-top: 2px solid var(--ink);
  z-index: 5;
}
.bar__counts { margin: 0; font-weight: 700; flex: 1 1 12rem; }
.bar__reason { margin: 0; flex: 1 1 12rem; }
@media (min-width: 900px) { .bar { position: sticky; margin-top: 12px; } }
</style>
