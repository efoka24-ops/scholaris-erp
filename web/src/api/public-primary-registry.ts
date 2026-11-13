import { http } from './http';

const ROOT = '/public-primary/registry';

export interface PublicSchoolProfile {
  id: string;
  tenant_id: string;
  establishment_id: string;
  name: string;
  school_code: string;
  territory_id: string;
}

export interface TerritoryNode {
  id: string;
  type: string;
  code: string;
  label_fr: string;
  label_en: string;
  parent_id: string | null;
  version: string;
}

export interface InfrastructureSnapshot {
  id: string;
  tenant_id: string;
  establishment_id: string;
  school_year: string;
  version: number;
  classrooms_count: number;
  classroom_condition_fr: string;
  classroom_condition_en: string;
  water_access: boolean;
  latrines_count: number;
  electricity: boolean;
  connectivity: 'unknown' | 'none' | 'limited' | 'available';
  equipment_fr: string;
  equipment_en: string;
  status: 'draft' | 'closed';
  correction_reason: string | null;
  closed_at: string | null;
}

const unwrap = async <T>(promise: Promise<{ data: T }>): Promise<T> => (await promise).data;

export const publicPrimaryRegistry = {
  profile: (): Promise<PublicSchoolProfile> => unwrap(http.get(`${ROOT}/profile`)),
  territoryLineage: (id: string): Promise<TerritoryNode[]> => unwrap(http.get(`${ROOT}/territories/${encodeURIComponent(id)}/lineage`)),
  infrastructure: (schoolYear: string): Promise<InfrastructureSnapshot | null> =>
    unwrap(http.get(`${ROOT}/infrastructure`, { school_year: schoolYear })),
};