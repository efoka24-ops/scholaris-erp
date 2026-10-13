// Appel mobile : lecture (créneaux du jour, effectif de la classe) avec cache hors ligne, brouillon sur l'appareil et mise en file.
import { ApiError, http as defaultHttp, type HttpClient } from '@/api/http';
import type { AttendanceLine, AttendanceStatus, Enrollment, Person, TimetableEntry, TimetableWeek } from '@/api/types';
import { cacheDelete, cacheGet, cachePut } from './db';
import { enqueue, listQueue } from './queue';
import { ulid } from '@/lib/ulid';

export interface RosterEntry {
  person_id: string;
  name: string;
}

export interface Draft {
  client_uuid: string;
  slot_id: string;
  date: string;
  /** État par élève ; absent = pas encore appelé. */
  lines: Record<string, AttendanceLine>;
  updated_at: string;
}

export interface SlotView extends TimetableEntry {
  structure_id: string | null;
}

const INACTIVE = new Set(['draft', 'withdrawn', 'transferred', 'excluded', 'graduated', 'suspended']);

function isNetworkError(e: unknown): boolean {
  return e instanceof ApiError && (e.network || e.status >= 500);
}

/** Créneaux de la journée de l'enseignant. En cas de coupure, le dernier emploi du temps connu est utilisé. */
export async function loadTodaySlots(
  establishmentId: string,
  teacherPersonId: string,
  date: string,
  client: HttpClient = defaultHttp,
): Promise<{ slots: SlotView[]; fromCache: boolean }> {
  const key = `slots:${establishmentId}:${teacherPersonId}:${date}`;
  try {
    const res = await client.get<{ data: TimetableWeek }>('/timetable', { teacher_person_id: teacherPersonId, week: date }, { establishmentId });
    const day = res.data.days.find((d) => d.date === date);
    const slots = (day?.entries ?? [])
      .filter((e) => e.status !== 'cancelled')
      .map((e) => ({ ...e, structure_id: e.structure_ids[0] ?? null }))
      .sort((a, b) => a.start_time.localeCompare(b.start_time));
    await cachePut(key, slots);
    return { slots, fromCache: false };
  } catch (e) {
    if (isNetworkError(e)) {
      const cached = await cacheGet<SlotView[]>(key);
      if (cached) return { slots: cached, fromCache: true };
    }
    throw e;
  }
}

export async function loadStructureNames(establishmentId: string, client: HttpClient = defaultHttp): Promise<Record<string, string>> {
  const key = `structures:${establishmentId}`;
  try {
    const rows = await client.all<{ id: string; name?: string; label?: string; code?: string }>('/structures', {}, { establishmentId }, 5);
    const map: Record<string, string> = {};
    for (const r of rows) map[r.id] = r.name ?? r.label ?? r.code ?? r.id;
    await cachePut(key, map);
    return map;
  } catch (e) {
    const cached = await cacheGet<Record<string, string>>(key);
    if (cached) return cached;
    if (e instanceof ApiError) return {};
    throw e;
  }
}

/** Effectif d'une classe : inscriptions actives puis noms. Mis en cache pour l'appel hors ligne. */
export async function loadRoster(
  establishmentId: string,
  structureId: string,
  client: HttpClient = defaultHttp,
): Promise<{ roster: RosterEntry[]; fromCache: boolean }> {
  const key = `roster:${establishmentId}:${structureId}`;
  try {
    const enrollments = await client.all<Enrollment>('/enrollments', { 'filter[structure_id]': structureId }, { establishmentId });
    const ids = new Set(enrollments.filter((e) => !INACTIVE.has(e.status)).map((e) => e.person_id));
    const people = await client.all<Person>('/people', {}, { establishmentId }, 10);
    const byId = new Map(people.map((p) => [p.id, p]));
    const roster = [...ids]
      .map((id) => {
        const p = byId.get(id);
        return { person_id: id, name: p ? `${p.last_name.toUpperCase()} ${p.first_name}` : id };
      })
      .sort((a, b) => a.name.localeCompare(b.name, 'fr'));
    await cachePut(key, roster);
    return { roster, fromCache: false };
  } catch (e) {
    if (isNetworkError(e)) {
      const cached = await cacheGet<RosterEntry[]>(key);
      if (cached) return { roster: cached, fromCache: true };
    }
    throw e;
  }
}

const draftKey = (est: string, slot: string, date: string): string => `draft:attendance:${est}:${slot}:${date}`;

export async function loadDraft(est: string, slotId: string, date: string): Promise<Draft> {
  return (
    (await cacheGet<Draft>(draftKey(est, slotId, date))) ?? {
      client_uuid: ulid(), // identifiant de la séance : le même à chaque rejeu, donc jamais de doublon
      slot_id: slotId,
      date,
      lines: {},
      updated_at: new Date().toISOString(),
    }
  );
}

export async function saveDraft(est: string, draft: Draft): Promise<void> {
  await cachePut(draftKey(est, draft.slot_id, draft.date), { ...draft, updated_at: new Date().toISOString() });
}

export function setStatus(draft: Draft, personId: string, status: AttendanceStatus, arrivedAt?: string): Draft {
  const existing = draft.lines[personId];
  const line: AttendanceLine = {
    person_id: personId,
    status,
    client_uuid: existing?.client_uuid ?? ulid(),
    ...(status === 'R' ? { arrived_at: arrivedAt ?? existing?.arrived_at ?? '08:00' } : {}),
  };
  return { ...draft, lines: { ...draft.lines, [personId]: line } };
}

export function clearStatus(draft: Draft, personId: string): Draft {
  const { [personId]: _removed, ...rest } = draft.lines;
  return { ...draft, lines: rest };
}

export function counters(draft: Draft): { present: number; absent: number; late: number; recorded: number } {
  const lines = Object.values(draft.lines);
  return {
    present: lines.filter((l) => l.status === 'P').length,
    absent: lines.filter((l) => l.status === 'A').length,
    late: lines.filter((l) => l.status === 'R').length,
    recorded: lines.length,
  };
}

/** Valide l'appel : écrit d'abord sur l'appareil (file d'attente), l'envoi se fait ensuite, tout de suite si le réseau est là. */
export async function submitCall(est: string, draft: Draft, roster: RosterEntry[], label: string): Promise<void> {
  const missing = roster.filter((r) => !draft.lines[r.person_id]);
  if (missing.length > 0) throw new Error(`Il manque le statut de ${missing.length} élève(s).`);
  await enqueue({
    client_uuid: draft.client_uuid,
    kind: 'attendance',
    establishment_id: est,
    label,
    payload: {
      client_uuid: draft.client_uuid,
      slot_id: draft.slot_id,
      date: draft.date,
      validate: true,
      records: roster.map((r) => draft.lines[r.person_id]),
    },
  });
  await cacheDelete(draftKey(est, draft.slot_id, draft.date));
}

/** Appels de ce créneau/jour déjà dans la file (pour afficher « en attente d'envoi » sur la carte du créneau). */
export async function queuedFor(slotId: string, date: string): Promise<boolean> {
  const items = await listQueue('attendance');
  return items.some((i) => i.payload.slot_id === slotId && i.payload.date === date);
}
