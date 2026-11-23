// Saisie d'une case de la grille : une note (virgule ou point, au pas du barème) ou un code d'absence (ABJ, ABI, NA, FR…).
// Même contrôle que MarkValue côté serveur, pour signaler l'erreur avant l'envoi.
import type { AbsenceCode } from '@/api/higher-grades';

export interface CellValue { score: number | null; absence: string | null }
export type Parsed = { ok: true; value: CellValue } | { ok: false };

/** Code d'absence (clé de configuration) correspondant à une saisie libre (clé ou alias), sinon null. */
export function absenceOf(raw: string, codes: Record<string, AbsenceCode>): string | null {
  const up = raw.trim().toUpperCase();
  if (up === '') return null;
  for (const [key, def] of Object.entries(codes)) {
    if (key.toUpperCase() === up || def.aliases.some((a) => a.toUpperCase() === up)) return key;
  }
  return null;
}

export function parseCell(raw: string, max: number, step: number, codes: Record<string, AbsenceCode>): Parsed {
  const text = raw.trim();
  if (text === '') return { ok: true, value: { score: null, absence: null } };
  const absence = absenceOf(text, codes);
  if (absence) return { ok: true, value: { score: null, absence } };
  const normalized = text.replace(',', '.');
  if (!/^\d{1,3}(\.\d{1,2})?$/.test(normalized)) return { ok: false };
  const score = Number(normalized);
  if (score < 0 || score > max) return { ok: false };
  if (step > 0 && Math.abs(score / step - Math.round(score / step)) > 1e-9) return { ok: false };
  return { ok: true, value: { score, absence: null } };
}

/** Texte affiché dans la case : note au format local (virgule en français) ou premier alias du code d'absence. */
export function formatCell(v: CellValue, codes: Record<string, AbsenceCode>, locale: string): string {
  if (v.absence) return codes[v.absence]?.aliases[0] ?? v.absence.toUpperCase();
  if (v.score === null) return '';
  return locale === 'fr' ? String(v.score).replace('.', ',') : String(v.score);
}

export const sameValue = (a: CellValue, b: CellValue): boolean => a.score === b.score && (a.absence ?? null) === (b.absence ?? null);

/** Libellé d'une valeur de note pour les historiques et corrections. */
export function describe(v: CellValue | null | undefined, locale: string): string {
  if (!v) return '—';
  if (v.absence) return v.absence.toUpperCase();
  return v.score === null ? '—' : formatCell(v, {}, locale);
}
