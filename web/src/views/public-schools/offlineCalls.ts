// Appel de classe hors ligne (école publique) : listes mises en cache dans le coffre chiffré, brouillon sur l'appareil, appels
// mis en file puis rejoués à la reconnexion (PUT /public/classes/{id}/attendance/{date}, idempotent par client_uuid).
// Tous les élèves sont présents par défaut : seules les exceptions (absent A, retard R) sont enregistrées et envoyées.
//
// Même principe que src/offline/queue.ts (écriture d'abord sur l'appareil, rejeu idempotent, un conflit n'est jamais résolu
// automatiquement) mais file propre à cette application : les routes de l'appel public ne sont pas celles de la file du socle.
import { ApiError } from '@/api/http';
import { publicSchools, type AttendanceDay, type CallBody, type ClassRow, type DayPart, type RosterPupil } from '@/api/publicSchools';
import { ulid } from '@/lib/ulid';
import { sealDelete, sealGet, sealNames, sealPut } from './vault';

export type ExceptionStatus = 'A' | 'R';
export interface Exception {
  status: ExceptionStatus;
  arrived_at?: string;
}
export interface Draft {
  client_uuid: string;
  class_id: string;
  date: string;
  day_part: DayPart;
  exceptions: Record<string, Exception>;
  updated_at: string;
}
export type QueueStatus = 'pending' | 'conflict' | 'error';
export interface QueuedCall {
  client_uuid: string;
  establishment_id: string;
  class_id: string;
  class_label: string;
  date: string;
  day_part: DayPart;
  body: CallBody;
  absent: number;
  late: number;
  status: QueueStatus;
  message?: string;
  created_at: string;
}
export interface FlushReport {
  sent: number;
  conflicts: number;
  errors: number;
  offline: boolean;
  unauthorized: boolean;
}

const QUEUE = 'queue:';
const isNetwork = (e: unknown): boolean => e instanceof ApiError && (e.network || e.status >= 500 || e.status === 429);

// ------------------------------------------------------------------------------------------------ classes et listes (cache)

export async function loadClasses(establishmentId: string, academicYearId: string): Promise<{ classes: ClassRow[]; fromCache: boolean }> {
  const name = `classes:${establishmentId}:${academicYearId}`;
  try {
    const classes = await publicSchools.classes(academicYearId, establishmentId);
    await sealPut(name, classes);
    return { classes, fromCache: false };
  } catch (e) {
    const cached = isNetwork(e) ? await sealGet<ClassRow[]>(name) : undefined;
    if (cached) return { classes: cached, fromCache: true };
    throw e;
  }
}

export async function loadRoster(establishmentId: string, classId: string): Promise<{ roster: RosterPupil[]; fromCache: boolean }> {
  const name = `roster:${establishmentId}:${classId}`;
  try {
    const roster = [...(await publicSchools.roster(classId, establishmentId))].sort((a, b) =>
      `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`, 'fr'),
    );
    await sealPut(name, roster);
    return { roster, fromCache: false };
  } catch (e) {
    const cached = isNetwork(e) ? await sealGet<RosterPupil[]>(name) : undefined;
    if (cached) return { roster: cached, fromCache: true };
    throw e;
  }
}

/** Demi-journées déjà faites (lecture seule ; absent hors ligne). */
export async function loadDay(establishmentId: string, classId: string, date: string): Promise<AttendanceDay | null> {
  try {
    return await publicSchools.attendanceDay(classId, date, establishmentId);
  } catch (e) {
    if (e instanceof ApiError) return null;
    throw e;
  }
}

// ----------------------------------------------------------------------------------------------------------- brouillon

const draftName = (est: string, classId: string, date: string, part: DayPart): string => `draft:${est}:${classId}:${date}:${part}`;

export async function loadDraft(est: string, classId: string, date: string, part: DayPart): Promise<Draft> {
  return (
    (await sealGet<Draft>(draftName(est, classId, date, part))) ?? {
      client_uuid: ulid(), // identifiant de la demi-journée : le même à chaque rejeu, donc jamais de doublon
      class_id: classId,
      date,
      day_part: part,
      exceptions: {},
      updated_at: new Date().toISOString(),
    }
  );
}

export async function saveDraft(est: string, draft: Draft): Promise<void> {
  await sealPut(draftName(est, draft.class_id, draft.date, draft.day_part), { ...draft, updated_at: new Date().toISOString() });
}

