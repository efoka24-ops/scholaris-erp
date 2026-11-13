import { onBeforeUnmount, onMounted } from 'vue';
import { POLL_MS } from '@/api/messaging';

/** Interrogation légère pendant que l'écran est ouvert et visible ; rien en arrière-plan ni après fermeture. */
export function usePolling(tick: () => unknown, intervalMs = POLL_MS): void {
  let timer: ReturnType<typeof setInterval> | null = null;
  const run = (): void => {
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return;
    void tick();
  };
  onMounted(() => {
    timer = setInterval(run, intervalMs);
  });
  onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
    timer = null;
  });
}
