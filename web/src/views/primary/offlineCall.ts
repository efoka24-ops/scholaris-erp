// Appel par demi-journée (attendance.mode = day_part) : même brouillon et même file hors ligne que l'appel par créneau, mais la
// séance est identifiée par la classe et le moment de la journée, sans créneau d'emploi du temps. L'envoi (POST
// /attendance-sessions/sync) est idempotent par client_uuid : rejouer le même appel ne crée jamais de doublon.
import { cacheDelete, cacheGet, cachePut } from '@/offline/db';
import { enqueue, listQueue } from '@/offline/queue';
import { counters, loadDraft, type Draft, type RosterEntry } from '@/offline/attendance';

export type DayPart = 'morning' | 'afternoon';
export const DAY_PARTS: readonly DayPart[] = ['morning', 'afternoon'];

/** Clé de brouillon : la même forme que la clé déterministe stockée côté serveur (dp:<classe>:<moment>). */
export const partKey = (classId: string, part: DayPart): string => `dp:${classId}:${part}`;

export async function loadPartDraft(est: string, classId: string, part: DayPart, date: string): Promise<Draft> {
  return loadDraft(est, partKey(classId, part), date);
}

/** Classes connues de l'appareil (cache), pour ouvrir l'appel sans réseau. */
export async function cachedClasses(est: string): Promise<{ id: string; name: string }[] | null> {
  return (await cacheGet<{ id: string; name: string }[]>(`primary:classes:${est}`)) ?? null;
}
export async function rememberClasses(est: string, classes: { id: string; name: string }[]): Promise<void> {
  await cachePut(`primary:classes:${est}`, classes);
}

/** Valide l'appel : écrit sur l'appareil (file), l'envoi suit dès que le réseau est là. Chaque élève doit avoir un statut. */
export async function submitPartCall(est: string, classId: string, part: DayPart, draft: Draft, roster: RosterEntry[], label: string): Promise<void> {
  const missing = roster.filter((r) => !draft.lines[r.person_id]);
  if (missing.length > 0) throw new Error(`Il manque le statut de ${missing.length} élève(s).`);
  await enqueue({
    client_uuid: draft.client_uuid,
    kind: 'attendance',
    establishment_id: est,
    label,
    payload: {
      client_uuid: draft.client_uuid,
      structure_id: classId,
      day_part: part,
      date: draft.date,
      validate: true,
      records: roster.map((r) => draft.lines[r.person_id]),
    },
  });
  await cacheDelete(`draft:attendance:${est}:${partKey(classId, part)}:${draft.date}`);
}

/** Un appel de cette classe, ce moment et ce jour attend-il déjà son envoi ? */
export async function queuedPart(classId: string, part: DayPart, date: string): Promise<boolean> {
  const items = await listQueue('attendance');
  return items.some((i) => i.payload.structure_id === classId && i.payload.day_part === part && i.payload.date === date);
}

export { counters };
