// Grille de saisie IPES branchée sur la file hors ligne du socle (type « higher-marks ») : état local par case (en attente,
// envoyé, conflit, refusé). Un conflit n'est jamais résolu automatiquement : l'enseignant choisit entre la valeur du serveur et
// la sienne (renvoyée sur la version actuelle du serveur).
import { computed, onUnmounted, reactive, ref } from 'vue';
import { higherMarksOf, queueHigherMark, queuedValue, type HigherMarkEdit } from '@/offline/higherMarks';
import { onQueueChange, resolveConflict, type QueueItem } from '@/offline/queue';
import { useOfflineStore } from '@/stores/offline';
import type { CellValue } from './marks';

export type LocalState = 'pending' | 'sent' | 'conflict' | 'error';

export interface MarkConflictView {
  item: QueueItem;
  markId: string;
  server: CellValue & { version: number | null };
  mine: CellValue;
}

const cell = (v: Record<string, unknown> | undefined): CellValue => ({
  score: typeof v?.score === 'number' ? v.score : null,
  absence: typeof v?.absence === 'string' ? v.absence : null,
});

export function useOfflineMarks(establishmentId: () => string | null, assessmentId: () => string) {
  const offline = useOfflineStore();
  const local = ref(new Map<string, QueueItem>());
  const sent = reactive(new Set<string>());

  async function refreshLocal(): Promise<void> {
    const est = establishmentId();
    if (!est) return;
    const before = local.value;
    const now = await higherMarksOf(est, assessmentId());
    for (const [markId, item] of before) if (item.status === 'pending' && !now.has(markId)) sent.add(markId);
    for (const markId of now.keys()) sent.delete(markId);
    local.value = now;
  }

  const stop = onQueueChange(() => void refreshLocal());
  onUnmounted(stop);

  const stateOf = (markId: string): LocalState | null => local.value.get(markId)?.status ?? (sent.has(markId) ? 'sent' : null);
  const valueOf = (markId: string): CellValue | null => {
    const item = local.value.get(markId);
    return item ? queuedValue(item) : null;
  };
  const pendingCount = computed(() => [...local.value.values()].filter((i) => i.status === 'pending').length);
  const conflicts = computed<MarkConflictView[]>(() =>
    [...local.value.entries()]
      .filter(([, i]) => i.status === 'conflict')
      .map(([markId, item]) => {
        const server = item.server_version as Record<string, unknown> | undefined;
        return {
          item,
          markId,
          server: { ...cell(server), version: typeof server?.version === 'number' ? server.version : null },
          mine: cell((item.client_version as Record<string, unknown> | undefined) ?? item.payload),
        };
      }),
  );

  /** Enregistre des cases sur l'appareil puis tente l'envoi si le réseau est là. */
  async function save(edits: Array<{ edit: HigherMarkEdit; label: string }>): Promise<void> {
    const est = establishmentId();
    if (!est) return;
    for (const { edit, label } of edits) {
      sent.delete(edit.mark_id);
      await queueHigherMark(est, edit, label);
    }
    await refreshLocal();
    if (offline.online) await offline.sync();
  }

  async function decide(c: MarkConflictView, choice: 'keep-server' | 'resend'): Promise<void> {
    await resolveConflict(c.item.client_uuid, choice);
    await refreshLocal();
    if (choice === 'resend' && offline.online) await offline.sync();
  }

  return { offline, local, stateOf, valueOf, pendingCount, conflicts, refreshLocal, save, decide };
}
