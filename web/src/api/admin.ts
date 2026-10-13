// Appels de l'API d'administration (services S01 Identité et S02 Organisation).
// Les routes /establishments n'exigent pas d'établissement actif : l'en-tête X-Establishment-Id n'y est jamais envoyé,
// ce qui permet à un super administrateur sans établissement de créer le premier.
import { http } from './http';

export interface Branding {
  accent?: string | null;
  initial?: string | null;
  logo?: { path: string; mime: string; size: number } | null;
  headers?: string[];
  signatories?: Array<{ name: string; title: string }>;
  authorization_numbers?: string[];
}

export interface Establishment {
  id: string;
  name: string;
  type: 'nursery' | 'primary' | 'secondary' | 'higher';
  branding: Branding | null;
  modules_enabled: string[];
  created_at?: string;
  updated_at?: string;
}

export interface ModulesPayload {
  modules_enabled: string[];
  available: string[];
}

export interface AcademicYear {
  id: string;
  label: string;
  starts_on: string;
  ends_on: string;
  status: 'open' | 'closed';
  closed_at: string | null;
}

export interface Period {
  id: string;
  academic_year_id: string;
  kind: 'trimester' | 'sequence' | 'semester';
  ordinal: number;
  starts_on: string;
  ends_on: string;
}

export interface ClosureCheck {
  key: string;
  label: string;
  status: 'ok' | 'blocking' | 'not_evaluable';
  blocking_count: number;
  detail?: string | null;
}

export type ClosureStatus = 'pending_approval' | 'approved_incomplete' | 'closed' | 'rejected';

export interface Closure {
  id: string | null;
  academic_year_id: string | null;
  next_academic_year_id: string | null;
  status: ClosureStatus | null;
  requested_by: string | null;
  requested_at: string | null;
  decided_by: string | null;
  closed_at: string | null;
  steps?: Record<string, { status: string; summary?: string }>;
}

export interface ClosureReport {
  academic_year_id: string;
  ready: boolean;
  blocking_count: number;
  not_evaluable_count: number;
  checks: ClosureCheck[];
  closure: Closure | null;
}

export type StructureKind = 'level' | 'class' | 'group' | 'track';

export interface Structure {
  id: string;
  org_unit_id: string | null;
  kind: StructureKind;
  name: string;
  capacity: number | null;
}

export interface OrgUnit {
  id: string;
  parent_id: string | null;
  type: string;
  name: string;
}

export type ScopeType = 'tenant' | 'establishment' | 'org_unit' | 'class';

export interface RoleAssignment {
  id: string;
  person_id: string;
  role: string;
  scope_type: ScopeType;
  scope_id: string | null;
  establishment_id: string | null;
  starts_at: string | null;
  ends_at: string | null;
  delegated_by: string | null;
}

export interface AssignmentInput {
  person_id: string;
  role: string;
  scope_type: ScopeType;
  scope_id?: string | null;
  establishment_id?: string | null;
  starts_at?: string | null;
  ends_at?: string | null;
}

export interface SettingEntry {
  scope: 'platform' | 'tenant' | 'establishment';
  key: string;
  value: unknown;
  updated_by?: string;
  updated_at?: string;
}

export interface EffectiveSetting {
  key: string;
  value: unknown;
  source: 'platform' | 'tenant' | 'establishment';
  overridden: SettingEntry[];
}

const NO_HEADER = { establishmentId: false as const };

// --- Établissements -------------------------------------------------------------------------------------------------
export const listEstablishments = (): Promise<Establishment[]> => http.all<Establishment>('/establishments', {}, NO_HEADER);
export const getEstablishment = async (id: string): Promise<Establishment> => (await http.get<{ data: Establishment }>(`/establishments/${id}`, undefined, NO_HEADER)).data;
export const createEstablishment = async (input: { name: string; type: string }): Promise<Establishment> =>
  (await http.post<{ data: Establishment }>('/establishments', input, NO_HEADER)).data;
export const renameEstablishment = async (id: string, name: string): Promise<Establishment> =>
  (await http.patch<{ data: Establishment }>(`/establishments/${id}`, { name }, NO_HEADER)).data;
export const getBranding = async (id: string): Promise<Branding> => (await http.get<{ data: Branding }>(`/establishments/${id}/branding`, undefined, NO_HEADER)).data ?? {};
export const saveBranding = async (id: string, changes: Branding): Promise<Branding> =>
  (await http.put<{ data: Branding }>(`/establishments/${id}/branding`, changes, NO_HEADER)).data;
export const getModules = async (id: string): Promise<ModulesPayload> => (await http.get<{ data: ModulesPayload }>(`/establishments/${id}/modules`, undefined, NO_HEADER)).data;
export const saveModules = async (id: string, modules: string[]): Promise<ModulesPayload> =>
  (await http.put<{ data: ModulesPayload }>(`/establishments/${id}/modules`, { modules }, NO_HEADER)).data;

