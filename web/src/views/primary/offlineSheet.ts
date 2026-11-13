// Feuille de compétences hors ligne : la feuille lue est gardée sur l'appareil, chaque évaluation saisie est écrite d'abord dans un
// brouillon local (client_uuid par saisie, version vue au moment de la saisie), puis envoyée en lot (PUT) dès que le réseau est là.
// Un envoi rejoué ne crée jamais de doublon (client_uuid) ; une version périmée (409) n'écrase rien : le brouillon est gardé et le
// conflit signalé, jamais résolu automatiquement.
import { ApiError } from '@/api/http';
import { primaryRaw } from '@/api/primary';
import { cacheDelete, cacheGet, cachePut } from '@/offline/db';
import { ulid } from '@/lib/ulid';

export interface ScaleLevel {
  code: string;
  label_fr: string;
  label_en: string;
  sort_order: number;
}
export interface SheetCompetency {
  id: string;
  code: string;
  label_fr: string;
  label_en: string | null;
  domain_id: string;
  subject_id: string | null;
}
export interface SheetMark {
  id: string;
  person_id: string;
  competency_id: string;
  scale_code: string | null;
  absence_code: string | null;
  remark: string | null;
  status: string;
  version: number;
}
export interface Sheet {
  class: { id: string; name: string };
  period_id: string;
  status: 'open' | 'locked' | 'published';
  published: boolean;
  scale: ScaleLevel[];
  absence_codes: string[];
  domains: { id: string; code: string; label_fr: string; label_en: string | null; sort_order: number }[];
  competencies: SheetCompetency[];
  learners: { person_id: string; name: string }[];
  marks: SheetMark[];
}
export interface DraftCell {
  person_id: string;
  competency_id: string;
  scale_code: string | null;
  client_uuid: string;
  /** Version de l'évaluation vue au moment de la saisie (absente pour une nouvelle évaluation). */
  version?: number;
}
export interface FlushResult {
  sent: number;
  offline: boolean;
  conflict: boolean;
  message?: string;
}

const sheetKey = (est: string, classId: string, period: string): string => `primary:sheet:${est}:${classId}:${period}`;
const draftKey = (est: string, classId: string, period: string): string => `primary:sheetdraft:${est}:${classId}:${period}`;
const cellKey = (personId: string, competencyId: string): string => `${personId}|${competencyId}`;

export function isNetworkError(e: unknown): boolean {
  return e instanceof ApiError && (e.network || e.status >= 500);
}

/** Lit la feuille (réseau puis cache) ; hors ligne, la dernière feuille connue de l'appareil est rendue. */
export async function loadSheet(est: string, classId: string, period: string): Promise<{ sheet: Sheet; fromCache: boolean }> {
  try {
    const sheet = await primaryRaw.get<Sheet>(`/classes/${classId}/competency-sheet`, { period_id: period });
    await cachePut(sheetKey(est, classId, period), sheet);
    return { sheet, fromCache: false };
  } catch (e) {
    if (isNetworkError(e)) {
      const cached = await cacheGet<Sheet>(sheetKey(est, classId, period));
      if (cached) return { sheet: cached, fromCache: true };
    }
    throw e;
  }
}

export async function loadDraft(est: string, classId: string, period: string): Promise<Record<string, DraftCell>> {
  return (await cacheGet<Record<string, DraftCell>>(draftKey(est, classId, period))) ?? {};
}

/** Saisit une évaluation : écrite sur l'appareil tout de suite. Un second appui sur le même niveau la retire du brouillon. */
export async function setCell(
  est: string,
  classId: string,
  period: string,
  draft: Record<string, DraftCell>,
  mark: SheetMark | undefined,
  personId: string,
  competencyId: string,
  scaleCode: string,
): Promise<Record<string, DraftCell>> {
  const key = cellKey(personId, competencyId);
  const next = { ...draft };
  if (next[key]?.scale_code === scaleCode || (mark?.scale_code === scaleCode && next[key] === undefined)) {
    delete next[key];
  } else {
    next[key] = { person_id: personId, competency_id: competencyId, scale_code: scaleCode, client_uuid: ulid(), ...(mark ? { version: mark.version } : {}) };
  }
  if (Object.keys(next).length === 0) await cacheDelete(draftKey(est, classId, period));
  else await cachePut(draftKey(est, classId, period), next);
  return next;
}

/** Envoie le brouillon en un lot. Rien n'est perdu : le brouillon n'est effacé que si le serveur a tout accepté. */
export async function flushDraft(est: string, classId: string, period: string): Promise<FlushResult> {
  const draft = await loadDraft(est, classId, period);
  const entries = Object.values(draft);
  if (entries.length === 0) return { sent: 0, offline: false, conflict: false };
  try {
    await primaryRaw.put(`/classes/${classId}/competency-sheet?period_id=${encodeURIComponent(period)}`, { entries });
    await cacheDelete(draftKey(est, classId, period));
    return { sent: entries.length, offline: false, conflict: false };
  } catch (e) {
    if (isNetworkError(e)) return { sent: 0, offline: true, conflict: false };
    if (e instanceof ApiError && e.status === 409) return { sent: 0, offline: false, conflict: true, message: e.message };
    throw e;
  }
}

/** Abandonne le brouillon local (après un conflit : la version du serveur est conservée). */
export async function discardDraft(est: string, classId: string, period: string): Promise<void> {
  await cacheDelete(draftKey(est, classId, period));
}

export const marksIndex = (marks: SheetMark[]): Record<string, SheetMark> => Object.fromEntries(marks.map((m) => [cellKey(m.person_id, m.competency_id), m]));
export const cellId = cellKey;
