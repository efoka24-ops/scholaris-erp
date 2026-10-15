import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { flushQueue, onQueueChange, queueCounts, type FlushReport } from '@/offline/queue';

const RETRY_MS = 30_000;

/** État réseau et file d'attente, pour l'indicateur « en attente d'envoi » et le bandeau hors ligne. */
export const useOfflineStore = defineStore('offline', () => {
  const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
  const pending = ref(0);
  const pendingAttendance = ref(0);
  const pendingGrades = ref(0);
  const conflicts = ref(0);
  const errors = ref(0);
  const syncing = ref(false);
  const lastReport = ref<FlushReport | null>(null);
  const lastSyncAt = ref<string | null>(null);
  let timer: ReturnType<typeof setInterval> | null = null;
  let unsubscribe: (() => void) | null = null;

  const needsAttention = computed(() => conflicts.value + errors.value);

  async function refresh(): Promise<void> {
    const c = await queueCounts();
    pending.value = c.pending;
    pendingAttendance.value = c.attendance;
    pendingGrades.value = c.grade;
    conflicts.value = c.conflict;
    errors.value = c.error;
  }

  async function sync(): Promise<FlushReport | null> {
    if (syncing.value) return null;
    syncing.value = true;
    try {
      const report = await flushQueue();
      lastReport.value = report;
      lastSyncAt.value = new Date().toISOString();
      if (report.offline) online.value = false;
      return report;
    } finally {
      syncing.value = false;
      await refresh();
    }
  }

  function onOnline(): void {
    online.value = true;
    void sync();
  }
  function onOffline(): void {
    online.value = false;
  }

  /** Démarre la surveillance : réseau, changements de file, nouvelle tentative périodique tant qu'il reste des éléments. */
  function start(): void {
    if (timer) return;
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    unsubscribe = onQueueChange(() => void refresh());
    timer = setInterval(() => {
      if (pending.value > 0 && !syncing.value) void sync();
    }, RETRY_MS);
    void refresh().then(() => {
      if (pending.value > 0 && online.value) void sync();
    });
  }

  function stop(): void {
    window.removeEventListener('online', onOnline);
    window.removeEventListener('offline', onOffline);
    unsubscribe?.();
    unsubscribe = null;
    if (timer) clearInterval(timer);
    timer = null;
  }

  return { online, pending, pendingAttendance, pendingGrades, conflicts, errors, syncing, lastReport, lastSyncAt, needsAttention, refresh, sync, start, stop };
});