// --- Années académiques, périodes, clôture (établissement actif) --------------------------------------------------------
export const listYears = (): Promise<AcademicYear[]> => http.all<AcademicYear>('/academic-years', { sort: '-starts_on' });
export const createYear = async (input: { label: string; starts_on: string; ends_on: string }): Promise<AcademicYear> =>
  (await http.post<{ data: AcademicYear }>('/academic-years', input)).data;
export const listPeriods = (yearId: string): Promise<Period[]> => http.all<Period>('/periods', { 'filter[academic_year_id]': yearId, sort: 'starts_on' });
export const createPeriod = async (input: { academic_year_id: string; kind: string; ordinal: number; starts_on: string; ends_on: string }): Promise<Period> =>
  (await http.post<{ data: Period }>('/periods', input)).data;
export const closureCheck = async (yearId: string): Promise<ClosureReport> => (await http.get<{ data: ClosureReport }>(`/academic-years/${yearId}/closure-check`)).data;
export const requestClosure = async (yearId: string, nextYearId: string | null): Promise<Closure> =>
  (await http.post<{ data: Closure }>(`/academic-years/${yearId}/close`, nextYearId ? { next_academic_year_id: nextYearId } : {})).data;
export const approveClosure = async (yearId: string): Promise<Closure> => (await http.post<{ data: Closure }>(`/academic-years/${yearId}/close/approve`)).data;
export const rejectClosure = async (yearId: string): Promise<Closure> => (await http.post<{ data: Closure }>(`/academic-years/${yearId}/close/reject`)).data;

// --- Structures et unités organisationnelles ------------------------------------------------------------------------------
export const listStructures = (): Promise<Structure[]> => http.all<Structure>('/structures', { sort: 'name' });
export const createStructure = async (input: { kind: string; name: string; org_unit_id?: string | null; capacity?: number | null }): Promise<Structure> =>
  (await http.post<{ data: Structure }>('/structures', input)).data;
export const updateStructure = async (id: string, changes: Partial<{ name: string; org_unit_id: string | null; capacity: number | null }>): Promise<Structure> =>
  (await http.patch<{ data: Structure }>(`/structures/${id}`, changes)).data;
export const listOrgUnits = (): Promise<OrgUnit[]> => http.all<OrgUnit>('/org-units', { sort: 'name' });
export const createOrgUnit = async (input: { type: string; name: string; parent_id?: string | null }): Promise<OrgUnit> =>
  (await http.post<{ data: OrgUnit }>('/org-units', input)).data;

// --- Utilisateurs et rôles ------------------------------------------------------------------------------------------------
export const createUser = async (input: { first_name: string; last_name: string; phone?: string; email?: string; locale: string }): Promise<{ person_id: string; user_id: string; status: string }> =>
  (await http.post<{ data: { person_id: string; user_id: string; status: string } }>('/users', input)).data;
export const listAssignments = (filters: { person_id?: string; role?: string } = {}): Promise<RoleAssignment[]> =>
  http.all<RoleAssignment>('/role-assignments', Object.fromEntries(Object.entries(filters).map(([k, v]) => [`filter[${k}]`, v])));
export const assignRole = async (input: AssignmentInput): Promise<RoleAssignment> => (await http.post<{ data: RoleAssignment }>('/role-assignments', input)).data;
export const delegateRole = async (input: AssignmentInput): Promise<RoleAssignment> => (await http.post<{ data: RoleAssignment }>('/role-assignments/delegations', input)).data;
export const revokeAssignment = (id: string): Promise<void> => http.delete(`/role-assignments/${id}`);

// --- Paramètres de l'établissement ------------------------------------------------------------------------------------------
export const effectiveSettings = async (): Promise<EffectiveSetting[]> => (await http.get<{ data: EffectiveSetting[] }>('/settings/effective')).data;
export const putSetting = (scope: SettingEntry['scope'], key: string, value: unknown): Promise<{ data: SettingEntry; effective: EffectiveSetting | null }> =>
  http.put('/settings', { scope, key, value });

/** Sonde « suis-je administrateur du client ? » : la liste des établissements répond 200 (même vide) seulement avec le droit de lecture au niveau du client. */
export async function probeTenantAdmin(): Promise<boolean> {
  try {
    await http.get('/establishments', { per_page: 1 }, NO_HEADER);
    return true;
  } catch {
    return false;
  }
}

// --- Personnes (sélection d'une personne pour lui attribuer un rôle) -----------------------------------------------------
export interface PersonLite {
  id: string;
  first_name: string;
  last_name: string;
}
export const searchPeople = async (q: string): Promise<PersonLite[]> => (await http.page<PersonLite>('/people', { 'filter[q]': q.trim(), per_page: 20 })).items;
export const getPerson = async (id: string): Promise<PersonLite> => (await http.get<{ data: PersonLite }>(`/people/${id}`)).data;
