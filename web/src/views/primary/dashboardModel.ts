// Aides du tableau de bord du directeur : état d'une feuille de compétences à afficher. Affichage seulement : aucun seuil ni règle
// métier (le serveur décide ce qui est « en retard » d'après les dates limites paramétrées par l'école).
import type { DashboardClass, SheetFollowUp } from '@/api/primary';

export type SheetBadge = 'late' | 'overdue' | 'locked' | 'complete' | 'progress' | 'none';

export function sheetBadge(sheet: SheetFollowUp): SheetBadge {
  if (sheet.current?.late === true) return 'late';
  if (sheet.overdue_periods.length > 0) return 'overdue';
  const current = sheet.current;
  if (current === null || current.expected === 0) return 'none';
  if (current.status !== 'open') return 'locked';
  return current.entered >= current.expected ? 'complete' : 'progress';
}

export const BADGE_TONE: Record<SheetBadge, 'warning' | 'success' | 'neutral' | 'accent'> = {
  late: 'warning',
  overdue: 'warning',
  locked: 'success',
  complete: 'success',
  progress: 'accent',
  none: 'neutral',
};

/** Pourcentage saisi (affichage) ; null quand rien n'est attendu. */
export function entryPercent(sheet: SheetFollowUp): number | null {
  const current = sheet.current;
  if (current === null || current.expected === 0) return null;
  return Math.min(100, Math.round((current.entered * 100) / current.expected));
}

/** Classes à surveiller d'abord : feuille en retard, puis période à terminer, puis le reste par nom. */
export function watchFirst(classes: readonly DashboardClass[]): DashboardClass[] {
  const rank = (c: DashboardClass): number => (c.sheet.current?.late === true ? 0 : c.sheet.late ? 1 : 2);
  return [...classes].sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name));
}
