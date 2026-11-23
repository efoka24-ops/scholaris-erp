// Reprise de données de l'application Supérieur (IPES) : /api/v1/higher/migration/** (contrat openapi.d/higher-migration.yaml).
// Simulation obligatoire d'abord (fichier joint en multipart/form-data), puis application (double authentification récente) et
// annulation d'un lot. Permission : higher.migration.manage.
import { http } from './http';
import { H, type Q } from './higher';

const M = `${H}/migration`;
const seg = (v: string): string => encodeURIComponent(v);
const unwrap = async <T>(p: Promise<{ data: T }>): Promise<T> => (await p).data;

export type ImportKind = 'students' | 'credits' | 'balances' | 'staff' | 'marks';
export const IMPORT_KINDS: ImportKind[] = ['students', 'credits', 'balances', 'staff', 'marks'];
export type BatchStatus = 'simulated' | 'applied' | 'reverted';
export type LineStatus = 'ok' | 'warning' | 'duplicate' | 'error' | 'applied' | 'reverted';

export interface KindInfo { kind: ImportKind; label: string; columns: string[]; required: string[] }
export interface BatchCounts { total: number; ok: number; warning: number; duplicate: number; error: number; applied: number }
export interface BatchLine { line: number; status: LineStatus; key: string; errors: string[]; warnings: string[] }
export interface Batch {
  id: string; kind: ImportKind; kind_label: string; academic_year_id: string; status: BatchStatus; source_format: 'csv' | 'xlsx';
  file_name: string | null; file_sha256: string; counts: BatchCounts; applicable: boolean; created_by: string; created_at: string;
  applied_at: string | null; reverted_at: string | null; revert_reason: string | null;
  unknown_columns?: string[]; lines?: BatchLine[]; lines_truncated?: boolean;
}

export const migration = {
  kinds: (): Promise<KindInfo[]> => unwrap(http.get(`${M}/kinds`)),
  /** Modèle de fichier (CSV UTF-8, séparateur « ; »). */
  template: (kind: ImportKind): Promise<Blob> => http.get<Blob>(`${M}/templates/${seg(kind)}`, undefined, { expect: 'blob', headers: { Accept: 'text/csv, application/json;q=0.5' } }),
  batches: (kind?: ImportKind): Promise<Batch[]> => unwrap(http.get(`${M}/batches`, kind ? { kind } : undefined)),
  batch: (id: string, status?: string): Promise<Batch> => unwrap(http.get(`${M}/batches/${seg(id)}`, status ? ({ status } as Q) : undefined)),
  /** Simulation : le fichier part en multipart/form-data (champ `file`), rien n'est écrit. */
  simulate: (b: { kind: ImportKind; academic_year_id: string; file: File }): Promise<Batch> => {
    const form = new FormData();
    form.append('kind', b.kind);
    form.append('academic_year_id', b.academic_year_id);
    form.append('dry_run', 'true');
    form.append('file', b.file, b.file.name);
    return unwrap(http.post(`${M}/batches`, undefined, { form }));
  },
  apply: (id: string): Promise<Batch> => unwrap(http.post(`${M}/batches/${seg(id)}/apply`)),
  revert: (id: string, reason: string): Promise<Batch> => unwrap(http.post(`${M}/batches/${seg(id)}/revert`, { reason })),
};
