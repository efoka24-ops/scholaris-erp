// API de la carte scolaire du secondaire (préfixe /api/v1/secondary/carte-scolaire). Contrat : openapi.d/secondary-cartescolaire.yaml.
// cartescolaire.cm n'a aucune interface publique : l'établissement extrait ses fichiers sur le site et les importe ici (CSV UTF-8).
// Le matricule n'est jamais mis dans une adresse : l'élève est désigné par l'identifiant interne de la personne.
import { http } from '@/api/http';

const ROOT = '/secondary/carte-scolaire';

export type NationalIdStatus = 'pending' | 'recorded' | 'mismatch';
export type DiscrepancyKind = 'missing_local' | 'missing_remote' | 'identity' | 'conflict' | 'ambiguous';

export interface CsSettings {
  national_id_pattern: string;
  csv_delimiter: string;
  columns: { national_ids: Record<string, string>; payments: Record<string, string> };
  entry_levels: string[];
  max_bytes: number;
  confirmation: string;
}
export interface CsStudent {
  person_id: string;
  first_name: string;
  last_name: string;
  birth_date: string | null;
  national_id: string | null;
  national_id_status: NationalIdStatus;
  level: string | null;
}
export interface CsImport {
  id: string;
  import_kind: 'national_ids' | 'payments';
  file_name: string;
  rows_total: number;
  rows_ok: number;
  rows_rejected: number;
  rows_duplicate: number;
  rows_unknown: number;
  rejected_rows: Array<{ line: number; reason: string }>;
  replayed?: boolean;
  created_at: string;
}
export interface CsDiscrepancy {
  id: string;
  person_id: string | null;
  national_id: string | null;
  discrepancy_kind: DiscrepancyKind;
  detail: string;
  remote_label: string | null;
  resolved: boolean;
}
export interface CsPayment {
  id: string;
  national_id: string;
  payment_kind: 'contribution' | 'exam_fee';
  exam_code: string | null;
  amount: number;
  paid_on: string;
  external_ref: string;
  matched_person_id: string | null;
  match_status: 'matched' | 'unknown';
}

type One<T> = { data: T };
type Many<T> = { data: T[]; meta?: { next_cursor: string | null } };

export const secondaryCarteScolaire = {
  settings: async (): Promise<CsSettings> => (await http.get<One<CsSettings>>(`${ROOT}/settings`)).data,
  students: async (status?: NationalIdStatus, entryOnly = false): Promise<CsStudent[]> =>
    (await http.get<Many<CsStudent>>(`${ROOT}/students`, { per_page: 200, ...(status ? { status } : {}), ...(entryOnly ? { entry_only: '1' } : {}) })).data,
  recordNationalId: async (personId: string, nationalId: string): Promise<{ national_id: string }> =>
    (await http.put<One<{ national_id: string }>>(`${ROOT}/students/${encodeURIComponent(personId)}/national-id`, { national_id: nationalId })).data,
  importNationalIds: async (fileName: string, content: string): Promise<CsImport> =>
    (await http.post<One<CsImport>>(`${ROOT}/national-ids/import`, { file_name: fileName, content })).data,
  importPayments: async (fileName: string, content: string): Promise<CsImport> =>
    (await http.post<One<CsImport>>(`${ROOT}/payments/import`, { file_name: fileName, content })).data,
  imports: async (): Promise<CsImport[]> => (await http.get<Many<CsImport>>(`${ROOT}/imports`)).data,
  discrepancies: async (openOnly = true): Promise<CsDiscrepancy[]> =>
    (await http.get<Many<CsDiscrepancy>>(`${ROOT}/discrepancies`, { per_page: 200, ...(openOnly ? { resolved: '0' } : {}) })).data,
  resolve: async (id: string, note: string, applyNationalId: boolean): Promise<CsDiscrepancy> =>
    (await http.post<One<CsDiscrepancy>>(`${ROOT}/discrepancies/${encodeURIComponent(id)}/resolve`, { note, apply_national_id: applyNationalId })).data,
  payments: async (matchStatus?: 'matched' | 'unknown'): Promise<CsPayment[]> =>
    (await http.get<Many<CsPayment>>(`${ROOT}/payments`, { per_page: 200, ...(matchStatus ? { 'filter[match_status]': matchStatus } : {}) })).data,
};

/** Contrôle du format côté écran (même expression que le serveur, qui reste juge). */
export function matchesPattern(nationalId: string, pattern: string): boolean {
  const clean = nationalId.replace(/\s+/g, '').toUpperCase();
  try {
    return clean !== '' && new RegExp(`^(?:${pattern})$`).test(clean);
  } catch {
    return clean !== '';
  }
}
