<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ExportButton from '@/components/ExportButton.vue';
import StatusTag from '@/components/StatusTag.vue';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useOfflineStore } from '@/stores/offline';
import { useI18n } from '@/i18n';
import { useVocabulary } from '@/composables/useVocabulary';
import type { AttendanceStatus } from '@/api/types';
import {
  clearStatus,
  counters,
  loadDraft,
  loadRoster,
  loadStructureNames,
  loadTodaySlots,
  queuedFor,
  saveDraft,
  setStatus,
  submitCall,
  type Draft,
  type RosterEntry,
  type SlotView,
} from '@/offline/attendance';
import { formatTime, nowHhmm, todayIso, formatDate } from '@/lib/format';

// Appel mobile (maquette 05) : P / A / R par élève, compteurs, « Valider l'appel (n/N) », fonctionnement hors ligne.
const { t, tp, locale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();
const offline = useOfflineStore();
const { term } = useVocabulary();

const date = todayIso();
const slots = ref<SlotView[]>([]);
const structureNames = ref<Record<string, string>>({});
const queuedSlots = ref<Set<string>>(new Set());
const slotsFromCache = ref(false);
const loading = ref(true);
const error = ref<unknown>(null);

const selected = ref<SlotView | null>(null);
const roster = ref<RosterEntry[]>([]);
const rosterFromCache = ref(false);
const draft = ref<Draft | null>(null);
const submitting = ref(false);
const justSubmitted = ref<'sent' | 'queued' | null>(null);

const est = computed(() => ctx.establishmentId ?? '');
const stats = computed(() => (draft.value ? counters(draft.value) : { present: 0, absent: 0, late: 0, recorded: 0 }));
const complete = computed(() => roster.value.length > 0 && stats.value.recorded === roster.value.length);
const structureName = (s: SlotView): string => (s.structure_id ? (structureNames.value[s.structure_id] ?? '') : '');

async function loadSlots(): Promise<void> {
  loading.value = true;
  error.value = null;
  const person = auth.me?.person_id;
  if (!person || !est.value) {
    loading.value = false;
    return;
  }
  try {
    const res = await loadTodaySlots(est.value, person, date);
    slots.value = res.slots;
    slotsFromCache.value = res.fromCache;
    structureNames.value = await loadStructureNames(est.value);
    const queued = new Set<string>();
    for (const s of res.slots) if (await queuedFor(s.slot_id, date)) queued.add(s.slot_id);
    queuedSlots.value = queued;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function openSlot(slot: SlotView): Promise<void> {
  error.value = null;
  justSubmitted.value = null;
  if (!slot.structure_id) return;
  try {
    const r = await loadRoster(est.value, slot.structure_id);
    roster.value = r.roster;
    rosterFromCache.value = r.fromCache;
    draft.value = await loadDraft(est.value, slot.slot_id, date);
    selected.value = slot;
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
  const current = draft.value.lines[personId];
  // Un second appui sur le même statut le retire : on peut se corriger sans valider.
  if (current?.status === status) return persist(clearStatus(draft.value, personId));
  return persist(setStatus(draft.value, personId, status, status === 'R' ? nowHhmm() : undefined));
}

async function setArrival(personId: string, time: string): Promise<void> {
  if (!draft.value || !time) return;
  await persist(setStatus(draft.value, personId, 'R', time));
}

async function allPresent(): Promise<void> {
  if (!draft.value) return;
  let next = draft.value;
  for (const r of roster.value) if (!next.lines[r.person_id]) next = setStatus(next, r.person_id, 'P');
  await persist(next);
}

async function validate(): Promise<void> {
  const slot = selected.value;
  if (!slot || !draft.value || !complete.value) return;
  submitting.value = true;
  error.value = null;
  try {
    const label = `${structureName(slot)} · ${slot.subject_label ?? ''} · ${formatDate(date, locale.value)}`;
    await submitCall(est.value, draft.value, roster.value, label);
    // L'appel est déjà à l'abri sur l'appareil ; l'envoi part tout de suite s'il y a du réseau.
    const report = offline.online ? await offline.sync() : null;
    await offline.refresh();
    justSubmitted.value = report && !report.offline && report.sent > 0 && offline.pending === 0 ? 'sent' : 'queued';
    queuedSlots.value = new Set([...queuedSlots.value, slot.slot_id]);
    selected.value = null;
    draft.value = null;
  } catch (e) {
    error.value = e;
  } finally {
    submitting.value = false;
  }
}

function back(): void {
  selected.value = null;
  draft.value = null;
}

onMounted(loadSlots);
</script>

<template>
  <div class="call-page">
    <header class="hd">
      <p class="eyebrow">{{ ctx.activeTile?.name }} · {{ formatDate(date, locale) }}</p>
      <template v-if="!selected">
        <h1 class="hd__title">{{ t('attendance.title') }}</h1>
        <p class="hd__sub" role="status" data-test="pending-counter">{{ tp('attendance.pendingCount', offline.pendingAttendance) }}</p>
      </template>
      <template v-else-if="draft">
        <div class="row row--between hd__line">
          <h1 id="call-title" class="hd__title">{{ t('attendance.callTitle', { structure: structureName(selected) }) }}</h1>
          <span class="hd__time">{{ formatTime(selected.start_time) }} – {{ formatTime(selected.end_time) }}</span>
        </div>
        <p class="hd__sub">{{ t('attendance.callSub', { subject: selected.subject_label ?? '', count: roster.length, learners: term('learner') }) }}</p>
        <p class="totals" aria-live="polite">
          <span>{{ tp('attendance.present', stats.present) }}</span>
          <span class="warn">{{ tp('attendance.absent', stats.absent) }}</span>
          <span>{{ tp('attendance.late', stats.late) }}</span>
        </p>
      </template>
    </header>

    <div class="body">
      <ErrorNotice :error="error" />
      <p v-if="justSubmitted === 'sent'" class="notice notice--success" role="status">{{ t('attendance.sent') }}</p>
      <p v-else-if="justSubmitted === 'queued'" class="notice notice--warning" role="status">
        {{ offline.online ? t('attendance.queuedOnline') : t('attendance.queuedOffline') }}
      </p>

      <!-- Choix du créneau -->
      <section v-if="!selected" aria-labelledby="slots-title">
        <h2 id="slots-title" class="h2s">{{ t('attendance.slots') }}</h2>
        <ExportButton v-if="ctx.can('attendance.read', 'attendance.take')" path="/attendance-records/export" :query="{ 'filter[date]': date }" name="presences" />
        <p v-if="slotsFromCache" class="notice notice--warning">{{ t('attendance.fromCache') }}</p>
        <p v-if="loading" class="muted">{{ t('common.loading') }}</p>
        <p v-else-if="!auth.me?.person_id" class="notice notice--warning">{{ t('attendance.noPerson') }}</p>
        <p v-else-if="slots.length === 0 && !error" class="card muted">{{ t('attendance.noSlots') }}</p>
        <ul class="slots">
          <li v-for="s in slots" :key="s.slot_id + s.start_time" class="card card--tight">
            <div class="row row--between">
              <div>
                <p class="eyebrow">{{ formatTime(s.start_time) }} – {{ formatTime(s.end_time) }}</p>
                <h3>{{ s.subject_label }}<template v-if="structureName(s)"> · {{ structureName(s) }}</template></h3>
                <p v-if="s.status !== 'planned' && s.status" class="muted">{{ s.status }}</p>
              </div>
              <StatusTag v-if="queuedSlots.has(s.slot_id)" tone="warning">{{ t('attendance.queuedTag') }}</StatusTag>
            </div>
            <button type="button" class="btn btn--primary btn--block" :disabled="!s.structure_id || queuedSlots.has(s.slot_id)" @click="openSlot(s)">
              {{ t('attendance.startCall') }}
            </button>
          </li>
        </ul>
      </section>

      <!-- Appel d'un créneau -->
      <section v-else-if="draft" aria-labelledby="call-title" class="call">
        <div class="row tools">
          <button type="button" class="btn btn--quiet" @click="back">← {{ t('attendance.back') }}</button>
          <button type="button" class="btn btn--quiet" @click="allPresent">{{ t('attendance.allPresent') }}</button>
        </div>
        <p v-if="rosterFromCache" class="notice notice--warning">{{ t('attendance.rosterCache') }}</p>
        <p v-if="roster.length === 0" class="notice notice--warning">{{ t('attendance.emptyRoster') }}</p>

        <ul class="roster">
          <li v-for="(r, i) in roster" :key="r.person_id" class="line" :class="{ 'line--absent': draft.lines[r.person_id]?.status === 'A' }">
            <div class="who">
              <div class="name">{{ r.name }}</div>
              <div v-if="draft.lines[r.person_id]?.status === 'A'" class="sub sub--warn">{{ t('attendance.absentNotified') }}</div>
              <div v-else-if="draft.lines[r.person_id]?.status === 'R'" class="sub">{{ t('attendance.lateAt', { time: draft.lines[r.person_id]?.arrived_at ?? '' }) }}</div>
              <div v-else class="sub">{{ i + 1 }}</div>
            </div>
            <div class="choices" role="group" :aria-label="t('attendance.statusFor', { name: r.name })">
              <button type="button" class="pick" :aria-pressed="draft.lines[r.person_id]?.status === 'P'" @click="mark(r.person_id, 'P')">
                <span aria-hidden="true">P</span><span class="sr-only">{{ t('attendance.presentOne') }}</span>
              </button>
              <button type="button" class="pick pick--a" :aria-pressed="draft.lines[r.person_id]?.status === 'A'" @click="mark(r.person_id, 'A')">
                <span aria-hidden="true">A</span><span class="sr-only">{{ t('attendance.absentOne') }}</span>
              </button>
              <button type="button" class="pick" :aria-pressed="draft.lines[r.person_id]?.status === 'R'" @click="mark(r.person_id, 'R')">
                <span aria-hidden="true">R</span><span class="sr-only">{{ t('attendance.lateOne') }}</span>
              </button>
            </div>
            <div v-if="draft.lines[r.person_id]?.status === 'R'" class="arrival">
              <label :for="`arr-${r.person_id}`">{{ t('attendance.arrival') }}</label>
              <input :id="`arr-${r.person_id}`" type="time" :value="draft.lines[r.person_id]?.arrived_at" @change="setArrival(r.person_id, ($event.target as HTMLInputElement).value)" />
            </div>
          </li>
        </ul>

        <div class="bar">
          <button type="button" class="validate" :disabled="!complete || submitting" @click="validate">
            {{ t('attendance.validate', { done: stats.recorded, total: roster.length }) }}
          </button>
          <p class="bar__note" data-test="call-footer">{{ t('attendance.savedOnDevice') }} · {{ tp('attendance.pendingCount', offline.pendingAttendance) }}</p>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.call-page {
  max-width: 560px;
  margin: 0 auto;
  background: var(--surface);
  min-height: 100%;
}
.hd {
  padding: 16px 20px;
  border-bottom: 2px solid var(--ink);
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.hd__title {
  margin: 0;
  font-size: 24px;
}
.hd__line {
  align-items: baseline;
  flex-wrap: nowrap;
}
.hd__time {
  font-size: 13px;
  font-weight: 700;
  white-space: nowrap;
}
.hd__sub {
  margin: 0;
  font-size: 13px;
  color: var(--text-2);
}
.totals {
  display: flex;
  gap: 16px;
  margin: 6px 0 0;
  font-size: 13px;
  font-weight: 700;
}
.totals .warn {
  color: var(--warning);
}
.body {
  padding: 4px 20px 0;
}
.h2s {
  font-size: 17px;
  margin: 16px 0 8px;
}
.slots {
  list-style: none;
  padding: 0;
  margin: 0;
}
.slots .card button {
  margin-top: 0.75rem;
}
.tools {
  margin: 12px 0 4px;
}
.roster {
  list-style: none;
  padding: 0;
  margin: 0 0 8px;
}
.line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 10px;
  padding: 8px 0;
  border-bottom: 1px solid var(--border);
}
.line--absent {
  background: var(--warning-bg);
  margin: 0 -20px;
  padding: 8px 20px;
}
.who {
  flex: 1;
  min-width: 0;
}
.name {
  font-weight: 700;
  font-size: 15px;
}
.sub {
  font-size: 12px;
  color: var(--muted);
}
.sub--warn {
  color: var(--warning);
  font-weight: 700;
}
.choices {
  display: flex;
  gap: 6px;
}
.pick {
  min-width: 44px;
  min-height: 44px;
  font: 800 13px var(--font);
  border: 1px solid var(--input-border);
  background: var(--surface);
  color: var(--ink);
  cursor: pointer;
}
.pick[aria-pressed='true'] {
  background: var(--ink);
  color: #fff;
  border-color: var(--ink);
}
.pick--a[aria-pressed='true'] {
  background: var(--accent);
  border-color: var(--accent);
  color: var(--on-accent);
}
.arrival {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
}
.arrival input {
  width: auto;
}
.bar {
  position: sticky;
  bottom: 0;
  margin: 0 -20px;
  padding: 16px 20px;
  background: var(--surface);
  border-top: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.validate {
  min-height: 52px;
  border: 0;
  background: var(--accent);
  color: var(--on-accent);
  font: 800 16px var(--font);
  cursor: pointer;
}
.validate:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.bar__note {
  font-size: 12px;
  color: var(--muted);
  text-align: center;
  margin: 0;
}
</style>
