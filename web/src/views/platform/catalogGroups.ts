import { reactive } from 'vue';
import type { AppStatus, AppTarget } from '@/api/platform';

// Catalogue présenté par niveau (supérieur, maternelle, primaire, secondaire), avec le régime (privé ou public) comme choix :
// le régime désigne l'application technique à ouvrir (nursery/public_nursery, primary/public_primary...).
export type Sector = 'private' | 'public';

/** Ordre d'affichage des niveaux. */
export const LEVELS: AppTarget[] = ['higher', 'nursery', 'primary', 'secondary'];

export interface Leveled {
  key: string;
  label: string;
  target: AppTarget;
  status: AppStatus;
  /** Niveau ; absent d'une ancienne réponse : la cible en tient lieu. */
  family?: AppTarget;
  /** Régime ; absent d'une ancienne réponse : privé. */
  sector?: Sector;
}

export interface RegimeEntry<T extends Leveled> {
  sector: Sector;
  app: T;
}

export interface LevelGroup<T extends Leveled> {
  level: AppTarget;
  /** Libellé du niveau (sans le régime). */
  label: string;
  regimes: Array<RegimeEntry<T>>;
}

export const levelOf = (app: Leveled): AppTarget => app.family ?? app.target;
export const sectorOf = (app: Leveled): Sector => (app.sector === 'public' ? 'public' : 'private');

/** Une entrée par niveau, régimes triés (privé puis public) ; niveaux dans l'ordre d'affichage. */
export function groupByLevel<T extends Leveled>(apps: T[]): Array<LevelGroup<T>> {
  const groups = new Map<AppTarget, Array<RegimeEntry<T>>>();
  for (const app of apps) {
    const level = levelOf(app);
    const list = groups.get(level) ?? [];
    list.push({ sector: sectorOf(app), app });
    groups.set(level, list);
  }
  const rank = (level: AppTarget): number => {
    const i = LEVELS.indexOf(level);
    return i === -1 ? LEVELS.length : i;
  };
  return [...groups.entries()]
    .sort(([a], [b]) => rank(a) - rank(b))
    .map(([level, regimes]) => {
      const sorted = [...regimes].sort((a, b) => (a.sector === b.sector ? 0 : a.sector === 'private' ? -1 : 1));
      return { level, label: (sorted[0] as RegimeEntry<T>).app.label, regimes: sorted };
    });
}

/** Régime proposé d'emblée : le premier disponible, sinon le premier. */
export function defaultRegime<T extends Leveled>(group: LevelGroup<T>): RegimeEntry<T> {
  return group.regimes.find((r) => r.app.status === 'available') ?? (group.regimes[0] as RegimeEntry<T>);
}

/** Choix du régime par niveau, conservé le temps de la page. */
export function useRegimePick<T extends Leveled>() {
  const picked = reactive<Record<string, Sector>>({});
  return {
    current(group: LevelGroup<T>): RegimeEntry<T> {
      return group.regimes.find((r) => r.sector === picked[group.level]) ?? defaultRegime(group);
    },
    choose(group: LevelGroup<T>, sector: Sector): void {
      picked[group.level] = sector;
    },
  };
}

// --- Type d'établissement du secondaire : application unique, le type la personnalise (liste fermée, codes du serveur) ---
export const SCHOOL_TYPES = ['leg', 'lycee_technique', 'ces', 'cetic', 'college_prive', 'lycee_prive', 'autre'] as const;
export type SchoolType = (typeof SCHOOL_TYPES)[number];

/** Le type d'établissement est demandé (et obligatoire) pour le secondaire seulement. */
export const needsSchoolType = (level: string): boolean => level === 'secondary';
export const isSchoolType = (v: string): v is SchoolType => (SCHOOL_TYPES as readonly string[]).includes(v);
