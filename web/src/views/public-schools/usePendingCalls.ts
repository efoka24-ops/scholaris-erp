// Appels en attente d'envoi : compteur réactif, rejeu au retour du réseau et à intervalle tant qu'il en reste.
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useOfflineStore } from '@/stores/offline';
import { flushCalls, listQueue, onQueueChange, type FlushReport, type QueuedCall } from './offlineCalls';
import { unlocked } from './vault';

const RETRY_MS = 30_000;

export function usePendingCalls() {
  const offline = useOfflineStore();
  const items = ref<QueuedCall[]>([]);
  const syncing = ref(false);
  const lastReport = ref<FlushReport | null>(null);
  let timer: ReturnType<typeof setInterval> | null = null;
  let off: (() => void) | null = null;

  async function refresh(): Promise<void> {
    items.value = unlocked.value ? await listQueue() : [];
  }

  async function sync(): Promise<FlushReport | null> {
    if (!unlocked.value || syncing.value) return null;
    syncing.value = true;
    try {
      const report = await flushCalls();
      lastReport.value = report;
      if (report.offline) offline.online = false;
      return report;
    } finally {
      syncing.value = false;
      await refresh();
    }
  }

  const onOnline = (): void => void sync();

  onMounted(() => {
    void refresh().then(() => {
      if (items.value.some((i) => i.status === 'pending') && offline.online) void sync();
    });
    off = onQueueChange(() => void refresh());
    window.addEventListener('online', onOnline);
    timer = setInterval(() => {
      if (items.value.some((i) => i.status === 'pending')) void sync();
    }, RETRY_MS);
  });
  onBeforeUnmount(() => {
    off?.();
    window.removeEventListener('online', onOnline);
    if (timer) clearInterval(timer);
  });

  return { items, syncing, lastReport, refresh, sync };
}
