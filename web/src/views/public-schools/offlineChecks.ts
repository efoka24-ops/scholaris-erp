// Saisie des critères hors ligne (Primaire publique, API-06) : même principe que l'appel (offlineCalls.ts). La grille de la classe est
// gardée dans le coffre chiffré, la saisie s'écrit d'abord sur l'appareil puis part dès que le réseau est là (PUT idempotent par
// client_uuid). Un conflit (409 : bulletin publié, lot déjà reçu avec un autre contenu) n'est jamais écrasé : la personne décide.
import { ApiError } from '@/api/http';
import { publicPrimary, type Cell, type ChecksBody, type Grid, type Outcome } from '@/api/publicSchools';
import { ulid } from '@/lib/ulid';
import { sealDelete, sealGet, sealNames, sealPut } from './vault';

export type CheckStatus = 'pending' | 'conflict' | 'error';
export interface QueuedChecks {
  client_uuid: string;
  establishment_id: string;
  unit_id: string;
  class_label: string;
  unit_no: number;
  body: ChecksBody;
  status: CheckStatus;
  message?: string;
  created_at: string;
}

const QUEUE = 'evalq:';
const isNetwork = (e: unknown): boolean => e instanceof ApiError && (e.network || e.status >= 500 || e.status === 429);
const gridName = (est: string, unitId: string, classId: string): string => `grid:${est}:${unitId}:${classId}`;
const draftName = (est: string, unitId: string, classId: string): string => `evald:${est}:${unitId}:${classId}`;

/** Grille de la classe pour l'unité : du serveur, sinon du coffre (hors ligne). */
export async function loadGrid(est: string, unitId: string, classId: string): Promise<{ grid: Grid | null; fromCache: boolean }> {
  try {
    const grid = await publicPrimary.grid(unitId, classId);
    await sealPut(gridName(est, unitId, classId), grid);
    return { grid, fromCache: false };
  } catch (e) {
    if (!isNetwork(e)) throw e;
    return { grid: (await sealGet<Grid>(gridName(est, unitId, classId))) ?? null, fromCache: true };
  }
}

/** Cases modifiées sur l'appareil, par clé « élève|critère|occasion ». */
export type Draft = Record<string, Outcome>;
export const cellKey = (personId: string, criterionId: string, occasion: number): string => `${personId}|${criterionId}|${occasion}`;

export async function loadDraft(est: string, unitId: string, classId: string): Promise<Draft> {
  return (await sealGet<Draft>(draftName(est, unitId, classId))) ?? {};
}

export async function saveDraft(est: string, unitId: string, classId: string, draft: Draft): Promise<void> {
  await sealPut(draftName(est, unitId, classId), draft);
}

/** Un appui fait tourner la case : réussi, échoué, absent, puis réussi. */
export function nextOutcome(current: Outcome | undefined): Outcome {
  return current === 'success' ? 'fail' : current === 'fail' ? 'absent' : 'success';
}

/** Valide la saisie : écrite d'abord dans la file chiffrée, envoyée ensuite. */
export async function submitChecks(est: string, grid: Grid, draft: Draft): Promise<QueuedChecks> {
  const checks: Cell[] = Object.entries(draft).map(([key, outcome]) => {
    const [person_id = '', criterion_id = '', occasion = '0'] = key.split('|');
    return { person_id, criterion_id, occasion_no: Number(occasion), outcome };
  });
  const clientUuid = ulid();
  const item: QueuedChecks = {
    client_uuid: clientUuid,
    establishment_id: est,
    unit_id: grid.unit.id,
    class_label: grid.class.label,
    unit_no: grid.unit.unit_no,
    body: { structure_id: grid.class.structure_id, client_uuid: clientUuid, checks },
    status: 'pending',
    created_at: new Date().toISOString(),
  };
  await sealPut(`${QUEUE}${clientUuid}`, item);
  await sealDelete(draftName(est, grid.unit.id, grid.class.structure_id));
  return item;
}

export async function listChecksQueue(): Promise<QueuedChecks[]> {
  const items: QueuedChecks[] = [];
  for (const name of await sealNames(QUEUE)) {
    const item = await sealGet<QueuedChecks>(name);
    if (item) items.push(item);
  }
  return items.sort((a, b) => a.created_at.localeCompare(b.created_at));
}

/** Rejoue les saisies en attente, une par une ; s'arrête sans perte si le réseau manque. */
export async function flushChecks(): Promise<{ sent: number; conflicts: number; errors: number; offline: boolean }> {
  const report = { sent: 0, conflicts: 0, errors: 0, offline: false };
  for (const item of (await listChecksQueue()).filter((i) => i.status === 'pending')) {
    try {
      await publicPrimary.saveChecks(item.unit_id, item.body, item.establishment_id);
      await sealDelete(`${QUEUE}${item.client_uuid}`);
      report.sent++;
    } catch (e) {
      if (!(e instanceof ApiError)) throw e;
      if (isNetwork(e) || e.status === 401) {
        report.offline = true;
        return report;
      }
      const status: CheckStatus = e.status === 409 ? 'conflict' : 'error';
      await sealPut(`${QUEUE}${item.client_uuid}`, { ...item, status, message: [e.problem?.detail, ...Object.values(e.fieldErrors).flat()].filter(Boolean).join(' ') || e.message });
      if (status === 'conflict') report.conflicts++;
      else report.errors++;
    }
  }
  return report;
}

export async function discardChecks(uuid: string): Promise<void> {
  await sealDelete(`${QUEUE}${uuid}`);
}
