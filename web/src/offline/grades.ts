// Saisie de notes hors ligne : chaque modification est écrite sur l'appareil puis envoyée par POST /grades/sync.
import type { Grade } from '@/api/types';
import { discardItem, enqueue, listQueue, type QueueItem } from './queue';
import { ulid } from '@/lib/ulid';

export interface GradeEdit {
  assessment_id: string;
  person_id: string;
  /** null efface la note. */
  value: number | null;
  absence: 'justified' | 'unjustified' | null;
  /** Version de la note telle que l'appareil l'a lue : sert à détecter qu'elle a changé entre-temps. */
  base_version: number;
}

export const cellKey = (assessmentId: string, personId: string): string => `${assessmentId}|${personId}`;

/**
 * Enregistre une modification de note. Si une modification de la même cellule attend déjà d'être envoyée, elle est
 * remplacée, mais la version de base d'origine est conservée (c'est elle qui permet de détecter un conflit).
 */
export async function queueGradeEdit(establishmentId: string, edit: GradeEdit, label: string): Promise<QueueItem> {
  const pending = (await listQueue('grade')).filter(
    (i) => i.status === 'pending' && i.payload.assessment_id === edit.assessment_id && i.payload.person_id === edit.person_id,
  );
  const baseVersion = pending.length > 0 ? Number(pending[0]?.payload.base_version ?? edit.base_version) : edit.base_version;
  for (const p of pending) await discardItem(p.client_uuid);
  const uuid = ulid();
  return enqueue({
    client_uuid: uuid,
    kind: 'grade',
    establishment_id: establishmentId,
    label,
    payload: {
      client_uuid: uuid,
      assessment_id: edit.assessment_id,
      person_id: edit.person_id,
      base_version: baseVersion,
      value: edit.value,
      absence: edit.absence,
    },
  });
}

/** Modifications non envoyées (ou en conflit), par cellule, pour les superposer à la grille lue sur le serveur. */
export async function localEdits(establishmentId: string): Promise<Map<string, QueueItem>> {
  const map = new Map<string, QueueItem>();
  for (const i of await listQueue('grade')) {
    if (i.establishment_id === establishmentId) map.set(cellKey(String(i.payload.assessment_id), String(i.payload.person_id)), i);
  }
  return map;
}

/** Contrôle de saisie identique à celui du serveur : « Ligne 4 · Devoir : 22 dépasse le maximum de 20. Corrigez avant de valider. » */
export function validateScore(value: number | null, max: number): 'ok' | 'negative' | 'over' | 'invalid' {
  if (value === null) return 'ok';
  if (Number.isNaN(value)) return 'invalid';
  if (value < 0) return 'negative';
  if (value > max) return 'over';
  return 'ok';
}

export function parseScore(raw: string): number | null | typeof Number.NaN {
  const s = raw.trim().replace(',', '.');
  if (s === '') return null;
  return /^\d+(\.\d+)?$/.test(s) ? Number(s) : Number.NaN;
}

export function gradeFromServer(g: Grade | undefined): { value: number | null; absence: Grade['absence']; version: number } {
  return { value: g?.score ?? null, absence: g?.absence ?? null, version: g?.version ?? 0 };
}
