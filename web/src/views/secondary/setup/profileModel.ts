// Modèle de l'assistant de profil (T836) : brouillon tiré du profil (valeurs de départ du type, à confirmer), règles d'affichage et
// charge utile d'ouverture. Aucune règle par type : seulement les cycles choisis (un module de second cycle exige le second cycle).
import {
  REQUIRES_SECOND_CYCLE, SECONDARY_MODULES, type OpenProfileInput, type SecondaryModule, type SecondaryProfile,
} from '@/api/secondaryProfile';

export interface ProfileDraft {
  ownership: string;
  cycles: string[];
  teaching_kinds: string[];
  sections: string[];
  head_title: string;
  modules: SecondaryModule[];
  official_code: string;
  reason: string;
}

export function draftFrom(profile: SecondaryProfile): ProfileDraft {
  return {
    ownership: profile.ownership ?? (profile.ownership_options.length === 1 ? (profile.ownership_options[0] ?? '') : ''),
    cycles: [...profile.cycles],
    teaching_kinds: [...profile.teaching_kinds],
    sections: profile.sections.length > 0 ? [...profile.sections] : ['fr'],
    head_title: profile.head_title ?? '',
    modules: profile.modules.filter((m) => m.state === 'active').map((m) => m.code),
    official_code: '',
    reason: '',
  };
}

/** Un module qui exige le second cycle ne peut pas être coché sans lui. */
export function moduleAllowed(module: SecondaryModule, cycles: readonly string[]): boolean {
  return !REQUIRES_SECOND_CYCLE.includes(module) || cycles.includes('second');
}

/** Ce qu'il manque pour ouvrir (clés de messages), vide si l'assistant est complet. */
export function missing(draft: ProfileDraft): Array<'ownership' | 'cycles' | 'teaching_kinds' | 'sections' | 'head_title'> {
  const out: Array<'ownership' | 'cycles' | 'teaching_kinds' | 'sections' | 'head_title'> = [];
  if (draft.ownership === '') out.push('ownership');
  if (draft.cycles.length === 0) out.push('cycles');
  if (draft.teaching_kinds.length === 0) out.push('teaching_kinds');
  if (draft.sections.length === 0) out.push('sections');
  if (draft.head_title === '') out.push('head_title');
  return out;
}

export function openPayload(draft: ProfileDraft): OpenProfileInput {
  const modules = SECONDARY_MODULES.filter((m) => draft.modules.includes(m) && moduleAllowed(m, draft.cycles));
  return {
    ownership: draft.ownership,
    cycles: [...draft.cycles],
    teaching_kinds: [...draft.teaching_kinds],
    sections: [...draft.sections],
    head_title: draft.head_title,
    modules,
    ...(draft.official_code.trim() !== '' ? { official_code: draft.official_code.trim() } : {}),
    ...(draft.reason.trim() !== '' ? { reason: draft.reason.trim() } : {}),
  };
}

/** Bascule une valeur dans une liste (case à cocher), sans doublon. */
export function toggle<T>(list: T[], value: T): T[] {
  return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
}
