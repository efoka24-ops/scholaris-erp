// Saisie de notes du Supérieur hors ligne (type de file « higher-marks », rejoué vers POST /higher/grades/sync).
// Chaque case modifiée devient une entrée `{client_uuid, assessment_id, person_id|anonymous_code, base_version, score|absence}` ;
// le serveur répond par entrée (applied, unchanged, duplicate, conflict, rejected). Un conflit n'est jamais résolu ici.
import { idbGet, idbPut } from './db';
import { discardItem, enqueue, listQueue, type QueueItem } from './queue';
import { ulid } from '@/lib/ulid';

export const HIGHER_MARKS = 'higher-marks' as const;

export interface HigherMarkEdit {
  assessment_id: string;
  /** Identifiant de la case dans la grille (non lu par le serveur, sert à superposer l'état local). */
  mark_id: string;
  person_id?: string | null;
  anonymous_code?: string | null;
  score: number | null;
  absence: string | null;
  /** Version de la note telle que l'appareil l'a lue. */
  base_version: number;
}

/** Corps d'une entrée tel qu'attendu par le serveur (clé étudiant ou numéro d'anonymat, note ou code d'absence). */
export function higherMarkPayload(uuid: string, edit: HigherMarkEdit, baseVersion: number): Record<string, unknown> {
  const key = edit.person_id ? { person_id: edit.person_id } : { anonymous_code: edit.anonymous_code ?? '' };
  const value = edit.absence ? { absence: edit.absence } : { score: edit.score };
  return { client_uuid: uuid, assessment_id: edit.assessment_id, mark_id: edit.mark_id, ...key, base_version: baseVersion, ...value };
}

/**
 * Enregistre une case sur l'appareil. Une modification non envoyée de la même case est remplacée en gardant sa version de
 * base d'origine (c'est elle qui permet au serveur de détecter un conflit). Une case en conflit n'est pas touchée.
 */
export async function queueHigherMark(establishmentId: string, edit: HigherMarkEdit, label: string): Promise<QueueItem> {
  const pending = (await listQueue(HIGHER_MARKS)).filter(
    (i) => i.status !== 'conflict' && i.establishment_id === establishmentId && i.payload.mark_id === edit.mark_id,
  );
  const baseVersion = pending.length > 0 ? Number(pending[0]?.payload.base_version ?? edit.base_version) : edit.base_version;
  for (const p of pending) await discardItem(p.client_uuid);
  const uuid = ulid();
  return enqueue({ client_uuid: uuid, kind: HIGHER_MARKS, establishment_id: establishmentId, label, payload: higherMarkPayload(uuid, edit, baseVersion) });
}

/** Entrées locales (en attente, en conflit ou en erreur) d'une évaluation, par case. */
export async function higherMarksOf(establishmentId: string, assessmentId: string): Promise<Map<string, QueueItem>> {
  const map = new Map<string, QueueItem>();
  for (const i of await listQueue(HIGHER_MARKS)) {
    if (i.establishment_id === establishmentId && i.payload.assessment_id === assessmentId) map.set(String(i.payload.mark_id), i);
  }
  return map;
}

/** Valeur locale d'une entrée de la file : `{score, absence}`. */
export function queuedValue(item: QueueItem): { score: number | null; absence: string | null } {
  const absence = typeof item.payload.absence === 'string' ? item.payload.absence : null;
  const score = typeof item.payload.score === 'number' ? item.payload.score : null;
  return { score: absence ? null : score, absence };
}

const cacheKey = (establishmentId: string, assessmentId: string): string => `higher-grid|${establishmentId}|${assessmentId}`;

/** Dernière grille lue sur le serveur, pour continuer la saisie sans réseau. */
export async function cacheGrid<T extends object>(establishmentId: string, assessmentId: string, grid: T): Promise<void> {
  await idbPut('cache', { key: cacheKey(establishmentId, assessmentId), grid });
}

export async function cachedGrid<T>(establishmentId: string, assessmentId: string): Promise<T | null> {
  const row = await idbGet<{ key: string; grid: T }>('cache', cacheKey(establishmentId, assessmentId));
  return row?.grid ?? null;
}
