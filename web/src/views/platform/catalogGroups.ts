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

// --- Type d'établissement du secondaire (consigné dans le message de la demande, sans colonne dédiée) ---
export type SchoolType = 'leg' | 'technical' | 'ces' | 'cetic' | 'college' | 'lycee' | 'other';

export const SCHOOL_TYPES: Record<Sector, SchoolType[]> = {
  public: ['leg', 'technical', 'ces', 'cetic', 'other'],
  private: ['college', 'lycee', 'other'],
};

/** Libellés enregistrés dans le message : lus par l'administrateur de plateforme, donc toujours en français. */
export const SCHOOL_TYPE_FR: Record<SchoolType, string> = {
  leg: "Lycée d'enseignement général",
  technical: 'Lycée technique',
  ces: "CES (collège d'enseignement secondaire)",
  cetic: "CETIC (collège d'enseignement technique et commercial)",
  college: 'Collège',
  lycee: 'Lycée',
  other: 'Autre',
};

export const SCHOOL_TYPE_PREFIX = "Type d'établissement : ";

/** Message de la demande précédé du type d'établissement choisi (secondaire). */
export function withSchoolType(type: SchoolType | '', message: string): string {
  if (type === '') return message;
  const line = SCHOOL_TYPE_PREFIX + SCHOOL_TYPE_FR[type];
  return message === '' ? line : `${line}\n${message}`;
}
