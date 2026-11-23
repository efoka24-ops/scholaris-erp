// Visites d'inspection hors ligne (EX-INS-02 : grille remplie sur téléphone, sans réseau) : même principe que l'appel et les critères.
// Le rapport s'écrit d'abord dans le coffre chiffré de l'appareil, puis part dès que le réseau est là (PUT idempotent par client_uuid).
// Un conflit (409 : visite déjà rapportée sous un autre identifiant) n'est jamais écrasé : l'inspecteur décide.
import { ApiError } from '@/api/http';
import { publicInspection, type VisitGrid, type VisitReport } from '@/api/publicSchools';
import { ulid } from '@/lib/ulid';
import { sealDelete, sealGet, sealNames, sealPut } from './vault';

export type VisitQueueStatus = 'pending' | 'conflict' | 'error';
export interface QueuedVisit {
  client_uuid: string;
  establishment_id: string;
  visit_id: string;
  label: string;
  body: VisitReport;
  status: VisitQueueStatus;
  message?: string;
  created_at: string;
}

const QUEUE = 'visitq:';
const GRIDS = 'inspection-grids';
const isNetwork = (e: unknown): boolean => e instanceof ApiError && (e.network || e.status >= 500 || e.status === 429);

/** Grilles actives : du serveur (gardées dans le coffre), sinon du coffre quand le réseau manque. */
export async function loadGrids(): Promise<{ grids: VisitGrid[]; fromCache: boolean }> {
  try {
    const grids = await publicInspection.grids();
    await sealPut(GRIDS, grids);
    return { grids, fromCache: false };
  } catch (e) {
    if (!isNetwork(e)) throw e;
    return { grids: (await sealGet<VisitGrid[]>(GRIDS)) ?? [], fromCache: true };
  }
}

/** Valide le rapport : écrit dans la file chiffrée, avec un identifiant client fixé une fois pour toutes. */
export async function queueReport(est: string, visitId: string, label: string, body: Omit<VisitReport, 'client_uuid'>): Promise<QueuedVisit> {
  const clientUuid = ulid();
  const item: QueuedVisit = {
    client_uuid: clientUuid,
    establishment_id: est,
    visit_id: visitId,
    label,
    body: { ...body, client_uuid: clientUuid },
    status: 'pending',
    created_at: new Date().toISOString(),
  };
  await sealPut(`${QUEUE}${clientUuid}`, item);
  return item;
}

export async function listVisitQueue(): Promise<QueuedVisit[]> {
  const items: QueuedVisit[] = [];
  for (const name of await sealNames(QUEUE)) {
    const item = await sealGet<QueuedVisit>(name);
    if (item) items.push(item);
  }
  return items.sort((a, b) => a.created_at.localeCompare(b.created_at));
}

/** Rejoue les rapports en attente ; s'arrête sans perte si le réseau manque. */
export async function flushVisits(): Promise<{ sent: number; conflicts: number; errors: number; offline: boolean }> {
  const report = { sent: 0, conflicts: 0, errors: 0, offline: false };
  for (const item of (await listVisitQueue()).filter((i) => i.status === 'pending')) {
    try {
      await publicInspection.report(item.visit_id, item.body, item.establishment_id);
      await sealDelete(`${QUEUE}${item.client_uuid}`);
      report.sent++;
    } catch (e) {
      if (!(e instanceof ApiError)) throw e;
      if (isNetwork(e) || e.status === 401) {
        report.offline = true;
        return report;
      }
      const status: VisitQueueStatus = e.status === 409 ? 'conflict' : 'error';
      await sealPut(`${QUEUE}${item.client_uuid}`, { ...item, status, message: [e.problem?.detail, ...Object.values(e.fieldErrors).flat()].filter(Boolean).join(' ') || e.message });
      if (status === 'conflict') report.conflicts++;
      else report.errors++;
    }
  }
  return report;
}

export async function discardVisit(uuid: string): Promise<void> {
  await sealDelete(`${QUEUE}${uuid}`);
}
