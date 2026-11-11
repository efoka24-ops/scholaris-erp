// API des inscriptions du secondaire (préfixe /api/v1/secondary). Contrat : openapi.d/secondary-enrolment.yaml. Le matricule national
// ne figure jamais dans une adresse : les routes portent l'identifiant interne de la personne. Aucun contrôle de paiement (RG-INS-4).
import { http, type QueryValue } from '@/api/http';

const ROOT = '/secondary';

const get = async <T>(path: string, query?: Record<string, QueryValue>): Promise<T> => (await http.get<{ data: T }>(`${ROOT}${path}`, query)).data;
const post = async <T>(path: string, body?: unknown): Promise<T> => (await http.post<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const put = async <T>(path: string, body?: unknown): Promise<T> => (await http.put<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const patch = async <T>(path: string, body?: unknown): Promise<T> => (await http.patch<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const all = <T>(path: string, query?: Record<string, QueryValue>): Promise<T[]> => http.all<T>(`${ROOT}${path}`, query ?? {});
const enc = encodeURIComponent;

export type NationalIdStatus = 'pending' | 'recorded' | 'mismatch';
export type EntryKind = 'new' | 'reenrolment' | 'transfer_in' | 'primary_entry' | 'import';
export type GuardianKind = 'parent' | 'guardian' | 'financial_guarantor' | 'emergency';

export interface Identity {
  last_name: string;
  first_name: string;
  birth_date?: string;
  gender?: 'male' | 'female';
  birth_place?: string;
  phone?: string;
}
export interface DirectoryStudent {
  person_id: string;
  last_name: string | null;
  first_name: string | null;
  full_name: string;
  class_structure_id: string;
  class_label: string | null;
  academic_year_id: string;
}
export interface GuardianInput {
  kind: GuardianKind;
  person_id?: string;
  identity?: Identity;
}
export interface EnrolmentInput {
  person_id?: string;
  identity?: Identity;
  guardians?: GuardianInput[];
  class_id: string;
  entry_date: string;
  national_id?: string;
  repeater?: boolean;
  entry_kind?: EntryKind;
  age_decision_reason?: string;
  exclusion_decision_reason?: string;
  documents_checked?: string[];
  confirm_different?: boolean;
  special_situation?: string;
  /** C-118 : le premier parent ou tuteur (avec téléphone) devient garant financier. */
  guardian_financial?: boolean;
}
export interface EnrolmentRecord {
  id: string;
  person_id: string;
  enrollment_id: string;
  class_structure_id: string;
  class_id: string;
  level_code: string;
  academic_year_id: string;
  entry_kind: EntryKind;
  entry_date: string;
  repeater: boolean;
  age_alert: boolean;
  age_decision_reason: string | null;
  exclusion_decision_reason: string | null;
  decided_by: string | null;
  documents_checked: string[];
  created_at: string;
}
export interface StudentRow extends EnrolmentRecord {
  last_name: string | null;
  first_name: string | null;
  gender: string | null;
  national_id_status: NationalIdStatus;
}
export interface Transfer {
  id: string;
  person_id: string;
  direction: 'in' | 'out';
  other_school_label: string;
  transfer_date: string;
  exeat_document_id: string | null;
  national_id_checked: boolean;
  national_id_match: 'match' | 'recorded' | 'mismatch' | 'invalid' | null;
  file_ref: string | null;
  enrollment_id: string | null;
  reason: string | null;
  status: 'completed' | 'to_check';
  created_at: string;
}
export interface Dossier {
  person_id: string;
  last_name: string | null;
  first_name: string | null;
  gender: string | null;
  birth_date: string | null;
  national_id: string | null;
  national_id_status: NationalIdStatus;
  repeater: boolean;
  final_exclusion_on: string | null;
  readmission_year_id: string | null;
  special_situation: string | null;
  enrollments: { id: string; structure_id: string | null; academic_year_id: string; status: string; internal_number: string | null }[];
  records: EnrolmentRecord[];
  transfers: Transfer[];
  financial_guarantors?: { person_id: string; last_name: string | null; first_name: string | null; phone: string | null }[];
}
export interface Preregistration {
  id: string;
  person_id: string | null;
  last_name: string;
  first_name: string;
  birth_date: string | null;
  gender: string | null;
  guardian_name: string | null;
  guardian_phone: string | null;
  target_level_code: string;
  stream_id: string | null;
  channel: 'online' | 'desk' | 'primary_file';
  entry_test_score: number | null;
  status: 'pending' | 'accepted' | 'refused' | 'enrolled';
  documents_checked: string[];
  source: Record<string, unknown> | null;
  enrollment_id: string | null;
  decision_reason: string | null;
  decided_by: string | null;
  created_at: string;
}
export interface Candidate {
  person_id: string;
  full_name: string | null;
  level_code: string;
  class_id: string;
  admitted: boolean;
  reenrolled_class_id: string | null;
}
export interface ImportLine {
  line: number;
  status: 'valid' | 'error' | 'duplicate' | 'enrolled';
  last_name: string;
  first_name: string;
  class: string;
  messages: string[];
  person_id?: string;
}
export interface ImportReport {
  dry_run: boolean;
  unknown_columns: string[];
  summary: { valid: number; error: number; duplicate: number; total: number; enrolled?: number };
  lines: ImportLine[];
}
export interface ImportTemplate {
  columns: string[];
  required: string[];
  sample: string[][];
  separator: string;
  encoding: string;
}

export const secondaryEnrolment = {
  enrol: (input: EnrolmentInput): Promise<Dossier> => post('/enrolments', input),
  students: (query: { academic_year_id?: string; class_structure_id?: string; pending?: boolean } = {}): Promise<StudentRow[]> =>
    all<StudentRow>('/enrolment/students', { academic_year_id: query.academic_year_id, class_structure_id: query.class_structure_id, pending: query.pending ? '1' : undefined }),
  /** Annuaire (nom et classe de la dernière inscription) : choisir un élève par son nom, jamais par son identifiant (FIX-SEC). */
  directory: (query: { academic_year_id?: string; class_structure_id?: string } = {}): Promise<DirectoryStudent[]> =>
    get<{ items: DirectoryStudent[] }>('/enrolment/directory', { academic_year_id: query.academic_year_id, class_structure_id: query.class_structure_id }).then((r) => r.items),
  student: (personId: string): Promise<Dossier> => get(`/enrolment/students/${enc(personId)}`),
  setNationalId: (personId: string, nationalId: string): Promise<Dossier> => put(`/students/${enc(personId)}/national-id`, { national_id: nationalId }),
  attachGuarantor: (personId: string, input: { phone: string; last_name?: string; first_name?: string }): Promise<Dossier> => post(`/enrolment/students/${enc(personId)}/guarantors`, input),
  preregistrations: (status?: string): Promise<Preregistration[]> => all<Preregistration>('/enrolment/preregistrations', status ? { 'filter[status]': status } : {}),
  createPreregistration: (input: Record<string, unknown>): Promise<Preregistration> => post('/enrolment/preregistrations', input),
  updatePreregistration: (id: string, changes: { status?: string; entry_test_score?: number; decision_reason?: string }): Promise<Preregistration> =>
    patch(`/enrolment/preregistrations/${enc(id)}`, changes),
  convert: (id: string, input: { class_id: string; entry_date: string; guardians?: GuardianInput[]; age_decision_reason?: string }): Promise<Dossier> =>
    post(`/enrolment/preregistrations/${enc(id)}/convert`, input),
  primaryFile: (dossierJson: string, signature: string, targetLevelCode: string): Promise<{ preregistration: Preregistration; duplicate: boolean }> =>
    post('/enrolment/primary-files', { dossier_json: dossierJson, signature, target_level_code: targetLevelCode }),
  candidates: (fromYear: string, toYear?: string): Promise<Candidate[]> => all<Candidate>('/enrolment/reenrolments/candidates', { from_academic_year_id: fromYear, academic_year_id: toYear }),
  reenrol: (input: { from_academic_year_id: string; class_id: string; entry_date: string; students: { person_id: string; repeat?: boolean; exclusion_decision_reason?: string }[] }): Promise<{ enrolled: string[]; refused: { person_id: string; reason: string }[] }> =>
    post('/enrolment/reenrolments', input),
  transfers: (): Promise<Transfer[]> => all<Transfer>('/enrolment/transfers'),
  transferOut: (input: { person_id: string; other_school_label: string; transfer_date: string; exeat_document_id?: string; file_ref?: string; reason?: string }): Promise<Transfer> =>
    post('/enrolment/transfers/out', input),
  transferIn: (input: EnrolmentInput & { other_school_label: string; transfer_date: string; declared_national_id?: string; file_ref?: string }): Promise<Transfer> =>
    post('/enrolment/transfers/in', input),
  importTemplate: (): Promise<ImportTemplate> => get('/enrolment/imports/template'),
  importFile: (input: { format: 'csv' | 'xlsx'; content?: string; content_base64?: string; academic_year_id: string; entry_date: string; dry_run: boolean }): Promise<ImportReport> =>
    post('/enrolment/imports', input),
};

/** Modèle CSV (séparateur « ; », UTF-8 avec BOM pour Excel) construit à partir du modèle du serveur ; cellules neutralisées. */
export function templateCsv(template: ImportTemplate): string {
  const cell = (v: string): string => {
    const safe = /^[=+\-@]/.test(v) ? `'${v}` : v;
    return /[;"\n]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe;
  };
  const lines = [template.columns, ...template.sample].map((row) => row.map(cell).join(template.separator));
  return `${String.fromCharCode(0xfeff)}${lines.join('\r\n')}\r\n`;
}

/** Format d'un fichier choisi par l'utilisateur : xlsx ou csv (sinon null). */
export function fileFormat(name: string): 'csv' | 'xlsx' | null {
  const lower = name.toLowerCase();
  if (lower.endsWith('.xlsx')) return 'xlsx';
  if (lower.endsWith('.csv')) return 'csv';
  return null;
}

/** Contenu binaire en base64 (pour un fichier Excel). */
export function toBase64(bytes: ArrayBuffer): string {
  let binary = '';
  const view = new Uint8Array(bytes);
  for (let i = 0; i < view.length; i += 1) binary += String.fromCharCode(view[i] ?? 0);
  return btoa(binary);
}