/** Un appui fait avancer l'élève : présent → absent → en retard → présent. */
export function cycle(draft: Draft, personId: string, now = ''): Draft {
  const current = draft.exceptions[personId];
  const exceptions = { ...draft.exceptions };
  if (!current) exceptions[personId] = { status: 'A' };
  else if (current.status === 'A') exceptions[personId] = { status: 'R', ...(now ? { arrived_at: now } : {}) };
  else delete exceptions[personId];
  return { ...draft, exceptions };
}

export function setArrival(draft: Draft, personId: string, hhmm: string): Draft {
  const current = draft.exceptions[personId];
  if (!current || current.status !== 'R') return draft;
  return { ...draft, exceptions: { ...draft.exceptions, [personId]: { status: 'R', ...(hhmm ? { arrived_at: hhmm } : {}) } } };
}

export function counters(draft: Draft, total: number): { present: number; absent: number; late: number } {
  const list = Object.values(draft.exceptions);
  const absent = list.filter((e) => e.status === 'A').length;
  const late = list.filter((e) => e.status === 'R').length;
  return { present: Math.max(0, total - absent), absent, late };
}

// --------------------------------------------------------------------------------------------------------------- file

export async function listQueue(): Promise<QueuedCall[]> {
  const items: QueuedCall[] = [];
  for (const name of await sealNames(QUEUE)) {
    const item = await sealGet<QueuedCall>(name);
    if (item) items.push(item);
  }
  return items.sort((a, b) => a.created_at.localeCompare(b.created_at));
}

async function store(item: QueuedCall): Promise<void> {
  await sealPut(`${QUEUE}${item.client_uuid}`, item);
  notify();
}

const listeners = new Set<() => void>();
export function onQueueChange(fn: () => void): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}
function notify(): void {
  listeners.forEach((fn) => fn());
}

/** Valide l'appel : écrit d'abord sur l'appareil, l'envoi se fait ensuite (tout de suite si le réseau est là). */
export async function submitCall(est: string, draft: Draft, classLabel: string, reason?: string): Promise<QueuedCall> {
  const records = Object.entries(draft.exceptions).map(([person_id, e]) => ({
    person_id,
    status: e.status,
    ...(e.status === 'R' && e.arrived_at ? { arrived_at: e.arrived_at } : {}),
  }));
  const item: QueuedCall = {
    client_uuid: draft.client_uuid,
    establishment_id: est,
    class_id: draft.class_id,
    class_label: classLabel,
    date: draft.date,
    day_part: draft.day_part,
    body: { day_part: draft.day_part, client_uuid: draft.client_uuid, records, ...(reason ? { reason } : {}) },
    absent: records.filter((r) => r.status === 'A').length,
    late: records.filter((r) => r.status === 'R').length,
    status: 'pending',
    created_at: new Date().toISOString(),
  };
  await store(item);
  await sealDelete(draftName(est, draft.class_id, draft.date, draft.day_part));
  return item;
}

let flushing: Promise<FlushReport> | null = null;

/** Rejoue les appels en attente, un par un. Un seul envoi à la fois. */
export function flushCalls(): Promise<FlushReport> {
  if (flushing) return flushing;
  flushing = doFlush().finally(() => {
    flushing = null;
    notify();
  });
  return flushing;
}

async function doFlush(): Promise<FlushReport> {
  const report: FlushReport = { sent: 0, conflicts: 0, errors: 0, offline: false, unauthorized: false };
  for (const item of (await listQueue()).filter((i) => i.status === 'pending')) {
    try {
      await publicSchools.recordAttendance(item.class_id, item.date, item.body, item.establishment_id);
      await sealDelete(`${QUEUE}${item.client_uuid}`);
      report.sent++;
    } catch (e) {
      if (!(e instanceof ApiError)) throw e;
      if (isNetwork(e)) {
        report.offline = true;
        return report;
      }
      if (e.status === 401) {
        report.unauthorized = true;
        return report;
      }
      // Conflit (409) : la version du serveur n'est jamais écrasée ; la personne décide. Autre refus : à corriger ou abandonner.
      const status: QueueStatus = e.status === 409 ? 'conflict' : 'error';
      await store({ ...item, status, message: [e.problem?.detail, ...Object.values(e.fieldErrors).flat()].filter(Boolean).join(' ') || e.message });
      if (status === 'conflict') report.conflicts++;
      else report.errors++;
    }
  }
  return report;
}

export async function retryCall(uuid: string): Promise<void> {
  const item = await sealGet<QueuedCall>(`${QUEUE}${uuid}`);
  if (item) await store({ ...item, status: 'pending', message: undefined });
}

export async function discardCall(uuid: string): Promise<void> {
  await sealDelete(`${QUEUE}${uuid}`);
  notify();
}

export async function pendingCount(): Promise<number> {
  return (await listQueue()).filter((i) => i.status === 'pending').length;
}
