// Import en masse des acteurs d'un établissement (permission imports.people.manage). Le fichier est analysé SANS rien créer ;
// l'application est unique et rend les mots de passe provisoires une seule fois : ils ne doivent être ni stockés ni journalisés.
import { http } from './http';

export type ImportLineStatus = 'valid' | 'error' | 'duplicate' | 'applied' | 'partial' | 'failed';
export type ImportCredentials = 'provisional_password' | 'activation_code';

export interface ImportLine {
  line: number;
  status: ImportLineStatus;
  messages: string[];
  last_name: string | null;
  first_name: string | null;
  email: string | null;
  phone: string | null;
  locale: string | null;
  role: string | null;
  scope_type: string | null;
  scope_code: string | null;
  starts_on: string | null;
  ends_on: string | null;
  note: string | null;
  person_id: string | null;
  user_id: string | null;
}

export interface ImportSummary {
  total: number;
  valid: number;
  error: number;
  duplicate: number;
  applied: number;
  partial: number;
  failed: number;
}

export interface ImportBatch {
  id: string;
  status: string;
  source_format: string;
  file_name: string | null;
  created_at: string;
  expires_at: string;
  committed_at: string | null;
  credentials: ImportCredentials | null;
  send_codes: boolean;
  summary: ImportSummary;
  lines: ImportLine[];
  /** Présent uniquement dans la réponse de l'application (mot de passe provisoire) : à jeter dès la fermeture. */
  provisional_credentials?: ProvisionalCredential[];
}

export interface ProvisionalCredential {
  line: number;
  user_id?: string;
  person_id?: string;
  login: string;
  last_name: string;
  first_name: string;
  role: string;
  provisional_password: string;
}

export interface ImportPreview {
  batch: ImportBatch;
  unknownColumns: string[];
  allowedRoles: string[];
}

export interface ImportCommit {
  batch: ImportBatch;
  replay: boolean;
}

export const IMPORT_MAX_BYTES = 2 * 1024 * 1024;
export const IMPORT_EXTENSIONS = ['csv', 'xlsx'] as const;

const BASE = '/imports/people';
const seg = (id: string): string => encodeURIComponent(id);

export const importsApi = {
  template: (format: 'csv' | 'xlsx'): Promise<Blob> => http.get<Blob>(`${BASE}/template`, { format }, { expect: 'blob' }),

  async preview(file: File): Promise<ImportPreview> {
    const form = new FormData();
    form.append('file', file);
    const res = await http.post<{ data: ImportBatch; meta?: { unknown_columns?: string[]; allowed_roles?: string[] } }>(BASE, undefined, { form });
    return { batch: res.data, unknownColumns: res.meta?.unknown_columns ?? [], allowedRoles: res.meta?.allowed_roles ?? [] };
  },

  async show(id: string, status?: ImportLineStatus): Promise<ImportBatch> {
    return (await http.get<{ data: ImportBatch }>(`${BASE}/${seg(id)}`, { status })).data;
  },

  errorsCsv: (id: string): Promise<Blob> => http.get<Blob>(`${BASE}/${seg(id)}/errors.csv`, undefined, { expect: 'blob', headers: { Accept: 'text/csv' } }),

  async commit(id: string, credentials: ImportCredentials, sendCodes: boolean): Promise<ImportCommit> {
    const res = await http.post<{ data: ImportBatch; meta?: { replay?: boolean } }>(`${BASE}/${seg(id)}/commit`, { credentials, send_codes: sendCodes });
    return { batch: res.data, replay: res.meta?.replay === true };
  },
};
