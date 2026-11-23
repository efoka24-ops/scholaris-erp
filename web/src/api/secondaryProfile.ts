// API du profil d'établissement du secondaire (préfixe /api/v1/secondary/setup/profile). Contrats : openapi.d/secondary-core.yaml et
// secondary-setup.yaml. Le serveur reste seul juge : le type ne se teste jamais ici, on lit les cycles et l'état des modules.
import { http, type QueryValue } from '@/api/http';

const ROOT = '/secondary';

export type ModuleState = 'active' | 'read_only' | 'inactive' | 'unavailable';
export type SecondaryModule =
  | 'second_cycle' | 'orientation' | 'official_exams' | 'workshops' | 'internships' | 'fees_private' | 'fees_public_catalog'
  | 'authorizations' | 'payroll_simple' | 'network_indicators' | 'boarding' | 'canteen_transport' | 'library' | 'infirmary';

/** Liste fermée des modules activables (spec 005 §1.3), dans l'ordre d'affichage. */
export const SECONDARY_MODULES: SecondaryModule[] = [
  'second_cycle', 'orientation', 'official_exams', 'workshops', 'internships', 'fees_private', 'fees_public_catalog',
  'authorizations', 'payroll_simple', 'network_indicators', 'boarding', 'canteen_transport', 'library', 'infirmary',
];
export const SECONDARY_CYCLES = ['first', 'second'] as const;
export const TEACHING_KINDS = ['general', 'technical'] as const;
export const SECTIONS = ['fr', 'en'] as const;
export const OWNERSHIPS = ['public', 'private_secular', 'private_faith'] as const;
export const HEAD_TITLES = ['proviseur', 'principal'] as const;
/** Modules qui exigent le second cycle (miroir de Modules::REQUIRED_CYCLE). */
export const REQUIRES_SECOND_CYCLE: SecondaryModule[] = ['second_cycle', 'orientation'];

export interface ModuleInfo {
  code: SecondaryModule;
  state: ModuleState;
  default_for_type: boolean;
}
export interface SecondaryProfile {
  establishment_id: string;
  subtype: string;
  stored: boolean;
  profile_version: number;
  confirmation: string;
  ownership: string | null;
  ownership_options: string[];
  cycles: string[];
  teaching_kinds: string[];
  sections: string[];
  head_title: string | null;
  read_only_modules?: string[];
  modules: ModuleInfo[];
}
export interface OpenProfileInput {
  ownership?: string;
  cycles?: string[];
  teaching_kinds?: string[];
  sections?: string[];
  head_title?: string;
  modules?: SecondaryModule[];
  official_code?: string;
  reason?: string;
}
export type ChangeKind = 'subtype' | 'ownership' | 'cycles' | 'teaching_kinds' | 'sections' | 'head_title' | 'modules' | 'official_code';
export interface ProfileChange {
  id: string;
  change_kind: string;
  reason?: string | null;
  created_at: string;
  [key: string]: unknown;
}

export const secondaryProfile = {
  get: async (): Promise<SecondaryProfile> => (await http.get<{ data: SecondaryProfile }>(`${ROOT}/setup/profile`)).data,
  open: async (input: OpenProfileInput): Promise<SecondaryProfile> => (await http.post<{ data: SecondaryProfile }>(`${ROOT}/setup/profile/open`, input)).data,
  change: async (kind: ChangeKind, value: unknown, reason: string): Promise<SecondaryProfile> =>
    (await http.post<{ data: SecondaryProfile }>(`${ROOT}/setup/profile/changes`, { kind, value, reason })).data,
  changes: (query: Record<string, QueryValue> = {}): Promise<ProfileChange[]> => http.all<ProfileChange>(`${ROOT}/setup/profile/changes`, query),
};

/** Modules utilisables (actifs ou coupés avec données en lecture seule) : seuls ceux-là ont une entrée de menu. */
export function usableModules(profile: SecondaryProfile | null): Set<SecondaryModule> {
  return new Set((profile?.modules ?? []).filter((m) => m.state === 'active' || m.state === 'read_only').map((m) => m.code));
}
